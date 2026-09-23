<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Support\ProductSizeVariant;

/**
 * Czym różnią się pozycje karty dystrybutora, które trafiają w kilka kart producenta: rozmiarem, kolorem czy nie
 * wiadomo (łączenie kart, krok 5). Bez zgadywania — rozmiar tylko wtedy, gdy cała etykieta pozycji jest rozmiarem
 * („rozmiar S (mały)”, „46/48”, „uniwersalny”), a nazwy kart producenta różnią się rozmiarem; kolor, gdy etykieta albo
 * różniąca część nazwy go podaje. Wszystko inne — unknown (decyduje człowiek). Bez bazy danych.
 */
final class CardMatchSignals
{
    /** Rdzenie nazw kolorów — dopasowanie początku słowa (czerwony, czerwona, czerwone…). */
    private const COLOR_STEMS = [
        'czerwon', 'zielon', 'żółt', 'zolt', 'niebiesk', 'granat', 'biał', 'bial', 'czarn', 'szar', 'pomarańcz',
        'pomarancz', 'limonk', 'różow', 'rozow', 'fiolet', 'brąz', 'braz', 'oliw', 'khaki', 'beż', 'srebrn', 'złot',
        'zlot', 'turkus', 'bordo',
    ];

    /** „bez” bez polskich znaków to także przyimek („bez kaptura”) — kolor tylko jako całe słowo z końcówką. */
    private const BEZ_COLOR_WORDS = ['bezowy', 'bezowa', 'bezowe'];

    private const SIZE_WORDS = [
        'mały', 'mała', 'małe', 'maly', 'mala', 'średni', 'średnia', 'sredni', 'srednia', 'duży', 'duża', 'duzy',
        'duza', 'uniwersalny', 'uniwersalna', 'onesize', 'one size',
    ];

    /** Słowo wprowadzające rozmiar na początku etykiety albo w nazwie. */
    private const SIZE_KEYWORD = '(?:rozmiar|rozm\.?|size)';

    public function __construct(private readonly ProductSizeVariant $sizes = new ProductSizeVariant) {}

    /**
     * Sygnał z etykiety pozycji u dystrybutora (product_identifiers.variant_label): color, gdy podaje kolor; size,
     * gdy cała etykieta to rozmiar (ze słowem „rozmiar/rozm./size” albo bez, z ewentualnym nawiasem bez koloru);
     * inaczej unknown (także pusta).
     *
     * @param  string  $source  nazwa źródła do uzasadnienia („B2B P4S”)
     * @return array{signal: string, why: string}
     */
    public function labelSignal(?string $label, string $source = ''): array
    {
        $label = trim((string) $label);
        $prefix = 'etykieta'.($source !== '' ? ' '.$source : '');
        if ($label === '') {
            return ['signal' => 'unknown', 'why' => $prefix.': brak'];
        }
        if ($this->mentionsColor($label)) {
            return ['signal' => 'color', 'why' => 'kolor w etykiecie'.($source !== '' ? ' '.$source : '').': '.$label];
        }
        if ($this->isSizeLabel($label)) {
            return ['signal' => 'size', 'why' => $prefix.': '.$label];
        }

        return ['signal' => 'unknown', 'why' => $prefix.' bez rozmiaru i koloru: '.$label];
    }

    /**
     * Sygnał z nazw kart producenta: część wspólna = słowa obecne we wszystkich nazwach, różniąca = pozostałe słowa
     * danej karty. color, gdy różniąca ma kolor; size, gdy ma rozmiar albo jej pierwsze słowo stoi zaraz po
     * „rozmiar/rozm./size”; inaczej unknown (także identyczne nazwy). Wynik w kolejności nazw.
     *
     * @param  list<string>  $names
     * @param  string  $source  producent do uzasadnienia („3M”)
     * @return list<array{signal: string, why: string, differing: list<string>}>
     */
    public function nameSignals(array $names, string $source = ''): array
    {
        $words = array_map(fn (string $name): array => $this->words($name), array_values($names));
        $common = null;
        foreach ($words as $list) {
            $set = array_fill_keys(array_column($list, 'key'), true);
            $common = $common === null ? $set : array_intersect_key($common, $set);
        }
        $common ??= [];

        $out = [];
        $prefix = 'nazwa'.($source !== '' ? ' '.$source : '');
        foreach ($words as $list) {
            $differing = [];
            $firstIndex = null;
            foreach ($list as $i => $word) {
                if (! isset($common[$word['key']])) {
                    $differing[] = $word;
                    $firstIndex ??= $i;
                }
            }
            $shown = array_values(array_unique(array_column($differing, 'text')));
            if ($differing === []) {
                $out[] = ['signal' => 'unknown', 'why' => $prefix.' bez różnicy', 'differing' => []];

                continue;
            }
            $signal = 'unknown';
            foreach ($differing as $word) {
                if ($this->isColorWord($word['key'])) {
                    $signal = 'color';
                    break;
                }
            }
            if ($signal === 'unknown') {
                $afterKeyword = $firstIndex > 0 && preg_match('/^'.self::SIZE_KEYWORD.'$/u', $list[$firstIndex - 1]['key']) === 1;
                if ($afterKeyword) {
                    $signal = 'size';
                } else {
                    foreach ($differing as $word) {
                        if ($this->isSizeToken($word['key'])) {
                            $signal = 'size';
                            break;
                        }
                    }
                }
            }
            $out[] = ['signal' => $signal, 'why' => $prefix.' różni się: '.implode(', ', $shown), 'differing' => $shown];
        }

        return $out;
    }

