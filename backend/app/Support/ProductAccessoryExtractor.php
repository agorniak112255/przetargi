<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Blok „Warianty produktu i akcesoria” / JSON-LD — nie „klienci kupili też”.
 */
final class ProductAccessoryExtractor
{
    /**
     * @return list<array{sku: string, ean: string, name: string, manufacturer: string}>
     */
    public function fromHtml(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        return $this->unique(array_merge(
            $this->fromJsonLd($html),
            $this->fromAccessoryBlocks($html),
        ));
    }

    /**
     * @return list<array{sku: string, ean: string, name: string, manufacturer: string}>
     */
    public function fromText(string $text): array
    {
        $text = trim($text);
        if ($text === '' || ! $this->looksLikeAccessoryHeading($text)) {
            return [];
        }

        return $this->unique($this->fromPlainList($text));
    }

    /**
     * @return list<array{sku: string, ean: string, name: string, manufacturer: string}>
     */
    private function fromJsonLd(string $html): array
    {
        $out = [];
        if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks) < 1) {
            return [];
        }
        foreach ($blocks[1] as $raw) {
            $json = json_decode(html_entity_decode(trim((string) $raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (! is_array($json)) {
                continue;
            }
            foreach ($this->walkJsonLd($json) as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $node
     * @return list<array{sku: string, ean: string, name: string, manufacturer: string}>
     */
    private function walkJsonLd(array $node): array
    {
        $out = [];
        $keys = ['isAccessoryOrSparePartFor', 'isRelatedTo', 'isConsumableFor', 'accessory'];
        if ($this->isList($node)) {
            foreach ($node as $child) {
                if (is_array($child)) {
                    $out = array_merge($out, $this->walkJsonLd($child));
                }
            }

            return $out;
        }
        foreach ($keys as $key) {
            if (! isset($node[$key])) {
                continue;
            }
            $items = $node[$key];
            if (! is_array($items)) {
                continue;
            }
            $rows = $this->isList($items) ? $items : [$items];
            foreach ($rows as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $out[] = $this->candidate('', '', trim($item), '');
                }
                if (is_array($item)) {
                    $mapped = $this->fromJsonProduct($item);
                    if ($mapped !== null) {
                        $out[] = $mapped;
                    }
                }
            }
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $out = array_merge($out, $this->walkJsonLd($child));
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{sku: string, ean: string, name: string, manufacturer: string}|null
     */
    private function fromJsonProduct(array $item): ?array
    {
        $sku = trim((string) ($item['sku'] ?? $item['mpn'] ?? $item['productID'] ?? ''));
        $ean = preg_replace('/\D+/', '', (string) ($item['gtin13'] ?? $item['gtin'] ?? $item['ean'] ?? '')) ?? '';
        $name = trim((string) ($item['name'] ?? ''));
        $brand = $item['brand'] ?? '';
        $manufacturer = is_array($brand)
            ? trim((string) ($brand['name'] ?? ''))
            : trim((string) $brand);
        if ($sku === '' && $ean === '' && $name === '') {
            return null;
        }

        return $this->candidate($sku, $ean, $name, $manufacturer);
    }

    /**
     * @return list<array{sku: string, ean: string, name: string, manufacturer: string}>
     */
    private function fromAccessoryBlocks(string $html): array
    {
        $chunks = [];
        if (preg_match_all(
            '#<(?:section|div|aside|article)[^>]*(?:class|id)=["\'][^"\']*'
            .'(?:product-accessories|js-product-accessories|block-accessories|'
            .'accessories-block|accessory-products|warianty[^"\']*akcesor)'
            .'[^"\']*["\'][^>]*>(.*?)</(?:section|div|aside|article)>#is',
            $html,
            $m
        )) {
            foreach ($m[1] as $chunk) {
                $chunks[] = (string) $chunk;
            }
        }
        $headingAt = $this->accessoryHeadingOffset($html);
        if ($headingAt !== null) {
            $tail = mb_substr($html, $headingAt, 40000);
            $cut = preg_split('#<(?:h[12]|footer|section)#i', $tail, 2) ?: [$tail];
            $chunks[] = (string) $cut[0];
        }

        $out = [];
        foreach ($chunks as $chunk) {
            if ($this->looksLikeGenericRelated($chunk) && ! $this->looksLikeAccessoryHeading($chunk)) {
                continue;
            }
            $out = array_merge($out, $this->fromMiniatures($chunk), $this->fromPlainList(strip_tags($chunk)));
        }

        return $out;
    }

    /**
     * @return list<array{sku: string, ean: string, name: string, manufacturer: string}>
     */
    private function fromMiniatures(string $html): array
    {
        $out = [];
        $parts = preg_split(
            '#<(?:article|li|div)[^>]*(?:class|id)=["\'][^"\']*(?:product-miniature|js-product-miniature|product-item|accessory)[^"\']*["\']#i',
            $html
        ) ?: [];
        if (count($parts) < 2) {
            $parts = [$html];
        }
        foreach ($parts as $part) {
            $name = '';
            if (preg_match('#itemprop=["\']name["\'][^>]*>([^<]+)#i', $part, $m)
                || preg_match('#<(?:h[2-4]|a)[^>]*class=["\'][^"\']*(?:product-title|product-name)[^"\']*["\'][^>]*>([^<]+)#i', $part, $m)
                || preg_match('#<a[^>]+href=["\'][^"\']+\d+-[^"\']+\.html["\'][^>]*>([^<]{8,180})#i', $part, $m)) {
                $name = $this->cleanName((string) $m[1]);
            }
            $sku = '';
            if (preg_match('#itemprop=["\']sku["\'][^>]*content=["\']([^"\']+)#i', $part, $m)
                || preg_match('#(?:data-reference|data-product-reference)=["\']([^"\']+)#i', $part, $m)
                || preg_match('#(?:class=["\'][^"\']*product-reference[^"\']*["\'][^>]*>)([^<]+)#i', $part, $m)) {
                $sku = trim(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            $ean = '';
            if (preg_match('#itemprop=["\']gtin(?:13)?["\'][^>]*content=["\'](\d{8,14})#i', $part, $m)) {
                $ean = (string) $m[1];
            }
            $brand = '';
            if (preg_match('#itemprop=["\']brand["\'][^>]*>([^<]+)#i', $part, $m)
                || preg_match('#class=["\'][^"\']*product-manufacturer[^"\']*["\'][^>]*>([^<]+)#i', $part, $m)) {
                $brand = trim(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            if ($sku === '' && $name === '') {
                continue;
            }
            $out[] = $this->candidate($sku, $ean, $name, $brand);
        }

        return $out;
    }

    /**
     * @return list<array{sku: string, ean: string, name: string, manufacturer: string}>
     */
    private function fromPlainList(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim(html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (mb_strlen($line) < 8 || mb_strlen($line) > 180) {
                continue;
            }
            if ($this->looksLikeAccessoryHeading($line) || $this->looksLikeGenericRelated($line)) {
                continue;
            }
            $sku = '';
            if (preg_match('/\b(?:ref|sku|kod|art\.?)\s*[:.]?\s*([A-Z0-9][A-Z0-9._\/-]{2,32})\b/iu', $line, $m)) {
                $sku = (string) $m[1];
            }
            $name = $this->cleanName($line);
            if ($sku === '' && ! preg_match('/[A-Za-zĄĆĘŁŃÓŚŹŻąćęłńóśźż]{4,}/u', $name)) {
                continue;
            }
            if ($sku === '' && $name === '') {
                continue;
            }
            $out[] = $this->candidate($sku, '', $name, '');
        }

        return $out;
    }

    private function accessoryHeadingOffset(string $html): ?int
    {
        $low = mb_strtolower($html);
        foreach ([
            'warianty produktu i akcesoria',
            'akcesoria do produktu',
            'product accessories',
            'compatible accessories',
            'compatible products',
            'pasuje do',
        ] as $needle) {
            $pos = mb_strpos($low, $needle);
            if ($pos !== false) {
                return $pos + mb_strlen($needle);
            }
        }

        return null;
    }

    private function looksLikeAccessoryHeading(string $text): bool
    {
        $low = mb_strtolower($text);

        return str_contains($low, 'akcesor')
            || str_contains($low, 'warianty produktu')
            || str_contains($low, 'compatible accessor')
            || str_contains($low, 'pasuje do')
            || str_contains($low, 'product accessor');
    }

    private function looksLikeGenericRelated(string $html): bool
    {
        $low = mb_strtolower($html);
        foreach ([
            'klienci kupili', 'customers also', 'you may also', 'podobne produkty',
            'polecane produkty', 'często kupowane', 'frequently bought',
        ] as $needle) {
            if (str_contains($low, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{sku: string, ean: string, name: string, manufacturer: string}
     */
    private function candidate(string $sku, string $ean, string $name, string $manufacturer): array
    {
        return [
            'sku' => mb_substr(trim($sku), 0, 128),
            'ean' => mb_substr(preg_replace('/\D+/', '', $ean) ?? '', 0, 32),
            'name' => mb_substr($this->cleanName($name), 0, 255),
            'manufacturer' => mb_substr(trim($manufacturer), 0, 128),
        ];
    }

    private function cleanName(string $name): string
    {
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = trim(preg_replace('/\s+/', ' ', strip_tags($name)) ?? '');

        return $name;
    }

    /**
     * @param  list<array{sku: string, ean: string, name: string, manufacturer: string}>  $rows
     * @return list<array{sku: string, ean: string, name: string, manufacturer: string}>
     */
    private function unique(array $rows): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            if ($row['sku'] === '' && $row['ean'] === '' && $row['name'] === '') {
                continue;
            }
            $key = mb_strtolower($row['sku'].'|'.$row['ean'].'|'.$row['name']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isList(array $value): bool
    {
        return $value === [] || array_is_list($value);
    }
}
