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
 * b2b.mactronic.pl — hurtownia producenta latarek Mactronic (ImB2B). Sprawdzone na zalogowanym koncie 01.10.2026
 * (MactronicB2bClient opisuje logowanie i sesję).
 *
 * Lista: „Produkty” (/product/category) po 60, w kolejności indeksów (01.10.2026: 675 produktów na 12 stronach).
 * Karta = produkt sklepu (id z adresu listy i strony, „Kod produktu” np. THH0126) — tabela „Warianty” produktu
 * pokazuje tylko ten sam produkt. Dane karty ze strony produktu (jedno zapytanie na produkt).
 *
 * Cena: „Twoja cena” = cena konta netto, „Cena katalogowa” = cena bazowa netto (dymek przy cenie), obie za jednostkę
 * sprzedaży sklepu („szt.”, „bl” = blister). Cen brutto nie zapisujemy.
 *
 * Producent = „Producent” z parametrów strony („Mactronic”, „Falcon Eye”, „Streamlight”…); produkt bez niego zostaje
 * bez producenta, bez zgadywania. Opis = „Opis produktu” dosłownie (tabele parametrów z komórkami rozdzielonymi „ | ”);
 * krótki tekst z „Parametrów technicznych” — do tabelki sklepu. „Kod EAN” i „Kod produktu” — do identyfikatorów.
 * Pliki do pobrania (instrukcje, karty produktu, deklaracje CE) tylko z sesją; zdjęcia galerii publiczne, 1000×1000.
 */
final class MactronicB2bConnector implements B2bConnector, B2bDocumentSource, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldSource
{
    public const BRAND = 'Mactronic';

    private const SECTION = 'Informacje ze sklepu Mactronic';

    /** Ochrona przed zapętleniem listy (01.10.2026: 12 stron po 60). */
    private const MAX_LIST_PAGES = 200;

    /** Wiersz krótkiego tekstu z uwagą o wyprzedaży (01.10.2026: 156 produktów, zawsze ten sam napis). */
    private const SALE_NOTE = '/^WYPRZEDA[ŻZ](?![\p{L}\p{N}])/iu';

    /** Kod wyjątku: lista zmieniła się w trakcie pobierania (listItems pobiera ją wtedy jeszcze raz). */
    private const INCONSISTENT = 7302;

    /**
     * Tyle produktów z odczytaną stroną, a żaden z ceną konta, zanim pojawi się pierwsza cena = sesja bez cen (albo
     * zmiana sklepu) — przebieg przerwany.
     */
    private const MAX_FIRST_WITHOUT_PRICE = 20;

    private const PROGRESS_EVERY = 50;

    private const EPSILON = 0.005;

    private int $total = 0;

    private int $cards = 0;

    private int $withPrice = 0;

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> */
    private array $withoutPrice = [];

    /** @var list<string> */
    private array $withoutBase = [];

    /** @var list<string> */
    private array $baseBelowNet = [];

    /** @var list<string> */
    private array $withoutProducer = [];

    /** @var list<string> */
    private array $withoutDescription = [];

    /** @var list<string> */
    private array $shortDescription = [];

    /** @var list<string> */
    private array $duplicateCodes = [];

    /** @var array<string, int> */
    private array $producers = [];

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public function __construct(private readonly MactronicB2bClient $client) {}

    public static function key(): string
    {
        return 'mactronic';
    }

    public static function label(): string
    {
        return self::BRAND;
    }

