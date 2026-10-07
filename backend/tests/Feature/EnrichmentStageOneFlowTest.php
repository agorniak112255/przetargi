<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ProductSourcesNotFoundException;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentBatchItem;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use App\Models\ProductSourceDocument;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ManufacturerDomainResolver;
use App\Services\Enrichment\ProductDocumentDownloader;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductImageCandidateVerifier;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\ProductSearchIdentity;
use App\Services\Enrichment\SourceDocumentStore;
use App\Services\ProductReviewService;
use App\Support\BhpAttributeNormalizer;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Etap 1 opisów z cenników (08.10.2026), przebieg wzbogacania: zapis wersji opisu z werdyktem tożsamości i dowodami,
 * zapis źródeł, propozycja zamiast gorszego opisu (karta wraca do stanu sprzed przebiegu), pamięć SKU nie nadpisuje
 * chronionego opisu, odrzucona strona źródła daje propozycję, powód przeglądu przy publikacji ze słabszej strony.
 */
final class EnrichmentStageOneFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'TX4521';

    /** Karta sklepu z kodem karty w adresie — werdykt hard. */
    private const CODED_PAGE = 'https://sklep.example/rekawice-testex-tx4521';

    private const CODED_IMAGE = 'https://sklep.example/media/testex-tx4521.jpg';

    /** Karta producenta bez kodu w adresie i tytule, kod tylko w tekście — werdykt soft. */
    private const MFR_PAGE = 'https://testex.example/produkty/rekawice-ochronne-montazowe';

    private const MFR_IMAGE = 'https://testex.example/img/tx4521-packshot.jpg';

    private const OLD_DESCRIPTION = 'Rękawice montażowe Testex TX4521 z dzianiny poliestrowej powlekanej nitrylem na części chwytnej. '
        .'Opis z poprzedniego pobrania ze strony z kodem wyrobu w adresie.';

    private const NEW_DESCRIPTION = 'Rękawice ochronne Testex TX4521 wykonane z dzianiny nylonowej powlekanej nitrylem, odporne na ścieranie. '
        .'Spełniają normę EN 388:2016 z poziomami 4131A. Przeznaczone do prac montażowych i magazynowych.';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake(SourceDocumentStore::DISK);
        config()->set('enrichment.manufacturer_domains.testex', ['testex.example']);
    }

    public function test_publish_saves_version_with_identity_evidence_and_stored_sources(): void
    {
        config()->set('enrichment.store_sources', true);
        $product = $this->card();

        $product = $this->enrich($product, self::CODED_PAGE, self::CODED_IMAGE);

        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status, (string) $product->enrichment_error);
        $payload = $product->enrichment_payload;
        $this->assertSame('hard', $payload['identity']['verdict'] ?? null, json_encode($payload['identity'] ?? null));
        $this->assertSame(self::CODED_PAGE, $payload['identity']['source_url'] ?? null);
        $this->assertGreaterThanOrEqual(1, $payload['evidence_summary']['explicit'] ?? 0);
        $this->assertContains('EN388:2016', array_column(array_filter(
            $payload['evidence'],
            static fn (array $e): bool => $e['status'] === 'explicit'
        ), 'value'));
        $this->assertNull($product->review_reason);

        $version = ProductDescriptionVersion::query()->sole();
        $this->assertSame((int) $version->id, $payload['description_version_id'] ?? null);
        $this->assertSame(ProductDescriptionVersion::STATUS_PUBLISHED, $version->status);
        $this->assertSame(ProductDescriptionVersion::ORIGIN_ENRICHMENT, $version->origin);
        $this->assertSame(sha1((string) $product->description), $version->description_sha1, 'baza = dokładnie tekst z products.description');
        $this->assertSame('hard', $version->identity_verdict);
        $this->assertSame($payload['evidence_summary']['explicit'], $version->evidence_count);
        $this->assertTrue(app(DescriptionVersionStore::class)->hasProtectedPublished($product));
        // pamięć SKU bez pochodzenia opisu — wpis należy do kodu, nie do tej karty
        $cache = ProductEnrichmentCache::query()->sole();
        $this->assertArrayNotHasKey('identity', $cache->enrichment_payload);
        $this->assertArrayNotHasKey('description_version_id', $cache->enrichment_payload);

        $doc = ProductSourceDocument::query()->sole();
        $this->assertSame((int) $version->id, (int) $doc->description_version_id);
        $this->assertSame(self::CODED_PAGE, $doc->url);
        $this->assertSame('hard', $doc->identity_verdict);
        $this->assertSame([ProductSourceDocument::ROLE_DESCRIPTION, ProductSourceDocument::ROLE_IMAGE], $doc->roleList());
        $raw = app(SourceDocumentStore::class)->get((string) $doc->sha256);
        $this->assertNotNull($raw);
        $this->assertStringContainsString('EN 388:2016', (string) $raw, 'tekst surowy strony, sprzed filtra stron');
        $this->assertNotNull($doc->filtered_sha256);
    }

    public function test_publish_from_page_without_card_code_marks_identity_soft_for_review(): void
    {
        $product = $this->enrich($this->card(), self::MFR_PAGE, self::MFR_IMAGE, normFrame: true);

        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status, (string) $product->enrichment_error);
        $this->assertSame(self::NEW_DESCRIPTION, $product->description);
        // te same skutki przebiegu, które propozycja cofa (test_worse_version_…): zdjęcie, normy, normy producenta
        $this->assertSame(1, $product->images()->count());
        $this->assertSame('EN 388:2016 4131A', $product->norms);
        $this->assertSame([['label' => 'EN 388:2016', 'value' => '4131A']], $product->manufacturer_norms['rows'] ?? null);
        $this->assertSame('soft', $product->enrichment_payload['identity']['verdict'] ?? null, json_encode($product->enrichment_payload['identity'] ?? null));
        $this->assertSame(Product::REVIEW_IDENTITY_SOFT, $product->review_reason);
        $this->assertNotNull($product->review_since);
        $version = ProductDescriptionVersion::query()->sole();
        $this->assertSame('soft', $version->identity_verdict);
        $this->assertSame(Product::REVIEW_IDENTITY_SOFT, $version->review_reason);
        // zapis źródeł wyłączony (phpunit.xml) — bez wierszy i plików
        $this->assertSame(0, ProductSourceDocument::query()->count());
        $this->assertSame([], Storage::disk(SourceDocumentStore::DISK)->allFiles());
    }

    public function test_publish_from_page_without_brand_marks_identity_none_for_review(): void
    {
        // strona bez marki: przepuszcza ją bramka kodu w tekście, ale ani klucza w adresie/tytule, ani marki z nazwą
        $product = $this->enrich($this->card(), 'https://sklep.example/produkty/rekawice-montazowe', 'https://sklep.example/img/tx4521.jpg', withBrand: false);

        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status, (string) $product->enrichment_error);
        $this->assertSame('none', $product->enrichment_payload['identity']['verdict'] ?? null, json_encode($product->enrichment_payload['identity'] ?? null));
        $this->assertSame(Product::REVIEW_IDENTITY_NONE, $product->review_reason);
    }

    public function test_worse_version_is_kept_as_proposal_and_card_returns_to_state_before_run(): void
    {
        config()->set('enrichment.store_sources', true);
        $oldManufacturerNorms = [
            'source' => ['connector' => 'strona-producenta', 'url' => 'https://testex.example/stara'],
            'rows' => [['label' => 'EN 388:2016', 'value' => '3121X']],
        ];
        $trace = ['at' => '2026-10-01', 'steps' => [['t' => 'desc', 'm' => 'opis ze strony z kodem w adresie']]];
        $product = $this->card([
            'description' => self::OLD_DESCRIPTION,
            'norms' => 'EN 420',
            'manufacturer_norms' => $oldManufacturerNorms,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_error' => 'Opis OK, nie udało się pobrać zdjęcia.',
            'enrichment_trace' => $trace,
            'enrichment_payload' => ['norms' => ['EN 420'], 'primary_source_url' => self::CODED_PAGE],
        ]);
        $oldImage = ProductImage::query()->create([
            'product_id' => $product->id, 'path' => 'products/'.$product->id.'/stare.jpg', 'source_url' => 'https://sklep.example/stare.jpg',
            'is_primary' => true, 'sort_order' => 0, 'checksum' => str_repeat('a', 64),
        ]);
        $base = app(DescriptionVersionStore::class)->record($product, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::OLD_DESCRIPTION,
            'primary_source_url' => self::CODED_PAGE,
            'identity_verdict' => 'hard',
            'evidence_count' => 1,
        ]);

        // strona producenta bez kodu w adresie (soft) z ramką norm — przebieg zapisuje normy i zdjęcie przed decyzją
        $product = $this->enrich($product, self::MFR_PAGE, self::MFR_IMAGE, force: true, normFrame: true);

        $this->assertSame(self::OLD_DESCRIPTION, $product->description);
        $this->assertSame('EN 420', $product->norms);
        $this->assertSame($oldManufacturerNorms, $product->manufacturer_norms);
        $this->assertSame([(int) $oldImage->id], $product->images()->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertSame([], array_values(array_diff(Storage::disk('public')->allFiles(), ['products/'.$product->id.'/stare.jpg'])), 'nowe zdjęcie usunięte z dysku');
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        // poprzedni komunikat zostaje, dochodzi informacja o propozycji
        $this->assertSame('Opis OK, nie udało się pobrać zdjęcia. Nowy opis czeka w „Do przeglądu” (gorszy od obecnego).', $product->enrichment_error);
        $this->assertSame($trace, $product->enrichment_trace);
        $this->assertSame(['norms' => ['EN 420'], 'primary_source_url' => self::CODED_PAGE], $product->enrichment_payload);
        $this->assertSame(Product::REVIEW_WORSE_VERSION, $product->review_reason);
        $this->assertNotNull($product->review_since);
        $this->assertSame(0, ProductEnrichmentCache::query()->count(), 'propozycja nie trafia do pamięci SKU');

        $this->assertSame(ProductDescriptionVersion::STATUS_PUBLISHED, $base->fresh()?->status, 'baza zostaje opublikowana');
        $proposal = ProductDescriptionVersion::query()->where('status', ProductDescriptionVersion::STATUS_PROPOSED)->sole();
        $this->assertSame(self::NEW_DESCRIPTION, $proposal->description);
        $this->assertSame('soft', $proposal->identity_verdict);
        $this->assertSame(Product::REVIEW_WORSE_VERSION, $proposal->review_reason);
        $this->assertIsArray($proposal->enrichment_trace);
        $this->assertSame(self::MFR_PAGE, $proposal->primary_source_url);
        // źródło propozycji zapisane i przypięte do niej
        $doc = ProductSourceDocument::query()->sole();
        $this->assertSame((int) $proposal->id, (int) $doc->description_version_id);
        $this->assertSame('soft', $doc->identity_verdict);
    }

    public function test_proposal_from_queue_restores_status_from_before_the_queue(): void
    {
        $product = $this->card([
            'description' => self::OLD_DESCRIPTION,
            'enrichment_status' => Product::ENRICHMENT_RUNNING,
        ]);
        app(DescriptionVersionStore::class)->record($product, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::OLD_DESCRIPTION,
            'identity_verdict' => 'hard',
        ]);

        // karta przyszła z kolejki („w trakcie”), bez pozycji partii — z opisem wraca jako gotowa
        $product = $this->enrich($product, self::MFR_PAGE, self::MFR_IMAGE, force: true);

        $this->assertSame(self::OLD_DESCRIPTION, $product->description);
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        $this->assertSame('Nowy opis czeka w „Do przeglądu” (gorszy od obecnego).', $product->enrichment_error);
        $this->assertSame(Product::REVIEW_WORSE_VERSION, $product->review_reason);
    }

    public function test_proposal_keeps_norms_changed_meanwhile_by_another_writer(): void
    {
        $oldManufacturerNorms = [
            'source' => ['connector' => 'strona-producenta', 'url' => 'https://testex.example/stara'],
            'rows' => [['label' => 'EN 388:2016', 'value' => '3121X']],
        ];
        $b2bNorms = ['source' => ['connector' => 'b2b'], 'rows' => [['label' => 'EN 388:2016', 'value' => '4121X']]];
        $product = $this->card([
            'description' => self::OLD_DESCRIPTION,
            'norms' => 'EN 420',
            'manufacturer_norms' => $oldManufacturerNorms,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
        app(DescriptionVersionStore::class)->record($product, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::OLD_DESCRIPTION,
            'identity_verdict' => 'hard',
        ]);

        // Przebieg zapisuje normy i normy producenta przed decyzją; zanim decyzja zapadnie (pobieranie zdjęcia),
        // synchronizacja B2B zapisuje na karcie swoje — propozycja ich nie cofa.
        $product = $this->enrich($product, self::MFR_PAGE, self::MFR_IMAGE, force: true, normFrame: true, onImage: static function () use ($product, $b2bNorms): void {
            DB::table('products')->where('id', $product->id)->update([
                'norms' => 'EN 388 (B2B)',
                'manufacturer_norms' => json_encode($b2bNorms),
            ]);
        });

        $this->assertSame(self::OLD_DESCRIPTION, $product->description);
        $this->assertSame(Product::REVIEW_WORSE_VERSION, $product->review_reason);
        $this->assertSame('EN 388 (B2B)', $product->norms, 'cudza zmiana norm zostaje');
        $this->assertSame($b2bNorms, $product->manufacturer_norms, 'cudza zmiana norm producenta zostaje');
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        $this->assertSame('Nowy opis czeka w „Do przeglądu” (gorszy od obecnego).', $product->enrichment_error);
    }

    public function test_proposal_keeps_status_changed_meanwhile_by_another_writer(): void
    {
        $product = $this->card([
            'description' => self::OLD_DESCRIPTION,
            'norms' => 'EN 420',
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
        app(DescriptionVersionStore::class)->record($product, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::OLD_DESCRIPTION,
            'identity_verdict' => 'hard',
        ]);

        // ktoś inny w trakcie przebiegu zmienił status karty (nie jest już „w trakcie” z tego przebiegu)
        $product = $this->enrich($product, self::MFR_PAGE, self::MFR_IMAGE, force: true, onImage: static function () use ($product): void {
            DB::table('products')->where('id', $product->id)->update([
                'enrichment_status' => Product::ENRICHMENT_MANUAL,
                'enrichment_error' => 'Opis wpisany ręcznie.',
            ]);
        });

        $this->assertSame(Product::ENRICHMENT_MANUAL, $product->enrichment_status);
        $this->assertSame('Opis wpisany ręcznie.', $product->enrichment_error);
        // normy zapisane przez ten przebieg (nikt ich nie ruszał) wracają
        $this->assertSame('EN 420', $product->norms);
        $this->assertSame(Product::REVIEW_WORSE_VERSION, $product->review_reason);
    }

    public function test_proposal_in_sync_batch_marks_item_done_with_proposal_message(): void
    {
        $user = User::factory()->create();
        $product = $this->card([
            'description' => self::OLD_DESCRIPTION,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_error' => 'Opis OK, nie udało się pobrać zdjęcia.',
        ]);
        app(DescriptionVersionStore::class)->record($product, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::OLD_DESCRIPTION,
            'identity_verdict' => 'hard',
        ]);

        $this->enrich($product, self::MFR_PAGE, self::MFR_IMAGE, force: true, syncAs: $user);

        $item = ProductEnrichmentBatchItem::query()->where('product_id', $product->id)->sole();
        $this->assertSame(ProductEnrichmentBatchItem::STATUS_DONE, $item->status);
        $this->assertSame('Nowy opis czeka w „Do przeglądu” (gorszy od obecnego).', $item->message);
        $this->assertSame(1, (int) ProductEnrichmentBatch::query()->sole()->done);
    }

    public function test_page_rejected_by_salesperson_is_skipped_before_gates(): void
    {
        $product = $this->card(['description' => self::OLD_DESCRIPTION, 'enrichment_status' => Product::ENRICHMENT_FAILED, 'enrichment_error' => 'stary błąd']);
        // opis z tej strony odrzucony wcześniej w przeglądzie (inny zapis adresu: ukośnik, parametr śledzący) —
        // strona nie wraca jako kandydat, więc bez innej strony nie ma z czego pisać opisu
        $this->rejectedVersion($product, 'https://SKLEP.example/rekawice-testex-tx4521/?utm_source=google');

        try {
            $this->enrich($product, self::CODED_PAGE, self::CODED_IMAGE);
            $this->fail('jedyna strona odrzucona — przebieg bez karty');
        } catch (ProductSourcesNotFoundException $e) {
            $this->assertStringContainsString('Nie znaleziono', $e->getMessage());
        }

        $product->refresh();
        $this->assertSame(self::OLD_DESCRIPTION, $product->description);
        $this->assertSame(0, $product->images()->count());
        $this->assertSame(0, ProductDescriptionVersion::query()->where('status', ProductDescriptionVersion::STATUS_PROPOSED)->count());
        $this->assertTraceSkipsRejected($product, self::CODED_PAGE);
    }

    public function test_rejected_page_is_skipped_and_other_page_describes_the_card(): void
    {
        $product = $this->card();
        $this->rejectedVersion($product, self::CODED_PAGE);

        // bez zdjęcia ślad przebiegu zostaje pełny (z udanym zdjęciem skrócony do ostatnich kroków)
        $product = $this->enrich($product, self::MFR_PAGE, self::MFR_IMAGE, searchResults: [
            ['url' => self::CODED_PAGE, 'title' => 'Rękawice ochronne montażowe', 'snippet' => ''],
            ['url' => self::MFR_PAGE, 'title' => 'Rękawice ochronne montażowe', 'snippet' => ''],
        ], brokenImage: true);

        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status, (string) $product->enrichment_error);
        $this->assertSame(self::NEW_DESCRIPTION, $product->description);
        $this->assertSame(self::MFR_PAGE, $product->enrichment_payload['primary_source_url'] ?? null);
        $this->assertNotContains(self::CODED_PAGE, $product->enrichment_payload['source_urls'] ?? []);
        $this->assertTraceSkipsRejected($product, self::CODED_PAGE);
    }

    public function test_page_given_by_human_wins_over_rejection(): void
    {
        // handlowiec wpisał adres strony, którą wcześniej odrzucono — człowiek wskazał, strona wraca
        $product = $this->card(['shop_source_url' => self::CODED_PAGE]);
        $this->rejectedVersion($product, self::CODED_PAGE);

        $product = $this->enrich($product, self::CODED_PAGE, self::CODED_IMAGE);

        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status, (string) $product->enrichment_error);
        $this->assertSame(self::NEW_DESCRIPTION, $product->description);
        $this->assertNull($product->review_reason);
        $this->assertSame(0, ProductDescriptionVersion::query()->where('status', ProductDescriptionVersion::STATUS_PROPOSED)->count());
    }

    public function test_image_from_other_card_does_not_mark_description_page_as_image_source(): void
    {
        config()->set('enrichment.store_sources', true);
        $otherPage = 'https://inny-sklep.example/testex-tx4521-rekawice';
        $otherImage = 'https://inny-sklep.example/img/testex-tx4521.jpg';

        // zdjęcie strony opisu nie pobiera się (404) — zdjęcie przychodzi z innej karty sklepu
        $product = $this->enrich($this->card(), self::CODED_PAGE, self::CODED_IMAGE, searchResults: [
            ['url' => self::CODED_PAGE, 'title' => 'Rękawice ochronne montażowe', 'snippet' => ''],
            ['url' => $otherPage, 'title' => 'Testex TX4521 rękawice', 'snippet' => ''],
        ], brokenImage: true, otherPages: [$otherPage => $otherImage]);

        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status, (string) $product->enrichment_error);
        $this->assertSame([$otherImage], $product->images()->pluck('source_url')->all());
        $docs = ProductSourceDocument::query()->get();
        $this->assertNotEmpty($docs);
        foreach ($docs as $doc) {
            $this->assertNotContains(ProductSourceDocument::ROLE_IMAGE, $doc->roleList(), $doc->url.' — zdjęcie nie pochodzi ze strony opisu');
        }
    }

    public function test_published_version_carries_run_files_and_manufacturer_norms_before(): void
    {
        $oldManufacturerNorms = [
            'source' => ['connector' => 'strona-producenta', 'url' => 'https://testex.example/stara'],
            'rows' => [['label' => 'EN 388:2016', 'value' => '3121X']],
        ];
        $product = $this->card(['manufacturer_norms' => $oldManufacturerNorms]);

        $product = $this->enrich($product, self::MFR_PAGE, self::MFR_IMAGE, normFrame: true);

        $this->assertSame(self::NEW_DESCRIPTION, $product->description);
        $version = ProductDescriptionVersion::query()->where('status', ProductDescriptionVersion::STATUS_PUBLISHED)->sole();
        $meta = app(DescriptionVersionStore::class)->meta($version);
        $this->assertSame($product->images()->pluck('id')->map(static fn ($id): int => (int) $id)->all(), $meta['web_file_ids']['images'] ?? null);
        $this->assertSame($oldManufacturerNorms, $meta['manufacturer_norms_before'] ?? null);
        $this->assertSame($product->manufacturer_norms, $meta['manufacturer_norms_written'] ?? null);
        $this->assertNotSame($oldManufacturerNorms, $product->manufacturer_norms, 'przebieg zapisał normy producenta');
        $this->assertArrayNotHasKey(DescriptionVersionStore::META_KEY, $product->enrichment_payload, 'dane techniczne tylko w wersji');
        $this->assertSame((int) $version->id, $product->enrichment_payload['description_version_id'] ?? null);
    }

    public function test_rejecting_shop_description_keeps_manufacturer_norms_written_later(): void
    {
        // opis ze sklepu — przebieg nie pisze norm producenta, więc wersja nie niesie ich wartości
        $product = $this->enrich($this->card(), self::CODED_PAGE, self::CODED_IMAGE);
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status, (string) $product->enrichment_error);
        $version = ProductDescriptionVersion::query()->where('status', ProductDescriptionVersion::STATUS_PUBLISHED)->sole();
        $this->assertArrayNotHasKey('manufacturer_norms_before', app(DescriptionVersionStore::class)->meta($version));
        // później normy producenta zapisała inna ścieżka (strona producenta)
        $later = ['source' => ['connector' => 'strona-producenta', 'url' => self::MFR_PAGE], 'rows' => [['label' => 'EN 388:2016', 'value' => '4131A']]];
        $product->update(['manufacturer_norms' => $later]);

        Queue::fake();
        app(ProductReviewService::class)->reject($product, (int) $version->id, User::factory()->create(), null);

        $this->assertSame($later, $product->fresh()->manufacturer_norms);
        $this->assertSame(ProductDescriptionVersion::STATUS_REJECTED, $version->fresh()->status);
    }

    public function test_run_value_check_compares_manufacturer_norms_by_facts_not_key_order(): void
    {
        // kolumna JSON może wrócić z bazy z kluczami w innej kolejności (MySQL) — to dalej wartość zapisana przez przebieg
        $same = new ReflectionMethod(ProductEnrichmentService::class, 'sameColumnValue');
        $written = ['source' => ['connector' => 'strona-producenta', 'url' => self::MFR_PAGE, 'synced_at' => '2026-10-08T10:00:00Z'],
            'rows' => [['label' => 'EN 388:2016', 'value' => '4131A']]];
        $reordered = ['rows' => [['value' => '4131A', 'label' => 'EN 388:2016']], 'source' => ['url' => self::MFR_PAGE, 'synced_at' => '2026-10-08T10:00:00Z', 'connector' => 'strona-producenta']];

        $this->assertTrue($same->invoke(null, 'manufacturer_norms', $reordered, $written));
        $this->assertFalse($same->invoke(null, 'manufacturer_norms', ['rows' => [['label' => 'EN 388:2016', 'value' => '4121X']], 'source' => $written['source']], $written));
        $this->assertFalse($same->invoke(null, 'manufacturer_norms', null, $written));
        $this->assertTrue($same->invoke(null, 'norms', 'EN 388:2016 4131A', 'EN 388:2016 4131A'));
        $this->assertFalse($same->invoke(null, 'norms', 'EN 388:2016 4131A', 'EN 388:2016'));
    }

    public function test_rejected_page_under_www_variant_of_host_is_skipped(): void
    {
        $product = $this->card();
        // handlowiec odrzucił opis z tej samej strony pod adresem z „www.”
        $this->rejectedVersion($product, str_replace('https://', 'https://www.', self::CODED_PAGE));

        $product = $this->enrich($product, self::MFR_PAGE, self::MFR_IMAGE, searchResults: [
            ['url' => self::CODED_PAGE, 'title' => 'Rękawice ochronne montażowe', 'snippet' => ''],
            ['url' => self::MFR_PAGE, 'title' => 'Rękawice ochronne montażowe', 'snippet' => ''],
        ], brokenImage: true);

        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status, (string) $product->enrichment_error);
        $this->assertSame(self::MFR_PAGE, $product->enrichment_payload['primary_source_url'] ?? null);
        $this->assertNotContains(self::CODED_PAGE, $product->enrichment_payload['source_urls'] ?? []);
        $this->assertTraceSkipsRejected($product, self::CODED_PAGE);
    }

    public function test_decision_at_write_time_sees_baseline_saved_during_run(): void
    {
        // przy starcie przebiegu opis karty nie ma bazy (zapis), ale zanim przebieg zapisze, inny proces zapisuje
        // bazę z mocniejszą tożsamością — decyzja pod blokadą daje propozycję
        $product = $this->card(['description' => self::OLD_DESCRIPTION, 'enrichment_status' => Product::ENRICHMENT_DONE]);

        $product = $this->enrich($product, self::MFR_PAGE, self::MFR_IMAGE, force: true, onImage: static function () use ($product): void {
            if (ProductDescriptionVersion::query()->where('product_id', $product->id)->exists()) {
                return;
            }
            app(DescriptionVersionStore::class)->record($product, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_LEGACY_BASELINE, [
                'description' => self::OLD_DESCRIPTION,
                'identity_verdict' => 'hard',
            ]);
        });

        $this->assertSame(self::OLD_DESCRIPTION, $product->description);
        $this->assertSame(Product::REVIEW_WORSE_VERSION, $product->review_reason);
        $this->assertSame(0, $product->images()->count(), 'zdjęcie przebiegu znika razem z propozycją');
        $this->assertSame(1, ProductDescriptionVersion::query()->where('status', ProductDescriptionVersion::STATUS_PUBLISHED)->count());
        $proposal = ProductDescriptionVersion::query()->where('status', ProductDescriptionVersion::STATUS_PROPOSED)->sole();
        $this->assertSame(Product::REVIEW_WORSE_VERSION, $proposal->review_reason);
        $this->assertSame([], app(DescriptionVersionStore::class)->meta($proposal), 'propozycja bez danych technicznych publikacji');
    }

    public function test_failed_card_write_rolls_back_published_version(): void
    {
        $product = $this->card(['description' => self::OLD_DESCRIPTION, 'enrichment_status' => Product::ENRICHMENT_DONE]);
        Product::updating(static function (Product $p): void {
            if ($p->isDirty('description') && $p->description === self::NEW_DESCRIPTION) {
                throw new RuntimeException('awaria zapisu karty');
            }
        });

        try {
            $this->enrich($product, self::CODED_PAGE, self::CODED_IMAGE, force: true);
            $this->fail('awaria zapisu karty przerywa przebieg');
        } catch (RuntimeException $e) {
            $this->assertSame('awaria zapisu karty', $e->getMessage());
        }

        $product->refresh();
        $this->assertSame(self::OLD_DESCRIPTION, $product->description);
        $this->assertSame(Product::ENRICHMENT_FAILED, $product->enrichment_status);
        $this->assertSame(0, ProductDescriptionVersion::query()->count(), 'wersja published cofnięta razem z zapisem karty');
    }

    public function test_sku_cache_is_abandoned_when_description_changes_under_lock(): void
    {
        $product = $this->card(['enrichment_status' => Product::ENRICHMENT_FAILED]);
        $this->skuCache();
        // Pod blokadą opis karty jest inny niż ten, który pamięć SKU widziała (w prawdziwym przebiegu: zapisany przez inny
        // proces przed blokadą; tu symulowany w tej samej transakcji, więc cofa się razem z nią) — zapis porzucony.
        ProductDescriptionVersion::created(static function (ProductDescriptionVersion $v) use ($product): void {
            if ($v->origin === ProductDescriptionVersion::ORIGIN_SKU_CACHE) {
                DB::table('products')->where('id', $product->id)->update(['description' => self::OLD_DESCRIPTION]);
            }
        });

        try {
            $this->enrich($product, self::CODED_PAGE, self::CODED_IMAGE, searchResults: []);
            $this->fail('pamięć SKU porzucona — pełna ścieżka bez wyników wyszukiwania kończy się brakiem karty');
        } catch (ProductSourcesNotFoundException $e) {
            $this->assertStringContainsString('Nie znaleziono', $e->getMessage());
        }

        $product->refresh();
        $this->assertNull($product->description, 'opis z pamięci SKU nie wszedł na kartę');
        $this->assertSame(0, ProductDescriptionVersion::query()->count(), 'wersja z pamięci SKU cofnięta');
        $steps = array_column($product->enrichment_trace['steps'] ?? [], 'm');
        $this->assertContains('pamięć SKU pominięta — karta zmieniła się w trakcie przebiegu', $steps);
    }

    private function rejectedVersion(Product $product, string $url): void
    {
        $version = ProductDescriptionVersion::query()->create([
            'product_id' => $product->id, 'status' => ProductDescriptionVersion::STATUS_REJECTED, 'origin' => ProductDescriptionVersion::ORIGIN_ENRICHMENT,
            'description' => 'Cudzy opis.', 'primary_source_url' => $url, 'decision' => ProductDescriptionVersion::DECISION_REJECTED,
        ]);
        app(DescriptionVersionStore::class)->blockSourceUrl($version);
    }

    private function assertTraceSkipsRejected(Product $product, string $url): void
    {
        $steps = $product->enrichment_trace['steps'] ?? [];
        $hits = array_filter($steps, static fn (array $step): bool => str_contains((string) ($step['m'] ?? ''), 'pominięto stronę odrzuconą przez handlowca')
            && in_array($url, (array) ($step['urls'] ?? []), true));
        $this->assertNotEmpty($hits, 'ślad przebiegu: '.json_encode($steps, JSON_UNESCAPED_UNICODE));
    }

    public function test_sku_cache_does_not_overwrite_protected_description(): void
    {
        $product = $this->card(['description' => self::OLD_DESCRIPTION, 'enrichment_status' => Product::ENRICHMENT_FAILED]);
        app(DescriptionVersionStore::class)->record($product, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::OLD_DESCRIPTION,
            'identity_verdict' => 'hard',
        ]);
        $this->skuCache();

        // pamięć SKU pominięta — pełna ścieżka bez wyników wyszukiwania kończy się brakiem karty, opis zostaje
        try {
            $this->enrich($product, self::CODED_PAGE, self::CODED_IMAGE, searchResults: []);
            $this->fail('bez wyników wyszukiwania przebieg kończy się brakiem karty');
        } catch (Throwable $e) {
            $this->assertStringContainsString('Nie znaleziono', $e->getMessage());
        }

        $product->refresh();
        $this->assertSame(self::OLD_DESCRIPTION, $product->description);
        $this->assertSame(1, ProductDescriptionVersion::query()->count());
    }

    public function test_sku_cache_on_card_without_baseline_records_published_version(): void
    {
        $product = $this->card(['description' => self::OLD_DESCRIPTION, 'enrichment_status' => Product::ENRICHMENT_FAILED, 'review_reason' => Product::REVIEW_IDENTITY_SOFT, 'review_since' => now()]);
        $this->skuCache();

        $product = $this->enrich($product, self::CODED_PAGE, self::CODED_IMAGE, searchResults: []);

        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        $this->assertSame(self::NEW_DESCRIPTION, $product->description);
        $this->assertNull($product->review_reason);
        $version = ProductDescriptionVersion::query()->sole();
        $this->assertSame(ProductDescriptionVersion::ORIGIN_SKU_CACHE, $version->origin);
        $this->assertSame(ProductDescriptionVersion::STATUS_PUBLISHED, $version->status);
        $this->assertSame(sha1(self::NEW_DESCRIPTION), $version->description_sha1);
        $this->assertSame((int) $version->id, $product->enrichment_payload['description_version_id'] ?? null);
    }

    /** @param  array<string, mixed>  $attributes */
    private function card(array $attributes = []): Product
    {
        return Product::query()->create([
            'sku' => self::SKU,
            'name' => 'Rękawice montażowe Testex TX4521 powlekane nitrylem',
            'manufacturer' => 'Testex',
            'catalog_price_net' => 12,
            'purchase_price' => 10,
            'stock' => 1,
            'description' => null,
            'enrichment_status' => Product::ENRICHMENT_NONE,
            ...$attributes,
        ]);
    }

    private function skuCache(): void
    {
        ProductEnrichmentCache::query()->create([
            ...ProductEnrichmentCache::normalizeKey('Testex', self::SKU),
            'description' => self::NEW_DESCRIPTION,
            'enrichment_payload' => ['norms' => [], 'confidence' => 0.9, 'features' => [], 'specs' => []],
            'image_urls' => [],
            'source_urls' => [self::CODED_PAGE],
        ]);
    }

    /**
     * Przebieg enrichProduct na jednej stronie z wyszukiwarki: strona HTML (z marką albo bez, z ramką norm albo bez),
     * filtr stron oddaje fakty, model oddaje NEW_DESCRIPTION z normą EN 388.
     *
     * @param  list<array<string, string>>|null  $searchResults
     * @param  callable|null  $onImage  wołane przy pobieraniu zdjęcia strony — po zapisie norm przez przebieg, przed decyzją o wersji
     * @param  array<string, string>  $otherPages  inna karta sklepu => jej zdjęcie
     */
    private function enrich(
        Product $product,
        string $pageUrl,
        string $imageUrl,
        bool $force = false,
        bool $withBrand = true,
        bool $normFrame = false,
        ?array $searchResults = null,
        ?callable $onImage = null,
        ?User $syncAs = null,
        bool $brokenImage = false,
        array $otherPages = [],
    ): Product {
        $brand = $withBrand ? 'Testex ' : '';
        $facts = 'Rękawice ochronne '.$brand.'TX4521 z dzianiny nylonowej powlekanej nitrylem, odporne na ścieranie. '
            .'Norma EN 388:2016, poziomy 4131A. Do prac montażowych i magazynowych, pewny chwyt suchych i lekko zaolejonych elementów.';
        $htmlFor = static fn (string $imageUrl): string => '<html><head><title>Rękawice ochronne montażowe</title>'
            .'<meta property="og:image" content="'.$imageUrl.'"></head><body><h1>Rękawice ochronne montażowe</h1>'
            .'<img src="'.$imageUrl.'" alt="Rękawice">'
            .'<div class="product-description"><p>'.$facts.'</p>'
            .'<p>Kod wyrobu: TX4521. '.($withBrand ? 'Producent: Testex. ' : '').'Dzianina nylonowa bez szwów dobrze przylega do dłoni, '
            .'a powłoka nitrylowa na części chwytnej chroni przed ścieraniem i zabrudzeniem olejami. Mankiet ściągacz utrzymuje '
            .'rękawicę na dłoni. Rękawice przeznaczone do montażu drobnych elementów, prac magazynowych i ogólnych prac warsztatowych.</p></div>'
            .($normFrame ? '<ul class="normy"><li>EN 388:2016<br>4131A</li></ul>' : '')
            .'</body></html>';
        $html = $htmlFor($imageUrl);

        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldReceive('searchBothPhases')->zeroOrMoreTimes()->andReturn([
            'results' => $searchResults ?? [['url' => $pageUrl, 'title' => 'Rękawice ochronne montażowe', 'snippet' => '']],
            'errors' => [],
        ]);
        $search->shouldReceive('dropListingResults')->zeroOrMoreTimes()->andReturnUsing(static fn (array $results): array => $results);
        $search->shouldReceive('moreCatalogHits')->zeroOrMoreTimes()->andReturn([]);
        $search->shouldReceive('searchMappedRetailers')->zeroOrMoreTimes()->andReturn([]);
        $search->shouldReceive('searchWebWithoutLocalIndex')->zeroOrMoreTimes()->andReturn(['results' => [], 'images' => [], 'errors' => []]);
        $search->shouldReceive('forgetProductCache')->zeroOrMoreTimes();
        $search->shouldReceive('shopCardsForImage')->zeroOrMoreTimes()->andReturn([]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $handler = static function (array $messages) use ($facts, $pageUrl): array {
            $system = (string) ($messages[0]['content'] ?? '');
            if (str_contains($system, 'filtrem treści')) {
                return ['pages' => [['url' => $pageUrl, 'text' => $facts]]];
            }

            return [
                'features' => ['Powłoka nitrylowa na części chwytnej'], 'specs' => [], 'norms' => ['EN 388:2016 4131A'],
                'certificates' => [], 'materials' => ['nitryl', 'nylon'], 'use_cases' => ['prace montażowe'],
                'image_urls' => [], 'source_urls' => [$pageUrl], 'description' => self::NEW_DESCRIPTION, 'confidence' => 0.9,
            ];
        };
        $llm->shouldReceive('chatJsonEnrichment')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJsonWithImages')->zeroOrMoreTimes()->andReturn(['candidates' => []]);

        Http::fake(function (Request $request) use ($html, $htmlFor, $imageUrl, $onImage, $brokenImage, $otherPages) {
            $url = $request->url();
            foreach ($otherPages as $otherPage => $otherImage) {
                if (str_starts_with($url, $otherImage)) {
                    return Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']);
                }
                if (str_starts_with($url, $otherPage)) {
                    return Http::response($htmlFor($otherImage), 200, ['Content-Type' => 'text/html']);
                }
            }
            if (str_starts_with($url, $imageUrl)) {
                if ($onImage !== null) {
                    $onImage();
                }

                return $brokenImage ? Http::response('', 404) : Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']);
            }

            return str_contains($url, 'r.jina.ai')
                ? Http::response("Title: x\n\nMarkdown Content:\nundefined", 200, ['Content-Type' => 'text/plain'])
                : Http::response($html, 200, ['Content-Type' => 'text/html']);
        });

        $service = new ProductEnrichmentService(
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
        if ($syncAs !== null) {
            $service->enrichProductSync($product, $syncAs, $force);
        } else {
            $service->enrichProduct($product, $force);
        }

        return $product->refresh();
    }

    private function jpeg(): string
    {
        $im = imagecreatetruecolor(320, 480);
        imagefill($im, 0, 0, imagecolorallocate($im, 40, 120, 200));
        ob_start();
        imagejpeg($im, null, 85);
        imagedestroy($im);

        return (string) ob_get_clean();
    }
}
