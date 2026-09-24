<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * Panel UVEX izam.system-b2b.pl — cena konta, dostępność, nazwa i kod z listy wszystkich produktów; opis i zdjęcie
 * ze strony produktu (opis tylko gdy synchronizacja może go zapisać). Sprawdzone na koncie 15.09.2026.
 *
 * Lista: 5750 pozycji po 20 na stronę, zbierana w całości przed pierwszym zapisem (rozmiary jednego wyrobu bywają
 * na sąsiednich stronach). Każda strona musi mieć licznik „Wyświetlanie rekordów od X do Y z Z” zgodny z numerem
 * strony i liczbą wierszy; zmiana w trakcie (produkt dodany/usunięty) = jedno ponowne pobranie całej listy,
 * potem błąd przebiegu bez zapisu. Wiersz bez ceny konta = błąd (utrata widoku cen), nie „brak ceny”.
 *
 * Rozmiary o tej samej cenie są jedną kartą (UvexSizeGroups); karta podaje wszystkie swoje kody (members), więc
 * synchronizacja wiąże każdy kod z kartą. Pozycja pojedyncza też podaje swój kod — dwie osobne pozycje nigdy nie
 * trafią na jedną kartę. Kod i remoteId karty = pierwszy kod grupy (sortowanie naturalne) — prawdziwy kod, bez
 * wymyślonego rdzenia; pozostałe kody i rozmiary są w variant_summary (wyszukiwanie).
 *
 * Ceny: „Twoja cena netto” w PLN (jedyna waluta widoczna na koncie); katalogowa = zakupu (sklep nie pokazuje ceny
 * bazowej); 0,00 PLN (np. pozycje „Cenniki”, „Karty charakterystyki”) = brak ceny. Dostępność dosłownie
 * („Dostępny” / „Na zamówienie”), dla grupy z podziałem na rozmiary, gdy się różni.
 *
 * Karta bez opisu w panelu ma tam odnośnik do strony producenta (uvex-laservision.de) — opis (zakładka
 * „Description”, a gdy jej nie ma, wstęp przy cenie), tabelę „Specifications” i poziomy ochrony bierzemy stamtąd,
 * ale tylko gdy numer katalogowy wypisany na stronie zgadza się z kodem karty; sklep bywa nierzetelny i odsyła
 * kilka filtrów pod jeden adres. Tekst jest po angielsku i idzie do tłumaczenia (B2bForeignTextCards).
 *
 * Opis to sama proza: dane z tabelek (jednostka sprzedaży, „Specifications”, zakresy i poziomy ochrony) idą na
 * kartę wyrobu u dostawcy (shopFields(), ProductShopCard), a nie w opis. Karta bez prozy u dostawcy zostaje więc
 * z pustym opisem i czeka na niego — synchronizacja pustym opisem istniejącego nie nadpisuje.
 *
 * Zdjęcia: miniatura z listy i galeria ze strony produktu (#B2B_fotorama_details) — sklep trzyma tam pozostałe
 * ujęcia wyrobu (do czterech); na kartę idą wszystkie, pierwsze zostaje głównym.
 *
 * Pliki do pobrania (zakładka „Pliki do pobrania”, tabela #p-files-table): karta techniczna „SST …” i instrukcje.
 * Adresy w tabeli są względne wobec <base href> strony; nazwa pliku dosłownie z tabeli. Strona produktu pobierana
 * jest raz na kartę — ten sam HTML służy opisowi i liście plików.
 *
 * Producent — ZAŁOŻENIE (sklep nie ma pola producenta): „HECKEL” gdy kod lub nazwa zawiera heckel, „HexArmor” gdy
 * nazwa zawiera hexarmor, inaczej „UVEX” (sklep firmy UVEX; większość nazw zawiera „uvex”).
 *
 * Cennik bazowy (B2bStandardDiscountSite): xlsx spod odnośnika „Cennik do pobrania” na stronie startowej konta
 * (nazwa pliku zmienia się z wydaniem cennika — szukamy po tekście odnośnika). Wczytywany raz na przebieg, po
 * liście, przed pierwszą kartą; każdy błąd (brak odnośnika, HTTP, plik nieczytelny) to ostrzeżenie w podsumowaniu,
 * nie przerwanie przebiegu — ceny konta nie zależą od cennika bazowego, a synchronizacja zostawia wtedy ceny
 * bazowe z poprzedniego przebiegu. Dopasowanie kodów: UvexBasePriceList. Rabat standardowy z reguł konta
 * (B2bDiscountRuleResolver: numer katalogowy = kod karty, kategoria = arkusz cennika, nazwa = nazwa karty).
 */
final class UvexB2bConnector implements B2bConnector, B2bDocumentSource, B2bForeignTextCards, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldNormSource, B2bShopFieldSource, B2bStandardDiscountSite
{
    /**
     * Rabaty standardowe z wiadomości dostawcy (22.09.2026): 35% ochrona wzroku; 30% hełmy, ochrona słuchu, dróg
     * oddechowych, rękawice (HexArmor 30% — potwierdzone przez użytkownika); 15% obuwie. Odzieży nie ma, bo jej
     * arkusz jest pominięty (UvexBasePriceList::EXCLUDED_SHEETS). Nazwy dosłownie jak arkusze cennika.
     */
    private const DEFAULT_STANDARD_DISCOUNTS = [
        'Ochrona wzroku' => 35.0,
        'Hełmy' => 30.0,
        'Ochrona słuchu' => 30.0,
        'Ochrona dróg oddechowych' => 30.0,
        'Rękawice uvex' => 30.0,
        'Rękawice HEXArmor' => 30.0,
        'Buty Uvex' => 15.0,
        'Buty Heckel' => 15.0,
    ];

    /** Tekst odnośnika do cennika bazowego na stronie startowej konta (sprawdzone 22.09.2026). */
    private const BASE_PRICE_LINK_TEXT = 'cennik do pobrania';

    /** Nieprzerwane pobieranie listy dłużej = błąd (przebieg bez postępu uznałby b2b:sync-due za przerwany). */
    private const LIST_BUDGET_SECONDS = 25 * 60;

    private const PROGRESS_EVERY_PAGES = 20;

    /** Tyle plików z jednej strony produktu trafia na kartę (UVEX: karta techniczna i instrukcja). */
    private const DOCUMENTS_LIMIT = 5;

    /** Tyle zdjęć jednej karty bierzemy ze sklepu (galeria strony produktu; najwięcej widziano 4). */
    private const IMAGES_LIMIT = 8;

    /** Sklep wstawia to zamiast opisu części kart — to zachęta do kliknięcia, nie opis wyrobu. */
    private const DESCRIPTION_PLACEHOLDERS = ['kliknij i przejdź do pełnego opisu'];

    /** Nagłówek sekcji, pod którą na karcie ląduje opis wzięty ze strony producenta. */
    private const MANUFACTURER_SECTION = 'Opis ze strony producenta';

    /** Nagłówek wierszy tabeli poziomów ochrony na karcie wyrobu u dostawcy. */
    private const PROTECTION_SECTION = 'Poziomy ochrony';

    /** Sekcje karty wyrobu u dostawcy (B2bShopFieldSource) — osobne od nagłówków opisu. */
    private const SHOP_SECTION_TRADE = 'Informacje handlowe';

    private const SHOP_SECTION_TECHNICAL = 'Dane techniczne';

    private const SHOP_SECTION_PROTECTION = 'Ochrona';

    /** Koniec tekstu opisu na stronie producenta — nagłówek tabeli parametrów, którą czytamy osobno. */
    private const MANUFACTURER_TAIL_HEADINGS = ['specifications', 'spezifikationen'];

    /**
     * Tyle pierwszych znaków numeru katalogowego musi się zgadzać ze stroną producenta, żeby uznać ją za stronę
     * tego wyrobu. Sklep bywa nierzetelny: karty filtra P621 (000P6P21…) i P1N01 (000P1N01…) odsyłają do strony
     * filtra P1P10 (000P1P10…) — opis, normy i poziomy ochrony byłyby z cudzego wyrobu (sprawdzone 16.09.2026).
     * Osiem znaków to „000” + oznaczenie filtra; różnią się dopiero wariant i rozmiar, a te opisu nie zmieniają.
     */
    private const MANUFACTURER_CODE_PREFIX = 8;

    /**
     * Etykiety, którymi sklep producenta podpisuje numer katalogowy pokazywanego wyrobu (strona po angielsku,
     * niemiecku albo polsku). To jedyne miejsce, w którym strona sama mówi, jaki wyrób opisuje.
     */
    private const ORDER_NUMBER_LABELS = ['Order number', 'Bestellnummer', 'Artikelnummer', 'Numer katalogowy', 'Numer artykułu'];

    /** Tyle wierszy tabeli poziomów ochrony trafia na kartę. */
    private const PROTECTION_ROWS_LIMIT = 30;

    /** Tyle zakresów ochrony (UV, VIS, IR…) bierzemy z bocznego bloku strony producenta. */
    private const PROTECTION_RANGE_LIMIT = 8;

    /**
     * Nagłówki tabeli poziomów ochrony po polsku. Same wiersze zostają dosłownie (kody, długości fal i stopnie
     * ochrony), ale nagłówek to etykieta kolumny, nie dana wyrobu — nieznanego nagłówka nie zmieniamy.
     */
    private const PROTECTION_HEADINGS = [
        'wavelength (nm)' => 'Długość fali (nm)',
        'wavelength' => 'Długość fali',
        'od' => 'OD',
        'operating mode / tested protection level' => 'Tryb pracy / badany stopień ochrony',
        'operating mode' => 'Tryb pracy',
    ];

    private const INCONSISTENT = 7001;

    private const SKIPPED_TAGS = ['input', 'button', 'select', 'option', 'textarea', 'img', 'iframe', 'svg', 'script', 'style', 'noscript'];

    private const BLOCK_TAGS = ['p', 'div', 'section', 'article', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tr', 'blockquote', 'pre', 'hr'];

    private int $total = 0;

    /** Strona produktu bieżącej karty — opis i pliki czytają ten sam HTML (jedno pobranie na kartę). */
    private ?string $pageUrl = null;

    private ?string $pageHtml = null;

    /**
     * Ostatnio pobrany plik karty — tekst karty technicznej i zapis dokumentu biorą te same bajty.
     *
     * @var array{url: string, bytes: string, mime: string}|null
     */
    private ?array $file = null;

    /** remoteId karty, której opis przyszedł ze strony producenta (po angielsku) — do zlecenia tłumaczenia. */
    private ?string $foreignDescriptionFor = null;

    /**
     * Strona producenta bieżącej karty: jedno pobranie na kartę służy i opisowi, i wierszom karty dostawcy —
     * bez względu na to, które z nich poprosiło o nią pierwsze. Wpis powstaje także wtedy, gdy strony nie ma
     * (page === null: karta nigdzie nie odsyła albo odnośnik prowadzi do innego wyrobu), żeby nie szukać jej
     * drugi raz. Dokument jednej karty naraz — kasowany razem ze stroną sklepu.
     *
     * @var array{remote_id: string, url: string|null, page: DOMXPath|null, fields: list<B2bRemoteShopField>}|null
     */
    private ?array $manufacturer = null;

    /**
     * Karty, których odnośnik prowadził do strony innego wyrobu, a właściwej nie udało się znaleźć.
     *
     * @var list<string>
     */
    private array $wrongLinks = [];

    /** Ile razy właściwa strona producenta znalazła się po numerze katalogowym, mimo błędnego odnośnika. */
    private int $foundByCode = 0;

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    /** Cennik bazowy tego przebiegu; null = niewczytany (powód w $basePriceError). */
    private ?UvexBasePriceList $basePrices = null;

    private ?string $basePriceError = null;

    /**
     * Ostatnia karta, o którą pytano basePrice() — drugie pytanie o tę samą kartę nie liczy się drugi raz
     * w licznikach cennika ani reguł.
     *
     * @var array{remote_id: string, price: B2bBasePrice|null}|null
     */
    private ?array $lastBasePrice = null;

    /**
     * @param  B2bDiscountRuleResolver|null  $discounts  reguły rabatu standardowego konta; null = bez oceny (rabat null)
     */
    public function __construct(
        private readonly UvexB2bClient $client,
        private readonly UvexSizeGroups $groups = new UvexSizeGroups,
        private readonly ?B2bDiscountRuleResolver $discounts = null,
    ) {}

    public static function key(): string
    {
        return 'uvex';
    }

    public static function label(): string
    {
        return 'UVEX';
    }

    public static function host(): string
    {
        return UvexB2bClient::HOST;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new UvexB2bClient(
            (string) $account->contractor_code,
            (string) $account->username,
            (string) $account->password,
            $delayMs,
        ), discounts: new B2bDiscountRuleResolver((int) $account->id));
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
        $rows = $this->listRows();
        [$rows, $skipped] = self::withoutConflictingCodes($rows);

        $products = $skipped;
        $grouped = 0;
        foreach ($this->groups->group($rows) as $group) {
            $products[] = $this->productFor($group);
            if (count($group) > 1) {
                $grouped++;
            }
        }
        $this->total = count($products);
        $this->progress(sprintf(
            'Lista UVEX: %d pozycji → %d kart (%d grup rozmiarów o tej samej cenie)',
            count($rows) + count($skipped),
            $this->total,
            $grouped,
        ));

        // generator żyje do ostatniej karty — wiersze listy (tysiące pozycji) nie są już potrzebne,
        // karty mają własne kopie kodu i nazwy
        unset($rows, $skipped);

        $this->loadBasePriceList();

        foreach ($products as $product) {
            yield $product;
        }
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    /** Witryna nalezy do tej marki — tylko jej karty wolno nadpisac opisem stad. */
    public static function ownBrand(): string
    {
        return 'uvex';
    }

    /** Dane techniczne / Protection Class / Norm = „EN 207 full protection, EN 60825” — tylko ochrona laserowa; rękawice UVEX nie mają tu EN 388 (23.09.2026: 58 kart). */
    public static function normShopFieldNames(): array
    {
        return ['Protection Class / Norm'];
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        $texts = [];
        foreach ($product->raw['rows'] ?? [] as $row) {
            $texts[] = ['code' => (string) ($row['code'] ?? ''), 'name' => (string) ($row['name'] ?? '')];
        }
        if ($texts === []) {
            $texts[] = ['code' => $product->sku, 'name' => $product->name];
        }

        return self::brandOf($texts);
    }

    /**
     * Producent z kodów i nazw pozycji karty (założenie opisane przy klasie) — ta sama decyzja dla manufacturer()
     * i dla rodzaju kodu w identifiers().
     *
     * @param  list<array{code: string, name: string}>  $texts
     */
    private static function brandOf(array $texts): string
    {
        foreach ($texts as $text) {
            if (mb_stripos($text['code'].' '.$text['name'], 'heckel') !== false) {
                return 'HECKEL';
            }
        }
        foreach ($texts as $text) {
            if (mb_stripos($text['name'], 'hexarmor') !== false) {
                return 'HexArmor';
            }
        }

        return 'UVEX';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'pozycja listy nieodczytana'));
        }
        $text = (string) ($product->raw['price_text'] ?? '');
        $cents = self::priceCents($text);
        if ($cents === null) {
            throw new RuntimeException('nie udało się odczytać ceny „'.$text.'” (oczekiwano np. „1 133,05 PLN”)');
        }
        if ($cents === 0) {
            return null;
        }

        return new B2bRemotePrice(net: $cents / 100, base: null, discountPercent: 0.0, currency: 'PLN', order: self::orderQuantity($product));
    }

    public function basePriceListLoaded(): bool
    {
        return $this->basePrices !== null;
    }

    /**
     * Wiersz cennika bazowego dla wszystkich kodów karty (rozmiary) i rabat standardowy z reguł konta. Karta bez
     * wiersza, z niejednoznacznym wierszem albo z arkusza pominiętego = null (brak oceny ceny, nie „standard”).
     */
    public function basePrice(B2bRemoteProduct $product): ?B2bBasePrice
    {
        if ($this->basePrices === null || ($product->raw['status'] ?? null) !== 'ok') {
            return null;
        }
        if (($this->lastBasePrice['remote_id'] ?? null) === $product->remoteId) {
            return $this->lastBasePrice['price'];
        }

        $codes = array_map(static fn (array $member): string => (string) $member['sku'], $product->members);
        $row = $this->basePrices->match($codes !== [] ? $codes : [$product->sku]);
        $price = null;
        if ($row !== null) {
            $price = new B2bBasePrice(
                net: $row['price'],
                category: $row['sheet'],
                code: $row['code'],
                source: $this->basePrices->sourceOf($row),
                standardDiscountPercent: $this->discounts?->resolve(
                    catalogNo: $product->sku,
                    category: $row['sheet'],
                    name: $product->name,
                )?->discountPercent,
            );
        }
        $this->lastBasePrice = ['remote_id' => $product->remoteId, 'price' => $price];

        return $price;
    }

    /**
     * @return list<array{category: string, discount_percent: float}>
     */
    public static function defaultStandardDiscounts(): array
    {
        $out = [];
        foreach (self::DEFAULT_STANDARD_DISCOUNTS as $category => $percent) {
            $out[] = ['category' => $category, 'discount_percent' => $percent];
        }

        return $out;
    }

    /**
     * Arkusze aktualnego cennika bazowego dla okna reguł rabatu — ta sama ścieżka co przebieg (strona startowa,
     * odnośnik, plik), więc podpowiedź nazw nie rozjedzie się z tym, co zapisze synchronizacja.
     *
     * @return list<string>
     */
    public function basePriceCategories(): array
    {
        $this->client->login();
        $this->loadBasePriceList();
        if ($this->basePrices === null) {
            throw new RuntimeException('Nie udało się wczytać cennika bazowego UVEX: '.($this->basePriceError ?? 'nieznany błąd'));
        }

        return $this->basePrices->sheets();
    }

    /**
     * Cennik bazowy raz na przebieg. Błąd sesji konta (B2bFatalException) przerywa przebieg jak na każdej innej
     * stronie; każdy inny błąd zostaje w podsumowaniu, a karty idą dalej z samą ceną konta.
     */
    private function loadBasePriceList(): void
    {
        $this->basePrices = null;
        $this->basePriceError = null;
        $this->lastBasePrice = null;
        try {
            $url = self::basePriceListUrl($this->client->startPage());
            $file = $this->client->fileBytes($url, 'cennika bazowego');
            $this->basePrices = UvexBasePriceList::fromXlsx(
                $file['bytes'],
                rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))),
                now(),
            );
            $this->progress('Cennik bazowy UVEX: '.$this->basePrices->fileName().' ('.$this->basePrices->rowCount().' wierszy)');
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->basePriceError = $e->getMessage();
        }
    }

    /**
     * Adres cennika bazowego ze strony startowej: odnośnik z tekstem „Cennik do pobrania”, rozwinięty po <base href>
     * i sprawdzony, czy prowadzi do sklepu. Brak albo kilka różnych adresów = wyjątek (nie zgadujemy, który plik).
     */
    public static function basePriceListUrl(string $startPage): string
    {
        $xpath = JspB2bClient::dom($startPage);
        $base = self::baseUrl($xpath);
        $urls = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $link) {
            if (! $link instanceof DOMElement || ! str_contains(mb_strtolower(self::text($link)), self::BASE_PRICE_LINK_TEXT)) {
                continue;
            }
            $url = self::fileUrl($base, $link->getAttribute('href'));
            if ($url !== null) {
                $urls[$url] = true;
            }
        }
        if ($urls === []) {
            throw new RuntimeException('brak odnośnika „Cennik do pobrania” do sklepu na stronie startowej konta');
        }
        if (count($urls) > 1) {
            throw new RuntimeException('kilka różnych odnośników „Cennik do pobrania” na stronie startowej ('.implode(', ', array_keys($urls)).')');
        }

        return (string) array_key_first($urls);
    }

    /**
     * Sama proza opisu: tekst ze strony produktu pierwszej pozycji karty (dosłownie, akapity i pozycje list jako
     * linie), a gdy panel opisu nie ma — tekst ze strony producenta, do której odsyła. Danych z tabelek
     * (jednostka sprzedaży, „Specifications”, zakresy i poziomy ochrony) opis nie powtarza — te są na karcie
     * wyrobu u dostawcy (shopFields()). Strona z innym kodem niż lista = wyjątek (opis innego produktu nie może
     * trafić na kartę). Pusty wynik = karta czeka na opis; synchronizacja pustym opisem niczego nie nadpisuje.
     */
    public function description(B2bRemoteProduct $product): string
    {
        $xpath = $this->productXpath($product);
        if ($xpath === null) {
            return '';
        }

        $node = $xpath->query('//*[@id="description"]')->item(0);
        $lines = array_values(array_filter(
            $node !== null ? self::blockLines($node) : [],
            static fn (string $line): bool => ! in_array(mb_strtolower($line), self::DESCRIPTION_PLACEHOLDERS, true),
        ));
        if ($lines !== []) {
            return mb_substr(implode("\n", $lines), 0, 10000);
        }
        if ($node === null) {
            return '';
        }

        // sklep nie ma opisu, tylko odnośnik „Kliknij i przejdź do pełnego opisu” — opis jest u producenta
        return mb_substr($this->manufacturerDescription($product, $xpath), 0, 10000);
    }

    /**
     * Karta wyrobu u dostawcy: kod i jednostka sprzedaży z wiersza listy oraz — gdy karta odsyła do strony
     * producenta — jej numer katalogowy, tabela „Specifications”, zakresy ochrony i wiersze „Protection Level”
     * (sklep sam tabelki wyrobu nie pokazuje). Strony producenta metoda w razie potrzeby szuka sama: karta
     * z opisem ręcznym albo od AI nie woła description() w ogóle, a bez tego zostałaby z samymi danymi
     * handlowymi. Koszt: strona produktu i strona producenta po jednym pobraniu na kartę — tę samą kopię czyta
     * opis, a synchronizacja woła tę metodę nie częściej niż raz na B2bCatalogSync::SHOP_FIELDS_TTL_DAYS.
     * Ceny nie dokładamy — karta ma na nie własną sekcję.
     *
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            return [];
        }

        $fields = [];
        $code = trim((string) ($product->raw['code'] ?? $product->sku));
        if ($code !== '') {
            $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_TRADE, 'Kod', $code);
        }
        $unit = trim((string) ($product->raw['unit'] ?? ''));
        if ($unit !== '') {
            $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_TRADE, 'Jednostka sprzedaży', $unit);
        }
        $order = $product->raw['order'] ?? null;
        if (is_array($order) && $order['by_size'] !== null) {
            $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_TRADE, 'Zamawianie (różne dla rozmiarów)', $order['by_size']);
        } elseif (is_array($order) && $order['min'] !== null && (new B2bOrderQuantity($order['min'], $order['step']))->restricts()) {
            // bez ograniczenia (min 1, step any albo 1) — nie dopisujemy „bez ograniczeń” do tysiąca kart
            $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_TRADE, 'Zamawianie', self::orderLabel($order['min'], $order['step'], $unit));
        }

        return [...$fields, ...$this->manufacturerFor($product)['fields']];
    }

    /**
     * Wiersze karty dostawcy ze strony producenta, w kolejności ze źródła. Wiersze „Protection Level” zostają
     * sklejone z kolumn („180 - 315 | (OD10+) | …”), jak w opisie — karta pokazuje je jako tabelę.
     *
     * @return list<B2bRemoteShopField>
     */
    private static function manufacturerShopFields(DOMXPath $page, string $html): array
    {
        $fields = [];
        $orderNumber = self::orderNumber($html);
        if ($orderNumber !== null) {
            $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_TRADE, 'Numer katalogowy producenta', $orderNumber);
        }
        foreach (self::specificationPairs($page) as [$name, $value]) {
            $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_TECHNICAL, $name, $value);
        }
        foreach (self::protectionRangePairs($page) as [$name, $value]) {
            $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_PROTECTION, $name, $value);
        }
        foreach (self::protectionLines($page) as $line) {
            $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_PROTECTION, self::PROTECTION_SECTION, $line);
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    public function runSummary(): array
    {
        $lines = $this->basePriceSummary();
        if ($this->foundByCode > 0) {
            $lines[] = 'Odnośnik ze sklepu prowadził do strony innego wyrobu, właściwą znaleziono po numerze katalogowym: '
                .$this->foundByCode.' kart';
        }
        if ($this->wrongLinks === []) {
            return $lines;
        }

        return [...$lines, sprintf(
            'Odnośnik ze sklepu prowadził do strony innego wyrobu — opisu nie pobrano dla %d kart (%s%s)',
            count($this->wrongLinks),
            implode('; ', array_slice($this->wrongLinks, 0, 3)),
            count($this->wrongLinks) > 3 ? '; …' : '',
        )];
    }

    /**
     * Linie podsumowania cennika bazowego i rabatu standardowego. Liczniki trafień reguł zapisujemy tylko po
     * wczytanym cenniku — bez niego reguł nikt nie pytał, a zera przy regułach wyglądałyby jak „reguła nic nie łapie”.
     *
     * @return list<string>
     */
    private function basePriceSummary(): array
    {
        if ($this->basePrices === null) {
            return $this->basePriceError === null ? [] : [
                'Cennik bazowy UVEX nie wczytany ('.$this->basePriceError.') — ceny bazowe kart bez zmian z poprzedniego przebiegu',
            ];
        }

        $list = $this->basePrices;
        $counters = $list->counters();
        $lines = [
            'Cennik bazowy UVEX: '.$list->fileName().' — '.$list->rowCount().' wierszy z arkuszy: '.implode(', ', $list->sheets())
                .' (pominięte: '.implode(', ', [...UvexBasePriceList::EXCLUDED_SHEETS, ...$list->skippedSheets()]).')',
            sprintf(
                'Cennik bazowy: %d kart dopasowanych (w tym %d z częścią rozmiarów spoza cennika), %d kart spoza cennika, %d kart z niejednoznacznym wierszem',
                $counters['matched'],
                $counters['partial'],
                $counters['missing'],
                $counters['conflicts'],
            ).($counters['conflict_examples'] !== [] ? ' ('.implode('; ', $counters['conflict_examples']).($counters['conflicts'] > count($counters['conflict_examples']) ? '; …' : '').')' : ''),
        ];

        if ($this->discounts === null) {
            return $lines;
        }
        $this->discounts->flushCounters();
        if (! $this->discounts->hasRules()) {
            $lines[] = 'Konto nie ma reguł rabatu standardowego — ceny specjalne nie są oceniane. Uzupełnij rabaty w konfiguracji konta.';

            return $lines;
        }
        $lines[] = 'Rabat standardowy: '.$this->discounts->matchedCount().' kart z pasującą regułą';
        if ($this->discounts->missedCount() > 0) {
            $lines[] = 'Kart w cenniku bazowym bez pasującej reguły rabatu standardowego: '.$this->discounts->missedCount().' — cena specjalna nieoceniana.';
        }

        return $lines;
    }

    public function hasForeignDescription(B2bRemoteProduct $product): bool
    {
        return $this->foreignDescriptionFor !== null && $this->foreignDescriptionFor === $product->remoteId;
    }

    /**
     * Opis ze strony producenta, do której odsyła karta bez opisu w panelu — sama proza (zakładka „Description”,
     * a gdy jej nie ma, wstęp przy cenie). Tekst jest po angielsku, więc kartę oznaczamy jako obcojęzyczną, żeby
     * synchronizacja zleciła tłumaczenie na polski (jak przy Bollé). Nagłówek sekcji podaje domenę, z której opis
     * pochodzi. Tabele strony (parametry, zakresy i poziomy ochrony) do opisu nie wchodzą — są w shopFields().
     */
    private function manufacturerDescription(B2bRemoteProduct $product, DOMXPath $xpath): string
    {
        $manufacturer = $this->manufacturerFor($product, $xpath);
        $page = $manufacturer['page'];
        if ($page === null) {
            return '';
        }

        $description = $page->query('//*[@itemprop="description"]')->item(0);
        $intro = $page->query('//*['.JspB2bClient::classPredicate('product-detail-short-description').']')->item(0);
        $properties = $page->query('//table[contains(@class, "product-detail-properties-table")]')->item(0);
        if ($description === null && $intro === null && $properties === null) {
            // żaden z bloków opisu — to nie jest strona wyrobu albo sklep zmienił budowę stron
            throw new RuntimeException('strona producenta '.$manufacturer['url'].' nie ma opisu w spodziewanym miejscu');
        }

        $lines = $description !== null ? self::blockLines($description) : [];
        // ostatni wiersz to nagłówek tabeli parametrów — sama tabela jest na karcie wyrobu u dostawcy
        while ($lines !== [] && in_array(mb_strtolower(end($lines)), self::MANUFACTURER_TAIL_HEADINGS, true)) {
            array_pop($lines);
        }

        // wstęp przy cenie: zwykle mówi to samo innymi słowami, ale dokłada fakty (maksymalny rozmiar, grubość,
        // normy); część kart (akcesoria) ma go zamiast zakładki „Description” — wtedy jest całym opisem
        $lines = self::withoutRepeatedText($intro !== null ? self::blockLines($intro) : [], $lines);

        if ($lines === []) {
            // Strona producenta bywa samą tabelą parametrów, bez zdania opisu — pusty wynik znaczy „ta strona nie
            // ma prozy”, a nie „strony nie pobrano”: jej wiersze są już zapamiętane dla shopFields(). Karta czeka
            // wtedy na opis (z AI albo od człowieka), zamiast dostać tabelkę w miejscu opisu.
            return '';
        }

        $this->foreignDescriptionFor = $product->remoteId;

        return self::MANUFACTURER_SECTION.' ('.parse_url((string) $manufacturer['url'], PHP_URL_HOST).'):'
            ."\n".implode("\n", $lines);
    }

    /**
     * Strona producenta karty: pobrana raz, niezależnie od tego, czy poprosił o nią opis, czy karta wyrobu
     * u dostawcy. Wiersze tabelek liczymy od razu — przy jednym przebiegu strona idzie po sieci tylko raz.
     * Brak strony (karta nigdzie nie odsyła, odnośnik prowadzi poza domeny producenta albo do innego wyrobu)
     * też zapamiętujemy, żeby druga metoda nie szukała jej ponownie.
     *
     * @return array{remote_id: string, url: string|null, page: DOMXPath|null, fields: list<B2bRemoteShopField>}
     */
    private function manufacturerFor(B2bRemoteProduct $product, ?DOMXPath $xpath = null): array
    {
        if (($this->manufacturer['remote_id'] ?? null) === $product->remoteId) {
            return $this->manufacturer;
        }

        $found = $this->manufacturerPageOf($product, $xpath);
        $page = $found !== null ? JspB2bClient::dom($found['html']) : null;
        $this->manufacturer = [
            'remote_id' => $product->remoteId,
            'url' => $found['url'] ?? null,
            'page' => $page,
            'fields' => $page !== null ? self::manufacturerShopFields($page, $found['html']) : [],
        ];

        return $this->manufacturer;
    }

    /**
     * Strona producenta spod odnośnika, który panel pokazuje zamiast opisu; null = karta nigdzie nie odsyła,
     * odnośnik prowadzi poza domeny producenta albo do innego wyrobu (wtedy powód idzie do runSummary()).
     *
     * @return array{url: string, html: string}|null
     */
    private function manufacturerPageOf(B2bRemoteProduct $product, ?DOMXPath $xpath): ?array
    {
        $xpath ??= $this->productXpath($product);
        $node = $xpath?->query('//*[@id="description"]')->item(0);
        if ($xpath === null || $node === null) {
            return null;
        }

        $link = $xpath->query('.//a[@href]', $node)->item(0);
        $url = $link instanceof DOMElement ? trim(html_entity_decode($link->getAttribute('href'), ENT_QUOTES | ENT_HTML5)) : '';
        if ($url === '' || ! UvexB2bClient::isManufacturerUrl($url)) {
            return null;
        }

        $code = (string) ($product->raw['code'] ?? $product->sku);
        $found = $this->manufacturerPageFor($url, $code);
        if ($found === null) {
            // Sam pomijamy stronę, zamiast przerywać wyjątkiem: karta zapisze opis bez treści producenta,
            // więc cudzy opis z wcześniejszego przebiegu zniknie z katalogu sam.
            $this->wrongLinks[] = $code.' → '.basename((string) parse_url($url, PHP_URL_PATH));
        }

        return $found;
    }

    /**
     * Pliki z zakładki „Pliki do pobrania” strony produktu pierwszej pozycji karty (rozmiary tego samego wyrobu
     * mają te same pliki). Nazwa dosłownie z tabeli; adres względny rozwijany po <base href> strony i sprawdzany,
     * czy prowadzi do sklepu. PDF traktujemy jako kartę techniczną, resztę (np. skan instrukcji) jako inny plik.
     *
     * @return list<B2bRemoteDocument>
     */
    public function documents(B2bRemoteProduct $product): array
    {
        $xpath = $this->productXpath($product);
        if ($xpath === null) {
            return [];
        }

        $base = self::baseUrl($xpath);
        $documents = [];
        foreach ($xpath->query('//*[@id="p-files-table"]//tr') ?: [] as $row) {
            $link = $xpath->query('.//a[@href]', $row)->item(0);
            if (! $link instanceof DOMElement) {
                continue;
            }
            $url = self::fileUrl($base, $link->getAttribute('href'));
            if ($url === null || isset($documents[$url])) {
                continue;
            }
            $title = self::text($xpath->query('.//strong', $row)->item(0));
            if ($title === '') {
                $title = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
            }
            $documents[$url] = new B2bRemoteDocument(
                title: mb_substr($title, 0, 255),
                sourceUrl: $url,
                kind: self::isPdfUrl($url) ? ProductDocument::KIND_DATASHEET : ProductDocument::KIND_OTHER,
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
        if (($this->file['url'] ?? null) === $document->sourceUrl) {
            return ['bytes' => $this->file['bytes'], 'mime' => $this->file['mime']];
        }

        $file = $this->client->fileBytes($document->sourceUrl, 'pliku produktu');
        // jeden plik w pamięci: te same bajty idą na tekst karty technicznej i na dokument karty
        $this->file = ['url' => $document->sourceUrl, ...$file];

        return $file;
    }

    /**
     * Zdjęcie z listy. Adres z listy (…/public/get-preview/…) sklep zwraca jako 404 — działa ta sama ścieżka bez
     * „/public” (sprawdzone 15.09.2026, obrazy 500–1000 px). Zaślepka „blank.jpg” = brak zdjęcia.
     */
    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $url = self::imageUrl((string) ($product->raw['image_url'] ?? ''));

        return $url === null ? null : $this->imageAt($url);
    }

    /**
     * Zdjęcia karty: z listy (pierwsze — zostaje głównym) i z galerii strony produktu, gdzie sklep trzyma
     * pozostałe ujęcia (strona jest pobierana raz na kartę, razem z opisem i plikami). Kolejność ze sklepu,
     * bez powtórzeń. Strona innego wyrobu albo pozycja bez strony = zostaje samo zdjęcie z listy.
     *
     * @return list<string>
     */
    public function imageUrls(B2bRemoteProduct $product): array
    {
        $urls = [];
        $listImage = self::imageUrl((string) ($product->raw['image_url'] ?? ''));
        if ($listImage !== null) {
            $urls[$listImage] = true;
        }

        try {
            $xpath = $this->productXpath($product);
        } catch (RuntimeException) {
            // strona produktu nieczytelna albo o innym kodzie — zdjęć stamtąd nie bierzemy (mogłyby być cudze),
            // ale miniatura z wiersza listy należy do tej karty na pewno; powód błędu zgłasza już opis karty
            return array_keys($urls);
        }

        foreach ($xpath?->query('//*[@id="B2B_fotorama_details"]//a[@data-full]') ?: [] as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $url = self::imageUrl(trim(html_entity_decode($link->getAttribute('data-full'), ENT_QUOTES | ENT_HTML5)));
            if ($url !== null) {
                $urls[$url] = true;
            }
            if (count($urls) >= self::IMAGES_LIMIT) {
                break;
            }
        }

        return array_keys($urls);
    }

    public function imageAt(string $url): ?B2bRemoteImage
    {
        $file = $this->client->imageBytes($url);
        if ($file['bytes'] === '' || ! str_starts_with($file['mime'], 'image/')) {
            return null;
        }

        return new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: $url);
    }

    public static function imageUrl(string $listUrl): ?string
    {
        if (! UvexB2bClient::isShopUrl($listUrl)) {
            return null;
        }
        $path = (string) parse_url($listUrl, PHP_URL_PATH);
        if (str_starts_with($path, '/public/get-preview/')) {
            $path = substr($path, strlen('/public'));
        }
        if (! str_starts_with($path, '/get-preview/')) {
            return null;
        }

        return UvexB2bClient::BASE.$path;
    }

    /** „1 133,05 PLN” → 113305; inny zapis (np. bez waluty, inna waluta) = null — nie zgadujemy. */
    public static function priceCents(string $text): ?int
    {
        if (preg_match('/^(\d{1,3}(?:[ \x{00A0}]\d{3})*),(\d{2})\s*PLN$/u', trim($text), $m) !== 1) {
            return null;
        }

        return (int) preg_replace('/\D/', '', $m[1]) * 100 + (int) $m[2];
    }

    /**
     * Cała lista; niespójna — jedno ponowne pobranie od początku.
     *
     * @return list<array<string, mixed>>
     */
    private function listRows(): array
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

            throw new RuntimeException('Lista produktów '.UvexB2bClient::HOST.' niespójna także po ponownym pobraniu: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scanList(): array
    {
        $started = microtime(true);
        $rows = [];
        $seen = [];
        $total = 0;
        $pageSize = 0;
        $pages = 1;

        for ($page = 1; $page <= $pages; $page++) {
            if (microtime(true) - $started > self::LIST_BUDGET_SECONDS) {
                throw new RuntimeException('Pobieranie listy '.UvexB2bClient::HOST.' trwa ponad '.(self::LIST_BUDGET_SECONDS / 60).' min — przerwane bez zapisu');
            }
            $xpath = JspB2bClient::dom($this->client->listPage($page));
            $info = self::paginationInfo($xpath);
            $pageRows = self::parseRows($xpath, $page);

            if ($info === null) {
                if ($page === 1) {
                    throw new RuntimeException('Lista produktów '.UvexB2bClient::HOST.' bez licznika rekordów — pusta lista konta albo zmiana strony sklepu');
                }

                throw new RuntimeException('strona '.$page.' bez licznika rekordów', self::INCONSISTENT);
            }
            [$from, $to, $pageTotal] = $info;
            if ($page === 1) {
                $total = $pageTotal;
                $pageSize = $to - $from + 1;
                if ($total <= 0 || $from !== 1 || $pageSize <= 0) {
                    throw new RuntimeException('Lista produktów '.UvexB2bClient::HOST.' ma nieoczekiwany licznik: od '.$from.' do '.$to.' z '.$total);
                }
                $pages = (int) ceil($total / $pageSize);
                $this->progress('Lista produktów UVEX: '.$total.' pozycji na '.$pages.' stronach');
            } elseif ($pageTotal !== $total) {
                throw new RuntimeException('liczba produktów zmieniła się z '.$total.' na '.$pageTotal.' (strona '.$page.')', self::INCONSISTENT);
            }

            $expectedFrom = ($page - 1) * $pageSize + 1;
            $expectedTo = min($page * $pageSize, $total);
            if ($from !== $expectedFrom || $to !== $expectedTo || count($pageRows) !== $to - $from + 1) {
                throw new RuntimeException(sprintf(
                    'strona %d: licznik od %d do %d, oczekiwano od %d do %d, wierszy %d',
                    $page, $from, $to, $expectedFrom, $expectedTo, count($pageRows),
                ), self::INCONSISTENT);
            }

            foreach ($pageRows as $row) {
                if ($row['price_text'] === '') {
                    throw new RuntimeException('Strona '.$page.' listy '.UvexB2bClient::HOST.': pozycja '.$row['code'].' bez ceny konta — pobieranie przerwane (utracony widok cen konta?)');
                }
                if (isset($seen[$row['id']])) {
                    throw new RuntimeException('produkt id '.$row['id'].' ('.$row['code'].') na dwóch stronach', self::INCONSISTENT);
                }
                $seen[$row['id']] = true;
                $rows[] = $row;
            }

            if ($page > 1 && ($page % self::PROGRESS_EVERY_PAGES === 0 || $page === $pages)) {
                $this->progress('Lista produktów UVEX: strona '.$page.'/'.$pages);
            }
        }

        if (count($rows) !== $total) {
            throw new RuntimeException('pobrano '.count($rows).' z '.$total.' pozycji', self::INCONSISTENT);
        }

        return $rows;
    }

    /**
     * Ten sam kod pod kilkoma pozycjami: ta sama cena — zostaje pierwsza; różne ceny — wszystkie pomijane z powodem
     * (nie wiadomo, która cena należy do kodu).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<B2bRemoteProduct>}
     */
    private static function withoutConflictingCodes(array $rows): array
    {
        $byCode = [];
        foreach ($rows as $row) {
            $byCode[mb_strtolower($row['code'])][] = $row;
        }

        $kept = [];
        $skipped = [];
        foreach ($rows as $row) {
            $same = $byCode[mb_strtolower($row['code'])];
            if (count($same) === 1) {
                $kept[] = $row;

                continue;
            }
            if ($same[0]['id'] !== $row['id']) {
                continue;
            }
            $prices = array_unique(array_map(static fn (array $r): string => $r['price_text'], $same));
            if (count($prices) === 1) {
                $kept[] = $row;

                continue;
            }
            $skipped[] = new B2bRemoteProduct(
                remoteId: $row['code'],
                sku: $row['code'],
                name: $row['name'] !== '' ? $row['name'] : $row['code'],
                sourceUrl: $row['detail_url'] !== '' ? $row['detail_url'] : null,
                raw: [
                    'status' => 'skipped',
                    'code' => $row['code'],
                    'reason' => 'kod występuje na liście '.count($same).' razy z różnymi cenami ('.implode(', ', $prices).')',
                ],
            );
        }

        return [$kept, $skipped];
    }

    /**
     * @param  list<array{row: array<string, mixed>, size: array{size: string, expr: string|null, stem: string|null}|null}>  $group
     */
    private function productFor(array $group): B2bRemoteProduct
    {
        $first = $group[0]['row'];
        $members = array_map(static fn (array $m): array => [
            'remote_id' => (string) $m['row']['code'],
            'sku' => (string) $m['row']['code'],
            'name' => (string) $m['row']['name'],
        ], $group);
        // tylko kod i nazwa — jedyne, co czyta manufacturer(); pełne wiersze listy (1302 karty × rozmiary)
        // zostawałyby w pamięci do końca przebiegu
        $rows = array_map(static fn (array $m): array => [
            'code' => $m['row']['code'],
            'name' => $m['row']['name'],
        ], $group);

        $name = $first['name'];
        $availability = $first['availability'] !== '' ? $first['availability'] : null;
        $summary = '';
        if (count($group) > 1) {
            $name = $this->groups->groupName($first['name'], $first['code'], $group[0]['size']);
            $bySize = $group;
            usort($bySize, static fn (array $a, array $b): int => UvexSizeGroups::compareSizes($a['size']['size'], $b['size']['size']));
            $summary = 'Rozmiary: '.implode('; ', array_map(
                static fn (array $m): string => $m['size']['size'].' ('.$m['row']['code'].')',
                $bySize,
            ));
            $availability = self::groupAvailability($bySize);
        }

        return new B2bRemoteProduct(
            remoteId: $first['code'],
            sku: $first['code'],
            name: $name,
            sourceUrl: $first['detail_url'] !== '' ? $first['detail_url'] : null,
            raw: [
                'status' => 'ok',
                'code' => $first['code'],
                'price_text' => $first['price_text'],
                'unit' => $first['unit'],
                'order' => self::groupOrder($group),
                'detail_url' => $first['detail_url'],
                'image_url' => $first['image_url'],
                'rows' => $rows,
            ],
            availability: $availability,
            variantSummary: $summary,
            members: $members,
            identifiers: self::identifiers($group, self::brandOf($rows)),
        );
    }

    /**
     * Kod panelu każdej pozycji karty (codeViewProductDane, dosłownie; pozycja = ten sam kod, remote_id
     * powiązania). Sklep należy do UVEX, więc kod wyrobu UVEX jest kodem producenta; HECKEL i HexArmor sklep
     * tylko sprzedaje — ich kody („HECKEL6273/3/36”, „HA2023(L)”) to kody źródła. Rozmiar dosłownie z nazwy
     * albo kodu, tylko na karcie z rozmiarami (tam jest też w podsumowaniu rozmiarów).
     *
     * Nie podajemy: numeru modelu (jest tylko w nazwie), kodu cennika bazowego (cennik wczytuje się po liście,
     * już po zbudowaniu kart — basePrice()) ani numeru katalogowego i GTIN ze strony producenta (pobieranej
     * dopiero przy opisie albo karcie wyrobu u dostawcy).
     *
     * @param  list<array{row: array<string, mixed>, size: array{size: string, expr: string|null, stem: string|null, raw: string}|null}>  $group
     * @return list<B2bRemoteIdentifier>
     */
    private static function identifiers(array $group, string $brand): array
    {
        $type = $brand === 'UVEX' ? ProductIdentifier::TYPE_MANUFACTURER_CODE : ProductIdentifier::TYPE_SOURCE_CODE;
        $out = [];
        foreach ($group as $member) {
            $code = (string) $member['row']['code'];
            if ($code === '') {
                continue;
            }
            $out[] = new B2bRemoteIdentifier(
                type: $type,
                value: $code,
                remoteId: $code,
                label: count($group) > 1 ? ($member['size']['raw'] ?? null) : null,
                field: 'Kod',
            );
        }

        return $out;
    }

    /**
     * Warunek zamawiania karty z pól ilości jej pozycji. Wszystkie pozycje z tym samym min/step — ten warunek;
     * różne albo część nieodczytana — min i step null (warunku nie przypisujemy karcie, zapis czyści poprzedni)
     * i w by_size warunek każdego rozmiaru dosłownie, na kartę dostawcy. Żadna pozycja bez pola ilości = null
     * (źródło warunku nie podało, zapisany zostaje).
     *
     * @param  list<array{row: array<string, mixed>, size: array{size: string}|null}>  $group
     * @return array{min: float|null, step: float|null, by_size: string|null}|null
     */
    private static function groupOrder(array $group): ?array
    {
        $conditions = [];
        $read = 0;
        foreach ($group as $member) {
            $min = B2bOrderQuantity::attribute((string) ($member['row']['order_min'] ?? ''));
            $step = B2bOrderQuantity::attribute((string) ($member['row']['order_step'] ?? ''));
            if ($min !== null) {
                $read++;
            }
            $label = $min === null ? 'nieodczytane' : self::orderLabel($min, $step, '');
            $conditions[$label][] = $member['size']['size'] ?? (string) $member['row']['code'];
        }
        if ($read === 0) {
            return null;
        }
        if (count($conditions) === 1 && $read === count($group)) {
            $row = $group[0]['row'];

            return [
                'min' => B2bOrderQuantity::attribute((string) $row['order_min']),
                'step' => B2bOrderQuantity::attribute((string) $row['order_step']),
                'by_size' => null,
            ];
        }
        $parts = [];
        foreach ($conditions as $label => $sizes) {
            $parts[] = $label.': '.implode(', ', $sizes);
        }

        return ['min' => null, 'step' => null, 'by_size' => implode('; ', $parts)];
    }

    /** „po 10 szt.”, „min. 5 szt., po 5 szt.”, „bez ograniczeń” — do karty dostawcy i opisu różnic rozmiarów. */
    private static function orderLabel(float $min, ?float $step, string $unit): string
    {
        $unit = $unit !== '' ? ' '.$unit : '';
        if ($step !== null && $step > 1 && abs($min - $step) < 0.0001) {
            return 'po '.B2bOrderQuantity::format($step).$unit;
        }
        $parts = [];
        if ($min > 1) {
            $parts[] = 'min. '.B2bOrderQuantity::format($min).$unit;
        }
        if ($step !== null && $step > 1) {
            $parts[] = 'po '.B2bOrderQuantity::format($step).$unit;
        }

        return $parts !== [] ? implode(', ', $parts) : 'bez ograniczeń';
    }

    /** Warunek zamawiania do slotu ceny; null = lista nie podała pola ilości. */
    private static function orderQuantity(B2bRemoteProduct $product): ?B2bOrderQuantity
    {
        $order = $product->raw['order'] ?? null;
        if (! is_array($order)) {
            return null;
        }
        $unit = trim((string) ($product->raw['unit'] ?? ''));

        return new B2bOrderQuantity($order['min'], $order['step'], $unit !== '' ? $unit : null, varies: $order['by_size'] !== null);
    }

    /**
     * Jedna dostępność dla wszystkich rozmiarów — dosłownie; różne — „Dostępny: 39, 41; Na zamówienie: 40, 42”.
     *
     * @param  list<array{row: array<string, mixed>, size: array{size: string}}>  $bySize
     */
    private static function groupAvailability(array $bySize): ?string
    {
        $statuses = [];
        foreach ($bySize as $member) {
            $status = (string) $member['row']['availability'];
            $statuses[$status !== '' ? $status : 'brak informacji'][] = $member['size']['size'];
        }
        if (count($statuses) === 1) {
            $only = (string) array_key_first($statuses);

            return $only === 'brak informacji' ? null : $only;
        }
        $parts = [];
        foreach ($statuses as $status => $sizes) {
            $parts[] = $status.': '.implode(', ', $sizes);
        }

        return implode('; ', $parts);
    }

    /**
     * Wiersze listy: id pozycji z klasy odnośnika „urlViewProductCart_{id}”, każde pole z elementu z klasą albo id
     * z tym samym numerem. Nie przez <tr>: wiersz ma formularz koszyka w komórce tabeli, a parser HTML (libxml)
     * przestawia takie zagnieżdżenie — odnośniki pojawiają się podwójnie i poza swoim wierszem. Pozycja = unikalne id.
     *
     * Pole ilości koszyka niesie warunek zamawiania: min="10.0000" step="10.0000" = tylko po 10 szt., min="1" step="any"
     * = bez ograniczenia (sprawdzone na koncie 24.09.2026: 9307.375 po 10, 9169.543 po 8). Atrybuty dosłownie.
     *
     * @return list<array{id: string, code: string, name: string, price_text: string, price_cents: int|null, availability: string, unit: string, order_min: string, order_step: string, detail_url: string, image_url: string, page: int}>
     */
    private static function parseRows(DOMXPath $xpath, int $page): array
    {
        $rows = [];
        foreach ($xpath->query('//a[starts-with(@class, "urlViewProductCart_")]') ?: [] as $link) {
            if (! $link instanceof DOMElement || preg_match('/^urlViewProductCart_(\d+)$/', trim($link->getAttribute('class')), $m) !== 1) {
                continue;
            }
            $id = $m[1];
            if (isset($rows[$id])) {
                continue;
            }
            $byClass = static fn (string $class): string => '//*['.JspB2bClient::classPredicate($class.$id).']';
            $codeNode = $xpath->query($byClass('codeViewProductDane_'))->item(0);
            // nazwa: pierwszy <span> komórki z klasą urlViewProductCart_{id}, w której jest kod
            $nameNode = $codeNode !== null
                ? $xpath->query('ancestor::*['.JspB2bClient::classPredicate('urlViewProductCart_'.$id).'][1]/span[1]', $codeNode)->item(0)
                : null;
            $priceText = self::text($xpath->query($byClass('twojaCenaNetto_'))->item(0));
            $image = $xpath->query('//*[@id="B2B_fotorama-'.$id.'"]//a[@data-full]')->item(0);
            $quantity = $xpath->query($byClass('panelDodawaniaProduktuDoKoszyka_').'//input[@name="quantity"]')->item(0);

            $rows[$id] = [
                'id' => $id,
                'code' => self::text($codeNode),
                'name' => self::text($nameNode),
                'price_text' => $priceText,
                'price_cents' => self::priceCents($priceText),
                'availability' => self::text($xpath->query($byClass('stanViewProductDane_'))->item(0)),
                'unit' => self::text($xpath->query($byClass('panelDodawaniaProduktuDoKoszyka_').'//*['.JspB2bClient::classPredicate('input-group-addon').']')->item(0)),
                'order_min' => $quantity instanceof DOMElement ? trim($quantity->getAttribute('min')) : '',
                'order_step' => $quantity instanceof DOMElement ? trim($quantity->getAttribute('step')) : '',
                'detail_url' => UvexB2bClient::isShopUrl($link->getAttribute('href')) ? $link->getAttribute('href') : '',
                'image_url' => $image instanceof DOMElement ? trim($image->getAttribute('data-full')) : '',
                'page' => $page,
            ];
        }

        return array_values($rows);
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function paginationInfo(DOMXPath $xpath): ?array
    {
        $text = self::text($xpath->query('//*['.JspB2bClient::classPredicate('pagination-info').']')->item(0));
        if (preg_match('/od\s+(\d+)\s+do\s+(\d+)\s+z\s+(\d+)/u', $text, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    /**
     * Element → linie tekstu: nowa linia przy <br> i elementach blokowych, pozycja listy jako „- …”. Białe znaki
     * zwinięte, puste linie pominięte.
     *
     * @return list<string>
     */
    private static function blockLines(DOMNode $root): array
    {
        $lines = [];
        $buffer = '';
        $flush = static function () use (&$lines, &$buffer): void {
            $line = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $buffer));
            if ($line !== '' && $line !== '-') {
                $lines[] = $line;
            }
            $buffer = '';
        };
        $walk = static function (DOMNode $node) use (&$walk, &$buffer, $flush): void {
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                    $buffer .= $child->nodeValue;

                    continue;
                }
                if (! $child instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($child->nodeName);
                if (in_array($tag, self::SKIPPED_TAGS, true)) {
                    continue;
                }
                if ($tag === 'br') {
                    $flush();
                } elseif ($tag === 'li') {
                    $flush();
                    $buffer = '- ';
                    $walk($child);
                    $flush();
                } elseif (in_array($tag, self::BLOCK_TAGS, true)) {
                    $flush();
                    $walk($child);
                    $flush();
                } else {
                    $walk($child);
                }
            }
        };
        $walk($root);
        $flush();

        return $lines;
    }

    /**
     * Strona produktu pierwszej pozycji karty, pobrana raz na kartę (opis i pliki). Strona z innym kodem niż lista
     * = wyjątek (opis i pliki innego produktu nie mogą trafić na kartę). null = pozycja bez strony produktu.
     */
    private function productXpath(B2bRemoteProduct $product): ?DOMXPath
    {
        $url = (string) ($product->raw['detail_url'] ?? '');
        if (($product->raw['status'] ?? null) !== 'ok' || $url === '') {
            return null;
        }

        if ($this->pageUrl !== $url) {
            $this->pageHtml = $this->client->productPage($url);
            $this->pageUrl = $url;
            // plik, znacznik języka i strona producenta poprzedniej karty nie są już potrzebne
            $this->file = null;
            $this->foreignDescriptionFor = null;
            $this->manufacturer = null;
        }

        $xpath = JspB2bClient::dom((string) $this->pageHtml);
        $shownCode = self::text($xpath->query('//td['.JspB2bClient::classPredicate('codeViewProductDane').']')->item(0));
        $code = (string) ($product->raw['code'] ?? '');
        if ($shownCode === '') {
            throw new RuntimeException('brak kodu na stronie produktu '.$code);
        }
        if ($shownCode !== $code) {
            throw new RuntimeException('kod na stronie produktu ('.$shownCode.') inny niż kod z listy ('.$code.')');
        }

        return $xpath;
    }

    /**
     * Strona producenta dla karty: najpierw ta, do której odsyła sklep. O przynależności decyduje numer
     * katalogowy widoczny na stronie („Order number: …”) — adres bywa samym opisowym skrótem nazwy
     * (…/accessories/cushion-frame-with-lip-seal), więc po nim samym poznać wyrobu nie sposób. Strona z cudzym
     * numerem albo bez numeru i o niezgodnym adresie = szukanie po numerze katalogowym w sklepie producenta.
     *
     * @return array{url: string, html: string}|null null = nie znaleziono karty tego wyrobu
     */
    private function manufacturerPageFor(string $url, string $code): ?array
    {
        $slug = basename((string) parse_url($url, PHP_URL_PATH));
        if (self::looksLikeCode($slug) && ! self::sameProduct($slug, $code) && ! self::sameProduct(self::numberParam($url), $code)) {
            // adres jest numerem katalogowym innego wyrobu — strony nie pobieramy, od razu szukamy po numerze karty
            return $this->manufacturerPageByCode($url, $code);
        }

        $html = $this->client->manufacturerPage($url);
        if (self::pageBelongsTo($html, $url, $code)) {
            return ['url' => $url, 'html' => $html];
        }

        return $this->manufacturerPageByCode($url, $code);
    }

    /**
     * Sklep bywa nierzetelny i odsyła kartę pod adres innego wyrobu — właściwej strony szukamy wtedy sami,
     * po numerze katalogowym, w wyszukiwarce tego samego sklepu producenta. Przy jednym trafieniu sklep
     * przekierowuje wprost na kartę, inaczej wybieramy z listy wynik o zgodnym numerze.
     *
     * @return array{url: string, html: string}|null null = nie znaleziono karty tego wyrobu
     */
    private function manufacturerPageByCode(string $linkUrl, string $code): ?array
    {
        $host = (string) parse_url($linkUrl, PHP_URL_HOST);
        if ($host === '' || $code === '') {
            return null;
        }

        $search = $this->client->manufacturerSearch('https://'.$host.'/en/search?search='.rawurlencode($code));
        if (self::pageBelongsTo($search['html'], $search['url'], $code)) {
            $this->foundByCode++;

            return $search;
        }

        $found = self::productLinkFor(JspB2bClient::dom($search['html']), $code);
        if ($found === null) {
            return null;
        }
        $html = $this->client->manufacturerPage($found);
        if (! self::pageBelongsTo($html, $found, $code)) {
            return null;
        }
        $this->foundByCode++;

        return ['url' => $found, 'html' => $html];
    }

    /**
     * Pierwszy odnośnik z listy wyników prowadzący do karty o tym numerze katalogowym (bez plików) — numer jest
     * w adresie albo w parametrze „number”, którym sklep wybiera wariant wyrobu.
     */
    private static function productLinkFor(DOMXPath $results, string $code): ?string
    {
        foreach ($results->query('//a[@href]') ?: [] as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $href = trim(html_entity_decode($link->getAttribute('href'), ENT_QUOTES | ENT_HTML5));
            $path = (string) parse_url($href, PHP_URL_PATH);
            if ($path === '' || str_ends_with(mb_strtolower($path), '.pdf')) {
                continue;
            }
            if (! UvexB2bClient::isManufacturerUrl($href)) {
                continue;
            }
            if (self::sameProduct(basename($path), $code) || self::sameProduct(self::numberParam($href), $code)) {
                return $href;
            }
        }

        return null;
    }

    /**
     * Czy ta strona producenta opisuje wyrób karty. Rozstrzyga numer katalogowy wypisany na stronie
     * („Order number: A14LIPSE1000” / „Bestellnummer: …”) — to jedyne miejsce, w którym sklep sam mówi, jaki
     * wyrób pokazuje. Strona bez numeru (skrócona, inna budowa) — jak dotąd po adresie.
     */
    private static function pageBelongsTo(string $html, string $url, string $code): bool
    {
        $shown = self::orderNumber($html);
        if ($shown !== null) {
            return self::sameProduct($shown, $code);
        }

        return self::sameProduct(basename((string) parse_url($url, PHP_URL_PATH)), $code)
            || self::sameProduct(self::numberParam($url), $code);
    }

    /** Numer katalogowy wypisany na stronie producenta; null = strona go nie podaje. */
    public static function orderNumber(string $html): ?string
    {
        // skrypty strony zostają po strip_tags — a sklep wstawia w nich cały koszyk jako JSON, z cudzymi numerami
        $body = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        // znacznik rozdziela tekst: bez spacji „…9970.005</li><li>GTIN…” dałoby numer „9970.005GTIN”
        $body = (string) preg_replace('#<[^>]*>#', ' ', $body);
        $text = (string) preg_replace('/[\s\x{00A0}]+/u', ' ', strip_tags($body));
        foreach (self::ORDER_NUMBER_LABELS as $label) {
            if (preg_match('/'.preg_quote($label, '/').'\s*:?\s*([\p{L}\p{N}][\p{L}\p{N}.\/_-]*)/ui', $text, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Czy ostatni człon adresu jest numerem katalogowym (…/000P1P102001, …/9970.005), a nie skrótem nazwy
     * (…/cushion-frame-with-lip-seal). Numer innego wyrobu w adresie wystarczy, żeby strony nie pobierać;
     * po skrócie nazwy nie widać, jaki to wyrób — to mówi dopiero numer wypisany na stronie.
     */
    private static function looksLikeCode(string $segment): bool
    {
        return $segment !== '' && preg_match('/[a-z]{3,}-[a-z]{3,}/i', $segment) !== 1;
    }

    /** Wariant wyrobu wybrany parametrem „number” adresu (sklep producenta); '' = adres go nie ma. */
    private static function numberParam(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return is_string($query['number'] ?? null) ? $query['number'] : '';
    }

    /**
     * Czy strona producenta opisuje ten sam wyrób co karta: ten sam numer katalogowy albo wspólny początek
     * numeru (wariant i rozmiar mogą się różnić, oznaczenie wyrobu nie).
     */
    public static function sameProduct(string $pageCode, string $cardCode): bool
    {
        $page = mb_strtolower((string) preg_replace('/[^a-z0-9]/i', '', $pageCode));
        $card = mb_strtolower((string) preg_replace('/[^a-z0-9]/i', '', $cardCode));
        if ($page === '' || $card === '') {
            return false;
        }
        if ($page === $card) {
            return true;
        }

        $length = min(self::MANUFACTURER_CODE_PREFIX, mb_strlen($page), mb_strlen($card));

        // krótkie numery muszą się zgadzać w całości — sam początek nie mówi jeszcze, że to ten sam wyrób
        return $length >= 6 && mb_substr($page, 0, $length) === mb_substr($card, 0, $length);
    }

    /**
     * Tabela „Specifications” ze strony producenta (powłoka, materiał filtra, klasa ochrony i normy) jako pary
     * nazwa→wartość, dosłownie ze strony (etykieta bez końcowego dwukropka).
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function specificationPairs(DOMXPath $page): array
    {
        $pairs = [];
        foreach ($page->query('//table[contains(@class, "product-detail-properties-table")]//tr') ?: [] as $row) {
            $cells = $page->query('.//th|.//td', $row);
            $label = rtrim(self::text($cells->item(0)), ':');
            $value = self::text($cells->item(1));
            if ($label === '' || $value === '') {
                continue;
            }
            $pairs[] = [$label, $value];
        }

        return $pairs;
    }

    /**
     * Dwa opisy tego samego wyrobu (wstęp przy cenie i tekst z zakładki) obok siebie — chyba że jeden zawiera
     * się w drugim; wtedy zostaje ten dłuższy, żeby karta nie miała dwa razy tego samego akapitu.
     *
     * @param  list<string>  $intro
     * @param  list<string>  $description
     * @return list<string>
     */
    private static function withoutRepeatedText(array $intro, array $description): array
    {
        if ($intro === []) {
            return $description;
        }
        if ($description === []) {
            return $intro;
        }

        $plain = static fn (array $lines): string => mb_strtolower(
            (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', implode(' ', $lines))
        );
        $first = trim($plain($intro));
        $second = trim($plain($description));
        if (str_contains($second, $first)) {
            return $description;
        }
        if (str_contains($first, $second)) {
            return $intro;
        }

        return [...$intro, ...$description];
    }

    /**
     * Boczny blok „Protection range” jako pary nazwa zakresu → zdanie z granicami widma („between 180 and
     * 400nm”), dosłownie ze strony. Tabela „Specifications” podaje same nazwy zakresów, więc granice są tu
     * jedyną liczbą — a przy doborze do przetargu liczą się właśnie one.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function protectionRangePairs(DOMXPath $page): array
    {
        $pairs = [];
        foreach ($page->query('//*['.JspB2bClient::classPredicate('protectionrange-information-container').']') ?: [] as $row) {
            $title = self::text($page->query('.//*['.JspB2bClient::classPredicate('uvex-protectionrange-title').']', $row)->item(0));
            $whole = self::text($row);
            $detail = $title !== '' && str_starts_with($whole, $title)
                ? trim(mb_substr($whole, mb_strlen($title)))
                : $whole;
            if ($title === '' || $detail === '') {
                continue;
            }
            $pairs[] = [$title, $detail];
            if (count($pairs) >= self::PROTECTION_RANGE_LIMIT) {
                break;
            }
        }

        return $pairs;
    }

    /**
     * Zakładka „Protection Level”: długość fali, gęstość optyczna i badany stopień ochrony — przy doborze do
     * przetargu to najważniejsza tabela. Wiersze zostają dosłownie (kody i liczby), kolumny sklejone „|”.
     *
     * @return list<string>
     */
    private static function protectionLines(DOMXPath $page): array
    {
        $lines = [];
        foreach ($page->query('//*[@id="protection-levels-tab-pane"]//tr') ?: [] as $row) {
            $cells = [];
            foreach ($page->query('.//th|.//td', $row) ?: [] as $cell) {
                $text = self::text($cell);
                if ($text === '') {
                    continue;
                }
                if (strtolower($cell->nodeName) === 'th') {
                    $text = self::PROTECTION_HEADINGS[mb_strtolower($text)] ?? $text;
                }
                $cells[] = $text;
            }
            if (count($cells) < 2) {
                continue;
            }

            // Kolumny rozdzielone „|” — panel składa z takich wierszy prawdziwą tabelę na karcie
            $lines[] = implode(' | ', $cells);
            if (count($lines) >= self::PROTECTION_ROWS_LIMIT) {
                break;
            }
        }

        return $lines;
    }

    /** <base href> strony; bez niego adresy z tabeli plików są względne wobec /public/. */
    private static function baseUrl(DOMXPath $xpath): string
    {
        $base = $xpath->query('//base[@href]')->item(0);
        $href = $base instanceof DOMElement ? trim($base->getAttribute('href')) : '';

        return $href !== '' ? $href : UvexB2bClient::BASE.'/public/';
    }

    /**
     * Adres pliku z tabeli (względny wobec <base>) → pełny adres sklepu; null = adres spoza sklepu albo pusty.
     * Nazwy plików mają spacje i polskie znaki — segmenty ścieżki kodujemy raz (dekodowanie przed kodowaniem).
     */
    public static function fileUrl(string $base, string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        $url = preg_match('#^https?://#i', $href) === 1 ? $href : rtrim($base, '/').'/'.ltrim($href, '/');
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return null;
        }
        $path = implode('/', array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', $parts['path']),
        ));
        $url = $parts['scheme'].'://'.$parts['host'].$path.(isset($parts['query']) ? '?'.$parts['query'] : '');

        return UvexB2bClient::isShopUrl($url) ? $url : null;
    }

    private static function isPdfUrl(string $url): bool
    {
        return str_ends_with(mb_strtolower(rawurldecode((string) parse_url($url, PHP_URL_PATH))), '.pdf');
    }

    private function progress(string $message): void
    {
        if ($this->listProgress !== null) {
            ($this->listProgress)($message);
        }
    }

    private static function text(?DOMNode $node): string
    {
        return $node === null ? '' : trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $node->textContent));
    }
}
