<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Pricing\ProductEffectivePrice;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ProductEffectivePriceTest extends TestCase
{
    use RefreshDatabase;

    private ProductEffectivePrice $prices;

    private User $user;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->prices = app(ProductEffectivePrice::class);
        $this->user = User::factory()->create();
    }

    public function test_file_slot_sets_card_price(): void
    {
        $product = $this->card();

        $result = $this->prices->saveSlot($product, ProductSourcePrice::SOURCE_FILE, [
            'catalog_price_net' => 50.00, 'purchase_price' => 45.00, 'discount_percent' => 10, 'currency' => 'PLN',
        ]);

        $this->assertNull($result['previous']);
        $this->assertSame(ProductSourcePrice::SOURCE_FILE, $result['slot']->source_key);
        $this->assertSame([
            'catalog_price_net' => ['40.80', '50.00'],
            'purchase_price' => ['36.72', '45.00'],
        ], $result['card_changes']);
        $this->assertCardPrice($product, '50.00', '10.00', '45.00', 'PLN');
    }

    public function test_b2b_slot_wins_over_file_with_whole_price(): void
    {
        $product = $this->card();
        $account = $this->account('jsp');

        $this->prices->saveSlot($product, ProductSourcePrice::SOURCE_FILE, [
            'catalog_price_net' => 50.00, 'purchase_price' => 45.00, 'discount_percent' => 10, 'currency' => 'PLN',
        ]);
        $this->prices->saveSlot($product, ProductSourcePrice::b2bKey($account->id), [
            'b2b_account_id' => $account->id,
            'catalog_price_net' => 12.00, 'purchase_price' => 9.60, 'discount_percent' => 20, 'currency' => 'EUR',
        ]);

        // zakup, katalogowa, rabat i waluta — wszystko z B2B, nic nie miesza się z plikiem
        $this->assertCardPrice($product, '12.00', '20.00', '9.60', 'EUR');
        $this->assertSame(ProductSourcePrice::b2bKey($account->id), $this->prices->resolve($product)['source_key']);

        // kolejny import z pliku zapisuje tylko swój slot i nie nadpisuje ceny z B2B
        $again = $this->prices->saveSlot($product, ProductSourcePrice::SOURCE_FILE, [
            'catalog_price_net' => 70.00, 'purchase_price' => 63.00, 'discount_percent' => 10, 'currency' => 'PLN',
        ]);
        $this->assertSame([], $again['card_changes']);
        $this->assertCardPrice($product, '12.00', '20.00', '9.60', 'EUR');
        $this->assertSame('63.00', ProductSourcePrice::query()->where('source_key', ProductSourcePrice::SOURCE_FILE)->value('purchase_price'));
    }

    public function test_newest_checked_b2b_account_wins(): void
    {
        // Obaj to dystrybutorzy tej marki — karta „Anro” miałaby w koncie anro cennik producenta, który od 23.09.2026
        // wygrywa niezależnie od daty (test_manufacturer_b2b_price_list_wins_over_fresher_distributor).
        $product = $this->card(['manufacturer' => 'Ansell']);
        $first = $this->account('jsp');
        $second = $this->account('anro');

        $this->prices->saveSlot($product, ProductSourcePrice::b2bKey($first->id), [
            'b2b_account_id' => $first->id, 'catalog_price_net' => 10.00, 'purchase_price' => 10.00, 'currency' => 'PLN',
            'checked_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);
        $older = $this->prices->saveSlot($product, ProductSourcePrice::b2bKey($second->id), [
            'b2b_account_id' => $second->id, 'catalog_price_net' => 20.00, 'purchase_price' => 20.00, 'currency' => 'PLN',
            'checked_at' => Carbon::parse('2026-09-15 09:00:00'),
        ]);

        $this->assertSame([], $older['card_changes']);
        $this->assertCardPrice($product, '10.00', '10.00', '10.00', 'PLN');

        $this->prices->saveSlot($product, ProductSourcePrice::b2bKey($second->id), [
            'b2b_account_id' => $second->id, 'catalog_price_net' => 20.00, 'purchase_price' => 20.00, 'currency' => 'PLN',
            'checked_at' => Carbon::parse('2026-09-15 11:00:00'),
        ]);

        $this->assertCardPrice($product, '20.00', '10.00', '20.00', 'PLN');
        $this->assertSame(ProductSourcePrice::b2bKey($second->id), $this->prices->resolve($product)['source_key']);
    }

    public function test_deleting_b2b_slot_falls_back_to_file_and_without_slots_card_stays(): void
    {
        $product = $this->card();
        $account = $this->account('bolle');

        $this->prices->saveSlot($product, ProductSourcePrice::SOURCE_FILE, [
            'catalog_price_net' => 50.00, 'purchase_price' => 45.00, 'discount_percent' => 10, 'currency' => 'PLN',
        ]);
        $this->prices->saveSlot($product, ProductSourcePrice::b2bKey($account->id), [
            'b2b_account_id' => $account->id,
            'catalog_price_net' => 12.00, 'purchase_price' => 9.60, 'discount_percent' => 20, 'currency' => 'EUR',
        ]);

        $changes = $this->prices->deleteSlot($product, ProductSourcePrice::b2bKey($account->id));

        $this->assertSame([
            'catalog_price_net' => ['12.00', '50.00'],
            'discount_percent' => ['20.00', '10.00'],
            'purchase_price' => ['9.60', '45.00'],
            'currency' => ['EUR', 'PLN'],
        ], $changes);
        $this->assertCardPrice($product, '50.00', '10.00', '45.00', 'PLN');

        // ostatni slot usunięty — cena karty zostaje, nie jest zerowana
        $this->assertSame([], $this->prices->deleteSlot($product, ProductSourcePrice::SOURCE_FILE));
        $this->assertCardPrice($product, '50.00', '10.00', '45.00', 'PLN');
        $this->assertNull($this->prices->resolve($product));
    }

    public function test_card_without_slots_is_unchanged(): void
    {
        $product = $this->card();

        $this->assertNull($this->prices->resolve($product));
        $this->assertSame([], $this->prices->refresh($product));
        $this->assertCardPrice($product, '40.80', '10.00', '36.72', 'PLN');
    }

    public function test_card_with_active_variant_is_not_recalculated(): void
    {
        $product = $this->card();
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id, 'source' => 'b2b:signproject', 'remote_id' => 'v1', 'label' => 'A4 · PCV',
            'purchase_price' => 4.20, 'currency' => 'PLN',
        ]);

        $result = $this->prices->saveSlot($product, ProductSourcePrice::SOURCE_FILE, [
            'catalog_price_net' => 50.00, 'purchase_price' => 45.00, 'currency' => 'PLN',
        ]);

        $this->assertSame([], $result['card_changes']);
        $this->assertNull($this->prices->resolve($product));
        $this->assertCardPrice($product, '40.80', '10.00', '36.72', 'PLN');
        $this->assertSame(1, ProductSourcePrice::query()->where('product_id', $product->id)->count());

        // wersja usunięta u dostawcy — karta znów liczona ze slotów
        $variant->forceFill(['removed_at' => Carbon::now()])->save();
        $this->assertNotSame([], $this->prices->refresh($product));
        $this->assertCardPrice($product, '50.00', '10.00', '45.00', 'PLN');
    }

    public function test_save_slot_returns_previous_values_and_same_save_changes_nothing(): void
    {
        $product = $this->card();
        $values = ['catalog_price_net' => 60.00, 'purchase_price' => 54.00, 'discount_percent' => 10, 'currency' => 'PLN'];

        $first = $this->prices->saveSlot($product, ProductSourcePrice::SOURCE_FILE, [
            'catalog_price_net' => 50.00, 'purchase_price' => 45.00, 'discount_percent' => 10, 'currency' => 'PLN',
        ]);
        $second = $this->prices->saveSlot($product, ProductSourcePrice::SOURCE_FILE, $values);

        $this->assertNotNull($second['previous']);
        $this->assertSame($first['slot']->id, $second['previous']->id);
        $this->assertSame($first['slot']->id, $second['slot']->id);
        $this->assertSame('50.00', $second['previous']->catalog_price_net);
        $this->assertSame('45.00', $second['previous']->purchase_price);
        $this->assertSame([
            'catalog_price_net' => ['50.00', '60.00'],
            'purchase_price' => ['45.00', '54.00'],
        ], $second['card_changes']);

        $third = $this->prices->saveSlot($product, ProductSourcePrice::SOURCE_FILE, $values);

        $this->assertSame([], $third['card_changes']);
        $this->assertSame('60.00', $third['previous']->catalog_price_net);
    }

    public function test_missing_catalog_price_uses_purchase_and_vice_versa(): void
    {
        $product = $this->card();
        $account = $this->account('anro');

        $this->prices->saveSlot($product, ProductSourcePrice::SOURCE_FILE, [
            'catalog_price_net' => null, 'purchase_price' => 30.00, 'currency' => 'PLN',
        ]);
        // slot bez rabatu — rabat karty zostaje (brak informacji to nie 0%), katalogowa = zakup
        $this->assertCardPrice($product, '30.00', '10.00', '30.00', 'PLN');

        $this->prices->saveSlot($product, ProductSourcePrice::b2bKey($account->id), [
            'b2b_account_id' => $account->id, 'catalog_price_net' => 25.00, 'purchase_price' => null, 'currency' => 'PLN',
        ]);
        $this->assertCardPrice($product, '25.00', '10.00', '25.00', 'PLN');
    }

    public function test_card_with_slot_prices_does_not_save_card(): void
    {
        $product = $this->card();
        $account = $this->account('jsp');
        // slot zapisany bez przeliczenia — karta nadal ma własną cenę
        $slot = ProductSourcePrice::query()->create([
            'product_id' => $product->id, 'source_key' => ProductSourcePrice::b2bKey($account->id),
            'b2b_account_id' => $account->id, 'catalog_price_net' => 12.00, 'purchase_price' => 9.60, 'currency' => 'EUR',
        ]);

        $copy = $this->prices->cardWithSlotPrices($product, $slot);

        $this->assertSame('12.00', $copy->catalog_price_net);
        $this->assertSame('9.60', $copy->purchase_price);
        $this->assertSame('EUR', $copy->currency);
        // slot bez rabatu — kopia zostawia rabat karty
        $this->assertSame('10.00', $copy->discount_percent);
        $this->assertFalse($copy->isDirty());

        $this->assertSame('36.72', $product->purchase_price);
        $this->assertCardPrice($product, '40.80', '10.00', '36.72', 'PLN');

        $withoutSlot = $this->prices->cardWithSlotPrices($product, null);
        $this->assertSame('36.72', $withoutSlot->purchase_price);
        $this->assertSame('PLN', $withoutSlot->currency);
    }

    public function test_second_file_slot_updates_existing_one(): void
    {
        $product = $this->card();

        $this->prices->saveSlot($product, ProductSourcePrice::SOURCE_FILE, ['purchase_price' => 45.00, 'currency' => 'PLN']);
        $this->prices->saveSlot($product, ProductSourcePrice::SOURCE_FILE, ['purchase_price' => 47.00, 'currency' => 'PLN']);

        $this->assertSame(1, ProductSourcePrice::query()->where('product_id', $product->id)->count());
        $this->assertSame('47.00', ProductSourcePrice::query()->where('product_id', $product->id)->value('purchase_price'));

        $this->expectException(QueryException::class);
        ProductSourcePrice::query()->create([
            'product_id' => $product->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'purchase_price' => 1.00,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function card(array $attributes = []): Product
    {
        $this->sequence++;

        return Product::query()->create([
            'sku' => 'KARTA-'.$this->sequence,
            'name' => 'Rękawice robocze '.$this->sequence,
            'manufacturer' => 'Anro',
            'catalog_price_net' => 40.80,
            'discount_percent' => 10,
            'purchase_price' => 36.72,
            'currency' => 'PLN',
            ...$attributes,
        ]);
    }

    private function account(string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $connector.'-konto', 'password' => 'sekret', 'sites' => ['b2b.example.pl'], 'connector' => $connector,
            'created_by' => $this->user->id, 'updated_by' => $this->user->id,
        ]);
    }

    private function assertCardPrice(Product $product, string $catalog, string $discount, string $purchase, string $currency): void
    {
        $fresh = Product::query()->findOrFail($product->id);

        $this->assertSame(
            ['catalog_price_net' => $catalog, 'discount_percent' => $discount, 'purchase_price' => $purchase, 'currency' => $currency],
            [
                'catalog_price_net' => $fresh->catalog_price_net,
                'discount_percent' => $fresh->discount_percent,
                'purchase_price' => $fresh->purchase_price,
                'currency' => $fresh->currency,
            ],
        );
    }
}
