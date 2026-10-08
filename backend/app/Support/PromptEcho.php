<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Zdanie opisu albo pozycja listy, w której model powtarza polecenie albo swój tok sprawdzania źródeł, zamiast pisać
 * o wyrobie (etap 3, §1.4 planu z 08.10.2026). Przykłady z audytów:
 * - MAPA Harpon 330: „Produkt należy do kategorii PPE (obuwie, rękawice, odzież), a tekst opisuje właściwości
 *   rękawic, co jest zgodne z wymaganiami.” — warunek z polecenia (EnrichmentDescriptionTemplates „inne”, jsonContract);
 * - SECURA 151: „…choć w opisach zestawów detalicznych dla tego samego SKU podawany jest rozmiar M.”;
 * - AJ GROUP 217 (model 298): „…kurtki model 285 (lub 1031 OC, zgodnie z różnymi źródłami).”;
 * - Ansell 11772, pole norm: „EN ISO 21420 (wymagania ogólne - implied by context of PPE, but specific cut level is
 *   EN ISO 13999 or similar, source says…)”.
 *
 * - ELTEN 64571 (produkcja): „…(Typ 1 w nazwie karty, choć źródło opisuje Typ 2).”
 *
 * Tylko zwroty, których w opisie wyrobu nie ma po co pisać — „zgodne z wymaganiami normy EN 388”, „Kategoria III
 * ŚOI” i „to produkt z kategorii PPE, zaprojektowany…” to treść karty, nie echo.
 */
final class PromptEcho
{
    /** @var list<string> */
    private const PATTERNS = [
        // „Jeśli nazwa to PPE (obuwie, rękawice, odzież…)” z polecenia
        '/\(\s*obuwie\s*,\s*rękawice\s*,\s*odzież/iu',
        // „należy do kategorii PPE (obuwie…” — tylko z wyliczeniem z polecenia. Samo „to produkt z kategorii PPE,
        // zaprojektowany z myślą o…” pisze model w pierwszym zdaniu prawdziwych opisów (pomiar na produkcji 08.10.2026:
        // 9 z 13 trafień, MAPA, ARTRA, Polstar) — tego zdania nie wycinamy
        '/\bkategorii\s+PPE\s*\(/iu',
        '/(?<!\p{L})(?:tekst|źródło|źródła|strona|strony)\s+(?:opisuje|opisują)\b/iu',
        '/\bzgodnie\s+z\s+różnymi\s+źródłami\b/iu',
        '/\bdla\s+tego\s+samego\s+SKU\b/iu',
        '/\bimplied\b/iu',
        '/\bor\s+similar\b/iu',
        '/\bsource\s+says\b/iu',
    ];

    public static function isEcho(string $sentenceOrItem): bool
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $sentenceOrItem));
        if ($text === '') {
            return false;
        }
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
