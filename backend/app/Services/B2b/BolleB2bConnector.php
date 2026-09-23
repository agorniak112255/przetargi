<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductIdentifier;
use RuntimeException;

/**
 * b2b.bolle-safety.com — jedna pozycja NetSuite (internalid) = jedna karta. Pozycje z liści drzewa kategorii
 * (/api/items, fieldset=details), bez materiałów marketingowych. Ta sama pozycja bywa w kilku liściach
 * (210 z 819 pozycji, 15.09.2026) — liczy się raz, z kategorią pierwszego liścia w kolejności drzewa.
 *
 * Całość jest zbierana przed podaniem pierwszego produktu: totalProducts() musi być znane od pierwszego
 * produktu, a niepełna lista kategorii przerywa przebieg (B2bFatalException) — decyzja, których pozycji
 * brakuje, byłaby zgadywaniem.
 *
 * Bezpieczeństwo sesji: /api/items odpowiada też gościowi, z ceną katalogową w miejscu ceny konta. Cena
 * kontrolna = pierwsza pozycja z ceną konta niższą od katalogowej (pricelevel1). Po zebraniu każdego liścia
 * pozycja kontrolna jest pobierana ponownie — inna cena albo brak pozycji = ponowne logowanie i ponowne zebranie
 * liścia; drugi raz źle = B2bFatalException (nic nie zapisujemy). Dopóki ceny kontrolnej brak, po liściu
 * sprawdzany jest profil sesji. Brak w całym zakresie pozycji z ceną konta niższą od katalogowej = nie da się
 * potwierdzić, że ceny są cenami konta → B2bFatalException.
 *
 * Nazwa istniejącej karty nie jest nadpisywana (B2bKeepsExistingNames): 254 karty Bolle mają polskie nazwy
 * z cennika EMEA, sklep podaje angielskie nazwy rodzin.
 *
 * Sklep podaje teksty po angielsku (B2bForeignLanguageSource, decyzja użytkownika 15.09.2026): opis zapisany
 * przez import i nazwa nowej karty są tłumaczone na polski po zapisie (TranslateB2bProductTextJob). Łącznik
 * podaje tekst dosłownie; tylko etykiety cech w tabelce „Parametry” (shopFields) są naszymi stałymi polskimi
 * odpowiednikami.
 */
final class BolleB2bConnector implements B2bConnector, B2bForeignLanguageSource, B2bKeepsExistingNames, B2bManufacturerSite, B2bShopFieldSource
{
    private const SESSION_LOST = 'Utracono sesję konta bolle-safety.com — ceny konta niedostępne';

    private const REASON_MATRIX = 'pozycja z wariantami (macierz) — importer ich nie obsługuje';

    private const EPSILON = 0.005;

    /** Sekcje karty wyrobu u dostawcy (B2bShopFieldSource). */
    private const SHOP_SECTION_TRADE = 'Informacje handlowe';

    private const SHOP_SECTION_PARAMETERS = 'Parametry';

    /**
     * Pola cech, które mają etykiety filtrów sklepu (SC.CONFIGURATION.facets, odczyt 15.09.2026: Frame Material,
     * Frame Technology, Lens coating, Lens colour). Etykiety to nasze stałe polskie odpowiedniki tych etykiet
     * (15.09.2026) — wartości cech zostają dosłownie ze źródła, po angielsku.
     * Pozostałe custitem_* nie mają etykiet w źródle (część to pola wewnętrzne: segmenty klientów, najbliższa
     * dostawa) — nie trafiają na kartę wyrobu u dostawcy.
     */
    private const PARAMETERS = [
        'custitem_bb_fm_product_material' => 'Materiał oprawki',
        'custitem_bb_specific_technology' => 'Technologia oprawki',
        'custitem_bb_safety_lens_coating' => 'Powłoka soczewki',
        'custitem_bb_safety_lens_shade' => 'Kolor soczewki',
    ];

    /** Pola pozycji zachowywane w raw (reszta odpowiedzi nie jest potrzebna). */
    private const RAW_FIELDS = [
        'internalid', 'itemid', 'storedisplayname2', 'displayname', 'storedescription', 'storedetaileddescription',
        'featureddescription', 'onlinecustomerprice_detail', 'onlinecustomerprice', 'pricelevel1', 'dontshowprice',
        'ispurchasable', 'urlcomponent', 'custitem_atlas_item_image',
        'custitem_bb_fm_product_material', 'custitem_bb_specific_technology', 'custitem_bb_safety_lens_coating', 'custitem_bb_safety_lens_shade',
    ];

