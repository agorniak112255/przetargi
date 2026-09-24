<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Opis wyrobu do listu z ofertą — w trzech szablonach pozycja wygląda inaczej.
 *
 * Cały tekst pochodzi z karty wyrobu (`products.description`), więc nic tu nie
 * jest zmyślane: wybieramy z niego fragment i wycinamy to, czego w danym
 * szablonie klient nie ma widzieć. Gdy karta opisu nie ma, metody zwracają
 * null — list bierze wtedy słowa klienta z zapytania, a nie nasz wymysł.
 *
 * Skąd cięcie sekcji: opisy kart bywają zlepkiem prozy i bloków przeniesionych
 * ze strony dostawcy („NORMY I CERTYFIKATY:”, potem linie „10 par/worek”).
 * Do listu wchodzi sama proza — normy stoją w liście w osobnej linii, a dane
 * opakowań nie są opisem wyrobu.
 *
 * Dopisek o wycofaniu (WithdrawnProductNote) też nie wchodzi: to stan karty
 * dla handlowca, nie opis wyrobu. Panel zapytania pokazuje go przy pozycji,
 * a to, czy oferować wyrób wycofany albo następcę, rozstrzyga handlowiec.
 */
final class OfferProductText
{
    /** Krótszy tekst nie jest opisem — ten sam próg, którego pilnuje karta wyrobu. */
    private const MIN_CHARS = 24;

    /** Ile znaków opisu wchodzi do szablonu oficjalnego. */
    public const PARAGRAPH_LIMIT = 1200;

    /** Ile znaków wchodzi do szablonu bez SKU — tam ma być jedno zdanie. */
    public const LEAD_LIMIT = 300;

    /** Słowa, po których w opisie stoi nazwa producenta („marki MAPA”). */
    private const BRAND_LEAD_INS = ['marki', 'marka', 'firmy', 'firma', 'producenta', 'modelu', 'model'];

    /** Formy prawne i dopiski w nazwie producenta — nie są nazwą do wycinania. */
    private const LEGAL_WORDS = ['sp', 'spółka', 'spolka', 'zoo', 'akcyjna', 'poland', 'polska', 'group',
        'gmbh', 'ltd', 'inc', 'llc', 'corp', 'holding', 'company', 'international'];

