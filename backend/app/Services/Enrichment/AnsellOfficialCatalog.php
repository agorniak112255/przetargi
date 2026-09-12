<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;

/**
 * Karta AlphaTec na ansell.com — bpbhp i inni często nie mają danego modelu.
 */
final class AnsellOfficialCatalog
{
    public function __construct(
        private readonly ProductSearchIdentity $identity,
        private readonly BlockedPageReader $reader,
    ) {}

    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    public function find(Product $product): array
    {
        $urls = array_values(array_unique(array_merge(
            array_slice($this->identity->ansellOfficialProductUrls($product), 0, 9),
            $this->identity->kleenGuardCatalogCardUrls($product),
        )));
        foreach ($urls as $url) {
            $hit = $this->hitIfOurCard($url, $product);
            if ($hit !== null) {
                return [$hit];
            }
        }

        return [];
    }

    /**
     * @return array{url: string, title: string, snippet: string}|null
     */
    private function hitIfOurCard(string $url, Product $product): ?array
    {
        if ($this->identity->looksLikeNonProductCardUrl($url)) {
            return null;
        }
        $page = $this->reader->fetch($url);
        if ($page === null) {
            return null;
        }
        $text = $page['text'];
        $head = mb_strtolower(mb_substr($text, 0, 400));
        if (str_contains($head, 'product not found') || str_contains($head, 'nie znaleziono produktu')
            || ProductPageFetcher::looksLikeCompanyImprint($text)) {
            return null;
        }
        $title = $this->titleFrom($text, $url);
        $hay = $url.' '.$title.' '.$text;
        if (! $this->identity->hayMentionsProduct($hay, $product)
            || $this->identity->pageClaimsAnotherCode($url, $title, $product)) {
            return null;
        }

        return [
            'url' => $url,
            'title' => $title,
            'snippet' => mb_substr($text, 0, 400),
        ];
    }

    private function titleFrom(string $text, string $url): string
    {
        if (preg_match('/^#\s+(.+)$/m', $text, $m) === 1) {
            return trim($m[1]);
        }

        return $url;
    }
}
