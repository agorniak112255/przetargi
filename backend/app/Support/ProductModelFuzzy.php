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
        'dla', 'na', 'do', 'od', 'ze', 'za', 'po', 'we', 'przy', 'plus',
        'dluga', 'krotka', 'zapinana', 'zatrzaski', 'zamek', 'stojka',
        'kieszen', 'kieszenie', 'tasma', 'tasmy', 'elektryk', 'elektrykow',
        'elektryka', 'elektryczne', 'ubranie', 'komplet', 'zestaw',
        'wodoochronna', 'wodoochronny', 'przeciwdeszczowa', 'przeciwdeszczowy',
        'polar', 'polaru', 'polarowa', 'polarowy', 'damska', 'damski', 'meska', 'meski',
        'granatowy', 'granatowa', 'granat', 'czapka', 'czepek', 'czepki', 'kominiarka', 'kominiarki',
        'gramatura', 'gramatury', 'gram', 'gramy', 'gramow', 'gsm',
        'rozm', 'gumowe', 'gumowa', 'gumowy', 'damskie', 'meskie', 'antyelektrostatyczne',
        'antyelektrostatyczna', 'prod', 'jednorazowy', 'jednorazowa', 'jednorazowe',
        'opakowanie', 'opakowaniu',
    ];

    public function hasNamedModel(string $requirement): bool
    {
        return $this->needles($requirement) !== [];
    }

    /**
     * Igły do wyszukiwania po modelu (PERSPECTA + 010 → perspecta010) — wspólne dla listy i AI.
     *
     * @return list<string>
     */
    public function catalogModelNeedles(string $requirement): array
    {
        $needles = $this->needles($requirement);
        $out = [];
        foreach ($needles as $needle) {
            if ($this->isJunkCatalogModelNeedle($needle)) {
                continue;
            }
            if (mb_strlen($needle) >= 4 || $this->isMixedModelCode($needle)) {
                $out[] = $needle;
            }
        }
        usort($out, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return array_values(array_unique($out));
    }

    public function usesModelAnchoredCatalogSearch(string $requirement): bool
    {
        return $this->catalogModelNeedles($requirement) !== [];
    }

    /**
     * Para słowo + kod (PERSPECTA, 010) — dopasowanie w nazwie ze spacją między tokenami.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function catalogModelWordDigitPairs(string $requirement): array
    {
        $text = $this->stripNorms($requirement);
        $tokens = preg_split('/[\s,;:·•\/|+]+/u', $text) ?: [];
        $tokens = array_values(array_filter($tokens, static fn (string $t): bool => $t !== ''));
        $pairs = [];
        $count = count($tokens);
        for ($i = 0; $i < $count - 1; $i++) {
            $aWord = $this->lettersOnly($tokens[$i]);
            $num = $this->compact($tokens[$i + 1]);
            if (
                $aWord === ''
                || mb_strlen($aWord) < 3
                || $this->isStop($aWord)
                || $this->isSizeLabelWord($aWord)
                || ! ctype_digit($num)
                || $this->isSizeRangeDigits($num, $tokens[$i + 1] ?? '')
                || mb_strlen($num) < 3
                || mb_strlen($num) > 5
            ) {
                continue;
            }
            $pairs[] = [$aWord, $num];
        }

        return $pairs;
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
                if ($this->isShortHyphenModel($raw)) {
                    $compact = $this->compact($raw);
                    if ($compact !== '' && ! $this->isStop($compact) && ! $this->isJunkCatalogModelNeedle($compact)) {
                        $out[] = $compact;
                    }
                }
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
            if (isset($tokens[$i + 1])) {
                $aWord = $this->lettersOnly($tokens[$i]);
                $num = $this->compact($tokens[$i + 1]);
                if (
                    $aWord !== ''
                    && mb_strlen($aWord) >= 3
                    && ! $this->isStop($aWord)
                    && ! $this->isSizeLabelWord($aWord)
                    && $this->isNumberedModelToken($num)
                    && ! $this->isSizeRangeDigits($num, $tokens[$i + 1] ?? '')
                ) {
                    $this->pushNeedle($out, $aWord.$num);
                }
            }
            if (str_contains($tokens[$i], '-') || (isset($tokens[$i + 1]) && str_contains($tokens[$i + 1], '-'))) {
                continue;
            }
            $a = $this->lettersOnly($tokens[$i]);
            $nextCompact = isset($tokens[$i + 1]) ? $this->compact($tokens[$i + 1]) : '';
            if (
                $a !== ''
                && mb_strlen($a) >= 4
                && ! $this->isStop($a)
                && ! $this->isSizeLabelWord($a)
                && ! $this->isModelPairStop($a)
                && $this->isShortAlnumModel($nextCompact)
            ) {
                $this->pushNeedle($out, $a.$nextCompact);
            }
            $b = isset($tokens[$i + 1]) ? $this->lettersOnly($tokens[$i + 1]) : '';
            if ($a === '' || $b === '' || $this->isStop($a) || $this->isStop($b)) {
                continue;
            }
            if (mb_strlen($a) < 3 || mb_strlen($b) < 3 || (mb_strlen($a) + mb_strlen($b)) < 6) {
                continue;
            }
            $pair = $a.$b;
            $nextNum = $this->trailingModelNumber($tokens, $i + 2);
            $hasDigit = preg_match('/\d/', $tokens[$i].($tokens[$i + 1] ?? '')) === 1;
            if ($hasDigit) {
                $this->pushNeedle($out, $pair);
            }
            if ($nextNum !== null && ! $this->isSizeRangeDigits($nextNum, $tokens[$i + 2] ?? '')) {
                $this->pushNeedle($out, $pair.$nextNum);
            }
        }

        $knownBrands = $this->knownCatalogBrandTokens();
        for ($i = 0; $i < $count - 1; $i++) {
            $brand = $this->compact($tokens[$i]);
            if ($brand === '' || ! isset($knownBrands[$brand])) {
                continue;
            }
            $line = $this->lettersOnly($tokens[$i + 1]);
            if ($line === '' || mb_strlen($line) < 5 || $this->isStop($line) || $this->isSizeLabelWord($line)) {
                continue;
            }
            $after = isset($tokens[$i + 2]) ? $this->compact($tokens[$i + 2]) : '';
            if ($after !== '' && (
                $this->isNumberedModelToken($after)
                || $this->isShortAlnumModel($after)
                || $this->isMixedModelCode($after)
            )) {
                continue;
            }
            $this->pushNeedle($out, $line);
        }

        foreach ($tokens as $token) {
            if (! $this->isSiwxUpperModelToken($token, $requirement)) {
                continue;
            }
            $letters = $this->lettersOnly($token);
            if ($this->hasNumberedNeedleForPrefix($out, $letters)) {
                continue;
            }
            $this->pushNeedle($out, $letters);
        }

        foreach ($tokens as $token) {
            $c = $this->compact($token);
            if ($this->isMixedModelCode($c)) {
                $this->pushNeedle($out, $c);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Krótki kod katalogowy (P3E, H31, WFU255DG) — litera + cyfra, min. 3 znaki.
     *
     * @return list<string>
     */
    public function shortCodes(string $requirement): array
    {
        $out = [];
        foreach ($this->needles($requirement) as $needle) {
            if ($this->isMixedModelCode($needle)) {
                $out[] = $needle;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Znane marki z SIWZ (MSA, 3M, uvex) — nie rzeczowniki typu „ochronniki”.
     *
     * @return list<string>
     */
    public function catalogBrands(string $requirement): array
    {
        $known = $this->knownCatalogBrandTokens();
        if ($known === []) {
            return [];
        }
        $found = [];
        $text = $this->stripNorms($requirement);
        $tokens = preg_split('/[\s,;:·•\/|+]+/u', $text) ?: [];
        foreach ($tokens as $token) {
            $c = $this->compact($token);
            if ($c !== '' && isset($known[$c])) {
                $found[$c] = true;
            }
            foreach (preg_split('/[^a-z0-9]+/u', mb_strtolower($token)) ?: [] as $part) {
                $p = $this->compact($part);
                if ($p !== '' && isset($known[$p])) {
                    $found[$p] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * @param  list<string>  $brands
     */
    public function matchesCatalogBrand(Product $product, array $brands): bool
    {
        if ($brands === []) {
            return true;
        }
        $hay = $this->compact((string) $product->manufacturer.' '.(string) $product->name);
        foreach ($brands as $brand) {
            if ($brand !== '' && str_contains($hay, $brand)) {
                return true;
            }
        }

        return false;
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
        if ($c === '' || ctype_digit($c)) {
            return;
        }
        $len = mb_strlen($c);
        if ($len < 3) {
            return;
        }
        if ($len < 5 && ! $this->isMixedModelCode($c)) {
            return;
        }
        if ($this->isJunkCatalogModelNeedle($c) || $this->isStop($c)) {
            return;
        }
        $out[] = $c;
    }

    /** Peltor + X2 — nie „klasa S3” i nie „filtr A2”. */
    private function isModelPairStop(string $word): bool
    {
        return in_array($word, [
            'klasa', 'filtr', 'typ', 'kategoria', 'poziom', 'wersja', 'norma',
            'ochrona', 'ochrony', 'przeciwhalasowe', 'naglowne', 'nahelmowe',
        ], true);
    }

    /** 010 / 2047W — numer modelu, także z literą na końcu. */
    private function isNumberedModelToken(string $compact): bool
    {
        $len = mb_strlen($compact);
        if ($len < 3 || $len > 8) {
            return false;
        }

        return preg_match('/^\d{3,5}[a-z]{0,3}$/u', $compact) === 1;
    }

    /** X2 / X2A / H31 — za krótkie na isMixedModelCode, ale to kod przy nazwie serii. */
    private function isShortAlnumModel(string $compact): bool
    {
        $len = mb_strlen($compact);
        if ($len < 2 || $len > 6 || $this->isJunkCatalogModelNeedle($compact)) {
            return false;
        }

        return preg_match('/^[a-z]{1,3}\d[a-z0-9]{0,3}$/u', $compact) === 1;
    }

    /** URG-A / TX-12 — po sklejeniu 4 znaki, za krótkie na zwykły pushNeedle. */
    private function isShortHyphenModel(string $raw): bool
    {
        $fold = mb_strtolower(trim($raw));

        return preg_match('/^[a-z]{2,5}-[a-z0-9]{1,3}$/u', $fold) === 1;
    }

    /** P3E / H31P3E / WFU255DG — nie czysty wyraz i nie sama liczba. */
    private function isMixedModelCode(string $compact): bool
    {
        $len = mb_strlen($compact);
        if ($len < 3 || $len > 16 || ctype_digit($compact)) {
            return false;
        }
        if (preg_match('/[a-z]/', $compact) !== 1 || preg_match('/\d/', $compact) !== 1) {
            return false;
        }

        return ! $this->isStop($this->lettersOnly($compact));
    }

    /**
     * @param  list<string>  $tokens
     */
    private function trailingModelNumber(array $tokens, int $index): ?string
    {
        if (! isset($tokens[$index])) {
            return null;
        }
        $raw = $tokens[$index];
        if ($this->isSizeRangeToken($raw)) {
            return null;
        }
        $n = $this->compact($raw);
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
        if (preg_match('/^(.*[a-z])(\d{3,5})$/u', $needle, $m) === 1) {
            $digits = $m[2];
            $bounded = preg_match('/(?<![0-9])'.preg_quote($digits, '/').'(?![0-9])/u', $hay) === 1;
            if (! $bounded && ! str_contains($hay, $needle)) {
                return 99;
            }
        }
        if (preg_match('/[a-z]\d{1,2}$/u', $needle) === 1 && ! str_contains($hay, $needle)) {
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
        if (in_array($token, self::STOP, true)) {
            return true;
        }

        if (str_starts_with($token, 'gramatur') || str_starts_with($token, 'gramat')) {
            return true;
        }

        return false;
    }

    private function isSizeLabelWord(string $word): bool
    {
        return preg_match('/^rozm/i', $word) === 1 || str_starts_with($word, 'rozmiar');
    }

    private function isSizeRangeToken(string $raw): bool
    {
        $norm = preg_replace('/\s/u', '', $raw) ?? '';

        return preg_match('/^\d{2,3}-\d{2,3}$/', $norm) === 1;
    }

    private function isSizeRangeDigits(string $digits, string $rawToken): bool
    {
        if ($this->isSizeRangeToken($rawToken)) {
            return true;
        }
        if (mb_strlen($digits) === 4 && preg_match('/^(\d{2})(\d{2})$/', $digits, $m) === 1) {
            $a = (int) $m[1];
            $b = (int) $m[2];

            return $a >= 28 && $a <= 52 && $b >= 28 && $b <= 52 && $b >= $a && ($b - $a) <= 16;
        }

        return false;
    }

    private function isJunkCatalogModelNeedle(string $needle): bool
    {
        if (preg_match('/rozm|antyelektrostat|damsk|mesk|gumow|obuw|buty|czepek|jednorazow/u', $needle) === 1) {
            return true;
        }
        if (preg_match('/^(op|opak|szt|sztuk)\d+$/u', $needle) === 1) {
            return true;
        }
        if (preg_match('/^\d+(gr|g|gsm)$/u', $needle) === 1) {
            return true;
        }

        return $this->isSizeRangeDigits($needle, $needle);
    }

    /** PERSPECTA 010 → nie używaj samego „perspecta” (9000 / etui dostałyby 99%). */
    private function hasNumberedNeedleForPrefix(array $needles, string $prefix): bool
    {
        if ($prefix === '' || mb_strlen($prefix) < 3) {
            return false;
        }
        foreach ($needles as $needle) {
            if (preg_match('/^'.preg_quote($prefix, '/').'\d{3,5}[a-z]{0,3}$/u', $needle) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isSiwxUpperModelToken(string $token, string $rawRequirement): bool
    {
        $trim = trim($token);
        if ($trim === '' || preg_match('/\d/u', $trim) === 1) {
            return false;
        }
        $letters = $this->lettersOnly($trim);
        if (mb_strlen($letters) < 6 || $this->isStop($letters)) {
            return false;
        }
        if (isset($this->knownCatalogBrandTokens()[$letters])) {
            return false;
        }
        $upper = mb_strtoupper($letters, 'UTF-8');

        return preg_match('/\b'.preg_quote($upper, '/').'\b/u', $rawRequirement) === 1;
    }

    /**
     * @return array<string, true>
     */
    private function knownCatalogBrandTokens(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }
        $skip = ['safety', 'group', 'plus', 'auer', 'gloves', 'protection', 'the', 'and'];
        $out = [];
        foreach (array_keys((array) config('enrichment.manufacturer_domains', [])) as $key) {
            $c = $this->compact((string) $key);
            if ($c !== '' && mb_strlen($c) >= 2 && ! in_array($c, $skip, true)) {
                $out[$c] = true;
            }
            foreach (preg_split('/[^a-z0-9]+/u', mb_strtolower((string) $key)) ?: [] as $part) {
                $p = $this->compact($part);
                if ($p === '' || in_array($p, $skip, true)) {
                    continue;
                }
                if (mb_strlen($p) >= 3 || in_array($p, ['3m', 'msa', 'atg', 'kcl', 'gvs', 'pip'], true)) {
                    $out[$p] = true;
                }
            }
        }
        foreach (['portwest', 'tegera', 'ejendals', 'showa', 'jalas', 'kleenguard', 'cofra', 'coverguard'] as $extra) {
            $out[$extra] = true;
        }
        $cached = $out;

        return $cached;
    }
}
