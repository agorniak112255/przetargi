<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Linia zapytania przygotowana do szukania w katalogu.
 *
 * Z maili handlowych („Wycieraczka gumowa:rozm: 40x60cm, c. netto......24,00
 * PLN/szt”) wycinamy wyłącznie cenę i numerację pozycji. Reszta zostaje —
 * norma, klasa ochrony, substancja, wymiar i wielkość opakowania decydują
 * o doborze wyrobu i skasowanie ich byłoby cichą utratą warunku.
 *
 * Zasada: usuwamy liczbę tylko wtedy, gdy stoi przy słowie o cenie albo po
 * ciągu kropek. Gołej liczby ani liczby z jednostką techniczną nie ruszamy.
 */
final class InquiryQueryText
{
    /** Słowa, które zdradzają cenę — tylko przy nich wolno usunąć liczbę. */
    private const PRICE_WORD = '(?:c\.|cena|cenie|ceny|netto|brutto|pln|zł|zl|eur|usd)';

    /** Liczba w zapisie cenowym: „4 497,00”, „24,00”, „137”. */
    private const AMOUNT = '\d[\d \x{00A0}]*(?:[.,]\d+)?';

    public static function forCatalog(string $line): string
    {
        $text = trim($line);
        if ($text === '') {
            return '';
        }

        $text = self::dropPositionMarkers($text);
        $text = self::dropPrices($text);

        // Ciągi kropek („c. netto......24,00”) i osierocone separatory po cięciu.
        $text = preg_replace('/\.{2,}/u', ' ', $text) ?? $text;
        // „Wycieraczka gumowa:rozm:” — rozmiar stoi dopiero w następnej linii
        $text = preg_replace('/\s*[:,]?\s*\b(?:rozmiar|rozm)\.?\s*[:.]?\s*$/iu', '', $text) ?? $text;
        // urwane „, c” z ceny rozbitej na dwie linie („…, c.\n netto....4 497,00 PLN”)
        $text = preg_replace('/[,;]?\s*\bc\.?\s*$/iu', '', $text) ?? $text;
        $text = preg_replace('/\s*[,;:]\s*(?=[,;:]|$)/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B,;:.-–—");

        return mb_substr(trim($text), 0, 140);
    }

    /**
     * Cytat pozycji bez ceny — do listu, który zobaczy klient.
     *
     * W mailach hurtowych przy każdej pozycji stoi cena z wcześniejszej oferty.
     * Odsyłanie jej klientowi w naszej odpowiedzi jest mylące: obok naszej ceny
     * stałaby cudza. Reszta cytatu zostaje słowo w słowo — to dane źródłowe.
     */
    public static function withoutPrice(string $text): string
    {
        $clean = self::dropPrices(trim($text));
        $clean = preg_replace('/\.{2,}/u', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;
        $clean = trim($clean, " \t\n\r\0\x0B,;:.-–—");

        // Gdyby z cytatu został sam ogryzek, lepszy jest oryginał niż strzępek.
        return mb_strlen($clean) < 3 ? trim($text) : $clean;
    }

    /**
     * Sama nazwa wyrobu, bez wymiarów i rozmiarów. Tyle dziedziczy wiersz,
     * w którym stoi wyłącznie rozmiar — inaczej skleiłyby się dwa wymiary
     * („wycieraczka 40x60cm 50x100cm”) i zapytanie przestałoby mieć sens.
     */
    public static function productNameOnly(string $text): string
    {
        $name = preg_replace('/\b(?:rozmiar|rozm\.?)[:\s]+[\p{L}\d\/,.\-]+/iu', ' ', $text) ?? $text;
        $name = preg_replace('/\b[\d.,]+\s*(?:x|×)\s*[\d.,]+\s*(?:mm|cm|m)?\b/iu', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name, " \t\n\r\0\x0B,;:.-–—");
    }

    /** Czy w tekście została choć jedna nazwa wyrobu (słowo, nie wymiar). */
    public static function hasProductWord(string $text): bool
    {
        return preg_match('/(?<![\p{L}\d])\p{L}{4,}(?![\p{L}]*\d)/u', $text) === 1
            && ! self::isOnlyMeasurement($text);
    }

    /** Sama miara albo rozmiar: „50x100cm”, „rozm. 44”, „XL”. */
    private static function isOnlyMeasurement(string $text): bool
    {
        $rest = preg_replace(
            '/(?:rozmiar|rozm\.?|размер|size)|[\d.,]+\s*(?:x|×)\s*[\d.,]+|[\d.,]+\s*(?:mm|cm|m|kv|v|g\/m2|g\/m²|l|kg|%)?/iu',
            ' ',
            $text
        ) ?? $text;
        $rest = trim(preg_replace('/[^\p{L}]+/u', ' ', $rest) ?? $rest);

        foreach (preg_split('/\s+/u', $rest) ?: [] as $word) {
            if (mb_strlen($word) >= 4) {
                return false;
            }
        }

        return true;
    }

    /** „(poz6)”, „( poz 25)”, „poz. 12”, wiodące „1.” albo „2)”. */
    private static function dropPositionMarkers(string $text): string
    {
        $text = preg_replace('/\(\s*poz\.?\s*\d+\s*\)/iu', ' ', $text) ?? $text;
        $text = preg_replace('/^\s*[*\-•]*\s*\d{1,2}\s*[.)]\s*/u', '', $text) ?? $text;
        $text = preg_replace('/^\s*\*+/u', '', $text) ?? $text;

        return $text;
    }

    /**
     * Cena razem z otoczeniem: „c. netto......24,00 PLN/szt”, „cena 39,00 zł”,
     * „4 497,00PLN/szt.”. Jednostka po cenie („/szt”, „/opak”) idzie razem z nią,
     * bo mówi o cenie, a nie o wyrobie.
     */
    private static function dropPrices(string $text): string
    {
        $unit = '(?:\s*\/\s*(?:szt\.?|sztuk[ai]?|opak\.?|op\.?|kpl\.?|mb|m2|m²|para|par))?';
        $amount = self::AMOUNT;
        $word = self::PRICE_WORD;

        $patterns = [
            // „c. netto......24,00 PLN/szt” i „cena: 39,00 zł”
            '/(?:'.$word.')(?:\s*'.$word.')*\s*[:.\s]*\.{0,}\s*'.$amount.'\s*(?:'.$word.')?'.$unit.'/iu',
            // „4 497,00PLN/szt.” — liczba przyklejona do waluty
            '/'.$amount.'\s*(?:pln|zł|zl|eur|usd)'.$unit.'/iu',
            // „......24,00” — po ciągu kropek zawsze stoi cena
            '/\.{3,}\s*'.$amount.'/u',
            // osierocone „c. netto”, gdy liczbę zabrał wcześniejszy wzorzec
            '/\bc\.\s*netto\b/iu',
            '/\bnetto\b|\bbrutto\b/iu',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, ' ', $text) ?? $text;
        }

        return $text;
    }
}
