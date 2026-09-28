<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * www.ardon.pl — sklep ARDON: własna marka ARDON i wyroby innych producentów (3M, ATG, Honeywell, UNIVET, SHOWA…).
 * Lista i ceny konta z cennika CSV konta (ArdonB2bClient::priceListCsv — wszystkie pozycje wszystkich marek),
 * treść karty (opis, tabelka „Parametry”, zdjęcie, pliki PDF, producent) ze strony produktu.
 *
 * Rozmiary jednego wyrobu są w cenniku osobnymi pozycjami z kodem „kod/rozmiar” („A3031/07”, „A1002/10,5”,
 * „A1013/10/SPE”); nazwa jest ta sama dla wszystkich rozmiarów kodu (sprawdzone na całym cenniku 21.09.2026).
 * Karta = kod bazowy z nazwą, ze wszystkimi rozmiarami z ceną (decyzja użytkownika 28.09.2026: rozmiary w różnych
 * cenach to jedna karta; u Ardona 21.09.2026 różne ceny rozmiarów miało 321 z 2253 kodów). Każdy rozmiar niesie
 * swoją cenę konta i sugerowaną cenę detaliczną z wiersza cennika (members[].price), cena karty = najniższa cena
 * rozmiaru. Do 28.09.2026 (decyzja 15.09.2026) rozmiar w innej cenie był osobną kartą z pełnym kodem Ardon
 * („A5001/11”) — takie karty zostają, dopóki nie scali ich osobne polecenie (synchronizacja daje każdej jej
 * rozmiary, B2bCatalogSync::syncMembersByCard). Pozycja bez rozmiaru albo bez ceny konta w cenniku zostaje osobną
 * kartą, jak dotąd.
 *
 * SKU karty: kod bazowy Ardon — poza wyrobami ATG, których nazwa kończy się numerem artykułu producenta
 * („ATG® NBR-Lite® 24-985”). Katalog trzyma karty ATG pod numerem artykułu ATG i pod nim wiąże je łącznik witryny
 * producenta (AtgB2bConnector: normy, opis, pliki) — kod Ardon założyłby drugą kartę tego samego wyrobu.
 *
 * Producent: część kart ma w tabelce „Parametry” wiersz „Producent” z marką („3M”) — bierzemy go dosłownie (poza
 * odmienioną formą z PARAMETER_BRANDS). Wyrób z „ARDON®” w nazwie to marka ARDON, także gdy dane strukturalne
 * podają fabrykę, w której Ardon go zamawia.
 * Poza tym sklep podaje w danych strukturalnych tylko nazwę prawną dostawcy („3M Česko, spol. s r.o.”); na markę
 * zamieniamy tylko nazwy sprawdzone w MANUFACTURERS — nieznaną zostawiamy dosłownie i wypisujemy w podsumowaniu
 * przebiegu. Nazwa prawna trafia też dosłownie do tabelki sklepu („Marka w danych sklepu”).
 *
 * Ardon nie jest producentem tych marek, więc łącznik nie jest B2bManufacturerSite: opis ze sklepu nie nadpisuje
 * opisu z witryny producenta ani opisu zapisanego na karcie wcześniej.
 *
 * Część kart polskiej wersji sklepu ma opis po słowacku albo czesku (21.09.2026: 3M 1100 — „zátkový chránič
 * sluchu…”). Taki opis oznaczamy jako obcojęzyczny (B2bForeignTextCards), a synchronizacja zleca tłumaczenie
 * na polski. Rozpoznajemy go po literach, których polszczyzna nie ma (isForeignText).
 */
final class ArdonB2bConnector implements B2bConnector, B2bDocumentSource, B2bForeignTextCards, B2bGroupsSizes, B2bRunSummaryAware, B2bShopFieldSource, B2bSizePriceSource
{
    private const SHOP_SECTION = 'Parametry';

    private const TRADE_SECTION = 'Informacje handlowe';

    private const HEADING_DESCRIPTION = 'Opis i informacje techniczne';

    private const HEADING_PARAMETERS = 'Parametry';

    private const HEADING_DOWNLOADS = 'Do pobrania';

    /** Tyle adresów z podpowiedzi wyszukiwarki sprawdzamy, zanim uznamy, że strony wyrobu nie ma. */
    private const SEARCH_CANDIDATES = 3;

    /**
     * Nazwa prawna ze strony produktu → marka w katalogu. Tylko nazwy sprawdzone na stronach sklepu 21.09.2026,
     * z pisownią marki taką jak na istniejących kartach („3M”, „ATG”). B2bCatalogSync porównuje producenta karty
     * bez wielkości liter, ale poza tym dosłownie (foreignManufacturer) — inna pisownia oznaczałaby pominięcie
     * istniejącej karty.
     *
     * @var array<string, string>
     */
    private const MANUFACTURERS = [
        'ARDON s.r.o.' => 'ARDON',
        '3M Česko, spol. s r.o.' => '3M',
        'ATG HAND CARE (PVT) LTD.' => 'ATG',
        'Honeywell Safety Products France SAS' => 'Honeywell',
        'SHOWA Glove Co.' => 'SHOWA',
        'Sundström Safety AB' => 'Sundström',
        'UNIVET s.r.l.' => 'Univet',
        'Kratos Safety' => 'Kratos Safety',
        'Productos Climax S.A.' => 'Climax',
        // sprawdzone po pierwszym przebiegu 21.09.2026 — firma jest właścicielem marki z nazw wyrobów
        'ANSELL HEALTHCARE EUROPE N.V.' => 'Ansell',
        'WELDAS EUROPE B.V.' => 'Weldas',
        'PAB Akrapovič d.o.o.' => 'PAB',
        'OKULA Nýrsko a.s.' => 'Okula',
        'FAGUM - STOMIL Sp. z o. o.' => 'Fagum-Stomil',
        'Medicom SAS' => 'Medicom',
        'JS GLOVES Szewczyk Sp. J.' => 'JS Gloves',
        'Moleda, a.s.' => 'Moleda',
    ];

