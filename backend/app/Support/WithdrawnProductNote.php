<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Dopisek o wycofaniu wyrobu w opisie karty — jeden format dla zapisu i odczytu.
 *
 * Witryna producenta (dziś tylko PROTEKT, ProtektB2bConnector::description) oznacza wyrób etykietą
 * „Wycofany” i zdaniem „zastąpiony przez …”. Łącznik nie ma na to osobnego pola: surowe dane strony
 * nie są nigdzie zapisywane, więc jedynym trwałym śladem jest pierwsza linia opisu:
 *
 *     UWAGA: produkt wycofany przez producenta — Wycofany — zastąpiony przezBW100.
 *
 * Tekst po prefiksie jest dosłownie ze strony — łącznie z „przezBW100” bez spacji (link na stronie
 * stoi tuż za słowem). Dlatego dane zostają, jakie są, a następcę odczytujemy tu, przy wyświetlaniu.
 */
final class WithdrawnProductNote
{
    public const PREFIX = 'UWAGA: produkt wycofany przez producenta — ';

    /** Linia opisu z dopiskiem; $sourceNote dosłownie ze strony producenta. */
    public static function forDescription(string $sourceNote): string
    {
        return self::PREFIX.$sourceNote.'.';
    }

    /**
     * Wycofanie zapisane w opisie karty; null, gdy opis go nie ma.
     *
     * successor — nazwa następcy, gdy strona ją podała („BW100”, „PROTON 100”), inaczej null.
     * Nazwa to tekst linku ze strony, nie kod karty w naszym katalogu — nie musi się z nim zgadzać.
     *
     * @return array{successor: ?string}|null
     */
    public static function parse(?string $description): ?array
    {
        $line = self::noteLine((string) $description);
        if ($line === null) {
            return null;
        }

        $note = rtrim(mb_substr($line, mb_strlen(self::PREFIX)), ". \t");
        $successor = null;
        if (preg_match('/zastąpiony\s+przez\s*(\S.*)$/iu', $note, $found) === 1) {
            $successor = trim($found[1]);
        }

        return ['successor' => $successor !== '' ? $successor : null];
    }

    /** Opis bez linii o wycofaniu — reszta tekstu bez zmian. */
    public static function strip(string $description): string
    {
        $lines = preg_split('/\R/u', $description) ?: [];
        $kept = array_filter($lines, static fn (string $line): bool => ! self::isNoteLine($line));

        return count($kept) === count($lines) ? $description : trim(implode("\n", $kept));
    }

    private static function noteLine(string $description): ?string
    {
        if (! str_contains($description, self::PREFIX)) {
            return null;
        }
        foreach (preg_split('/\R/u', $description) ?: [] as $line) {
            if (self::isNoteLine($line)) {
                return trim($line);
            }
        }

        return null;
    }

    private static function isNoteLine(string $line): bool
    {
        return str_starts_with(ltrim($line), self::PREFIX);
    }
}
