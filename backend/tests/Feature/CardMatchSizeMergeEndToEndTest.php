<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardMatchCandidate;
use App\Models\CardRedirect;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bCatalogSync;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRemoteShopField;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\Catalog\CardMatchFinder;
use App\Services\PriceListImportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * „Połącz rozmiary” od początku do końca (plan łączenia kart, krok 6): zastane wiersze z przebiegów 3M (konto B2B,
 * pozycja na rozmiar), cennika 3M z pliku i P4S (grupa 6X00 z trzema rozmiarami), decyzja na ekranie, potem KOLEJNE
 * przebiegi wszystkich źródeł — to w nich synchronizacja i import zakładały karty od nowa albo nadpisywały kartę
 * modelu. Karty z produkcji: 6100 S #40819 (7000146845), 6200 M #40815 (7000146847), 6300 L #40814 (7000146849),
 * P4S „6X00 Półmaska 3M 6000” #56362.
 */
final class CardMatchSizeMergeEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const S = '7000146845';

    private const M = '7000146847';

    private const L = '7000146849';

    private const NAME = '6X00 Półmaska 3M 6000';

    private const SUMMARY = 'Rozmiary: S (mały) (7000146845); M (średni) (7000146847); L (duży) (7000146849)';

    private User $admin;

    private B2bAccount $mmm;

    private B2bAccount $p4s;

    private EndToEndProducerConnector $producer;

    private EndToEndDistributorConnector $distributor;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'card-match-e2e-'.uniqid('', true);
        mkdir($this->storage.DIRECTORY_SEPARATOR.'app', 0775, true);
        $this->app->useStoragePath($this->storage);

        $this->admin = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($this->admin);
        $this->mmm = $this->account('mmm', '3m');
        $this->p4s = $this->account('p4s', 'p4s');
        $this->producer = new EndToEndProducerConnector;
        $this->distributor = new EndToEndDistributorConnector;
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_model_card_stays_one_card_through_next_3m_p4s_and_file_runs(): void
    {
        $keep = $this->mergeSizes();
        $this->assertSame(1, Product::query()->count());

        // 1) przebieg 3M w kolejności M, L, S (wiodąca ostatnia), każda pozycja z nowym opisem, zdjęciem i tabelką
        $this->producer->items = [$this->producerPosition(self::M), $this->producerPosition(self::L), $this->producerPosition(self::S)];
        $this->producer->prices = [self::S => 62.00, self::M => 61.38, self::L => 61.38];
        foreach ([self::S, self::M, self::L] as $code) {
            $this->producer->descriptions[$code] = 'Opis 3M pozycji '.$code.' — druga wersja tekstu producenta.';
            $this->producer->galleries[$code] = ['https://b2b.3m.example.test/'.$code.'-v2.png'];
            $this->producer->shopFields[$code] = [new B2bRemoteShopField('', 'Pozycja', $code.' v2')];
        }
        $this->travel(B2bCatalogSync::SHOP_FIELDS_TTL_DAYS + 1)->days();
        $run = $this->syncProducer();

        $this->assertSame(0, $run['created']);
        $this->assertSame(1, Product::query()->count());
        $card = $keep->fresh();
        $this->assertSame(self::S, $card->sku);
        $this->assertSame(self::NAME, $card->name);
        $this->assertSame(self::SUMMARY, $card->variant_summary);
        $this->assertSame('Opis 3M pozycji '.self::S.' — druga wersja tekstu producenta.', $card->description);
        $this->assertSame('62.00', $this->slot($keep, ProductSourcePrice::b2bKey($this->mmm->id))->purchase_price);
        // zdjęcie główne dalej z pozycji wiodącej; galerie pozycji M i L nie trafiają na kartę
        $this->assertStringStartsWith(
            'https://b2b.3m.example.test/'.self::S.'-',
            (string) ProductImage::query()->where('product_id', $keep->id)->where('is_primary', true)->sole()->source_url,
        );
        foreach ([self::M, self::L] as $code) {
            $this->assertFalse(ProductImage::query()->where('source_url', 'https://b2b.3m.example.test/'.$code.'-v2.png')->exists());
        }
        $shop = ProductShopCard::query()->where('product_id', $keep->id)->where('b2b_account_id', $this->mmm->id)->sole();
        $this->assertSame(self::S.' v2', $shop->fields[0]['rows'][0]['value']);
        foreach ([self::S => '62.00', self::M => '61.38', self::L => '61.38'] as $code => $price) {
            $link = B2bProductLink::query()->where('b2b_account_id', $this->mmm->id)->where('remote_id', (string) $code)->sole();
            $this->assertSame($keep->id, (int) $link->product_id);
            $this->assertSame($price, $link->last_purchase_price);
        }

        // 2) przebieg P4S: grupa 6X00 trafia w kartę modelu z mapy (merge), bez nowej karty
        foreach ([1, 2] as $pass) {
            $p4sRun = $this->syncDistributor();
            $this->assertSame(0, $p4sRun['created'], 'przebieg P4S '.$pass);
            $this->assertSame(1, Product::query()->count(), 'przebieg P4S '.$pass);
            $this->assertSame(
                ['1001' => $keep->id, '1002' => $keep->id, '1003' => $keep->id],
                B2bProductLink::query()->where('b2b_account_id', $this->p4s->id)->orderBy('remote_id')->pluck('product_id', 'remote_id')
                    ->map(static fn ($id): int => (int) $id)->all(),
            );
            $this->assertSame('31.20', $this->slot($keep, ProductSourcePrice::b2bKey($this->p4s->id))->purchase_price);
            $this->assertSame(self::NAME, $keep->fresh()->name);
            $this->assertSame(self::SUMMARY, $keep->fresh()->variant_summary);
        }

        // 3) cennik 3M z pliku: wiersze trzech rozmiarów w tej samej cenie — jeden zapis slotu karty modelu
        $import = $this->importFile('2026-10', [self::S => 60.00, self::M => 60.00, self::L => 60.00]);
        $this->assertSame(0, $import['created']);
        $this->assertSame(1, $import['updated']);
        $this->assertSame(1, Product::query()->count());
        $this->assertEquals(60.00, (float) $this->slot($keep, ProductSourcePrice::SOURCE_FILE)->purchase_price);
        $this->assertSame(self::S, $keep->fresh()->sku);
        $this->assertSame(self::NAME, $keep->fresh()->name);

        // jedna cena inna — slot bez zmian i ostrzeżenie „do rozdzielenia”
        $import = $this->importFile('2026-11', [self::S => 59.00, self::M => 65.00, self::L => 59.00]);
        $this->assertSame(0, $import['created']);
        $this->assertSame(1, Product::query()->count());
        $this->assertEquals(60.00, (float) $this->slot($keep, ProductSourcePrice::SOURCE_FILE)->purchase_price);
        $this->assertNotEmpty(array_filter(
            (array) $import['errors'],
            static fn (string $e): bool => str_contains($e, 'karta #'.$keep->id) && str_contains($e, 'do rozdzielenia w Łączenie kart'),
        ));
        $this->assertSame(self::NAME, $keep->fresh()->name);
    }

    /**
     * Zastane wiersze i decyzja: przebieg 3M (trzy karty rozmiarów z opisem, zdjęciem, tabelką i slotem), cennik 3M
     * z pliku (slot „file” i pozycje pliku na każdej karcie), przebieg P4S (karta 6X00 z trzema rozmiarami), odświeżenie
     * propozycji i „Połącz rozmiary” z kartą S.
     */
    private function mergeSizes(): Product
    {
        $this->producer->items = [$this->producerPosition(self::S), $this->producerPosition(self::M), $this->producerPosition(self::L)];
        $this->producer->prices = [self::S => 61.38, self::M => 61.38, self::L => 61.38];
        foreach ([self::S, self::M, self::L] as $code) {
            $this->producer->descriptions[$code] = 'Opis 3M pozycji '.$code.' — pierwsza wersja tekstu producenta.';
            $this->producer->galleries[$code] = ['https://b2b.3m.example.test/'.$code.'-v1.png'];
            $this->producer->shopFields[$code] = [new B2bRemoteShopField('', 'Pozycja', $code)];
        }
        $this->assertSame(3, $this->syncProducer()['created']);
        $first = $this->importFile('2026-09', [self::S => 55.00, self::M => 55.00, self::L => 55.00]);
        $this->assertSame(0, $first['created']);
        $this->syncDistributor();
        $this->assertSame(4, Product::query()->count());
        $source = Product::query()->where('sku', '6X00')->sole();
        $s = Product::query()->where('sku', self::S)->sole();

        app(CardMatchFinder::class)->refresh();
        $candidate = CardMatchCandidate::query()->where('source_product_id', $source->id)->sole();
        $this->assertSame('size_merge', $candidate->kind);
        $this->assertSame('pending', $candidate->status, (string) $candidate->reason);

        $this->postJson('/api/card-matches/'.$candidate->id.'/merge-sizes', [
            'keep_product_id' => $s->id, 'name' => self::NAME, 'plan_hash' => $candidate->plan_hash, 'confirm_sizes_only' => true,
        ])->assertOk()->assertJsonPath('decision_input.variant_summary', self::SUMMARY);

        // mapa: 3M B2B i plik 3M size_merge (wiodąca tylko pozycja B2B karty S), P4S merge
        $rows = CardRedirect::query()->get();
        $this->assertSame(9, $rows->count());
        $this->assertSame([self::S], $rows->where('is_anchor', true)->pluck('position_key')->values()->all());
        $this->assertSame(3, $rows->where('reason', 'size_merge')->where('source_key', ProductSourcePrice::b2bKey($this->mmm->id))->count());
        $this->assertSame(3, $rows->where('reason', 'size_merge')->filter(static fn (CardRedirect $r): bool => str_starts_with($r->source_key, 'file:'))->count());
        $this->assertSame(3, $rows->where('reason', 'merge')->where('source_key', ProductSourcePrice::b2bKey($this->p4s->id))->count());

        return $s->fresh();
    }

    private function producerPosition(string $code): B2bRemoteProduct
    {
        $sizes = [self::S => ['mały', '6100'], self::M => ['średni', '6200'], self::L => ['duży', '6300']];

        return new B2bRemoteProduct(
            remoteId: $code,
            sku: $code,
            name: 'Półmaska wielokrotnego użytku 3M™, rozmiar '.$sizes[$code][0].', '.$sizes[$code][1],
            identifiers: [new B2bRemoteIdentifier(type: 'manufacturer_code', value: $code, remoteId: $code, field: 'code')],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function syncProducer(): array
    {
        return app(B2bAccountSyncRunner::class)->run($this->mmm->fresh(), delayMs: 0, connector: $this->producer);
    }

    /**
     * @return array<string, mixed>
     */
    private function syncDistributor(): array
    {
        return app(B2bAccountSyncRunner::class)->run($this->p4s->fresh(), delayMs: 0, connector: $this->distributor);
    }

    /**
     * @param  array<string, float>  $prices  SKU → cena
     * @return array<string, mixed>
     */
    private function importFile(string $version, array $prices): array
    {
        $rows = [];
        $names = [self::S => 'mały, 6100', self::M => 'średni, 6200', self::L => 'duży, 6300'];
        foreach ($prices as $sku => $price) {
            $rows[] = [
                'sku' => (string) $sku, 'name' => 'Półmaska wielokrotnego użytku 3M™, rozmiar '.$names[$sku],
                'catalog_price_net' => $price, 'purchase_price' => $price, 'currency' => 'PLN',
            ];
        }
        $path = tempnam(sys_get_temp_dir(), 'e2e').'.pdf';
        file_put_contents($path, "%PDF-1.4\n");

        try {
            $result = app(PriceListImportService::class)->importFromProducts(
                new UploadedFile($path, 'cennik-3m.pdf', 'application/pdf', null, true),
                '3M',
                $version,
                $this->admin,
                $rows,
            );
            $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));

            return $result;
        } finally {
            @unlink($path);
        }
    }

    private function slot(Product $card, string $sourceKey): ProductSourcePrice
    {
        return ProductSourcePrice::query()->where('product_id', $card->id)->where('source_key', $sourceKey)->sole();
    }

    private function account(string $username, string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $username, 'password' => 'sekret', 'sites' => ['b2b.'.$connector.'.example.test'], 'connector' => $connector,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ]);
    }
}

