<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use Illuminate\Contracts\Encryption\DecryptException;
use RuntimeException;
use Throwable;

/**
 * Sklep 3M Polska order.3m.com — witryna producenta (B2bManufacturerSite, marka 3M). Sprawdzone na koncie 22.09.2026.
 *
 * Lista: aktywne wyroby kategorii „Środki ochrony indywidualnej” (GPH10008) z wyszukiwarki 3M, po 100 na stronę,
 * w grupach podkategorii (i marek) — duże wyniki wyszukiwarka stronicuje niedeterministycznie (listItems). Cała lista
 * idzie przed pierwszym produktem, deduplikowana po numerze magazynowym (mmm_id), i musi się zgadzać z licznikami.
 *
 * Karta = jeden numer magazynowy 3M (SKU = mmm_id); wyjątek — warianty kolorystyczne jednego wyrobu (decyzja użytkownika
 * 28.09.2026): pozycje o nazwie „{wspólny początek}, {kolor}, {kod}” z kodami różnymi tylko końcówką koloru to jedna
 * karta z tabelą kolorów (colourVariantKey, colourProducts; B2bSizePriceSource — „Scal rozmiary”). Rozmiarów 3M nie
 * łączy (bez B2bGroupsSizes): półmaski 6200 S/M/L to osobne numery i osobne karty, które „Łączenie kart” może
 * zaproponować jako rozmiary. Ceny konta w paczkach po 50 za JEDNOSTKĘ BAZOWĄ wyrobu
 * (materialUnits = baseUomCode) — 3M sam przelicza cenę kartonu na sztukę/parę, my niczego nie dzielimy. Odpowiedź
 * mówi, za co jest cena („pricePer”: „1 szt”); inna jednostka niż bazowa = brak ceny z powodem w podsumowaniu.
 *
 * Opis, parametry, zdjęcia i dokumenty z karty wyrobu wyszukiwarki (pdp) — pobieranej raz na produkt. Opis dosłownie
 * (krótki opis, długi opis, zalety); parametry i numery handlowe do tabelki sklepu.
 *
 * Logowanie kodem z e-maila (B2bCodeLoginSite): przebieg korzysta z sesji zapisanej na koncie, a po udanym login()
 * i na końcu przebiegu zapisuje odnowione ciasteczka z powrotem na koncie.
 */
final class MmmB2bConnector implements B2bCodeLoginSite, B2bConnector, B2bDocumentSource, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldNormSource, B2bShopFieldSource, B2bSizePriceSource
{
    public const BRAND = '3M';

    /** Paczka cen w jednym zapytaniu (sklep przyjmuje 100); paczka z błędem dzielona na pół (chunkPrices). */
    private const PRICE_CHUNK = 50;

    /** Górna granica listy — więcej pozycji niż tyle to błąd licznika, nie oferta ŚOI. */
    private const MAX_TOTAL = 20_000;

    /** Komunikat postępu co tyle grup kategorii. */
    private const PROGRESS_EVERY_PAGES = 10;

    /** Przejścia jednej grupy listy, zanim uznamy ją za niespójną (małe grupy przechodzą w całości za pierwszym razem). */
    private const MAX_GROUP_PASSES = 4;

    private const DOCUMENTS_LIMIT = 8;

    private const IMAGES_LIMIT = 8;

    /** Rodzaje dokumentów pomijane — wielkie katalogi i przewodniki wielu wyrobów. */
    private const SKIPPED_DOCUMENT_TYPES = ['katalog', 'przewodnik'];

    private const DATASHEET_TYPE = 'arkusze danych';

    private const SHOP_SECTION_TRADE = 'Informacje handlowe';

    private const SHOP_SECTION_TECHNICAL = 'Dane techniczne';

    private const SHOP_SECTION_PACKAGING = 'Opakowanie';

    /** Parametr barwy na karcie 3M (classified, „Kolor produktu”) — małymi literami do porównania. */
    private const COLOUR_FIELD = 'kolor produktu';

    /**
     * Człon koloru w nazwie 3M — cały człon to jedna barwa (rdzeń z dowolną polską końcówką: „biały”, „zielone”,
     * „pomarańczowe”, z jasno-/ciemno-) albo „o zwiększonej widzialności” (hełmy G3000, żółto-zielony fluo).
     */
    private const COLOUR_SEGMENT = '/^(?:(?:jasno|ciemno)-?)?(?:czerwon|niebiesk|zielon|żółt|biał|pomarańczow|czarn|szar|granatow|fioletow|różow|srebrn|brązow|beżow|limonkow|oliwkow|grafitow)\p{L}*$|^o zwiększonej widzialności$/iu';

    private int $total = 0;

    /** Karty z wariantami kolorystycznymi w przebiegu i liczba ich pozycji (runSummary). */
    private int $colourCards = 0;

    private int $colourMembers = 0;

    /** @var list<string> */
    private array $summary = [];

    /** @var array<string, list<string>> powód braku ceny → numery magazynowe */
    private array $withoutPrice = [];

    /** @var list<string> */
    private array $withoutBase = [];

    /** @var array<string, true> */
    private array $withoutDescription = [];

    private ?string $pdpId = null;

    /** @var array<string, mixed> */
    private array $pdp = [];

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public function __construct(
        private readonly MmmB2bClient $client,
        private readonly ?B2bAccount $account = null,
    ) {}

    public static function key(): string
    {
        return '3m';
    }

    public static function label(): string
    {
        return self::BRAND;
    }

