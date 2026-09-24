<?php

declare(strict_types=1);

namespace App\Support;

/**
 * List do klienta w HTML, układ „bento”: po lewej jeden duży kafel na wyrób
 * (nazwa, cena, opis raz) z małymi kafelkami rozmiarów pod spodem, po prawej
 * kolumna kafli — zapytanie klienta, warunki i suma.
 *
 * Powstaje z tych samych danych co wersja tekstowa (`reply_body`), więc obie
 * mówią to samo. Style są wpisane w znaczniki, bo programy pocztowe wycinają
 * arkusze stylów — bez tego list rozpadłby się u odbiorcy. Zaokrąglenia pomija
 * stary Outlook; układ trzyma się wtedy na tłach.
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

    /** Beżowe tło listu, na którym stoją białe kafle. */
    private const PAGE_BG = '#f5f3ef';

    private const TILE_BG = '#ffffff';

    /** Kafelek rozmiaru. */
    private const SIZE_BG = '#fafaf9';

    private const BORDER = '#e7e5e4';

    /** Kafel zapytania klienta. */
    private const ASKED_BG = '#e7e5e4';

    /** Zieleń — suma i plakietka liczby rozmiarów. */
    private const TOTAL_BG = '#166534';

    private const TOTAL_SOFT = '#bbf7d0';

    private const BADGE_BG = '#ecfdf5';

    private const BADGE = '#047857';

    /** Tyle kafelków rozmiarów stoi w jednym rzędzie. */
    private const SIZES_PER_ROW = 3;

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
        // 60% okna: na pełnej szerokości opis rozlewał się w długie, męczące wiersze.
        // Dolna granica chroni wąskie okna (telefon), gdzie 60% byłoby za ciasne.
        $html = '<div style="'.self::FONT.';background:'.self::PAGE_BG.';padding:22px;border-radius:18px;'
            .'width:60%;min-width:320px;box-sizing:border-box">';
        $html .= self::paragraph($intro);

        $main = self::items($rows);
        $side = self::asked($asked).self::terms($terms).self::summary($rows);
        if ($main === '' || $side === '') {
            $html .= $main.$side;
        } else {
            $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%">'
                .'<tr><td style="vertical-align:top;color:'.self::TEXT.'">'.$main.'</td>'
                .'<td style="width:12px"></td>'
                .'<td style="width:32%;vertical-align:top;color:'.self::TEXT.'">'.$side.'</td></tr></table>';
        }

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

    /** Drobne wersaliki nad treścią kafla. */
    private static function caption(string $label, string $color = self::MUTED): string
    {
        return '<div style="font-size:10px;letter-spacing:1.2px;text-transform:uppercase;color:'.$color.'">'
            .self::text($label).'</div>';
    }

    /** Kafel w prawej kolumnie; kolejne kafle dzieli odstęp nad nimi. */
    private static function sideTile(string $content, string $background, string $color): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;'
            .'width:100%;margin:0 0 12px"><tr><td style="background:'.$background.';color:'.$color.';'
            .'border-radius:16px;padding:16px">'.$content.'</td></tr></table>';
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

        // List czyta klient — zwracamy się do niego, jak w makiecie.
        $html = self::caption('Państwa zapytanie');
        if ($title !== '') {
            $html .= '<div style="font-size:13px;font-weight:bold;color:'.self::TEXT.';margin-top:4px">'
                .self::text($title).'</div>';
        }
        if ($date !== '') {
            $html .= '<div style="font-size:12px;color:#57534e">'.self::text($date).'</div>';
        }
        if ($lines !== []) {
            $html .= '<div style="font-size:12px;color:'.self::BODY.';margin-top:8px;line-height:1.7">'
                .self::text(implode("\n", $lines)).'</div>';
        }

        return self::sideTile($html, self::ASKED_BG, self::TEXT);
    }

    /**
     * Warunki w jednym kafelku, każdy w osobnej linii — klient pyta o nie wprost,
     * więc stoją osobno, a nie w akapicie razem z dopiskiem handlowca.
     *
     * @param  list<array{label: string, value: string}>  $terms
     */
    private static function terms(array $terms): string
    {
        if ($terms === []) {
            return '';
        }

        $html = self::caption('Warunki');
        foreach ($terms as $term) {
            $html .= '<div style="font-size:12px;color:'.self::BODY.';margin-top:4px">'
                .self::text($term['label'].': ')
                .'<b style="color:'.self::TEXT.'">'.self::text($term['value']).'</b></div>';
        }

        return self::sideTile($html, self::TILE_BG, self::TEXT);
    }

    /**
     * Suma w zielonym kafelku. Liczymy tylko pozycje, które mają wartość — gdy
     * choć jednej brakuje ceny albo ilości, mówimy o tym wprost zamiast podawać
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
            $facts = self::facts($row);
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

        $html = self::caption('Razem netto', self::TOTAL_SOFT)
            .'<div style="font-size:26px;font-weight:bold;color:#ffffff;white-space:nowrap">'
            .self::text(number_format($sum, 2, ',', ' ').' zł').'</div>';
        $quantity = self::quantitySum($rows);
        if ($missing > 0) {
            $html .= '<div style="font-size:11px;color:'.self::TOTAL_SOFT.'">'
                .self::text('bez pozycji, dla których podamy cenę po weryfikacji').'</div>';
        } elseif ($quantity !== null) {
            $html .= '<div style="font-size:11px;color:'.self::TOTAL_SOFT.'">'.self::text($quantity).'</div>';
        }

        return self::sideTile($html, self::TOTAL_BG, '#ffffff');
    }

    /**
     * Pozycje z tym samym wyrobem stoją w jednym kaflu — opis wyrobu klient czyta
     * raz, a rozmiary widzi obok siebie. Łączymy tylko pozycje, które w liście
     * wyglądałyby identycznie poza rozmiarem, ilością i ceną; inny opis albo inny
     * zamiennik to już osobny kafel.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function items(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $groups = [];
        foreach ($rows as $index => $row) {
            $key = self::groupKey($row);
            if ($key === null) {
                $groups[] = ['rows' => [$row], 'numbers' => [$index + 1]];

                continue;
            }
            $groups[$key] ??= ['rows' => [], 'numbers' => []];
            $groups[$key]['rows'][] = $row;
            $groups[$key]['numbers'][] = $index + 1;
        }

        $html = '<div style="font-size:10px;letter-spacing:1.4px;text-transform:uppercase;color:'.self::MUTED.';'
            .'margin:0 0 10px">'.self::text('Propozycja').'</div>';
        foreach ($groups as $group) {
            $html .= self::productTile($group['rows'], $group['numbers']);
        }

        return $html;
    }

    /**
     * Klucz wyrobu albo null, gdy pozycja nie ma wyrobu (wtedy zawsze stoi sama).
     *
     * @param  array<string, mixed>  $row
     */
    private static function groupKey(array $row): ?string
    {
        $name = self::productName($row, false);
        if ($name === null) {
            return null;
        }
        $facts = self::facts($row);

        return json_encode([
            $name,
            trim((string) ($facts['code'] ?? '')),
            trim((string) ($facts['norms'] ?? '')),
            self::notes($row),
        ], JSON_UNESCAPED_UNICODE) ?: null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows  pozycje jednego wyrobu
     * @param  list<int>  $numbers  numery pozycji w liście
     */
    private static function productTile(array $rows, array $numbers): string
    {
        $first = $rows[0];
        $facts = self::facts($first);
        $name = self::productName($first, true) ?? '';

        $meta = array_values(array_filter([
            ($code = trim((string) ($facts['code'] ?? ''))) === '' ? null : 'kod '.$code,
            ($norms = trim((string) ($facts['norms'] ?? ''))) === '' ? null : $norms,
        ]));

        $head = '';
        $badge = self::badge($rows);
        if ($badge !== null) {
            $head .= '<span style="display:inline-block;background:'.self::BADGE_BG.';color:'.self::BADGE.';'
                .'font-size:11px;font-weight:bold;border-radius:20px;padding:3px 10px;margin-bottom:10px">'
                .self::text($badge).'</span>';
        }
        $head .= '<div style="font-size:20px;font-weight:bold;color:'.self::TEXT.';line-height:1.3">'.self::text($name).'</div>';
        if ($meta !== []) {
            $head .= '<div style="font-size:12px;color:'.self::MUTED.';margin-top:2px">'.self::text(implode(' · ', $meta)).'</div>';
        }

        // Cena za jednostkę stoi przy nazwie tylko wtedy, gdy cena i jednostka są
        // te same dla wszystkich rozmiarów; inaczej każdy kafelek podaje swoją.
        $prices = array_values(array_unique(array_map(
            fn (array $row): string => (string) self::unitPrice(self::facts($row)),
            $rows,
        )));
        $sharedPrice = count($prices) === 1 && $prices[0] !== ''
            ? trim((string) ($facts['price'] ?? ''))
            : null;
        $priceCell = '';
        if ($sharedPrice !== null) {
            $unit = self::unitOf($facts);
            $priceCell = '<div style="font-size:26px;font-weight:bold;color:'.self::TEXT.'">'.self::text($sharedPrice).'</div>'
                .'<div style="font-size:11px;color:'.self::MUTED.'">'
                .self::text($unit === '' ? 'netto' : 'netto / '.self::singular($unit)).'</div>';
        }

        $html = '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin:0 0 12px">'
            .'<tr><td style="background:'.self::TILE_BG.';color:'.self::TEXT.';border-radius:16px;padding:20px">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%"><tr>'
            .'<td style="vertical-align:top;color:'.self::TEXT.'">'.$head.'</td>'
            .($priceCell === '' ? '' : '<td style="vertical-align:top;text-align:right;white-space:nowrap;padding-left:14px;color:'.self::TEXT.'">'.$priceCell.'</td>')
            .'</tr></table>'
            .self::notesHtml($first)
            .self::sizeTiles($rows, $numbers, $sharedPrice === null)
            .'</td></tr></table>';

        return $html;
    }

    /**
     * „3 rozmiary · 480 par” nad nazwą — tylko gdy wyrób ma kilka pozycji.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function badge(array $rows): ?string
    {
        $count = count($rows);
        if ($count < 2) {
            return null;
        }
        $allSized = array_filter($rows, fn (array $row): bool => trim((string) (self::facts($row)['size'] ?? '')) !== '') === $rows;
        $label = $count.' '.($allSized
            ? self::plural($count, 'rozmiar', 'rozmiary', 'rozmiarów')
            : self::plural($count, 'pozycja', 'pozycje', 'pozycji'));
        $quantity = self::quantitySum($rows);

        return $quantity === null ? $label : $label.' · '.$quantity;
    }

    /**
     * Małe kafelki rozmiarów pod opisem, po trzy w rzędzie.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $numbers
     */
    private static function sizeTiles(array $rows, array $numbers, bool $withPrice): string
    {
        $tiles = [];
        foreach ($rows as $index => $row) {
            $facts = self::facts($row);
            $size = trim((string) ($facts['size'] ?? ''));
            $qty = trim((string) ($facts['qty'] ?? ''));
            $total = trim((string) ($facts['total'] ?? ''));
            $price = $withPrice ? self::unitPrice($facts) : null;
            if ($size === '' && $qty === '' && $total === '' && $price === null) {
                continue;
            }

            // Rozmiar jest z zapytania klienta, nie z naszej karty — kafelek pod nazwą wyrobu z samym
            // „rozmiar” czytał się jak potwierdzenie (karta „rozmiar S” z kafelkiem „M-XL”). Tak samo
            // pisze wersja tekstowa listu.
            [$label, $value] = $size !== ''
                ? ['rozmiar z zapytania', $size]
                : ['pozycja', (string) ($numbers[$index] ?? $index + 1)];
            $line = '';
            if ($qty !== '') {
                $line .= self::text($qty);
            }
            if ($total !== '') {
                $line .= ($line === '' ? '' : ' · ').'<b style="color:'.self::TEXT.'">'.self::text($total).'</b>';
            }

            $tiles[] = '<div style="font-size:11px;color:'.self::MUTED.'">'.self::text($label).'</div>'
                .'<div style="font-size:18px;font-weight:bold;color:'.self::TEXT.'">'.self::text($value).'</div>'
                .($line === '' ? '' : '<div style="font-size:12px;color:'.self::BODY.'">'.$line.'</div>')
                .($price === null ? '' : '<div style="font-size:11px;color:'.self::MUTED.'">'.self::text($price).'</div>');
        }
        if ($tiles === []) {
            return '';
        }

        $html = '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:separate;'
            .'border-spacing:8px;width:100%;margin:8px -8px -8px;table-layout:fixed">';
        foreach (array_chunk($tiles, self::SIZES_PER_ROW) as $chunk) {
            $html .= '<tr>';
            foreach ($chunk as $tile) {
                $html .= '<td style="vertical-align:top;background:'.self::SIZE_BG.';border:1px solid '.self::BORDER.';'
                    .'border-radius:12px;padding:10px 12px;color:'.self::TEXT.'">'.$tile.'</td>';
            }
            // Puste komórki trzymają szerokość kafelków w ostatnim, niepełnym rzędzie.
            for ($i = count($chunk); $i < self::SIZES_PER_ROW; $i++) {
                $html .= '<td></td>';
            }
            $html .= '</tr>';
        }

        return $html.'</table>';
    }

    /**
     * Nazwa wyrobu w kaflu. Szablon „bez SKU” nie podaje nazwy z katalogu —
     * nagłówkiem jest wtedy zdanie opisowe z treści listu. Gdy i tego nie ma
     * (brak karty), zostaje nagłówek pozycji z zapytania klienta.
     *
     * @param  array<string, mixed>  $row
     */
    private static function productName(array $row, bool $fallbackToHead): ?string
    {
        $name = trim((string) (self::facts($row)['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $name = self::lineWithRole($row, 'name');
        if ($name !== null) {
            return $name;
        }
        if (! $fallbackToHead) {
            return null;
        }
        $head = trim((string) ($row['head'] ?? ''));

        return $head === '' ? null : $head;
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
        $unit = self::unitOf($facts);

        return $unit === '' ? $price : $price.' / '.self::singular($unit);
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
     * Opis wyrobu i linie zamiennika — wszystko, co nie ma miejsca w nagłówku
     * kafla i kafelkach rozmiarów.
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
            // Nazwa, normy i cena wyrobu mają już swoje miejsce w kaflu.
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
     * Zamiennik dostaje własny, oddzielony blok: to inna propozycja niż wyrób
     * z pozycji i nie może się z nim zlewać.
     *
     * @param  array<string, mixed>  $row
     */
    private static function notesHtml(array $row): string
    {
        $notes = self::notes($row);
        $html = '';
        foreach ($notes['body'] as $text) {
            $html .= '<div style="font-size:13px;color:'.self::BODY.';margin-top:12px">'.self::text($text).'</div>';
        }

        if ($notes['substitute'] !== []) {
            $html .= '<div style="margin-top:12px;padding-top:10px;border-top:1px dashed '.self::BORDER.'">'
                .self::caption('Zamiennik');
            foreach ($notes['substitute'] as $line) {
                $html .= '<div style="font-size:12px;color:'.self::BODY.';'.($line['strong'] ? 'font-weight:bold;' : '')
                    .'margin-top:4px">'.self::text($line['text']).'</div>';
            }
            $html .= '</div>';
        }

        return $html;
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
