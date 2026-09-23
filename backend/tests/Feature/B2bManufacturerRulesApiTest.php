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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Okno „Producenci” przy koncie B2B (23.09.2026): lista producentów kart konta ze znacznikami „cena” i „opis”,
 * zapis tylko wyłączeń i od razu przeliczona cena obowiązująca kart producenta, któremu zmieniono znacznik ceny.
 */
final class B2bManufacturerRulesApiTest extends TestCase
{
    use RefreshDatabase;

    private B2bAccount $rawpol;

    private B2bAccount $tegro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->rawpol = $this->account('rawpol', 'b2b.raw-pol.pl');
        $this->tegro = $this->account('tegro', 'b2b.tegro.pl');
    }

    public function test_lista_producentow_z_liczba_kart_i_cennikiem_producenta(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $bolle = $this->account('bolle', 'b2b.bolle.com');

        // dwa brzmienia tego samego producenta — jeden wiersz, nazwa = najczęstsze brzmienie
        foreach (['B-1', 'B-2'] as $sku) {
            $this->link($this->rawpol, $this->card($sku, 'Bolle Safety'), 'Bolle');
        }
        $this->link($this->rawpol, $this->card('B-3', 'Bolle'), 'BOLLE');
        // stary zapis bez producenta w powiązaniu — producent z karty; karta z dwoma powiązaniami liczy się raz
        $ansell = $this->card('A-1', 'Ansell');
        $this->link($this->rawpol, $ansell, null, 'a-1-rozm-7');
        $this->link($this->rawpol, $ansell, null, 'a-1-rozm-8');
        // pusty producent pomijany
        $this->link($this->rawpol, $this->card('X-1', ''), null);
        // karta innego konta nie trafia na listę
        $this->link($this->tegro, $this->card('T-1', 'Tegera'), 'Tegera');
        // reguła producenta, którego kart konto już nie ma — zostaje na liście z liczbą 0
        B2bAccountManufacturerRule::query()->create([
            'b2b_account_id' => $this->rawpol->id,
            'manufacturer' => 'Uvex',
            'manufacturer_key' => 'uvex',
            'take_price' => false,
            'take_description' => true,
        ]);
        // cennik producenta z pliku: tylko cennik, z którego karty mają cenę (slot „file”)
        $list = PriceList::query()->create(['manufacturer' => 'Ansell', 'version' => '2026-09']);
        $this->slot($ansell, ProductSourcePrice::SOURCE_FILE, 10.0, ['price_list_id' => $list->id]);
        PriceList::query()->create(['manufacturer' => 'Uvex', 'version' => 'bez kart']);

        $this->getJson("/api/b2b-accounts/{$this->rawpol->id}/manufacturers")
            ->assertOk()
            ->assertJsonPath('uses_price_rules', true)
            ->assertJsonPath('sync_running', false)
            ->assertJsonCount(3, 'manufacturers')
            ->assertJsonPath('manufacturers.0', [
                'manufacturer' => 'Bolle',
                'key' => 'bolle',
                'cards' => 3,
                'take_price' => true,
                'take_description' => true,
                'has_rule' => false,
                'own_source' => 'cennik producenta: konto Bolle (#'.$bolle->id.')',
            ])
            ->assertJsonPath('manufacturers.1.manufacturer', 'Ansell')
            ->assertJsonPath('manufacturers.1.cards', 1)
            ->assertJsonPath('manufacturers.1.own_source', 'cennik producenta z pliku: Ansell 2026-09')
            ->assertJsonPath('manufacturers.2.manufacturer', 'Uvex')
            ->assertJsonPath('manufacturers.2.cards', 0)
            ->assertJsonPath('manufacturers.2.take_price', false)
            ->assertJsonPath('manufacturers.2.has_rule', true)
            ->assertJsonPath('manufacturers.2.own_source', null);

        // konto producenta widzi przy swojej marce „to cennik producenta”
        $this->link($bolle, $this->card('B-9', 'Bolle'), 'Bolle');
        $this->rawpol->forceFill(['sync_requested_at' => now()])->save();
        $this->getJson("/api/b2b-accounts/{$bolle->id}/manufacturers")
            ->assertOk()
            ->assertJsonPath('manufacturers.0.own_source', 'to cennik producenta');
        $this->getJson("/api/b2b-accounts/{$this->rawpol->id}/manufacturers")
            ->assertJsonPath('sync_running', true);
    }

    public function test_zapis_tylko_wylaczen_a_oba_znaczniki_wlaczone_usuwaja_regule(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->link($this->rawpol, $this->card('S-1', 'Secura'), 'Secura');

        $this->putJson("/api/b2b-accounts/{$this->rawpol->id}/manufacturers", ['rules' => [
            ['manufacturer' => 'Secura', 'take_price' => true, 'take_description' => false],
            ['manufacturer' => 'Portwest', 'take_price' => false, 'take_description' => false],
        ]])
            ->assertOk()
            ->assertJsonPath('recomputed', 0)
            ->assertJsonPath('frozen', 0)
            ->assertJsonPath('manufacturers.0.manufacturer', 'Secura')
            ->assertJsonPath('manufacturers.0.take_description', false)
            ->assertJsonPath('manufacturers.0.has_rule', true);
        $this->assertDatabaseHas('b2b_account_manufacturer_rules', [
            'b2b_account_id' => $this->rawpol->id, 'manufacturer_key' => 'secura', 'take_price' => true, 'take_description' => false,
        ]);
        $this->assertDatabaseHas('b2b_account_manufacturer_rules', [
            'b2b_account_id' => $this->rawpol->id, 'manufacturer_key' => 'portwest', 'take_price' => false,
        ]);

        // wiersz nieobecny w żądaniu zostaje; oba znaczniki włączone = brak reguły
        $this->putJson("/api/b2b-accounts/{$this->rawpol->id}/manufacturers", ['rules' => [
            ['manufacturer' => 'SECURA', 'take_price' => true, 'take_description' => true],
        ]])->assertOk()->assertJsonPath('manufacturers.0.has_rule', false);
        $this->assertDatabaseMissing('b2b_account_manufacturer_rules', ['manufacturer_key' => 'secura']);
        $this->assertDatabaseHas('b2b_account_manufacturer_rules', ['manufacturer_key' => 'portwest']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'b2b_account.manufacturer_rules_updated']);
    }

    public function test_lacznik_bez_ceny_w_slocie_zapisuje_tylko_znacznik_opisu(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $artra = $this->account('artra', 'artra.pl');

        $this->putJson("/api/b2b-accounts/{$artra->id}/manufacturers", ['rules' => [
            ['manufacturer' => 'Artra', 'take_price' => false, 'take_description' => false],
        ]])
            ->assertOk()
            ->assertJsonPath('uses_price_rules', false)
            ->assertJsonPath('manufacturers.0.take_price', true)
            ->assertJsonPath('manufacturers.0.take_description', false);
    }

    public function test_walidacja(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson("/api/b2b-accounts/{$this->rawpol->id}/manufacturers", [])
            ->assertStatus(422)->assertJsonValidationErrors('rules');
        $this->putJson("/api/b2b-accounts/{$this->rawpol->id}/manufacturers", ['rules' => [
            ['manufacturer' => 'Secura', 'take_price' => 'może'],
        ]])->assertStatus(422)->assertJsonValidationErrors(['rules.0.take_price', 'rules.0.take_description']);
        $this->putJson("/api/b2b-accounts/{$this->rawpol->id}/manufacturers", ['rules' => [
            ['manufacturer' => '—', 'take_price' => false, 'take_description' => true],
        ]])->assertStatus(422)->assertJsonValidationErrors('rules.0.manufacturer');
        $this->assertSame(0, B2bAccountManufacturerRule::query()->count());
    }

    public function test_wylaczenie_ceny_przelicza_cene_kart_producenta(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        // dwa konta dystrybutorów: cena z najświeżej sprawdzonego (Raw-Pol)
        $both = $this->card('S-1', 'Secura');
        $this->link($this->rawpol, $both, 'SECURA');
        $this->slot($both, ProductSourcePrice::b2bKey($this->rawpol->id), 60.0, ['checked_at' => Carbon::parse('2026-09-20')]);
        $this->link($this->tegro, $both, 'Secura');
        $this->slot($both, ProductSourcePrice::b2bKey($this->tegro->id), 70.0, ['checked_at' => Carbon::parse('2026-09-10')]);
        // tylko Raw-Pol ma cenę — po wyłączeniu cena karty zostaje (zamrożona)
        $only = $this->card('S-2', 'Secura');
        $this->link($this->rawpol, $only, 'Secura');
        $this->slot($only, ProductSourcePrice::b2bKey($this->rawpol->id), 30.0);
        // karta z producentem Secura, ale konto podaje innego — reguła Secury jej nie dotyczy
        $other = $this->card('S-3', 'Secura');
        $this->link($this->rawpol, $other, 'Reis');
        $this->slot($other, ProductSourcePrice::b2bKey($this->rawpol->id), 40.0, ['checked_at' => Carbon::parse('2026-09-20')]);
        $this->slot($other, ProductSourcePrice::b2bKey($this->tegro->id), 45.0, ['checked_at' => Carbon::parse('2026-09-10')]);
        $this->refreshAll([$both, $only, $other]);
        $this->assertEquals(60.0, (float) $both->refresh()->purchase_price);

        $this->putJson("/api/b2b-accounts/{$this->rawpol->id}/manufacturers", ['rules' => [
            ['manufacturer' => 'Secura', 'take_price' => false, 'take_description' => true],
        ]])
            ->assertOk()
            ->assertJsonPath('recomputed', 1)
            ->assertJsonPath('frozen', 1);

        $this->assertEquals(70.0, (float) $both->refresh()->purchase_price);
        $this->assertEquals(30.0, (float) $only->refresh()->purchase_price);
        $this->assertEquals(40.0, (float) $other->refresh()->purchase_price);
        // slot zostaje w bazie — nic nie kasujemy
        $this->assertTrue(ProductSourcePrice::query()->where('product_id', $both->id)->where('source_key', ProductSourcePrice::b2bKey($this->rawpol->id))->exists());

        // karta pokazuje, dlaczego cena Raw-Pol nie obowiązuje
        $this->getJson('/api/products/'.$both->id)
            ->assertOk()
            ->assertJsonPath('source_prices.0.source_key', ProductSourcePrice::b2bKey($this->tegro->id))
            ->assertJsonPath('source_prices.0.is_effective', true)
            ->assertJsonPath('source_prices.1.source_key', ProductSourcePrice::b2bKey($this->rawpol->id))
            ->assertJsonPath('source_prices.1.is_effective', false)
            ->assertJsonPath('source_prices.1.ignored_reason', 'cena producenta SECURA wyłączona w tym cenniku');

        // ponowne włączenie przywraca cenę Raw-Pol
        $this->putJson("/api/b2b-accounts/{$this->rawpol->id}/manufacturers", ['rules' => [
            ['manufacturer' => 'Secura', 'take_price' => true, 'take_description' => true],
        ]])
            ->assertOk()
            ->assertJsonPath('recomputed', 1)
            ->assertJsonPath('frozen', 0);
        $this->assertEquals(60.0, (float) $both->refresh()->purchase_price);
    }

    public function test_uprawnienia(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->getJson("/api/b2b-accounts/{$this->rawpol->id}/manufacturers")->assertForbidden();
        $this->putJson("/api/b2b-accounts/{$this->rawpol->id}/manufacturers", ['rules' => [
            ['manufacturer' => 'Secura', 'take_price' => false, 'take_description' => true],
        ]])->assertForbidden();
        $this->assertSame(0, B2bAccountManufacturerRule::query()->count());
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

    private function card(string $sku, string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Karta '.$sku,
            'manufacturer' => $manufacturer,
            'catalog_price_net' => 0,
            'purchase_price' => 0,
            'currency' => 'PLN',
            'stock' => 0,
        ]);
    }

    private function link(B2bAccount $account, Product $product, ?string $manufacturer, ?string $remoteId = null): void
    {
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id,
            'remote_id' => $remoteId ?? $product->sku,
            'remote_sku' => $product->sku,
            'product_id' => $product->id,
            'manufacturer' => $manufacturer,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function slot(Product $product, string $sourceKey, float $price, array $values = []): void
    {
        ProductSourcePrice::query()->create([
            'product_id' => $product->id,
            'source_key' => $sourceKey,
            'b2b_account_id' => str_starts_with($sourceKey, 'b2b:') ? (int) substr($sourceKey, 4) : null,
            'catalog_price_net' => $price,
            'purchase_price' => $price,
            'currency' => 'PLN',
            'checked_at' => now(),
            ...$values,
        ]);
    }

    /**
     * @param  list<Product>  $products
     */
    private function refreshAll(array $products): void
    {
        foreach ($products as $product) {
            app(ProductEffectivePrice::class)->refresh($product);
        }
    }
}
