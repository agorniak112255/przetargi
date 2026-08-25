<?php

declare(strict_types=1);

namespace App\Services\Presta;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\PrestaProductMatch;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Enrichment\ProductImageDownloader;
use App\Support\BhpAttributeNormalizer;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class PrestaCatalogApplyService
{
    public function __construct(
        private readonly PrestaCatalogGateway $catalog,
        private readonly ProductImageDownloader $images,
        private readonly BhpAttributeNormalizer $bhpAttributes,
    ) {}

    /**
     * @param  list<array{product_id: int, presta_id: int, method?: string, score?: int}>  $items
     * @return array{applied: int, skipped: int, failed: int, errors: list<string>}
     */
    public function applyMany(array $items, bool $force = false): array
    {
        $applied = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];
        $seen = [];

        foreach ($items as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $prestaId = (int) ($row['presta_id'] ?? 0);
            if ($productId <= 0 || $prestaId <= 0 || isset($seen[$productId])) {
                continue;
            }
            $seen[$productId] = true;
            $product = Product::query()->find($productId);
            if (! $product instanceof Product) {
                $failed++;
                $errors[] = 'Brak produktu #'.$productId;

                continue;
            }
            try {
                $this->apply(
                    $product,
                    $prestaId,
                    $force,
                    (string) ($row['method'] ?? 'manual'),
                    (int) ($row['score'] ?? 100),
                );
                $applied++;
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), 'już uzupełniony')) {
                    $skipped++;

                    continue;
                }
                $failed++;
                $errors[] = $product->sku.': '.$e->getMessage();
            }
        }

        return [
            'applied' => $applied,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => array_slice($errors, 0, 12),
        ];
    }

    /**
     * @return array{product: Product, match: PrestaProductMatch, images: int}
     */
    public function apply(Product $product, int $prestaId, bool $force = false, string $method = 'manual', int $score = 100): array
    {
        $card = $this->catalog->findCard($prestaId);
        if ($card === null) {
            throw new RuntimeException('Nie znaleziono karty w Preście (id '.$prestaId.').');
        }

        $description = $this->plainText(
            (string) ($card['description'] ?? ''),
            (string) ($card['description_short'] ?? '')
        );
        $existing = trim((string) ($product->description ?? ''));
        $hasDesc = $existing !== '' && mb_strlen($existing) >= 24;
        $writeDesc = $description !== '' && ($force || ! $hasDesc);
        if ($description === '' && ! $hasDesc) {
            throw new RuntimeException('Karta Presty nie ma opisu do skopiowania.');
        }

        $features = $this->featureList((string) ($card['features'] ?? ''));
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $payload['features'] = $features !== [] ? $features : ($payload['features'] ?? []);
        $payload['source_urls'] = array_values(array_unique(array_filter([
            ...(is_array($payload['source_urls'] ?? null) ? $payload['source_urls'] : []),
            (string) ($card['url'] ?? ''),
        ])));
        $payload['from_presta'] = true;
        $payload['presta_id'] = $prestaId;
        $attrs = $this->bhpAttributes->normalize(
            is_array($payload['attributes'] ?? null) ? $payload['attributes'] : null,
            [
                'norms' => $features,
                'description' => $description,
                'name' => (string) ($card['name'] ?? $product->name),
                'sku' => (string) ($card['reference'] ?? $product->sku),
            ]
        );
        $payload['attributes'] = $attrs;

        if ($writeDesc) {
            $product->description = mb_substr($description, 0, 10000);
        }
        if (trim((string) ($product->norms ?? '')) === '' && $attrs['normy_en'] !== []) {
            $product->norms = implode(', ', $attrs['normy_en']);
        }
        $product->enrichment_payload = $payload;
        $product->enrichment_status = Product::ENRICHMENT_DONE;
        $product->enrichment_error = null;
        $product->enriched_at = now();
        $product->save();

        $imageCount = $this->downloadImages($product, $card, $force);

        $match = PrestaProductMatch::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'presta_id' => $prestaId,
            ],
            [
                'method' => mb_substr($method !== '' ? $method : 'manual', 0, 32),
                'score' => max(0, min(100, $score)),
                'status' => PrestaProductMatch::STATUS_APPLIED,
                'presta_url' => (string) ($card['url'] ?? ''),
                'presta_reference' => mb_substr((string) ($card['reference'] ?? ''), 0, 128),
                'presta_name' => mb_substr((string) ($card['name'] ?? ''), 0, 255),
            ]
        );

        ReindexProductEmbeddingJob::dispatch($product->id);

        return [
            'product' => $product->fresh(['images']),
            'match' => $match,
            'images' => $imageCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function downloadImages(Product $product, array $card, bool $force): int
    {
        $product->load('images');
        if ($force) {
            $product->images()->where('path', 'remote')->delete();
            $product->unsetRelation('images');
            $product->load('images');
        }
        if (! $force && $product->images->isNotEmpty()) {
            return $product->images->count();
        }

        $prestaId = (int) ($card['id_product'] ?? 0);
        $rewrite = (string) ($card['link_rewrite'] ?? '');
        $urls = $this->catalog->imageUrls($prestaId, $rewrite);
        $pageUrl = (string) ($card['url'] ?? '');
        if ($pageUrl !== '') {
            $urls = array_values(array_unique(array_merge($urls, $this->ogImageUrls($pageUrl))));
        }

        $saved = $this->images->downloadMany($product, array_slice($urls, 0, 8), 3);
        if ($saved !== []) {
            return count($saved);
        }

        return $this->attachRemoteImages($product, $urls);
    }

    /**
     * @return list<string>
     */
    private function ogImageUrls(string $pageUrl): array
    {
        if (! str_starts_with($pageUrl, 'http')) {
            return [];
        }

        try {
            $response = Http::timeout(8)
                ->connectTimeout(3)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->withOptions(['allow_redirects' => true])
                ->get($pageUrl);
            if (! $response->successful()) {
                return [];
            }
            $html = $response->body();
            $urls = [];
            if (preg_match_all('#property=["\']og:image(?::secure_url)?["\'][^>]*content=["\']([^"\']+)#i', $html, $m)
                || preg_match_all('#content=["\']([^"\']+)["\'][^>]*property=["\']og:image#i', $html, $m)) {
                foreach ($m[1] as $url) {
                    $urls[] = html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
            if (preg_match_all('#https?://[^"\']+\d+-large_default/[^"\']+\.(?:jpe?g|webp)#i', $html, $m2)) {
                foreach ($m2[0] as $url) {
                    $urls[] = $url;
                }
            }

            return array_values(array_unique(array_filter($urls)));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<string>  $urls
     */
    private function attachRemoteImages(Product $product, array $urls): int
    {
        $added = 0;
        $sort = (int) $product->images()->max('sort_order');
        foreach ($urls as $url) {
            if ($added >= 3 || ! is_string($url) || ! ProductImageDownloader::looksLikeImageUrl($url)) {
                continue;
            }
            $checksum = hash('sha256', 'remote:'.$url);
            $exists = ProductImage::query()
                ->where('product_id', $product->id)
                ->where(function ($q) use ($checksum, $url): void {
                    $q->where('checksum', $checksum)->orWhere('source_url', $url);
                })
                ->exists();
            if ($exists) {
                continue;
            }
            ProductImage::query()->create([
                'product_id' => $product->id,
                'path' => 'remote',
                'source_url' => mb_substr($url, 0, 2000),
                'is_primary' => $added === 0,
                'sort_order' => $sort + $added,
                'checksum' => $checksum,
            ]);
            $added++;
        }

        return $added;
    }

    private function plainText(string $html, string $fallbackHtml): string
    {
        $text = $this->stripHtml($html);
        if (mb_strlen($text) < 24) {
            $text = $this->stripHtml($fallbackHtml);
        }

        return $text;
    }

    private function stripHtml(string $html): string
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/p>/i', "\n\n", $html) ?? $html;
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    /**
     * @return list<string>
     */
    private function featureList(string $features): array
    {
        if (trim($features) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $v): string => trim($v),
            explode(';', $features)
        )));
    }
}
