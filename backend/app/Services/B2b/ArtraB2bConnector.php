<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use RuntimeException;

/**
 * artra.pl — publiczny sklep producenta na Shopify. Łącznik jest źródłem treści, nie cen (B2bContentOnlySite):
 * sklep pokazuje ceny detaliczne, a ceny zakupu biorą się z cennika, więc przebieg dokłada istniejącym kartom
 * opis, tabelkę parametrów, zdjęcie i dokumenty, a nowych kart nie zakłada.
 *
 * Kartę wiąże z katalogiem tytuł produktu ze sklepu: ARTRA nazywa produkt tak samo w sklepie i w cenniku
 * („ARAGON 920 6060 S2”), a innego wspólnego kodu sklep nie podaje — wariant Shopify ma tylko rozmiar, bez SKU.
 *
 * Zdjęcie wyrobu rozpoznajemy po nazwie pliku równej nazwie produktu z podkreśleniami zamiast spacji
 * („ARCASIO_732_616560_S1_P_ESD.png”) i ono zostaje zdjęciem głównym — wzięcie pierwszego lepszego obrazu
 * z galerii wstawiało na kartę but innego modelu albo koloru. Pozostałe ujęcia tej samej galerii karta
 * dostaje po nim (patrz galleryPhotos), bo to naprawdę ten wyrób: podeszwa i detale serii.
 *
 * Tabelki parametrów nie wklejamy do opisu: dane tabelaryczne dostawcy mają w tym projekcie własne miejsce
 * (product_shop_cards), a opis zostaje prozą karty.
 */
final class ArtraB2bConnector implements B2bConnector, B2bContentOnlySite, B2bDocumentSource, B2bImageGallery, B2bManufacturerSite, B2bPublicSite, B2bRunSummaryAware, B2bShopFieldSource
{
    /** Blok parametrów szablonu sklepu: wiersz, etykieta i wartość. */
    private const SPEC_ROW_CLASS = 'product-specs__row';

    private const SPEC_KEY_CLASS = 'product-specs__key';

    private const SPEC_VALUE_CLASS = 'product-specs__value';

    /** Akapit opisowy o konstrukcji ARELAX i technologiach YUM — jedyna proza na karcie. */
    private const DESCRIPTION_CLASS = 'product-description-text';

    private const SIZE_TABLE_CLASS = 'size-guide-drawer__table';

    private const SECTION_PARAMETERS = 'Parametry';

    private const SECTION_SIZE_GUIDE = 'Przewodnik po rozmiarach';

    /** Nazwa pliku grafiki wstawianej do galerii przez szablon sklepu, a nie przez zdjęcia wyrobu. */
    private const TEMPLATE_IMAGE_PREFIX = 'product_gallery';

    private int $total = 0;

    /** @var list<string> nazwy produktów, przy których sklep nie ma zdjęcia o nazwie produktu */
    private array $withoutPhoto = [];

    public function __construct(
        private readonly ArtraB2bClient $client,
        private readonly ShopifyPublicCatalog $catalog = new ShopifyPublicCatalog(ArtraB2bClient::HOST),
    ) {}

    public static function key(): string
    {
        return 'artra';
    }

    public static function label(): string
    {
        return 'ARTRA';
    }

