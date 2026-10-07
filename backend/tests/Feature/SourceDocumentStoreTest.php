<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSourceDocument;
use App\Services\Enrichment\SourceDoc;
use App\Services\Enrichment\SourceDocumentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SourceDocumentStoreTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://www.coba.com/product/orthomat-standard';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(SourceDocumentStore::DISK);
        config()->set('enrichment.store_sources', true);
    }

    public function test_text_is_stored_once_compressed_and_read_back(): void
    {
        $store = new SourceDocumentStore;
        $sha = $store->put('Orthomat® Standard — AF060001');

        $this->assertSame(hash('sha256', 'Orthomat® Standard — AF060001'), $sha);
        $this->assertSame($sha, $store->put('Orthomat® Standard — AF060001'));
        $this->assertSame([$sha.'.txt.gz'], Storage::disk(SourceDocumentStore::DISK)->files());
        $this->assertSame('Orthomat® Standard — AF060001', $store->get($sha));
        $this->assertNull($store->get(str_repeat('0', 64)));
        $this->assertNull($store->get('../.env'));
    }

    public function test_record_upserts_per_card_url_and_text_with_identity(): void
    {
        $product = $this->product();
        $store = new SourceDocumentStore;
        $doc = [
            'url' => self::URL,
            'final_url' => self::URL.'/',
            'text' => 'Orthomat AF060001 surowy',
            'filtered_text' => 'Orthomat AF060001',
            'roles' => ['description', 'image'],
            'identity' => ['verdict' => 'hard', 'reason' => 'SKU AF060001 w tekście strony', 'key_type' => 'sku', 'key' => 'AF060001'],
            'markup_codes' => [['type' => 'sku', 'value' => 'AF060001']],
            'norm_facts' => [['label' => 'EN 13501-1']],
        ];

        $store->record($product, [$doc], 10);
        $store->record($product, [['url' => self::URL.'/'] + $doc], 11);

        $row = ProductSourceDocument::query()->sole();
        $this->assertSame(ProductSourceDocument::urlHash(self::URL), $row->url_hash);
        $this->assertSame('www.coba.com', $row->host);
        $this->assertSame(hash('sha256', 'Orthomat AF060001 surowy'), $row->sha256);
        $this->assertSame(hash('sha256', 'Orthomat AF060001'), $row->filtered_sha256);
        $this->assertSame(['hard', 'sku:AF060001', 'description,image', 11], [$row->identity_verdict, $row->identity_key, $row->roles, $row->description_version_id]);
        $this->assertSame([['type' => 'sku', 'value' => 'AF060001']], $row->markup_codes);
        $this->assertSame(mb_strlen('Orthomat AF060001 surowy'), $row->chars);
    }

    public function test_retention_keeps_newest_text_and_texts_of_published_versions(): void
    {
        $product = $this->product();
        $published = $this->version($product, 'published');
        $proposed = $this->version($product, 'proposed');
        $store = new SourceDocumentStore;

        $store->record($product, [['url' => self::URL, 'text' => 'wersja 1', 'fetched_at' => now()->subDays(3)]], $published);
        $store->record($product, [['url' => self::URL, 'text' => 'wersja 2', 'fetched_at' => now()->subDays(2)]], $proposed);
        $store->record($product, [['url' => self::URL, 'text' => 'wersja 3', 'fetched_at' => now()->subDay()]], null);
        $store->record($product, [['url' => 'https://inna.example/karta', 'text' => 'inna strona']], null);

        $this->assertEqualsCanonicalizing(
            [hash('sha256', 'wersja 1'), hash('sha256', 'wersja 3'), hash('sha256', 'inna strona')],
            ProductSourceDocument::query()->pluck('sha256')->all(),
            'wersja 2 (propozycja, starsza) wypada; opublikowana i najnowsza zostają'
        );
        // pliki zostają — tekst bywa źródłem innych kart
        $this->assertSame('wersja 2', $store->get(hash('sha256', 'wersja 2')));
    }

    public function test_same_text_keeps_pin_to_published_version(): void
    {
        $product = $this->product();
        $published = $this->version($product, 'published');
        $proposed = $this->version($product, 'proposed');
        $store = new SourceDocumentStore;

        $store->record($product, [['url' => self::URL, 'text' => 'ta sama treść']], $published);
        $store->record($product, [['url' => self::URL, 'text' => 'ta sama treść']], $proposed);

        $this->assertSame($published, ProductSourceDocument::query()->sole()->description_version_id);
    }

    public function test_for_product_lists_newest_text_per_url_in_role_without_text(): void
    {
        $product = $this->product();
        $store = new SourceDocumentStore;
        $store->record($product, [
            ['url' => self::URL, 'text' => 'stara', 'fetched_at' => now()->subDays(2), 'identity' => ['verdict' => 'soft']],
            ['url' => 'https://img.example/karta', 'text' => 'zdjęcie', 'roles' => ['image']],
        ], null);
        $store->record($product, [['url' => self::URL, 'text' => 'nowa', 'identity' => ['verdict' => 'hard', 'reason' => 'kod']]], null);

        $docs = $store->forProduct($product);

        $this->assertCount(1, $docs);
        $this->assertInstanceOf(SourceDoc::class, $docs[0]);
        $this->assertSame([self::URL, hash('sha256', 'nowa'), 'hard', 'kod', ['description']], [$docs[0]->url, $docs[0]->sha256, $docs[0]->verdict, $docs[0]->verdictReason, $docs[0]->roles]);
        $this->assertSame(['https://img.example/karta'], array_map(static fn (SourceDoc $d): string => $d->url, $store->forProduct($product, 'image')));
    }

    public function test_record_does_nothing_when_storing_sources_is_off(): void
    {
        config()->set('enrichment.store_sources', false);
        (new SourceDocumentStore)->record($this->product(), [['url' => self::URL, 'text' => 'tekst']], null);

        $this->assertSame(0, ProductSourceDocument::query()->count());
        $this->assertSame([], Storage::disk(SourceDocumentStore::DISK)->files());
    }

    public function test_documents_go_with_the_card(): void
    {
        $product = $this->product();
        (new SourceDocumentStore)->record($product, [['url' => self::URL, 'text' => 'tekst']], null);

        $product->delete();

        $this->assertSame(0, ProductSourceDocument::query()->count());
    }

    private function product(): Product
    {
        return Product::query()->create(['sku' => 'AF060001', 'name' => 'Orthomat Standard Szary 0.6m x 0.9m', 'manufacturer' => 'Coba']);
    }

    private function version(Product $product, string $status): int
    {
        return (int) DB::table('product_description_versions')->insertGetId([
            'product_id' => $product->id,
            'status' => $status,
            'origin' => 'enrichment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
