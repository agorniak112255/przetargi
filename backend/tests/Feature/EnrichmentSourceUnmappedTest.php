<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\EnrichProductJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentBatchItem;
use App\Models\ProductImage;
use App\Models\ProductSourcePin;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\B2b\B2bDescriptionSupplement;
use App\Services\B2b\B2bSupplementContext;
use App\Services\Enrichment\B2bSupplementNoPages;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\EnrichmentSlots;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ManufacturerDomainResolver;
use App\Services\Enrichment\ProductDocumentDownloader;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductImageCandidateVerifier;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\ProductSearchIdentity;
use App\Services\Enrichment\Sources\SourcePins;
use App\Services\Enrichment\Sources\SourceUnmappedException;
use App\Support\BhpAttributeNormalizer;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Karta cennika map_only bez strony z mapy importera i bez adresu człowieka (decyzja właściciela 10.10.2026): bez opisu
 * z internetu — model i wyszukiwarka niewołane, nic nie jest pobierane; opis, wersje, zdjęcia i normy zostają (także przy
 * force), karta w „Do przeglądu” z powodem source_unmapped; bez opisu — „ręcznie”. Członek modelu z takiej karty nie
 * dostaje opisu lidera. Zadanie kolejki nie ponawia takiej karty.
 */
final class EnrichmentSourceUnmappedTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_DESCRIPTION = 'Rękawice ZETA Z100 z nitrylu — opis wpisany wcześniej, z poprzedniego pobrania albo ręcznie. Opis musi '
        .'zostać na karcie, gdy importer cennika nie przypiął jeszcze strony tej karty.';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::fake(static fn () => Http::response('', 500));
    }

    public function test_force_run_without_map_keeps_everything_and_sends_card_to_review(): void
    {
        $card = $this->card(['enrichment_status' => Product::ENRICHMENT_DONE, 'description' => self::OLD_DESCRIPTION, 'norms' => 'EN 388']);
        app(DescriptionVersionStore::class)->record($card, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::OLD_DESCRIPTION,
            'primary_source_url' => 'https://www.example.net/rekawice-z100',
            'identity_verdict' => 'soft',
            'evidence_count' => 3,
        ]);
        $this->priceList($card, PriceList::POLICY_MAP_ONLY);
        $image = $this->webImage($card, 'https://www.example.net/img/Z100.jpg');
        $search = $this->search();
        $service = $this->service($search);

        try {
            $service->enrichProduct($card, true);
            $this->fail('karta map_only bez mapy — bez opisu z internetu');
        } catch (SourceUnmappedException $e) {
            $this->assertSame(SourcePins::REASON_NO_MAP, $e->reason);
        }

        $card->refresh();
        $this->assertSame(self::OLD_DESCRIPTION, $card->description);
        $this->assertSame('EN 388', $card->norms);
        $this->assertSame(Product::ENRICHMENT_DONE, $card->enrichment_status);
        $this->assertSame(Product::REVIEW_SOURCE_UNMAPPED, $card->review_reason);
        $this->assertNotNull($card->review_since);
        $this->assertStringContainsString(SourcePins::REASON_NO_MAP, (string) $card->enrichment_error);
        $this->assertSame([(int) $image->id], $card->images()->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        // nic nie cofnięte: jedna wersja, nadal opublikowana
        $this->assertSame(1, ProductDescriptionVersion::query()->where('product_id', $card->id)->count());
        $this->assertSame(ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::query()->where('product_id', $card->id)->value('status'));
        $this->assertNull($service->lastRunVersion());
        $search->shouldNotHaveReceived('searchBothPhases');
        $search->shouldNotHaveReceived('forgetProductCache');
        Http::assertNothingSent();
    }

    public function test_card_without_description_and_unresolved_map_row_goes_to_manual_with_importer_reason(): void
    {
        $card = $this->card();
        $list = $this->priceList($card, PriceList::POLICY_MAP_ONLY);
        ProductSourcePin::query()->create([
            'product_id' => $card->id, 'price_list_id' => $list->id, 'importer_key' => 'zeta-2026', 'importer_version' => 1,
            'url' => null, 'unresolved_reason' => 'kod Z100 nie występuje na stronach producenta', 'checked_at' => now(),
        ]);
        $search = $this->search();

        $this->expectException(SourceUnmappedException::class);
        try {
            $this->service($search)->enrichProduct($card, false);
        } finally {
            $card->refresh();
            $this->assertSame(Product::ENRICHMENT_MANUAL, $card->enrichment_status);
            $this->assertSame(Product::REVIEW_SOURCE_UNMAPPED, $card->review_reason);
            $this->assertStringContainsString('kod Z100 nie występuje na stronach producenta', (string) $card->enrichment_error);
            $this->assertSame('', (string) $card->description);
            $search->shouldNotHaveReceived('searchBothPhases');
        }
    }

    public function test_prefetch_of_unmapped_card_does_not_search(): void
    {
        $card = $this->card();
        $this->priceList($card, PriceList::POLICY_MAP_ONLY);
        $search = $this->search();
        $ready = false;

        $this->service($search)->prefetchProductSources($card, true, null, static function () use (&$ready): void {
            $ready = true;
        });

        $this->assertTrue($ready, 'zadanie opisu dostaje sygnał od razu');
        $search->shouldNotHaveReceived('searchBothPhases');
        $search->shouldNotHaveReceived('forgetProductCache');
        Http::assertNothingSent();
    }

    public function test_enrich_job_settles_unmapped_card_as_manual_without_retry(): void
    {
        $card = $this->card(['description' => self::OLD_DESCRIPTION]);
        $this->priceList($card, PriceList::POLICY_MAP_ONLY);
        $batch = $this->batch([$card]);
        $search = $this->search();

        (new EnrichProductJob((int) $card->id, (int) $batch->id, true))->handle($this->service($search), app(AiSettingsService::class), app(EnrichmentSlots::class));

        $item = ProductEnrichmentBatchItem::query()->where('batch_id', $batch->id)->where('product_id', $card->id)->sole();
        $this->assertSame(ProductEnrichmentBatchItem::STATUS_MANUAL, $item->status);
        $this->assertStringContainsString('Brak strony z importera cennika', (string) $item->message);
        $card->refresh();
        $this->assertSame(self::OLD_DESCRIPTION, $card->description);
        $this->assertSame(Product::ENRICHMENT_DONE, $card->enrichment_status);
        $this->assertSame(Product::REVIEW_SOURCE_UNMAPPED, $card->review_reason);
        $search->shouldNotHaveReceived('searchBothPhases');
    }

    public function test_unmapped_model_member_does_not_get_leader_description(): void
    {
        $leader = $this->card(['sku' => 'Z101', 'shop_source_url' => 'https://www.example.org/rekawice-z101']);
        $member = $this->card(['description' => self::OLD_DESCRIPTION]);
        $this->priceList($member, PriceList::POLICY_MAP_ONLY);
        $leaderVersion = app(DescriptionVersionStore::class)->record($leader, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => 'Opis lidera modelu z adresu wskazanego przez człowieka — rękawice ZETA Z101 z nitrylu do prac montażowych.',
            'primary_source_url' => 'https://www.example.org/rekawice-z101',
            'identity_verdict' => 'hard',
            'evidence_count' => 5,
        ]);
        $search = $this->search();

        try {
            $this->service($search)->applyModelDescription($member, $leaderVersion, null);
            $this->fail('członek bez mapy nie dostaje opisu lidera');
        } catch (SourceUnmappedException) {
            // oczekiwane
        }

        $member->refresh();
        $this->assertSame(self::OLD_DESCRIPTION, $member->description);
        $this->assertSame(Product::REVIEW_SOURCE_UNMAPPED, $member->review_reason);
        $this->assertSame(Product::ENRICHMENT_DONE, $member->enrichment_status);
        $this->assertSame(0, ProductDescriptionVersion::query()->where('product_id', $member->id)->count());
    }

    public function test_waiting_proposal_keeps_its_review_reason(): void
    {
        $card = $this->card(['enrichment_status' => Product::ENRICHMENT_DONE, 'description' => self::OLD_DESCRIPTION, 'review_reason' => Product::REVIEW_WORSE_VERSION, 'review_since' => now()->subDay()]);
        app(DescriptionVersionStore::class)->record($card, ProductDescriptionVersion::STATUS_PROPOSED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => 'Propozycja nowego opisu rękawic ZETA Z100 czekająca na decyzję handlowca w przeglądzie.',
            'primary_source_url' => 'https://www.example.net/rekawice-z100',
        ]);
        $this->priceList($card, PriceList::POLICY_MAP_ONLY);

        try {
            $this->service($this->search())->enrichProduct($card, true);
        } catch (SourceUnmappedException) {
            // oczekiwane
        }

        $card->refresh();
        $this->assertSame(Product::REVIEW_WORSE_VERSION, $card->review_reason);
        $this->assertSame(self::OLD_DESCRIPTION, $card->description);
    }

    public function test_b2b_supplement_of_unmapped_card_has_no_pages_and_does_not_search(): void
    {
        $text = 'Rękawice nitrylowe Z100, rozmiary 7–11.';
        $card = $this->card(['description' => $text, 'enrichment_status' => Product::ENRICHMENT_DONE]);
        $this->priceList($card, PriceList::POLICY_MAP_ONLY);
        $search = $this->search();
        $context = new B2bSupplementContext(
            accountId: 1, linkIds: [], descriptionHash: sha1($text), sourceDescriptionHash: null, sourceSha1: sha1($text),
            b2bText: $text, b2bUrl: 'https://b2b.example.com/produkt/z100', hosts: ['www.example.org'],
            hostsSha1: sha1('www.example.org'), minChars: 1000, productDescription: $text,
        );

        try {
            $this->service($search)->supplementB2bDescription($card, $context);
            $this->fail('karta map_only bez mapy — bez stron z internetu');
        } catch (B2bSupplementNoPages $e) {
            $this->assertStringContainsString(SourcePins::REASON_NO_MAP, $e->getMessage());
        }

        $search->shouldNotHaveReceived('catalogHitsOnHosts');
        $search->shouldNotHaveReceived('searchOnHosts');
        $search->shouldNotHaveReceived('searchBothPhases');
        Http::assertNothingSent();
    }

    public function test_image_retry_does_not_go_to_shop_cards_for_unmapped_card(): void
    {
        $card = $this->card(['description' => self::OLD_DESCRIPTION, 'enrichment_status' => Product::ENRICHMENT_DONE]);
        $this->priceList($card, PriceList::POLICY_MAP_ONLY);
        $search = $this->search();

        $this->assertSame([], $this->service($search)->imageFromShopCards($card));

        $search->shouldNotHaveReceived('shopCardsForImage');
        Http::assertNothingSent();
    }

    public function test_unmapped_card_is_not_a_b2b_supplement_candidate_until_mapped(): void
    {
        $short = 'Rękawica ochronna nitrylowa, kategoria II, dostępna w rozmiarach 7–11.';
        $account = B2bAccount::query()->create([
            'username' => 'konto-procera', 'password' => 'haslo', 'sites' => ['procera.example'], 'connector' => 'procera',
            'enrichment_sites' => ['www.example.org'],
        ]);
        $card = $this->card(['description' => $short, 'enrichment_status' => Product::ENRICHMENT_DONE]);
        $legacyCard = $this->card(['sku' => 'Z200', 'description' => $short, 'enrichment_status' => Product::ENRICHMENT_DONE]);
        foreach ([$card, $legacyCard] as $i => $product) {
            B2bProductLink::query()->create([
                'b2b_account_id' => $account->id, 'remote_id' => 'r'.$i, 'product_id' => $product->id,
                'remote_sku' => $product->sku, 'description_hash' => sha1($short),
            ]);
        }
        $list = $this->priceList($card, PriceList::POLICY_MAP_ONLY);
        $supplement = app(B2bDescriptionSupplement::class);

        // karta bez cennika z importerem zostaje kandydatem, karta map_only bez mapy — nie
        $this->assertSame([(int) $legacyCard->id], $supplement->candidateIds($account));

        ProductSourcePin::query()->create([
            'product_id' => $card->id, 'price_list_id' => $list->id, 'importer_key' => 'zeta-2026', 'importer_version' => 1,
            'url' => 'https://www.example.com/produkt/rekawice-z100', 'source_kind' => ProductSourcePin::KIND_MANUFACTURER,
            'match_kind' => ProductSourcePin::MATCH_EXACT_CODE, 'match_key' => 'Z100', 'checked_at' => now(),
        ]);
        $this->assertSame([(int) $card->id, (int) $legacyCard->id], $supplement->candidateIds($account));
    }

    /** @param  array<string, mixed>  $extra */
    private function card(array $extra = []): Product
    {
        return Product::query()->create([
            'sku' => 'Z100', 'name' => 'Rękawice nitrylowe ZETA Z100 czarne', 'manufacturer' => 'ZETA SAFETY',
            'catalog_price_net' => 10, 'purchase_price' => 8, 'stock' => 0,
            'enrichment_status' => Product::ENRICHMENT_QUEUED,
            ...$extra,
        ]);
    }

    private function priceList(Product $card, ?string $policy): PriceList
    {
        $list = PriceList::query()->firstOrCreate(['manufacturer_key' => PriceList::manufacturerKey('ZETA SAFETY')], [
            'manufacturer' => 'ZETA SAFETY', 'version' => '2026', 'original_filename' => 'zeta.xlsx',
            'source_policy' => $policy, 'importer_key' => $policy !== null ? 'zeta-2026' : null,
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id,
            'catalog_price_net' => 10, 'purchase_price' => 8, 'currency' => 'PLN', 'checked_at' => now(),
        ]);

        return $list;
    }

    /** @param  list<Product>  $cards */
    private function batch(array $cards): ProductEnrichmentBatch
    {
        $user = User::factory()->create();
        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRICE_LIST, 'scope_id' => 1, 'total' => count($cards), 'done' => 0, 'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_RUNNING, 'created_by' => $user->id, 'force' => true,
        ]);
        foreach ($cards as $card) {
            ProductEnrichmentBatchItem::query()->create([
                'batch_id' => $batch->id, 'product_id' => $card->id, 'sku' => $card->sku, 'name' => $card->name,
                'status' => ProductEnrichmentBatchItem::STATUS_QUEUED, 'previous_status' => Product::ENRICHMENT_NONE,
            ]);
            $card->update(['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        }

        return $batch;
    }

    private function search(): MockInterface
    {
        return Mockery::spy(HybridWebSearchService::class);
    }

    /** Model językowy, który nie może zostać wywołany. */
    private function service(MockInterface $search): ProductEnrichmentService
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldNotReceive('chatJsonEnrichment');
        $llm->shouldNotReceive('chatJson');
        $llm->shouldNotReceive('chatJsonWithImages');

        return new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );
    }

    private function webImage(Product $product, string $sourceUrl): ProductImage
    {
        $path = 'products/'.$product->id.'/'.uniqid('img', true).'.jpg';
        Storage::disk('public')->put($path, 'x');

        return ProductImage::query()->create([
            'product_id' => $product->id, 'path' => $path, 'source_url' => $sourceUrl, 'is_primary' => true,
            'sort_order' => 0, 'checksum' => hash('sha256', $path),
        ]);
    }
}
