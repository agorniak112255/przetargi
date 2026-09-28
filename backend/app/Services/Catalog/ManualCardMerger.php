<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardMatchCandidate;
use App\Models\CardRedirect;
use App\Models\Product;
use App\Models\ProductEnrichmentBatchItem;
use App\Models\ProductImage;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bSizePriceMerger;
use App\Services\Pricing\SourcePriceComparison;
use App\Services\ProductSizeMergeService;
use App\Support\CanonicalBrand;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

/**
 * Ręczne łączenie kart z listy produktów (28.09.2026): człowiek zaznacza 2–10 kart tego samego wyrobu, których nie da
 * się połączyć po EAN ani kodzie producenta, okno pokazuje podgląd (preview) i podpowiada kartę, która zostaje — kartę
 * producenta (CardOwnership), a bez niej kartę z opisem, potem z największą liczbą źródeł cen i zdjęć. Połączenie
 * (merge) = każda pozostała karta wchodzi w kartę, która zostaje, jak „Połącz” na ekranie „Łączenie kart”
 * (mapa połączeń reason merge + ProductSizeMergeService::mergeDuplicate), a ślad decyzji to propozycja kind=merge,
 * status merged, matched_by „manual” na każdą łączoną kartę.
 *
 * Podgląd jest tylko do odczytu; połączenie liczy go od nowa w transakcji po blokadzie kont i kart — inny skrót
 * (plan_hash) niż ten, który widział człowiek, to CardMatchPlanChanged (409), blokada albo inna marka bez potwierdzenia
 * to DomainException (422). Przy każdej odmowie i każdym błędzie nic się nie zmienia, a plik kopii zapasowej znika.
 */
final class ManualCardMerger
{
    public const MIN_CARDS = 2;

    public const MAX_CARDS = 10;

    public const MATCHED_BY = 'manual';

    public const NOTE_MAX = 500;

    /** różnica ceny zakupu (ta sama waluta), od której podgląd ostrzega */
    private const PRICE_DIFF = 0.30;

    /** kopia zapasowa bieżącego łączenia — usuwana, gdy transakcja się wycofa */
    private ?string $backupPath = null;

