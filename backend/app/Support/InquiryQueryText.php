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
     * Słowa zapytania i tematu maila, które nie nazywają wyrobu: „Czy ma Pani może…”,
     * „Zapytanie ofertowe”, „Pilne”. Lista zamknięta — słowo spoza niej liczy się
     * jako nazwa, bo lepiej nie użyć tematu niż skleić wiersz z cudzym wyrobem.
     */
    private const INQUIRY_WORDS = [
        'czy', 'pan', 'pani', 'pana', 'panu', 'państwo', 'panstwo', 'może', 'moze', 'można', 'mozna',
        'jaka', 'jaki', 'jakie', 'jaką', 'jest', 'będzie', 'cena', 'ceny', 'cenę', 'cene', 'cenie', 'cennik',
        'koszt', 'kosztuje', 'proszę', 'prosze', 'prosimy', 'poproszę', 'poprosze', 'potrzebuję',
        'potrzebuje', 'potrzebujemy', 'macie', 'posiadacie', 'mają', 'maja', 'dostępne', 'dostepne',
        'dostępny', 'dostępność', 'dostepnosc', 'dzień', 'dzien', 'dobry', 'witam', 'zapytanie',
        'zapytania', 'ofertowe', 'ofertowy', 'oferta', 'ofertę', 'oferte', 'oferty', 'wycena', 'wycenę',
        'wycene', 'wyceny', 'zamówienie', 'zamowienie', 'zamówienia', 'zamowienia', 'pytanie', 'prośba',
        'prosba', 'pilne', 'pilnie', 'dotyczy', 'sztuk', 'sztuki', 'pary', 'rozmiar', 'rozmiarze',
        'rozmiary', 'ilość', 'ilosc', 'termin', 'dostawa', 'dostawy', 'również', 'rowniez', 'także',
        'takze', 'oraz', 'około', 'okolo', 'tylko', 'jeszcze', 'bardzo', 'dziękuję', 'dziekuje',
        'pozdrawiam', 'informacja', 'sprawa', 'temat', 'cenowe', 'cenowa', 'cenowy', 'cenowego',
        'produkt', 'produkty', 'produktów', 'produktow', 'towar', 'towary', 'asortyment',
    ];

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

        // Adres strony nie opisuje wyrobu, a jego słowa bywają wspólne dla całej rodziny
        // („urzadzenie-samohamowne-do-pracy-w-pionie” pasowało do każdego ROLEX-a, #69).
        // Kartę, do której prowadzi, wskazuje InquiryProductLinks.
        $text = InquiryLinks::withoutUrls($text);
        $text = self::dropPositionMarkers($text);
        $text = self::dropPrices($text);

        // Ciągi kropek („c. netto......24,00”) i osierocone separatory po cięciu.
        $text = preg_replace('/\.{2,}/u', ' ', $text) ?? $text;
        $text = preg_replace('/[(\[]\s*[)\]]/u', ' ', $text) ?? $text;
        // „Wycieraczka gumowa:rozm:” — rozmiar stoi dopiero w następnej linii
        $text = preg_replace('/\s*[:,]?\s*\b(?:rozmiar|rozm)\.?\s*[:.]?\s*$/iu', '', $text) ?? $text;
        // urwane „, c” z ceny rozbitej na dwie linie („…, c.\n netto....4 497,00 PLN”)
        $text = preg_replace('/[,;]?\s*\bc\.\s*$/iu', '', $text) ?? $text;
        $text = preg_replace('/\s*[,;:]\s*(?=[,;:]|$)/u', ' ', $text) ?? $text;
        // po wycietej cenie zostawala osierocona spacja przed przecinkiem
        $text = preg_replace('/\s+([,;])/u', '$1', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = Utf8Trim::trim($text, " \t\n\r\0\x0B,;:.-–—?!");

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
        $clean = preg_replace('/\s+([,;])/u', '$1', $clean) ?? $clean;
        // „Rekawice 24,00 (netto)” — po kwocie zostawal pusty nawias
        $clean = preg_replace('/\s*[(\[]\s*[)\]]/u', '', $clean) ?? $clean;
        $clean = Utf8Trim::trim($clean, " \t\n\r\0\x0B,;:.-–—");

        // Gdyby z cytatu został sam ogryzek, lepszy jest oryginał niż strzępek —
        // chyba że oryginał był samą ceną z cudzej oferty. Wtedy pusty cytat jest
        // jedynym wyjściem: odesłanie klientowi jego ceny obok naszej wprowadza w błąd.
        if (mb_strlen($clean) >= 3) {
            return $clean;
        }

        return self::looksLikePrice($text) ? $clean : trim($text);
    }

    /** Czy w tekście stała kwota przy słowie o cenie. */
    public static function looksLikePrice(string $text): bool
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

        return Utf8Trim::trim($name, " \t\n\r\0\x0B,;:.-–—");
    }

    /**
     * Wyrób z tematu maila. Klient bywa pisze model w temacie („11-571”), a w treści
     * już tylko „r. 11 – 40-50 par, jaka cena?”. Zdejmujemy przedrostki odpowiedzi
     * i przekazania, datę, numer sprawy i słowa, które niczego nie nazywają
     * („Zapytanie ofertowe”, „Pilne”). Gdy nie zostaje nazwa ani kod wyrobu — null.
     */
    public static function subjectProductHint(?string $subject): ?string
    {
        $text = trim((string) $subject);
        do {
            $before = $text;
            $text = preg_replace('/^\s*\[[^\]]{0,40}\]\s*/u', '', $text) ?? $text;
            $text = preg_replace('/^\s*(?:re|fwd?|fw|odp|pd|wg|aw|tr|sv)\s*(?:\[\d+\]|\(\d+\))?\s*:\s*/iu', '', $text) ?? $text;
        } while ($text !== $before);

        // numer sprawy i data to nie wyrób, a wyglądają jak kod
        $text = preg_replace('/(?<![\p{L}])(?:nr|numer)\.?\s*\S+/iu', ' ', $text) ?? $text;
        $text = preg_replace('/(?<![\d])(?:\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}|\d{4}-\d{2}-\d{2})(?![\d])/u', ' ', $text) ?? $text;
        // „Zapytanie ofertowe 056646136” — w temacie goły ciąg cyfr to numer sprawy
        $text = preg_replace('/(?<![\p{L}\d.\-])\d{5,}(?![\p{L}\d.\-])/u', ' ', $text) ?? $text;
        $text = self::withoutWords($text, self::INQUIRY_WORDS);
        $text = Utf8Trim::trim(preg_replace('/\s+/u', ' ', $text) ?? $text, " \t\n\r\0\x0B,;:.-–—?!*\"'()");

        if ($text === '' || ! self::namesProduct($text)) {
            return null;
        }

        return mb_substr($text, 0, 80);
    }

    /**
     * Czy wiersz zapytania sam nazywa wyrób — słowem albo kodem. Rozmiar, ilość
     * z jednostką i zwroty zapytania („Czy ma Pani może … jaka cena?”) nie nazywają
     * niczego: wiersz „r. 11 40-50 par” mówi, ile i jaki rozmiar, ale nie co.
     */
    public static function namesProduct(string $text): bool
    {
        $rest = self::dropPrices($text);
        $rest = preg_replace('/(?<![\p{L}])(?:rozmiar\p{L}*|rozm\.?|roz\.?|r\.)\s*\S+/iu', ' ', $rest) ?? $rest;
        // lookbehind: „PX140 - 30 par” — ogon kodu nie jest początkiem zakresu ilości
        $rest = preg_replace('/(?<![\p{L}\d.,])\d+(?:\s*[-–]\s*\d+)?\s*'.self::TRADE_UNIT.'(?![\p{L}])/iu', ' ', $rest) ?? $rest;
        $rest = self::withoutWords($rest, self::INQUIRY_WORDS);

        return self::hasProductWord($rest) || self::hasProductCode($rest);
    }

    /**
     * Kod wyrobu: litery z cyframi („PX140”, „11571VP”) albo grupy cyfr z łącznikiem,
     * z których jedna ma co najmniej trzy cyfry („11-571”). Krótkie oznaczenia
     * („S3”, „M/8”) to klasa albo rozmiar, a „40-50” to zakres ilości.
     */
    private static function hasProductCode(string $text): bool
    {
        preg_match_all('/(?<![\p{L}\d])[\p{L}\d]+(?:[-.][\p{L}\d]+)*(?![\p{L}\d])/u', $text, $m);
        foreach ($m[0] as $token) {
            $compact = preg_replace('/[-.]/u', '', $token) ?? $token;
            if (mb_strlen($compact) < 4 || preg_match('/\d/u', $compact) !== 1) {
                continue;
            }
            // wymiar i miara („40x60cm”, „500ml”) opisują wyrób, ale go nie nazywają
            if (preg_match('/^\d+(?:[.,]\d+)?(?:[x×]\d|(?:mm|cm|m|mb|g|kg|l|ml|db|v|kv)$)/iu', $token) === 1) {
                continue;
            }
            $lettersAndDigits = preg_match('/\p{L}/u', $compact) === 1;
            $groupedDigits = preg_match('/\d{3,}/u', $token) === 1 && preg_match('/\d[-.]\d/u', $token) === 1;
            if ($lettersAndDigits || $groupedDigits || preg_match('/^\d{5,}$/u', $compact) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $words
     */
    private static function withoutWords(string $text, array $words): string
    {
        $pattern = '/(?<![\p{L}\d])(?:'.implode('|', array_map(static fn (string $w): string => preg_quote($w, '/'), $words)).')(?![\p{L}\d])/iu';

        return preg_replace($pattern, ' ', $text) ?? $text;
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
        $sum = '(?>'.$amount.')(?!\d|[.,]\d)';
        // przy jednostce fizycznej liczba jest parametrem wyrobu, nie ceną
        $notPhysical = '(?!\s*'.self::PHYSICAL_UNIT.'(?![\p{L}]))';
        // Za kwotą stoi coś, co mówi o cenie (waluta, „za sztukę”, nawias z walutą),
        // albo nie stoi tam żadne słowo. Słowo spoza tej listy znaczy, że liczba opisuje
        // wyrób — jednostkę, ilość albo parametr — i wtedy zostaje.
        // Znak też bywa jednostką („99,95 %”, „21,50 °C”, „12,50 Ø”) — sama reguła
        // „za kwotą stoi słowo” by ich nie ochroniła, bo to nie są litery.
        $priceTail = '(?!\s*[%°Ø‰])(?=\s*(?:'.$word.'|za\b)|\s*[(\[]\s*(?:'.$word.')|\s*[^\p{L}(\[\s]|\s*$)';

        $patterns = [
            // „c. netto......24,00 PLN/szt”, „cena: 39,00 zł”, „cena jednostkowa 189,00”
            '/(?<![\p{L}])(?:'.$word.')(?:\s*(?:'.$word.'|'.$filler.'))*\s*[:.\s]*\.{0,}\s*'.$sum.$notPhysical.'\s*(?:'.$word.')?'.$unit.$per.$bare.'/iu',
            // „24,00/szt.” i „12,50 za szt.” — cena bez waluty. Tylko przy jednostce
            // handlowej i tylko dla kwoty z groszami: „120,5 g/m2” to gramatura,
            // a „12,5 mb” długość, nie cena.
            '/\d[\d \x{00A0}]*[.,]\d{2}\s*(?:\/\s*|za\s+)'.self::TRADE_UNIT.'(?![\p{L}\d])/iu',
            // „......24,00” — po ciągu kropek stoi cena, ale w wypunktowaniu stoi tam
            // również ilość albo długość („......... 18 m”, „...... 100 szt./op.”,
            // „....... 32 dB”, „....... 3 worki”). Kwotę bierzemy tylko wtedy, gdy za nią
            // stoi waluta albo koniec zapisu — jednostka po liczbie znaczy, że to nie cena.
            // Kwota z groszami po ciągu kropek („......24,00”) jest ceną, o ile zaraz za nią
            // nie stoi słowo: „....... 6,00 bar”, „....... 1,80 metra”, „....... 32,50 (dB)”
            // i „ilość ....... 100,00 szt.” to parametr albo ilość, a lista jednostek nigdy
            // nie będzie kompletna — dlatego pyta o to, czy słowo jest słowem o cenie.
            '/\.{3,}\s*(?>\d[\d \x{00A0}]*(?:[.,]\d{3})*[.,]\d{2})(?!\d|[.,]\d)'.$priceTail.'(?>(?:\s*[-–—]\s*'.$amount.')?)(?:\s*'.$word.')*'.$unit.$per.$bare.'/iu',
            // Goła liczba po kropkach bywa ilością albo długością („....... 32 dB”,
            // „...... 100 szt./op.”), więc bierzemy ją tylko przy walucie albo na końcu zapisu.
            '/\.{3,}\s*'.$sum.'(?>(?:\s*[-–—]\s*'.$amount.')?)(?=\s*(?:pln|zł|zl|eur|usd|netto|brutto)(?![\p{L}])|\s*za\s+(?:\d{1,3}\s*)?'.self::TRADE_UNIT.'|\s*[(\[]\s*(?:'.$word.')|\s*[,;)\]]|\s*\.(?!\d)|\s*$)(?:\s*'.$word.')*'.$unit.$per.$bare.'/iu',
            // Waluta po kwocie („4 497,00PLN/szt.”). Rozpiętość bierzemy tylko wtedy, gdy
            // dolna granica ma grosze („24,00-30,00 PLN”) — goła cyfra przed myślnikiem bywa
            // końcówką kodu wyrobu albo rozmiarem („A2P3 - 24,00 zł”, „SRC 45 - 189,00 PLN”).
            '/(?:(?<![\p{L}\d.,])\d{1,3}(?:[ \x{00A0}]\d{3})*[.,]\d{2}\s*[-–—]\s*)?'.$amount.'\s*(?:pln|zł|zl|eur|usd)'.$unit.$per.'/iu',
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
