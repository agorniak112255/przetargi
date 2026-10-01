<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Services\B2b\AtlasB2bClient;
use App\Services\B2b\AtlasB2bConnector;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDocumentSource;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bGroupsSizes;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteDocument;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bShopFieldNormSource;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\B2bSizePriceSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik www.atlas-obuwie.pl (TYPO3, strefa klienta ATLAS) na atrapie witryny (Http::fake). Znaczniki HTML odwzorowują
 * strony zalogowanego konta z 01.10.2026: formularz logowania z nagłówka (user, pass, pid „44,265”, logintype
 * „login_atlas”) wysłany POST-em na /index.html, przekierowanie na /produkt.html z ciasteczkiem fe_typo_user, menu konta
 * z odnośnikiem href="/produkt/my-profil.html"; katalog /produkt.html (div.product-list__item, pole Nummer[] z numerem
 * „75600   S3”), strona wyrobu (h1, blok „wyposażenie” z <br>, lista cech, przyciski z deklaracją /eu-pdf/…pdf albo
 * ogólną stroną deklaracji, galeria .product-slider, tabela ceny „Cena artykułu: … PLN (incl. 22% Rabat)”, ukryte pola
 * Preis/Preis_csv, tabela zamówienia #Order_table z polami Text/Artikelnummer/Uebergroesse/Weite i klasą
 * availability-*, przypis „*20 % dopłata - rozmiar nietypowy”). Gość widzi stronę wyrobu bez ceny i bez tabeli.
 *
 * Wszystkie dane (numery, ceny, nazwy, opisy) są SYNTETYCZNE.
 */
