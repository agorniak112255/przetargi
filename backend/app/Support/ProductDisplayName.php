<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;

/**
 * PL etykieta produktu gdy name z cennika jest angielskim skrótem (jak frontend productLabel).
 */
final class ProductDisplayName
{
    public static function for(?Product $product, int $maxLen = 72): string
    {
        if ($product === null) {
            return '—';
        }

        $name = trim((string) ($product->name ?? ''));
        $desc = trim((string) ($product->description ?? ''));

        if (! self::isWeak($name, $desc)) {
            return $name !== '' ? $name : (trim((string) ($product->manufacturer ?? '')) ?: '—');
        }

        return self::fromDescription($desc, $maxLen) ?: ($name !== '' ? $name : '—');
    }

    private static function isWeak(string $name, string $desc): bool
    {
        if ($name === '') {
            return true;
        }
        if (preg_match(
            '/^(uncoated|coated|liner|glove|gloves|nitrile|latex|foam|pu|pvc|nylon|hppe|cut|ultra|dry|wet|winter|summer|nitrile foam|pu coated|latex coated)$/iu',
            $name
        ) === 1) {
            return true;
        }
        if (mb_strlen($desc) < 20) {
            return false;
        }
        $hasPlDesc = preg_match('/[ąćęłńóśźż]/iu', $desc) === 1;
        $hasPlName = preg_match('/[ąćęłńóśźż]/iu', $name) === 1;
        if ($hasPlName || ! $hasPlDesc) {
            return false;
        }
        if (preg_match('/^[a-z][a-z\-]*$/iu', $name) === 1 && mb_strlen($name) <= 20) {
            return true;
        }

        return preg_match('/\b(uncoated|coated|liner|gloves?|nitrile|latex|foam|palm|micro-?foam|hppe|kw)\b/iu', $name) === 1
            && preg_match('/^[a-z0-9][a-z0-9\s\-\/_.%°]*$/iu', $name) === 1
            && mb_strlen($name) <= 48;
    }

    private static function fromDescription(string $desc, int $maxLen): string
    {
        if (preg_match('/(?:^|\n)\s*-\s*Typ:\s*(.+)/iu', $desc, $m) === 1) {
            $t = preg_replace('/\s+/u', ' ', trim($m[1])) ?? '';

            return self::clip($t, $maxLen);
        }
        $firstLine = trim(explode("\n", $desc)[0] ?? '');
        if (preg_match('/^(.+?)\s+to\s+/iu', $firstLine, $m) === 1) {
            return self::clip(trim($m[1]), $maxLen);
        }
        if (preg_match('/^(Rękawic\w+[^.]*?)(?:\s+z\s+)/iu', $firstLine, $m) === 1) {
            $t = preg_replace('/\s*\(SKU\s+[^)]+\)\s*/iu', ' ', $m[1]) ?? $m[1];

            return self::clip(trim(preg_replace('/\s+/u', ' ', $t) ?? $t), $maxLen);
        }
        $sentence = preg_split('/(?<=[.!?])\s+/u', $firstLine)[0] ?? $firstLine;

        return self::clip(trim(preg_replace('/\s+/u', ' ', $sentence) ?? $sentence), $maxLen);
    }

    private static function clip(string $value, int $maxLen): string
    {
        if (mb_strlen($value) <= $maxLen) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $maxLen - 1)).'…';
    }
}
