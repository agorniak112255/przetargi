<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\CardMatchCandidate;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\B2b\B2bCatalogSync;
use App\Services\Pricing\SourcePriceComparison;
use App\Services\ProductSizeMergeService;
use App\Support\CanonicalBrand;
use DomainException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Throwable;

/**
 * „Połącz rozmiary” na ekranie „Łączenie kart” (plan łączenia kart, krok 6): producent ma osobną kartę na każdy
 * rozmiar w tej samej cenie, a dystrybutor jedną kartę modelu. Przykład z produkcji: 3M 6100 S #40819, 6200 M #40815,
 * 6300 L #40814 (po 61,38 zł, konto 3M) i P4S „6X00 Półmaska 3M 6000” #56362 z trzema rozmiarami. Człowiek wybiera
 * kartę, która zostaje, i nazwę karty modelu; karty rozmiarów wchodzą w nią (ProductSizeMergeService::mergeSizeCards),
 * a karta dystrybutora dołącza do karty modelu jak przy zwykłym połączeniu (CardMatchMerger::attachWithin).
 *
 * Jedna transakcja: przy każdej odmowie i każdym błędzie nic się nie zmienia. Przed zmianą — ponowne sprawdzenie planu
 * (CardMatchFinder::evaluate: ten sam rodzaj i ten sam skrót planu), strażnicy kart i pełna kopia zapasowa JSON.
 * Mapa połączeń: pozycje właścicieli kart rozmiarów → karta modelu (size_merge, pozycja karty, która zostaje, wiodąca),
 * pozycje dystrybutora → karta modelu (merge).
 */
final class CardMatchSizeMerger
{
    private const NAME_MIN = 3;

    private const NAME_MAX = 1000;

    /** kopia zapasowa bieżącego łączenia — usuwana, gdy transakcja się wycofa */
    private ?string $backupPath = null;

    /**
     * CardMatchFinder z kontenera przy użyciu (klasa final — atrapa w testach przez $app->instance), jak
     * w CardMatchMerger.
     */
    public function __construct(
        private readonly ProductSizeMergeService $sizeMerge,
        private readonly CardMatchMerger $merger,
        private readonly CardRedirectStore $redirects,
        private readonly CardOwnership $ownership,
        private readonly ActivityLogger $activity,
        private readonly Container $container,
        private readonly SourcePriceComparison $labels,
    ) {}

    /**
     * @throws CardMatchPlanChanged propozycja zmieniła się od wczytania ekranu (409, nic nie zmienione)
     * @throws DomainException z powodem po polsku (422, nic nie zmienione)
     */
    public function merge(CardMatchCandidate $candidate, User $user, int $keepProductId, string $name, ?string $variantSummary, string $planHash): CardMatchCandidate
    {
        $this->backupPath = null;
        try {
            DB::transaction(function () use ($candidate, $user, $keepProductId, $name, $variantSummary, $planHash): void {
                $this->mergeLocked($candidate, $user, $keepProductId, $name, $variantSummary, $planHash);
            });
        } catch (Throwable $e) {
            // transakcja wycofana — kopia zapasowa łączenia, którego nie było, tylko by myliła przy odtwarzaniu
            if ($this->backupPath !== null && is_file($this->backupPath)) {
                @unlink($this->backupPath);
            }
            if ($e instanceof JsonException) {
                throw new DomainException('Kopia zapasowa nie powstała: '.$e->getMessage().' — nic nie połączono.', 0, $e);
            }

            throw $e;
        } finally {
            $this->backupPath = null;
        }

        return $candidate->refresh();
    }