/** Łącznik testowy producenta jak 3M: pozycje pojedyncze, cena, opis, galeria i tabelka według pozycji. */
final class EndToEndProducerConnector implements B2bConnector, B2bImageGallery, B2bManufacturerSite, B2bShopFieldSource
{
    /** @var list<B2bRemoteProduct> */
    public array $items = [];

    /** @var array<string, float> */
    public array $prices = [];

    /** @var array<string, string> */
    public array $descriptions = [];

    /** @var array<string, list<string>> */
    public array $galleries = [];

    /** @var array<string, list<B2bRemoteShopField>> */
    public array $shopFields = [];

    public static function key(): string
    {
        return 'e2e-producer-fake';
    }

    public static function label(): string
    {
        return 'Producent — test';
    }

    public static function host(): string
    {
        return 'producer.example.test';
    }

    public static function ownBrand(): string
    {
        return '3M';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        yield from $this->items;
    }

    public function totalProducts(): int
    {
        return count($this->items);
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return '3M';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        $net = $this->prices[$product->remoteId] ?? null;

        return $net !== null ? new B2bRemotePrice(net: $net) : null;
    }

    public function description(B2bRemoteProduct $product): string
    {
        return $this->descriptions[$product->remoteId] ?? '';
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $urls = $this->imageUrls($product);

        return $urls === [] ? null : $this->imageAt($urls[0]);
    }

