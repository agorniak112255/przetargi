<?php

declare(strict_types=1);

namespace App\Services\Presta;

use App\Support\PpeAssortment;

/**
 * Odrzuca śmieci z cenników (Excel, kolumna A/B/C, model jako „kategoria”).
 */
final class ProductCategorySanitizer
{
    public const FAMILY_TOWEL = 'towel';

    public const FAMILY_CLEANING = 'cleaning';

    /** @var array<string, string> */
    public const FAMILY_LABELS = [
        PpeAssortment::FAMILY_GLOVES => 'Rękawice',
        PpeAssortment::FAMILY_FOOTWEAR => 'Obuwie',
        PpeAssortment::FAMILY_HEARING => 'Ochrona słuchu',
        PpeAssortment::FAMILY_EYES => 'Ochrona oczu',
        PpeAssortment::FAMILY_FACE => 'Ochrona twarzy',
        PpeAssortment::FAMILY_RESPIRATORY => 'Drogi oddechowe',
        PpeAssortment::FAMILY_FALL => 'Asekuracja',
        PpeAssortment::FAMILY_APPAREL => 'Odzież',
        PpeAssortment::FAMILY_HEAD => 'Ochrona głowy',
        PpeAssortment::FAMILY_KNEE => 'Ochrona kolan',
        self::FAMILY_TOWEL => 'Ręczniki',
        self::FAMILY_CLEANING => 'Środki czystości',
    ];

    public function __construct(
        private readonly PpeAssortment $ppe,
    ) {}

    public function isGarbage(?string $category): bool
    {
        $c = trim((string) $category);
        if ($c === '') {
            return true;
        }
        if (str_starts_with($c, '=') || str_starts_with($c, "'=")) {
            return true;
        }
        if (preg_match('/trad\s*\.\s*gammes/iu', $c) === 1) {
            return true;
        }
        if (preg_match('/![A-Z]+\d+\s*$/u', $c) === 1) {
            return true;
        }
        if (preg_match('/^[A-C]$/u', $c) === 1) {
            return true;
        }
        if (preg_match('/^cz[eę][sś][cć]\s*\d/iu', $c) === 1) {
            return true;
        }
        if (preg_match('/^(cena|cennik|netto pln|brutto|previous code|linia symbol|grupa wzor)/iu', $c) === 1) {
            return true;
        }
        if (preg_match('/^en\s*(iso\s*)?2034/i', $c) === 1) {
            return true;
        }
        if (mb_strlen($c) > 72) {
            return true;
        }
        if (preg_match('/\b(S[1-5]P?|ESD|SRC|GTX|BOA)\b/u', $c) === 1
            && preg_match('/\d{2}\s*-\s*\d{2}|\d{4,}/u', $c) === 1) {
            return true;
        }
        if (preg_match('/^(BLUE|GREEN|WHITE|YELLOW|ORANGE|RED|BLACK|GREY)(\/[A-Z]+)?$/iu', $c) === 1) {
            return true;
        }

        return false;
    }

    public function inferFamily(string $name, ?string $category = null): ?string
    {
        $fromName = $this->familyFromText($name);
        if ($fromName !== null) {
            return $fromName;
        }
        if ($category !== null && $category !== '' && ! $this->isGarbage($category)) {
            return $this->familyFromText($category);
        }

        return null;
    }

    public function inferLabel(string $name, ?string $category = null): ?string
    {
        $family = $this->inferFamily($name, $category);
        if ($family === null) {
            return null;
        }

        return self::FAMILY_LABELS[$family] ?? null;
    }

    public function imported(?string $category, string $name = ''): ?string
    {
        $category = trim((string) $category);
        if ($category !== '' && ! $this->isGarbage($category)) {
            return mb_substr($category, 0, 255);
        }
        $label = $this->inferLabel($name, $category !== '' ? $category : null);

        return $label;
    }

    public function familyFromText(string $text): ?string
    {
        $n = $this->normalize($text);
        if (preg_match('/\b(recznik|towel|r[eę]cznik)/u', $n) === 1) {
            return self::FAMILY_TOWEL;
        }
        if (preg_match('/\b(srodki\s+czystosci|chemia\s+gospod|deterg|czyszczac)/u', $n) === 1) {
            return self::FAMILY_CLEANING;
        }
        $expanded = $this->expandAliases($n);
        $family = $this->ppe->family($expanded);
        if ($family !== null) {
            return $family;
        }

        return $this->ppe->family($n);
    }

    private function expandAliases(string $normalized): string
    {
        $aliases = [
            'hearing' => 'ochrona sluchu',
            'respiratory' => 'drogi oddechowe polmaska',
            'eyeface' => 'okulary ochronne',
            'affiliated eye' => 'okulary ochronne',
            'fall protection' => 'asekuracja szelki',
            'gloves' => 'rekawice',
            'shoes' => 'obuwie',
            'footwear' => 'obuwie',
            'electrical safety' => 'odziez',
            'hand protection' => 'rekawice',
            'eye protection' => 'okulary ochronne',
            'face protection' => 'oslona twarzy',
            'head protection' => 'helm ochronny',
        ];
        foreach ($aliases as $from => $to) {
            if (str_contains($normalized, $from)) {
                $normalized .= ' '.$to;
            }
        }

        return $normalized;
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $map = [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
        ];

        return strtr($s, $map);
    }
}