    public static function host(): string
    {
        return MmmB2bClient::HOST;
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    /** Dane techniczne / Spełnione specyfikacje = „EN 166:2001, EN 14594 3B, EN 12941:1998 TH3, Oznaczenie CE” (23.09.2026: 380 kart). */
    public static function normShopFieldNames(): array
    {
        return ['Spełnione specyfikacje'];
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        try {
            $session = $account->connector_session;
        } catch (DecryptException) {
            // sesja zaszyfrowana innym kluczem — jak brak sesji (przebieg poprosi o logowanie kodem)
            $session = null;
        }

        return new self(new MmmB2bClient(
            (string) $account->username,
            (string) $account->password,
            is_array($session) ? $session : [],
            $delayMs,
        ), $account);
    }

    public function onListProgress(callable $callback): void
    {
        $this->listProgress = $callback;
    }

    public function login(): void
    {
        $this->client->login();
        $error = $this->saveSession();
        if ($error !== null) {
            $this->summary[] = $error;
        }
    }

    public function startCodeLogin(): array
    {
        return $this->client->startCodeLogin();
    }

    public function finishCodeLogin(array $state, string $code): array
    {
        return $this->client->finishCodeLogin($state, $code);
    }

    public function products(): iterable
    {
        $this->summary = array_values(array_filter($this->summary, static fn (string $line): bool => str_starts_with($line, 'Sesja 3M')));
        $this->withoutPrice = [];
        $this->withoutBase = [];
        $this->withoutDescription = [];
        $this->pdpId = null;
        $this->pdp = [];
        $this->colourCards = 0;
        $this->colourMembers = 0;

        $items = $this->listItems();
        $colours = self::colourGroups($items);
        // na start zakładamy, że każda grupa kolorów da jedną kartę; po cenach — liczba faktycznie wydanych produktów
        $this->total = count($items) - count($colours) + count(array_unique(array_column($colours, 'key')));

        /** @var array<string, list<array{item: array<string, mixed>, price: array<string, mixed>, colour: array{key: string, colour: string, code: string, prefix: string, stem: string}}>> $stash */
        $stash = [];
        foreach (array_chunk($items, self::PRICE_CHUNK) as $chunk) {
            [$prices, $errors] = $this->chunkPrices($chunk);
            foreach ($chunk as $item) {
                $id = (string) $item['id'];
                $price = $this->priceFor($item, $prices, $errors[$id] ?? null);
                if (isset($colours[$id])) {
                    // grupa kolorów rozstrzyga się dopiero, gdy są ceny wszystkich jej pozycji (paczki cen idą po liście)
                    $stash[$colours[$id]['key']][] = ['item' => $item, 'price' => $price, 'colour' => $colours[$id]];

                    continue;
                }
                yield $this->productFor($item, $price);
            }
        }

        $grouped = [];
        foreach ($stash as $group) {
            array_push($grouped, ...$this->colourProducts($group));
        }
        $this->total = count($items) - count($colours) + count($grouped);
        foreach ($grouped as $product) {
            yield $product;
        }
    }

    /**
     * Wariant kolorystyczny z nazwy pozycji listy 3M: „{początek}, {kolor}, {kod}” — przedostatni człon to sama barwa
     * (COLOUR_SEGMENT), ostatni to kod bez spacji z cyfrą, zakończony członem koloru „-XX” (1–3 wielkie litery/cyfry:
     * G3000CUV-VI, G3000NUV-10-BB, 210100-478-GN). Klucz = początek nazwy (bez wielkości liter) i kod bez końcówki —
     * inny początek („ze skóry”, „Wymienne czasze …”) albo inny rdzeń kodu (G3001MUV100V / G3001MUV1000V) to inny wyrób.
     * Kolor i kod dosłownie z nazwy; null = nazwa nie ma takiego układu (pozycja zostaje osobną kartą).
     *
     * @return array{key: string, colour: string, code: string, prefix: string, stem: string}|null
     */
    public static function colourVariantKey(string $name): ?array
    {
        if (preg_match('/^(.+),\s*([^,]+),\s*([^,\s]*\d[^,\s]*)$/u', self::clean($name), $m) !== 1) {
            return null;
        }
        $prefix = trim($m[1]);
        $colour = trim($m[2]);
        $code = $m[3];
        if ($prefix === '' || preg_match(self::COLOUR_SEGMENT, $colour) !== 1 || preg_match('/^(.+)-[A-Z0-9]{1,3}$/', $code, $c) !== 1) {
            return null;
        }

        return ['key' => mb_strtolower($prefix).'|'.$c[1], 'colour' => $colour, 'code' => $code, 'prefix' => $prefix, 'stem' => $c[1]];
    }

    /**
     * Pozycje listy w grupach kolorów (co najmniej dwie pozycje o tym samym kluczu) wg numeru magazynowego.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, array{key: string, colour: string, code: string, prefix: string, stem: string}>
     */
    private static function colourGroups(array $items): array
    {
        $parsed = [];
        $counts = [];
        foreach ($items as $item) {
            $colour = self::colourVariantKey((string) $item['name']);
            if ($colour !== null) {
                $parsed[(string) $item['id']] = $colour;
                $counts[$colour['key']] = ($counts[$colour['key']] ?? 0) + 1;
            }
        }

        return array_filter($parsed, static fn (array $colour): bool => $counts[$colour['key']] >= 2);
    }

    /**
     * Grupa kolorów po cenach: pozycje z ceną konta (co najmniej dwie, każdy kolor raz — bez względu na wielkość
     * liter) to jedna karta z tabelą kolorów; pozycje bez ceny albo z błędem ceny zostają osobnymi kartami jak dotąd.
     * Powtórzony kolor wśród pozycji z ceną = nie wiemy, który wiersz jest którym wyrobem — cała grupa osobno.
     *
     * Karta: pozycja najtańsza (remis — niższy numer magazynowy) daje remoteId, SKU, nazwę ze źródła, kartę pdp (opis,
     * tabelka, zdjęcia, dokumenty) i cenę karty (price()); nazwa nowej karty bez koloru („{początek}, {kod bez
     * końcówki}”). Pozycje w kolejności kolorów, każda ze swoją ceną konta, kodem z nazwy i numerami 3M.
     *
     * @param  list<array{item: array<string, mixed>, price: array<string, mixed>, colour: array{key: string, colour: string, code: string, prefix: string, stem: string}}>  $group
     * @return list<B2bRemoteProduct>
     */
    private function colourProducts(array $group): array
    {
        $priced = array_values(array_filter($group, static fn (array $m): bool => ($m['price']['status'] ?? null) === 'ok'));
        $labels = array_map(static fn (array $m): string => mb_strtolower($m['colour']['colour']), $priced);
        if (count($priced) < 2 || count(array_unique($labels)) !== count($labels)) {
            return array_map(fn (array $m): B2bRemoteProduct => $this->productFor($m['item'], $m['price']), $group);
        }

        usort($priced, static fn (array $a, array $b): int => [mb_strtolower($a['colour']['colour']), (string) $a['item']['id']]
            <=> [mb_strtolower($b['colour']['colour']), (string) $b['item']['id']]);
        $lead = $priced[0];
        foreach ($priced as $member) {
            // w groszach, jak cena pozycji (remotePrice) — remis po zaokrągleniu rozstrzyga numer magazynowy
            $cheaper = round((float) $member['price']['net'], 2) <=> round((float) $lead['price']['net'], 2);
            if ($cheaper < 0 || ($cheaper === 0 && strcmp((string) $member['item']['id'], (string) $lead['item']['id']) < 0)) {
                $lead = $member;
            }
        }

        $members = [];
        $identifiers = [];
        foreach ($priced as $member) {
            $item = $member['item'];
            $members[] = [
                'remote_id' => (string) $item['id'],
                'sku' => $member['colour']['code'],
                'name' => (string) $item['name'],
                'size' => $member['colour']['colour'],
                'price' => self::remotePrice($member['price'], $item),
            ];
            array_push($identifiers, ...self::identifiers($item, $member['colour']['colour']));
        }
        $this->colourCards++;
        $this->colourMembers += count($priced);

        $leadId = (string) $lead['item']['id'];
        $products = [new B2bRemoteProduct(
            remoteId: $leadId,
            sku: $leadId,
            name: (string) $lead['item']['name'],
            category: null,
            sourceUrl: MmmB2bClient::productUrl($leadId),
            raw: ['item' => $lead['item'], 'price' => $lead['price']],
            variantSummary: 'Kolory: '.implode('; ', array_map(
                static fn (array $m): string => $m['colour']['colour'].' ('.$m['colour']['code'].')',
                $priced,
            )),
            members: $members,
            identifiers: $identifiers,
            cardName: $lead['colour']['prefix'].', '.$lead['colour']['stem'],
        )];
        foreach ($group as $member) {
            if (($member['price']['status'] ?? null) !== 'ok') {
                $products[] = $this->productFor($member['item'], $member['price']);
            }
        }

        return $products;
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    public function runSummary(): array
    {
        $lines = $this->summary;
        foreach ($this->withoutPrice as $reason => $ids) {
            $lines[] = 'Bez ceny konta — '.$reason.': '.self::listing($ids).' (pominięte)';
        }
        if ($this->withoutBase !== []) {
            $lines[] = 'Bez ceny katalogowej (cena katalogowa = cena konta): '.self::listing($this->withoutBase);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Karta 3M bez opisu (brak opisu, długiego opisu i zalet): '.self::listing(array_keys($this->withoutDescription));
        }
        if ($this->colourCards > 0) {
            $lines[] = 'Warianty kolorystyczne 3M: '.$this->colourCards.' wyrobów z '.$this->colourMembers.' pozycji';
        }
        if ($this->account !== null && $this->client->isLoggedIn()) {
            $lines[] = $this->saveSession() ?? 'Sesja 3M zapisana na koncie';
        }

        return $lines;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return self::BRAND;
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        $price = $product->raw['price'] ?? null;
        if (! is_array($price)) {
            return null;
        }
        if (($price['status'] ?? null) === 'error') {
            throw new RuntimeException((string) $price['reason']);
        }
        if (($price['status'] ?? null) !== 'ok') {
            return null;
        }

        return self::remotePrice($price, is_array($product->raw['item'] ?? null) ? $product->raw['item'] : []);
    }

    /**
     * Cena konta pozycji (status „ok” z priceOf) z warunkiem zamawiania jej jednostki bazowej — dla karty (price())
     * i każdej pozycji karty z kolorami (members[].price).
     *
     * @param  array<string, mixed>  $price
     * @param  array<string, mixed>  $item
     */
    private static function remotePrice(array $price, array $item): B2bRemotePrice
    {
        $net = (float) $price['net'];
        $base = is_float($price['base'] ?? null) ? $price['base'] : null;

        return new B2bRemotePrice(
            net: round($net, 2),
            base: $base !== null ? round($base, 2) : null,
            discountPercent: $base !== null && $base > 0 ? round((1 - $net / $base) * 100, 2) : 0.0,
            order: self::orderQuantity($item),
        );
    }

    /**
     * Warunek zamawiania w jednostce ceny (bazowej): moq i moi wiersza orderUnits tej jednostki — minimum i krok,
     * które 3M liczy już w sztukach bazowych (sprawdzone na koncie 24.09.2026: 7100265270 szt moq 20 moi 20, karton
     * 20 szt moq 1; 7100001829 szt moq 40 = 1 karton). „Minimalne zamówienie” z odpowiedzi ceny jest w jednostce
     * sprzedaży („1 karton”), więc do warunku go nie bierzemy. Brak wiersza jednostki bazowej = null.
     *
     * @param  array<string, mixed>  $item
     */
    private static function orderQuantity(array $item): ?B2bOrderQuantity
    {
        $base = (string) ($item['base_unit'] ?? '');
        if ($base === '') {
            return null;
        }
        foreach (is_array($item['order_units'] ?? null) ? $item['order_units'] : [] as $unit) {
            if (! is_array($unit) || ($unit['code'] ?? '') !== $base) {
                continue;
            }
            $min = B2bOrderQuantity::attribute((string) ($unit['moq'] ?? ''));
            if ($min === null) {
                return null;
            }
            $name = trim((string) ($unit['name'] ?? ''));

            return new B2bOrderQuantity($min, B2bOrderQuantity::attribute((string) ($unit['moi'] ?? '')), $name !== '' ? $name : null);
        }

        return null;
    }

    /**
     * Dosłownie z karty 3M: krótki opis, długi opis (akapity rozdzielone pustą linią), zalety jako „- …”.
     * Pusty, gdy karta nie ma żadnego z nich (częste przy częściach zamiennych).
     */
    public function description(B2bRemoteProduct $product): string
    {
        $pdp = $this->pdpOf($product);
        $parts = [];

        $short = implode("\n", self::lines($pdp['description'] ?? null));
        if ($short !== '') {
            $parts[] = $short;
        }
        $long = implode("\n\n", self::lines($pdp['long_description'] ?? null));
        if ($long !== '' && $long !== $short) {
            $parts[] = $long;
        }
        $benefits = [];
        foreach (self::listOf($pdp['benefits'] ?? null) as $benefit) {
            $text = implode(' ', self::lines($benefit));
            if ($text !== '') {
                $benefits[] = '- '.$text;
            }
        }
        if ($benefits !== []) {
            $parts[] = implode("\n", $benefits);
        }

        $description = mb_substr(implode("\n\n", $parts), 0, 20000);
        if ($description === '') {
            $this->withoutDescription[$product->remoteId] = true;
        }

        return $description;
    }

    /**
     * Numery handlowe, marka linii, jednostki i minimalne zamówienie; parametry (classified) i dane opakowania
     * dosłownie z karty 3M.
     *
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        $item = $product->raw['item'] ?? [];
        $price = is_array($product->raw['price'] ?? null) ? $product->raw['price'] : [];
        $pdp = $this->pdpOf($product);

        $fields = [];
        $add = static function (string $section, string $name, string $value) use (&$fields): void {
            $name = self::clean($name);
            $value = self::clean($value);
            if ($name !== '' && $value !== '') {
                $fields[] = new B2bRemoteShopField($section, $name, $value);
            }
        };

        foreach (self::listOf($pdp['common'] ?? null) as $row) {
            if (is_array($row) && mb_strtolower(self::value($row['label'] ?? null)) === 'marka') {
                $add(self::SHOP_SECTION_TRADE, 'Marka', self::value($row['value'] ?? null));
            }
        }
        // karta kolorów (members): numery, EAN i kolor ze strony wiodącej pozycji opisują tylko jeden kolor — każdy
        // kolor ma swoje numery w wierszach wariantów i identyfikatorach, więc w tabelce ich nie powtarzamy
        $grouped = count($product->members) > 1;
        if (! $grouped) {
            $add(self::SHOP_SECTION_TRADE, 'Numer katalogowy 3M', (string) ($item['catalog'] ?? ''));
            $add(self::SHOP_SECTION_TRADE, 'Numer magazynowy 3M', (string) ($item['id'] ?? ''));
            $add(self::SHOP_SECTION_TRADE, 'EAN', (string) ($item['gtin'] ?? ''));
            $add(self::SHOP_SECTION_TRADE, 'Poprzedni numer 3M', (string) ($item['legacy'] ?? ''));
        }
        $add(self::SHOP_SECTION_TRADE, 'Cena za', (string) ($price['price_per'] ?? ''));
        $add(self::SHOP_SECTION_TRADE, 'Minimalne zamówienie', (string) ($price['min_order'] ?? ''));
        $add(self::SHOP_SECTION_TRADE, 'Jednostka sprzedaży', self::salesUnitText($item));

        foreach (self::listOf($pdp['classified'] ?? null) as $row) {
            if (is_array($row) && ! ($grouped && mb_strtolower(self::value($row['label'] ?? null)) === self::COLOUR_FIELD)) {
                $add(self::SHOP_SECTION_TECHNICAL, self::value($row['label'] ?? null), self::classifiedValue($row['value'] ?? null));
            }
        }
        // kody kreskowe opakowań też są kodami jednej pozycji
        foreach ($grouped ? [] : self::listOf($pdp['packagingIdentificationDetails'] ?? null) as $row) {
            if (is_array($row)) {
                $add(self::SHOP_SECTION_PACKAGING, self::value($row['label'] ?? null), self::value($row['value'] ?? null));
            }
        }

        return $fields;
    }

    /**
     * PDF-y z karty 3M (adres zdjęcia podglądu „…{dmr}J/nazwa.jpg” → plik „…{dmr}O/nazwa.pdf”); bez katalogów
     * i przewodników wielu wyrobów.
     *
     * @return list<B2bRemoteDocument>
     */
    public function documents(B2bRemoteProduct $product): array
    {
        $documents = [];
        foreach (self::listOf($this->pdpOf($product)['media_links_documents'] ?? null) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = mb_strtolower(self::value($row['content_type'] ?? null));
            $mime = mb_strtolower(self::value($row['mime_type'] ?? null));
            $title = self::value($row['title'] ?? null);
            // katalogi wielu wyrobów bywają opisane innym typem („3M-Fall-Protection-Product-Catalogue-EMEA-EN”)
            if ($mime !== 'application/pdf' || self::startsWithAny($type, self::SKIPPED_DOCUMENT_TYPES)
                || preg_match('/catalog|katalog/i', $title) === 1) {
                continue;
            }
            $url = self::pdfUrl(self::value($row['url'] ?? null));
            if ($url === null || isset($documents[$url])) {
                continue;
            }
            $documents[$url] = new B2bRemoteDocument(
                mb_substr($title !== '' ? $title : rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))), 0, 255),
                $url,
                $type === self::DATASHEET_TYPE ? ProductDocument::KIND_DATASHEET : ProductDocument::KIND_OTHER,
            );
            if (count($documents) >= self::DOCUMENTS_LIMIT) {
                break;
            }
        }

