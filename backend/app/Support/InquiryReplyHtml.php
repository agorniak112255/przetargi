<?php

declare(strict_types=1);

namespace App\Support;

/**
 * List do klienta w HTML: każda pozycja zapytania we własnym kaflu, a w nim dwa
 * odróżnione bloki — „Państwa zapytanie” (słowa klienta) i „Nasza propozycja”.
 * Kolejne kafle są na przemian jasne i ciemniejsze (zebra), żeby przy kilku
 * pozycjach od razu było widać, gdzie kończy się jedna, a zaczyna następna.
 * Pod pozycjami stoją warunki i suma.
 *
 * Powstaje z tych samych danych co wersja tekstowa (`reply_body`), więc obie
 * mówią to samo. Style są wpisane w znaczniki, bo programy pocztowe wycinają
 * arkusze stylów — bez tego list rozpadłby się u odbiorcy. Zaokrąglenia pomija
 * stary Outlook; układ trzyma się wtedy na tłach i ramkach.
 */
final class InquiryReplyHtml
{
    /**
     * Kolor tekstu wpisany wprost w każdy element. Ciemny motyw Thunderbirda
     * podmienia kolor dziedziczony na jasny, a tła wpisane w znaczniki zostawia
     * jasne — tekst bez własnego koloru znikał.
     */
    private const TEXT = '#1c1917';

    private const BODY = '#44403c';

    private const MUTED = '#78716c';

    private const FONT = 'font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.55;color:'.self::TEXT;

    /** Beżowe tło listu, na którym stoją kafle pozycji. */
    private const PAGE_BG = '#f5f3ef';

    private const TILE_BG = '#ffffff';

    /** Co druga pozycja stoi na ciemniejszym kaflu (zebra). */
    private const ZEBRA_BG = '#ebe7df';

    private const BORDER = '#e7e5e4';

    /** Blok słów klienta: beż z brązowym napisem. */
    private const ASK_BG = '#f6f1e8';

    private const ASK_BORDER = '#e4dac8';

    private const ASK_LABEL = '#8a6d3b';

    /** Blok naszej propozycji: biel w zielonej ramce. */
    private const OURS_BG = '#ffffff';

    private const OURS_BORDER = '#cfe3d5';

    /** Pozycja bez naszego wyrobu: przerywana ramka zamiast zielonej. */
    private const NONE_BG = '#fafaf9';

    private const NONE_BORDER = '#cbc5bc';

    /** Zieleń — suma i napis „Nasza propozycja”. */
    private const TOTAL_BG = '#166534';

    private const TOTAL_SOFT = '#bbf7d0';

    /**
     * @param  list<array{head: string, quote: string|null, answer: list<string>, answer_roles?: list<string>, facts?: array<string, mixed>}>  $rows
     * @param  list<string>  $outro
     * @param  list<array{label: string, value: string}>  $terms  warunki wpisane przez handlowca
     * @param  array{title: string|null, date: string|null}  $asked  temat i data zapytania klienta
     */
    public static function render(
        string $intro,
        array $rows,
        ?string $note,
        array $outro,
        array $terms = [],
        array $asked = ['title' => null, 'date' => null],
    ): string {
        // 60% okna: na pełnej szerokości opis rozlewał się w długie, męczące wiersze.
        // Dolna granica chroni wąskie okna (telefon), gdzie 60% byłoby za ciasne.
        $html = '<div style="'.self::FONT.';background:'.self::PAGE_BG.';padding:22px;border-radius:18px;'
            .'width:60%;min-width:320px;box-sizing:border-box">';
        $html .= self::paragraph($intro);
        $html .= self::inquiryLine($asked, count($rows));
        $html .= self::positions($rows);
        $html .= self::closing($terms, $rows);

        if ($note !== null && trim($note) !== '') {
            $html .= self::paragraph($note, '18px 0 0');
        }

        $html .= self::paragraph(implode("\n", $outro), '18px 0 0');

        return $html.'</div>';
    }

