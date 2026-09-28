<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductEnrichmentBatchItem;
use App\Models\ProductIdentifier;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Services\Catalog\CardMatchBackup;
use App\Services\Catalog\CardMatchSizeMerger;
use App\Services\Catalog\CardOwnership;
use App\Services\Pricing\ProductEffectivePrice;
use App\Services\ProductSizeMergeService;
use App\Support\CanonicalBrand;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Scalanie kart rozbitych według ceny rozmiaru (etap 2 decyzji użytkownika 28.09.2026). Do 28.09.2026 łączniki B2B
 * zakładały osobną kartę na każdą cenę rozmiaru (Mascot „18001-249-1809 XS” za 539 zł z XS–2XL i „… 3XL” za 599 zł).
 * Pełna synchronizacja po etapie 1 zostawia te karty, daje każdej jej wiersze rozmiarów i zapisuje wyrób w
 * b2b_sync_runs.size_spread — ta lista jest jedynym źródłem grupowania (grupowanie zna tylko dostawca).
 *
 * Każdy wyrób jest sprawdzany na bazie TERAZ (dane z przebiegu mogą być nieaktualne) i scalany tylko w prostym
 * przypadku; każdy nietypowy — pominięty z powodem, bez zgadywania: karty tej samej marki bez właściciela albo tego
 * konta (dystrybutor wielu marek też — Raw-Pol), każde
 * powiązanie konta na kartach to rozmiar tego wyrobu (karta z pozycją innego wyrobu skleiłaby dwa wyroby), każdy
 * rozmiar ma wiersz ceny na swojej karcie, bez wersji Sign Project, cen specjalnych, cennika z pliku (import po SKU
 * odtworzyłby skasowane karty), decyzji w mapie połączeń (człowiek już rozstrzygnął), trwających opisów, powiązań
 * i cen innych kont oraz Presty na kartach łączonych; pozycje przetargów na droższym rozmiarze — tylko z
 * --with-tenders (marża liczona od najniższej ceny karty byłaby zawyżona).
 *
 * Karta, która zostaje: najwięcej rozmiarów, potem SKU = kod wyrobu, potem karta pozycji wiodącej dostawcy, potem
 * najniższe id. Scalenie: ProductSizeMergeService::mergeSizeCards (odwołania, zdjęcia, powiązania, sloty, tabelki,
 * identyfikatory, mapa połączeń, karty kasowane) poprzedzone przeniesieniem tego, czego tamto nie przenosi (wiersze
 * rozmiarów z historią cen, działania z wyszukiwarki, sprawdzenia zdjęć, propozycje „Łączenie kart”), potem slot
 * konta = najniższy rozmiar (jak zapis synchronizacji — następny przebieg nie widzi zmiany ceny) i SKU = kod wyrobu,
 * gdy wolny i karta nie ma Presty. Bez wpisów size_merge w mapie połączeń: wszystkie powiązania są na karcie, która
 * zostaje, więc synchronizacja trafia w nią zwykłą drogą.
 */
final class B2bSizePriceMerger
{
    public function __construct(
        private readonly ProductSizeMergeService $sizeMerge,
        private readonly CardMatchSizeMerger $candidates,
        private readonly CardMatchBackup $backup,
        private readonly CardOwnership $ownership,
        private readonly ProductEffectivePrice $prices,
        private readonly B2bConnectorRegistry $connectors,
    ) {}

    /**
     * Lista wyrobów z ostatniego udanego przebiegu konta; null + powód, gdy przebieg jej nie ma.
     *
     * @return array{run: B2bSyncRun|null, groups: list<array<string, mixed>>, total: int, truncated: bool, reason: string|null}
     */
    public function spread(B2bAccount $account): array
    {
        $run = B2bSyncRun::query()
            ->where('b2b_account_id', $account->id)
            ->where('status', B2bSyncRun::STATUS_OK)
            ->orderByDesc('id')
            ->first(['id', 'b2b_account_id', 'status', 'started_at', 'finished_at', 'size_spread']);
        if ($run === null) {
            return ['run' => null, 'groups' => [], 'total' => 0, 'truncated' => false,
                'reason' => 'konto nie ma udanego przebiegu synchronizacji — uruchom synchronizację'];
        }
        $spread = $run->size_spread;
        if (! is_array($spread)) {
            return ['run' => $run, 'groups' => [], 'total' => 0, 'truncated' => false,
                'reason' => 'ostatni udany przebieg #'.$run->id.' nie ma listy kart rozbitych według ceny (przebieg sprzed 28.09.2026) — uruchom pełną synchronizację'];
        }
        $groups = array_values(array_filter(is_array($spread['groups'] ?? null) ? $spread['groups'] : [], 'is_array'));

        return [
            'run' => $run,
            'groups' => $groups,
            'total' => (int) ($spread['total'] ?? count($groups)),
            'truncated' => (bool) ($spread['truncated'] ?? false),
            'reason' => null,
        ];
    }