    /**
     * Wiersz „Producent” z tabelki sklepu w złej formie — polski tekst sklepu jest tłumaczeniem maszynowym
     * i odmienia markę („Sundströma”, 33 karty 21.09.2026). Klucz małymi literami.
     *
     * @var array<string, string>
     */
    private const PARAMETER_BRANDS = [
        'sundströma' => 'Sundström',
    ];

    /** Marka ARDON wprost w nazwie wyrobu („Kalosze robocze ARDON®NIGHTFISH OB”, „ARDON®SAFETY”). */
    private const ARDON_NAME = '/\bARDON\s*®/u';

    /** Litery czeskie i słowackie, których nie ma w polskim alfabecie. */
    private const CZECH_SLOVAK_LETTERS = '/[áäčďéěíĺľňôŕřšťúůýž]/u';

    /** Litery polskie, których nie ma w czeskim ani słowackim. */
    private const POLISH_LETTERS = '/[ąćęłńśźż]/u';

    /**
     * Człon kodu Ardon będący rozmiarem: liczba (rękawice „07”, „10,5”, obuwie „40”, odzież „104”) albo rozmiar
     * literowy (XS–XXXL, 2XL–6XL), także zakres z myślnikiem („S-M”). Inne człony („V1”, „SPE”, „N”, „uni”) to wersja.
     */
    private const SIZE_SEGMENT = '/^(?:\d{1,3}(?:[.,]\d)?|[2-6]?X{0,4}[SML]|XXS|XS)(?:-(?:\d{1,3}(?:[.,]\d)?|[2-6]?X{0,4}[SML]|XXS|XS))?$/i';

    /** Numer artykułu ATG na końcu nazwy pozycji („ATG® MaxiCut® Oil™ 34-504”, „ATG®MaxiChem® z TRItech™ 76-730”). */
    private const ATG_NAME = '/^ATG\b.*\s(\d{2}-\d{3,4}[A-Z]*)$/u';

    private int $total = 0;

    /** @var list<string> linie podsumowania przebiegu (B2bRunSummaryAware) */
    private array $summary = [];

    /** @var list<string> kody bazowe bez odnalezionej strony produktu */
    private array $withoutPage = [];

    /** @var list<string> kody bazowe, które w cenniku nie mają nazwy (sklep ich nie pokazuje) */
    private array $withoutName = [];

    /** @var array<string, int> nazwa prawna spoza MANUFACTURERS → liczba kart */
    private array $unknownManufacturers = [];

    /**
     * Strony odczytane w tym przebiegu wg kodu bazowego (null = nie znaleziona) — kod bazowy bywa kilkoma kartami
     * tej samej strony (pozycja bez ceny, inna nazwa). Trzymamy tylko wyciąg ze strony, nie HTML.
     *
     * @var array<string, array<string, mixed>|null>
     */
    private array $pages = [];

    public function __construct(private readonly ArdonB2bClient $client) {}

    public static function key(): string
    {
        return 'ardon';
    }

    public static function label(): string
    {
        return 'Ardon';
    }

