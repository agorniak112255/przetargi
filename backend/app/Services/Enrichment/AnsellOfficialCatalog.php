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
        $urls = array_slice($this->identity->ansellOfficialProductUrls($product), 0, 6);
        foreach ($urls as $url) {
            $page = $this->reader->fetch($url);
            if ($page === null) {
                continue;
            }
            $text = $page['text'];
            $head = mb_strtolower(mb_substr($text, 0, 400));
            if (str_contains($head, 'product not found') || str_contains($head, 'nie znaleziono produktu')) {
                continue;
            }
            $title = $this->titleFrom($text, $url);
            $hay = $url.' '.$title.' '.$text;
            if (! $this->identity->hayMentionsProduct($hay, $product)
                || $this->identity->pageClaimsAnotherCode($url, $title, $product)) {
                continue;
            }

            return [[
                'url' => $url,
                'title' => $title,
                'snippet' => mb_substr($text, 0, 400),
            ]];
        }

        return [];
    }

    private function titleFrom(string $text, string $url): string
    {
        if (preg_match('/^#\s+(.+)$/m', $text, $m) === 1) {
            return trim($m[1]);
        }

        return $url;
    }
}
