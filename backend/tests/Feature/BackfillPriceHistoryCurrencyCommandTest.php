<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BackfillPriceHistoryCurrencyCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $backup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->backup = sys_get_temp_dir().'/price-history-currency-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->backup);
        parent::tearDown();
    }

    public function test_fills_currency_from_slot_of_the_same_source_and_leaves_unknown_empty(): void
    {
        // karta Bolle: cennik z pliku w EUR, konto producenta w EUR, dystrybutor w PLN
        $bolle = $this->account('bolle');
        $procera = $this->account('procera');
        $card = $this->card('BOL-1');
        $this->slot($card, ProductSourcePrice::SOURCE_FILE, 'EUR');
        $this->slot($card, ProductSourcePrice::b2bKey($bolle->id), 'eur', $bolle->id);
        $this->slot($card, ProductSourcePrice::b2bKey($procera->id), 'PLN', $procera->id);
        $file = $this->row($card, 'price_list_import');
        $discount = $this->row($card, 'price_list_discount');
        $fromBolle = $this->row($card, 'b2b:bolle', $this->syncRun($bolle));
        $fromProcera = $this->row($card, 'b2b:procera', $this->syncRun($procera));
        $legacy = $this->row($card, 'b2b_api');
        $filled = $this->row($card, 'b2b:procera', $this->syncRun($procera), 'USD');

        // karta z jednym źródłem: dawne i puste źródło dostają jego walutę
        $single = $this->card('ONE-1');
        $this->slot($single, ProductSourcePrice::b2bKey($procera->id), 'PLN', $procera->id);
        $singleLegacy = $this->row($single, 'b2b_api');
        $singleEmpty = $this->row($single, '');

        // „b2b:{łącznik}” bez przebiegu — sloty kont tego łącznika
        $anro = $this->account('anro');
        $noRun = $this->card('ANRO-1');
        $this->slot($noRun, ProductSourcePrice::b2bKey($anro->id), 'EUR', $anro->id);
        $this->slot($noRun, ProductSourcePrice::SOURCE_FILE, 'PLN');
        $anroRow = $this->row($noRun, 'b2b:anro');

        // karta bez slotów i wiersz z pliku bez slotu pliku — waluta nieznana
        $bare = $this->card('BARE-1');
        $bareRow = $this->row($bare, 'b2b_api');
        $noFileSlot = $this->row($single, 'price_list_import');

        $this->artisan('prices:backfill-history-currency')
            ->expectsOutputToContain('Zostaje bez waluty (nie da się ustalić): 3')
            ->assertSuccessful();
        $this->assertSame(1, ProductPriceHistory::query()->whereNotNull('currency')->count(), 'podgląd niczego nie zapisuje');

        $this->artisan('prices:backfill-history-currency', ['--apply' => true, '--backup' => $this->backup])
            ->expectsOutputToContain('Zapisano walutę 7 wierszy')
            ->assertSuccessful();

        $this->assertCurrency('EUR', $file);
        $this->assertCurrency('EUR', $discount);
        $this->assertCurrency('EUR', $fromBolle);
        $this->assertCurrency('PLN', $fromProcera);
        $this->assertCurrency(null, $legacy);
        $this->assertCurrency('USD', $filled);
        $this->assertCurrency('PLN', $singleLegacy);
        $this->assertCurrency('PLN', $singleEmpty);
        $this->assertCurrency('EUR', $anroRow);
        $this->assertCurrency(null, $bareRow);
        $this->assertCurrency(null, $noFileSlot);
        $this->assertFileExists($this->backup);

        // wiersz zmieniony po uzupełnieniu nie jest cofany
        $fromProcera->forceFill(['currency' => 'CZK'])->save();

        $this->artisan('prices:backfill-history-currency', ['--restore' => $this->backup])
            ->expectsOutputToContain('Wyczyszczono walutę 6 wierszy')
            ->assertSuccessful();
        $this->assertCurrency(null, $file);
        $this->assertCurrency(null, $anroRow);
        $this->assertCurrency('CZK', $fromProcera);
        $this->assertCurrency('USD', $filled);
    }

    private function assertCurrency(?string $expected, ProductPriceHistory $row): void
    {
        $this->assertSame($expected, $row->fresh()->currency, 'wiersz '.$row->source);
    }

    private function account(string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => 'jan-'.$connector,
            'password' => 'sekret',
            'sites' => [$connector.'.example.test'],
            'connector' => $connector,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    private function syncRun(B2bAccount $account): B2bSyncRun
    {
        return B2bSyncRun::query()->create(['b2b_account_id' => $account->id, 'status' => 'finished', 'trigger' => 'manual']);
    }

    private function card(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Karta '.$sku,
            'manufacturer' => 'X',
            'catalog_price_net' => 10,
            'purchase_price' => 10,
            'currency' => 'PLN',
        ]);
    }

    private function slot(Product $card, string $key, string $currency, ?int $accountId = null): void
    {
        ProductSourcePrice::query()->create([
            'product_id' => $card->id,
            'source_key' => $key,
            'b2b_account_id' => $accountId,
            'catalog_price_net' => 10,
            'purchase_price' => 10,
            'currency' => $currency,
        ]);
    }

    private function row(Product $card, string $source, ?B2bSyncRun $run = null, ?string $currency = null): ProductPriceHistory
    {
        return ProductPriceHistory::query()->create([
            'product_id' => $card->id,
            'b2b_sync_run_id' => $run?->id,
            'catalog_price_net' => 10,
            'purchase_price' => 10,
            'currency' => $currency,
            'source' => $source,
        ]);
    }
}
