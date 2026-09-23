<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Czy nazwa karty w katalogu nazywa ten sam wyrób co nazwa u dostawcy B2B (link.remote_name).
 *
 * 23.09.2026: 173 karty Bollé mają nazwę innego wyrobu (FLASHV „FLASH – Welding helmet” nazwana „Napotnik do przyłbic
 * ELECTRO i ELECTRO+ (Pakiet 5 szt.)”, TRACPSF „TRACKER – Smoke safety glasses” nazwana „XP”). Taka nazwa podana
 * modelowi jako kontekst psuła tłumaczenie („Welding helmet” → „Napotnik do przyłbic”).
 *
 * Ten sam wyrób, gdy nazwa karty: jest identyczna z nazwą u dostawcy, zawiera SKU karty (całe słowo) albo ma z nazwą
 * u dostawcy wspólne oznaczenie modelu — słowo (ciąg liter, cyfr i „+”) co najmniej 3-znakowe, z cyfrą albo pisane
 * u dostawcy w całości wielkimi literami (RUSH+), poza GENERIC_WORDS; porównanie bez wielkości liter.
 * Brak nazwy u dostawcy = nie wiadomo, więc nie.
 */
final class B2bProductNameMatch
{
    /** Słowa wielkimi literami w nazwie u dostawcy, które nie są oznaczeniem modelu — wspólne nic nie wiążą. */
    public const GENERIC_WORDS = [
        'PACK', 'PIECES', 'SAFETY', 'GLASSES', 'GOGGLE', 'GOGGLES', 'CLEAR', 'SMOKE', 'ECO', 'THE', 'AND', 'WITH',
        'KIT', 'SIZE', 'FOR', 'LENS', 'LENSES', 'FRAME', 'HELMET', 'SPARE', 'PARTS',
    ];

    /** Krótsze słowo („XP”, „PC”) i krótszy SKU niczego nie wiążą — za łatwo o przypadkową zgodność. */
    private const MIN_LENGTH = 3;

    public static function sameProduct(string $cardName, ?string $remoteName, string $sku): bool
    {
        $cardName = trim($cardName);
        $remoteName = trim((string) $remoteName);
        if ($cardName === '' || $remoteName === '') {
            return false;
        }
        if ($cardName === $remoteName) {
            return true;
        }

        $sku = trim($sku);
        if (mb_strlen($sku) >= self::MIN_LENGTH
            && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($sku, '/').'(?![\p{L}\p{N}])/iu', $cardName) === 1) {
            return true;
        }

        // Nazwa w całości wielkimi literami (Procera: „OKULARY OCHRONNE BOLLE COBRA (PRZEZROCZYSTE)”) nie odróżnia
        // modelu od zwykłych słów — wtedy wiążą tylko słowa z cyfrą, inaczej „Okulary” w nazwie karty dawałoby zgodność.
        $allCaps = preg_match('/\p{Ll}/u', $remoteName) !== 1;
        $cardWords = array_map('mb_strtolower', self::words($cardName));
        foreach (self::words($remoteName) as $word) {
            if (self::isDesignation($word, $allCaps) && in_array(mb_strtolower($word), $cardWords, true)) {
                return true;
            }
        }

        return false;
    }

    private static function isDesignation(string $word, bool $remoteAllCaps): bool
    {
        return mb_strlen($word) >= self::MIN_LENGTH
            && ! in_array(mb_strtoupper($word), self::GENERIC_WORDS, true)
            && (preg_match('/\p{N}/u', $word) === 1
                || (! $remoteAllCaps && preg_match('/^\p{Lu}+\+*$/u', $word) === 1));
    }

    /**
     * Słowa nazwy: ciągi liter, cyfr i „+” („RUSH+ 2.0 XP” → RUSH+, 2, 0, XP).
     *
     * @return list<string>
     */
    private static function words(string $name): array
    {
        return preg_split('/[^\p{L}\p{N}+]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
