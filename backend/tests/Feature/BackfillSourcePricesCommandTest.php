<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Pricing\ProductEffectivePrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class BackfillSourcePricesCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private B2bAccount $jsp;

    private B2bAccount $anro;

    private PriceList $olderList;

    private PriceList $newerList;

    /** Tylko cennik z pliku (dwa importy — liczy się ostatni). */
    private Product $fileOnly;

    /** Tylko B2B, historia „b2b:jsp”. */
    private Product $jspOnly;

    /** Tylko B2B, dawna nazwa źródła „b2b_api”. */
    private Product $legacyB2b;

    /** Plik i B2B — obowiązuje B2B (równe cenie karty). */
    private Product $both;

    /** Link B2B bez historii cen. */
    private Product $linkOnly;

    private Product $withVariants;

    /** Ostatni wiersz historii ≠ cena karty. */
    private Product $mismatch;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::factory()->create();
        $this->jsp = $this->account('jsp');
        $this->anro = $this->account('anro');
        $this->olderList = $this->priceList('2025');
        $this->newerList = $this->priceList('2026');

        $this->fileOnly = $this->card('PLIK-1', 40.80, 10, 36.72, 'EUR', ['pack_qty' => 10]);
        $this->history($this->fileOnly, 'price_list_import', 30.00, 27.00, '2026-09-01 08:00:00', $this->olderList);
        $this->history($this->fileOnly, 'price_list_import', 40.80, 36.72, '2026-09-10 12:00:00', $this->newerList);

        $this->jspOnly = $this->card('JSP-1', 20.00, 0, 20.00);
        $this->link($this->jspOnly, $this->jsp, 'j1');
        $this->history($this->jspOnly, 'b2b:jsp', 20.00, 20.00, '2026-09-14 10:00:00');

        $this->legacyB2b = $this->card('ANRO-1', 15.00, 0, 15.00);
        $this->link($this->legacyB2b, $this->anro, 'a1');
        $this->history($this->legacyB2b, 'b2b_api', 15.00, 15.00, '2026-09-12 09:30:00');

        // karta marki konta jsp: od 23.09.2026 plik cennika producenta wygrywa z dystrybutorem, więc „obowiązuje
        // B2B” zostaje prawdą tylko wtedy, gdy konto B2B jest cennikiem producenta tej karty
        $this->both = $this->card('OBA-1', 80.00, 10, 72.00, 'PLN', ['manufacturer' => 'JSP']);
        $this->link($this->both, $this->jsp, 'j2');
        $this->history($this->both, 'price_list_import', 100.00, 90.00, '2026-09-01 08:00:00', $this->olderList);
        $this->history($this->both, 'b2b:jsp', 80.00, 72.00, '2026-09-14 10:00:00');

        $this->linkOnly = $this->card('LINK-1', 12.00, 0, 12.00);
        $this->link($this->linkOnly, $this->anro, 'a2', '2026-09-15 02:10:00');

        $this->withVariants = $this->card('ZNAK-1', 0, 0, 0);
        $this->link($this->withVariants, $this->anro, 'a3');
        $this->history($this->withVariants, 'b2b:anro', 5.00, 5.00, '2026-09-14 10:00:00');
        ProductVariant::query()->create([
            'product_id' => $this->withVariants->id, 'source' => 'b2b:signproject', 'remote_id' => 'v1', 'label' => 'A4 · PCV',
            'purchase_price' => 4.20, 'currency' => 'PLN',
        ]);

        $this->mismatch = $this->card('ROZN-1', 50.00, 0, 50.00);
        $this->history($this->mismatch, 'price_list_import', 40.00, 40.00, '2026-09-10 12:00:00', $this->newerList);
    }

    public function test_report_without_apply_writes_nothing(): void
    {
        $this->artisan('prices:backfill-sources')
            ->expectsOutputToContain('Tryb: raport bez zapisu')
            ->expectsOutputToContain('Karty z nowymi slotami: 5')
            ->expectsOutputToContain('Sloty do utworzenia · file (cennik z pliku): 2')
            ->expectsOutputToContain(sprintf('Sloty do utworzenia · b2b:%d (jsp, jsp-konto): 2', $this->jsp->id))
            ->expectsOutputToContain(sprintf('Sloty do utworzenia · b2b:%d (anro, anro-konto): 2', $this->anro->id))
            ->expectsOutputToContain('Karty z aktywnymi wersjami (pominięte): 1')
            ->expectsOutputToContain('Karty z linkiem B2B bez historii cen B2B (slot B2B z ceny karty): 1')
            ->expectsOutputToContain('Karty z ceną ze slotów inną niż cena karty — ich sloty (1) nie zostaną zapisane, decyzja człowieka: 1')
            ->expectsOutputToContain('ROZN-1: zakup 50.00 → 40.00, katalogowa 50.00 → 40.00 (źródło: file)')
            ->expectsOutputToContain('Karty z rabatem innym niż rabat ze slotów (historia nie ma rabatu — slot dostaje rabat karty, gdy ceny wiersza są cenami karty; ceny kart nie są przeliczane): 0')
            ->assertSuccessful();

        $this->assertSame(0, ProductSourcePrice::query()->count());
    }

    public function test_apply_creates_slots_from_history_and_links_without_touching_cards(): void
    {
        $before = $this->cardPrices();

        $this->artisan('prices:backfill-sources', ['--apply' => true])
            ->expectsOutputToContain('Tryb: zapis (--apply).')
            ->expectsOutputToContain('Sloty zapisane · file (cennik z pliku): 2')
            ->expectsOutputToContain('ich sloty (1) nie zostały zapisane, decyzja człowieka: 1')
            ->expectsOutputToContain('ROZN-1: zakup 50.00 → 40.00')
            ->assertSuccessful();

        $this->assertSame(6, ProductSourcePrice::query()->count());
        $this->assertTrue(ProductSourcePrice::query()->where('migrated', false)->doesntExist());

        // tylko plik — ostatni import
        $file = $this->slot($this->fileOnly, ProductSourcePrice::SOURCE_FILE);
        $this->assertSame($this->newerList->id, $file->price_list_id);
        $this->assertNull($file->b2b_account_id);
        $this->assertSame('40.80', $file->catalog_price_net);
        $this->assertSame('36.72', $file->purchase_price);
        // historia nie ma rabatu — ceny wiersza równe cenom karty, więc rabat z karty (nie null, nie 0)
        $this->assertSame('10.00', $file->discount_percent);
        $this->assertSame('EUR', $file->currency);
        $this->assertSame(10, $file->pack_qty);
        $this->assertSame('2026-09-10 12:00:00', $file->checked_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, ProductSourcePrice::query()->where('product_id', $this->fileOnly->id)->count());

        // tylko B2B — konto z linku, także dla dawnej nazwy źródła
        $jsp = $this->slot($this->jspOnly, ProductSourcePrice::b2bKey($this->jsp->id));
        $this->assertSame($this->jsp->id, $jsp->b2b_account_id);
        $this->assertSame('20.00', $jsp->purchase_price);
        $this->assertSame('2026-09-14 10:00:00', $jsp->checked_at->format('Y-m-d H:i:s'));
        $legacy = $this->slot($this->legacyB2b, ProductSourcePrice::b2bKey($this->anro->id));
        $this->assertSame($this->anro->id, $legacy->b2b_account_id);
        $this->assertSame('15.00', $legacy->catalog_price_net);
        $this->assertSame('2026-09-12 09:30:00', $legacy->checked_at->format('Y-m-d H:i:s'));

        // plik i B2B — oba sloty, obowiązuje B2B równe cenie karty
        $this->assertSame('90.00', $this->slot($this->both, ProductSourcePrice::SOURCE_FILE)->purchase_price);
        $this->assertSame('72.00', $this->slot($this->both, ProductSourcePrice::b2bKey($this->jsp->id))->purchase_price);
        $resolved = app(ProductEffectivePrice::class)->resolve($this->both);
        $this->assertSame(ProductSourcePrice::b2bKey($this->jsp->id), $resolved['source_key']);
        $this->assertSame('72.00', $resolved['purchase_price']);
        $this->assertSame('80.00', $resolved['catalog_price_net']);

        // link bez historii — ceny z karty, sprawdzone przy ostatnim pobraniu
        $linkOnly = $this->slot($this->linkOnly, ProductSourcePrice::b2bKey($this->anro->id));
        $this->assertSame('12.00', $linkOnly->purchase_price);
        $this->assertNull($linkOnly->price_list_id);
        $this->assertSame('2026-09-15 02:10:00', $linkOnly->checked_at->format('Y-m-d H:i:s'));

        $this->assertSame(0, ProductSourcePrice::query()->where('product_id', $this->withVariants->id)->count());
        $this->assertSame(0, ProductSourcePrice::query()->where('product_id', $this->mismatch->id)->count());

        // ceny kart (także rabat) nie są przeliczane
        $this->assertSame($before, $this->cardPrices());
    }

    public function test_second_apply_does_not_overwrite_existing_slots(): void
    {
        $this->artisan('prices:backfill-sources', ['--apply' => true])->assertSuccessful();
        ProductSourcePrice::query()
            ->where('product_id', $this->fileOnly->id)
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->update(['purchase_price' => 1.00]);

        $this->artisan('prices:backfill-sources', ['--apply' => true])
            ->expectsOutputToContain('Karty z nowymi slotami: 0')
            ->expectsOutputToContain('Sloty zapisane: 0')
            ->expectsOutputToContain('Sloty już istniejące (bez zmian): 6')
            ->assertSuccessful();

        $this->assertSame(6, ProductSourcePrice::query()->count());
        $this->assertSame('1.00', $this->slot($this->fileOnly, ProductSourcePrice::SOURCE_FILE)->purchase_price);
    }

    public function test_card_with_price_difference_gets_no_slots(): void
    {
        // B2B nowsze w historii, ale karta ma cenę z pliku — slot B2B zmieniłby cenę obowiązującą
        $card = $this->card('ROZN-2', 100.00, 10, 90.00);
        $this->link($card, $this->jsp, 'j9');
        $this->history($card, 'b2b:jsp', 80.00, 72.00, '2026-09-01 08:00:00');
        $this->history($card, 'price_list_import', 100.00, 90.00, '2026-09-10 12:00:00', $this->newerList);

        $this->artisan('prices:backfill-sources', ['--apply' => true])
            ->expectsOutputToContain(sprintf('ROZN-2: zakup 90.00 → 72.00, katalogowa 100.00 → 80.00 (źródło: b2b:%d)', $this->jsp->id))
            ->expectsOutputToContain('ich sloty (3) nie zostały zapisane, decyzja człowieka: 2')
            ->assertSuccessful();

        $this->assertSame(0, ProductSourcePrice::query()->where('product_id', $card->id)->count());
        $this->assertSame('90.00', $card->fresh()->purchase_price);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function card(string $sku, float $catalog, float $discount, float $purchase, string $currency = 'PLN', array $attributes = []): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Produkt '.$sku,
            'manufacturer' => 'Anro',
            'catalog_price_net' => $catalog,
            'discount_percent' => $discount,
            'purchase_price' => $purchase,
            'currency' => $currency,
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

    private function priceList(string $version): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => 'Anro', 'version' => $version, 'original_filename' => 'anro-'.$version.'.xlsx',
            'imported_by' => $this->user->id,
        ]);
    }

    private function link(Product $product, B2bAccount $account, string $remoteId, ?string $lastSeenAt = null): void
    {
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id, 'remote_id' => $remoteId, 'product_id' => $product->id,
            'remote_sku' => $product->sku, 'last_seen_at' => $lastSeenAt !== null ? Carbon::parse($lastSeenAt) : null,
        ]);
    }

    private function history(Product $product, string $source, float $catalog, float $purchase, string $at, ?PriceList $list = null): void
    {
        $row = new ProductPriceHistory([
            'product_id' => $product->id,
            'price_list_id' => $list?->id,
            'b2b_sync_run_id' => null,
            'catalog_price_net' => $catalog,
            'purchase_price' => $purchase,
            'source' => $source,
        ]);
        $row->created_at = Carbon::parse($at);
        $row->updated_at = Carbon::parse($at);
        $row->save();
    }

    private function slot(Product $product, string $sourceKey): ProductSourcePrice
    {
        return ProductSourcePrice::query()
            ->where('product_id', $product->id)
            ->where('source_key', $sourceKey)
            ->firstOrFail();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function cardPrices(): array
    {
        return Product::query()->orderBy('sku')->get()
            ->mapWithKeys(static fn (Product $p): array => [
                $p->sku => [$p->catalog_price_net, $p->discount_percent, $p->purchase_price, $p->currency],
            ])
            ->all();
    }
}
