<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Support\NormCode;
use App\Support\PromptEcho;

/**
 * Pole norm opisu przed zapisem (etap 3, §1.4 i §1.6a planu z 08.10.2026). Do tej pory payloadFromExtraction tylko
 * zwijał powtórzenia (NormCode::dedupe), a withoutUnsupportedNormClaims sprawdza, czy oznaczenie stoi w źródle — nie,
 * czy poziom należy do tej normy. Audyty z 08.10 znalazły: „EN ISO 13999 (klasa D)” zamiast poziomu TDM z EN 388
 * (~23 karty HyFlex), gauge dzianiny jako poziom EN 388 („EN 388 (klasa 15)”), poziom ANSI A1–A9 pod EN 388, kod EN 388
 * przy EN 420 / EN ISO 21420, EN ISO 374-1 z typem sprzecznym z liczbą liter, nieistniejące „EN ISO 388”, „Kategoria 2”
 * w normach, pozycje bez żadnego oznaczenia normy („ATEX HAZARDOUS AREA / ATMOSPHERE GROUP” — Bolle 23053, „SCS” —
 * AJ 908) i szablonowe „EN 166 (oznaczenie na soczewce: PrB420)” bez symboli EN 166 (ProBlu).
 *
 * Poprawiamy automatycznie tylko to, co nie zmienia sensu źródła (zapis „EN ISO 388”, poziom obcej normy zdjęty
 * z pozycji, kategoria ŚOI przeniesiona do certyfikatów). Resztę wyłącznie odrzucamy — z powodem do śladu
 * (dropped_norm_claims); niczego nie dopisujemy.
 */
final class NormListSanity
{
    /** Prefiksy krajowe przed EN — ta sama norma. */
    private const NATIONAL = '(?:(?:PN|DIN|BS|SS|NF|UNI|UNE|NEN|SFS|CSN|STN|ÖNORM|ONORM)[\s\-]+)?';

    /** Rok wydania i poprawka zaraz za numerem normy („:2016”, „:2017-02”, „+A1:2018”, „ + A1:2009”). */
    private const EDITION = '(?:\s*[:\-]\s*(?:19|20)\d{2}(?:-\d{2})?)?(?:\s*\+\s*A\d{1,2}(?:\s*:\s*(?:19|20)\d{2})?)*';

    /** Kod EN 388: ścieranie 0–4, przecięcie Coup 0–5, rozdarcie 0–4, przekłucie 0–4, TDM A–F, uderzenie P. */
    private const EN388_CODE = '/(?<![\p{L}\d])([0-4X][0-5X][0-4X][0-4X](?:\s?[A-FX])?(?:\s?P)?)(?![\p{L}\d])/u';

    /** Oznaczenie normy: organ normalizacyjny i numer co najmniej dwucyfrowy („DIN 13157”, „ASTM F2675”). */
    private const DESIGNATION = '/(?<!\p{L})(?:EN|ISO|IEC|DIN|PN|BS|ANSI|ISEA|ASTM|NFPA|CSA|AS|NZS|GOST|GB|JIS|UL|CFR|FDA)(?![a-z])[\s\-\/A-Za-z]{0,12}?\d{2,}/u';

    /** Przywołanie rozporządzenia albo dyrektywy ŚOI — nie norma, ale też nie śmieć: zostaje jak dotąd. */
    private const REGULATION = '#\b(?:2016\s*/\s*425|89\s*/\s*686)\b|(?:rozporządzeni|dyrektyw|regulation|directive)\w*.{0,40}?\d{2,4}\s*/\s*\d{2,4}#iu';

    /** Nazwy organów i systemów, które same — bez numeru — niczego o wyrobie nie mówią. */
    private const BARE_BODIES = 'EN|ISO|PN|DIN|BS|ANSI|ISEA|ASTM|CSA|NFPA|OSHA|KOSHA|SCS|CE|UKCA|REACH';

    /** Symbole oznaczenia EN 166: klasa optyczna i numery zastosowań to cyfry, litery — F/B/A/S, T, K, N, R, H. */
    private const EN166_SYMBOL = '/^(?:\d.*|[FBASTKNRH]{1,4})$/u';

