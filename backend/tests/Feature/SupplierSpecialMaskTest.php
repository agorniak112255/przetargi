<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Services\Pricing\MaskedPrice;
use App\Services\Pricing\SupplierSpecialMask;
use App\Support\SupplierSpecialPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\Support\SupplierSpecialFixture;
use Tests\TestCase;

/**
 * Maska ceny specjalnej B2B (prices.supplier_special.view): kto widzi prawdziwą cenę konta, jak wygląda widok
 * standardowy (cennik bazowy − rabat standardowy) i że maska nigdy nie zmienia ani nie zapisuje modeli wejściowych.
 */
final class SupplierSpecialMaskTest extends TestCase
{
    use RefreshDatabase;
    use SupplierSpecialFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->setUpSupplierSpecial();
    }

    public function test_for_user_reveals_only_with_permission(): void
    {
        $this->assertFalse(SupplierSpecialMask::forUser($this->userWithRole('admin'))->hides());
        $this->assertFalse(SupplierSpecialMask::forUser($this->userWithRole('dyrektor'))->hides());
        $this->assertTrue(SupplierSpecialMask::forUser($this->userWithRole('handlowiec'))->hides());
        $this->assertTrue(SupplierSpecialMask::forUser($this->userWithRole('przetargi'))->hides());
        $this->assertTrue(SupplierSpecialMask::forUser($this->userWithRole('kierownik'))->hides());
        $this->assertFalse(SupplierSpecialMask::forUser(
            $this->userWithCustomRole('zakupy', ['products.view', SupplierSpecialMask::PERMISSION]),
        )->hides());
        $this->assertTrue(SupplierSpecialMask::forUser($this->userWithCustomRole('magazyn', ['products.view']))->hides());
        // brak tożsamości nie odsłania ceny
        $this->assertTrue(SupplierSpecialMask::forUser(null)->hides());
        $this->assertTrue(SupplierSpecialMask::hiding()->hides());
        $this->assertFalse(SupplierSpecialMask::revealing()->hides());
    }

    public function test_revealing_changes_nothing_and_asks_nothing(): void
    {
        $fixture = $this->supplierSpecialCard();
        $mask = SupplierSpecialMask::revealing();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $mask->preload([$fixture['product']->id]);
        $this->assertNull($mask->card($fixture['product']->id));
        $this->assertNull($mask->slot($fixture['slot']));
        $this->assertNull($mask->variant($fixture['product']->id, $fixture['account']->id));
        $this->assertFalse($mask->hidesHistory($fixture['product']->id, null));
        $this->assertSame($fixture['product'], $mask->maskProduct($fixture['product']));
        $this->assertSame($fixture['slot'], $mask->maskSlot($fixture['slot']));
        $this->assertSame($fixture['variants'][1], $mask->maskVariant($fixture['variants'][1]));
        $row = ['id' => $fixture['product']->id, 'purchase_price' => '173.19'];
        $this->assertSame($row, $mask->productRow($row));
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_card_masks_special_price_shown_on_card(): void
    {
        $fixture = $this->supplierSpecialCard();
        $masked = SupplierSpecialMask::hiding()->card($fixture['product']->id);

        $this->assertInstanceOf(MaskedPrice::class, $masked);
        $this->assertSame(ProductSourcePrice::b2bKey((int) $fixture['account']->id), $masked->sourceKey);
        $this->assertSame((int) $fixture['account']->id, $masked->accountId);
        $this->assertSame('PLN', $masked->currency);
        $this->assertSame(173.19, $masked->realPurchase);
        $this->assertSame(211.37, $masked->standardPrice);
        $this->assertEqualsWithDelta(211.37 / 173.19, $masked->ratio, 1e-9);
        // ocena ceny standardowej: nic nie zdradza, że konto ma rabat większy niż standardowy
        $this->assertSame(SupplierSpecialPrice::STANDARD, $masked->evaluation['status']);
        $this->assertSame(211.37, $masked->evaluation['standard_price']);
        $this->assertEquals(0, $masked->evaluation['saving_net']);
        $this->assertEquals(15, $masked->evaluation['actual_discount_percent']);
        $this->assertEquals(15, $masked->evaluation['standard_discount_percent']);
        $this->assertSame(248.67, $masked->evaluation['base_price']);
        $this->assertSame('Rękawice ochronne', $masked->evaluation['category']);
        $this->assertNoSpecialLeak((string) json_encode($masked->evaluation));
    }

    public function test_card_without_special_price_is_not_masked(): void
    {
        // 211,00 mieści się w tolerancji (standard), 230 jest gorsze niż standard
        $standard = $this->card('STD', 211.00);
        $this->uvexSlot($standard, 211.00);
        $worse = $this->card('WORSE', 230);
        $this->uvexSlot($worse, 230);
        $mask = SupplierSpecialMask::hiding();

        $this->assertNull($mask->card($standard->id));
        $this->assertNull($mask->card($worse->id));
        $this->assertSame($standard, $mask->maskProduct($standard));
        $this->assertNull($mask->card(999999));
    }

    public function test_special_slot_at_other_price_than_card_masks_only_the_slot(): void
    {
        // cenę karty (200) ustala inne źródło — karta bez maski, slot UVEX dalej w widoku standardowym
        $product = $this->card('OTHER', 200);
        $slot = $this->uvexSlot($product, 173.19, ['size_price_max' => '190.00', 'carton_price_net' => '150.00']);
        $mask = SupplierSpecialMask::hiding();

        $this->assertNull($mask->card($product->id));
        $maskedSlot = $mask->maskSlot($slot);
        $this->assertNotSame($slot, $maskedSlot);
        $this->assertTrue($maskedSlot->priceMasked);
        $this->assertSame('211.37', $maskedSlot->purchase_price);
        $this->assertSame('211.37', $maskedSlot->catalog_price_net);
        $this->assertSame('0.00', $maskedSlot->discount_percent);
        $this->assertSame('231.89', $maskedSlot->size_price_max);
        // druga cena konta (przy pełnym kartonie) w tej samej proporcji co cena konta
        $this->assertSame('183.07', $maskedSlot->carton_price_net);
        $this->assertSame(SupplierSpecialPrice::STANDARD, SupplierSpecialPrice::forSlot($maskedSlot)['status'] ?? null);
        // oryginał nietknięty
        $this->assertSame('173.19', $slot->purchase_price);
        $this->assertSame('190.00', $slot->size_price_max);
        $this->assertFalse($slot->isDirty());
    }

    public function test_slot_loaded_without_evaluation_columns_is_still_masked(): void
    {
        $fixture = $this->supplierSpecialCard();
        $partial = ProductSourcePrice::query()
            ->whereKey($fixture['slot']->id)
            ->first(['id', 'product_id', 'source_key', 'b2b_account_id', 'purchase_price', 'catalog_price_net', 'size_price_max', 'currency']);

        $masked = SupplierSpecialMask::hiding()->maskSlot($partial);

        $this->assertSame('211.37', $masked->purchase_price);
        $this->assertSame('231.89', $masked->size_price_max);
    }

    public function test_currency_rules_match_the_card(): void
    {
        $euro = $this->card('EUR', 173.19, 'EUR');
        $this->uvexSlot($euro, 173.19);
        $inherits = $this->card('INHERIT', 173.19);
        $this->uvexSlot($inherits, 173.19, ['currency' => null]);
        $mask = SupplierSpecialMask::hiding();

        // ta sama liczba w innej walucie to inna cena
        $this->assertNull($mask->card($euro->id));
        // slot bez waluty ma walutę karty
        $this->assertSame('PLN', $mask->card($inherits->id)?->currency);
    }

    public function test_card_is_twin_of_where_card_status_special(): void
    {
        $special = $this->card('P-SPEC', 173.19);
        $this->uvexSlot($special, 173.19);
        // tuż za tolerancją (211,37 − 1,06)
        $edge = $this->card('P-EDGE', 210.25);
        $this->uvexSlot($edge, 210.25);
        $inTolerance = $this->card('P-TOL', 210.40);
        $this->uvexSlot($inTolerance, 210.40);
        $worse = $this->card('P-WORSE', 230);
        $this->uvexSlot($worse, 230);
        $shadowed = $this->card('P-SHADOW', 180);
        $this->uvexSlot($shadowed, 150);
        $foreign = $this->card('P-EUR', 173.19, 'EUR');
        $this->uvexSlot($foreign, 173.19);
        $inherit = $this->card('P-INHERIT', 173.19, 'EUR');
        $this->uvexSlot($inherit, 173.19, ['currency' => null]);
        $noRule = $this->card('P-NORULE', 150);
        $this->uvexSlot($noRule, 150, ['standard_discount_percent' => null]);
        // cena 0 to brak ceny — nie ma czego oceniać
        $zero = $this->card('P-ZERO', 0);
        $this->uvexSlot($zero, 0);
        $rounded = $this->card('P-ROUND', 173.19);
        $this->uvexSlot($rounded, 173.19, ['purchase_price' => '173.19']);
        $this->card('P-NONE', 100);

        $query = Product::query();
        SupplierSpecialPrice::whereCardStatus($query, SupplierSpecialPrice::SPECIAL);
        $sql = $query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all();

        $mask = SupplierSpecialMask::hiding();
        $ids = Product::query()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $mask->preload($ids);
        $php = collect($ids)->filter(static fn (int $id): bool => $mask->card($id) !== null)->sort()->values()->all();

        $this->assertSame($sql, $php);
        $this->assertEqualsCanonicalizing(
            [$special->id, $edge->id, $inherit->id, $rounded->id],
            $php,
        );
    }

    public function test_fields_uvex_and_catalog_other_than_purchase(): void
    {
        $masked = SupplierSpecialMask::hiding()->card($this->supplierSpecialCard()['product']->id);

        // UVEX: katalogowa = cena konta, rabat 0 → wszystko w cenie standardowej
        $this->assertSame(
            ['catalog_price_net' => '211.37', 'purchase_price' => '211.37', 'discount_percent' => '0.00'],
            $masked->fields('173.19', '173.19', '0.00'),
        );
        // pusta katalogowa też nie może zostać pusta obok ceny standardowej
        $this->assertSame('211.37', $masked->fields(null, '173.19', null)['catalog_price_net']);
        // katalogowa z cennika dostawcy zostaje, rabat liczony od niej na nowo
        $this->assertSame(
            ['catalog_price_net' => '300.00', 'purchase_price' => '211.37', 'discount_percent' => '29.54'],
            $masked->fields('300.00', '173.19', '42.27'),
        );
        $this->assertNull($masked->scale(null));
        $this->assertSame('231.89', $masked->scale('190.00'));
        $this->assertSame('211.37', $masked->scale(173.19));
    }

    public function test_mask_product_returns_protected_clone_with_standard_price(): void
    {
        $product = $this->supplierSpecialCard()['product']->fresh();
        $mask = SupplierSpecialMask::hiding();

        $masked = $mask->maskProduct($product);

        $this->assertNotSame($product, $masked);
        $this->assertTrue($masked->priceMasked);
        $this->assertFalse($product->priceMasked);
        $this->assertSame('211.37', $masked->purchase_price);
        $this->assertSame('211.37', $masked->catalog_price_net);
        $this->assertSame('0.00', $masked->discount_percent);
        $this->assertSame(211.37, $mask->purchasePln($product));
        $this->assertNoSpecialLeak((string) json_encode($masked->toArray()));
        // oryginał bez zmian i bez „dirty”
        $this->assertSame('173.19', $product->purchase_price);
        $this->assertFalse($product->isDirty());

        try {
            $masked->save();
            $this->fail('Zapis maskowanej karty powinien rzucić wyjątek');
        } catch (LogicException $e) {
            $this->assertSame('Maskowana kopia ceny nie może być zapisana', $e->getMessage());
        }
        $this->expectException(LogicException::class);
        try {
            $masked->delete();
        } finally {
            $this->assertSame('173.19', Product::query()->find($product->id)?->purchase_price);
        }
    }

    public function test_masked_slot_and_variant_cannot_be_saved(): void
    {
        $fixture = $this->supplierSpecialCard();
        $mask = SupplierSpecialMask::hiding();

        $slot = $mask->maskSlot($fixture['slot']);
        $variant = $mask->maskVariant($fixture['variants'][1]);
        foreach ([$slot, $variant] as $model) {
            foreach (['save', 'delete'] as $method) {
                try {
                    $model->{$method}();
                    $this->fail($method.' na maskowanej kopii powinien rzucić wyjątek');
                } catch (LogicException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        $this->assertSame('173.19', ProductSourcePrice::query()->find($fixture['slot']->id)?->purchase_price);
        $this->assertSame('190.00', ProductVariant::query()->find($fixture['variants'][1]->id)?->purchase_price);
        $this->assertFalse($fixture['slot']->isDirty());
        $this->assertFalse($fixture['variants'][1]->isDirty());
    }

    public function test_clone_does_not_share_relations_with_original(): void
    {
        $fixture = $this->supplierSpecialCard();
        $slot = ProductSourcePrice::query()->with('account')->find($fixture['slot']->id);

        $masked = SupplierSpecialMask::hiding()->maskSlot($slot);
        $masked->account->setAttribute('username', 'zmienione');

        $this->assertSame('uvex-login', $slot->account->username);
        $this->assertFalse($slot->account->isDirty());
    }

    public function test_variants_are_scaled_only_for_the_special_account(): void
    {
        $fixture = $this->supplierSpecialCard();
        $other = $this->otherAccount();
        $foreignVariant = ProductVariant::query()->create([
            'product_id' => $fixture['product']->id,
            'kind' => ProductVariant::KIND_SIZE,
            'b2b_account_id' => $other->id,
            'source' => ProductSourcePrice::b2bKey((int) $other->id),
            'remote_id' => 'ANRO-M',
            'label' => 'M',
            'purchase_price' => '180.00',
            'currency' => 'PLN',
        ]);
        $mask = SupplierSpecialMask::hiding();

        $small = $mask->maskVariant($fixture['variants'][0]);
        $large = $mask->maskVariant($fixture['variants'][1]);
        $this->assertSame('211.37', $small->purchase_price);
        $this->assertSame('231.89', $large->purchase_price);
        $this->assertTrue($large->priceMasked);
        $this->assertSame('190.00', $fixture['variants'][1]->purchase_price);
        // rozmiar innego konta (bez ceny specjalnej) bez zmian — ta sama instancja
        $this->assertSame($foreignVariant, $mask->maskVariant($foreignVariant));
        $this->assertNull($mask->variant($fixture['product']->id, null));

        $row = $mask->variantRow([
            'product_id' => $fixture['product']->id,
            'b2b_account_id' => $fixture['account']->id,
            'purchase_price' => '190.00',
            'purchase_price_pln' => 190.0,
            'currency' => 'PLN',
        ]);
        $this->assertSame('231.89', $row['purchase_price']);
        $this->assertSame(231.89, $row['purchase_price_pln']);
        $this->assertNoSpecialLeak((string) json_encode($row));
        $otherRow = ['product_id' => $fixture['product']->id, 'b2b_account_id' => $other->id, 'purchase_price' => '180.00'];
        $this->assertSame($otherRow, $mask->variantRow($otherRow));
    }

    public function test_product_row_replaces_only_present_keys(): void
    {
        $fixture = $this->supplierSpecialCard();
        $mask = SupplierSpecialMask::hiding();
        $evaluation = SupplierSpecialPrice::evaluate(173.19, 248.67, 15);

        $row = $mask->productRow([
            'id' => $fixture['product']->id,
            'sku' => '60148-UVEX',
            'catalog_price_net' => '173.19',
            'purchase_price' => '173.19',
            'discount_percent' => '0.00',
            'currency' => 'PLN',
            'price_pln' => 173.19,
            'purchase_price_pln' => 173.19,
            'supplier_special' => [...$evaluation, 'category' => 'Rękawice ochronne'],
        ]);

        $this->assertSame('211.37', $row['purchase_price']);
        $this->assertSame('211.37', $row['catalog_price_net']);
        $this->assertSame('0.00', $row['discount_percent']);
        $this->assertSame(211.37, $row['price_pln']);
        $this->assertSame(211.37, $row['purchase_price_pln']);
        $this->assertSame(SupplierSpecialPrice::STANDARD, $row['supplier_special']['status']);
        $this->assertNoSpecialLeak((string) json_encode($row));

        $partial = $mask->productRow(['id' => $fixture['product']->id, 'purchase_price' => '173.19']);
        $this->assertSame(['id' => $fixture['product']->id, 'purchase_price' => '211.37'], $partial);
        // znacznik innej oceny (nie „special”) zostaje
        $plain = $mask->productRow(['id' => $fixture['product']->id, 'supplier_special' => null]);
        $this->assertNull($plain['supplier_special']);
    }

    public function test_hides_history_for_any_evaluable_slot_of_the_account(): void
    {
        $fixture = $this->supplierSpecialCard();
        // slot z oceną, dziś w cenie standardowej — dawniejsze ceny mogły być specjalne
        $standard = $this->card('HIST-STD', 211.37);
        $this->uvexSlot($standard, 211.37);
        $other = $this->otherAccount();
        $plain = $this->card('HIST-PLAIN', 50);
        ProductSourcePrice::query()->create([
            'product_id' => $plain->id,
            'source_key' => ProductSourcePrice::b2bKey((int) $other->id),
            'b2b_account_id' => $other->id,
            'catalog_price_net' => 60,
            'purchase_price' => 50,
            'currency' => 'PLN',
        ]);
        $mask = SupplierSpecialMask::hiding();
        $uvexId = (int) $fixture['account']->id;

        $this->assertTrue($mask->hidesHistory($fixture['product']->id, $uvexId));
        $this->assertTrue($mask->hidesHistory($fixture['product']->id, null));
        $this->assertFalse($mask->hidesHistory($fixture['product']->id, (int) $other->id));
        $this->assertTrue($mask->hidesHistory($standard->id, $uvexId));
        $this->assertNull($mask->card($standard->id));
        $this->assertFalse($mask->hidesHistory($plain->id, null));
        $this->assertFalse($mask->hidesHistory($plain->id, (int) $other->id));
        $this->assertFalse(SupplierSpecialMask::revealing()->hidesHistory($fixture['product']->id, $uvexId));
    }

    public function test_preload_budget_two_queries_per_thousand_cards(): void
    {
        $fixture = $this->supplierSpecialCard();
        $productId = (int) $fixture['product']->id;
        $accountId = (int) $fixture['account']->id;
        $mask = SupplierSpecialMask::hiding();

        DB::flushQueryLog();
        DB::enableQueryLog();
        // 1001 kart = dwie paczki po 1000
        $mask->preload([$fixture['product'], ...range($productId + 1, $productId + 1000)]);
        $this->assertCount(4, DB::getQueryLog());

        DB::flushQueryLog();
        $this->assertNotNull($mask->card($productId));
        $this->assertNotNull($mask->variant($productId, $accountId));
        $this->assertTrue($mask->hidesHistory($productId, $accountId));
        $mask->maskProduct($fixture['product']);
        $mask->maskSlot($fixture['slot']);
        $mask->maskVariant($fixture['variants'][1]);
        $mask->preload([$productId]);
        $this->assertCount(0, DB::getQueryLog());

        // brakująca karta doczytywana sama — dwa zapytania, potem z pamięci
        $fresh = SupplierSpecialMask::hiding();
        DB::flushQueryLog();
        $this->assertNotNull($fresh->card($productId));
        $this->assertNotNull($fresh->variant($productId, $accountId));
        $this->assertCount(2, DB::getQueryLog());
        DB::disableQueryLog();
    }

    private function card(string $sku, float $purchase, string $currency = 'PLN'): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice UVEX '.$sku,
            'manufacturer' => 'UVEX',
            'catalog_price_net' => $purchase,
            'purchase_price' => $purchase,
            'currency' => $currency,
            'stock' => 1,
        ]);
    }
}