        return array_values($documents);
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    public function documentBytes(B2bRemoteDocument $document): array
    {
        $file = $this->client->fileBytes($document->sourceUrl);
        if (! str_starts_with($file['bytes'], '%PDF-')) {
            throw new RuntimeException('plik 3M nie jest PDF-em ('.$file['mime'].'): '.$document->sourceUrl);
        }
        $file['mime'] = 'application/pdf';

        return $file;
    }

    /**
     * Zdjęcia z karty 3M w dużym wariancie (Z z url_pattern), główne pierwsze; bez karty — zdjęcie z listy.
     *
     * @return list<string>
     */
    public function imageUrls(B2bRemoteProduct $product): array
    {
        $rows = array_values(array_filter(self::listOf($this->pdpOf($product)['media_links_images'] ?? null), 'is_array'));
        usort($rows, static fn (array $a, array $b): int => (int) self::isTrue($b['is_main_image'] ?? null) <=> (int) self::isTrue($a['is_main_image'] ?? null));

        $urls = [];
        foreach ($rows as $row) {
            // galeria 3M miesza zdjęcia z filmami (MP4 701 MB, przebieg 22.09.2026) — filmów nie pobieramy wcale
            if (! self::isImageRow($row)) {
                continue;
            }
            $pattern = html_entity_decode(self::value($row['url_pattern'] ?? null), ENT_QUOTES | ENT_HTML5);
            $url = str_contains($pattern, '<R>') ? str_replace('<R>', 'Z', $pattern) : self::value($row['url'] ?? null);
            if ($url !== '' && MmmB2bClient::isFileUrl($url) && self::hasImageExtension($url)) {
                $urls[$url] = true;
            }
            if (count($urls) >= self::IMAGES_LIMIT) {
                break;
            }
        }
        if ($urls === []) {
            $listed = (string) ($product->raw['item']['image'] ?? '');
            if ($listed !== '' && MmmB2bClient::isFileUrl($listed)) {
                $urls[$listed] = true;
            }
        }

        return array_keys($urls);
    }

