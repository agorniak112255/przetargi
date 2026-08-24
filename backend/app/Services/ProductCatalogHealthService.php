<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Support\BhpAttributeNormalizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Raport jakości katalogu + kolejka enrichment dla braków.
 */
final class ProductCatalogHealthService
{
    public function __construct(
        private readonly ProductEnrichmentService $enrichment,
        private readonly BhpAttributeNormalizer $bhpAttributes,
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
        $missingDescription = (clone $base)
            ->where(function ($q): void {
                $q->whereNull('description')->orWhere('description', '');
            })
            ->count();
        $missingImages = (clone $base)
            ->whereDoesntHave('images')
            ->count();
        $notEnriched = (clone $base)
            ->where('enrichment_status', '!=', Product::ENRICHMENT_DONE)
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
                    $desc = trim((string) ($product->description ?? ''));
                    if ($desc === '') {
                        $idsMissingDescription[] = (int) $product->id;
                    }
                    if ($product->enrichment_status !== Product::ENRICHMENT_DONE) {
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
            'with_description' => max(0, $total - $missingDescription),
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

    /** @return list<int> */
    private function candidateIds(string $reason, ?string $manufacturer): array
    {
        $query = Product::query()->orderBy('id');
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
