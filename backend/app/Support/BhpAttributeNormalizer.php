<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;

/**
 * Mini-schemat atrybutów BHP (kanoniczne pola w enrichment_payload.attributes).
 */
final class BhpAttributeNormalizer
{
    /** @var list<string> */
    public const KATEGORIE = [
        'rekawice', 'obuwie', 'odziez',
        'ochrona_glowy', 'ochrona_twarzy', 'ochrona_oczu', 'ochrona_sluchu',
        'drogi_oddechowe', 'asekuracja', 'ochrona_kolan', 'inne',
    ];

    /**
     * @return array{
     *     kategoria_bhp: ?string,
     *     kod_producenta: ?string,
     *     material: ?string,
     *     materialy: list<string>,
     *     normy_en: list<string>,
     *     klasa_ochrony: ?string,
     *     rozmiar: ?string,
     *     poziomy_en388: ?string,
     *     typ_wyrobu: ?string,
     *     przeznaczenie: ?string,
     *     oznaczenia: list<string>,
     *     rodzina_materialu: ?string
     * }
     */
    public function empty(): array
    {
        return [
            'kategoria_bhp' => null,
            'kod_producenta' => null,
            'material' => null,
            'materialy' => [],
            'normy_en' => [],
            'klasa_ochrony' => null,
            'rozmiar' => null,
            'poziomy_en388' => null,
            'typ_wyrobu' => null,
            'przeznaczenie' => null,
            'oznaczenia' => [],
            'rodzina_materialu' => null,
        ];
    }

    /**
     * Atrybuty z produktu: zapisane w payload lub wyprowadzone z list enrichment.
     *
     * @return array{
     *     kategoria_bhp: ?string,
     *     kod_producenta: ?string,
     *     material: ?string,
     *     materialy: list<string>,
     *     normy_en: list<string>,
     *     klasa_ochrony: ?string,
     *     rozmiar: ?string,
     *     poziomy_en388: ?string,
     *     typ_wyrobu: ?string,
     *     przeznaczenie: ?string,
     *     oznaczenia: list<string>,
     *     rodzina_materialu: ?string
     * }
     */
    public function forProduct(Product $product): array
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $useCases = $this->stringList($payload['use_cases'] ?? null);
        $features = $this->stringList($payload['features'] ?? null);
        $haystack = trim(implode("\n", array_filter([
            (string) ($product->name ?? ''),
            (string) ($product->description ?? ''),
            (string) ($product->category ?? ''),
            (string) ($product->norms ?? ''),
            ...$useCases,
            ...$features,
        ])));

