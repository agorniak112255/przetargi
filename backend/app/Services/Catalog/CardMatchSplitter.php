<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\CardMatchCandidate;
use App\Models\CardRedirect;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductImageRejection;
use App\Models\ProductPriceHistory;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bCatalogSync;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\Pricing\ProductEffectivePrice;
use App\Services\Pricing\SourcePriceComparison;
use App\Services\Vector\ProductEmbeddingIndexer;
use App\Support\CanonicalBrand;
use DomainException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

/**
 * „Rozdziel” na ekranie „Łączenie kart” (plan łączenia kart, krok 7 — etap C2): karta dystrybutora trzyma kilka
 * wyrobów, które producent ma na osobnych kartach. Przykład z produkcji: P4S „2365X0 Hełm ochronny 3M SecureFit serii
 * X5000VE-CE” (#55012, 391,60 zł) to grupa czterech kolorów 236510/236520/236540/236570, a 3M ma kartę na każdy kolor
 * (7100175101 biały, 7100175511 żółty, 7100175512 niebieski, 7100175534 czerwony, po 269,99 zł). Każda pozycja
 * dystrybutora przechodzi na kartę producenta swojego klucza (powiązanie, identyfikatory, wpis mapy połączeń), karty
 * producenta dostają slot ceny, wiersz historii i tabelkę sklepu dystrybutora, a karta dystrybutora znika (jej
 * historia cen, zdjęcia, dokumenty i opis zostają w kopii zapasowej; pliki na dysku zostają). Nazwa, opis, SKU i cena
 * właściciela kart producenta bez zmian.
 *
 * Jedna transakcja: przy każdej odmowie i każdym błędzie nic się nie zmienia (kopia zapasowa wycofanej decyzji jest
 * usuwana). Blokady: propozycja → konta (przebieg, który zechce zająć konto, czeka na commit i wczytuje już wpisy
 * „split”) → karty rosnąco. Przed zmianą — ponowna ocena planu (CardMatchFinder::evaluate: ten sam rodzaj i skrót,
 * propozycja pewna) i strażnicy niezależni od reguł propozycji. Kolejne przebiegi dystrybutora trzymają pozycje na
 * kartach producenta: grupa z wpisem split idzie trybem pojedynczym (B2bCatalogSync::syncMembersSeparately).
 */
final class CardMatchSplitter
{
    private const NOTHING_DONE = 'nic nie rozdzielono';

    /** tabela => [kolumny karty, opis] — czego rozdzielenie nie przenosi z karty dystrybutora */
    private const SOURCE_BLOCKING = [
        'product_variants' => [['product_id'], 'ma wersje'],
        'product_special_prices' => [['product_id'], 'ma ceny specjalne'],
        'product_accessories' => [['product_id', 'related_product_id'], 'ma akcesoria albo jest akcesorium innej karty'],
        'presta_product_matches' => [['product_id'], 'jest powiązana z Prestą'],
        'tender_items' => [['main_product_id', 'companion_product_id'], 'jest w pozycjach przetargów'],
        'product_substitutes' => [['main_product_id', 'substitute_product_id'], 'ma zamienniki albo jest zamiennikiem'],
    ];

    /** kopia zapasowa bieżącego rozdzielenia — usuwana, gdy transakcja się wycofa */
    private ?string $backupPath = null;

    /**
     * CardMatchFinder z kontenera przy użyciu (klasa final — atrapa w testach przez $app->instance), jak
     * w CardMatchMerger i CardMatchSizeMerger.
     */
    public function __construct(
        private readonly CardRedirectStore $redirects,
        private readonly CardOwnership $ownership,
        private readonly CardMatchBackup $backup,
        private readonly ProductEffectivePrice $effectivePrices,
        private readonly B2bConnectorRegistry $connectors,
        private readonly SourcePriceComparison $labels,
        private readonly ActivityLogger $activity,
        private readonly ProductEmbeddingIndexer $embeddings,
        private readonly Container $container,
    ) {}

    /**
     * @throws CardMatchPlanChanged propozycja zmieniła się od wczytania ekranu (409, nic nie zmienione)
     * @throws DomainException z powodem po polsku (422, nic nie zmienione)
     */
    public function split(CardMatchCandidate $candidate, User $user, string $planHash): CardMatchCandidate
    {
        $this->backupPath = null;
        try {
            DB::transaction(function () use ($candidate, $user, $planHash): void {
                $this->splitLocked($candidate, $user, $planHash);
            });
        } catch (Throwable $e) {
            // transakcja wycofana — kopia zapasowa rozdzielenia, którego nie było, tylko by myliła przy odtwarzaniu
            if ($this->backupPath !== null && is_file($this->backupPath)) {
                @unlink($this->backupPath);
            }
            if ($e instanceof JsonException) {
                throw new DomainException('Kopia zapasowa nie powstała: '.$e->getMessage().' — '.self::NOTHING_DONE.'.', 0, $e);
            }

            throw $e;
        } finally {
            $this->backupPath = null;
        }

        return $candidate->refresh();
    }