    /**
     * Podpowiedź nazwy karty modelu: wspólny początek nazw (słowami), bez końcowego „rozmiar/rozm./size”
     * i interpunkcji. null, gdy zostaje mniej niż 2 słowa.
     *
     * @param  array<array-key, string>  $names
     */
    public function commonName(array $names): ?string
    {
        $lists = array_map(fn (string $name): array => $this->words($name), array_values($names));
        if ($lists === []) {
            return null;
        }
        $prefix = [];
        foreach ($lists[0] as $i => $word) {
            foreach ($lists as $list) {
                if (($list[$i]['key'] ?? null) !== $word['key']) {
                    break 2;
                }
            }
            $prefix[] = $word['raw'];
        }
        while ($prefix !== []) {
            $last = (string) preg_replace('/[\s,;:.\-–—\/]+$/u', '', (string) end($prefix));
            if ($last === '' || preg_match('/^'.self::SIZE_KEYWORD.'$/iu', $last) === 1) {
                array_pop($prefix);

                continue;
            }
            $prefix[count($prefix) - 1] = $last;
            break;
        }

        return count($prefix) >= 2 ? implode(' ', $prefix) : null;
    }

    /** Rozmiar z etykiety do pokazania: „rozmiar S (mały)” → „S (mały)”; bez słowa „rozmiar” — dosłownie. */
    public function sizeLabel(?string $label): ?string
    {
        $label = trim((string) $label);
        if ($label === '') {
            return null;
        }
        $stripped = trim((string) preg_replace('/^'.self::SIZE_KEYWORD.'(?!\p{L})\s*:?\s*/iu', '', $label, 1));

        return $stripped !== '' ? $stripped : $label;
    }

    private function isSizeLabel(string $label): bool
    {
        $text = mb_strtolower(trim($label));
        $text = trim((string) preg_replace('/^'.self::SIZE_KEYWORD.'(?!\p{L})\s*:?\s*/u', '', $text, 1));
        if (preg_match('/^(.+?)(?:\s*\(([^()]*)\))?$/u', $text, $m) !== 1) {
            return false;
        }
        $bracket = $m[2] ?? '';
        if ($bracket !== '' && $this->mentionsColor($bracket)) {
            return false;
        }

        return $this->isSizeToken(trim($m[1]));
    }

    /** Rozmiar odzieży/obuwia/rękawic, zakres dwóch rozmiarów („46/48”) albo słowo rozmiaru („mały”). */
    private function isSizeToken(string $token): bool
    {
        $token = mb_strtolower(trim($token));
        if ($token === '') {
            return false;
        }
        if (in_array($token, self::SIZE_WORDS, true) || $this->sizes->looksLikeWearSize($token)) {
            return true;
        }

        return preg_match('/^(\d{2})\s*[-\/]\s*(\d{2})$/', $token, $m) === 1
            && $this->sizes->looksLikeWearSize($m[1])
            && $this->sizes->looksLikeWearSize($m[2]);
    }

    private function mentionsColor(string $text): bool
    {
        $text = mb_strtolower($text);
        if (str_contains($text, 'kolor')) {
            return true;
        }
        foreach (preg_split('/[^\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if ($this->isColorWord($word)) {
                return true;
            }
        }

        return false;
    }

    private function isColorWord(string $word): bool
    {
        $word = mb_strtolower($word);
        if (in_array($word, self::BEZ_COLOR_WORDS, true)) {
            return true;
        }
        foreach (self::COLOR_STEMS as $stem) {
            if (str_starts_with($word, $stem)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Słowa nazwy rozdzielone białymi znakami: raw — dosłownie, text — bez końcowych „,;:”, key — text małymi literami.
     *
     * @return list<array{raw: string, text: string, key: string}>
     */
    private function words(string $name): array
    {
        $out = [];
        foreach (preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $raw) {
            $text = (string) preg_replace('/[,;:]+$/u', '', $raw);
            if ($text === '') {
                continue;
            }
            $out[] = ['raw' => $raw, 'text' => $text, 'key' => mb_strtolower($text)];
        }

        return $out;
    }
}