    /**
     * Domyślna lista rozmiarów karty modelu: „Rozmiary: S (mały) (7000146845); M (średni) (7000146847); …” —
     * w kolejności podpowiedzi planu (plan.suggested.sizes), etykieta rozmiaru z pozycji dystrybutora i SKU karty
     * producenta tego rozmiaru; bez etykiety — sam SKU. Karta, której już nie ma — kod z planu.
     *
     * @param  list<mixed>  $sizes  plan.suggested.sizes
     * @param  array<int, string>  $skuById  SKU kart docelowych
     */
    public static function defaultVariantSummary(array $sizes, array $skuById): string
    {
        $parts = [];
        foreach ($sizes as $size) {
            if (! is_array($size)) {
                continue;
            }
            $id = (int) ($size['product_id'] ?? 0);
            $sku = trim((string) ($skuById[$id] ?? ''));
            if ($sku === '') {
                $sku = trim((string) ($size['code'] ?? ''));
            }
            $label = trim((string) ($size['label'] ?? ''));
            $part = match (true) {
                $label !== '' && $sku !== '' => $label.' ('.$sku.')',
                $label !== '' => $label,
                default => $sku,
            };
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        // plan bez podpowiedzi rozmiarów — bez listy (samo „Rozmiary: ” byłoby pustą obietnicą)
        return $parts === [] ? '' : mb_substr('Rozmiary: '.implode('; ', $parts), 0, B2bCatalogSync::VARIANT_SUMMARY_LIMIT);
    }

    /**
     * @throws JsonException
     */
    private function mergeLocked(CardMatchCandidate $candidate, User $user, int $keepProductId, string $name, ?string $variantSummary, string $planHash): void
    {
        // 1) propozycja zablokowana do końca transakcji — dwa kliknięcia naraz nie połączą kart dwa razy
        $locked = CardMatchCandidate::query()->lockForUpdate()->find($candidate->id);
        if (! $locked instanceof CardMatchCandidate) {
            throw new DomainException('Propozycja #'.$candidate->id.' już nie istnieje — odśwież listę.');
        }
        if ((string) $locked->kind !== CardMatchCandidate::KIND_SIZE_MERGE || $locked->status !== CardMatchCandidate::STATUS_PENDING) {
            throw new DomainException('Propozycja #'.$locked->id.' nie jest łączeniem rozmiarów do decyzji.');
        }

        // 2) ekran pokazywał inny plan niż zapisany (odświeżenie w międzyczasie)
        if (! hash_equals((string) $locked->plan_hash, $planHash)) {
            throw new CardMatchPlanChanged('Propozycja zmieniła się od wczytania ekranu — odśwież listę.');
        }

        // 3) karty planu, karta, która zostaje, i nazwa
        $plan = is_array($locked->plan) ? $locked->plan : [];
        $targetIds = self::planTargetIds($plan);
        if (count($targetIds) < 2) {
            throw new DomainException('Plan propozycji #'.$locked->id.' nie wskazuje co najmniej dwóch kart producenta — odśwież propozycje.');
        }
        if (! in_array($keepProductId, $targetIds, true)) {
            throw new DomainException('Karta #'.$keepProductId.' nie jest w planie tej propozycji — wybierz jedną z kart planu.');
        }
        $sourceId = (int) $locked->source_product_id;
        $cards = Product::query()->whereIn('id', [$sourceId, ...$targetIds])->lockForUpdate()->get()->keyBy('id');
        foreach ([$sourceId, ...$targetIds] as $id) {
            if (! $cards->has($id)) {
                throw new DomainException('Karta #'.$id.' już nie istnieje — odśwież propozycje.');
            }
        }
        $name = mb_substr(trim($name), 0, self::NAME_MAX);
        if (mb_strlen($name) < self::NAME_MIN) {
            throw new DomainException('Nazwa karty modelu musi mieć co najmniej '.self::NAME_MIN.' znaki.');
        }
        /** @var Product $source */
        $source = $cards->get($sourceId);
        /** @var Product $keep */
        $keep = $cards->get($keepProductId);
        /** @var list<Product> $targets */
        $targets = array_values(array_map(static fn (int $id): Product => $cards->get($id), $targetIds));
        /** @var list<Product> $drops */
        $drops = array_values(array_filter($targets, static fn (Product $p): bool => (int) $p->id !== $keepProductId));
        $dropIds = array_map(static fn (Product $p): int => (int) $p->id, $drops);

        // 4) trwająca synchronizacja konta dystrybutora albo producenta zapisałaby w połowie łączenia
        $owners = [];
        foreach ($targets as $target) {
            $owners[(int) $target->id] = $this->ownership->ownerSourceKeys($target);
        }
        $this->refuseRunningSync($source, $owners);

        // 5) ponowne sprawdzenie: ten sam rodzaj i ten sam plan co na ekranie
        $this->verifyPlan($source, $locked);

        // 6) strażnicy kart docelowych
        $this->guard($source, $targets, $owners);

        // 7) pełna kopia zapasowa
        $backupPath = $this->writeBackup($locked, $source, $keep, $targets, $user);

        $skuById = [];
        foreach ($targets as $target) {
            $skuById[(int) $target->id] = (string) $target->sku;
        }
        $suggested = is_array($plan['suggested'] ?? null) ? $plan['suggested'] : [];
        $summary = trim((string) $variantSummary);
        if ($summary === '') {
            $summary = self::defaultVariantSummary(is_array($suggested['sizes'] ?? null) ? $suggested['sizes'] : [], $skuById);
        }
        $cardsBefore = [];
        foreach ([$keep, ...$drops] as $card) {
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

        // 8) mapa połączeń — powiązania i identyfikatory są jeszcze na kartach rozmiarów
        $recorded = $this->redirects->recordSizeMerge($keep, $drops, $name, $locked, $user);

        // 11) propozycje z kartami, które znikną — przed scaleniem: usunięcie karty zeruje target_product_id
        // (klucz obcy), a przepięcie odrzuconej pary na kartę modelu musi wiedzieć, którą kartę wskazywała
        $this->settleOtherCandidates($locked, $sourceId, $keepProductId, $dropIds);

        // 9) karty rozmiarów wchodzą w kartę modelu
        $images = $this->sizeMerge->mergeSizeCards($keep, $drops, $name, $summary);

        // 10) karta dystrybutora dołącza do karty modelu (ponowna weryfikacja pary w attachWithin)
        try {
            $this->merger->attachWithin($locked, $source, $keep->refresh(), $user, false);
        } catch (DomainException $e) {
            throw new DomainException('Po połączeniu rozmiarów karta dystrybutora nie daje pewnej pary: '
                .rtrim($e->getMessage(), '.').' — nic nie zmieniono.', 0, $e);
        }
        $this->sizeMerge->orderSizeMergeImages($keep->refresh(), $images['image_ids_keep'], $images['image_ids_drops']);

        // 12) decyzja
        $locked->forceFill([
            'status' => CardMatchCandidate::STATUS_MERGED,
            'backup_path' => $backupPath,
            'decided_by' => $user->id,
            'decided_at' => now(),
            'source_snapshot' => $sourceSnapshot,
            'decision_input' => [
                'keep_product_id' => $keepProductId,
                'drop_product_ids' => $dropIds,
                'attached_source_product_id' => $sourceId,
                'name' => $name,
                'name_suggested' => isset($suggested['common_name']) ? (string) $suggested['common_name'] : null,
                'variant_summary' => $summary,
                'confirm_sizes_only' => true,
                'plan_hash' => $planHash,
                'cards_before' => $cardsBefore,
                'anchors' => $recorded['anchors'],
            ],
        ])->save();

        // 13) dziennik aktywności
        $this->activity->log('card_match.size_merge', $user, $keep, [
            'label' => 'Łączenie rozmiarów: '.implode(', ', array_map(static fn (Product $p): string => (string) $p->sku, $drops))
                .' → '.$keep->sku.', dołączona karta '.$sourceSnapshot['sku'],
            'candidate_id' => (int) $locked->id,
            'backup_path' => $backupPath,
            'drop_product_ids' => $dropIds,
        ]);
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

    /**
     * @param  array<int, list<string>>  $owners  karta docelowa => źródła-właściciele
     */
    private function refuseRunningSync(Product $source, array $owners): void
    {
        $accountIds = B2bProductLink::query()->where('product_id', $source->id)->distinct()->pluck('b2b_account_id')
            ->map(static fn (mixed $id): int => (int) $id)->all();
        foreach ($owners as $keys) {
            foreach ($keys as $key) {
                if (str_starts_with($key, 'b2b:')) {
                    $accountIds[] = (int) substr($key, 4);
                }
            }
        }
        $accountIds = array_values(array_unique($accountIds));
        if ($accountIds === []) {
            return;
        }
        $run = B2bSyncRun::query()
            ->whereIn('b2b_account_id', $accountIds)
            ->where('status', B2bSyncRun::STATUS_RUNNING)
            ->orderBy('id')
            ->first();
        if ($run !== null) {
            throw new DomainException('Trwa synchronizacja konta '.$this->labels->accountLabel(B2bAccount::query()->find($run->b2b_account_id))
                .' — spróbuj po jej zakończeniu.');
        }
    }

    /**
     * Ponowne sprawdzenie kluczem: rodzaj size_merge, ten sam skrót planu (te same pozycje w te same karty) i pewna
     * propozycja. Inny rodzaj albo plan — ekran pokazywał co innego (409); niepewna — powód (422).
     */
    private function verifyPlan(Product $source, CardMatchCandidate $locked): void
    {
        $result = $this->container->make(CardMatchFinder::class)->evaluate($source, true);
        if ($result === null) {
            throw new CardMatchPlanChanged('Karta dystrybutora nie ma już wspólnego klucza z kartami producenta — odśwież listę.');
        }
        $kind = (string) ($result['kind'] ?? CardMatchCandidate::KIND_MERGE);
        if ($kind !== CardMatchCandidate::KIND_SIZE_MERGE) {
            $what = $kind === CardMatchCandidate::KIND_SPLIT ? 'rozdzielanie' : 'połączenie z jedną kartą producenta';

            throw new CardMatchPlanChanged('Propozycja zmieniła rodzaj — ponowne sprawdzenie daje '.$what.'. Odśwież listę.');
        }
        if ((string) ($result['plan_hash'] ?? '') !== (string) $locked->plan_hash) {
            throw new CardMatchPlanChanged('Plan pozycji zmienił się od odświeżenia propozycji — odśwież listę.');
        }
        if (($result['status'] ?? null) !== CardMatchCandidate::STATUS_PENDING) {
            $reason = trim((string) ($result['reason'] ?? ''));

            throw new DomainException('Ponowne sprawdzenie dało propozycję niepewną'.($reason !== '' ? ': '.$reason : '').'.');
        }
    }

    /**
     * Strażnicy kart docelowych — to, czego łączenie rozmiarów nie przenosi albo czego nie wolno łączyć bez decyzji
     * (przetargi i Presta wskazują konkretny rozmiar; wersje i ceny specjalne skasowałaby kaskada).
     *
     * @param  list<Product>  $targets
     * @param  array<int, list<string>>  $owners
     */
    private function guard(Product $source, array $targets, array $owners): void
    {
        $checks = [
            ['product_variants', ['product_id'], 'ma wersje'],
            ['product_special_prices', ['product_id'], 'ma ceny specjalne'],
            ['product_accessories', ['product_id', 'related_product_id'], 'ma akcesoria albo jest akcesorium innej karty'],
            ['presta_product_matches', ['product_id'], 'jest powiązana z Prestą'],
            ['tender_items', ['main_product_id', 'companion_product_id'], 'jest w pozycjach przetargów'],
        ];
        $signatures = [];
        foreach ($targets as $target) {
            if (! CanonicalBrand::same($source->manufacturer, $target->manufacturer)) {
                throw new DomainException('Karta '.$target->sku.' jest innej marki niż karta dystrybutora („'
                    .$target->manufacturer.'” / „'.$source->manufacturer.'”).');
            }
            foreach ($checks as [$table, $columns, $label]) {
                $count = DB::table($table)
                    ->where(static function (Builder $q) use ($columns, $target): void {
                        foreach ($columns as $column) {
                            $q->orWhere($column, $target->id);
                        }
                    })
                    ->count();
                if ($count > 0) {
                    throw new DomainException('Karta '.$target->sku.' '.$label.' ('.$count.') — łączenie rozmiarów wyłączone.');
                }
            }
            $keys = $owners[(int) $target->id] ?? [];
            if ($keys === []) {
                throw new DomainException('Karta '.$target->sku.' nie ma już właściciela (konta B2B ani cennika producenta) — odśwież propozycje.');
            }
            sort($keys);
            $fileList = in_array(ProductSourcePrice::SOURCE_FILE, $keys, true)
                ? ProductSourcePrice::query()->where('product_id', $target->id)->where('source_key', ProductSourcePrice::SOURCE_FILE)->value('price_list_id')
                : null;
            $signatures[(string) $target->sku] = implode(', ', $keys).($fileList !== null ? ' (cennik #'.$fileList.')' : '');
        }
        if (count(array_unique($signatures)) > 1) {
            $parts = [];
            foreach ($signatures as $sku => $signature) {
                $parts[] = $sku.': '.$signature;
            }

            throw new DomainException('Karty mają różnych właścicieli ('.implode('; ', $parts).') — to nie są rozmiary jednego producenta.');
        }
    }

    /**
     * Inne propozycje z kartami, które znikną: do decyzji i niepewne tej karty dystrybutora albo z łączoną kartą
     * (w karcie docelowej lub zestawie kart) — usunięte, odświeżenie policzy je od nowa na karcie modelu (P4S 6X00P
     * z M i L: potem „karta producenta ma już pozycję tego konta — sztuka czy karton?”). Odrzucone i połączone pary
     * z jedną łączoną kartą — przepięte na kartę modelu, żeby odrzucenie dalej obowiązywało (Raw-Pol → #40815 nie
     * wraca jako Raw-Pol → karta modelu); gdy taka para już jest — bez zmian.
     *
     * @param  list<int>  $dropIds
     */
    private function settleOtherCandidates(CardMatchCandidate $locked, int $sourceId, int $keepId, array $dropIds): void
    {
        $rows = CardMatchCandidate::query()
            ->where('id', '!=', $locked->id)
            ->where(static function ($q) use ($sourceId, $dropIds): void {
                $q->where('source_product_id', $sourceId)->orWhereIn('target_product_id', $dropIds);
                self::whereTargetsKeyContains($q, $dropIds);
            })
            ->orderBy('id')
            ->get();

        $delete = [];
        foreach ($rows as $row) {
            if (in_array($row->status, [CardMatchCandidate::STATUS_PENDING, CardMatchCandidate::STATUS_CONFLICT], true)) {
                if ((int) $row->source_product_id === $sourceId || self::touchesCards($row, $dropIds)) {
                    $delete[] = (int) $row->id;
                }

                continue;
            }
            if ($row->target_product_id === null || ! in_array((int) $row->target_product_id, $dropIds, true)) {
                continue;
            }
            $taken = CardMatchCandidate::query()
                ->where('source_product_id', $row->source_product_id)
                ->where('targets_key', (string) $keepId)
                ->where('id', '!=', $row->id)
                ->exists();
            if (! $taken) {
                $row->forceFill(['target_product_id' => $keepId, 'targets_key' => (string) $keepId])->save();
            }
        }
        if ($delete !== []) {
            CardMatchCandidate::query()->whereIn('id', $delete)->delete();
        }
    }

    /**
     * targets_key zawiera jedną z kart (lista id po przecinku; „gone:” i „sha1:” pomijane — nie niosą id).
     *
     * @param  list<int>  $ids
     */
    private static function whereTargetsKeyContains(mixed $query, array $ids): void
    {
        foreach ($ids as $id) {
            $query->orWhere('targets_key', (string) $id)
                ->orWhere('targets_key', 'like', $id.',%')
                ->orWhere('targets_key', 'like', '%,'.$id)
                ->orWhere('targets_key', 'like', '%,'.$id.',%');
        }
    }

    /**
     * @param  list<int>  $ids
     */
    private static function touchesCards(CardMatchCandidate $row, array $ids): bool
    {
        if ($row->target_product_id !== null && in_array((int) $row->target_product_id, $ids, true)) {
            return true;
        }
        $key = (string) $row->targets_key;
        if ($key === '' || str_starts_with($key, 'gone:') || str_starts_with($key, 'sha1:')) {
            return false;
        }

        return array_intersect(array_map('intval', explode(',', $key)), $ids) !== [];
    }

    /**
     * Pełna kopia zapasowa przed łączeniem: wszystkie karty (dystrybutora i rozmiarów) z wierszami, które scalenia
     * przenoszą albo kasują, wpisy mapy połączeń tych kart i ich pozycji (nowa decyzja nadpisuje wiersz pozycji),
     * propozycje z tymi kartami, zamienniki, akcesoria, pozycje przetargów (także produkt dodatkowy) i cenniki z tymi
     * kartami na liście.
     *
     * @param  list<Product>  $targets
     *
     * @throws JsonException
     */
    private function writeBackup(CardMatchCandidate $candidate, Product $source, Product $keep, array $targets, User $user): string
    {
        $ids = [(int) $source->id];
        foreach ($targets as $target) {
            $ids[] = (int) $target->id;
        }

        $cards = [];
        foreach ([$source, ...$targets] as $product) {
            $rows = [];
            foreach (CardMatchMerger::BACKUP_TABLES as $table => $column) {
                if (Schema::hasTable($table)) {
                    $rows[$table] = self::rows(DB::table($table)->where($column, $product->id));
                }
            }
            $cards[] = [
                'role' => match (true) {
                    (int) $product->id === (int) $source->id => 'source',
                    (int) $product->id === (int) $keep->id => 'keep',
                    default => 'drop',
                },
                'product' => (array) DB::table('products')->where('id', $product->id)->first(),
                'rows' => $rows,
            ];
        }

        // pozycje kart (powiązania B2B i pozycje z pliku) — wpis mapy tej pozycji mógł wskazywać inną kartę
        $pairs = [];
        foreach (B2bProductLink::query()->toBase()->whereIn('product_id', $ids)->get(['b2b_account_id', 'remote_id']) as $link) {
            $pairs[] = [ProductSourcePrice::b2bKey((int) $link->b2b_account_id), (string) $link->remote_id];
        }
        foreach (ProductIdentifier::query()->toBase()->whereIn('product_id', $ids)->where('source_key', 'like', 'file:%')
            ->distinct()->get(['source_key', 'position_key']) as $row) {
            $pairs[] = [(string) $row->source_key, (string) $row->position_key];
        }
        $redirects = self::rows(DB::table('card_redirects')->where(static function (Builder $q) use ($ids, $pairs): void {
            $q->whereIn('product_id', $ids);
            foreach ($pairs as [$sourceKey, $positionKey]) {
                $q->orWhere(static fn (Builder $w) => $w->where('source_key', $sourceKey)->where('position_key', $positionKey));
            }
        }));

        $candidates = self::rows(DB::table('card_match_candidates')->where(static function (Builder $q) use ($ids): void {
            $q->whereIn('source_product_id', $ids)->orWhereIn('target_product_id', $ids);
            self::whereTargetsKeyContains($q, $ids);
        }));
        $substitutes = Schema::hasTable('product_substitutes')
            ? self::rows(DB::table('product_substitutes')->where(static fn (Builder $q) => $q->whereIn('main_product_id', $ids)->orWhereIn('substitute_product_id', $ids)))
            : [];
        $accessories = Schema::hasTable('product_accessories')
            ? self::rows(DB::table('product_accessories')->where(static fn (Builder $q) => $q->whereIn('product_id', $ids)->orWhereIn('related_product_id', $ids)))
            : [];
        $companions = self::rows(DB::table('tender_items')->whereIn('companion_product_id', $ids));
        $priceLists = [];
        foreach (PriceList::query()->whereNotNull('product_ids')->cursor() as $list) {
            $productIds = is_array($list->product_ids) ? array_map('intval', $list->product_ids) : [];
            if (array_intersect($productIds, $ids) !== []) {
                $priceLists[] = ['id' => (int) $list->id, 'product_ids' => $list->product_ids];
            }
        }

        $payload = [
            'kind' => 'card-match-size-merge',
            'created_at' => now()->toIso8601String(),
            'user' => ['id' => (int) $user->id, 'name' => (string) $user->name],
            'candidate' => $candidate->getAttributes(),
            'source_product_id' => (int) $source->id,
            'keep_product_id' => (int) $keep->id,
            'drop_product_ids' => array_values(array_diff(array_slice($ids, 1), [(int) $keep->id])),
            'cards' => $cards,
            'card_redirects' => $redirects,
            'card_match_candidates' => $candidates,
            'product_substitutes' => $substitutes,
            'product_accessories' => $accessories,
            'tender_items_companion' => $companions,
            'price_lists' => $priceLists,
        ];

        $dir = storage_path('app/repair-backups');
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new DomainException('Kopia zapasowa nie powstała: brak katalogu '.$dir.' — nic nie połączono.');
        }
        $path = $dir.DIRECTORY_SEPARATOR.'card-match-sizes-'.$candidate->id.'-'.now()->format('Ymd-His').'.json';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (@file_put_contents($path, $json) === false) {
            throw new DomainException('Kopia zapasowa nie powstała: zapis '.$path.' się nie udał — nic nie połączono.');
        }
        $this->backupPath = $path;

        return $path;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(Builder $query): array
    {
        return $query->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
    }
}
