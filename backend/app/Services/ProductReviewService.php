<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductImage;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDescriptionSource;
use App\Services\Campaigns\SmtpHostGuard;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\ProductEnrichmentResetter;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Support\ManufacturerNormFacts;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Przegląd opisów kart cenników z plików (etap 1, 08.10.2026): lista kart z powodem przeglądu (products.review_reason)
 * i decyzje handlowca — zatwierdź, odrzuć, podaj adres strony wyrobu, przywróć wersję. Każda decyzja idzie w transakcji
 * z blokadą wiersza karty i jest idempotentna: drugie kliknięcie (albo drugi handlowiec) nic już nie zmienia.
 *
 * Czekająca propozycja = najnowsza wersja proposed/rejected po bieżącej published, o ile to proposed bez decyzji.
 * Starsze propozycje sprzed nowszego zapisu albo sprzed odrzucenia nie wracają na listę.
 */
final class ProductReviewService
{
    public const MAX_PER_PAGE = 200;

    private const CHUNK = 1000;

    /** Powody związane z propozycją — znikają, gdy propozycja dostanie decyzję. */
    private const PROPOSAL_REASONS = [Product::REVIEW_WORSE_VERSION, Product::REVIEW_REJECTED_SOURCE];

    public function __construct(
        private readonly DescriptionVersionStore $versions,
        private readonly PriceListCards $cards,
        private readonly B2bDescriptionSource $b2bDescriptions,
        private readonly ProductEnrichmentResetter $resetter,
        private readonly SmtpHostGuard $hosts,
    ) {}

