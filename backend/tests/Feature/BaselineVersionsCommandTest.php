<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductSourceDocument;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\SourceDocumentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * products:baseline-versions (08.10.2026): wersja bazowa legacy_baseline dla kart z opisem bez wersji; products bez zmian.
 */
final class BaselineVersionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTION = 'Rękawice robocze powlekane nitrylem, mankiet ściągacz, norma EN 388 4121X.';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_preview_writes_nothing_and_apply_records_baseline_without_touching_card(): void
    {
        $card = $this->card('A-1', ['enrichment_payload' => ['primary_source_url' => 'https://coba.com/a-1', 'description' => 'x'],
            'enrichment_trace' => [['step' => 'desc']], 'packaging' => 'para']);
        $before = $card->fresh()->getAttributes();

        $this->artisan('products:baseline-versions', ['--no-fetch' => true])
            ->expectsOutputToContain('Kart z opisem bez wersji: 1')
            ->expectsOutputToContain('Podgląd — nic nie zapisano')
            ->assertSuccessful();
        $this->assertSame(0, ProductDescriptionVersion::query()->count());

        $this->artisan('products:baseline-versions', ['--no-fetch' => true, '--apply' => true])
            ->expectsOutputToContain('Zapisano wersji bazowych: 1')
            ->assertSuccessful();

        $version = ProductDescriptionVersion::query()->sole();
        $this->assertSame(ProductDescriptionVersion::STATUS_PUBLISHED, $version->status);
        $this->assertSame(ProductDescriptionVersion::ORIGIN_LEGACY_BASELINE, $version->origin);
        $this->assertSame(sha1(self::DESCRIPTION), $version->description_sha1);
        $this->assertSame('https://coba.com/a-1', $version->primary_source_url);
        $this->assertNull($version->identity_verdict);
        $this->assertNull($version->evidence_count);
        $this->assertSame('para', $version->packaging);
        $this->assertSame([['step' => 'desc']], $version->enrichment_trace);
        $this->assertSame($before, $card->fresh()->getAttributes());
        $this->assertTrue(app(DescriptionVersionStore::class)->hasProtectedPublished($card->fresh()));

        // drugi przebieg — karta ma już wersję
        $this->artisan('products:baseline-versions', ['--no-fetch' => true, '--apply' => true])
            ->expectsOutputToContain('Kart z opisem bez wersji: 0')
            ->assertSuccessful();
        $this->assertSame(1, ProductDescriptionVersion::query()->count());
    }

    public function test_skips_b2b_descriptions_name_only_descriptions_and_cards_with_versions(): void
    {
        $b2b = $this->card('B-1');
        $account = B2bAccount::query()->create([
            'username' => 'jan', 'password' => 'sekret', 'sites' => ['b2b.anro.net.pl'], 'connector' => 'anro',
            'created_by' => User::factory()->create()->id,
        ]);
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id, 'remote_id' => 'B-1', 'product_id' => $b2b->id, 'remote_sku' => 'B-1',
            'remote_name' => 'Rękawice', 'description_hash' => sha1(self::DESCRIPTION),
        ]);
        $this->card('B-2', ['name' => 'Rękawice nitrylowe B-2 długa nazwa', 'description' => 'Rękawice nitrylowe B-2 długa nazwa']);
        $this->card('B-3', ['description' => null]);
        $versioned = $this->card('B-4');
        app(DescriptionVersionStore::class)->record($versioned, ProductDescriptionVersion::STATUS_SHADOW, ProductDescriptionVersion::ORIGIN_STORED_SOURCES, ['description' => 'Opis z cienia, rękawice nitrylowe.']);
        $this->card('B-5');

        $this->artisan('products:baseline-versions', ['--no-fetch' => true, '--apply' => true])
            ->expectsOutputToContain('Pominięte: opis z B2B 1, bez opisu (sama nazwa) 1')
            ->expectsOutputToContain('Zapisano wersji bazowych: 1')
            ->assertSuccessful();

        $this->assertSame(['B-5'], Product::query()
            ->whereIn('id', ProductDescriptionVersion::query()->where('origin', 'legacy_baseline')->pluck('product_id'))
            ->pluck('sku')->all());
    }

    public function test_price_list_manufacturer_and_limit_scope(): void
    {
        $list = PriceList::query()->create([
            'manufacturer' => 'Coba', 'version' => '2026', 'original_filename' => 'plik.xlsx', 'rows_total' => 1,
            'products_created' => 1, 'products_updated' => 0, 'rows_skipped' => 0, 'product_ids' => [],
        ]);
        foreach (['C-1', 'C-2', 'C-3'] as $sku) {
            $card = $this->card($sku, ['manufacturer' => 'Coba']);
            ProductSourcePrice::query()->create([
                'product_id' => $card->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id,
                'catalog_price_net' => 10, 'purchase_price' => 8, 'currency' => 'PLN', 'checked_at' => now(),
            ]);
        }
        $this->card('M-1', ['manufacturer' => 'MAPA']);

        $this->artisan('products:baseline-versions', ['--no-fetch' => true, '--apply' => true, '--price-list' => $list->id, '--limit' => 2])
            ->assertSuccessful();
        $this->assertSame(2, ProductDescriptionVersion::query()->count());

        $this->artisan('products:baseline-versions', ['--no-fetch' => true, '--apply' => true, '--manufacturer' => 'mapa'])
            ->assertSuccessful();
        $this->assertSame(3, ProductDescriptionVersion::query()->count());
        $this->assertSame(1, ProductDescriptionVersion::query()->whereIn('product_id', Product::query()->where('sku', 'M-1')->pluck('id'))->count());

        $this->artisan('products:baseline-versions', ['--price-list' => 999])->assertFailed();
    }

    /** Wymaga części B (SourceIdentity, SourceDocumentStore, ProductPageFetcher z markup_codes). */
    public function test_fetch_judges_source_page_and_keeps_its_text_with_baseline(): void
    {
        config(['enrichment.store_sources' => true]);
        Storage::fake('sources');
        Http::fake([
            'coba.com/*' => Http::response(
                '<!doctype html><html><head><title>CCLIP25 Zaczep do kabli | Coba</title></head><body><h1>CCLIP25 Zaczep do kabli</h1>'
                .'<p>Zaczep do kabli Coba CCLIP25, stal ocynkowana, opakowanie 25 sztuk, do mocowania przewodów na budowie.</p></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8'],
            ),
            '*' => Http::response('', 404),
        ]);
        $card = $this->card('CCLIP25', ['manufacturer' => 'Coba', 'name' => 'Zaczep do kabli',
            'enrichment_payload' => ['primary_source_url' => 'https://coba.com/products/cclip25', 'primary_source_kind' => 'manufacturer']]);

        $this->artisan('products:baseline-versions', ['--apply' => true])
            ->expectsOutputToContain('hard 1')
            ->assertSuccessful();

        $version = ProductDescriptionVersion::query()->sole();
        $this->assertSame('hard', $version->identity_verdict);
        $document = ProductSourceDocument::query()->sole();
        $this->assertSame($card->id, $document->product_id);
        $this->assertSame($version->id, $document->description_version_id);
        $this->assertSame('hard', $document->identity_verdict);
        $this->assertStringContainsString('CCLIP25', (string) app(SourceDocumentStore::class)->get((string) $document->sha256));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function card(string $sku, array $attributes = []): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice testowe '.$sku,
            'manufacturer' => 'Testowy',
            'description' => self::DESCRIPTION,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 0,
            ...$attributes,
        ]);
    }
}
