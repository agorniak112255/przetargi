<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Opis produktu jako zwykły tekst — bez HTML/CSS, który rozsadza kartę i Prestę.
 */
final class ProductDescriptionText
{
    public static function plain(?string $text): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^(.*)\n\n(?:Specyfikacja|Cechy|Materiały|Normy|Certyfikaty|Zastosowanie)\s*:/us', $text, $m) === 1) {
            $text = trim($m[1]);
        }
        $text = preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', $text) ?? $text;
        $text = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(?:p|div|li|h[1-6]|tr|section|article|table)>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\{[^{}]{0,240}\}/u', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = preg_replace('/([^\n])\s+(\d{1,2})\)\s+/u', "$1\n$2) ", $text) ?? $text;
        $text = preg_replace('/(\S{72})(?=\S)/u', '$1 ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return list<string>
     */
    public static function paragraphs(string $plain): array
    {
        $plain = trim($plain);
        if ($plain === '') {
            return [];
        }
        $parts = preg_split('/\n\s*\n/u', $plain) ?: [$plain];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
        if (count($parts) >= 2) {
            return $parts;
        }
        $one = $parts[0] ?? $plain;
        if (mb_strlen($one) < 280 || substr_count($one, '. ') < 3) {
            return [$one];
        }
        $sentences = preg_split('/(?<=[.!?])\s+/u', $one) ?: [$one];
        $out = [];
        $buf = '';
        $n = 0;
        foreach ($sentences as $sentence) {
            $sentence = trim((string) $sentence);
            if ($sentence === '') {
                continue;
            }
            $buf = $buf === '' ? $sentence : $buf.' '.$sentence;
            $n++;
            if ($n >= 3) {
                $out[] = $buf;
                $buf = '';
                $n = 0;
            }
        }
        if ($buf !== '') {
            $out[] = $buf;
        }

        return $out !== [] ? $out : [$one];
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    public static function dropDuplicatedListItems(array $items, string $prose): array
    {
        $normProse = self::norm($prose);
        if ($normProse === '') {
            return array_values(array_filter($items, static fn (string $item): bool => trim($item) !== ''));
        }
        $out = [];
        foreach ($items as $item) {
            $t = trim($item);
            if ($t === '') {
                continue;
            }
            $norm = self::norm($t);
            if (mb_strlen($norm) >= 40 && str_contains($normProse, $norm)) {
                continue;
            }
            $out[] = $t;
        }

        return $out;
    }

    private static function norm(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
