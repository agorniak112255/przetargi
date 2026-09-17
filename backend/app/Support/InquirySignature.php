<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Dane kontaktowe wyjęte ze stopki maila.
 *
 * Stopka i tak jest odcinana przed analizą (`InquiryMailText::footerOf`),
 * więc zamiast ją wyrzucać, zapisujemy z niej kontakt — razem z surowym
 * blokiem w polu `raw`, żeby każdą wartość dało się sprawdzić w źródle.
 *
 * Zasada nadrzędna: tylko to, co stoi w tekście. Żadnego zgadywania,
 * żadnego uzupełniania, żadnego normalizowania numerów telefonu. Gdy nie ma
 * pewności, czy coś jest nazwiskiem, czy nazwą firmy — zostaje null.
 */
final class InquirySignature
{
    /** Zwrot grzecznościowy — zaraz pod nim (albo za przecinkiem) stoi podpis. */
    private const CLOSING = '/^(pozdrawiam|pozdrowienia|z\s+pozdrowieniami|z\s+powa[żz]aniem|z\s+wyrazami\s+szacunku|[łl][ąa]cz[ęe]\s+wyrazy\s+szacunku|serdecznie\s+pozdrawiam|(best|kind|warm)\s+regards|regards|sincerely)\b/iu';

    /** Forma prawna — najpewniejszy sygnał, że linia albo jej część to firma. */
    private const COMPANY_LEGAL = '/(sp[óo][łl]ka\s+z\s+o|sp\.\s*z\s*o\.?\s*o|sp[óo][łl]ka\s+akcyjna|s\.\s*a\.|sp\.\s*j\.|sp\.\s*k\.|s\.\s*c\.|p\.\s*p\.\s*h\.\s*u|z\.\s*p\.\s*ch|\bgmbh\b|\bltd\b|\bs\.\s*r\.\s*o\b)/iu';

    /** Początek adresu ulicznego. */
    private const STREET = '/^(ul\.|ulica|al\.|aleja|aleje|os\.|osiedle|pl\.|plac)\s/iu';

    /** Kod pocztowy — kotwica, po której poznajemy linię z adresem. */
    private const POSTAL = '/\b\d{2}-\d{3}\b/u';

    /** Etykieta numeru — segment z nią nie jest częścią adresu. */
    private const PHONE_LABEL = '/\b(tel|telefon|tel\/fax|kom|mob|mobile|fax|faks|phone)\b/iu';

    /** Numery, których nie wolno wziąć za telefon. */
    private const NOT_A_PHONE_LINE = '/\b(nip|regon|krs|iban|konto|rachunek|swift|bdo)\b/iu';

    private const EMAIL_BODY = '[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}';

    private const EMAIL = '/'.self::EMAIL_BODY.'/u';

    private const WEBSITE = '/(?<![@\w.\-])((?:https?:\/\/|www\.)[A-Za-z0-9.\-]+\.[A-Za-z]{2,}(?:\/[^\s<>,;"]*)?)/iu';

    /** Kandydat na numer telefonu — cyfry, spacje, myślniki i nawiasy. */
    private const PHONE_CANDIDATE = '/(?<![\d\-\/])(\+?\(?\d[\d\s\-()]{6,17}\d)(?![\d\-\/])/u';

    /**
     * Kontakt ze stopki maila albo null, gdy stopki nie ma lub nic z niej nie wynika.
     *
     * `$fromEmail` (adres z nagłówka From) służy wyłącznie do ustawienia
     * kolejności — jeśli ten sam adres jest w stopce, trafia na początek listy.
     * Nigdy nie jest do niej dopisywany: w `contact` ma być tylko to, co widać
     * w `raw`.
     *
     * @return array{person: string|null, company: string|null, emails: list<string>, phones: list<string>, address: string|null, website: string|null, raw: string}|null
     */
    public static function extract(string $rawBody, ?string $fromEmail = null): ?array
    {
        $footer = InquiryMailText::footerOf($rawBody);
        if ($footer === '') {
            return null;
        }

        $lines = explode("\n", $footer);

        $emails = self::emails($footer, $fromEmail);
        $phones = self::phones($lines);
        $person = self::person($lines);
        $company = self::company($lines, $person);
        $address = self::address($lines, $phones);
        $website = self::website($footer);

        $nothing = $person === null
            && $company === null
            && $address === null
            && $website === null
            && $emails === []
            && $phones === [];

        if ($nothing) {
            return null;
        }

        return [
            'person' => $person,
            'company' => $company,
            'emails' => $emails,
            'phones' => $phones,
            'address' => $address,
            'website' => $website,
            'raw' => $footer,
        ];
    }