    /**
     * @throws JsonException
     */
    private function splitLocked(CardMatchCandidate $candidate, User $user, string $planHash): void
    {
        // 1) propozycja zablokowana do końca transakcji — dwa kliknięcia naraz nie rozdzielą karty dwa razy
        $locked = CardMatchCandidate::query()->lockForUpdate()->find($candidate->id);
        if (! $locked instanceof CardMatchCandidate) {
            throw new DomainException('Propozycja #'.$candidate->id.' już nie istnieje — odśwież listę.');
        }
        if ((string) $locked->kind !== CardMatchCandidate::KIND_SPLIT || $locked->status !== CardMatchCandidate::STATUS_PENDING) {
            throw new DomainException('Propozycja #'.$locked->id.' nie jest rozdzielaniem do decyzji.');
        }
        // ekran pokazywał inny plan niż zapisany (odświeżenie w międzyczasie)
        if (! hash_equals((string) $locked->plan_hash, $planHash)) {
            throw new CardMatchPlanChanged('Propozycja zmieniła się od wczytania ekranu — odśwież listę.');
        }
        $plan = is_array($locked->plan) ? $locked->plan : [];
        $targetIds = self::planTargetIds($plan);
        if (count($targetIds) < 2) {
            throw new DomainException('Plan propozycji #'.$locked->id.' nie wskazuje co najmniej dwóch kart producenta — odśwież propozycje.');
        }
        $sourceId = (int) $locked->source_product_id;

        // 2) konta — przed kartami: przebieg, który zechce zająć konto teraz, poczeka na commit i wczyta nową mapę
        $this->lockAccounts($sourceId, $plan, $targetIds);

        // 3) karty rosnąco po id
        $ids = [$sourceId, ...$targetIds];
        sort($ids);
        $cards = Product::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($ids as $id) {
            if (! $cards->has($id)) {
                throw new DomainException('Karta #'.$id.' już nie istnieje — odśwież propozycje.');
            }
        }
        /** @var Product $source */
        $source = $cards->get($sourceId);

        // 4) ponowne sprawdzenie: ten sam rodzaj, ten sam plan i pewna propozycja — pozycje z tej oceny
        $positions = $this->verifiedPositions($source, $locked);
        /** @var array<int, Product> $targets */
        $targets = [];
        foreach ($targetIds as $id) {
            $targets[$id] = $cards->get($id);
        }
        foreach ($positions as $position) {
            if (! isset($targets[$position['target_product_id']])) {
                throw new CardMatchPlanChanged('Plan pozycji zmienił się od odświeżenia propozycji — odśwież listę.');
            }
        }

        // 5) strażnicy
        $this->guardSource($source);
        $this->guardTargets($source, $targets, $positions);
        $sourceSlots = ProductSourcePrice::query()->where('product_id', $sourceId)->orderBy('id')->get()->keyBy('source_key');
        $this->guardPositions($source, $positions, $sourceSlots);

        // 6) stan sprzed decyzji
        $cardsBefore = [];
        foreach ([$source, ...array_values($targets)] as $card) {
            $cardsBefore[] = [
                'id' => (int) $card->id,
                'sku' => (string) $card->sku,
                'name' => (string) $card->name,
                'purchase_price' => $card->purchase_price !== null ? (string) $card->purchase_price : null,
                'currency' => $card->currency !== null ? (string) $card->currency : null,
            ];
        }
        $sourceSnapshot = [
            'sku' => (string) $source->sku,
            'name' => (string) $source->name,
            'manufacturer' => (string) $source->manufacturer,
        ];

        // 7) pełna kopia zapasowa — po strażnikach, odmowa nie zostawia pliku
        $backupPath = $this->writeBackup($locked, $source, $targets, $positions, $user);

        // 8) mapa połączeń — kody i etykiety pozycji z planu, przed przeniesieniem powiązań
        $this->redirects->recordSplit(array_map(static fn (array $p): array => [
            'source_key' => $p['source_key'],
            'position_key' => $p['position_key'],
            'remote_sku' => $p['remote_sku'],
            'label' => $p['label'],
            'target' => $targets[$p['target_product_id']],
        ], $positions), $locked, $user);

        // 9) inne propozycje karty dystrybutora wskazują kartę, której zaraz nie będzie; odrzucone i połączone zostają
        CardMatchCandidate::query()
            ->where('source_product_id', $sourceId)
            ->where('id', '!=', $locked->id)
            ->whereIn('status', [CardMatchCandidate::STATUS_PENDING, CardMatchCandidate::STATUS_CONFLICT])
            ->delete();

        // 10) powiązania i identyfikatory pozycji (także wycofane identyfikatory tej pozycji) → karta producenta
        $identifiersMoved = 0;
        foreach ($positions as $position) {
            B2bProductLink::query()
                ->where('b2b_account_id', self::accountIdOf($position['source_key']))
                ->where('remote_id', $position['position_key'])
                ->where('product_id', $sourceId)
                ->update(['product_id' => $position['target_product_id'], 'merged_at' => null]);
            $identifiersMoved += ProductIdentifier::query()
                ->where('product_id', $sourceId)
                ->where('source_key', $position['source_key'])
                ->where('position_key', $position['position_key'])
                ->update(['product_id' => $position['target_product_id']]);
        }

        // 11) slot ceny, historia, tabelka sklepu każdego konta na kartach, które dostały jego pozycje; odrzucone zdjęcia
        $slotsReplaced = $this->copySlotsAndShopCards($source, $positions, $sourceSlots);
        $this->copyImageRejections($sourceId, array_keys($targets));

        // 12) cenniki: wpis konta — karty producenta jego pozycji, pozostałe — bez karty dystrybutora
        $priceLists = $this->remapPriceLists($sourceId, $positions);

        // 13) karta dystrybutora znika — bez powiązań (wszystkie były pozycjami planu); wiersze mapy spoza planu,
        // które na nią wskazywały, zostaną decyzją bez karty (klucz obcy)
        $leftLinks = B2bProductLink::query()->where('product_id', $sourceId)->pluck('remote_id')->map(static fn ($id): string => (string) $id)->all();
        if ($leftLinks !== []) {
            throw new DomainException('Na karcie dystrybutora zostały powiązania spoza planu ('.implode(', ', $leftLinks).') — odśwież propozycje.');
        }
        $orphaned = CardRedirect::query()->where('product_id', $sourceId)->orderBy('source_key')->orderBy('position_key')->get(['source_key', 'position_key'])
            ->map(static fn (CardRedirect $r): array => ['source_key' => (string) $r->source_key, 'position_key' => (string) $r->position_key])
            ->all();
        $identifiersDropped = ProductIdentifier::query()->where('product_id', $sourceId)->count();
        Product::query()->whereKey($sourceId)->delete();

        // 14) ceny i tabelki kart producenta; przetarg liczy z ceny karty — zmiana ceny karty w przetargu to odmowa
        $cardChanges = [];
        foreach ($targets as $id => $target) {
            $changes = $this->effectivePrices->refresh($target);
            if ($changes !== []) {
                $this->refuseTenderPriceChange($target, $changes);
                $cardChanges[(string) $id] = $changes;
            }
            B2bCatalogSync::refreshShopFieldsSummary($target->refresh());
        }

        // 15) decyzja
        $locked->forceFill([
            'status' => CardMatchCandidate::STATUS_MERGED,
            'backup_path' => $backupPath,
            'decided_by' => $user->id,
            'decided_at' => now(),
            'source_snapshot' => $sourceSnapshot,
            'decision_input' => [
                'kind' => CardMatchCandidate::KIND_SPLIT,
                'plan_hash' => (string) $locked->plan_hash,
                'source_product_id' => $sourceId,
                'target_product_ids' => array_map('intval', array_keys($targets)),
                'positions' => array_map(static fn (array $p): array => [
                    'source_key' => $p['source_key'],
                    'source_label' => $p['source_label'],
                    'position_key' => $p['position_key'],
                    'remote_sku' => $p['remote_sku'],
                    'label' => $p['label'],
                    'target_product_id' => $p['target_product_id'],
                    'target_sku' => (string) $targets[$p['target_product_id']]->sku,
                ], $positions),
                'cards_before' => $cardsBefore,
                'card_changes' => $cardChanges,
                'slots_replaced' => $slotsReplaced,
                'redirects_orphaned' => $orphaned,
                'identifiers_moved' => $identifiersMoved,
                'identifiers_dropped' => $identifiersDropped,
                'price_lists' => $priceLists,
            ],
        ])->save();

        // 16) dziennik aktywności — karty dystrybutora już nie ma, więc przy propozycji
        $this->activity->log('card_match.split', $user, $locked, [
            'label' => 'Rozdzielenie: '.$sourceSnapshot['sku'].' → '.implode(', ', array_map(static fn (Product $p): string => (string) $p->sku, array_values($targets))),
            'candidate_id' => (int) $locked->id,
            'backup_path' => $backupPath,
            'source_product_id' => $sourceId,
            'target_product_ids' => array_map('intval', array_keys($targets)),
        ]);

        // po commit: wektor usuniętej karty i reindeks kart producenta (tabelka sklepu) — błąd nie cofa decyzji
        $targetIds = array_map('intval', array_keys($targets));
        DB::afterCommit(function () use ($sourceId, $targetIds): void {
            try {
                $this->embeddings->delete($sourceId);
            } catch (Throwable) {
                // wektor usuniętej karty bez karty w bazie nie trafia do wyników — sprzątanie nie blokuje rozdzielenia
            }
            foreach ($targetIds as $id) {
                try {
                    ReindexProductEmbeddingJob::dispatch($id, true);
                } catch (Throwable) {
                    // kolejka embeddingów nie blokuje rozdzielenia
                }
            }
        });
    }

