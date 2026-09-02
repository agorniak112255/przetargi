<?php

declare(strict_types=1);

namespace App\Services\Presta;

use App\Models\Product;

/**
 * Opis HTML do Presty — ten sam układ co karta produktu w przetargach.
 */
final class PrestaDescriptionHtml
{
    /** @var array<string, string> */
    private const KATEGORIA_LABELS = [
        'rekawice' => 'rękawice',
        'obuwie' => 'obuwie',
        'odziez' => 'odzież',
        'ochrona_glowy' => 'ochrona głowy',
        'ochrona_twarzy' => 'ochrona twarzy',
        'ochrona_oczu' => 'ochrona oczu',
        'ochrona_sluchu' => 'ochrona słuchu',
        'drogi_oddechowe' => 'drogi oddechowe',
        'asekuracja' => 'asekuracja',
        'ochrona_kolan' => 'ochrona kolan',
        'inne' => 'inne',
    ];

    /** @var list<array{0: string, 1: string}> */
    private const LISTS = [
        ['Specyfikacja', 'specs'],
        ['Cechy', 'features'],
        ['Materiały', 'materials'],
        ['Normy', 'norms'],
        ['Certyfikaty', 'certificates'],
        ['Zastosowanie', 'use_cases'],
    ];

    public function fromProduct(Product $product): string
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $rawDescription = (string) ($product->description ?? '');
        $prose = $this->prose($rawDescription);
        $attrPairs = $this->attributePairs(is_array($payload['attributes'] ?? null) ? $payload['attributes'] : []);
        $sections = [];
        foreach (self::LISTS as [$title, $key]) {
            $items = $this->stringList($payload[$key] ?? null);
            if ($items !== []) {
                $sections[] = [$title, $items];
            }
        }
        $sources = $this->stringList($payload['source_urls'] ?? null);

        if ($attrPairs === [] && $sections === [] && $sources === []) {
            return $this->fallbackHtml($rawDescription);
        }

        $html = '';
        if ($prose !== '') {
            $html .= $this->proseHtml($prose);
        }
        if ($attrPairs !== []) {
            $html .= $this->attributesBox($attrPairs);
        }
        foreach ($sections as [$title, $items]) {
            $html .= $this->listSection($title, $items);
        }
        if ($sources !== []) {
            $html .= $this->sourcesHtml($sources);
        }

        return $html !== '' ? $html : '<p></p>';
    }

    public function prose(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^(.*)\n\n(?:Specyfikacja|Cechy|Materiały|Normy|Certyfikaty|Zastosowanie)\s*:/us', $text, $m) === 1) {
            $text = trim($m[1]);
        }
        if (! $this->looksLikeHtml($text)) {
            $text = preg_replace('/([^\n])\s+(\d{1,2})\)\s+/u', "$1\n$2) ", $text) ?? $text;
        }

        return trim($text);
    }

    private function fallbackHtml(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '<p></p>';
        }
        if ($this->looksLikeHtml($text)) {
            return $text;
        }
        $escaped = htmlspecialchars($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return '<p align="justify" style="margin:0 0 12px;text-align:justify">'.nl2br($escaped, false).'</p>';
    }

    private function proseHtml(string $text): string
    {
        if ($this->looksLikeHtml($text)) {
            return $text;
        }
        $escaped = htmlspecialchars($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return '<p align="justify" style="margin:0 0 12px;text-align:justify">'.nl2br($escaped, false).'</p>';
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return list<array{0: string, 1: string}>
     */
    private function attributePairs(array $attrs): array
    {
        $pairs = [];
        foreach (
            [
                ['Kategoria', $this->kategoriaLabel($attrs['kategoria_bhp'] ?? null)],
                ['Materiał', $this->nullableString($attrs['material'] ?? null)],
                ['Klasa', $this->nullableString($attrs['klasa_ochrony'] ?? null)],
                ['EN 388', $this->nullableString($attrs['poziomy_en388'] ?? null)],
                ['Rozmiar', $this->nullableString($attrs['rozmiar'] ?? null)],
                ['Kod', $this->nullableString($attrs['kod_producenta'] ?? null)],
            ] as [$label, $value]
        ) {
            if ($value !== null) {
                $pairs[] = [$label, $value];
            }
        }
        $normy = $this->stringList($attrs['normy_en'] ?? null);
        if ($normy !== []) {
            $pairs[] = ['Normy', implode(', ', $normy)];
        }

        return $pairs;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    private function attributesBox(array $pairs): string
    {
        $rows = array_chunk($pairs, 3);
        $body = '<tr><td colspan="3" style="font-weight:700;font-size:13px;padding:0 0 8px">Atrybuty BHP</td></tr>';
        foreach ($rows as $row) {
            $body .= '<tr>';
            for ($i = 0; $i < 3; $i++) {
                if (! isset($row[$i])) {
                    $body .= '<td width="33%" valign="top"></td>';

                    continue;
                }
                [$label, $value] = $row[$i];
                $body .= '<td width="33%" valign="top" style="padding:4px 8px 4px 0">'
                    .'<span style="color:#94a3b8;font-size:11px">'.$this->e($label).'</span><br>'
                    .'<span style="font-weight:600;font-size:13px;color:#1e293b">'.$this->e($value).'</span>'
                    .'</td>';
            }
            $body .= '</tr>';
        }

        return '<table width="100%" cellpadding="10" cellspacing="0" bgcolor="#f8fafc" border="0" '
            .'style="background:#f8fafc;border:1px solid #e2e8f0;margin:16px 0">'
            .$body
            .'</table>';
    }

    /**
     * @param  list<string>  $items
     */
    private function listSection(string $title, array $items): string
    {
        $lis = '';
        foreach ($items as $item) {
            $lis .= '<li style="margin:0 0 4px;font-size:13px">'.$this->e($item).'</li>';
        }

        return '<p style="font-weight:700;font-size:13px;margin:16px 0 6px">'.$this->e($title).'</p>'
            .'<ul style="margin:0 0 12px 20px;padding:0">'.$lis.'</ul>';
    }

    /**
     * @param  list<string>  $urls
     */
    private function sourcesHtml(array $urls): string
    {
        $links = [];
        foreach (array_slice($urls, 0, 3) as $url) {
            if (! preg_match('#^https?://#i', $url)) {
                continue;
            }
            $href = $this->e($url);
            $label = $this->e(mb_substr(preg_replace('#^https?://#i', '', $url) ?? $url, 0, 40));
            $links[] = '<a href="'.$href.'" target="_blank" rel="noreferrer">'.$label.'</a>';
        }
        if ($links === []) {
            return '';
        }

        return '<p style="font-size:11px;color:#94a3b8;margin:12px 0 0">Źródła: '.implode(' · ', $links).'</p>';
    }

    private function kategoriaLabel(mixed $value): ?string
    {
        $raw = $this->nullableString($value);
        if ($raw === null) {
            return null;
        }
        $key = mb_strtolower($raw);

        return self::KATEGORIA_LABELS[$key] ?? $raw;
    }

    private function looksLikeHtml(string $text): bool
    {
        return str_contains($text, '<p') || str_contains($text, '<div') || str_contains($text, '<ul');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $v = trim($value);

        return $v === '' ? null : $v;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }
}
