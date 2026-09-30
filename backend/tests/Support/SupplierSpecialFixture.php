<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\ErpItemPurchase;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\ProductVariantPriceHistory;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

/**
 * Dane testowe ukrywania ceny specjalnej B2B (prices.supplier_special.view). Karta UVEX z ceną konta 173,19 zł,
 * cennik bazowy 248,67 zł, rabat standardowy 15% → cena standardowa 211,37 zł. Rozmiary 173,19 i 190,00 zł
 * (size_price_max 190,00); w widoku standardowym 190,00 → 231,89 (stosunek 211,37 / 173,19).
 *
 * assertNoSpecialLeak() pilnuje, żeby żadna z liczb zdradzających cenę specjalną nie wyszła w odpowiedzi.
 * Użycie: $this->setUpSupplierSpecial() w setUp(), potem $this->supplierSpecialCard().
 */
trait SupplierSpecialFixture
{
    protected const SPECIAL_PRICE = '173.19';

    protected const SPECIAL_BASE = '248.67';

    protected const SPECIAL_STANDARD = '211.37';

    protected const SPECIAL_SIZE_MAX = '190.00';

    protected const SPECIAL_SIZE_MAX_MASKED = '231.89';

    protected const SPECIAL_ERP_PRICE = 173.19;

    protected ?B2bAccount $uvexAccount = null;

    private int $supplierSpecialGid = 7000;

    /** Role z katalogu i kurs NBP bez sieci (EUR 4,00). */
    protected function setUpSupplierSpecial(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::fake([
            'api.nbp.pl/*' => Http::response([[
                'effectiveDate' => '2026-09-15',
                'rates' => [['code' => 'EUR', 'mid' => 4.0]],
            ]]),
        ]);
    }

    protected function uvexAccount(): B2bAccount
    {
        return $this->uvexAccount ??= B2bAccount::query()->create([
            'username' => 'uvex-login',
            'password' => 'uvex-haslo',
            'sites' => ['izam.system-b2b.pl'],
            'connector' => 'uvex',
        ]);
    }

