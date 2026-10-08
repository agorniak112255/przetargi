<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Support\ProductCodeMatch;

/**
 * Inne zapisy kodu karty do drugiej próby na hostach producenta (etap 3 opisów z cenników, §1.1). Audyt AJ GROUP
 * 08.10.2026: cennik pisze „SBA01B”, „WRA02B”, „SB01-J”, „071 STRAŻ”, a pros.pl ma strony modeli „SBA01”, „WRA02”,
 * „SB01”, „model 071”. Reguły tylko z profilu marki (code.alt_forms) — Coba, MAPA i Ansell ich nie mają:
 * - letter_suffix: jedna litera doklejona za cyfrą („SBA01B” → „SBA01”);
 * - dash_suffix: końcówka po ostatnim myślniku („SB01-J” → „SB01”, „108 - 130/120” → „108”);
 * - trailing_words: słowa z samych liter za kodem, od końca („071 STRAŻ” → „071”, „SB04 AIR CARP” → „SB04 AIR”,
 *   „SB04”);
 * - leading_zeros: zera na początku kodu liczbowego („00123” → „123”).
 * Zapis musi mieć co najmniej 3 znaki (po ProductCodeMatch::key) i cyfrę; sam SKU karty nie jest innym zapisem.
 * Strona znaleziona innym zapisem to najwyżej „soft”, a zapis będący SKU innej karty marki wskazuje stronę tamtej
 * karty (CardCodeArbiter) — tu tego nie sprawdzamy.
 */
final class ManufacturerCodeForms
{
    public const RULES = ['letter_suffix', 'dash_suffix', 'trailing_words', 'leading_zeros'];

    private const MIN_LENGTH = 3;

    /**
     * @return list<array{code: string, rule: string}> — reguły z profilu; ≥3 znaki z cyfrą; bez samego SKU
     */
    public function alternatives(Product $p, ?ManufacturerProfile $prof): array
    {
        $sku = trim((string) preg_replace('/\s+/u', ' ', (string) $p->sku));
        if ($prof === null || $prof->altForms === [] || $sku === '') {
            return [];
        }
        $own = ProductCodeMatch::key($sku);
        $out = [];
        $seen = [$own => true];
        foreach (self::RULES as $rule) {
            if (! in_array($rule, $prof->altForms, true)) {
                continue;
            }
            foreach ($this->forms($sku, $rule) as $code) {
                $code = trim($code, " \t-/");
                $key = ProductCodeMatch::key($code);
                if (mb_strlen($key) < self::MIN_LENGTH || preg_match('/\p{N}/u', $key) !== 1 || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = ['code' => $code, 'rule' => $rule];
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function forms(string $sku, string $rule): array
    {
        return match ($rule) {
            'letter_suffix' => preg_match('/^(.*\p{N})\p{L}$/u', $sku, $m) === 1 ? [$m[1]] : [],
            'dash_suffix' => preg_match('/^(.*\p{N}.*?)\s*-\s*[^\-]+$/u', $sku, $m) === 1 ? [$m[1]] : [],
            'trailing_words' => $this->withoutTrailingWords($sku),
            'leading_zeros' => preg_match('/^0+(\p{N}.*)$/u', $sku, $m) === 1 ? [$m[1]] : [],
            default => [],
        };
    }

    /**
     * Kod bez słów z samych liter na końcu, po jednym słowie: „SB04 AIR CARP” → „SB04 AIR”, „SB04”. Pierwsze słowo
     * z cyfrą zostaje zawsze.
     *
     * @return list<string>
     */
    private function withoutTrailingWords(string $sku): array
    {
        $words = explode(' ', $sku);
        $out = [];
        while (count($words) > 1 && preg_match('/^\p{L}+$/u', $words[count($words) - 1]) === 1) {
            array_pop($words);
            $out[] = implode(' ', $words);
        }

        return $out;
    }
}
