<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Porównuje cechy liczbowe z SIWZ z treścią karty katalogowej. SIWZ pisze „250gr”,
 * karta „250 g/m²”, a producent „250 gsm” — dosłowne LIKE nigdy tego nie połączy.
 */
final class ProductFeatureMatch
{
    /** Gramatury poza tym zakresem to zwykle cena, kod albo rozmiar. */
    private const GRAMMAGE_MIN = 40;

    private const GRAMMAGE_MAX = 2000;

    public function normalize(string $text): string
    {
        $t = mb_strtolower($text);
        $t = strtr($t, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            '²' => '2', '³' => '3', '–' => '-', '—' => '-',
        ]);

        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /**
     * Gramatury w gramach na metr kwadratowy, niezależnie od zapisu jednostki.
     *
     * @return list<int>
     */
    public function grammages(string $text): array
    {
        $t = $this->normalize($text);
        $out = [];

        // 250 g/m2, 250g/m 2, 250 gsm, 250gr, 250 g
        if (preg_match_all('/(\d{2,4})\s*(?:g\s*\/\s*m\s*2|gsm|gr\b|g\b)/u', $t, $m) === false) {
            $m = [1 => []];
        }
        foreach ($m[1] ?? [] as $value) {
            $out[] = (int) $value;
        }

        // „gramatura 250”, „gramaturze: 250” — jednostka bywa pominięta
        if (preg_match_all('/gramatur\w*\D{0,12}(\d{2,4})/u', $t, $m2) !== false) {
            foreach ($m2[1] ?? [] as $value) {
                $out[] = (int) $value;
            }
        }

        $out = array_values(array_unique(array_filter(
            $out,
            static fn (int $v): bool => $v >= self::GRAMMAGE_MIN && $v <= self::GRAMMAGE_MAX
        )));
        sort($out);

        return $out;
    }

    /**
     * Numery norm EN bez wariantów zapisu: „EN ISO 20471”, „EN20471”, „en 20471:2013”.
     *
     * @return list<string>
     */
    public function norms(string $text): array
    {
        $t = $this->normalize($text);
        if (preg_match_all('/\ben\s*(?:iso\s*)?(\d{3,5})/u', $t, $m) === false) {
            return [];
        }

        $out = array_values(array_unique($m[1] ?? []));
        sort($out);

        return $out;
    }

    /**
     * Ile cech z wymagania potwierdza karta produktu.
     *
     * @return array{grammage: int, norms: int}
     */
    public function overlap(string $requirement, string $productText): array
    {
        $reqGrammages = $this->grammages($requirement);
        $reqNorms = $this->norms($requirement);
        if ($reqGrammages === [] && $reqNorms === []) {
            return ['grammage' => 0, 'norms' => 0];
        }

        return [
            'grammage' => count(array_intersect($reqGrammages, $this->grammages($productText))),
            'norms' => count(array_intersect($reqNorms, $this->norms($productText))),
        ];
    }
}