    /**
     * To samo co spread() bez listy wyrobów (do 5 MB JSON) — okno „Scal rozmiary” odpytuje co 2,5 s; z bazy tylko
     * total i truncated.
     *
     * @return array{run_id: int|null, finished_at: string|null, total: int, truncated: bool, reason: string|null}
     */
    public function spreadSummary(B2bAccount $account): array
    {
        $run = B2bSyncRun::query()
            ->where('b2b_account_id', $account->id)
            ->where('status', B2bSyncRun::STATUS_OK)
            ->orderByDesc('id')
            ->first(['id', 'finished_at']);
        if ($run === null) {
            return ['run_id' => null, 'finished_at' => null, 'total' => 0, 'truncated' => false,
                'reason' => 'konto nie ma udanego przebiegu synchronizacji — uruchom synchronizację'];
        }
        $row = DB::table('b2b_sync_runs')->where('id', $run->id)->whereNotNull('size_spread')
            ->first(['size_spread->total as total', 'size_spread->truncated as truncated']);
        if ($row === null) {
            return ['run_id' => (int) $run->id, 'finished_at' => $run->finished_at?->toIso8601String(), 'total' => 0, 'truncated' => false,
                'reason' => 'ostatni udany przebieg #'.$run->id.' nie ma listy kart rozbitych według ceny (przebieg sprzed 28.09.2026) — uruchom pełną synchronizację'];
        }

        return [
            'run_id' => (int) $run->id,
            'finished_at' => $run->finished_at?->toIso8601String(),
            'total' => (int) $row->total,
            'truncated' => in_array(strtolower((string) $row->truncated), ['1', 'true'], true),
            'reason' => null,
        ];
    }

