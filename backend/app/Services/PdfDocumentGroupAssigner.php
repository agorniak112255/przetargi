<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AssortmentGroup;

/**
 * Grupy z nagłówków sekcji w PDF (np. „zagrożenia mechaniczne”), nie ze starych cenników.
 */
final class PdfDocumentGroupAssigner
{
    /**
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    public function assign(array $products, string $text, string $manufacturer): array
    {
        $headings = $this->headingPositions($text);
        foreach ($products as $i => $product) {
            $current = trim((string) ($product['category'] ?? ''));
            if ($current !== '') {
                $products[$i]['category'] = $this->canonicalName($current, $manufacturer);

                continue;
            }
            $pos = $this->productOffset($text, $product);
            if ($pos === null) {
                continue;
            }
            $heading = $this->headingBefore($headings, $pos);
            if ($heading !== null) {
                $products[$i]['category'] = $this->canonicalName($heading, $manufacturer);
            }
        }

        return $products;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function productOffset(string $text, array $product): ?int
    {
        $sku = trim((string) ($product['sku'] ?? ''));
        if ($sku !== '' && mb_strlen($sku) >= 3) {
            $pos = mb_stripos($text, $sku);
            if ($pos !== false) {
                return $pos;
            }
        }
        $name = trim((string) ($product['name'] ?? ''));
        if (mb_strlen($name) >= 5) {
            $pos = mb_stripos($text, $name);
            if ($pos !== false) {
                return $pos;
            }
        }

        return null;
    }

    /**
     * @return list<array{pos: int, name: string}>
     */
    private function headingPositions(string $text): array
    {
        $out = [];
        $offset = 0;
        foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
            $trim = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
            if ($this->isHeadingLine($trim)) {
                $out[] = ['pos' => $offset, 'name' => $this->cleanHeading($trim)];
            }
            foreach ($this->inlineHeadings($trim) as $name) {
                $out[] = ['pos' => $offset, 'name' => $name];
            }
            $offset += mb_strlen($line) + 1;
        }

        return $out;
    }

    /**
     * @param  list<array{pos: int, name: string}>  $headings
     */
    private function headingBefore(array $headings, int $pos): ?string
    {
        $best = null;
        foreach ($headings as $heading) {
            if ($heading['pos'] <= $pos) {
                $best = $heading['name'];
            }
        }

        return $best;
    }

    private function isHeadingLine(string $line): bool
    {
        if ($line === '' || mb_strlen($line) < 4 || mb_strlen($line) > 70) {
            return false;
        }
        if (preg_match('/\d+[.,]\d{2}/', $line) === 1) {
            return false;
        }
        if (preg_match('/^(strona|page|cennik|uvex safety|numer|nazwa produktu|ilość|ilosc|cena|www\.|ul\.|tel\.|fax)/ui', $line) === 1) {
            return false;
        }
        if (preg_match('/^\d+$/u', $line) === 1) {
            return false;
        }

        return preg_match('/\p{L}{3,}/u', $line) === 1;
    }

    /**
     * @return list<string>
     */
    private function inlineHeadings(string $line): array
    {
        if (mb_strlen($line) < 40 || preg_match('/\d+[.,]\d{2}/', $line) !== 1) {
            return [];
        }
        if (preg_match_all(
            '/\b((?:ochrona|zagro[żz]e[nń]ia|r[ęe]kawice|odzie[żz]|obuwie|he[lł]m(?:y)?)\s+[\p{L}\/-]{3,40})/ui',
            $line,
            $m
        ) < 1) {
            return [];
        }

        $out = [];
        foreach ($m[1] ?? [] as $name) {
            $out[] = $this->cleanHeading($name);
        }

        return $out;
    }

    private function cleanHeading(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $name = preg_replace('/\s*strona\s+\d+.*/ui', '', $name) ?? $name;

        return mb_substr(trim($name), 0, 80);
    }

    public function canonicalName(string $heading, string $manufacturer): string
    {
        $heading = $this->cleanHeading($heading);
        if ($heading === '' || $manufacturer === '') {
            return $heading;
        }
        $want = $this->fold($heading);
        if ($want === '') {
            return $heading;
        }
        $best = $heading;
        $bestScore = 0.0;
        foreach (AssortmentGroup::query()->where('manufacturer', $manufacturer)->where('is_global', false)->get() as $group) {
            $have = $this->fold($group->name);
            if ($have === '') {
                continue;
            }
            if ($have === $want) {
                return $group->name;
            }
            similar_text($want, $have, $pct);
            if ($pct >= 78 && $pct > $bestScore) {
                $bestScore = $pct;
                $best = $group->name;
            }
        }

        return $best;
    }

    private function fold(string $value): string
    {
        $value = mb_strtolower($value);
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

        return preg_replace('/[^a-z0-9]+/', '', strtr($value, $map)) ?? '';
    }
}
