<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RegisterManufacturerCatalogJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\BrandDictionaryEntry;
use App\Models\CardRedirect;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\ProductSpecialPrice;
use App\Models\User;
use App\Services\Catalog\ProductIdentifierStore;
use App\Services\PriceListGoodsBrand;
use App\Services\PriceListImportService;
use App\Services\SpreadsheetColumnMapper;
use App\Support\CanonicalBrand;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Cennik wielomarkowy (decyzja właściciela z 26.09.2026): Canis podpisuje plik swoją nazwą, a sprzedaje też wyroby 3M,
 * MSA i Ansella. Wiersz z marką towaru w nazwie dostaje tę markę, karta tego cennika nie jest pomijana z powodu marki,
 * a marki innej niż producent pliku import nie cofa — ceny Canis idą dalej także po scaleniu z kartą P4S.
 */
final class PriceListGoodsBrandImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
        config(['price_lists.brand_from_name' => ['canis']]);
    }

    public function test_brand_is_read_from_goods_name_only_for_configured_list(): void
    {
        $brands = app(PriceListGoodsBrand::class);

        $this->assertSame('3M', $brands->brandOf(['name' => 'Respirator 3M 9914 with valve, FFP1, filter with active carbon'], 'Canis'));
        $this->assertSame('3M', $brands->brandOf(['name' => 'Helmet Peltor G3000, ventilated, wheel ratchet'], 'Canis'), 'Peltor to 3M');
        $this->assertSame('Ansell', $brands->brandOf(['name' => 'Rukavice ANSELL BI-COLOUR 87-900, kyselinovzdorné, blistr'], 'Canis'));
        $this->assertSame('MSA', $brands->brandOf(['name' => 'MSA V-Gard 500 helmet ventilated, white'], 'Canis'));
        $this->assertSame('3M', $brands->brandOf(['name' => 'Filter platform', 'model_name' => '3M'], 'Canis'), 'model z cennika równy marce');
        $this->assertNull($brands->brandOf(['name' => 'Measure tape, 3m, with a magnets on the hook'], 'Canis'), '3m to metry');
        $this->assertNull($brands->brandOf(['name' => 'Visor for 3M helmet G3000'], 'Canis'), 'marka urządzenia, nie towaru');
        $this->assertNull($brands->brandOf(['name' => 'Sada 3M a MSA, 2 ks'], 'Canis'), 'dwie marki — zostaje producent pliku');
        $this->assertNull($brands->brandOf(['name' => 'Rukavice CXS BONO, kožené'], 'Canis'));
        // wiersze pliku Canis z 1.5.2026: 3M za przecinkiem to składnik własnego wyrobu Canis, nie marka towaru
        $this->assertNull($brands->brandOf(['name' => 'High visible pants, shorten 170-176cm, men´s,  twill 65% polyester 35% cotton 280g/m2, reflective stripes 3M, orange-blue'], 'Canis'));
        $this->assertNull($brands->brandOf(['name' => 'Semi-shank safety footwear S7S, full grain leather upper, waterproof membrane, 3M Thinsulate lining,composite toe cap'], 'Canis'));

        $this->assertTrue($brands->enabledFor('Canis'));
        $this->assertTrue($brands->enabledFor('CANIS'));
        $this->assertFalse($brands->enabledFor('Anro'));
        $this->assertSame([], $brands->summarize([['sku' => 'A', 'name' => 'Respirator 3M 9914']], 'Anro'));
        $this->assertSame(
            [['brand' => '3M', 'count' => 2, 'skus' => ['A', 'B']], ['brand' => 'MSA', 'count' => 1, 'skus' => ['C']]],
            $brands->summarize([
                ['sku' => 'A', 'name' => 'Respirator 3M 9914'],
                ['sku' => 'B', 'name' => 'Semi-mask 3M 6200'],
                ['sku' => 'C', 'name' => 'MSA V-Gard 500 helmet'],
                ['sku' => 'D', 'name' => 'Measure tape, 3m'],
            ], 'Canis'),
        );
    }

    public function test_new_rows_of_multi_brand_list_get_goods_brand_and_a_note(): void
    {
        $result = $this->import('v1', [
            $this->row('4510-016-000-00', 'Respirator 3M 9914 with valve, FFP1', 16.53),
            $this->row('3660-025-000-07', 'Rukavice ANSELL BI-COLOUR 87-900, vel. 6,5 - 7', 20.00),
            $this->row('6121-008-000-00', 'Measure tape, 3m, with a magnets on the hook', 5.00),
        ]);

        $this->assertSame('3M', $this->card('4510-016-000-00')->manufacturer);
        $this->assertSame('Ansell', $this->card('3660-025-000-07')->manufacturer);
        $this->assertSame('Canis', $this->card('6121-008-000-00')->manufacturer);
        $this->assertSame(3, $result['created']);
        $this->assertSame('Marka z nazwy wyrobu (cennik wielomarkowy Canis): 3M 1, Ansell 1 pozycji.', $result['errors'][0] ?? null);
        $this->assertSame(3, $this->fileSlots($result['price_list']->id));
        // przykładowa karta rejestracji domen ma markę pliku — 3m.com nie trafia do Canis
        $sample = $this->card('6121-008-000-00')->id;
        Queue::assertPushed(RegisterManufacturerCatalogJob::class, static fn (RegisterManufacturerCatalogJob $job): bool => $job->manufacturer === 'Canis' && $job->sampleProductId === $sample);
    }

    public function test_own_card_gets_goods_brand_keeps_prices_and_is_never_reverted(): void
    {
        config(['price_lists.brand_from_name' => []]);
        $this->import('v1', [$this->row('4510-016-000-00', 'Respirator 3M 9914 with valve, FFP1', 16.53)]);
        $card = $this->card('4510-016-000-00');
        $this->assertSame('Canis', $card->manufacturer, 'stan sprzed zmiany: marka pliku');

        config(['price_lists.brand_from_name' => ['canis']]);
        $second = $this->import('v2', [$this->row('4510-016-000-00', 'Respirator 3M 9914 with valve, FFP1', 17.00)]);

        $card->refresh();
        $this->assertSame('3M', $card->manufacturer);
        $this->assertSame(1, $second['updated']);
        $this->assertSame(0, $second['skipped']);
        $this->assertEquals(17.00, (float) $this->fileSlot($card)->purchase_price);
        $this->assertContains('Producent karty ustawiony na 3M (zamiast Canis): 1 kart (4510-016-000-00).', $second['errors']);

        // nowa wersja pliku bez marki w nazwie: cena idzie, marka 3M zostaje (bez pominięcia jako „karta producenta 3M”)
        $third = $this->import('v3', [$this->row('4510-016-000-00', 'Respirator 9914 with valve, FFP1', 18.00)]);

        $card->refresh();
        $this->assertSame('3M', $card->manufacturer);
        $this->assertSame(0, $third['skipped']);
        $this->assertEquals(18.00, (float) $this->fileSlot($card)->purchase_price);
        $this->assertSame([], array_values(array_filter($third['errors'], static fn (string $e): bool => str_starts_with($e, 'Producent karty'))));
    }

    public function test_own_card_with_b2b_link_keeps_list_brand_and_takes_the_price(): void
    {
        config(['price_lists.brand_from_name' => []]);
        $this->import('v1', [$this->row('4520-001-000-00', 'Semi-mask 3M 6200, size M', 30.00)]);
        $card = $this->card('4520-001-000-00');
        B2bProductLink::query()->create(['b2b_account_id' => $this->account()->id, 'remote_id' => '77', 'product_id' => $card->id]);

        config(['price_lists.brand_from_name' => ['canis']]);
        $result = $this->import('v2', [$this->row('4520-001-000-00', 'Semi-mask 3M 6200, size M', 31.00)]);

        $card->refresh();
        $this->assertSame('Canis', $card->manufacturer);
        $this->assertSame(0, $result['skipped']);
        $this->assertEquals(31.00, (float) $this->fileSlot($card)->purchase_price);
        $this->assertContains('Karty z powiązaniem B2B zostają przy dotychczasowym producencie (cena z pliku zaktualizowana): 1 (4520-001-000-00).', $result['errors']);
    }

    public function test_goods_brand_row_does_not_overwrite_file_price_of_another_list(): void
    {
        $threeM = $this->import('2026', [$this->row('X-9914', 'Półmaska 3M 9914 z zaworem', 12.00)], '3M')['price_list'];
        $card = $this->card('X-9914');

        $result = $this->import('v1', [$this->row('X-9914', 'Respirator 3M 9914 with valve', 16.53)]);

        $card->refresh();
        $slot = $this->fileSlot($card);
        $this->assertSame($threeM->id, $slot->price_list_id);
        $this->assertEquals(12.00, (float) $slot->purchase_price);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('kod należy do karty producenta 3M', collect($result['skipped_details'])->firstWhere('sku', 'X-9914')['reason'] ?? null);
    }

    /**
     * Karta 3M od P4S z tym samym kodem co wiersz Canis: pominięta jak przed zmianą — opis, kategoria i EAN karty P4S
     * nie przychodzą z pliku Canis, a połączenie to decyzja człowieka (Łączenie kart).
     */
    public function test_goods_brand_row_skips_foreign_brand_card_with_the_same_code(): void
    {
        $p4s = Product::query()->create([
            'sku' => 'CAN-9914', 'name' => 'Półmaska 3M 9914', 'manufacturer' => '3M', 'description' => 'Opis z P4S',
            'catalog_price_net' => 17.50, 'purchase_price' => 17.50,
        ]);
        B2bProductLink::query()->create(['b2b_account_id' => $this->account()->id, 'remote_id' => '14209', 'product_id' => $p4s->id]);

        $result = $this->import('v1', [['ean' => '5900000000024'] + $this->row('CAN-9914', 'Respirator 3M 9914 with valve', 16.53)]);

        $p4s->refresh();
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('kod należy do karty producenta 3M', collect($result['skipped_details'])->firstWhere('sku', 'CAN-9914')['reason'] ?? null);
        $this->assertSame('Opis z P4S', $p4s->description);
        $this->assertNull($p4s->ean, 'EAN z pliku Canis nie trafia na kartę P4S');
        $this->assertFalse(ProductSourcePrice::query()->where('product_id', $p4s->id)->where('source_key', ProductSourcePrice::SOURCE_FILE)->exists());
    }

    /** Stara karta Canis bez slotu tego cennika (np. po usunięciu cennika): wiersz z marką z nazwy dalej ją aktualizuje. */
    public function test_goods_brand_row_updates_canis_card_without_list_slot(): void
    {
        $old = Product::query()->create([
            'sku' => '4510-016-000-00', 'name' => 'Respirator 3M 9914', 'manufacturer' => 'Canis',
            'catalog_price_net' => 16.00, 'purchase_price' => 16.00,
        ]);

        $result = $this->import('v1', [$this->row('4510-016-000-00', 'Respirator 3M 9914 with valve, FFP1', 16.53)]);

        $old->refresh();
        $this->assertSame(0, $result['skipped'], implode('; ', $result['errors']));
        $this->assertSame('3M', $old->manufacturer);
        $this->assertSame($result['price_list']->id, $this->fileSlot($old)->price_list_id);
    }

    /**
     * Mapa połączeń: pozycja Canis połączona z kartą 3M, która ma cenę z cennika 3M — wiersz z marką z nazwy jej nie
     * nadpisuje (jedna karta = jeden slot „file”); pozycja połączona ze starą kartą Canis przechodzi.
     */
    public function test_redirected_goods_brand_row_respects_other_list_slot_and_reaches_canis_card(): void
    {
        $threeM = $this->import('2026', [$this->row('X-6200', 'Półmaska 3M 6200 M', 29.00)], '3M')['price_list'];
        $threeMCard = $this->card('X-6200');
        $canisCard = Product::query()->create([
            'sku' => 'CAN-OLD', 'name' => 'Semi-mask 6200 L', 'manufacturer' => 'Canis', 'catalog_price_net' => 31, 'purchase_price' => 31,
        ]);
        $list = $this->import('v1', [$this->row('6121-008-000-00', 'Measure tape, 3m', 5.00)])['price_list'];
        foreach (['4520-001-000-00' => $threeMCard, '4520-001-000-01' => $canisCard] as $position => $target) {
            CardRedirect::query()->create([
                'source_key' => ProductIdentifierStore::fileKey((int) $list->id),
                'position_key' => $position,
                'price_list_id' => $list->id,
                'product_id' => $target->id,
                'reason' => CardRedirect::REASON_SIZE_MERGE,
            ]);
        }

        $result = $this->import('v2', [
            $this->row('4520-001-000-00', 'Semi-mask 3M 6200, size M', 30.00),
            $this->row('4520-001-000-01', 'Semi-mask 3M 6200, size L', 31.50),
        ]);

        $this->assertSame('karta 3M ma już cenę z innego cennika (#'.$threeM->id.')', collect($result['skipped_details'])->firstWhere('sku', '4520-001-000-00')['reason'] ?? null);
        $slot = $this->fileSlot($threeMCard);
        $this->assertSame($threeM->id, $slot->price_list_id);
        $this->assertEquals(29.00, (float) $slot->purchase_price);
        $this->assertNull(collect($result['skipped_details'])->firstWhere('sku', '4520-001-000-01'), implode('; ', $result['errors']));
        $this->assertEquals(31.50, (float) $this->fileSlot($canisCard)->purchase_price);
    }

    /**
     * Wiersz z marką z nazwy szuka kart swojej marki rdzeniem i nazwą tylko wśród kart tego cennika: rozmiar M z Canisa
     * nie podpina się pod kartę 3M rozmiaru L od P4S (ta sama nazwa bez rozmiaru i cena) — to robi człowiek scaleniem.
     */
    public function test_goods_brand_row_does_not_attach_to_foreign_brand_card_by_name(): void
    {
        $foreign = Product::query()->create([
            'sku' => '7000002391', 'name' => 'Semi-mask 3M 6200, size L', 'manufacturer' => '3M',
            'catalog_price_net' => 30.00, 'purchase_price' => 30.00,
        ]);

        $this->import('v1', [$this->row('4520-001-000-00', 'Semi-mask 3M 6200, size M', 30.00)]);

        $this->assertFalse(ProductSourcePrice::query()->where('product_id', $foreign->id)->exists(), 'karta P4S bez ceny Canis');
        $card = Product::query()->where('sku', '4520-001-000-00')->first();
        $this->assertNotNull($card, 'nowa karta z cennika');
        $this->assertSame('3M', $card->manufacturer);
    }

    /** Karta bez producenta dostaje go z pliku jak przed zmianą (CanonicalBrand::same nie porównuje pustej nazwy). */
    public function test_card_without_manufacturer_still_takes_list_manufacturer(): void
    {
        $card = Product::query()->create(['sku' => 'NO-MFR', 'name' => 'Znak', 'manufacturer' => '', 'catalog_price_net' => 1, 'purchase_price' => 1]);

        $this->import('v1', [$this->row('NO-MFR', 'Znak ewakuacyjny', 2.00)], 'Anro');

        $this->assertSame('Anro', $card->refresh()->manufacturer);
    }

    /** Karta cennika spoza konfiguracji z marką poprawioną ręcznie (AlphaTec z SECURA → Ansell) nie jest już pomijana. */
    public function test_manually_rebranded_card_of_ordinary_list_keeps_brand_and_gets_prices(): void
    {
        $this->import('v1', [$this->row('SEC-2000', 'Kombinezon AlphaTec 2000', 40.00)], 'SECURA');
        $card = $this->card('SEC-2000');
        $this->assertSame('SECURA', $card->manufacturer, 'cennik spoza konfiguracji nie czyta marki z nazwy');
        $card->update(['manufacturer' => 'Ansell']);

        $result = $this->import('v2', [['ean' => '5900000000017'] + $this->row('SEC-2000', 'Kombinezon AlphaTec 2000 SECURA', 42.00)], 'SECURA');

        $card->refresh();
        $this->assertSame('Ansell', $card->manufacturer);
        $this->assertSame(0, $result['skipped']);
        $this->assertEquals(42.00, (float) $this->fileSlot($card)->purchase_price);
        $this->assertSame('Kombinezon AlphaTec 2000', $card->name, 'nazwa karty innej marki nie przychodzi z pliku');
        $this->assertSame('5900000000017', $card->ean, 'puste pole karta uzupełnia');
    }

    /** Ścieżka mapy połączeń jak zwykła: karta z marką zmienioną ręcznie dostaje cenę, a z pliku tylko puste pola. */
    public function test_redirected_row_on_rebranded_card_fills_only_empty_fields(): void
    {
        $list = $this->import('v1', [['ean' => '5900000000031'] + $this->row('SEC-2000', 'Kombinezon AlphaTec 2000', 40.00)], 'SECURA')['price_list'];
        $card = $this->card('SEC-2000');
        $card->update(['manufacturer' => 'Ansell']);
        CardRedirect::query()->create([
            'source_key' => ProductIdentifierStore::fileKey((int) $list->id),
            'position_key' => 'SEC-2000-L',
            'price_list_id' => $list->id,
            'product_id' => $card->id,
            'reason' => CardRedirect::REASON_SIZE_MERGE,
        ]);

        $result = $this->import('v2', [['ean' => '5900000000048'] + $this->row('SEC-2000-L', 'Kombinezon AlphaTec 2000 L', 42.00)], 'SECURA');

        $card->refresh();
        $this->assertSame(0, $result['skipped'], implode('; ', $result['errors']));
        $this->assertEquals(42.00, (float) $this->fileSlot($card)->purchase_price);
        $this->assertSame('5900000000031', $card->ean, 'EAN karty innej marki nie przychodzi z pliku');
        $this->assertSame('Ansell', $card->manufacturer);
    }

    /** Karta z marką z nazwy znaleziona rdzeniem kodu: cena z nowego wiersza trafia do slotu, kod karty zostaje. */
    public function test_goods_brand_card_found_by_code_stem_keeps_its_sku(): void
    {
        $this->import('v1', [$this->row('4520-001-000-00', 'Semi-mask 3M 6200, size M', 30.00)]);
        $card = $this->card('4520-001-000-00');
        $this->assertSame('3M', $card->manufacturer);

        $result = $this->import('v2', [$this->row('4520-001-000-01', 'Semi-mask 3M 6200, size M', 30.00)]);

        $this->assertSame(1, $result['updated'], implode('; ', $result['errors']));
        $this->assertSame(0, $result['created']);
        $this->assertSame('4520-001-000-00', $card->refresh()->sku);
    }

    /** Inny zapis marki kanonicznej na karcie cennika („PELTOR” na karcie z cennika 3M) zostaje na karcie. */
    public function test_own_card_with_other_spelling_of_brand_keeps_it(): void
    {
        BrandDictionaryEntry::query()->create(['term' => 'Peltor', 'kind' => BrandDictionaryEntry::KIND_BRAND, 'manufacturer' => '3M']);
        $this->assertTrue(CanonicalBrand::same('PELTOR', '3M'), 'słownik: Peltor to marka 3M');
        $this->import('v1', [$this->row('H510A', 'Nauszniki Optime I', 80.00)], '3M');
        $card = $this->card('H510A');
        $card->update(['manufacturer' => 'PELTOR']);

        $result = $this->import('v2', [$this->row('H510A', 'Nauszniki Optime I', 82.00)], '3M');

        $this->assertSame(0, $result['skipped']);
        $this->assertSame('PELTOR', $card->refresh()->manufacturer);
        $this->assertEquals(82.00, (float) $this->fileSlot($card)->purchase_price);
    }

    /**
     * Pełny scenariusz duplikatu 9914: karta Canis podniesiona do 3M, scalona z kartą P4S (3M, powiązanie B2B), a kolejny
     * import Canis dalej aktualizuje swój slot na karcie, która zostaje. Cenę obowiązującą ustala P4S (dystrybutor
     * z powiązaniem przed plikiem innej marki) — skutek opisany właścicielowi.
     */
    public function test_merged_card_keeps_getting_canis_prices(): void
    {
        config(['price_lists.brand_from_name' => []]);
        $this->import('v1', [$this->row('4510-016-000-00', 'Respirator 3M 9914 with valve, FFP1', 16.53)]);
        $keep = $this->card('4510-016-000-00');
        $p4s = Product::query()->create([
            'sku' => '9914',
            'name' => 'Półmaska p/pyłowa 3M klasy P1 z zaworem',
            'manufacturer' => '3M',
            'catalog_price_net' => 17.50,
            'purchase_price' => 17.50,
            'currency' => 'PLN',
        ]);
        $account = $this->account();
        B2bProductLink::query()->create(['b2b_account_id' => $account->id, 'remote_id' => '14209', 'remote_sku' => '9914', 'product_id' => $p4s->id]);
        ProductSourcePrice::query()->create([
            'product_id' => $p4s->id,
            'source_key' => ProductSourcePrice::b2bKey($account->id),
            'b2b_account_id' => $account->id,
            'catalog_price_net' => 17.50,
            'purchase_price' => 17.50,
            'discount_percent' => 0,
            'currency' => 'PLN',
            'checked_at' => CarbonImmutable::parse('2026-09-24 13:38'),
        ]);

        config(['price_lists.brand_from_name' => ['canis']]);
        $this->import('v2', [$this->row('4510-016-000-00', 'Respirator 3M 9914 with valve, FFP1', 16.53)]);
        $this->assertSame('3M', $keep->refresh()->manufacturer);

        $backup = storage_path('framework/testing/merge-duplicate-goods-brand.json');
        $this->artisan('products:merge-duplicate', ['--pair' => [$keep->id.':'.$p4s->id], '--apply' => true, '--backup' => $backup])
            ->assertSuccessful();
        @unlink($backup);
        $this->assertNull(Product::query()->find($p4s->id));
        $this->assertTrue(B2bProductLink::query()->where('product_id', $keep->id)->exists(), 'powiązanie P4S przeszło na kartę Canis');

        $next = $this->import('v3', [$this->row('4510-016-000-00', 'Respirator 3M 9914 with valve, FFP1', 16.10)]);

        $keep->refresh();
        $this->assertSame(0, $next['skipped']);
        $this->assertSame('3M', $keep->manufacturer);
        $this->assertEquals(16.10, (float) $this->fileSlot($keep)->purchase_price, 'cena Canis dalej się aktualizuje');
        $this->assertEquals(17.50, (float) $keep->purchase_price, 'cena obowiązująca z P4S');
    }

    /**
     * Mapa połączeń: pozycja pliku przekierowana (np. po łączeniu rozmiarów) na kartę Canis tego cennika — wiersz z marką
     * 3M z nazwy nie jest pomijany jako „inna marka producenta niż karta”, a marki karty mapa nie zmienia.
     */
    public function test_redirected_row_with_goods_brand_reaches_own_card(): void
    {
        config(['price_lists.brand_from_name' => []]);
        $list = $this->import('v1', [$this->row('4520-001-000-00', 'Semi-mask 3M 6200, size M', 30.00)])['price_list'];
        $keep = $this->card('4520-001-000-00');
        CardRedirect::query()->create([
            'source_key' => ProductIdentifierStore::fileKey((int) $list->id),
            'position_key' => '4520-001-000-01',
            'price_list_id' => $list->id,
            'product_id' => $keep->id,
            'reason' => CardRedirect::REASON_SIZE_MERGE,
        ]);

        config(['price_lists.brand_from_name' => ['canis']]);
        $result = $this->import('v2', [$this->row('4520-001-000-01', 'Semi-mask 3M 6200, size L', 31.00)]);

        $keep->refresh();
        $this->assertSame(0, $result['skipped'], implode('; ', $result['errors']));
        $this->assertSame('Canis', $keep->manufacturer, 'mapa nie zmienia marki karty');
        $this->assertEquals(31.00, (float) $this->fileSlot($keep)->purchase_price);
        $this->assertSame(0, Product::query()->where('sku', '4520-001-000-01')->count(), 'bez nowej karty');
    }

    /** Plik Canis to arkusz: ścieżka importWithMapping, a ceny specjalne z tego pliku trafiają też w kartę podniesioną do 3M. */
    public function test_spreadsheet_import_gives_goods_brand_and_special_prices_reach_the_card(): void
    {
        $path = $this->makeCanisSpreadsheet(withSpecialPrices: true);
        $file = new UploadedFile($path, 'canis.xlsx', null, null, true);

        try {
            $result = app(PriceListImportService::class)->importWithMapping(
                $file,
                'Canis',
                '2026-05',
                $this->user,
                app(SpreadsheetColumnMapper::class)->refineMapping($path, $this->canisMapping()),
            );
        } finally {
            @unlink($path);
        }

        $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));
        $respirator = $this->card('4510-016-000-00');
        $this->assertSame('3M', $respirator->manufacturer);
        $this->assertSame('Canis', $this->card('6121-008-000-00')->manufacturer);
        $special = ProductSpecialPrice::query()->where('product_id', $respirator->id)->first();
        $this->assertNotNull($special, 'cena specjalna z pliku Canis na karcie 3M tego cennika');
        $this->assertEqualsWithDelta(15.10, (float) $special->price, 0.01);
    }

    public function test_preview_endpoint_lists_rows_that_get_brand_from_name(): void
    {
        Sanctum::actingAs($this->user);
        $path = $this->makeCanisSpreadsheet(withSpecialPrices: false);

        try {
            $canis = $this->post('/api/price-lists/preview', [
                'file' => new UploadedFile($path, 'canis.xlsx', null, null, true),
                'mapping' => json_encode($this->canisMapping()),
                'manufacturer' => 'Canis',
            ]);
            $other = $this->post('/api/price-lists/preview', [
                'file' => new UploadedFile($path, 'canis.xlsx', null, null, true),
                'mapping' => json_encode($this->canisMapping()),
                'manufacturer' => 'Anro',
            ]);
        } finally {
            @unlink($path);
        }

        $canis->assertOk();
        $canis->assertJsonPath('brand_from_name', [['brand' => '3M', 'count' => 1, 'skus' => ['4510-016-000-00']]]);
        $other->assertOk();
        $other->assertJsonPath('brand_from_name', []);
    }

    /**
     * @return array<string, mixed>
     */
    private function canisMapping(): array
    {
        return [
            'currency' => 'PLN',
            'notes' => 'test',
            'sheets' => [[
                'sheet' => 'Price list',
                'include' => true,
                'header_excel_row' => 1,
                'columns' => ['sku' => 0, 'name' => 1, 'catalog_price' => 2],
                'repeating_headers' => false,
                'confidence' => 1.0,
            ]],
        ];
    }

    private function makeCanisSpreadsheet(bool $withSpecialPrices): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Price list');
        $sheet->fromArray([
            ['Kód', 'Název', 'Price PLN'],
            ['4510-016-000-00', 'Respirator 3M 9914 with valve, FFP1, filter with active carbon', 16.53],
            ['6121-008-000-00', 'Measure tape, 3m, with a magnets on the hook', 5.00],
            ['3100-011-000-08', 'Rukavice BONO, s blistrem, kožené', 6.80],
        ], null, 'A1', true);
        if ($withSpecialPrices) {
            $special = $spreadsheet->createSheet();
            $special->setTitle('Special Pricing plPL');
            $special->fromArray([
                ['Numer kontraktu oferty specjalnej', 'Numer klienta', 'Nazwa klienta', 'Adres wysyłki', 'Nazwa kontraktu oferty specjalnej',
                    'Typ', 'Data rozpoczęcia', 'Umowa dystrybucyjna', '3M numer magazynowy', '3M numer magazynowy (stary)', 'Kod EAN',
                    'Nazwa produktu', 'Waluta', 'Cena kontraktowa'],
                ['C0001', '4099', 'ARCELORMITTAL POLAND S.A.', '', 'SUPON + ARCELORMITTAL', 'Channel Partner', '01/05/2026',
                    'Safety Accounts', '', '4510-016-000-00', '', 'Respirator 3M 9914', 'PLN', '15,10'],
            ]);
        }
        $path = tempnam(sys_get_temp_dir(), 'canis').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function card(string $sku): Product
    {
        return Product::query()->where('sku', $sku)->sole();
    }

    private function fileSlot(Product $product): ProductSourcePrice
    {
        return ProductSourcePrice::query()
            ->where('product_id', $product->id)
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->sole();
    }

    private function fileSlots(int $priceListId): int
    {
        return ProductSourcePrice::query()
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->where('price_list_id', $priceListId)
            ->count();
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(['username' => 'p4s'], [
            'password' => 'sekret',
            'sites' => ['b2b.p4s.pl'],
            'connector' => 'p4s',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $sku, string $name, float $purchase): array
    {
        return ['sku' => $sku, 'name' => $name, 'catalog_price_net' => $purchase, 'purchase_price' => $purchase, 'currency' => 'PLN'];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function import(string $version, array $rows, string $manufacturer = 'Canis'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'goodsbrand').'.pdf';
        file_put_contents($path, "%PDF-1.4\n");
        $file = new UploadedFile($path, 'cennik.pdf', 'application/pdf', null, true);

        try {
            return app(PriceListImportService::class)->importFromProducts($file, $manufacturer, $version, $this->user, $rows);
        } finally {
            @unlink($path);
        }
    }
}