    /**
     * Plan scalenia jednego wyrobu na podstawie bazy teraz (bez zapisu).
     *
     * @param  array<string, mixed>  $group  wpis size_spread
     * @return array{merge: bool, reason: string|null, sku: string, name: string, keep_id: int|null, drop_ids: list<int>, card_ids: list<int>, summary: string, sizes: int, min: float|null, max: float|null, currency: string|null, sku_to: string|null, sku_note: string|null, tenders: int, keep: Product|null, drops: list<Product>}
     */
    public function plan(B2bAccount $account, array $group, bool $withTenders): array
    {
        $members = array_values(array_filter(is_array($group['members'] ?? null) ? $group['members'] : [], 'is_array'));
        $sku = trim((string) ($group['sku'] ?? ''));
        $name = trim((string) ($group['name'] ?? ''));
        $out = [
            'merge' => false, 'reason' => null, 'sku' => $sku, 'name' => $name, 'keep_id' => null, 'drop_ids' => [],
            'card_ids' => [], 'summary' => '', 'sizes' => count($members), 'min' => null, 'max' => null, 'currency' => null,
            'sku_to' => null, 'sku_note' => null, 'tenders' => 0, 'keep' => null, 'drops' => [],
        ];
        $skip = static fn (string $reason): array => [...$out, 'reason' => $reason];

        $cardOf = [];
        foreach ($members as $member) {
            $remoteId = (string) ($member['remote_id'] ?? '');
            if ($remoteId === '' || ! isset($member['card_id'])) {
                return $skip('wpis listy bez pozycji albo karty — puść synchronizację ponownie');
            }
            $cardOf[$remoteId] = (int) $member['card_id'];
        }
        $cardIds = array_values(array_unique(array_values($cardOf)));
        sort($cardIds);
        $out['card_ids'] = $cardIds;
        if (count($cardIds) < 2) {
            return $skip('rozmiary są już na jednej karcie');
        }
        if (mb_strlen($name) < 3) {
            return $skip('brak nazwy wyrobu w liście przebiegu');
        }

        /** @var Collection<int, Product> $cards */
        $cards = Product::query()->whereIn('id', $cardIds)->get()->keyBy('id');
        foreach ($cardIds as $id) {
            if (! $cards->has($id)) {
                return $skip('karta #'.$id.' już nie istnieje');
            }
        }
        $first = $cards->first();
        foreach ($cards as $card) {
            if (! CanonicalBrand::same($first->manufacturer, $card->manufacturer)) {
                return $skip('karty różnych marek („'.$first->manufacturer.'” / „'.$card->manufacturer.'”)');
            }
            // karta, której właścicielem jest inne źródło (konto producenta marki albo jego cennik z pliku) — nie nasza
            // decyzja; karta bez właściciela (założona przez dystrybutora, np. Raw-Pol z wieloma markami) i karta konta-
            // producenta — tak (28.09.2026: warunek „tylko konto producenta” pominął wszystkie 1200 wyrobów Raw-Pol)
            $owners = $this->ownership->ownerSourceKeys($card);
            if ($owners !== [] && ! in_array(ProductSourcePrice::b2bKey((int) $account->id), $owners, true)) {
                return $skip('karta #'.$card->id.' należy do innego źródła ('.implode(', ', $owners).') — łącz ręcznie w „Łączenie kart”');
            }
        }

        // powiązania konta: każdy rozmiar na swojej karcie z przebiegu, żadna pozycja spoza wyrobu na tych kartach
        $links = B2bProductLink::query()->where('b2b_account_id', $account->id)->whereIn('product_id', $cardIds)->get(['remote_id', 'product_id']);
        // dawne remote_id pozycji (łącznik zmienił identyfikatory, Protekt „LB100 / biały”) — należą do tego wyrobu
        $legacy = [];
        foreach ($members as $member) {
            if (is_string($member['legacy_remote_id'] ?? null) && $member['legacy_remote_id'] !== '') {
                $legacy[$member['legacy_remote_id']] = true;
            }
        }
        foreach ($links as $link) {
            if (! array_key_exists((string) $link->remote_id, $cardOf) && ! isset($legacy[(string) $link->remote_id])) {
                return $skip('karta #'.$link->product_id.' ma pozycję '.$link->remote_id.' spoza tego wyrobu');
            }
        }
        $linkCard = $links->mapWithKeys(static fn (B2bProductLink $l): array => [(string) $l->remote_id => (int) $l->product_id])->all();
        foreach ($cardOf as $remoteId => $cardId) {
            if (($linkCard[$remoteId] ?? null) !== $cardId) {
                return $skip('pozycja '.$remoteId.' nie jest już na karcie #'.$cardId.' — puść synchronizację ponownie');
            }
        }

        // wiersze cen rozmiarów tego konta — każdy rozmiar na swojej karcie, jedna waluta
        $source = ProductSourcePrice::b2bKey((int) $account->id);
        $rows = ProductVariant::query()->sizes()->active()->where('source', $source)
            ->whereIn('remote_id', array_map(static fn ($id): string => mb_substr((string) $id, 0, 64), array_keys($cardOf)))
            ->get(['remote_id', 'product_id', 'purchase_price', 'currency']);
        $rowCard = $rows->mapWithKeys(static fn (ProductVariant $v): array => [(string) $v->remote_id => (int) $v->product_id])->all();
        foreach ($cardOf as $remoteId => $cardId) {
            if (($rowCard[mb_substr((string) $remoteId, 0, 64)] ?? null) !== $cardId) {
                return $skip('rozmiar '.$remoteId.' nie ma wiersza ceny na karcie #'.$cardId.' — puść synchronizację ponownie');
            }
        }
        $currencies = $rows->pluck('currency')->map(static fn ($c): string => strtoupper((string) $c))->unique()->values()->all();
        if (count($currencies) !== 1) {
            return $skip('ceny rozmiarów w różnych walutach ('.implode(', ', $currencies).')');
        }
        $nets = $rows->map(static fn (ProductVariant $v): float => round((float) $v->purchase_price, 2));
        $min = (float) $nets->min();
        $max = (float) $nets->max();
        $minByCard = [];
        foreach ($rows as $row) {
            $id = (int) $row->product_id;
            $minByCard[$id] = min($minByCard[$id] ?? PHP_FLOAT_MAX, round((float) $row->purchase_price, 2));
        }
        $out = [...$out, 'min' => $min, 'max' => $max, 'currency' => $currencies[0]];
        $skip = static fn (string $reason): array => [...$out, 'reason' => $reason];

        foreach ([
            'product_variants' => ['wersje Sign Project', static fn ($q) => $q->where('kind', ProductVariant::KIND_VERSION)],
            'product_special_prices' => ['ceny specjalne', null],
            'card_redirects' => ['decyzje w mapie połączeń („Łączenie kart”)', null],
        ] as $table => [$label, $scope]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $query = DB::table($table)->whereIn('product_id', $cardIds);
            if ($scope !== null) {
                $scope($query);
            }
            $hit = $query->value('product_id');
            if ($hit !== null) {
                return $skip('karta #'.$hit.' ma '.$label);
            }
        }
        $mapped = DB::table('card_redirects')->where('source_key', $source)->whereIn('position_key', [...array_keys($cardOf), ...array_keys($legacy)])->value('position_key');
        if ($mapped !== null) {
            return $skip('pozycja '.$mapped.' ma decyzję w mapie połączeń („Łączenie kart”)');
        }
        $fileCard = ProductSourcePrice::query()->whereIn('product_id', $cardIds)->where('source_key', ProductSourcePrice::SOURCE_FILE)->value('product_id')
            ?? ProductIdentifier::query()->whereIn('product_id', $cardIds)->where('source_key', 'like', 'file:%')->value('product_id');
        if ($fileCard !== null) {
            return $skip('karta #'.$fileCard.' ma cenę albo kody z cennika z pliku (import po SKU odtworzyłby łączone karty)');
        }
        $enriching = DB::table('product_enrichment_batch_items')->whereIn('product_id', $cardIds)
            ->whereIn('status', [ProductEnrichmentBatchItem::STATUS_QUEUED, ProductEnrichmentBatchItem::STATUS_RUNNING])->value('product_id');
        if ($enriching !== null) {
            return $skip('karta #'.$enriching.' czeka na opis w partii — spróbuj po jej zakończeniu');
        }

        // karta, która zostaje: najwięcej rozmiarów, SKU = kod wyrobu, karta pozycji wiodącej, najniższe id
        $counts = array_count_values($cardOf);
        $leadCard = $cardOf[(string) ($group['remote_id'] ?? '')] ?? null;
        $ranked = $cardIds;
        usort($ranked, static function (int $a, int $b) use ($counts, $cards, $sku, $leadCard): int {
            return [$counts[$b] ?? 0, (string) $cards[$b]->sku === $sku ? 1 : 0, $b === $leadCard ? 1 : 0, -$b]
                <=> [$counts[$a] ?? 0, (string) $cards[$a]->sku === $sku ? 1 : 0, $a === $leadCard ? 1 : 0, -$a];
        });
        $keepId = $ranked[0];
        $dropIds = array_values(array_filter($cardIds, static fn (int $id): bool => $id !== $keepId));
        $out = [...$out, 'keep_id' => $keepId, 'drop_ids' => $dropIds];
        $skip = static fn (string $reason): array => [...$out, 'reason' => $reason];

        $foreign = B2bProductLink::query()->whereIn('product_id', $dropIds)->where('b2b_account_id', '!=', $account->id)->value('product_id')
            ?? ProductSourcePrice::query()->whereIn('product_id', $dropIds)->where('source_key', '!=', $source)->value('product_id');
        if ($foreign !== null) {
            return $skip('łączona karta #'.$foreign.' ma powiązania albo ceny innego konta');
        }
        $presta = DB::table('presta_product_matches')->whereIn('product_id', $dropIds)->value('product_id');
        if ($presta !== null) {
            return $skip('łączona karta #'.$presta.' jest powiązana z Prestą');
        }

        // przetargi na droższym rozmiarze: marża od najniższej ceny karty byłaby zawyżona
        $tenders = DB::table('tender_items')->where(static fn ($q) => $q->whereIn('main_product_id', $cardIds)->orWhereIn('companion_product_id', $cardIds))
            ->get(['main_product_id', 'companion_product_id']);
        $out['tenders'] = $tenders->count();
        $skip = static fn (string $reason): array => [...$out, 'reason' => $reason];
        if (! $withTenders) {
            foreach ($tenders as $item) {
                foreach ([(int) $item->main_product_id, (int) $item->companion_product_id] as $id) {
                    if (isset($minByCard[$id]) && $minByCard[$id] > $min + 0.004) {
                        return $skip('karta #'.$id.' (droższy rozmiar) jest w pozycjach przetargów — scal z opcją „także z przetargami na droższym rozmiarze” (--with-tenders)');
                    }
                }
            }
        }

        $keep = $cards[$keepId];
        $skuTo = null;
        $skuNote = null;
        if ($sku !== '' && (string) $keep->sku !== $sku) {
            $taken = Product::query()->where('sku', $sku)->whereNotIn('id', $cardIds)->value('id');
            $keepPresta = DB::table('presta_product_matches')->where('product_id', $keepId)->exists();
            if ($taken !== null) {
                $skuNote = 'kod '.$sku.' ma karta #'.$taken.' — SKU bez zmian';
            } elseif ($keepPresta) {
                $skuNote = 'karta jest w Preście — SKU bez zmian';
            } else {
                $skuTo = $sku;
            }
        }

        $labels = [];
        foreach ($members as $member) {
            $labels[] = trim((string) ($member['size'] ?? '')) !== '' ? trim((string) $member['size']) : (string) ($member['sku'] ?? $member['remote_id']);
        }

        return [
            ...$out,
            'merge' => true,
            'summary' => mb_substr('Rozmiary: '.implode('; ', $labels), 0, B2bCatalogSync::VARIANT_SUMMARY_LIMIT),
            'sku_to' => $skuTo,
            'sku_note' => $skuNote,
            'keep' => $keep,
            'drops' => array_map(static fn (int $id): Product => $cards[$id], $dropIds),
        ];
    }