    /**
     * Rozbicie nagłówka From na nazwę i adres.
     *
     * Obsługuje `Jan Kowalski <jan@firma.pl>`, sam adres, adres w cudzysłowie
     * oraz nazwę zakodowaną wg RFC 2047 (`=?UTF-8?B?...?=`).
     *
     * @return array{name: string|null, email: string|null}
     */
    public static function splitFrom(?string $from): array
    {
        $value = trim((string) $from);
        if ($value === '') {
            return ['name' => null, 'email' => null];
        }

        if (str_contains($value, '=?')) {
            $decoded = mb_decode_mimeheader($value);
            if ($decoded !== '') {
                $value = trim($decoded);
            }
        }

        $email = null;
        $name = $value;

        if (preg_match('/<\s*('.self::EMAIL_BODY.')\s*>/u', $value, $m) === 1) {
            $email = $m[1];
            $name = trim(str_replace($m[0], '', $value));
        } elseif (preg_match(self::EMAIL, $value, $m) === 1) {
            $email = $m[0];
            $name = trim(str_replace($m[0], '', $value));
        }

        $name = trim($name, " \t\"'<>,;");
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        // Sama kopia adresu w polu nazwy nie jest nazwą nadawcy.
        if ($name === '' || ($email !== null && mb_strtolower($name) === mb_strtolower($email))) {
            $name = null;
        }

        return [
            'name' => $name === null ? null : mb_substr($name, 0, 200),
            'email' => $email === null ? null : mb_substr($email, 0, 320),
        ];
    }

    /**
     * @return list<string>
     */
    private static function emails(string $footer, ?string $fromEmail): array
    {
        preg_match_all(self::EMAIL, $footer, $matches);

        $found = [];
        foreach ($matches[0] as $email) {
            $key = mb_strtolower($email);
            $found[$key] ??= $email;
        }

        $emails = array_values($found);

        // Adres nadawcy na początek — ale tylko wtedy, gdy naprawdę jest w stopce.
        $sender = mb_strtolower(trim((string) $fromEmail));
        if ($sender !== '' && isset($found[$sender])) {
            $emails = array_values(array_filter(
                $emails,
                static fn (string $email): bool => mb_strtolower($email) !== $sender,
            ));
            array_unshift($emails, $found[$sender]);
        }

        return $emails;
    }

    /**
     * Numery zapisane dokładnie tak, jak stoją w mailu — normalizacja mogłaby
     * zmienić dane źródłowe.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function phones(array $lines): array
    {
        $phones = [];
        foreach ($lines as $line) {
            // NIP, REGON, numer konta — to też ciągi cyfr, ale nie telefony.
            if (preg_match(self::NOT_A_PHONE_LINE, $line) === 1) {
                continue;
            }
            preg_match_all(self::PHONE_CANDIDATE, $line, $matches);
            foreach ($matches[1] as $candidate) {
                $number = trim($candidate);
                if (! self::looksLikePhone($number)) {
                    continue;
                }
                $key = preg_replace('/\D+/u', '', $number) ?? $number;
                $phones[$key] ??= $number;
            }
        }

        return array_values($phones);
    }

    /** Polski numer to 9 cyfr, ewentualnie z kierunkowym kraju. */
    private static function looksLikePhone(string $candidate): bool
    {
        $digits = preg_replace('/\D+/u', '', $candidate) ?? '';
        $length = strlen($digits);

        if ($length === 9) {
            return true;
        }

        return $length === 11 && str_starts_with($digits, '48') && str_contains($candidate, '+');
    }

    /**
     * Podpis stoi pod zwrotem grzecznościowym albo zaraz za przecinkiem w tej
     * samej linii. Wszystko inne to zgadywanie, więc zwracamy null.
     *
     * @param  list<string>  $lines
     */
    private static function person(array $lines): ?string
    {
        $candidate = null;

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || preg_match(self::CLOSING, $trimmed) !== 1) {
                continue;
            }

