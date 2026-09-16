<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PrefetchProductSourcesJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\User;
use App\Services\B2b\B2bDescriptionSource;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Opis z cennika B2B a uzupełnianie AI (decyzja użytkownika 15.09.2026): lista i karta pokazują „Z B2B”, zbiorcze AI
 * pomija takie karty (także z force), pojedyncze wymaga potwierdzenia nadpisania.
 */
final class B2bDescriptionEnrichmentGuardTest extends TestCase
{
    use RefreshDatabase;

    private const B2B_TEXT = 'Stacja czyszcząca do okularów i gogli. Zawiera chusteczki, płyn i pompkę dozującą.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
    }

    public function test_list_and_detail_mark_only_unchanged_b2b_description(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $fromB2b = $this->b2bProduct('UVEX-1');
        $edited = $this->b2bProduct('UVEX-2');
        $edited->update(['description' => 'Opis poprawiony ręcznie przez handlowca w katalogu produktów.']);
        $plain = $this->product('PLAIN-1', 'Opis z cennika z pliku, bez powiązania z kontem B2B dostawcy.');

        $rows = collect($this->getJson('/api/products?per_page=50')->assertOk()->json('data'))->keyBy('sku');

        $this->assertTrue($rows['UVEX-1']['description_from_b2b']);
        $this->assertFalse($rows['UVEX-2']['description_from_b2b']);
        $this->assertFalse($rows['PLAIN-1']['description_from_b2b']);
        $this->getJson("/api/products/{$fromB2b->id}")->assertOk()->assertJsonPath('description_from_b2b', true);
        $this->getJson("/api/products/{$plain->id}")->assertOk()->assertJsonPath('description_from_b2b', false);
    }

    public function test_bulk_enrichment_skips_b2b_description_even_with_force(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $fromB2b = $this->b2bProduct('UVEX-1');
        $other = $this->product('OTHER-1', null);

        $this->postJson('/api/products/enrich', ['product_ids' => [$fromB2b->id, $other->id], 'force' => true])
            ->assertStatus(202)
            ->assertJsonPath('product_ids', [$other->id])
            ->assertJsonPath('skipped_b2b', 1)
            ->assertJsonPath('batch.total', 1);

        Queue::assertPushed(PrefetchProductSourcesJob::class, 1);
        $this->assertSame(Product::ENRICHMENT_NONE, $fromB2b->fresh()?->enrichment_status);
        $this->assertSame(self::B2B_TEXT, $fromB2b->fresh()?->description);
    }

    public function test_bulk_enrichment_of_only_b2b_cards_explains_why_nothing_was_queued(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $fromB2b = $this->b2bProduct('UVEX-1');

        $this->postJson('/api/products/enrich', ['product_ids' => [$fromB2b->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Brak produktów do wzbogacenia — 1 kart ma opis z cennika B2B (ze sklepu dostawcy), którego AI nie nadpisuje. '
                .'Pojedynczą kartę można nadpisać przyciskiem „Pobierz” po potwierdzeniu.');

        // reindeks wyszukiwania z hooków modelu przy tworzeniu karty w teście — nie jest uzupełnianiem AI
        Queue::assertNotPushed(PrefetchProductSourcesJob::class);
        $this->assertSame(Product::ENRICHMENT_NONE, $fromB2b->fresh()?->enrichment_status);
    }

    public function test_single_enrichment_of_b2b_card_requires_confirmation(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $fromB2b = $this->b2bProduct('UVEX-1');

        $this->postJson("/api/products/{$fromB2b->id}/enrich")
            ->assertStatus(422)
            ->assertJsonPath('message', B2bDescriptionSource::OVERWRITE_MESSAGE);

        $this->assertSame(Product::ENRICHMENT_NONE, $fromB2b->fresh()?->enrichment_status);
        $this->assertSame(self::B2B_TEXT, $fromB2b->fresh()?->description);
    }

    public function test_guard_passes_after_confirmation_or_for_cards_without_b2b_description(): void
    {
        $guard = app(B2bDescriptionSource::class);
        $fromB2b = $this->b2bProduct('UVEX-1');
        $plain = $this->product('PLAIN-1', 'Opis z cennika z pliku, bez powiązania z kontem B2B dostawcy.');

        $guard->assertMayOverwrite($fromB2b, true);
        $guard->assertMayOverwrite($plain, false);
        $this->assertSame([(int) $fromB2b->id => true], $guard->productIds([(int) $fromB2b->id, (int) $plain->id]));

        $this->expectException(RuntimeException::class);
        $guard->assertMayOverwrite($fromB2b, false);
    }

    public function test_catalog_health_counts_b2b_description_as_ready(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $fromB2b = $this->b2bProduct('UVEX-1');
        $pending = $this->product('OTHER-1', null);

        $this->getJson('/api/products/catalog-health')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('with_description', 1)
            ->assertJsonPath('from_b2b', 1)
            // karta z B2B nie czeka na AI — do kolejki „nie wzbogacone” idzie tylko OTHER-1
            ->assertJsonPath('not_enriched', 1)
            ->assertJsonPath('queue_candidates.not_enriched', 1)
            ->assertJsonPath('sample_ids.not_enriched', [$pending->id]);

        $this->postJson('/api/products/catalog-health/queue', ['reason' => 'not_enriched'])
            ->assertStatus(202)
            ->assertJsonPath('queued', 1)
            ->assertJsonPath('batch.total', 1);

        $this->assertSame(Product::ENRICHMENT_NONE, $fromB2b->fresh()?->enrichment_status);
        $this->assertSame(Product::ENRICHMENT_QUEUED, $pending->fresh()?->enrichment_status);
    }

    private function product(string $sku, ?string $description): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Pojemnik testowy '.$sku,
            'manufacturer' => 'UVEX',
            'catalog_price_net' => 10,
            'purchase_price' => 10,
            'stock' => 0,
            'description' => $description,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);
    }

    private function b2bProduct(string $sku): Product
    {
        $product = $this->product($sku, self::B2B_TEXT);
        $account = B2bAccount::query()->firstOrCreate(
            ['username' => 'jan'],
            ['contractor_code' => 'K123', 'password' => 'haslo', 'sites' => ['izam.system-b2b.pl'], 'connector' => 'uvex'],
        );
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id,
            'product_id' => $product->id,
            'remote_id' => $sku,
            'remote_sku' => $sku,
            'description_hash' => sha1(self::B2B_TEXT),
            'last_seen_at' => now(),
        ]);

        return $product;
    }
}