    /** Kod liter EN ISO 374-1:2016 (substancje A–P, S, T) — kolejne litery alfabetycznie. */
    private const CHEMICAL_LETTERS = '/(?<![\p{L}\d])([A-PST]{2,18})(?![\p{L}\d])/u';

    /** Krótkie wyrazy wielkimi literami, które w alfabetycznym porządku wyglądają jak kod liter, a nim nie są. */
    private const NOT_CHEMICAL_CODES = ['EN', 'DIN', 'BS', 'CE', 'DE', 'AT', 'BT', 'FT'];

    /**
     * @param  list<string>  $norms
     * @return array{norms: list<string>, certificates: list<string>, dropped: list<string>, fixed: list<string>}
     */
    public static function clean(array $norms): array
    {
        $kept = [];
        $certificates = [];
        $dropped = [];
        $fixed = [];
        foreach ($norms as $raw) {
            if (! is_string($raw)) {
                continue;
            }
            $item = trim((string) preg_replace('/\s+/u', ' ', $raw));
            if ($item === '') {
                continue;
            }
            if (PromptEcho::isEcho($item)) {
                $dropped[] = 'powtórzenie polecenia: '.$item;

                continue;
            }
            if (self::isSafetyCategory($item)) {
                $certificates[] = $item;
                $fixed[] = 'kategoria ŚOI przeniesiona do certyfikatów: '.$item;

                continue;
            }
            $iso388 = self::withoutIsoBefore388($item);
            if ($iso388 !== $item) {
                $fixed[] = '„EN ISO 388” to „EN 388”: '.$item.' → '.$iso388;
                $item = $iso388;
            }
            $reason = self::withoutDesignation($item)
                ?? self::en166MarkingWithoutSymbols($item)
                ?? self::cutLevelUnder13999($item)
                ?? self::chemicalTypeConflict($item);
            if ($reason !== null) {
                $dropped[] = $reason.': '.$item;

                continue;
            }
            $stripped = self::withoutForeignLevel($item);
            if ($stripped !== null) {
                $fixed[] = $stripped['reason'].': '.$item.' → '.$stripped['item'];
                $item = $stripped['item'];
            }
            $kept[] = $item;
        }

        return [
            // po poprawce „EN 388 15 gauge” → „EN 388” może powtórzyć inną pozycję; bez poprawek lista zostaje taka jak była
            'norms' => $fixed !== [] ? NormCode::dedupe($kept) : $kept,
            'certificates' => array_values(array_unique($certificates)),
            'dropped' => $dropped,
            'fixed' => $fixed,
        ];
    }

