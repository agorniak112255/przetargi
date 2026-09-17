<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductShopCard;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bCatalogSync;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRemoteShopField;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\Vector\ProductEmbeddingIndexer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tabelki z kart wyrobu u dostawców (ProductShopCard) widoczne dla wyszukiwania: products.shop_fields_summary,
 * search_blob i dokument embeddingu. Bez HTTP.
 */
final class ShopFieldsSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private B2bAccount $account;

    private SummaryFakeConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
        $this->account = $this->b2bAccount('jan');
        $this->connector = new SummaryFakeConnector;
        $this->connector->items = [$this->remote()];
    }

    public function test_summary_lands_in_column_search_blob_and_embedding_document(): void
    {
        $this->connector->fields = [
            new B2bRemoteShopField('Informacje handlowe', 'Dział towarowy', 'Rękawice'),
            new B2bRemoteShopField('Informacje handlowe', 'Jednostka sprzedaży', 'para'),
            new B2bRemoteShopField('Parametry techniczne', 'Norma', 'EN 388:2016'),
            // sekcja bez nazwy nie dostaje linii nagłówka
            new B2bRemoteShopField('', 'Kolor', 'czarny'),
        ];

        $this->sync();

        $product = Product::query()->sole();
        $this->assertSame(
            "Informacje handlowe\nDział towarowy: Rękawice\nJednostka sprzedaży: para\n"
                ."Parametry techniczne\nNorma: EN 388:2016\nKolor: czarny",
            $product->shop_fields_summary,
        );

        // indeks tekstowy: tekst po normalizacji i kanoniczny token normy z tabelki
        $blob = (string) $product->search_blob;
        $this->assertStringContainsString('jednostka sprzedazy: para', $blob);
        $this->assertStringContainsString('en388', $blob);

        // dokument embeddingu dostaje te same wiersze
        $this->assertStringContainsString(
            "Parametry techniczne\nNorma: EN 388:2016",
            app(ProductEmbeddingIndexer::class)->documentText($product),
        );
    }

    public function test_cards_of_two_accounts_make_one_text_ordered_by_account(): void
    {
        $second = $this->b2bAccount('anna');
        $this->assertGreaterThan($this->account->id, $second->id);

        // konto o większym id synchronizowane jako pierwsze — kolejność w tekście ma iść po id, nie po zapisie
        $later = new SummaryFakeConnector;
        $later->items = [$this->remote()];
        $later->fields = [new B2bRemoteShopField('', 'Kolor', 'granatowy')];
        $this->sync($second, $later);

        $this->connector->fields = [new B2bRemoteShopField('', 'Kolor', 'czarny')];
        $this->sync();

        $product = Product::query()->sole();
        $this->assertSame(2, ProductShopCard::query()->where('product_id', $product->id)->count());
        $this->assertSame("Kolor: czarny\nKolor: granatowy", $product->shop_fields_summary);
        // ten sam stan bazy daje ten sam tekst
        $this->assertSame($product->shop_fields_summary, B2bCatalogSync::shopFieldsSummary($product));
    }

    public function test_removed_shop_card_clears_the_column(): void
    {
        $this->connector->fields = [new B2bRemoteShopField('', 'Kolor', 'czarny')];
        $this->sync();
        $this->assertSame('Kolor: czarny', Product::query()->sole()->shop_fields_summary);

        $this->expireShopCards();
        $this->connector->fields = [];
        $this->sync();

        $this->assertSame(0, ProductShopCard::query()->count());
        $product = Product::query()->sole();
        $this->assertNull($product->shop_fields_summary);
        $this->assertStringNotContainsString('czarny', (string) $product->search_blob);
    }

    public function test_ttl_gate_does_not_block_filling_the_column(): void
    {
        $this->connector->fields = [new B2bRemoteShopField('', 'Kolor', 'czarny')];
        $this->sync();
        $this->assertSame(1, $this->connector->shopFieldsCalls);

        // stan po etapie 1: rekordy są świeże, ale kolumny jeszcze nikt nie policzył
        Product::query()->toBase()->update(['shop_fields_summary' => null]);
        $this->assertNull(Product::query()->sole()->shop_fields_summary);

        $result = $this->sync();

        // brama TTL nie wypuściła zapytania do sklepu, a kolumna i tak jest wypełniona
        $this->assertSame(1, $this->connector->shopFieldsCalls);
        $this->assertSame(0, $result['shop_fields']);
        $product = Product::query()->sole();
        $this->assertSame('Kolor: czarny', $product->shop_fields_summary);
        $this->assertStringContainsString('kolor: czarny', (string) $product->search_blob);
    }

    public function test_poorer_response_does_not_replace_richer_stored_card(): void
    {
        $this->connector->fields = [
            new B2bRemoteShopField('', 'Jednostka sprzedaży', 'para'),
            new B2bRemoteShopField('', 'Kod katalogowy', '60968'),
            new B2bRemoteShopField('Dane techniczne', 'Norma', 'EN 388:2016'),
            new B2bRemoteShopField('Dane techniczne', 'Materiał', 'skóra licowa'),
        ];
        $this->sync();
        $this->assertSame(4, $this->rowCount(ProductShopCard::query()->sole()));

        // po TTL strona producenta nie została odwiedzona (karta ma już opis) — zostają same wiersze handlowe
        $this->expireShopCards();
        $this->connector->fields = [
            new B2bRemoteShopField('', 'Jednostka sprzedaży', 'para'),
            new B2bRemoteShopField('', 'Kod katalogowy', '60968'),
        ];
        $result = $this->sync();

        $this->assertSame(0, $result['shop_fields']);
        $card = ProductShopCard::query()->sole();
        $this->assertSame(4, $this->rowCount($card));
        $this->assertSame('Dane techniczne', $card->fields[1]['section']);
        // synced_at zostaje stare — następny przebieg ma spróbować jeszcze raz
        $this->assertTrue($card->synced_at->lt(now()->subDays(B2bCatalogSync::SHOP_FIELDS_TTL_DAYS)));
        $this->assertContains(
            'UX1: dane z karty w sklepie były uboższe od zapisanych — zostawiono poprzednie wiersze',
            $this->logTexts($result),
        );
        $this->assertStringContainsString('Norma: EN 388:2016', (string) Product::query()->sole()->shop_fields_summary);

        // odpowiedź z nową sekcją nie jest uboższa — wchodzi normalnie, choć ma mniej wierszy
        $this->expireShopCards();
        $this->connector->fields = [new B2bRemoteShopField('Parametry', 'Rozmiar', '10')];
        $this->sync();
        $this->assertSame("Parametry\nRozmiar: 10", Product::query()->sole()->shop_fields_summary);
    }

    public function test_summary_is_capped_at_1500_chars(): void
    {
        $fields = [];
        for ($i = 1; $i <= 40; $i++) {
            $fields[] = new B2bRemoteShopField('Protection Level', 'Parametr '.$i, str_repeat('x', 60));
        }
        $this->connector->fields = $fields;

        $this->sync();

        $summary = (string) Product::query()->sole()->shop_fields_summary;
        $this->assertSame(1500, mb_strlen($summary));
        $this->assertStringStartsWith("Protection Level\nParametr 1: xxx", $summary);
    }

    public function test_backfill_command_fills_cards_saved_earlier(): void
    {
        $other = $this->b2bAccount('anna');
        $first = $this->product('BF-1');
        $second = $this->product('BF-2');
        $this->shopCard($first, $this->account, [['section' => '', 'rows' => [['name' => 'Kolor', 'value' => 'czarny']]]]);
        $this->shopCard($second, $other, [['section' => 'Parametry', 'rows' => [['name' => 'Norma', 'value' => 'EN 388:2016']]]]);

        // tylko konto „anna”
        $this->artisan('products:refresh-shop-summaries', ['--account' => (string) $other->id])->assertSuccessful();
        $this->assertNull($first->fresh()->shop_fields_summary);
        $this->assertSame("Parametry\nNorma: EN 388:2016", $second->fresh()->shop_fields_summary);

        // bez opcji — wszystkie karty z tabelką
        $this->artisan('products:refresh-shop-summaries')->assertSuccessful();
        $first->refresh();
        $this->assertSame('Kolor: czarny', $first->shop_fields_summary);
        $this->assertStringContainsString('kolor: czarny', (string) $first->search_blob);
        $this->assertStringContainsString('en388', (string) $second->fresh()->search_blob);
    }

    public function test_backfill_command_rejects_unknown_account(): void
    {
        $this->artisan('products:refresh-shop-summaries', ['--account' => '9999'])->assertFailed();
    }

    /**
     * @param  array<int, array{section: string, rows: list<array{name: string, value: string}>}>  $fields
     */
    private function shopCard(Product $product, B2bAccount $account, array $fields): ProductShopCard
    {
        return ProductShopCard::query()->create([
            'product_id' => $product->id,
            'b2b_account_id' => $account->id,
            'source_url' => null,
            'fields' => $fields,
            'synced_at' => now(),
        ]);
    }

    private function product(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice '.$sku,
            'manufacturer' => 'Anro',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 1,
        ]);
    }

    private function rowCount(ProductShopCard $card): int
    {
        return array_sum(array_map(static fn (array $section): int => count($section['rows']), $card->fields));
    }

    private function remote(): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: 'UX1',
            sku: 'UX1',
            name: 'Rękawice UVEX',
            sourceUrl: 'https://shopsummary.example.test/p/UX1',
        );
    }

    private function b2bAccount(string $username): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $username,
            'password' => 'sekret',
            'sites' => [SummaryFakeConnector::host()],
            'connector' => SummaryFakeConnector::key(),
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    /** Cofa czas pobrania wszystkich kart poza bramę TTL. */
    private function expireShopCards(): void
    {
        ProductShopCard::query()->toBase()->update([
            'synced_at' => now()->subDays(B2bCatalogSync::SHOP_FIELDS_TTL_DAYS + 1),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sync(?B2bAccount $account = null, ?B2bConnector $connector = null): array
    {
        return app(B2bAccountSyncRunner::class)->run(
            ($account ?? $this->account)->fresh(),
            delayMs: 0,
            connector: $connector ?? $this->connector,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function logTexts(array $result): array
    {
        return array_column(B2bSyncRun::query()->findOrFail($result['sync_run_id'])->log, 'text');
    }
}

/** Łącznik testowy bez sieci: stała cena, bez opisu, wiersze karty ze sklepu ustawiane w teście. */
final class SummaryFakeConnector implements B2bConnector, B2bShopFieldSource
{
    /** @var list<B2bRemoteProduct> */
    public array $items = [];

    /** @var list<B2bRemoteShopField> */
    public array $fields = [];

    /** Ile razy synchronizacja poprosiła o wiersze karty — brama TTL ma je oszczędzać. */
    public int $shopFieldsCalls = 0;

    public static function key(): string
    {
        return 'shopsummary';
    }

    public static function label(): string
    {
        return 'Sklep testowy (podsumowanie)';
    }

    public static function host(): string
    {
        return 'shopsummary.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        yield from $this->items;
    }

    public function totalProducts(): int
    {
        return count($this->items);
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'UVEX';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: 40.0);
    }

    public function description(B2bRemoteProduct $product): string
    {
        return '';
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }

    public function shopFields(B2bRemoteProduct $product): array
    {
        $this->shopFieldsCalls++;

        return $this->fields;
    }
}
