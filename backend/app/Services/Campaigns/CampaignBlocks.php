<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignAsset;
use Illuminate\Validation\ValidationException;

/**
 * Bloki treści maila kampanii i szablonu: normalizacja, walidacja, „Standard SUPON” i bloki starej kampanii.
 * Blok = tablica z `type` i polami; kolejność w tablicy = kolejność w mailu. Pola zawsze obecne (puste = '' / null),
 * inne klucze usuwane. Podpis nadawcy, linia wypisu i „Ceny netto ważne…” nie są blokami — dokłada je renderer.
 */
final class CampaignBlocks
{
    public const MAX_BLOCKS = 20;

    /** Kolory maila (pierwszy domyślny — zieleń SUPON); na każdym biały tekst przycisku. */
    public const BRAND_COLORS = ['#0b7d6a', '#1f5fa8', '#b3261e', '#c25e00', '#5b3fa0', '#2f3a40'];

    public const DEFAULT_COLOR = '#0b7d6a';

    /**
     * Układy usunięte 01.10.2026 → najbliższy zachowany. Zapisane kampanie i szablony zostają w bazie bez zmian;
     * zamiana przy odczycie (upgrade, legacy), zapisie (validate) i w rendererze.
     */
    public const REPLACED_LAYOUTS = ['hero' => 'grid3', 'grid4' => 'grid3', 'sale' => 'grid2', 'big' => 'list_desc'];

    /** Pola bloku: nazwa → [rodzaj, limit znaków]; rodzaj: text | line (bez CR/LF) | uuid | url | layout. */
    private const FIELDS = [
        'header' => ['logo' => ['uuid', 0]],
        'heading' => ['text' => ['line', 200]],
        'text' => ['text' => ['text', 5000]],
        'image' => ['asset' => ['uuid', 0], 'alt' => ['line', 200], 'url' => ['url', 500]],
        'products' => ['layout' => ['layout', 0]],
        'button' => ['label' => ['line', 60], 'url' => ['url', 500]],
        'footer' => ['text' => ['text', 1000]],
    ];

    private const LABELS = [
        'header' => 'Logo',
        'heading' => 'Nagłówek',
        'text' => 'Tekst',
        'image' => 'Grafika',
        'products' => 'Produkty',
        'button' => 'Przycisk',
        'footer' => 'Stopka',
    ];

    /**
     * Znormalizowane bloki albo ValidationException z kluczami `blocks` / `blocks.N.pole` (komunikaty po polsku).
     * Zawsze: struktura, typy, limity, dokładnie jeden blok produktów, najwyżej jedno logo i jedna stopka, istniejące
     * obrazki. $strict (start i planowanie wysyłki): adresy tylko https:// (przycisk także mailto:), przycisk wymaga
     * napisu i adresu, grafika — obrazka. Projekt kampanii i szablon zapisują bez $strict: puste pola i niedokończone
     * adresy dozwolone (autozapis w trakcie pisania), z adresu usuwane są tylko znaki sterujące — renderer i tak
     * wstawia wyłącznie poprawne linki (validUrl).
     *
     * @return list<array<string, mixed>>
     */
    public static function validate(mixed $blocks, bool $strict): array
    {
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            throw ValidationException::withMessages(['blocks' => ['Treść maila ma zły format.']]);
        }
        if (count($blocks) > self::MAX_BLOCKS) {
            throw ValidationException::withMessages(['blocks' => ['Mail może mieć najwyżej '.self::MAX_BLOCKS.' elementów.']]);
        }

        $errors = [];
        $out = [];
        $counts = [];
        foreach ($blocks as $i => $block) {
            $n = $i + 1;
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if (! is_string($type) || ! isset(self::FIELDS[$type])) {
                $errors["blocks.{$i}"][] = "Element nr {$n}: nieznany rodzaj elementu.";

                continue;
            }
            $counts[$type] = ($counts[$type] ?? 0) + 1;
            $prefix = self::LABELS[$type]." (element nr {$n})";
            $normalized = ['type' => $type];
            foreach (self::FIELDS[$type] as $field => [$kind, $limit]) {
                $key = "blocks.{$i}.{$field}";
                $value = $block[$field] ?? null;
                if ($kind === 'layout') {
                    $value ??= 'grid3';
                    $value = is_string($value) ? self::layout($value) : $value;
                    if (! is_string($value) || ! in_array($value, Campaign::LAYOUTS, true)) {
                        $errors[$key][] = $prefix.': wybierz układ produktów.';
                    }
                    $normalized[$field] = is_string($value) ? $value : 'grid3';

                    continue;
                }
                if ($value !== null && ! is_string($value)) {
                    $errors[$key][] = $prefix.': zły format pola.';
                    $normalized[$field] = $kind === 'uuid' ? null : '';

                    continue;
                }
                $value = trim((string) $value);
                if ($kind === 'url' && ! $strict) {
                    // zapis w trakcie pisania („htt”) — adres sprawdza dopiero wysyłka, renderer pomija niepoprawne linki
                    $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $value));
                }
                if ($kind === 'uuid') {
                    if ($value !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) !== 1) {
                        $errors[$key][] = $prefix.': obrazek nie istnieje — wgraj go ponownie.';
                    }
                    $normalized[$field] = $value !== '' ? $value : null;