    /**
     * Powody odrzucenia zdania opisu: EN ISO 13999 z poziomem przecięcia, EN ISO 374-1 z typem sprzecznym z kodem
     * liter. Do SourceClaimGuard::filterSentences.
     *
     * @return list<string>
     */
    public static function sentenceProblems(string $sentence): array
    {
        $sentence = (string) preg_replace('/\s+/u', ' ', $sentence);
        $problems = [];
        foreach ([self::cutLevelUnder13999($sentence, 60), self::chemicalTypeConflict($sentence, 100)] as $problem) {
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * Tekst źródeł bez szablonowych pól sklepu „EN166 Lens Marking PrB420”: sama etykieta atrybutu z numerem normy,
     * bez symboli oznaczenia EN 166 w wartości, nie potwierdza normy (chipdip.ru przy okularach ekranowych ProBlu Bollé).
     * Wartość to pierwszy wyraz za etykietą, a gdy w wierszu jej nie ma — pierwszy wyraz następnej niepustej linii.
     * Etykieta z symbolem („EN166 Lens Marking 2C-1.2 1 FT”) zostaje.
     */
    public static function withoutTemplateAttributeRows(string $sourceText): string
    {
        $pattern = '/(?<![\p{L}\d])'.self::NATIONAL.'EN\s*166(?!\d)\s*[:\-]?\s*'
            .'(?:(?:lens|frame|ocular|filter)\s+)?(?:markings?|oznaczenie(?:\s+(?:soczewki|oprawki|na\s+soczewce|na\s+oprawce))?)'
            .'(?![\p{L}])[ \t]*[:=]?[ \t]*([^\s]*)/iu';

        return (string) preg_replace_callback($pattern, static function (array $m) use ($sourceText): string {
            $value = $m[1][0];
            if ($value === '') {
                $rest = substr($sourceText, $m[0][1] + strlen($m[0][0]));
                $value = preg_match('/^\s*\R\s*(\S+)/u', $rest, $next) === 1 ? $next[1] : '';
            }

            return preg_match(self::EN166_SYMBOL, trim($value, ',;.()')) === 1 ? $m[0][0] : ' ';
        }, $sourceText, -1, $count, PREG_OFFSET_CAPTURE);
    }

    /** „Kategoria 2”, „Kat. III”, „CE kat. II (ŚOI)”, „Kategoria 3: 0334” (z numerem jednostki) — kategoria ŚOI, nie norma. */
    private static function isSafetyCategory(string $item): bool
    {
        return preg_match(
            '/^(?:CE\s*)?(?:kategoria|kat\.?|category|cat\.?)\s*(?:ŚOI\s*|PPE\s*)?(?:I{1,3}|[1-3])(?![\p{L}\d])\s*(?:\(?\s*(?:ŚOI|PPE)\s*\)?)?(?:\s*[:(]\s*\d{4}\s*\)?)?\.?$/iu',
            $item
        ) === 1;
    }

    /**
     * Pozycja, która nie jest normą ani oznaczeniem: nazwa pustego pola sklepu „ATEX HAZARDOUS AREA / ATMOSPHERE GROUP”
     * (Bolle 23053), sama nazwa organu albo systemu bez numeru („SCS (Scientific Certification Systems)” — AJ 908,
     * „CSA”, „OSHA”, „EN (brak szczegółowych poziomów w źródle)”), „Klasa 2 (…)” bez normy (SECURA 185, 186).
     *
     * Celowo wąsko. Pomiar na produkcji (08.10.2026): z 872 pozycji pola norm bez numeru normy większość to prawdziwe
     * oznaczenia i normy spoza EN/ISO — „DGUV 112-191” (ELTEN, 397 kart), „Oznaczenie soczewki: 5-2.5 1 FT KN”
     * (Bollé), „SRC – odporność na poślizg”, „Typ 5”, „STANAG 2920”, „OEKO-TEX® STANDARD 100”, „EN ISO D” (poziom
     * przecięcia w zapisie Ansella) — te zostają.
     */
    private static function withoutDesignation(string $item): ?string
    {
        if (preg_match(self::DESIGNATION, $item) === 1 || preg_match(self::REGULATION, $item) === 1) {
            return null;
        }
        // „ATEX II 2G Ex h IIB T4” to oznakowanie; bez cyfr zostaje sama nazwa pola
        if (preg_match('/^ATEX\b/iu', $item) === 1 && preg_match('/\d/u', $item) !== 1) {
            return 'pozycja bez oznaczenia normy (ATEX bez oznakowania)';
        }
        // same nazwy organów / systemów, dalej tylko objaśnienie bez cyfr i bez litery poziomu
        if (preg_match('/^(?:'.self::BARE_BODIES.')(?:[\s\/\-–]+(?:'.self::BARE_BODIES.'))*(?![\p{L}\d])(.*)$/u', $item, $m) === 1
            && preg_match('/\d|(?<![\p{L}\d])[A-Z](?![\p{L}\d])/u', $m[1]) !== 1) {
            return 'pozycja bez oznaczenia normy (sama nazwa organu lub systemu)';
        }
        if (preg_match('/^(?:klasa|class|kl\.)\s*(?:00|[0-4]|I{1,3}|IV)(?![\p{L}\d])/iu', $item) === 1) {
            return 'pozycja bez oznaczenia normy (klasa bez normy)';
        }

        return null;
    }

    /** „EN ISO 388” nie istnieje — to EN 388 (AlphaTec 55302, 55305, 55307, 55308). */
    private static function withoutIsoBefore388(string $item): string
    {
        return (string) preg_replace('/(?<![\p{L}\d])EN[\s\-]*ISO[\s\-]*388(?!\d)/iu', 'EN 388', $item);
    }

    /** „EN 166 (oznaczenie na soczewce: PrB420)” — etykieta oznaczenia bez żadnego symbolu EN 166 w wartości. */
    private static function en166MarkingWithoutSymbols(string $item): ?string
    {
        if (preg_match('/(?<![\p{L}\d])EN\s*166(?!\d)/iu', $item) !== 1
            || preg_match('/\b(?:(?:lens|frame|ocular)\s+)?markings?\b|\boznaczeni(?:e|a|em)\b/iu', $item, $label, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $value = substr($item, $label[0][1] + strlen($label[0][0]));
        $value = (string) preg_replace('/^\s*(?:na\s+(?:soczewce|soczewkach|oprawce|oprawkach|szybie)|soczewki|oprawki)?\s*[:=]?/iu', '', $value);
        foreach (preg_split('/[\s,;()]+/u', $value) ?: [] as $token) {
            if ($token !== '' && preg_match(self::EN166_SYMBOL, $token) === 1) {
                return null;
            }
        }

        return 'EN 166 bez symboli oznaczenia (szablon pola sklepu)';
    }

    /**
     * EN ISO 13999 (rękawice kolcze) z literą poziomu przecięcia A–F albo poziomem ANSI A1–A9 — model pomylił normę
     * z badaniem TDM (EN ISO 13997 w EN 388:2016). Sama „EN ISO 13999-1:2006” bez poziomu zostaje.
     */
    private static function cutLevelUnder13999(string $text, int $window = 0): ?string
    {
        if (preg_match('/(?<!\d)13999(?:-\d{1,2})?(?:\s*:\s*(?:19|20)\d{2})?(?:\s*\+\s*A\d{1,2}(?:\s*:\s*(?:19|20)\d{2})?)*/u', $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $tail = substr($text, $m[0][1] + strlen($m[0][0]));
        if ($window > 0) {
            $tail = mb_substr($tail, 0, $window);
        }
        $letter = preg_match('/(?<![\p{L}\d°])[A-F]\+?(?![\p{L}\d])/u', $tail) === 1
            || preg_match('/(?<![\p{L}\d])A[1-9](?!\d)/u', $tail) === 1;

        return $letter ? 'EN ISO 13999 z poziomem przecięcia (poziom TDM należy do EN 388)' : null;
    }

    /**
     * EN ISO 374-1:2016: typ A = co najmniej 6 liter, typ B = 3–5 liter. Typu C nie sprawdzamy — jego piktogram
     * nie ma liter, a litery substancji z poziomem 1 podane obok nie przeczą typowi.
     */
    private static function chemicalTypeConflict(string $text, int $window = 0): ?string
    {
        if (preg_match('/(?<![\d\-])374-1(?![\d])(?:\s*:\s*(?:19|20)\d{2})?(?:\s*\+\s*A\d{1,2}(?:\s*:\s*(?:19|20)\d{2})?)*/u', $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $tail = substr($text, $m[0][1] + strlen($m[0][0]));
        if ($window > 0) {
            $tail = mb_substr($tail, 0, $window);
        }
        if (preg_match('/(?<!\p{L})(?i:typ|typu|type)\s*[:.]?\s*([ABC])(?![\p{L}\d])/u', $tail, $type) !== 1 || $type[1] === 'C') {
            return null;
        }
        $code = null;
        if (preg_match_all(self::CHEMICAL_LETTERS, $tail, $tokens) > 0) {
            foreach ($tokens[1] as $token) {
                if (! in_array($token, self::NOT_CHEMICAL_CODES, true) && self::strictlyAlphabetical($token)) {
                    $code = $token;
                    break;
                }
            }
        }
        if ($code === null) {
            return null;
        }
        $letters = strlen($code);
        $conflict = $type[1] === 'A' ? $letters < 6 : ($letters < 3 || $letters >= 6);

        return $conflict ? "EN ISO 374-1: typ {$type[1]} sprzeczny z kodem {$code} ({$letters} liter)" : null;
    }

    private static function strictlyAlphabetical(string $token): bool
    {
        for ($i = 1, $n = strlen($token); $i < $n; $i++) {
            if ($token[$i] <= $token[$i - 1]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Poziom, który nie należy do normy z pozycji — zdejmujemy go, norma zostaje:
     * - EN 388: gauge dzianiny („15 gauge”, „18G”), poziom ANSI (A1–A9, „ANSI”), liczba ≥ 6 („klasa 15”, „poziom 13”);
     *   prawidłowy kod EN 388 obok zostaje („EN 388 4X43D, 13 gauge” → „EN 388 4X43D”);
     * - EN 420 / EN ISO 21420: kod EN 388, poziom przecięcia A–F, poziom ANSI („EN 420 (klasa ochrony 4121B)” → „EN 420”).
     *
     * @return array{item: string, reason: string}|null
     */
    private static function withoutForeignLevel(string $item): ?array
    {
        if (preg_match('/^('.self::NATIONAL.'EN[\s\-]*388(?!\d)'.self::EDITION.')(.*)$/iu', $item, $m) === 1) {
            [$own, $rest] = self::ownTail($m[2]);
            $amendments = preg_match_all('/\+\s*A\d{1,2}(?:\s*:\s*(?:19|20)\d{2})?/u', $own, $a) > 0 ? $a[0] : [];
            $probe = (string) preg_replace('/\+\s*A\d{1,2}(?:\s*:\s*(?:19|20)\d{2})?/u', ' ', $own);
            $foreign = [];
            if (preg_match('/\d{1,2}\s*-?\s*(?:gauge|ga|gg|g)(?![\p{L}])|gauge|uiglen/iu', $probe) === 1) {
                $foreign[] = 'gauge';
            }
            if (preg_match('/\bANSI\b|(?<![\p{L}\d])A[1-9](?!\d)/u', $probe) === 1) {
                $foreign[] = 'poziom ANSI';
            }
            // liczba z jednostką („TDM 10 N”, „1,2 mm”) to wynik badania, nie poziom
            if (preg_match_all('/(?<![\p{L}\d.,:\-])(\d{1,2})(?![\d.,]|\s*(?:%|N\b|kN\b|mm\b|cm\b|J\b))/u', (string) preg_replace(self::EN388_CODE, ' ', $probe), $numbers) > 0
                && max(array_map('intval', $numbers[1])) >= 6) {
                $foreign[] = 'poziom spoza skali EN 388';
            }
            if ($foreign === []) {
                return null;
            }
            $code = preg_match(self::EN388_CODE, $probe, $c) === 1 ? ' '.$c[1] : '';
            $clean = trim(rtrim($m[1]).($amendments !== [] ? ' '.implode(' ', $amendments) : '').$code).$rest;

            return ['item' => $clean, 'reason' => 'EN 388 bez obcego poziomu ('.implode(', ', array_unique($foreign)).')'];
        }
        if (preg_match('/^('.self::NATIONAL.'EN[\s\-]*(?:ISO[\s\-]*)?(?:420|21420)(?!\d)'.self::EDITION.')(.*)$/iu', $item, $m) === 1) {
            [$own, $rest] = self::ownTail($m[2]);
            if (preg_match(self::EN388_CODE, $own) === 1
                || preg_match('/przecię\w*[^()]{0,24}?(?<![\p{L}\d])[A-F](?![\p{L}\d])|\bpoziom\w*\s+[A-F](?![\p{L}\d])/iu', $own) === 1
                || preg_match('/\bANSI\b|(?<![\p{L}\d])A[1-9](?!\d)/u', $own) === 1) {
                return ['item' => rtrim($m[1]).$rest, 'reason' => 'poziom EN 388 zdjęty z normy wymagań ogólnych'];
            }
        }

        return null;
    }

    /**
     * Część pozycji należąca do pierwszej normy — do następnej normy po przecinku albo średniku.
     *
     * @return array{0: string, 1: string}
     */
    private static function ownTail(string $tail): array
    {
        $parts = preg_split('/(?=[,;]\s*'.self::NATIONAL.'(?:EN|ISO|IEC|ANSI|ASTM|DIN)(?![a-z]))/u', $tail, 2) ?: [$tail];

        return [$parts[0], $parts[1] ?? ''];
    }
}
