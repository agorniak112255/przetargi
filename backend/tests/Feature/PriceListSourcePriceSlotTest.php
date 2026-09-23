<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\PriceListDeletionService;
use App\Services\PriceListImportService;
use App\Services\ProductSizeMergeService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Cenniki z plików i B2B nie nadpisują sobie cen (decyzja użytkownika 15.09.2026): import pliku zapisuje slot „file”,
 * cena obowiązująca pochodzi z B2B, gdy karta ma slot B2B; nazwa i producent karty z powiązaniem B2B zostają.
 */
final class PriceListSourcePriceSlotTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
    }

    public function test_file_import_on_b2b_card_saves_file_slot_and_keeps_b2b_price_name_and_manufacturer(): void
    {
        $card = $this->b2bCard();
        $first = $this->import('v1', [$this->row('N/IF005', 'Alarm z pliku', 50.00, 40.00, 'Znaki')]);
        $firstList = $first['price_list'];

        $card->refresh();
        $slot = $this->fileSlot($card);
        $this->assertNotNull($slot);
        $this->assertSame($firstList->id, $slot->price_list_id);
        $this->assertEquals(50.00, (float) $slot->catalog_price_net);
        $this->assertEquals(40.00, (float) $slot->purchase_price);
        // cena obowiązująca dalej z B2B, nazwa i producent karty bez zmian, kategoria wg cennika
        $this->assertEquals(40.80, (float) $card->catalog_price_net);
        $this->assertEquals(36.72, (float) $card->purchase_price);
        $this->assertSame('Alarm pożarowy', $card->name);
        $this->assertSame('Anro', $card->manufacturer);
        $this->assertSame('Znaki', $card->category);
        $this->assertSame([$card->id], $firstList->product_ids);
        // pierwszy import pliku porównuje z ceną, która była na karcie
        $this->assertSame(1, $first['prices_changed']);
        $this->assertEquals(36.72, $first['price_changes'][0]['purchase_old']);
        $this->assertEquals(40.00, $first['price_changes'][0]['purchase_new']);
        $this->assertNotContains('name', $first['updated_products'][0]['fields']);
        $this->assertNotContains('manufacturer', $first['updated_products'][0]['fields']);

        $second = $this->import('v2', [$this->row('N/IF005', 'Alarm z pliku', 55.00, 44.00, 'Znaki')]);

        $card->refresh();
        $this->assertEquals(36.72, (float) $card->purchase_price);
        $this->assertSame('Alarm pożarowy', $card->name);
        // raport zmian cen względem poprzedniego pliku, nie ceny B2B
        $this->assertSame(1, $second['prices_changed']);
        $this->assertEquals(40.00, $second['price_changes'][0]['purchase_old']);
        $this->assertEquals(44.00, $second['price_changes'][0]['purchase_new']);
        $this->assertEquals(50.00, $second['updated_products'][0]['catalog_old']);
        // cennik producenta jest jeden, więc historię rozróżnia aktualizacja, nie wpis cennika
        $history = ProductPriceHistory::query()
            ->where('product_id', $card->id)
            ->where('price_list_import_id', $second['price_list_import']->id)
            ->where('source', 'price_list_import')
            ->sole();
        $this->assertEquals(55.00, (float) $history->catalog_price_net);
        $this->assertEquals(44.00, (float) $history->purchase_price);

        // ten sam plik jeszcze raz: bez zmiany ceny z pliku — bez zmian w raporcie i bez historii
        $third = $this->import('v3', [$this->row('N/IF005', 'Alarm z pliku', 55.00, 44.00, 'Znaki')]);
        $this->assertSame(0, $third['prices_changed']);
        $this->assertFalse(
            ProductPriceHistory::query()->where('price_list_import_id', $third['price_list_import']->id)->exists()
        );
    }

    public function test_file_import_without_b2b_sets_effective_price_and_name(): void
    {
        $result = $this->import('v1', [$this->row('ATG-1', 'Rękawica', 10.00, 8.00)]);

        $card = Product::query()->where('sku', 'ATG-1')->sole();
        $this->assertEquals(10.00, (float) $card->catalog_price_net);
        $this->assertEquals(8.00, (float) $card->purchase_price);
        $this->assertSame($result['price_list']->id, $this->fileSlot($card)?->price_list_id);
        $this->assertSame(1, ProductPriceHistory::query()->where('product_id', $card->id)->count());

        $this->import('v2', [$this->row('ATG-1', 'Rękawica nowa', 12.00, 9.00)]);

        $card->refresh();
        $this->assertSame('Rękawica nowa', $card->name);
        $this->assertEquals(12.00, (float) $card->catalog_price_net);
        $this->assertEquals(9.00, (float) $card->purchase_price);
    }

    public function test_sku_of_other_manufacturers_card_is_skipped_with_reason(): void
    {
        $card = Product::query()->create([
            'sku' => '420',
            'name' => 'Kamizelka AJ',
            'manufacturer' => 'AJ GROUP',
            'catalog_price_net' => 20.00,
            'purchase_price' => 15.00,
            'category' => 'Odzież',
        ]);

        $result = $this->import('2026', [
            $this->row('420', 'Nauszniki 3M', 99.00, 70.00, 'Ochrona słuchu'),
            $this->row('3M-OK', 'Półmaska 3M', 30.00, 25.00),
        ], '3M');

        $card->refresh();
        $this->assertSame('Kamizelka AJ', $card->name);
        $this->assertSame('AJ GROUP', $card->manufacturer);
        $this->assertSame('Odzież', $card->category);
        $this->assertEquals(15.00, (float) $card->purchase_price);
        $this->assertNull($this->fileSlot($card));
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertNotContains($card->id, $result['product_ids']);
        $this->assertContains('420: kod należy do karty producenta AJ GROUP', $result['errors']);
        $details = collect($result['skipped_details'])->firstWhere('sku', '420');
        $this->assertSame('kod należy do karty producenta AJ GROUP', $details['reason'] ?? null);
        $this->assertSame(1, $result['price_list']->rows_skipped);
        $this->assertSame(1, Product::query()->where('sku', '420')->count());
    }

    public function test_undoing_an_update_keeps_the_price_list_and_deleting_it_removes_only_its_slot(): void
    {
        $card = $this->b2bCard();
        $older = $this->import('v1', [$this->row('N/IF005', 'Alarm', 50.00, 40.00)]);
        $newer = $this->import('v2', [$this->row('N/IF005', 'Alarm', 55.00, 44.00)]);

        // jeden wpis na producenta: obie aktualizacje trafiły do tego samego cennika
        $this->assertSame($older['price_list']->id, $newer['price_list']->id);
        $this->assertSame($newer['price_list']->id, $this->fileSlot($card)?->price_list_id);

        // cofnięcie starszej aktualizacji: kartę przyniosła też nowsza, więc zostaje razem z ceną
        $meta = app(PriceListDeletionService::class)->undoImport($older['price_list_import'], $this->user);
        $this->assertSame(0, $meta['products_deleted']);
        $this->assertSame($newer['price_list']->id, $this->fileSlot($card)?->price_list_id);

        // usunięcie cennika producenta: karta ma powiązanie B2B, więc zostaje, ale traci cenę z pliku
        $meta = app(PriceListDeletionService::class)->delete($newer['price_list']->fresh(), $this->user);
        $this->assertSame(0, $meta['products_deleted']);
        $this->assertSame(1, $meta['products_kept_shared']);
        $card->refresh();
        $this->assertNull($this->fileSlot($card));
        $this->assertTrue(ProductSourcePrice::query()->where('product_id', $card->id)->where('source_key', 'like', 'b2b:%')->exists());
        $this->assertEquals(40.80, (float) $card->catalog_price_net);
        $this->assertEquals(36.72, (float) $card->purchase_price);
    }

    public function test_deleting_file_list_switches_price_to_b2b_when_file_slot_was_effective(): void
    {
        // karta z ceną z pliku i powiązaniem B2B bez slotu B2B (np. przed pierwszym pobraniem po wdrożeniu)
        $card = Product::query()->create([
            'sku' => 'MIX-1',
            'name' => 'Karta mieszana',
            'manufacturer' => 'Anro',
            'catalog_price_net' => 10.00,
            'purchase_price' => 9.00,
            'currency' => 'PLN',
        ]);
        $account = $this->account();
        B2bProductLink::query()->create(['b2b_account_id' => $account->id, 'remote_id' => 'mix', 'product_id' => $card->id]);
        $list = $this->import('v1', [$this->row('MIX-1', 'Karta mieszana', 12.00, 11.00)])['price_list'];
        $card->refresh();
        $this->assertEquals(11.00, (float) $card->purchase_price);

        ProductSourcePrice::query()->create([
            'product_id' => $card->id,
            'source_key' => ProductSourcePrice::b2bKey($account->id),
            'b2b_account_id' => $account->id,
            'catalog_price_net' => 20.00,
            'purchase_price' => 18.00,
            'discount_percent' => 10,
            'currency' => 'PLN',
            'checked_at' => now(),
        ]);

        app(PriceListDeletionService::class)->delete($list->fresh(), $this->user);

        $card->refresh();
        $this->assertNull($this->fileSlot($card));
        $this->assertEquals(20.00, (float) $card->catalog_price_net);
        $this->assertEquals(18.00, (float) $card->purchase_price);
    }

    public function test_size_merge_moves_slots_to_winner_and_keeps_newer_conflicting_slot(): void
    {
        $winner = Product::query()->create([
            'sku' => '37695VP100',
            'name' => 'AlphaTec 37695VP Size 10.0',
            'manufacturer' => 'Ansell',
            'description' => str_repeat('Rękawice chemiczne Ansell AlphaTec. ', 3),
            // cena karty inna niż w slotach — po łączeniu ma wynikać z przeniesionych slotów
            'catalog_price_net' => 3.00,
            'purchase_price' => 3.00,
            'currency' => 'PLN',
        ]);
        $loser = Product::query()->create([
            'sku' => '37695VP070',
            'name' => 'AlphaTec 37695VP Size 7.0',
            'manufacturer' => 'Ansell',
            // cena obowiązująca z B2B różna od ceny z pliku — łączenie rozmiarów liczy koszyk ze slotu pliku
            'catalog_price_net' => 3.50,
            'purchase_price' => 3.10,
            'currency' => 'PLN',
        ]);
        $account = $this->account();
        $oldList = PriceList::query()->create(['manufacturer' => 'Ansell', 'version' => 'v1', 'product_ids' => [$winner->id]]);
        $newList = PriceList::query()->create(['manufacturer' => 'Ansell', 'version' => 'v2', 'product_ids' => [$loser->id]]);
        $this->slot($winner, ProductSourcePrice::SOURCE_FILE, 2.85, 2.85, '2026-09-01 10:00', $oldList->id);
        $this->slot($loser, ProductSourcePrice::SOURCE_FILE, 2.85, 2.85, '2026-09-10 10:00', $newList->id);
        $this->slot($loser, ProductSourcePrice::b2bKey($account->id), 3.50, 3.10, '2026-09-12 10:00', null, $account->id);

        $result = app(ProductSizeMergeService::class)->merge('Ansell', false);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['groups']);
        $this->assertNull(Product::query()->find($loser->id));
        $slots = ProductSourcePrice::query()->where('product_id', $winner->id)->get()->keyBy('source_key');
        $this->assertCount(2, $slots);
        $this->assertSame($newList->id, $slots[ProductSourcePrice::SOURCE_FILE]->price_list_id);
        $this->assertTrue($slots->has(ProductSourcePrice::b2bKey($account->id)));
        // cena obowiązująca karty docelowej przeliczona z przeniesionych slotów: plik cennika Ansell to cennik
        // producenta, a konto anro jest dla Ansell dystrybutorem — od 23.09.2026 wygrywa plik producenta
        $kept = $winner->fresh();
        $this->assertEquals(2.85, (float) $kept->catalog_price_net);
        $this->assertEquals(2.85, (float) $kept->purchase_price);
    }

    private function b2bCard(): Product
    {
        $card = Product::query()->create([
            'sku' => 'N/IF005',
            'name' => 'Alarm pożarowy',
            'manufacturer' => 'Anro',
            'catalog_price_net' => 40.80,
            'purchase_price' => 36.72,
            'discount_percent' => 10,
            'currency' => 'PLN',
        ]);
        $account = $this->account();
        B2bProductLink::query()->create(['b2b_account_id' => $account->id, 'remote_id' => '5', 'product_id' => $card->id]);
        $this->slot($card, ProductSourcePrice::b2bKey($account->id), 40.80, 36.72, '2026-09-14 02:10', null, $account->id);

        return $card;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(['username' => 'jan'], [
            'password' => 'sekret',
            'sites' => ['b2b.anro.net.pl'],
            'connector' => 'anro',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    private function slot(Product $product, string $key, float $catalog, float $purchase, string $checkedAt, ?int $priceListId, ?int $accountId = null): void
    {
        ProductSourcePrice::query()->create([
            'product_id' => $product->id,
            'source_key' => $key,
            'b2b_account_id' => $accountId,
            'price_list_id' => $priceListId,
            'catalog_price_net' => $catalog,
            'purchase_price' => $purchase,
            'discount_percent' => 0,
            'currency' => 'PLN',
            'checked_at' => CarbonImmutable::parse($checkedAt),
        ]);
    }

    private function fileSlot(Product $product): ?ProductSourcePrice
    {
        return ProductSourcePrice::query()
            ->where('product_id', $product->id)
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $sku, string $name, float $catalog, float $purchase, ?string $category = null): array
    {
        return array_filter([
            'sku' => $sku,
            'name' => $name,
            'catalog_price_net' => $catalog,
            'purchase_price' => $purchase,
            'currency' => 'PLN',
            'category' => $category,
        ], static fn ($v): bool => $v !== null);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function import(string $version, array $rows, string $manufacturer = 'Anro'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'slotimp').'.pdf';
        file_put_contents($path, "%PDF-1.4\n");
        $file = new UploadedFile($path, 'cennik.pdf', 'application/pdf', null, true);

        try {
            return app(PriceListImportService::class)->importFromProducts($file, $manufacturer, $version, $this->user, $rows);
        } finally {
            @unlink($path);
        }
    }
}