    /**
     * Scalenie jednego wyrobu w jednej transakcji: blokada kont (trwający przebieg = B2bSyncRunningException, przerwać
     * całe polecenie), blokada kart, ponowny plan, kopia zapasowa ($backup dostaje wiersze przed zmianą), scalenie.
     *
     * @param  array<string, mixed>  $group
     * @param  callable(array<string, mixed>): void  $backup
     * @return array<string, mixed> plan (merge=false z powodem, gdy w międzyczasie coś się zmieniło)
     */
    public function apply(B2bAccount $account, array $group, bool $withTenders, callable $backup): array
    {
        return DB::transaction(function () use ($account, $group, $withTenders, $backup): array {
            $cardIds = [];
            foreach (is_array($group['members'] ?? null) ? $group['members'] : [] as $member) {
                if (is_array($member) && isset($member['card_id'])) {
                    $cardIds[(int) $member['card_id']] = true;
                }
            }
            $cardIds = array_keys($cardIds);
            $accountIds = [(int) $account->id, ...B2bProductLink::query()->whereIn('product_id', $cardIds)->distinct()->pluck('b2b_account_id')
                ->map(static fn ($id): int => (int) $id)->all()];
            $running = B2bAccountSyncRunner::lockIdle($accountIds);
            if ($running !== null) {
                throw new RuntimeException('Trwa synchronizacja konta #'.$running->id.' — scalanie przerwane, uruchom je po zakończeniu przebiegu.', self::SYNC_RUNNING);
            }
            Product::query()->whereIn('id', $cardIds)->lockForUpdate()->get(['id']);

            $plan = $this->plan($account, $group, $withTenders);
            if (! $plan['merge']) {
                return $plan;
            }
            /** @var Product $keep */
            $keep = $plan['keep'];
            $drops = $plan['drops'];
            $dropIds = $plan['drop_ids'];
            $keepId = (int) $keep->id;
            $priceBefore = [(string) $keep->purchase_price, (string) $keep->currency];

            $backup([
                'account_id' => (int) $account->id,
                'sku' => $plan['sku'],
                'keep_product_id' => $keepId,
                'drop_product_ids' => $dropIds,
                'group' => $group,
                ...$this->backup->snapshot([
                    ['role' => 'keep', 'product' => $keep],
                    ...array_map(static fn (Product $p): array => ['role' => 'drop', 'product' => $p], $drops),
                ]),
            ]);

            // 1) to, czego mergeSizeCards nie przenosi, a kaskada skasowałaby z kartami łączonymi
            ProductVariant::query()->sizes()->whereIn('product_id', $dropIds)->update(['product_id' => $keepId]);
            $this->sizeMerge->moveSearchActions($keepId, $dropIds);
            // sprawdzenia zdjęć idą za zdjęciem (moveMedia przenosi je na kartę, która zostaje; duplikat — kaskada)
            DB::table('product_visual_checks')->whereIn('product_id', $dropIds)->update(['product_id' => $keepId]);
            // propozycje przed kasowaniem: usunięcie karty zeruje target_product_id
            $this->candidates->settleOtherCandidates(null, null, $keepId, $dropIds);

            // 2) scalenie kart (odwołania, zdjęcia, powiązania, sloty, tabelki, identyfikatory, mapa, kasowanie)
            $images = $this->sizeMerge->mergeSizeCards($keep, $drops, $plan['name'], $plan['summary']);
            $keep->refresh();
            $this->sizeMerge->orderSizeMergeImages($keep, $images['image_ids_keep'], $images['image_ids_drops']);

            // 3) slot konta = najniższy rozmiar (jak zapis synchronizacji), SKU = kod wyrobu
            $this->recomputeSlot($keep, $account);
            if ($plan['sku_to'] !== null) {
                $payload = is_array($keep->enrichment_payload) ? $keep->enrichment_payload : [];
                $payload['merged_size_skus'] = array_values(array_unique([
                    ...(is_array($payload['merged_size_skus'] ?? null) ? $payload['merged_size_skus'] : []),
                    (string) $keep->sku,
                ]));
                $keep->forceFill(['sku' => $plan['sku_to'], 'enrichment_payload' => $payload])->save();
            }
            $keep->refresh();
            if ([(string) $keep->purchase_price, (string) $keep->currency] !== $priceBefore) {
                // cena karty zmieniona scaleniem (zostaje droższa karta) — następny przebieg porówna już ze slotem
                ProductPriceHistory::query()->create([
                    'product_id' => $keepId,
                    'catalog_price_net' => $keep->catalog_price_net,
                    'purchase_price' => $keep->purchase_price,
                    'currency' => $keep->currency,
                    'source' => 'b2b:'.$this->connectors->keyForAccount($account),
                ]);
            }

            return [...$plan, 'keep' => $keep];
        });
    }

