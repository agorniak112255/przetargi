<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Kategoria wpisana przez sklep B2B i nietknięta idzie za kategorią sklepu (06.10.2026: kategorie SIR po polsku —
 * kategorię wpisywało się tylko na pustą kartę, więc 1079 kart zostałoby z angielską ścieżką). Wybór w panelu
 * i karta z drugim kontem B2B zostają bez zmian.
 */
final class B2bShopCategoryFollowTest extends TestCase
{
    use RefreshDatabase;

    private B2bAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->account = $this->account('jan');
    }

    public function test_untouched_shop_category_follows_the_shop(): void
    {
        $shop = new CategoryShopConnector;
        $shop->category = 'GLOVES > CUT PROTECTION GLOVES';
        $this->runSync($shop);
        $this->assertSame('GLOVES > CUT PROTECTION GLOVES', $this->card()->category);

        $shop->category = 'Rękawice > Rękawice chroniące przed przecięciem';
        $this->runSync($shop);

        $card = $this->card();
        $this->assertSame('Rękawice > Rękawice chroniące przed przecięciem', $card->category);
        $this->assertSame('Rękawice > Rękawice chroniące przed przecięciem', $card->category_evidence);
        $this->assertSame(Product::CATEGORY_SOURCE_B2B, $card->category_source);
    }

    public function test_category_chosen_in_the_panel_stays(): void
    {
        $shop = new CategoryShopConnector;
        $shop->category = 'GLOVES > CUT PROTECTION GLOVES';
        $this->runSync($shop);
        $this->card()->update(['category' => 'Rękawice antyprzecięciowe', 'category_source' => Product::CATEGORY_SOURCE_MANUAL]);

        $shop->category = 'Rękawice > Rękawice chroniące przed przecięciem';
        $this->runSync($shop);

        $this->assertSame('Rękawice antyprzecięciowe', $this->card()->category);
        $this->assertSame('Rękawice > Rękawice chroniące przed przecięciem', $this->card()->category_evidence);
    }

    public function test_card_of_two_shops_keeps_its_category(): void
    {
        $shop = new CategoryShopConnector;
        $shop->category = 'GLOVES > CUT PROTECTION GLOVES';
        $this->runSync($shop);
        B2bProductLink::query()->create([
            'b2b_account_id' => $this->account('drugi')->id,
            'remote_id' => 'X1',
            'product_id' => $this->card()->id,
        ]);

        $shop->category = 'Rękawice > Rękawice chroniące przed przecięciem';
        $this->runSync($shop);

        $this->assertSame('GLOVES > CUT PROTECTION GLOVES', $this->card()->category);
    }

    private function runSync(B2bConnector $connector): void
    {
        app(B2bAccountSyncRunner::class)->run($this->account->fresh(), delayMs: 0, connector: $connector);
    }

    private function card(): Product
    {
        return Product::query()->where('sku', 'CAT-1')->sole();
    }

    private function account(string $username): B2bAccount
    {
        $user = User::factory()->withRole('admin')->create();

        return B2bAccount::query()->create([
            'username' => $username,
            'password' => 'sekret',
            'sites' => [CategoryShopConnector::host()],
            'connector' => CategoryShopConnector::key(),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }
}

/** Łącznik testowy bez sieci: jedna karta, kategoria ustawiana w teście. */
final class CategoryShopConnector implements B2bConnector
{
    public string $category = '';

    public static function key(): string
    {
        return 'categorytest';
    }

    public static function label(): string
    {
        return 'Testowy';
    }

    public static function host(): string
    {
        return 'category.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        yield new B2bRemoteProduct(remoteId: '1', sku: 'CAT-1', name: 'Rękawice TESTCUT', category: $this->category);
    }

    public function totalProducts(): int
    {
        return 1;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'Testowy';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: 50.0, base: 60.0);
    }

    public function description(B2bRemoteProduct $product): string
    {
        return '';
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }
}
