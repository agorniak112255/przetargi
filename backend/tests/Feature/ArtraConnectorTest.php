<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Models\ProductPriceHistory;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Services\B2b\ArtraB2bConnector;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bRemoteShopField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Łącznik artra.pl na atrapie sklepu (Http::fake). Sklep jest publiczny i stoi na Shopify, więc atrapa
 * odwzorowuje to, co odczytaliśmy 17.09.2026: mapę nadrzędną wskazującą mapę produktów, `<handle>.json`
 * z tytułem, wariantami i galerią oraz HTML karty z blokiem parametrów (product-specs__*), akapitem
 * opisowym (product-description-text), tabelą rozmiarów i czterema załącznikami PDF.
 *
 * Sklep jest źródłem treści, nie cen: przebieg ma uzupełniać karty, które są już w katalogu z cennika.
 */
final class ArtraConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const PHOTO = 'https://cdn.shopify.com/s/files/1/0994/8672/8533/files/ARCASIO_732_616560_S1_P_ESD.png';

    private const SOLE_GRAPHIC = 'https://cdn.shopify.com/s/files/1/0994/8672/8533/files/Raptor-black.png';

    /** @var array<string, array{title: string, sizes: list<string>, images: list<string>}> handle => karta */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_zdjeciem_karty_jest_obraz_o_nazwie_produktu_a_nie_grafika_podeszwy(): void
    {
        // Sedno zmiany: galeria Shopify miesza zdjęcie tego modelu z grafikami technologii podeszwy, wspólnymi
        // dla wielu butów. Wzięcie pierwszego lepszego obrazu wstawiało na kartę cudzy model albo cudzy kolor.
        Storage::fake('public');
        $this->catalogCard('ARCASIO 732 616560 S1 P ESD');
        $this->shopProduct('3813781-arcasio-732-616560-s1-p-esd', 'ARCASIO 732 616560 S1 P ESD', images: [
            self::SOLE_GRAPHIC,
            self::PHOTO,
            'https://cdn.shopify.com/s/files/1/0994/8672/8533/files/320.jpg',
        ]);
        $this->fakeShop();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $product = Product::query()->where('sku', 'ARCASIO 732 616560 S1 P ESD')->sole();
        $this->assertSame(
            [self::PHOTO],
            ProductImage::query()->where('product_id', $product->id)->pluck('source_url')->all(),
        );
    }

    public function test_karta_bez_zdjecia_o_nazwie_produktu_zostaje_bez_zdjecia(): void
    {
        Storage::fake('public');
        $this->catalogCard('ARCASIO 732 616560 S1 P ESD');
        $this->shopProduct('3813781-arcasio-732-616560-s1-p-esd', 'ARCASIO 732 616560 S1 P ESD', images: [self::SOLE_GRAPHIC]);
        $this->fakeShop();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $product = Product::query()->where('sku', 'ARCASIO 732 616560 S1 P ESD')->sole();
        $this->assertSame(0, ProductImage::query()->where('product_id', $product->id)->count());
        $this->assertSame(0, $result['images']);
    }

    public function test_cztery_zalaczniki_pdf_maja_rodzaje_i_nazwy_po_polsku(): void
    {
        // Deklaracji zgodności żądają specyfikacje przetargowe — musi być przy karcie razem z adresem źródła.
        Storage::fake('public');
        $this->catalogCard('ARCASIO 732 616560 S1 P ESD');
        $this->shopProduct('3813781-arcasio-732-616560-s1-p-esd', 'ARCASIO 732 616560 S1 P ESD');
        $this->fakeShop();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $product = Product::query()->where('sku', 'ARCASIO 732 616560 S1 P ESD')->sole();
        $documents = ProductDocument::query()->where('product_id', $product->id)->get();

        $this->assertSame(
            [
                ['Karta produktu', ProductDocument::KIND_DATASHEET],
                ['Deklaracja zgodności UE', ProductDocument::KIND_CERTIFICATE],
                ['Deklaracja zgodności UE (28 języków)', ProductDocument::KIND_CERTIFICATE],
                ['Karta gwarancyjna', 'warranty'],
            ],
            $documents->sortBy('sort_order')->map(static fn (ProductDocument $d): array => [(string) $d->title, (string) $d->kind])->values()->all(),
        );
        // Adres źródła bez znacznika wersji (?v=…): to cache-buster Shopify, nie tożsamość pliku.
        $this->assertSame(
            'https://artra.pl/cdn/shop/files/PL-KP-ARCASIO_732_616560_S1_P_ESD.pdf',
            (string) $documents->firstWhere('title', 'Karta produktu')?->source_url,
        );
    }

    public function test_wiersze_tabelki_maja_doslowne_wartosci_ze_strony(): void
    {
        $this->shopProduct('3813781-arcasio-732-616560-s1-p-esd', 'ARCASIO 732 616560 S1 P ESD', sizes: ['EU 41', 'EU 42']);
        $this->fakeShop();

        $connector = ArtraB2bConnector::forAccount($this->account(), 0);
        $remote = iterator_to_array($connector->products(), false);

        $this->assertSame([
            ['Parametry', 'cholewka', 'wegańska NUVYA SKINYUM™ – Miękkość, która oddycha.'],
            ['Parametry', 'podnosek', 'stalowy LIBERYUM™'],
            // „norma” ma na stronie dwa oznaczenia rozdzielone <br> — każde osobnym wierszem, bo przy
            // dopasowaniu do wymagania przetargu liczy się pojedyncze oznaczenie
            ['Parametry', 'norma', 'EN ISO 20345:2011 S1 P SRC'],
            ['Parametry', 'norma', 'ESD według EN IEC 61340-4-3:2018'],
            // „Waga” to jedna wartość zapisana w dwóch wierszach znaczników — zostaje jedną wartością
            ['Parametry', 'Waga', '580 gramów dla rozmiaru 42'],
            ['Parametry', 'Rozmiary', 'EU 41, EU 42'],
            ['Przewodnik po rozmiarach', 'Rozmiar EU 41', '25,4'],
            ['Przewodnik po rozmiarach', 'Rozmiar EU 42', '26,0'],
        ], self::rows($connector->shopFields($remote[0])));
    }

    public function test_tabelka_trafia_do_karty_wyrobu_a_nie_do_opisu(): void
    {
        // W tym projekcie dane tabelaryczne dostawcy mają własną tabelę; opis zostaje prozą karty.
        Storage::fake('public');
        $this->catalogCard('ARCASIO 732 616560 S1 P ESD');
        $this->shopProduct('3813781-arcasio-732-616560-s1-p-esd', 'ARCASIO 732 616560 S1 P ESD');
        $this->fakeShop();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $product = Product::query()->where('sku', 'ARCASIO 732 616560 S1 P ESD')->sole();
        $description = (string) $product->description;
        $this->assertStringContainsString('Konstrukcja obuwia ARELAX', $description);
        $this->assertStringNotContainsString('EN ISO 20345:2011', $description);
        $this->assertStringNotContainsString('cholewka', $description);

        $card = ProductShopCard::query()->where('product_id', $product->id)->sole();
        $this->assertSame('https://artra.pl/products/3813781-arcasio-732-616560-s1-p-esd', (string) $card->source_url);
        $this->assertContains(
            ['name' => 'norma', 'value' => 'EN ISO 20345:2011 S1 P SRC'],
            self::sectionRows($card, 'Parametry'),
        );
    }

    public function test_brak_ceny_nie_pomija_pozycji_i_nie_zapisuje_zadnej_ceny(): void
    {
        Storage::fake('public');
        $card = $this->catalogCard('ARCASIO 732 616560 S1 P ESD');
        $this->shopProduct('3813781-arcasio-732-616560-s1-p-esd', 'ARCASIO 732 616560 S1 P ESD');
        $this->fakeShop();

        $connector = ArtraB2bConnector::forAccount($this->account(), 0);
        $remote = iterator_to_array($connector->products(), false);
        $this->assertNull($connector->price($remote[0]));

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(0, $result['skipped'], implode(' | ', $result['errors']));
        $this->assertSame(1, $result['updated']);
        // cena karty z cennika nietknięta, żadnego slotu konta i żadnego wpisu w historii cen
        $this->assertSame('519.00', (string) $card->refresh()->catalog_price_net);
        $this->assertSame('300.00', (string) $card->purchase_price);
        $this->assertSame(0, ProductSourcePrice::query()->where('product_id', $card->id)->count());
        $this->assertSame(0, ProductPriceHistory::query()->where('product_id', $card->id)->count());
    }

    public function test_pozycja_bez_karty_w_katalogu_jest_pomijana_i_nie_zaklada_nowej(): void
    {
        // Katalog buduje cennik. Sklep producenta ma też modele, których nie mamy od kogo kupić — taka pozycja
        // ma czekać na cennik, a nie dokładać karty bez ceny.
        Storage::fake('public');
        $this->catalogCard('ARCASIO 732 616560 S1 P ESD');
        $this->shopProduct('3813781-arcasio-732-616560-s1-p-esd', 'ARCASIO 732 616560 S1 P ESD');
        $this->shopProduct('3815338-aryel-320-618080-s3l-esd', 'ARYEL 320 618080 S3L ESD');
        $this->fakeShop();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, Product::query()->count());
        $this->assertContains(
            'ARYEL 320 618080 S3L ESD: brak karty w katalogu — cennik jej nie zawiera',
            $result['errors'],
            implode(' | ', $result['errors']),
        );
    }

    public function test_rozmiary_ze_sklepu_trafiaja_do_podsumowania_wersji(): void
    {
        Storage::fake('public');
        $card = $this->catalogCard('ARCASIO 732 616560 S1 P ESD');
        $this->shopProduct(
            '3813781-arcasio-732-616560-s1-p-esd',
            'ARCASIO 732 616560 S1 P ESD',
            sizes: ['EU 41', 'EU 42', 'EU 43'],
        );
        $this->fakeShop();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame('EU 41, EU 42, EU 43', (string) $card->refresh()->variant_summary);
    }

    /**
     * Wiersze karty wyrobu jako proste trójki — czytelniej porównać niż obiekty.
     *
     * @param  list<B2bRemoteShopField>  $fields
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function rows(array $fields): array
    {
        return array_map(
            static fn (B2bRemoteShopField $field): array => [$field->section, $field->name, $field->value],
            $fields,
        );
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private static function sectionRows(ProductShopCard $card, string $section): array
    {
        foreach ((array) $card->fields as $entry) {
            if (($entry['section'] ?? null) === $section) {
                return $entry['rows'];
            }
        }

        return [];
    }

    /** Karta, którą katalog ma już z cennika — tylko takim przebieg dokłada treść. */
    private function catalogCard(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $sku,
            'manufacturer' => 'ARTRA',
            'catalog_price_net' => 519.00,
            'purchase_price' => 300.00,
            'currency' => 'PLN',
        ]);
    }

    /**
     * @param  list<string>  $sizes
     * @param  list<string>|null  $images
     */
    private function shopProduct(string $handle, string $title, array $sizes = ['EU 41', 'EU 42'], ?array $images = null): void
    {
        $this->products[$handle] = [
            'title' => $title,
            'sizes' => $sizes,
            'images' => $images ?? [self::PHOTO, self::SOLE_GRAPHIC],
        ];
    }

    private function fakeShop(): void
    {
        $products = $this->products;

        Http::fake(function (Request $request) use ($products) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);

            if ($path === '/sitemap.xml') {
                return Http::response(self::sitemapIndex([
                    'https://artra.pl/sitemap_agentic_discovery.xml',
                    'https://artra.pl/sitemap_products_1.xml?from=1&to=2',
                    'https://artra.pl/sitemap_pages_1.xml?from=1&to=2',
                ]));
            }

            if ($path === '/sitemap_products_1.xml') {
                return Http::response(self::urlset([
                    'https://artra.pl/',
                    ...array_map(static fn (string $h): string => 'https://artra.pl/products/'.$h, array_keys($products)),
                ]));
            }

            if (preg_match('#^/products/([^/]+)\.json$#', $path, $m) === 1) {
                $card = $products[$m[1]] ?? null;

                return $card === null
                    ? Http::response('Not found', 404)
                    : Http::response(self::productJson($m[1], $card));
            }

            if (preg_match('#^/products/([^/]+)$#', $path, $m) === 1) {
                $card = $products[$m[1]] ?? null;

                return $card === null
                    ? Http::response('Not found', 404)
                    : Http::response(self::productHtml($card['title']));
            }

            if (str_ends_with($path, '.pdf')) {
                // każdy plik musi mieć inną treść — zapis rozpoznaje identyczne pliki jako ten sam
                return Http::response('%PDF-1.4 '.$path, 200, ['Content-Type' => 'application/pdf']);
            }
            if (str_ends_with($path, '.png') || str_ends_with($path, '.jpg')) {
                return Http::response(self::pngBytes($path), 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('Nie znaleziono', 404);
        });
    }

    /**
     * @param  array{title: string, sizes: list<string>, images: list<string>}  $card
     */
    private static function productJson(string $handle, array $card): string
    {
        return (string) json_encode([
            'product' => [
                'id' => 10284519031125,
                'title' => $card['title'],
                'handle' => $handle,
                'vendor' => 'Artra',
                // u ARTRY pole jest puste — opis trzeba brać z HTML karty
                'body_html' => null,
                'options' => [['name' => 'Size', 'values' => $card['sizes']]],
                'variants' => array_map(
                    static fn (string $size): array => ['title' => $size, 'option1' => $size, 'sku' => null, 'price' => '519.00'],
                    $card['sizes'],
                ),
                'images' => array_map(
                    static fn (string $src): array => ['src' => $src.'?v=1764059518'],
                    $card['images'],
                ),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Karta produktu w znacznikach szablonu sklepu — blok parametrów bywa na stronie dwa razy. */
    private static function productHtml(string $title): string
    {
        $file = str_replace(' ', '_', $title);
        $specs = '<div class="product-specs">'
            .'<div class="product-specs__row">'
            .'<span class="product-specs__key"> cholewka </span>'
            .'<span class="product-specs__value">wegańska NUVYA SKINYUM™ – Miękkość, która oddycha.</span>'
            .'</div>'
            .'<div class="product-specs__row">'
            .'<span class="product-specs__key"> podnosek </span>'
            .'<span class="product-specs__value">stalowy LIBERYUM™</span>'
            .'</div>'
            .'<div class="product-specs__row">'
            .'<span class="product-specs__key"> norma </span>'
            .'<span class="product-specs__value"> EN ISO 20345:2011 S1 P SRC <br> ESD według EN IEC 61340-4-3:2018 </span>'
            .'</div>'
            .'<div class="product-specs__row">'
            .'<span class="product-specs__key"> Waga </span>'
            .'<span class="product-specs__value"> 580 gramów dla rozmiaru 42 </span>'
            .'</div>'
            .'</div>'
            .'<div class="product-description-text"> Konstrukcja obuwia ARELAX® zapewnia przestrzeń dla wszystkich palców'
            .' i swobodę ruchu przez cały dzień. </div>';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
            .'<h1>'.$title.'</h1>'
            // szablon powtarza blok parametrów w szybkim podglądzie — tabelka nie może mieć przez to duplikatów
            .$specs.$specs
            .'<div class="size-guide-drawer"><table class="size-guide-drawer__table">'
            .'<thead><tr><th class="sg-col-head">Rozmiar EU</th><th class="sg-col-head">Długość stopy</th></tr></thead>'
            .'<tbody>'
            .'<tr><td class="sg-cell sg-cell--size"><strong>41</strong></td><td class="sg-cell">25,4</td></tr>'
            .'<tr><td class="sg-cell sg-cell--size"><strong>42</strong></td><td class="sg-cell">26,0</td></tr>'
            .'</tbody></table></div>'
            .'<div class="accordion__content"><div class="prose">'
            .'<p><a href="//artra.pl/cdn/shop/files/PL-KP-'.$file.'.pdf?v=12125936432765682288" target="_blank">Karta produktu</a></p>'
            .'<p><a href="//artra.pl/cdn/shop/files/PL_'.$file.'_-_deklaracja_zgodnosci_UE.pdf?v=14810436873708132624">Deklaracja zgodności</a></p>'
            .'<p><a href="//artra.pl/cdn/shop/files/ALL_'.$file.'_-_EU_declaration_of_conformity_ALL.pdf?v=7396110642925598725">Deklaracja zgodności – 28 języków</a></p>'
            .'<p><a href="https://cdn.shopify.com/s/files/1/0994/8672/8533/files/karta-gwarancyjna.pdf?v=1764589884">Karta gwarancyjna</a></p>'
            .'</div></div>'
            .'</body></html>';
    }

    /** Najmniejszy poprawny PNG; wypełnienie różni się adresem, żeby zapis nie uznał zdjęć za ten sam plik. */
    private static function pngBytes(string $path): string
    {
        $image = imagecreatetruecolor(2, 2);
        imagefill($image, 0, 0, (int) (crc32($path) & 0xFFFFFF));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * @param  list<string>  $urls
     */
    private static function sitemapIndex(array $urls): string
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            $body .= '<sitemap><loc>'.htmlspecialchars($url, ENT_XML1).'</loc></sitemap>';
        }

        return $body.'</sitemapindex>';
    }

    /**
     * @param  list<string>  $urls
     */
    private static function urlset(array $urls): string
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            $body .= '<url><loc>'.htmlspecialchars($url, ENT_XML1).'</loc></url>';
        }

        return $body.'</urlset>';
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => 'ARTRA'],
            ['sites' => ['artra.pl'], 'connector' => 'artra'],
        )->fresh();
    }
}
