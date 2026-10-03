<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Numer ogłoszenia przetargu — Biuletyn Zamówień Publicznych albo TED (Dziennik Urzędowy UE).
 *
 * BZP: „2026/BZP 00431178/01” (wersja „/01” opcjonalna); `bzp_number` = numer bez wersji, tak jak pole
 * `bzpNumber` w API Biuletynu („2026/BZP 00431178”). TED: „606345-2026” albo stary zapis „2026/S 187-606345”
 * → „606345-2026”. Normalizowane są tylko spacje i wielkość liter (plus zera wiodące numeru TED, które nie
 * zmieniają numeru); każdy inny zapis → null, bez zgadywania.
 */
final class NoticeNumber
{
    public const SOURCE_BZP = 'bzp';

    public const SOURCE_TED = 'ted';

    /**
     * @return array{source: 'bzp'|'ted', normalized: string, bzp_number: ?string}|null
     */
    public static function parse(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }
        // twarda spacja z kopiowania ze strony Biuletynu i zwykłe odstępy → bez odstępów, wielkie litery
        $compact = mb_strtoupper((string) preg_replace('/[\s\x{00A0}]+/u', '', $value));
        if ($compact === '') {
            return null;
        }

        if (preg_match('~^(\d{4})/BZP(\d{8})(?:/(\d{2}))?$~', $compact, $m) === 1) {
            $bzpNumber = $m[1].'/BZP '.$m[2];
            $version = $m[3] ?? '';

            return [
                'source' => self::SOURCE_BZP,
                'normalized' => $version !== '' ? $bzpNumber.'/'.$version : $bzpNumber,
                'bzp_number' => $bzpNumber,
            ];
        }

        if (preg_match('~^(\d{1,9})-(\d{4})$~', $compact, $m) === 1) {
            return self::ted($m[1], $m[2]);
        }

        if (preg_match('~^(\d{4})/S\d{1,3}-(\d{1,9})$~', $compact, $m) === 1) {
            return self::ted($m[2], $m[1]);
        }

        return null;
    }

    public static function source(?string $value): ?string
    {
        return self::parse($value)['source'] ?? null;
    }

    /**
     * @return array{source: 'ted', normalized: string, bzp_number: null}|null
     */
    private static function ted(string $number, string $year): ?array
    {
        $number = ltrim($number, '0');
        if ($number === '') {
            return null;
        }

        return [
            'source' => self::SOURCE_TED,
            'normalized' => $number.'-'.$year,
            'bzp_number' => null,
        ];
    }
}
