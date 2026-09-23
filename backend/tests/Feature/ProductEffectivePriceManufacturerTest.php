<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bAccountManufacturerRule;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Pricing\ProductEffectivePrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pierwszeństwo cennika producenta przed dystrybutorem wielu marek i znacznik „cena” z okna „Producenci”
 * (decyzje użytkownika 23.09.2026). Przypadki z audytu produkcji: Procera nadpisywała ceny konta Bolle na 34
 * wspólnych kartach (wygrywał później sprawdzony slot), Ardon — plik cennika ATG na 44 kartach.
 */
final class ProductEffectivePriceManufacturerTest extends TestCase
{
    use RefreshDatabase;

    private ProductEffectivePrice $prices;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->prices = app(ProductEffectivePrice::class);
        $this->user = User::factory()->create();
    }

    public function test_manufacturer_b2b_price_list_wins_over_fresher_distributor(): void
    {
        $card = $this->card('Bolle');
        $bolle = $this->account('bolle');
        $procera = $this->account('procera');

        $this->slot($card, $bolle, 15.39, 6.93, 'EUR', '2026-09-19 10:00');
        $this->slot($card, $procera, 65.72, 34.17, 'PLN', '2026-09-21 20:55');

        $this->assertCardPrice($card, '15.39', '6.93', 'EUR');
        $explain = $this->prices->explain($card->fresh());
        $this->assertSame(ProductSourcePrice::b2bKey($bolle->id), $explain['winner']?->source_key);
        $this->assertSame(
            'pierwszeństwo ma cennik producenta (konto B2B producenta)',
            $explain['reasons'][ProductSourcePrice::b2bKey($procera->id)],
        );
    }

    public function test_manufacturer_file_price_list_wins_over_distributor_b2b(): void
    {
        $card = $this->card('ATG');
        $ardon = $this->account('ardon');
        $list = PriceList::query()->create(['manufacturer' => 'ATG', 'manufacturer_key' => 'atg', 'version' => 'v1']);

        $this->fileSlot($card, $list, 30.00, 24.00, '2026-09-12 19:45');
        $this->slot($card, $ardon, 28.00, 21.00, 'PLN', '2026-09-22 03:00');

        $this->assertCardPrice($card, '30.00', '24.00', 'PLN');
        $this->assertSame(
            'pierwszeństwo ma cennik producenta (plik cennika producenta)',
            $this->prices->explain($card->fresh())['reasons'][ProductSourcePrice::b2bKey($ardon->id)],
        );
    }

    public function test_manufacturer_b2b_wins_over_manufacturer_file(): void
    {
        $card = $this->card('Bolle');
        $bolle = $this->account('bolle');
        $list = PriceList::query()->create(['manufacturer' => 'Bolle', 'manufacturer_key' => 'bolle', 'version' => 'v1']);

        $this->slot($card, $bolle, 15.39, 6.93, 'EUR', '2026-09-10 10:00');
        $this->fileSlot($card, $list, 15.39, 15.39, '2026-09-12 19:45', 'EUR');

        $this->assertCardPrice($card, '15.39', '6.93', 'EUR');
        $this->assertSame(
            [ProductSourcePrice::SOURCE_FILE => 'pierwszeństwo ma konto B2B producenta'],
            $this->prices->explain($card->fresh())['reasons'],
        );
    }

    public function test_distributor_file_does_not_block_distributor_b2b(): void
    {
        // plik cennika innej marki niż karta (np. cennik dystrybutora) nie jest cennikiem producenta — B2B dalej wygrywa
        $card = $this->card('Ansell');
        $ardon = $this->account('ardon');
        $list = PriceList::query()->create(['manufacturer' => 'Rawpol', 'manufacturer_key' => 'rawpol', 'version' => 'v1']);

        $this->fileSlot($card, $list, 30.00, 24.00, '2026-09-12 19:45');
        $this->slot($card, $ardon, 28.00, 21.00, 'PLN', '2026-09-10 03:00');

        $this->assertCardPrice($card, '28.00', '21.00', 'PLN');
        $this->assertSame(
            [ProductSourcePrice::SOURCE_FILE => 'pierwszeństwo ma cena z konta B2B'],
            $this->prices->explain($card->fresh())['reasons'],
        );
    }

    public function test_disabled_distributor_price_is_skipped_and_file_takes_over(): void
    {
        $card = $this->card('Ansell');
        $ardon = $this->account('ardon');
        $this->slot($card, $ardon, 28.00, 21.00, 'PLN', '2026-09-10 03:00');
        $this->fileSlot($card, null, 30.00, 24.00, '2026-09-12 19:45');
        $this->assertCardPrice($card, '28.00', '21.00', 'PLN');

        $this->disablePrice($ardon, 'Ansell');
        $changes = $this->prices->refresh($card);

        $this->assertSame(['24.00'], [$card->fresh()->purchase_price]);
        $this->assertArrayHasKey('purchase_price', $changes);
        $this->assertSame(
            'cena producenta Ansell wyłączona w tym cenniku',
            $this->prices->explain($card->fresh())['reasons'][ProductSourcePrice::b2bKey($ardon->id)],
        );
    }

    public function test_only_disabled_source_keeps_last_card_price_and_says_why(): void
    {
        $card = $this->card('Ansell');
        $ardon = $this->account('ardon');
        $this->slot($card, $ardon, 28.00, 21.00, 'PLN', '2026-09-10 03:00');

        $this->disablePrice($ardon, 'ANSELL');
        $this->assertSame([], $this->prices->refresh($card));

        // cena karty zostaje (nie ma innego źródła), ale explain mówi, że nie obowiązuje żaden slot
        $this->assertCardPrice($card, '28.00', '21.00', 'PLN');
        $this->assertNull($this->prices->explain($card->fresh())['winner']);
        $this->assertNull($this->prices->resolve($card->fresh()));
    }

    public function test_rule_matches_manufacturer_as_the_account_names_it(): void
    {
        // karta wspólna trzyma nazwę z ostatniego przebiegu; reguła konta trafia w brzmienie tego konta
        $card = $this->card('ATG');
        $ardon = $this->account('ardon');
        B2bProductLink::query()->create([
            'b2b_account_id' => $ardon->id, 'remote_id' => 'A1', 'product_id' => $card->id, 'manufacturer' => 'ATG Glovesolutions',
        ]);
        $this->slot($card, $ardon, 28.00, 21.00, 'PLN', '2026-09-10 03:00');
        $this->fileSlot($card, null, 30.00, 24.00, '2026-09-12 19:45');
        $this->assertCardPrice($card, '28.00', '21.00', 'PLN');

        $this->disablePrice($ardon, 'ATG Glovesolutions');
        $this->prices->refresh($card);

        $this->assertCardPrice($card, '30.00', '24.00', 'PLN');
    }

    public function test_slot_without_price_never_wins(): void
    {
        $card = $this->card('Ansell');
        $ardon = $this->account('ardon');
        $this->fileSlot($card, null, 30.00, 24.00, '2026-09-12 19:45');
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::b2bKey($ardon->id), 'b2b_account_id' => $ardon->id,
            'catalog_price_net' => 0, 'purchase_price' => 0, 'currency' => 'PLN', 'checked_at' => Carbon::parse('2026-09-20'),
        ]);
        $this->prices->refresh($card);

        $this->assertCardPrice($card, '30.00', '24.00', 'PLN');
        $this->assertSame('brak ceny w tym źródle', $this->prices->explain($card->fresh())['reasons'][ProductSourcePrice::b2bKey($ardon->id)]);
    }

    public function test_own_b2b_account_ids_skip_accounts_with_disabled_price(): void
    {
        $bolle = $this->account('bolle');
        $this->account('procera');

        $this->assertSame([$bolle->id], $this->prices->ownB2bAccountIds('BOLLE'));

        $this->disablePrice($bolle, 'Bolle');
        $this->assertSame([], $this->prices->ownB2bAccountIds('Bolle'));
    }

    private function card(string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => 'KARTA-'.$manufacturer,
            'name' => 'Okulary ochronne',
            'manufacturer' => $manufacturer,
            'catalog_price_net' => 99.00,
            'discount_percent' => 0,
            'purchase_price' => 99.00,
            'currency' => 'PLN',
        ]);
    }

    private function account(string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $connector.'-konto', 'password' => 'sekret', 'sites' => ['b2b.example.pl'], 'connector' => $connector,
            'created_by' => $this->user->id, 'updated_by' => $this->user->id,
        ]);
    }

    private function slot(Product $card, B2bAccount $account, float $catalog, float $purchase, string $currency, string $checkedAt): void
    {
        $this->prices->saveSlot($card, ProductSourcePrice::b2bKey($account->id), [
            'b2b_account_id' => $account->id, 'catalog_price_net' => $catalog, 'purchase_price' => $purchase,
            'currency' => $currency, 'checked_at' => Carbon::parse($checkedAt),
        ]);
    }

    private function fileSlot(Product $card, ?PriceList $list, float $catalog, float $purchase, string $checkedAt, string $currency = 'PLN'): void
    {
        $this->prices->saveSlot($card, ProductSourcePrice::SOURCE_FILE, [
            'price_list_id' => $list?->id, 'catalog_price_net' => $catalog, 'purchase_price' => $purchase,
            'currency' => $currency, 'checked_at' => Carbon::parse($checkedAt),
        ]);
    }

    private function disablePrice(B2bAccount $account, string $manufacturer): void
    {
        B2bAccountManufacturerRule::query()->create([
            'b2b_account_id' => $account->id,
            'manufacturer' => $manufacturer,
            'manufacturer_key' => PriceList::manufacturerKey($manufacturer),
            'take_price' => false,
            'take_description' => true,
        ]);
    }

    private function assertCardPrice(Product $card, string $catalog, string $purchase, string $currency): void
    {
        $fresh = Product::query()->findOrFail($card->id);
        $this->assertSame([$catalog, $purchase, $currency], [$fresh->catalog_price_net, $fresh->purchase_price, $fresh->currency]);
    }
}