    /**
     * Karty cenników z plików (sloty „file”, PriceListCards) z powodem przeglądu. Bez kolumny description — źródło,
     * werdykt i dowody ścieżkami JSON. Liczniki jak filtry z sąsiednim wymiarem: by_reason w wybranym cenniku,
     * by_price_list przy wybranym powodzie — liczone w PHP (bez GROUP BY po złączeniach, ONLY_FULL_GROUP_BY).
     *
     * @param  array{price_list_id?: int|null, reason?: string|null}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int}, counts: array{by_reason: array<string, int>, by_price_list: list<array{id: int, manufacturer: string, count: int}>}}
     */
    public function list(array $filters, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $listFilter = (int) ($filters['price_list_id'] ?? 0);
        $reasonFilter = in_array($filters['reason'] ?? null, Product::REVIEW_REASONS, true) ? (string) $filters['reason'] : null;

        $listIds = ProductSourcePrice::query()
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->whereNotNull('price_list_id')
            ->distinct()
            ->pluck('price_list_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        /** @var array<int, int> $listOf id karty => id cennika (slot „file” jest jeden na kartę) */
        $listOf = [];
        foreach ($this->cards->fileSlotIdsByList($listIds) as $listId => $ids) {
            foreach ($ids as $id) {
                $listOf[$id] = (int) $listId;
            }
        }

        $byReason = array_fill_keys(Product::REVIEW_REASONS, 0);
        $byList = [];
        $rows = [];
        Product::query()
            ->toBase()
            ->whereNotNull('review_reason')
            ->select([
                'id',
                'sku',
                'name',
                'manufacturer',
                'review_reason',
                'review_since',
                'enrichment_status',
                'enrichment_payload->primary_source_url as primary_source_url',
                'enrichment_payload->primary_source_kind as primary_source_kind',
                'enrichment_payload->identity as identity',
                'enrichment_payload->evidence_summary as evidence_summary',
            ])
            ->chunkById(self::CHUNK, function ($chunk) use ($listOf, $listFilter, $reasonFilter, &$byReason, &$byList, &$rows): void {
                foreach ($chunk as $row) {
                    $listId = $listOf[(int) $row->id] ?? null;
                    if ($listId === null) {
                        continue;
                    }
                    $reason = (string) $row->review_reason;
                    if ($listFilter === 0 || $listId === $listFilter) {
                        $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
                    }
                    if ($reasonFilter === null || $reason === $reasonFilter) {
                        $byList[$listId] = ($byList[$listId] ?? 0) + 1;
                        if ($listFilter === 0 || $listId === $listFilter) {
                            $row->price_list_id = $listId;
                            $rows[] = $row;
                        }
                    }
                }
            }, 'id');

        // najnowsze powody pierwsze; review_since z bazy to napis „Y-m-d H:i:s” — porównanie napisów wystarcza
        usort($rows, static fn (object $a, object $b): int => [(string) ($b->review_since ?? ''), (int) $b->id]
            <=> [(string) ($a->review_since ?? ''), (int) $a->id]);
        $total = count($rows);
        $pageRows = array_slice($rows, ($page - 1) * $perPage, $perPage);

        $manufacturers = $byList === [] ? [] : PriceList::query()->whereIn('id', array_keys($byList))->pluck('manufacturer', 'id')->all();
        $countsByList = [];
        foreach ($byList as $listId => $count) {
            $countsByList[] = ['id' => (int) $listId, 'manufacturer' => (string) ($manufacturers[$listId] ?? ''), 'count' => $count];
        }
        usort($countsByList, static fn (array $a, array $b): int => [mb_strtolower($a['manufacturer']), $a['id']]
            <=> [mb_strtolower($b['manufacturer']), $b['id']]);

        return [
            'data' => $this->presentRows($pageRows),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
            'counts' => ['by_reason' => $byReason, 'by_price_list' => $countsByList],
        ];
    }

    /**
     * Historia wersji opisu karty, najnowsze pierwsze; current_version_id = bieżąca baza (DescriptionVersionStore::current).
     *
     * @return array{data: list<array<string, mixed>>, current_version_id: int|null}
     */
    public function history(Product $p): array
    {
        // bez pełnego payloadu i śladu przebiegu każdej wersji — z payloadu tylko listy pokazywane w historii
        $meta = DescriptionVersionStore::META_KEY;
        $versions = ProductDescriptionVersion::query()
            ->where('product_id', $p->id)
            ->select([
                'id', 'product_id', 'status', 'origin', 'identity_verdict', 'identity_reason', 'evidence_count',
                'completeness', 'review_reason', 'reason', 'created_at', 'decision', 'decided_at', 'decided_by',
                'description', 'primary_source_url',
                'enrichment_payload->source_urls as payload_source_urls',
                'enrichment_payload->evidence as payload_evidence',
                "enrichment_payload->{$meta}->url_blocked as payload_url_blocked",
            ])
            ->with('decider:id,name')
            ->orderByDesc('id')
            ->get();

        return [
            'data' => $versions->map(function (ProductDescriptionVersion $v): array {
                $sourceUrls = $this->jsonList($v->getAttribute('payload_source_urls'));
                $evidence = $this->jsonList($v->getAttribute('payload_evidence'));
                $blocked = $v->getAttribute('payload_url_blocked');

                return [
                    'id' => (int) $v->id,
                    'status' => $v->status,
                    'origin' => $v->origin,
                    'identity_verdict' => $v->identity_verdict,
                    'identity_reason' => $v->identity_reason,
                    'evidence_count' => $v->evidence_count,
                    'completeness' => $v->completeness !== null ? (float) $v->completeness : null,
                    'review_reason' => $v->review_reason,
                    'reason' => $v->reason,
                    'created_at' => $v->created_at?->toJSON(),
                    'decision' => $v->decision,
                    'decided_at' => $v->decided_at?->toJSON(),
                    'decided_by' => $v->decider !== null ? ['id' => (int) $v->decider->id, 'name' => (string) $v->decider->name] : null,
                    'description' => $v->description,
                    'primary_source_url' => $v->primary_source_url,
                    'source_urls' => array_values(array_filter($sourceUrls, 'is_string')),
                    'evidence' => array_values(array_filter($evidence, 'is_array')),
                    // odrzucony opis z karty blokuje swój adres dla automatu (do zdjęcia zatwierdzeniem wersji z niego)
                    'url_blocked' => $v->status === ProductDescriptionVersion::STATUS_REJECTED
                        && ($blocked === true || $blocked === 1 || $blocked === '1' || $blocked === 'true'),
                ];
            })->all(),
            'current_version_id' => $this->versions->current($p)?->id,
        ];
    }

    /**
     * Zatwierdzenie: czekająca propozycja trafia na kartę (nowa wersja published, origin review_approve — ze zdjęciami
     * i plikami poprzedniego opisu, files_from_previous); opis już na karcie (powód identity_*) zostaje, powód
     * przeglądu znika, a decyzja zapisuje się przy wersji. Zatwierdzenie wersji z adresu X zdejmuje blokadę X.
     * Karta bez bieżącej wersji i bez propozycji (opis zmieniony innym torem, powód został) — sam powód znika.
     * Bez version_id przy czekającej propozycji — 409: handlowiec zatwierdzał to, co widział (opis na karcie), a od
     * wczytania listy doszła propozycja, której nie widział; zatwierdzenie nie może jej opublikować.
     *
     * @return array{review_reason: string|null, current_version_id: int|null, batch_id: int|null, shop_source_url: string|null, shop_source_url_cleared: bool, files_from_previous: bool}
     */
    public function approve(Product $p, ?int $versionId, User $u, ?string $note): array
    {
        return DB::transaction(function () use ($p, $versionId, $u, $note): array {
            $product = $this->locked($p);
            $pending = $this->pendingProposal($product);
            $current = $this->versions->current($product);
            if ($versionId === null && $pending !== null) {
                throw new ProductReviewConflict('Na karcie pojawiła się nowa propozycja opisu — odśwież listę.');
            }
            $target = $versionId !== null ? $this->versionOf($product, $versionId) : $current;

            if ($target === null) {
                // karta bez wersji (np. opis sprzed wersji z powodem ustawionym ręcznie) — zostaje tylko zdjąć powód
                $this->clearReason($product);

                return $this->state($product);
            }
            if ($pending !== null && $target->is($pending)) {
                // synchronizacja B2B mogła od propozycji zapisać opis ze sklepu dostawcy — przegląd go nie nadpisuje
                if ($this->b2bDescriptions->has($product)) {
                    throw new ProductReviewConflict('Karta ma teraz opis z cennika B2B (ze sklepu dostawcy) — propozycja go nie zastąpi.');
                }
                $this->markDecision($target, ProductDescriptionVersion::DECISION_APPROVED, $u, $note);
                $this->versions->unblockSourceUrl($product, $target->primary_source_url, $u);

                return $this->state($this->versions->publish($target, $u, ProductDescriptionVersion::ORIGIN_REVIEW_APPROVE), filesFromPrevious: true);
            }
            if ($current !== null && $target->is($current)) {
                if ($pending !== null) {
                    throw new ProductReviewConflict('Na karcie czeka propozycja nowego opisu — zatwierdź ją albo odrzuć.');
                }
                if ($target->decision === null) {
                    $this->markDecision($target, ProductDescriptionVersion::DECISION_APPROVED, $u, $note);
                }
                $this->versions->unblockSourceUrl($product, $target->primary_source_url, $u);
                $this->clearReason($product);

                return $this->state($product);
            }
            // przed sprawdzeniem decyzji wersji: lista wysyła wersję opublikowaną, a ta (zatwierdzona kiedyś) mogła
            // przestać być opisem karty — karta i tak nie może utknąć na liście z powodem bez wersji
            if ($current === null && $pending === null) {
                // nie ma czego zatwierdzać ani czym zastąpić
                $this->clearReason($product);

                return $this->state($product);
            }
            if ($target->decision === ProductDescriptionVersion::DECISION_APPROVED) {
                return $this->state($product);
            }

            throw new ProductReviewConflict('Ta wersja opisu nie czeka już na decyzję — odśwież listę.');
        });
    }

    /**
     * Odrzucenie propozycji: propozycja → rejected, obecny opis zostaje, a powód przeglądu karty wynika z werdyktu
     * obecnego opisu (reasonForCurrent). Adres propozycji nie jest blokowany — obecny dobry opis bywa z tego samego
     * adresu.
     *
     * Odrzucenie opisu z karty („to cudza strona”): wersja → rejected z zablokowanym adresem (rejectedUrls), z karty
     * znikają zdjęcia i pliki z internetu dodane przez jej przebieg i wpis pamięci SKU, normy producenta wracają do
     * stanu sprzed przebiegu; na kartę wraca poprzedni opublikowany opis (nowa published, origin restore), a gdy go
     * nie ma — karta jest zerowana (ProductEnrichmentResetter::reset; tekst zostaje w odrzuconej wersji). Adres strony
     * wskazany ręcznie (shop_source_url), który prowadzi na odrzuconą stronę, znika z karty — inaczej ponowne
     * pobranie wzięłoby go znowu (adres podany ręcznie wygrywa z blokadą); shop_source_url_cleared w odpowiedzi. Na
     * końcu karta idzie do ponownego pobrania z force (batch_id w odpowiedzi) — automat omija już zablokowany adres.
     * Wszystko w jednej transakcji: odmowa kolejki (RuntimeException) cofa całą decyzję.
     *
     * @return array{review_reason: string|null, current_version_id: int|null, batch_id: int|null, shop_source_url: string|null, shop_source_url_cleared: bool, files_from_previous: bool}
     */
    public function reject(Product $p, ?int $versionId, User $u, ?string $note): array
    {
        return DB::transaction(function () use ($p, $versionId, $u, $note): array {
            $product = $this->locked($p);
            $pending = $this->pendingProposal($product);
            $current = $this->versions->current($product);
            $target = $versionId !== null ? $this->versionOf($product, $versionId) : ($pending ?? $current);

            if ($target === null) {
                throw new ProductReviewConflict('Karta nie ma wersji opisu do odrzucenia.');
            }
            if ($target->status === ProductDescriptionVersion::STATUS_REJECTED) {
                return $this->state($product);
            }
            if ($pending !== null && $target->is($pending)) {
                $this->markRejected($target, $u, $note);
                if (in_array($product->review_reason, self::PROPOSAL_REASONS, true)) {
                    $this->setReason($product, $this->reasonForCurrent($current));
                }

                return $this->state($product);
            }
            if ($current !== null && $target->is($current)) {
                if ($pending !== null) {
                    throw new ProductReviewConflict('Na karcie czeka propozycja nowego opisu — najpierw ją zatwierdź albo odrzuć.');
                }
                $hadShopUrl = trim((string) ($product->shop_source_url ?? '')) !== '';
                $this->markRejected($target, $u, $note);
                $this->versions->blockSourceUrl($target);
                $this->dropRunEffects($product, $target);
                // Adres podany ręcznie wygrywa w przebiegu z blokadą (rejectedSourceKeysFor, decide reguła 1) —
                // wskazujący odrzuconą stronę musi zniknąć, bo ponowne pobranie wzięłoby z niego ten sam opis.
                // Porównanie kluczem blokady (sourceUrlKey: też wariant hosta z „www.”/„m.”), żeby nie zostawić
                // ręcznego adresu, którego blokada i tak dotyczy.
                $hint = $product->hintedShopUrl();
                $rejectedUrl = trim((string) ($target->primary_source_url ?? ''));
                if ($hint !== null && $rejectedUrl !== ''
                    && DescriptionVersionStore::sourceUrlKey($hint) === DescriptionVersionStore::sourceUrlKey($rejectedUrl)) {
                    $product->update(['shop_source_url' => null]);
                }
                $previous = $this->previousPublished($product, $target);
                if ($previous !== null) {
                    $product = $this->versions->publish($previous, $u, ProductDescriptionVersion::ORIGIN_RESTORE);
                } else {
                    $this->resetter->reset($product);
                }
                $batch = app(ProductEnrichmentService::class)->enqueueProduct($product->refresh(), $u, force: true);
                $product->refresh();

                return $this->state(
                    $product,
                    (int) $batch->id,
                    shopUrlCleared: $hadShopUrl && trim((string) ($product->shop_source_url ?? '')) === '',
                );
            }

            throw new ProductReviewConflict('Ta wersja opisu nie jest już na karcie ani nie czeka na decyzję — odśwież listę.');
        });
    }

    /**
     * Adres strony wyrobu od handlowca: zapis w shop_source_url (link zaufany — omija bramki tożsamości), decyzja
     * url_given przy wersji czekającej na przegląd i ponowne pobranie opisu z force. Adres łącznika B2B nie jest stroną
     * dla człowieka (Product::trustedShopUrl) — 422. Drugie kliknięcie z tym samym adresem przy karcie w kolejce oddaje
     * otwartą partię zamiast nowej.
     *
     * @return array{review_reason: string|null, current_version_id: int|null, batch_id: int|null, shop_source_url: string|null, shop_source_url_cleared: bool, files_from_previous: bool}
     */
    public function giveUrl(Product $p, string $url, User $u): array
    {
        $url = mb_substr(trim($url), 0, 2000);
        $this->assertPublicPageUrl($url);
        if (B2bConnectorRegistry::isConnectorUrl($url)) {
            throw new DomainException('To adres sklepu B2B dostawcy — bez zalogowania strona jest pusta. Podaj stronę producenta albo sklepu internetowego z tym wyrobem.');
        }

        return DB::transaction(function () use ($p, $url, $u): array {
            $product = $this->locked($p);
            if ($this->b2bDescriptions->has($product)) {
                throw new ProductReviewConflict('Karta ma opis z cennika B2B (ze sklepu dostawcy) — przegląd go nie zmienia.');
            }
            if ($product->isHintedShopUrl($url)
                && in_array($product->enrichment_status, [Product::ENRICHMENT_QUEUED, Product::ENRICHMENT_RUNNING], true)) {
                $open = $this->openBatchId($product);
                if ($open !== null) {
                    return $this->state($product, $open);
                }
            }

            $target = $this->pendingProposal($product) ?? $this->versions->current($product);
            if ($target !== null && $target->decision === null) {
                $this->markDecision($target, ProductDescriptionVersion::DECISION_URL_GIVEN, $u, 'adres: '.$url);
            }
            $product->update([
                'shop_source_url' => $url,
                'review_reason' => null,
                'review_since' => null,
            ]);
            // w tej samej transakcji: kolejka „database” zapisuje zadanie razem z adresem, a odmowa cofa oba
            $batch = app(ProductEnrichmentService::class)->enqueueProduct($product, $u, force: true);

            return $this->state($product->refresh(), (int) $batch->id);
        });
    }

    /**
     * Przywrócenie wybranej wersji na kartę (nowa published, origin restore; zdjęcia i pliki zostają obecne —
     * files_from_previous). 409: opis karty ze sklepu B2B, wersja z przebiegu w cieniu albo bez tekstu. Wersja z tym
     * samym opisem co bieżący — bez zmian.
     *
     * @return array{current_version_id: int|null, description: string|null, files_from_previous: bool}
     */
    public function restore(Product $p, ProductDescriptionVersion $v, User $u, ?string $note): array
    {
        if ((int) $v->product_id !== (int) $p->id) {
            throw new ProductReviewConflict('Ta wersja należy do innej karty.');
        }
        if ($v->status === ProductDescriptionVersion::STATUS_SHADOW) {
            throw new ProductReviewConflict('Wersja z przebiegu próbnego (w cieniu) nie była opisem karty — nie da się jej przywrócić.');
        }

        return DB::transaction(function () use ($p, $v, $u, $note): array {
            $product = $this->locked($p);
            if ($this->b2bDescriptions->has($product)) {
                throw new ProductReviewConflict('Karta ma opis z cennika B2B (ze sklepu dostawcy) — wersji z pobierania nie przywraca się w jego miejsce.');
            }
            $current = $this->versions->current($product);
            if ($current !== null && $current->description_sha1 !== null && $current->description_sha1 === $v->description_sha1) {
                return ['current_version_id' => (int) $current->id, 'description' => $product->description, 'files_from_previous' => false];
            }
            if (! Product::isDescriptionText((string) ($v->description ?? ''))) {
                throw new ProductReviewConflict('Ta wersja nie ma tekstu opisu.');
            }
            if ($v->decision === null) {
                $this->markDecision($v, ProductDescriptionVersion::DECISION_RESTORED, $u, $note);
            }
            // handlowiec sam wybrał opis z tego adresu — adres przestaje być zablokowany
            $this->versions->unblockSourceUrl($product, $v->primary_source_url, $u);
            $product = $this->versions->publish($v, $u, ProductDescriptionVersion::ORIGIN_RESTORE);

            return [
                'current_version_id' => $this->versions->current($product)?->id,
                'description' => $product->description,
                // zdjęcia i pliki karty zostają te z opisu, który był na karcie — do sprawdzenia przez handlowca
                'files_from_previous' => true,
            ];
        });
    }

    /**
     * @param  list<object>  $rows
     * @return list<array<string, mixed>>
     */
    private function presentRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (object $row): int => (int) $row->id, $rows);