    public function imageAt(string $url): ?B2bRemoteImage
    {
        $file = $this->client->fileBytes($url);
        if ($file['bytes'] === '') {
            return null;
        }
        $mime = $file['mime'];
        if (! str_starts_with($mime, 'image/')) {
            $info = @getimagesizefromstring($file['bytes']);
            $mime = is_array($info) ? (string) $info['mime'] : '';
        }

        return str_starts_with($mime, 'image/') ? new B2bRemoteImage(bytes: $file['bytes'], mime: $mime, sourceUrl: $url) : null;
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $urls = $this->imageUrls($product);

        return $urls === [] ? null : $this->imageAt($urls[0]);
    }

    /**
     * Kwota w zapisie sklepu 3M: „3 141,12 PLN / szt”, „0,4196PLN” (spacja tysięcy zwykła albo niełamliwa).
     * Inny zapis (bez PLN, kropka dziesiętna) = null — nie zgadujemy.
     *
     * @return array{amount: float, unit: string|null}|null
     */
    public static function parsePrice(string $text): ?array
    {
        $pattern = '/^(\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+|\d+)(?:,(\d+))?[\s\x{00A0}\x{202F}]*PLN[\s\x{00A0}\x{202F}]*(?:\/[\s\x{00A0}\x{202F}]*(.+))?$/u';
        if (preg_match($pattern, self::clean($text), $m) !== 1) {
            return null;
        }
        $amount = (float) (preg_replace('/\D/u', '', $m[1]).'.'.(($m[2] ?? '') !== '' ? $m[2] : '0'));
        $unit = isset($m[3]) ? self::clean($m[3]) : '';

        return ['amount' => $amount, 'unit' => $unit !== '' ? $unit : null];
    }

