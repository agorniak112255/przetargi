<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PrefetchProductSourcesJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductSourcePrice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /price-lists/{id}/enrich z filtrami kart i podglądem (Cenniki → „Z pliku” → „Pobierz opisy ponownie”).
 */
final class PriceListEnrichFiltersTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTION = 'Rękawice robocze powlekane nitrylem, mankiet ściągacz, norma EN 388.';

    private User $admin;

    private PriceList $list;

    /** @var array<string, Product> */
    private array $cards = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['ai.enrichment_batch_limit' => 50]);
        $this->admin = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($this->admin);
        Queue::fake();

        $changedAt = Carbon::parse('2026-10-05 12:00:00');
        $this->list = PriceList::query()->create([
            'manufacturer' => 'Testowy',
            'version' => '2026',
            'original_filename' => 'plik.xlsx',
            'rows_total' => 6,
            'products_created' => 6,
            'products_updated' => 0,
            'rows_skipped' => 0,
            'product_ids' => [],
            'enrichment_sites' => ['sklep-a.pl'],
            'enrichment_sites_updated_at' => $changedAt,
        ]);
        $before = $changedAt->copy()->subDay();
        $after = $changedAt->copy()->addHour();
        $done = Product::ENRICHMENT_DONE;
        $this->card('SITE', ['enriched_at' => $before, 'enrichment_status' => $done], 'https://sklep-a.pl/site', 'shop');
        $this->card('MFR', ['enriched_at' => $before, 'enrichment_status' => $done], 'https://producent.pl/mfr', 'manufacturer');
        $this->card('OLD', ['enriched_at' => $before, 'enrichment_status' => $done], 'https://inny.pl/old', 'shop');
        $this->card('NEW', ['enriched_at' => $after, 'enrichment_status' => $done], 'https://inny.pl/new', 'shop');
        $this->card('NONE', ['description' => null]);
        $b2b = $this->card('B2B', ['description' => 'Opis ze sklepu dostawcy B2B, dzianina nylonowa 13.']);
        $account = B2bAccount::query()->create([
            'username' => 'jan', 'password' => 'sekret', 'sites' => ['b2b.anro.net.pl'], 'connector' => 'anro',
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ]);
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id, 'remote_id' => 'B2B', 'product_id' => $b2b->id, 'remote_sku' => 'B2B',
            'remote_name' => 'Rękawice', 'description_hash' => sha1((string) $b2b->description),
        ]);
    }

    public function test_preview_counts_filtered_cards_without_queueing_anything(): void
    {
        $this->postJson("/api/price-lists/{$this->list->id}/enrich", [
            'force' => true,
            'only_not_from_sites' => true,
            'enriched_before' => '2026-10-05T12:00:00+00:00',
            'apply' => false,
        ])
            ->assertOk()
            // OLD, NONE, B2B — SITE ze strony cennika, MFR od producenta (domyślnie pomijany), NEW po zmianie stron
            ->assertExactJson(['preview' => true, 'matched' => 3, 'will_queue' => 2, 'skipped_b2b' => 1, 'limit' => 50]);

        $this->assertSame(0, ProductEnrichmentBatch::query()->count());
        $this->assertSame(Product::ENRICHMENT_DONE, $this->cards['OLD']->fresh()->enrichment_status);
        Queue::assertNothingPushed();
    }

    public function test_preview_without_force_skips_done_cards_and_respects_batch_limit(): void
    {
        $this->postJson("/api/price-lists/{$this->list->id}/enrich", ['only_not_from_sites' => true, 'apply' => false])
            ->assertOk()
            // matched: MFR odpada (skip_manufacturer domyślnie), SITE odpada; z OLD, NEW, NONE, B2B bez force zostają NONE i B2B
            ->assertExactJson(['preview' => true, 'matched' => 4, 'will_queue' => 1, 'skipped_b2b' => 1, 'limit' => 50]);

        $this->postJson("/api/price-lists/{$this->list->id}/enrich", [
            'force' => true,
            'only_not_from_sites' => true,
            'skip_manufacturer' => false,
            'apply' => false,
        ])
            ->assertOk()
            ->assertJsonPath('matched', 5)
            ->assertJsonPath('will_queue', 4);
    }

    /** Karta tylko z product_ids (wpis wspólny z kontem B2B — Bolle) nie ma slotu pliku, więc stron cennika nie dostaje. */
    public function test_filters_take_only_cards_with_file_slot_not_last_import_ids(): void
    {
        $accountCard = Product::query()->create([
            'sku' => 'KONTO', 'name' => 'Rękawice z konta B2B', 'manufacturer' => 'Testowy', 'description' => self::DESCRIPTION,
            'catalog_price_net' => 10, 'purchase_price' => 8, 'stock' => 0,
        ]);
        $this->list->forceFill(['product_ids' => [$accountCard->id]])->save();

        $this->postJson("/api/price-lists/{$this->list->id}/enrich", ['force' => true, 'skip_manufacturer' => false, 'apply' => false])
            ->assertOk()
            ->assertJsonPath('matched', 6);
    }

    /** Opis AI z karty katalogowej B2B (b2b_datasheet) to też opis z B2B — ponowne pobranie go nie zastępuje. */
    public function test_ai_description_from_b2b_datasheet_is_skipped_like_b2b_description(): void
    {
        $this->card('DATASHEET', ['enriched_at' => Carbon::parse('2026-10-01'), 'enrichment_status' => Product::ENRICHMENT_DONE], 'https://b2b.example/karta.pdf', 'b2b_datasheet');

        $this->postJson("/api/price-lists/{$this->list->id}/enrich", ['force' => true, 'skip_manufacturer' => false, 'apply' => false])
            ->assertOk()
            ->assertExactJson(['preview' => true, 'matched' => 7, 'will_queue' => 5, 'skipped_b2b' => 2, 'limit' => 50]);

        $this->postJson("/api/price-lists/{$this->list->id}/enrich", ['force' => true, 'skip_manufacturer' => false])
            ->assertStatus(202)
            ->assertJsonPath('skipped_b2b', 2)
            ->assertJsonPath('batch.total', 5);
        $this->assertNotSame(Product::ENRICHMENT_QUEUED, $this->cards['DATASHEET']->fresh()->enrichment_status);
    }

    /**
     * skip_manufacturer pomija też ręczny link do strony producenta i opis sprzed pola primary_source_kind z adresem
     * producenta na początku source_urls (PriceListDescriptionSources liczy je od producenta); ręczny link do obcego
     * sklepu zostaje do ponownego pobrania.
     */
    public function test_skip_manufacturer_also_skips_manual_link_and_legacy_description_from_manufacturer_domain(): void
    {
        config(['enrichment.manufacturer_domains' => ['testowy' => ['producent.pl']]]);
        $done = ['enriched_at' => Carbon::parse('2026-10-01'), 'enrichment_status' => Product::ENRICHMENT_DONE];
        $this->card('MANUAL', $done, 'https://www.producent.pl/manual', 'manual');
        $this->card('LEGACY', [...$done, 'enrichment_payload' => ['source_urls' => ['https://producent.pl/legacy', 'https://inny.pl/legacy']]]);
        $this->card('MANUAL-SHOP', $done, 'https://obcy-sklep.pl/manual', 'manual');

        $this->postJson("/api/price-lists/{$this->list->id}/enrich", ['force' => true, 'skip_manufacturer' => false, 'apply' => false])
            ->assertOk()
            ->assertJsonPath('matched', 9);

        $response = $this->postJson("/api/price-lists/{$this->list->id}/enrich", ['force' => true, 'skip_manufacturer' => true])
            ->assertStatus(202);
        // MFR, MANUAL i LEGACY od producenta pomijane, B2B zostaje opisem z B2B
        $this->assertEqualsCanonicalizing(
            ['SITE', 'OLD', 'NEW', 'NONE', 'MANUAL-SHOP'],
            Product::query()->whereIn('id', $response->json('product_ids'))->pluck('sku')->all(),
        );
        $this->assertSame(Product::ENRICHMENT_DONE, $this->cards['MANUAL']->fresh()->enrichment_status);
        $this->assertSame(Product::ENRICHMENT_DONE, $this->cards['LEGACY']->fresh()->enrichment_status);
    }

    public function test_batch_limit_caps_will_queue(): void
    {
        config(['ai.enrichment_batch_limit' => 2]);

        $this->postJson("/api/price-lists/{$this->list->id}/enrich", ['force' => true, 'skip_manufacturer' => false, 'apply' => false])
            ->assertOk()
            ->assertExactJson(['preview' => true, 'matched' => 6, 'will_queue' => 2, 'skipped_b2b' => 1, 'limit' => 2]);
    }

    public function test_apply_is_default_and_queues_only_filtered_cards_in_price_list_batch(): void
    {
        $response = $this->postJson("/api/price-lists/{$this->list->id}/enrich", [
            'force' => true,
            'only_not_from_sites' => true,
            'enriched_before' => '2026-10-05 12:00:00',
        ])->assertStatus(202);

        $response->assertJsonPath('price_list_id', $this->list->id)
            ->assertJsonPath('skipped_b2b', 1)
            ->assertJsonPath('batch.scope', ProductEnrichmentBatch::SCOPE_PRICE_LIST)
            ->assertJsonPath('batch.scope_id', $this->list->id)
            ->assertJsonPath('batch.total', 2);
        $this->assertEqualsCanonicalizing(
            [$this->cards['OLD']->id, $this->cards['NONE']->id],
            $response->json('product_ids'),
        );
        $this->assertSame(Product::ENRICHMENT_QUEUED, $this->cards['OLD']->fresh()->enrichment_status);
        $this->assertSame(Product::ENRICHMENT_DONE, $this->cards['SITE']->fresh()->enrichment_status);
        $this->assertSame(Product::ENRICHMENT_DONE, $this->cards['MFR']->fresh()->enrichment_status);
        $this->assertSame(Product::ENRICHMENT_DONE, $this->cards['NEW']->fresh()->enrichment_status);
        Queue::assertPushed(PrefetchProductSourcesJob::class, 2);
    }

    public function test_no_matching_cards_is_rejected(): void
    {
        $this->postJson("/api/price-lists/{$this->list->id}/enrich", [
            'force' => true,
            'only_not_from_sites' => true,
            'enriched_before' => '2020-01-01',
        ])
            // NONE i B2B nie mają daty opisu, więc zostają; B2B pomija kolejka — w kolejce tylko NONE
            ->assertStatus(202)
            ->assertJsonPath('product_ids', [$this->cards['NONE']->id]);

        $this->cards['NONE']->forceFill(['enriched_at' => Carbon::parse('2026-10-01'), 'enrichment_status' => Product::ENRICHMENT_DONE])->save();
        $this->cards['B2B']->forceFill(['enriched_at' => Carbon::parse('2026-10-01')])->save();
        $this->postJson("/api/price-lists/{$this->list->id}/enrich", [
            'force' => true,
            'only_not_from_sites' => true,
            'enriched_before' => '2020-01-01',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Żadna karta cennika nie spełnia wybranych warunków.');
    }

    public function test_without_new_fields_behaves_as_before(): void
    {
        $response = $this->postJson("/api/price-lists/{$this->list->id}/enrich")->assertStatus(202);

        $this->assertSame(['batch', 'product_ids', 'skipped_b2b', 'price_list_id'], array_keys($response->json()));
        // bez force: karty gotowe odpadają, karta z opisem z B2B jest pomijana
        $response->assertJsonPath('product_ids', [$this->cards['NONE']->id])->assertJsonPath('skipped_b2b', 1);

        // Od 07.10.2026 ponowne pobranie (force) pomija kartę z gotowym opisem od producenta (MFR) — wszystkie 5 kart
        // tylko z include_manufacturer (przycisk „Pobierz też te karty”)
        $this->postJson("/api/price-lists/{$this->list->id}/enrich", ['apply' => true, 'force' => true])
            ->assertStatus(202)
            ->assertJsonPath('batch.total', 4)
            ->assertJsonPath('skipped_manufacturer_ids', [$this->cards['MFR']->id]);
        $this->postJson("/api/price-lists/{$this->list->id}/enrich", ['apply' => true, 'force' => true, 'include_manufacturer' => true])
            ->assertStatus(202)
            ->assertJsonPath('batch.total', 5);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->postJson("/api/price-lists/{$this->list->id}/enrich", ['enriched_before' => 'wczoraj-ish'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('enriched_before');
        $this->postJson("/api/price-lists/{$this->list->id}/enrich", ['apply' => 'tak'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('apply');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function card(string $sku, array $attributes = [], ?string $sourceUrl = null, ?string $sourceKind = null): Product
    {
        $card = Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice testowe '.$sku,
            'manufacturer' => 'Testowy',
            'description' => self::DESCRIPTION,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 0,
            'enrichment_status' => Product::ENRICHMENT_NONE,
            'enrichment_payload' => $sourceUrl !== null
                ? ['primary_source_url' => $sourceUrl, 'primary_source_kind' => $sourceKind]
                : null,
            ...$attributes,
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id,
            'source_key' => ProductSourcePrice::SOURCE_FILE,
            'price_list_id' => $this->list->id,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'currency' => 'PLN',
            'checked_at' => now(),
        ]);

        return $this->cards[$sku] = $card;
    }
}