        $versionsByCard = [];
        $versionRows = ProductDescriptionVersion::query()
            ->whereIn('product_id', $ids)
            ->whereIn('status', [
                ProductDescriptionVersion::STATUS_PUBLISHED,
                ProductDescriptionVersion::STATUS_PROPOSED,
                ProductDescriptionVersion::STATUS_REJECTED,
            ])
            ->orderBy('id')
            ->get(['id', 'product_id', 'status', 'identity_verdict', 'evidence_count', 'decision', 'created_at', 'primary_source_url']);
        foreach ($versionRows as $version) {
            $versionsByCard[(int) $version->product_id][] = $version;
        }

        $images = [];
        $imageRows = ProductImage::query()
            ->whereIn('product_id', $ids)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        foreach ($imageRows as $image) {
            $images[(int) $image->product_id] ??= $image->url();
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            [$published, $proposal] = $this->publishedAndProposal($versionsByCard[$id] ?? []);
            $identity = $this->jsonObject($row->identity ?? null);
            $evidence = $this->jsonObject($row->evidence_summary ?? null);
            $out[] = [
                'product_id' => $id,
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'manufacturer' => (string) ($row->manufacturer ?? ''),
                'price_list_id' => (int) $row->price_list_id,
                'review_reason' => (string) $row->review_reason,
                'review_since' => $row->review_since !== null ? CarbonImmutable::parse((string) $row->review_since)->toJSON() : null,
                'enrichment_status' => (string) ($row->enrichment_status ?? Product::ENRICHMENT_NONE),
                'primary_source_url' => $this->jsonString($row->primary_source_url ?? null),
                'primary_source_kind' => $this->jsonString($row->primary_source_kind ?? null),
                'identity' => $identity !== null ? [
                    'verdict' => is_string($identity['verdict'] ?? null) ? $identity['verdict'] : null,
                    'reason' => is_string($identity['reason'] ?? null) ? $identity['reason'] : null,
                ] : null,
                'evidence_summary' => $evidence !== null ? [
                    'explicit' => (int) ($evidence['explicit'] ?? 0),
                    'inferred' => (int) ($evidence['inferred'] ?? 0),
                ] : null,
                'image_url' => $images[$id] ?? null,
                // adres źródła wersji (nie payloadu karty): odrzucenie blokuje adres wersji, a wersji bez adresu nic
                // nie blokuje — okno potwierdzenia nie może wtedy obiecywać pominięcia strony
                'published' => $published !== null ? [
                    'version_id' => (int) $published->id,
                    'identity_verdict' => $published->identity_verdict,
                    'evidence_count' => $published->evidence_count,
                    'primary_source_url' => $published->primary_source_url,
                ] : null,
                'proposal' => $proposal !== null ? [
                    'version_id' => (int) $proposal->id,
                    'identity_verdict' => $proposal->identity_verdict,
                    'evidence_count' => $proposal->evidence_count,
                    'primary_source_url' => $proposal->primary_source_url,
                    'created_at' => $proposal->created_at?->toJSON(),
                ] : null,
            ];
        }

