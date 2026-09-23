<?php

declare(strict_types=1);

namespace App\Services\Norms;

/**
 * Strona za zaporą (Ansell: Incapsula) czytana przez reader jako markdown — zamieniona na prosty HTML, żeby bramka
 * tożsamości i czytniki działały na niej tak samo jak na zwykłej stronie: tytuł z linii „Title:”, nagłówek z „# …”,
 * a treść główna od nagłówka wyrobu do bloku innych wyrobów („## Produkty powiązane”) — menu przed nagłówkiem i lista
 * powiązanych rękawic za nim nie mogą potwierdzić kodu naszego wyrobu. Markdown zostaje dosłowny (escapowany) w <main>.
 */
final class ReaderMarkdownPage
{
    /** Koniec karty wyrobu w markdownie Ansella: lista innych wyrobów, stopka prywatności. */
    private const END = '/^#{1,3}\s*(?:Produkty powiązane|Related products|Do Not Sell|Cookie List)\b/imu';

    public static function toHtml(string $markdown): string
    {
        $title = preg_match('/^Title:\s*(.+)$/mu', $markdown, $t) === 1 ? trim($t[1]) : '';
        $start = preg_match('/^#\s+\S.*$/mu', $markdown, $h, PREG_OFFSET_CAPTURE) === 1 ? (int) $h[0][1] : 0;
        $h1 = isset($h[0][0]) ? trim(ltrim((string) $h[0][0], '#')) : '';
        $main = substr($markdown, $start);
        if (preg_match(self::END, $main, $end, PREG_OFFSET_CAPTURE) === 1) {
            $main = substr($main, 0, (int) $end[0][1]);
        }
        $esc = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<html><head><title>'.$esc($title).'</title></head><body>'
            .($h1 !== '' ? '<h1>'.$esc($h1).'</h1>' : '')
            .'<main class="reader-markdown">'.$esc($main).'</main></body></html>';
    }

    /** Markdown z <main> strony zbudowanej przez toHtml — dla czytnika witryny. */
    public static function markdownFrom(string $html): ?string
    {
        if (preg_match('#<main class="reader-markdown">(.*?)</main>#su', $html, $m) !== 1) {
            return null;
        }

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