    /**
     * Cała lista ŚOI złożona z grup po najwyżej kilkaset pozycji. Wyszukiwarka 3M stronicuje duże wyniki
     * niedeterministycznie (sprawdzone na żywo 22.09.2026: ta sama strona 2955 pozycji zapytana dwa razy potrafiła
     * mieć 0 wspólnych pozycji, a pełne przejście dawało ~2300 różnych z 2955; parametry sortowania i queryId nic
     * nie zmieniają), a małe wyniki (331 pozycji PELTOR) przechodzi w całości. Dlatego: drzewo kategorii do liści,
     * liść ponad 100 pozycji dzielony po marce; każda grupa musi dać tyle różnych pozycji, ile mówi jej licznik
     * (inaczej kolejne przejście, suma przejść), a suma grup — licznik całej kategorii.
     *
     * @return list<array<string, mixed>>
     */
    private function listItems(): array
    {
        $root = $this->searchPage(null, null, 0, 1);
        $total = $root['total'];
        if ($total <= 0 || $total > self::MAX_TOTAL) {
            throw new RuntimeException('Lista wyrobów '.MmmB2bClient::HOST.' ma nieoczekiwany licznik: '.$total);
        }
        $groups = $this->listGroups([MmmB2bClient::CATEGORY], $root);
        $grouped = array_sum(array_column($groups, 'total'));
        if ($grouped !== $total) {
            throw new RuntimeException('Lista wyrobów '.MmmB2bClient::HOST.': grupy kategorii dają '.$grouped.' pozycji przy liczniku '.$total);
        }
        $this->progress('Lista wyrobów 3M: '.$total.' pozycji w '.count($groups).' grupach kategorii');

        $items = [];
        $withoutId = 0;
        $repeated = 0;
        $done = 0;
        foreach ($groups as $i => $group) {
            $scan = $this->scanGroup($group);
            $withoutId += $scan['without_id'];
            $repeated += $scan['passes'] > 1 ? 1 : 0;
            $items += $scan['items'];
            $done += $group['total'];
            if (($i + 1) % self::PROGRESS_EVERY_PAGES === 0) {
                $this->progress('Lista wyrobów 3M: '.$done.'/'.$total);
            }
        }
        if (count($items) + $withoutId !== $total) {
            throw new RuntimeException('Lista wyrobów '.MmmB2bClient::HOST.' niespójna: zebrano '.count($items).' różnych pozycji'
                .($withoutId > 0 ? ' i '.$withoutId.' bez numeru' : '').' przy liczniku '.$total.' (pozycje w kilku grupach naraz?)');
        }

        $line = 'Lista 3M (ŚOI, aktywne): '.count($items).' wyrobów z '.count($groups).' grup kategorii';
        if ($repeated > 0) {
            $line .= ', grup pobranych więcej niż raz (zmienna kolejność stron): '.$repeated;
        }
        if ($withoutId > 0) {
            $line .= ', bez numeru magazynowego (pominięte): '.$withoutId;
        }
        $this->summary[] = $line;

        return array_values($items);
    }

    /**
     * Grupy do pobrania: gałąź do 100 pozycji albo bez podkategorii to jedna grupa; większa gałąź — jej podkategorie
     * (suma ich liczników musi dać licznik gałęzi); większy liść — marki, gdy ich suma daje licznik, inaczej cały liść.
     *
     * @param  list<string>  $path
     * @param  array{total: int, categories: array<string, int>, brands: array<string, int>}  $node
     * @return list<array{path: list<string>, brand: ?string, total: int}>
     */
    private function listGroups(array $path, array $node): array
    {
        if ($node['total'] === 0) {
            return [];
        }
        $children = array_diff_key($node['categories'], array_flip($path));
        if ($node['total'] <= MmmB2bClient::LIST_PAGE_SIZE || ($children === [] && $node['brands'] === [])) {
            return [['path' => $path, 'brand' => null, 'total' => $node['total']]];
        }
        if ($children !== []) {
            if (array_sum($children) !== $node['total']) {
                throw new RuntimeException('Lista wyrobów '.MmmB2bClient::HOST.': podkategorie '.implode('/', $path)
                    .' dają '.array_sum($children).' pozycji przy liczniku '.$node['total']);
            }
            $groups = [];
            foreach (array_keys($children) as $child) {
                $childPath = [...$path, (string) $child];
                $groups = [...$groups, ...$this->listGroups($childPath, $this->searchPage($childPath, null, 0, 1))];
            }

            return $groups;
        }
        if (array_sum($node['brands']) !== $node['total']) {
            return [['path' => $path, 'brand' => null, 'total' => $node['total']]];
        }
        $groups = [];
        foreach ($node['brands'] as $brand => $count) {
            $groups[] = ['path' => $path, 'brand' => (string) $brand, 'total' => $count];
        }

        return $groups;
    }

