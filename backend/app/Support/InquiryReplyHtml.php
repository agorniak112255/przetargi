<?php

declare(strict_types=1);

namespace App\Support;

/**
 * List do klienta w HTML: tabela „pozycja z zapytania — nasza propozycja”.
 *
 * Powstaje z tych samych danych co wersja tekstowa (`reply_body`), więc obie
 * mówią to samo. Style są wpisane w znaczniki, bo programy pocztowe wycinają
 * arkusze stylów — bez tego tabela rozpadłaby się u odbiorcy.
 */
final class InquiryReplyHtml
{
    private const FONT = 'font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.5;color:#1f2430';

    private const BORDER = '1px solid #cbd5e1';

    /** Kolumna z zapytaniem jest przyciemniona, nasza odpowiedź zostaje biała. */
    private const ASK_BG = '#eef2f7';

    private const ANSWER_BG = '#ffffff';

    private const HEAD_BG = '#1d4ed8';

    /**
     * @param  list<array{head: string, quote: string|null, answer: list<string>}>  $rows
     * @param  list<string>  $outro
     * @param  list<array{label: string, value: string}>  $terms  warunki wpisane przez handlowca
     */
    public static function render(string $intro, array $rows, ?string $note, array $outro, array $terms = []): string
    {
        $html = '<div style="'.self::FONT.'">';
        $html .= self::paragraph($intro);
        $html .= self::table($rows);
        $html .= self::terms($terms);

        if ($note !== null && trim($note) !== '') {
            $html .= self::paragraph($note);
        }

        $html .= self::paragraph(implode("\n", $outro));

        return $html.'</div>';
    }

    private static function paragraph(string $text): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return '';
        }

        return '<p style="margin:0 0 12px">'.self::text($trimmed).'</p>';
    }

    /**
     * @param  list<array{head: string, quote: string|null, answer: list<string>}>  $rows
     */
    private static function table(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $html = '<table role="presentation" cellpadding="0" cellspacing="0" '
            .'style="border-collapse:collapse;width:100%;margin:0 0 12px;'.self::FONT.'">';
        $html .= '<tr>'
            .self::headCell('Pozycja z zapytania')
            .self::headCell('Nasza propozycja')
            .'</tr>';

        foreach ($rows as $row) {
            $ask = '<div style="font-weight:bold">'.self::text($row['head']).'</div>';
            if ($row['quote'] !== null) {
                // dosłowny cytat z maila klienta — stąd wiadomo, czego dotyczy wiersz
                $ask .= '<div style="margin-top:4px;color:#475569">'.self::text($row['quote']).'</div>';
            }

            $html .= '<tr>'
                .self::cell($ask, self::ASK_BG, '38%')
                .self::cell(self::text(implode("\n", $row['answer'])), self::ANSWER_BG, null)
                .'</tr>';
        }

        return $html.'</table>';
    }

    /**
     * Warunki pod tabelą: etykieta i wartość w jednym wierszu. Klient pyta o nie
     * wprost, więc stoją osobno, a nie w akapicie razem z dopiskiem handlowca.
     *
     * @param  list<array{label: string, value: string}>  $terms
     */
    private static function terms(array $terms): string
    {
        if ($terms === []) {
            return '';
        }

        $html = '<table role="presentation" cellpadding="0" cellspacing="0" '
            .'style="border-collapse:collapse;margin:0 0 12px;'.self::FONT.'">';
        foreach ($terms as $term) {
            $html .= '<tr>'
                .'<td style="padding:2px 12px 2px 0;color:#475569;vertical-align:top;white-space:nowrap">'
                .self::text($term['label'])
                .'</td>'
                .'<td style="padding:2px 0;vertical-align:top">'.self::text($term['value']).'</td>'
                .'</tr>';
        }

        return $html.'</table>';
    }

    private static function headCell(string $label): string
    {
        return '<th style="background:'.self::HEAD_BG.';color:#ffffff;text-align:left;'
            .'padding:8px 10px;border:'.self::BORDER.';font-weight:bold">'
            .self::text($label)
            .'</th>';
    }

    private static function cell(string $html, string $background, ?string $width): string
    {
        return '<td style="background:'.$background.';padding:8px 10px;border:'.self::BORDER.';'
            .'vertical-align:top'.($width === null ? '' : ';width:'.$width).'">'
            .$html
            .'</td>';
    }

    /** Treść od klienta i z katalogu trafia do maila jako tekst, nigdy jako znaczniki. */
    private static function text(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return nl2br($escaped, false);
    }
}
