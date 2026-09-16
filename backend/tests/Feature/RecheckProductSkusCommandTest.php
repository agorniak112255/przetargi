<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Po poprawce w doborze źródeł trzeba przejść jeszcze raz dokładnie tę listę kodów, którą
 * sprawdzał człowiek. Polecenie kasuje opis wskazanych kart (z kopią zapasową) i zleca
 * pobranie go od nowa — z opcją przywrócenia stanu sprzed, gdyby wynik był gorszy.
 */
final class RecheckProductSkusCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->withRole('admin')->create();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
            'enrichment_batch_limit' => 2,
        ]);
        $this->card('S56212-50', 'Pierścień z zaczepami zaworu wydechowego do półmaski SECURA 3000');
        $this->card('S5621300', 'Nagłowie tekstylne kompletne do półmaski SECURA 3100');
        $this->card('34650008', 'BUTOFLEX 650');
    }

    public function test_preview_lists_cards_and_changes_nothing(): void
    {
        $this->artisan('products:recheck-skus', ['--sku' => ['S56212-50', '34650008']])
            ->expectsOutputToContain('Do ponownego wzbogacenia: 2 kart')
            ->expectsOutputToContain('Podgląd')
            ->assertSuccessful();

        $this->assertSame(0, ProductEnrichmentBatch::query()->count());
        $this->assertNotNull(Product::query()->where('sku', 'S56212-50')->value('description'));
    }

    public function test_apply_clears_descriptions_and_queues_batches(): void
    {
        $backup = storage_path('app/repair-backups/test-recheck-batches.json');
        @unlink($backup);
        $this->artisan('products:recheck-skus', [
            '--sku' => ['S56212-50', 'S5621300', '34650008'],
            '--backup' => $backup,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Wyczyszczono 3 kart')
            ->expectsOutputToContain('Zlecono pobranie opisu: 3 kart w 2 partiach')
            ->assertSuccessful();

        $cleared = Product::query()->whereIn('sku', ['S56212-50', 'S5621300', '34650008'])->get();
        $this->assertSame([null, null, null], $cleared->pluck('description')->all());
        $this->assertSame([2, 1], ProductEnrichmentBatch::query()->orderBy('id')->pluck('total')
            ->map(static fn ($n): int => (int) $n)->all());
        @unlink($backup);
    }

    public function test_backup_restores_the_previous_state(): void
    {
        $backup = storage_path('app/repair-backups/test-recheck.json');
        @unlink($backup);

        $this->artisan('products:recheck-skus', [
            '--sku' => ['S56212-50'],
            '--backup' => $backup,
            '--apply' => true,
        ])->assertSuccessful();
        $this->assertNull(Product::query()->where('sku', 'S56212-50')->value('description'));

        $this->artisan('products:recheck-skus', ['--restore' => $backup])
            ->expectsOutputToContain('Przywrócono 1 kart')
            ->assertSuccessful();
        $this->assertSame(
            'Opis z obcej karty.',
            Product::query()->where('sku', 'S56212-50')->value('description')
        );
        @unlink($backup);
    }

    public function test_unknown_code_is_reported_and_does_not_stop_the_rest(): void
    {
        $this->artisan('products:recheck-skus', ['--sku' => ['S56212-50', 'NIE-MA-TAKIEGO']])
            ->expectsOutputToContain('Nie ma w katalogu: NIE-MA-TAKIEGO')
            ->expectsOutputToContain('Do ponownego wzbogacenia: 1 kart')
            ->assertSuccessful();
    }

    public function test_codes_can_come_from_a_file(): void
    {
        $file = storage_path('app/repair-backups/test-skus.txt');
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, "# lista z arkusza\nS56212-50, S5621300\n\n34650008   # BUTOFLEX 650 (-)\n");

        $this->artisan('products:recheck-skus', ['--file' => $file])
            ->expectsOutputToContain('Do ponownego wzbogacenia: 3 kart')
            ->assertSuccessful();
        @unlink($file);
    }

    /** Kod bywa ze spacją („SB04 AIR”) — wiersza nie wolno dzielić po spacjach. */
    public function test_code_with_a_space_survives_the_file(): void
    {
        $this->card('SB04 AIR', 'Spodniobuty oddychające AIR');
        $file = storage_path('app/repair-backups/test-skus-space.txt');
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, "SB04 AIR   # spodniobuty\n");

        $this->artisan('products:recheck-skus', ['--file' => $file])
            ->expectsOutputToContain('Do ponownego wzbogacenia: 1 kart')
            ->doesntExpectOutputToContain('Nie ma w katalogu')
            ->assertSuccessful();
        @unlink($file);
    }

    public function test_without_codes_it_refuses(): void
    {
        $this->artisan('products:recheck-skus')
            ->expectsOutputToContain('Podaj kody')
            ->assertFailed();
    }

    private function card(string $sku, string $name): void
    {
        Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'SECURA',
            'category' => 'Środki ochrony indywidualnej',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
            'description' => 'Opis z obcej karty.',
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
    }
}
