<?php

declare(strict_types=1);

namespace App\Support;

/**
 * List do klienta w HTML: zapytanie w ciemnym kaflu u góry, pod nim nasze
 * pozycje jako osobne wiersze-kafelki z terakotową krawędzią.
 *
 * Powstaje z tych samych danych co wersja tekstowa (`reply_body`), więc obie
 * mówią to samo. Style są wpisane w znaczniki, bo programy pocztowe wycinają
 * arkusze stylów — bez tego list rozpadłby się u odbiorcy. Zaokrąglenia i cienie
 * pomija stary Outlook; układ trzyma się wtedy na tłach i krawędziach.
 */
final class InquiryReplyHtml
{
    /**
     * Kolor tekstu wpisany wprost w każdy element na jasnym tle. Ciemny motyw
     * Thunderbirda podmienia kolor dziedziczony na jasny, a białe tło kafla
     * zostawia — tekst bez własnego koloru znikał (nazwa, plakietki, wartość).
     */
    private const TEXT = '#24211f';

    private const FONT = 'font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.55;color:'.self::TEXT;

    /** Węgiel — kafel zapytania. */
    private const INK = '#1f1d1b';

    /** Terakota — krawędź pozycji i suma. */
    private const ACCENT = '#b35a32';

    private const MUTED = '#6f6862';

    private const LABEL = '#a49b93';

    private const PAGE_BG = '#f7f6f5';

    private const CHIP_BG = '#f4f1ee';

    private const HAIRLINE = '#f0ece8';

    /**
     * @param  list<array{head: string, quote: string|null, answer: list<string>, answer_roles?: list<string>, facts?: array<string, mixed>}>  $rows
     * @param  list<string>  $outro
     * @param  list<array{label: string, value: string}>  $terms  warunki wpisane przez handlowca
     * @param  array{title: string|null, date: string|null, lines: list<string>}  $asked  zapytanie klienta
     */
    public static function render(
        string $intro,
        array $rows,
        ?string $note,
        array $outro,
        array $terms = [],
        array $asked = ['title' => null, 'date' => null, 'lines' => []],
    ): string {
        $html = '<div style="'.self::FONT.';background:'.self::PAGE_BG.';padding:24px;border-radius:10px">';
        $html .= self::paragraph($intro);
        $html .= self::asked($asked);
        $html .= self::items($rows);
        $html .= self::summary($rows);
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

        return '<p style="margin:0 0 16px;color:'.self::MUTED.'">'.self::text($trimmed).'</p>';
    }

    /** Nagłówek sekcji: drobne wersaliki nad blokiem. */
    private static function caption(string $label): string
    {
        return '<div style="font-size:10px;letter-spacing:1.4px;text-transform:uppercase;'
            .'color:'.self::LABEL.';margin:0 0 10px">'.self::text($label).'</div>';
    }

    /**
     * Zapytanie klienta jego słowami — żeby wiedział, na które pismo odpowiadamy.
     *
     * @param  array{title: string|null, date: string|null, lines: list<string>}  $asked
     */
    private static function asked(array $asked): string
    {
        $title = trim((string) ($asked['title'] ?? ''));
        $date = trim((string) ($asked['date'] ?? ''));
        $lines = $asked['lines'] ?? [];
        if ($title === '' && $date === '' && $lines === []) {
            return '';
        }

        $head = trim($title.($title !== '' && $date !== '' ? ' · ' : '').$date);

        $html = '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;'
            .'width:100%;background:'.self::INK.';border-radius:8px;margin:0 0 20px">'
            .'<tr><td style="padding:18px 20px">';
        $html .= '<div style="font-size:10px;letter-spacing:1.4px;text-transform:uppercase;color:#9c948c">'
            .'Zapytanie klienta</div>';
        if ($head !== '') {
            $html .= '<div style="font-size:16px;font-weight:bold;color:#ffffff;margin-top:4px">'
                .self::text($head).'</div>';
        }
        if ($lines !== []) {
            $html .= '<div style="font-size:12px;color:#b9b0a7;margin-top:10px;line-height:1.8">'
                .self::text(implode("\n", $lines))
                .'</div>';
        }

        return $html.'</td></tr></table>';
    }

