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
    private const PRICE_WORD = '(?:c\.|cena|cenie|ceny|cenę|koszt|kosztu|wartość|wartości|stawka|stawki|razem|netto|brutto|pln|zł|zl|eur|usd)';

    /**
     * Wtrącenie między słowem o cenie a kwotą: „cena jednostkowa 189,00”,
     * „wartość netto pozycji: 120,00”. Lista zamknięta — dowolne słowo między
     * ceną a liczbą pozwoliłoby wyciąć liczbę, która ceną nie jest.
     */
    private const PRICE_FILLER = '(?:jednostkow[aeiy]|jedn\.?|hurtow[aey]|detaliczn[aey]|ofertow[aey]|katalogow[aey]|zakupu|sprzedaży|sprzedazy|pozycji|brutto|netto|za\s+\d{1,3}\s*'.self::TRADE_UNIT.'|\/\s*'.self::TRADE_UNIT.')';

    /**
     * Jednostka fizyczna tuż za liczbą znaczy, że to parametr wyrobu (masa, długość,
     * pojemność), a nie cena — „masa netto 20 kg” gubiło liczbę razem z warunkiem.
     */
    private const PHYSICAL_UNIT = '(?:kg|g|l|ml|t|mm|cm|m|db|kv|v|%|mb|m2|m²)';

    /** Jednostka handlowa: sama z siebie znaczy wielkość opakowania albo ilość. */
    private const TRADE_UNIT = '(?:sztuk[aeiyę]?|szt\.?|opak\.?|op\.?|kpl\.?|komplet[a-zóy]*|zest\.?|zestaw[a-zóy]*|par[aeyę]?)';

    /**
     * Liczba w zapisie cenowym: „4 497,00”, „1.250,00”, „24,00”, „137”. Grupy tysięcy
     * bierzemy w całości — inaczej z „1.250,00” wzorzec dopasowywał samo „1.250” i cena
     * z kropką tysięcy zostawała w cytacie.
     */
    private const AMOUNT = '(?:\d{1,3}(?:[ \x{00A0}.]\d{3})+(?:,\d{1,2})?|\d[\d \x{00A0}]*(?:[.,]\d+)?)';

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
        $text = preg_replace('/[(\[]\s*[)\]]/u', ' ', $text) ?? $text;
        // „Wycieraczka gumowa:rozm:” — rozmiar stoi dopiero w następnej linii
        $text = preg_replace('/\s*[:,]?\s*\b(?:rozmiar|rozm)\.?\s*[:.]?\s*$/iu', '', $text) ?? $text;
        // urwane „, c” z ceny rozbitej na dwie linie („…, c.\n netto....4 497,00 PLN”)
        $text = preg_replace('/[,;]?\s*\bc\.?\s*$/iu', '', $text) ?? $text;
        $text = preg_replace('/\s*[,;:]\s*(?=[,;:]|$)/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B,;:.-–—?!");

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
        // po wycięciu ceny zostawał podwójny przecinek: „Rękawice, , rozmiar 9”
        $clean = preg_replace('/(?:\s*[,;]\s*){2,}/u', ', ', $clean) ?? $clean;
        // „Rekawice 24,00 (netto)” — po kwocie zostawal pusty nawias
        $clean = preg_replace('/\s*[(\[]\s*[)\]]/u', '', $clean) ?? $clean;
        $clean = trim($clean, " \t\n\r\0\x0B,;:.-–—");

        // Gdyby z cytatu został sam ogryzek, lepszy jest oryginał niż strzępek —
        // chyba że oryginał był samą ceną z cudzej oferty. Wtedy pusty cytat jest
        // jedynym wyjściem: odesłanie klientowi jego ceny obok naszej wprowadza w błąd.
        if (mb_strlen($clean) >= 3) {
            return $clean;
        }

        return self::looksLikePrice($text) ? $clean : trim($text);
    }

    /** Czy w tekście stała kwota przy słowie o cenie. */
    private static function looksLikePrice(string $text): bool
    {
        return preg_match('/'.self::PRICE_WORD.'/iu', $text) === 1
            && preg_match('/\d[\d \x{00A0}]*[.,]\d{2}/u', $text) === 1;
    }

    /**
     * Sama nazwa wyrobu, bez wymiarów i rozmiarów. Tyle dziedziczy wiersz,
     * w którym stoi wyłącznie rozmiar — inaczej skleiłyby się dwa wymiary
     * („wycieraczka 40x60cm 50x100cm”) i zapytanie przestałoby mieć sens.
     */
    public static function productNameOnly(string $text): string
    {
        $name = preg_replace('/\b(?:rozmiar|rozm\.?|roz\.?)(?:[:.\s]+|(?=\d))[\p{L}\d\/,.\-]+/iu', ' ', $text) ?? $text;
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
            '/(?:rozmiar|rozm\.?|roz\.?|размер|size)|[\d.,]+\s*(?:x|×)\s*[\d.,]+|[\d.,]+\s*(?:mm|cm|m|kv|v|g\/m2|g\/m²|l|kg|%)?/iu',
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
        // „poz. 12 Buty robocze” — numer pozycji szedł do katalogu razem z nazwą
        $text = preg_replace('/^\s*[*\-•]*\s*poz\.?\s*\d{1,3}\s*[.:)\-]*\s*/iu', '', $text) ?? $text;
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
        // ogon po kwocie: „18,00 za szt.” — mówi o cenie, nie o ilości
        $per = '(?:\s*za\s+(?:\d{1,3}\s*)?'.self::TRADE_UNIT.'(?![\p{L}]))?';
        // „cena 24,00 szt.” — jednostka tuż za ceną też mówi o cenie; bez tego w cytacie
        // zostawał ogryzek „, szt”
        $bare = '(?:\s*'.self::TRADE_UNIT.'(?![\p{L}]))?';
        $amount = self::AMOUNT;
        $word = self::PRICE_WORD;
        $filler = self::PRICE_FILLER;
        // Kwota bez cofania się w głąb liczby: bez grupy atomowej silnik oddałby
        // ostatnią cyfrę, żeby wejśrzenie przeszło, i z „20 kg” zostałoby „0 kg”.
        $sum = '(?>'.$amount.')(?![\d,.])';
        // przy jednostce fizycznej liczba jest parametrem wyrobu, nie ceną
        $notPhysical = '(?!\s*'.self::PHYSICAL_UNIT.'(?![\p{L}]))';

        $patterns = [
            // „c. netto......24,00 PLN/szt”, „cena: 39,00 zł”, „cena jednostkowa 189,00”
            '/(?<![\p{L}])(?:'.$word.')(?:\s*(?:'.$word.'|'.$filler.'))*\s*[:.\s]*\.{0,}\s*'.$sum.$notPhysical.'\s*(?:'.$word.')?'.$unit.$per.$bare.'/iu',
            // „4 497,00PLN/szt.” — liczba przyklejona do waluty
            '/'.$amount.'\s*(?:pln|zł|zl|eur|usd)'.$unit.$per.'/iu',
            // „24,00/szt.” i „12,50 za szt.” — cena bez waluty. Tylko przy jednostce
            // handlowej i tylko dla kwoty z groszami: „120,5 g/m2” to gramatura,
            // a „12,5 mb” długość, nie cena.
            '/\d[\d \x{00A0}]*[.,]\d{2}\s*(?:\/\s*|za\s+)'.self::TRADE_UNIT.'(?![\p{L}\d])/iu',
            // „......24,00” — po ciągu kropek stoi cena, ale w wypunktowaniu stoi tam
            // również ilość albo długość („......... 18 m”, „...... 100 szt./op.”,
            // „....... 32 dB”, „....... 3 worki”). Kwotę bierzemy tylko wtedy, gdy za nią
            // stoi waluta albo koniec zapisu — jednostka po liczbie znaczy, że to nie cena.
            '/\.{3,}\s*'.$sum.'(?>(?:\s*[-–—]\s*'.$amount.')?)(?=\s*(?:pln|zł|zl|eur|usd|netto|brutto)(?![\p{L}])|\s*za\s+(?:\d{1,3}\s*)?'.self::TRADE_UNIT.'|\s*[(\[]\s*(?:'.$word.')|\s*[,;)\]]|\s*\.(?!\d)|\s*$)(?:\s*'.$word.')*'.$unit.$per.$bare.'/iu',
            // osierocone „c. netto”, gdy liczbę zabrał wcześniejszy wzorzec
            '/\bc\.\s*netto\b/iu',
            // „masa netto 20 kg”, „waga produktu brutto 25 kg” — tu „netto” opisuje
            // wielkość opakowania, a nie cenę, i cytat klienta ma je zachować
            '/\b(?:netto|brutto)\b(?!\s*[:=]?\s*(?:ok\.?\s+|ponad\s+|min\.?\s+|maks\.?\s+)?\d[\d,. ]*\s*'.self::PHYSICAL_UNIT.'(?![\p{L}]))/iu',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, ' ', $text) ?? $text;
        }

        return $text;
    }
}
