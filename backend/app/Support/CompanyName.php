<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ujednolicanie firm konkurencji z wyników przetargów.
 *
 * key(): klucz porównania nazwy — małe litery, bez polskich znaków, cudzysłowów, interpunkcji i form prawnych
 * („sp. z o.o.”, „S.A.”, „sp.k.”, „sp.j.”, „s.c.”, „spółka …”). Służy tylko do łączenia zapisów tej samej firmy,
 * nigdy do wyświetlania (nazwa firmy zostaje taka, jak w źródle).
 *
 * nip(): NIP z dowolnego zapisu („NIP: 118-16-25-269”, „PL1181625269”) — 10 cyfr z poprawną sumą kontrolną,
 * inaczej null (surową wartość przechowuje wywołujący).
 */
final class CompanyName
{
    private const KEY_MAX_LENGTH = 191;

    private const NIP_WEIGHTS = [6, 5, 7, 2, 3, 4, 5, 6, 7];

    private const TRANSLITERATION = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'í' => 'i', 'ú' => 'u',
        'č' => 'c', 'ř' => 'r', 'š' => 's', 'ž' => 'z', 'ý' => 'y', 'ě' => 'e', 'ů' => 'u',
    ];

    /**
     * Formy prawne zaczynające się od „sp”/„spolka” — jednoznaczne, usuwane w dowolnym miejscu nazwy.
     * Kolejność: dłuższe przed krótszymi.
     */
    private const LEGAL_FORMS_ANYWHERE = [
        'spolka z ograniczona odpowiedzialnoscia',
        'spolka z o o',
        'spolka z oo',
        'spolka komandytowo akcyjna',
        'prosta spolka akcyjna',
        'spolka akcyjna',
        'spolka komandytowa',
        'spolka jawna',
        'spolka cywilna',
        'spolka partnerska',
        'sp z o o',
        'sp z oo',
        'sp zoo',
        'sp k a',
        'sp k',
        'sp j',
        'sp p',
        'spk',
        'spj',
        'spzoo',
    ];

    /**
     * Krótkie formy, które mogą być też zwykłym słowem nazwy („SC Johnson”) — usuwane tylko na końcu nazwy.
     */
    private const LEGAL_FORMS_AT_END = [
        'p s a',
        's k a',
        's a',
        's c',
        'psa',
        'ska',
        'sa',
        'sc',
        'spolka',
        'sp',
    ];

    public static function key(?string $name): string
    {
        $text = self::plain($name);
        if ($text === '') {
            return '';
        }

        $stripped = $text;
        do {
            $before = $stripped;
            $padded = ' '.$stripped.' ';
            foreach (self::LEGAL_FORMS_ANYWHERE as $form) {
                $padded = str_replace(' '.$form.' ', ' ', $padded);
            }
            $stripped = trim((string) preg_replace('/ +/', ' ', $padded));
            foreach (self::LEGAL_FORMS_AT_END as $form) {
                if ($stripped !== $form && str_ends_with($stripped, ' '.$form)) {
                    $stripped = rtrim(substr($stripped, 0, -strlen($form)));
                }
            }
        } while ($stripped !== $before);

        // nazwa złożona z samej formy prawnej — lepszy klucz z całości niż pusty
        $key = $stripped !== '' ? $stripped : $text;

        return mb_substr($key, 0, self::KEY_MAX_LENGTH);
    }

    public static function nip(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $all = (string) preg_replace('/\D+/', '', $value);
        if (self::validNip($all)) {
            return $all;
        }

        // kilka numerów w jednym polu („NIP: …, REGON: …”) — pierwszy ciąg cyfr, który jest poprawnym NIP-em
        if (preg_match_all('/\d[\d\s.\-]*\d/', $value, $matches) > 0) {
            foreach ($matches[0] as $candidate) {
                $digits = (string) preg_replace('/\D+/', '', $candidate);
                if (self::validNip($digits)) {
                    return $digits;
                }
            }
        }

        return null;
    }

    public static function validNip(string $digits): bool
    {
        if (preg_match('/^\d{10}$/', $digits) !== 1 || $digits === '0000000000') {
            return false;
        }
        $sum = 0;
        foreach (self::NIP_WEIGHTS as $i => $weight) {
            $sum += $weight * (int) $digits[$i];
        }
        $check = $sum % 11;

        return $check !== 10 && $check === (int) $digits[9];
    }

    /**
     * Małe litery, bez znaków diakrytycznych, interpunkcji i cudzysłowów, pojedyncze spacje.
     */
    private static function plain(?string $name): string
    {
        if ($name === null) {
            return '';
        }
        $text = mb_strtolower($name, 'UTF-8');
        $text = strtr($text, self::TRANSLITERATION);
        // „&” zostaje słowem, żeby „A&B” i „A & B” dały ten sam klucz
        $text = str_replace('&', ' & ', $text);
        $text = (string) preg_replace('/[^a-z0-9&]+/u', ' ', $text);

        return trim((string) preg_replace('/ +/', ' ', $text));
    }
}