    public const SYNC_RUNNING = 7301;

    public const BACKUP_FAILED = 7302;

    /** Co tyle wyrobów process() zgłasza postęp ($onProgress — okno „Scal rozmiary”). */
    private const PROGRESS_EVERY = 50;

    /**
     * Przejście po liście od $offset — podgląd albo scalanie (polecenie b2b:merge-size-prices w całości, zadanie w tle
     * porcjami do $deadline). $limit — najwyżej tyle wyrobów do scalenia łącznie ($toMergeBefore policzone w
     * poprzednich porcjach). Scalanie dopisuje do kopii JSONL ($backupPath): przed wyrobem jego wiersze („before”), po
     * commit „committed”, po błędzie „rolled_back”. stop — trwająca synchronizacja albo nieudany zapis kopii: dalej nie
     * idziemy (scalone wyroby zostają). $onProgress — co PROGRESS_EVERY wyrobów dostaje wynik częściowy (ten sam kształt).
     *
     * @param  list<array<string, mixed>>  $groups
     * @return array{offset: int, done: bool, stop: string|null, to_merge: int, merged: int, sizes: int, tenders: int, sku_renamed: int, skipped: array<string, int>, lines: list<string>}
     */
    public function process(B2bAccount $account, array $groups, int $offset, bool $apply, bool $withTenders, ?int $limit, int $toMergeBefore, ?string $backupPath, ?float $deadline, ?callable $onProgress = null): array
    {
        $out = ['offset' => $offset, 'done' => false, 'stop' => null, 'to_merge' => 0, 'merged' => 0, 'sizes' => 0, 'tenders' => 0,
            'sku_renamed' => 0, 'skipped' => [], 'lines' => []];
        $handle = null;
        if ($apply) {
            if ($backupPath === null || ($handle = @fopen($backupPath, 'ab')) === false) {
                return [...$out, 'done' => true, 'stop' => 'Kopia zapasowa nie powstanie ('.($backupPath ?? 'brak ścieżki').') — nic nie scalono.'];
            }
        }
        try {
            $count = count($groups);
            for ($i = $offset; $i < $count; $i++) {
                if ($limit !== null && $toMergeBefore + $out['to_merge'] >= $limit) {
                    return [...$out, 'offset' => $i, 'done' => true];
                }
                if ($deadline !== null && microtime(true) >= $deadline) {
                    return [...$out, 'offset' => $i];
                }
                if ($onProgress !== null && $i > $offset && ($i - $offset) % self::PROGRESS_EVERY === 0) {
                    $onProgress([...$out, 'offset' => $i]);
                }
                $group = $groups[$i];
                $sku = (string) ($group['sku'] ?? '?');
                try {
                    $plan = $apply
                        ? $this->apply($account, $group, $withTenders, static fn (array $row) => self::writeLine($handle, ['status' => 'before', ...$row]))
                        : $this->plan($account, $group, $withTenders);
                } catch (RuntimeException $e) {
                    if (in_array($e->getCode(), [self::SYNC_RUNNING, self::BACKUP_FAILED], true)) {
                        return [...$out, 'offset' => $i, 'done' => true, 'stop' => $e->getMessage()];
                    }
                    $plan = ['merge' => false, 'reason' => 'błąd: '.$e->getMessage(), 'sku' => $sku];
                    if ($apply) {
                        self::writeLine($handle, ['status' => 'rolled_back', 'sku' => $sku]);
                    }
                } catch (JsonException $e) {
                    return [...$out, 'offset' => $i, 'done' => true, 'stop' => 'Kopia zapasowa wyrobu '.$sku.' nie powstała: '.$e->getMessage().' — scalanie przerwane.'];
                } catch (Throwable $e) {
                    $plan = ['merge' => false, 'reason' => 'błąd: '.$e->getMessage(), 'sku' => $sku];
                    if ($apply) {
                        self::writeLine($handle, ['status' => 'rolled_back', 'sku' => $sku]);
                    }
                }

                if (! $plan['merge']) {
                    $kind = self::reasonKind((string) $plan['reason']);
                    $out['skipped'][$kind] = ($out['skipped'][$kind] ?? 0) + 1;
                    $out['lines'][] = '– '.$plan['sku'].': pominięty — '.$plan['reason'];

                    continue;
                }
                $out['to_merge']++;
                $out['sizes'] += (int) $plan['sizes'];
                $out['tenders'] += (int) $plan['tenders'];
                $out['sku_renamed'] += $plan['sku_to'] !== null ? 1 : 0;
                if ($apply) {
                    $out['merged']++;
                    self::writeLine($handle, ['status' => 'committed', 'sku' => $plan['sku'], 'keep_product_id' => $plan['keep_id']]);
                }
                $out['lines'][] = self::planLine($plan, $apply);
            }

            return [...$out, 'offset' => $count, 'done' => true];
        } catch (RuntimeException $e) {
            // zapis „committed” po scaleniu się nie udał — wyrób scalony, dalej nie idziemy
            return [...$out, 'done' => true, 'stop' => $e->getMessage()];
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Rodzaj powodu do podsumowania: bez numerów kart, kodów pozycji i wartości w nawiasach — 2557 wyrobów Mascot
     * daje wtedy kilka wierszy zamiast tysięcy jednostkowych.
     */
    public static function reasonKind(string $reason): string
    {
        return (string) preg_replace(
            ['/#\d+/u', '/\b(pozycja|pozycję|rozmiar|kod) \S+/u', '/\([^)]*\)/u', '/„[^”]*”/u', '/\s+/u'],
            ['#…', '$1 …', '(…)', '„…”', ' '],
            $reason,
        );
    }

    /**
     * „+ K1: zostaje #12 (K1 S) ← #13 · rozmiarów 4 · 100,00–120,00 PLN · SKU → K1” (scalony — „✓”).
     *
     * @param  array<string, mixed>  $plan
     */
    public static function planLine(array $plan, bool $applied): string
    {
        return sprintf(
            '%s %s: zostaje #%d (%s) ← %s · rozmiarów %d · %s–%s %s%s%s%s',
            $applied ? '✓' : '+',
            $plan['sku'],
            $plan['keep_id'],
            $applied && $plan['sku_to'] !== null ? $plan['sku_to'] : ($plan['keep']?->sku ?? '?'),
            implode(', ', array_map(static fn (int $id): string => '#'.$id, $plan['drop_ids'])),
            $plan['sizes'],
            number_format((float) $plan['min'], 2, ',', ''),
            number_format((float) $plan['max'], 2, ',', ''),
            $plan['currency'],
            $plan['tenders'] > 0 ? ' · przetargi '.$plan['tenders'] : '',
            $plan['sku_to'] !== null ? ' · SKU → '.$plan['sku_to'] : '',
            $plan['sku_note'] !== null ? ' · '.$plan['sku_note'] : '',
        );
    }

    /** Ścieżka nowej kopii zapasowej scalania konta (storage/app/repair-backups); null — brak katalogu. */
    public static function newBackupPath(B2bAccount $account): ?string
    {
        $dir = storage_path('app/repair-backups');
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return $dir.DIRECTORY_SEPARATOR.'size-prices-'.$account->id.'-'.now()->format('Ymd-His').'.jsonl';
    }

    /**
     * Jedna linia JSONL, od razu na dysk — kopia wyrobu jest w pliku przed jego scaleniem.
     *
     * @param  resource|null  $handle
     * @param  array<string, mixed>  $row
     *
     * @throws JsonException
     */
    private static function writeLine($handle, array $row): void
    {
        if (! is_resource($handle)) {
            return;
        }
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (@fwrite($handle, $json."\n") === false || ! fflush($handle)) {
            throw new RuntimeException('Zapis kopii zapasowej się nie udał — scalanie przerwane.', self::BACKUP_FAILED);
        }
    }

    /**
     * Slot konta z wierszy rozmiarów na karcie — ta sama reguła co synchronizacja (B2bCatalogSync::sizePricing):
     * najtańszy rozmiar (remis — B2bCatalogSync::winsSizePriceTie), katalogowa = jego cena katalogowa albo
     * cena konta, size_price_max = najwyższa, gdy różni się o grosz; data sprawdzenia slotu bez zmian.
     */
    private function recomputeSlot(Product $keep, B2bAccount $account): void
    {
        $source = ProductSourcePrice::b2bKey((int) $account->id);
        $rows = ProductVariant::query()->sizes()->active()->where('product_id', $keep->id)->where('source', $source)
            ->orderBy('sort_order')->orderBy('id')->get();
        $slot = ProductSourcePrice::query()->where('product_id', $keep->id)->where('source_key', $source)->first();
        if ($rows->isEmpty() || $slot === null) {
            return;
        }
        $cheapest = null;
        $max = null;
        foreach ($rows as $row) {
            $net = round((float) $row->purchase_price, 2);
            $max = $max === null ? $net : max($max, $net);
            if ($cheapest === null) {
                $cheapest = $row;

                continue;
            }
            $cheapestNet = round((float) $cheapest->purchase_price, 2);
            if ($net < $cheapestNet - 0.0049
                || (abs($net - $cheapestNet) < 0.005 && B2bCatalogSync::winsSizePriceTie(
                    $row->list_price_net !== null ? (float) $row->list_price_net : null,
                    $cheapest->list_price_net !== null ? (float) $cheapest->list_price_net : null,
                ))) {
                $cheapest = $row;
            }
        }
        $net = round((float) $cheapest->purchase_price, 2);
        $changed = abs((float) $slot->purchase_price - $net) >= 0.005;
        $this->prices->saveSlot($keep, $source, [
            'purchase_price' => $net,
            'catalog_price_net' => round((float) ($cheapest->list_price_net ?? $net), 2),
            'size_price_max' => $max !== null && $max - $net >= 0.005 ? $max : null,
            'currency' => strtoupper((string) $cheapest->currency),
            // rabat slotu opisywał cenę zostającej karty — przy innej cenie nie wiadomo, czy dotyczy najtańszego rozmiaru
            ...($changed ? ['discount_percent' => null] : []),
            'checked_at' => $slot->checked_at,
        ]);
    }
}