        return $this->normalize(
            is_array($payload['attributes'] ?? null) ? $payload['attributes'] : null,
            [
                'materials' => array_values(array_unique(array_merge(
                    $this->stringList($payload['materials'] ?? null),
                    $this->detectMaterialsFromText($haystack),
                ))),
                'norms' => array_values(array_unique(array_merge(
                    $this->stringList($payload['norms'] ?? null),
                    $this->detectNormsFromText($haystack),
                ))),
                'specs' => $this->stringList($payload['specs'] ?? null),
                'certificates' => $this->stringList($payload['certificates'] ?? null),
                'use_cases' => $useCases,
                'category' => (string) ($product->category ?? ''),
                'sku' => (string) ($product->sku ?? ''),
                'name' => (string) ($product->name ?? ''),
                'description' => (string) ($product->description ?? ''),
                'norms_column' => (string) ($product->norms ?? ''),
            ]
        );
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @param  array{
     *     materials?: list<string>,
     *     norms?: list<string>,
     *     specs?: list<string>,
     *     certificates?: list<string>,
     *     category?: string,
     *     sku?: string,
     *     name?: string,
     *     description?: string,
     *     norms_column?: string
     * }  $context
     * @return array{
     *     kategoria_bhp: ?string,
     *     kod_producenta: ?string,
     *     material: ?string,
     *     materialy: list<string>,
     *     normy_en: list<string>,
     *     klasa_ochrony: ?string,
     *     rozmiar: ?string,
     *     poziomy_en388: ?string,
     *     typ_wyrobu: ?string,
     *     przeznaczenie: ?string,
     *     oznaczenia: list<string>,
     *     rodzina_materialu: ?string
     * }
     */
    public function normalize(?array $raw, array $context = []): array
    {
        $out = $this->empty();
        $raw = $raw ?? [];

        $identity = ($context['category'] ?? '').' '
            .($context['name'] ?? '').' '
            .($context['sku'] ?? '');
        $katText = $identity.' '.($context['description'] ?? '');

        $out['kategoria_bhp'] = $this->normalizeKategoria(
            $this->nullableString($raw['kategoria_bhp'] ?? null)
            ?? $this->detectKategoria($katText)
        );

        $out['kod_producenta'] = $this->nullableString($raw['kod_producenta'] ?? null)
            ?? $this->nullableString($context['sku'] ?? null);

        $materials = array_values(array_unique(array_merge(
            $this->stringList($raw['materialy'] ?? null),
            $this->stringList($context['materials'] ?? null),
        )));
        $primary = $this->nullableString($raw['material'] ?? null);
        if ($primary !== null && ! in_array($primary, $materials, true)) {
            array_unshift($materials, $primary);
        }
        if ($primary === null && $materials !== []) {
            $primary = $materials[0];
        }
        $out['material'] = $primary;
        $out['materialy'] = $materials;

        $normy = array_values(array_unique(array_merge(
            $this->stringList($raw['normy_en'] ?? null),
            $this->stringList($context['norms'] ?? null),
            $this->splitNormsColumn($context['norms_column'] ?? ''),
        )));
        $out['normy_en'] = $normy;

        $descBlob = implode(' ', array_merge(
            $normy,
            $this->stringList($context['specs'] ?? null),
            $this->stringList($context['certificates'] ?? null),
            $this->stringList($context['use_cases'] ?? null),
            [$context['name'] ?? '', $context['description'] ?? '', $context['norms_column'] ?? ''],
        ));

        $parsed = $this->parseKlasaAndMarkings(
            $this->nullableString($raw['klasa_ochrony'] ?? null),
            $descBlob
        );
        $out['klasa_ochrony'] = $parsed['klasa'];
        $out['oznaczenia'] = $parsed['oznaczenia'];

        $out['rozmiar'] = $this->nullableString($raw['rozmiar'] ?? null)
            ?? $this->detectRozmiar(
                implode(' ', $this->stringList($context['specs'] ?? null)).' '.($context['description'] ?? '')
            );

        $out['poziomy_en388'] = $this->nullableString($raw['poziomy_en388'] ?? null)
            ?? $this->detectEn388($descBlob);

        $assortment = new PpeAssortment;
        $typeBlob = $identity.' '.$descBlob;
        $family = $assortment->familyFromKategoria($out['kategoria_bhp']);
        $out['typ_wyrobu'] = $this->nullableString($raw['typ_wyrobu'] ?? null)
            ?? $assortment->articleTypePreferIdentity($identity, $typeBlob, $family);
        $out['przeznaczenie'] = $this->nullableString($raw['przeznaczenie'] ?? null)
            ?? $assortment->purpose($typeBlob);
        $out['rodzina_materialu'] = $this->materialFamily($primary, $materials, $typeBlob);

        return $out;
    }