    /**
     * Unikalne karty docelowe pozycji planu, rosnąco.
     *
     * @param  array<string, mixed>  $plan
     * @return list<int>
     */
    private static function planTargetIds(array $plan): array
    {
        $ids = [];
        foreach (is_array($plan['positions'] ?? null) ? $plan['positions'] : [] as $position) {
            if (is_array($position) && isset($position['target_product_id'])) {
                $ids[(int) $position['target_product_id']] = true;
            }
        }
        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    private static function accountIdOf(string $sourceKey): int
    {
        return str_starts_with($sourceKey, 'b2b:') ? (int) substr($sourceKey, 4) : 0;
    }

    /**
     * Konta, których przebieg zapisałby w połowie rozdzielenia: konta powiązań karty dystrybutora, konta pozycji planu
     * i konta-właściciele kart producenta — wiersze zablokowane do końca transakcji, konto z przebiegiem → odmowa.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<int>  $targetIds
     */
    private function lockAccounts(int $sourceId, array $plan, array $targetIds): void
    {
        $accountIds = B2bProductLink::query()->where('product_id', $sourceId)->distinct()->pluck('b2b_account_id')
            ->map(static fn (mixed $id): int => (int) $id)->all();
        foreach (is_array($plan['positions'] ?? null) ? $plan['positions'] : [] as $position) {
            if (is_array($position)) {
                $accountIds[] = self::accountIdOf((string) ($position['source_key'] ?? ''));
            }
        }
        foreach (Product::query()->whereIn('id', $targetIds)->get() as $target) {
            foreach ($this->ownership->ownerSourceKeys($target) as $key) {
                $accountIds[] = self::accountIdOf($key);
            }
        }
        $running = B2bAccountSyncRunner::lockIdle($accountIds);
        if ($running !== null) {
            throw new DomainException('Trwa synchronizacja konta '.$this->labels->accountLabel($running).' — spróbuj po jej zakończeniu.');
        }
    }

    /**
     * Ponowne sprawdzenie kluczem: rodzaj split, ten sam skrót planu (te same pozycje w te same karty) i pewna
     * propozycja. Inny rodzaj albo plan — ekran pokazywał co innego (409); niepewna — powód (422). Wynik: pozycje planu
     * z tej oceny, każda z jedną kartą producenta.
     *
     * @return list<array{source_key: string, source_label: string, position_key: string, remote_sku: string|null, label: string|null, target_product_id: int}>
     */
    private function verifiedPositions(Product $source, CardMatchCandidate $locked): array
    {
        $result = $this->container->make(CardMatchFinder::class)->evaluate($source, true);
        if ($result === null) {
            throw new CardMatchPlanChanged('Karta dystrybutora nie ma już wspólnego klucza z kartami producenta — odśwież listę.');
        }
        $kind = (string) ($result['kind'] ?? CardMatchCandidate::KIND_MERGE);
        if ($kind !== CardMatchCandidate::KIND_SPLIT) {
            $what = $kind === CardMatchCandidate::KIND_SIZE_MERGE ? 'łączenie rozmiarów' : 'połączenie z jedną kartą producenta';

            throw new CardMatchPlanChanged('Propozycja zmieniła rodzaj — ponowne sprawdzenie daje '.$what.'. Odśwież listę.');
        }
        if ((string) ($result['plan_hash'] ?? '') !== (string) $locked->plan_hash) {
            throw new CardMatchPlanChanged('Plan pozycji zmienił się od odświeżenia propozycji — odśwież listę.');
        }
        if (($result['status'] ?? null) !== CardMatchCandidate::STATUS_PENDING) {
            $reason = trim((string) ($result['reason'] ?? ''));

            throw new DomainException('Ponowne sprawdzenie dało propozycję niepewną'.($reason !== '' ? ': '.$reason : '').'.');
        }

        $positions = [];
        foreach (is_array($result['plan']['positions'] ?? null) ? $result['plan']['positions'] : [] as $position) {
            if (! is_array($position) || ! isset($position['target_product_id'])) {
                throw new CardMatchPlanChanged('Plan pozycji zmienił się od odświeżenia propozycji — odśwież listę.');
            }
            $positions[] = [
                'source_key' => (string) $position['source_key'],
                'source_label' => (string) ($position['source_label'] ?? ''),
                'position_key' => (string) $position['position_key'],
                'remote_sku' => isset($position['remote_sku']) ? (string) $position['remote_sku'] : null,
                'label' => isset($position['label']) ? (string) $position['label'] : null,
                'target_product_id' => (int) $position['target_product_id'],
            ];
        }
        if ($positions === []) {
            throw new CardMatchPlanChanged('Plan pozycji zmienił się od odświeżenia propozycji — odśwież listę.');
        }

        return $positions;
    }

    /**
     * Karta dystrybutora: to, czego rozdzielenie nie przenosi (wersje, także wycofane — skasowałaby je kaskada; ceny
     * specjalne, akcesoria, Presta, przetargi, zamienniki) i cena z pliku (plik po rozdzieleniu nie ma karty).
     */
    private function guardSource(Product $source): void
    {
        foreach (self::SOURCE_BLOCKING as $table => [$columns, $label]) {
            $count = DB::table($table)
                ->where(static function (Builder $q) use ($columns, $source): void {
                    foreach ($columns as $column) {
                        $q->orWhere($column, $source->id);
                    }
                })
                ->count();
            if ($count > 0) {
                throw new DomainException('Karta dystrybutora '.$source->sku.' '.$label.' ('.$count.') — rozdzielenie wyłączone.');
            }
        }
        if (ProductSourcePrice::query()->where('product_id', $source->id)->where('source_key', ProductSourcePrice::SOURCE_FILE)->exists()) {
            throw new DomainException('Karta dystrybutora '.$source->sku.' ma cenę z pliku — rozdzielenie jej nie przenosi.');
        }
    }

    /**
     * Karty producenta: ta sama marka, bez aktywnych wersji, z właścicielem i bez pozycji kont, których pozycje
     * przechodzą (druga pozycja konta na karcie — sztuka czy karton?, a slot konta jest jeden na kartę).
     *
     * @param  array<int, Product>  $targets
     * @param  list<array{source_key: string, position_key: string, target_product_id: int}>  $positions
     */
    private function guardTargets(Product $source, array $targets, array $positions): void
    {
        $movedAccounts = [];
        foreach ($positions as $position) {
            $movedAccounts[self::accountIdOf($position['source_key'])] = true;
        }
        foreach ($targets as $target) {
            if (! CanonicalBrand::same($source->manufacturer, $target->manufacturer)) {
                throw new DomainException('Karta '.$target->sku.' jest innej marki niż karta dystrybutora („'
                    .$target->manufacturer.'” / „'.$source->manufacturer.'”).');
            }
            $variants = DB::table('product_variants')->where('product_id', $target->id)->whereNull('removed_at')->count();
            if ($variants > 0) {
                throw new DomainException('Karta '.$target->sku.' ma wersje ('.$variants.') — rozdzielenie wyłączone.');
            }
            if ($this->ownership->ownerSourceKeys($target) === []) {
                throw new DomainException('Karta '.$target->sku.' nie ma już właściciela (konta B2B ani cennika producenta) — odśwież propozycje.');
            }
            $taken = B2bProductLink::query()
                ->where('product_id', $target->id)
                ->whereIn('b2b_account_id', array_keys($movedAccounts))
                ->orderBy('id')
                ->get(['b2b_account_id', 'remote_id']);
            if ($taken->isNotEmpty()) {
                $account = B2bAccount::query()->find((int) $taken->first()->b2b_account_id);

                throw new DomainException('Karta '.$target->sku.' ma już pozycję konta '.$this->labels->accountLabel($account).' ('
                    .$taken->pluck('remote_id')->implode(', ').') — sztuka czy karton? Rozdzielenie wyłączone.');
            }
        }
    }

    /**
     * Pozycje planu: tylko kont B2B; powiązanie pozycji na karcie dystrybutora albo bez powiązania; wpis mapy pozycji
     * bez innej decyzji; cena pozycji (ostatnio widziana przy powiązaniu) równa cenie slotu konta, który przejdzie na
     * kartę producenta — inaczej kopia slotu dałaby karcie producenta cenę innej pozycji.
     *
     * @param  list<array{source_key: string, position_key: string, remote_sku: string|null, target_product_id: int}>  $positions
     * @param  Collection<string, ProductSourcePrice>  $sourceSlots
     */
    private function guardPositions(Product $source, array $positions, Collection $sourceSlots): void
    {
        foreach ($positions as $position) {
            $code = $position['remote_sku'] ?? $position['position_key'];
            if (! str_starts_with($position['source_key'], 'b2b:')) {
                throw new DomainException('Pozycja '.$code.' pochodzi z pliku — rozdzielenie przenosi tylko pozycje kont B2B.');
            }
            $link = B2bProductLink::query()
                ->where('b2b_account_id', self::accountIdOf($position['source_key']))
                ->where('remote_id', $position['position_key'])
                ->first(['product_id', 'last_purchase_price', 'last_currency']);
            if ($link !== null && (int) $link->product_id !== (int) $source->id) {
                throw new DomainException('Pozycja '.$code.' jest już na innej karcie (#'.$link->product_id.') — odśwież propozycje.');
            }
            $redirect = CardRedirect::query()
                ->where('source_key', $position['source_key'])
                ->where('position_key', $position['position_key'])
                ->first(['product_id']);
            if ($redirect !== null && $redirect->product_id !== null
                && ! in_array((int) $redirect->product_id, [(int) $source->id, $position['target_product_id']], true)) {
                throw new DomainException('Pozycja '.$code.': mapa połączeń kieruje ją już na kartę #'.$redirect->product_id.' — odśwież propozycje.');
            }
            $slot = $sourceSlots->get($position['source_key']);
            if ($link === null || $slot === null || $link->last_purchase_price === null || $slot->purchase_price === null) {
                continue;
            }
            $samePrice = round((float) $link->last_purchase_price, 2) === round((float) $slot->purchase_price, 2);
            $linkCurrency = strtoupper(trim((string) $link->last_currency));
            $slotCurrency = strtoupper(trim((string) $slot->currency));
            if (! $samePrice || ($linkCurrency !== '' && $slotCurrency !== '' && $linkCurrency !== $slotCurrency)) {
                throw new DomainException('Pozycja '.$code.' ma ostatnio cenę '.self::money($link->last_purchase_price, $link->last_currency)
                    .', a karta dystrybutora '.self::money($slot->purchase_price, $slot->currency)
                    .' — pozycje w różnych cenach, rozdzielenie wyłączone.');
            }
        }
    }

    private static function money(mixed $value, mixed $currency): string
    {
        $currency = strtoupper(trim((string) $currency));

        return number_format((float) $value, 2, ',', ' ').' '.($currency !== '' ? $currency : 'PLN');
    }

    /**
     * Slot ceny, wiersz historii i tabelka sklepu każdego konta dystrybutora na kartach producenta, które dostały jego
     * pozycje. Slot to kopia slotu karty dystrybutora z datą potwierdzenia przez dostawcę (checked_at bez zmian);
     * dostępność — tylko gdy konto miało na karcie jedną pozycję (przy kilku to tekst całej grupy, przebieg wpisze
     * dostępność pozycji). Slot albo tabelka tego konta, które karta producenta już miała bez powiązania konta
     * (sierota — strażnik wyklucza powiązanie), są zastępowane: cena pozycji, której na karcie nie ma, nie wygrywa
     * z bieżącą. Wiersz historii należy do przebiegu konta — inaczej pierwsza zmiana ceny wyglądałaby jak dodanie ceny.
     *
     * @param  list<array{source_key: string, target_product_id: int}>  $positions
     * @param  Collection<string, ProductSourcePrice>  $sourceSlots
     * @return list<array{product_id: int, source_key: string, purchase_price: string|null, currency: string|null, checked_at: string|null}>
     */
    private function copySlotsAndShopCards(Product $source, array $positions, Collection $sourceSlots): array
    {
        /** @var array<int, array<int, true>> $targetsByAccount */
        $targetsByAccount = [];
        /** @var array<int, int> $positionsByAccount */
        $positionsByAccount = [];
        foreach ($positions as $position) {
            $accountId = self::accountIdOf($position['source_key']);
            $targetsByAccount[$accountId][$position['target_product_id']] = true;
            $positionsByAccount[$accountId] = ($positionsByAccount[$accountId] ?? 0) + 1;
        }

        $replaced = [];
        foreach ($targetsByAccount as $accountId => $targetIds) {
            $sourceKey = ProductSourcePrice::b2bKey($accountId);
            $slot = $sourceSlots->get($sourceKey);
            $shopCard = ProductShopCard::query()->where('product_id', $source->id)->where('b2b_account_id', $accountId)->first();
            $reference = $slot !== null ? $this->historyReference((int) $source->id, $accountId) : null;
            foreach (array_keys($targetIds) as $targetId) {
                if ($slot !== null) {
                    $existing = ProductSourcePrice::query()->where('product_id', $targetId)->where('source_key', $sourceKey)->first();
                    if ($existing !== null) {
                        $replaced[] = [
                            'product_id' => (int) $targetId,
                            'source_key' => $sourceKey,
                            'purchase_price' => $existing->purchase_price !== null ? (string) $existing->purchase_price : null,
                            'currency' => $existing->currency !== null ? (string) $existing->currency : null,
                            'checked_at' => $existing->checked_at?->toIso8601String(),
                        ];
                        $existing->delete();
                    }
                    $copy = $slot->replicate();
                    $copy->product_id = $targetId;
                    if ($positionsByAccount[$accountId] > 1) {
                        $copy->availability = null;
                    }
                    $copy->save();

                    ProductPriceHistory::query()->create([
                        'product_id' => $targetId,
                        'price_list_id' => $reference['price_list_id'],
                        'b2b_sync_run_id' => $reference['b2b_sync_run_id'],
                        'catalog_price_net' => $copy->catalog_price_net,
                        'purchase_price' => $copy->purchase_price,
                        'currency' => $copy->currency,
                        'source' => $reference['source'],
                    ]);
                }
                if ($shopCard !== null) {
                    ProductShopCard::query()->where('product_id', $targetId)->where('b2b_account_id', $accountId)->delete();
                    $copy = $shopCard->replicate();
                    $copy->product_id = $targetId;
                    $copy->save();
                }
            }
        }

        return $replaced;
    }

    /**
     * Przebieg, cennik i źródło wiersza historii ceny konta na karcie producenta: z ostatniego wiersza historii karty
     * dystrybutora z przebiegu tego konta, a bez niego — ostatni przebieg konta i jego stały wpis w Cennikach.
     *
     * @return array{b2b_sync_run_id: int|null, price_list_id: int|null, source: string}
     */
    private function historyReference(int $sourceId, int $accountId): array
    {
        $row = DB::table('product_price_history as h')
            ->join('b2b_sync_runs as r', 'r.id', '=', 'h.b2b_sync_run_id')
            ->where('h.product_id', $sourceId)
            ->where('r.b2b_account_id', $accountId)
            ->orderByDesc('h.id')
            ->first(['h.b2b_sync_run_id', 'h.price_list_id', 'h.source']);
        $account = B2bAccount::query()->find($accountId);
        $source = 'b2b:'.($account !== null ? (string) $this->connectors->keyForAccount($account) : 'b2b');
        if ($row !== null) {
            return [
                'b2b_sync_run_id' => (int) $row->b2b_sync_run_id,
                'price_list_id' => $row->price_list_id !== null ? (int) $row->price_list_id : null,
                'source' => trim((string) $row->source) !== '' ? (string) $row->source : $source,
            ];
        }
        $runId = B2bSyncRun::query()->where('b2b_account_id', $accountId)->orderByDesc('id')->value('id');

        return [
            'b2b_sync_run_id' => $runId !== null ? (int) $runId : null,
            'price_list_id' => $account?->last_price_list_id !== null ? (int) $account->last_price_list_id : null,
            'source' => $source,
        ];
    }

    /**
     * Zdjęcie odrzucone na karcie dystrybutora nie wraca z galerii jego pozycji na żadną kartę producenta.
     *
     * @param  list<int>  $targetIds
     */
    private function copyImageRejections(int $sourceId, array $targetIds): void
    {
        $rejections = ProductImageRejection::query()->where('product_id', $sourceId)->orderBy('id')->get();
        if ($rejections->isEmpty()) {
            return;
        }
        foreach ($targetIds as $targetId) {
            $taken = array_fill_keys(
                ProductImageRejection::query()->where('product_id', $targetId)->pluck('file_key_hash')->map(static fn ($h): string => (string) $h)->all(),
                true,
            );
            foreach ($rejections as $rejection) {
                $hash = (string) $rejection->file_key_hash;
                if (isset($taken[$hash])) {
                    continue;
                }
                $taken[$hash] = true;
                $copy = $rejection->replicate();
                $copy->product_id = $targetId;
                $copy->save();
            }
        }
    }

    /**
     * Cenniki z kartą dystrybutora na liście: stały wpis konta (b2b_accounts.last_price_list_id) dostaje karty
     * producenta pozycji tego konta w miejscu karty dystrybutora, z pozostałych karta tylko znika.
     * price_list_imports zostają bez zmian (od nich zależy cofnięcie importu).
     *
     * @param  list<array{source_key: string, target_product_id: int}>  $positions
     * @return list<int> zmienione cenniki
     */
    private function remapPriceLists(int $sourceId, array $positions): array
    {
        /** @var array<int, list<int>> $targetsByAccount */
        $targetsByAccount = [];
        foreach ($positions as $position) {
            $accountId = self::accountIdOf($position['source_key']);
            if (! in_array($position['target_product_id'], $targetsByAccount[$accountId] ?? [], true)) {
                $targetsByAccount[$accountId][] = $position['target_product_id'];
            }
        }
        $listAccounts = [];
        foreach (B2bAccount::query()->whereIn('id', array_keys($targetsByAccount))->whereNotNull('last_price_list_id')->get(['id', 'last_price_list_id']) as $account) {
            $listAccounts[(int) $account->last_price_list_id] = (int) $account->id;
        }

        $changed = [];
        foreach (PriceList::query()->whereNotNull('product_ids')->cursor() as $list) {
            $ids = is_array($list->product_ids) ? array_map('intval', $list->product_ids) : [];
            if (! in_array($sourceId, $ids, true)) {
                continue;
            }
            $replacement = isset($listAccounts[(int) $list->id]) ? $targetsByAccount[$listAccounts[(int) $list->id]] : [];
            $next = [];
            foreach ($ids as $id) {
                foreach ($id === $sourceId ? $replacement : [$id] as $nid) {
                    if (! in_array($nid, $next, true)) {
                        $next[] = $nid;
                    }
                }
            }
            $list->update(['product_ids' => $next]);
            $changed[] = (int) $list->id;
        }

        return $changed;
    }

    /**
     * Przetarg liczy z ceny karty (decyzja 23.09.2026: w przetargach cena producenta). Gdy cena karty producenta
     * zmienia się przez slot dystrybutora (cena producenta wyłączona w oknie „Producenci” albo tylko cennik
     * sugerowany), a karta jest w przetargu — odmowa.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    private function refuseTenderPriceChange(Product $target, array $changes): void
    {
        $tenders = DB::table('tender_items')
            ->where(static fn (Builder $q) => $q->where('main_product_id', $target->id)->orWhere('companion_product_id', $target->id))
            ->count();
        if ($tenders === 0) {
            return;
        }
        $before = $changes['purchase_price'][0] ?? null;
        $after = $changes['purchase_price'][1] ?? null;

        throw new DomainException('Karta '.$target->sku.' jest w pozycjach przetargów ('.$tenders.'), a rozdzielenie zmieniłoby jej cenę'
            .($before !== null || $after !== null ? ' ('.self::money($before, $changes['currency'][0] ?? $target->currency).' → '.self::money($after, $changes['currency'][1] ?? $target->currency).')' : '')
            .' — rozdzielenie wyłączone.');
    }

    /**
     * Pełna kopia zapasowa przed rozdzieleniem (CardMatchBackup): karta dystrybutora i karty producenta z wierszami,
     * które rozdzielenie przenosi, kopiuje albo kasuje, oraz plan pozycja → karta tej decyzji.
     *
     * @param  array<int, Product>  $targets
     * @param  list<array{source_key: string, position_key: string, target_product_id: int}>  $positions
     *
     * @throws JsonException
     */
    private function writeBackup(CardMatchCandidate $candidate, Product $source, array $targets, array $positions, User $user): string
    {
        $cards = [['role' => 'source', 'product' => $source]];
        foreach ($targets as $target) {
            $cards[] = ['role' => 'target', 'product' => $target];
        }

        $path = $this->backup->write('card-match-split', 'card-match-split', self::NOTHING_DONE, $candidate, $user, $cards, [
            'source_product_id' => (int) $source->id,
            'target_product_ids' => array_map('intval', array_keys($targets)),
            'positions' => array_map(static fn (array $p): array => [
                'source_key' => $p['source_key'],
                'position_key' => $p['position_key'],
                'target_product_id' => $p['target_product_id'],
            ], $positions),
        ]);
        $this->backupPath = $path;

        return $path;
    }
}
