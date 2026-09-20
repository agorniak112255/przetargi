<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bCatalogSync;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Opis złożony z samego tekstu karty technicznej (zapis sprzed 20.09.2026) przy ponownym przebiegu witryny
 * producenta.
 *
 * Przebieg UVEX 20.09.2026 wyczyścił takie opisy: łącznik oddał pusty opis (panel sklepu go nie ma, jest tylko
 * PDF), odcisk opisu zgadzał się z odciskiem synchronizacji, więc reguła „producent wycofał swój opis”
 * uznała tekst za nieaktualny. Tyle że sklep nigdy tego tekstu nie miał — karta została bez opisu, czyli
 * poza propozycjami przetargowymi. Taki opis ma zostać nietknięty do czasu, aż ktoś napisze lepszy.
 */
final class B2bLegacyDatasheetDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private const DUMP = "Z karty technicznej (SST sandały uvex 1 G2 S1 P 6836.1-4.pdf):\nSzczegółowa specyfikacja techniczna: sandały uvex 1 G2\nuvex 1 G2 to ultralekkie obuwie ochronne z podeszwą PU.\nEN ISO 20345:2011 S1 P SRC";

    private User $user;

    private B2bAccount $account;

    private LegacyDumpFakeConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
        $this->account = B2bAccount::query()->create([
            'username' => 'uvex',
            'password' => 'sekret',
            'sites' => [LegacyDumpFakeConnector::host()],
            'connector' => LegacyDumpFakeConnector::key(),
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
        $this->connector = new LegacyDumpFakeConnector;
    }

    public function test_empty_shop_description_does_not_wipe_a_datasheet_only_description(): void
    {
        $product = $this->productWithDump();
        $link = $this->link($product, sha1(self::DUMP));

        $result = app(B2bAccountSyncRunner::class)->run($this->account->fresh(), delayMs: 0, connector: $this->connector);

        $this->assertSame(self::DUMP, (string) $product->fresh()->description);
        $this->assertSame(sha1(self::DUMP), (string) $link->fresh()->description_hash);
        $log = implode("\n", array_column((array) B2bSyncRun::query()->findOrFail($result['sync_run_id'])->log, 'text'));
        $this->assertStringNotContainsString('zniknął ze strony', $log);
    }

    public function test_real_shop_description_that_vanished_is_still_removed(): void
    {
        $shopText = 'Sandały ochronne uvex 1 G2 z podnoskiem kompozytowym i podwójnym rzepem.';
        $product = $this->productWithDump($shopText);
        $this->link($product, sha1($shopText));

        app(B2bAccountSyncRunner::class)->run($this->account->fresh(), delayMs: 0, connector: $this->connector);

        // to jest ten przypadek, dla którego reguła istnieje: tekst ze sklepu, którego sklep już nie ma
        $this->assertNull($product->fresh()->description);
    }

    public function test_restore_command_brings_the_wiped_description_back(): void
    {
        $product = $this->productWithDump(null);
        $link = $this->link($product, null);
        $this->datasheet($product);
        // karta bez opisu, ale bez karty technicznej z tekstem — nie ma z czego odtwarzać
        $bare = Product::query()->create(['sku' => 'P2', 'name' => 'Sandały uvex 2', 'manufacturer' => 'UVEX']);

        $this->artisan('b2b:restore-datasheet-descriptions')
            ->expectsOutputToContain('Opisy do odtworzenia:    1')
            ->expectsOutputToContain('Raport — nic nie zapisano')
            ->assertSuccessful();
        $this->assertNull($product->fresh()->description);

        $this->artisan('b2b:restore-datasheet-descriptions --apply')->assertSuccessful();

        $restored = (string) $product->fresh()->description;
        $this->assertStringStartsWith(B2bCatalogSync::DATASHEET_MARK.'SST sandały uvex 1 G2 S1 P 6836.1-4.pdf):', $restored);
        $this->assertStringContainsString('EN ISO 20345:2011 S1 P SRC', $restored);
        $this->assertSame(sha1($restored), (string) $link->fresh()->description_hash);
        $this->assertNull($bare->fresh()->description);
    }

    public function test_restore_command_leaves_cards_with_a_description_alone(): void
    {
        $product = $this->productWithDump('Sandały ochronne uvex 1 G2 z podnoskiem kompozytowym.');
        $this->link($product, null);
        $this->datasheet($product);

        $this->artisan('b2b:restore-datasheet-descriptions --apply')->assertSuccessful();

        $this->assertSame('Sandały ochronne uvex 1 G2 z podnoskiem kompozytowym.', (string) $product->fresh()->description);
    }

    private function productWithDump(?string $description = self::DUMP): Product
    {
        return Product::query()->create([
            'sku' => 'P1',
            'name' => 'Sandały uvex 1 G2 6836/2',
            'manufacturer' => 'UVEX',
            'description' => $description,
        ]);
    }

    private function link(Product $product, ?string $descriptionHash): B2bProductLink
    {
        return B2bProductLink::query()->create([
            'b2b_account_id' => $this->account->id,
            'product_id' => $product->id,
            'remote_id' => 'P1',
            'description_hash' => $descriptionHash,
        ]);
    }

    private function datasheet(Product $product): ProductDocument
    {
        return ProductDocument::query()->create([
            'product_id' => $product->id,
            'b2b_account_id' => $this->account->id,
            'path' => 'products/'.$product->id.'/karta.pdf',
            'source_url' => 'https://uvex.example.test/6836.pdf',
            'title' => 'SST sandały uvex 1 G2 S1 P 6836.1-4.pdf',
            'kind' => ProductDocument::KIND_DATASHEET,
            'sort_order' => 1,
            'text' => "Szczegółowa specyfikacja techniczna: sandały uvex 1 G2\nuvex 1 G2 to ultralekkie obuwie ochronne z podeszwą PU.\nEN ISO 20345:2011 S1 P SRC",
        ]);
    }
}

/** Witryna producenta, która przy karcie nie ma prozy opisu — tylko plik PDF. */
final class LegacyDumpFakeConnector implements B2bConnector, B2bManufacturerSite
{
    public static function key(): string
    {
        return 'legacydump';
    }

    public static function label(): string
    {
        return 'UVEX testowy';
    }

    public static function host(): string
    {
        return 'uvex.example.test';
    }

    public static function ownBrand(): string
    {
        return 'UVEX';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        yield new B2bRemoteProduct(
            remoteId: 'P1',
            sku: 'P1',
            name: 'Sandały uvex 1 G2 6836/2',
            sourceUrl: 'https://uvex.example.test/p/P1',
        );
    }

    public function totalProducts(): int
    {
        return 1;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'UVEX';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: 210.0);
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
