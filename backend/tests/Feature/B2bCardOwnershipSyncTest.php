<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\BrandDictionaryEntry;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRemoteShopField;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\PriceListImportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Karta producenta chroniona przed dystrybutorem (plan łączenia kart, etap A, 23.09.2026). Dystrybutor wielu marek
 * (P4S, Ardon) dopięty do karty producenta przez products:merge-duplicate przepisywał przy każdym przebiegu
 * producenta karty, listę rozmiarów, dowód kategorii i kolejność zdjęć. Testy odtwarzają zastane wiersze: karta
 * dystrybutora z pierwszego przebiegu, scalenie poleceniem, potem drugi przebieg dystrybutora.
 */
final class B2bCardOwnershipSyncTest extends TestCase
{
    use RefreshDatabase;

    private const WEB = 'https://img.example.test/optime-h540a.png';

    private const P4S_EXTRA = 'https://p4s.example.test/foto/99254-2.png';

    private User $user;

    private B2bAccount $mmm;

    private B2bAccount $p4s;

    private OwnershipFakeConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
        $this->mmm = $this->account('mmm', '3m', 'b2b.3m.example.test');
        $this->p4s = $this->account('p4s', 'p4s', 'b2b.p4s.example.test');
        $this->connector = new OwnershipFakeConnector;
    }

    public function test_distributor_second_run_on_merged_b2b_owner_card_keeps_card_fields_and_gallery(): void
    {
        $keep = $this->producerCard();
        B2bProductLink::query()->create(['b2b_account_id' => $this->mmm->id, 'remote_id' => 'H540A', 'product_id' => $keep->id]);
        $this->slot($keep, $this->mmm, 40.00);
        $this->galleryOf($keep);

        // pierwszy przebieg P4S: własna karta obok karty producenta (SKU nie trafił), bez zdjęć
        $this->connector->items = [$this->p4sItem()];
        $this->connector->manufacturer = '3m';
        $this->connector->category = 'Słuchawki przeciwhałasowe';
        $this->connector->variantSummary = 'Rozmiar: uniwersalny (P4S)';
        $this->connector->shopFields = [new B2bRemoteShopField('', 'Tłumienie', 'SNR 35 dB')];
        $this->sync($this->p4s);
        $drop = Product::query()->where('sku', 'P4S-99254')->sole();

        $this->merge($keep, $drop);
        $before = $this->imagesOf($keep);

        // drugi przebieg: inna cena, kategoria, rozmiary, tabelka i galeria z obcym zdjęciem
        $this->travel(8)->days();
        $this->connector->price = 30.00;
        $this->connector->category = 'Ochronniki słuchu P4S';
        $this->connector->variantSummary = 'Rozmiary: S, M';
        $this->connector->shopFields = [new B2bRemoteShopField('', 'Tłumienie', 'SNR 31 dB')];
        $this->connector->urls = [self::WEB, self::P4S_EXTRA];
        $this->sync($this->p4s);

        $card = $keep->fresh();
        $this->assertSame('3M', $card->manufacturer);
        $this->assertSame('Nauszniki 3M Optime III H540A', $card->name);
        $this->assertSame('Rozmiar uniwersalny', $card->variant_summary);
        $this->assertSame('Ochrona słuchu > Nauszniki', $card->category_evidence);
        // slot ceny, tabelka i powiązanie dystrybutora — jak dotąd
        $slot = ProductSourcePrice::query()->where('product_id', $keep->id)
            ->where('source_key', ProductSourcePrice::b2bKey($this->p4s->id))->sole();
        $this->assertEquals(30.00, (float) $slot->purchase_price);
        $shopCard = ProductShopCard::query()->where('product_id', $keep->id)->where('b2b_account_id', $this->p4s->id)->sole();
        $this->assertSame('SNR 31 dB', $shopCard->fields[0]['rows'][0]['value']);
        $link = B2bProductLink::query()->where('b2b_account_id', $this->p4s->id)->where('remote_id', '99254')->sole();
        $this->assertSame($keep->id, (int) $link->product_id);
        $this->assertTrue($link->last_seen_at->isToday());
        // zdjęcia karty producenta nietknięte: bez obcego ujęcia, bez stempla P4S, ta sama kolejność i główne
        $this->assertEquals($before, $this->imagesOf($keep));
        $this->assertSame(0, $this->connector->downloads[self::P4S_EXTRA] ?? 0);
        // cena karty dalej z konta producenta
        $this->assertEquals(40.00, (float) $card->purchase_price);
    }

    public function test_distributor_fills_only_empty_sizes_and_category_evidence_on_file_owner_card(): void
    {
        // karta ATG z cennika z pliku (jak #9669 „MaxiChem Cut”: konto treści ATG bez rozmiarów)
        $ardon = $this->account('ardon', 'ardon', 'b2b.ardon.example.test');
        $this->importFile('ATG', [['sku' => '76-733', 'name' => 'Rękawice MaxiChem Cut 76-733', 'catalog_price_net' => 20.0, 'purchase_price' => 20.0, 'currency' => 'PLN']]);
        $keep = Product::query()->where('sku', '76-733')->sole();
        $this->assertNull($keep->variant_summary);

        $this->connector->items = [$this->ardonGroup(['07', '08'])];
        $this->connector->manufacturer = 'atg';
        $this->connector->category = 'Rękawice chemiczne';
        $this->connector->variantSummary = 'Rozmiary: 07 (A3083/07); 08 (A3083/08)';
        $this->sync($ardon);
        $drop = Product::query()->where('sku', 'A3083/07')->sole();
        $this->merge($keep, $drop);
        $this->assertNull($keep->fresh()->variant_summary);
        $this->assertNull($keep->fresh()->category_evidence);

        // puste pola karty producenta — dystrybutor je wypełnia; producent i nazwa zostają; karta bez zdjęć dostaje zdjęcie
        $this->connector->urls = [self::P4S_EXTRA];
        $this->sync($ardon);
        $this->assertSame(1, ProductImage::query()->where('product_id', $keep->id)->count());
        $card = $keep->fresh();
        $this->assertSame('Rozmiary: 07 (A3083/07); 08 (A3083/08)', $card->variant_summary);
        $this->assertSame('Rękawice chemiczne', $card->category_evidence);
        $this->assertSame('ATG', $card->manufacturer);
        $this->assertSame('Rękawice MaxiChem Cut 76-733', $card->name);

        // wypełnionych pól kolejny przebieg nie nadpisuje
        $this->connector->items = [$this->ardonGroup(['07', '08', '09'])];
        $this->connector->category = 'Rękawice ochronne';
        $this->connector->variantSummary = 'Rozmiary: 07 (A3083/07); 08 (A3083/08); 09 (A3083/09)';
        $this->sync($ardon);
        $card = $keep->fresh();
        $this->assertSame('Rozmiary: 07 (A3083/07); 08 (A3083/08)', $card->variant_summary);
        $this->assertSame('Rękawice chemiczne', $card->category_evidence);
        $this->assertSame('ATG', $card->manufacturer);
        // nowa pozycja dystrybutora dalej dopina się do karty
        $this->assertSame($keep->id, (int) B2bProductLink::query()->where('remote_id', 'A3083/09')->value('product_id'));
    }

    public function test_card_without_owner_is_overwritten_by_distributor_as_before(): void
    {
        // konto 3M istnieje, ale z tą kartą powiązania nie ma — karta należy tylko do dystrybutora
        $this->connector->items = [$this->p4sItem()];
        $this->connector->manufacturer = '3M';
        $this->connector->category = 'Słuchawki przeciwhałasowe';
        $this->connector->variantSummary = 'Rozmiar: uniwersalny (P4S)';
        $this->sync($this->p4s);
        $card = Product::query()->where('sku', 'P4S-99254')->sole();
        $web = (new ProductImageDownloader)->storeBytes($card, OwnershipFakeConnector::png(self::WEB), 'image/png', self::WEB, 0);
        $this->assertNotNull($web);

        $this->connector->manufacturer = '3m';
        $this->connector->category = 'Ochronniki słuchu P4S';
        $this->connector->variantSummary = 'Rozmiary: S, M';
        $this->connector->urls = [self::WEB, self::P4S_EXTRA];
        $this->sync($this->p4s);

        $card->refresh();
        $this->assertSame('3m', $card->manufacturer);
        $this->assertSame('Rozmiary: S, M', $card->variant_summary);
        $this->assertSame('Ochronniki słuchu P4S', $card->category_evidence);
        $this->assertSame(2, ProductImage::query()->where('product_id', $card->id)->count());
        $this->assertSame($this->p4s->id, (int) $web->fresh()->b2b_account_id);
    }

    public function test_owner_account_on_its_own_protected_card_writes_as_before(): void
    {
        $card = $this->producerCard();
        B2bProductLink::query()->create(['b2b_account_id' => $this->mmm->id, 'remote_id' => 'H540A', 'product_id' => $card->id]);
        B2bProductLink::query()->create(['b2b_account_id' => $this->p4s->id, 'remote_id' => '99254', 'product_id' => $card->id]);
        $this->galleryOf($card);

        $this->connector->items = [new B2bRemoteProduct(remoteId: 'H540A', sku: '3M-H540A', name: 'Optime III H540A')];
        $this->connector->manufacturer = '3m';
        $this->connector->category = 'Ochronniki słuchu 3M';
        $this->connector->variantSummary = 'Rozmiary: uniwersalny, składany';
        $this->connector->urls = [self::P4S_EXTRA];
        $this->sync($this->mmm);

        $card->refresh();
        $this->assertSame('3m', $card->manufacturer);
        // nazwa istniejącej karty nie zmienia się w żadnym łączniku (decyzja użytkownika 15.09.2026)
        $this->assertSame('Nauszniki 3M Optime III H540A', $card->name);
        $this->assertSame('Rozmiary: uniwersalny, składany', $card->variant_summary);
        $this->assertSame('Ochronniki słuchu 3M', $card->category_evidence);
        $this->assertSame(3, ProductImage::query()->where('product_id', $card->id)->count());
    }

    public function test_brand_dictionary_makes_3m_account_owner_of_peltor_card(): void
    {
        $card = $this->producerCard(manufacturer: 'PELTOR');
        B2bProductLink::query()->create(['b2b_account_id' => $this->mmm->id, 'remote_id' => 'H540A', 'product_id' => $card->id]);
        B2bProductLink::query()->create(['b2b_account_id' => $this->p4s->id, 'remote_id' => '99254', 'product_id' => $card->id]);
        BrandDictionaryEntry::query()->create(['term' => 'Peltor', 'kind' => BrandDictionaryEntry::KIND_BRAND, 'manufacturer' => '3M', 'detect_in_query' => true]);

        $this->connector->items = [$this->p4sItem()];
        $this->connector->manufacturer = 'Peltor';
        $this->connector->variantSummary = 'Rozmiary: S, M';
        $this->sync($this->p4s);

        $card->refresh();
        $this->assertSame('PELTOR', $card->manufacturer);
        $this->assertSame('Rozmiar uniwersalny', $card->variant_summary);

        // bez wpisu słownika „PELTOR” to inna marka niż konto 3M — karta bez właściciela, dystrybutor nadpisuje
        // pojedynczo — hak modelu unieważnia pamięć słownika (masowe delete() go omija)
        BrandDictionaryEntry::query()->get()->each->delete();
        $this->sync($this->p4s);
        $card->refresh();
        $this->assertSame('Peltor', $card->manufacturer);
        $this->assertSame('Rozmiary: S, M', $card->variant_summary);
    }

    public function test_producer_file_updates_name_and_manufacturer_of_card_linked_only_to_distributor(): void
    {
        $ardon = $this->account('ardon', 'ardon', 'b2b.ardon.example.test');
        $card = Product::query()->create([
            'sku' => '76-733', 'name' => 'Rękawice ATG MaxiChem Cut A3083', 'manufacturer' => 'atg',
            'catalog_price_net' => 25.0, 'purchase_price' => 25.0, 'currency' => 'PLN',
        ]);
        B2bProductLink::query()->create(['b2b_account_id' => $ardon->id, 'remote_id' => 'A3083/07', 'product_id' => $card->id]);

        $this->importFile('ATG', [['sku' => '76-733', 'name' => 'Rękawice MaxiChem Cut 76-733', 'catalog_price_net' => 20.0, 'purchase_price' => 20.0, 'currency' => 'PLN']]);

        $card->refresh();
        $this->assertSame('Rękawice MaxiChem Cut 76-733', $card->name);
        $this->assertSame('ATG', $card->manufacturer);
    }

    public function test_producer_file_keeps_name_and_manufacturer_of_card_linked_to_owner_account(): void
    {
        $ardon = $this->account('ardon', 'ardon', 'b2b.ardon.example.test');
        $atg = $this->account('atg', 'atg', 'atg.example.test');
        $card = Product::query()->create([
            'sku' => '76-733', 'name' => 'MaxiChem Cut 76-733 (strona ATG)', 'manufacturer' => 'atg',
            'catalog_price_net' => 25.0, 'purchase_price' => 25.0, 'currency' => 'PLN',
        ]);
        B2bProductLink::query()->create(['b2b_account_id' => $ardon->id, 'remote_id' => 'A3083/07', 'product_id' => $card->id]);
        B2bProductLink::query()->create(['b2b_account_id' => $atg->id, 'remote_id' => '76-733', 'product_id' => $card->id]);

        $this->importFile('ATG', [['sku' => '76-733', 'name' => 'Rękawice MaxiChem Cut 76-733', 'catalog_price_net' => 20.0, 'purchase_price' => 20.0, 'currency' => 'PLN']]);

        $card->refresh();
        $this->assertSame('MaxiChem Cut 76-733 (strona ATG)', $card->name);
        $this->assertSame('atg', $card->manufacturer);
        // cena z pliku i tak trafia do slotu
        $this->assertEquals(20.0, (float) ProductSourcePrice::query()->where('product_id', $card->id)
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)->value('purchase_price'));
    }

    public function test_other_manufacturer_file_keeps_name_of_card_linked_to_distributor(): void
    {
        // cennik dystrybutora z pliku (inna marka niż karta) nie jest właścicielem — jak dotąd nazwa zostaje
        $ardon = $this->account('ardon', 'ardon', 'b2b.ardon.example.test');
        $card = Product::query()->create([
            'sku' => '76-733', 'name' => 'Rękawice ATG MaxiChem Cut A3083', 'manufacturer' => '',
            'catalog_price_net' => 25.0, 'purchase_price' => 25.0, 'currency' => 'PLN',
        ]);
        B2bProductLink::query()->create(['b2b_account_id' => $ardon->id, 'remote_id' => 'A3083/07', 'product_id' => $card->id]);

        $this->importFile('Ardon', [['sku' => '76-733', 'name' => 'Rękawice z pliku Ardon', 'catalog_price_net' => 20.0, 'purchase_price' => 20.0, 'currency' => 'PLN']]);

        $this->assertSame('Rękawice ATG MaxiChem Cut A3083', $card->fresh()->name);
    }

    private function producerCard(string $manufacturer = '3M'): Product
    {
        return Product::query()->create([
            'sku' => '3M-H540A',
            'name' => 'Nauszniki 3M Optime III H540A',
            'manufacturer' => $manufacturer,
            'variant_summary' => 'Rozmiar uniwersalny',
            'category_evidence' => 'Ochrona słuchu > Nauszniki',
            'catalog_price_net' => 40.00,
            'purchase_price' => 40.00,
            'currency' => 'PLN',
        ]);
    }

    /** Zdjęcie z sieci (bez konta, główne) i zdjęcie konta producenta za nim. */
    private function galleryOf(Product $card): void
    {
        $downloader = new ProductImageDownloader;
        $this->assertNotNull($downloader->storeBytes($card, OwnershipFakeConnector::png(self::WEB), 'image/png', self::WEB, 0));
        $this->assertNotNull($downloader->storeBytes(
            $card,
            OwnershipFakeConnector::png('3m'),
            'image/png',
            'https://b2b.3m.example.test/h540a.png',
            1,
            (int) $this->mmm->id,
        ));
        ProductImage::query()->where('product_id', $card->id)->where('sort_order', 0)->update(['is_primary' => true]);
        ProductImage::query()->where('product_id', $card->id)->where('sort_order', 1)->update(['is_primary' => false]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function imagesOf(Product $card): array
    {
        return ProductImage::query()->where('product_id', $card->id)->orderBy('id')
            ->get(['id', 'source_url', 'b2b_account_id', 'sort_order', 'is_primary', 'updated_at'])
            ->toArray();
    }

    private function slot(Product $card, B2bAccount $account, float $price): void
    {
        ProductSourcePrice::query()->create([
            'product_id' => $card->id,
            'source_key' => ProductSourcePrice::b2bKey((int) $account->id),
            'b2b_account_id' => $account->id,
            'catalog_price_net' => $price,
            'purchase_price' => $price,
            'currency' => 'PLN',
            'checked_at' => now(),
        ]);
    }

    private function p4sItem(): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: '99254',
            sku: 'P4S-99254',
            name: 'Nauszniki PELTOR Optime III H540A',
            sourceUrl: 'https://p4s.example.test/99254',
        );
    }

    /**
     * @param  list<string>  $sizes
     */
    private function ardonGroup(array $sizes): B2bRemoteProduct
    {
        $members = array_map(static fn (string $size): array => [
            'remote_id' => 'A3083/'.$size,
            'sku' => 'A3083/'.$size,
            'name' => 'Rękawice ATG MaxiChem Cut A3083 rozm. '.$size,
        ], $sizes);

        return new B2bRemoteProduct(
            remoteId: $members[0]['remote_id'],
            sku: $members[0]['sku'],
            name: 'Rękawice ATG MaxiChem Cut A3083',
            members: $members,
        );
    }

    private function merge(Product $keep, Product $drop): void
    {
        $backup = storage_path('framework/testing/merge-duplicate-ownership.json');
        $this->artisan('products:merge-duplicate', ['--pair' => [$keep->id.':'.$drop->id], '--apply' => true, '--backup' => $backup])
            ->expectsOutputToContain('Scalono 1 par.')
            ->assertSuccessful();
        @unlink($backup);
        $this->assertNull(Product::query()->find($drop->id));
    }

    private function account(string $username, string $connector, string $site): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $username,
            'password' => 'sekret',
            'sites' => [$site],
            'connector' => $connector,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    private function sync(B2bAccount $account): void
    {
        app(B2bAccountSyncRunner::class)->run($account->fresh(), delayMs: 0, connector: $this->connector);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importFile(string $manufacturer, array $rows): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ownimp').'.pdf';
        file_put_contents($path, "%PDF-1.4\n");
        $file = new UploadedFile($path, 'cennik.pdf', 'application/pdf', null, true);
        try {
            app(PriceListImportService::class)->importFromProducts($file, $manufacturer, 'v1', $this->user, $rows);
        } finally {
            @unlink($path);
        }
    }
}