    /**
     * Proza z opisu karty: bez bloków przepisanych ze strony dostawcy
     * i bez złamań wiersza, żeby w liście wyszedł jeden akapit.
     */
    public static function prose(string $description): string
    {
        $lines = preg_split('/\R/u', trim(WithdrawnProductNote::strip($description))) ?: [];

        $kept = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (self::isSectionHeader($trimmed)) {
                break;
            }
            $kept[] = $trimmed;
        }

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $kept)));
    }

    /**
     * Cały akapit opisu do szablonu oficjalnego, przycięty na końcu zdania.
     * Null, gdy karta nie ma z czego zrobić opisu.
     */
    public static function paragraph(string $description, int $limit = self::PARAGRAPH_LIMIT): ?string
    {
        $prose = self::prose($description);
        if (mb_strlen($prose) < self::MIN_CHARS) {
            return null;
        }

        return self::shorten($prose, $limit);
    }

    /**
     * Jedno zdanie opisu bez marki i oznaczenia modelu — szablon „bez SKU”.
     *
     * Null, gdy po wycięciu nazw nie zostaje zdanie, które cokolwiek mówi,
     * albo gdy marka lub model zostałyby w tekście mimo wycinania. Lepiej
     * oddać null i pozwolić listowi użyć słów klienta, niż wysłać do klienta
     * ogryzek zdania albo model, który miał zostać u nas.
     */
    public static function genericLead(
        string $description,
        string $manufacturer = '',
        ?string $model = null,
        ?string $name = null,
        int $limit = self::LEAD_LIMIT,
    ): ?string {
        $prose = self::prose($description);
        if ($prose === '') {
            return null;
        }

        $hidden = self::hiddenNames($manufacturer, $model, $name);
        // Najpierw całe nazwy („VITAL 175”), potem ich pojedyncze wyrazy —
        // producent bywa w karcie zapisany inaczej niż w opisie („3M Peltor X”
        // wobec „3M Peltor”) i samo wycięcie całości by go nie ruszyło.
        $words = self::hiddenWords($hidden);
        $sentence = self::withoutNames(self::firstSentence($prose), [...$hidden, ...$words]);
        if (mb_strlen($sentence) < self::MIN_CHARS) {
            return null;
        }
        foreach ([...$hidden, ...$words] as $needle) {
            if (mb_stripos($sentence, $needle) !== false) {
                return null;
            }
        }

        return self::shorten($sentence, $limit);
    }

    /**
     * Nazwy, które w szablonie bez SKU nie mogą pójść do klienta: producent,
     * oznaczenie modelu i nazwa karty, gdy sama jest oznaczeniem („VITAL 175”).
     *
     * @return list<string>
     */
    private static function hiddenNames(string $manufacturer, ?string $model, ?string $name): array
    {
        $names = [trim($manufacturer), trim((string) $model)];

        $card = trim((string) $name);
        if ($card !== '' && self::looksLikeDesignation($card)) {
            $names[] = $card;
        }

        // Jednoliterowe „nazwy” wycięłyby pół zdania — do wycinania biorą się
        // tylko takie, które da się rozpoznać w tekście.
        return array_values(array_unique(array_filter(
            $names,
            static fn (string $value): bool => mb_strlen($value) >= 2,
        )));
    }

    /**
     * Pojedyncze wyrazy z ukrywanych nazw. Liczby same w sobie zostają: „175”
     * wycięte z „Długość 175 cm” zepsułoby zdanie, a nazwa w całości i tak
     * została już usunięta.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function hiddenWords(array $names): array
    {
        $words = [];
        foreach ($names as $name) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $name) ?: [] as $word) {
                if (mb_strlen($word) < 2 || preg_match('/\p{L}/u', $word) !== 1) {
                    continue;
                }
                if (in_array(mb_strtolower($word, 'UTF-8'), self::LEGAL_WORDS, true)) {
                    continue;
                }
                $words[] = $word;
            }
        }

        return array_values(array_unique($words));
    }

    /**
     * Nazwa karty jest oznaczeniem wyrobu, a nie jego opisem: krótka i albo
     * z liczbą („VITAL 175”), albo pisana wielkimi literami („SOLIS FLEX”).
     * Długie nazwy opisowe („Gogle autoklawowalne wielokrotnego użytku…”)
     * zostają — nie ma w nich czego ukrywać.
     */
    private static function looksLikeDesignation(string $name): bool
    {
        if (mb_strlen($name) > 40) {
            return false;
        }
        if (preg_match('/\d/u', $name) === 1) {
            return true;
        }

        $words = preg_split('/\s+/u', $name) ?: [];

        return count($words) <= 3 && mb_strtoupper($name, 'UTF-8') === $name;
    }

    /**
     * @param  list<string>  $names
     */
    private static function withoutNames(string $text, array $names): string
    {
        $out = $text;
        foreach ($names as $name) {
            $quoted = preg_quote($name, '/');
            // Najpierw z wyrazem wprowadzającym („marki MAPA”), żeby nie zostało
            // samotne „marki”, potem sama nazwa.
            $out = (string) preg_replace(
                '/\b(?:'.implode('|', self::BRAND_LEAD_INS).')\s+'.$quoted.'\b/iu',
                ' ',
                $out,
            );
            $out = (string) preg_replace('/\b'.$quoted.'\b/iu', ' ', $out);
        }

        return self::tidy($out);
    }

    /** Porządki po wycinaniu: podwójne spacje, spacje przed przecinkiem, sieroty. */
    private static function tidy(string $text): string
    {
        $out = (string) preg_replace('/\s+/u', ' ', $text);
        $out = (string) preg_replace('/\s+([,.;:])/u', '$1', $out);
        $out = (string) preg_replace('/([,;:])\s*(?=[,.;:])/u', '', $out);
        $out = trim($out, " \t\n\r,;:-–—");

        if ($out === '') {
            return '';
        }

        // Po wycięciu nazwy z początku zdania zostaje mała litera.
        return mb_strtoupper(mb_substr($out, 0, 1), 'UTF-8').mb_substr($out, 1);
    }

    private static function firstSentence(string $prose): string
    {
        if (preg_match('/^.*?[.!?](?=\s|$)/u', $prose, $found) === 1) {
            return trim($found[0]);
        }

        return $prose;
    }

    /** Przycięcie na końcu zdania, a gdy takiego nie ma — na granicy wyrazu. */
    private static function shorten(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        $end = self::lastSentenceEnd($cut, (int) ($limit / 2));
        if ($end !== null) {
            return trim(mb_substr($cut, 0, $end + 1));
        }

        $space = mb_strrpos($cut, ' ');

        return trim($space === false ? $cut : mb_substr($cut, 0, $space)).'…';
    }

    /**
     * Koniec ostatniego pełnego zdania w tekście, nie bliżej początku niż $min.
     *
     * Kropka kończy zdanie tylko wtedy, gdy stoi przed spacją albo na końcu, i gdy
     * nie jest w środku kodu wyrobu ani w niezamkniętym nawiasie. Bez tego opis
     * urywał się w połowie zapisu „(8543.8.” i tak szedł do klienta.
     */
    private static function lastSentenceEnd(string $text, int $min): ?int
    {
        if (preg_match_all('/[.!?](?=\s|$)/u', $text, $m, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }
        $found = null;
        foreach ($m[0] ?? [] as $hit) {
            // offset z PREG_OFFSET_CAPTURE jest bajtowy, a tniemy po znakach
            $at = mb_strlen(substr($text, 0, (int) $hit[1]));
            if ($at < $min) {
                continue;
            }
            $before = mb_substr($text, 0, $at);
            if (mb_substr_count($before, '(') > mb_substr_count($before, ')')) {
                continue;
            }
            $found = $at;
        }

        return $found;
    }

    private static function isSectionHeader(string $line): bool
    {
        if ($line === '' || ! str_ends_with($line, ':')) {
            return false;
        }
        if (preg_match_all('/\p{L}/u', $line) < 3) {
            return false;
        }

        return mb_strtoupper($line, 'UTF-8') === $line;
    }
}
