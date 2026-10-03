<?php

declare(strict_types=1);

namespace App\Services\Bzp;

use App\Models\ProcurementNotice;
use App\Support\PpeAssortment;

/**
 * Które części (pakiety) ogłoszenia mają towary BHP — żeby z wielopakietowego postępowania (np. „sprzęt jednorazowy”
 * z rękawicami tylko w pakiecie 3) odczytywać dokumenty tylko tych pakietów (decyzja właściciela 03.10.2026:
 * „niech odczytuje tylko pakiety z towarami BHP”). Część jest BHP, gdy:
 *  - któryś jej kod rodzaju zamówienia (CPV: główny albo dodatkowy) pasuje do config bzp.cpv_categories
 *    (ta sama mapa co filtr „Rodzaj zamówienia” listy ogłoszeń), albo
 *  - jej nazwa lub opis wymienia rodzinę środków ochrony indywidualnej (PpeAssortment::family).
 * To wnioskowanie z ogłoszenia, nie fakt — w szczegółach ogłoszenia widać powód, a człowiek może zaznaczyć inaczej.
 */
final class NoticeBhpLots
{
    public function __construct(
        private readonly PpeAssortment $assortment = new PpeAssortment,
    ) {}

    /**
     * @return array<int, array{bhp: bool, reason: ?string}> numer części → ocena z powodem
     */
    public function forNotice(ProcurementNotice $notice): array
    {
        $parsed = is_array($notice->parsed) ? $notice->parsed : [];
        $out = [];
        foreach (is_array($parsed['lots'] ?? null) ? $parsed['lots'] : [] as $lot) {
            if (! is_array($lot) || ! is_numeric($lot['lot_no'] ?? null)) {
                continue;
            }
            $out[(int) $lot['lot_no']] = $this->forLot($lot);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $lot  część z parsed.lots
     * @return array{bhp: bool, reason: ?string}
     */
    public function forLot(array $lot): array
    {
        $codes = array_merge([$lot['cpv_main'] ?? null], is_array($lot['cpv_additional'] ?? null) ? $lot['cpv_additional'] : []);
        foreach ($codes as $code) {
            $label = self::cpvCategory(is_string($code) ? $code : '');
            if ($label !== null) {
                return ['bhp' => true, 'reason' => 'kod rodzaju zamówienia (CPV) '.$code.' — '.mb_strtolower($label)];
            }
        }
        $text = trim(((string) ($lot['name'] ?? '')).' '.((string) ($lot['description'] ?? '')));
        $family = $text !== '' ? $this->assortment->family($text) : null;
        // „ewakuacyjny” (przyczepka, schodołaz, prześcieradło ewakuacyjne — produkcja 03.10.2026) to dla PpeAssortment
        // sprzęt chroniący przed upadkiem; w opisie części liczy się tylko z wyraźnym sprzętem asekuracyjnym
        if ($family === 'fall' && preg_match('/szel(?:ki|ek)|uprz[ąa][żz]|amortyzator|asekur|samohamown|upad/iu', $text) !== 1) {
            $family = null;
        }
        if ($family !== null) {
            return ['bhp' => true, 'reason' => 'opis części wymienia środki ochrony indywidualnej'];
        }

        return ['bhp' => false, 'reason' => null];
    }

    /** Nazwa grupy BHP z config bzp.cpv_categories dla kodu CPV (najdłuższy pasujący początek, jak lista ogłoszeń) albo null. */
    public static function cpvCategory(string $code): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $code);
        if ($digits === '') {
            return null;
        }
        $best = null;
        $bestLength = 0;
        foreach ((array) config('bzp.cpv_categories', []) as $category) {
            foreach ((array) ($category['prefixes'] ?? []) as $prefix) {
                $prefix = (string) $prefix;
                if ($prefix !== '' && strlen($prefix) > $bestLength && str_starts_with($digits, $prefix)) {
                    $best = (string) ($category['label'] ?? '');
                    $bestLength = strlen($prefix);
                }
            }
        }

        return $best;
    }

    /**
     * Numer pakietu/części z nazwy dokumentu: „Załącznik nr 2 do SWZ - Pakiet nr 3 - Formularz cenowy” → 3,
     * „Część 2”, „Część II”, „cz. 4”, „Zadanie nr 5”. Sam „Załącznik nr 2” to nie numer części — null.
     */
    public static function lotNumberOf(string $text): ?int
    {
        if (preg_match('/(?<![\p{L}])(?:pakiet\w*|częś[ćc]\w*|czesc\w*|cz\.|zadani\w*)\s*(?:nr\.?|numer)?\s*(\d{1,3}|[IVX]{1,5})(?![\p{L}\d])/u', self::lowerKeepRoman($text), $m) === 1) {
            return ctype_digit($m[1]) ? (int) $m[1] : self::romanToInt($m[1]);
        }

        return null;
    }

    /** Małe litery poza liczbą rzymską po słowie kluczowym (rzymskie cyfry rozpoznajemy tylko wielkie: „Część II”). */
    private static function lowerKeepRoman(string $text): string
    {
        return (string) preg_replace_callback('/\S+/u', static fn (array $w): string => preg_match('/^[IVX]{1,5}[.,:;)]*$/', $w[0]) === 1 ? $w[0] : mb_strtolower($w[0]), $text);
    }

    private static function romanToInt(string $roman): int
    {
        $values = ['I' => 1, 'V' => 5, 'X' => 10];
        $total = 0;
        $len = strlen($roman);
        for ($i = 0; $i < $len; $i++) {
            $value = $values[$roman[$i]] ?? 0;
            $next = $i + 1 < $len ? ($values[$roman[$i + 1]] ?? 0) : 0;
            $total += $value < $next ? -$value : $value;
        }

        return $total;
    }
}