/**
 * Łącznik testowy dystrybutora (albo producenta — rolę wyznacza klucz łącznika konta, nie ta klasa): pozycje,
 * producent, kategoria, lista rozmiarów, tabelka i galeria ustawiane z testu.
 */
final class OwnershipFakeConnector implements B2bConnector, B2bImageGallery, B2bShopFieldSource
{
    /** @var list<B2bRemoteProduct> */
    public array $items = [];

    public string $manufacturer = '';

    public float $price = 35.00;

    public ?string $category = null;

    public ?string $variantSummary = null;

    /** @var list<B2bRemoteShopField> */
    public array $shopFields = [];

    /** @var list<string> */
    public array $urls = [];

    /** @var array<string, int> */
    public array $downloads = [];

    public static function key(): string
    {
        return 'ownership-fake';
    }

    public static function label(): string
    {
        return 'Właściciel karty — test';
    }

    public static function host(): string
    {
        return 'ownership.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        foreach ($this->items as $item) {
            yield new B2bRemoteProduct(
                remoteId: $item->remoteId,
                sku: $item->sku,
                name: $item->name,
                category: $this->category,
                sourceUrl: $item->sourceUrl,
                variantSummary: $this->variantSummary,
                members: $item->members,
            );
        }
    }

    public function totalProducts(): int
    {
        return count($this->items);
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return $this->manufacturer;
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: $this->price);
    }

    public function description(B2bRemoteProduct $product): string
    {
        return '';
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return $this->urls === [] ? null : $this->imageAt($this->urls[0]);
    }

    public function imageUrls(B2bRemoteProduct $product): array
    {
        return $this->urls;
    }

    public function imageAt(string $url): ?B2bRemoteImage
    {
        $this->downloads[$url] = ($this->downloads[$url] ?? 0) + 1;

        return new B2bRemoteImage(bytes: self::png($url), mime: 'image/png', sourceUrl: $url);
    }

    public function shopFields(B2bRemoteProduct $product): array
    {
        return $this->shopFields;
    }

    /** Plik PNG zależny od ziarna — różne ziarna, różne sumy kontrolne (dedup zdjęć). */
    public static function png(string $seed): string
    {
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, crc32($seed) & 0xFFFFFF);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
