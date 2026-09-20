<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\ProductController;
use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Services\B2b\AtgB2bConnector;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\Presta\PrestaDescriptionHtml;
use App\Support\BhpAttributeNormalizer;
use App\Support\ManufacturerNormFacts;
use App\Support\RequirementCheck\CardSource;
use App\Support\RequirementCheck\CardSources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Łącznik atg-glovesolutions.com na atrapie witryny (Http::fake). Atrapa odwzorowuje to, co odczytaliśmy
 * 20.09.2026: mapę nadrzędną z trzema stronami adresów, kartę artykułu z danymi strukturalnymi schema.org
 * (numer artykułu, zdjęcie, normy z poziomami), akapitem wstępu, blokami cech, tabelką „Informacje
 * o produkcie”, listą „Funkcje” i szcześcioma plikami pod /download/{uuid}.
 *
 * Witryna jest źródłem treści i norm, nie cen: przebieg uzupełnia karty, które są już w katalogu z cennika.
 */
final class AtgConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = '34-8753';

    private const URL = 'https://www.atg-glovesolutions.com/pl/products/maxiflex/maxiflex-cut/34-8753';

    private const PHOTO = 'https://www.atg-glovesolutions.com/sites/default/files/atg-datahub/MaxiFlex_Cut_34_8753_2017_12_LR.png';

    /**
     * Pliki karty tak, jak je podaje witryna: adres jest samym identyfikatorem, podpis odnośnika stoi na stronie
     * (razem z literówką „charakterystki”), a nazwa pliku dochodzi dopiero w nagłówku pobrania. Kolejność jest
     * kolejnością ze strony — inną niż ta, w której pliki mają stanąć przy karcie.
     *
     * „Food declaration…” to plik, którego podpisu łącznik nie zna: rodzaj ma wyjść z nazwy pliku.
     *
     * @var array<string, array{0: string, 1: string}> identyfikator => [podpis odnośnika, nazwa pliku]
     */
    private const FILES = [
        '433b09da-cbeb' => ['Pobierz kartę produktu', 'catalog_maxiflex-cut_34-8753_pl.pdf'],
        '854925cd-adfe' => ['Deklaracja zgodności UE', 'eu_declaration_34-8753_bp-60169313_pl.pdf'],
        '3106772b-5817' => ['Deklaracja zgodności UKCA', 'ukca_declaration_34-8753_a7-60169570_pl.pdf'],
        '88042e39-1190' => ['Food declaration of product compliance', 'food_declaration_34-8753_pl.pdf'],
        'd2faffaf-8280' => ['Karta charakterystyki', 'material_safety_data_sheet_34-8753_bp-60169313_pl.pdf'],
        'd184053f-1f73' => ['Karta charakterystki produktu', 'product_data_sheet_maxiflex-cut_34-8753_pl.pdf'],
        'ca270c24-6837' => ['Instrukcje dotyczące prania', 'laundry_instructions_34-8753_bp-60169313_pl.pdf'],
    ];

    /** @var array<string, array{norms: list<array{0: string, 1: string|null}>}> numer artykułu => karta */
    private array $cards = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_karta_dostaje_proze_tabelke_zdjecie_i_pliki_producenta(): void
    {
        Storage::fake('public');
        $product = $this->catalogCard(self::SKU);
        $this->siteCard(self::SKU);
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $result['skipped'], implode(' | ', $result['errors']));
        $product->refresh();

        // opis: akapit wstępu i bloki cech, bez tabelki parametrów
        $this->assertStringContainsString('ultracienką, elastyczną konstrukcję MaxiFlex', (string) $product->description);
        $this->assertStringContainsString('Zwiększona ochrona przed przecięciem', (string) $product->description);
        $this->assertStringNotContainsString('0.80 mm', (string) $product->description);
        $this->assertStringNotContainsString('EN 388', (string) $product->description);

        // tabelka u dostawcy: parametry, cechy i normy — dosłownie ze strony
        $card = ProductShopCard::query()->where('product_id', $product->id)->sole();
        $this->assertSame(self::URL, (string) $card->source_url);
        $this->assertContains(
            ['name' => 'Grubość warstwy dłoniowej', 'value' => '0.80 mm'],
            self::sectionRows($card, 'Informacje o produkcie'),
        );
        // zakres rozmiarów zostaje zakresem — rozpisanie go dodałoby wersje, których dostawca może nie mieć
        $this->assertContains(
            ['name' => 'Rozmiary', 'value' => '6 (XS) - 12 (3XL)'],
            self::sectionRows($card, 'Informacje o produkcie'),
        );
        $this->assertNull($product->variant_summary);
        $this->assertContains(
            ['name' => 'Funkcje', 'value' => 'Bez silikonu'],
            self::sectionRows($card, 'Funkcje'),
        );
        $this->assertContains(
            ['name' => 'EN 388:2016 + A1:2018', 'value' => '4331B'],
            self::sectionRows($card, 'Normy'),
        );

        // zdjęcie wyrobu ze danych strukturalnych — bez grafik technologii z galerii
        $this->assertSame(
            [self::PHOTO],
            ProductImage::query()->where('product_id', $product->id)->pluck('source_url')->all(),
        );

        // pliki: karta techniczna przed katalogową (z niej bierzemy tekst do indeksu), deklaracje,
        // karta charakterystyki i instrukcja — nazwy po polsku, adresy w polskiej wersji witryny
        $documents = ProductDocument::query()->where('product_id', $product->id)->orderBy('sort_order')->get();
        $this->assertSame([
            ['Karta techniczna produktu', ProductDocument::KIND_DATASHEET],
            ['Karta produktu', ProductDocument::KIND_DATASHEET],
            ['Deklaracja zgodności UE', ProductDocument::KIND_CERTIFICATE],
            ['Deklaracja zgodności UKCA', ProductDocument::KIND_CERTIFICATE],
            ['Karta charakterystyki', ProductDocument::KIND_OTHER],
            ['Instrukcja prania', ProductDocument::KIND_MANUAL],
            // podpisu tego pliku łącznik nie zna — zostaje z nazwą ze strony i rodzajem „inne”
            ['Food declaration of product compliance', ProductDocument::KIND_OTHER],
        ], $documents->map(static fn (ProductDocument $d): array => [(string) $d->title, (string) $d->kind])->all());
        $this->assertStringStartsWith(
            'https://www.atg-glovesolutions.com/pl/download/',
            (string) $documents->firstWhere('title', 'Deklaracja zgodności UE')?->source_url,
        );

        // witryna producenta nie wnosi żadnej ceny
        $this->assertSame(0, ProductSourcePrice::query()->where('product_id', $product->id)->count());
        $this->assertSame('300.00', (string) $product->purchase_price);
    }

    public function test_poziom_en388_producenta_bije_kod_z_opisu_sklepowego(): void
    {
        Storage::fake('public');
        // Tak wygląda karta 34-274 przed przebiegiem: kod EN 388 wyciągnięty z opisu sklepu (4121A),
        // podczas gdy producent podaje 3121A. Od tego kodu zależy dopasowanie do wymagania przetargu.
        $product = $this->catalogCard(self::SKU);
        $product->enrichment_payload = [
            'attributes' => ['poziomy_en388' => '4121A', 'normy_en' => ['EN 388:2016 + A1:2018 4121A']],
            'primary_source_url' => 'https://sklep.example/rekawice-atg',
        ];
        $product->save();
        $this->siteCard(self::SKU);
        $this->fakeSite();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $attributes = app(BhpAttributeNormalizer::class)->forProduct($product->refresh());
        $this->assertSame('4331B', $attributes['poziomy_en388']);
        // sprzeczny wariant tej samej normy ze słabszego źródła nie stoi obok wersji producenta
        $this->assertSame(['EN 388:2016 + A1:2018 4331B'], array_values(array_filter(
            $attributes['normy_en'],
            static fn (string $norm): bool => str_contains($norm, 'EN 388'),
        )));
        // ślad do źródła: adres karty producenta i data odczytu
        $this->assertSame(self::URL, ManufacturerNormFacts::sourceUrl($product->manufacturer_norms));
        $this->assertNotNull($product->manufacturer_norms['source']['synced_at'] ?? null);
    }

    public function test_normy_producenta_sa_cytowane_przy_weryfikacji_wymagania(): void
    {
        Storage::fake('public');
        $product = $this->catalogCard(self::SKU);
        $this->siteCard(self::SKU);
        $this->fakeSite();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $quotes = array_values(array_map(
            static fn (CardSource $source): string => $source->text,
            array_filter(
                CardSources::fromProduct($product->refresh()),
                static fn (CardSource $source): bool => $source->source === CardSource::MANUFACTURER,
            ),
        ));
        $this->assertContains('EN 388:2016 + A1:2018: 4331B', $quotes);
        // norma bez poziomu zostaje cytatem bez dopisywania wartości
        $this->assertContains('EN ISO 21420:2020+A1:2024', $quotes);
        // ANSI/ISEA nie udaje normy EN, ale jest cytowalne
        $this->assertContains('ANSI/ISEA 105 (2016): A2', $quotes);
    }

    public function test_karta_w_panelu_i_opis_na_sklep_pokazuja_poziom_producenta(): void
    {
        Storage::fake('public');
        // Karta z poziomem EN 388 wyciągniętym z opisu sklepowego — tak wyglądały karty ATG przed przebiegiem.
        $product = $this->catalogCard(self::SKU);
        $product->enrichment_payload = [
            'attributes' => ['poziomy_en388' => '4121A', 'normy_en' => ['EN 388:2016 + A1:2018 4121A']],
            'norms' => ['EN 388:2016 + A1:2018 4121A', 'EN 420:2003+A1:2009'],
            'specs' => ['Powłoka: nitryl'],
        ];
        $product->save();
        $this->siteCard(self::SKU);
        $this->fakeSite();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        // panel: karta pokazuje to, czym liczy się dopasowanie — poziom producenta, bez sprzecznego wariantu
        $shown = app(ProductController::class)
            ->show($product->refresh())
            ->getData(true);
        $this->assertSame('4331B', $shown['enrichment_payload']['attributes']['poziomy_en388'] ?? null);
        $this->assertNotContains('EN 388:2016 + A1:2018 4121A', $shown['enrichment_payload']['norms'] ?? []);
        $this->assertContains('EN 388:2016 + A1:2018 4331B', $shown['enrichment_payload']['norms'] ?? []);
        // payload w bazie zostaje zapisem tego, co przyniosło wzbogacanie
        $this->assertSame('4121A', $product->refresh()->enrichment_payload['attributes']['poziomy_en388']);

        // opis wychodzący na nasz sklep: ten sam poziom, bez starego kodu ze sklepowej karty
        $html = app(PrestaDescriptionHtml::class)->fromProduct($product->refresh());
        $this->assertStringContainsString('4331B', $html);
        $this->assertStringNotContainsString('4121A', $html);
    }

    public function test_karta_obcej_marki_nie_dostaje_norm_z_tej_witryny(): void
    {
        Storage::fake('public');
        // Kod 34-8753 na karcie innego producenta: witryna ATG nie jest dla niej źródłem niczego.
        $foreign = Product::query()->create([
            'sku' => self::SKU,
            'name' => 'Rękawice Ansell HyFlex',
            'manufacturer' => 'Ansell',
        ]);
        $this->siteCard(self::SKU);
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertNull($foreign->refresh()->manufacturer_norms);
        $this->assertSame(1, $result['skipped'], implode(' | ', $result['errors']));
    }

    public function test_pozycja_bez_karty_w_katalogu_nie_zaklada_nowej(): void
    {
        Storage::fake('public');
        $this->catalogCard(self::SKU);
        $this->siteCard('44-3745');
        $this->siteCard(self::SKU);
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, Product::query()->count());
        $this->assertContains(
            '44-3745: brak karty w katalogu — cennik jej nie zawiera',
            $result['errors'],
            implode(' | ', $result['errors']),
        );
    }

    public function test_drugi_przebieg_nie_przepisuje_norm_bez_zmiany_u_producenta(): void
    {
        Storage::fake('public');
        $product = $this->catalogCard(self::SKU);
        $this->siteCard(self::SKU);
        $this->fakeSite();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);
        $first = $product->refresh()->manufacturer_norms;
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        // te same pary = ten sam zapis, także data odczytu: zbędny zapis zlecałby reindeks wektora
        $this->assertSame($first, $product->refresh()->manufacturer_norms);
    }

    public function test_karta_bez_norm_zglasza_sie_w_podsumowaniu_przebiegu(): void
    {
        // Witryna stoi na klasach Tailwinda i danych strukturalnych — przebudowa szablonu ma być widoczna
        // w dzienniku przebiegu, a nie kasować dane po cichu.
        $this->siteCard(self::SKU, norms: []);
        $this->fakeSite();

        $connector = AtgB2bConnector::forAccount($this->account(), 0);
        iterator_to_array($connector->products(), false);

        $this->assertStringContainsString('Kart bez listy norm', implode(' ', $connector->runSummary()));
        $this->assertStringContainsString(self::SKU, implode(' ', $connector->runSummary()));
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
            'name' => 'Rękawice ATG MaxiFlex Cut '.$sku,
            'manufacturer' => 'ATG',
            'catalog_price_net' => 42.00,
            'purchase_price' => 300.00,
            'currency' => 'PLN',
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string|null}>|null  $norms
     */
    private function siteCard(string $sku, ?array $norms = null): void
    {
        $this->cards[$sku] = [
            'norms' => $norms ?? [
                ['EN ISO 21420:2020+A1:2024', null],
                ['EN 388:2016 + A1:2018', '4331B'],
                ['ANSI/ISEA 105 (2016)', 'A2'],
            ],
        ];
    }

    private function fakeSite(): void
    {
        $cards = $this->cards;

        Http::fake(function (Request $request) use ($cards) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            $query = (string) parse_url($url, PHP_URL_QUERY);

            if ($path === '/sitemap.xml' && $query === '') {
                return Http::response(self::sitemapIndex([
                    'https://www.atg-glovesolutions.com/sitemap.xml?page=1',
                    'https://www.atg-glovesolutions.com/sitemap.xml?page=2',
                ]));
            }
            if ($path === '/sitemap.xml') {
                // strona 1 ma adresy stron rodzin i wersje językowe, strona 2 — karty artykułów
                return Http::response(self::urlset($query === 'page=1' ? [
                    'https://www.atg-glovesolutions.com/pl',
                    'https://www.atg-glovesolutions.com/pl/products/maxiflex',
                    'https://www.atg-glovesolutions.com/en/products/maxiflex/maxiflex-cut/34-8753',
                ] : array_map(
                    static fn (string $sku): string => 'https://www.atg-glovesolutions.com/pl/products/maxiflex/maxiflex-cut/'.$sku,
                    array_keys($cards),
                )));
            }

            if (preg_match('#^/pl/products/maxiflex/maxiflex-cut/([^/]+)$#', $path, $m) === 1) {
                $card = $cards[$m[1]] ?? null;

                return $card === null
                    ? Http::response('Not found', 404)
                    : Http::response(self::cardHtml($m[1], $card['norms']));
            }

            if (preg_match('#^/pl/download/(.+)$#', $path, $m) === 1) {
                // Adres pliku to sam identyfikator — nazwę pliku witryna podaje dopiero w nagłówku pobrania.
                $file = self::FILES[$m[1]][1] ?? null;

                return $file === null
                    ? Http::response('Not found', 404)
                    // każdy plik musi mieć inną treść — zapis rozpoznaje identyczne pliki jako ten sam
                    : Http::response('%PDF-1.4 '.$path, 200, [
                        'Content-Type' => 'application/pdf',
                        'Content-Disposition' => 'attachment;filename="'.$file.'"',
                    ]);
            }
            if (str_ends_with($path, '.png')) {
                return Http::response(self::pngBytes($path), 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('Nie znaleziono', 404);
        });
    }

    /**
     * Karta artykułu w znacznikach witryny: dane strukturalne, akapit wstępu, bloki cech, tabelka
     * parametrów, lista cech i pliki pod /download/{uuid}.
     *
     * @param  list<array{0: string, 1: string|null}>  $norms
     */
    private static function cardHtml(string $sku, array $norms): string
    {
        $properties = array_map(
            static fn (array $norm): array => $norm[1] === null
                ? ['@type' => 'PropertyValue', 'name' => $norm[0]]
                : ['@type' => 'PropertyValue', 'name' => $norm[0], 'value' => $norm[1]],
            $norms,
        );
        $data = (string) json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => 'MaxiFlex® Cut™ '.$sku,
            'sku' => $sku,
            'mpn' => $sku,
            'category' => 'Glove',
            'url' => 'https://www.atg-glovesolutions.com/pl/products/maxiflex/maxiflex-cut/'.$sku,
            'description' => 'Rękawice inżynieryjne odporne na przecięcia, zapewniające precyzję.',
            'manufacturer' => ['@type' => 'Organization', 'name' => 'ATG'],
            'image' => ['https://www.atg-glovesolutions.com/sites/default/files/atg-datahub/MaxiFlex_Cut_'.str_replace('-', '_', $sku).'_2017_12_LR.png'],
            'brand' => ['@type' => 'Brand', 'name' => 'MaxiFlex® Cut™'],
            'additionalProperty' => $properties,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Szablon witryny opakowuje podpisy tabelki w <span class="font-semibold"> z akapitem wartości obok,
        // a numer artykułu stoi w <h1>. Ta sama klasa „font-semibold” jest też w blokach cech (<dt>).
        $downloads = '';
        foreach (self::FILES as $uuid => [$label, $file]) {
            $downloads .= '<a href="/download/'.$uuid.'"><span>'.$label.'</span></a>';
        }

        return '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8">'
            .'<script type="application/ld+json">'.$data.'</script>'
            .'</head><body>'
            .'<div class="italic font-medium text-xl">MaxiFlex<sup>®</sup> Cut<sup>™</sup></div>'
            .'<h1 class="text-brand-red text-2xl font-bold">'.$sku.'</h1>'
            .'<div class="mt-4 prose prose-brand text-brand-text"><p>Zaprojektowane do precyzyjnego manipulowania'
            .' z dodatkową ochroną przed przecięciem, rękawice te zachowują ultracienką, elastyczną konstrukcję'
            .' MaxiFlex®, jednocześnie wykorzystując wysokowydajne włókna odporne na przecięcie.</p></div>'
            .'<dl class="mt-12 grid grid-cols-1">'
            .'<div class="border-t pt-4"><dt class="font-semibold">Zwiększona ochrona przed przecięciem'
            .' z zachowaniem komfortu</dt><dd class="mt-2 text-sm"><p>Zwiększona odporność na przecięcie przy'
            .' zachowaniu cienkiej konstrukcji.</p></dd></div>'
            .'<div class="border-t pt-4"><dt class="font-semibold">Kompatybilne z ekranami dotykowymi</dt>'
            .'<dd class="mt-2 text-sm"><p>Używaj urządzeń bez zdejmowania rękawic.<br>Pozostań chroniony.</p></dd></div>'
            .'</dl>'
            .'<h2 class="font-bold">Informacje techniczne</h2>'
            .'<h4 class="text-xl">Informacje o produkcie</h4>'
            .'<div class="mt-4 flex flex-col space-y-6">'
            .'<div class="flex space-x-4"><div class="max-h-[50px]">'
            .'<img src="/sites/default/files/atg-datahub/dip-3_4.svg" alt="Wzór" class="h-[50px] w-auto" />'
            .'</div><div><span class="font-semibold">Wzór</span><p class="mt-2">Powlekanie ¾, ściągacz</p></div></div>'
            .'<div class="flex space-x-4"><div><span class="font-semibold">Grubość warstwy dłoniowej</span>'
            .'<p class="mt-2">0.80 mm</p></div></div>'
            .'<div class="flex space-x-4"><div><span class="font-semibold">Rozmiary</span>'
            .'<p class="mt-2">6 (XS) - 12 (3XL)</p></div></div>'
            .'</div>'
            .'<h4 class="text-xl">Funkcje</h4>'
            .'<ul class="list-none mt-4 max-w-lg">'
            .'<li class="pb-2 grid"><div class="col-span-2"></div><div class="col-span-10">Bez silikonu</div></li>'
            .'<li class="pb-2 grid"><div class="col-span-10">Kompatybilne z ekranami dotykowymi</div></li>'
            .'</ul>'
            .'<h4 class="text-xl">Normy</h4><ul class="list mt-4"><li>EN 388:2016 + A1:2018 4331B</li></ul>'
            .'<h4 class="text-xl">Pobrania</h4>'.$downloads
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
            $body .= '<sitemap><loc>'.htmlspecialchars($url, ENT_XML1).'</loc><lastmod>2026-09-20T19:30:30+02:00</lastmod></sitemap>';
        }

        return $body.'</sitemapindex>';
    }

    /**
     * Lista adresów z odsyłaczami do wersji językowych — te nie są osobnymi stronami i nie mogą trafić na listę.
     *
     * @param  list<string>  $urls
     */
    private static function urlset(array $urls): string
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            .' xmlns:xhtml="http://www.w3.org/1999/xhtml">';
        foreach ($urls as $url) {
            $body .= '<url><loc>'.htmlspecialchars($url, ENT_XML1).'</loc>'
                .'<xhtml:link rel="alternate" hreflang="cs" href="'.htmlspecialchars(str_replace('/pl', '/cs', $url), ENT_XML1).'"/>'
                .'</url>';
        }

        return $body.'</urlset>';
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => 'ATG'],
            ['sites' => ['atg-glovesolutions.com'], 'connector' => 'atg'],
        )->fresh();
    }
}