    private int $total = 0;

    private ?int $controlId = null;

    private ?float $controlPrice = null;

    public function __construct(private readonly BolleB2bClient $client) {}

    public static function key(): string
    {
        return 'bolle';
    }

    public static function label(): string
    {
        return 'Bolle';
    }

    public static function host(): string
    {
        return BolleB2bClient::HOST;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new BolleB2bClient($account->username, (string) $account->password, $delayMs));
    }

    public function login(): void
    {
        $this->client->login();
    }

    public function products(): iterable
    {
        $this->total = 0;
        $this->controlId = null;
        $this->controlPrice = null;

        if (! $this->client->sessionAlive()) {
            // login() potwierdza sesję w profilu — nieudane = B2bFatalException
            $this->client->relogin();
        }

        /** @var array<int, array{item: array<string, mixed>, path: string}> $found */
        $found = [];
        foreach ($this->client->leafCategories() as $leaf) {
            $items = $this->leafItems($leaf);
            if (! $this->sessionHolds()) {
                $this->client->relogin();
                $items = $this->leafItems($leaf);
                if (! $this->sessionHolds()) {
                    throw new B2bFatalException(self::SESSION_LOST.' (kontrola po kategorii '.$leaf['path'].' po ponownym logowaniu)');
                }
            }
            foreach ($items as $id => $item) {
                $found[$id] ??= ['item' => $item, 'path' => $leaf['path']];
            }
        }

        if ($this->controlId === null) {
            throw new B2bFatalException(
                'W katalogu konta '.BolleB2bClient::HOST.' nie ma żadnej pozycji z ceną konta niższą od katalogowej — nie da się potwierdzić, że ceny są cenami konta'
            );
        }

        $this->total = count($found);
        foreach ($found as $entry) {
            yield $this->productFor($entry['item'], $entry['path']);
        }
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    /** Tak nazywają się istniejące karty (254, cennik EMEA) — B2bCatalogSync porównuje producenta z kartą. */
    /** Witryna nalezy do tej marki — tylko jej karty wolno nadpisac opisem stad. */
    public static function ownBrand(): string
    {
        return 'Bolle';
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'Bolle';
    }

    /**
     * Cena konta = onlinecustomerprice (sklep podaje ją bez oznaczenia VAT — przyjmujemy jako netto zakupu, jak
     * w JSP). Katalogowa = pricelevel1: 15.09.2026 równa cenie katalogowej z cennika EMEA dla 194 z 207 wspólnych
     * kodów (13 różnic ±0,1 i jedna zmiana ceny) i zawsze ≥ cena konta. pricelevel1 niższe od ceny konta = brak
     * katalogowej (null), nie zgadujemy. Waluta z profilu konta.
     */
    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'pozycja nieodczytana'));
        }
        if (($product->raw['dontshowprice'] ?? false) === true) {
            return null;
        }
        $net = self::accountPrice($product->raw);
        if ($net === null || $net <= 0) {
            return null;
        }
        $net = round($net, 2);

        $catalog = $product->raw['pricelevel1'] ?? null;
        $base = is_numeric($catalog) && (float) $catalog > 0 && round((float) $catalog, 2) >= $net
            ? round((float) $catalog, 2)
            : null;

        return new B2bRemotePrice(
            net: $net,
            base: $base,
            discountPercent: 0.0,
            currency: $this->client->currency(),
        );
    }

    /**
     * Opis dosłownie ze sklepu (tłumaczenie dopiero po zapisie karty — B2bForeignLanguageSource), sekcje
     * oddzielone pustą linią: krótki opis (storedescription), opis szczegółowy (storedetaileddescription), opis
     * wyróżniony (featureddescription) — sekcja identyczna z wcześniejszą pominięta. Cech z PARAMETERS tu nie ma:
     * to pary „nazwa → wartość”, które podaje shopFields() jako tabelkę „Parametry”. Pozycja bez żadnego z trzech
     * pól opisowych zostaje bez opisu; pusty opis niczego nie nadpisuje (B2bCatalogSync::applyCardDetails).
     */
    public function description(B2bRemoteProduct $product): string
    {
        $sections = [];
        foreach (['storedescription', 'storedetaileddescription', 'featureddescription'] as $field) {
            $text = self::htmlToText((string) ($product->raw[$field] ?? ''));
            if ($text !== '' && ! in_array($text, $sections, true)) {
                $sections[] = $text;
            }
        }

        return mb_substr(implode("\n\n", $sections), 0, 10000);
    }

    /**
     * Karta wyrobu u dostawcy z tej samej odpowiedzi /api/items, którą products() już pobrał (żadnego zapytania
     * więcej): kod towaru i cztery cechy z etykietami filtrów sklepu (PARAMETERS) — wartości dosłownie ze
     * źródła, po angielsku, jak w opisie. Pozostałych pól custitem_* sklep nie opisuje etykietą, więc na kartę
     * nie trafiają. Ceny nie dokładamy — karta ma na nie własną sekcję.
     *
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            return [];
        }

        $fields = [];
        $sku = trim((string) ($product->raw['itemid'] ?? $product->sku));
        if ($sku !== '') {
            $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_TRADE, 'Kod towaru', $sku);
        }
        foreach (self::PARAMETERS as $field => $label) {
            $value = $product->raw[$field] ?? null;
            if (! is_scalar($value)) {
                continue;
            }
            $value = self::inlineText((string) $value);
            if ($value !== '') {
                $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_PARAMETERS, $label, $value);
            }
        }

        return $fields;
    }

    /**
     * Zdjęcie z custitem_atlas_item_image. API podaje ścieżkę bez domeny („/core/media/media.nl?id=…&c=…&h=…”,
     * sprawdzone na żywym sklepie 15.09.2026 — przebiegi #14 i #16 odrzuciły przez to wszystkie zdjęcia), więc ścieżkę
     * zaczynającą się od „/” uzupełniamy adresem sklepu; pełny adres innego hosta dalej odrzuca klient. Adres zapisujemy
     * w pełni, z parametrem h — to hash pliku w NetSuite (bez niego HTTP 403), nie token sesji: plik pobiera się bez logowania.
     */
    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $url = trim((string) ($product->raw['custitem_atlas_item_image'] ?? ''));
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            $url = BolleB2bClient::BASE.$url;
        }
        $file = $this->client->imageBytes($url);
        if ($file['bytes'] === '' || ! str_starts_with($file['mime'], 'image/')) {
            return null;
        }

        return new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: $url);
    }

    /**
     * Wszystkie strony liścia, bez powtórzeń po internalid. Liczba pozycji ≠ total sklepu = B2bFatalException.
     * Przy okazji zapamiętuje cenę kontrolną (pierwsza pozycja z ceną konta niższą od katalogowej).
     *
     * @param  array{url: string, path: string}  $leaf
     * @return array<int, array<string, mixed>>
     */
    private function leafItems(array $leaf): array
    {
        $items = [];
        $offset = 0;
        $total = 0;
        do {
            try {
                $page = $this->client->itemsPage($leaf['url'], $offset);
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException $e) {
                throw new B2bFatalException('Nie udało się pobrać listy kategorii '.$leaf['path'].': '.$e->getMessage(), 0, $e);
            }
            $total = $page['total'];
            foreach ($page['items'] as $item) {
                $id = $item['internalid'] ?? null;
                if (! is_int($id) || $id <= 0) {
                    continue;
                }
                $items[$id] ??= $item;
                if ($this->controlId === null) {
                    $this->rememberControl($id, $item);
                }
            }
            $offset += count($page['items']);
        } while ($page['items'] !== [] && $offset < $total);

        if (count($items) !== $total) {
            throw new B2bFatalException('Lista kategorii '.$leaf['path'].' niepełna ('.count($items).' z '.$total.')');
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function rememberControl(int $id, array $item): void
    {
        $price = self::accountPrice($item);
        $catalog = $item['pricelevel1'] ?? null;
        if ($price !== null && $price > 0 && is_numeric($catalog) && $price < (float) $catalog - self::EPSILON) {
            $this->controlId = $id;
            $this->controlPrice = $price;
        }
    }

    private function sessionHolds(): bool
    {
        if ($this->controlId === null || $this->controlPrice === null) {
            return $this->client->sessionAlive();
        }
        try {
            $item = $this->client->item($this->controlId);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException) {
            return false;
        }
        $price = $item !== null ? self::accountPrice($item) : null;

        return $price !== null && abs($price - $this->controlPrice) < self::EPSILON;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function productFor(array $item, string $path): B2bRemoteProduct
    {
        $id = (string) $item['internalid'];
        $sku = trim((string) ($item['itemid'] ?? ''));
        $url = $this->client->productUrl((string) ($item['urlcomponent'] ?? ''));

        $matrix = $item['matrixchilditems_detail'] ?? null;
        if (is_array($matrix) && $matrix !== []) {
            return new B2bRemoteProduct(
                remoteId: $id,
                sku: $sku,
                name: $sku,
                category: $path,
                sourceUrl: $url,
                raw: ['status' => 'skipped', 'internalid' => $item['internalid'], 'itemid' => $sku, 'reason' => self::REASON_MATRIX],
            );
        }

        $raw = ['status' => 'ok'];
        foreach (self::RAW_FIELDS as $field) {
            if (array_key_exists($field, $item)) {
                $raw[$field] = $item[$field];
            }
        }

        return new B2bRemoteProduct(
            remoteId: $id,
            sku: $sku,
            name: self::name($item),
            category: $path,
            sourceUrl: $url,
            raw: $raw,
            // Kod towaru Bolle (itemid), dosłownie — sklep producenta, karta = jedna pozycja, więc to kod producenta
            // pozycji karty (internalid). internalid to wewnętrzny numer NetSuite, nie identyfikator wyrobu. Kodu
            // kreskowego (upccode) nie podajemy: atrapa testów (dane syntetyczne) go nie ma, a żywej odpowiedzi
            // fieldset=details pod tym kątem nie sprawdzono — bez potwierdzenia pola nie zgadujemy, czy to EAN sztuki.
            identifiers: $sku !== '' ? [new B2bRemoteIdentifier(
                type: ProductIdentifier::TYPE_MANUFACTURER_CODE,
                value: $sku,
                remoteId: $id,
                field: 'itemid',
            )] : [],
        );
    }

    /**
     * Nazwa rodziny (storedisplayname2, gdy pusta — displayname) + krótki opis, gdy go wyróżnia, np.
     * „TRYON BSSI – Copper safety glasses”. Bez rodziny — sam krótki opis.
     *
     * @param  array<string, mixed>  $item
     */
    private static function name(array $item): string
    {
        $family = trim((string) ($item['storedisplayname2'] ?? ''));
        if ($family === '') {
            $family = trim((string) ($item['displayname'] ?? ''));
        }
        $short = self::inlineText((string) ($item['storedescription'] ?? ''));

        if ($family === '') {
            return $short;
        }

        return $short !== '' && mb_strtolower($short) !== mb_strtolower($family) ? $family.' – '.$short : $family;
    }

    /**
     * Cena konta z pozycji: onlinecustomerprice_detail.onlinecustomerprice, a gdy jej brak — onlinecustomerprice.
     *
     * @param  array<string, mixed>  $item
     */
    private static function accountPrice(array $item): ?float
    {
        $detail = $item['onlinecustomerprice_detail'] ?? null;
        $value = is_array($detail) && is_numeric($detail['onlinecustomerprice'] ?? null)
            ? $detail['onlinecustomerprice']
            : ($item['onlinecustomerprice'] ?? null);

        return is_numeric($value) ? (float) $value : null;
    }

    /** HTML → linie tekstu (jak AnroB2bConnector::description): encje zdekodowane, &nbsp; → spacja, bez pustych linii. */
    private static function htmlToText(string $html): string
    {
        $text = preg_replace('#<\s*br\s*/?>#i', "\n", $html) ?? $html;
        $text = preg_replace('#<\s*li[^>]*>#i', "\n- ", $text) ?? $text;
        $text = preg_replace('#</\s*(p|div|h[1-6]|li|ul|ol|tr|table)\s*>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? $line);
            if ($line !== '' && $line !== '-') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /** Tekst w jednej linii (nazwa, wartość cechy). */
    private static function inlineText(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', self::htmlToText($html)));
    }
}
