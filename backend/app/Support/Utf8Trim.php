<?php

declare(strict_types=1);

namespace App\Support;

/**
 * trim() z listą, w której stoją znaki wielobajtowe: półpauza, pauza, polskie
 * cudzysłowy, punktor.
 *
 * trim() czyta listę bajt po bajcie: półpauzę (E2 80 93) bierze za trzy osobne
 * bajty i zdejmuje każdy z nich z brzegów napisu. Polski cudzysłów otwierający
 * (E2 80 9E) tracił wtedy E2 80, a „Ó” (C3 93) i cyrylickie „р” (D1 80) na końcu —
 * ostatni bajt; dalej szedł zepsuty UTF-8.
 *
 * Tu z brzegów schodzą tylko całe znaki z listy — ten sam zestaw co w trim(),
 * bez zakresów „a..z”. Wyrażenie porównuje całe sekwencje bajtów, bez flagi /u:
 * w poprawnym UTF-8 sekwencja znaku nie zaczyna się w środku innego znaku,
 * a napis z błędnym UTF-8 nie wywraca wyrażenia i jego bajty zostają nietknięte
 * (mb_trim z polyfillu Symfony kasuje je w całym napisie).
 */
final class Utf8Trim
{
    /** @var array<string, string> */
    private static array $patterns = [];

    public static function trim(string $text, string $characters): string
    {
        $pattern = self::$patterns[$characters] ??= self::pattern($characters);

        return preg_replace($pattern, '', $text) ?? $text;
    }

    private static function pattern(string $characters): string
    {
        $listed = implode('|', array_map(
            static fn (string $char): string => preg_quote($char, '/'),
            mb_str_split($characters, 1, 'UTF-8'),
        ));

        // \z, nie $: $ pasuje też przed końcowym \n, a trim() zatrzymuje się na znaku spoza listy.
        // (?<!…): koniec próbujemy tylko od początku ciągu znaków z listy — inaczej długi ciąg
        // w środku napisu (spacje, kropki) byłby przechodzony od każdej swojej pozycji.
        return '/\A(?:'.$listed.')++|(?<!'.$listed.')(?:'.$listed.')++\z/';
    }
}
