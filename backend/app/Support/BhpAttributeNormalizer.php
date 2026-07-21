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
    public const KATEGORIE = ['rekawice', 'obuwie', 'odziez', 'ochrona_glowy', 'inne'];

    /**
     * @return array{
     *     kategoria_bhp: ?string,
     *     kod_producenta: ?string,
     *     material: ?string,
     *     materialy: list<string>,
     *     normy_en: list<string>,
     *     klasa_ochrony: ?string,
     *     rozmiar: ?string,
     *     poziomy_en388: ?string
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
     *     poziomy_en388: ?string
     * }
     */
    public function forProduct(Product $product): array
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];

        return $this->normalize(
            is_array($payload['attributes'] ?? null) ? $payload['attributes'] : null,
            [
                'materials' => $this->stringList($payload['materials'] ?? null),
                'norms' => $this->stringList($payload['norms'] ?? null),
                'specs' => $this->stringList($payload['specs'] ?? null),
                'certificates' => $this->stringList($payload['certificates'] ?? null),
                'category' => (string) ($product->category ?? ''),
                'sku' => (string) ($product->sku ?? ''),
                'name' => (string) ($product->name ?? ''),
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
     *     poziomy_en388: ?string
     * }
     */
    public function normalize(?array $raw, array $context = []): array
    {
        $out = $this->empty();
        $raw = $raw ?? [];

        $out['kategoria_bhp'] = $this->normalizeKategoria(
            $this->nullableString($raw['kategoria_bhp'] ?? null)
            ?? $this->detectKategoria(
                ($context['category'] ?? '').' '.($context['name'] ?? '').' '.($context['sku'] ?? '')
            )
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

        $out['klasa_ochrony'] = $this->nullableString($raw['klasa_ochrony'] ?? null)
            ?? $this->detectKlasa(
                implode(' ', array_merge(
                    $normy,
                    $this->stringList($context['specs'] ?? null),
                    $this->stringList($context['certificates'] ?? null),
                    [$context['name'] ?? ''],
                ))
            );

        $out['rozmiar'] = $this->nullableString($raw['rozmiar'] ?? null)
            ?? $this->detectRozmiar(implode(' ', $this->stringList($context['specs'] ?? null)));

        $out['poziomy_en388'] = $this->nullableString($raw['poziomy_en388'] ?? null)
            ?? $this->detectEn388(implode(' ', $normy).' '.implode(' ', $this->stringList($context['specs'] ?? null)));

        return $out;
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
            $attrs['rozmiar'] ?? null,
            $attrs['poziomy_en388'] ?? null,
        ];
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
            'inne' => 'inne',
        ];

        return $map[$v] ?? (in_array($v, self::KATEGORIE, true) ? $v : null);
    }

    private function detectKategoria(string $text): ?string
    {
        $t = $this->normalizeText($text);
        if (preg_match('/\b(rekawic|glove|handschuh)\w*/u', $t) === 1) {
            return 'rekawice';
        }
        if (preg_match('/\b(trzewik|polbut|sandal|obuwie|buty|footwear|podeszw|podnosek|\bs3\b|\bs1p?\b)\b/u', $t) === 1) {
            return 'obuwie';
        }
        if (preg_match('/\b(odziez|kurtk|spodn|kombinezon|kamizelk|softshell)\w*/u', $t) === 1) {
            return 'odziez';
        }
        if (preg_match('/\b(kask|helmet|nausznik|gogle|okulary)\w*/u', $t) === 1) {
            return 'ochrona_glowy';
        }

        return null;
    }

    private function detectKlasa(string $text): ?string
    {
        $t = $text;
        if (preg_match('/\b(S1P?|S2|S3|SB|OB|SRC|HRO)\b/u', $t, $m) === 1) {
            return mb_strtoupper($m[1]);
        }
        if (preg_match('/\bkat(?:egoria)?\.?\s*(I{1,3}|[123])\b/iu', $t, $m) === 1) {
            return 'kat. '.$m[1];
        }
        if (preg_match('/\bPPE\s*kat(?:egoria)?\.?\s*(I{1,3}|[123])\b/iu', $t, $m) === 1) {
            return 'PPE kat. '.$m[1];
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
        // EN 388:2016 4X42C — pomijamy rok normy, bierzemy poziomy (cyfry/X + opcjonalna litera)
        if (preg_match('/EN\s*388(?::\s*\d{4})?\s*([0-9X]{3,5}[A-F]?)\b/iu', $text, $m) === 1) {
            return mb_strtoupper($m[1]);
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
