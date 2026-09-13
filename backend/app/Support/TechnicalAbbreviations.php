<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Skróty norm, klas ochrony, materiałów i instytucji pisane wersalikami (EN, ESD, SRC, PVC, FDA,
 * FFP2, S1P, III). W SIWZ wyglądają jak kod produktu albo marka, a nie są ani jednym, ani drugim —
 * wspólna lista dla wyszukiwarki (token producenta) i dopasowania przetargu (kody z SIWZ).
 */
final class TechnicalAbbreviations
{
    /** @var list<string> */
    private const NON_BRAND = [
        'EN', 'ISO', 'PN', 'PNEN', 'PNENISO', 'ENISO', 'DIN', 'ASTM', 'ANSI', 'IEC', 'CE', 'UV', 'IR', 'UKCA',
        'FFP', 'SRA', 'SRB', 'SRC', 'HRO', 'CI', 'HI', 'WR', 'WRU', 'FO', 'ESD', 'AQL', 'NDS', 'NDSCH',
        'PVC', 'PCV', 'PU', 'PA', 'PE', 'PP', 'PC', 'PES', 'NBR', 'HPPE', 'UHMWPE', 'TPR', 'TPU', 'TPE', 'EVA', 'SBR',
        'SVHC', 'FDA', 'HACCP', 'REACH', 'ROHS', 'ATEX', 'OEKO', 'OEKOTEX',
        'KV', 'SOI', 'ŚOI', 'BHP', 'PPE', 'RKO', 'AED', 'NRC', 'EU', 'UE', 'USA', 'PL',
        'ABEK', 'ABEKP', 'SNR', 'KAT', 'OTG', 'HV', 'LED', 'RFID',
    ];

    /** Skrót normy/klasy/materiału albo oznaczenie klasy (FFP2, S1P, OB, A2B2E2K2, EN388, III, 17KV). */
    public static function isNormOrClass(string $token): bool
    {
        $compact = mb_strtoupper((string) preg_replace('/[^\p{L}\d]/u', '', $token), 'UTF-8');
        if ($compact === '') {
            return false;
        }
        if (in_array($compact, self::NON_BRAND, true)) {
            return true;
        }

        // Klasy i poziomy: FFP2, S1P, S3, OB, O2, A2B2E2K2NO, ABEK1P3, EN388, ISO20345, III.
        return preg_match(
            '/^(?:FFP[1-3]?|S[1-7]P?L?|SB|OB|O[1-7]|SR[ABC]|A[1-3]|B[1-3]|E[1-2]|K[1-2]|P[1-3]'
            .'|(?:ABEK\d?|[ABEKP]\d(?:[ABEKP]\d){0,4})(?:HG|NO|CO|SX|AX|NR|R|D)?(?:P\d)?'
            .'|EN\d{2,6}|ISO\d{2,6}|PNEN\d{2,6}|[IVX]{1,4}|\d+KV)$/u',
            $compact
        ) === 1;
    }
}
