<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * www.atlas-obuwie.pl — witryna producenta obuwia ATLAS (TYPO3) ze strefą klienta hurtowego. Sprawdzone na
 * zalogowanym koncie #28 01.10.2026 (AtlasB2bClient opisuje logowanie i sesję).
 *
 * Lista: katalog /produkt.html — jedna strona z wszystkimi wyrobami (265 po zalogowaniu, 254 dla gościa), bez
 * stronicowania. Z listy bierzemy adres strony wyrobu, numer artykułu i nazwę; resztę czyta strona wyrobu (jedno
 * zapytanie na pozycję listy).
 *
 * Karta = model ze wszystkimi rozmiarami i tęgościami. Strona wyrobu zalogowanego konta ma tabelę zamówienia:
 * wiersz = tęgość (tęgość 10 — numer modelu „75600 S3”, tęgość 12 — osobny numer „75612 S3” na tej samej stronie),
 * kolumna = rozmiar. Tęgości 13 i 14 są w katalogu osobnymi pozycjami („Flash 6405 XP BOA,Weite 13 | ESD”, numer
 * „91313 S3”) z własną, wyższą ceną — łączymy je z modelem po numerze (ostatnie dwie cyfry 00/12/13/14, ten sam
 * przyrostek, w nazwie „Weite”), tak jak rozmiary w różnych cenach (decyzja właściciela 28.09.2026): każda komórka
 * tabeli to pozycja karty (members) ze swoją ceną, karta ma cenę najtańszej.
 *
 * Cena: „Cena artykułu: 237.90 PLN (incl. 22% Rabat)” = cena konta netto (w historii zamówień konta „cena netto”
 * równa tej cenie), ukryte pole „Preis_csv” = cena katalogowa przed rabatem konta (305.00 → 237.90 przy 22%).
 * Rozmiary nietypowe (kolumna z gwiazdką, pole „Uebergroesse” = „20 % ÜGZ”, przypis „20 % dopłata - rozmiar
 * nietypowy”) kosztują o tyle procent więcej — cena pozycji = cena artykułu powiększona o procent z pola.
 * Dostępność komórki to kolor pola ilości (zielony/żółty/czerwony) z legendą strony.
 *
 * remote_id pozycji = numer artykułu + rozmiar („75600 S3/36”); karta bez tabeli rozmiarów (spray, sznurowadła) —
 * sam numer artykułu. SKU karty = numer artykułu modelu (tęgość 10) ze spacjami zwiniętymi. Producent = ATLAS
 * (witryna własna producenta; wkładki, skarpety i środki pielęgnacji sprzedawane pod tą samą marką).
 *
 * Co skąd: opis = blok „wyposażenie” strony wyrobu bez wierszy „numer artykułu” i „rozmiary” (norma, lista cech,
 * tekst wyrobu) dosłownie; numer artykułu, norma, rozmiary, tęgości i dopłata za rozmiar nietypowy — do tabelki
 * sklepu (B2bShopFieldSource), normy z wiersza „Norma” (B2bShopFieldNormSource). Pliki: „Deklaracja zgodności EU”
 * (/eu-pdf/…pdf; odnośnik do ogólnej strony deklaracji — nie plik — pomijany). Zdjęcia: galeria strony wyrobu.
 */
final class AtlasB2bConnector implements B2bConnector, B2bDocumentSource, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldNormSource, B2bShopFieldSource, B2bSizePriceSource
{
    public const BRAND = 'ATLAS';

    private const SECTION = 'Informacje ze sklepu ATLAS';

    /** Wiersz tabelki z normą („EN ISO 20345:2022 S3S SR”). */
    private const NORM_FIELD = 'Norma';

    /** robots.txt witryny: Crawl-delay 10 — co najmniej sekunda między zapytaniami. */
    public const MIN_DELAY_MS = 1000;

    /**
     * Tyle stron wyrobów z odczytaną stroną, a żadna z ceną konta, zanim pojawi się pierwsza cena = sesja bez cen (albo
     * zmiana strony) — przebieg przerwany.
     */
    private const MAX_FIRST_WITHOUT_PRICE = 20;

    private const PROGRESS_EVERY = 50;

    /** Kolor pola ilości w tabeli zamówienia → legenda pod tabelą (na stronie bez polskich liter: „Dostpne natychmiast”). */
    private const AVAILABILITY = [
        'green' => 'dostępne natychmiast',
        'yellow' => 'niski poziom zapasów',
        'red' => 'artykuł jest w produkcji',
    ];

