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

    /**
     * @param  array<string, true>  $official
     */
    public function isOfficial(string $category, array $official): bool
    {
        $key = mb_strtolower(trim($category));

        return $key !== '' && isset($official[$key]);
    }

    /**
     * @param  iterable<int, \App\Models\PrestaCategory>  $tree
     * @return array<string, true>
     */
    public function officialKeys($tree): array
    {
        $keys = [];
        foreach ($tree as $cat) {
            $name = mb_strtolower(trim((string) $cat->name));
            $path = mb_strtolower(trim((string) ($cat->path !== '' ? $cat->path : $cat->name)));
            if ($name !== '') {
                $keys[$name] = true;
            }
            if ($path !== '') {
                $keys[$path] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, true>  $official
     */
    public function isGarbage(?string $category, array $official = []): bool
    {
        $c = trim((string) $category);
        if ($c === '' || $c === '-') {
            return true;
        }
        if ($this->isOfficial($c, $official)) {
            return false;
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
        if (preg_match('/^cat\.?\s*i{1,3}\b/iu', $c) === 1) {
            return true;
        }
        if (preg_match('/^[A-Z]{1,2}\d{2}(\s|-)/u', $c) === 1) {
            return true;
        }
        if (preg_match('/^\d+[A-Za-zĄĆĘŁŃÓŚŹŻąćęłńóśźż]/u', $c) === 1) {
            return true;
        }
        if (str_contains($c, '@')) {
            return true;
        }
        if (preg_match('/^(accessori|accessories|colours|essential|premium|unique|distributor|grupa|model|produkt|para|szt|op)\b/iu', $c) === 1) {
            return true;
        }
        if (preg_match('/^(art\.?\s*no|dodatkowe oplat|ceny wyrobow|tabela cen)/iu', $c) === 1) {
            return true;
        }
        if (mb_strlen($c) > 72 && ! str_contains($c, ' / ')) {
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
        $n = trim((string) preg_replace('/^\d+[\.\s]*/u', '', $n));
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
            'felpa' => 'bluza odziez',
            'giacca' => 'kurtka odziez',
            'gilet' => 'kamizelka odziez',
            'pantalon' => 'spodnie odziez',
            'bermuda' => 'spodnie odziez',
            'jeans' => 'spodnie odziez',
            'coverall' => 'kombinezon odziez',
            'clothing' => 'odziez',
            'workwear' => 'odziez',
            'rainwear' => 'odziez',
            'softshell' => 'odziez kurtka',
            't-shirt' => 'odziez',
            'polo' => 'odziez',
            'camicia' => 'odziez',
            'spectacles' => 'okulary ochronne',
            'occhiali' => 'okulary ochronne',
            'ear plug' => 'ochrona sluchu',
            'ear muff' => 'ochrona sluchu',
            'fall arrest' => 'asekuracja szelki',
            'harness' => 'asekuracja szelki',
            'polmask' => 'drogi oddechowe polmaska',
            'gasnic' => 'odziez',
            'skarpet' => 'obuwie',
            'socks' => 'obuwie',
            'rekawic' => 'rekawice',
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