final class AtlasConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const USER = '100001';

    private const PASSWORD = 'dobre-haslo';

    /** @var list<array<string, mixed>> wyroby w kolejności katalogu */
    private array $products = [];

    /** @var list<string> ważne sesje */
    private array $sessions = [];

    private int $logins = 0;

    /** @var list<array<string, mixed>> */
    private array $loginBodies = [];

    /** Sesja wygasa po tylu stronach wyrobów (0 = nigdy). */
    private int $expireAfterPages = 0;

    private int $pages = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_posts_the_header_form_and_confirms_the_account_menu(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $this->assertSame(['user' => self::USER, 'pass' => self::PASSWORD, 'pid' => '44,265', 'logintype' => 'login_atlas'], $this->loginBodies[0]);
    }

    public function test_wrong_password_is_not_fatal(): void
    {
        $this->fakeSite();
        $client = new AtlasB2bClient(self::USER, 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sprawdź numer klienta i hasło', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_price_text_and_surcharge_are_parsed(): void
    {
        $this->assertSame(['net' => 237.9, 'currency' => 'PLN', 'discount' => 22.0], AtlasB2bConnector::parsePriceText("Cena artykułu: 237.90 PLN (incl. 22% Rabat)\n"));
        $this->assertSame(['net' => 32.0, 'currency' => 'PLN', 'discount' => 0.0], AtlasB2bConnector::parsePriceText('Cena artykułu: 32.00 PLN'));
        $this->assertNull(AtlasB2bConnector::parsePriceText('Cena artykułu: 0.00 PLN'));
        $this->assertNull(AtlasB2bConnector::parsePriceText(''));
        $this->assertSame(20.0, AtlasB2bConnector::surchargePercent('20 % ÜGZ'));
        $this->assertNull(AtlasB2bConnector::surchargePercent(''));
    }

    public function test_separate_width_positions_join_their_model_only_with_weite_in_the_name(): void
    {
        $groups = AtlasB2bConnector::groupRows([
            ['path' => '/a', 'number' => '11113 S3', 'name' => 'TEST 100,Weite 13 | ESD'],
            ['path' => '/b', 'number' => '11100 S3', 'name' => 'TEST 100 | ESD'],
            ['path' => '/c', 'number' => '11114 S3', 'name' => 'TEST 100, Weite 14 | ESD'],
            // numer kończący się na 12, ale bez „Weite” w nazwie — osobny wyrób
            ['path' => '/d', 'number' => '22212 S1', 'name' => 'TEST 222'],
            ['path' => '/e', 'number' => '22200 S1', 'name' => 'TEST 220'],
            ['path' => '/f', 'number' => '160510', 'name' => 'TEST SOCK'],
        ]);

        $this->assertSame(
            [['11100 S3', '11113 S3', '11114 S3'], ['22212 S1'], ['22200 S1'], ['160510']],
            array_map(static fn (array $g): array => array_column($g, 'number'), $groups),
        );
    }

    public function test_model_page_with_widths_becomes_one_card_with_priced_positions(): void
    {
        $this->addModel();
        $this->addInsole();
        $this->addSpray();
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['11100 S3', '88650', '84320'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $card = $products[0];
        $this->assertSame('11100 S3/40', $card->remoteId);
        $this->assertSame('TEST 100 PRO | ESD', $card->name);
        $this->assertSame('https://www.atlas-obuwie.pl/index.php?id=90&Back=88&asanr=11100%20%20%20S3&L=8&cHash=c0ffee01', $card->sourceUrl);
        $this->assertSame(
            [
                ['11100 S3/40', 'tęgość 10 / 40', 200.0, 250.0, 'dostępne natychmiast'],
                ['11100 S3/41', 'tęgość 10 / 41', 200.0, 250.0, 'artykuł jest w produkcji'],
                // rozmiar nietypowy: cena artykułu + 20 % z pola „Uebergroesse” (tak liczy zamówienie konta)
                ['11100 S3/49', 'tęgość 10 / 49', 240.0, 300.0, 'niski poziom zapasów'],
                ['11112 S3/40', 'tęgość 12 / 40', 200.0, 250.0, 'dostępne natychmiast'],
                ['11112 S3/41', 'tęgość 12 / 41', 200.0, 250.0, 'dostępne natychmiast'],
                // tęgość 13 — osobna pozycja katalogu z własną ceną
                ['11113 S3/40', 'tęgość 13 / 40', 230.0, 287.5, 'dostępne natychmiast'],
                ['11113 S3/41', 'tęgość 13 / 41', 230.0, 287.5, 'dostępne natychmiast'],
            ],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['size'], $m['price']->net, $m['price']->base, $m['availability']], $card->members),
        );
        $price = $connector->price($card);
        $this->assertSame(200.0, $price->net);
        $this->assertSame(250.0, $price->base);
        $this->assertSame(20.0, $price->discountPercent);
        $this->assertSame('PLN', $price->currency);
        $this->assertSame('ATLAS', $connector->manufacturer($card));
        $this->assertSame('Tęgości: tęgość 10, tęgość 12, tęgość 13; rozmiary: 40, 41, 49', $card->variantSummary);
        $this->assertSame(
            'dostępne natychmiast — tęgość 10: 40; tęgość 12: 40, 41; tęgość 13: 40, 41 | niski poziom zapasów — tęgość 10: 49 | artykuł jest w produkcji — tęgość 10: 41',
            $card->availability,
        );
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_SOURCE_CODE, '11100 S3', '11100 S3/40', 'tęgość 10', 'Artikelnummer'],
                [ProductIdentifier::TYPE_SOURCE_CODE, '11112 S3', '11112 S3/40', 'tęgość 12', 'Artikelnummer'],
                [ProductIdentifier::TYPE_SOURCE_CODE, '11113 S3', '11113 S3/40', 'tęgość 13', 'Artikelnummer'],
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, '11100', '11100 S3/40', null, 'numer artykułu'],
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, '11113', '11113 S3/40', 'tęgość 13', 'numer artykułu'],
            ],
            array_map(static fn ($i): array => [$i->type, $i->value, $i->remoteId, $i->label, $i->field], $card->identifiers ?? []),
        );
        // numer artykułu i rozmiary z bloku „wyposażenie” — do tabelki, nie do opisu
        $this->assertSame(
            "EN ISO 20345:2022 S3S SR\nTestProTrax - Materiał cholewki\nESD (DIN EN 61340-5-1)\nDostępne w tęgości W13",
            $connector->description($card),
        );
        $this->assertSame(
            [
                'Informacje ze sklepu ATLAS | Numer artykułu | 11100, 11113',
                'Informacje ze sklepu ATLAS | Norma | EN ISO 20345:2022 S3S SR',
                'Informacje ze sklepu ATLAS | Rozmiary | 40-49',
                'Informacje ze sklepu ATLAS | Tęgości | tęgość 10, tęgość 12, tęgość 13',
                'Informacje ze sklepu ATLAS | Rozmiar nietypowy | 49: 20 % dopłata - rozmiar nietypowy',
            ],
            array_map(static fn ($f): string => $f->section.' | '.$f->name.' | '.$f->value, $connector->shopFields($card)),
        );
        $this->assertSame(
            [['Deklaracja zgodności EU', ProductDocument::KIND_CERTIFICATE, 'https://www.atlas-obuwie.pl/eu-pdf/TEST%20100%20PRO.pdf']],
            array_map(static fn ($d): array => [$d->title, $d->kind, $d->sourceUrl], $connector->documents($card)),
        );
        $this->assertSame(
            ['https://www.atlas-obuwie.pl/typo3temp/assets/_processed_/1/1/csm_TEST_100_PRO_aa11.webp', 'https://www.atlas-obuwie.pl/typo3temp/assets/_processed_/0/f/csm_Z_Sohle_bb22.webp'],
            $connector->imageUrls($card),
        );

        // wkładka: rozmiary w przedziałach, bez normy, tekst wyrobu jako opis, ogólna strona deklaracji to nie plik
        $insole = $products[1];
        $this->assertSame('88650/35-37', $insole->remoteId);
        $this->assertSame('Rozmiary: 35-37, 38-40', $insole->variantSummary);
        $this->assertSame('dostępne natychmiast (wszystkie rozmiary)', $insole->availability);
        $this->assertSame(['35-37', '38-40'], array_column($insole->members, 'size'));
        $this->assertSame('Większa stabilność – testowa wkładka marki ATLAS®.', $connector->description($insole));
        $this->assertSame([], $connector->documents($insole));
        $this->assertSame(41.34, $connector->price($insole)->net);

        // spray: jedna komórka tabeli — karta bez pozycji, remote_id = numer artykułu
        $spray = $products[2];
        $this->assertSame('84320', $spray->remoteId);
        $this->assertSame([], $spray->members);
        $this->assertNull($spray->variantSummary);
        $this->assertSame('dostępne natychmiast', $spray->availability);
        $this->assertSame(
            [[ProductIdentifier::TYPE_SOURCE_CODE, '84320', null, null], [ProductIdentifier::TYPE_MANUFACTURER_CODE, '84320', null, null]],
            array_map(static fn ($i): array => [$i->type, $i->value, $i->remoteId, $i->label], $spray->identifiers ?? []),
        );

        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Lista Atlas: 4 pozycji katalogu, 3 modeli', $summary);
        $this->assertStringContainsString('Karty: 3, pozycji (tęgość × rozmiar): 10', $summary);
        $this->assertStringContainsString('dołączone do modelu: 1, np. 11100 S3 + 11113 S3', $summary);
    }

    public function test_page_without_a_price_is_skipped_with_a_reason_not_dropped(): void
    {
        $this->addCatalogue();
        $this->addSpray();
        $this->fakeSite();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['89680', '84320'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $this->assertSame('skipped', $products[0]->raw['status']);
        $this->assertSame([], $connector->shopFields($products[0]));
        $this->assertStringContainsString('Bez ceny konta (pominięte): 1, np. 89680', implode("\n", $connector->runSummary()));
        $this->expectExceptionMessage('bez ceny konta');
        $connector->price($products[0]);
    }

    public function test_many_pages_without_any_account_price_stop_the_run(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->addCatalogue((string) (89000 + $i));
        }
        $this->fakeSite();

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('bez ceny konta');

        iterator_to_array($this->connector()->products(), false);
    }

    public function test_expired_session_is_renewed_once(): void
    {
        $this->addModel();
        $this->addSpray();
        $this->fakeSite();
        $connector = $this->connector();
        $this->expireAfterPages = 1;

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(2, $products);
        $this->assertSame('ok', $products[1]->raw['status']);
        $this->assertSame(2, $this->logins);
    }

    public function test_files_and_images_are_public_and_an_html_page_is_not_a_file(): void
    {
        $this->addModel();
        $this->fakeSite();
        $connector = $this->connector();
        $card = iterator_to_array($connector->products(), false)[0];

        $image = $connector->imageAt($connector->imageUrls($card)[0]);
        $file = $connector->documentBytes($connector->documents($card)[0]);

        $this->assertSame('image/webp', $image?->mime);
        $this->assertSame('application/pdf', $file['mime']);
        $this->assertStringStartsWith('%PDF-', $file['bytes']);
        $this->expectExceptionMessage('nie wydała pliku');
        $connector->documentBytes(new B2bRemoteDocument('x', 'https://www.atlas-obuwie.pl/eu-pdf/BRAK.pdf'));
    }

    public function test_sync_creates_the_model_card_with_size_rows_norms_documents_and_images_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addModel();
        $this->addSpray();
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(2, $result['created'], implode(' | ', $result['errors']));
        $card = Product::query()->where('sku', '11100 S3')->sole();
        $this->assertSame('ATLAS', $card->manufacturer);
        $this->assertSame('TEST 100 PRO | ESD', $card->name);
        $this->assertStringContainsString('TestProTrax - Materiał cholewki', (string) $card->description);
        $this->assertEqualsCanonicalizing(
            ['11100 S3/40', '11100 S3/41', '11100 S3/49', '11112 S3/40', '11112 S3/41', '11113 S3/40', '11113 S3/41'],
            B2bProductLink::query()->where('product_id', $card->id)->pluck('remote_id')->all(),
        );
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame('200.00', (string) $slot->purchase_price);
        $this->assertSame('250.00', (string) $slot->catalog_price_net);
        $this->assertSame('240.00', (string) $slot->size_price_max);
        $this->assertSame(
            ['tęgość 10 / 40' => '200.00', 'tęgość 10 / 41' => '200.00', 'tęgość 10 / 49' => '240.00', 'tęgość 12 / 40' => '200.00', 'tęgość 12 / 41' => '200.00', 'tęgość 13 / 40' => '230.00', 'tęgość 13 / 41' => '230.00'],
            ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('label')->pluck('purchase_price', 'label')->map(static fn ($p): string => (string) $p)->all(),
        );
        $this->assertSame('atlas', $card->manufacturer_norms['source']['connector'] ?? null);
        $this->assertSame([['label' => 'EN ISO 20345:2022', 'value' => 'S3S SR']], $card->manufacturer_norms['rows'] ?? null);
        $this->assertSame(['Deklaracja zgodności EU'], ProductDocument::query()->where('product_id', $card->id)->pluck('title')->all());
        $this->assertSame(2, ProductImage::query()->where('product_id', $card->id)->count());
        $this->assertEqualsCanonicalizing(
            ['11100', '11113'],
            ProductIdentifier::query()->where('product_id', $card->id)->where('type', ProductIdentifier::TYPE_MANUFACTURER_CODE)->pluck('value')->all(),
        );
        $rows = collect(ProductShopCard::query()->where('product_id', $card->id)->sole()->fields)->flatMap(static fn (array $section): array => array_map(
            static fn (array $row): string => ($section['section'] ?? '').' | '.$row['name'].' | '.$row['value'],
            $section['rows'] ?? [],
        ))->all();
        $this->assertContains('Informacje ze sklepu ATLAS | Norma | EN ISO 20345:2022 S3S SR', $rows);
        $spray = Product::query()->where('sku', '84320')->sole();
        $this->assertSame(['84320'], B2bProductLink::query()->where('product_id', $spray->id)->pluck('remote_id')->all());

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(2, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_registry_detects_atlas_by_host_as_the_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('atlas', $registry->keyForSites(['https://www.atlas-obuwie.pl/index.html']));
        $this->assertSame('Atlas', $registry->label('atlas'));
        $this->assertTrue($registry->requiresPassword('atlas'));
        $this->assertTrue($registry->isManufacturerSite('atlas'));
        $this->assertTrue($registry->groupsSizes('atlas'));
        $this->assertTrue($registry->sendsSizePrices('atlas'));
        $this->assertSame(['brand' => 'ATLAS', 'names' => ['Norma']], $registry->shopFieldNormSource('atlas'));
        $this->assertTrue(B2bConnectorRegistry::isConnectorUrl('https://www.atlas-obuwie.pl/produkt.html'));

        $account = B2bAccount::query()->create([
            'username' => self::USER, 'password' => 'sekret', 'sites' => ['https://www.atlas-obuwie.pl/index.html'],
        ]);
        $connector = $registry->make($account, 150);

        $this->assertInstanceOf(AtlasB2bConnector::class, $connector);
        foreach ([B2bManufacturerSite::class, B2bShopFieldSource::class, B2bShopFieldNormSource::class, B2bDocumentSource::class, B2bImageGallery::class, B2bGroupsSizes::class, B2bSizePriceSource::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
        // Crawl-delay witryny: zwykła przerwa przebiegu (150 ms) podniesiona do sekundy
        $client = (new \ReflectionProperty($connector, 'client'))->getValue($connector);
        $this->assertSame(AtlasB2bConnector::MIN_DELAY_MS, (new \ReflectionProperty($client, 'delayMs'))->getValue($client));
    }

    // ---- pomocnicze ----

    private function client(): AtlasB2bClient
    {
        return new AtlasB2bClient(self::USER, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): AtlasB2bConnector
    {
        $connector = new AtlasB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::USER],
            ['password' => self::PASSWORD, 'sites' => ['https://www.atlas-obuwie.pl/index.html'], 'connector' => 'atlas', 'sync_images' => true],
        )->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return Product::query()->orderBy('sku')->get()->mapWithKeys(fn (Product $p): array => [$p->sku => [
            'name' => $p->name,
            'description' => $p->description,
            'variant_summary' => $p->variant_summary,
            'manufacturer_norms' => collect($p->manufacturer_norms ?? [])->except('source')->all(),
            'links' => B2bProductLink::query()->where('product_id', $p->id)->orderBy('remote_id')->pluck('remote_id')->all(),
            'variants' => ProductVariant::query()->where('product_id', $p->id)->orderBy('label')->get(['label', 'purchase_price', 'availability'])->toArray(),
            'shop_card' => ProductShopCard::query()->where('product_id', $p->id)->value('fields'),
            'images' => ProductImage::query()->where('product_id', $p->id)->orderBy('sort_order')->pluck('source_url')->all(),
            'documents' => ProductDocument::query()->where('product_id', $p->id)->orderBy('sort_order')->pluck('source_url')->all(),
            'price' => ProductSourcePrice::query()->where('product_id', $p->id)->get(['purchase_price', 'catalog_price_net', 'size_price_max', 'availability'])->toArray(),
        ]])->all();
    }

    /**
     * Model w tęgości 10 (wiersz „tęgość 10”, rozmiar 49 z dopłatą 20 %) i 12 (wiersz innego artykułu z polem Weite) oraz
     * osobna pozycja katalogu tęgości 13 z wyższą ceną — jak „Flash 6405 XP BOA” i „…,Weite 13”.
     */
    private function addModel(): void
    {
        $head = 'numer artykułu %s'."\n".'                <br>EN ISO 20345:2022 S3S SR'."\n".'                <br>rozmiary: 40-49';
        $features = "<ul class='highlight-list'><li>TestProTrax - Materiał cholewki</li><li>ESD (DIN EN 61340-5-1)</li><li>Dostępne w tęgości W13</li></ul>";
        $images = ['/typo3temp/assets/_processed_/1/1/csm_TEST_100_PRO_aa11.webp', '/typo3temp/assets/_processed_/0/f/csm_Z_Sohle_bb22.webp'];
        $this->products[] = [
            'number' => '11100   S3', 'cHash' => 'c0ffee01', 'name' => 'TEST 100 PRO | ESD', 'size' => 'rozmiar 40-49', 'serie' => 'S3',
            'head' => sprintf($head, '11100'), 'features' => $features, 'pdf' => '/eu-pdf/TEST 100 PRO.pdf', 'images' => $images,
            'price' => 'Cena artykułu: 200.00 PLN (incl. 20% Rabat)', 'net' => '200.00', 'csv' => '250.00',
            'sizes' => ['40', '41', '49<sup>*</sup>'], 'hint' => true,
            'rows' => [
                ['label' => 'tęgość 10', 'cells' => [['40', '11100   S3', 'green', '', ''], ['41', '11100   S3', 'red', '', ''], ['49', '11100   S3', 'yellow', '20 % ÜGZ', '']]],
                ['label' => 'tęgość 12', 'cells' => [['40', '11112   S3', 'green', '', 'Weite 12'], ['41', '11112   S3', 'green', '', 'Weite 12'], null]],
            ],
        ];
        $this->products[] = [
            'number' => '11113   S3', 'cHash' => 'c0ffee13', 'name' => 'TEST 100 PRO,Weite 13 | ESD', 'size' => 'rozmiar 40-41', 'serie' => 'S3',
            'head' => sprintf($head, '11113'), 'features' => $features, 'pdf' => '/eu-pdf/TEST 100 PRO.pdf', 'images' => $images,
            'price' => 'Cena artykułu: 230.00 PLN (incl. 20% Rabat)', 'net' => '230.00', 'csv' => '287.50',
            'sizes' => ['40', '41'], 'hint' => false,
            // jak na witrynie: etykieta wiersza osobnej pozycji tęgości bywa przypadkowa („14 dni 3 %”)
            'rows' => [['label' => '14 dni 3 %', 'cells' => [['40', '11113   S3', 'green', '', ''], ['41', '11113   S3', 'green', '', '']]]],
        ];
    }

    /** Wkładka: rozmiary w przedziałach, wiersz „Pair”, tekst wyrobu zamiast listy cech, ogólna strona deklaracji. */
    private function addInsole(): void
    {
        $this->products[] = [
            'number' => '88650', 'cHash' => 'c0ffee02', 'name' => 'TEST COMFORT INSOLE', 'size' => '35-40', 'serie' => '',
            'head' => "numer artykułu 88650\n                \n                <br>rozmiary: 35-40",
            'features' => '<br />Większa stabilność – testowa wkładka marki ATLAS®. <br />', 'pdf' => '/index.php?id=509&L=8',
            'images' => ['/typo3temp/assets/images/csm_Z_TEST_Insole_cc33.webp'],
            'price' => 'Cena artykułu: 41.34 PLN (incl. 22% Rabat)', 'net' => '41.34', 'csv' => '53.00',
            'sizes' => ['35-37', '38-40'], 'hint' => false, 'corner' => '',
            'rows' => [['label' => 'Pair', 'cells' => [['35-37', '88650', 'green', '', ''], ['38-40', '88650', 'green', '', '']]]],
        ];
    }

    /** Spray: jedna komórka tabeli z rozmiarem „kein” i pustym nagłówkiem. */
    private function addSpray(): void
    {
        $this->products[] = [
            'number' => '84320', 'cHash' => 'c0ffee03', 'name' => 'TEST Schuh-Hygienespray', 'size' => 'rozmiar 125 ml', 'serie' => '',
            'head' => "numer artykułu 84320\n                \n                <br>rozmiary: 125 ml",
            'features' => '<br />Testowa higiena obuwia - 125 ml<br />', 'pdf' => '/index.php?id=509&L=8',
            'images' => ['/typo3temp/assets/_processed_/f/9/csm_Z_TEST_Spray_dd44.webp'],
            'price' => 'Cena artykułu: 26.52 PLN (incl. 22% Rabat)', 'net' => '26.52', 'csv' => '34.00',
            'sizes' => [], 'hint' => false,
            'rows' => [['label' => 'tęgość 10', 'cells' => [['kein', '84320', 'green', '', '']]]],
        ];
    }

    /** Katalog drukowany: strona bez ceny i bez tabeli zamówienia. */
    private function addCatalogue(string $number = '89680'): void
    {
        $this->products[] = [
            'number' => $number, 'cHash' => 'cafe'.$number, 'name' => 'TEST KATALOG G?ÓWNY', 'size' => '', 'serie' => '',
            'head' => "numer artykułu \n                \n                <br>rozmiary: ", 'features' => '<br />', 'pdf' => '/index.php?id=509&L=8',
            'images' => ['/typo3temp/assets/_processed_/2/2/csm_TEST_Katalog_ee55.webp'],
            'price' => null, 'net' => null, 'csv' => null, 'sizes' => [], 'hint' => false, 'rows' => [],
        ];
    }

    // ---- atrapa witryny ----

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            preg_match('/fe_typo_user=([\w-]+)/', $request->header('Cookie')[0] ?? '', $m);
            $signedIn = in_array($m[1] ?? '', $this->sessions, true);
            $html = ['Content-Type' => 'text/html; charset=utf-8'];

            if ($path === '/index.html') {
                if ($request->method() === 'POST') {
                    $data = $request->data();
                    $this->loginBodies[] = $data;
                    if (($data['user'] ?? null) === self::USER && ($data['pass'] ?? null) === self::PASSWORD && ($data['logintype'] ?? null) === 'login_atlas') {
                        $this->logins++;
                        $this->sessions[] = 's'.$this->logins;
                        $this->pages = 0;

                        // jak na witrynie: przekierowanie na katalog z ciasteczkiem sesji klienta
                        return Http::response('', 303, ['Location' => AtlasB2bClient::BASE.'/produkt.html', 'Set-Cookie' => 'fe_typo_user=s'.$this->logins.'; path=/; secure; HttpOnly']);
                    }

                    // złe hasło: strona gościa bez komunikatu
                    return Http::response(self::page('<p>Strona główna</p>', false), 200, $html);
                }

                return Http::response(self::page('<p>Strona główna</p>', $signedIn), 200, $html);
            }

            if (str_starts_with($path, '/eu-pdf/')) {
                if (str_contains($path, 'BRAK')) {
                    return Http::response(self::page('<h1>Nie znaleziono</h1>', false), 200, $html);
                }

                return Http::response('%PDF-1.4 test '.$path, 200, ['Content-Type' => 'application/pdf']);
            }
            if (str_starts_with($path, '/typo3temp/')) {
                return Http::response(self::webp($path), 200, ['Content-Type' => 'image/webp']);
            }

            if ($path === '/produkt.html') {
                return Http::response(self::page($this->catalog(), $signedIn), 200, $html);
            }

            if ($path === '/index.php' && ($query['id'] ?? null) === '90') {
                if ($signedIn) {
                    $this->pages++;
                    if ($this->expireAfterPages > 0 && $this->pages > $this->expireAfterPages) {
                        $this->expireAfterPages = 0;
                        $this->sessions = [];
                        $signedIn = false;
                    }
                }
                foreach ($this->products as $product) {
                    if ($product['number'] === ($query['asanr'] ?? null)) {
                        return Http::response(self::page(self::productPage($product, $signedIn), $signedIn), 200, $html);
                    }
                }
            }

            return Http::response(self::page('<h1>Nie znaleziono</h1>', $signedIn), 404, $html);
        });
    }

    private static function page(string $body, bool $account): string
    {
        $header = $account
            ? '<nav class="topbar-navigation-mobile"><button class="topbar-navigation-mobile__toggle-profile-list" id="mobileProfileListTrigger"><i class="icon-user"></i> Moje konto</button>'
                .'<ul class="topbar-navigation-mobile__profile-list" aria-hidden="true" id="mobileProfileList"><li><a href="/produkt/my-profil.html">Mój profil</a></li><li><a href="/produkt/history.html">Historia zakupu</a></li><li><a href="/index.html?logintype=logout">Logout</a></li></ul>'
                .'<a href="/produkt/basket.html"><i class="icon-shopping-cart"></i> Koszyk</a></nav>'
            : '<form method="post" class="header-topbar-loginform header-topbar-form" name="login"><input type="text" name="user"/><input type="password" name="pass"/><button type="submit">Login</button>'
                .'<input type="hidden" name="pid" value="44,265" /><input type="hidden" name="logintype" value="login_atlas" /></form>'
                // język witryny w stopce menu — także dla gościa (odnośniki z „logintype=logout”)
                .'<a href="https://www.atlasschuhe.de/index.html?logintype=logout">Deutsch</a>';

        return '<!DOCTYPE html><html lang="pl-PL"><head><meta charset="utf-8"><title>Produkt</title></head><body><header>'.$header.'</header><div id="main" role="main">'.$body.'</div></body></html>';
    }

    private function catalog(): string
    {
        $items = '';
        foreach ($this->products as $p) {
            $href = '/index.php?id=90&Back=88&asanr='.rawurlencode($p['number']).'&L=8&cHash='.$p['cHash'];
            $key = str_replace(' ', '', $p['number']);
            $items .= '<div class="product-list__item grid__column grid__column--xs-6 grid__column--sm-6 grid__column--md-6 grid__column--lg-4">'
                .'<div class="product-list-image"><a href="'.$href.'" title="'.$p['name'].'"><img alt="'.$p['name'].'" data-src="/typo3temp/x.webp" src="/storage/_Shop/atlas-prev.png" class="img-lazy"/></a>'
                .'<div class="product-list-bookmarks"><div class="input checkbox"><label class="form-check-label" for="Nummer['.$key.']">'
                .'<input  title="zaznaczenie" aria-label="zaznaczenie" id="Nummer['.$key.']" type="checkbox" name="Nummer[]" value="'.$p['number'].'" ><span><i class="icon-list"></i></span></label></div></div></div>'
                .'<div class="product-list-text"><a href="'.$href.'" title="'.$p['name'].'">'.$p['name'].'</a><div class="product-list-size">'.$p['size'].'</div><div class="product-list-serie">'.$p['serie'].'</div></div></div>';
        }

        return '<div class="grid product-list"><div class="product-list-header grid__column grid__column--xs-12"><video class="product-video__file"></video></div>'.$items.'</div>';
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private static function productPage(array $p, bool $account): string
    {
        $slider = '';
        $thumbs = '';
        foreach ($p['images'] as $image) {
            $slider .= '<div><img src="'.$image.'" alt="'.$p['number'].'"/></div>';
            $thumbs .= '<div><img src="'.str_replace('.webp', '_thumb.webp', $image).'" alt="'.$p['number'].'"/></div>';
        }
        $prefix = str_replace(' ', '_', $p['number']);
        $pdf = '<a href="'.$p['pdf'].'" target="_blank" title="Deklaracja zgodności EU" class="btn btn--m">'."\n                    Deklaracja zgodności EU\n            </a>";
        $detail = '<div class="product-detail"><div class="product-detail__header"><h1>'.$p['name'].'</h1>'
            .'<div class="product-detail__images"><div class="product-slider">'.$slider.'</div><div class="product-slider__navigation">'.$thumbs.'</div></div></div>'
            .'<div class="product-detail__description">'."\n                <h2>wyposażenie</h2>\n                ".$p['head']."\n                \n"
            .'<div class="grid product-detail__list"><div class="grid__column grid__column--md-6 grid__column--lg-8"><div class="frame--type-text">'.$p['features'].'</div></div>'
            .'<div class="grid__column grid__column--md-6 grid__column--lg-4 product-detail__buttons">'
            .'<a href="#"  class="btn btn--m btn--icon btn--icon-list product-detail__bookmark " data-value="'.$p['number'].'"><span class="product-detail__bookmark--active-text">Dodaj do listy obserwacyjnej</span><span class="product-detail__bookmark--inactive-text">Usunięcie z listy obserwacyjnej</span></a>'
            .$pdf.'<a href="/index.php?id=93&L=8&add=1&number='.$p['number'].'" class="btn btn--m btn--icon btn--icon-pdf">Utwórz katalog PDF</a></div></div></div></div>'
            .'<div class="slider--six slider--hover-effect product-detail__icons"><a href="/index.php?id=2068&L=8"><img src="/typo3temp/assets/_processed_/2/0/csm_icon-esd.webp" alt="ESD" /></a></div>';
        if (! $account || $p['price'] === null) {
            return '<div class="section"><div class="section__content">'.$detail.'</div></div>';
        }

        $head = '<th>'.($p['corner'] ?? 'tęgość').'</th>';
        foreach ($p['sizes'] as $size) {
            $head .= '<th>'.$size.'</th>';
        }
        $body = '';
        $field = 52184;
        foreach ($p['rows'] as $row) {
            $body .= '<tr><td>'.$row['label'].'</td>';
            foreach ($row['cells'] as $cell) {
                if ($cell === null) {
                    $body .= '<td></td>';

                    continue;
                }
                [$size, $number, $colour, $surcharge, $weite] = $cell;
                $f = $prefix.'[Felder]['.$field++.']';
                $body .= '<td><input type="text" name="'.$f.'[Menge]" id="q'.$field.'" value="" style="width:22px;" class="availability-'.$colour.'" />'
                    .($weite !== '' ? '<input type="hidden" name="'.$prefix.'[Weite]['.$number.']" id="w'.$field.'" value="'.$weite.'" />' : '')
                    .'<input type="hidden" name="'.$f.'[Uebergroesse]" id="u'.$field.'" value="'.$surcharge.'" />'
                    .'<input type="hidden" name="'.$f.'[Text]" id="t'.$field.'" value="'.$size.'" />'
                    .'<input type="hidden" name="'.$f.'[ASAGR]" value="SCD" /> <input type="hidden" name="'.$f.'[Artikelnummer]" value="'.$number.'" /></td>';
            }
            $body .= '</tr>';
        }

        $form = '<div class="section section--grey"><div class="section__content"><form method="post" enctype="multipart/form-data" action="index.php?id=90&asanr='.$p['number'].'&shop=&L=8">'
            .'<table class="priceWDiscount">'."\n<tr>\n\t".'<td class="col1"><b>'.$p['price'].'</b><br />'."\n        </td>\n\t".'<td class="col2"></td>'."\n</tr>\n</table>"
            .'<input type="hidden" name="'.$prefix.'[Artikelnummer]" id="a1" value="'.$p['number'].'" />'
            .'<input type="hidden" name="'.$prefix.'[Artikelname]" id="a2" value="'.$p['name'].'" />'
            .'<input type="hidden" name="'.$prefix.'[Preis]" id="a3" value="'.$p['net'].'" /><input type="hidden" name="'.$prefix.'[Preis_csv]" id="a4" value="'.$p['csv'].'" />'
            .'<div class="product-detail__table-container"><table id="Order_table" class="product-detail__table"><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table></div>'
            .($p['hint'] ? '<p class="product-detail__hint"><sup>*</sup>20 % dopłata - rozmiar nietypowy</p>' : '<p class="product-detail__hint"></p>')
            .'<p><button type="submit" name="send" class="btn btn--m btn--icon btn--icon-shopping-cart" >Do koszyka</button></p>'
            .'<div class="product-detail__stockinformation"><div class="product-detail__stockinformation__state product-detail__stockinformation__state--green">Dostpne natychmiast</div>'
            .'<div class="product-detail__stockinformation__state product-detail__stockinformation__state--yellow">Niski poziom zapasw</div>'
            .'<div class="product-detail__stockinformation__state product-detail__stockinformation__state--red">Artyku jest w produkcji</div></div></form></div></div>';

        return '<div class="section"><div class="section__content">'.$detail.'</div></div>'.$form;
    }

    private static function webp(string $seed): string
    {
        $image = imagecreatetruecolor(1, 1);
        imagesetpixel($image, 0, 0, crc32($seed) & 0xFFFFFF);
        ob_start();
        imagewebp($image);

        return (string) ob_get_clean();
    }
}