    protected function otherAccount(string $connector = 'anro'): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $connector.'-login',
            'password' => $connector.'-haslo',
            'sites' => ['b2b.'.$connector.'.example.pl'],
            'connector' => $connector,
        ]);
    }

    /**
     * Pełna karta z ceną specjalną: slot UVEX (special), powiązanie z kontem, dwa rozmiary konta, wiersz historii
     * przebiegu UVEX (karta i rozmiary) i zakup w ERP XL po cenie specjalnej.
     *
     * @param  array<string, mixed>  $card  nadpisania pól karty
     * @param  array<string, mixed>  $slot  nadpisania pól slotu
     * @return array{product: Product, slot: ProductSourcePrice, variants: list<ProductVariant>, run: B2bSyncRun, account: B2bAccount}
     */
    protected function supplierSpecialCard(string $sku = '60148-UVEX', array $card = [], array $slot = []): array
    {
        $account = $this->uvexAccount();
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice UVEX '.$sku,
            'manufacturer' => 'UVEX',
            // UVEX: katalogowa karty = cena konta, rabat 0 (B2bCatalogSync)
            'catalog_price_net' => self::SPECIAL_PRICE,
            'discount_percent' => 0,
            'purchase_price' => self::SPECIAL_PRICE,
            'currency' => 'PLN',
            'stock' => 1,
            ...$card,
        ]);
        $sourceSlot = $this->uvexSlot($product, (float) self::SPECIAL_PRICE, [
            'size_price_max' => self::SPECIAL_SIZE_MAX,
            ...$slot,
        ]);
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id,
            'remote_id' => 'R-'.$sku,
            'remote_sku' => $sku,
            'product_id' => $product->id,
        ]);

        $run = B2bSyncRun::query()->create([
            'b2b_account_id' => $account->id,
            'status' => 'finished',
            'trigger' => 'manual',
            'started_at' => Carbon::parse('2026-09-29 06:00:00'),
            'finished_at' => Carbon::parse('2026-09-29 06:10:00'),
        ]);
        ProductPriceHistory::query()->create([
            'product_id' => $product->id,
            'b2b_sync_run_id' => $run->id,
            'catalog_price_net' => self::SPECIAL_PRICE,
            'purchase_price' => self::SPECIAL_PRICE,
            'currency' => 'PLN',
            'source' => 'b2b:uvex',
        ]);

        $variants = [];
        foreach ([['S', self::SPECIAL_PRICE], ['XL', self::SPECIAL_SIZE_MAX]] as $i => [$label, $price]) {
            $variant = ProductVariant::query()->create([
                'product_id' => $product->id,
                'kind' => ProductVariant::KIND_SIZE,
                'b2b_account_id' => $account->id,
                'source' => ProductSourcePrice::b2bKey((int) $account->id),
                'remote_id' => $sku.'-'.$label,
                'sku' => $sku.'-'.$label,
                'label' => $label,
                'purchase_price' => $price,
                'currency' => 'PLN',
                'sort_order' => $i,
                'price_checked_at' => Carbon::parse('2026-09-29 06:05:00'),
            ]);
            ProductVariantPriceHistory::query()->create([
                'product_variant_id' => $variant->id,
                'b2b_sync_run_id' => $run->id,
                'purchase_price' => $price,
                'currency' => 'PLN',
                'source' => 'b2b:uvex',
            ]);
            $variants[] = $variant;
        }

        $this->erpPurchase($product, self::SPECIAL_ERP_PRICE);

        return ['product' => $product, 'slot' => $sourceSlot, 'variants' => $variants, 'run' => $run, 'account' => $account];
    }

    /**
     * Slot UVEX z cennikiem bazowym 248,67 zł i rabatem 15% (standard 211,37 zł).
     *
     * @param  array<string, mixed>  $values
     */
    protected function uvexSlot(Product $product, float $purchase, array $values = []): ProductSourcePrice
    {
        $account = $this->uvexAccount();

        return ProductSourcePrice::query()->create([
            'product_id' => $product->id,
            'source_key' => ProductSourcePrice::b2bKey((int) $account->id),
            'b2b_account_id' => $account->id,
            'catalog_price_net' => $purchase,
            'purchase_price' => $purchase,
            'discount_percent' => 0,
            'currency' => 'PLN',
            'base_price_net' => self::SPECIAL_BASE,
            'base_price_category' => 'Rękawice ochronne',
            'base_price_code' => '60148',
            'base_price_source' => 'Cennik UVEX 2026.xlsx',
            'standard_discount_percent' => 15,
            'checked_at' => Carbon::now()->subDay(),
            ...$values,
        ]);
    }

    /** Towar ERP XL powiązany z kartą z zakupem (PZ) po podanej cenie jednostkowej. */
    protected function erpPurchase(Product $product, float $unitPricePln): ErpItem
    {
        $gid = $this->supplierSpecialGid++;
        $item = ErpItem::query()->create([
            'xl_gid' => $gid,
            'code' => 'XL-'.$product->sku,
            'name' => 'Towar '.$product->sku,
            'unit' => 'szt',
            'archived' => false,
            'stock_trade' => 10,
            'stock_total' => 10,
            'stock_by_warehouse' => [['code' => '01H', 'name' => 'Magazyn HANDEL', 'quantity' => 10]],
            'synced_at' => now(),
        ]);
        ErpItemPurchase::query()->create([
            'erp_item_id' => $item->id,
            'document_type' => 1489,
            'document_id' => 2200000 + $gid,
            'document_line' => 1,
            'purchased_at' => '2026-09-20',
            'supplier' => 'UVEX',
            'quantity' => 10,
            'document_unit' => 'szt',
            'net_value_pln' => $unitPricePln * 10,
            'unit_price_pln' => $unitPricePln,
            'document_price' => $unitPricePln,
            'currency' => 'PLN',
        ]);
        ErpItemLink::query()->create([
            'erp_item_id' => $item->id,
            'product_id' => $product->id,
            'status' => ErpItemLink::STATUS_CONFIRMED,
            'method' => ErpItemLink::METHOD_NAME,
            'matched_value' => $item->code,
            'last_seen_at' => now(),
        ]);

        return $item;
    }

    /** Użytkownik z rolą z katalogu (role muszą być zasiane — setUpSupplierSpecial). */
    protected function userWithRole(string $role): User
    {
        return User::factory()->withRole($role)->create();
    }

    /**
     * Użytkownik z własną rolą (spoza katalogu) i podanymi uprawnieniami.
     *
     * @param  list<string>  $permissions
     */
    protected function userWithCustomRole(string $role, array $permissions): User
    {
        $created = Role::findOrCreate($role, 'web');
        $created->syncPermissions($permissions);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return User::factory()->withRole($role)->create();
    }

    /**
     * Odpowiedź dla użytkownika bez uprawnienia nie może zawierać żadnej liczby zdradzającej cenę specjalną:
     * cenę konta 173,19 (także jako float PLN), rozmiar 190,00 w prawdziwej cenie, rabat faktyczny 30,35%,
     * oszczędność 38,18 zł ani ceny z narzutem 18% (204,36).
     */
    protected function assertNoSpecialLeak(string $json): void
    {
        $patterns = [
            '173.19' => '/(?<!\d)173[.,]19(?!\d)/',
            '190.00' => '/(?<!\d)190[.,]00?(?!\d)/',
            '30.35' => '/(?<!\d)30[.,]35(?!\d)/',
            '38.18' => '/(?<!\d)38[.,]18(?!\d)/',
            '204.36' => '/(?<!\d)204[.,]36(?!\d)/',
        ];
        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $json, $match, PREG_OFFSET_CAPTURE) === 1) {
                $offset = (int) $match[0][1];
                $this->fail('Wyciek ceny specjalnej ('.$label.'): …'.substr($json, max(0, $offset - 80), 160).'…');
            }
        }
        $this->addToAssertionCount(1);
    }
}
