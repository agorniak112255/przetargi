<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * b2b:recompute-effective-prices — karty liczone po staremu (najświeższe B2B przed plikiem) mają dostać cenę wg
 * pierwszeństwa cennika producenta (23.09.2026). Bez --apply tylko raport, z --apply przeliczenie.
 */
final class B2bRecomputeEffectivePricesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Product $bolleCard;

    private Product $securaCard;

    private Product $consistent;

    private Product $single;

    protected function setUp(): void
    {
        parent::setUp();
        $bolle = $this->account('bolle', 'b2b.bolle.com');
        $rawpol = $this->account('rawpol', 'b2b.raw-pol.pl');

        // konto producenta (starsze) i dystrybutor (świeższy): dziś cena z dystrybutora, wg reguł z konta producenta
        $this->bolleCard = $this->card('BOL-1', 'Bolle', 40.0);
        $this->slot($this->bolleCard, ProductSourcePrice::b2bKey($bolle->id), 50.0, '2026-09-01', ['b2b_account_id' => $bolle->id]);
        $this->slot($this->bolleCard, ProductSourcePrice::b2bKey($rawpol->id), 40.0, '2026-09-20', ['b2b_account_id' => $rawpol->id]);

        // plik producenta i dystrybutor: dziś cena z B2B, wg reguł z pliku producenta
        $list = PriceList::query()->create(['manufacturer' => 'Secura', 'version' => '2026']);
        $this->securaCard = $this->card('SEC-1', 'Secura', 33.0);
        $this->slot($this->securaCard, ProductSourcePrice::SOURCE_FILE, 30.0, '2026-09-01', ['price_list_id' => $list->id]);
        $this->slot($this->securaCard, ProductSourcePrice::b2bKey($rawpol->id), 33.0, '2026-09-20', ['b2b_account_id' => $rawpol->id]);

        // cena zgodna z regułami (waluta w innym zapisie to ta sama waluta)
        $this->consistent = $this->card('OK-1', 'Bolle', 50.0);
        $this->slot($this->consistent, ProductSourcePrice::b2bKey($bolle->id), 50.0, '2026-09-01', ['b2b_account_id' => $bolle->id, 'currency' => 'pln']);
        $this->slot($this->consistent, ProductSourcePrice::b2bKey($rawpol->id), 45.0, '2026-09-20', ['b2b_account_id' => $rawpol->id]);

        // jeden slot — kolejność źródeł nic nie zmienia, polecenie karty nie bierze
        $this->single = $this->card('ONE-1', 'Secura', 99.0);
        $this->slot($this->single, ProductSourcePrice::b2bKey($rawpol->id), 10.0, '2026-09-20', ['b2b_account_id' => $rawpol->id]);
    }

    public function test_bez_apply_tylko_raport(): void
    {
        $this->artisan('b2b:recompute-effective-prices')
            ->expectsOutputToContain('Karty z co najmniej dwoma źródłami ceny: 3')
            ->expectsOutputToContain('Karty z ceną inną niż wynika z reguł: 2')
            ->expectsOutputToContain('BOL-1')
            ->expectsOutputToContain('SEC-1')
            ->doesntExpectOutputToContain('OK-1')
            ->doesntExpectOutputToContain('ONE-1')
            ->assertSuccessful();

        $this->assertEquals(40.0, (float) $this->bolleCard->refresh()->purchase_price);
        $this->assertEquals(33.0, (float) $this->securaCard->refresh()->purchase_price);
        $this->assertEquals(99.0, (float) $this->single->refresh()->purchase_price);
    }

    public function test_z_apply_przelicza_karty_i_drugi_przebieg_nic_nie_zmienia(): void
    {
        $this->artisan('b2b:recompute-effective-prices', ['--apply' => true])
            ->expectsOutputToContain('Przeliczono ceny kart: 2')
            ->assertSuccessful();

        $this->assertEquals(50.0, (float) $this->bolleCard->refresh()->purchase_price);
        $this->assertEquals(30.0, (float) $this->securaCard->refresh()->purchase_price);
        $this->assertEquals(50.0, (float) $this->consistent->refresh()->purchase_price);
        // karta z jednym slotem poza zakresem polecenia
        $this->assertEquals(99.0, (float) $this->single->refresh()->purchase_price);

        $this->artisan('b2b:recompute-effective-prices')
            ->expectsOutputToContain('Karty z ceną inną niż wynika z reguł: 0')
            ->assertSuccessful();
    }

    private function account(string $connector, string $site): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => 'login-'.$connector,
            'password' => 'haslo',
            'sites' => [$site],
            'connector' => $connector,
        ]);
    }

    private function card(string $sku, string $manufacturer, float $price): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Karta '.$sku,
            'manufacturer' => $manufacturer,
            'catalog_price_net' => $price,
            'purchase_price' => $price,
            'currency' => 'PLN',
            'stock' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function slot(Product $product, string $sourceKey, float $price, string $checkedAt, array $values = []): void
    {
        ProductSourcePrice::query()->create([
            'product_id' => $product->id,
            'source_key' => $sourceKey,
            'catalog_price_net' => $price,
            'purchase_price' => $price,
            'currency' => 'PLN',
            'checked_at' => Carbon::parse($checkedAt),
            ...$values,
        ]);
    }
}
