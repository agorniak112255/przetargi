<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;

/**
 * Literówki w modelu SIWZ (TEPM-ICE → TEMP-ICE) vs nazwa/SKU cennika.
 */
final class ProductModelFuzzy
{
    private const STOP = [
        'rekawice', 'rekawica', 'ochronne', 'ochronna', 'ochronny', 'robocze', 'robocza',
        'produkt', 'art', 'kat', 'para', 'par', 'szt', 'sztuk', 'the', 'and', 'for',
        'with', 'bez', 'oraz', 'typ', 'model', 'kolor', 'rozmiar', 'kurtka', 'bluza',
        'spodnie', 'odziez', 'kamizelka', 'fartuch', 'kitel', 'zimowe', 'zimowa',
        'polmaska', 'kask', 'buty', 'obuwie', 'en', 'iso', 'ce', 'ppe', 'dawniej',
        'oslona', 'twarzy', 'przylbica', 'siatkowa', 'siatkowy', 'odblaskowa', 'odblaskowy',
        'zolta', 'nadrukiem', 'okulary', 'gogle', 'nauszniki', 'szelki', 'kalesony',
        'kombinezon', 'trzewiki', 'kominiarka', 'helm',
    ];

    public function hasNamedModel(string $requirement): bool
    {
        return $this->needles($requirement) !== [];
    }

