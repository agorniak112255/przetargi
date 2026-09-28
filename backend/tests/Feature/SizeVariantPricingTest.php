<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\CardMatchFinder;
use App\Services\Presta\PrestaExportGateway;
use App\Services\Presta\PrestaProductExportService;
use App\Services\Pricing\ProductEffectivePrice;
use App\Services\Pricing\SourcePriceComparison;
use App\Support\ProductIdentifierCode;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakePrestaExportGateway;
use Tests\TestCase;

/**
 * Rozmiary w różnych cenach jako wiersze product_variants „size” (decyzja użytkownika 28.09.2026): strażnicy ceny
 * liczą tylko wersje Sign Project („version”) — karta z samymi rozmiarami ma cenę najniższego rozmiaru ze slotu
 * konta; strażnicy utraty danych (karta kasowana przy scaleniu) liczą wszystkie rodzaje.
 */
final class SizeVariantPricingTest extends TestCase
{
    use RefreshDatabase;

    private ProductEffectivePrice $prices;

    private SourcePriceComparison $comparison;

    private User $user;

    private int $sku = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-09-28 12:00:00'));
        $this->prices = app(ProductEffectivePrice::class);
        $this->comparison = app(SourcePriceComparison::class);
        $this->user = User::factory()->withRole('admin')->create();
    }

    public function test_card_with_only_size_rows_takes_the_lowest_size_price_from_the_account_slot(): void
    {
        $card = $this->card('Mascot', 0, 0);
        $mascot = $this->account('mascot');
        $this->sizeRow($card, $mascot, 'M-44', '44', 12.50);
        $this->sizeRow($card, $mascot, 'M-52', '52', 14.00);
        $this->matchLink($card, $mascot, 'M-44', 'M-44');

        $this->slot($card, $mascot, 12.50, 15.00, 14.00);

        $card->refresh();
        $this->assertSame('12.50', $card->purchase_price);
        $this->assertSame('15.00', $card->catalog_price_net);
        $this->assertSame(ProductSourcePrice::b2bKey($mascot->id), $this->prices->explain($card)['winner']?->source_key);
        // porównanie źródeł nie mówi „ceny w wersjach karty”
        $rows = $this->comparison->forCard(
            $card,
            ProductSourcePrice::query()->with(['account', 'priceList'])->where('product_id', $card->id)->get(),
            $this->prices->explain($card),
        )['rows'];
        $this->assertTrue($rows[ProductSourcePrice::b2bKey($mascot->id)]['comparable']);
        $this->assertNull($rows[ProductSourcePrice::b2bKey($mascot->id)]['not_comparable_reason']);
    }

    public function test_version_row_of_sign_project_still_keeps_the_card_out_of_slot_pricing(): void
    {
        $card = $this->card('Mascot', 0, 0);
        $mascot = $this->account('mascot');
        $this->sizeRow($card, $mascot, 'M-44', '44', 12.50);
        $this->versionRow($card, 'SP-1');

        $this->slot($card, $mascot, 12.50, 15.00, 14.00);
        $this->prices->refresh($card);

        $card->refresh();
        $this->assertSame('0.00', $card->purchase_price);
        $this->assertSame(['winner' => null, 'reasons' => []], $this->prices->explain($card));
        $this->assertSame([], $this->comparison->orderQuantities(collect([$card])));
        $this->assertSame(
            PrestaProductExportService::VARIANTS_BLOCKED_MESSAGE,
            app(PrestaProductExportService::class)->blockedReason($card),
        );
    }

    public function test_order_quantity_carries_size_price_max_of_the_winning_slot(): void
    {
        $card = $this->card('Mascot', 0, 0);
        $mascot = $this->account('mascot');
        $this->sizeRow($card, $mascot, 'M-44', '44', 12.50);
        $this->sizeRow($card, $mascot, 'M-52', '52', 14.00);
        $this->slot($card, $mascot, 12.50, 15.00, 14.00);
        // rozmiary w jednej cenie — bez warunku zakupu
        $plain = $this->card('Mascot', 0, 0);
        $this->sizeRow($plain, $mascot, 'P-44', '44', 9.00);
        $this->slot($plain, $mascot, 9.00, 11.00, null);

        $result = $this->comparison->orderQuantities(collect([$card->fresh(), $plain->fresh()]));

        $this->assertSame([$card->id], array_keys($result));
        $this->assertSame([
            'min' => null,
            'step' => null,
            'unit' => null,
            'varies' => false,
            'price_note' => null,
            'price_carton_qty' => null,
            'size_price_max' => '14.00',
            'size_price_currency' => 'PLN',
            'source_key' => ProductSourcePrice::b2bKey($mascot->id),
            'source_label' => 'B2B Mascot',
        ], $result[$card->id]);
        // karta wyrobu liczy to samo ze zwycięzcy explain()
        $winner = $this->prices->explain($card->fresh())['winner'];
        $this->assertNotNull($winner);
        $this->assertSame($result[$card->id], $this->comparison->orderQuantityOf($winner));
    }

    public function test_size_price_max_of_a_losing_slot_is_not_the_card_condition(): void
    {
        // konto producenta Mascot (jedna cena) wygrywa z dystrybutorem, którego rozmiary mają różne ceny
        $card = $this->card('Mascot', 0, 0);
        $mascot = $this->account('mascot');
        $ardon = $this->account('ardon');
        $this->slot($card, $mascot, 20.00, 25.00, null, '2026-09-20 10:00');
        $this->slot($card, $ardon, 12.50, 15.00, 14.00, '2026-09-27 10:00');

        $this->assertSame(ProductSourcePrice::b2bKey($mascot->id), $this->prices->explain($card->fresh())['winner']?->source_key);
        $this->assertSame([], $this->comparison->orderQuantities(collect([$card->fresh()])));
        $this->assertNull(app(PrestaProductExportService::class)->blockedReason($card->fresh()));
    }

    public function test_presta_blocks_sizes_in_different_prices_but_exports_sizes_in_one_price(): void
    {
        $presta = new FakePrestaExportGateway;
        $this->app->instance(PrestaExportGateway::class, $presta);
        Sanctum::actingAs($this->user);
        $mascot = $this->account('mascot');

        $different = $this->card('Mascot', 0, 0);
        $this->sizeRow($different, $mascot, 'D-44', '44', 1234.50);
        $this->sizeRow($different, $mascot, 'D-52', '52', 1400.00);
        $this->slot($different, $mascot, 1234.50, 1500.00, 1400.00);
        $message = 'Rozmiary karty mają różne ceny (od 1 234,50 do 1 400,00 zł) — eksport dałby wszystkim rozmiarom cenę najniższą.';

        $this->assertSame($message, app(PrestaProductExportService::class)->blockedReason($different->fresh()));
        $this->postJson('/api/products/'.$different->id.'/presta-export')
            ->assertStatus(422)
            ->assertJsonPath('message', $message);
        $this->assertSame([], $presta->created);

        $same = $this->card('Mascot', 0, 0);
        $this->sizeRow($same, $mascot, 'S-44', '44', 9.00);
        $this->sizeRow($same, $mascot, 'S-52', '52', 9.00);
        $this->slot($same, $mascot, 9.00, 11.00, null);

        $this->assertNull(app(PrestaProductExportService::class)->blockedReason($same->fresh()));
        $this->postJson('/api/products/'.$same->id.'/presta-export')->assertOk()->assertJsonPath('action', 'created');
        $this->assertCount(1, $presta->created);
    }

    public function test_card_match_target_with_size_rows_is_not_a_version_conflict_but_source_with_size_rows_is_refused(): void
    {
        $anro = $this->account('anro');
        $p4s = $this->account('p4s');

        // karta producenta z rozmiarami konta Anro — para dystrybutor → producent bez konfliktu „ma wersje”
        $target = $this->matchCard('IF/070/X', 'Anro');
        $this->matchLink($target, $anro, 'A-IF/070/X', 'IF/070/X');
        $this->sizeRow($target, $anro, 'A-IF/070/X-S', 'S', 10.00);
        $source = $this->matchCard('ZPPV70', 'ANRO');
        $this->matchLink($source, $p4s, 'P-ZPPV70', 'ZPPV70');
        $this->identifier($source, 'b2b:'.$p4s->id, 'P-ZPPV70', 'IF/070/X');

        $result = app(CardMatchFinder::class)->evaluate($source);

        $this->assertSame('pending', $result['status']);
        $this->assertSame($target->id, $result['target_product_id']);
        $this->assertNull($result['reason']);

        // wersja Sign Project na karcie producenta — konflikt jak dotąd
        $this->versionRow($target, 'SP-70');
        $conflict = app(CardMatchFinder::class)->evaluate($source);
        $this->assertSame('conflict', $conflict['status']);
        $this->assertStringContainsString('karta producenta ma wersje (1)', (string) $conflict['reason']);

        // karta dystrybutora z rozmiarami nie jest źródłem — scalenie skasowałoby je kaskadą
        $withSizes = $this->matchCard('ZPPV80', 'ANRO');
        $this->matchLink($withSizes, $p4s, 'P-ZPPV80', 'ZPPV80');
        $this->identifier($withSizes, 'b2b:'.$p4s->id, 'P-ZPPV80', 'IF/080/X');
        $this->sizeRow($withSizes, $p4s, 'P-ZPPV80-S', 'S', 10.00);
        $other = $this->matchCard('IF/080/X', 'Anro');
        $this->matchLink($other, $anro, 'A-IF/080/X', 'IF/080/X');

        $this->assertNull(app(CardMatchFinder::class)->evaluate($withSizes));
    }

    public function test_account_destroy_retires_its_size_rows_and_the_card_price_falls_back(): void
    {
        $card = $this->card('Mascot', 0, 0);
        $mascot = $this->account('mascot');
        $ardon = $this->account('ardon');
        $this->sizeRow($card, $mascot, 'M-44', '44', 12.50);
        $this->sizeRow($card, $mascot, 'M-52', '52', 14.00);
        $otherSize = $this->sizeRow($card, $ardon, 'A-44', '44', 16.00);
        $this->slot($card, $ardon, 16.00, 18.00, null, '2026-09-20 10:00');
        $this->slot($card, $mascot, 12.50, 15.00, 14.00, '2026-09-27 10:00');
        $this->assertSame('12.50', $card->fresh()->purchase_price);

        Sanctum::actingAs($this->user);
        $this->deleteJson("/api/b2b-accounts/{$mascot->id}")->assertOk();

        $retired = ProductVariant::query()->where('source', ProductSourcePrice::b2bKey($mascot->id))->get();
        $this->assertCount(2, $retired);
        foreach ($retired as $row) {
            $this->assertNotNull($row->removed_at);
            $this->assertNull($row->b2b_account_id);
        }
        $this->assertNull($otherSize->fresh()->removed_at);
        // cena karty wraca do drugiego konta
        $this->assertSame('16.00', $card->fresh()->purchase_price);
        $this->assertSame('18.00', $card->fresh()->catalog_price_net);
    }

    private function card(string $manufacturer, float $catalog, float $purchase): Product
    {
        $this->sku++;

        return Product::query()->create([
            'sku' => 'SIZE-'.$this->sku,
            'name' => 'Spodnie robocze '.$this->sku,
            'manufacturer' => $manufacturer,
            'catalog_price_net' => $catalog,
            'discount_percent' => 0,
            'purchase_price' => $purchase,
            'currency' => 'PLN',
        ]);
    }

    private function account(string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $connector.'-konto', 'password' => 'sekret', 'sites' => ['b2b.'.$connector.'.example.test'], 'connector' => $connector,
            'created_by' => $this->user->id, 'updated_by' => $this->user->id,
        ]);
    }

    /** Slot konta jak zapis synchronizacji: najniższa cena rozmiaru, size_price_max = najwyższa (null = jedna cena). */
    private function slot(Product $card, B2bAccount $account, float $purchase, float $catalog, ?float $sizeMax, string $checkedAt = '2026-09-28 10:00'): void
    {
        $this->prices->saveSlot($card, ProductSourcePrice::b2bKey($account->id), [
            'b2b_account_id' => $account->id, 'purchase_price' => $purchase, 'catalog_price_net' => $catalog,
            'size_price_max' => $sizeMax, 'currency' => 'PLN', 'checked_at' => Carbon::parse($checkedAt),
        ]);
    }

    private function sizeRow(Product $card, B2bAccount $account, string $remoteId, string $size, float $price): ProductVariant
    {
        return ProductVariant::query()->create([
            'product_id' => $card->id,
            'kind' => ProductVariant::KIND_SIZE,
            'b2b_account_id' => $account->id,
            'source' => ProductSourcePrice::b2bKey($account->id),
            'remote_id' => $remoteId,
            'sku' => $remoteId,
            'label' => $size,
            'purchase_price' => $price,
            'currency' => 'PLN',
            'last_seen_at' => now(),
            'price_checked_at' => now(),
        ]);
    }

    private function versionRow(Product $card, string $remoteId): void
    {
        ProductVariant::query()->create([
            'product_id' => $card->id, 'kind' => ProductVariant::KIND_VERSION, 'source' => 'b2b:signproject',
            'remote_id' => $remoteId, 'label' => 'Format A4 · PCV', 'purchase_price' => 5.00, 'currency' => 'PLN',
        ]);
    }

    private function matchCard(string $sku, string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => 'Rękawice '.$manufacturer.' '.$sku, 'manufacturer' => $manufacturer,
            'catalog_price_net' => 10, 'purchase_price' => 8, 'currency' => 'PLN', 'stock' => 0,
        ]);
    }

    private function matchLink(Product $card, B2bAccount $account, string $remoteId, string $remoteSku): void
    {
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id, 'remote_id' => $remoteId, 'remote_sku' => $remoteSku, 'product_id' => $card->id,
        ]);
    }

    private function identifier(Product $card, string $source, string $position, string $code): void
    {
        ProductIdentifier::query()->create([
            'product_id' => $card->id,
            'source_key' => $source,
            'position_key' => $position,
            'type' => 'manufacturer_code',
            'value' => $code,
            'normalized' => ProductIdentifierCode::normalize('manufacturer_code', $code),
            'manufacturer' => $card->manufacturer,
            'last_seen_at' => now(),
        ]);
    }
}
