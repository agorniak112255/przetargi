<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Support\BhpAttributeNormalizer;
use App\Support\ProductSizeVariant;
use Illuminate\Database\Eloquent\Builder;
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
        $notEnriched = (clone $queueable)
            ->where('enrichment_status', '!=', Product::ENRICHMENT_DONE)
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
        $idsMissingDescription = [];
        $idsNotEnriched = [];
        $idsMissingAttributes = [];

        (clone $base)
            ->select(['id', 'description', 'enrichment_status', 'enrichment_payload', 'manufacturer'])
            ->orderBy('id')
            ->chunkById(200, function ($products) use (
                &$missingAttributes,
                &$idsMissingDescription,
                &$idsNotEnriched,
                &$idsMissingAttributes,
            ): void {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $manual = $product->enrichment_status === Product::ENRICHMENT_MANUAL;
                    $desc = trim((string) ($product->description ?? ''));
                    if ($desc === '' && ! $manual) {
                        $idsMissingDescription[] = (int) $product->id;
                    }
                    if ($product->enrichment_status !== Product::ENRICHMENT_DONE && ! $manual) {
                        $idsNotEnriched[] = (int) $product->id;
                    }
                    if (! $this->hasUsefulAttributes($product)) {
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
            'not_enriched' => $notEnriched,
            'manual_review' => $manualReview,
            'empty_packaging' => $emptyPackaging,
            'with_description' => max(0, $total - $missingDescription),
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
        if (config('queue.default') === 'database' && Schema::hasTable('jobs')) {
            $pending = DB::table('jobs')
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
            ->select(['id', 'description', 'packaging', 'enrichment_payload'])
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
                $pack = trim((string) ($product->packaging ?? ''));
                if ($pack !== '' && preg_match('/[,;]/', $pack) === 1) {
                    $skipped++;

                    continue;
                }
                $found = $this->sizesFromStoredText($product);
                if ($found === [] || ! $this->sizes->shouldFillPackaging($product->packaging, $found)) {
                    $skipped++;

                    continue;
                }
                $label = $this->sizes->formatPackaging($found);
                if ($label === null) {
                    $skipped++;

                    continue;
                }
                $product->packaging = $label;
                $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
                $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
                if (($attrs['rozmiar'] ?? null) === null || $attrs['rozmiar'] === '') {
                    $attrs['rozmiar'] = $label;
                    $payload['attributes'] = $attrs;
                    $product->enrichment_payload = $payload;
                }
                $product->saveQuietly();
                $updated++;
            }
        });

        return ['scanned' => $scanned, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @return list<string>
     */
    private function sizesFromStoredText(Product $product): array
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
        $found = [];
        $chunks = [(string) ($attrs['rozmiar'] ?? '')];
        foreach ($payload['specs'] ?? [] as $spec) {
            if (is_string($spec) && trim($spec) !== '') {
                $chunks[] = $spec;
            }
        }
        $chunks[] = (string) ($product->description ?? '');
        foreach ($chunks as $chunk) {
            $parsed = $this->sizes->parseSizesFromText($chunk);
            if (count($parsed) > count($found)) {
                $found = $parsed;
            }
        }

        return $found;
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
            'not_enriched' => $query
                ->where('enrichment_status', '!=', Product::ENRICHMENT_DONE)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all(),
            default => throw new RuntimeException('Nieznany powód kolejki: '.$reason),
        };
    }

    private function hasUsefulAttributes(Product $product): bool
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
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
