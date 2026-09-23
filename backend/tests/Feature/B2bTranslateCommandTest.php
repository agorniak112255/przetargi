<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\TranslateB2bProductTextJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\User;
use App\Services\B2b\AnroB2bConnector;
use App\Services\B2b\BolleB2bConnector;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * b2b:translate — kandydaci jak w TranslateB2bProductTextJob (opis zapisany przez import i nietknięty,
 * nazwa nowej karty równa nazwie u dostawcy), tylko łącznik z obcojęzycznym źródłem.
 */
final class B2bTranslateCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private B2bAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
        $this->account = $this->account(BolleB2bConnector::class);

        // opis ze źródła + nazwa ze źródła
        $this->card('BOL-1', 'Copper safety glasses', 'Copper lens.', remoteName: 'Copper safety glasses');
        // opis ze źródła, nazwa istniejącej karty po polsku
        $this->card('BOL-2', 'Okulary ochronne Rush', 'Clear lens.', remoteName: 'RUSH – Clear safety glasses');
        // opis edytowany ręcznie, nazwa ze źródła
        $this->card('BOL-3', 'Goggles', 'Opis poprawiony ręcznie', remoteName: 'Goggles', hashOf: 'Indirect vent goggles.');
        // opis edytowany ręcznie, nazwa po polsku — pomijana
        $this->card('BOL-4', 'Gogle', 'Opis poprawiony ręcznie', remoteName: 'Goggles 4', hashOf: 'Goggles.');
        // już przetłumaczona — pomijana
        $this->card('BOL-5', 'Okulary Tracker', 'Soczewka bezbarwna.', remoteName: 'Okulary Tracker', translated: true);
        // przed remote_name — tylko opis
        $this->card('BOL-6', 'Silium+ glasses', 'Smoke lens.', remoteName: null);
    }

    public function test_dry_run_counts_candidates_and_dispatches_nothing(): void
    {
        $this->artisan('b2b:translate', ['account' => $this->account->id, '--dry-run' => true])
            ->expectsOutputToContain('do tłumaczenia: 4 kart (opis: 3, nazwa: 2)')
            ->expectsOutputToContain('BOL-1 · opis · nazwa')
            ->expectsOutputToContain('BOL-3 · nazwa')
            ->doesntExpectOutputToContain('BOL-4')
            ->doesntExpectOutputToContain('BOL-5')
            ->assertSuccessful();

        Queue::assertNotPushed(TranslateB2bProductTextJob::class);
    }

    public function test_dispatches_jobs_with_translate_name_flag(): void
    {
        $this->artisan('b2b:translate', ['account' => $this->account->id])
            ->expectsOutputToContain('Zlecono tłumaczenie 4 kart')
            ->assertSuccessful();

        Queue::assertPushed(TranslateB2bProductTextJob::class, 4);
        $flags = [];
        Queue::pushed(TranslateB2bProductTextJob::class)->each(function (TranslateB2bProductTextJob $job) use (&$flags): void {
            $this->assertSame($this->account->id, $job->b2bAccountId);
            $flags[Product::query()->findOrFail($job->productId)->sku] = $job->translateName;
        });
        ksort($flags);
        $this->assertSame(['BOL-1' => true, 'BOL-2' => false, 'BOL-3' => true, 'BOL-6' => false], $flags);
        Queue::assertPushedOn(TranslateB2bProductTextJob::QUEUE, TranslateB2bProductTextJob::class);
    }

    public function test_rejected_card_is_not_dispatched_and_is_listed_for_manual_translation(): void
    {
        $product = Product::query()->where('sku', 'BOL-6')->firstOrFail();
        $link = B2bProductLink::query()->where('product_id', $product->id)->firstOrFail();
        $link->update([
            'translation_rejected_hash' => TranslateB2bProductTextJob::rejectionKey($product, ['description' => true, 'name' => false]),
            'translation_rejected_reason' => 'segment 2: inna liczba linii — w źródle 4, w tłumaczeniu 2',
            'translation_rejected_at' => now(),
        ]);

        $this->artisan('b2b:translate', ['account' => $this->account->id, '--dry-run' => true])
            ->expectsOutputToContain('do tłumaczenia: 3 kart')
            ->doesntExpectOutputToContain('BOL-6')
            ->assertSuccessful();

        $this->assertSame(0, Artisan::call('b2b:translate', ['account' => $this->account->id, '--rejected' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('odrzucone tłumaczenia: 1 kart', $output);
        $this->assertMatchesRegularExpression(
            '/BOL-6 \(#'.$product->id.'\) · \d{4}-\d{2}-\d{2} \d{2}:\d{2} · segment 2: inna liczba linii/u',
            $output,
        );
        Queue::assertNotPushed(TranslateB2bProductTextJob::class);
    }

    public function test_redo_identical_requeues_cards_whose_translation_equals_the_source(): void
    {
        // HUSTLN50E: model oddał angielski tekst bez zmian, a karta została oznaczona jako przetłumaczona
        $text = 'Experience unmatched protection and comfort with HUSTLER.';
        $product = Product::query()->create([
            'sku' => 'HUSTLN50E', 'name' => 'Okulary Hustler', 'description' => $text, 'manufacturer' => 'Bollé Safety',
            'catalog_price_net' => 60.00, 'purchase_price' => 50.00, 'discount_percent' => 0, 'currency' => 'PLN',
        ]);
        B2bProductLink::query()->create([
            'b2b_account_id' => $this->account->id, 'remote_id' => 'HUSTLN50E', 'product_id' => $product->id,
            'remote_sku' => 'HUSTLN50E', 'remote_name' => 'HUSTLER – Safety glasses',
            'description_hash' => sha1($text), 'source_description_hash' => sha1($text), 'last_seen_at' => now(),
        ]);

        $this->artisan('b2b:translate', ['account' => $this->account->id, '--redo-identical' => true, '--dry-run' => true])
            ->expectsOutputToContain('identyczne ze źródłem: 1 kart')
            ->expectsOutputToContain('HUSTLN50E')
            // BOL-5 ma prawdziwe tłumaczenie (inny odcisk źródła) — nie jest ruszana
            ->doesntExpectOutputToContain('BOL-5')
            ->assertSuccessful();
        Queue::assertNotPushed(TranslateB2bProductTextJob::class);
        $this->assertNotNull(B2bProductLink::query()->where('product_id', $product->id)->value('source_description_hash'));

        $this->artisan('b2b:translate', ['account' => $this->account->id, '--redo-identical' => true])
            ->expectsOutputToContain('Zlecono ponowne tłumaczenie 1 kart')
            ->assertSuccessful();
        $this->assertNull(B2bProductLink::query()->where('product_id', $product->id)->value('source_description_hash'));
        Queue::assertPushed(TranslateB2bProductTextJob::class, fn (TranslateB2bProductTextJob $job): bool => $job->productId === $product->id);
        Queue::assertPushed(TranslateB2bProductTextJob::class, 1);
    }

    public function test_limit_caps_dispatched_jobs(): void
    {
        $this->artisan('b2b:translate', ['account' => $this->account->id, '--limit' => 2])
            ->expectsOutputToContain('Zlecono tłumaczenie 2 kart')
            ->assertSuccessful();

        Queue::assertPushed(TranslateB2bProductTextJob::class, 2);
    }

    public function test_connector_without_foreign_language_source_fails(): void
    {
        $anro = $this->account(AnroB2bConnector::class);

        $this->artisan('b2b:translate', ['account' => $anro->id])
            ->expectsOutputToContain('nie ma czego tłumaczyć')
            ->assertFailed();

        Queue::assertNotPushed(TranslateB2bProductTextJob::class);
    }

    public function test_missing_account_fails(): void
    {
        $this->artisan('b2b:translate', ['account' => 999])->assertFailed();
    }

    /**
     * @param  class-string<AnroB2bConnector|BolleB2bConnector>  $connector
     */
    private function account(string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => 'jan',
            'password' => 'sekret',
            'sites' => [$connector::host()],
            'connector' => $connector::key(),
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    private function card(
        string $sku,
        string $name,
        string $description,
        ?string $remoteName,
        ?string $hashOf = null,
        bool $translated = false,
    ): void {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'description' => $description,
            'manufacturer' => 'Bollé Safety',
            'catalog_price_net' => 60.00,
            'purchase_price' => 50.00,
            'discount_percent' => 0,
            'currency' => 'PLN',
        ]);
        B2bProductLink::query()->create([
            'b2b_account_id' => $this->account->id,
            'remote_id' => $sku,
            'product_id' => $product->id,
            'remote_sku' => $sku,
            'remote_name' => $remoteName,
            'description_hash' => sha1($hashOf ?? $description),
            'source_description_hash' => $translated ? sha1('Clear lens source.') : null,
            'last_seen_at' => now(),
        ]);
    }
}