                    continue;
                }
                if (mb_strlen($value) > $limit) {
                    $errors[$key][] = $prefix.": najwyżej {$limit} znaków.";
                } elseif ($kind === 'line' && preg_match('/[\r\n]/', $value) === 1) {
                    $errors[$key][] = $prefix.': tekst musi być jedną linią.';
                } elseif ($strict && $kind === 'url' && $value !== '' && ! self::validUrl($value, $type === 'button')) {
                    $errors[$key][] = $prefix.($type === 'button'
                        ? ': podaj adres https://… albo mailto:…'
                        : ': link musi zaczynać się od https://');
                }
                $normalized[$field] = $value;
            }

            if ($strict && $type === 'button') {
                if ($normalized['label'] === '') {
                    $errors["blocks.{$i}.label"][] = $prefix.': wpisz napis na przycisku.';
                }
                if ($normalized['url'] === '') {
                    $errors["blocks.{$i}.url"][] = $prefix.': podaj adres https://… albo mailto:…';
                }
            }
            if ($strict && $type === 'image' && $normalized['asset'] === null) {
                $errors["blocks.{$i}.asset"][] = $prefix.': wgraj obrazek albo usuń ten element.';
            }
            // klucz = numer w żądaniu, żeby błąd obrazka wskazał właściwy element
            $out[$i] = $normalized;
        }

        if (($counts['products'] ?? 0) !== 1) {
            $errors['blocks'][] = 'Mail musi mieć dokładnie jeden blok produktów.';
        }
        if (($counts['header'] ?? 0) > 1) {
            $errors['blocks'][] = 'Mail może mieć tylko jedno logo.';
        }
        if (($counts['footer'] ?? 0) > 1) {
            $errors['blocks'][] = 'Mail może mieć tylko jedną stopkę.';
        }
        self::checkAssets($out, $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return array_values($out);
    }

    /**
     * „Standard SUPON” — bloki nowej kampanii i szablonu.
     *
     * @return list<array<string, mixed>>
     */
    public static function standard(): array
    {
        return [
            ['type' => 'header', 'logo' => null],
            ['type' => 'heading', 'text' => ''],
            ['type' => 'text', 'text' => ''],
            ['type' => 'products', 'layout' => 'grid3'],
            ['type' => 'footer', 'text' => ''],
        ];
    }

    /**
     * Bloki kampanii sprzed szablonów (blocks = null) z heading, intro i layout. Nagłówek w jednej linii — dawniej
     * pole nie zabraniało entera, a w HTML i tak łamał się jak spacja.
     *
     * @return list<array<string, mixed>>
     */
    public static function legacy(Campaign $campaign): array
    {
        $layout = self::layout((string) $campaign->layout);

        return [
            ['type' => 'header', 'logo' => null],
            ['type' => 'heading', 'text' => trim((string) preg_replace('/[\r\n]+/', ' ', (string) $campaign->heading))],
            ['type' => 'text', 'text' => trim((string) $campaign->intro)],
            ['type' => 'products', 'layout' => in_array($layout, Campaign::LAYOUTS, true) ? $layout : 'grid3'],
            ['type' => 'footer', 'text' => ''],
        ];
    }

    /**
     * Zapisane bloki z usuniętymi układami zamienionymi na zachowane (REPLACED_LAYOUTS); reszta bez zmian.
     *
     * @param  array<array-key, mixed>  $blocks
     * @return list<mixed>
     */
    public static function upgrade(array $blocks): array
    {
        return array_map(
            static fn (mixed $block): mixed => is_array($block) && ($block['type'] ?? null) === 'products' && is_string($block['layout'] ?? null)
                ? [...$block, 'layout' => self::layout($block['layout'])]
                : $block,
            array_values($blocks),
        );
    }

    /** Układ z usuniętym zamienionym na zachowany; nieznany zostaje (validate go odrzuci, renderer da grid3). */
    public static function layout(string $layout): string
    {
        return self::REPLACED_LAYOUTS[$layout] ?? $layout;
    }

    /** https://host… (przycisk także mailto:adres) bez spacji i znaków sterujących — nic, co przeglądarka wykona. */
    public static function validUrl(string $url, bool $allowMailto): bool
    {
        if (preg_match('/[\x00-\x20\x7F]|\s/u', $url) === 1) {
            return false;
        }
        if (preg_match('#^https://[^/?\#@:]+(:\d{1,5})?([/?\#].*)?$#i', $url) === 1) {
            return true;
        }

        return $allowMailto && preg_match('/^mailto:[^@?]+@[^@?]+(\?.*)?$/i', $url) === 1;
    }

    /**
     * Obrazki (logo, grafika) muszą istnieć w campaign_assets. Właściciela nie sprawdzamy — pliki są publiczne,
     * a wspólne szablony administratora wskazują jego obrazki.
     *
     * @param  array<int, array<string, mixed>>  $blocks  numer w żądaniu → blok
     * @param  array<string, list<string>>  $errors
     */
    private static function checkAssets(array $blocks, array &$errors): void
    {
        $wanted = [];
        foreach ($blocks as $i => $block) {
            $field = $block['type'] === 'header' ? 'logo' : ($block['type'] === 'image' ? 'asset' : null);
            if ($field !== null && is_string($block[$field]) && ! isset($errors["blocks.{$i}.{$field}"])) {
                $wanted[$i] = [$field, $block[$field]];
            }
        }
        if ($wanted === []) {
            return;
        }
        $existing = array_flip(CampaignAsset::query()->whereIn('uuid', array_unique(array_column($wanted, 1)))->pluck('uuid')->all());
        foreach ($wanted as $i => [$field, $uuid]) {
            if (! isset($existing[$uuid])) {
                $errors["blocks.{$i}.{$field}"][] = self::LABELS[$blocks[$i]['type']].' (element nr '.($i + 1).'): obrazek nie istnieje — wgraj go ponownie.';
            }
        }
    }
}
