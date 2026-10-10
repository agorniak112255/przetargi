<?php

declare(strict_types=1);

namespace App\Services\PriceLists\Importers\Mapa;

use App\Models\Product;
use App\Services\PriceLists\Importers\ImportedRow;
use App\Services\PriceLists\Importers\MapContext;
use App\Services\PriceLists\Importers\PriceListFormatChanged;
use App\Services\PriceLists\Importers\PriceListImporter;
use App\Services\PriceLists\Importers\ReadContext;
use App\Services\PriceLists\Importers\ReadResult;
use App\Services\PriceLists\Importers\SourceDecision;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Pilot importera cennika z pliku (10.10.2026): „MAPA SUPON - cennik bazowy & ceny specjalne_2025.xlsx”.
 *
 * Układ pliku (sprawdzony na pliku z 01.01.2025):
 * - arkusz „Cennik bazowy 2025”: nad nagłówkiem „Cennik bazowy w walucie €”, „Obowiązuje od …”, legenda „VM* - opakowanie
 *   vendingowe”; nagłówek „Kod produktu | Nazwa produktu | 2025” (rok jako nagłówek ceny); w kolumnie D bez nagłówka
 *   znacznik „Wycofane” / „Nowość”. Cena = cena katalogowa netto w EUR za parę, 3 miejsca po przecinku. Kod MAPA = 8 cyfr
 *   „34” + numer modelu (3 cyfry) + 3 cyfry wariantu — nazwa to linia i ten sam numer („ULTRANITRIL 492 UNIT”).
 * - arkusz „Supon - ceny specjalne”: ceny projektowe dla konkretnych klientów (kolumna „Projekt”) — nie są ceną zakupu
 *   wyrobu, nie importujemy ich (tylko uwaga w podglądzie). Dawny import (cennik #1, 10.09.2026) też ich nie brał.
 *
 * Mapa źródła (decyzja właściciela 10.10.2026, deterministycznie): karta → strona produktu mapa-pro (najpierw .pl, potem
 * .com), której adres kończy się linią i DOKŁADNIE numerem modelu karty (492 ≠ 4920). Kilka stron → zawężenie pełną
 * nazwą, potem linią; dalej kilka → nierozwiązana. Bez stron spoza katalogu produktów (aktualności też mają numery).
 */
final class MapaPriceListImporter implements PriceListImporter
{
    public const SHEET_PREFIX = 'Cennik bazowy';

    public const SPECIAL_SHEET_PREFIX = 'Supon - ceny specjalne';

    /** hosty w kolejności pierwszeństwa — grupa .pl przed .com */
    public const HOST_GROUPS = [
        ['mapa-pro.pl', 'www.mapa-pro.pl'],
        ['mapa-pro.com', 'www.mapa-pro.com'],
    ];

    /** segment ścieżki strony produktu (polska i angielska witryna) */
    private const PRODUCT_PATH_MARKERS = ['/strona-produktu/', '/product-page/'];

    /** końcówki nazwy oznaczające opakowanie (legenda pliku: „VM* - opakowanie vendingowe”) */
    private const PACKAGING_WORDS = [
        'VM' => 'VM (opakowanie vendingowe)',
        'POLYBAG' => 'POLYBAG',
        'UNIT' => 'UNIT',
        'BOX' => 'BOX',
    ];

    private const HEADER_SCAN_ROWS = 20;

    public static function key(): string
    {
        return 'mapa-2025';
    }

    public static function label(): string
    {
        return 'MAPA — cennik bazowy i ceny specjalne 2025';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function manufacturerKeys(): array
    {
        return ['mapa'];
    }

    public function read(string $path, string $originalName, ReadContext $ctx): ReadResult
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        $sheet = null;
        $special = null;
        foreach ($book->getWorksheetIterator() as $candidate) {
            $title = trim($candidate->getTitle());
            if (str_starts_with(mb_strtolower($title), mb_strtolower(self::SHEET_PREFIX))) {
                if ($sheet !== null) {
                    throw PriceListFormatChanged::because('dwa arkusze „'.self::SHEET_PREFIX.' …” — nie wiadomo, który czytać.');
                }
                $sheet = $candidate;
            } elseif (str_starts_with(mb_strtolower($title), mb_strtolower(self::SPECIAL_SHEET_PREFIX))) {
                $special = $candidate;
            }
        }
        if ($sheet === null) {
            throw PriceListFormatChanged::because('brak arkusza „'.self::SHEET_PREFIX.' …” (są: '.implode(', ', $book->getSheetNames()).').');
        }
        $sheetName = trim($sheet->getTitle());

        [$headerRow, $year, $currency] = $this->header($sheet, $sheetName);

        $rows = [];
        $skipped = [];
        $seen = [];
        $total = 0;
        $news = 0;
        /** @var array<string, list<string>> $unknownFlags */
        $unknownFlags = [];
        $rounded = 0;
        $highest = $sheet->getHighestDataRow();
        for ($r = $headerRow + 1; $r <= $highest; $r++) {
            $codeCell = $this->text($sheet, 'A', $r);
            $name = $this->text($sheet, 'B', $r);
            $priceCell = $this->cell($sheet, 'C', $r);
            $flag = $this->text($sheet, 'D', $r);
            if ($codeCell === '' && $name === '' && $this->blank($priceCell) && $flag === '') {
                continue;
            }
            $total++;
            $ref = $sheetName.'!'.$r;
            $code = $this->code($sheet, $r);

            if ($code === null) {
                $skipped[] = ['ref' => $ref, 'sku' => $codeCell !== '' ? $codeCell : null, 'reason' => $codeCell === ''
                    ? 'brak kodu produktu'
                    : 'kod spoza wzoru MAPA (8 cyfr): „'.$codeCell.'”'];

                continue;
            }
            if ($name === '') {
                $skipped[] = ['ref' => $ref, 'sku' => $code, 'reason' => 'brak nazwy produktu'];

                continue;
            }
            if (mb_strtolower($flag) === 'wycofane') {
                $skipped[] = ['ref' => $ref, 'sku' => $code, 'reason' => 'wycofane (kolumna D „Wycofane”)'];

                continue;
            }
            $price = $this->price($priceCell);
            if ($price === null) {
                $skipped[] = ['ref' => $ref, 'sku' => $code, 'reason' => 'brak ceny'];

                continue;
            }
            if (isset($seen[$code])) {
                $skipped[] = ['ref' => $ref, 'sku' => $code, 'reason' => 'kod powtórzony (pierwszy w wierszu '.$seen[$code].')'];

                continue;
            }
            $seen[$code] = $r;
            if (mb_strtolower($flag) === 'nowość') {
                $news++;
            } elseif ($flag !== '') {
                $unknownFlags[$flag][] = $ref;
            }

            // „TEMP-TEC 332 SIZE 8” — cena dotyczy rozmiaru 8; nazwa karty bez rozmiaru (jak dawny import), rozmiar w atrybucie
            $attributes = [];
            if (preg_match('/^(.*\S)\s+SIZE\s+(\d{1,2}(?:[.,]5)?|XXS|XS|S|M|L|XL|XXL|XXXL)$/iu', $name, $m) === 1) {
                $name = $m[1];
                $attributes['rozmiar'] = mb_strtoupper($m[2]);
            }
            $net = round($price, 2);
            if (abs($net - $price) > 0.0000001) {
                $rounded++;
            }

            $rows[] = new ImportedRow(
                sku: $code,
                name: $name,
                catalogPriceNet: $net,
                ref: $ref,
                currency: $currency,
                modelName: self::modelOf($name),
                attributes: $attributes,
            );
        }

        $notes = [
            'Ceny katalogowe netto w '.$currency.' z kolumny „'.$year.'” arkusza „'.$sheetName.'”; zakup liczy rabat wspólny cennika (plik nie podaje ceny zakupu).',
        ];
        if ($rounded > 0) {
            $notes[] = 'Ceny w pliku mają więcej niż 2 miejsca po przecinku — '.$rounded.' zaokrąglono do 2 (tyle przechowuje karta, jak dawny import).';
        }
        if ($news > 0) {
            $notes[] = 'Pozycje oznaczone „Nowość”: '.$news.'.';
        }
        foreach ($unknownFlags as $flag => $refs) {
            $notes[] = 'Nieznany znacznik w kolumnie D „'.$flag.'” (zaimportowano jak zwykłą pozycję): '.implode(', ', $refs).'.';
        }
        $notes = [...$notes, ...$this->specialSheetNotes($special, $rows)];

        return new ReadResult($rows, $skipped, $total, $notes);
    }

    public function mapSource(Product $card, array $rows, MapContext $ctx): SourceDecision
    {
        $row = $rows[0] ?? null;
        $name = $row !== null && trim($row->name) !== '' ? trim($row->name) : trim((string) $card->name);
        $sku = $row !== null ? $row->sku : trim((string) $card->sku);

        $model = self::parseModel($name);
        if ($model === null) {
            return SourceDecision::unresolved('nazwa bez jednego numeru modelu MAPA: „'.$name.'”');
        }
        [$line, $number, $full] = $model;
        if (preg_match('/^34(\d{3})\d{3}$/', $sku, $m) === 1 && $m[1] !== $number) {
            return SourceDecision::unresolved('numer w nazwie ('.$number.') nie zgadza się z kodem '.$sku.' ('.$m[1].')');
        }

        $mismatched = [];
        foreach (self::HOST_GROUPS as $hosts) {
            $pages = $this->productPages($ctx->pagesWithCode($number, $hosts), $number, $ctx);
            if ($pages === []) {
                continue;
            }
            $exact = array_values(array_filter($pages, static fn (array $p): bool => $p['letters'] === $full));
            $byLine = array_values(array_filter($pages, static fn (array $p): bool => $p['letters'] === $line));
            [$chosen, $level] = match (true) {
                $exact !== [] => [$exact, 'pełna nazwa'],
                $byLine !== [] => [$byLine, 'linia'],
                default => [[], null],
            };
            if ($chosen === []) {
                foreach ($pages as $page) {
                    $mismatched[] = ['url' => $page['url'], 'title' => $page['title'], 'reason' => 'inna nazwa wyrobu w adresie'];
                }

                continue;
            }
            if (count($chosen) > 1) {
                return SourceDecision::unresolved('kilka stron producenta', array_map(
                    static fn (array $p): array => ['url' => $p['url'], 'title' => $p['title'], 'reason' => 'ten sam numer '.$number.' i nazwa'],
                    $chosen,
                ));
            }

            return $this->pin($chosen[0], $pages, $level, $hosts, $model, $name, $sku, $row, $ctx);
        }

        if ($mismatched !== []) {
            return SourceDecision::unresolved('strona producenta z numerem '.$number.' ma inną nazwę wyrobu', $mismatched);
        }

        return SourceDecision::unresolved('brak strony producenta z tym numerem');
    }

    /**
     * @param  array{url: string, title: ?string, letters: string, slug: string}  $page
     * @param  list<array{url: string, title: ?string, letters: string, slug: string}>  $pages
     * @param  list<string>  $hosts
     * @param  array{0: string, 1: string, 2: string}  $model
     */
    private function pin(array $page, array $pages, string $level, array $hosts, array $model, string $name, string $sku, ?ImportedRow $row, MapContext $ctx): SourceDecision
    {
        [$line, $number] = $model;
        $title = $page['title'];
        $evidence = [
            'rule' => 'strona produktu mapa-pro z linią i numerem modelu w adresie',
            'model' => self::modelOf($name),
            'number' => $number,
            'host' => $hosts[0],
            'matched_by' => $level,
            'slug' => $page['slug'],
            'sku_carries_number' => preg_match('/^34'.$number.'\d{3}$/', $sku) === 1,
            'other_pages_with_number' => array_values(array_map(
                static fn (array $p): string => $p['url'],
                array_filter($pages, static fn (array $p): bool => $p['url'] !== $page['url']),
            )),
            'page_checked' => false,
        ];

        if ($ctx->liveFetch()) {
            $fetched = $ctx->fetch($page['url']);
            if ($fetched === null) {
                $evidence['page_check'] = 'strona nie odpowiedziała — przypięcie z indeksu';
            } else {
                $finalSlug = self::lastSegment((string) ($fetched['final_url'] ?? $page['url']));
                if ($finalSlug !== $page['slug']) {
                    return SourceDecision::unresolved('strona producenta przekierowuje na inny adres', [
                        ['url' => $page['url'], 'title' => $title, 'reason' => 'przekierowanie na '.(string) $fetched['final_url']],
                    ]);
                }
                $haystack = trim((string) ($fetched['title'] ?? '')).' '.$fetched['text'];
                if (! $ctx->carriesCode($haystack, [$number])) {
                    return SourceDecision::unresolved('strona producenta bez numeru '.$number.' w treści', [
                        ['url' => $page['url'], 'title' => $title, 'reason' => 'numer nie występuje na stronie'],
                    ]);
                }
                $evidence['page_checked'] = true;
                $evidence['page_check'] = 'numer '.$number.' na stronie';
                $fetchedTitle = trim((string) ($fetched['title'] ?? ''));
                if ($fetchedTitle !== '') {
                    $title = $fetchedTitle;
                }
            }
        }

        return SourceDecision::pinned(
            url: $page['url'],
            sourceKind: 'manufacturer',
            matchKind: 'model',
            matchKey: self::modelOf($name) ?? $line.' '.$number,
            pageTitle: $title,
            spec: $this->spec($sku, $name, $row),
            evidence: $evidence,
        );
    }

    /**
     * Strony produktu (nie aktualności, nie kategorie), których ostatni człon adresu niesie numer jako całe słowo.
     *
     * @param  list<array{url: string, title: string}>  $hits
     * @return list<array{url: string, title: ?string, letters: string, slug: string}>
     */
    private function productPages(array $hits, string $number, MapContext $ctx): array
    {
        $out = [];
        foreach ($hits as $hit) {
            $url = trim((string) ($hit['url'] ?? ''));
            $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
            $isProduct = false;
            foreach (self::PRODUCT_PATH_MARKERS as $marker) {
                $isProduct = $isProduct || str_contains($path, $marker);
            }
            $slug = self::lastSegment($url);
            if (! $isProduct || $slug === '' || isset($out[$url])) {
                continue;
            }
            $tokens = preg_split('/[^a-z0-9]+/', $slug, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $numbers = array_values(array_filter($tokens, static fn (string $t): bool => ctype_digit($t)));
            if ($numbers !== [$number] || ! $ctx->carriesCode($slug, [$number])) {
                continue;
            }
            $title = trim((string) ($hit['title'] ?? ''));
            $out[$url] = [
                'url' => $url,
                'title' => $title !== '' ? $title : null,
                'letters' => implode('', array_filter($tokens, static fn (string $t): bool => ! ctype_digit($t))),
                'slug' => $slug,
            ];
        }

        return array_values($out);
    }

    /**
     * Linia, numer i pełna nazwa (litery bez numeru) w postaci do porównania z adresem: „TEMP-ICE 700” → [tempice, 700,
     * tempice]; „SOLO 967 BOX” → [solo, 967, solobox]. null, gdy nazwa nie ma dokładnie jednego numeru 3–4 cyfrowego.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    public static function parseModel(string $name): ?array
    {
        $ascii = mb_strtolower(Str::ascii($name));
        $tokens = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $numberAt = null;
        foreach ($tokens as $i => $token) {
            if (ctype_digit($token)) {
                if ($numberAt !== null || strlen($token) < 3 || strlen($token) > 4) {
                    return null;
                }
                $numberAt = $i;
            }
        }
        if ($numberAt === null || $numberAt === 0) {
            return null;
        }
        $line = implode('', array_slice($tokens, 0, $numberAt));
        $full = implode('', array_filter($tokens, static fn (string $t): bool => ! ctype_digit($t)));

        return [$line, $tokens[$numberAt], $full];
    }

    /** „ULTRANITRIL 492 UNIT” → „ULTRANITRIL 492” (linia i numer), null bez numeru. */
    public static function modelOf(string $name): ?string
    {
        if (preg_match('/^(.*?\D)(?<!\d)(\d{3,4})(?!\d)/u', trim($name), $m) !== 1) {
            return null;
        }
        $line = trim(preg_replace('/\s+/u', ' ', $m[1]) ?? $m[1]);

        return $line !== '' ? $line.' '.$m[2] : null;
    }

    /** @return list<string> */
    private function spec(string $sku, string $name, ?ImportedRow $row): array
    {
        $spec = ['Kod: '.$sku, 'Nazwa w cenniku: '.$name];
        $suffix = preg_match('/\d{3,4}\s*-?\s*(.*)$/u', $name, $m) === 1 ? $m[1] : '';
        foreach (preg_split('/[^\p{L}]+/u', mb_strtoupper($suffix), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (isset(self::PACKAGING_WORDS[$word])) {
                $spec[] = 'Opakowanie: '.self::PACKAGING_WORDS[$word];
                break;
            }
        }
        if ($row !== null && isset($row->attributes['rozmiar'])) {
            $spec[] = 'Rozmiar w cenniku: '.$row->attributes['rozmiar'];
        }

        return $spec;
    }

    /**
     * Wiersz nagłówka „Kod produktu | Nazwa produktu | rok” i waluta z opisu nad nim.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function header(Worksheet $sheet, string $sheetName): array
    {
        $headerRow = null;
        $above = [];
        $last = min(self::HEADER_SCAN_ROWS, $sheet->getHighestDataRow());
        for ($r = 1; $r <= $last; $r++) {
            $a = mb_strtolower($this->text($sheet, 'A', $r));
            $b = mb_strtolower($this->text($sheet, 'B', $r));
            if ($a === 'kod produktu' && $b === 'nazwa produktu') {
                $headerRow = $r;
                break;
            }
            $above[] = $this->text($sheet, 'A', $r).' '.$this->text($sheet, 'B', $r);
        }
        if ($headerRow === null) {
            throw PriceListFormatChanged::because('w arkuszu „'.$sheetName.'” nie ma nagłówka „Kod produktu | Nazwa produktu” w pierwszych '.self::HEADER_SCAN_ROWS.' wierszach.');
        }
        $year = $this->text($sheet, 'C', $headerRow);
        if (preg_match('/^20\d{2}$/', $year) !== 1) {
            throw PriceListFormatChanged::because('kolumna C nagłówka to „'.$year.'” zamiast roku cennika (np. 2025).');
        }
        foreach (['D', 'E', 'F', 'G', 'H'] as $col) {
            $extra = $this->text($sheet, $col, $headerRow);
            if ($extra !== '') {
                throw PriceListFormatChanged::because('nowa kolumna „'.$extra.'” ('.$col.') w nagłówku arkusza „'.$sheetName.'”.');
            }
        }
        $intro = mb_strtolower(implode(' ', $above));
        $currency = match (true) {
            str_contains($intro, '€') || preg_match('/\beur\b/u', $intro) === 1 => 'EUR',
            preg_match('/\b(pln|zł)\b/u', $intro) === 1 => 'PLN',
            default => throw PriceListFormatChanged::because('nad nagłówkiem nie ma waluty cennika („Cennik bazowy w walucie €”).'),
        };

        return [$headerRow, $year, $currency];
    }

    /**
     * @param  list<ImportedRow>  $rows
     * @return list<string>
     */
    private function specialSheetNotes(?Worksheet $special, array $rows): array
    {
        if ($special === null) {
            return ['Brak arkusza „'.self::SPECIAL_SHEET_PREFIX.'” — w pliku są tylko ceny bazowe.'];
        }
        if (mb_strtolower($this->text($special, 'C', 1)) !== 'nazwa produktu' || mb_strtolower($this->text($special, 'B', 1)) !== 'projekt') {
            return ['Arkusz „'.trim($special->getTitle()).'” ma inny nagłówek niż „Klient | Projekt | Nazwa produktu | Cena …” — nie odczytano cen specjalnych.'];
        }
        $names = [];
        foreach ($rows as $row) {
            $names[mb_strtolower($row->name)] = true;
        }
        $count = 0;
        $projects = [];
        $unknown = [];
        $highest = $special->getHighestDataRow();
        for ($r = 2; $r <= $highest; $r++) {
            $name = $this->text($special, 'C', $r);
            if ($name === '') {
                continue;
            }
            $count++;
            $project = $this->text($special, 'B', $r);
            if ($project !== '') {
                $projects[$project] = true;
            }
            if (! isset($names[mb_strtolower($name)])) {
                $unknown[] = $name;
            }
        }
        $notes = ['Arkusz „'.trim($special->getTitle()).'”: '.$count.' cen projektowych dla klientów ('.implode(', ', array_keys($projects)).') — nie importowane, dotyczą konkretnych projektów, nie ceny zakupu wyrobu.'];
        if ($unknown !== []) {
            $notes[] = 'Ceny specjalne dla nazw spoza cennika bazowego: '.implode(', ', array_values(array_unique($unknown))).'.';
        }

        return $notes;
    }

    private function cell(Worksheet $sheet, string $col, int $row): mixed
    {
        if (! $sheet->cellExists($col.$row)) {
            return null;
        }

        return $sheet->getCell($col.$row)->getCalculatedValue();
    }

    private function text(Worksheet $sheet, string $col, int $row): string
    {
        $value = $this->cell($sheet, $col, $row);
        if ($value === null || is_bool($value)) {
            return '';
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            $value = sprintf('%.0f', $value);
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    /** Kod MAPA: 8 cyfr (w pliku raz liczba, raz tekst). */
    private function code(Worksheet $sheet, int $row): ?string
    {
        $code = str_replace(' ', '', $this->text($sheet, 'A', $row));

        return preg_match('/^\d{8}$/', $code) === 1 ? $code : null;
    }

    private function price(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            $price = (float) $value;
        } else {
            $text = str_replace([' ', "\u{00A0}", '€'], '', trim((string) $value));
            $text = str_replace(',', '.', $text);
            if ($text === '' || ! is_numeric($text)) {
                return null;
            }
            $price = (float) $text;
        }

        return $price > 0 ? $price : null;
    }

    private function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private static function lastSegment(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        return $segments === [] ? '' : mb_strtolower(urldecode((string) end($segments)));
    }
}
