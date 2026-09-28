<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Models\ProductShopCard;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * Sklep MAVIBO mavibo.pl (PrestaShop 1.7) — dystrybutor odzieży reklamowej i roboczej marek PROMOSTARS, CRIMSON CUT,
 * GEFFER, MARK THE HELPER i P&M. Sprawdzone na koncie 23.09.2026: 238 modeli, każdy w kombinacjach kolor × rozmiar.
 *
 * Lista: JSON kategorii głównej (MaviboB2bClient::listPage), 100 pozycji na stronę, z kontrolą spójności jak
 * u JHK (ta sama liczba pozycji i stron na każdej stronie, unikalne wyroby; zmiana w trakcie = jedno ponowne
 * pobranie). Potem strona każdego modelu — raz na model.
 *
 * Strona modelu ma w atrybucie data-product (#product-details) JSON wyrobu PrestaShop (nazwa, model, marka,
 * kategoria, opisy, załączniki) i tabelę WSZYSTKICH kombinacji (moduł tablecombz): indeks, kolor, rozmiar, cena
 * netto, cena brutto i dostępność. Kolumny czytamy po nagłówkach, nie po kolejności. Ceny: tabela zalogowanego
 * konta podaje cenę po rabacie grupy klienta (cena zakupu), a ceny przed rabatem nie pokazuje wcale — tę samą
 * stronę pobieramy drugi raz bez logowania i cena netto gościa jest ceną katalogową. Cena katalogowa kombinacji niższa
 * od jej ceny konta albo kombinacja, której strona gościa nie zna, to rozmiar bez ceny katalogowej (liczone
 * w podsumowaniu) — ceny innej kombinacji nie przepisujemy.
 *
 * Karta = model (strona wyrobu) ze wszystkimi kombinacjami kolor × rozmiar z ceną konta (decyzja użytkownika
 * 28.09.2026 wieczorem: kolory jednego modelu to jedna karta z tabelą wariantów, jak rozmiary w różnych cenach; tu
 * typowo XS–XXL w jednej cenie, 3XL i większe drożej, biały bywa tańszy od kolorów). Łączymy tylko to, co sklep sam
 * podaje jako jeden wyrób: kombinacje jednej strony, których jedyną cechą poza rozmiarem jest „Kolor” (niepusty),
 * bez powtórzonej pary kolor–rozmiar; inaczej (inna cecha w tabeli, kombinacja bez koloru) karta na kolor jak dotąd
 * (liczone w podsumowaniu). Model w jednym kolorze — karta jak dotąd („…, kolor 21”, SKU z indeksu koloru).
 * Do 28.09.2026 karta była kolorem modelu, a jeszcze wcześniej (decyzja 15.09.2026) rozmiar w innej cenie był osobną
 * kartą („51005_26_3XL”) — takie karty zostają, dopóki nie scali ich „Scal rozmiary” (synchronizacja daje każdej jej
 * pozycje, B2bCatalogSync::syncMembersByCard; zdjęcia i pliki tylko jej kolorów — imageUrls/documents liczą je
 * z pozycji produktu).
 * Pozycje (members) i remote_id = klucz kombinacji ze sklepu „{id wyrobu}_{id kombinacji}” (np. „368_6076”) — indeksy
 * sklepu nie nadają się na klucz: bywają puste („—”) i niespójne w jednym modelu („21172 20 XS” obok „21172_20_S”) —
 * z etykietą wiersza, dostępnością i ceną kombinacji (konta i katalogowa); cena karty = najniższa cena kombinacji
 * (raw['price'], price()), pozostałe ceny — wiersze wariantów karty. Etykieta wiersza karty z kolorami: „kolor 21 / S”
 * (wyrób bez rozmiaru — „kolor 21”); sklep nazywa kolory tylko numerami (także w JSON-ie listy i data-product,
 * sprawdzone 28.09.2026), więc nazwy barwy nie dopisujemy — próbka koloru (#hex) to nie nazwa. Karta jednego koloru:
 * etykieta = rozmiar, jak dotąd. Sklep podaje ceny tylko w zł (inny zapis = kombinacja bez ceny) i nie podaje
 * jednostki sprzedaży przy kombinacji. Promocja sklepu jest opisem karty (tabelka sklepu): wspólna dla wszystkich
 * kombinacji — jak dotąd, różna — wypisana przy wierszach, których dotyczy.
 * SKU karty z kolorami: model z data-product („51005” — kod rodziny bez koloru i rozmiaru), zajęty w przebiegu albo
 * pusty — „MAVIBO {id wyrobu}”; nazwa nowej karty bez koloru (cardName, „PROMOSTARS NIMBO 51005”), nazwa ze źródła =
 * nazwa pierwszego koloru jak dotąd. SKU karty jednego koloru: indeks bez rozmiaru („51005_21”), gdy wszystkie
 * rozmiary koloru dają ten sam (różnica tylko spacja/podkreślnik to ten sam indeks); inaczej indeks pierwszego
 * rozmiaru karty; kombinacja bez indeksu — „MAVIBO {klucz kombinacji}”.
 *
 * Opis: pole „description_short” (gramatura, skład) i „description” (opis z tabelą wymiarów) dosłownie, bez
 * obrazków (logo marki, ikony). Pliki: załączniki wyrobu („KARTA PRODUKTU 51005 26” — PDF albo JPG); plik przypisany
 * kolorowi (numer koloru na końcu nazwy albo w nazwie pliku) trafia tylko na karty z tym kolorem. Zdjęcia: galeria
 * kombinacji z okna podglądu tabeli (osobna dla każdego koloru); karta z kolorami — tylko pierwsze (główne) zdjęcie
 * każdego koloru, jedno na kolor (decyzja właściciela 28.09.2026). Sklep nie podaje norm ani certyfikatów — łącznik
 * ich nie dopisuje.
 *
 * Producent: marka wyrobu ze sklepu (manufacturer_name); wyrób bez marki — „MAVIBO” (liczone w podsumowaniu).
 */
final class MaviboB2bConnector implements B2bConnector, B2bDocumentSource, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bRunSummaryAware, B2bShopFieldSource, B2bSizePriceSource
{
    /** Producent przyjęty dla wyrobu, któremu sklep nie podał marki. */
    public const FALLBACK_BRAND = 'MAVIBO';

    private const INCONSISTENT = 7401;

    private const DOCUMENTS_LIMIT = 8;

    private const IMAGES_LIMIT = 8;

    private const DESCRIPTION_LIMIT = 10000;

    /** Nagłówki kolumn tabeli kombinacji (tablecombz) dosłownie ze sklepu. */
    private const COLUMN_REFERENCE = 'indeks';

    private const COLUMN_NET = 'cena netto';

    private const COLUMN_STOCK = 'dostępny';

    private const ATTRIBUTE_COLOR = 'kolor';

    private const ATTRIBUTE_SIZE = 'rozmiar';

    /** Indeks pusty w tabeli. */
    private const NO_REFERENCE = '—';

    private const SHOP_SECTION_MARKS = 'Oznaczenia';

    private const SHOP_SECTION_FEATURES = 'Cechy';

    private const SHOP_SECTION_TRADE = 'Informacje handlowe';

    private const SKIPPED_TAGS = ['input', 'button', 'select', 'option', 'textarea', 'img', 'iframe', 'svg', 'script', 'style', 'noscript'];

    private const BLOCK_TAGS = ['p', 'div', 'section', 'article', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tr', 'caption', 'blockquote', 'pre', 'hr'];

    private int $total = 0;

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> kombinacje (indeks albo klucz) bez ceny katalogowej */
    private array $withoutBase = [];

    /** @var list<string> kombinacje bez ceny konta (poza kartami) */
    private array $sizesWithoutPrice = [];

    /** @var array<string, true> karty z producentem FALLBACK_BRAND */
    private array $withoutBrand = [];

    /** @var array<string, true> SKU wydane w tym przebiegu (małymi literami) */
    private array $skus = [];

    private int $cards = 0;

    /** kolory z rozmiarami w różnych cenach (jedna karta, cena od najniższej) */
    private int $multiPriceProducts = 0;

    /** modele w kilku kolorach jako jedna karta i liczba ich kolorów */
    private int $colourModels = 0;

    private int $colourCount = 0;

    /** @var list<string> modele w kilku kolorach zostawione jako karty kolorów (nazwa i powód) */
    private array $colourKept = [];

    private int $skippedProducts = 0;

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public function __construct(private readonly MaviboB2bClient $client) {}

    public static function key(): string
    {
        return 'mavibo';
    }

    public static function label(): string
    {
        return 'MAVIBO';
    }

    public static function host(): string
    {
        return MaviboB2bClient::HOST;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new MaviboB2bClient(
            (string) $account->username,
            (string) $account->password,
            $delayMs,
        ));
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
        $this->withoutBase = [];
        $this->sizesWithoutPrice = [];
        $this->withoutBrand = [];
        $this->skus = [];
        $this->cards = 0;
        $this->multiPriceProducts = 0;
        $this->colourModels = 0;
        $this->colourCount = 0;
        $this->colourKept = [];
        $this->skippedProducts = 0;

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }

        $rows = $this->listRows();
        $this->total = count($rows);
        $this->summary[] = 'Lista MAVIBO: '.count($rows).' modeli';

        foreach ($rows as $row) {
            $products = $this->productsFor($row);
            // model w kilku kolorach to kilka kart — licznik postępu rośnie, zanim je wydamy
            $this->total += max(0, count($products) - 1);
            yield from $products;
        }
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    public function runSummary(): array
    {
        $lines = $this->summary;
        $lines[] = sprintf(
            'Karty: %d (%d kolorów z rozmiarami w różnych cenach — jedna karta, cena karty = najniższa cena rozmiaru, ceny rozmiarów w tabeli rozmiarów karty; %d modeli pominiętych)',
            $this->cards,
            $this->multiPriceProducts,
            $this->skippedProducts,
        );
        if ($this->colourModels > 0) {
            $lines[] = 'Modele w kilku kolorach — jedna karta z tabelą kolor × rozmiar: '.$this->colourModels.' ('.$this->colourCount.' kolorów)';
        }
        if ($this->colourKept !== []) {
            $lines[] = 'Modele w kilku kolorach zostawione jako karty kolorów: '.self::listing($this->colourKept);
        }
        if ($this->withoutBrand !== []) {
            $lines[] = 'Bez marki w sklepie — producent przyjęty jako '.self::FALLBACK_BRAND.': '.count($this->withoutBrand);
        }
        if ($this->withoutBase !== []) {
            $lines[] = 'Bez ceny katalogowej (strona gościa nie podała ceny kombinacji albo podała niższą od ceny konta): '.self::listing($this->withoutBase);
        }
        if ($this->sizesWithoutPrice !== []) {
            $lines[] = 'Kombinacje bez ceny konta (poza kartami): '.self::listing($this->sizesWithoutPrice);
        }

        return $lines;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        $brand = trim((string) ($product->raw['brand'] ?? ''));
        if ($brand === '') {
            $this->withoutBrand[$product->remoteId] = true;

            return self::FALLBACK_BRAND;
        }

        return $brand;
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'strona wyrobu nieodczytana'));
        }
        $base = $product->raw['base_price'] ?? null;

        return self::accountPrice((float) $product->raw['price'], is_float($base) ? $base : null);
    }

    /** Cena konta z ceną katalogową (gdy jest) i rabatem wyliczonym z obu — karta i każda kombinacja tak samo. */
    private static function accountPrice(float $net, ?float $base): B2bRemotePrice
    {
        return new B2bRemotePrice(
            net: round($net, 2),
            base: $base !== null ? round($base, 2) : null,
            discountPercent: $base !== null && $base > 0 ? round((1 - $net / $base) * 100, 2) : 0.0,
        );
    }

    /**
     * Najtańsza kombinacja: najniższa cena konta; przy remisie reguła B2bCatalogSync::winsSizePriceTie (wygrywa
     * kombinacja ze znaną ceną katalogową, z dwóch znanych niższa), dalej pierwsza w tabeli — tak samo jak w silniku,
     * żeby price() i cena karty z pozycji się zgadzały.
     *
     * @param  non-empty-list<array<string, mixed>>  $group
     * @return array<string, mixed>
     */
    private static function cheapest(array $group): array
    {
        $best = $group[0];
        foreach ($group as $combination) {
            if ($combination['cents'] < $best['cents']
                || ($combination['cents'] === $best['cents'] && B2bCatalogSync::winsSizePriceTie(
                    $combination['base'] !== null ? $combination['base'] / 100 : null,
                    $best['base'] !== null ? $best['base'] / 100 : null,
                ))) {
                $best = $combination;
            }
        }

        return $best;
    }

    /** Opis ze sklepu dosłownie (krótki i pełny, bez obrazków); '' gdy strona opisu nie ma. */
    public function description(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['description'] ?? '');
    }

    /**
     * Model, indeksy kombinacji, kolor (tylko karta jednego koloru), cechy wyrobu, kategoria, VAT i dostępność
     * kombinacji — wszystko z raz pobranej strony; metoda niczego nie pobiera.
     *
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        $raw = $product->raw;
        if (($raw['status'] ?? null) !== 'ok') {
            return [];
        }

        $fields = [];
        $add = static function (string $section, string $name, string $value) use (&$fields): void {
            if ($name !== '' && $value !== '') {
                $fields[] = new B2bRemoteShopField($section, $name, $value);
            }
        };

        $add(self::SHOP_SECTION_MARKS, 'Model', (string) ($raw['model'] ?? ''));
        $add(self::SHOP_SECTION_MARKS, 'Indeks', (string) ($raw['references'] ?? ''));
        foreach ($raw['attributes'] ?? [] as [$label, $value]) {
            $add(self::SHOP_SECTION_MARKS, $label, $value);
        }
        $add(self::SHOP_SECTION_MARKS, 'Próbka koloru', (string) ($raw['color_hex'] ?? ''));
        foreach ($raw['features'] ?? [] as [$label, $value]) {
            $add(self::SHOP_SECTION_FEATURES, $label, $value);
        }
        $add(self::SHOP_SECTION_TRADE, 'Kategoria w sklepie', (string) ($raw['category'] ?? ''));
        $add(self::SHOP_SECTION_TRADE, 'VAT', (string) ($raw['vat'] ?? ''));
        $add(self::SHOP_SECTION_TRADE, 'Dostępność', implode('; ', $raw['stock'] ?? []));
        $add(self::SHOP_SECTION_TRADE, 'Promocja w sklepie', (string) ($raw['promotion'] ?? ''));

        return $fields;
    }

    /**
     * Załączniki wyrobu bez koloru i przypisane kolorom pozycji tego produktu (productColours) — dawna karta koloru
     * dostaje od synchronizacji tylko swoje pozycje, więc nie dostaje pliku innego koloru.
     *
     * @return list<B2bRemoteDocument>
     */
    public function documents(B2bRemoteProduct $product): array
    {
        $colours = self::productColours($product);
        $documents = [];
        foreach ($product->raw['documents'] ?? [] as $file) {
            $named = $file['colours'] ?? [];
            if ($named !== [] && $colours !== null && array_intersect($named, $colours) === []) {
                continue;
            }
            $documents[] = new B2bRemoteDocument($file['title'], $file['url'], $file['kind']);
        }

        return array_slice($documents, 0, self::DOCUMENTS_LIMIT);
    }

    /**
     * Kolory (małymi literami) pozycji, które opisuje ten produkt: members, bez nich — pozycja remoteId, w kolejności
     * pozycji. Liczone z produktu, nie z całej strony (B2bCatalogSync::syncMembersByCard podaje dawnej karcie tylko jej
     * pozycje). null — kolor którejś pozycji nieznany: bez filtrowania.
     *
     * @return list<string>|null
     */
    private static function productColours(B2bRemoteProduct $product): ?array
    {
        $known = $product->raw['member_colours'] ?? [];
        $colours = [];
        foreach (self::productPositions($product) as $id) {
            if (! isset($known[$id])) {
                return null;
            }
            $colours[$known[$id]] = true;
        }

        return array_map('strval', array_keys($colours));
    }

    /**
     * Klucze kombinacji produktu: members, bez nich — remoteId.
     *
     * @return list<string>
     */
    private static function productPositions(B2bRemoteProduct $product): array
    {
        return $product->members !== []
            ? array_map(static fn (array $member): string => (string) ($member['remote_id'] ?? ''), $product->members)
            : [$product->remoteId];
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    public function documentBytes(B2bRemoteDocument $document): array
    {
        $file = $this->client->fileBytes($document->sourceUrl, 'pliku wyrobu');
        if (str_starts_with($file['bytes'], '%PDF-')) {
            $file['mime'] = 'application/pdf';
        }

        return $file;
    }

    /**
     * Galerie kombinacji tego produktu (productPositions — dawna karta koloru tylko swoje), bez powtórzeń. Jeden
     * kolor — galerie w kolejności pozycji (kolor ma zwykle jedną wspólną galerię), najwyżej IMAGES_LIMIT. Kilka
     * kolorów (decyzja właściciela 28.09.2026: karta z kolorami ma dokładnie jedno zdjęcie na kolor) — tylko pierwsze
     * (główne) zdjęcie każdego koloru, w kolejności wierszy (kolor prowadzący pierwszy), bez powtórzeń i bez limitu:
     * B2bCatalogSync::storeGallery pobiera każdy podany adres, którego karta nie ma, więc reszta galerii wracałaby
     * co przebieg. Kolor pozycji nieznany (brak w member_colours) liczy się jako osobny kolor.
     *
     * @return list<string>
     */
    public function imageUrls(B2bRemoteProduct $product): array
    {
        $images = $product->raw['member_images'] ?? [];
        $colours = $product->raw['member_colours'] ?? [];
        /** @var array<string, array<string, true>> $galleries kolory w kolejności wierszy, także bez zdjęć */
        $galleries = [];
        foreach (self::productPositions($product) as $id) {
            $colour = (string) ($colours[$id] ?? '');
            $galleries[$colour] ??= [];
            foreach ($images[$id] ?? [] as $url) {
                $galleries[$colour][$url] = true;
            }
        }
        $colourCount = count($galleries);
        $galleries = array_values(array_filter(array_map(
            static fn (array $urls): array => array_keys($urls),
            $galleries,
        ), static fn (array $urls): bool => $urls !== []));
        if ($galleries === []) {
            return [];
        }

        if ($colourCount === 1) {
            return array_slice(array_map('strval', $galleries[0]), 0, self::IMAGES_LIMIT);
        }

        $urls = [];
        foreach ($galleries as $gallery) {
            $urls[(string) $gallery[0]] = true;
        }

        return array_map('strval', array_keys($urls));
    }

    public function imageAt(string $url): ?B2bRemoteImage
    {
        $file = $this->client->imageBytes($url);
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

    /** „54,25 zł” → 5425, „1 234,05 zł” → 123405; inny zapis (bez waluty, inna waluta) = null — nie zgadujemy. */
    public static function priceCents(string $text): ?int
    {
        $text = self::clean($text);
        if (preg_match('/^(\d{1,3}(?: \d{3})*|\d+),(\d{2}) zł$/u', $text, $m) !== 1) {
            return null;
        }

        return (int) preg_replace('/\D/', '', $m[1]) * 100 + (int) $m[2];
    }

    /**
     * Indeks koloru z indeksów rozmiarów: każdy indeks bez swojego rozmiaru na końcu („51005_21_XS” przy rozmiarze
     * „XS” → „51005_21”). Oddzielnikiem rozmiaru bywa podkreślnik albo spacja, także w jednym modelu („21172 20 XS”,
     * „21172_20_S”) — indeksy różniące się tylko tym są tym samym indeksem, a kodem zostaje zapis pierwszego rozmiaru.
     * Indeks pusty, bez rozmiaru na końcu albo inny rdzeń = null (nie zgadujemy).
     *
     * @param  list<array{reference: string, size: string}>  $rows
     */
    public static function colorCode(array $rows): ?string
    {
        $code = null;
        $key = null;
        foreach ($rows as $row) {
            $size = trim($row['size']);
            $reference = trim($row['reference']);
            if ($size === '' || $reference === '') {
                return null;
            }
            if (preg_match('/^(.+?)[ _-]+'.preg_quote($size, '/').'$/iu', $reference, $m) !== 1) {
                return null;
            }
            $stripped = trim($m[1]);
            $strippedKey = mb_strtolower((string) preg_replace('/[ _]+/u', '_', $stripped));
            if ($key !== null && $strippedKey !== $key) {
                return null;
            }
            $code ??= $stripped;
            $key = $strippedKey;
        }

        return $code;
    }

    /**
     * Strona wyrobu → dane z JSON-u data-product (nazwa, model, marka, kategoria, opis, cechy, załączniki) i wiersze
     * tabeli kombinacji z ceną netto (konta albo gościa — zależnie od tego, czyja to strona), dostępnością i galerią
     * kombinacji. Nieczytelna budowa = wyjątek z powodem (model pominięty, reszta przebiegu idzie dalej).
     *
     * @return array{id: string, name: string, model: string, brand: string, category: string, description: string, features: list<array{0: string, 1: string}>, attachments: list<array{id: string, name: string, file: string, mime: string}>, vat: string, rows: list<array{key: string, attribute_id: string, reference: string, attributes: list<array{0: string, 1: string}>, color: string, color_hex: string, size: string, cents: int|null, regular_cents: int|null, promotion: string, stock: string, images: list<string>}>}
     */
    public static function parsePage(string $html): array
    {
        $xpath = JspB2bClient::dom($html);
        $details = $xpath->query('//*[@id="product-details"][@data-product]')->item(0);
        $data = $details instanceof DOMElement ? json_decode($details->getAttribute('data-product'), true) : null;
        if (! is_array($data)) {
            throw new RuntimeException('strona bez danych wyrobu (data-product) — zmiana sklepu?');
        }
        $name = self::clean((string) ($data['name'] ?? ''));
        $id = trim((string) ($data['id_product'] ?? ''));
        if ($name === '' || preg_match('/^\d+$/', $id) !== 1) {
            throw new RuntimeException('strona wyrobu bez nazwy albo numeru wyrobu');
        }

        return [
            'id' => $id,
            'name' => $name,
            'model' => self::clean((string) ($data['reference'] ?? '')),
            'brand' => self::clean((string) ($data['manufacturer_name'] ?? '')),
            'category' => self::clean((string) ($data['category_name'] ?? '')),
            'description' => self::descriptionOf((string) ($data['description_short'] ?? ''), (string) ($data['description'] ?? '')),
            'features' => self::featuresOf($data['features'] ?? []),
            'attachments' => self::attachmentsOf($data['attachments'] ?? []),
            'vat' => self::vatOf($xpath),
            'rows' => self::combinationRows($xpath, $id),
        ];
    }

    /**
     * Cała lista; niespójna — jedno ponowne pobranie od początku.
     *
     * @return list<array{id: string, url: string, name: string}>
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

            throw new RuntimeException('Lista produktów '.MaviboB2bClient::HOST.' niespójna także po ponownym pobraniu: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<array{id: string, url: string, name: string}>
     */
    private function scanList(): array
    {
        $rows = [];
        $pages = 1;
        $total = 0;

        for ($page = 1; $page <= $pages; $page++) {
            $json = $this->client->listPage($page);
            $pagination = $json['pagination'];
            $pageCount = (int) ($pagination['pages_count'] ?? 0);
            $pageTotal = (int) ($pagination['total_items'] ?? -1);

            if ($page === 1) {
                if ($pageCount < 1 || $pageTotal < 1 || $json['products'] === []) {
                    throw new RuntimeException('Lista produktów '.MaviboB2bClient::HOST.' pusta — pusta lista konta albo zmiana sklepu');
                }
                $pages = $pageCount;
                $total = $pageTotal;
                $this->progress('Lista produktów MAVIBO: '.$total.' modeli na '.$pages.' stronach');
            } elseif ($pageCount !== $pages || $pageTotal !== $total) {
                throw new RuntimeException('lista zmieniła się ze '.$total.' modeli na '.$pageTotal.' (strona '.$page.')', self::INCONSISTENT);
            } elseif ($json['products'] === []) {
                throw new RuntimeException('strona '.$page.' bez wyrobów', self::INCONSISTENT);
            }

            foreach ($json['products'] as $item) {
                $id = is_array($item) ? trim((string) ($item['id_product'] ?? '')) : '';
                $url = is_array($item) ? trim((string) ($item['url'] ?? '')) : '';
                if ($id === '' || $url === '') {
                    throw new RuntimeException('pozycja listy bez numeru albo adresu wyrobu (strona '.$page.') — zmiana sklepu?');
                }
                if (isset($rows[$id])) {
                    throw new RuntimeException('wyrób '.$id.' na dwóch stronach', self::INCONSISTENT);
                }
                $rows[$id] = ['id' => $id, 'url' => $url, 'name' => self::clean((string) ($item['name'] ?? ''))];
            }
        }

        if (count($rows) !== $total) {
            throw new RuntimeException('lista ma '.count($rows).' modeli, sklep podaje '.$total, self::INCONSISTENT);
        }

        return array_values($rows);
    }

    /**
     * Karty jednego modelu (jedna na każdy kolor ze wszystkimi jego rozmiarami z ceną); strona nieczytelna albo bez
     * cen — jedna pozycja pominięta z powodem.
     *
     * @param  array{id: string, url: string, name: string}  $row
     * @return list<B2bRemoteProduct>
     */
    private function productsFor(array $row): array
    {
        try {
            $page = self::parsePage($this->client->productPage($row['url']));
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return [$this->skipped($row, 'strona wyrobu: '.$e->getMessage())];
        }
        if ($page['id'] !== $row['id']) {
            return [$this->skipped($row, 'strona wyrobu pokazuje wyrób '.$page['id'].', lista — '.$row['id'])];
        }
        if ($page['rows'] === []) {
            return [$this->skipped($row, 'strona wyrobu bez tabeli kombinacji z ceną (sklep nie sprzedaje tego wyrobu)')];
        }

        $priced = array_values(array_filter($page['rows'], static fn (array $r): bool => $r['cents'] !== null && $r['cents'] > 0));
        foreach ($page['rows'] as $combination) {
            if ($combination['cents'] === null || $combination['cents'] <= 0) {
                $this->sizesWithoutPrice[] = $combination['reference'] !== '' ? $combination['reference'] : $combination['key'];
            }
        }
        if ($priced === []) {
            return [$this->skipped($row, 'żadna kombinacja nie ma ceny konta')];
        }

        $catalog = $this->catalogCents($row);

        /** @var array<string, list<array<string, mixed>>> $colors */
        $colors = [];
        foreach ($priced as $combination) {
            $base = $catalog[$combination['key']] ?? null;
            // cena katalogowa kombinacji niższa od jej ceny konta nie jest ceną przed rabatem — kombinacja
            // zostaje bez niej
            $combination['base'] = $base !== null && $base > $combination['cents'] ? $base : null;
            if ($combination['base'] === null) {
                $this->withoutBase[] = $combination['reference'] !== '' ? $combination['reference'] : $combination['key'];
            }
            $colors[self::colorKey($combination)][] = $combination;
        }
        foreach ($colors as $group) {
            $this->multiPriceProducts += count(array_unique(array_column($group, 'cents'))) > 1 ? 1 : 0;
        }

        $blocker = count($colors) > 1 ? self::colourMergeBlocker($priced) : null;
        if (count($colors) > 1 && $blocker === null) {
            // kolory w kolejności pierwszego wystąpienia w tabeli, w kolorze — kolejność tabeli
            $this->colourModels++;
            $this->colourCount += count($colors);
            $products = [$this->productFor($row, $page, array_merge(...array_values($colors)), null, true)];
        } else {
            if ($blocker !== null) {
                $this->colourKept[] = $page['name'].' ('.$blocker.')';
            }
            $products = [];
            foreach ($colors as $group) {
                $code = self::colorCode(array_map(
                    static fn (array $c): array => ['reference' => $c['reference'], 'size' => $c['size']],
                    $group,
                ));
                $products[] = $this->productFor($row, $page, $group, $code, false);
            }
        }
        $this->cards += count($products);

        return $products;
    }

    /**
     * Czy kombinacje z ceną można podać jako jedną kartę z kolorami — powód, dla którego nie, albo null. Łączymy
     * tylko to, co sklep sam podaje jako jeden wyrób różniący się kolorem: jedyną cechą kombinacji poza rozmiarem jest
     * „Kolor” (niepusty), a para kolor–rozmiar się nie powtarza (inaczej nie wiadomo, który wiersz jest którym).
     *
     * @param  list<array<string, mixed>>  $combinations
     */
    private static function colourMergeBlocker(array $combinations): ?string
    {
        $seen = [];
        foreach ($combinations as $combination) {
            $other = array_values(array_filter(
                $combination['attributes'],
                static fn (array $pair): bool => mb_strtolower($pair[0]) !== self::ATTRIBUTE_SIZE,
            ));
            if (count($other) !== 1 || mb_strtolower($other[0][0]) !== self::ATTRIBUTE_COLOR || $combination['color'] === '') {
                return 'kombinacja '.($combination['reference'] !== '' ? $combination['reference'] : $combination['key']).' ma cechy inne niż kolor i rozmiar albo nie ma koloru';
            }
            $pair = mb_strtolower($combination['color']).'|'.mb_strtolower($combination['size']);
            if (isset($seen[$pair])) {
                return 'powtórzony kolor '.$combination['color'].' w rozmiarze '.($combination['size'] !== '' ? $combination['size'] : '—');
            }
            $seen[$pair] = true;
        }

        return null;
    }

    /** Etykieta wiersza karty z kolorami: „kolor 21 / S”, wyrób bez rozmiaru — „kolor 21” (kolor dosłownie ze sklepu). */
    private static function colourRowLabel(array $combination): string
    {
        return 'kolor '.$combination['color'].($combination['size'] !== '' ? ' / '.$combination['size'] : '');
    }

    /**
     * Ceny katalogowe kombinacji ze strony gościa (klucz kombinacji → grosze): cena gościa przed promocją — w czasie
     * promocji przekreślona, poza nią zwykła. Brak strony gościa = karty bez ceny katalogowej (podsumowanie przebiegu
     * to liczy).
     *
     * @param  array{id: string, url: string, name: string}  $row
     * @return array<string, int>
     */
    private function catalogCents(array $row): array
    {
        try {
            $guest = self::parsePage($this->client->guestProductPage($row['url']));
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->summaryOnce('Ceny katalogowe bez części stron gościa (np. '.$row['url'].': '.$e->getMessage().')');

            return [];
        }
        $cents = [];
        foreach ($guest['rows'] as $combination) {
            if ($combination['regular_cents'] !== null && $combination['regular_cents'] > 0) {
                $cents[$combination['key']] = $combination['regular_cents'];
            }
        }

        return $cents;
    }

    /**
     * Karta: kombinacje z ceną konta jako pozycje (members), każda ze swoją ceną konta i — gdy strona gościa podała ją
     * dla tej kombinacji i jest wyższa od ceny konta — swoją ceną katalogową. Cena karty (raw price, base_price) =
     * najtańsza kombinacja (remis — self::cheapest), tak jak liczy ją B2bCatalogSync.
     *
     * Karta jednego koloru ($colours = false) — jak dotąd: nazwa „…, kolor 21”, SKU z indeksu koloru, etykieta
     * wiersza = rozmiar, cechy kombinacji (kolor, próbka) w tabelce. Karta z kolorami — SKU z modelu, nazwa nowej karty
     * bez koloru (cardName), nazwa ze źródła i nazwy pozycji jak dotąd (kolor pozycji w nazwie), etykieta wiersza
     * „kolor 21 / S”; kolor i próbka koloru jednej kombinacji nie idą do tabelki — opisałyby jeden kolor całej karty.
     *
     * @param  array{id: string, url: string, name: string}  $row
     * @param  array<string, mixed>  $page
     * @param  non-empty-list<array<string, mixed>>  $group  kombinacje z ceną konta (cents) i katalogową (base albo null)
     */
    private function productFor(array $row, array $page, array $group, ?string $code, bool $colours): B2bRemoteProduct
    {
        $first = $group[0];
        $sku = $this->uniqueSku(array_values(array_filter($colours
            ? [$page['model'] !== '' ? $page['model'] : null, self::FALLBACK_BRAND.' '.$row['id']]
            : [
                $code,
                $first['reference'] !== '' ? $first['reference'] : null,
                self::FALLBACK_BRAND.' '.$first['key'],
            ])));
        $cheapest = self::cheapest($group);

        $name = self::cardName($page['name'], $page['brand'], $first['color']);
        $label = $colours
            ? static fn (array $c): string => self::colourRowLabel($c)
            : static fn (array $c): string => $c['size'] !== '' ? $c['size'] : $c['key'];
        // lista wierszy do pola tabelki: karta jednego koloru — jak dotąd, karta z kolorami — z jawnym ucięciem
        $rowList = $colours
            ? static fn (array $items): string => self::limitedList($items, 'pełna lista w tabeli wariantów')
            : static fn (array $items): string => implode('; ', $items);

        $members = [];
        $memberColours = [];
        $memberImages = [];
        foreach ($group as $combination) {
            $members[] = [
                'remote_id' => $combination['key'],
                'sku' => $combination['reference'] !== '' ? $combination['reference'] : $combination['key'],
                // nazwa pozycji z jej kolorem, jak nazwa dawnej karty koloru
                'name' => trim(self::cardName($page['name'], $page['brand'], $combination['color']).' '.$combination['size']),
                'availability' => $combination['stock'],
                'size' => $colours ? self::colourRowLabel($combination) : $combination['size'],
                'price' => self::accountPrice(
                    $combination['cents'] / 100,
                    $combination['base'] !== null ? $combination['base'] / 100 : null,
                ),
            ];
            $memberColours[$combination['key']] = mb_strtolower($combination['color']);
            $memberImages[$combination['key']] = $combination['images'];
        }

        return new B2bRemoteProduct(
            remoteId: $first['key'],
            sku: $sku,
            name: $name,
            category: $page['category'] !== '' ? $page['category'] : null,
            sourceUrl: MaviboB2bClient::productUrl($row['url']),
            raw: [
                'status' => 'ok',
                // cena karty = najtańsza kombinacja (B2bCatalogSync liczy ją też z members[].price)
                'price' => (float) $cheapest['cents'] / 100,
                'base_price' => $cheapest['base'] !== null ? (float) $cheapest['base'] / 100 : null,
                'brand' => $page['brand'],
                'model' => $page['model'],
                // karta z kolorami: lista wierszy dłuższa niż pole tabelki — jawnie ucięta (indeksy są w wierszach wariantów)
                'references' => $rowList(array_map(
                    static fn (array $c): string => $label($c).': '.($c['reference'] !== '' ? $c['reference'] : self::NO_REFERENCE),
                    $group,
                )),
                // cechy kombinacji wspólne dla karty (rozmiar jest w liście rozmiarów, na karcie z kolorami — także kolor)
                'attributes' => array_values(array_filter(
                    $first['attributes'],
                    static fn (array $pair): bool => mb_strtolower($pair[0]) !== self::ATTRIBUTE_SIZE
                        && (! $colours || mb_strtolower($pair[0]) !== self::ATTRIBUTE_COLOR),
                )),
                'color_hex' => $colours ? '' : $first['color_hex'],
                'features' => $page['features'],
                'category' => $page['category'],
                'vat' => $page['vat'],
                // jeden element — shopFields łączy listę; na karcie z kolorami już przycięty jawnie (dostępność każdego
                // wiersza jest w wierszach wariantów)
                'stock' => [$rowList(array_map(static fn (array $c): string => $label($c).': '.$c['stock'], $group))],
                // cena konta w promocji sklepu — przetarg z terminem po jej końcu dostanie inną cenę
                'promotion' => self::promotionNote($group, $label, $colours),
                'description' => $page['description'],
                // pliki i zdjęcia wybierane z pozycji produktu (documents, imageUrls)
                'documents' => self::documentsOf($page),
                'member_colours' => $memberColours,
                'member_images' => $memberImages,
            ],
            availability: self::groupAvailability($group, $label),
            variantSummary: $colours
                ? self::coloursSummary($group)
                : 'Rozmiary: '.implode('; ', array_map(
                    static fn (array $c): string => $label($c).' ('.($c['reference'] !== '' ? $c['reference'] : $c['key']).')',
                    $group,
                )),
            members: $members,
            identifiers: self::identifiers($page['model'], $group, $colours),
            cardName: $colours ? self::cardName($page['name'], $page['brand'], '') : null,
        );
    }

    /**
     * Lista wariantów karty z kolorami: „Kolory: 21 (51005_21), 26 (51005_26); rozmiary: S, M, 3XL” — kolor dosłownie,
     * w nawiasie indeks koloru, gdy jego rozmiary go dają (colorCode), rozmiary bez powtórzeń w kolejności tabeli.
     *
     * @param  non-empty-list<array<string, mixed>>  $group
     */
    private static function coloursSummary(array $group): string
    {
        /** @var array<string, list<array<string, mixed>>> $byColour */
        $byColour = [];
        $sizes = [];
        foreach ($group as $combination) {
            $byColour['c'.$combination['color']][] = $combination;
            if ($combination['size'] !== '') {
                $sizes['s'.$combination['size']] = $combination['size'];
            }
        }
        $colours = [];
        foreach ($byColour as $combinations) {
            $code = self::colorCode(array_map(
                static fn (array $c): array => ['reference' => $c['reference'], 'size' => $c['size']],
                $combinations,
            ));
            $colours[] = $combinations[0]['color'].($code !== null ? ' ('.$code.')' : '');
        }

        return 'Kolory: '.implode(', ', $colours).($sizes !== [] ? '; rozmiary: '.implode(', ', $sizes) : '');
    }

    /**
     * Promocja sklepu na karcie: wszystkie kombinacje w tej samej promocji (albo żadna) — jak dotąd, dosłownie
     * z pierwszej („– 25% (cena konta przed promocją 40,08 zł netto)”); różne — każda promocja z rozmiarami, których
     * dotyczy („S, M: – 25% (…); 3XL: bez promocji”), żeby cena rozmiaru spoza promocji nie wyglądała na promocyjną.
     * Karta z kolorami, gdy taki wykaz nie mieści się w polu tabelki (ProductShopCard::MAX_VALUE_CHARS) — każda
     * promocja z liczbą kombinacji, których dotyczy, i jawną uwagą, że wykazu wierszy nie ma (przycięty wykaz
     * wyglądałby na pełny).
     *
     * @param  non-empty-list<array<string, mixed>>  $group
     * @param  callable(array<string, mixed>): string  $label
     */
    private static function promotionNote(array $group, callable $label, bool $colours = false): string
    {
        $notes = [];
        foreach ($group as $combination) {
            $note = $combination['promotion'] !== ''
                ? $combination['promotion'].' (cena konta przed promocją '.number_format(((int) $combination['regular_cents']) / 100, 2, ',', ' ').' zł netto)'
                : '';
            $notes[$note][] = $label($combination);
        }
        if (count($notes) === 1) {
            return (string) array_key_first($notes);
        }
        $parts = [];
        foreach ($notes as $note => $sizes) {
            $parts[] = implode(', ', $sizes).': '.($note !== '' ? $note : 'bez promocji');
        }
        $text = implode('; ', $parts);
        if (! $colours || mb_strlen($text) <= ProductShopCard::MAX_VALUE_CHARS) {
            return $text;
        }
        $counts = [];
        foreach ($notes as $note => $sizes) {
            $counts[] = ($note !== '' ? $note : 'bez promocji').': '.count($sizes).' z '.count($group).' kombinacji';
        }

        $remark = ' (wykaz kombinacji za długi na tabelkę — które są w promocji, pokazuje sklep)';

        return self::limitedList($counts, 'pozostałych promocji', mb_strlen($remark)).$remark;
    }

    /**
     * Lista do jednego pola tabelki sklepu: cała, gdy mieści się w ProductShopCard::MAX_VALUE_CHARS; inaczej tyle
     * pełnych pozycji, ile się zmieści, i jawny dopisek „… (+N, {uwaga})” — nigdy lista przycięta po cichu przez zapis.
     * $reserve — znaki, które wołający dopisze za listą.
     *
     * @param  list<string>  $items
     */
    private static function limitedList(array $items, string $note, int $reserve = 0): string
    {
        $all = implode('; ', $items);
        // zapas na dopisek „; … (+N, uwaga)”
        $limit = ProductShopCard::MAX_VALUE_CHARS - $reserve - mb_strlen($note) - 20;
        if (mb_strlen($all) <= ProductShopCard::MAX_VALUE_CHARS - $reserve) {
            return $all;
        }
        $kept = [];
        $length = 0;
        foreach ($items as $item) {
            $next = $length + ($kept === [] ? 0 : 2) + mb_strlen($item);
            if ($next > $limit) {
                break;
            }
            $kept[] = $item;
            $length = $next;
        }
        $suffix = '… (+'.(count($items) - count($kept)).', '.$note.')';

        return $kept === [] ? $suffix : implode('; ', $kept).'; '.$suffix;
    }

    /**
     * Model wyrobu z data-product („reference”, np. „51005” — kod rodziny bez koloru i rozmiaru) na całą kartę
     * i indeks każdej kombinacji z tabeli (kolumna „Indeks”) na jej pozycji, dosłownie. MAVIBO jest dystrybutorem,
     * więc indeks to jego własny kod, nie kod producenta. Indeks pusty („—”) pomijamy; indeksy niespójne w jednym
     * modelu („21172 20 XS” obok „21172_20_S”) zostają tak, jak je podał sklep. Indeks koloru („51005_21”, SKU karty)
     * składamy sami — nie jest identyfikatorem. Etykieta = kolor i rozmiar kombinacji dosłownie z tabeli („21 S”),
     * na karcie z kolorami — etykieta wiersza („kolor 21 / S”).
     *
     * @param  list<array<string, mixed>>  $group
     * @return list<B2bRemoteIdentifier>
     */
    private static function identifiers(string $model, array $group, bool $colours): array
    {
        $out = [];
        if ($model !== '') {
            $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MODEL_CODE, value: $model, field: 'reference');
        }
        foreach ($group as $combination) {
            if ($combination['reference'] === '') {
                continue;
            }
            $label = $colours ? self::colourRowLabel($combination) : trim(implode(' ', array_column($combination['attributes'], 1)));
            $out[] = new B2bRemoteIdentifier(
                type: ProductIdentifier::TYPE_SOURCE_CODE,
                value: $combination['reference'],
                remoteId: $combination['key'],
                label: $label !== '' ? $label : null,
                field: 'Indeks',
            );
        }

        return $out;
    }

    /**
     * Pierwszy wolny kod z kolejnych kandydatów (indeks koloru, indeks pierwszego rozmiaru, klucz kombinacji) — dwie
     * karty przebiegu z tym samym SKU złamałyby UNIQUE products.sku i synchronizacja pominęłaby drugą.
     *
     * @param  list<string>  $candidates
     */
    private function uniqueSku(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $key = mb_strtolower($candidate);
            if (! isset($this->skus[$key])) {
                $this->skus[$key] = true;

                return $candidate;
            }
        }

        // klucz kombinacji jest unikalny w sklepie — tu dochodzi tylko przy powtórzonym wierszu tabeli
        return $candidates[array_key_last($candidates)];
    }

    /** Kolor kombinacji (i inne cechy poza rozmiarem) — rozmiary jednego koloru idą na wspólne karty. */
    private static function colorKey(array $combination): string
    {
        $parts = [];
        foreach ($combination['attributes'] as [$label, $value]) {
            if (mb_strtolower($label) !== self::ATTRIBUTE_SIZE) {
                $parts[] = $label.'='.$value;
            }
        }

        return implode('|', $parts);
    }

    /** „PROMOSTARS NIMBO 51005, kolor 21” — marka tylko wtedy, gdy nazwa jej nie ma. */
    private static function cardName(string $name, string $brand, string $color): string
    {
        $name = self::clean($name);
        if ($brand !== '' && ! str_contains(mb_strtoupper($name), mb_strtoupper($brand))) {
            $name = $brand.' '.$name;
        }

        return $color !== '' ? $name.', kolor '.$color : $name;
    }

    /**
     * @param  array{id: string, url: string, name: string}  $row
     */
    private function skipped(array $row, string $reason): B2bRemoteProduct
    {
        $this->skippedProducts++;
        $name = $row['name'] !== '' ? $row['name'] : 'wyrób '.$row['id'];

        return new B2bRemoteProduct(
            remoteId: 'model_'.$row['id'],
            sku: $name,
            name: $name,
            sourceUrl: $row['url'],
            raw: ['status' => 'skipped', 'reason' => $reason],
        );
    }

    /**
     * Jedna dostępność dla wszystkich kombinacji — dosłownie; różne — „Produkt dostępny: S, M; Produkt niedostępny: XS”
     * (etykiety wierszy karty).
     *
     * @param  list<array<string, mixed>>  $group
     * @param  callable(array<string, mixed>): string  $label
     */
    private static function groupAvailability(array $group, callable $label): ?string
    {
        $statuses = [];
        foreach ($group as $combination) {
            $statuses[$combination['stock']][] = $label($combination);
        }
        if (count($statuses) === 1) {
            $only = (string) array_key_first($statuses);

            return $only !== '' ? $only : null;
        }
        $parts = [];
        foreach ($statuses as $status => $sizes) {
            $parts[] = ($status !== '' ? $status : 'brak informacji').': '.implode(', ', $sizes);
        }

        return implode('; ', $parts);
    }

    /**
     * Wiersze tabeli kombinacji. Kolumny po nagłówkach: „Indeks”, cechy (np. „Kolor”, „Rozmiar”), „Cena netto”,
     * „Cena brutto”, „Dostępny”. Wiersz kombinacji ma pole ilości „qty[{wyrób}_{kombinacja}]” — to klucz kombinacji.
     * Wyrób bez kombinacji (tabela z samym indeksem, bez pola ilości i ceny) = [] — sklep go nie sprzedaje.
     *
     * @return list<array{key: string, attribute_id: string, reference: string, attributes: list<array{0: string, 1: string}>, color: string, color_hex: string, size: string, cents: int|null, regular_cents: int|null, promotion: string, stock: string, images: list<string>}>
     */
    private static function combinationRows(DOMXPath $xpath, string $productId): array
    {
        $table = $xpath->query('//*[@id="tablecombz-wrapper"]//table')->item(0);
        if (! $table instanceof DOMElement) {
            return [];
        }
        $labels = [];
        foreach ($xpath->query('.//tr[th]', $table)->item(0)?->childNodes ?? [] as $cell) {
            if ($cell instanceof DOMElement && strtolower($cell->nodeName) === 'th') {
                $labels[] = self::text($cell);
            }
        }
        $headers = array_map(static fn (string $label): string => mb_strtolower($label), $labels);
        $referenceAt = array_search(self::COLUMN_REFERENCE, $headers, true);
        $netAt = array_search(self::COLUMN_NET, $headers, true);
        $stockAt = array_search(self::COLUMN_STOCK, $headers, true);
        if ($referenceAt === false) {
            throw new RuntimeException('tabela kombinacji bez kolumny „Indeks” — zmiana sklepu?');
        }
        if ($netAt === false) {
            // tabela bez cen (wyrób bez kombinacji) — nie ma czego sprzedawać
            return [];
        }
        // cechy kombinacji to kolumny między indeksem a ceną
        $attributeColumns = [];
        for ($i = $referenceAt + 1; $i < $netAt; $i++) {
            $attributeColumns[$i] = $headers[$i];
        }
        $legend = self::stockLegend($xpath);
        $galleries = self::combinationGalleries($xpath);

        $rows = [];
        foreach ($xpath->query('.//tr[td]', $table) ?: [] as $tr) {
            if (! $tr instanceof DOMElement) {
                continue;
            }
            if ($xpath->query('./td['.JspB2bClient::classPredicate('tablecombz-reference').']', $tr)->item(0) === null) {
                // wiersz podsumowania („Razem”)
                continue;
            }
            $attributeId = self::combinationId($xpath, $tr, $productId);
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && strtolower($cell->nodeName) === 'td') {
                    $cells[] = $cell;
                }
            }
            if (count($cells) !== count($headers)) {
                throw new RuntimeException('wiersz tabeli kombinacji ma '.count($cells).' komórek, nagłówek '.count($headers));
            }

            $attributes = [];
            $color = '';
            $colorHex = '';
            $size = '';
            foreach ($attributeColumns as $i => $header) {
                $value = self::text($cells[$i]);
                if ($value !== '') {
                    $attributes[] = [$labels[$i], $value];
                }
                if ($header === self::ATTRIBUTE_COLOR) {
                    $color = $value;
                    $swatch = $xpath->query('.//*[@style]', $cells[$i])->item(0);
                    if ($swatch instanceof DOMElement && preg_match('/background(?:-color)?\s*:\s*(#[0-9a-f]{3,6})\b/i', $swatch->getAttribute('style'), $hex) === 1) {
                        $colorHex = strtolower($hex[1]);
                    }
                } elseif ($header === self::ATTRIBUTE_SIZE) {
                    $size = $value;
                }
            }
            $reference = self::text($cells[$referenceAt]);
            $price = self::cellPrice($xpath, $cells[$netAt]);

            $rows[] = [
                'key' => $productId.'_'.$attributeId,
                'attribute_id' => $attributeId,
                'reference' => $reference === self::NO_REFERENCE ? '' : $reference,
                'attributes' => $attributes,
                'color' => $color,
                'color_hex' => $colorHex,
                'size' => $size,
                'cents' => $price['cents'],
                'regular_cents' => $price['regular_cents'],
                'promotion' => $price['promotion'],
                'stock' => $stockAt !== false ? self::stockText($cells[$stockAt], $legend) : '',
                'images' => $galleries[$attributeId] ?? [],
            ];
        }

        return $rows;
    }

    /**
     * Id kombinacji wiersza: z okna podglądu zdjęcia („#tablecombz-image-modal-930” — ma je każdy wiersz), a pole
     * ilości „qty[{wyrób}_{kombinacja}]” (tylko kombinacje dostępne) musi mówić to samo. Brak albo sprzeczność =
     * wyjątek: bez klucza kombinacji nie da się bezpiecznie powiązać rozmiaru z kartą.
     */
    private static function combinationId(DOMXPath $xpath, DOMElement $tr, string $productId): string
    {
        $id = null;
        $modal = $xpath->query('.//*[starts-with(@data-target, "#tablecombz-image-modal-")]', $tr)->item(0);
        if ($modal instanceof DOMElement && preg_match('/^#tablecombz-image-modal-(\d+)$/', $modal->getAttribute('data-target'), $m) === 1 && $m[1] !== '0') {
            $id = $m[1];
        }
        $quantity = $xpath->query('.//input[starts-with(@name, "qty[")]', $tr)->item(0);
        if ($quantity instanceof DOMElement) {
            if (preg_match('/^qty\[(\d+)_(\d+)\]$/', $quantity->getAttribute('name'), $q) !== 1 || $q[1] !== $productId || ($id !== null && $q[2] !== $id)) {
                throw new RuntimeException('wiersz tabeli kombinacji z niezgodnym kluczem „'.$quantity->getAttribute('name').'”');
            }
            $id = $q[2];
        }
        if ($id === null) {
            throw new RuntimeException('wiersz tabeli kombinacji bez numeru kombinacji');
        }

        return $id;
    }

    /**
     * Cena z komórki tabeli: zwykła („54,25 zł”) albo promocyjna („<s>40,08 zł</s> – 25% 30,06 zł”) — wtedy cena
     * bieżąca to ostatnia kwota, cena przed promocją to kwota przekreślona, a „– 25%” zostaje dosłownie. Zapis
     * z inną liczbą kwot = null (nie zgadujemy).
     *
     * @return array{cents: int|null, regular_cents: int|null, promotion: string}
     */
    private static function cellPrice(DOMXPath $xpath, DOMElement $cell): array
    {
        $none = ['cents' => null, 'regular_cents' => null, 'promotion' => ''];
        $struck = $xpath->query('.//s | .//del', $cell)->item(0);
        $text = self::text($cell);
        if ($struck === null) {
            $cents = self::priceCents($text);

            return ['cents' => $cents, 'regular_cents' => $cents, 'promotion' => ''];
        }
        $regular = self::priceCents(self::text($struck));
        if ($regular === null
            || preg_match_all('/(?:\d{1,3}(?: \d{3})*|\d+),\d{2} zł/u', $text, $amounts) !== 2
            || self::priceCents($amounts[0][0]) !== $regular) {
            return $none;
        }
        $current = self::priceCents($amounts[0][1]);
        $promotion = trim(str_replace($amounts[0], '', $text));

        return $current === null ? $none : ['cents' => $current, 'regular_cents' => $regular, 'promotion' => $promotion];
    }

    /**
     * Legenda dostępności pod tabelą: obrazek → opis („available.gif” → „Produkt dostępny”).
     *
     * @return array<string, string>
     */
    private static function stockLegend(DOMXPath $xpath): array
    {
        $legend = [];
        $node = $xpath->query('//*['.JspB2bClient::classPredicate('avail_descr').']')->item(0);
        if ($node === null) {
            return $legend;
        }
        $image = null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'img') {
                $image = basename((string) parse_url($child->getAttribute('src'), PHP_URL_PATH));
            } elseif ($child->nodeType === XML_TEXT_NODE && $image !== null) {
                $text = self::clean((string) $child->nodeValue);
                if ($text !== '') {
                    $legend[$image] = $text;
                    $image = null;
                }
            }
        }

        return $legend;
    }

    /**
     * Dostępność kombinacji dosłownie z legendy sklepu (po obrazku), a bez legendy — z podpowiedzi obrazka.
     *
     * @param  array<string, string>  $legend
     */
    private static function stockText(DOMElement $cell, array $legend): string
    {
        foreach ($cell->getElementsByTagName('img') as $image) {
            $file = basename((string) parse_url($image->getAttribute('src'), PHP_URL_PATH));
            if (isset($legend[$file])) {
                return $legend[$file];
            }
            $title = self::clean($image->getAttribute('title'));
            if ($title !== '') {
                return $title;
            }
        }

        return '';
    }

    /**
     * Galerie kombinacji z okien podglądu tabeli („#tablecombz-image-modal-{kombinacja}”): zdjęcie główne i miniatury
     * w rozmiarze dużym, w kolejności ze sklepu — jednym przejściem strony (model ma do ~180 kombinacji).
     *
     * @return array<string, list<string>> id kombinacji => adresy zdjęć
     */
    private static function combinationGalleries(DOMXPath $xpath): array
    {
        $galleries = [];
        foreach ($xpath->query('//*[starts-with(@id, "tablecombz-image-modal-")]') ?: [] as $modal) {
            if (! $modal instanceof DOMElement || preg_match('/^tablecombz-image-modal-(\d+)$/', $modal->getAttribute('id'), $m) !== 1) {
                continue;
            }
            $urls = [];
            foreach ($modal->getElementsByTagName('img') as $image) {
                $src = $image->getAttribute('data-image-large-src');
                $url = self::shopUrl($src !== '' ? $src : $image->getAttribute('src'));
                if ($url !== null) {
                    $urls[$url] = true;
                }
            }
            $galleries[$m[1]] = array_keys($urls);
        }

        return $galleries;
    }

    /**
     * Pliki wyrobu z kolorami, którym są przypisane (numer koloru jako ostatni wyraz nazwy — „KARTA PRODUKTU 51005
     * 26” — albo jako człon nazwy pliku — „p_51005_21_wynik.jpg”); bez koloru — [] (plik na każdą kartę modelu).
     * Sklep bywa niekonsekwentny (nazwa „… 22”, plik „…_21_…” przy kolorach 21 i 26) — plik należy do każdego koloru,
     * który którekolwiek z tych miejsc nazywa. Wybór dla karty — documents() z kolorów jej pozycji.
     *
     * @param  array<string, mixed>  $page
     * @return list<array{title: string, url: string, kind: string, colours: list<string>}>
     */
    private static function documentsOf(array $page): array
    {
        $colors = [];
        foreach ($page['rows'] as $combination) {
            if ($combination['color'] !== '') {
                $colors[mb_strtolower($combination['color'])] = true;
            }
        }
        $documents = [];
        foreach ($page['attachments'] as $attachment) {
            $named = [];
            $words = preg_split('/\s+/u', mb_strtolower($attachment['name'])) ?: [];
            $last = (string) end($words);
            if (isset($colors[$last])) {
                $named[$last] = true;
            }
            foreach (preg_split('/[\s_.\-]+/u', mb_strtolower($attachment['file'])) ?: [] as $part) {
                if (isset($colors[$part])) {
                    $named[$part] = true;
                }
            }
            $extension = mb_strtolower(pathinfo($attachment['file'], PATHINFO_EXTENSION));
            $documents[] = [
                'title' => mb_substr($attachment['name'] !== '' ? $attachment['name'] : $attachment['file'], 0, 255),
                'url' => MaviboB2bClient::BASE.'/index.php?controller=attachment&id_attachment='.$attachment['id'],
                'kind' => self::documentKind($attachment['name'].' '.$attachment['file'], $extension),
                // numery kolorów bywają kluczami liczbowymi tablicy — z powrotem tekst
                'colours' => array_map('strval', array_keys($named)),
            ];
        }

        return $documents;
    }

    private static function documentKind(string $name, string $extension): string
    {
        $name = mb_strtolower($name);
        if (str_contains($name, 'certyfikat') || str_contains($name, 'certificate') || str_contains($name, 'deklaracj') || str_contains($name, 'declaration')) {
            return ProductDocument::KIND_CERTIFICATE;
        }
        if (str_contains($name, 'instrukcj') || str_contains($name, 'manual')) {
            return ProductDocument::KIND_MANUAL;
        }
        if (str_contains($name, 'tabela rozmiar') || str_contains($name, 'size chart')) {
            return ProductDocument::KIND_SIZE_CHART;
        }
        if (str_contains($name, 'karta produktu') || $extension === 'pdf') {
            return ProductDocument::KIND_DATASHEET;
        }

        return ProductDocument::KIND_OTHER;
    }

    /**
     * @param  mixed  $attachments  „attachments” z data-product
     * @return list<array{id: string, name: string, file: string, mime: string}>
     */
    private static function attachmentsOf(mixed $attachments): array
    {
        $out = [];
        foreach (is_array($attachments) ? $attachments : [] as $attachment) {
            $id = is_array($attachment) ? trim((string) ($attachment['id_attachment'] ?? '')) : '';
            if (preg_match('/^\d+$/', $id) !== 1) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => self::clean((string) ($attachment['name'] ?? '')),
                'file' => self::clean((string) ($attachment['file_name'] ?? '')),
                'mime' => self::clean((string) ($attachment['mime'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * Cechy wyrobu PrestaShop (features: name/value) dosłownie.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function featuresOf(mixed $features): array
    {
        $out = [];
        foreach (is_array($features) ? $features : [] as $feature) {
            $name = is_array($feature) ? self::clean((string) ($feature['name'] ?? '')) : '';
            $value = is_array($feature) ? self::clean((string) ($feature['value'] ?? '')) : '';
            if ($name !== '' && $value !== '') {
                $out[] = [$name, $value];
            }
        }

        return $out;
    }

    /** „Ceny brutto VAT: 23,0%.” pod tabelą kombinacji → „23,0%”; brak = ''. */
    private static function vatOf(DOMXPath $xpath): string
    {
        $note = self::text($xpath->query('//*[@id="tablecombz-wrapper"]//*['.JspB2bClient::classPredicate('note').']')->item(0));

        return preg_match('/VAT:\s*(\d+(?:,\d+)?\s*%)/u', $note, $m) === 1 ? $m[1] : '';
    }

    /** Opis krótki (gramatura, skład) i pełny — oba dosłownie, linie ze źródła, bez obrazków. */
    private static function descriptionOf(string $short, string $long): string
    {
        $parts = [];
        foreach ([$short, $long] as $html) {
            $lines = self::htmlLines($html);
            if ($lines !== []) {
                $parts[] = implode("\n", $lines);
            }
        }

        return mb_substr(implode("\n\n", $parts), 0, self::DESCRIPTION_LIMIT);
    }

    /**
     * @return list<string>
     */
    private static function htmlLines(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="mavibo-root">'.$html.'</div>', LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->getElementById('mavibo-root');

        return $root !== null ? self::blockLines($root) : [];
    }

    /**
     * Adres ze sklepu (względny, „//mavibo.pl/…” albo pełny) → pełny adres https; null = adres spoza sklepu albo pusty.
     */
    private static function shopUrl(string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'data:')) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        } elseif (preg_match('#^https?://#i', $href) !== 1) {
            $href = MaviboB2bClient::BASE.'/'.ltrim($href, '/');
        }
        $href = (string) preg_replace('#^http://#i', 'https://', $href);

        return MaviboB2bClient::isShopUrl($href) ? $href : null;
    }

    /**
     * Element → linie tekstu: nowa linia przy <br>, elementach blokowych i znaku nowej linii w tekście; pozycja
     * listy jako „- …”; komórki wiersza tabeli w jednej linii. Białe znaki w linii zwinięte, puste linie pominięte.
     *
     * @return list<string>
     */
    private static function blockLines(DOMNode $root): array
    {
        $lines = [];
        $buffer = '';
        $flush = static function () use (&$lines, &$buffer): void {
            foreach (preg_split('/\R/u', $buffer) ?: [] as $part) {
                $line = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $part));
                if ($line !== '' && $line !== '-') {
                    $lines[] = $line;
                }
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
                } elseif ($tag === 'tr') {
                    // wiersz tabeli wymiarów to jedna linia: „Długość 73 75 75 77” — w źródle komórki dzielą znaki
                    // nowej linii, które rozbiłyby wiersz na osobne liczby
                    $flush();
                    $cells = [];
                    foreach ($child->childNodes as $cell) {
                        if ($cell instanceof DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                            $text = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $cell->textContent));
                            if ($text !== '') {
                                $cells[] = $text;
                            }
                        }
                    }
                    $buffer = implode(' ', $cells);
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

    private function summaryOnce(string $line): void
    {
        if (! in_array($line, $this->summary, true)) {
            $this->summary[] = $line;
        }
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
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $value));
    }

    private static function text(?DOMNode $node): string
    {
        return $node === null ? '' : self::clean($node->textContent);
    }
}
