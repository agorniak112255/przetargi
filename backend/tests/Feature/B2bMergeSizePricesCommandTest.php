<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\MergeB2bSizePricesJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\CardRedirect;
use App\Models\Client;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\ProductVariantPriceHistory;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bGroupsSizes;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bSizePriceMerger;
use App\Services\Catalog\CardRedirectStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Etap 2 (28.09.2026): b2b:merge-size-prices scala karty rozbite dawniej według ceny rozmiaru, z listy size_spread
 * pełnego przebiegu. Stan tworzony drogą synchronizacji: stary podział (grupy bez cen pozycji) → przebieg po zmianie
 * (ceny rozmiarów, lista do scalenia) → polecenie → kolejny przebieg nie widzi zmiany ceny ani kart do scalenia.
 */
final class B2bMergeSizePricesCommandTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTION = 'Kurtka robocza z membraną. Wodoszczelna, oddychająca.';

    private User $user;

    private B2bAccount $account;

    private MergeSizePriceFakeConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
        // konto łącznika mascot — karty marki MASCOT są jego kartami producenta (CardOwnership)
        $this->account = B2bAccount::query()->create([
            'username' => '15744', 'password' => 'sekret', 'sites' => ['b2b.mascot.dk'], 'connector' => 'mascot',
            'created_by' => $this->user->id, 'updated_by' => $this->user->id,
        ]);
        $this->connector = new MergeSizePriceFakeConnector;
    }

    public function test_preview_lists_the_group_and_writes_nothing(): void
    {
        [$small, $large] = $this->legacySplit(['S', 'M', 'L'], 100.0, ['XL'], 120.0);
        $before = $this->state();

        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id])
            ->expectsOutputToContain('K1: zostaje #'.$small->id.' (K1 S) ← #'.$large->id.' · rozmiarów 4 · 100,00–120,00 PLN · SKU → K1')
            ->expectsOutputToContain('Do scalenia: 1')
            ->assertSuccessful();

        $this->assertSame($before, $this->state());
    }

    public function test_apply_merges_cards_moves_sizes_and_the_next_sync_sees_no_change(): void
    {
        [$small, $large] = $this->legacySplit(['S', 'M', 'L'], 100.0, ['XL'], 120.0);
        $sizeIds = ProductVariant::query()->orderBy('id')->pluck('id')->all();
        $variantHistory = ProductVariantPriceHistory::query()->count();
        $tender = $this->tender();
        $cheap = TenderItem::query()->create(['tender_id' => $tender->id, 'line_no' => 1, 'requirement' => 'Kurtka', 'main_product_id' => $small->id]);
        $event = DB::table('search_events')->insertGetId(['query' => 'kurtka', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('search_event_actions')->insert([
            ['search_event_id' => $event, 'product_id' => $small->id, 'action' => 'open', 'created_at' => now(), 'updated_at' => now()],
            ['search_event_id' => $event, 'product_id' => $large->id, 'action' => 'open', 'created_at' => now(), 'updated_at' => now()],
            ['search_event_id' => $event, 'product_id' => $large->id, 'action' => 'pick', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id, '--apply' => true])
            ->expectsOutputToContain('Scalono wyrobów: 1')
            ->assertSuccessful();

        $this->assertNull(Product::query()->find($large->id));
        $keep = $small->fresh();
        $this->assertSame('K1', $keep->sku);
        $this->assertSame('Kurtka K1', $keep->name);
        $this->assertSame(self::DESCRIPTION, $keep->description);
        $this->assertSame('Rozmiary: S; M; L; XL', $keep->variant_summary);
        // kody łączonych kart, potem dawny kod karty, która zostaje
        $this->assertSame(['K1 XL', 'K1 S'], $keep->enrichment_payload['merged_size_skus']);
        // rozmiary przeniesione (te same wiersze, historia cen przy nich), powiązania na karcie, która zostaje
        $this->assertSame($sizeIds, ProductVariant::query()->where('product_id', $keep->id)->orderBy('id')->pluck('id')->all());
        $this->assertSame($variantHistory, ProductVariantPriceHistory::query()->count());
        $this->assertSame(4, B2bProductLink::query()->where('product_id', $keep->id)->count());
        // cena karty = najniższy rozmiar, najwyższa przy slocie
        $slot = ProductSourcePrice::query()->where('product_id', $keep->id)->sole();
        $this->assertSame(['100.00', '120.00'], [(string) $slot->purchase_price, (string) $slot->size_price_max]);
        $this->assertSame('100.00', (string) $keep->purchase_price);
        $this->assertSame($keep->id, (int) $cheap->fresh()->main_product_id);
        // działania z wyszukiwarki: „open” już jest na karcie, która zostaje — duplikat znika z kartą; „pick” przechodzi
        $this->assertSame(['open', 'pick'], DB::table('search_event_actions')->where('product_id', $keep->id)->orderBy('action')->pluck('action')->all());

        $backup = glob(storage_path('app/repair-backups/size-prices-'.$this->account->id.'-*.jsonl'));
        $this->assertNotEmpty($backup);
        $lines = array_map(static fn (string $l): array => json_decode($l, true), file(end($backup), FILE_IGNORE_NEW_LINES));
        $this->assertSame(['before', 'committed'], array_column($lines, 'status'));
        $this->assertSame([$large->id], $lines[0]['drop_product_ids']);
        $this->assertSame(['keep', 'drop'], array_column($lines[0]['cards'], 'role'));
        $this->assertCount(1, $lines[0]['cards'][1]['rows']['product_variants']);
        foreach ($backup as $file) {
            @unlink($file);
        }

        // kolejny przebieg: zwykła droga grupy na karcie, która zostaje — bez zmian cen, nowych kart i listy do scalenia
        $this->travel(5)->minutes();
        $next = $this->sync();
        $this->assertSame(0, $next['created'], implode(' | ', $next['errors']));
        $this->assertSame(0, $next['prices_changed']);
        $this->assertSame(0, $next['skipped']);
        $this->assertSame(0, B2bSyncRun::query()->findOrFail($next['sync_run_id'])->size_spread['total']);
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(self::DESCRIPTION, $keep->fresh()->description);
        $this->assertSame('100.00', (string) $keep->fresh()->purchase_price);
        $this->assertSame(0, ProductVariant::query()->whereNotNull('removed_at')->count());
    }

    public function test_expensive_keeper_gets_the_lowest_size_price_and_a_history_row(): void
    {
        // karta 120 zł ma więcej rozmiarów — zostaje ona, a jej cena spada do najtańszego rozmiaru
        [$small, $large] = $this->legacySplit(['S'], 100.0, ['M', 'L', 'XL'], 120.0);
        $history = ProductPriceHistory::query()->where('product_id', $large->id)->count();

        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id, '--apply' => true])->assertSuccessful();

        $this->assertNull(Product::query()->find($small->id));
        $keep = $large->fresh();
        $this->assertSame('100.00', (string) $keep->purchase_price);
        $this->assertSame('120.00', (string) ProductSourcePrice::query()->where('product_id', $keep->id)->value('size_price_max'));
        $this->assertSame($history + 1, ProductPriceHistory::query()->where('product_id', $keep->id)->count());
        $this->assertSame('100.00', (string) ProductPriceHistory::query()->where('product_id', $keep->id)->orderByDesc('id')->value('purchase_price'));
        $this->cleanBackups();

        $this->travel(5)->minutes();
        $next = $this->sync();
        $this->assertSame(0, $next['prices_changed'], implode(' | ', $next['errors']));
    }

    public function test_tender_item_on_a_single_size_card_gets_that_size_as_offer_variant(): void
    {
        [$small, $large] = $this->legacySplit(['S', 'M', 'L'], 100.0, ['XL'], 120.0);
        $item = TenderItem::query()->create(['tender_id' => $this->tender()->id, 'line_no' => 1, 'requirement' => 'Kurtka XL', 'main_product_id' => $large->id]);

        // karta XL ma jeden rozmiar — pozycja dostaje go jako wariant, marża z jego ceny, więc bez --with-tenders
        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id, '--apply' => true])
            ->expectsOutputToContain('Scalono wyrobów: 1')
            ->assertSuccessful();
        $fresh = $item->fresh();
        $this->assertSame($small->id, (int) $fresh->main_product_id);
        $this->assertNotNull($fresh->offerVariant());
        $this->assertSame('XL', $fresh->main_variant_label);
        $this->assertSame('auto', $fresh->main_variant_source);
        $this->assertSame('120.00', (string) $fresh->offerVariant()->purchase_price);
        $this->cleanBackups();
    }

    public function test_tender_item_on_the_more_expensive_card_with_several_sizes_needs_with_tenders(): void
    {
        [$small, $large] = $this->legacySplit(['S', 'M', 'L'], 100.0, ['XL', 'XXL'], 120.0);
        $item = TenderItem::query()->create(['tender_id' => $this->tender()->id, 'line_no' => 1, 'requirement' => 'Kurtka XL', 'main_product_id' => $large->id]);

        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id, '--apply' => true])
            ->expectsOutputToContain('(droższy rozmiar) jest w pozycjach przetargów')
            ->expectsOutputToContain('Scalono wyrobów: 0')
            ->assertSuccessful();
        $this->assertNotNull(Product::query()->find($large->id));

        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id, '--apply' => true, '--with-tenders' => true])
            ->expectsOutputToContain('Scalono wyrobów: 1')
            ->assertSuccessful();
        $fresh = $item->fresh();
        $this->assertSame($small->id, (int) $fresh->main_product_id);
        // dwa rozmiary na dawnej karcie — którego dotyczyła oferta, nie wiadomo
        $this->assertNull($fresh->main_variant_id);
        $this->cleanBackups();
    }

    public function test_colour_group_merges_under_the_colourless_card_name_and_connector_summary(): void
    {
        // dawniej karta na kolor (3M: „…, biały, G3000CUV-VI”), teraz łącznik podaje kolory jako pozycje jednej karty
        $colour = static fn (string $id, string $colour, float $net, bool $member): array => [
            'remote_id' => $id, 'sku' => 'G3000CUV-'.$id, 'name' => 'Hełm G3000, '.$colour.', G3000CUV-'.$id,
        ] + ($member ? ['availability' => 'Na stanie', 'size' => $colour, 'price' => new B2bRemotePrice(net: $net)] : []);
        $this->connector->items = array_map(fn (array $m): B2bRemoteProduct => new B2bRemoteProduct(
            remoteId: $m['remote_id'], sku: $m['remote_id'], name: $m['name'],
            raw: ['price' => 50.0, 'description' => self::DESCRIPTION], availability: 'Na stanie',
        ), [$colour('VI', 'biały', 50.0, false), $colour('RD', 'czerwony', 50.0, false)]);
        $legacy = $this->sync();
        $this->assertSame(2, $legacy['created'], implode(' | ', $legacy['errors']));
        $white = Product::query()->where('sku', 'VI')->sole();
        $red = Product::query()->where('sku', 'RD')->sole();
        $item = TenderItem::query()->create(['tender_id' => $this->tender()->id, 'line_no' => 1, 'requirement' => 'Hełm czerwony', 'main_product_id' => $red->id]);
        // galerie kart kolorów: po scaleniu zostaje jedno zdjęcie na kolor (decyzja właściciela 28.09.2026)
        $gallery = fn (Product $card, int $count): array => array_map(fn (int $i): ProductImage => ProductImage::query()->create([
            'product_id' => $card->id, 'b2b_account_id' => $this->account->id, 'path' => 'products/'.$card->sku.'-'.$i.'.jpg',
            'source_url' => 'https://b2b.example.test/'.$card->sku.'-'.$i.'.jpg', 'is_primary' => $i === 0, 'sort_order' => $i,
        ]), range(0, $count - 1));
        [$whiteMain] = $gallery($white, 3);
        [$redMain] = $gallery($red, 2);

        $this->travel(5)->minutes();
        $members = [$colour('VI', 'biały', 50.0, true), $colour('RD', 'czerwony', 55.0, true)];
        $this->connector->items = [new B2bRemoteProduct(
            remoteId: 'VI', sku: 'VI', name: $members[0]['name'], raw: ['price' => 50.0, 'description' => self::DESCRIPTION],
            availability: 'Na stanie', variantSummary: 'Kolory: biały (G3000CUV-VI); czerwony (G3000CUV-RD)',
            members: $members, cardName: 'Hełm G3000, G3000CUV',
        )];
        $after = $this->sync();
        $this->assertSame(1, B2bSyncRun::query()->findOrFail($after['sync_run_id'])->size_spread['total'], implode(' | ', $after['errors']));
        $this->travel(5)->minutes();

        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id, '--apply' => true])
            ->expectsOutputToContain('Scalono wyrobów: 1')
            ->assertSuccessful();

        $keep = $white->fresh();
        $this->assertNull(Product::query()->find($red->id));
        $this->assertSame('Hełm G3000, G3000CUV', $keep->name);
        $this->assertSame('Kolory: biały (G3000CUV-VI); czerwony (G3000CUV-RD)', $keep->variant_summary);
        // pozycja stała na karcie czerwonego — czerwony jest wariantem jej oferty (marża z jego ceny)
        $fresh = $item->fresh();
        $this->assertSame($keep->id, (int) $fresh->main_product_id);
        $this->assertSame('czerwony', $fresh->main_variant_label);
        $this->assertSame('G3000CUV-RD', $fresh->main_variant_sku);
        $this->assertSame('55.00', (string) $fresh->offerVariant()?->purchase_price);
        // główne zdjęcie każdej karty koloru zostaje, reszta przez odrzucenie (galeria dostawcy ich nie dołoży)
        $this->assertSame([$whiteMain->id, $redMain->id], ProductImage::query()->where('product_id', $keep->id)->orderBy('id')->pluck('id')->all());
        $this->assertSame(3, ProductImageRejection::query()->where('product_id', $keep->id)->where('reason', ProductImageRejection::REASON_COLOUR_GALLERY)->count());
        // kopia scalenia ma galerie sprzed scalenia — polecenie porządkujące nie ma już czego usuwać
        $this->artisan('b2b:trim-colour-gallery', ['account' => $this->account->id])
            ->expectsOutputToContain('Kart modeli: 0 · zdjęć ponad jedno na kolor: 0')
            ->assertSuccessful();
        $this->cleanBackups();
    }

    public function test_size_merge_keeps_every_image(): void
    {
        [$small, $large] = $this->legacySplit(['S', 'M', 'L'], 100.0, ['XL'], 120.0);
        foreach ([$small, $small, $large, $large] as $i => $card) {
            ProductImage::query()->create([
                'product_id' => $card->id, 'b2b_account_id' => $this->account->id, 'path' => 'products/k1-'.$i.'.jpg',
                'source_url' => 'https://b2b.example.test/k1-'.$i.'.jpg', 'is_primary' => $i % 2 === 0, 'sort_order' => $i % 2,
            ]);
        }

        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id, '--apply' => true])
            ->expectsOutputToContain('Scalono wyrobów: 1')
            ->assertSuccessful();

        // rozmiary to jeden wyrób — galeria cała
        $this->assertSame(4, ProductImage::query()->where('product_id', $small->id)->count());
        $this->assertSame(0, ProductImageRejection::query()->count());
        $this->cleanBackups();
    }

    public function test_trim_command_leaves_one_image_per_colour_card_from_the_merge_backup(): void
    {
        $keep = Product::query()->create(['sku' => 'M1', 'name' => 'Model M1', 'manufacturer' => 'MASCOT', 'currency' => 'PLN']);
        $image = fn (int $i, bool $primary, int $order): array => ProductImage::query()->create([
            'product_id' => $keep->id, 'b2b_account_id' => $this->account->id, 'path' => 'products/m1-'.$i.'.jpg',
            'source_url' => 'https://b2b.example.test/m1-'.$i.'.jpg', 'is_primary' => $primary, 'sort_order' => $order,
        ])->only(['id', 'is_primary', 'sort_order']);
        // galerie kart kolorów sprzed scalenia (dziś wszystkie na karcie modelu): karta modelu 2 zdjęcia, łączona 3
        $keepRows = [$image(1, true, 0), $image(2, false, 1)];
        $dropRows = [$image(3, false, 2), $image(4, true, 0), $image(5, false, 1)];
        // karta, która już przed scaleniem była kartą modelu (zdjęcie na kolor) — jej galeria zostaje cała
        $modelRows = [$image(6, true, 0), $image(7, false, 1)];
        $later = ProductImage::query()->create([
            'product_id' => $keep->id, 'path' => 'products/m1-web.jpg', 'source_url' => 'https://web.example.test/m1.jpg', 'sort_order' => 9,
        ]);
        $line = static fn (array $row): string => json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
        $cards = [
            ['role' => 'keep', 'product' => ['id' => $keep->id], 'rows' => ['product_images' => $keepRows]],
            ['role' => 'drop', 'product' => ['id' => 999001], 'rows' => ['product_images' => $dropRows]],
            ['role' => 'drop', 'product' => ['id' => 999002, 'variant_summary' => 'Kolory: biel, czerń'], 'rows' => ['product_images' => $modelRows]],
        ];
        @mkdir(storage_path('app/repair-backups'), 0775, true);
        file_put_contents(
            storage_path('app/repair-backups/size-prices-'.$this->account->id.'-20260928-190000.jsonl'),
            $line(['status' => 'before', 'sku' => 'M1', 'keep_product_id' => $keep->id, 'group' => ['variant_summary' => 'Kolory: czarny, granat'], 'cards' => $cards])
            .$line(['status' => 'committed', 'sku' => 'M1', 'keep_product_id' => $keep->id])
            // scalenie rozmiarów i scalenie wycofane — bez zmian
            .$line(['status' => 'before', 'sku' => 'M2', 'keep_product_id' => $keep->id, 'group' => ['variant_summary' => 'Rozmiary: S; M'], 'cards' => $cards])
            .$line(['status' => 'committed', 'sku' => 'M2', 'keep_product_id' => $keep->id])
            .$line(['status' => 'before', 'sku' => 'M3', 'keep_product_id' => $keep->id, 'group' => ['variant_summary' => 'Kolory: biel'], 'cards' => $cards])
            .$line(['status' => 'rolled_back', 'sku' => 'M3']),
        );

        $this->artisan('b2b:trim-colour-gallery', ['account' => $this->account->id])
            ->expectsOutputToContain('Kart modeli: 1 · zdjęć ponad jedno na kolor: 3')
            ->assertSuccessful();
        $this->assertSame(8, ProductImage::query()->where('product_id', $keep->id)->count());

        $this->artisan('b2b:trim-colour-gallery', ['account' => $this->account->id, '--apply' => true])
            ->expectsOutputToContain('usunięte: 3')
            ->assertSuccessful();
        // główne zdjęcie każdej karty koloru i zdjęcie spoza scalanych galerii zostają
        $this->assertSame(
            [$keepRows[0]['id'], $dropRows[1]['id'], $modelRows[0]['id'], $modelRows[1]['id'], $later->id],
            ProductImage::query()->where('product_id', $keep->id)->orderBy('id')->pluck('id')->all(),
        );
        $this->artisan('b2b:trim-colour-gallery', ['account' => $this->account->id])
            ->expectsOutputToContain('Kart modeli: 0 · zdjęć ponad jedno na kolor: 0')
            ->assertSuccessful();
        $this->cleanBackups();
    }

    public function test_stale_link_redirect_and_file_slot_skip_the_group(): void
    {
        [$small, $large] = $this->legacySplit(['S', 'M', 'L'], 100.0, ['XL'], 120.0);

        // pozycja przeniesiona na inną kartę po przebiegu
        B2bProductLink::query()->where('remote_id', 'K1-XL')->update(['product_id' => $small->id]);
        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id])
            ->expectsOutputToContain('pozycja K1-XL nie jest już na karcie #'.$large->id)
            ->expectsOutputToContain('Do scalenia: 0')
            ->assertSuccessful();
        B2bProductLink::query()->where('remote_id', 'K1-XL')->update(['product_id' => $large->id]);

        // decyzja człowieka w mapie połączeń
        CardRedirect::query()->create([
            'source_key' => ProductSourcePrice::b2bKey((int) $this->account->id), 'position_key' => 'K1-XL',
            'b2b_account_id' => $this->account->id, 'product_id' => $large->id, 'reason' => CardRedirect::REASON_SPLIT,
            'target_snapshot' => CardRedirectStore::snapshot($large), 'created_by' => $this->user->id,
        ]);
        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id])
            ->expectsOutputToContain('pozycja K1-XL ma decyzję w mapie połączeń')
            ->assertSuccessful();
        CardRedirect::query()->delete();

        // pozycja innego źródła wskazana ręcznie na łączoną kartę — decyzja człowieka o tej karcie
        CardRedirect::query()->create([
            'source_key' => 'b2b:999', 'position_key' => 'DYST-XL', 'product_id' => $large->id, 'reason' => 'merge',
            'target_snapshot' => CardRedirectStore::snapshot($large), 'created_by' => $this->user->id,
        ]);
        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id])
            ->expectsOutputToContain('łączona karta #'.$large->id.' ma decyzje w mapie połączeń')
            ->assertSuccessful();
        CardRedirect::query()->delete();

        // cennik z pliku: import po SKU odtworzyłby łączoną kartę
        ProductSourcePrice::query()->create([
            'product_id' => $large->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'purchase_price' => 110,
            'catalog_price_net' => 130, 'currency' => 'PLN', 'checked_at' => now(),
        ]);
        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id, '--apply' => true])
            ->expectsOutputToContain('cennika z pliku')
            ->expectsOutputToContain('Scalono wyrobów: 0')
            ->assertSuccessful();
        $this->assertNotNull(Product::query()->find($large->id));
        $this->cleanBackups();
    }

    public function test_redirect_of_another_source_on_the_card_that_stays_does_not_block_and_stays_on_it(): void
    {
        [$small, $large] = $this->legacySplit(['S', 'M', 'L'], 100.0, ['XL'], 120.0);
        // 28.09.2026: pozycja dystrybutora „G3000 biały” połączona ręcznie z kartą białego hełmu 3M, która zostaje
        $redirect = CardRedirect::query()->create([
            'source_key' => 'b2b:999', 'position_key' => 'DYST-S', 'product_id' => $small->id, 'reason' => 'merge',
            'target_snapshot' => CardRedirectStore::snapshot($small), 'created_by' => $this->user->id,
        ]);

        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id, '--apply' => true])
            ->expectsOutputToContain('Scalono wyrobów: 1')
            ->assertSuccessful();

        $this->assertNull(Product::query()->find($large->id));
        $this->assertSame($small->id, (int) $redirect->fresh()->product_id);
        $this->cleanBackups();
    }

    public function test_running_sync_stops_the_command_and_limit_merges_only_the_first_groups(): void
    {
        $this->connector->items = [
            $this->legacyGroup('K1', ['S', 'M'], 100.0), $this->legacyGroup('K1', ['XL'], 120.0),
            $this->legacyGroup('K2', ['S', 'M'], 50.0), $this->legacyGroup('K2', ['XL'], 60.0),
        ];
        $this->assertSame(4, $this->sync()['created']);
        $this->travel(5)->minutes();
        $this->connector->items = [
            $this->jacket('K1', ['S' => 100.0, 'M' => 100.0, 'XL' => 120.0]),
            $this->jacket('K2', ['S' => 50.0, 'M' => 50.0, 'XL' => 60.0]),
        ];
        $this->assertSame(2, B2bSyncRun::query()->findOrFail($this->sync()['sync_run_id'])->size_spread['total']);

        $this->account->forceFill(['last_sync_status' => B2bSyncRun::STATUS_RUNNING])->save();
        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id, '--apply' => true])
            ->expectsOutputToContain('Trwa synchronizacja konta')
            ->assertFailed();
        $this->assertSame(4, Product::query()->count());
        $this->account->forceFill(['last_sync_status' => B2bSyncRun::STATUS_OK])->save();

        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id, '--apply' => true, '--limit' => 1])
            ->expectsOutputToContain('Scalono wyrobów: 1')
            ->assertSuccessful();
        $this->assertSame(3, Product::query()->count());
        $this->assertTrue(Product::query()->where('sku', 'K1')->exists());
        $this->assertTrue(Product::query()->where('sku', 'K2 S')->exists());
        $this->cleanBackups();
    }

    public function test_run_without_spread_is_refused(): void
    {
        $this->connector->items = [$this->legacyGroup('K1', ['S'], 100.0)];
        $this->sync();
        B2bSyncRun::query()->update(['size_spread' => null]);

        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id])
            ->expectsOutputToContain('nie ma listy kart rozbitych według ceny')
            ->assertFailed();
    }

    public function test_panel_preview_then_apply_run_as_background_job(): void
    {
        [$small, $large] = $this->legacySplit(['S', 'M', 'L'], 100.0, ['XL'], 120.0);
        Sanctum::actingAs($this->user);

        $this->getJson('/api/b2b-accounts')->assertOk()->assertJsonPath('0.size_price_merge', true);
        $this->getJson('/api/b2b-accounts/'.$this->account->id.'/size-merge')
            ->assertOk()
            ->assertJsonPath('spread.total', 1)
            ->assertJsonPath('spread.reason', null)
            ->assertJsonPath('state', null);

        $this->postJson('/api/b2b-accounts/'.$this->account->id.'/size-merge', ['mode' => 'preview'])
            ->assertStatus(202)
            ->assertJsonPath('state.status', 'queued');
        // drugie uruchomienie w trakcie — odmowa
        $this->postJson('/api/b2b-accounts/'.$this->account->id.'/size-merge', ['mode' => 'apply'])->assertStatus(409);
        $this->runQueuedMergeJob();
        $preview = $this->getJson('/api/b2b-accounts/'.$this->account->id.'/size-merge')->assertOk()->json('state');
        $this->assertSame(['done', 1, 1, 0], [$preview['status'], $preview['processed'], $preview['to_merge'], $preview['merged']]);
        $this->assertStringContainsString('+ K1: zostaje #'.$small->id, $preview['lines'][0]);
        $this->assertNotNull(Product::query()->find($large->id));

        $this->postJson('/api/b2b-accounts/'.$this->account->id.'/size-merge', ['mode' => 'apply', 'limit' => 10])->assertStatus(202);
        $this->runQueuedMergeJob();
        $applied = $this->getJson('/api/b2b-accounts/'.$this->account->id.'/size-merge')->assertOk()->json('state');
        $this->assertSame(['done', 1, null], [$applied['status'], $applied['merged'], $applied['error']]);
        $this->assertStringContainsString('✓ K1: zostaje #'.$small->id.' (K1)', $applied['lines'][0]);
        $this->assertNull(Product::query()->find($large->id));
        $this->assertFileExists($applied['backup_path']);
        $this->cleanBackups();
    }

    public function test_panel_refuses_accounts_without_size_prices_and_running_sync(): void
    {
        Sanctum::actingAs($this->user);
        $other = B2bAccount::query()->create([
            'username' => 'x', 'password' => 'y', 'sites' => ['b2b.p4s.example'], 'connector' => 'p4s',
            'created_by' => $this->user->id, 'updated_by' => $this->user->id,
        ]);
        $this->postJson('/api/b2b-accounts/'.$other->id.'/size-merge', ['mode' => 'preview'])->assertStatus(422);

        $this->postJson('/api/b2b-accounts/'.$this->account->id.'/size-merge', ['mode' => 'preview'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'udanego przebiegu'));

        $this->account->forceFill(['last_sync_status' => B2bSyncRun::STATUS_RUNNING])->save();
        $this->postJson('/api/b2b-accounts/'.$this->account->id.'/size-merge', ['mode' => 'apply'])->assertStatus(409);
    }

    public function test_job_of_an_older_start_does_nothing(): void
    {
        $this->legacySplit(['S', 'M', 'L'], 100.0, ['XL'], 120.0);
        Sanctum::actingAs($this->user);
        $this->postJson('/api/b2b-accounts/'.$this->account->id.'/size-merge', ['mode' => 'preview'])->assertStatus(202);

        (new MergeB2bSizePricesJob((int) $this->account->id, 'inny-znacznik'))->handle(app(B2bSizePriceMerger::class));

        $this->assertSame('queued', MergeB2bSizePricesJob::state((int) $this->account->id)['status']);
    }

    public function test_old_card_under_a_legacy_remote_id_joins_the_spread_and_is_merged(): void
    {
        // Protekt do 28.09.2026: pozycje bez members, inna cena = karta „numer / kolor” (tu fałszywy podział długości)
        $single = fn (string $remoteId, float $price): B2bRemoteProduct => new B2bRemoteProduct(
            remoteId: $remoteId, sku: $remoteId, name: 'Linka K1', raw: ['price' => $price, 'description' => self::DESCRIPTION], availability: 'Na stanie',
        );
        $this->connector->items = [$single('K1', 100.0), $single('K1 / biały', 120.0)];
        $this->assertSame(2, $this->sync()['created']);
        $main = Product::query()->where('sku', 'K1')->sole();
        $fake = Product::query()->where('sku', 'K1 / biały')->sole();
        $this->travel(5)->minutes();

        // po zmianie: jeden wyrób, druga długość ma nowe remote_id i dawne w legacy_remote_id
        $this->connector->items = [new B2bRemoteProduct(
            remoteId: 'K1',
            sku: 'K1',
            name: 'Linka K1',
            raw: ['price' => 100.0, 'description' => self::DESCRIPTION],
            availability: 'Na stanie',
            members: [
                ['remote_id' => 'K1', 'sku' => 'K1', 'name' => 'Linka K1 1,4 m', 'size' => '1,4 m', 'price' => new B2bRemotePrice(net: 100.0)],
                ['remote_id' => 'K1 ~p2', 'sku' => 'K1', 'name' => 'Linka K1 2 m', 'size' => '2 m', 'price' => new B2bRemotePrice(net: 120.0), 'legacy_remote_id' => 'K1 / biały'],
            ],
        )];
        $after = $this->sync();
        $this->assertSame([0, 0], [$after['created'], $after['prices_changed']], implode(' | ', $after['errors']));
        $spread = B2bSyncRun::query()->findOrFail($after['sync_run_id'])->size_spread;
        $this->assertSame([$main->id, $fake->id], $spread['groups'][0]['cards']);
        $this->assertSame($fake->id, (int) B2bProductLink::query()->where('remote_id', 'K1 ~p2')->value('product_id'));
        $this->assertSame(['2 m'], ProductVariant::query()->where('product_id', $fake->id)->pluck('label')->all());
        $this->travel(5)->minutes();

        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id, '--apply' => true])
            ->expectsOutputToContain('Scalono wyrobów: 1')
            ->assertSuccessful();
        $this->assertNull(Product::query()->find($fake->id));
        $this->assertSame(['1,4 m', '2 m'], ProductVariant::query()->where('product_id', $main->id)->orderBy('sort_order')->pluck('label')->all());
        $this->assertSame(['K1', 'K1 / biały', 'K1 ~p2'], B2bProductLink::query()->where('product_id', $main->id)->orderBy('remote_id')->pluck('remote_id')->all());
        $this->assertSame('120.00', (string) ProductSourcePrice::query()->where('product_id', $main->id)->value('size_price_max'));
        $this->cleanBackups();
    }

    public function test_distributor_cards_of_other_brands_merge_but_a_card_owned_by_the_brand_account_does_not(): void
    {
        // konto rawpol (dystrybutor wielu marek) — jego karty BUFF nie mają właściciela
        $this->account = B2bAccount::query()->create([
            'username' => 'supon123', 'password' => 'sekret', 'sites' => ['b2b.raw-pol.com'], 'connector' => 'rawpol',
            'created_by' => $this->user->id, 'updated_by' => $this->user->id,
        ]);
        $this->connector->brand = 'BUFF';
        [$small, $large] = $this->legacySplit(['S', 'M', 'L'], 100.0, ['XL'], 120.0);

        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id])
            ->expectsOutputToContain('Do scalenia: 1')
            ->assertSuccessful();

        // karta z cennikiem producenta z pliku ma właściciela — dystrybutor jej nie scala
        $list = PriceList::query()->create([
            'manufacturer' => 'BUFF', 'version' => '1', 'original_filename' => 'buff.xlsx', 'imported_by' => $this->user->id,
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $large->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id,
            'purchase_price' => 110, 'catalog_price_net' => 130, 'currency' => 'PLN', 'checked_at' => now(),
        ]);
        $this->artisan('b2b:merge-size-prices', ['account' => $this->account->id])
            ->expectsOutputToContain('Do scalenia: 0')
            ->assertSuccessful();
        $this->assertNotNull($small->fresh());
    }

    public function test_process_stops_at_the_deadline_and_resumes_from_the_offset(): void
    {
        $this->legacySplit(['S', 'M', 'L'], 100.0, ['XL'], 120.0);
        $merger = app(B2bSizePriceMerger::class);
        $groups = $merger->spread($this->account)['groups'];

        $paused = $merger->process($this->account, $groups, 0, false, false, null, 0, null, microtime(true) - 1);
        $this->assertSame([0, false, 0, []], [$paused['offset'], $paused['done'], $paused['to_merge'], $paused['lines']]);

        $resumed = $merger->process($this->account, $groups, $paused['offset'], false, false, null, 0, null, null);
        $this->assertSame([1, true, 1], [$resumed['offset'], $resumed['done'], $resumed['to_merge']]);
        // limit liczony razem z poprzednimi porcjami
        $limited = $merger->process($this->account, $groups, 0, false, false, 1, 1, null, null);
        $this->assertSame([0, true, 0], [$limited['offset'], $limited['done'], $limited['to_merge']]);
    }

    /** Zadanie zlecone przez panel (Queue::fake) — wykonane tak, jak zrobiłby to worker. */
    private function runQueuedMergeJob(): void
    {
        $jobs = Queue::pushed(MergeB2bSizePricesJob::class);
        $this->assertNotEmpty($jobs);
        $jobs->last()->handle(app(B2bSizePriceMerger::class));
    }

    /**
     * Stary podział (grupy bez cen pozycji), potem przebieg po zmianie — karty dostają rozmiary, wyrób trafia do listy.
     *
     * @param  list<string>  $smallSizes
     * @param  list<string>  $largeSizes
     * @return array{0: Product, 1: Product}
     */
    private function legacySplit(array $smallSizes, float $smallPrice, array $largeSizes, float $largePrice): array
    {
        $this->connector->items = [$this->legacyGroup('K1', $smallSizes, $smallPrice), $this->legacyGroup('K1', $largeSizes, $largePrice)];
        $legacy = $this->sync();
        $this->assertSame(2, $legacy['created'], implode(' | ', $legacy['errors']));
        $small = Product::query()->where('sku', 'K1 '.$smallSizes[0])->sole();
        $large = Product::query()->where('sku', 'K1 '.$largeSizes[0])->sole();

        $this->travel(5)->minutes();
        $prices = [];
        foreach ($smallSizes as $size) {
            $prices[$size] = $smallPrice;
        }
        foreach ($largeSizes as $size) {
            $prices[$size] = $largePrice;
        }
        $this->connector->items = [$this->jacket('K1', $prices)];
        $after = $this->sync();
        $this->assertSame(0, $after['prices_changed'], implode(' | ', $after['errors']));
        $this->assertSame(1, B2bSyncRun::query()->findOrFail($after['sync_run_id'])->size_spread['total']);
        $this->travel(5)->minutes();

        return [$small, $large];
    }

    /**
     * @param  list<string>  $sizes
     */
    private function legacyGroup(string $code, array $sizes, float $price): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: $code.'-'.$sizes[0],
            sku: $code.' '.$sizes[0],
            name: 'Kurtka '.$code.' (rozm. '.implode(', ', $sizes).')',
            raw: ['price' => $price, 'description' => self::DESCRIPTION],
            availability: 'Na stanie',
            variantSummary: 'Rozmiary: '.implode('; ', $sizes),
            members: array_map(static fn (string $size): array => [
                'remote_id' => $code.'-'.$size, 'sku' => $code.' '.$size, 'name' => 'Kurtka '.$code.' '.$size,
            ], $sizes),
        );
    }

    /**
     * @param  array<string, float>  $prices  rozmiar => cena konta
     */
    private function jacket(string $code, array $prices): B2bRemoteProduct
    {
        $members = [];
        foreach ($prices as $size => $net) {
            $members[] = [
                'remote_id' => $code.'-'.$size, 'sku' => $code.' '.$size, 'name' => 'Kurtka '.$code.' '.$size,
                'availability' => 'Na stanie', 'size' => (string) $size, 'price' => new B2bRemotePrice(net: $net),
            ];
        }

        return new B2bRemoteProduct(
            remoteId: $members[0]['remote_id'],
            sku: $code,
            name: 'Kurtka '.$code,
            raw: ['price' => min($prices), 'description' => self::DESCRIPTION],
            availability: 'Na stanie',
            variantSummary: 'Rozmiary: '.implode('; ', array_map('strval', array_keys($prices))),
            members: $members,
        );
    }

    private function tender(): Tender
    {
        return Tender::query()->create([
            'number' => 'PRZ/'.random_int(1, 99999), 'title' => 'Test', 'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => $this->user->id, 'status' => 'wycena', 'ai_percent' => 0, 'last_activity_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return [
            'products' => Product::query()->orderBy('id')->get(['id', 'sku', 'name', 'purchase_price', 'variant_summary'])->toArray(),
            'links' => B2bProductLink::query()->orderBy('id')->get(['remote_id', 'product_id', 'merged_at'])->toArray(),
            'sizes' => ProductVariant::query()->orderBy('id')->get(['id', 'product_id'])->toArray(),
            'slots' => ProductSourcePrice::query()->orderBy('id')->get(['product_id', 'purchase_price', 'size_price_max'])->toArray(),
        ];
    }

    private function cleanBackups(): void
    {
        foreach (glob(storage_path('app/repair-backups/size-prices-'.$this->account->id.'-*.jsonl')) ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sync(): array
    {
        return app(B2bAccountSyncRunner::class)->run($this->account->fresh(), delayMs: 0, connector: $this->connector);
    }
}

/** Łącznik testowy marki MASCOT (konto łącznika mascot) bez sieci — cena grupy z raw['price']. */
final class MergeSizePriceFakeConnector implements B2bConnector, B2bGroupsSizes, B2bManufacturerSite
{
    /** @var list<B2bRemoteProduct> */
    public array $items = [];

    /** marka pozycji — dystrybutor wielu marek (Raw-Pol) podaje cudzą */
    public string $brand = 'MASCOT';

    public static function key(): string
    {
        return 'mascot';
    }

    public static function label(): string
    {
        return 'Mascot';
    }

    public static function host(): string
    {
        return 'b2b.mascot.dk';
    }

    public static function ownBrand(): string
    {
        return 'MASCOT';
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
        return $this->brand;
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        $net = $product->raw['price'] ?? null;

        return $net !== null ? new B2bRemotePrice(net: (float) $net) : null;
    }

    public function description(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['description'] ?? '');
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }
}
