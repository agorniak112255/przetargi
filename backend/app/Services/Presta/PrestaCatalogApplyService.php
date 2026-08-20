<?php

declare(strict_types=1);

namespace App\Services\Presta;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\PrestaProductMatch;
use App\Models\Product;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductPageFetcher;
use App\Support\BhpAttributeNormalizer;
use RuntimeException;

final class PrestaCatalogApplyService
{
    public function __construct(
        private readonly PrestaCatalogGateway $catalog,
        private readonly ProductImageDownloader $images,
        private readonly ProductPageFetcher $pages,
        private readonly BhpAttributeNormalizer $bhpAttributes,
    ) {}

    /**
     * @return array{product: Product, match: PrestaProductMatch, images: int}
     */
    public function apply(Product $product, int $prestaId, bool $force = false): array
    {
        $card = $this->catalog->findCard($prestaId);
        if ($card === null) {
            throw new RuntimeException('Nie znaleziono karty w Preście (id '.$prestaId.').');
        }

        $description = $this->plainText(
            (string) ($card['description'] ?? ''),
            (string) ($card['description_short'] ?? '')
        );
        if ($description === '') {
            throw new RuntimeException('Karta Presty nie ma opisu do skopiowania.');
        }

        $existing = trim((string) ($product->description ?? ''));
        if (! $force && $existing !== '' && mb_strlen($existing) >= 24) {
            throw new RuntimeException('Produkt ma już opis. Zaznacz „nadpisz”, aby wziąć treść ze sklepu.');
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

        $product->description = mb_substr($description, 0, 10000);
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
                'method' => 'manual',
                'score' => 100,
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
        if (! $force && $product->images->isNotEmpty()) {
            return 0;
        }

        $prestaId = (int) ($card['id_product'] ?? 0);
        $rewrite = (string) ($card['link_rewrite'] ?? '');
        $urls = $this->catalog->imageUrls($prestaId, $rewrite);

        if ($urls === []) {
            $pageUrl = (string) ($card['url'] ?? '');
            if ($pageUrl !== '') {
                $fetched = $this->pages->fetch(
                    [['url' => $pageUrl, 'title' => (string) ($card['name'] ?? '')]],
                    (string) (($card['reference'] ?? '') !== '' ? $card['reference'] : $product->sku),
                    1,
                    ['supon.rzeszow.pl']
                );
                $urls = array_values(array_unique(array_merge(
                    $fetched['trusted_image_urls'] ?? [],
                    $fetched['image_urls'] ?? []
                )));
            }
        }

        return count($this->images->downloadMany($product, $urls, 3));
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
