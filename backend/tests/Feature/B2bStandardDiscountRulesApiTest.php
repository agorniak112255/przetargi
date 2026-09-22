<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bDiscountRule;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\B2b\B2bConnectorRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reguły rabatu konta UVEX znaczą rabat standardowy (B2bStandardDiscountSite), nie cenę zakupu jak w Protekcie.
 * Zapis reguł przelicza od razu rabat standardowy slotów konta, żeby ocena ceny specjalnej nie czekała na nocną
 * synchronizację, a panel podpowiada nazwy kategorii dosłownie z cennika bazowego.
 */
final class B2bStandardDiscountRulesApiTest extends TestCase
{
    use RefreshDatabase;

    private B2bAccount $uvex;

    private B2bAccount $protekt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->uvex = B2bAccount::query()->create([
            'username' => 'uvex-login',
            'password' => 'uvex-haslo',
            'sites' => ['izam.system-b2b.pl'],
            'connector' => 'uvex',
        ]);
        $this->protekt = B2bAccount::query()->create([
            'username' => 'PROTEKT',
            'sites' => ['protekt.pl'],
            'connector' => 'protekt',
        ]);
    }

    public function test_rejestr_rozroznia_znaczenie_regul(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('standard', $registry->discountRulesMode('uvex'));
        $this->assertTrue($registry->usesStandardDiscounts('uvex'));
        $this->assertSame('price', $registry->discountRulesMode('protekt'));
        $this->assertFalse($registry->usesStandardDiscounts('protekt'));
        // łącznik treści (artra.pl) ceny nie pobiera, łącznik z samą ceną konta (Anro) reguł nie używa
        $this->assertNull($registry->discountRulesMode('artra'));
        $this->assertNull($registry->discountRulesMode('anro'));
        $this->assertNull($registry->discountRulesMode('nieznany'));
        $this->assertNull($registry->discountRulesMode(null));

        $options = collect($registry->options())->keyBy('key');
        $this->assertTrue($options['uvex']['uses_discount_rules']);
        $this->assertSame('standard', $options['uvex']['discount_rules_mode']);
        $this->assertTrue($options['protekt']['uses_discount_rules']);
        $this->assertSame('price', $options['protekt']['discount_rules_mode']);
        $this->assertFalse($options['artra']['uses_discount_rules']);
        $this->assertNull($options['artra']['discount_rules_mode']);
        $this->assertFalse($options['anro']['uses_discount_rules']);
        $this->assertNull($options['anro']['discount_rules_mode']);
    }

    public function test_lista_lacznikow_w_api_ma_tryb_regul(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $options = collect($this->getJson('/api/b2b-connectors')->assertOk()->json())->keyBy('key');
        $this->assertSame('standard', $options['uvex']['discount_rules_mode']);
        $this->assertSame('price', $options['protekt']['discount_rules_mode']);
    }

    public function test_konto_bez_zapisanego_lacznika_rozpoznaje_tryb_po_witrynie(): void
    {
        $registry = app(B2bConnectorRegistry::class);
        $legacy = new B2bAccount(['sites' => ['https://izam.system-b2b.pl/'], 'connector' => null]);

        $this->assertSame('uvex', $registry->keyForAccount($legacy));
    }

    public function test_odczyt_regul_uvex_podaje_tryb_i_kategorie_z_cennika(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->slot($this->product('9160-120'), $this->uvex, ['base_price_category' => 'Okulary ochronne']);
        $this->slot($this->product('9160-121'), $this->uvex, ['base_price_category' => 'Okulary ochronne']);
        $this->slot($this->product('60598'), $this->uvex, ['base_price_category' => 'Rękawice']);
        // bez wiersza w cenniku bazowym — nie jest kategorią
        $this->slot($this->product('X-1'), $this->uvex, []);
        // kategoria innego konta nie może się podpowiadać przy UVEX
        $this->slot($this->product('P-1'), $this->protekt, ['base_price_category' => 'Amortyzatory']);

        $this->getJson("/api/b2b-accounts/{$this->uvex->id}/discount-rules")
            ->assertOk()
            ->assertJsonPath('mode', 'standard')
            ->assertJsonCount(2, 'categories')
            ->assertJsonPath('categories.0', ['name' => 'Okulary ochronne', 'product_count' => 2])
            ->assertJsonPath('categories.1', ['name' => 'Rękawice', 'product_count' => 1]);

        $this->getJson("/api/b2b-accounts/{$this->protekt->id}/discount-rules")
            ->assertOk()
            ->assertJsonPath('mode', 'price')
            ->assertJsonMissingPath('categories');
    }

    public function test_zapis_regul_przelicza_rabat_standardowy_slotow_konta(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $glasses = $this->slot($this->product('9160-120'), $this->uvex, [
            'base_price_category' => 'Okulary ochronne',
            'standard_discount_percent' => null,
        ]);
        // kod karty łapie osobna reguła przed kategorią — ta sama kolejność co w synchronizacji
        $byCode = $this->slot($this->product('9160-999'), $this->uvex, [
            'base_price_category' => 'Okulary ochronne',
            'standard_discount_percent' => 15,
        ]);
        // reguła „Rękawice” znika — slot ma stracić ocenę, a nie zostać przy starej stawce
        $gloves = $this->slot($this->product('60598'), $this->uvex, [
            'base_price_category' => 'Rękawice',
            'standard_discount_percent' => 20,
        ]);
        // bez kategorii cennika — nie ruszamy (brak wejścia dla reguł kategorii)
        $noBase = $this->slot($this->product('X-1'), $this->uvex, ['standard_discount_percent' => 30]);
        $protektSlot = $this->slot($this->product('P-1'), $this->protekt, [
            'base_price_category' => 'Okulary ochronne',
            'standard_discount_percent' => 5,
        ]);

        $counted = B2bDiscountRule::query()->create([
            'b2b_account_id' => $this->uvex->id,
            'position' => 0,
            'name' => 'Okulary',
            'match_field' => 'category',
            'match_type' => 'equals',
            'pattern' => 'Okulary ochronne',
            'discount_percent' => 10,
        ]);
        $counted->forceFill(['last_matched_count' => 137, 'last_matched_at' => now()->subDay()])->saveQuietly();

        $this->putJson("/api/b2b-accounts/{$this->uvex->id}/discount-rules", [
            'rules' => [
                ['name' => 'Seria 9160-9', 'match_field' => 'catalog_no', 'match_type' => 'prefix', 'pattern' => '9160-9', 'discount_percent' => 25],
                ['name' => 'Okulary', 'match_field' => 'category', 'match_type' => 'equals', 'pattern' => 'okulary ochronne', 'discount_percent' => 15],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('mode', 'standard')
            ->assertJsonPath('recomputed', 3)
            // liczniki opisują ostatnią synchronizację — zapis reguł ich nie nadpisuje trafieniami z przeliczenia
            ->assertJsonPath('rules.0.last_matched_count', 0)
            ->assertJsonPath('rules.0.last_matched_at', null)
            ->assertJsonPath('rules.1.last_matched_count', 137);

        $this->assertSame('15.00', $glasses->fresh()->standard_discount_percent);
        $this->assertSame('25.00', $byCode->fresh()->standard_discount_percent);
        $this->assertNull($gloves->fresh()->standard_discount_percent);
        $this->assertSame('30.00', $noBase->fresh()->standard_discount_percent);
        $this->assertSame('5.00', $protektSlot->fresh()->standard_discount_percent);
        // cena konta zostaje — zmienia się tylko ocena
        $this->assertSame('200.00', $glasses->fresh()->purchase_price);

        // ten sam zapis drugi raz niczego nie zmienia
        $this->putJson("/api/b2b-accounts/{$this->uvex->id}/discount-rules", [
            'rules' => [
                ['name' => 'Seria 9160-9', 'match_field' => 'catalog_no', 'match_type' => 'prefix', 'pattern' => '9160-9', 'discount_percent' => 25],
                ['name' => 'Okulary', 'match_field' => 'category', 'match_type' => 'equals', 'pattern' => 'okulary ochronne', 'discount_percent' => 15],
            ],
        ])->assertOk()->assertJsonPath('recomputed', 0);
    }

    public function test_pusta_lista_regul_zdejmuje_ocene(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $slot = $this->slot($this->product('9160-120'), $this->uvex, [
            'base_price_category' => 'Okulary ochronne',
            'standard_discount_percent' => 15,
        ]);

        $this->putJson("/api/b2b-accounts/{$this->uvex->id}/discount-rules", ['rules' => []])
            ->assertOk()
            ->assertJsonPath('recomputed', 1);

        $this->assertNull($slot->fresh()->standard_discount_percent);
    }

    public function test_zapis_regul_protekt_nie_przelicza_slotow(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $slot = $this->slot($this->product('P-1'), $this->protekt, [
            'base_price_category' => 'Okulary ochronne',
            'standard_discount_percent' => 5,
        ]);

        $this->putJson("/api/b2b-accounts/{$this->protekt->id}/discount-rules", [
            'rules' => [
                ['name' => 'Okulary', 'match_field' => 'category', 'match_type' => 'equals', 'pattern' => 'Okulary ochronne', 'discount_percent' => 40],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('mode', 'price')
            ->assertJsonMissingPath('recomputed');

        $this->assertSame('5.00', $slot->fresh()->standard_discount_percent);
    }

    private function product(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Okulary '.$sku,
            'manufacturer' => 'UVEX',
            'catalog_price_net' => 200,
            'purchase_price' => 200,
            'stock' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function slot(Product $product, B2bAccount $account, array $values): ProductSourcePrice
    {
        return ProductSourcePrice::query()->create([
            'product_id' => $product->id,
            'source_key' => ProductSourcePrice::b2bKey((int) $account->id),
            'b2b_account_id' => $account->id,
            'catalog_price_net' => 200,
            'purchase_price' => 200,
            'currency' => 'PLN',
            'base_price_net' => array_key_exists('base_price_category', $values) ? 255.31 : null,
            ...$values,
        ]);
    }
}
