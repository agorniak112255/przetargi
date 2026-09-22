<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bDiscountRule;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\B2b\B2bBasePrice;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bStandardDiscountSite;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
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

    public function test_odczyt_regul_uvex_podaje_rabaty_standardowe_dostawcy_jako_propozycje(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $defaults = collect($this->getJson("/api/b2b-accounts/{$this->uvex->id}/discount-rules")
            ->assertOk()
            ->assertJsonCount(0, 'rules')
            ->json('defaults'))->keyBy('pattern');

        // wiadomość dostawcy 22.09.2026 + HexArmor 30% od użytkownika; odzieży brak (arkusz pominięty)
        $this->assertEquals(35, $defaults['Ochrona wzroku']['discount_percent']);
        $this->assertEquals(30, $defaults['Rękawice HEXArmor']['discount_percent']);
        $this->assertEquals(15, $defaults['Buty Heckel']['discount_percent']);
        $this->assertSame(['category', 'equals'], [$defaults['Hełmy']['match_field'], $defaults['Hełmy']['match_type']]);
        $this->assertArrayNotHasKey('Odzież', $defaults->all());
        // propozycja nie jest zapisem
        $this->assertSame(0, B2bDiscountRule::query()->where('b2b_account_id', $this->uvex->id)->count());

        $this->getJson("/api/b2b-accounts/{$this->protekt->id}/discount-rules")->assertOk()->assertJsonMissingPath('defaults');
    }

    public function test_pobranie_arkuszy_z_cennika_podpowiada_je_przed_pierwsza_synchronizacja(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $fake = new FakeStandardDiscountConnector(['Hełmy', 'Ochrona wzroku']);
        $this->useConnector($fake);
        $this->slot($this->product('9772.332'), $this->uvex, ['base_price_category' => 'Hełmy']);

        $this->postJson("/api/b2b-accounts/{$this->uvex->id}/discount-rules/base-categories")
            ->assertOk()
            ->assertJsonPath('categories', [
                ['name' => 'Hełmy', 'product_count' => 1],
                ['name' => 'Ochrona wzroku', 'product_count' => 0],
            ]);
        $this->assertSame(1, $fake->calls);

        // zapamiętane — zwykły odczyt reguł podpowiada je bez ponownego logowania u dostawcy
        $this->getJson("/api/b2b-accounts/{$this->uvex->id}/discount-rules")
            ->assertOk()
            ->assertJsonPath('categories.1', ['name' => 'Ochrona wzroku', 'product_count' => 0]);
        $this->assertSame(1, $fake->calls);
    }

    public function test_literowka_w_nazwie_arkusza_odrzuca_zapis(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->useConnector(new FakeStandardDiscountConnector(['Hełmy', 'Ochrona wzroku']));
        $this->postJson("/api/b2b-accounts/{$this->uvex->id}/discount-rules/base-categories")->assertOk();

        $this->putJson("/api/b2b-accounts/{$this->uvex->id}/discount-rules", [
            'rules' => [
                ['name' => 'Hełmy', 'match_field' => 'category', 'match_type' => 'equals', 'pattern' => 'hełmy', 'discount_percent' => 30],
                ['name' => 'Wzrok', 'match_field' => 'category', 'match_type' => 'equals', 'pattern' => 'Ochrona wzroki', 'discount_percent' => 35],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rules.1.pattern']);
        $this->assertSame(0, B2bDiscountRule::query()->where('b2b_account_id', $this->uvex->id)->count());

        // wielkość liter bez znaczenia, „zaczyna się od” trafiające w arkusz też przechodzi
        $this->putJson("/api/b2b-accounts/{$this->uvex->id}/discount-rules", [
            'rules' => [
                ['name' => 'Hełmy', 'match_field' => 'category', 'match_type' => 'equals', 'pattern' => 'hełmy', 'discount_percent' => 30],
                ['name' => 'Wzrok', 'match_field' => 'category', 'match_type' => 'prefix', 'pattern' => 'Ochrona w', 'discount_percent' => 35],
            ],
        ])->assertOk();
    }

    public function test_bez_znanych_arkuszy_zapis_nie_jest_blokowany(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson("/api/b2b-accounts/{$this->uvex->id}/discount-rules", [
            'rules' => [
                ['name' => 'Hełmy', 'match_field' => 'category', 'match_type' => 'equals', 'pattern' => 'Hełmy', 'discount_percent' => 30],
            ],
        ])->assertOk();
    }

    public function test_arkuszy_nie_pobieramy_w_trakcie_synchronizacji_ani_bez_uprawnien(): void
    {
        $fake = new FakeStandardDiscountConnector(['Hełmy']);
        $this->useConnector($fake);

        Sanctum::actingAs(User::factory()->withRole('kierownik')->create());
        $this->postJson("/api/b2b-accounts/{$this->uvex->id}/discount-rules/base-categories")->assertForbidden();

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        B2bSyncRun::query()->create([
            'b2b_account_id' => $this->uvex->id,
            'status' => B2bSyncRun::STATUS_RUNNING,
            'trigger' => 'manual',
            'started_at' => now(),
        ]);
        $this->postJson("/api/b2b-accounts/{$this->uvex->id}/discount-rules/base-categories")->assertStatus(409);
        $this->assertSame(0, $fake->calls);
    }

    public function test_blad_pobrania_cennika_zwraca_powod(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->useConnector(new FakeStandardDiscountConnector(null));

        $this->postJson("/api/b2b-accounts/{$this->uvex->id}/discount-rules/base-categories")
            ->assertStatus(502)
            ->assertJsonPath('message', 'brak odnośnika')
            ->assertJsonPath('categories', []);
    }

    private function useConnector(B2bConnector $connector): void
    {
        $this->app->instance(B2bConnectorRegistry::class, new class($connector) extends B2bConnectorRegistry
        {
            public function __construct(private readonly B2bConnector $fake) {}

            public function make(B2bAccount $account, int $delayMs = 150): B2bConnector
            {
                return $this->fake;
            }
        });
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

/** Łącznik z cennikiem bazowym bez sieci: arkusze z konstruktora, null = błąd pobrania. */
final class FakeStandardDiscountConnector implements B2bConnector, B2bStandardDiscountSite
{
    public int $calls = 0;

    /**
     * @param  list<string>|null  $sheets
     */
    public function __construct(private readonly ?array $sheets) {}

    public static function key(): string
    {
        return 'uvex';
    }

    public static function label(): string
    {
        return 'UVEX';
    }

    public static function host(): string
    {
        return 'izam.system-b2b.pl';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self([]);
    }

    public function login(): void {}

    public function products(): iterable
    {
        return [];
    }

    public function totalProducts(): int
    {
        return 0;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'UVEX';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return null;
    }

    public function description(B2bRemoteProduct $product): string
    {
        return '';
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }

    public function basePrice(B2bRemoteProduct $product): ?B2bBasePrice
    {
        return null;
    }

    public function basePriceListLoaded(): bool
    {
        return false;
    }

    public function basePriceCategories(): array
    {
        $this->calls++;
        if ($this->sheets === null) {
            throw new RuntimeException('brak odnośnika');
        }

        return $this->sheets;
    }

    public static function defaultStandardDiscounts(): array
    {
        return [['category' => 'Hełmy', 'discount_percent' => 30.0]];
    }
}