    public static function host(): string
    {
        return ArdonB2bClient::HOST;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new ArdonB2bClient($account->username, (string) $account->password, $delayMs));
    }

    public function login(): void
    {
        $this->client->login();
    }

    public function products(): iterable
    {
        $rows = self::parsePriceList($this->client->priceListCsv());
        $cards = self::group($rows);
        $this->total = count($cards);
        $this->withoutPage = [];
        $this->withoutName = [];
        $this->unknownManufacturers = [];
        $this->pages = [];
        $groups = count(array_filter($cards, static fn (array $card): bool => count($card['rows']) > 1));
        $multiPrice = count(array_filter($cards, static fn (array $card): bool => self::sizePrices($card) !== null
            && count(array_unique(array_map(static fn (B2bRemotePrice $p): string => sprintf('%.2F', $p->net), self::sizePrices($card)))) > 1));
        $this->summary = ['Cennik Ardon: '.count($rows).' pozycji → '.count($cards).' kart ('.$groups.' grup rozmiarów, w tym '.$multiPrice
            .' z rozmiarami w różnych cenach — jedna karta, cena karty = najniższa cena rozmiaru, ceny rozmiarów w tabeli rozmiarów karty)'];
        $unpriced = array_values(array_map(
            static fn (array $row): string => $row['code'],
            array_filter($rows, static fn (array $row): bool => self::splitCode($row['code'])[1] !== null && ($row['net'] === null || $row['net'] <= 0)),
        ));
        if ($unpriced !== []) {
            $this->summary[] = 'Rozmiary bez ceny konta w cenniku: '.count($unpriced).' (osobne pozycje bez ceny, poza kartą wyrobu), np. '
                .implode(', ', array_slice($unpriced, 0, 10));
        }

        foreach ($cards as $card) {
            yield $this->productFor($card);
        }
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    public function runSummary(): array
    {
        $lines = $this->summary;
        if ($this->withoutPage !== []) {
            $lines[] = 'Bez strony produktu w sklepie: '.count($this->withoutPage).' kart (pominięte, bo producent nieznany; ATG i ARDON® — z ceną, bez treści), np. '
                .implode(', ', array_slice($this->withoutPage, 0, 10));
        }
        if ($this->withoutName !== []) {
            $lines[] = 'Bez nazwy w cenniku Ardona: '.count($this->withoutName).' kart (pominięte — sklep nie podaje nazwy ani strony), np. '
                .implode(', ', array_slice($this->withoutName, 0, 10));
        }
        if ($this->unknownManufacturers !== []) {
            arsort($this->unknownManufacturers);
            $lines[] = 'Producent spoza listy marek — zapisany dosłownie nazwą ze sklepu: '.implode('; ', array_map(
                static fn (string $name, int $count): string => $name.' ('.$count.')',
                array_keys($this->unknownManufacturers),
                $this->unknownManufacturers,
            ));
        }

        return $lines;
    }

    /**
     * Marka karty (ATG z nazwy, reszta z nazwy prawnej na stronie). Bez strony produktu marki nie znamy — pozycja
     * jest pomijana z powodem, zanim cokolwiek zapisze: synchronizacja wpisuje producenta na każdą kartę konta,
     * więc „Ardon” w zastępstwie zamieniłby „3M” na karcie po jednym nieudanym odczycie strony.
     */
    public function manufacturer(B2bRemoteProduct $product): string
    {
        $brand = self::text($product->raw['manufacturer'] ?? null);
        if ($brand === '') {
            throw new RuntimeException(self::text($product->raw['page_url'] ?? null) === ''
                ? 'nie znaleziono strony produktu w sklepie — producent nieznany, pozycja pominięta'
                : 'strona produktu nie podaje producenta — pozycja pominięta');
        }

        return $brand;
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        $net = $product->raw['price'] ?? null;
        if (! is_float($net) || $net <= 0) {
            return null;
        }
        $base = $product->raw['retail_price'] ?? null;
        $discount = $product->raw['discount'] ?? null;

        return new B2bRemotePrice(
            net: round($net, 2),
            base: is_float($base) && $base > 0 ? round($base, 2) : null,
            discountPercent: is_float($discount) ? round($discount, 2) : 0.0,
        );
    }

    /** Sekcja „Opis i informacje techniczne” ze strony produktu, dosłownie: akapity i punkty listy w liniach. */
    public function description(B2bRemoteProduct $product): string
    {
        return mb_substr(self::text($product->raw['description'] ?? null), 0, 10000);
    }

    public function hasForeignDescription(B2bRemoteProduct $product): bool
    {
        return self::isForeignText($this->description($product));
    }

    /**
     * Tekst po czesku albo słowacku: co najmniej 3 litery spoza polskiego alfabetu i ponad dwa razy więcej niż
     * liter tylko polskich. Pojedyncze obce litery (nazwa „Bollé”, „Sundström”) nie wystarczają.
     */
    public static function isForeignText(string $text): bool
    {
        $text = mb_strtolower($text);
        $foreign = preg_match_all(self::CZECH_SLOVAK_LETTERS, $text);
        $polish = preg_match_all(self::POLISH_LETTERS, $text);

        return $foreign >= 3 && $foreign > 2 * $polish;
    }

    /**
     * Tabelka „Parametry” ze strony produktu i dane handlowe (producent — nazwa prawna ze sklepu, kategoria,
     * kod każdej pozycji karty). Wszystko dosłownie ze sklepu.
     *
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        $fields = [];
        $seen = [];
        $add = static function (string $section, string $name, string $value) use (&$fields, &$seen): void {
            $key = mb_strtolower($name).'|'.$value;
            if ($name === '' || $value === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $fields[] = new B2bRemoteShopField($section, $name, $value);
        };

        foreach ((array) ($product->raw['parameters'] ?? []) as [$name, $value]) {
            $add(self::SHOP_SECTION, $name, $value);
        }
        $add(self::TRADE_SECTION, 'Marka w danych sklepu', self::text($product->raw['legal_manufacturer'] ?? null));
        $add(self::TRADE_SECTION, 'Kategoria', implode(' > ', (array) ($product->raw['categories'] ?? [])));
        foreach ((array) ($product->raw['items'] ?? []) as $item) {
            $add(self::TRADE_SECTION, 'Kod towaru', $item['code']);
        }

        return $fields;
    }

    /**
     * Pliki z sekcji „Do pobrania”, tylko po polsku i wielojęzyczne („uni”). Angielski plik bierzemy tylko wtedy,
     * gdy tego rodzaju pliku nie ma po polsku (np. karta TDS przy braku polskiej karty KT); czeskich nie bierzemy
     * nigdy — sklep trzyma ten sam dokument w 3–4 językach.
     *
     * Wyroby ATG plików stąd nie dostają: ma je karta z witryny producenta (AtgB2bConnector), a te same dokumenty
     * pod innym adresem i sumą kontrolną dublowałyby tekst kart technicznych w indeksie.
     */
    public function documents(B2bRemoteProduct $product): array
    {
        if (($product->raw['atg'] ?? false) === true) {
            return [];
        }
        $files = [];
        foreach ((array) ($product->raw['documents'] ?? []) as [$title, $url]) {
            $files[] = ['title' => $title, 'url' => $url, ...self::documentType($title)];
        }
        $local = [];
        foreach ($files as $file) {
            if (in_array($file['language'], ['pl', 'uni'], true)) {
                $local[$file['group']] = true;
            }
        }
        $kept = array_values(array_filter(
            $files,
            static fn (array $file): bool => in_array($file['language'], ['pl', 'uni'], true)
                || ($file['language'] === 'en' && ! isset($local[$file['group']])),
        ));
        // sortowanie stabilne: w obrębie rodzaju zostaje kolejność ze strony
        usort($kept, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return array_map(static fn (array $file): B2bRemoteDocument => new B2bRemoteDocument($file['title'], $file['url'], $file['kind']), $kept);
    }

    public function documentBytes(B2bRemoteDocument $document): array
    {
        if (! ArdonB2bClient::isOwnUrl($document->sourceUrl)) {
            throw new RuntimeException('plik spoza '.ArdonB2bClient::HOST.': '.$document->sourceUrl);
        }

        $file = $this->client->fileBytes($document->sourceUrl);
        // Sklep wydaje załączniki jako application/octet-stream (sprawdzone 21.09.2026), a zapis plików przyjmuje
        // tylko rozpoznany typ — PDF poznajemy po sygnaturze pliku, nie po nagłówku.
        if (str_starts_with($file['bytes'], '%PDF-')) {
            $file['mime'] = 'application/pdf';
        }

        return $file;
    }

    /** Zdjęcie wyrobu z danych strukturalnych strony produktu (serwer zdjęć www.ardon.cz). */
    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $url = self::text($product->raw['image'] ?? null);
        if ($url === '' || ! ArdonB2bClient::isImageUrl($url)) {
            return null;
        }
        $file = $this->client->fileBytes($url);
        if (! str_starts_with($file['mime'], 'image/') || $file['bytes'] === '') {
            return null;
        }

        return new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: $url);
    }

    /**
     * Wiersze cennika CSV konta. Inny układ kolumn = błąd (z pliku o innym znaczeniu kolumn nie da się wziąć ceny).
     *
     * @return list<array{code: string, name: string, retail: float|null, discount: float|null, net: float|null}>
     */
    public static function parsePriceList(string $csv): array
    {
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];
        $header = array_map('trim', str_getcsv((string) array_shift($lines), ';', '"', ''));
        if ($header !== ArdonB2bClient::PRICE_LIST_HEADER) {
            throw new RuntimeException('Cennik CSV '.ArdonB2bClient::HOST.' ma inne kolumny niż oczekiwane: '.implode('; ', $header));
        }

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map('trim', str_getcsv($line, ';', '"', ''));
            if (count($cells) < 5 || $cells[0] === '') {
                continue;
            }
            $rows[] = [
                'code' => $cells[0],
                'name' => $cells[1],
                'retail' => self::decimal($cells[2]),
                'discount' => self::decimal($cells[3]),
                'net' => self::decimal($cells[4]),
            ];
        }

        return $rows;
    }

    /**
     * Pozycje cennika w karty: kod bez członu rozmiaru + nazwa (decyzja użytkownika 28.09.2026: cena konta nie dzieli
     * już kodu na karty — rozmiary w różnych cenach to jedna karta). Człon wersji albo dopisku („A1073/V1/07”,
     * „A1013/10/SPE”, „G3098/35/N”, „H2017/uni/XL”) zostaje w kluczu — inna wersja to inna karta (splitCode).
     * Powtórzony rozmiar w grupie — każda pozycja osobno. Pozycje karty wg rozmiaru; karty w kolejności pierwszej
     * pozycji w cenniku. Pozycja bez rozmiaru albo bez ceny konta (pusta komórka) — osobna karta, jak dotąd; rozmiary
     * z ceną 0 lub ujemną — osobna grupa kodu bez cen rozmiarów (jak dotąd grupa „w jednej cenie”, synchronizacja
     * pomija ją bez ceny), żeby zero nie zostało najniższą ceną karty.
     *
     * SKU karty — patrz opis klasy; gdy kod bazowy ma kilka kart (inna nazwa, pozycje bez ceny), klucz dostaje
     * największa karta z cenami (remis: pierwsza w cenniku), pozostałe — pełny kod Ardon swojej pierwszej pozycji.
     *
     * @param  list<array{code: string, name: string, retail: float|null, discount: float|null, net: float|null}>  $rows
     * @return list<array{sku: string, base: string, rows: list<array{code: string, name: string, retail: float|null, discount: float|null, net: float|null, size: string|null}>}>
     */
    public static function group(array $rows): array
    {
        $groups = [];
        foreach ($rows as $index => $row) {
            [$base, $size, $variant] = self::splitCode($row['code']);
            // klucz: kod bez samego członu rozmiaru — wersja i dopisek („V1”, „SPE”, „N”, „uni”) zostają w kluczu,
            // więc „A1073/V1/07” nie jest rozmiarem karty „A1073/07”
            $stem = mb_strtolower($base.($variant !== '' ? '/'.$variant : ''));
            $key = match (true) {
                $size === null || $row['net'] === null => '#'.$index,
                $row['net'] <= 0 => $stem.'|0|'.$row['name'],
                default => $stem.'|'.$row['name'],
            };
            $groups[$key]['base'] ??= $base;
            $groups[$key]['rows'][] = [...$row, 'size' => $size];
        }
        // ten sam rozmiar dwa razy w grupie (np. „07” i „7”) — to nie rozmiary jednego wyrobu: każda pozycja osobno
        // (jak UvexSizeGroups przy powtórzonym rozmiarze), nigdy dwie pozycje pod jedną etykietą rozmiaru
        $checked = [];
        foreach ($groups as $key => $group) {
            $labels = array_map(static fn (array $row): string => self::sizeKey((string) $row['size']), $group['rows']);
            if (count(array_unique($labels)) === count($labels)) {
                $checked[$key] = $group;

                continue;
            }
            foreach ($group['rows'] as $i => $row) {
                $checked[$key.'#'.$i] = ['base' => $group['base'], 'rows' => [$row]];
            }
        }
        $groups = $checked;

        $byBase = [];
        foreach ($groups as $key => $group) {
            usort($group['rows'], static fn (array $a, array $b): int => UvexSizeGroups::compareSizes((string) $a['size'], (string) $b['size']));
            $groups[$key] = $group;
            $byBase[mb_strtolower($group['base'])][] = $key;
        }
        // karta z cenami przed kartą bez ceny, potem więcej pozycji; remis — pierwsza w cenniku
        $rank = static fn (array $group): array => [
            $group['rows'][0]['net'] !== null && $group['rows'][0]['net'] > 0 ? 1 : 0,
            count($group['rows']),
        ];

        $cards = [];
        foreach ($groups as $key => $group) {
            $siblings = $byBase[mb_strtolower($group['base'])];
            $cardKey = self::atgArticle($group['rows'][0]['name']) ?? $group['base'];
            $main = $siblings[0];
            foreach ($siblings as $sibling) {
                if ($rank($groups[$sibling]) > $rank($groups[$main])) {
                    $main = $sibling;
                }
            }
            $first = $group['rows'][0];
            // pozostałe karty kodu: pełny kod Ardon pierwszej pozycji — kod, który istnieje w cenniku
            $sku = $key === $main ? $cardKey : $first['code'];
            $cards[] = ['sku' => $sku, 'base' => $group['base'], 'rows' => $group['rows']];
        }

        return $cards;
    }

    /**
     * Ceny rozmiarów karty (members[].price): cena konta i sugerowana cena detaliczna z wiersza cennika tego rozmiaru
     * (dosłownie ze źródła, zaokrąglone do groszy; bez ceny detalicznej w wierszu — base null, nigdy z innego
     * rozmiaru), rabat z wiersza. null = karta bez cen rozmiarów: jedna pozycja albo pozycje bez ceny konta > 0.
     *
     * @param  array{rows: list<array{code: string, retail: float|null, discount: float|null, net: float|null}>}  $card
     * @return list<B2bRemotePrice>|null w kolejności pozycji karty
     */
    public static function sizePrices(array $card): ?array
    {
        if (count($card['rows']) < 2) {
            return null;
        }
        $prices = [];
        foreach ($card['rows'] as $row) {
            if ($row['net'] === null || $row['net'] <= 0) {
                return null;
            }
            $prices[] = new B2bRemotePrice(
                net: round($row['net'], 2),
                base: $row['retail'] !== null && $row['retail'] > 0 ? round($row['retail'], 2) : null,
                discountPercent: $row['discount'] !== null ? round($row['discount'], 2) : 0.0,
            );
        }

        return $prices;
    }

    /** Numer artykułu ATG z nazwy pozycji; null = pozycja nie jest wyrobem ATG z numerem w nazwie. */
    public static function atgArticle(string $name): ?string
    {
        return preg_match(self::ATG_NAME, trim($name), $m) === 1 ? $m[1] : null;
    }

    /**
     * Treść strony produktu potrzebna karcie: producent (nazwa prawna), zdjęcie, okruszki kategorii, opis,
     * tabelka „Parametry” i pliki. null = to nie jest strona wyrobu o tym kodzie (inny mpn w danych strukturalnych).
     *
     * @return array{mpn: string, legal_manufacturer: string, image: string|null, categories: list<string>, description: string, parameters: list<array{0: string, 1: string}>, documents: list<array{0: string, 1: string}>}|null
     */
    public static function parsePage(string $html, string $baseCode): ?array
    {
        $product = null;
        $breadcrumb = [];
        if (preg_match_all('#<script\b[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $blocks) > 0) {
            foreach ($blocks[1] as $block) {
                $json = json_decode(trim($block), true);
                if (! is_array($json)) {
                    continue;
                }
                if (($json['@type'] ?? null) === 'Product' && $product === null) {
                    $product = $json;
                }
                if (($json['@type'] ?? null) === 'BreadcrumbList' && $breadcrumb === []) {
                    foreach ((array) ($json['itemListElement'] ?? []) as $item) {
                        $name = is_array($item) ? self::text($item['item']['name'] ?? null) : '';
                        if ($name !== '') {
                            $breadcrumb[] = $name;
                        }
                    }
                }
            }
        }
        if ($product === null || mb_strtolower(self::text($product['mpn'] ?? null)) !== mb_strtolower($baseCode)) {
            return null;
        }
        // ostatni element okruszków to sam wyrób, nie kategoria
        array_pop($breadcrumb);

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);

        $image = self::text($product['image'] ?? null);

        return [
            'mpn' => self::text($product['mpn'] ?? null),
            'legal_manufacturer' => self::inline(self::text($product['brand']['name'] ?? null)),
            'image' => ArdonB2bClient::isImageUrl($image) ? $image : null,
            'categories' => $breadcrumb,
            'description' => self::descriptionText($xpath),
            'parameters' => self::parameters($xpath),
            'documents' => self::downloads($xpath),
        ];
    }

    /**
     * @param  array{sku: string, base: string, rows: list<array{code: string, name: string, retail: float|null, discount: float|null, net: float|null, size: string|null}>}  $card
     */
    private function productFor(array $card): B2bRemoteProduct
    {
        $rows = $card['rows'];
        $first = $rows[0];
        $grouped = count($rows) > 1;
        $page = $this->findPage($card['base'], $first['name']);
        $url = $page['url'] ?? null;

        $legal = self::text($page['legal_manufacturer'] ?? null);
        $atg = self::atgArticle($first['name']) !== null;
        // marka: ATG z nazwy; wiersz „Producent” z tabelki strony (dosłownie, np. „3M”); ARDON® w nazwie wyrobu —
        // Ardon zamawia część własnych wyrobów w fabrykach innych firm, a dane strukturalne podają wtedy fabrykę
        // („Dikamar S.A.” przy kaloszach ARDON®NIGHTFISH); na końcu nazwa prawna z listy
        $parameter = self::parameter($page['parameters'] ?? [], 'Producent');
        $manufacturer = match (true) {
            $atg => 'ATG',
            $parameter !== null => self::PARAMETER_BRANDS[mb_strtolower($parameter)] ?? $parameter,
            preg_match(self::ARDON_NAME, $first['name']) === 1 => 'ARDON',
            default => self::brandOf($legal),
        };
        if ($manufacturer === null && $legal !== '') {
            $manufacturer = $legal;
            $this->unknownManufacturers[$legal] = ($this->unknownManufacturers[$legal] ?? 0) + 1;
        }

        $sized = array_values(array_filter($rows, static fn (array $row): bool => $row['size'] !== null));
        // ceny rozmiarów (decyzja użytkownika 28.09.2026) — cena karty = najtańszy rozmiar; remis ceny konta
        // rozstrzyga ta sama reguła co synchronizacja (B2bCatalogSync::winsSizePriceTie: znana i niższa cena
        // detaliczna, dalej pierwszy rozmiar), żeby price() i slot karty wskazały ten sam rozmiar
        $prices = self::sizePrices($card);
        $cheapest = $first;
        if ($prices !== null) {
            $best = 0;
            foreach ($prices as $i => $price) {
                $current = $prices[$best];
                if ($price->net < $current->net - 0.0049
                    || (abs($price->net - $current->net) < 0.005 && B2bCatalogSync::winsSizePriceTie($price->base, $current->base))) {
                    $best = $i;
                }
            }
            $cheapest = $rows[$best];
        }

        return new B2bRemoteProduct(
            remoteId: $first['code'],
            sku: $card['sku'],
            name: $first['name'],
            category: $page !== null && $page['categories'] !== [] ? $page['categories'][array_key_last($page['categories'])] : null,
            sourceUrl: $url,
            raw: [
                'manufacturer' => $manufacturer ?? '',
                'atg' => $atg,
                'legal_manufacturer' => $legal,
                // cena karty = najtańszy rozmiar (price()); ceny pozostałych rozmiarów — members[].price
                'price' => $cheapest['net'],
                'retail_price' => $cheapest['retail'],
                'discount' => $cheapest['discount'],
                'image' => $page['image'] ?? null,
                'categories' => $page['categories'] ?? [],
                'description' => $page['description'] ?? '',
                'parameters' => $page['parameters'] ?? [],
                'documents' => $page['documents'] ?? [],
                'items' => array_map(static fn (array $row): array => ['code' => $row['code'], 'size' => $row['size']], $rows),
                'page_url' => $url,
            ],
            variantSummary: $sized !== []
                ? 'Rozmiary: '.implode('; ', array_map(static fn (array $row): string => $row['size'].' ('.$row['code'].')', $sized))
                : null,
            members: $grouped
                ? array_map(static fn (array $row, int $i): array => [
                    'remote_id' => $row['code'],
                    'sku' => $row['code'],
                    'name' => $row['name'],
                    ...($prices !== null ? ['size' => (string) $row['size'], 'price' => $prices[$i]] : []),
                ], $rows, array_keys($rows))
                : [],
            identifiers: self::identifiers($rows, self::text($page['mpn'] ?? null), $atg ? self::atgArticle($first['name']) : null),
        );
    }

    /**
     * Kod Ardon każdej pozycji cennika („A5001/09”, kolumna „artykuł”) — własny kod dystrybutora, pozycja = ten kod
     * (remote_id powiązania), etykieta = rozmiar z kodu. Kod bazowy („A5001”) składamy sami z kodu pozycji, więc
     * identyfikatorem jest tylko wtedy, gdy strona produktu podaje go wprost (mpn w danych strukturalnych —
     * to kod Ardon, nie producenta). Numer artykułu ATG z końca nazwy w cenniku („… 24-985”) — kod producenta
     * karty ATG. Numerów innych producentów sklep nie podaje w osobnym polu (tylko w nazwach) — nie wyciągamy ich.
     *
     * @param  list<array{code: string, name: string, retail: float|null, discount: float|null, net: float|null, size: string|null}>  $rows
     * @return list<B2bRemoteIdentifier>
     */
    private static function identifiers(array $rows, string $mpn, ?string $atgArticle): array
    {
        $out = [];
        // pozycja bez rozmiaru ma kod równy mpn — wystarczy jej kod z cennika
        $codes = array_map(static fn (array $row): string => mb_strtolower($row['code']), $rows);
        if ($mpn !== '' && ! in_array(mb_strtolower($mpn), $codes, true)) {
            $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_SOURCE_CODE, value: $mpn, field: 'mpn');
        }
        if ($atgArticle !== null) {
            $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MANUFACTURER_CODE, value: $atgArticle, field: 'nazwa');
        }
        foreach ($rows as $row) {
            $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_SOURCE_CODE, value: $row['code'], remoteId: $row['code'], label: $row['size'], field: 'artykuł');
        }

        return $out;
    }

    /**
     * Strona produktu kodu bazowego: podpowiedzi wyszukiwarki, a z nich pierwsza strona, której dane strukturalne
     * mają ten kod (mpn). Najpierw szukamy po kodzie, a gdy żadna podpowiedź nie jest tym wyrobem — po nazwie
     * z cennika: po zalogowaniu wyszukiwarka dopasowuje fragment tekstu i dla „A1020” podaje same uprzęże
     * „FA1020…”, bez rękawicy A1020 (21.09.2026). Podpowiedzi ze zdjęciem nazwanym tym kodem sprawdzamy pierwsze.
     *
     * Błąd zapytania nie przerywa przebiegu: karta bez strony nie zna producenta, więc synchronizacja ją pomija
     * z powodem (manufacturer()), a wyrób ATG dostaje samą cenę. Pozycji bez nazwy w cenniku nie szukamy —
     * synchronizacja i tak ją pomija („brak kodu lub nazwy”), a sklep takich wyrobów nie pokazuje.
     *
     * @return array{url: string, mpn: string, legal_manufacturer: string, image: string|null, categories: list<string>, description: string, parameters: list<array{0: string, 1: string}>, documents: list<array{0: string, 1: string}>}|null
     */
    private function findPage(string $baseCode, string $name): ?array
    {
        $key = mb_strtolower($baseCode);
        if (array_key_exists($key, $this->pages)) {
            return $this->pages[$key];
        }
        $this->pages[$key] = null;
        if (trim($name) === '') {
            $this->withoutName[] = $baseCode;

            return null;
        }

        $checked = [];
        foreach (array_unique([$baseCode, trim($name)]) as $query) {
            try {
                $candidates = array_values(array_filter(
                    self::rankByImage($this->client->searchProducts($query), $baseCode),
                    static fn (array $candidate): bool => ! isset($checked[$candidate['url']]),
                ));
                foreach (array_slice($candidates, 0, self::SEARCH_CANDIDATES) as $candidate) {
                    $checked[$candidate['url']] = true;
                    $page = self::parsePage($this->client->page($candidate['url']), $baseCode);
                    if ($page !== null) {
                        return $this->pages[$key] = ['url' => $candidate['url'], ...$page];
                    }
                }
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException) {
                // błąd tego zapytania — próbujemy następnego; bez strony kod trafia do podsumowania przebiegu
            }
        }
        $this->withoutPage[] = $baseCode;

        return null;
    }

    /**
     * Podpowiedzi ze zdjęciem, którego nazwa pliku zaczyna się kodem wyrobu („A1020_001.jpg.webp”), na początek;
     * poza tym kolejność sklepu. To tylko kolejność sprawdzania — o trafieniu decyduje mpn na stronie produktu.
     *
     * @param  list<array{url: string, image: string}>  $candidates
     * @return list<array{url: string, image: string}>
     */
    private static function rankByImage(array $candidates, string $baseCode): array
    {
        $pattern = '/^'.preg_quote(mb_strtolower($baseCode), '/').'[_.\-]/u';
        $matching = [];
        $rest = [];
        foreach ($candidates as $candidate) {
            $file = mb_strtolower(basename((string) parse_url($candidate['image'], PHP_URL_PATH)));
            if (preg_match($pattern, $file) === 1) {
                $matching[] = $candidate;
            } else {
                $rest[] = $candidate;
            }
        }

        return [...$matching, ...$rest];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $parameters
     */
    private static function parameter(array $parameters, string $name): ?string
    {
        foreach ($parameters as [$label, $value]) {
            if (mb_strtolower($label) === mb_strtolower($name) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function brandOf(string $legal): ?string
    {
        foreach (self::MANUFACTURERS as $name => $brand) {
            if (mb_strtolower(self::inline($name)) === mb_strtolower($legal)) {
                return $brand;
            }
        }

        return null;
    }

    /**
     * Rodzaj pliku z nazwy („A3139-KT-PL.pdf”, „A1002_navod(instructions)_uni.pdf”, „F2032-PoS-DoC-2025-uni.pdf”,
     * „A3139-karta-charakterystyki-PL.pdf”, „A3139-MBL-CZ.pdf”). Język = ostatni człon nazwy; bez znanego języka —
     * plik wielojęzyczny. Instrukcja prania nie jest rodzajem „instrukcja” — tekst z instrukcji trafia do opisu
     * wyrobu w indeksie (B2bCatalogSync::DESCRIPTION_SOURCE_KINDS), a pranie wyrobu nie opisuje.
     *
     * @return array{language: string, group: string, rank: int, kind: string}
     */
    private static function documentType(string $title): array
    {
        $name = mb_strtolower((string) preg_replace('/\.pdf$/i', '', trim($title)));
        $tokens = array_values(array_filter(preg_split('/[\s\-_().]+/u', $name) ?: [], static fn (string $t): bool => $t !== ''));
        $last = $tokens === [] ? '' : $tokens[array_key_last($tokens)];
        $language = in_array($last, ['pl', 'en', 'cz', 'cs', 'sk', 'de', 'hu', 'ro', 'uni'], true) ? $last : 'uni';
        // człon nazwy to słowo, także z doklejonym rokiem („DZ2026”, „DoC2026”)
        $has = static function (string ...$words) use ($tokens): bool {
            foreach ($tokens as $token) {
                if (in_array((string) preg_replace('/\d+$/', '', $token), $words, true)) {
                    return true;
                }
            }

            return false;
        };

        [$group, $rank, $kind] = match (true) {
            $has('kt') => ['datasheet', 0, ProductDocument::KIND_DATASHEET],
            $has('tds', 'tl') => ['datasheet', 1, ProductDocument::KIND_DATASHEET],
            $has('navod', 'instructions', 'ifu') => ['manual', 2, ProductDocument::KIND_MANUAL],
            $has('doc', 'dz', 'pos') || str_contains($name, 'deklarac') => ['declaration', 3, ProductDocument::KIND_CERTIFICATE],
            // certyfikat OEKO-TEX Standard 100 materiału („OEKOTEX-STANDARD100-PG020-128167.pdf”) i inne certyfikaty
            $has('certificate', 'certifikat', 'certyfikat') || str_contains($name, 'oekotex') => ['certificate', 3, ProductDocument::KIND_CERTIFICATE],
            $has('msds', 'mbl', 'sds') || str_contains($name, 'karta-charakterystyki') => ['safety', 4, ProductDocument::KIND_OTHER],
            $has('pranie', 'prani', 'laundry') => ['laundry', 5, ProductDocument::KIND_OTHER],
            default => ['other', 6, ProductDocument::KIND_OTHER],
        };

        return ['language' => $language, 'group' => $group, 'rank' => $rank, 'kind' => $kind];
    }

    /** Treść akordeonu o tym nagłówku (h3) na stronie produktu. */
    private static function section(DOMXPath $xpath, string $heading): ?DOMElement
    {
        foreach ($xpath->query('//*['.self::classPredicate('accordion__container').']') ?: [] as $container) {
            $title = self::inline((string) $xpath->query('.//*['.self::classPredicate('accordion__title').']//h3', $container)?->item(0)?->textContent);
            if ($title !== $heading) {
                continue;
            }
            $content = $xpath->query('./*['.self::classPredicate('accordion__content').']', $container)?->item(0);

            return $content instanceof DOMElement ? $content : null;
        }

        return null;
    }

    /** Opis: akapity i punkty listy w osobnych liniach, łamania wiersza zachowane; bez piktogramów. */
    private static function descriptionText(DOMXPath $xpath): string
    {
        $section = self::section($xpath, self::HEADING_DESCRIPTION);
        if ($section === null) {
            return '';
        }
        $lines = [];
        foreach ($section->childNodes as $child) {
            if (! $child instanceof DOMElement || str_contains(' '.$child->getAttribute('class').' ', ' product-icon-line ')) {
                continue;
            }
            $html = (string) $child->ownerDocument?->saveHTML($child);
            $html = (string) preg_replace('#<br\s*/?>|</(p|li|div|h\d|tr)>#i', "\n", $html);
            foreach (explode("\n", html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) as $line) {
                $line = self::inline($line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function parameters(DOMXPath $xpath): array
    {
        $section = self::section($xpath, self::HEADING_PARAMETERS);
        if ($section === null) {
            return [];
        }
        $rows = [];
        foreach ($xpath->query('.//tr', $section) ?: [] as $row) {
            $name = self::inline((string) $xpath->query('./th', $row)?->item(0)?->textContent);
            $value = self::inline((string) $xpath->query('./td', $row)?->item(0)?->textContent);
            $name = rtrim($name, ': ');
            if ($name !== '' && $value !== '') {
                $rows[] = [$name, $value];
            }
        }

        return $rows;
    }

    /**
     * @return list<array{0: string, 1: string}> nazwa pliku i adres
     */
    private static function downloads(DOMXPath $xpath): array
    {
        $section = self::section($xpath, self::HEADING_DOWNLOADS);
        if ($section === null) {
            return [];
        }
        $files = [];
        foreach ($xpath->query('.//a[@href]', $section) ?: [] as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $url = trim($link->getAttribute('href'));
            $title = self::inline($link->textContent);
            if (! ArdonB2bClient::isOwnUrl($url) || $title === '' || isset($files[$url])) {
                continue;
            }
            $files[$url] = [$title, $url];
        }

        return array_values($files);
    }

    /**
     * Kod bazowy (przed pierwszym „/”), rozmiar i pozostałe człony. Rozmiar = ostatni człon po kodzie bazowym, który
     * wygląda jak rozmiar (SIZE_SEGMENT: „07”, „10,5”, „40”, „XL”, „2XL”); reszta członów to wersja albo dopisek
     * („V1”, „SPE”, „N”, „uni”) i zostaje w kluczu karty. Na cenniku 28.09.2026: „A1013/10/SPE”, „A1073/V1/07”,
     * „G3098/35/N”, „H2017/uni/XL”, „A3123/V1/40/07” (rozmiar 07, „V1/40” — wersja). Żaden człon nie wygląda jak
     * rozmiar — pozycja bez rozmiaru (osobna karta), a nie rozmiar „V1” cudzej karty.
     *
     * @return array{0: string, 1: string|null, 2: string} kod bazowy, rozmiar (dosłownie), pozostałe człony („/”)
     */
    private static function splitCode(string $code): array
    {
        $segments = array_map('trim', explode('/', $code));
        $base = array_shift($segments);
        $segments = array_values(array_filter($segments, static fn (string $segment): bool => $segment !== ''));
        $size = null;
        for ($i = count($segments) - 1; $i >= 0; $i--) {
            if (preg_match(self::SIZE_SEGMENT, $segments[$i]) === 1) {
                $size = $segments[$i];
                array_splice($segments, $i, 1);
                break;
            }
        }

        return [(string) $base, $size, implode('/', $segments)];
    }

    /** Rozmiar do porównań w grupie: „07” = „7”, „10,5” = „10.5”, litery bez wielkości. */
    private static function sizeKey(string $size): string
    {
        $size = mb_strtoupper(str_replace(',', '.', trim($size)));

        return is_numeric($size) ? (string) (float) $size : $size;
    }

    /** Liczba z cennika („13,857”); pusta komórka = brak. */
    private static function decimal(string $value): ?float
    {
        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($value));

        return is_numeric($value) ? (float) $value : null;
    }

    private static function classPredicate(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }

    private static function inline(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)) ?? $text);
    }

    /** Wartość dosłownie ze źródła — same białe znaki liczą się jak brak. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