    /**
     * Pozycje jako wiersze-kafelki: nazwa i dane techniczne po lewej, wartość po
     * prawej. Jednolity blok tekstu czytało się ciężko — klient szukał wzrokiem
     * rozmiaru, ilości i ceny pośród zdań opisu.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function items(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $html = self::caption('Propozycja');
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:separate;'
            .'border-spacing:0 10px;width:100%;margin:-10px 0 4px">';

        foreach ($rows as $row) {
            $facts = is_array($row['facts'] ?? null) ? $row['facts'] : [];
            $html .= '<tr><td style="background:#ffffff;color:'.self::TEXT.';border-left:4px solid '.self::ACCENT.';'
                .'border-radius:0 8px 8px 0;box-shadow:0 1px 5px rgba(25,22,20,0.09);padding:16px 18px">'
                .'<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%">'
                .'<tr><td style="vertical-align:top">'
                .self::itemMain($row, $facts)
                .'</td><td style="vertical-align:top;text-align:right;padding-left:14px;white-space:nowrap">'
                .self::itemValue($facts)
                .'</td></tr></table>'
                .self::itemNotes($row)
                .'</td></tr>';
        }

        return $html.'</table>';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $facts
     */
    private static function itemMain(array $row, array $facts): string
    {
        $name = trim((string) ($facts['name'] ?? ''));
        if ($name === '') {
            // Szablon „bez SKU” nie podaje nazwy z katalogu — nagłówkiem jest wtedy
            // zdanie opisowe z treści listu. Gdy i tego nie ma (brak karty), zostaje
            // nagłówek pozycji z zapytania klienta.
            $name = self::lineWithRole($row, 'name') ?? trim((string) ($row['head'] ?? ''));
        }

        $line = array_values(array_filter([
            ($code = trim((string) ($facts['code'] ?? ''))) === '' ? null : 'kod '.$code,
            ($norms = trim((string) ($facts['norms'] ?? ''))) === '' ? null : $norms,
        ]));

        $html = '<div style="font-size:15px;font-weight:bold;color:'.self::TEXT.'">'.self::text($name).'</div>';
        if ($line !== []) {
            $html .= '<div style="font-size:12px;color:#8a817a;margin-top:4px">'
                .self::text(implode(' · ', $line)).'</div>';
        }

        $chips = array_values(array_filter([
            ($size = trim((string) ($facts['size'] ?? ''))) === '' ? null : 'rozmiar '.$size,
            ($qty = trim((string) ($facts['qty'] ?? ''))) === '' ? null : $qty,
            self::unitPrice($facts),
        ]));
        if ($chips !== []) {
            $html .= '<div style="font-size:12px;margin-top:9px">';
            foreach ($chips as $chip) {
                $html .= '<span style="background:'.self::CHIP_BG.';color:'.self::TEXT.';padding:4px 10px;border-radius:12px;'
                    .'margin-right:4px;display:inline-block">'.self::text($chip).'</span>';
            }
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Cena jednostkowa z jednostką klienta („359,31 zł / szt.”). Bez ilości
     * zostaje sama cena — jednostki nie dopowiadamy, bo karta jej nie niesie.
     *
     * @param  array<string, mixed>  $facts
     */
    private static function unitPrice(array $facts): ?string
    {
        $price = trim((string) ($facts['price'] ?? ''));
        if ($price === '') {
            return null;
        }
        $unit = trim(preg_replace('/^[\d\s.,]+/u', '', trim((string) ($facts['qty'] ?? ''))) ?? '');

        return $unit === '' ? $price : $price.' / '.self::singular($unit);
    }

    /** „pary” → „para”, „sztuk” → „szt.” — jednostka przy cenie stoi w liczbie pojedynczej. */
    private static function singular(string $unit): string
    {
        $lower = mb_strtolower($unit);

        return match (true) {
            str_starts_with($lower, 'par') => 'para',
            str_starts_with($lower, 'szt') => 'szt.',
            str_starts_with($lower, 'opak'), str_starts_with($lower, 'op.') => 'opak.',
            str_starts_with($lower, 'kpl'), str_starts_with($lower, 'komplet') => 'kpl.',
            str_starts_with($lower, 'zest') => 'zestaw',
            default => $unit,
        };
    }

    /**
     * Wartość pozycji po prawej stronie kafelka.
     *
     * @param  array<string, mixed>  $facts
     */
    private static function itemValue(array $facts): string
    {
        $total = trim((string) ($facts['total'] ?? ''));
        if ($total === '') {
            return '';
        }

        return '<div style="font-size:10px;letter-spacing:0.8px;text-transform:uppercase;color:'.self::LABEL.'">'
            .'Wartość</div>'
            .'<div style="font-size:19px;font-weight:bold;color:'.self::TEXT.';margin-top:2px">'.self::text($total).'</div>';
    }

    /**
     * Pierwsza linia treści o podanej roli — stąd bierze się nazwa w szablonie,
     * który nie pozwala pokazać nazwy katalogowej.
     *
     * @param  array<string, mixed>  $row
     */
    private static function lineWithRole(array $row, string $wanted): ?string
    {
        $lines = is_array($row['answer'] ?? null) ? $row['answer'] : [];
        $roles = is_array($row['answer_roles'] ?? null) ? $row['answer_roles'] : [];
        foreach ($lines as $index => $line) {
            if ((string) ($roles[$index] ?? '') !== $wanted) {
                continue;
            }
            // „Produkt: …” / „Zamiennik: …” — etykieta ma już swoje miejsce w układzie.
            $text = trim(preg_replace('/^(?:Produkt|Zamiennik):\s*/u', '', trim((string) $line)) ?? '');
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * Opis wyrobu i zamiennik — wszystko, co nie zmieściło się w nagłówku kafelka
     * i plakietkach. Zamiennik dostaje własny, oddzielony blok: to inna propozycja
     * niż wyrób z pozycji i nie może się z nim zlewać.
     *
     * @param  array<string, mixed>  $row
     */
    private static function itemNotes(array $row): string
    {
        $lines = is_array($row['answer'] ?? null) ? $row['answer'] : [];
        $roles = is_array($row['answer_roles'] ?? null) ? $row['answer_roles'] : [];

        $html = '';
        $substitute = '';
        foreach ($lines as $index => $line) {
            $role = (string) ($roles[$index] ?? 'body');
            $text = trim((string) $line);
            if ($text === '') {
                continue;
            }
            // Nazwa, normy i cena wyrobu mają już swoje miejsce w kafelku.
            if (in_array($role, ['name', 'meta', 'price'], true)) {
                continue;
            }
            if (str_starts_with($role, 'sub_')) {
                $strong = $role === 'sub_name' ? 'font-weight:bold;' : '';
                $substitute .= '<div style="font-size:12px;color:#4a453f;'.$strong.'margin-top:4px">'
                    .self::text($text).'</div>';

                continue;
            }
            $html .= '<div style="font-size:13px;color:#4a453f;margin-top:12px">'.self::text($text).'</div>';
        }

        if ($substitute !== '') {
            $html .= '<div style="margin-top:12px;padding-top:10px;border-top:1px dashed #e4ded7">'
                .'<div style="font-size:10px;letter-spacing:0.8px;text-transform:uppercase;color:'.self::LABEL.'">'
                .'Zamiennik</div>'.$substitute.'</div>';
        }

        return $html;
    }

    /**
     * Suma po prawej. Liczymy tylko pozycje, które mają wartość — gdy choć
     * jednej brakuje ceny albo ilości, mówimy o tym wprost zamiast podawać
     * sumę części oferty jako całość.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function summary(array $rows): string
    {
        $sum = 0.0;
        $counted = 0;
        $missing = 0;
        foreach ($rows as $row) {
            $facts = is_array($row['facts'] ?? null) ? $row['facts'] : [];
            $value = $facts['total_pln'] ?? null;
            if (is_numeric($value)) {
                $sum += (float) $value;
                $counted++;

                continue;
            }
            $missing++;
        }
        if ($counted === 0) {
            return '';
        }

        $label = 'Razem netto';
        $note = $missing > 0
            ? '<div style="font-size:11px;color:'.self::LABEL.';text-transform:none;letter-spacing:0">'
                .self::text('bez pozycji, dla których podamy cenę po weryfikacji').'</div>'
            : '';

        return '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;'
            .'width:100%;margin:12px 0 18px"><tr>'
            .'<td style="text-align:right;padding:0 18px 0 0;color:'.self::MUTED.';font-size:12px;'
            .'letter-spacing:0.8px;text-transform:uppercase;vertical-align:top">'.self::text($label).$note.'</td>'
            .'<td style="width:150px;text-align:right;font-size:22px;font-weight:bold;color:'.self::ACCENT.';'
            .'white-space:nowrap;vertical-align:top">'.self::text(number_format($sum, 2, ',', ' ').' zł').'</td>'
            .'</tr></table>';
    }

    /**
     * Warunki na białej karcie, po dwa w rzędzie — klient pyta o nie wprost,
     * więc stoją osobno, a nie w akapicie razem z dopiskiem handlowca.
     *
     * @param  list<array{label: string, value: string}>  $terms
     */
    private static function terms(array $terms): string
    {
        if ($terms === []) {
            return '';
        }

        $html = '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;'
            .'width:100%;background:#ffffff;color:'.self::TEXT.';border-radius:8px;box-shadow:0 1px 5px rgba(25,22,20,0.09);margin:0 0 16px">';
        foreach (array_chunk($terms, 2) as $index => $pair) {
            $html .= '<tr>';
            foreach ($pair as $column => $term) {
                $html .= '<td style="padding:'.($index === 0 ? '14px 18px' : '0 18px 14px').';width:50%;'
                    .'vertical-align:top'.($column === 1 ? ';border-left:1px solid '.self::HAIRLINE : '').'">'
                    .'<div style="font-size:10px;letter-spacing:0.8px;text-transform:uppercase;color:'.self::LABEL.'">'
                    .self::text($term['label']).'</div>'
                    .'<div style="margin-top:3px;color:'.self::TEXT.'">'.self::text($term['value']).'</div>'
                    .'</td>';
            }
            if (count($pair) === 1) {
                $html .= '<td style="width:50%;border-left:1px solid '.self::HAIRLINE.'"></td>';
            }
            $html .= '</tr>';
        }

        return $html.'</table>';
    }

    /** Treść od klienta i z katalogu trafia do maila jako tekst, nigdy jako znaczniki. */
    private static function text(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return nl2br($escaped, false);
    }
}
