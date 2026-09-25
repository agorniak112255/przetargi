<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\B2b\B2bDescriptionSource;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Support\BhpAttributeNormalizer;
use App\Support\ProductSizeVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Raport jakości katalogu + kolejka enrichment dla braków.
 */
final class ProductCatalogHealthService
{
    public function __construct(
        private readonly ProductEnrichmentService $enrichment,
        private readonly BhpAttributeNormalizer $bhpAttributes,
        private readonly AiSettingsService $aiSettings,
        private readonly ProductSizeVariant $sizes,
        private readonly B2bDescriptionSource $b2bDescriptions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(?string $manufacturer = null): array
    {
        $base = Product::query();
        if ($manufacturer !== null && trim($manufacturer) !== '') {
            $base->where('manufacturer', $manufacturer);
        }

        $total = (clone $base)->count();
        // pozycje „do ręcznego opisu” wypadają z liczników kolejek — AI ich nie ruszy
        $queueable = (clone $base)->where('enrichment_status', '!=', Product::ENRICHMENT_MANUAL);
        $missingDescription = (clone $queueable)
            ->where(function ($q): void {
                $q->whereNull('description')->orWhere('description', '');
            })
            ->count();
        $missingImages = (clone $base)
            ->whereDoesntHave('images')
            ->count();
        $manualReview = (clone $base)
            ->where('enrichment_status', Product::ENRICHMENT_MANUAL)
            ->count();
        $emptyPackaging = (clone $base)
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->where(function ($q): void {
                $q->whereNull('packaging')->orWhere('packaging', '');
            })
            ->count();

        $missingAttributes = 0;
        $withDescription = 0;
        $fromB2b = 0;
        $idsMissingDescription = [];
        $idsNotEnriched = [];
        $idsMissingAttributes = [];

        // Raport idzie przy każdym wejściu na Produkty i przechodzi przez cały katalog (25.09.2026: 48 tys. kart, 35 MB
        // opisów, 17 MB enrichment_payload) — stąd wiersze bez modeli Eloquent i porcje po 1000 zamiast 200.
        (clone $base)
            ->select(['id', 'description', 'enrichment_status', 'enrichment_payload'])
            ->toBase()
            ->chunkById(1000, function ($products) use (
                &$missingAttributes,
                &$withDescription,
                &$fromB2b,
                &$idsMissingDescription,
                &$idsNotEnriched,
                &$idsMissingAttributes,
            ): void {
                // karty z opisem z cennika B2B nie idą do zbiorczego AI (ProductEnrichmentService::enqueueProductIds),
                // więc nie liczymy ich jako „nie wzbogacone” — inaczej licznik kolejki obiecuje pozycje, których AI nie ruszy
                $b2bDescribed = $this->b2bDescriptions->filterByDescription(
                    $products
                        ->mapWithKeys(static fn ($product): array => [
                            (int) $product->id => (string) ($product->description ?? ''),
                        ])
                        ->all()
                );

                foreach ($products as $product) {
                    $manual = $product->enrichment_status === Product::ENRICHMENT_MANUAL;
                    $desc = trim((string) ($product->description ?? ''));
                    $b2b = isset($b2bDescribed[(int) $product->id]);
                    if ($desc !== '') {
                        $withDescription++;
                    }
                    if ($b2b) {
                        $fromB2b++;
                    }
                    if ($desc === '' && ! $manual) {
                        $idsMissingDescription[] = (int) $product->id;
                    }
                    if ($product->enrichment_status !== Product::ENRICHMENT_DONE && ! $manual && ! $b2b) {
                        $idsNotEnriched[] = (int) $product->id;
                    }
                    // tak samo jak rzutowanie 'array' modelu (HasAttributes::fromJson)
                    $payload = $product->enrichment_payload === null || $product->enrichment_payload === ''
                        ? null
                        : Json::decode($product->enrichment_payload, true);
                    if (! $this->payloadHasUsefulAttributes($payload)) {
                        $missingAttributes++;
                        $idsMissingAttributes[] = (int) $product->id;
                    }
                }
            });

        $byManufacturer = Product::query()
            ->select('manufacturer', DB::raw('COUNT(*) as cnt'))
            ->when(
                $manufacturer !== null && trim($manufacturer) !== '',
                fn ($q) => $q->where('manufacturer', $manufacturer)
            )
            ->groupBy('manufacturer')
            ->orderByDesc('cnt')
            ->limit(12)
            ->get()
            ->map(static fn ($r): array => [
                'manufacturer' => $r->manufacturer ?: '(brak)',
                'count' => (int) $r->cnt,
            ])
            ->values()
            ->all();

        return [
            'total' => $total,
            'missing_description' => $missingDescription,
            'missing_images' => $missingImages,
            'missing_attributes' => $missingAttributes,
            'not_enriched' => count($idsNotEnriched),
            'manual_review' => $manualReview,
            'empty_packaging' => $emptyPackaging,
            'with_description' => $withDescription,
            'from_b2b' => $fromB2b,
            'vector' => $this->vectorProgress($base),
            'by_manufacturer' => $byManufacturer,
            'queue_candidates' => [
                'missing_description' => count($idsMissingDescription),
                'not_enriched' => count($idsNotEnriched),
                'missing_attributes' => count($idsMissingAttributes),
            ],
            // id tylko do kolejki (limit w enqueue)
            'sample_ids' => [
                'missing_description' => array_slice($idsMissingDescription, 0, 20),
                'not_enriched' => array_slice($idsNotEnriched, 0, 20),
                'missing_attributes' => array_slice($idsMissingAttributes, 0, 20),
            ],
            'offer_markup_percent' => (int) config('pricing.offer_markup_percent', 18),
        ];
    }

    /**
     * Sam postęp wektorów — tani odpowiednik report() do odpytywania w trakcie reindeksu.
     *
     * @return array{enabled: bool, indexed: int, pending_jobs: int}
     */
    public function vectorReport(?string $manufacturer = null): array
    {
        $base = Product::query();
        if ($manufacturer !== null && trim($manufacturer) !== '') {
            $base->where('manufacturer', $manufacturer);
        }

        return $this->vectorProgress($base);
    }

    /**
     * Postęp indeksowania wektorów: ile produktów ma świeży embedding i ile zadań czeka.
     *
     * @param  Builder<Product>  $base
     * @return array{enabled: bool, indexed: int, pending_jobs: int}
     */
    private function vectorProgress(Builder $base): array
    {
        $indexed = 0;
        if (Schema::hasColumn('products', 'embedding_synced_at')) {
            $indexed = (clone $base)->whereNotNull('embedding_synced_at')->count();
        }

        $pending = 0;
        if (config('queue.default') === 'database' && Schema::hasTable(ReindexProductEmbeddingJob::TABLE)) {
            $pending = DB::table(ReindexProductEmbeddingJob::TABLE)
                ->where('queue', ReindexProductEmbeddingJob::QUEUE)
                ->count();
        }

        return [
            'enabled' => $this->aiSettings->isVectorReady(),
            'indexed' => $indexed,
            'pending_jobs' => $pending,
        ];
    }

    /**
     * @return array{batch: mixed, queued: int, reason: string, message: string}
     */
    public function queue(
        User $user,
        string $reason,
        ?string $manufacturer = null,
        bool $force = false,
    ): array {
        $ids = $this->candidateIds($reason, $manufacturer);
        if ($ids === []) {
            throw new RuntimeException('Brak produktów do kolejki dla wybranego filtra.');
        }

        $queued = $this->enrichment->enqueueProductIds($ids, $user, $force);
        $batch = $queued['batch'];

        return [
            'batch' => $batch,
            'queued' => (int) $batch->total,
            'reason' => $reason,
            'message' => (string) ($batch->message ?? 'Dodano do kolejki enrichment.'),
        ];
    }

    /**
     * Lokalny backfill atrybutów BHP (bez AI) dla produktów bez użytecznych attributes.
     * Rozdziela trafienia od pozycji, w których nazwa i normy nic nie dały — te czekają na opis.
     *
     * @return array{updated: int, filled: int, pending: int}
     */
    public function backfillAttributes(?string $manufacturer = null, bool $force = false): array
    {
        $filled = 0;
        $pending = 0;
        $query = Product::query()->orderBy('id');
        if ($manufacturer !== null && trim($manufacturer) !== '') {
            $query->where('manufacturer', $manufacturer);
        }

        $query->chunkById(100, function ($products) use ($force, &$filled, &$pending): void {
            foreach ($products as $product) {
                /** @var Product $product */
                if (! $force && $this->hasUsefulAttributes($product)) {
                    continue;
                }
                $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
                $attributes = $this->bhpAttributes->forProduct($product);
                $payload['attributes'] = $attributes;
                $product->enrichment_payload = $payload;
                $product->saveQuietly();

                if ($this->attributesAreUseful($attributes)) {
                    $filled++;
                } else {
                    $pending++;
                }
            }
        });

        return ['updated' => $filled + $pending, 'filled' => $filled, 'pending' => $pending];
    }

    /**
     * Uzupełnia packaging / rozmiar z już zapisanego opisu — bez AI i bez sieci.
     *
     * @return array{scanned: int, updated: int, skipped: int}
     */
    public function backfillSizesFromDescriptions(?string $manufacturer = null): array
    {
        $scanned = 0;
        $updated = 0;
        $skipped = 0;
        $query = Product::query()
            ->select(['id', 'description', 'packaging', 'category', 'enrichment_payload'])
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->orderBy('id');
        if ($manufacturer !== null && trim($manufacturer) !== '') {
            $query->where('manufacturer', $manufacturer);
        }

        $query->chunkById(500, function ($products) use (&$scanned, &$updated, &$skipped): void {
            foreach ($products as $product) {
                /** @var Product $product */
                $scanned++;
                $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
                $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
                $category = is_string($attrs['kategoria_bhp'] ?? null)
                    ? $attrs['kategoria_bhp']
                    : ($product->category !== null ? (string) $product->category : null);
                $chunks = [];
                foreach ($payload['specs'] ?? [] as $spec) {
                    if (is_string($spec) && trim($spec) !== '') {
                        $chunks[] = $spec;
                    }
                }
                $chunks[] = (string) ($product->description ?? '');
                $label = $this->sizes->labelFromTexts(
                    is_string($attrs['rozmiar'] ?? null) ? $attrs['rozmiar'] : null,
                    implode("\n", $chunks),
                    $category
                );
                $changed = false;
                if (($attrs['rozmiar'] ?? null) !== $label) {
                    $attrs['rozmiar'] = $label;
                    $payload['attributes'] = $attrs;
                    $product->enrichment_payload = $payload;
                    $changed = true;
                }
                $found = $label !== null ? $this->sizes->parseSizesFromText($label) : [];
                if ($found !== [] && $this->sizes->shouldFillPackaging($product->packaging, $found)) {
                    $product->packaging = $label;
                    $changed = true;
                }
                if (! $changed) {
                    $skipped++;

                    continue;
                }
                $product->saveQuietly();
                $updated++;
            }
        });

        return ['scanned' => $scanned, 'updated' => $updated, 'skipped' => $skipped];
    }

    /** @return list<int> */
    private function candidateIds(string $reason, ?string $manufacturer): array
    {
        $query = Product::query()
            ->orderBy('id')
            ->where('enrichment_status', '!=', Product::ENRICHMENT_MANUAL);
        if ($manufacturer !== null && trim($manufacturer) !== '') {
            $query->where('manufacturer', $manufacturer);
        }

        return match ($reason) {
            'missing_description' => $query
                ->where(function ($q): void {
                    $q->whereNull('description')->orWhere('description', '');
                })
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all(),
            'not_enriched' => $this->withoutB2bDescription(
                $query->where('enrichment_status', '!=', Product::ENRICHMENT_DONE)
            ),
            default => throw new RuntimeException('Nieznany powód kolejki: '.$reason),
        };
    }

    /**
     * Id z zapytania bez kart z opisem z cennika B2B — zbiorcze AI i tak je pomija.
     *
     * @param  Builder<Product>  $query
     * @return list<int>
     */
    private function withoutB2bDescription(Builder $query): array
    {
        $descriptions = [];
        foreach ($query->get(['id', 'description']) as $product) {
            $descriptions[(int) $product->id] = (string) ($product->description ?? '');
        }
        $b2bDescribed = $this->b2bDescriptions->filterByDescription($descriptions);

        return array_values(array_filter(
            array_keys($descriptions),
            static fn (int $id): bool => ! isset($b2bDescribed[$id]),
        ));
    }

    private function hasUsefulAttributes(Product $product): bool
    {
        return $this->payloadHasUsefulAttributes($product->enrichment_payload);
    }

    /** @param  mixed  $payload  zdekodowany enrichment_payload karty */
    private function payloadHasUsefulAttributes(mixed $payload): bool
    {
        $payload = is_array($payload) ? $payload : [];
        $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : null;

        return $attrs !== null && $this->attributesAreUseful($attrs);
    }

    /** @param  array<string, mixed>  $attrs */
    private function attributesAreUseful(array $attrs): bool
    {
        return ($attrs['material'] ?? null) !== null
            || ($attrs['kategoria_bhp'] ?? null) !== null
            || (is_array($attrs['normy_en'] ?? null) && $attrs['normy_en'] !== []);
    }
}