    public static function host(): string
    {
        return ArtraB2bClient::HOST;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new ArtraB2bClient($delayMs));
    }

    /** Sklep jest publiczny — nie ma się gdzie logować. */
    public function login(): void {}

    public function products(): iterable
    {
        $handles = $this->handles();
        $this->total = count($handles);

        foreach ($handles as $handle) {
            yield $this->productFor($handle);
        }
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    /** Witryna nalezy do tej marki — tylko jej karty wolno nadpisac opisem stad. */
    public static function ownBrand(): string
    {
        return 'ARTRA';
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'ARTRA';
    }

    /**
     * Sklep podaje wyłącznie cenę detaliczną z VAT-em, więc łącznik nie wnosi żadnej ceny — to znaczenie
     * B2bContentOnlySite i normalny stan, nie powód pominięcia pozycji.
     *
     * Karta, której strony nie udało się odczytać, nie ma czym zaktualizować katalogu: powód pominięcia trafia
     * tutaj, bo to pierwsze miejsce, w którym synchronizacja pyta łącznik o pozycję.
     */
    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'strona produktu nieodczytana'));
        }

        return null;
    }

    /**
     * Akapit opisowy ze strony, dosłownie. Parametrów z bloku obok nie dopisujemy — idą do product_shop_cards
     * jako wiersze tabelki. Karta bez akapitu daje pusty opis, który niczego nie nadpisuje.
     */
    public function description(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['description'] ?? '');
    }

    /**
     * Tabelka u dostawcy: pary z bloku parametrów, rozmiary oferowane w sklepie i przewodnik po rozmiarach.
     * Wartości dosłownie ze strony — jednostek, norm i zakresów nie normalizujemy.
     *
     * Wielolinijkowa wartość (pole „norma”: oznaczenie obuwia i osobno oznaczenie ESD) rozpada się na tyle
     * wierszy, ile linii ma na stronie: przy dopasowaniu do wymagania przetargu liczy się pojedyncze oznaczenie,
     * więc musi dać się odczytać osobno. Powtórzona etykieta jest w karcie dozwolona (B2bRemoteShopField).
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
        foreach ($raw['specs'] ?? [] as $row) {
            foreach ($row['lines'] as $line) {
                $fields[] = new B2bRemoteShopField(self::SECTION_PARAMETERS, (string) $row['name'], (string) $line);
            }
        }

        // Rozmiary jako pełna lista ze sklepu, a nie zakres „35–48”: sklep wymienia konkretne warianty i nie
        // obiecuje, że są ciągłe, więc sklejenie ich w zakres dopisywałoby rozmiary, których może nie być.
        $sizes = $raw['sizes'] ?? [];
        if ($sizes !== []) {
            $fields[] = new B2bRemoteShopField(self::SECTION_PARAMETERS, 'Rozmiary', implode(', ', $sizes));
        }

        // Przewodnik po rozmiarach: etykieta wiersza to nagłówek kolumny i wartość z niej („Rozmiar EU 42”),
        // a wartością zostaje druga kolumna dosłownie („26,0”) — jednostki strona przy tabeli nie podaje.
        $table = $raw['size_table'] ?? ['headers' => [], 'rows' => []];
        $headers = $table['headers'] ?? [];
        foreach ($table['rows'] ?? [] as $row) {
            if (count($row) < 2) {
                continue;
            }
            $label = trim(((string) ($headers[0] ?? '')).' '.$row[0]);
            $fields[] = new B2bRemoteShopField(self::SECTION_SIZE_GUIDE, $label, (string) $row[1]);
        }

        return $fields;
    }

    /**
     * Załączniki PDF karty. Rodzaj i nazwę bierzemy z nazwy pliku, a nie z tekstu odnośnika: nazwy plików mają
     * u ARTRY stały wzorzec, a tekst odnośnika bywa tłumaczony i zmieniany razem z szablonem sklepu.
     * Pliku spoza wzorca nie nazywamy po swojemu — zostaje z nazwą ze strony i rodzajem „inne”.
     *
     * @return list<B2bRemoteDocument>
     */
    public function documents(B2bRemoteProduct $product): array
    {
        $documents = [];
        foreach ($product->raw['documents'] ?? [] as $file) {
            $url = (string) $file['url'];
            [$title, $kind] = self::documentKind(ShopifyPublicCatalog::fileStem($url), (string) $file['title']);
            if ($title === '') {
                continue;
            }
            $documents[] = new B2bRemoteDocument(title: $title, sourceUrl: $url, kind: $kind);
        }

        return $documents;
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    public function documentBytes(B2bRemoteDocument $document): array
    {
        return $this->client->fileBytes($document->sourceUrl);
    }

    /**
     * Galeria karty ze sklepu: zdjęcie wyrobu na pierwszym miejscu, po nim reszta ujęć tej karty.
     * Pusta lista = sklep zdjęcia tego modelu nie ma (patrz galleryPhotos).
     *
     * @return list<string>
     */
    public function imageUrls(B2bRemoteProduct $product): array
    {
        return $product->raw['image_urls'] ?? [];
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
     * @return list<string>
     */
    public function runSummary(): array
    {
        if ($this->withoutPhoto === []) {
            return [];
        }

        return ['Kart bez zdjęcia o nazwie produktu (sklep ma przy nich tylko grafiki technologii): '
            .count($this->withoutPhoto).' — '.implode('; ', array_slice($this->withoutPhoto, 0, 20))
            .(count($this->withoutPhoto) > 20 ? ' i '.(count($this->withoutPhoto) - 20).' więcej' : '')];
    }

    /**
     * Klucze kart z map produktów, w kolejności z map i bez powtórzeń.
     *
     * @return list<string>
     */
    private function handles(): array
    {
        $maps = $this->catalog->productSitemapUrls($this->client->body($this->catalog->sitemapUrl()));
        if ($maps === []) {
            throw new RuntimeException('Mapa strony '.ArtraB2bClient::HOST.' nie wskazuje żadnej mapy produktów');
        }

        $handles = [];
        foreach ($maps as $map) {
            foreach ($this->catalog->handles($this->client->body($map)) as $handle) {
                $handles[$handle] = true;
            }
        }

        if ($handles === []) {
            throw new RuntimeException('Mapa produktów '.ArtraB2bClient::HOST.' nie zawiera żadnej karty');
        }

        return array_keys($handles);
    }

    private function productFor(string $handle): B2bRemoteProduct
    {
        $url = $this->catalog->productUrl($handle);

        try {
            $json = $this->client->bodyOrNull($this->catalog->productJsonUrl($handle));
            $html = $json === null ? null : $this->client->bodyOrNull($url);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return self::skipped($handle, $url, 'nie udało się pobrać karty: '.$e->getMessage());
        }

        if ($json === null || $html === null) {
            return self::skipped($handle, $url, 'karta nie istnieje (martwy wpis w mapie strony)');
        }

        $product = ShopifyPublicCatalog::product($json);
        if ($product === null) {
            return self::skipped($handle, $url, 'odpowiedź sklepu nie jest kartą produktu');
        }

        $name = trim((string) ($product['title'] ?? ''));
        if ($name === '') {
            return self::skipped($handle, $url, 'karta bez nazwy produktu');
        }

        $images = self::galleryPhotos($name, $this->catalog->imageUrls($product));
        if ($images === []) {
            $this->withoutPhoto[] = $name;
        }
        $sizes = ShopifyPublicCatalog::variantTitles($product);

        return new B2bRemoteProduct(
            remoteId: $handle,
            // Nazwa produktu jest jednocześnie kodem karty: tak samo brzmi pozycja w cenniku ARTRY.
            sku: $name,
            name: $name,
            sourceUrl: $url,
            // null (a nie '') gdy sklep nie podał wariantów: pusta lista nie jest wiadomością o tym,
            // że karta rozmiarów nie ma — nie ma po co czyścić tego, co karta już ma
            variantSummary: $sizes === [] ? null : implode(', ', $sizes),
            raw: [
                'status' => 'ok',
                'description' => ShopifyPublicCatalog::blockText($html, self::DESCRIPTION_CLASS),
                'specs' => ShopifyPublicCatalog::labelledRows(
                    $html,
                    self::SPEC_ROW_CLASS,
                    self::SPEC_KEY_CLASS,
                    self::SPEC_VALUE_CLASS,
                ),
                'size_table' => ShopifyPublicCatalog::table($html, self::SIZE_TABLE_CLASS),
                'sizes' => $sizes,
                'documents' => $this->catalog->fileLinks($html, 'pdf'),
                'image_urls' => $images,
            ],
        );
    }

    /**
     * Galeria karty: najpierw zdjęcia wyrobu (nazwa pliku równa nazwie produktu z podkreśleniami zamiast
     * spacji), potem pozostałe ujęcia tej karty w kolejności ze sklepu — zdjęcie podeszwy nazwane technologią
     * i kolorem („Gripper-black.png”) oraz ujęcia serii z numerem modelu w nazwie („310-1.jpg”, „320Air-2.jpg”).
     * To jest to, co sklep pokazuje w galerii karty; z paska wyboru modelu obok ceny nie bierzemy niczego.
     *
     * Odpadają dwie rzeczy, których do tej karty przypisać nie można:
     * - plik nazwany nazwą innego modelu — sklep wgrał np. na karcie „ARAUKAN 940 1010 O2 FO” plik
     *   „ARAUKAN_940_1010_O2_CI_FO.png” i nie wiadomo, który z dwóch butów jest na obrazku,
     * - grafika szablonu sklepu („product_gallery_german_design_award.png” — odznaka nagrody, nie wyrób).
     *
     * Bez zdjęcia o nazwie produktu karta nie dostaje nic: pierwsze zdjęcie zostaje w katalogu zdjęciem
     * głównym, a sama podeszwa i ujęcia serii nie pokazują, jak wygląda ten model.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    private static function galleryPhotos(string $name, array $urls): array
    {
        $stem = mb_strtolower(str_replace(' ', '_', $name));
        $model = preg_match('/\d+/', $name, $m) === 1 ? $m[0] : '';

        $photos = [];
        $rest = [];
        foreach ($urls as $url) {
            $file = mb_strtolower(ShopifyPublicCatalog::fileStem($url));
            if ($file === $stem) {
                $photos[] = $url;
            } elseif (self::belongsToCard($file, $model)) {
                $rest[] = $url;
            }
        }

        return $photos === [] ? [] : array_merge($photos, $rest);
    }

    /**
     * Czy plik z galerii jest ujęciem tej karty. Grafika technologii podeszwy nie ma w nazwie żadnej cyfry,
     * a ujęcie serii zaczyna się numerem modelu z nazwy produktu — nazwa z cyframi spoza tego wzorca to plik
     * innego modelu („320.jpg” na karcie ARCASIO 732) albo grafika szablonu sklepu.
     */
    private static function belongsToCard(string $fileStem, string $model): bool
    {
        if (str_starts_with($fileStem, self::TEMPLATE_IMAGE_PREFIX)) {
            return false;
        }
        if (preg_match('/\d/', $fileStem) !== 1) {
            return true;
        }

        return $model !== '' && preg_match('/^'.preg_quote($model, '/').'(?!\d)/', $fileStem) === 1;
    }

    /**
     * Nazwa i rodzaj pliku po wzorcu jego nazwy. Karta produktu jest kartą techniczną (jej tekst trafia też do
     * opisu karty), obie deklaracje to dokument dla przetargu, karta gwarancyjna jest wspólna dla całego sklepu.
     *
     * @return array{0: string, 1: string} nazwa po polsku i ProductDocument::KIND_*
     */
    private static function documentKind(string $stem, string $linkTitle): array
    {
        $lower = mb_strtolower($stem);

        return match (true) {
            str_starts_with($lower, 'pl-kp-') => ['Karta produktu', ProductDocument::KIND_DATASHEET],
            str_ends_with($lower, '-_eu_declaration_of_conformity_all') => ['Deklaracja zgodności UE (28 języków)', ProductDocument::KIND_CERTIFICATE],
            str_ends_with($lower, '-_deklaracja_zgodnosci_ue') => ['Deklaracja zgodności UE', ProductDocument::KIND_CERTIFICATE],
            $lower === 'karta-gwarancyjna' => ['Karta gwarancyjna', ProductDocument::KIND_WARRANTY],
            default => [trim($linkTitle), ProductDocument::KIND_OTHER],
        };
    }

    /**
     * Pozycja, której nie da się zapisać. Kod i nazwa muszą być niepuste (B2bCatalogSync sprawdza je przed
     * price(), a dopiero price() zna prawdziwy powód) — zastępczo idzie klucz karty ze sklepu; do zapisu
     * i tak nie dochodzi, bo price() rzuca wyjątek z powodem.
     */
    private static function skipped(string $handle, string $url, string $reason): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: $handle,
            sku: $handle,
            name: $handle,
            sourceUrl: $url,
            raw: ['status' => 'skipped', 'reason' => $reason],
        );
    }
}