    /**
     * @return list<string>
     */
    public function needles(string $requirement): array
    {
        $text = $this->stripNorms($requirement);
        $out = [];

        if (preg_match_all('/\b[a-z]{2,14}(?:-[a-z0-9]{1,12}){1,4}\b/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$raw, $offset]) {
                $this->pushNeedle($out, $raw);
                $after = ltrim(substr($text, $offset + strlen($raw), 16));
                if (preg_match('/^(\d{3,5})\b/', $after, $nm) === 1) {
                    $this->pushNeedle($out, $this->compact($raw).$nm[1]);
                }
            }
        }

        $tokens = preg_split('/[\s,;:·•\/|+]+/u', $text) ?: [];
        $tokens = array_values(array_filter($tokens, static fn (string $t): bool => $t !== ''));
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (str_contains($tokens[$i], '-') || (isset($tokens[$i + 1]) && str_contains($tokens[$i + 1], '-'))) {
                continue;
            }
            $a = $this->lettersOnly($tokens[$i]);
            $b = isset($tokens[$i + 1]) ? $this->lettersOnly($tokens[$i + 1]) : '';
            if ($a === '' || $b === '' || $this->isStop($a) || $this->isStop($b)) {
                continue;
            }
            if (mb_strlen($a) < 3 || mb_strlen($b) < 2 || (mb_strlen($a) + mb_strlen($b)) < 6) {
                continue;
            }
            $pair = $a.$b;
            $this->pushNeedle($out, $pair);
            $nextNum = $this->trailingModelNumber($tokens, $i + 2);
            if ($nextNum !== null) {
                $this->pushNeedle($out, $pair.$nextNum);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Marka z SIWZ — bez tokenu modelu (tepmice).
     *
     * @return list<string>
     */
    public function manufacturerHints(string $requirement): array
    {
        $needles = $this->needles($requirement);
        $out = [];
        foreach ($this->brandHints($requirement) as $brand) {
            foreach ($needles as $needle) {
                if ($brand === $needle || (mb_strlen($brand) >= 5 && str_contains($needle, $brand))) {
                    continue 2;
                }
            }
            $out[] = $brand;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function hyphenLetterParts(string $requirement): array
    {
        $text = $this->stripNorms($requirement);
        $out = [];
        if (preg_match_all('/\b[a-z]{2,14}(?:-[a-z0-9]{1,12}){1,4}\b/u', $text, $m)) {
            foreach ($m[0] as $raw) {
                foreach (explode('-', $raw) as $part) {
                    $part = $this->compact($part);
                    if (mb_strlen($part) >= 3 && ! $this->isStop($part) && ! ctype_digit($part)) {
                        $out[] = $part;
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    public function modelNumbers(string $requirement): array
    {
        $out = [];
        foreach ($this->needles($requirement) as $needle) {
            if (preg_match('/(\d{3,5})$/', $needle, $m) === 1) {
                $out[] = $m[1];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    public function brandHints(string $requirement): array
    {
        $text = $this->stripNorms($requirement);
        $tokens = preg_split('/[\s,;:·•\/|+]+/u', $text) ?: [];
        $out = [];
        foreach ($tokens as $token) {
            $t = $this->lettersOnly($token);
            if (mb_strlen($t) < 3 || mb_strlen($t) > 16 || $this->isStop($t)) {
                continue;
            }
            $out[] = $t;
        }

        return array_values(array_unique($out));
    }

    public function score(string $requirement, Product $product): int
    {
        $needles = $this->needles($requirement);
        if ($needles === []) {
            return 0;
        }

        $hays = array_values(array_filter([
            $this->compact((string) $product->name),
            $this->compact((string) $product->sku),
        ], static fn (string $h): bool => $h !== ''));
        if ($hays === []) {
            return 0;
        }

        $best = 99;
        $bestLen = 0;
        foreach ($needles as $needle) {
            $allowed = $this->maxDistance(mb_strlen($needle));
            foreach ($hays as $hay) {
                $dist = $this->windowDistance($needle, $hay);
                if ($dist <= $allowed && ($dist < $best || ($dist === $best && mb_strlen($needle) > $bestLen))) {
                    $best = $dist;
                    $bestLen = mb_strlen($needle);
                }
            }
        }

        if ($best > 2) {
            return 0;
        }

        $score = match ($best) {
            0 => 94,
            1 => 90,
            default => 86,
        };

        if ($this->brandAgrees($requirement, $product)) {
            $score = min(99, $score + 6);
        }

        return $score;
    }

    public function matches(string $requirement, Product $product): bool
    {
        return $this->score($requirement, $product) >= 80;
    }

    private function brandAgrees(string $requirement, Product $product): bool
    {
        $manuf = $this->compact((string) $product->manufacturer);
        if ($manuf === '' || mb_strlen($manuf) < 3) {
            return false;
        }
        foreach ($this->brandHints($requirement) as $brand) {
            if (str_contains($manuf, $brand) || str_contains($brand, $manuf)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $out
     */
    private function pushNeedle(array &$out, string $raw): void
    {
        $c = $this->compact($raw);
        if ($c === '' || mb_strlen($c) < 5 || ctype_digit($c)) {
            return;
        }
        $out[] = $c;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function trailingModelNumber(array $tokens, int $index): ?string
    {
        if (! isset($tokens[$index])) {
            return null;
        }
        $n = $this->compact($tokens[$index]);
        if ($n === '' || ! ctype_digit($n) || mb_strlen($n) < 3 || mb_strlen($n) > 5) {
            return null;
        }

        return $n;
    }

    private function windowDistance(string $needle, string $hay): int
    {
        if ($needle === '' || $hay === '') {
            return 99;
        }
        if ($hay === $needle || str_contains($hay, $needle) || str_starts_with($hay, $needle)) {
            return 0;
        }

        $nLen = mb_strlen($needle);
        $hLen = mb_strlen($hay);
        $best = 99;
        $min = max(4, $nLen - 2);
        $max = $nLen + 2;
        for ($i = 0; $i <= max(0, $hLen - $min); $i++) {
            for ($w = $min; $w <= $max && ($i + $w) <= $hLen; $w++) {
                $window = mb_substr($hay, $i, $w);
                if (function_exists('levenshtein') && strlen($needle) < 255 && strlen($window) < 255) {
                    $best = min($best, levenshtein($needle, $window));
                }
                if ($best === 0) {
                    return 0;
                }
            }
        }

        return $best;
    }

    private function maxDistance(int $len): int
    {
        if ($len < 5) {
            return 0;
        }
        if ($len < 7) {
            return 1;
        }

        return 2;
    }

    private function stripNorms(string $text): string
    {
        $t = mb_strtolower($text);
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];
        $t = strtr($t, $map);
        $t = preg_replace('/\ben(?:\s*iso)?\s*\d+(?:\s+\d+)*/u', ' ', $t) ?? $t;
        $t = preg_replace('/\biso\s*\d+/u', ' ', $t) ?? $t;

        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    private function compact(string $s): string
    {
        $s = mb_strtolower($s);
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

        return preg_replace('/[^a-z0-9]/', '', strtr($s, $map)) ?? '';
    }

    private function lettersOnly(string $s): string
    {
        $c = $this->compact($s);

        return preg_replace('/[0-9]/', '', $c) ?? '';
    }

    private function isStop(string $token): bool
    {
        return in_array($token, self::STOP, true);
    }
}