    private static function paragraph(string $text, string $margin = '0 0 16px'): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return '';
        }

        return '<p style="margin:'.$margin.';color:'.self::BODY.'">'.self::text($trimmed).'</p>';
    }

    /** Nazwa bloku drobnymi wersalikami. */
    private static function label(string $text, string $color): string
    {
        return '<div style="font-size:11px;font-weight:bold;letter-spacing:.6px;text-transform:uppercase;color:'.$color.'">'
            .self::text($text).'</div>';
    }

    /** Wartość wytłuszczona i nierozdzielana — kwota nie łamie się w połowie („1” / „395,36 zł”). */
    private static function strong(string $value): string
    {
        return '<b style="color:'.self::TEXT.';white-space:nowrap">'.self::text($value).'</b>';
    }

    /**
     * „Zapytanie: Oferta cenowa… z 24.09.2026 · 7 pozycji” — klient ma od razu
     * widzieć, na które pismo odpowiadamy.
     *
     * @param  array{title?: string|null, date?: string|null}  $asked
     */
    private static function inquiryLine(array $asked, int $count): string
    {
        $title = trim((string) ($asked['title'] ?? ''));
        $date = trim((string) ($asked['date'] ?? ''));
        if ($title === '' && $date === '') {
            return '';
        }

        $html = self::text('Zapytanie');
        if ($title !== '') {
            $html .= self::text(': ').'<b style="color:'.self::TEXT.'">'.self::text($title).'</b>';
        }
        if ($date !== '') {
            $html .= self::text(' z '.$date);
        }
        if ($count > 0) {
            $html .= self::text(' · '.$count.' '.self::plural($count, 'pozycja', 'pozycje', 'pozycji'));
        }

        return '<div style="font-size:12px;color:'.self::BODY.';margin:0 0 12px">'.$html.'</div>';
    }

    /**
     * Kafel na każdą pozycję zapytania, w kolejności klienta. Opis wyrobu, który
     * już stał w liście, odsyła do tamtej pozycji zamiast powtarzać ten sam akapit.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function positions(array $rows): string
    {
        $withPrices = self::hasPrices($rows);
        $described = [];
        $html = '';
        foreach ($rows as $index => $row) {
            $number = $index + 1;
            $key = self::descriptionKey($row);
            $sameAs = $key === null ? null : ($described[$key] ?? null);
            if ($key !== null && $sameAs === null) {
                $described[$key] = $number;
            }
            $html .= self::positionTile($row, $number, $index % 2 === 1, $sameAs, $withPrices);
        }

        return $html;
    }

    /**
     * Opis wyrobu, po którym poznajemy powtórkę: ta sama nazwa i ten sam akapit.
     * Null, gdy pozycja nie ma wyrobu albo opisu — wtedy nie ma do czego odsyłać.
     *
     * @param  array<string, mixed>  $row
     */
    private static function descriptionKey(array $row): ?string
    {
        $name = self::productName($row);
        $body = self::notes($row)['body'];
        if ($name === null || $body === []) {
            return null;
        }

        return json_encode([$name, $body], JSON_UNESCAPED_UNICODE) ?: null;
    }

    /**
     * Czy list w ogóle podaje ceny. „Cena po weryfikacji” przy pozycji bez wyrobu
     * ma sens tylko wtedy — w liście „Bez cen” brzmiałaby jak obietnica.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function hasPrices(array $rows): bool
    {
        foreach ($rows as $row) {
            if (trim((string) (self::facts($row)['price'] ?? '')) !== '' || self::lineWithRole($row, 'price') !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function positionTile(array $row, int $number, bool $shaded, ?int $sameAs, bool $withPrices): string
    {
        $ask = self::askBlock($row);
        $ours = self::oursBlock($row, $sameAs);

        return '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0;'
            .'width:100%;margin:0 0 10px"><tr><td style="background:'.($shaded ? self::ZEBRA_BG : self::TILE_BG).';'
            .'color:'.self::TEXT.';border:1px solid '.self::BORDER.';border-radius:14px;padding:12px">'
            .self::positionHead($row, $number, $withPrices)
            .'<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0;width:100%"><tr>'
            .($ask === '' ? '' : $ask.'<td style="width:8px"></td>')
            .$ours
            .'</tr></table>'
            .'</td></tr></table>';
    }

    /**
     * „Pozycja 3” i wartość pozycji po prawej — przy kilku pozycjach klient
     * przegląda kwoty bez czytania opisów.
     *
     * @param  array<string, mixed>  $row
     */
    private static function positionHead(array $row, int $number, bool $withPrices): string
    {
        $facts = self::facts($row);
        $total = trim((string) ($facts['total'] ?? ''));

        $value = '';
        if ($total !== '') {
            $value = self::text('wartość netto: ')
                .'<b style="font-size:15px;color:'.self::TEXT.';white-space:nowrap">'.self::text($total).'</b>';
        } elseif ($withPrices && self::productName($row) === null) {
            $value = '<span style="color:'.self::MUTED.'">'.self::text('cena po weryfikacji').'</span>';
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin:0 0 8px"><tr>'
            .'<td style="font-size:14px;font-weight:bold;color:'.self::TEXT.'">'.self::text('Pozycja '.$number).'</td>'
            .($value === '' ? '' : '<td style="text-align:right;white-space:nowrap;padding-left:10px;font-size:13px;color:'.self::BODY.'">'.$value.'</td>')
            .'</tr></table>';
    }

    /**
     * Słowa klienta z zapytania. Ilość i rozmiar dopisujemy tylko wtedy, gdy klient
     * nie napisał ich w tym zdaniu — inaczej ta sama liczba stała dwa razy.
     *
     * @param  array<string, mixed>  $row
     */
    private static function askBlock(array $row): string
    {
        $quote = trim((string) ($row['quote'] ?? ''));
        $facts = self::facts($row);

        $extra = [];
        $qty = trim((string) ($facts['qty'] ?? ''));
        if ($qty !== '' && ! self::mentionsQuantity($quote, $qty)) {
            $extra[] = 'Ilość: '.$qty;
        }
        $size = trim((string) ($facts['size'] ?? ''));
        if ($size !== '' && ! self::mentions($quote, $size)) {
            $extra[] = 'Rozmiar: '.$size;
        }
        if ($quote === '' && $extra === []) {
            return '';
        }

        $html = self::label('Państwa zapytanie', self::ASK_LABEL);
        if ($quote !== '') {
            $html .= '<div style="font-size:13px;color:'.self::BODY.';margin-top:4px">'.self::text($quote).'</div>';
        }
        if ($extra !== []) {
            $html .= '<div style="font-size:12px;color:'.self::MUTED.';margin-top:4px">'.self::text(implode(' · ', $extra)).'</div>';
        }

        return self::block($html, 'background:'.self::ASK_BG.';border:1px solid '.self::ASK_BORDER, 'width:40%;');
    }

    /**
     * Blok w kaflu pozycji: komórka układu, a w niej tabela z tłem. Tło na samej
     * komórce rozciągało krótki blok zapytania do wysokości długiej propozycji
     * i zostawiało pod tekstem pustą, beżową plamę. Długie słowa wersalikami
     * („DIAGNOSTYCZNE”) mogą się łamać — w wąskim oknie dwa bloki obok siebie
     * wypychały kafel poza list.
     */
    private static function block(string $content, string $frame, string $width = ''): string
    {
        return '<td style="'.$width.'vertical-align:top">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0;width:100%"><tr>'
            .'<td style="'.$frame.';border-radius:10px;padding:10px 12px;color:'.self::TEXT.';'
            .'word-break:break-word;overflow-wrap:anywhere">'.$content.'</td>'
            .'</tr></table></td>';
    }

    /** Czy klient napisał liczbę z ilości („10” w „10 opakowań po 50 par”), a nie np. „1” z „1,5 m”. */
    private static function mentionsQuantity(string $quote, string $qty): bool
    {
        if ($quote === '' || preg_match('/^\d+(?:[.,]\d+)?/u', $qty, $m) !== 1) {
            return false;
        }

        return preg_match('/(?<![\d.,])'.preg_quote($m[0], '/').'(?![.,]?\d)/u', $quote) === 1;
    }

    /**
     * Czy rozmiar stoi w słowach klienta jako osobne oznaczenie („rozmiar M-XL”).
     * Jednoliterowy rozmiar porównujemy z wielkością liter — „M” to nie „m” z „1,5 m”.
     */
    private static function mentions(string $quote, string $size): bool
    {
        $flags = mb_strlen($size) > 1 ? 'iu' : 'u';

        return $quote !== ''
            && preg_match('/(?<![\p{L}\d])'.preg_quote($size, '/').'(?![\p{L}\d])/'.$flags, $quote) === 1;
    }

    /**
     * Nasza propozycja: nazwa, kod i normy, opis, zamiennik, a pod kreską cena
     * i ilość. Rozmiaru tu nie ma — pochodzi z zapytania, a nie z naszej karty,
     * więc stoi w słowach klienta. Pozycja bez wyrobu dostaje przerywaną ramkę
     * i zdanie z treści listu.
     *
     * @param  array<string, mixed>  $row
     */
    private static function oursBlock(array $row, ?int $sameAs): string
    {
        $facts = self::facts($row);
        $name = self::productName($row);
        $notes = self::notes($row);

        $html = self::label('Nasza propozycja', $name === null ? self::MUTED : self::TOTAL_BG);
        if ($name !== null) {
            $html .= '<div style="font-size:14px;font-weight:bold;color:'.self::TEXT.';line-height:1.3;margin-top:4px">'
                .self::text($name).'</div>';
            $meta = array_values(array_filter([
                ($code = trim((string) ($facts['code'] ?? ''))) === '' ? null : 'kod '.$code,
                ($norms = trim((string) ($facts['norms'] ?? ''))) === '' ? null : $norms,
            ]));
            if ($meta !== []) {
                $html .= '<div style="font-size:12px;color:'.self::MUTED.';margin-top:2px">'.self::text(implode(' · ', $meta)).'</div>';
            }
        }

        if ($sameAs !== null) {
            $html .= '<div style="font-size:12px;color:'.self::MUTED.';margin-top:6px">'
                .self::text('Opis jak w poz. '.$sameAs.'.').'</div>';
        } else {
            foreach ($notes['body'] as $text) {
                $html .= '<div style="font-size:13px;color:'.($name === null ? self::MUTED : self::BODY).';margin-top:6px">'
                    .self::text($text).'</div>';
            }
        }
        $html .= self::substituteHtml($notes['substitute']);
        if ($name !== null) {
            $html .= self::priceLine($row);
        }

        $frame = $name === null
            ? 'background:'.self::NONE_BG.';border:1px dashed '.self::NONE_BORDER
            : 'background:'.self::OURS_BG.';border:1px solid '.self::OURS_BORDER;

        return self::block($html, $frame);
    }

    /**
     * Cena i ilość pod kreską. Gdy kwoty nie ma, a list mówi o cenie
     * („Cena: do potwierdzenia”), idzie to samo zdanie co w wersji tekstowej.
     *
     * @param  array<string, mixed>  $row
     */
    private static function priceLine(array $row): string
    {
        $facts = self::facts($row);

        $parts = [];
        $price = trim((string) ($facts['price'] ?? ''));
        if ($price !== '') {
            $unit = self::unitOf($facts);
            $parts[] = self::text('Cena: ').self::strong($price)
                .self::text(' netto'.($unit === '' ? '' : ' / '.self::singular($unit)));
        } elseif (($said = self::lineWithRole($row, 'price')) !== null) {
            $parts[] = self::text($said);
        }
        $qty = trim((string) ($facts['qty'] ?? ''));
        if ($qty !== '') {
            $parts[] = self::text('Ilość: ').self::strong($qty);
        }
        if ($parts === []) {
            return '';
        }

        return '<div style="font-size:12px;color:'.self::BODY.';margin-top:8px;padding-top:7px;border-top:1px solid '.self::BORDER.'">'
            .implode(self::text(' · '), $parts).'</div>';
    }

    /**
     * Zamiennik dostaje własny, oddzielony blok: to inna propozycja niż wyrób
     * z pozycji i nie może się z nim zlewać.
     *
     * @param  list<array{text: string, strong: bool}>  $lines
     */
    private static function substituteHtml(array $lines): string
    {
        if ($lines === []) {
            return '';
        }

        $html = '<div style="margin-top:12px;padding-top:10px;border-top:1px dashed '.self::BORDER.'">'
            .self::label('Zamiennik', self::MUTED);
        foreach ($lines as $line) {
            $html .= '<div style="font-size:12px;color:'.self::BODY.';'.($line['strong'] ? 'font-weight:bold;' : '')
                .'margin-top:4px">'.self::text($line['text']).'</div>';
        }

        return $html.'</div>';
    }

    /**
     * Warunki i suma pod pozycjami, obok siebie.
     *
     * @param  list<array{label: string, value: string}>  $terms
     * @param  list<array<string, mixed>>  $rows
     */
    private static function closing(array $terms, array $rows): string
    {
        $cells = [];
        $termsHtml = self::terms($terms);
        if ($termsHtml !== '') {
            $cells[] = '<td style="vertical-align:top;background:'.self::TILE_BG.';color:'.self::TEXT.';'
                .'border-radius:12px;padding:12px 14px">'.$termsHtml.'</td>';
        }
        $summary = self::summary($rows);
        if ($summary !== '') {
            $cells[] = '<td style="'.($cells === [] ? '' : 'width:38%;').'vertical-align:top;background:'.self::TOTAL_BG.';'
                .'color:#ffffff;border-radius:12px;padding:12px 14px">'.$summary.'</td>';
        }
        if ($cells === []) {
            return '';
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0;'
            .'width:100%;margin:2px 0 0"><tr>'.implode('<td style="width:10px"></td>', $cells).'</tr></table>';
    }

    /**
     * Warunki po dwa w wierszu — klient pyta o nie wprost, więc stoją osobno,
     * a nie w akapicie razem z dopiskiem handlowca.
     *
     * @param  list<array{label: string, value: string}>  $terms
     */
    private static function terms(array $terms): string
    {
        if ($terms === []) {
            return '';
        }

        $rows = '';
        foreach (array_chunk($terms, 2) as $pair) {
            $rows .= '<tr>';
            foreach ($pair as $term) {
                $rows .= '<td style="vertical-align:top;font-size:12px;color:'.self::BODY.';padding:3px 12px 0 0">'
                    .self::text($term['label'].': ')
                    .'<b style="color:'.self::TEXT.'">'.self::text($term['value']).'</b></td>';
            }
            $rows .= '</tr>';
        }

        return self::label('Warunki', self::MUTED)
            .'<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-top:2px">'.$rows.'</table>';
    }

    /**
     * Suma w zielonym kafelku. Liczymy tylko pozycje, które mają wartość — gdy
     * choć jednej brakuje ceny albo ilości, piszemy wprost, których pozycji suma
     * nie obejmuje, zamiast podawać sumę części oferty jako całość.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function summary(array $rows): string
    {
        $sum = 0.0;
        $counted = 0;
        $missing = [];
        $unpriced = true;
        foreach ($rows as $index => $row) {
            $facts = self::facts($row);
            $value = $facts['total_pln'] ?? null;
            if (is_numeric($value)) {
                $sum += (float) $value;
                $counted++;

                continue;
            }
            $missing[] = $index + 1;
            if (trim((string) ($facts['price'] ?? '')) !== '') {
                $unpriced = false;
            }
        }
        if ($counted === 0) {
            return '';
        }

        $html = self::label('Razem netto', self::TOTAL_SOFT)
            .'<div style="font-size:22px;font-weight:bold;color:#ffffff;white-space:nowrap">'
            .self::text(number_format($sum, 2, ',', ' ').' zł').'</div>';
        $quantity = self::quantitySum($rows);
        if ($missing !== []) {
            // Pozycja z ceną, ale bez ilości też nie ma wartości — wtedy nie piszemy „po weryfikacji”.
            $text = 'bez poz. '.self::numberList($missing)
                .($unpriced ? ' – '.(count($missing) === 1 ? 'jej' : 'ich').' cenę podamy po weryfikacji' : '');
            $html .= '<div style="font-size:11px;color:'.self::TOTAL_SOFT.'">'.self::text($text).'</div>';
        } elseif ($quantity !== null) {
            $html .= '<div style="font-size:11px;color:'.self::TOTAL_SOFT.'">'.self::text($quantity).'</div>';
        }

        return $html;
    }

    /**
     * „4”, „4 i 5”, „4, 5 i 7”.
     *
     * @param  list<int>  $numbers
     */
    private static function numberList(array $numbers): string
    {
        $last = array_pop($numbers);

        return $numbers === [] ? (string) $last : implode(', ', $numbers).' i '.$last;
    }

    /**
     * Nazwa wyrobu w propozycji albo null, gdy pozycja wyrobu nie ma. Szablon
     * „bez SKU” nie podaje nazwy z katalogu — nazwą jest wtedy zdanie opisowe
     * z treści listu (rola „name”).
     *
     * @param  array<string, mixed>  $row
     */
    private static function productName(array $row): ?string
    {
        $name = trim((string) (self::facts($row)['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return self::lineWithRole($row, 'name');
    }

    /**
     * Jednostka z ilości klienta („96 par” → „par”).
     *
     * @param  array<string, mixed>  $facts
     */
    private static function unitOf(array $facts): string
    {
        return trim(preg_replace('/^[\d\s.,]+/u', '', trim((string) ($facts['qty'] ?? ''))) ?? '');
    }

    /**
     * Suma ilości („480 par”) — tylko gdy każda pozycja ma liczbę i wszystkie tę
     * samą jednostkę. Par i kartonów nie dodajemy.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function quantitySum(array $rows): ?string
    {
        $sum = 0.0;
        $unit = null;
        foreach ($rows as $row) {
            $qty = trim((string) (self::facts($row)['qty'] ?? ''));
            if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(\S.*)$/u', $qty, $m) !== 1) {
                return null;
            }
            $rowUnit = mb_strtolower(trim($m[2]));
            if ($unit !== null && $rowUnit !== $unit) {
                return null;
            }
            $unit = $rowUnit;
            $sum += (float) str_replace(',', '.', $m[1]);
        }
        if ($unit === null) {
            return null;
        }
        $number = floor($sum) === $sum ? number_format($sum, 0, ',', ' ') : number_format($sum, 2, ',', ' ');

        return $number.' '.$unit;
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
            str_starts_with($lower, 'karton') => 'karton',
            default => $unit,
        };
    }

    private static function plural(int $count, string $one, string $few, string $many): string
    {
        if ($count === 1) {
            return $one;
        }
        $tens = $count % 100;
        $units = $count % 10;

        return $units >= 2 && $units <= 4 && ($tens < 12 || $tens > 14) ? $few : $many;
    }

    /**
     * Pierwsza linia treści o podanej roli — stąd bierze się nazwa w szablonie,
     * który nie pozwala pokazać nazwy katalogowej, i cena bez kwoty.
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
     * Opis wyrobu i linie zamiennika — wszystko, co nie ma własnego miejsca
     * w bloku propozycji (nazwa, normy, cena).
     *
     * @param  array<string, mixed>  $row
     * @return array{body: list<string>, substitute: list<array{text: string, strong: bool}>}
     */
    private static function notes(array $row): array
    {
        $lines = is_array($row['answer'] ?? null) ? $row['answer'] : [];
        $roles = is_array($row['answer_roles'] ?? null) ? $row['answer_roles'] : [];

        $body = [];
        $substitute = [];
        foreach ($lines as $index => $line) {
            $role = (string) ($roles[$index] ?? 'body');
            $text = trim((string) $line);
            if ($text === '') {
                continue;
            }
            // Nazwa, normy i cena wyrobu mają już swoje miejsce w bloku.
            if (in_array($role, ['name', 'meta', 'price'], true)) {
                continue;
            }
            if (str_starts_with($role, 'sub_')) {
                $substitute[] = ['text' => $text, 'strong' => $role === 'sub_name'];

                continue;
            }
            $body[] = $text;
        }

        return ['body' => $body, 'substitute' => $substitute];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function facts(array $row): array
    {
        return is_array($row['facts'] ?? null) ? $row['facts'] : [];
    }

    /** Treść od klienta i z katalogu trafia do maila jako tekst, nigdy jako znaczniki. */
    private static function text(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return nl2br($escaped, false);
    }
}