    public function imageUrls(B2bRemoteProduct $product): array
    {
        return $this->galleries[$product->remoteId] ?? [];
    }

    public function imageAt(string $url): ?B2bRemoteImage
    {
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, crc32($url) & 0xFFFFFF);
        ob_start();
        imagepng($image);

        return new B2bRemoteImage(bytes: (string) ob_get_clean(), mime: 'image/png', sourceUrl: $url);
    }

    public function shopFields(B2bRemoteProduct $product): array
    {
        return $this->shopFields[$product->remoteId] ?? [];
    }
}

/** Łącznik testowy P4S: grupa 6X00 z trzema rozmiarami w jednej cenie, kody producenta i etykiety rozmiaru. */
final class EndToEndDistributorConnector implements B2bConnector
{
    public static function key(): string
    {
        return 'e2e-distributor-fake';
    }

    public static function label(): string
    {
        return 'Dystrybutor — test';
    }

    public static function host(): string
    {
        return 'distributor.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        $members = [];
        $identifiers = [];
        foreach ([['1001', '6100', 'S (mały)', '7000146845'], ['1002', '6200', 'M (średni)', '7000146847'], ['1003', '6300', 'L (duży)', '7000146849']] as [$id, $sku, $size, $code]) {
            $members[] = ['remote_id' => $id, 'sku' => $sku, 'name' => 'Półmaska 3M 6000, rozmiar '.$size];
            $identifiers[] = new B2bRemoteIdentifier(type: 'manufacturer_code', value: $code, remoteId: $id, label: 'rozmiar '.$size, field: 'manufacturerCode');
        }

        yield new B2bRemoteProduct(
            remoteId: '1001',
            sku: '6X00',
            name: 'Półmaska 3M 6000',
            members: $members,
            identifiers: $identifiers,
        );
    }

    public function totalProducts(): int
    {
        return 1;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return '3M';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: 31.20);
    }

    public function description(B2bRemoteProduct $product): string
    {
        return '';
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }
}