    private int $total = 0;

    private int $cards = 0;

    private int $withPrice = 0;

    private int $positions = 0;

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> */
    private array $withoutPrice = [];

    /** @var list<string> */
    private array $withoutDescription = [];

    /** @var list<string> */
    private array $merged = [];

    /** @var list<string> */
    private array $surcharged = [];

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public function __construct(private readonly AtlasB2bClient $client) {}

    public static function key(): string
    {
        return 'atlas';
    }

    public static function label(): string
    {
        return 'Atlas';
    }

    public static function host(): string
    {
        return 'atlas-obuwie.pl';
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public static function normShopFieldNames(): array
    {
        return [self::NORM_FIELD];
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        // zwykła przerwa przebiegu (150 ms) za krótka dla Crawl-delay witryny; 0 (testy, jawne --delay=0) zostaje
        return new self(new AtlasB2bClient((string) $account->username, (string) $account->password, $delayMs > 0 ? max($delayMs, self::MIN_DELAY_MS) : 0));
    }

    public function onListProgress(callable $callback): void
    {
        $this->listProgress = $callback;
    }

    public function login(): void
    {
        $this->client->login();
    }

    public function products(): iterable
    {
        $this->summary = [];
        $this->withoutPrice = [];
        $this->withoutDescription = [];
        $this->merged = [];
        $this->surcharged = [];
        $this->cards = 0;
        $this->withPrice = 0;
        $this->positions = 0;

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }

        $rows = self::parseCatalog($this->client->catalogPage());
        if ($rows === []) {
            throw new RuntimeException('Katalog '.AtlasB2bClient::HOST.' bez wyrobów — zmiana strony?');
        }
        $groups = self::groupRows($rows);
        $this->total = count($groups);
        $this->summary[] = 'Lista Atlas: '.count($rows).' pozycji katalogu, '.count($groups).' modeli';

        $done = 0;
        $seen = [];
        foreach ($groups as $group) {
            $product = $this->productFor($group, $seen);
            if ($this->withPrice === 0 && count($this->withoutPrice) >= self::MAX_FIRST_WITHOUT_PRICE) {
                throw new B2bFatalException(count($this->withoutPrice).' pierwszych wyrobów '.AtlasB2bClient::HOST.' bez ceny konta — sesja konta nie pokazuje cen albo witryna zmieniła stronę wyrobu; przebieg przerwany');
            }
            $done++;
            if ($done % self::PROGRESS_EVERY === 0) {
                $this->progress('Strony wyrobów Atlas: '.$done.'/'.count($groups));
            }
            yield $product;
        }
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    public function runSummary(): array
    {
        $lines = $this->summary;
        $lines[] = 'Karty: '.$this->cards.', pozycji (tęgość × rozmiar): '.$this->positions;
        if ($this->merged !== []) {
            $lines[] = 'Tęgości z osobnych pozycji katalogu dołączone do modelu: '.self::listing($this->merged);
        }
        if ($this->surcharged !== []) {
            $lines[] = 'Rozmiary z dopłatą (cena pozycji powiększona o procent ze strony): '.self::listing($this->surcharged);
        }
        if ($this->withoutPrice !== []) {
            $lines[] = 'Bez ceny konta (pominięte): '.self::listing($this->withoutPrice);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Bez opisu na stronie: '.self::listing($this->withoutDescription);
        }

        return $lines;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return self::BRAND;
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'wyrób nieodczytany'));
        }
        $price = $product->raw['price'] ?? null;
        if (! $price instanceof B2bRemotePrice) {
            throw new RuntimeException('wyrób bez ceny konta');
        }

        return $price;
    }

    public function description(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['description'] ?? '');
    }

    /**
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        $raw = $product->raw;
        if (($raw['status'] ?? null) !== 'ok') {
            return [];
        }

        $fields = [];
        foreach ($raw['fields'] as $name => $value) {
            if ($value !== '') {
                $fields[] = new B2bRemoteShopField(self::SECTION, $name, $value);
            }
        }

        return $fields;
    }

    /**
     * @return list<B2bRemoteDocument>
     */
    public function documents(B2bRemoteProduct $product): array
    {
        return array_map(
            static fn (array $file): B2bRemoteDocument => new B2bRemoteDocument($file['title'], $file['url'], ProductDocument::KIND_CERTIFICATE),
            $product->raw['documents'] ?? [],
        );
    }

    public function documentBytes(B2bRemoteDocument $document): array
    {
        return $this->client->fileBytes($document->sourceUrl);
    }

    /**
     * @return list<string>
     */
    public function imageUrls(B2bRemoteProduct $product): array
    {
        return $product->raw['images'] ?? [];
    }

    public function imageAt(string $url): ?B2bRemoteImage
    {
        $file = $this->client->fileBytes($url);
        if ($file['bytes'] === '' || ! str_starts_with($file['mime'], 'image/')) {
            return null;
        }

        return new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: $url);
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $urls = $this->imageUrls($product);

        return $urls === [] ? null : $this->imageAt($urls[0]);
    }

    /**
     * Katalog /produkt.html: pozycje listy (adres strony wyrobu, numer artykułu ze spacjami zwiniętymi, nazwa) w kolejności
     * strony. Pozycja bez adresu albo numeru odpada; ten sam numer dwa razy — raz.
     *
     * @return list<array{path: string, number: string, name: string}>
     */
    public static function parseCatalog(string $html): array
    {
        $xpath = self::xpath($html);
        $out = [];
        $seen = [];
        foreach ($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " product-list__item ")][contains(concat(" ", normalize-space(@class), " "), " grid__column ")]') ?: [] as $item) {
            $link = $xpath->query('.//div[contains(@class, "product-list-text")]/a', $item)?->item(0);
            $path = $link instanceof DOMElement ? self::sitePath($link->getAttribute('href')) : null;
            $input = $xpath->query('.//input[@name="Nummer[]"]', $item)?->item(0);
            $number = $input instanceof DOMElement ? self::number($input->getAttribute('value')) : '';
            if ($path === null || $number === '' || isset($seen[$number])) {
                continue;
            }
            $seen[$number] = true;
            $out[] = ['path' => $path, 'number' => $number, 'name' => self::clean((string) $link?->textContent)];
        }

        return $out;
    }

    /**
     * Pozycje katalogu połączone w modele: osobna pozycja tęgości („91313 S3”, „Flash 6405 XP BOA,Weite 13”) idzie do
     * modelu o tym samym rdzeniu numeru i przyrostku („91300 S3”). Wymagamy słowa „Weite” w nazwie pozycji tęgości —
     * sam numer kończący się na 12/13/14 nie wystarcza. Model bez pozycji tęgości 10 w katalogu = sama pozycja tęgości.
     *
     * @param  list<array{path: string, number: string, name: string}>  $rows
     * @return list<list<array{path: string, number: string, name: string}>> pozycja wiodąca (tęgość 10) pierwsza
     */
    public static function groupRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[self::groupKey($row['number'], $row['name'])][] = $row;
        }
        foreach ($groups as &$group) {
            usort($group, static fn (array $a, array $b): int => self::widthOrder($a['number']) <=> self::widthOrder($b['number']));
        }
        unset($group);

        return array_values($groups);
    }

    /**
     * Strona wyrobu zalogowanego konta.
     *
     * @return array{name: string, number: string, sizes: string, norms: list<string>, description: string, price: array{net: float, base: float|null, discount: float, currency: string}|null, rows: list<array{label: string, cells: list<array{size: string, number: string, width: string, surcharge: string, availability: string}>}>, hint: string, documents: list<array{title: string, url: string}>, images: list<string>}
     */
    public static function parseProductPage(string $html): array
    {
        $xpath = self::xpath($html);
        $details = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " product-detail__description ")]')?->item(0);

        $number = '';
        $sizes = '';
        $norms = [];
        $lines = [];
        if ($details !== null) {
            foreach (self::headLines($details) as $line) {
                if (preg_match('/^numer artykułu\s*(.*)$/iu', $line, $m) === 1) {
                    $number = self::clean($m[1]);
                } elseif (preg_match('/^rozmiary\s*:\s*(.*)$/iu', $line, $m) === 1) {
                    $sizes = self::clean($m[1]);
                } else {
                    if (preg_match('/^(?:PN[\s-]+)?EN\s?(?:ISO\s?)?\d/u', $line) === 1) {
                        $norms[] = $line;
                    }
                    $lines[] = $line;
                }
            }
            foreach ($xpath->query('.//div[contains(@class, "frame--type-text")]', $details) ?: [] as $frame) {
                foreach (self::blockLines($frame) as $line) {
                    $lines[] = $line;
                }
            }
        }

        return [
            'name' => self::clean((string) $xpath->query('//h1')?->item(0)?->textContent),
            'number' => $number,
            'sizes' => $sizes,
            'norms' => $norms,
            'description' => mb_substr(implode("\n", $lines), 0, 10000),
            'price' => self::pagePrice($xpath),
            'rows' => self::orderRows($xpath),
            'hint' => self::clean((string) $xpath->query('//p[contains(@class, "product-detail__hint")]')?->item(0)?->textContent),
            'documents' => self::documentLinks($xpath),
            'images' => self::imageLinks($xpath),
        ];
    }

    /**
     * Cena z napisu „Cena artykułu: 237.90 PLN (incl. 22% Rabat)” (kropka dziesiętna, jak na stronie); null = to nie cena.
     *
     * @return array{net: float, currency: string, discount: float}|null
     */
    public static function parsePriceText(string $text): ?array
    {
        $text = self::clean($text);
        if (preg_match('/Cena artykułu:\s*(\d+(?:[.,]\d{1,2})?)\s*([A-Z]{3})(?:\s*\(incl\.\s*(\d+(?:[.,]\d+)?)\s*%\s*Rabat\))?/u', $text, $m) !== 1) {
            return null;
        }
        $net = (float) str_replace(',', '.', $m[1]);

        return $net > 0 ? ['net' => $net, 'currency' => $m[2], 'discount' => isset($m[3]) ? (float) str_replace(',', '.', $m[3]) : 0.0] : null;
    }

    /**
     * Dopłata rozmiaru nietypowego z pola „Uebergroesse” („20 % ÜGZ”) w procentach; null = brak dopłaty.
     */
    public static function surchargePercent(string $value): ?float
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*%/u', $value, $m) !== 1) {
            return null;
        }
        $percent = (float) str_replace(',', '.', $m[1]);

        return $percent > 0 ? $percent : null;
    }

    /**
     * @param  list<array{path: string, number: string, name: string}>  $group
     * @param  array<string, true>  $seen  remote_id pozycji z wcześniejszych kart przebiegu
     */
    private function productFor(array $group, array &$seen): B2bRemoteProduct
    {
        $lead = $group[0];
        $pages = [];
        foreach ($group as $row) {
            try {
                $page = self::parseProductPage($this->client->page($row['path']));
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException $e) {
                // bez jednej tęgości karta zmieniłaby tabelę i cenę — cały model pominięty w tym przebiegu
                return $this->skipped($lead['number'], $lead['name'], 'strona wyrobu '.$row['number'].': '.$e->getMessage());
            }
            if ($page['price'] === null || $page['rows'] === []) {
                $this->withoutPrice[] = $row['number'];
                if ($row === $lead) {
                    return $this->skipped($lead['number'], $page['name'] !== '' ? $page['name'] : $lead['name'], 'strona wyrobu bez ceny konta');
                }

                continue;
            }
            $pages[] = ['row' => $row, 'page' => $page];
        }
        if (count($pages) > 1) {
            $this->merged[] = implode(' + ', array_map(static fn (array $p): string => $p['row']['number'], $pages));
        }
        $this->withPrice++;

        $leadPage = $pages[0]['page'];
        $name = $leadPage['name'] !== '' ? $leadPage['name'] : $lead['name'];
        $cells = [];
        foreach ($pages as ['row' => $row, 'page' => $page]) {
            foreach ($page['rows'] as $tableRow) {
                foreach ($tableRow['cells'] as $cell) {
                    $number = $cell['number'] !== '' ? $cell['number'] : $row['number'];
                    $width = $cell['width'] !== '' ? $cell['width'] : (self::widthLabel($tableRow['label']) ?? self::widthLabel($page['name']) ?? '');
                    $cells[] = $cell + ['article' => $number, 'width_label' => $width, 'page_price' => $page['price']];
                }
            }
        }

        $widths = array_values(array_unique(array_filter(array_column($cells, 'width_label'), static fn (string $w): bool => $w !== '')));
        $manyWidths = count($widths) > 1;
        // jedna komórka tabeli (spray, sznurowadła) = karta bez pozycji, remote_id = sam numer artykułu
        $single = count($cells) === 1;
        $members = [];
        $firstOfArticle = [];
        $widthOfArticle = [];
        $sizeNames = [];
        $byStatus = [];
        $surcharged = [];
        $cheapest = null;
        foreach ($cells as $cell) {
            $remoteId = $single ? $cell['article'] : $cell['article'].'/'.$cell['size'];
            if (isset($seen[$remoteId])) {
                continue;
            }
            $seen[$remoteId] = true;
            $percent = self::surchargePercent($cell['surcharge']);
            $pagePrice = $cell['page_price'];
            $factor = $percent !== null ? 1 + $percent / 100 : 1.0;
            $price = new B2bRemotePrice(
                net: round($pagePrice['net'] * $factor, 2),
                base: $pagePrice['base'] !== null ? round($pagePrice['base'] * $factor, 2) : null,
                discountPercent: $pagePrice['discount'],
                currency: $pagePrice['currency'],
            );
            $sizeLabel = $manyWidths && $cell['width_label'] !== '' ? $cell['width_label'].' / '.$cell['size'] : $cell['size'];
            if ($percent !== null) {
                $surcharged[$cell['size']] = $cell['surcharge'];
            }
            $members[] = [
                'remote_id' => $remoteId,
                'sku' => $remoteId,
                'name' => $name.', '.$sizeLabel,
                'availability' => self::AVAILABILITY[$cell['availability']] ?? '',
                'size' => $sizeLabel,
                'price' => $price,
            ];
            $sizeNames[$cell['size']] = true;
            $byStatus[$cell['availability']][$manyWidths ? $cell['width_label'] : ''][] = $cell['size'];
            $firstOfArticle[$cell['article']] ??= $remoteId;
            $widthOfArticle[$cell['article']] ??= $cell['width_label'];
            if ($cheapest === null || $price->net < $cheapest->net - 0.0049) {
                $cheapest = $price;
            }
        }
        if ($members === [] || $cheapest === null) {
            return $this->skipped($lead['number'], $name, 'pozycje wyrobu już na innej karcie przebiegu');
        }
        $grouped = count($members) > 1;
        $this->cards++;
        $this->positions += count($members);
        foreach ($surcharged as $size => $text) {
            $this->surcharged[] = $lead['number'].' '.$size.' ('.$text.')';
        }

        // numer z formularza zamówienia („75612 S3” — także tęgość 12 bez własnej pozycji katalogu) i numer pokazany
        // na stronie („numer artykułu 75600”), każdy przy pierwszej pozycji swojego artykułu
        $identifiers = [];
        foreach ($firstOfArticle as $article => $remoteId) {
            $identifiers[] = new B2bRemoteIdentifier(
                type: ProductIdentifier::TYPE_SOURCE_CODE,
                value: (string) $article,
                remoteId: $grouped ? $remoteId : null,
                label: $manyWidths && $widthOfArticle[$article] !== '' ? $widthOfArticle[$article] : null,
                field: 'Artikelnummer',
            );
        }
        $displayed = [];
        foreach ($pages as ['row' => $row, 'page' => $page]) {
            if ($page['number'] === '' || in_array($page['number'], $displayed, true)) {
                continue;
            }
            $displayed[] = $page['number'];
            $identifiers[] = new B2bRemoteIdentifier(
                type: ProductIdentifier::TYPE_MANUFACTURER_CODE,
                value: $page['number'],
                remoteId: $grouped ? ($firstOfArticle[$row['number']] ?? null) : null,
                label: self::widthLabel($page['name']),
                field: 'numer artykułu',
            );
        }

        $norms = [];
        $documents = [];
        $images = [];
        foreach ($pages as ['page' => $page]) {
            foreach ($page['norms'] as $norm) {
                $norms[$norm] = true;
            }
            foreach ($page['documents'] as $document) {
                $documents[$document['url']] ??= $document;
            }
            foreach ($page['images'] as $image) {
                $images[$image] = true;
            }
        }

        if ($leadPage['description'] === '') {
            $this->withoutDescription[] = $lead['number'];
        }
        $sizeList = implode(', ', array_map('strval', array_keys($sizeNames)));
        $surchargeText = '';
        if ($surcharged !== []) {
            $hint = (string) preg_replace('/^\*\s*/u', '', $leadPage['hint']);
            $surchargeText = implode(', ', array_map('strval', array_keys($surcharged))).': '.($hint !== '' ? $hint : implode(', ', array_unique($surcharged)));
        }

        $remoteId = $members[0]['remote_id'];
        $members = $grouped ? $members : [];

        return new B2bRemoteProduct(
            remoteId: $remoteId,
            sku: $lead['number'],
            name: $name,
            sourceUrl: AtlasB2bClient::BASE.$lead['path'],
            raw: [
                'status' => 'ok',
                // cena karty = najniższa cena pozycji (B2bCatalogSync liczy ją też z members[].price)
                'price' => $cheapest,
                'description' => $leadPage['description'],
                'fields' => [
                    'Numer artykułu' => implode(', ', $displayed),
                    self::NORM_FIELD => implode('; ', array_keys($norms)),
                    'Rozmiary' => $leadPage['sizes'],
                    'Tęgości' => $manyWidths ? implode(', ', $widths) : '',
                    'Rozmiar nietypowy' => $surchargeText,
                ],
                'documents' => array_values($documents),
                'images' => array_keys($images),
            ],
            availability: self::availabilityText($byStatus, $grouped),
            variantSummary: $sizeNames === [] || $members === [] ? null : ($manyWidths ? 'Tęgości: '.implode(', ', $widths).'; rozmiary: ' : 'Rozmiary: ').$sizeList,
            members: $members,
            identifiers: $identifiers,
        );
    }

    /**
     * Cena strony: napis „Cena artykułu: …” (cena konta) i ukryte pole „Preis_csv” formularza (cena katalogowa przed
     * rabatem konta). Bez napisu — null (strona bez ceny, np. katalog drukowany).
     *
     * @return array{net: float, base: float|null, discount: float, currency: string}|null
     */
    private static function pagePrice(DOMXPath $xpath): ?array
    {
        $parsed = self::parsePriceText((string) $xpath->query('//table[contains(@class, "priceWDiscount")]')?->item(0)?->textContent);
        if ($parsed === null) {
            return null;
        }
        $base = null;
        foreach ($xpath->query('//input[@type="hidden"][substring(@name, string-length(@name) - 10) = "[Preis_csv]"]') ?: [] as $input) {
            $value = $input instanceof DOMElement ? trim($input->getAttribute('value')) : '';
            if (preg_match('/^\d+(?:\.\d{1,2})?$/', $value) === 1 && (float) $value > 0) {
                $base = (float) $value;
                break;
            }
        }

        return ['net' => $parsed['net'], 'base' => $base, 'discount' => $parsed['discount'], 'currency' => $parsed['currency']];
    }

    /**
     * Tabela zamówienia (#Order_table): wiersz = tęgość (etykieta pierwszej komórki dosłownie), komórka = rozmiar z ukrytymi
     * polami „Text” (rozmiar), „Artikelnummer”, „Uebergroesse” (dopłata) i „Weite” (tęgość wiersza innego artykułu);
     * dostępność = klasa pola ilości („availability-green”).
     *
     * @return list<array{label: string, cells: list<array{size: string, number: string, width: string, surcharge: string, availability: string}>}>
     */
    private static function orderRows(DOMXPath $xpath): array
    {
        $rows = [];
        foreach ($xpath->query('//table[@id="Order_table"]//tbody/tr') ?: [] as $tr) {
            $label = '';
            $cells = [];
            foreach ($xpath->query('./td', $tr) ?: [] as $index => $td) {
                if ($index === 0) {
                    $label = self::clean($td->textContent);

                    continue;
                }
                $size = self::hiddenValue($xpath, $td, '[Text]');
                if ($size === null || $size === '') {
                    continue;
                }
                $quantity = $xpath->query('.//input[substring(@name, string-length(@name) - 6) = "[Menge]"]', $td)?->item(0);
                $availability = '';
                if ($quantity instanceof DOMElement && preg_match('/\bavailability-(\w+)\b/', $quantity->getAttribute('class'), $m) === 1) {
                    $availability = $m[1];
                }
                $weite = $xpath->query('.//input[contains(@name, "[Weite]")]', $td)?->item(0);
                $cells[] = [
                    'size' => $size,
                    'number' => self::number((string) self::hiddenValue($xpath, $td, '[Artikelnummer]')),
                    'width' => $weite instanceof DOMElement ? (self::widthLabel($weite->getAttribute('value')) ?? '') : '',
                    'surcharge' => (string) self::hiddenValue($xpath, $td, '[Uebergroesse]'),
                    'availability' => $availability,
                ];
            }
            if ($cells !== []) {
                $rows[] = ['label' => $label, 'cells' => $cells];
            }
        }

        return $rows;
    }

    private static function hiddenValue(DOMXPath $xpath, DOMNode $context, string $suffix): ?string
    {
        $length = strlen($suffix) - 1;
        $input = $xpath->query('.//input[substring(@name, string-length(@name) - '.$length.') = "'.$suffix.'"]', $context)?->item(0);

        return $input instanceof DOMElement ? self::clean($input->getAttribute('value')) : null;
    }

    /**
     * Wiersze bloku „wyposażenie” przed listą cech: tekst między znacznikami <br> (numer artykułu, norma, rozmiary);
     * nagłówek „wyposażenie” i kolumny z listą cech i przyciskami pomijane.
     *
     * @return list<string>
     */
    private static function headLines(DOMNode $details): array
    {
        $lines = [];
        $current = '';
        foreach ($details->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if ($tag === 'br') {
                    $lines[] = $current;
                    $current = '';

                    continue;
                }
                if ($tag === 'h2' || $tag === 'div') {
                    continue;
                }
            }
            $current .= ' '.$child->textContent;
        }
        $lines[] = $current;

        return array_values(array_filter(array_map(self::clean(...), $lines), static fn (string $l): bool => $l !== ''));
    }

    /**
     * Tekst bloku opisu: punkt listy = linia, tekst między <br> albo akapit = linia. Treść dosłownie, odstępy zwinięte.
     *
     * @return list<string>
     */
    private static function blockLines(DOMNode $node): array
    {
        $lines = [];
        $current = '';
        $flush = static function () use (&$lines, &$current): void {
            $text = self::clean($current);
            if ($text !== '') {
                $lines[] = $text;
            }
            $current = '';
        };
        $walk = static function (DOMNode $node) use (&$walk, &$current, $flush): void {
            foreach ($node->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    $current .= $child->textContent;

                    continue;
                }
                $tag = strtolower($child->tagName);
                if ($tag === 'br') {
                    $flush();
                } elseif (in_array($tag, ['li', 'p', 'div', 'ul', 'ol', 'h3', 'h4', 'table', 'tr'], true)) {
                    $flush();
                    $walk($child);
                    $flush();
                } elseif (! in_array($tag, ['script', 'style'], true)) {
                    $walk($child);
                }
            }
        };
        $walk($node);
        $flush();

        return $lines;
    }

    /**
     * „Deklaracja zgodności EU” — tylko odnośniki do plików PDF (/eu-pdf/MAX 100 PRO.pdf); odnośnik do ogólnej strony
     * deklaracji („/index.php?id=509”) to nie plik wyrobu.
     *
     * @return list<array{title: string, url: string}>
     */
    private static function documentLinks(DOMXPath $xpath): array
    {
        $out = [];
        foreach ($xpath->query('//div[contains(@class, "product-detail__buttons")]//a[@href]') ?: [] as $a) {
            if (! $a instanceof DOMElement) {
                continue;
            }
            $path = self::sitePath($a->getAttribute('href'));
            if ($path === null || preg_match('/\.pdf$/i', (string) parse_url($path, PHP_URL_PATH)) !== 1) {
                continue;
            }
            $title = self::clean($a->textContent);
            $url = AtlasB2bClient::BASE.$path;
            $out[$url] = ['title' => $title !== '' ? $title : basename(rawurldecode($path)), 'url' => $url];
        }

        return array_values($out);
    }

    /**
     * Zdjęcia galerii strony wyrobu (.product-slider, bez miniatur nawigacji) w kolejności strony.
     *
     * @return list<string>
     */
    private static function imageLinks(DOMXPath $xpath): array
    {
        $out = [];
        foreach ($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " product-slider ")]//img') ?: [] as $img) {
            $path = $img instanceof DOMElement ? self::sitePath($img->getAttribute('src')) : null;
            if ($path === null) {
                continue;
            }
            $url = AtlasB2bClient::BASE.$path;
            if (! in_array($url, $out, true)) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /**
     * Dostępność karty słowami legendy tabeli: „dostępne natychmiast: 36, 37; artykuł jest w produkcji: 48”, przy kilku
     * tęgościach rozmiary pod tęgością („dostępne natychmiast — tęgość 10: 36, 37; tęgość 12: 36”). Wszystkie pozycje
     * w jednym stanie (albo karta bez rozmiarów) — sam stan.
     *
     * @param  array<string, array<string, list<string>>>  $byStatus  kolor pola → tęgość ('' = bez podziału) → rozmiary
     */
    private static function availabilityText(array $byStatus, bool $withSizes): ?string
    {
        $known = array_intersect_key($byStatus, self::AVAILABILITY);
        if ($known === []) {
            return null;
        }
        if (! $withSizes || count($byStatus) === 1) {
            $colour = array_key_first($known);

            return self::AVAILABILITY[$colour].($withSizes ? ' (wszystkie rozmiary)' : '');
        }
        $parts = [];
        foreach (self::AVAILABILITY as $colour => $label) {
            $widths = $byStatus[$colour] ?? [];
            if ($widths === []) {
                continue;
            }
            if (array_keys($widths) === ['']) {
                $parts[] = $label.': '.implode(', ', $widths['']);

                continue;
            }
            $perWidth = [];
            foreach ($widths as $width => $sizes) {
                $perWidth[] = ($width !== '' ? $width.': ' : '').implode(', ', $sizes);
            }
            $parts[] = $label.' — '.implode('; ', $perWidth);
        }

        return mb_substr(implode(' | ', $parts), 0, 1000);
    }

    /** „tęgość 12” z etykiety wiersza, pola „Weite 12” albo nazwy „…,Weite 13”; null = brak tęgości. */
    private static function widthLabel(string $text): ?string
    {
        return preg_match('/\b(?:tęgość|Weite)\s*(\d{2})\b/iu', $text, $m) === 1 ? 'tęgość '.$m[1] : null;
    }

    /**
     * Rdzeń numeru modelu: „91313 S3” z nazwą „…Weite 13” → „913|S3”; numer tęgości 10 („91300 S3”) → ten sam rdzeń.
     * Pozostałe numery (skarpety „160510”, wkładki „98650”) — same dla siebie.
     */
    private static function groupKey(string $number, string $name): string
    {
        if (preg_match('/^(\d+)(00|12|13|14)\s+(\S+)$/', $number, $m) !== 1) {
            return $number;
        }
        if ($m[2] !== '00' && preg_match('/\bWeite\s*'.$m[2].'\b/iu', $name) !== 1) {
            return $number;
        }

        return $m[1].'|'.$m[3];
    }

    /** Kolejność pozycji modelu: tęgość 10 (numer …00) pierwsza, potem 12, 13, 14. */
    private static function widthOrder(string $number): int
    {
        return preg_match('/^\d+(00|12|13|14)\s/', $number, $m) === 1 ? (int) $m[1] : 0;
    }

    /**
     * Wyrób, którego nie da się zapisać w tym przebiegu — pozycja bez ceny, widoczna jako pominięta.
     */
    private function skipped(string $sku, string $name, string $reason): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: $sku,
            sku: $sku,
            name: $name !== '' ? $name : $sku,
            raw: ['status' => 'skipped', 'reason' => $reason],
        );
    }

    /**
     * Ścieżka na witrynie z adresu ze strony („/index.php?id=90&asanr=…”, „/eu-pdf/MAX 100 PRO.pdf”,
     * „https://www.atlas-obuwie.pl/…”); null = adres spoza witryny, kotwica, javascript albo pusty. Spacje kodowane (%20).
     */
    private static function sitePath(string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || str_starts_with($href, '#') || preg_match('/^(?:javascript|mailto|tel|data):/i', $href) === 1) {
            return null;
        }
        if (preg_match('#^https?://#i', $href) === 1) {
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            if ($host !== AtlasB2bClient::HOST) {
                return null;
            }
            $query = (string) parse_url($href, PHP_URL_QUERY);
            $href = (string) parse_url($href, PHP_URL_PATH).($query !== '' ? '?'.$query : '');
        } elseif (! str_starts_with($href, '/') || str_starts_with($href, '//')) {
            return null;
        }
        $href = str_replace(' ', '%20', (string) preg_replace('/#.*$/s', '', $href));
        if ($href === '' || str_contains($href, '..')) {
            return null;
        }

        return $href;
    }

    /** Numer artykułu ze strony („75600   S3”) ze spacjami zwiniętymi: „75600 S3”. */
    private static function number(string $value): string
    {
        return self::clean($value);
    }

    private static function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }

    private function progress(string $message): void
    {
        if ($this->listProgress !== null) {
            ($this->listProgress)($message);
        }
    }

    /**
     * @param  list<string>  $items
     */
    private static function listing(array $items): string
    {
        return count($items).', np. '.implode(', ', array_slice($items, 0, 10));
    }

    /** Tekst ze strony: bez znaków zerowej szerokości, odstępy (także twarde spacje) zwinięte. */
    private static function clean(string $text): string
    {
        $text = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}"], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }
}
