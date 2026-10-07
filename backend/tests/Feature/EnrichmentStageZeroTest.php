<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\EnrichProductJob;
use App\Jobs\PrefetchProductSourcesJob;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentBatchItem;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Enrichment\EnrichmentSlots;
use App\Services\Enrichment\PrefetchSlots;
use App\Services\Enrichment\ProductEnrichmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Etap 0 planu opisów z cenników z plików (SUPON_AI_Plan_Opisy_z_cennikow_2026-10-07.md): przerwana partia przywraca
 * stan karty sprzed kolejki (B3/B4), kody norm spoza źródeł wypadają z opisu (pkt 5), ponowne pobranie hurtem pomija
 * gotowe opisy od producenta (pkt 6), naprawa kart z „błąd: Anulowano” zapisanych przed zmianą.
 */
final class EnrichmentStageZeroTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTION = 'Rękawice robocze powlekane nitrylem na dzianinie nylonowej, mankiet ściągacz, do prac montażowych.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['ai.enrichment_batch_limit' => 50]);
        $this->admin = User::factory()->withRole('admin')->create();
        Queue::fake();
    }

    public function test_cancelled_batch_restores_status_from_before_the_queue_and_keeps_trace(): void
    {
        $trace = ['at' => '2026-10-01', 'steps' => [['t' => 'desc', 'm' => 'opis z karty producenta']]];
        $done = $this->product('DONE', [
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_error' => 'Opis OK, nie udało się pobrać zdjęcia.',
            'enrichment_trace' => $trace,
        ]);
        $none = $this->product('NONE', ['description' => null]);
        $failed = $this->product('FAILED', [
            'enrichment_status' => Product::ENRICHMENT_FAILED,
            'enrichment_error' => 'Nie znaleziono karty potwierdzającej produkt',
        ]);
        $service = app(ProductEnrichmentService::class);

        $queued = $service->enqueueProductIds([$done->id, $none->id, $failed->id], $this->admin, true);

        // kolejka nie kasuje śladu pochodzenia obecnego opisu
        $this->assertSame(Product::ENRICHMENT_QUEUED, $done->fresh()?->enrichment_status);
        $this->assertSame($trace, $done->fresh()?->enrichment_trace);

        $result = $service->cancelBatch($queued['batch']);

        $this->assertSame(3, $result['marked_products']);
        $this->assertSame(Product::ENRICHMENT_DONE, $done->fresh()?->enrichment_status);
        $this->assertSame('Opis OK, nie udało się pobrać zdjęcia.', $done->fresh()?->enrichment_error);
        $this->assertSame($trace, $done->fresh()?->enrichment_trace);
        $this->assertSame(Product::ENRICHMENT_NONE, $none->fresh()?->enrichment_status);
        $this->assertNull($none->fresh()?->enrichment_error);
        $this->assertSame(Product::ENRICHMENT_FAILED, $failed->fresh()?->enrichment_status);
        $this->assertSame('Nie znaleziono karty potwierdzającej produkt', $failed->fresh()?->enrichment_error);
        $this->assertSame(
            [ProductEnrichmentBatchItem::STATUS_CANCELLED],
            ProductEnrichmentBatchItem::query()->where('batch_id', $queued['batch']->id)->distinct()->pluck('status')->all()
        );
    }

    public function test_cancel_does_not_touch_result_written_by_the_batch(): void
    {
        $product = $this->product('DONE-IN-BATCH', ['enrichment_status' => Product::ENRICHMENT_FAILED, 'enrichment_error' => 'stary błąd']);
        $service = app(ProductEnrichmentService::class);
        $queued = $service->enqueueProductIds([$product->id], $this->admin, true);
        // partia zdążyła opisać kartę
        $product->update(['enrichment_status' => Product::ENRICHMENT_DONE, 'enrichment_error' => null]);

        $service->cancelBatch($queued['batch']);

        $this->assertSame(Product::ENRICHMENT_DONE, $product->fresh()?->enrichment_status);
        $this->assertNull($product->fresh()?->enrichment_error);
    }

    public function test_item_without_previous_state_keeps_old_cancel_behaviour(): void
    {
        $product = $this->product('LEGACY', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $batch = $this->batch();
        // pozycja sprzed migracji / karta była już w innej kolejce: brak stanu do przywrócenia
        ProductEnrichmentBatchItem::query()->create([
            'batch_id' => $batch->id, 'product_id' => $product->id, 'sku' => 'LEGACY', 'name' => 'x', 'status' => 'queued',
        ]);

        app(ProductEnrichmentService::class)->cancelBatch($batch);

        $this->assertSame(Product::ENRICHMENT_FAILED, $product->fresh()?->enrichment_status);
        $this->assertSame('Anulowano przez użytkownika', $product->fresh()?->enrichment_error);
    }

    public function test_stop_all_restores_cards_of_open_batches(): void
    {
        Sanctum::actingAs($this->admin);
        $done = $this->product('STOP-DONE', ['enrichment_status' => Product::ENRICHMENT_DONE]);
        $none = $this->product('STOP-NONE', ['description' => null]);
        app(ProductEnrichmentService::class)->enqueueProductIds([$done->id, $none->id], $this->admin, true);

        $this->postJson('/api/product-enrichment-batches/stop-all')
            ->assertOk()
            ->assertJsonPath('marked_products', 2);

        $this->assertSame(Product::ENRICHMENT_DONE, $done->fresh()?->enrichment_status);
        $this->assertSame(Product::ENRICHMENT_NONE, $none->fresh()?->enrichment_status);
    }

    public function test_enrich_and_prefetch_jobs_of_cancelled_batch_restore_previous_state(): void
    {
        $forEnrich = $this->product('JOB-ENRICH', ['enrichment_status' => Product::ENRICHMENT_DONE]);
        $forPrefetch = $this->product('JOB-PREFETCH', ['description' => null]);
        $service = app(ProductEnrichmentService::class);
        $queued = $service->enqueueProductIds([$forEnrich->id, $forPrefetch->id], $this->admin, true);
        $batch = $queued['batch'];
        $batch->markCancelledFlag();
        // przebieg zaczęty przed anulowaniem
        $forEnrich->update(['enrichment_status' => Product::ENRICHMENT_RUNNING]);

        (new EnrichProductJob($forEnrich->id, $batch->id, true))->handle($service, app(AiSettingsService::class), app(EnrichmentSlots::class));
        (new PrefetchProductSourcesJob($forPrefetch->id, $batch->id, true))->handle($service, app(PrefetchSlots::class));

        $this->assertSame(Product::ENRICHMENT_DONE, $forEnrich->fresh()?->enrichment_status);
        $this->assertNull($forEnrich->fresh()?->enrichment_error);
        $this->assertSame(Product::ENRICHMENT_NONE, $forPrefetch->fresh()?->enrichment_status);
    }

    public function test_norm_codes_missing_from_sources_are_dropped_from_description_and_lists(): void
    {
        $product = $this->product('NORMS', ['name' => 'Rękawice nitrylowe NORMS']);
        $description = 'Rękawice nitrylowe na dzianinie nylonowej z mankietem ściągaczem, do prac montażowych i magazynowych. '
            ."Spełniają EN 388:2016 z poziomem 4131X. Odporność na przecięcie potwierdza poziom 4544C.\n\n"
            .'Mata pomocnicza ma wymiary 1200 x 1800 mm i grubość 9 mm, pasuje do stanowisk pakowania.';
        $extracted = [
            'norms' => ['EN 388:2016 4131X', 'EN 407 X1XXXX'],
            'specs' => ['Wymiary: 1200 x 1800 mm', 'Poziom EN 388: 4544C'],
            'attributes' => ['poziomy_en388' => '4544C', 'normy_en' => ['EN 388', 'EN 407']],
        ];
        $pages = [['url' => 'https://producent.pl/norms', 'text' => 'Rękawice NORMS. EN 388:2016 4131X. Wymiary 1200 x 1800 mm.']];

        $method = new ReflectionMethod(ProductEnrichmentService::class, 'withoutUnsupportedNormClaims');
        $result = $method->invoke(app(ProductEnrichmentService::class), $product, $description, $extracted, $pages);

        $this->assertStringContainsString('4131X', $result['description']);
        $this->assertStringNotContainsString('4544C', $result['description']);
        // same cyfry bez kontekstu normy to wymiar, nie poziom normy
        $this->assertStringContainsString('1200 x 1800 mm', $result['description']);
        $this->assertSame(['EN 388:2016 4131X'], $result['extracted']['norms']);
        $this->assertSame(['Wymiary: 1200 x 1800 mm'], $result['extracted']['specs']);
        $this->assertNull($result['extracted']['attributes']['poziomy_en388']);
        $this->assertSame(['EN 388'], $result['extracted']['attributes']['normy_en']);
        $this->assertNotSame([], $result['dropped_norm_claims']);
    }

    public function test_norm_codes_from_manufacturer_norm_facts_are_accepted(): void
    {
        $product = $this->product('NORM-FACTS', [
            'manufacturer_norms' => ['source' => ['connector' => 'strona-producenta'], 'rows' => [['label' => 'EN 388:2016', 'value' => '4131A']]],
        ]);
        $description = 'Rękawice nitrylowe na dzianinie nylonowej z mankietem ściągaczem, do prac montażowych. Poziomy EN 388: 4131A.';

        $method = new ReflectionMethod(ProductEnrichmentService::class, 'withoutUnsupportedNormClaims');
        $result = $method->invoke(app(ProductEnrichmentService::class), $product, $description, [], [['url' => 'https://p.pl', 'text' => 'Rękawice.']]);

        $this->assertSame($description, $result['description']);
        $this->assertSame([], $result['dropped_norm_claims']);
    }

    public function test_norm_designations_match_without_prefixes_and_editions_only_when_source_gives_one(): void
    {
        $product = $this->product('NORM-FORMS');
        $description = 'Rękawice nitrylowe na dzianinie nylonowej z mankietem ściągaczem, do prac montażowych. '
            .'Odporność na przecięcie według EN ISO 13997. Ochrona chemiczna według EN ISO 374-1:2016. '
            .'Spełnia EN 388:2016 oraz EN 407. Obuwie towarzyszące spełnia EN ISO 20345:2022. '
            .'Rękaw spełnia EN ISO 20345:2011 w starszym wydaniu.';
        $pages = [[
            'url' => 'https://producent.com/p',
            // strona angielska: bez „EN”, sklep: bez „ISO”, wydanie w nawiasie, „EN-407”, norma obuwia bez wydania… i z innym
            'text' => 'Cut resistance ISO 13997. Chemical EN 374-1:2016. EN 388 (2016). EN-407. Footwear EN ISO 20345. EN ISO 20345:2022.',
        ]];
        $method = new ReflectionMethod(ProductEnrichmentService::class, 'withoutUnsupportedNormClaims');

        $result = $method->invoke(app(ProductEnrichmentService::class), $product, $description, [], $pages);

        $this->assertStringContainsString('EN ISO 13997', $result['description']);
        $this->assertStringContainsString('EN ISO 374-1:2016', $result['description']);
        $this->assertStringContainsString('EN 388:2016 oraz EN 407', $result['description']);
        $this->assertStringContainsString('EN ISO 20345:2022', $result['description']);
        // źródło podaje wydania tej normy (2022), a nie 2011 — to zdanie wypada
        $this->assertStringNotContainsString(':2011', $result['description']);
        $this->assertCount(1, $result['dropped_norm_claims']);
    }

    public function test_cancel_leaves_card_waiting_in_another_open_batch(): void
    {
        $product = $this->product('TWO-BATCHES', ['description' => null]);
        $service = app(ProductEnrichmentService::class);
        $first = $service->enqueueProductIds([$product->id], $this->admin, true);
        $second = $service->enqueueProductIds([$product->id], $this->admin, true);

        $service->cancelBatch($first['batch']);

        // partia B wciąż na nią czeka — anulowanie A nie zdejmuje karty z kolejki
        $this->assertSame(Product::ENRICHMENT_QUEUED, $product->fresh()?->enrichment_status);

        $service->cancelBatch($second['batch']);

        // B nie zna stanu sprzed kolejki (karta była już w kolejce A) — jak dotąd: błąd z komunikatem
        $this->assertSame(Product::ENRICHMENT_FAILED, $product->fresh()?->enrichment_status);
    }

    public function test_cancel_restores_card_whose_prefetch_was_running(): void
    {
        $product = $this->product('PREFETCH-RUNNING', ['enrichment_status' => Product::ENRICHMENT_DONE]);
        $service = app(ProductEnrichmentService::class);
        $queued = $service->enqueueProductIds([$product->id], $this->admin, true);
        // prefetch w toku: pozycja „running”, karta wciąż „queued”, job zarezerwowany (nie ma go w kolejce do usunięcia)
        ProductEnrichmentBatchItem::query()->where('batch_id', $queued['batch']->id)->update(['status' => ProductEnrichmentBatchItem::STATUS_RUNNING]);

        $service->cancelBatch($queued['batch']);

        $this->assertSame(Product::ENRICHMENT_DONE, $product->fresh()?->enrichment_status);
    }

    public function test_description_made_only_of_unsupported_norm_claims_is_rejected(): void
    {
        $product = $this->product('ONLY-NORMS');
        $method = new ReflectionMethod(ProductEnrichmentService::class, 'withoutUnsupportedNormClaims');

        $this->expectException(RuntimeException::class);
        $method->invoke(app(ProductEnrichmentService::class), $product, 'Spełnia EN 388:2016 z poziomem 4544C.', [], [['url' => 'https://p.pl', 'text' => 'Rękawice.']]);
    }

    public function test_bulk_force_skips_cards_with_manufacturer_description_unless_asked(): void
    {
        Sanctum::actingAs($this->admin);
        $mfr = $this->product('BULK-MFR', [
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['primary_source_url' => 'https://producent.pl/a', 'primary_source_kind' => 'manufacturer'],
        ]);
        $mfrFailed = $this->product('BULK-MFR-FAILED', [
            'enrichment_status' => Product::ENRICHMENT_FAILED,
            'enrichment_payload' => ['primary_source_url' => 'https://producent.pl/b', 'primary_source_kind' => 'manufacturer'],
        ]);
        $shop = $this->product('BULK-SHOP', [
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['primary_source_url' => 'https://sklep.pl/c', 'primary_source_kind' => 'shop'],
        ]);

        $this->postJson('/api/products/enrich', ['product_ids' => [$mfr->id, $mfrFailed->id, $shop->id], 'force' => true])
            ->assertStatus(202)
            ->assertJsonPath('skipped_manufacturer', 1)
            ->assertJsonPath('skipped_manufacturer_ids', [$mfr->id])
            ->assertJsonPath('product_ids', [$mfrFailed->id, $shop->id]);
        $this->assertSame(Product::ENRICHMENT_DONE, $mfr->fresh()?->enrichment_status);

        $this->postJson('/api/products/enrich', ['product_ids' => [$mfr->id], 'force' => true])
            ->assertStatus(422)
            ->assertJsonPath('skipped_manufacturer_ids', [$mfr->id]);

        $this->postJson('/api/products/enrich', ['product_ids' => [$mfr->id], 'force' => true, 'include_manufacturer' => true])
            ->assertStatus(202)
            ->assertJsonPath('product_ids', [$mfr->id])
            ->assertJsonPath('skipped_manufacturer', 0);
    }

    public function test_price_list_force_skips_cards_with_manufacturer_description(): void
    {
        Sanctum::actingAs($this->admin);
        $mfr = $this->product('PL-MFR', [
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['primary_source_url' => 'https://producent.pl/a', 'primary_source_kind' => 'manufacturer'],
        ]);
        $shop = $this->product('PL-SHOP', [
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['primary_source_url' => 'https://sklep.pl/c', 'primary_source_kind' => 'shop'],
        ]);
        $list = PriceList::query()->create([
            'manufacturer' => 'Testowy', 'version' => '2026', 'original_filename' => 'plik.xlsx', 'rows_total' => 2,
            'products_created' => 2, 'products_updated' => 0, 'rows_skipped' => 0, 'product_ids' => [$mfr->id, $shop->id],
        ]);

        $this->postJson("/api/price-lists/{$list->id}/enrich", ['force' => true])
            ->assertStatus(202)
            ->assertJsonPath('product_ids', [$shop->id])
            ->assertJsonPath('skipped_manufacturer', 1);

        $this->postJson("/api/price-lists/{$list->id}/enrich", ['force' => true, 'include_manufacturer' => true])
            ->assertStatus(202)
            ->assertJsonPath('skipped_manufacturer', 0);
    }

    public function test_repair_command_previews_then_restores_cancelled_cards(): void
    {
        $withDescription = $this->product('REP-DESC', [
            'enrichment_status' => Product::ENRICHMENT_FAILED,
            'enrichment_error' => 'Anulowano przez użytkownika',
            'enriched_at' => now()->subDays(3),
        ]);
        $withoutDescription = $this->product('REP-NONE', [
            'description' => null,
            'enrichment_status' => Product::ENRICHMENT_FAILED,
            'enrichment_error' => 'Zatrzymano wszystkie pobierania opisów',
        ]);
        // opis = sama nazwa z importu cennika — to nie opis z pobierania, „gotowe” zdjęłoby kartę z kolejki
        $nameOnly = $this->product('REP-NAME', [
            'name' => 'Mata przemysłowa Orthomat Ultra 0,6 x 0,9 m czarna',
            'description' => 'Mata przemysłowa Orthomat Ultra 0,6 x 0,9 m czarna',
            'enrichment_status' => Product::ENRICHMENT_FAILED,
            'enrichment_error' => 'Anulowano przez użytkownika',
            'enriched_at' => now()->subDays(3),
        ]);
        $realFailure = $this->product('REP-REAL', ['enrichment_status' => Product::ENRICHMENT_FAILED, 'enrichment_error' => 'Model nie odpowiedział']);

        $this->artisan('products:repair-cancelled-status')->assertSuccessful();
        $this->assertSame(Product::ENRICHMENT_FAILED, $withDescription->fresh()?->enrichment_status);

        $backup = storage_path('app/repair-backups/test-repair-cancelled-'.uniqid().'.json');
        $this->artisan('products:repair-cancelled-status', ['--apply' => true, '--backup' => $backup])->assertSuccessful();

        $this->assertSame(Product::ENRICHMENT_DONE, $withDescription->fresh()?->enrichment_status);
        $this->assertNull($withDescription->fresh()?->enrichment_error);
        $this->assertSame(Product::ENRICHMENT_NONE, $withoutDescription->fresh()?->enrichment_status);
        $this->assertSame(Product::ENRICHMENT_NONE, $nameOnly->fresh()?->enrichment_status);
        $this->assertSame(Product::ENRICHMENT_FAILED, $realFailure->fresh()?->enrichment_status);

        $this->artisan('products:repair-cancelled-status', ['--restore' => $backup])->assertSuccessful();
        $this->assertSame(Product::ENRICHMENT_FAILED, $withDescription->fresh()?->enrichment_status);
        $this->assertSame('Anulowano przez użytkownika', $withDescription->fresh()?->enrichment_error);
        @unlink($backup);
    }

    /** @param  array<string, mixed>  $overrides */
    private function product(string $sku, array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'sku' => $sku,
            'name' => 'Rękawice testowe '.$sku,
            'manufacturer' => 'Testowy',
            'description' => self::DESCRIPTION,
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ], $overrides));
    }

    private function batch(): ProductEnrichmentBatch
    {
        return ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCTS,
            'scope_id' => $this->admin->id,
            'total' => 1,
            'done' => 0,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_QUEUED,
            'created_by' => $this->admin->id,
            'force' => true,
        ]);
    }
}