    public function __construct(
        private readonly ProductSizeMergeService $sizeMerge,
        private readonly CardRedirectStore $redirects,
        private readonly CardOwnership $ownership,
        private readonly CardMatchSizeMerger $candidates,
        private readonly B2bSizePriceMerger $sizePrices,
        private readonly CardMatchBackup $backup,
        private readonly SourcePriceComparison $labels,
        private readonly CardMatchSignals $signals,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Podgląd połączenia (tylko odczyt): karty, podpowiedź karty, która zostaje, blokady, ostrzeżenia, co przejdzie
     * i skrót planu. $keepId null — karta z podpowiedzi.
     *
     * @param  list<mixed>  $productIds
     * @return array<string, mixed>
     *
     * @throws DomainException zła liczba kart
     */
    public function preview(array $productIds, ?int $keepId): array
    {
        return $this->build(self::normalizeIds($productIds), $keepId)['preview'];
    }

    /**
     * @param  list<mixed>  $productIds
     * @return array{keep_product_id: int, merged_product_ids: list<int>, candidate_ids: list<int>, backup_path: string}
     *
     * @throws CardMatchPlanChanged dane kart zmieniły się od podglądu (409, nic nie zmienione)
     * @throws DomainException z powodem po polsku (422, nic nie zmienione)
     */
    public function merge(array $productIds, int $keepId, string $planHash, bool $confirmBrand, ?string $note, User $user): array
    {
        $ids = self::normalizeIds($productIds);
        $this->backupPath = null;
        try {
            return DB::transaction(fn (): array => $this->mergeLocked($ids, $keepId, $planHash, $confirmBrand, $note, $user));
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
    }

    /**
     * @param  list<int>  $ids
     * @return array{keep_product_id: int, merged_product_ids: list<int>, candidate_ids: list<int>, backup_path: string}
     *
     * @throws JsonException
     */
    private function mergeLocked(array $ids, int $keepId, string $planHash, bool $confirmBrand, ?string $note, User $user): array
    {
        // 1) konta przed kartami (jak CardMatchSizeMerger): przebieg, który zechce zająć konto teraz, poczeka na commit
        // i wczyta już nową mapę połączeń; trwający przebieg zapisałby w połowie łączenia
        $accountIds = [
            ...B2bProductLink::query()->whereIn('product_id', $ids)->distinct()->pluck('b2b_account_id')->all(),
            ...ProductSourcePrice::query()->whereIn('product_id', $ids)->whereNotNull('b2b_account_id')->distinct()->pluck('b2b_account_id')->all(),
        ];
        $running = B2bAccountSyncRunner::lockIdle(array_map('intval', $accountIds));
        if ($running !== null) {
            throw new DomainException('Trwa synchronizacja konta '.$this->labels->accountLabel($running).' — spróbuj po jej zakończeniu.');
        }

        // 2) karty rosnąco po id, zablokowane do końca transakcji
        $locked = Product::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        // 3) podgląd od nowa — ten sam, który widział człowiek
        $built = $this->build($ids, $keepId);
        $preview = $built['preview'];
        if (! hash_equals((string) $preview['plan_hash'], $planHash)) {
            throw new CardMatchPlanChanged('Dane kart zmieniły się od podglądu — sprawdź i zatwierdź ponownie.');
        }
        if ($preview['blockers'] !== []) {
            throw new DomainException(implode(' ', $preview['blockers']));
        }
        foreach ($preview['warnings'] as $warning) {
            if ($warning['requires_confirm'] && $warning['code'] === 'brand' && ! $confirmBrand) {
                throw new DomainException('Karty są różnych marek — zaznacz „Wiem, łączę mimo innej marki”, jeśli to na pewno ten sam wyrób.');
            }
        }
        /** @var Product $keep */
        $keep = $locked->get($keepId);
        /** @var list<Product> $drops */
        $drops = array_map(static fn (int $id): Product => $locked->get($id), $built['drop_ids']);
        $dropIds = $built['drop_ids'];
        $note = trim((string) $note);
        $note = $note === '' ? null : mb_substr($note, 0, self::NOTE_MAX);

        // 4) propozycja na każdą łączoną kartę — ślad decyzji na ekranie „Łączenie kart” (zakładka połączonych);
        // istniejąca propozycja tej pary (do decyzji, niepewna, odrzucona) staje się połączoną
        $brand = CanonicalBrand::key($keep->manufacturer);
        $candidateByDrop = [];
        $candidatesBefore = [];
        foreach ($drops as $drop) {
            $row = CardMatchCandidate::query()
                ->where('source_product_id', $drop->id)
                ->where('targets_key', (string) $keepId)
                ->lockForUpdate()
                ->first();
            if ($row instanceof CardMatchCandidate) {
                $candidatesBefore[] = $row->getAttributes();
            } else {
                $row = new CardMatchCandidate;
            }
            $row->forceFill([
                'source_product_id' => (int) $drop->id,
                'target_product_id' => $keepId,
                'targets_key' => (string) $keepId,
                'kind' => CardMatchCandidate::KIND_MERGE,
                'status' => CardMatchCandidate::STATUS_MERGED,
                'matched_by' => self::MATCHED_BY,
                'matched_value' => null,
                'matched_source_key' => null,
                'brand' => $brand !== '' ? mb_substr($brand, 0, 100) : null,
                'reason' => 'połączone ręcznie',
                'conflict_product_ids' => null,
                'plan' => null,
                'plan_hash' => null,
                'source_snapshot' => [
                    'sku' => (string) $drop->sku,
                    'name' => (string) $drop->name,
                    'manufacturer' => (string) $drop->manufacturer,
                ],
                'decision_input' => [
                    'keep_product_id' => $keepId,
                    'drop_product_ids' => $dropIds,
                    'note' => $note,
                    'confirm_brand' => $confirmBrand,
                    'plan_hash' => $planHash,
                ],
                'decided_by' => $user->id,
                'decided_at' => now(),
            ])->save();
            $candidateByDrop[(int) $drop->id] = $row;
        }
        $first = $candidateByDrop[$dropIds[0]];

        // 5) pełna kopia zapasowa (karty z wierszami, mapa, propozycje, zamienniki, akcesoria, przetargi, cenniki);
        // propozycje tej pary sprzed decyzji osobno — kopia widzi je już jako połączone
        $cards = [['role' => 'keep', 'product' => $keep]];
        foreach ($drops as $drop) {
            $cards[] = ['role' => 'drop', 'product' => $drop];
        }
        $this->backupPath = $this->backup->write('card-match-manual', 'card-match-manual', 'nic nie połączono', $first, $user, $cards, [
            'keep_product_id' => $keepId,
            'drop_product_ids' => $dropIds,
            'candidates_before' => $candidatesBefore,
        ]);
        $candidateIds = [];
        foreach ($candidateByDrop as $row) {
            $row->forceFill(['backup_path' => $this->backupPath])->save();
            $candidateIds[] = (int) $row->id;
        }

        // 6) zdjęcia karty, która zostaje, sprzed łączenia (główne pierwsze) i kategoria zastępcza z łączonych kart
        $keepImageIds = ProductImage::query()
            ->where('product_id', $keepId)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $dropCategory = '';
        foreach ($drops as $drop) {
            if (trim((string) $drop->category) !== '') {
                $dropCategory = trim((string) $drop->category);
                break;
            }
        }

        // 7) inne propozycje z łączonymi kartami — przed scaleniem: usunięcie karty zeruje target_product_id, a odrzucona
        // para X → karta łączona ma dalej obowiązywać jako X → karta, która zostaje
        $this->candidates->settleOtherCandidates(null, null, $keepId, $dropIds);

        // 8) każda łączona karta: mapa połączeń (powiązania i identyfikatory są jeszcze na niej), potem scalenie w tę
        // samą instancję $keep (lista merged_duplicate_skus rośnie z każdą kartą)
        foreach ($drops as $drop) {
            $this->redirects->recordMerge($drop, $keep, CardRedirect::REASON_MERGE, $candidateByDrop[(int) $drop->id], $user);
            $this->sizeMerge->mergeDuplicate($keep, $drop);
        }

        // 9) zdjęcie główne karty, która zostaje, dalej główne; przeniesione za jej zdjęciami
        $keep->refresh();
        $this->sizeMerge->orderSizeMergeImages($keep, $keepImageIds, []);
        // jak products:merge-duplicate: pusta kategoria bierze kategorię łączonej karty
        if (trim((string) $keep->category) === '' && $dropCategory !== '') {
            $keep->category = $dropCategory;
            // zwykły save(): hak modelu przelicza indeks tekstowy i zleca reindeks wektora
            $keep->save();
        }

        $this->activity->log('card_match.manual_merge', $user, $keep, [
            'label' => 'Ręczne łączenie kart: '.implode(', ', array_map(static fn (Product $p): string => (string) $p->sku, $drops))
                .' → '.$keep->sku,
            'candidate_ids' => $candidateIds,
            'backup_path' => $this->backupPath,
            'drop_product_ids' => $dropIds,
            'note' => $note,
        ]);

        return [
            'keep_product_id' => $keepId,
            'merged_product_ids' => $dropIds,
            'candidate_ids' => $candidateIds,
            'backup_path' => (string) $this->backupPath,
        ];
    }

    /**
     * Podgląd i to, czego potrzebuje połączenie: karta, która zostaje, i karty łączone (rosnąco po id).
     *
     * @param  list<int>  $ids
     * @return array{preview: array<string, mixed>, keep: int|null, drop_ids: list<int>}
     */
    private function build(array $ids, ?int $keepId): array
    {
        /** @var Collection<int, Product> $cards */
        $cards = Product::query()->whereIn('id', $ids)->orderBy('id')->get()->keyBy('id');
        $existing = array_values(array_filter($ids, static fn (int $id): bool => $cards->has($id)));
        $blockers = [];
        foreach ($ids as $id) {
            if (! $cards->has($id)) {
                $blockers[] = 'Karta #'.$id.' już nie istnieje (usunięta albo połączona) — odśwież listę produktów.';
            }
        }

        $slots = ProductSourcePrice::query()
            ->with('priceList:id,manufacturer,version')
            ->whereIn('product_id', $existing)
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');
        $links = B2bProductLink::query()->whereIn('product_id', $existing)->orderBy('id')->get(['id', 'b2b_account_id', 'remote_id', 'product_id']);
        $accountIds = [
            ...$links->pluck('b2b_account_id')->all(),
            ...$slots->flatten(1)->pluck('b2b_account_id')->filter()->all(),
        ];
        $accountIds = array_values(array_unique(array_map('intval', $accountIds)));
        /** @var Collection<int, B2bAccount> $accounts */
        $accounts = $accountIds === [] ? collect() : B2bAccount::query()->whereIn('id', $accountIds)->get()->keyBy('id');

        $images = self::countBy(DB::table('product_images')->whereIn('product_id', $existing));
        $sizeRowsActive = self::countBy(DB::table('product_variants')->whereIn('product_id', $existing)
            ->where('kind', ProductVariant::KIND_SIZE)->whereNull('removed_at'));
        $sizeRowsAll = self::countBy(DB::table('product_variants')->whereIn('product_id', $existing)->where('kind', ProductVariant::KIND_SIZE));
        $versionsAll = self::countBy(DB::table('product_variants')->whereIn('product_id', $existing)->where('kind', ProductVariant::KIND_VERSION));
        $versionsActive = self::countBy(DB::table('product_variants')->whereIn('product_id', $existing)
            ->where('kind', ProductVariant::KIND_VERSION)->whereNull('removed_at'));
        $special = self::countBy(DB::table('product_special_prices')->whereIn('product_id', $existing));
        $identifiers = self::countBy(DB::table('product_identifiers')->whereIn('product_id', $existing));
        $fileIdentifiers = self::countBy(DB::table('product_identifiers')->whereIn('product_id', $existing)->where('source_key', 'like', 'file:%'));
        $accessories = self::countBy(DB::table('product_accessories')->whereIn('product_id', $existing));
        $tenders = self::countBy(DB::table('tender_items')->whereIn('main_product_id', $existing), 'main_product_id');
        foreach (self::countBy(DB::table('tender_items')->whereIn('companion_product_id', $existing), 'companion_product_id') as $id => $n) {
            $tenders[$id] = ($tenders[$id] ?? 0) + $n;
        }
        $presta = [];
        foreach (DB::table('presta_product_matches')->whereIn('product_id', $existing)->orderBy('presta_id')->get(['product_id', 'presta_id']) as $row) {
            $presta[(int) $row->product_id][] = (int) $row->presta_id;
        }

        // właściciele kart i podpowiedź karty, która zostaje
        $owners = [];
        $ownerLabels = [];
        foreach ($existing as $id) {
            $owners[$id] = $this->ownership->ownerSourceKeys($cards->get($id));
            $ownerLabels[$id] = $this->ownerLabel($owners[$id], $slots->get($id) ?? collect(), $accounts);
        }
        $ownerIds = array_values(array_filter($existing, static fn (int $id): bool => $owners[$id] !== []));
        $suggested = null;
        $keepLocked = false;
        if (count($ownerIds) === 1) {
            $suggested = $ownerIds[0];
            $keepLocked = true;
            $reason = 'Karta producenta #'.$suggested.' ('.$ownerLabels[$suggested].') zostaje — ma cennik producenta.';
        } elseif ($ownerIds === []) {
            $ranked = $existing;
            usort($ranked, static function (int $a, int $b) use ($cards, $slots, $images): int {
                $score = static fn (int $id): array => [
                    $cards->get($id)->hasUsableDescription() ? 1 : 0,
                    ($slots->get($id) ?? collect())->count(),
                    $images[$id] ?? 0,
                    -$id,
                ];

                return $score($b) <=> $score($a);
            });
            $suggested = $ranked[0] ?? null;
            $reason = 'Żadna karta nie ma cennika producenta — podpowiedź: karta z opisem, potem z największą liczbą źródeł cen i zdjęć.';
        } else {
            $reason = 'Kilka kart ma cennik producenta — bez podpowiedzi.';
            $blockers[] = 'Kilka kart producenta ('.self::idList($ownerIds).') — to zwykle rozmiary albo kolory jednego modelu albo różne wyroby. '
                .'Rozmiary łączy „Łączenie rozmiarów” na ekranie „Łączenie kart” albo „Scal rozmiary” konta; różnych wyrobów nie łącz.';
        }

        $keep = $keepId ?? $suggested;
        if ($keep !== null && ! $cards->has($keep)) {
            $blockers[] = 'Karta #'.$keep.', która ma zostać, nie jest wśród łączonych kart.';
            $keep = null;
        }
        if ($keepLocked && $keep !== null && $keep !== $suggested) {
            $blockers[] = 'Zostaje karta producenta #'.$suggested.' — ma cennik producenta ('.$ownerLabels[$suggested].'); '
                .'po połączeniu w kartę #'.$keep.' straciłaby ochronę nazwy i producenta.';
        }
        // bez karty, która zostaje, każdą kartę sprawdzamy jak łączoną
        $dropIds = array_values(array_filter($existing, static fn (int $id): bool => $id !== $keep));

        // blokady kart
        foreach ($existing as $id) {
            $isKeep = $id === $keep;
            if ($isKeep && ($versionsActive[$id] ?? 0) > 0) {
                $blockers[] = 'Karta #'.$id.', która zostaje, ma wersje Sign Project ('.$versionsActive[$id].') — łączenie z kartą wersji wyłączone.';
            }
            if (! $isKeep && ($versionsAll[$id] ?? 0) > 0) {
                $blockers[] = 'Karta #'.$id.' ma wersje Sign Project ('.$versionsAll[$id].') — połączenie ich nie przenosi.';
            }
            if (! $isKeep && ($special[$id] ?? 0) > 0) {
                $blockers[] = 'Karta #'.$id.' ma ceny specjalne ('.$special[$id].') — połączenie ich nie przenosi.';
            }
        }
        $fileCards = [];
        foreach ($existing as $id) {
            if (($slots->get($id) ?? collect())->contains(static fn (ProductSourcePrice $s): bool => $s->source_key === ProductSourcePrice::SOURCE_FILE)) {
                $fileCards[] = $id;
            }
        }
        if (count($fileCards) > 1) {
            $blockers[] = 'Kilka kart ma cenę z cennika z pliku ('.self::idList($fileCards).') — zostałaby jedna, starsza by zginęła.';
        }
        foreach ($fileCards as $id) {
            if ($id !== $keep && ($fileIdentifiers[$id] ?? 0) === 0) {
                $blockers[] = 'Karta #'.$id.' ma cenę z cennika z pliku bez kodów pozycji — zaimportuj cennik ponownie, potem połącz '
                    .'(import po SKU założyłby kartę od nowa).';
            }
        }
        foreach ($links->groupBy('b2b_account_id') as $accountId => $accountLinks) {
            $onCards = $accountLinks->pluck('product_id')->map(static fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all();
            if (count($onCards) > 1) {
                $blockers[] = 'Karty '.self::idList($onCards).' mają pozycje tego samego konta '.$this->labels->accountLabel($accounts->get((int) $accountId))
                    .' — ręcznie nie łączymy kart jednego konta (rozmiary tego konta łączy „Scal rozmiary”).';
            }
        }
        array_push($blockers, ...$this->spreadBlockers($links, $existing, $accounts));
        $modelCards = CardRedirect::query()->where('reason', CardRedirect::REASON_SIZE_MERGE)->whereIn('product_id', $dropIds === [] ? [0] : $dropIds)
            ->distinct()->pluck('product_id')->map(static fn (mixed $id): int => (int) $id)->all();
        foreach ($dropIds as $id) {
            $payload = $cards->get($id)->enrichment_payload;
            if (in_array($id, $modelCards, true) || (is_array($payload) && is_array($payload['size_merge'] ?? null))) {
                $blockers[] = 'Karta #'.$id.' to karta modelu po łączeniu rozmiarów — nie może zniknąć; wybierz ją jako kartę, która zostaje.';
            }
        }
        $enriching = DB::table('product_enrichment_batch_items')->whereIn('product_id', $existing === [] ? [0] : $existing)
            ->whereIn('status', [ProductEnrichmentBatchItem::STATUS_QUEUED, ProductEnrichmentBatchItem::STATUS_RUNNING])
            ->distinct()->orderBy('product_id')->pluck('product_id')->map(static fn (mixed $id): int => (int) $id)->all();
        foreach ($enriching as $id) {
            $blockers[] = 'Karta #'.$id.' czeka na opis w partii — spróbuj po jej zakończeniu.';
        }
        $prestaSets = [];
        foreach ($presta as $id => $prestaIds) {
            $prestaSets[implode(',', $prestaIds)][] = (int) $id;
        }
        if (count($prestaSets) > 1) {
            $blockers[] = 'Karty '.self::idList(array_merge(...array_values($prestaSets))).' są powiązane z różnymi produktami PrestaShop — '
                .'po połączeniu karta wskazywałaby kilka produktów sklepu.';
        }

        $preview = [
            'cards' => array_map(fn (int $id): array => $this->presentCard(
                $cards->get($id),
                $owners[$id] !== [],
                $owners[$id] !== [] ? $ownerLabels[$id] : null,
                $slots->get($id) ?? collect(),
                $accounts,
                [
                    'images' => $images[$id] ?? 0,
                    'size_rows' => $sizeRowsActive[$id] ?? 0,
                    'tender_items' => $tenders[$id] ?? 0,
                    'presta' => isset($presta[$id]),
                ],
            ), $existing),
            'keep_product_id' => $keep,
            'suggested_keep_id' => $suggested,
            'suggestion_reason' => $reason,
            'keep_locked' => $keepLocked,
            'blockers' => $blockers,
            'warnings' => $this->warnings($cards, $existing, $keep, $dropIds, $tenders),
            'moves' => [
                'source_prices' => self::movedSourceCount($slots, $keep, $dropIds),
                'b2b_links' => $links->whereIn('product_id', $dropIds)->count(),
                'images' => self::sum($images, $dropIds),
                'identifiers' => self::sum($identifiers, $dropIds),
                'tender_items' => self::sum($tenders, $dropIds),
                'size_rows' => self::sum($sizeRowsAll, $dropIds),
                'accessories' => self::sum($accessories, $dropIds),
            ],
            'can_merge' => $blockers === [] && $keep !== null,
        ];
        $preview['plan_hash'] = sha1(json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return ['preview' => $preview, 'keep' => $keep, 'drop_ids' => $dropIds];
    }

    /**
     * Karta w oknie podglądu.
     *
     * @param  Collection<int, ProductSourcePrice>  $slots
     * @param  Collection<int, B2bAccount>  $accounts
     * @param  array{images: int, size_rows: int, tender_items: int, presta: bool}  $counts
     * @return array<string, mixed>
     */
    private function presentCard(Product $product, bool $isOwner, ?string $ownerLabel, Collection $slots, Collection $accounts, array $counts): array
    {
        return [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'name' => (string) $product->name,
            'manufacturer' => $product->manufacturer !== null ? (string) $product->manufacturer : null,
            'is_owner' => $isOwner,
            'owner_label' => $ownerLabel,
            'has_description' => $product->hasUsableDescription(),
            'images' => $counts['images'],
            'size_rows' => $counts['size_rows'],
            'tender_items' => $counts['tender_items'],
            'presta' => $counts['presta'],
            'purchase_price' => $product->purchase_price !== null ? (string) $product->purchase_price : null,
            'currency' => $product->currency !== null ? (string) $product->currency : null,
            'sources' => $slots->map(fn (ProductSourcePrice $slot): array => [
                'source_key' => (string) $slot->source_key,
                'label' => $this->labels->sourceLabel($slot, $accounts),
                'purchase_price' => $slot->purchase_price !== null ? (string) $slot->purchase_price : null,
                'currency' => $slot->currency !== null ? (string) $slot->currency : null,
            ])->values()->all(),
        ];
    }

    /**
     * Ostrzeżenia (nie blokują): inna marka (wymaga potwierdzenia), duża różnica ceny zakupu, nazwy różniące się
     * rozmiarem albo kolorem, łączona karta w pozycjach przetargów.
     *
     * @param  Collection<int, Product>  $cards
     * @param  list<int>  $existing
     * @param  list<int>  $dropIds
     * @param  array<int, int>  $tenders
     * @return list<array{code: string, text: string, requires_confirm: bool}>
     */
    private function warnings(Collection $cards, array $existing, ?int $keep, array $dropIds, array $tenders): array
    {
        $warnings = [];
        if ($existing === []) {
            return $warnings;
        }

        $brands = [];
        foreach ($existing as $id) {
            $brands[CanonicalBrand::key($cards->get($id)->manufacturer)][] = $id;
        }
        if (count($brands) > 1 || isset($brands[''])) {
            $parts = [];
            foreach ($existing as $id) {
                $manufacturer = trim((string) $cards->get($id)->manufacturer);
                $parts[] = '#'.$id.': '.($manufacturer !== '' ? '„'.$manufacturer.'”' : 'bez producenta');
            }
            $warnings[] = [
                'code' => 'brand',
                'text' => 'Karty są różnych marek albo bez producenta ('.implode(', ', $parts).') — połącz tylko wtedy, gdy to na pewno ten sam wyrób.',
                'requires_confirm' => true,
            ];
        }

        $byCurrency = [];
        foreach ($existing as $id) {
            $card = $cards->get($id);
            $price = $card->purchase_price !== null ? (float) $card->purchase_price : 0.0;
            $currency = strtoupper(trim((string) $card->currency));
            if ($price > 0 && $currency !== '') {
                $byCurrency[$currency][$id] = $price;
            }
        }
        foreach ($byCurrency as $currency => $prices) {
            if (count($prices) < 2) {
                continue;
            }
            $min = min($prices);
            $max = max($prices);
            if (($max - $min) / $min > self::PRICE_DIFF) {
                $parts = [];
                foreach ($prices as $id => $price) {
                    $parts[] = '#'.$id.': '.self::money($price, $currency);
                }
                $warnings[] = [
                    'code' => 'price_diff',
                    'text' => 'Ceny zakupu różnią się o '.(int) round(($max - $min) / $min * 100).' % ('.implode(', ', $parts)
                        .') — sprawdź, czy to ten sam wyrób i to samo opakowanie.',
                    'requires_confirm' => false,
                ];
            }
        }

        $names = array_map(static fn (int $id): string => (string) $cards->get($id)->name, $existing);
        $differing = [];
        foreach ($this->signals->nameSignals($names) as $i => $signal) {
            if (in_array($signal['signal'], [CardMatchCandidate::SIGNAL_SIZE, CardMatchCandidate::SIGNAL_COLOR], true)) {
                $differing[] = '#'.$existing[$i].' '.($signal['signal'] === CardMatchCandidate::SIGNAL_SIZE ? 'rozmiar' : 'kolor')
                    .': '.implode(', ', $signal['differing']);
            }
        }
        if ($differing !== []) {
            $warnings[] = [
                'code' => 'size_color',
                'text' => 'Nazwy kart różnią się rozmiarem albo kolorem ('.implode('; ', $differing).') — po połączeniu zostanie jedna karta.',
                'requires_confirm' => false,
            ];
        }

        $keepCard = $keep !== null ? $cards->get($keep) : null;
        foreach ($dropIds as $id) {
            $count = $tenders[$id] ?? 0;
            if ($count === 0) {
                continue;
            }
            $drop = $cards->get($id);
            $text = 'Karta #'.$id.' jest w pozycjach przetargów ('.$count.') — przejdą na '.($keepCard !== null ? 'kartę #'.$keep : 'kartę, która zostanie');
            if ($keepCard !== null && $drop->purchase_price !== null && $keepCard->purchase_price !== null
                && strtoupper((string) $drop->currency) === strtoupper((string) $keepCard->currency)
                && abs((float) $drop->purchase_price - (float) $keepCard->purchase_price) >= 0.005) {
                $currency = strtoupper((string) $keepCard->currency);
                $text .= ' z ceną zakupu '.self::money((float) $keepCard->purchase_price, $currency)
                    .' zamiast '.self::money((float) $drop->purchase_price, $currency);
            }
            $warnings[] = ['code' => 'tender_items', 'text' => $text.'.', 'requires_confirm' => false];
        }

        return $warnings;
    }

    /**
     * Karta z wyrobu, który ostatni udany przebieg konta zapisał jako rozbity według ceny rozmiaru na kilka kart
     * (B2bSizePriceMerger::spread) i który dziś dalej leży na kilku kartach — najpierw „Scal rozmiary” tego konta:
     * po ręcznym połączeniu scalanie pominęłoby wyrób (karta z decyzją w mapie połączeń i powiązaniami innych kont).
     * Karty wyrobu z bieżących powiązań konta, nie z listy przebiegu (lista bywa starsza niż scalenie).
     *
     * @param  Collection<int, B2bProductLink>  $links  powiązania łączonych kart
     * @param  list<int>  $ids
     * @param  Collection<int, B2bAccount>  $accounts
     * @return list<string>
     */
    private function spreadBlockers(Collection $links, array $ids, Collection $accounts): array
    {
        $blockers = [];
        foreach ($links->groupBy('b2b_account_id') as $accountId => $accountLinks) {
            $account = $accounts->get((int) $accountId);
            if (! $account instanceof B2bAccount) {
                continue;
            }
            $ours = array_fill_keys($accountLinks->pluck('remote_id')->map(static fn (mixed $id): string => (string) $id)->all(), true);
            foreach ($this->sizePrices->spread($account)['groups'] as $group) {
                $remoteIds = [];
                foreach (is_array($group['members'] ?? null) ? $group['members'] : [] as $member) {
                    if (! is_array($member)) {
                        continue;
                    }
                    foreach ([$member['remote_id'] ?? null, $member['legacy_remote_id'] ?? null] as $remoteId) {
                        if (is_scalar($remoteId) && (string) $remoteId !== '') {
                            $remoteIds[(string) $remoteId] = true;
                        }
                    }
                }
                if (array_intersect_key($remoteIds, $ours) === []) {
                    continue;
                }
                $cardsNow = B2bProductLink::query()->where('b2b_account_id', $account->id)
                    ->whereIn('remote_id', array_map('strval', array_keys($remoteIds)))
                    ->distinct()->orderBy('product_id')->pluck('product_id')->map(static fn (mixed $id): int => (int) $id)->all();
                if (count($cardsNow) < 2) {
                    continue;
                }
                $label = $this->labels->accountLabel($account);
                $blockers[] = 'Karta '.self::idList(array_values(array_intersect($cardsNow, $ids))).' należy do wyrobu „'
                    .trim((string) ($group['name'] ?? $group['sku'] ?? '')).'” konta '.$label.' rozbitego według ceny rozmiaru na karty '
                    .self::idList($cardsNow).' — najpierw „Scal rozmiary” konta '.$label.'.';
                break;
            }
        }

        return $blockers;
    }

    /**
     * Właściciel karty po ludzku: „konto B2B 3M”, „cennik 3M 2026”.
     *
     * @param  list<string>  $keys
     * @param  Collection<int, ProductSourcePrice>  $slots
     * @param  Collection<int, B2bAccount>  $accounts
     */
    private function ownerLabel(array $keys, Collection $slots, Collection $accounts): string
    {
        $labels = [];
        foreach ($keys as $key) {
            if (str_starts_with($key, 'b2b:')) {
                $labels[] = 'konto '.$this->labels->accountLabel($accounts->get((int) substr($key, 4)));
            } elseif ($key === ProductSourcePrice::SOURCE_FILE) {
                $list = $slots->firstWhere('source_key', ProductSourcePrice::SOURCE_FILE)?->priceList;
                $name = $list !== null ? trim(trim((string) $list->manufacturer).' '.trim((string) $list->version)) : '';
                $labels[] = 'cennik z pliku'.($name !== '' ? ' '.$name : '');
            }
        }

        return implode(', ', $labels);
    }

    /**
     * Unikalne id kart rosnąco; 2–10 kart.
     *
     * @param  list<mixed>  $productIds
     * @return list<int>
     */
    private static function normalizeIds(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        sort($ids);
        if (count($ids) < self::MIN_CARDS || count($ids) > self::MAX_CARDS) {
            throw new DomainException('Zaznacz od '.self::MIN_CARDS.' do '.self::MAX_CARDS.' różnych kart.');
        }

        return $ids;
    }

    /**
     * Liczba wierszy na kartę.
     *
     * @return array<int, int>
     */
    private static function countBy(Builder $query, string $column = 'product_id'): array
    {
        $out = [];
        foreach ($query->select($column)->selectRaw('count(*) as aggregate')->groupBy($column)->get() as $row) {
            $out[(int) $row->{$column}] = (int) $row->aggregate;
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $counts
     * @param  list<int>  $ids
     */
    private static function sum(array $counts, array $ids): int
    {
        $sum = 0;
        foreach ($ids as $id) {
            $sum += $counts[$id] ?? 0;
        }

        return $sum;
    }

    /**
     * Źródła cen, które przybędą karcie, która zostaje: slot tego samego źródła na kilku kartach to jedno źródło
     * (mergeDuplicate zostawia nowszy), a źródło, które karta już ma, nie przybywa.
     *
     * @param  Collection<int|string, Collection<int, ProductSourcePrice>>  $slots
     * @param  list<int>  $dropIds
     */
    private static function movedSourceCount(Collection $slots, ?int $keep, array $dropIds): int
    {
        $own = $keep !== null ? ($slots->get($keep) ?? collect())->pluck('source_key')->map(static fn (mixed $k): string => (string) $k)->all() : [];
        $moved = [];
        foreach ($dropIds as $id) {
            foreach ($slots->get($id) ?? collect() as $slot) {
                $moved[(string) $slot->source_key] = true;
            }
        }

        return count(array_diff_key($moved, array_flip($own)));
    }

    /** @param  list<int>  $ids */
    private static function idList(array $ids): string
    {
        return implode(', ', array_map(static fn (int $id): string => '#'.$id, $ids));
    }

    private static function money(float $value, string $currency): string
    {
        return number_format($value, 2, ',', ' ').' '.$currency;
    }
}