    /** @return list<string> */
    private function detectMaterialsFromText(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }
        $t = $this->normalizeText($text);
        $found = [];
        $map = [
            'nitryl' => 'nitryl',
            'nitrile' => 'nitryl',
            'nbr' => 'nitryl',
            'lateks' => 'lateks',
            'latex' => 'lateks',
            'nylon' => 'nylon',
            'spandex' => 'spandex',
            'hppe' => 'HPPE',
            'poliuretan' => 'PU',
            '\bpu\b' => 'PU',
            'skora' => 'skóra',
            'leather' => 'skóra',
            'bawelna' => 'bawełna',
            'cotton' => 'bawełna',
            'neopren' => 'neopren',
            'pvc' => 'PVC',
        ];
        foreach ($map as $needle => $label) {
            $pattern = str_starts_with($needle, '\\') ? '/'.$needle.'/u' : '/\b'.preg_quote($needle, '/').'\w*/u';
            if (preg_match($pattern, $t) === 1) {
                $found[] = $label;
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    private function detectNormsFromText(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }
        if (preg_match_all('/\bEN(?:\s*ISO)?\s*\d{3,5}(?::\s*\d{4})?(?:\s*\+\s*A\d+)?\b/iu', $text, $m) < 1) {
            return [];
        }
        $out = [];
        foreach ($m[0] as $raw) {
            $norm = preg_replace('/\s+/', ' ', trim($raw));
            if (is_string($norm) && $norm !== '') {
                $out[] = $norm;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Płaski tekst do haystack / embedding.
     *
     * @param  array<string, mixed>  $attrs
     */
    public function toSearchText(array $attrs): string
    {
        $parts = [
            $attrs['kategoria_bhp'] ?? null,
            $attrs['kod_producenta'] ?? null,
            $attrs['material'] ?? null,
            $attrs['klasa_ochrony'] ?? null,
            $attrs['typ_wyrobu'] ?? null,
            $attrs['przeznaczenie'] ?? null,
            $attrs['rozmiar'] ?? null,
            $attrs['poziomy_en388'] ?? null,
        ];
        if (is_array($attrs['oznaczenia'] ?? null)) {
            $parts = array_merge($parts, $attrs['oznaczenia']);
        }
        if (is_array($attrs['materialy'] ?? null)) {
            $parts = array_merge($parts, $attrs['materialy']);
        }
        if (is_array($attrs['normy_en'] ?? null)) {
            $parts = array_merge($parts, $attrs['normy_en']);
        }

        return trim(implode(' ', array_filter(
            array_map(static fn ($v) => is_string($v) ? trim($v) : '', $parts),
            static fn (string $v): bool => $v !== ''
        )));
    }

    private function normalizeKategoria(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = mb_strtolower(trim($value));
        $map = [
            'rekawice' => 'rekawice',
            'rękawice' => 'rekawice',
            'gloves' => 'rekawice',
            'obuwie' => 'obuwie',
            'buty' => 'obuwie',
            'footwear' => 'obuwie',
            'odziez' => 'odziez',
            'odzież' => 'odziez',
            'apparel' => 'odziez',
            'ochrona_glowy' => 'ochrona_glowy',
            'kask' => 'ochrona_glowy',
            'helmet' => 'ochrona_glowy',
            'ochrona_twarzy' => 'ochrona_twarzy',
            'oslona twarzy' => 'ochrona_twarzy',
            'osłona twarzy' => 'ochrona_twarzy',
            'face' => 'ochrona_twarzy',
            'ochrona_oczu' => 'ochrona_oczu',
            'gogle' => 'ochrona_oczu',
            'okulary' => 'ochrona_oczu',
            'ochrona_sluchu' => 'ochrona_sluchu',
            'nauszniki' => 'ochrona_sluchu',
            'drogi_oddechowe' => 'drogi_oddechowe',
            'polmaska' => 'drogi_oddechowe',
            'półmaska' => 'drogi_oddechowe',
            'asekuracja' => 'asekuracja',
            'szelki' => 'asekuracja',
            'ochrona_kolan' => 'ochrona_kolan',
            'inne' => 'inne',
        ];

        return $map[$v] ?? (in_array($v, self::KATEGORIE, true) ? $v : null);
    }

    private function detectKategoria(string $text): ?string
    {
        return (new PpeAssortment)->kategoria($text);
    }

    /**
     * @return array{klasa: ?string, oznaczenia: list<string>}
     */
    private function parseKlasaAndMarkings(?string $rawKlasa, string $blob): array
    {
        $hay = trim(($rawKlasa ?? '').' '.$blob);
        $oznaczenia = $this->extractMarkings($hay);
        $klasa = $this->extractFfpClass($rawKlasa ?? '')
            ?? $this->extractFootwearClass($rawKlasa ?? '')
            ?? $this->detectKlasa($hay);

        return ['klasa' => $klasa, 'oznaczenia' => $oznaczenia];
    }

    /** @return list<string> */
    private function extractMarkings(string $text): array
    {
        if (preg_match_all('/\b(SRA|SRB|SRC|HRO|WR|CI|HI|FO|AN|NR)\b/u', $text, $m) < 1) {
            return [];
        }

        $out = [];
        foreach ($m[1] as $tag) {
            $out[] = mb_strtoupper((string) $tag);
        }

        return array_values(array_unique($out));
    }

    private function extractFootwearClass(string $text): ?string
    {
        if (preg_match('/\b(S7|S5|S4|S3|S2|S1P|S1|SB|OB)\b/u', $text, $m) === 1) {
            return mb_strtoupper($m[1]);
        }

        return null;
    }

    private function extractFfpClass(string $text): ?string
    {
        if (preg_match('/\bFFP\s*[-]?([123])\b/iu', $text, $m) === 1) {
            return 'FFP'.$m[1];
        }

        return null;
    }

    private function detectKlasa(string $text): ?string
    {
        $norm = $this->normalizeText($text);
        $respiratory = preg_match(
            '/\b(polmask|ffp|respirator|filtrujac|przeciwpyl|drog[iy]\s+oddech)\w*/u',
            $norm
        ) === 1;
        $ffp = $this->extractFfpClass($text);
        if ($ffp !== null && $respiratory) {
            return $ffp;
        }

        $footwearClass = $this->extractFootwearClass($text);
        if ($footwearClass !== null) {
            $footwear = preg_match(
                '/\b(trzewik|polbut|sandal|obuwie|buty|footwear|podeszw|podnosek|kalosz|purofort)\w*/u',
                $norm
            ) === 1;
            if ($footwear && ! $respiratory) {
                return $footwearClass;
            }
        }
        if (preg_match('/\bkat(?:egoria)?\.?\s*(I{1,3}|[123])\b/iu', $text, $m) === 1) {
            return 'kat. '.$m[1];
        }
        if (preg_match('/\bPPE\s*kat(?:egoria)?\.?\s*(I{1,3}|[123])\b/iu', $text, $m) === 1) {
            return 'PPE kat. '.$m[1];
        }

        return null;
    }

    /**
     * @param  list<string>  $materials
     */
    private function materialFamily(?string $primary, array $materials, string $text): ?string
    {
        $blob = $this->normalizeText(implode(' ', array_filter([$primary, ...$materials, $text])));
        // kalosz / Purofort zanim „PU w podeszwie” skórzanego trzewika
        if (preg_match('/\b(purofort|kalosz|wellington|gumowc|gumiak)\w*/u', $blob) === 1) {
            return 'guma';
        }
        if (preg_match('/\b(skora|leather|welur|licow|nubuk)\w*/u', $blob) === 1) {
            return 'skora';
        }
        if (preg_match('/\b(guma|rubber|pvc|eva|tpe)\w*/u', $blob) === 1) {
            return 'guma';
        }
        if (preg_match('/\b(nitryl|nitrile|nbr)\w*/u', $blob) === 1) {
            return 'nitryl';
        }
        if (preg_match('/\b(lateks|latex)\w*/u', $blob) === 1) {
            return 'lateks';
        }
        if (preg_match('/\b(hppe|dyneema)\w*/u', $blob) === 1) {
            return 'cut';
        }
        if (preg_match('/\b(wloknin|meltblown|polipropylen|polypropylen)\w*/u', $blob) === 1) {
            return 'wloknina';
        }
        if (preg_match('/\bpoliuretan\w*|\bpu\b/u', $blob) === 1) {
            return 'pu';
        }
        if (preg_match('/\b(bawelna|cotton|nylon|tekstyl)\w*/u', $blob) === 1) {
            return 'tkanina';
        }

        return null;
    }

    private function detectRozmiar(string $text): ?string
    {
        if (preg_match('/\b(?:rozmiar|size|sizes?)\s*[:=]?\s*([0-9]{1,2}(?:\s*[-–\/]\s*[0-9]{1,2})?|[XSML]{1,3})\b/iu', $text, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    private function detectEn388(string $text): ?string
    {
        // EN 388:2016 + A1:2018 - 4131A / EN 388 4X42C
        $patterns = [
            '/EN\s*388(?::\d{4})?(?:\s*\+\s*A\d+(?::\d+)?)?\s*[-–]\s*([0-9X]{3,5}[A-F]?)\b/iu',
            '/EN\s*388:\d{4}\s+([0-9X]{3,5}[A-F]?)\b/iu',
            '/EN\s*388\s+([0-9X]{3,5}[A-F]?)\b/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                return mb_strtoupper($m[1]);
            }
        }

        return null;
    }

    /** @return list<string> */
    private function splitNormsColumn(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $p): string => trim($p),
            preg_split('/[,;|]/u', $value) ?: []
        )));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $v = trim($value);

        return $v === '' ? null : $v;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn ($v): bool => is_string($v) && trim($v) !== ''
        ));
    }

    private function normalizeText(string $text): string
    {
        $t = mb_strtolower($text);
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

        return strtr($t, $map);
    }
}