    /**
     * Wszystkie pozycje grupy: przejście stron aż do licznika; brakujące pozycje = kolejne przejście, a pozycje
     * z kolejnych przejść się sumują (wyszukiwarka zmienia kolejność). Po MAX_GROUP_PASSES przejściach bez kompletu
     * — błąd zamiast cennika bez części wyrobów.
     *
     * @param  array{path: list<string>, brand: ?string, total: int}  $group
     * @return array{items: array<string, array<string, mixed>>, without_id: int, passes: int}
     */
    private function scanGroup(array $group): array
    {
        $items = [];
        $withoutId = 0;
        for ($pass = 1; $pass <= self::MAX_GROUP_PASSES; $pass++) {
            $passWithoutId = 0;
            for ($start = 0; $start < $group['total']; $start += MmmB2bClient::LIST_PAGE_SIZE) {
                $page = $this->searchPage($group['path'], $group['brand'], $start, MmmB2bClient::LIST_PAGE_SIZE);
                if ($page['total'] !== $group['total']) {
                    throw new RuntimeException('Lista wyrobów '.MmmB2bClient::HOST.': licznik grupy '.self::groupName($group)
                        .' zmienił się w trakcie pobierania ('.$group['total'].' → '.$page['total'].')');
                }
                foreach ($page['rows'] as $row) {
                    $item = is_array($row) ? self::listItem($row) : null;
                    if ($item === null) {
                        $passWithoutId++;

                        continue;
                    }
                    $items[$item['id']] ??= $item;
                }
                if ($page['rows'] === []) {
                    break;
                }
            }
            // pozycji bez numeru nie da się odróżnić między przejściami — liczy się największa liczba z jednego
            $withoutId = max($withoutId, $passWithoutId);
            if (count($items) + $withoutId >= $group['total']) {
                return ['items' => $items, 'without_id' => $withoutId, 'passes' => $pass];
            }
        }

        throw new RuntimeException('Lista wyrobów '.MmmB2bClient::HOST.' niespójna: grupa '.self::groupName($group).' po '
            .self::MAX_GROUP_PASSES.' przejściach ma '.count($items).' różnych pozycji przy liczniku '.$group['total']);
    }

    /**
     * Strona wyszukiwarki z odczytanym licznikiem, pozycjami i podziałem na podkategorie i marki.
     *
     * @param  list<string>|null  $path
     * @return array{total: int, rows: list<mixed>, categories: array<string, int>, brands: array<string, int>}
     */
    private function searchPage(?array $path, ?string $brand, int $start, int $size): array
    {
        $json = $this->client->search($start, $size, $path, $brand);
        $rows = $json['items'] ?? null;
        $total = $json['total'] ?? null;
        if (! is_array($rows) || ! is_numeric($total)) {
            throw new RuntimeException('Lista wyrobów '.MmmB2bClient::HOST.': nieczytelna odpowiedź wyszukiwarki ('
                .implode('/', $path ?? [MmmB2bClient::CATEGORY]).($brand !== null ? ', '.$brand : '').', od '.$start.')');
        }
        $sticky = is_array($json['aggregations']['sticky'] ?? null) ? $json['aggregations']['sticky'] : [];
        $facets = static function (mixed $list, string $key): array {
            $out = [];
            foreach (is_array($list) ? $list : [] as $facet) {
                if (is_array($facet) && is_scalar($facet[$key] ?? null) && is_numeric($facet['count'] ?? null) && (string) $facet[$key] !== '') {
                    $out[(string) $facet[$key]] = (int) $facet['count'];
                }
            }

            return $out;
        };

        return [
            'total' => (int) $total,
            'rows' => array_values($rows),
            'categories' => $facets($sticky['categories']['facets'] ?? null, 'id'),
            'brands' => $facets($sticky['brand']['facets'] ?? null, 'value'),
        ];
    }

    /**
     * @param  array{path: list<string>, brand: ?string, total: int}  $group
     */
    private static function groupName(array $group): string
    {
        return implode('/', $group['path']).($group['brand'] !== null ? ' ('.$group['brand'].')' : '');
    }

    /**
     * Pozycja listy wyszukiwarki → pola używane przez łącznik; null = bez numeru magazynowego.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private static function listItem(array $row): ?array
    {
        $id = self::value($row['mmm_id'] ?? null);
        if ($id === '') {
            return null;
        }
        $units = [];
        foreach (self::listOf($row['orderUnits'] ?? null) as $unit) {
            if (! is_array($unit)) {
                continue;
            }
            $units[] = [
                'code' => self::value($unit['orderUnitCode'] ?? null),
                'name' => self::value($unit['orderUnitName'] ?? null),
                'default' => self::isTrue($unit['defaultOrderUnit'] ?? null),
                'conversion' => self::value(is_array($unit['uomData'] ?? null) ? ($unit['uomData']['conversion'] ?? null) : null),
                // minimum i krok zamówienia w tej jednostce (moq/moi, np. „20.0”) — warunek zamawiania (orderQuantity())
                'moq' => self::value($unit['moq'] ?? null),
                'moi' => self::value($unit['moi'] ?? null),
            ];
        }
        $image = $row['main_image'] ?? null;

        return [
            'id' => $id,
            'catalog' => self::value($row['mmm_catalog_number'] ?? null),
            'name' => self::value($row['fml_mkpl_name'] ?? null),
            'gtin' => self::value($row['gtin_display'] ?? null),
            'legacy' => self::value($row['legacy_mmm_id'] ?? null),
            'image' => is_array($image) ? self::value($image['url'] ?? null) : '',
            'base_unit' => self::value($row['baseUomCode'] ?? null),
            'sales_unit' => self::value($row['salesUnit'] ?? null),
            'order_units' => $units,
        ];
    }

    /**
     * Ceny jednej paczki (za jednostkę bazową). Błąd pobrania (poza krytycznym) → paczka dzielona na pół i pobierana
     * ponownie, aż do pojedynczej pozycji — powód braku ceny dostaje tylko pozycja, której ceny sklep nie wydał,
     * a nie cała paczka.
     *
     * @param  list<array<string, mixed>>  $chunk
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, string>} ceny i powody błędów wg numeru
     */
    private function chunkPrices(array $chunk): array
    {
        $ids = [];
        $units = [];
        foreach ($chunk as $item) {
            if ($item['base_unit'] !== '') {
                $ids[] = (string) $item['id'];
                $units[] = (string) $item['base_unit'];
            }
        }
        try {
            return [$this->client->prices($ids, $units), []];
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            if (count($chunk) > 1) {
                $half = (int) ceil(count($chunk) / 2);
                [$firstPrices, $firstErrors] = $this->chunkPrices(array_slice($chunk, 0, $half));
                [$secondPrices, $secondErrors] = $this->chunkPrices(array_slice($chunk, $half));

                return [$firstPrices + $secondPrices, $firstErrors + $secondErrors];
            }
            // adres zapytania (z listą numerów) nie mówi nic nowego, a zalewa dziennik
            $reason = 'ceny 3M nie zostały pobrane ('.trim((string) preg_replace('~\s*\(see https?://\S+\)|\s*for https?://\S+~', '', $e->getMessage())).')';

            return [[], [(string) $chunk[0]['id'] => $reason]];
        }
    }

