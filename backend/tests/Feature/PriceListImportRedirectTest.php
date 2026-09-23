<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardRedirect;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Catalog\CardRedirectStore;
use App\Services\PriceListImportService;
use App\Services\SpreadsheetColumnMapper;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Import cennika z pliku czyta mapę połączeń (card_redirects, source_key „file:{cennik}”) — plan łączenia kart,
 * „Wersja uzgodniona”, krok 4: pozycja z wpisem mapy trafia na kartę z mapy przed zwykłym dopasowaniem, bez zmiany
 * SKU, nazwy i producenta karty; wiersze jednej karty razem (jedna cena albo ostrzeżenie o różnych cenach).
 */
final class PriceListImportRedirectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create();
    }

    public function test_distributor_file_card_merged_into_producer_card_updates_producer_card_on_reimport(): void
    {
        $first = $this->importProducts('v1', [$this->row('D-100', 'Rękawica powlekana dystrybutor', 12.00, 10.00)]);
        $distributorCard = Product::query()->where('sku', 'D-100')->sole();
        $list = $first['price_list'];

        // karta producenta z konta B2B, marka zapisana inaczej niż w cenniku („ANRO” / „Anro”)
        $producer = Product::query()->create([
            'sku' => 'RP-100', 'name' => 'Rękawica RP-100 producenta', 'manufacturer' => 'ANRO', 'category' => 'Rękawice',
            'catalog_price_net' => 11.00, 'purchase_price' => 9.00, 'currency' => 'PLN',
        ]);
        (new CardRedirectStore)->recordMerge($distributorCard, $producer, CardRedirect::REASON_MERGE, null, $this->user);
        $distributorCard->delete();
        $cards = Product::query()->count();

        $result = $this->importProducts('v2', [$this->row('D-100', 'Rękawica powlekana dystrybutor NOWA', 13.00, 11.00)]);

        $this->assertSame($cards, Product::query()->count());
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame([(int) $producer->id], $result['product_ids']);
        $producer->refresh();
        $this->assertSame('RP-100', $producer->sku);
        $this->assertSame('Rękawica RP-100 producenta', $producer->name);
        $this->assertSame('ANRO', $producer->manufacturer);
        $slot = $this->fileSlot($producer);
        $this->assertNotNull($slot);
        $this->assertSame((int) $list->id, (int) $slot->price_list_id);
        $this->assertEquals(13.00, (float) $slot->catalog_price_net);
        $this->assertEquals(11.00, (float) $slot->purchase_price);
        // identyfikatory wiersza przy karcie docelowej
        $this->assertSame(
            [(int) $producer->id],
            ProductIdentifier::query()->where('position_key', 'D-100')->pluck('product_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all(),
        );
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());
        $this->assertSame(1, ProductPriceHistory::query()->where('product_id', $producer->id)->count());
    }

    public function test_merged_sizes_in_the_same_price_give_one_slot_on_the_target_card(): void
    {
        $list = $this->bolleList();
        $card = $this->producerCard();
        $this->redirect($list, ['BAX-S', 'BAX-M', 'BAX-L'], $card, CardRedirect::REASON_SIZE_MERGE);

        $result = $this->importBolle([
            ['BAX-S', 'Okulary BAXTER rozmiar S', 18.55, '3660740007768', 'BAX', 'S'],
            ['BAX-M', 'Okulary BAXTER rozmiar M', 18.55, '3660740007751', 'BAX', 'M'],
            ['BAX-L', 'Okulary BAXTER rozmiar L', 18.55, '3660740007744', 'BAX', 'L'],
        ], '2026-02');

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $card->refresh();
        $this->assertSame('BOLLE-BAX', $card->sku);
        $this->assertSame('Okulary Bolle BAXTER', $card->name);
        $this->assertEquals(18.55, (float) $this->fileSlot($card)?->catalog_price_net);
        $this->assertSame('EUR', $this->fileSlot($card)?->currency);
        $this->assertSame(9, ProductIdentifier::query()->where('product_id', $card->id)->count());
    }

    public function test_rows_of_one_target_card_in_the_same_price_write_the_slot_once(): void
    {
        $first = $this->importProducts('v1', [
            $this->row('Q-1', 'Kask ochronny biały', 30.00, 25.00),
            $this->row('Z-2', 'Nauszniki przeciwhałasowe', 30.00, 25.00),
        ]);
        $list = $first['price_list'];
        $card = Product::query()->create([
            'sku' => 'KOMPLET-1', 'name' => 'Zestaw kask z nausznikami', 'manufacturer' => 'Anro',
            'catalog_price_net' => 28.00, 'purchase_price' => 24.00, 'currency' => 'PLN',
        ]);
        foreach (['Q-1', 'Z-2'] as $sku) {
            $source = Product::query()->where('sku', $sku)->sole();
            (new CardRedirectStore)->recordMerge($source, $card, CardRedirect::REASON_MERGE, null, $this->user);
            $source->delete();
        }

        $result = $this->importProducts('v2', [
            $this->row('Q-1', 'Kask ochronny biały', 32.00, 26.00),
            $this->row('Z-2', 'Nauszniki przeciwhałasowe', 32.00, 26.00),
        ]);

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['prices_changed']);
        $this->assertCount(1, $result['updated_products']);
        $this->assertSame('KOMPLET-1', $result['updated_products'][0]['sku']);
        $card->refresh();
        $this->assertSame('KOMPLET-1', $card->sku);
        $this->assertSame('Zestaw kask z nausznikami', $card->name);
        $this->assertEquals(32.00, (float) $this->fileSlot($card)?->catalog_price_net);
        $this->assertEquals(26.00, (float) $this->fileSlot($card)?->purchase_price);
        $this->assertSame(1, ProductPriceHistory::query()->where('product_id', $card->id)->count());
    }

    public function test_merged_sizes_in_different_prices_leave_the_slot_and_warn(): void
    {
        $list = $this->bolleList();
        $card = $this->producerCard();
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id,
            'catalog_price_net' => 9.00, 'purchase_price' => 9.00, 'discount_percent' => 0, 'currency' => 'EUR',
            'checked_at' => CarbonImmutable::parse('2026-09-01 10:00'),
        ]);
        $this->redirect($list, ['BAX-S', 'BAX-M', 'BAX-L'], $card, CardRedirect::REASON_SIZE_MERGE);

        $result = $this->importBolle([
            ['BAX-S', 'Okulary BAXTER rozmiar S', 18.55, '3660740007768', 'BAX', 'S'],
            ['BAX-M', 'Okulary BAXTER rozmiar M', 19.55, '3660740007751', 'BAX', 'M'],
            ['BAX-L', 'Okulary BAXTER rozmiar L', 20.55, '3660740007744', 'BAX', 'L'],
        ], '2026-02');

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['prices_changed']);
        $this->assertEquals(9.00, (float) $this->fileSlot($card)?->catalog_price_net);
        $warning = 'karta #'.$card->id.' (połączone pozycje: BAX-S, BAX-M, BAX-L) ma w cenniku różne ceny — cena karty bez zmian; do rozdzielenia w Łączenie kart';
        $this->assertContains($warning, $result['errors']);
        // ostrzeżenie, nie pominięty wiersz
        $this->assertNotContains($warning, array_column($result['skipped_details'], 'reason'));
        $this->assertSame(0, $result['skipped']);
        $card->refresh();
        $this->assertSame('BOLLE-BAX', $card->sku);
        // każdy rozmiar ma inny EAN — żaden nie nadpisuje karty
        $this->assertNull($card->ean);
        $this->assertFalse(ProductPriceHistory::query()->where('product_id', $card->id)->exists());
        $this->assertSame(9, ProductIdentifier::query()->where('product_id', $card->id)->count());
    }

    public function test_own_row_of_size_merge_target_joins_the_group_and_keeps_card_sku(): void
    {
        // pierwszy import: rozmiary w różnych cenach → trzy karty
        $this->importBolle([
            ['BAX-S', 'Okulary BAXTER rozmiar S', 18.55, '3660740007768', 'BAX', 'S'],
            ['BAX-M', 'Okulary BAXTER rozmiar M', 19.55, '3660740007751', 'BAX', 'M'],
            ['BAX-L', 'Okulary BAXTER rozmiar L', 20.55, '3660740007744', 'BAX', 'L'],
        ], '2026-01');
        $this->assertSame(3, Product::query()->count());
        $target = Product::query()->where('sku', 'BAX-M')->sole();
        foreach (['BAX-S', 'BAX-L'] as $sku) {
            $source = Product::query()->where('sku', $sku)->sole();
            (new CardRedirectStore)->recordMerge($source, $target, CardRedirect::REASON_SIZE_MERGE, null, $this->user);
            $source->delete();
        }
        $target->update(['name' => 'Okulary BAXTER (S, M, L)']);

        // nowy cennik: ta sama cena wszystkich rozmiarów → wiersz zwinięty do kodu modelu „BAX”
        $result = $this->importBolle([
            ['BAX-S', 'Okulary BAXTER rozmiar S', 21.00, '3660740007768', 'BAX', 'S'],
            ['BAX-M', 'Okulary BAXTER rozmiar M', 21.00, '3660740007751', 'BAX', 'M'],
            ['BAX-L', 'Okulary BAXTER rozmiar L', 21.00, '3660740007744', 'BAX', 'L'],
        ], '2026-02');

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(0, $result['created']);
        $target->refresh();
        $this->assertSame('BAX-M', $target->sku);
        $this->assertSame('Okulary BAXTER (S, M, L)', $target->name);
        $this->assertEquals(21.00, (float) $this->fileSlot($target)?->catalog_price_net);

        // znowu różne ceny: własny wiersz karty (BAX-M, zwykłe dopasowanie kodu) razem z pozycjami z mapy
        $result = $this->importBolle([
            ['BAX-S', 'Okulary BAXTER rozmiar S', 22.00, '3660740007768', 'BAX', 'S'],
            ['BAX-M', 'Okulary BAXTER rozmiar M', 23.00, '3660740007751', 'BAX', 'M'],
            ['BAX-L', 'Okulary BAXTER rozmiar L', 24.00, '3660740007744', 'BAX', 'L'],
        ], '2026-03');

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertEquals(21.00, (float) $this->fileSlot($target->refresh())?->catalog_price_net);
        $this->assertContains(
            'karta #'.$target->id.' (połączone pozycje: BAX-S, BAX-M, BAX-L) ma w cenniku różne ceny — cena karty bez zmian; do rozdzielenia w Łączenie kart',
            $result['errors'],
        );
    }

    public function test_collapsed_row_with_positions_of_different_cards_is_skipped_with_reason(): void
    {
        $list = $this->bolleList();
        $small = $this->producerCard('BOLLE-BAX-S', 'Okulary Bolle BAXTER S');
        $medium = $this->producerCard('BOLLE-BAX-M', 'Okulary Bolle BAXTER M');
        $this->redirect($list, ['BAX-S'], $small, CardRedirect::REASON_SPLIT);
        $this->redirect($list, ['BAX-M'], $medium, CardRedirect::REASON_SPLIT);

        $result = $this->importBolle([
            ['BAX-S', 'Okulary BAXTER rozmiar S', 18.55, '3660740007768', 'BAX', 'S'],
            ['BAX-M', 'Okulary BAXTER rozmiar M', 18.55, '3660740007751', 'BAX', 'M'],
        ], '2026-02');

        $this->assertSame(2, Product::query()->count());
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        $ids = [(int) $small->id, (int) $medium->id];
        sort($ids);
        $reason = 'pozycje wiersza należą do różnych połączonych kart (#'.$ids[0].', #'.$ids[1].') — sprawdź w Łączenie kart';
        $this->assertContains('BAX: '.$reason, $result['errors']);
        $this->assertContains('BAX: '.$reason, array_column($result['skipped_details'], 'reason'));
        $this->assertNull($this->fileSlot($small));
        $this->assertNull($this->fileSlot($medium));
        // pozycje są w pliku: identyfikatory przy kartach z mapy, nie oznaczone jako zniknięte
        $this->assertSame(3, ProductIdentifier::query()->where('product_id', $small->id)->where('position_key', 'BAX-S')->whereNull('removed_at')->count());
        $this->assertSame(3, ProductIdentifier::query()->where('product_id', $medium->id)->where('position_key', 'BAX-M')->whereNull('removed_at')->count());
    }

    public function test_entry_without_card_warns_and_row_takes_the_usual_path(): void
    {
        $first = $this->importProducts('v1', [$this->row('D-200', 'Okulary ochronne', 8.00, 7.00)]);
        $list = $first['price_list'];
        CardRedirect::query()->create([
            'source_key' => 'file:'.$list->id, 'position_key' => 'D-200', 'price_list_id' => $list->id,
            'product_id' => null, 'reason' => CardRedirect::REASON_MERGE,
            'target_snapshot' => ['id' => 999, 'sku' => 'USUNIETA-1', 'name' => 'Karta usunięta', 'manufacturer' => 'Anro'],
        ]);

        $result = $this->importProducts('v2', [$this->row('D-200', 'Okulary ochronne', 9.00, 8.00)]);

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(1, $result['updated']);
        $this->assertEquals(9.00, (float) $this->fileSlot(Product::query()->where('sku', 'D-200')->sole())?->catalog_price_net);
        $this->assertContains(
            'D-200: pozycja D-200 była połączona z kartą #999 (USUNIETA-1), której już nie ma (decyzja bez karty) — pozycja bez przekierowania; sprawdź w Łączenie kart',
            $result['errors'],
        );
    }

    public function test_target_card_of_another_brand_is_skipped_not_overwritten(): void
    {
        $first = $this->importProducts('v1', [$this->row('D-300', 'Kask', 40.00, 35.00)]);
        $list = $first['price_list'];
        $other = Product::query()->create([
            'sku' => 'JSP-1', 'name' => 'Kask JSP', 'manufacturer' => 'JSP', 'catalog_price_net' => 41.00,
            'purchase_price' => 36.00, 'currency' => 'PLN',
        ]);
        $this->redirect($list, ['D-300'], $other, CardRedirect::REASON_MERGE);

        $result = $this->importProducts('v2', [$this->row('D-300', 'Kask', 42.00, 37.00)]);

        $this->assertSame(0, $result['updated']);
        $this->assertNull($this->fileSlot($other));
        $this->assertContains(
            'pozycja połączona z kartą #'.$other->id.' producenta JSP, a cennik podaje producenta Anro — sprawdź w Łączenie kart',
            array_column($result['skipped_details'], 'reason'),
        );
    }

    public function test_protected_card_of_producer_b2b_account_takes_only_price_and_empty_fields_from_the_file(): void
    {
        $first = $this->importProducts('v1', [$this->row('D-400', 'Rękawica dystrybutor', 12.00, 10.00)]);
        $source = Product::query()->where('sku', 'D-400')->sole();
        // karta producenta z kontem B2B producenta (Anro) — chroniona, plik nie jest jej właścicielem
        $card = Product::query()->create([
            'sku' => 'RP-400', 'name' => 'Rękawica RP-400', 'manufacturer' => 'Anro', 'ean' => '5900000000400',
            'category' => 'Rękawice', 'packaging' => null, 'catalog_price_net' => 11.00, 'purchase_price' => 9.00,
            'currency' => 'PLN',
        ]);
        $account = B2bAccount::query()->create(['username' => 'anro', 'password' => 'x', 'sites' => ['b2b.anro.pl'], 'connector' => 'anro']);
        B2bProductLink::query()->create(['b2b_account_id' => $account->id, 'remote_id' => '400', 'product_id' => $card->id]);
        (new CardRedirectStore)->recordMerge($source, $card, CardRedirect::REASON_MERGE, null, $this->user);
        $source->delete();

        $this->importProducts('v2', [[
            ...$this->row('D-400', 'Rękawica dystrybutor', 13.00, 11.00),
            'ean' => '5900000000999', 'category' => 'Inne', 'packaging' => 'para',
        ]]);

        $card->refresh();
        $this->assertSame('5900000000400', $card->ean);
        $this->assertSame('Rękawice', $card->category);
        $this->assertSame('Rękawica RP-400', $card->name);
        // puste pole uzupełnione
        $this->assertSame('para', $card->packaging);
        $slot = $this->fileSlot($card);
        $this->assertSame((int) $first['price_list']->id, (int) $slot?->price_list_id);
        $this->assertEquals(13.00, (float) $slot?->catalog_price_net);
        $this->assertEquals(11.00, (float) $slot?->purchase_price);
    }

    public function test_protected_card_of_another_producer_file_takes_only_empty_fields(): void
    {
        $first = $this->importProducts('v1', [$this->row('D-600', 'Okulary dystrybutor', 8.00, 7.00)]);
        $source = Product::query()->where('sku', 'D-600')->sole();
        // karta z plikiem producenta tej marki (inny wpis cennika) — właściciel „file”, ale nie ten cennik
        $ownerList = PriceList::query()->create([
            'manufacturer' => 'Anro Safety', 'manufacturer_key' => PriceList::manufacturerKey('Anro Safety'), 'version' => '1',
            'rows_total' => 0, 'products_created' => 0, 'products_updated' => 0, 'rows_skipped' => 0,
        ]);
        $card = Product::query()->create([
            'sku' => 'OK-600', 'name' => 'Okulary OK-600', 'manufacturer' => 'Anro', 'ean' => '5900000000600',
            'catalog_price_net' => 9.00, 'purchase_price' => 8.00, 'currency' => 'PLN',
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $ownerList->id,
            'catalog_price_net' => 9.00, 'purchase_price' => 8.00, 'discount_percent' => 0, 'currency' => 'PLN',
            'checked_at' => CarbonImmutable::parse('2026-09-01 10:00'),
        ]);
        (new CardRedirectStore)->recordMerge($source, $card, CardRedirect::REASON_MERGE, null, $this->user);
        $source->delete();

        $this->importProducts('v2', [[...$this->row('D-600', 'Okulary dystrybutor', 8.50, 7.50), 'ean' => '5900000000999', 'packaging' => 'szt']]);

        $card->refresh();
        $this->assertSame('5900000000600', $card->ean);
        $this->assertSame('szt', $card->packaging);
        $this->assertEquals(8.50, (float) $this->fileSlot($card)?->catalog_price_net);
        $this->assertSame((int) $first['price_list']->id, (int) $this->fileSlot($card)?->price_list_id);
    }

    public function test_card_owned_by_this_producer_file_is_updated_as_before(): void
    {
        $this->importProducts('v1', [
            [...$this->row('D-500', 'Kask', 40.00, 35.00), 'ean' => '5900000000500'],
            $this->row('D-501', 'Kask wersja B', 41.00, 36.00),
        ]);
        $card = Product::query()->where('sku', 'D-500')->sole();
        $source = Product::query()->where('sku', 'D-501')->sole();
        (new CardRedirectStore)->recordMerge($source, $card, CardRedirect::REASON_MERGE, null, $this->user);
        $source->delete();

        $this->importProducts('v2', [[...$this->row('D-501', 'Kask wersja B', 42.00, 37.00), 'ean' => '5900000000501']]);

        $card->refresh();
        // właściciel karty (cennik producenta tej marki) — pola z pliku jak dotąd, SKU i nazwa bez zmian
        $this->assertSame('5900000000501', $card->ean);
        $this->assertSame('D-500', $card->sku);
        $this->assertSame('Kask', $card->name);
        $this->assertEquals(42.00, (float) $this->fileSlot($card)?->catalog_price_net);
    }

    private function bolleList(): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => 'Bolle', 'manufacturer_key' => PriceList::manufacturerKey('Bolle'), 'version' => '2026-01',
            'rows_total' => 0, 'products_created' => 0, 'products_updated' => 0, 'rows_skipped' => 0,
        ]);
    }

    private function producerCard(string $sku = 'BOLLE-BAX', string $name = 'Okulary Bolle BAXTER'): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => 'BOLLE SAFETY',
            'catalog_price_net' => 9.00, 'purchase_price' => 9.00, 'currency' => 'EUR',
        ]);
    }

    /**
     * @param  list<string>  $positions
     */
    private function redirect(PriceList $list, array $positions, Product $card, string $reason): void
    {
        foreach ($positions as $position) {
            CardRedirect::query()->create([
                'source_key' => 'file:'.$list->id, 'position_key' => $position, 'price_list_id' => $list->id,
                'product_id' => $card->id, 'reason' => $reason, 'target_snapshot' => CardRedirectStore::snapshot($card),
            ]);
        }
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
    private function row(string $sku, string $name, float $catalog, float $purchase): array
    {
        return ['sku' => $sku, 'name' => $name, 'catalog_price_net' => $catalog, 'purchase_price' => $purchase, 'currency' => 'PLN'];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function importProducts(string $version, array $rows): array
    {
        $path = tempnam(sys_get_temp_dir(), 'redir').'.pdf';
        file_put_contents($path, "%PDF-1.4\n");

        try {
            $result = app(PriceListImportService::class)->importFromProducts(
                new UploadedFile($path, 'cennik.pdf', 'application/pdf', null, true),
                'Anro',
                $version,
                $this->user,
                $rows,
            );
            $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));

            return $result;
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: float, 3: string, 4: string, 5: string}>  $items  kod, nazwa, cena, EAN, model, rozmiar
     * @return array<string, mixed>
     */
    private function importBolle(array $items, string $version): array
    {
        $rows = [['Article', 'Opis produktu', 'Cena EUR', 'EAN unit', 'Model', 'Rozmiar']];
        foreach ($items as $item) {
            $rows[] = $item;
        }
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('INDUSTRIAL');
        $sheet->fromArray($rows, null, 'A1', true);
        foreach (array_keys($rows) as $i) {
            $sheet->setCellValueExplicit('D'.($i + 1), (string) $rows[$i][3], DataType::TYPE_STRING);
        }
        $path = tempnam(sys_get_temp_dir(), 'redir').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        try {
            $result = app(PriceListImportService::class)->importWithMapping(
                new UploadedFile($path, 'bolle.xlsx', null, null, true),
                'Bolle',
                $version,
                $this->user,
                app(SpreadsheetColumnMapper::class)->refineMapping($path, [
                    'currency' => 'EUR',
                    'sheets' => [[
                        'sheet' => 'INDUSTRIAL',
                        'include' => true,
                        'header_excel_row' => 1,
                        'columns' => ['sku' => 0, 'name' => 1, 'catalog_price' => 2, 'ean' => 3, 'model_key' => 4, 'packaging' => 5],
                        'repeating_headers' => false,
                        'confidence' => 1.0,
                    ]],
                ]),
            );
            $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));

            return $result;
        } finally {
            @unlink($path);
        }
    }
}