    public static function host(): string
    {
        return MactronicB2bClient::HOST;
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new MactronicB2bClient((string) $account->username, (string) $account->password, $delayMs));
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
        $this->withoutBase = [];
        $this->baseBelowNet = [];
        $this->withoutProducer = [];
        $this->withoutDescription = [];
        $this->shortDescription = [];
        $this->duplicateCodes = [];
        $this->producers = [];
        $this->cards = 0;
        $this->withPrice = 0;

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }

        $list = $this->listItems();
        $this->total = count($list);
        $this->summary[] = 'Lista Mactronic: '.count($list).' produktów';
        $this->progress('Lista Mactronic: '.count($list).' produktów');

        $done = 0;
        $seenCodes = [];
        foreach ($list as $item) {
            $product = $this->productFor($item, $seenCodes);
            if ($this->withPrice === 0 && count($this->withoutPrice) >= self::MAX_FIRST_WITHOUT_PRICE) {
                throw new B2bFatalException(count($this->withoutPrice).' pierwszych produktów '.MactronicB2bClient::HOST.' bez żadnej ceny konta — sesja konta nie pokazuje cen albo sklep zmienił stronę produktu; przebieg przerwany');
            }
            $done++;
            if ($done % self::PROGRESS_EVERY === 0) {
                $this->progress('Produkty Mactronic: '.$done.'/'.count($list));
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
        $lines[] = 'Karty: '.$this->cards;
        if ($this->producers !== []) {
            arsort($this->producers);
            $lines[] = 'Producenci: '.implode(', ', array_map(
                static fn (string $name, int $count): string => $name.' '.$count,
                array_keys($this->producers),
                array_values($this->producers),
            ));
        }
        if ($this->withoutPrice !== []) {
            $lines[] = 'Bez ceny konta (pominięte): '.self::listing($this->withoutPrice);
        }
        if ($this->withoutBase !== []) {
            $lines[] = 'Bez ceny katalogowej: '.self::listing($this->withoutBase);
        }
        if ($this->baseBelowNet !== []) {
            $lines[] = 'Cena katalogowa niższa niż cena konta (bez ceny katalogowej): '.self::listing($this->baseBelowNet);
        }
        if ($this->duplicateCodes !== []) {
            $lines[] = 'Ten sam Kod produktu na kilku produktach (kolejne pominięte): '.self::listing($this->duplicateCodes);
        }
        if ($this->withoutProducer !== []) {
            $lines[] = 'Bez producenta w sklepie (karty bez producenta): '.self::listing($this->withoutProducer);
        }
        if ($this->shortDescription !== []) {
            $lines[] = 'Bez „Opisu produktu” — opis z krótkiego tekstu: '.self::listing($this->shortDescription);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Bez opisu w sklepie: '.self::listing($this->withoutDescription);
        }

        return $lines;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['manufacturer'] ?? '');
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'produkt nieodczytany'));
        }
        $price = $product->raw['price'] ?? null;
        if (! $price instanceof B2bRemotePrice) {
            throw new RuntimeException('produkt bez ceny konta');
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
        foreach ([
            'Kod produktu' => $raw['code'] ?? '',
            'Kod EAN' => $raw['ean'] ?? '',
            'Producent' => $raw['manufacturer'] ?? '',
            'Parametry techniczne' => $raw['summary'] ?? '',
            'Uwaga sklepu' => implode('; ', $raw['notes'] ?? []),
            'Jednostka sprzedaży' => $raw['unit'] ?? '',
        ] as $name => $value) {
            if ((string) $value !== '') {
                $fields[] = new B2bRemoteShopField(self::SECTION, $name, (string) $value);
            }
        }

        return $fields;
    }

    public function documents(B2bRemoteProduct $product): array
    {
        return array_map(
            static fn (array $file): B2bRemoteDocument => new B2bRemoteDocument($file['title'], $file['url'], $file['kind']),
            $product->raw['documents'] ?? [],
        );
    }

    public function documentBytes(B2bRemoteDocument $document): array
    {
        $file = $this->client->fileBytes($document->sourceUrl);
        if (str_starts_with($file['bytes'], '%PDF-')) {
            $file['mime'] = 'application/pdf';
        }

        return $file;
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
     * Pozycje jednej strony listy i liczba stron („Strona 1 z 12”).
     *
     * @return array{pages: int|null, items: list<array{id: int, url: string, name: string, code: string}>}
     */
    public static function parseListPage(string $html): array
    {
        $pages = preg_match('#<span class="pages">\s*(\d+)\s*</span>#', $html, $m) === 1 ? (int) $m[1] : null;

        $items = [];
        $parts = preg_split('#<div class="product btn__container product--(\d+)"[^>]*>#', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $id = (int) $parts[$i];
            $chunk = $parts[$i + 1];
            if (preg_match('#<a class="product__main-link"[^>]*\bhref="([^"]+)"#', $chunk, $link) !== 1) {
                continue;
            }
            $url = self::absoluteUrl($link[1]);
            if ($url === null) {
                continue;
            }
            $name = preg_match('#class="product__main-link"[^>]*\btitle="([^"]*)"#', $chunk, $t) === 1 ? self::clean(html_entity_decode($t[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
            $code = preg_match('#<div class="index">(.*?)</div>#s', $chunk, $c) === 1 ? self::clean(html_entity_decode(strip_tags($c[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
            $items[] = ['id' => $id, 'url' => $url, 'name' => $name, 'code' => $code];
        }

        return ['pages' => $pages, 'items' => $items];
    }

    /**
     * Strona produktu: nazwa, kody, ceny konta i katalogowa, jednostka, dostępność, producent, krótki tekst parametrów,
     * opis, ścieżka kategorii, zdjęcia galerii i pliki do pobrania.
     *
     * @return array{id: int|null, name: string, code: string, ean: string, net: float|null, base: float|null, currency: string|null, unit: string, availability: string, manufacturer: string, summary: string, notes: list<string>, description: string, categories: list<string>, images: list<string>, documents: list<array{title: string, url: string, kind: string}>}
     */
    public static function parseProductPage(string $html): array
    {
        $xpath = self::xpath($html);
        $class = static fn (string $name): string => 'contains(concat(" ", normalize-space(@class), " "), " '.$name.' ")';
        $first = static fn (string $query, ?DOMNode $context = null): ?DOMNode => $xpath->query($query, $context)?->item(0);

        $id = preg_match('#<body\b[^>]*\bclass="[^"]*\bproduct-view-(\d+)\b#', $html, $m) === 1 ? (int) $m[1] : null;
        $name = self::clean((string) $first('//div['.$class('product__header__wrapper').']//h1['.$class('product__title').']')?->textContent);
        $code = self::labelledValue($first('//div['.$class('product__header__wrapper').']//div['.$class('product__indeks').']'));
        $ean = self::labelledValue($first('//div['.$class('product__header__wrapper').']//div['.$class('product__ean').']'));

        // „Twoja cena” i „Cena katalogowa” z dymka przy cenie; bez dymka — cena wyświetlona (cena konta)
        $priceRow = $first('//div['.$class('product__price-row').']');
        $net = null;
        $base = null;
        $currency = null;
        $unit = '';
        if ($priceRow !== null) {
            foreach ($xpath->query('.//div['.$class('hover__row').']', $priceRow) ?: [] as $row) {
                $label = mb_strtolower(self::clean((string) $first('./label', $row)?->textContent));
                $main = $first('.//div['.$class('price--main').']', $row);
                if ($main === null) {
                    continue;
                }
                if ($label === 'twoja cena') {
                    [$net, $currency, $unit] = self::priceOf($main);
                } elseif ($label === 'cena katalogowa') {
                    [$base] = self::priceOf($main);
                }
            }
            if ($net === null) {
                $main = $first('.//div['.$class('price--main').']', $priceRow);
                if ($main !== null) {
                    [$net, $currency, $unit] = self::priceOf($main);
                }
            }
        }

        $availability = self::labelledValue($first('//div['.$class('product__availability-column').']//span['.$class('available').']'));
        $manufacturer = self::clean((string) $first('//div['.$class('product__technical-manufacturer').']/span['.$class('title').']')?->textContent);

        // krótki tekst o wyrobie; dopisek o wyprzedaży („WYPRZEDAŻ. Cena ostateczna, nie podlega rabatowaniu”) to uwaga
        // handlowa sklepu, nie opis wyrobu — osobno
        $summaryLines = [];
        $notes = [];
        $technical = $first('//div['.$class('product__technical').']');
        if ($technical !== null) {
            foreach (explode("\n", self::descriptionText(self::innerHtml($technical))) as $line) {
                if ($line === '') {
                    continue;
                }
                if (preg_match(self::SALE_NOTE, $line) === 1) {
                    $notes[] = $line;
                } else {
                    $summaryLines[] = $line;
                }
            }
        }
        $summary = implode("\n", $summaryLines);
        $description = '';
        $descriptionNode = $first('//section[@id="product-description"]//div['.$class('description-content').']');
        if ($descriptionNode !== null) {
            $description = self::descriptionText(self::innerHtml($descriptionNode));
        }

        $categories = [];
        foreach ($xpath->query('//ul['.$class('breadcrumb').']/li') ?: [] as $li) {
            if (! $li instanceof DOMElement || preg_match('/\blevel-(\d+)\b/', $li->getAttribute('class'), $level) !== 1 || (int) $level[1] < 2) {
                continue;
            }
            $text = self::clean($li->textContent);
            $text = self::clean(rtrim($text, '/'));
            if ($text !== '') {
                $categories[] = $text;
            }
        }

        $images = [];
        foreach ($xpath->query('//div[@id="product-gallery"]/a['.$class('colorbox').' and '.$class('group').']') ?: [] as $a) {
            if (! $a instanceof DOMElement) {
                continue;
            }
            $url = self::absoluteUrl($a->getAttribute('href'));
            if ($url !== null && ! in_array($url, $images, true)) {
                $images[] = $url;
            }
        }

        $documents = [];
        foreach ($xpath->query('//section[@id="product-download"]//a['.$class('file__link').']') ?: [] as $a) {
            if (! $a instanceof DOMElement) {
                continue;
            }
            $url = self::absoluteUrl($a->getAttribute('href'));
            $title = self::clean($a->textContent);
            if ($url === null || $title === '' || in_array($url, array_column($documents, 'url'), true)) {
                continue;
            }
            $documents[] = ['title' => $title, 'url' => $url, 'kind' => self::documentKind($title)];
        }

        return [
            'id' => $id,
            'name' => $name,
            'code' => $code,
            'ean' => $ean,
            'net' => $net,
            'base' => $base,
            'currency' => $currency,
            'unit' => $unit,
            'availability' => $availability,
            'manufacturer' => $manufacturer,
            'summary' => $summary,
            'notes' => $notes,
            'description' => $description,
            'categories' => $categories,
            'images' => $images,
            'documents' => $documents,
        ];
    }

    /**
     * Opis HTML jako tekst: białe znaki jak w przeglądarce, podziały z tagów blokowych, punkty listy „- ”, komórki
     * tabel rozdzielone „ | ” (tabele parametrów: tryb | strumień | czas pracy). Treść dosłownie.
     */
    public static function descriptionText(string $html): string
    {
        $html = preg_replace('#<(script|style)\b.*?</\1>#is', '', $html) ?? $html;
        $text = preg_replace('/\s+/u', ' ', $html) ?? $html;
        $text = preg_replace('#<\s*/?\s*br\s*/?\s*>#i', "\n", $text) ?? $text;
        $text = preg_replace('#<\s*li[^>]*>#i', "\n- ", $text) ?? $text;
        $text = preg_replace('#</\s*t[dh]\s*>#i', "\u{1F}", $text) ?? $text;
        $text = preg_replace('#</?\s*(p|div|h[1-6]|ul|ol|tr|table|tbody|thead)\b[^>]*>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = [];
        foreach (preg_split('/\n/u', $text) ?: [] as $line) {
            if (str_contains($line, "\u{1F}")) {
                // puste komórki zostają na miejscu (inaczej wartość przeskoczy do sąsiedniej kolumny); wiersz z samych
                // pustych komórek — pominięty
                $cells = array_map(self::clean(...), explode("\u{1F}", $line));
                if (end($cells) === '') {
                    // odstęp po ostatniej komórce wiersza, nie komórka
                    array_pop($cells);
                }
                $line = implode('', $cells) === '' ? '' : implode(' | ', $cells);
            }
            $line = self::clean($line);
            if ($line !== '' && $line !== '-') {
                $lines[] = $line;
            }
        }

        return mb_substr(implode("\n", $lines), 0, 10000);
    }

    /**
     * Rodzaj pliku z nazwy: deklaracja/certyfikat („CE_Mactronic_THH0126_EMC.pdf”, „DEKLARACJA FCL0027.pdf”,
     * „Certyfikat_Mactronic_PFL0330_IP.pdf”), instrukcja („…_manual.pdf”, instrukcje Streamlight „…_op_PL.pdf”),
     * karta produktu („…_karta produktu-min.pdf”, „…_karta-produktu.pdf”, „…_karta produktowa_PL.pdf”); pozostałe
     * („ABR0051_RedLine 2.0.pdf”) — inne.
     */
    public static function documentKind(string $title): string
    {
        return match (true) {
            // „CE” tylko wielkimi literami i jako osobny człon nazwy (nie „PRICE”, nie „ce” w środku słowa)
            preg_match('/(?:^|[^A-Za-z])CE(?:[^A-Za-z]|$)/', $title) === 1,
            preg_match('/deklarac|declaration|zgodno|certyfikat|certificate|(?:^|[^a-z])doc(?:[^a-z]|$)/iu', $title) === 1 => ProductDocument::KIND_CERTIFICATE,
            preg_match('/instrukc|manual|instruction|user[ _-]?guide|_op_[a-z]{2}\.pdf$|_op\.pdf$/iu', $title) === 1 => ProductDocument::KIND_MANUAL,
            preg_match('/karta[ _-]produkt|karta[ _-]techniczn|datasheet|data[ _-]sheet|specyfikacj/iu', $title) === 1 => ProductDocument::KIND_DATASHEET,
            default => ProductDocument::KIND_OTHER,
        };
    }

    /**
     * Cała lista; strona pusta przed ostatnią = błąd (niepełna lista nie może udawać całej oferty). Lista zmieniona
     * w trakcie (inna liczba stron, ten sam produkt na dwóch stronach — przesunięcie po dodaniu produktu, więc inny
     * mógł wypaść) — jedno ponowne pobranie od początku, potem błąd.
     *
     * @return list<array{id: int, url: string, name: string, code: string}>
     */
    private function listItems(): array
    {
        try {
            return $this->scanList();
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::INCONSISTENT) {
                throw $e;
            }
            $this->progress('Lista zmieniła się w trakcie pobierania ('.$e->getMessage().') — pobieram od nowa');
        }

        try {
            return $this->scanList();
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::INCONSISTENT) {
                throw $e;
            }

            throw new RuntimeException('Lista produktów '.MactronicB2bClient::HOST.' zmieniała się w trakcie dwóch pobrań ('.$e->getMessage().') — przerwane, spróbuj ponownie', 0, $e);
        }
    }

    /**
     * @return list<array{id: int, url: string, name: string, code: string}>
     */
    private function scanList(): array
    {
        $first = self::parseListPage($this->client->listPage(1));
        $pages = $first['pages'] ?? ($first['items'] !== [] ? 1 : null);
        if ($pages === null || $first['items'] === []) {
            throw new RuntimeException('Lista produktów '.MactronicB2bClient::HOST.' bez produktów — zmiana sklepu?');
        }
        if ($pages > self::MAX_LIST_PAGES) {
            throw new RuntimeException('Lista produktów '.MactronicB2bClient::HOST.' ma '.$pages.' stron — więcej niż '.self::MAX_LIST_PAGES.', przerwane');
        }

        $byId = [];
        $add = static function (array $items, int $page) use (&$byId): void {
            foreach ($items as $item) {
                if (isset($byId[$item['id']])) {
                    throw new RuntimeException('produkt '.$item['id'].' także na stronie '.$page, self::INCONSISTENT);
                }
                $byId[$item['id']] = $item;
            }
        };
        $add($first['items'], 1);
        for ($page = 2; $page <= $pages; $page++) {
            $this->progress('Lista Mactronic: strona '.$page.'/'.$pages);
            $parsed = self::parseListPage($this->client->listPage($page));
            if ($parsed['pages'] !== null && $parsed['pages'] !== $pages) {
                throw new RuntimeException($pages.' → '.$parsed['pages'].' stron', self::INCONSISTENT);
            }
            if ($parsed['items'] === []) {
                throw new RuntimeException('Strona '.$page.'/'.$pages.' listy produktów '.MactronicB2bClient::HOST.' bez produktów — lista niepełna, przerwane');
            }
            $add($parsed['items'], $page);
        }

        return array_values($byId);
    }

    /**
     * @param  array{id: int, url: string, name: string, code: string}  $item
     * @param  array<string, true>  $seenCodes
     */
    private function productFor(array $item, array &$seenCodes): B2bRemoteProduct
    {
        $remoteId = (string) $item['id'];
        try {
            $page = self::parseProductPage($this->client->productPage($item['url']));
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return $this->skipped($remoteId, $item['code'], $item['name'], 'produkt '.$remoteId.': '.$e->getMessage());
        }

        $code = $page['code'] !== '' ? $page['code'] : $item['code'];
        $name = $page['name'] !== '' ? $page['name'] : $item['name'];
        if ($page['id'] !== null && $page['id'] !== $item['id']) {
            return $this->skipped($remoteId, $code, $name, 'adres z listy ('.$item['id'].') otworzył inny produkt ('.$page['id'].')');
        }
        if ($code === '') {
            return $this->skipped($remoteId, $code, $name, 'produkt '.$remoteId.' bez Kodu produktu w sklepie');
        }
        if (isset($seenCodes[$code])) {
            $this->duplicateCodes[] = $code.' (produkt '.$remoteId.')';

            return $this->skipped($remoteId, $code, $name, 'ten sam Kod produktu ma już inny produkt sklepu');
        }
        if ($page['net'] === null || $page['net'] <= 0 || $page['currency'] === null) {
            $this->withoutPrice[] = $code;

            return $this->skipped($remoteId, $code, $name, $page['net'] !== null && $page['currency'] === null ? 'sklep nie podał waluty ceny' : 'produkt bez ceny konta');
        }
        // Kod zajmuje dopiero produkt z ceną — kolejny z tym samym Kodem nie trafi na tę samą kartę
        $seenCodes[$code] = true;
        $this->withPrice++;

        $net = $page['net'];
        $base = $page['base'] !== null && $page['base'] > 0 && $page['base'] >= $net - self::EPSILON ? $page['base'] : null;
        if ($page['base'] === null || $page['base'] <= 0) {
            $this->withoutBase[] = $code;
        } elseif ($base === null) {
            // katalogowa niższa niż cena konta — nie jest ceną przed rabatem, bez przeliczania
            $this->baseBelowNet[] = $code.' ('.number_format($page['base'], 2, ',', '').' < '.number_format($net, 2, ',', '').')';
        }
        $price = new B2bRemotePrice(
            net: $net,
            base: $base,
            discountPercent: $base !== null && $base > 0 ? round((1 - $net / $base) * 100, 2) : 0.0,
            currency: $page['currency'],
            order: new B2bOrderQuantity(null, null, $page['unit'] !== '' ? $page['unit'] : null),
        );

        if ($page['manufacturer'] === '') {
            $this->withoutProducer[] = $code;
        } else {
            $this->producers[$page['manufacturer']] = ($this->producers[$page['manufacturer']] ?? 0) + 1;
        }
        // bez „Opisu produktu” — krótki tekst ze sklepu (akcesoria: „Pasek gumowy do uchwytu do ABR0051”), dosłownie;
        // wiersz powtarzający samą nazwę produktu (L-33202) niczego o wyrobie nie mówi — nie jest opisem
        $description = $page['description'];
        $short = implode("\n", array_filter(
            $page['summary'] !== '' ? explode("\n", $page['summary']) : [],
            static fn (string $line): bool => mb_strtolower($line) !== mb_strtolower($name),
        ));
        if ($description === '' && $short !== '') {
            $description = $short;
            $this->shortDescription[] = $code;
        } elseif ($description === '') {
            $this->withoutDescription[] = $code;
        }
        $this->cards++;

        $identifiers = [new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_SOURCE_CODE, value: $code, field: 'Kod produktu')];
        if ($page['ean'] !== '') {
            $identifiers[] = new B2bRemoteIdentifier(
                type: preg_match('/^(?:\d{8}|\d{12,14})$/', $page['ean']) === 1 ? ProductIdentifier::TYPE_EAN : ProductIdentifier::TYPE_ALT_CODE,
                value: $page['ean'],
                field: 'Kod EAN',
            );
        }

        return new B2bRemoteProduct(
            remoteId: $remoteId,
            sku: $code,
            name: $name,
            category: $page['categories'] !== [] ? implode(' > ', $page['categories']) : null,
            sourceUrl: $item['url'],
            raw: [
                'status' => 'ok',
                'price' => $price,
                'manufacturer' => $page['manufacturer'],
                'code' => $code,
                'ean' => $page['ean'],
                'unit' => $page['unit'],
                'summary' => $page['summary'],
                'notes' => $page['notes'],
                'description' => $description,
                'images' => $page['images'],
                'documents' => $page['documents'],
            ],
            availability: $page['availability'] !== '' ? mb_substr($page['availability'], 0, 1000) : null,
            identifiers: $identifiers,
        );
    }

    /** Produkt, którego nie da się zapisać w tym przebiegu — przebieg widzi go jako pominięty (poza sprzątaniem). */
    private function skipped(string $remoteId, string $code, string $name, string $reason): B2bRemoteProduct
    {
        $sku = $code !== '' ? $code : $remoteId;

        return new B2bRemoteProduct(
            remoteId: $remoteId,
            sku: $sku,
            name: $name !== '' ? $name : $sku,
            raw: ['status' => 'skipped', 'reason' => $reason],
        );
    }

    /**
     * Cena z elementu .price--main: netto („1 028,22 zł” → 1028.22), waluta z tekstu ceny (zł → PLN; brak = null,
     * bez domyślnej) i jednostka („/ szt.” → „szt.”).
     *
     * @return array{0: float|null, 1: string|null, 2: string}
     */
    private static function priceOf(DOMNode $main): array
    {
        $unit = '';
        $amount = '';
        foreach ($main->childNodes as $child) {
            if ($child instanceof DOMElement && str_contains(' '.$child->getAttribute('class').' ', ' price__unit ')) {
                $unit = self::clean(ltrim(self::clean($child->textContent), '/'));

                continue;
            }
            $amount .= $child->textContent;
        }
        $amount = self::clean($amount);
        $currency = null;
        if (mb_stripos($amount, 'zł') !== false) {
            $currency = 'PLN';
        } elseif (preg_match('/(?<![A-Za-z])([A-Z]{3})(?![A-Za-z])/', $amount, $m) === 1) {
            $currency = $m[1];
        }
        $number = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $amount) ?? '';
        $net = preg_match('/^(\d+(?:,\d{1,4})?)/', preg_replace('/^[^\d]+/', '', $number) ?? '', $m) === 1
            ? round((float) str_replace(',', '.', $m[1]), 2)
            : null;

        return [$net, $currency, $unit];
    }

    /** Tekst elementu bez etykiety <label> („Kod produktu:”, „Dostępność:”); brak elementu = ''. */
    private static function labelledValue(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'label') {
                continue;
            }
            $text .= $child->textContent;
        }

        return self::clean($text);
    }

    private static function innerHtml(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }

    /** Pełny adres w sklepie ze ścieżki („/upload/getfile/624”); adres spoza sklepu albo pusty = null. */
    private static function absoluteUrl(string $src): ?string
    {
        $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($src === '' || str_contains($src, '..')) {
            return null;
        }
        $url = str_starts_with($src, '/') && ! str_starts_with($src, '//') ? MactronicB2bClient::BASE.$src : $src;

        return MactronicB2bClient::isShopUrl($url) ? $url : null;
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

    /** Tekst ze sklepu: bez znaków zerowej szerokości, odstępy (także twarde spacje) zwinięte. */
    private static function clean(string $text): string
    {
        $text = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}"], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }
}