    /**
     * Cena pozycji do raw['price']: błąd paczki cen albo odczyt odpowiedzi (priceOf — raz na pozycję, bo zapisuje
     * powody braku ceny do podsumowania).
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, mixed>>  $prices
     * @return array<string, mixed>
     */
    private function priceFor(array $item, array $prices, ?string $error): array
    {
        return $error !== null ? ['status' => 'error', 'reason' => $error] : $this->priceOf($item, $prices[(string) $item['id']] ?? null);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $price  priceFor
     */
    private function productFor(array $item, array $price): B2bRemoteProduct
    {
        $id = (string) $item['id'];
        $name = (string) $item['name'];

        return new B2bRemoteProduct(
            remoteId: $id,
            sku: $id,
            name: $name !== '' ? $name : ($item['catalog'] !== '' ? '3M '.$item['catalog'] : '3M '.$id),
            category: null,
            sourceUrl: MmmB2bClient::productUrl($id),
            raw: [
                'item' => $item,
                'price' => $price,
            ],
            identifiers: self::identifiers($item),
        );
    }

    /**
     * Numery 3M z pozycji listy wyszukiwarki, dosłownie (GTIN-14 z zerem na początku tak, jak podaje 3M); pole = klucz
     * wyszukiwarki. Karta = jedna pozycja (numer magazynowy), a na karcie z kolorami — numery każdej pozycji z jej
     * kolorem jako etykietą. Kody kreskowe opakowań (packagingIdentificationDetails) są tylko na karcie pdp, pobieranej
     * dopiero po wydaniu produktu — tu ich nie ma.
     *
     * @param  array<string, mixed>  $item
     * @return list<B2bRemoteIdentifier>
     */
    private static function identifiers(array $item, ?string $label = null): array
    {
        $id = (string) $item['id'];
        $out = [];
        foreach ([
            [ProductIdentifier::TYPE_MANUFACTURER_CODE, 'catalog', 'mmm_catalog_number'],
            [ProductIdentifier::TYPE_ALT_CODE, 'id', 'mmm_id'],
            [ProductIdentifier::TYPE_LEGACY_CODE, 'legacy', 'legacy_mmm_id'],
            [ProductIdentifier::TYPE_EAN, 'gtin', 'gtin_display'],
        ] as [$type, $key, $field]) {
            $value = (string) ($item[$key] ?? '');
            if ($value !== '') {
                $out[] = new B2bRemoteIdentifier(type: $type, value: $value, remoteId: $id, label: $label, field: $field);
            }
        }

        return $out;
    }

    /**
     * Cena konta za jednostkę bazową: „value” (netto konta) i „listPrice” (katalogowa). Jednostka ceny („pricePer”)
     * musi być „1 {nazwa jednostki bazowej}”; inaczej brak ceny z powodem — nie zgadujemy przelicznika.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $response
     * @return array<string, mixed>
     */
    private function priceOf(array $item, ?array $response): array
    {
        $id = (string) $item['id'];
        $baseName = self::baseUnitName($item);
        if ($item['base_unit'] === '' || $baseName === null) {
            return $this->noPrice($id, 'brak jednostki bazowej na liście');
        }
        $price = is_array($response['price'] ?? null) ? $response['price'] : [];
        $valueText = self::value($price['value'] ?? null);
        if ($valueText === '') {
            return $this->noPrice($id, 'sklep nie podaje ceny');
        }
        $net = self::parsePrice($valueText);
        if ($net === null || $net['amount'] < 0.005) {
            return $this->noPrice($id, 'nieczytelna cena');
        }
        $pricePer = self::value($price['pricePer'] ?? null);
        if (! self::sameUnit($pricePer, '1 '.$baseName) || ($net['unit'] !== null && ! self::sameUnit($net['unit'], $baseName))) {
            return $this->noPrice($id, 'cena za inną jednostkę niż bazowa ('.$baseName.')', $id.' („'.$pricePer.'”)');
        }

        $base = null;
        $list = self::parsePrice(self::value($price['listPrice'] ?? null));
        if ($list !== null && ($list['unit'] === null || self::sameUnit($list['unit'], $baseName)) && $list['amount'] >= $net['amount']) {
            $base = $list['amount'];
        } else {
            $this->withoutBase[] = $id;
        }

        $min = is_array($response['minOrderQuantity'] ?? null) ? $response['minOrderQuantity'] : [];

        return [
            'status' => 'ok',
            'net' => $net['amount'],
            'base' => $base,
            'price_per' => $pricePer,
            'min_order' => self::clean(self::value($min['value'] ?? null).' '.self::value($min['unit'] ?? null)),
        ];
    }

    /**
     * @return array{status: string, reason: string}
     */
    private function noPrice(string $id, string $reason, ?string $entry = null): array
    {
        $this->withoutPrice[$reason][] = $entry ?? $id;

        return ['status' => 'none', 'reason' => $reason];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function baseUnitName(array $item): ?string
    {
        foreach ($item['order_units'] as $unit) {
            if ($unit['code'] === $item['base_unit'] && $unit['name'] !== '') {
                return $unit['name'];
            }
        }

        return null;
    }

    /**
     * Domyślna jednostka zamówienia z przelicznikiem na jednostkę bazową: „karton = 64 szt”; sama bazowa: „szt”.
     *
     * @param  array<string, mixed>  $item
     */
    private static function salesUnitText(array $item): string
    {
        if (! is_array($item['order_units'] ?? null)) {
            return '';
        }
        $baseName = self::baseUnitName($item);
        foreach ($item['order_units'] as $unit) {
            if (! $unit['default'] || $unit['name'] === '') {
                continue;
            }
            if ($unit['code'] === $item['base_unit'] || $baseName === null || $unit['conversion'] === '') {
                return $unit['name'];
            }

            return $unit['name'].' = '.$unit['conversion'].' '.$baseName;
        }

        return '';
    }

    /**
     * Karta wyrobu z wyszukiwarki — pobierana raz na produkt (pamięć ostatniej).
     *
     * @return array<string, mixed>
     */
    private function pdpOf(B2bRemoteProduct $product): array
    {
        if ($this->pdpId === $product->remoteId) {
            return $this->pdp;
        }
        $json = $this->client->pdp($product->remoteId);
        $record = $json;
        foreach (['item', 'product', 'data'] as $key) {
            if (! isset($record['name']) && is_array($json[$key] ?? null)) {
                $record = $json[$key];
            }
        }
        if (! isset($record['name']) && is_array($json['items'][0] ?? null)) {
            $record = $json['items'][0];
        }
        // nieznany kształt odpowiedzi to błąd, nie „karta bez opisu” — pusty opis producenta kasuje opis karty
        // (B2bCatalogSync::ownDescriptionIsGone), więc nie wolno go udawać
        if (array_intersect(['mmm_id', 'name', 'description', 'long_description', 'classified'], array_keys($record)) === []) {
            throw new RuntimeException('nieczytelna karta wyrobu 3M '.$product->remoteId);
        }
        $id = self::value($record['mmm_id'] ?? null);
        if ($id !== '' && $id !== $product->remoteId) {
            throw new RuntimeException('karta 3M innego wyrobu ('.$id.' zamiast '.$product->remoteId.')');
        }
        $this->pdpId = $product->remoteId;
        $this->pdp = $record;

        return $record;
    }

    /** Zapis sesji na koncie (tylko dwie kolumny); null = zapisano albo nie ma konta, inaczej komunikat. */
    private function saveSession(): ?string
    {
        if ($this->account === null || ! $this->account->exists) {
            return null;
        }
        try {
            $this->account->forceFill([
                'connector_session' => $this->client->session(),
                'connector_session_saved_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            return 'Sesja 3M nie została zapisana na koncie ('.$e->getMessage().')';
        }

        return null;
    }

    /** Adres pliku PDF z adresu podglądu dokumentu („…/2064569J/x.jpg” → „…/2064569O/x.pdf”); null = inny adres. */
    /**
     * Pozycja galerii 3M, która jest obrazem: typ MIME obrazu albo brak typu (starsze pozycje) — wideo, audio
     * i dokumenty odpadają.
     *
     * @param  array<string, mixed>  $row
     */
    private static function isImageRow(array $row): bool
    {
        $mime = mb_strtolower(self::value($row['mime_type'] ?? null));

        return $mime === '' || str_starts_with($mime, 'image/');
    }

    /** Adres pliku obrazu (jpg/jpeg/png/gif/webp) — adres filmu z galerii 3M ma rozszerzenie .mp4. */
    private static function hasImageExtension(string $url): bool
    {
        return preg_match('/\.(jpe?g|png|gif|webp)$/i', (string) parse_url($url, PHP_URL_PATH)) === 1;
    }

    private static function pdfUrl(string $url): ?string
    {
        if (! MmmB2bClient::isFileUrl($url)) {
            return null;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (str_ends_with(mb_strtolower($path), '.pdf')) {
            return $url;
        }
        $pdf = preg_replace('#/(\d+)J/([^/]+)\.jpe?g$#i', '/$1O/$2.pdf', $path, 1, $count);
        if ($count !== 1 || ! is_string($pdf)) {
            return null;
        }

        return 'https://'.MmmB2bClient::FILE_HOST.$pdf;
    }

    /**
     * Wartość parametru: enum [{value}] łączone „, ”, numeric [{uom_id, value}] z jednostką, tekst dosłownie.
     */
    private static function classifiedValue(mixed $value): string
    {
        $pieces = [];
        foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $entry) {
            if (is_array($entry) && array_key_exists('value', $entry)) {
                $unit = self::value($entry['uom_id'] ?? null);
                $piece = trim(self::value($entry['value']).($unit !== '' ? ' '.$unit : ''));
            } else {
                $piece = self::value($entry);
            }
            if ($piece !== '') {
                $pieces[] = $piece;
            }
        }

        return implode(', ', $pieces);
    }

    /**
     * Tekst ze źródła (zwykły albo z prostym HTML) → linie bez pustych; tablica → linie każdego elementu.
     *
     * @return list<string>
     */
    private static function lines(mixed $value): array
    {
        if (is_array($value) && array_is_list($value)) {
            $lines = [];
            foreach ($value as $entry) {
                $text = implode(' ', self::lines($entry));
                if ($text !== '') {
                    $lines[] = $text;
                }
            }

            return $lines;
        }
        $text = self::value($value);
        if ($text === '') {
            return [];
        }
        if (preg_match('/<[a-z\/!]/i', $text) === 1) {
            $text = (string) preg_replace('#<\s*br\s*/?\s*>#i', "\n", $text);
            $text = (string) preg_replace('#<\s*li\b[^>]*>#i', "\n- ", $text);
            $text = (string) preg_replace('#</\s*(p|div|li|ul|ol|h[1-6]|tr)\s*>#i', "\n", $text);
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        }
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = self::clean($line);
            if ($line !== '' && $line !== '-') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Wartość pola wyszukiwarki: tekst, liczba albo obiekt {value: …}; lista → wartości łączone „, ”.
     */
    private static function value(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            if (array_key_exists('value', $value)) {
                return self::value($value['value']);
            }
            if (array_is_list($value)) {
                return implode(', ', array_values(array_filter(array_map(self::value(...), $value), static fn (string $v): bool => $v !== '')));
            }
        }

        return '';
    }

    /**
     * @return list<mixed>
     */
    private static function listOf(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    private static function isTrue(mixed $value): bool
    {
        return $value === true || (is_string($value) && strtolower(trim($value)) === 'true');
    }

    private static function sameUnit(string $a, string $b): bool
    {
        return mb_strtolower(self::clean($a)) === mb_strtolower(self::clean($b));
    }

    /**
     * @param  list<string>  $prefixes
     */
    private static function startsWithAny(string $text, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($text, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $items
     */
    private static function listing(array $items): string
    {
        return count($items).', np. '.implode(', ', array_slice($items, 0, 10));
    }

    private function progress(string $message): void
    {
        if ($this->listProgress !== null) {
            ($this->listProgress)($message);
        }
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $value));
    }
}