            $rest = trim((string) preg_replace(self::CLOSING, '', $trimmed));
            $rest = trim($rest, " \t,.;!-");
            if ($rest !== '') {
                $candidate = $rest;
                break;
            }

            for ($j = $i + 1; $j < count($lines); $j++) {
                if (trim($lines[$j]) !== '') {
                    $candidate = trim($lines[$j]);
                    break;
                }
            }
            break;
        }

        return $candidate === null ? null : self::personName($candidate);
    }

    /** Dwa albo trzy człony pisane wielką literą, bez cyfr, adresu i formy prawnej. */
    private static function personName(string $candidate): ?string
    {
        $name = trim($candidate, " \t,.;");
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        if ($name === '' || mb_strlen($name) > 60) {
            return null;
        }
        if (preg_match('/[0-9@|\/:<>]/u', $name) === 1) {
            return null;
        }
        if (preg_match(self::COMPANY_LEGAL, $name) === 1 || preg_match(self::STREET, $name) === 1) {
            return null;
        }

        $words = explode(' ', $name);
        if (count($words) < 2 || count($words) > 3) {
            return null;
        }
        foreach ($words as $word) {
            if (preg_match('/^\p{Lu}[\p{L}\'\-.]*$/u', $word) !== 1) {
                return null;
            }
        }

        return $name;
    }

    /**
     * Firma: linia po znaku „|” (tak zapisują ją stopki firmowe) albo segment
     * z formą prawną. Gołej nazwy własnej nie zgadujemy.
     *
     * @param  list<string>  $lines
     */
    private static function company(array $lines, ?string $person): ?string
    {
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^\|\s*(\S.*)$/u', $trimmed, $m) === 1) {
                $name = self::tidy(trim($m[1], " \t|,;"));
                if ($name !== '' && $name !== $person) {
                    return $name;
                }
            }
        }

        foreach ($lines as $line) {
            foreach (explode(',', $line) as $segment) {
                $name = self::tidy(trim($segment, " \t|;"));
                if ($name === '' || $name === $person) {
                    continue;
                }
                if (preg_match(self::COMPANY_LEGAL, $name) === 1) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * Adres z linii zawierającej kod pocztowy. Z linii wypadają segmenty
     * z telefonem i z nazwą firmy; ulica z linii wyżej zostaje dołączona.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $phones
     */
    private static function address(array $lines, array $phones): ?string
    {
        foreach ($lines as $i => $line) {
            if (preg_match(self::POSTAL, $line) !== 1) {
                continue;
            }

            $kept = [];
            foreach (explode(',', $line) as $segment) {
                $part = self::tidy(trim($segment, " \t|;"));
                if ($part === '') {
                    continue;
                }
                if (preg_match(self::POSTAL, $part) !== 1) {
                    if (preg_match(self::PHONE_LABEL, $part) === 1 || in_array($part, $phones, true)) {
                        continue;
                    }
                    if (preg_match(self::COMPANY_LEGAL, $part) === 1 || preg_match(self::EMAIL, $part) === 1) {
                        continue;
                    }
                }
                $kept[] = $part;
            }

            if ($kept === []) {
                continue;
            }

            // „ul. Przemysłowa 12” w linii wyżej należy do tego samego adresu.
            if (preg_match(self::STREET, $kept[0]) !== 1) {
                for ($j = $i - 1; $j >= 0; $j--) {
                    $previous = self::tidy(trim($lines[$j], " \t|;,"));
                    if ($previous === '') {
                        continue;
                    }
                    if (preg_match(self::STREET, $previous) === 1) {
                        array_unshift($kept, $previous);
                    }
                    break;
                }
            }

            return mb_substr(implode(', ', $kept), 0, 400);
        }

        return null;
    }

    private static function website(string $footer): ?string
    {
        if (preg_match(self::WEBSITE, $footer, $m) !== 1) {
            return null;
        }

        return rtrim($m[1], '.,;');
    }

    /** Wielokrotne spacje z maila zjadają czytelność, ale nie zmieniają treści. */
    private static function tidy(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