        return $out;
    }

    /**
     * Wersja published karty (record() zostawia jedną) i czekająca propozycja — ta sama reguła co pendingProposal(),
     * na wersjach już wczytanych dla strony listy (rosnąco po id).
     *
     * @param  list<ProductDescriptionVersion>  $versions
     * @return array{0: ProductDescriptionVersion|null, 1: ProductDescriptionVersion|null}
     */
    private function publishedAndProposal(array $versions): array
    {
        $published = null;
        foreach ($versions as $version) {
            if ($version->status === ProductDescriptionVersion::STATUS_PUBLISHED) {
                $published = $version;
            }
        }
        $latest = null;
        foreach ($versions as $version) {
            if ($version->status !== ProductDescriptionVersion::STATUS_PUBLISHED
                && ($published === null || (int) $version->id > (int) $published->id)) {
                $latest = $version;
            }
        }
        $proposal = $latest !== null && $latest->status === ProductDescriptionVersion::STATUS_PROPOSED && $latest->decision === null
            ? $latest
            : null;

        return [$published, $proposal];
    }

    private function pendingProposal(Product $product): ?ProductDescriptionVersion
    {
        $publishedId = (int) ProductDescriptionVersion::query()
            ->where('product_id', $product->id)
            ->where('status', ProductDescriptionVersion::STATUS_PUBLISHED)
            ->max('id');
        $latest = ProductDescriptionVersion::query()
            ->where('product_id', $product->id)
            ->whereIn('status', [ProductDescriptionVersion::STATUS_PROPOSED, ProductDescriptionVersion::STATUS_REJECTED])
            ->when($publishedId > 0, static fn ($q) => $q->where('id', '>', $publishedId))
            ->orderByDesc('id')
            ->first();

        return $latest !== null && $latest->status === ProductDescriptionVersion::STATUS_PROPOSED && $latest->decision === null
            ? $latest
            : null;
    }

    /**
     * Opis, który był na karcie przed odrzuconym: najnowsza superseded sprzed niego z tekstem opisu, inna treścią
     * i spoza zablokowanych adresów (rejectedUrls — tylko odrzucone opisy z karty, nie odrzucone propozycje).
     */
    private function previousPublished(Product $product, ProductDescriptionVersion $rejected): ?ProductDescriptionVersion
    {
        $rejectedUrls = array_map(static fn (string $url): string => DescriptionVersionStore::sourceUrlKey($url), $this->versions->rejectedUrls($product));
        $candidates = ProductDescriptionVersion::query()
            ->where('product_id', $product->id)
            ->where('status', ProductDescriptionVersion::STATUS_SUPERSEDED)
            ->where('id', '<', $rejected->id)
            ->orderByDesc('id')
            ->get();
        foreach ($candidates as $candidate) {
            if ($candidate->description_sha1 === $rejected->description_sha1
                || ! Product::isDescriptionText((string) ($candidate->description ?? ''))) {
                continue;
            }
            $url = trim((string) ($candidate->primary_source_url ?? ''));
            if ($url !== '' && in_array(DescriptionVersionStore::sourceUrlKey($url), $rejectedUrls, true)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Adres od handlowca pobiera potem serwer (shop_source_url → ProductPageFetcher), więc przed zapisem musi wskazywać
     * publiczną stronę w internecie (SSRF): tylko http(s), port 80/443, bez danych logowania w adresie, a nazwa serwera
     * rozwiązuje się wyłącznie na publiczne adresy IP — ta sama reguła co przy pobieraniu stron klientów
     * (SmtpHostGuard::publicIps: bez localhost, adresu IP zamiast nazwy, sieci prywatnej, link-local i zarezerwowanej).
     */
    private function assertPublicPageUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? mb_strtolower((string) ($parts['scheme'] ?? '')) : '';
        if (! is_array($parts) || ! in_array($scheme, ['http', 'https'], true) || trim((string) ($parts['host'] ?? '')) === '') {
            throw new DomainException('Podaj adres strony zaczynający się od http:// albo https://.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new DomainException('Adres strony nie może zawierać nazwy użytkownika ani hasła.');
        }
        if (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true)) {
            throw new DomainException('Adres strony może używać tylko zwykłego portu (80 albo 443).');
        }
        $host = trim((string) $parts['host'], '[]');
        if ($this->hosts->publicIps($host) === null) {
            throw new DomainException('Adres musi wskazywać publiczną stronę w internecie — nie localhost, adres IP ani adres sieci wewnętrznej. Sprawdź też, czy nazwa strony jest poprawna.');
        }
    }

    private function locked(Product $p): Product
    {
        /** @var Product */
        return Product::query()->lockForUpdate()->findOrFail($p->id);
    }

    private function versionOf(Product $product, int $versionId): ProductDescriptionVersion
    {
        /** @var ProductDescriptionVersion */
        return ProductDescriptionVersion::query()
            ->where('product_id', $product->id)
            ->whereKey($versionId)
            ->firstOrFail();
    }

    private function markDecision(ProductDescriptionVersion $version, string $decision, User $u, ?string $note): void
    {
        $version->forceFill([
            'decision' => $decision,
            'decided_by' => $u->id,
            'decided_at' => now(),
            'reason' => $this->withNote($version->reason, $note),
        ])->save();
    }

    private function markRejected(ProductDescriptionVersion $version, User $u, ?string $note): void
    {
        $version->status = ProductDescriptionVersion::STATUS_REJECTED;
        $this->markDecision($version, ProductDescriptionVersion::DECISION_REJECTED, $u, $note);
    }

    /** Uwaga handlowca dopisana do powodu wersji (kolumna reason, 255 znaków). */
    private function withNote(?string $reason, ?string $note): ?string
    {
        $note = trim((string) $note);
        if ($note === '') {
            return $reason;
        }
        $reason = trim((string) $reason);

        return mb_substr(($reason !== '' ? $reason.' | ' : '').'uwaga: '.$note, 0, 255);
    }

    private function clearReason(Product $product): void
    {
        if ($product->review_reason !== null || $product->review_since !== null) {
            $product->update(['review_reason' => null, 'review_since' => null]);
        }
    }

    /** Powód przeglądu karty; ten sam co dotąd zachowuje datę, nowy liczy się od teraz. */
    private function setReason(Product $product, ?string $reason): void
    {
        if ($reason === null) {
            $this->clearReason($product);

            return;
        }
        if ($product->review_reason === $reason && $product->review_since !== null) {
            return;
        }
        $product->update(['review_reason' => $reason, 'review_since' => now()]);
    }

    /**
     * Powód przeglądu obecnego opisu po decyzji o propozycji — jak przy zapisie (DescriptionVersionStore::decide):
     * strona bez kodu → identity_soft, niepotwierdzona → identity_none, potwierdzona kodem albo nieznana → brak.
     * Opis już zatwierdzony przez człowieka (decyzja approved, origin review_approve) nie wraca na listę.
     */
    private function reasonForCurrent(?ProductDescriptionVersion $current): ?string
    {
        if ($current === null
            || $current->decision === ProductDescriptionVersion::DECISION_APPROVED
            || $current->origin === ProductDescriptionVersion::ORIGIN_REVIEW_APPROVE) {
            return null;
        }

        return match ($current->identity_verdict) {
            ProductDescriptionVersion::VERDICT_SOFT => Product::REVIEW_IDENTITY_SOFT,
            ProductDescriptionVersion::VERDICT_NONE => Product::REVIEW_IDENTITY_NONE,
            default => null,
        };
    }

    /**
     * Skutki przebiegu odrzuconego opisu poza samym opisem: zdjęcia i pliki z internetu dodane przez ten przebieg
     * (z plikami na dysku, bez plików z panelu B2B i wgranych ręcznie), wpis pamięci SKU z tym opisem i normy
     * producenta — przywrócone do stanu sprzed przebiegu tylko wtedy, gdy ten przebieg je zapisał i na karcie stoi
     * dalej jego wartość (ManufacturerNormFacts::sameFacts, ta sama reguła co przy propozycji); zapis późniejszy
     * (synchronizacja, inny przebieg, handlowiec) zostaje. Wersja bez danych technicznych (bazowa, z przeglądu) —
     * tylko pamięć SKU.
     */
    private function dropRunEffects(Product $product, ProductDescriptionVersion $rejected): void
    {
        $meta = $this->versions->meta($rejected);
        $files = is_array($meta['web_file_ids'] ?? null) ? $meta['web_file_ids'] : [];
        $ids = static fn (mixed $list): array => array_values(array_map(
            static fn ($id): int => (int) $id,
            array_filter(is_array($list) ? $list : [], 'is_numeric'),
        ));
        $this->resetter->dropRejectedRunFiles($product, $ids($files['images'] ?? []), $ids($files['documents'] ?? []));
        $written = $meta['manufacturer_norms_written'] ?? null;
        if (array_key_exists('manufacturer_norms_before', $meta)
            && is_array($written)
            && ManufacturerNormFacts::sameFacts($product->manufacturer_norms, $written)) {
            // przez model — hak saving przelicza indeks wyszukiwania
            $product->manufacturer_norms = $meta['manufacturer_norms_before'];
            if ($product->isDirty('manufacturer_norms')) {
                $product->save();
            }
        }
    }

    private function openBatchId(Product $product): ?int
    {
        $id = ProductEnrichmentBatch::query()
            ->where('scope', ProductEnrichmentBatch::SCOPE_PRODUCT)
            ->where('scope_id', $product->id)
            ->whereIn('status', [ProductEnrichmentBatch::STATUS_QUEUED, ProductEnrichmentBatch::STATUS_RUNNING])
            ->max('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * files_from_previous: opis trafił na kartę bez swoich zdjęć i plików (zostały te z poprzedniego opisu).
     * shop_source_url_cleared: decyzja usunęła z karty adres strony wskazany ręcznie (odrzucenie opisu z tej strony
     * albo wyzerowanie karty bez poprzedniego opisu — ProductEnrichmentResetter::reset zeruje też ten adres).
     *
     * @return array{review_reason: string|null, current_version_id: int|null, batch_id: int|null, shop_source_url: string|null, shop_source_url_cleared: bool, files_from_previous: bool}
     */
    private function state(Product $product, ?int $batchId = null, bool $filesFromPrevious = false, bool $shopUrlCleared = false): array
    {
        return [
            'review_reason' => $product->review_reason,
            'current_version_id' => $this->versions->current($product)?->id,
            'batch_id' => $batchId,
            'shop_source_url' => $product->shop_source_url,
            'shop_source_url_cleared' => $shopUrlCleared,
            'files_from_previous' => $filesFromPrevious,
        ];
    }

    /** Wartość ścieżki JSON: MySQL json_unquote zwraca napis „null” dla JSON null. */
    private function jsonString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' || $value === 'null' ? null : $value;
    }

    /**
     * Lista JSON ze ścieżki (tekst JSON z MySQL i SQLite).
     *
     * @return list<mixed>
     */
    private function jsonList(mixed $value): array
    {
        $decoded = is_array($value) ? $value : $this->jsonObject($value);

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }

    /**
     * Obiekt JSON ze ścieżki (MySQL i SQLite oddają go jako tekst JSON).
     *
     * @return array<string, mixed>|null
     */
    private function jsonObject(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '' || $value === 'null') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
