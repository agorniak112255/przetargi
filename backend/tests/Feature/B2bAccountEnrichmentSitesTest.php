<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SupplementB2bDescriptionJob;
use App\Models\B2bAccount;
use App\Models\B2bDescriptionSupplementAttempt;
use App\Models\B2bProductLink;
use App\Models\CatalogSearchSite;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Strony z opisami przy koncie B2B (28.09.2026): zapis listy hostów i progu w formularzu konta, liczniki prób
 * uzupełniania i hosty spoza indeksu na liście kont, odmowa „Uzupełnij krótkie opisy” dla konta bez stron.
 */
final class B2bAccountEnrichmentSitesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_patch_normalizes_sites_to_unique_hosts_and_saves_threshold(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $account = $this->account();

        $this->patchJson("/api/b2b-accounts/{$account->id}", [
            ...$this->form(),
            'enrichment_sites' => [
                'https://www.Sklep-BHP.pl/produkty/rekawice?x=1',
                ' sklep-bhp.pl ',
                '',
                null,
                'http://katalog.producent.com.pl/',
            ],
            'enrichment_min_chars' => 1200,
        ])
            ->assertOk()
            ->assertJsonPath('enrichment_sites', ['sklep-bhp.pl', 'katalog.producent.com.pl'])
            ->assertJsonPath('enrichment_min_chars', 1200)
            ->assertJsonPath('enrichment_min_chars_effective', 1200);

        $fresh = $account->fresh();
        $this->assertSame(['sklep-bhp.pl', 'katalog.producent.com.pl'], $fresh->enrichment_sites);
        $this->assertSame(1200, $fresh->enrichment_min_chars);
    }

    public function test_empty_list_and_threshold_clear_to_null_and_default_threshold_applies(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $account = $this->account(['enrichment_sites' => ['sklep-bhp.pl'], 'enrichment_min_chars' => 800]);

        $this->patchJson("/api/b2b-accounts/{$account->id}", [
            ...$this->form(),
            'enrichment_sites' => ['', '  '],
            'enrichment_min_chars' => null,
        ])
            ->assertOk()
            ->assertJsonPath('enrichment_sites', [])
            ->assertJsonPath('enrichment_min_chars', null)
            ->assertJsonPath('enrichment_min_chars_effective', 1000);

        $this->assertNull(DB::table('b2b_accounts')->where('id', $account->id)->value('enrichment_sites'));
        $this->assertNull($account->fresh()->enrichment_min_chars);
    }

    public function test_patch_without_enrichment_keys_keeps_saved_sites_and_threshold(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $account = $this->account(['enrichment_sites' => ['sklep-bhp.pl'], 'enrichment_min_chars' => 800]);

        $this->patchJson("/api/b2b-accounts/{$account->id}", $this->form())
            ->assertOk()
            ->assertJsonPath('enrichment_sites', ['sklep-bhp.pl'])
            ->assertJsonPath('enrichment_min_chars', 800);
    }

    public function test_invalid_host_threshold_out_of_range_and_too_many_sites_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $account = $this->account(['enrichment_sites' => ['sklep-bhp.pl']]);

        $this->patchJson("/api/b2b-accounts/{$account->id}", [...$this->form(), 'enrichment_sites' => ['sklep-bhp.pl', 'to nie jest strona']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrichment_sites']);
        $this->patchJson("/api/b2b-accounts/{$account->id}", [...$this->form(), 'enrichment_sites' => ['localhost']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrichment_sites']);
        $this->patchJson("/api/b2b-accounts/{$account->id}", [...$this->form(), 'enrichment_min_chars' => 199])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrichment_min_chars']);
        $this->patchJson("/api/b2b-accounts/{$account->id}", [...$this->form(), 'enrichment_min_chars' => 5001])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrichment_min_chars']);
        $this->patchJson("/api/b2b-accounts/{$account->id}", [
            ...$this->form(),
            'enrichment_sites' => array_map(static fn (int $i): string => "sklep{$i}.pl", range(1, 21)),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrichment_sites']);

        // odrzucony formularz niczego nie zapisał
        $this->assertSame(['sklep-bhp.pl'], $account->fresh()->enrichment_sites);
    }

    public function test_store_saves_enrichment_sites(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->postJson('/api/b2b-accounts', [
            ...$this->form(),
            'password' => 'sekret',
            'enrichment_sites' => ['www.sklep-bhp.pl'],
            'enrichment_min_chars' => 600,
        ])
            ->assertCreated()
            ->assertJsonPath('enrichment_sites', ['sklep-bhp.pl'])
            ->assertJsonPath('enrichment_min_chars', 600)
            ->assertJsonPath('supplement_stats', $this->stats())
            ->assertJsonPath('enrichment_hosts_not_indexed', ['sklep-bhp.pl']);
    }

    public function test_index_returns_supplement_stats_per_account_and_hosts_outside_index(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $first = $this->account(['enrichment_sites' => ['sklep-bhp.pl', 'katalog.producent.pl']]);
        $second = $this->account(['username' => 'drugie']);
        $third = $this->account(['username' => 'trzecie', 'enrichment_sites' => ['sklep-bhp.pl']]);
        CatalogSearchSite::query()->create(['host' => 'sklep-bhp.pl', 'source' => 'manual']);

        foreach (['replaced', 'replaced', 'kept_b2b', 'no_pages', 'failed', 'queued'] as $i => $status) {
            $this->attempt($first, $this->card("A-{$i}"), $status);
        }
        $this->attempt($third, $this->card('C-1'), 'no_pages');
        // status spoza listy (np. z przyszłej wersji) nie psuje liczników
        $this->attempt($third, $this->card('C-2'), 'inny');

        $this->getJson('/api/b2b-accounts')
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('0.id', $first->id)
            ->assertJsonPath('0.supplement_stats', $this->stats(['queued' => 1, 'replaced' => 2, 'kept_b2b' => 1, 'no_pages' => 1, 'failed' => 1]))
            ->assertJsonPath('0.enrichment_sites', ['sklep-bhp.pl', 'katalog.producent.pl'])
            ->assertJsonPath('0.enrichment_hosts_not_indexed', ['katalog.producent.pl'])
            ->assertJsonPath('0.enrichment_min_chars', null)
            ->assertJsonPath('0.enrichment_min_chars_effective', 1000)
            ->assertJsonPath('1.id', $second->id)
            ->assertJsonPath('1.supplement_stats', $this->stats())
            ->assertJsonPath('1.enrichment_sites', [])
            ->assertJsonPath('1.enrichment_hosts_not_indexed', [])
            ->assertJsonPath('2.supplement_stats.no_pages', 1)
            ->assertJsonPath('2.supplement_stats.queued', 0)
            ->assertJsonPath('2.enrichment_hosts_not_indexed', []);
    }

    public function test_supplement_descriptions_refuses_account_without_sites_and_needs_manage_permission(): void
    {
        $account = $this->account();

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->postJson("/api/b2b-accounts/{$account->id}/supplement-descriptions", ['apply' => false])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Konto nie ma stron z opisami — dodaj je w edycji konta („Strony z opisami”).');
        $this->postJson("/api/b2b-accounts/{$account->id}/supplement-descriptions", ['apply' => 'tak'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['apply']);

        Sanctum::actingAs(User::factory()->withRole('kierownik')->create());
        $this->postJson("/api/b2b-accounts/{$account->id}/supplement-descriptions")->assertForbidden();
    }

    public function test_supplement_descriptions_previews_then_queues_short_b2b_cards(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $account = $this->account(['connector' => 'procera', 'sites' => ['b2b.procera.pl'], 'enrichment_sites' => ['sklep-bhp.pl']]);
        $short = 'Rękawica ochronna nitrylowa, kategoria II, dostępna w rozmiarach 7–11.';
        $card = $this->card('S-1', $short);
        $this->link($account, $card, sha1($short));
        $long = str_repeat('Długi opis rękawicy z konta B2B dostawcy. ', 40);
        $this->link($account, $this->card('L-1', $long), sha1($long));

        $this->postJson("/api/b2b-accounts/{$account->id}/supplement-descriptions", ['apply' => false])
            ->assertOk()
            ->assertJsonPath('candidates', 1)
            ->assertJsonPath('queued', 0);
        Queue::assertNothingPushed();
        $this->assertSame(0, B2bDescriptionSupplementAttempt::query()->count());

        $this->postJson("/api/b2b-accounts/{$account->id}/supplement-descriptions", ['apply' => true])
            ->assertOk()
            ->assertJsonPath('candidates', 1)
            ->assertJsonPath('queued', 1);
        Queue::assertPushed(SupplementB2bDescriptionJob::class, fn (SupplementB2bDescriptionJob $job): bool => $job->productId === (int) $card->id);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_QUEUED, B2bDescriptionSupplementAttempt::query()->sole()->status);
    }

    public function test_command_previews_and_queues_with_apply(): void
    {
        Queue::fake();
        $account = $this->account(['connector' => 'procera', 'sites' => ['b2b.procera.pl'], 'enrichment_sites' => ['sklep-bhp.pl']]);
        $short = 'Rękawica ochronna nitrylowa, kategoria II, dostępna w rozmiarach 7–11.';
        $this->link($account, $this->card('S-1', $short), sha1($short));

        $this->artisan('b2b:supplement-descriptions', ['--account' => $account->id])->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('b2b:supplement-descriptions', ['--account' => $account->id, '--apply' => true])->assertSuccessful();
        Queue::assertPushed(SupplementB2bDescriptionJob::class, 1);
    }

    public function test_progress_stop_and_stop_all(): void
    {
        $account = $this->account(['enrichment_sites' => ['sklep-bhp.pl']]);
        $other = $this->account(['username' => 'drugie', 'enrichment_sites' => ['sklep-bhp.pl']]);
        $running = $this->card('R-1');
        $this->attempt($account, $running, 'running');
        B2bDescriptionSupplementAttempt::query()->where('product_id', $running->id)
            ->update(['stage' => 'model pisze opis (2 stron z internetu)', 'started_at' => now()->subSeconds(20)]);
        $waiting = $this->card('W-1');
        $this->attempt($account, $waiting, 'queued');
        B2bDescriptionSupplementAttempt::query()->where('product_id', $waiting->id)
            ->update(['retry_at' => now()->addMinutes(8), 'message' => 'Wyszukiwarka niedostępna — ponowię o 20:10']);
        $done = $this->card('D-1');
        $this->attempt($account, $done, 'replaced');
        $this->attempt($other, $this->card('O-1'), 'queued');

        Sanctum::actingAs(User::factory()->withRole('kierownik')->create());
        $this->postJson("/api/b2b-accounts/{$account->id}/supplement-stop")->assertForbidden();

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->getJson('/api/b2b-accounts')
            ->assertJsonPath('0.supplement_stats.running', 1)
            ->assertJsonPath('0.supplement_stats.waiting_search', 1);
        $this->getJson("/api/b2b-accounts/{$account->id}/supplement-progress")
            ->assertOk()
            ->assertJsonPath('counts.running', 1)
            ->assertJsonPath('counts.queued', 1)
            ->assertJsonPath('counts.replaced', 1)
            ->assertJsonPath('waiting_search', 1)
            ->assertJsonPath('running.0.sku', 'R-1')
            ->assertJsonPath('running.0.stage', 'model pisze opis (2 stron z internetu)')
            ->assertJsonCount(2, 'recent');

        $this->postJson("/api/b2b-accounts/{$account->id}/supplement-stop")
            ->assertOk()
            ->assertJsonPath('stopped', 2);
        $this->assertSame(
            ['cancelled', 'cancelled', 'replaced'],
            B2bDescriptionSupplementAttempt::query()->where('b2b_account_id', $account->id)->orderBy('product_id')->pluck('status')->all(),
        );
        // drugie konto nietknięte — dopiero „Zatrzymaj wszystko”
        $this->assertSame('queued', B2bDescriptionSupplementAttempt::query()->where('b2b_account_id', $other->id)->value('status'));
        $this->postJson('/api/b2b-accounts/supplement-stop-all')->assertOk()->assertJsonPath('stopped', 1);
        $this->assertSame('cancelled', B2bDescriptionSupplementAttempt::query()->where('b2b_account_id', $other->id)->value('status'));
    }

    /**
     * Liczniki prób przy koncie (supplement_stats) — zera poza podanymi.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int|string|null>
     */
    private function stats(array $counts = []): array
    {
        return [
            'queued' => 0, 'running' => 0, 'replaced' => 0, 'kept_b2b' => 0, 'no_pages' => 0, 'failed' => 0, 'cancelled' => 0,
            'waiting_search' => 0, 'next_retry_at' => null, ...$counts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function form(): array
    {
        return [
            'username' => 'konto',
            'password' => '',
            'sites' => ['b2b.example.test'],
            'note' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function account(array $overrides = []): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => 'konto',
            'password' => 'sekret',
            'sites' => ['b2b.example.test'],
            ...$overrides,
        ]);
    }

    private function card(string $sku, ?string $description = null): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Karta '.$sku,
            'manufacturer' => 'Producent',
            'description' => $description,
            'catalog_price_net' => 0,
            'purchase_price' => 0,
            'currency' => 'PLN',
            'stock' => 0,
        ])->refresh();
    }

    private function link(B2bAccount $account, Product $product, string $descriptionHash): void
    {
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id,
            'remote_id' => 'r-'.$product->id,
            'product_id' => $product->id,
            'remote_sku' => $product->sku,
            'description_hash' => $descriptionHash,
        ]);
    }

    private function attempt(B2bAccount $account, Product $product, string $status): void
    {
        B2bDescriptionSupplementAttempt::query()->create([
            'product_id' => $product->id,
            'b2b_account_id' => $account->id,
            'source_sha1' => sha1('b2b'),
            'hosts_sha1' => $account->enrichmentHostsSha1(),
            'status' => $status,
        ]);
    }
}
