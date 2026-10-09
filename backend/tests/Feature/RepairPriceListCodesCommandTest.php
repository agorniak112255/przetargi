<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CardRedirect;
use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\Product;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductIdentifier;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Catalog\CardRedirectStore;
use App\Services\PriceListImportService;
use App\Services\ProductDeletionService;
use App\Services\SpreadsheetColumnMapper;
use App\Services\SpreadsheetMappingHeuristic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * products:repair-price-list-codes — naprawa kart Coby po imporcie z 12.09.2026 (decyzja właściciela 10.10.2026): kody
 * ucięte dawną regułą rozmiaru (AF010005 → AF0100, DP010004 → DP0100-4, DP010011 → DP0100-11), pozycje o tej samej
 * cenie zlane w jedną kartę (HI010002–05 → HI0100), nazwy z nagłówka grupy, arkusz „Noże bezpieczne” niewczytany.
 * Wiersze z cennika „PLN - COBA Price Book 2026.xlsx” (przycięte), stan kart jak po imporcie z 12.09.
 */
final class RepairPriceListCodesCommandTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['Numer produktu', 'Nazwa. kolor. wymiar produktu', 'Waga [kg]', "ExWorks'26", "Transport jedn.'26"];

    private const MATS = [
        [null, 'Orthomat Standard', null, null, null],
        ['AF010003', 'Orthomat Standard Czarny 0.9m x 18.3m (9.5mm)', 45, 1934.02, 397.5],
        ['AF010005', 'Orthomat Standard Czarny 1.2m x 18.3m (9.5mm)', 51, 2608.70, 397.5],
        ['AF010006', 'Orthomat Standard Czarny 1.5m x 18.3m (9.5mm)', 60, 2608.70, 397.5],
        ['UWAGA: akcesoria do maty dodatkowo płatne.', null, null, null, null],
        [null, 'Deckplate', null, null, null],
        ['DP010004', 'Deckplate Czarny 0.6m x 18.3m (15mm)', 93, 4107.35, 618.9],
        ['DP010005', 'Deckplate Czarny 0.9m x 18.3m (15mm)', 146.4, 6623.02, 618.9],
        ['DP010011', 'Deckplate Czarny 1.5m x 15m (15mm)', 200, 9318.12, 618.9],
        [null, 'High-Duty', null, null, null],
        ['HI010004', 'High-Duty Czarny 0.9m x 1.5m (12mm) - moduł boczny (2 kr./1 dł.)', 11, 302.32, 80.86],
        ['HI010005', 'High-Duty Czarny 0.9m x 1.5m (12mm) - moduł środkowy (2 kr.)', 11, 302.32, 80.86],
        [null, 'Standardowa guma', null, null, null],
        ['CRS00005', 'Standardowa guma 1.4m x 10m (12mm)', 268, 2911.40, 618.9],
        [null, 'Bubblemat', null, null, null],
        ['BF010004', 'Bubblemat Czarny 0.6m x 0.9m (14mm) - moduł środkowy', 9, 238.12, 78.78],
        [null, 'COBArib', null, null, null],
        ['RR010010', 'COBArib Standard Czarny 0.9m x 10m (3mm)', 30, 456.98, 160.2],
        [null, 'Orthomat Ultimate', null, null, null],
        ['OU010005', 'Orthomat Ultimate Czarny 1.2m x 18.3m (15mm)', 80, 5120.10, 618.9],
        ['SE010010', 'COBAelite Bubble Special - rozmiar specjalny', null, 'Proszę o kontakt', null],
    ];

    private const KNIVES = [
        [null, 'Noże bezpieczne z ukrytym ostrzem', null, null, null],
        [741242, 'GR8 Pro - Czerwony', 0.13, 26.61, 57.45],
        ['620010X10', 'Wymienne ostrza GR8 - w opakowaniu 10 sztuk', 0.05, 34.65, 57.45],
        ['650010X50RB', 'Wymienne ostrza Anti-Stab AutoSafe - w opakowaniu 50 sztuk', 0.18, 77.20, 57.45],
        ['650010X50RBM', 'Wymienne ostrza Anti-Stab AutoSafe Pro - w opakowaniu 50 sztuk', 0.18, 78.72, 57.45],
        ['YO YO METAL', 'Zaczep Heavy Duty YoYo - GR8 Pro, AutoSafe Pro, AutoSlide', 0.05, 27.37, 57.45],
    ];

    private User $user;

    private PriceList $list;

    private string $file;

    private string $backup;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create();
        $this->list = PriceList::query()->create([
            'manufacturer' => 'Coba',
            'manufacturer_key' => PriceList::manufacturerKey('Coba'),
            'version' => '2026',
            'original_filename' => 'PLN - COBA Price Book 2026.xlsx',
            'imported_by' => $this->user->id,
            'rows_total' => 0,
        ]);
        $this->file = $this->workbook();
        $this->backup = tempnam(sys_get_temp_dir(), 'repair-codes').'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @unlink($this->backup);
        foreach (glob(storage_path('app/repair-reports/price-list-codes-'.$this->list->id.'-*.csv')) ?: [] as $report) {
            @unlink($report);
        }
        parent::tearDown();
    }

    public function test_preview_writes_the_report_and_changes_nothing(): void
    {
        $state = $this->stateAfterImportOf1209();
        $before = $this->snapshot();

        $this->assertSame(0, Artisan::call('products:repair-price-list-codes', [
            'file' => $this->file,
            '--price-list' => $this->list->id,
        ]));
        $output = Artisan::output();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, ProductIdentifier::query()->count());
        // cennik przesyłek nie jest arkuszem wyrobów
        $this->assertStringNotContainsString('TRANSPORT', $output);
        $this->assertStringContainsString('Zmiany: kod 2, nazwa 1, kod+nazwa 4; bez zmian 1; nowe karty 7 (Noże bezpieczne: 5, Maty przemysłowe: 2);'
            .' kolizje kodu 1; niejednoznaczne 0; blokady 1; karty bez wiersza 1; nazwy nie z pliku (zostają) 1.', $output);
        $this->assertStringContainsString('Bezpiecznik ceny: 2 z 2 kart z dokładnym kodem', $output);

        $report = $this->report($output);
        $byCard = [];
        $bySku = [];
        foreach ($report as $row) {
            if ($row['id'] !== '') {
                $byCard[(int) $row['id']] = $row;
            } else {
                $bySku[$row['sku_nowe'] !== '' ? $row['sku_nowe'] : $row['uwagi']] = $row;
            }
        }
        $af = $byCard[$state['af']->id];
        $this->assertSame(['zmiana', 'kod ucięty', 'AF0100', 'AF010005', ''], [$af['akcja'], $af['dopasowanie'], $af['sku_teraz'], $af['sku_nowe'], $af['nazwa_nowa']]);
        $this->assertSame(['Maty przemysłowe', '4', '2608.70|2608.70'], [$af['arkusz'], $af['wiersz'], $af['cena_pliku']]);
        $this->assertSame('tak (bez przypięcia)', $af['przypiecie_do_sprawdzenia']);
        $this->assertSame('tak (przypięta skrótem cennika)', $byCard[$state['dp4']->id]['przypiecie_do_sprawdzenia']);
        $this->assertSame('AF0107', $byCard[$state['dp4']->id]['parts_table_part']);
        $this->assertSame('Deckplate Czarny 0.6m x 18.3m (15mm)', $byCard[$state['dp4']->id]['nazwa_nowa']);
        $this->assertStringContainsString('nazwa nie z pliku — zostaje', $byCard[$state['dp5']->id]['uwagi']);
        $this->assertSame('DP010011', $byCard[$state['dp11']->id]['sku_nowe']);
        $this->assertStringContainsString('tylko separatorem', $byCard[$state['dp11']->id]['uwagi']);
        $this->assertStringContainsString('karta zebrana z 2 wierszy pliku (HI010004, HI010005)', $byCard[$state['hi']->id]['uwagi']);
        $this->assertSame(['0', '0', '0'], [$af['oferty'], $af['przetargi'], $af['wersje_opisu']]);
        $this->assertSame(['bez zmian', 'mapa połączeń'], [$byCard[$state['redirect']->id]['akcja'], $byCard[$state['redirect']->id]['dopasowanie']]);
        $this->assertSame('bez wiersza', $byCard[$state['orphan']->id]['akcja']);
        $this->assertSame('running', $byCard[$state['running']->id]['enrichment_status']);
        $this->assertSame('tak (nowa karta)', $bySku['YO YO METAL']['przypiecie_do_sprawdzenia']);
        $this->assertSame('Noże bezpieczne', $bySku['741242']['arkusz']);
        $this->assertArrayHasKey('AF010006', $bySku);
        $this->assertArrayHasKey('HI010005', $bySku);
        $collision = array_values(array_filter($report, static fn (array $r): bool => $r['akcja'] === 'kolizja kodu'));
        $this->assertCount(1, $collision);
        $this->assertStringContainsString('kod CRS00005 ma już karta #'.$state['foreign']->id, $collision[0]['uwagi']);
        $blocked = array_values(array_filter($report, static fn (array $r): bool => $r['akcja'] === 'blokada'));
        $this->assertCount(1, $blocked);
        $this->assertStringContainsString('BF0100-4', $blocked[0]['uwagi']);
    }

    public function test_apply_repairs_codes_and_names_keeps_card_ids_and_adds_missing_cards(): void
    {
        $state = $this->stateAfterImportOf1209();

        $this->assertSame(0, Artisan::call('products:repair-price-list-codes', [
            'file' => $this->file,
            '--price-list' => $this->list->id,
            '--apply' => true,
            '--backup' => $this->backup,
        ]));
        $output = Artisan::output();
        $this->assertStringContainsString('Zapisano: 6 kart poprawionych, 7 nowych kart.', $output);
        $this->assertStringContainsString('#'.$state['running']->id.' (opis w trakcie pobierania)', $output);

        // kod i nazwa na tej samej karcie (id, historia cen, pamięć opisu pod nowym kodem)
        $af = $state['af']->fresh();
        $this->assertSame(['AF010005', 'Orthomat Standard Czarny 1.2m x 18.3m (9.5mm)'], [$af->sku, $af->name]);
        $this->assertSame(1, ProductPriceHistory::query()->where('product_id', $af->id)->count());
        $this->assertSame(['af010005'], ProductEnrichmentCache::query()->where('manufacturer', 'coba')->pluck('sku')->all());
        $this->assertSame(['DP010004', 'Deckplate Czarny 0.6m x 18.3m (15mm)'], [$state['dp4']->fresh()->sku, $state['dp4']->fresh()->name]);
        // nazwa wpisana ręcznie zostaje, kod się zmienia
        $this->assertSame(['DP010005', 'Mata Deckplate 0,9 x 18,3 (nazwa ręczna)'], [$state['dp5']->fresh()->sku, $state['dp5']->fresh()->name]);
        $this->assertSame('DP010011', $state['dp11']->fresh()->sku);
        // zlana karta zostaje przy pierwszym wierszu grupy z jego nazwą, drugi wiersz dostaje nową kartę
        $this->assertSame(['HI010004', 'High-Duty Czarny 0.9m x 1.5m (12mm) - moduł boczny (2 kr./1 dł.)'], [$state['hi']->fresh()->sku, $state['hi']->fresh()->name]);
        $hi5 = Product::query()->where('sku', 'HI010005')->sole();
        $this->assertSame('High-Duty Czarny 0.9m x 1.5m (12mm) - moduł środkowy (2 kr.)', $hi5->name);
        // nazwa z wiersza, choć kod się zgadzał
        $this->assertSame('Orthomat Standard Czarny 0.9m x 18.3m (9.5mm)', $state['exact']->fresh()->name);

        // bez zmian: mapa połączeń, karta bez wiersza, karta w trakcie pobierania, cudza karta z kodem z pliku
        $this->assertSame(['RR-POLACZONA', 'COBArib ręcznie połączony'], [$state['redirect']->fresh()->sku, $state['redirect']->fresh()->name]);
        $this->assertSame('XX0100', $state['orphan']->fresh()->sku);
        $this->assertSame('OU0100', $state['running']->fresh()->sku);
        $this->assertSame('Inny', $state['foreign']->fresh()->manufacturer);
        // zablokowana pozycja i kolizja — bez nowych kart
        $this->assertSame(0, Product::query()->whereIn('sku', ['BF010004'])->count());
        $this->assertSame(1, Product::query()->where('sku', 'CRS00005')->count());

        // nowe karty: zlane wiersze i cały arkusz noży, kod i nazwa z pliku, slot pliku, historia, bez wagi jako sztuk
        $new = Product::query()->whereIn('sku', ['AF010006', 'HI010005', '741242', '620010X10', '650010X50RB', '650010X50RBM', 'YO YO METAL'])->get()->keyBy('sku');
        $this->assertCount(7, $new);
        $this->assertSame('Wymienne ostrza Anti-Stab AutoSafe Pro - w opakowaniu 50 sztuk', $new['650010X50RBM']->name);
        foreach ($new as $card) {
            $slot = ProductSourcePrice::query()->where('product_id', $card->id)->where('source_key', ProductSourcePrice::SOURCE_FILE)->sole();
            $this->assertSame((int) $this->list->id, (int) $slot->price_list_id);
            $this->assertNull($card->pack_qty, $card->sku);
            $this->assertSame('Coba', $card->manufacturer);
            $this->assertSame(1, ProductPriceHistory::query()->where('product_id', $card->id)->whereNull('price_list_import_id')->count());
        }
        $this->assertEqualsWithDelta(2608.70, (float) $new['AF010006']->catalog_price_net, 0.001);

        // identyfikatory wierszy: każda karta ma kod swojego wiersza, bez wpisu importu
        $sourceKey = 'file:'.$this->list->id;
        $this->assertSame(['AF010005'], ProductIdentifier::query()->where('source_key', $sourceKey)->where('product_id', $af->id)->pluck('value')->all());
        $this->assertSame(['RR010010'], ProductIdentifier::query()->where('source_key', $sourceKey)->where('product_id', $state['redirect']->id)->pluck('value')->all());
        $this->assertSame(['OU010005'], ProductIdentifier::query()->where('source_key', $sourceKey)->where('product_id', $state['running']->id)->pluck('value')->all());
        $this->assertSame(0, ProductIdentifier::query()->where('source_key', $sourceKey)->whereNotNull('price_list_import_id')->count());
        $this->assertSame(0, ProductIdentifier::query()->where('value', 'CRS00005')->count());
    }

    public function test_after_repair_second_run_size_merge_and_reimport_change_nothing(): void
    {
        $state = $this->stateAfterImportOf1209(running: false);
        $this->artisan('products:repair-price-list-codes', [
            'file' => $this->file, '--price-list' => $this->list->id, '--apply' => true, '--backup' => $this->backup,
        ])->assertSuccessful();
        $skus = Product::query()->orderBy('id')->pluck('sku', 'id')->all();

        $this->artisan('products:repair-price-list-codes', ['file' => $this->file, '--price-list' => $this->list->id])
            ->expectsOutputToContain('Nic do zapisania')
            ->assertSuccessful();
        $this->artisan('products:merge-size-variants', ['--manufacturer' => 'Coba', '--dry-run' => true])
            ->expectsOutputToContain('grup 0, do usunięcia 0 SKU')
            ->assertSuccessful();

        // ponowny import tego samego pliku: żadnej nowej karty i żadnej zmiany kodu
        $mapping = app(SpreadsheetColumnMapper::class)->refineMapping($this->file, app(SpreadsheetMappingHeuristic::class)->detect($this->file));
        $result = app(PriceListImportService::class)->importWithMapping(
            new UploadedFile($this->file, 'PLN - COBA Price Book 2026.xlsx', null, null, true),
            'Coba',
            '2026',
            $this->user,
            $mapping,
        );
        $this->assertSame(0, $result['created']);
        $this->assertSame([], array_values(array_filter($result['errors'], static fn (string $e): bool => str_contains($e, 'repair-price-list-codes'))));
        $this->assertSame($skus, Product::query()->orderBy('id')->pluck('sku', 'id')->all());
        $this->assertSame('AF010005', $state['af']->fresh()->sku);
    }

    public function test_restore_returns_codes_names_cache_and_identifiers_but_keeps_new_cards(): void
    {
        $state = $this->stateAfterImportOf1209(running: false);
        $before = $this->snapshot();
        $this->artisan('products:repair-price-list-codes', [
            'file' => $this->file, '--price-list' => $this->list->id, '--apply' => true, '--backup' => $this->backup,
        ])->assertSuccessful();
        $created = Product::query()->whereNotIn('id', array_keys($before))->pluck('id')->all();
        $this->assertCount(7, $created);
        // karta zmieniona po naprawie zostaje, jak jest
        $state['dp11']->fresh()->update(['name' => 'Deckplate poprawiony ręcznie po naprawie']);

        $this->artisan('products:repair-price-list-codes', ['--restore' => $this->backup])
            ->expectsOutputToContain('#'.$state['dp11']->id.' (karta zmieniła się od naprawy)')
            ->assertSuccessful();

        $after = $this->snapshot();
        foreach ($before as $id => $card) {
            if ($id === (int) $state['dp11']->id) {
                $this->assertSame('DP010011', $after[$id]['sku']);

                continue;
            }
            $this->assertSame($card, $after[$id], 'karta #'.$id);
        }
        $this->assertSame(['af0100'], ProductEnrichmentCache::query()->where('manufacturer', 'coba')->pluck('sku')->all());
        // nowe karty zostają z identyfikatorami; dopisane do starych kart — oznaczone jako zniknięte, nie usunięte
        $this->assertSame(7, Product::query()->whereIn('id', $created)->count());
        $sourceKey = 'file:'.$this->list->id;
        $this->assertSame(0, ProductIdentifier::query()->where('source_key', $sourceKey)->whereNotIn('product_id', $created)->whereNull('removed_at')->count());
        $this->assertSame(7, ProductIdentifier::query()->where('source_key', $sourceKey)->whereIn('product_id', $created)->whereNull('removed_at')->count());
        $this->assertGreaterThan(0, ProductIdentifier::query()->where('source_key', $sourceKey)->whereNotNull('removed_at')->count());
    }

    public function test_restore_after_a_later_import_returns_codes_but_leaves_identifiers(): void
    {
        $state = $this->stateAfterImportOf1209(running: false);
        $this->artisan('products:repair-price-list-codes', [
            'file' => $this->file, '--price-list' => $this->list->id, '--apply' => true, '--backup' => $this->backup,
        ])->assertSuccessful();
        $identifiers = ProductIdentifier::query()->orderBy('id')->get(['id', 'product_id', 'removed_at'])->toArray();

        $this->travel(1)->hours();
        PriceListImport::query()->create([
            'price_list_id' => $this->list->id, 'source' => PriceListImport::SOURCE_FILE, 'version' => '2026b',
            'original_filename' => 'PLN - COBA Price Book 2026.xlsx', 'imported_by' => $this->user->id, 'rows_total' => 0,
        ]);

        $this->artisan('products:repair-price-list-codes', ['--restore' => $this->backup])
            ->expectsOutputToContain('był importowany po naprawie — identyfikatorów wierszy nie ruszam')
            ->assertSuccessful();

        $this->assertSame('AF0100', $state['af']->fresh()->sku);
        $this->assertSame($identifiers, ProductIdentifier::query()->orderBy('id')->get(['id', 'product_id', 'removed_at'])->toArray());
    }

    public function test_restore_touches_only_identifiers_written_by_the_repair(): void
    {
        $state = $this->stateAfterImportOf1209(running: false);
        $this->artisan('products:repair-price-list-codes', [
            'file' => $this->file, '--price-list' => $this->list->id, '--apply' => true, '--backup' => $this->backup,
        ])->assertSuccessful();

        // wpis dopisany później (np. łączenie kart) — nie pochodzi z naprawy
        $this->travel(1)->hours();
        $later = ProductIdentifier::query()->create([
            'product_id' => $state['orphan']->id, 'source_key' => 'file:'.$this->list->id, 'price_list_id' => $this->list->id,
            'position_key' => 'XX010001', 'type' => ProductIdentifier::TYPE_SOURCE_CODE, 'value' => 'XX010001',
            'normalized' => 'XX010001', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $this->artisan('products:repair-price-list-codes', ['--restore' => $this->backup])->assertSuccessful();

        $this->assertNull($later->fresh()->removed_at);
        $this->assertNotNull(ProductIdentifier::query()->where('product_id', $state['af']->id)->where('value', 'AF010005')->sole()->removed_at);
    }

    /** Ponowny przegląd 10.10: karta z kopii usunięta po naprawie (łączenie kart) — identyfikator zostaje przy obecnej karcie. */
    public function test_restore_leaves_identifier_when_card_from_backup_no_longer_exists(): void
    {
        $state = $this->stateAfterImportOf1209(running: false);
        $before = ProductIdentifier::query()->create([
            'product_id' => $state['af']->id, 'source_key' => 'file:'.$this->list->id, 'price_list_id' => $this->list->id,
            'position_key' => 'AF010005', 'type' => ProductIdentifier::TYPE_SOURCE_CODE, 'value' => 'AF010005',
            'normalized' => 'AF010005', 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subDay(),
        ]);
        $this->artisan('products:repair-price-list-codes', [
            'file' => $this->file, '--price-list' => $this->list->id, '--apply' => true, '--backup' => $this->backup,
        ])->assertSuccessful();
        // kopia wskazuje kartę, której już nie ma (scalona i usunięta po naprawie)
        $data = json_decode((string) file_get_contents($this->backup), true);
        foreach ($data['identifiers'] as $i => $row) {
            if ((int) $row['id'] === (int) $before->id) {
                $data['identifiers'][$i]['product_id'] = 999999;
            }
        }
        file_put_contents($this->backup, json_encode($data));

        $this->artisan('products:repair-price-list-codes', ['--restore' => $this->backup])->assertSuccessful();

        $this->assertSame((int) $state['af']->id, (int) $before->fresh()->product_id);
    }

    public function test_weight_column_guessed_as_pack_quantity_is_not_written_to_new_cards(): void
    {
        // arkusz ESD Coby: „Waga [kg]” z samymi liczbami całkowitymi — rozpoznanie bez AI bierze ją za ilość w opakowaniu
        $rows = [];
        foreach ([2, 4, 3, 55, 2, 4, 3, 55, 2, 4] as $i => $kg) {
            $rows[] = [sprintf('AS0600%02d', $i + 1), 'COBAstat Szary wariant '.($i + 1), $kg, 95.66 + $i, 57.45];
        }
        @unlink($this->file);
        $this->file = $this->workbook(['ESD - elektroizolacyjne' => $rows]);
        $this->card('AS060001', 'COBAstat Szary wariant 1', 95.66);

        $this->artisan('products:repair-price-list-codes', [
            'file' => $this->file, '--price-list' => $this->list->id, '--apply' => true, '--backup' => $this->backup,
        ])->expectsOutputToContain('kolumna „Waga [kg]” odgadnięta jako ilość w opakowaniu z samych liczb — pominięta')->assertSuccessful();

        $this->assertSame(10, Product::query()->count());
        $this->assertSame(0, Product::query()->whereNotNull('pack_qty')->count());
        $this->assertSame(0, ProductSourcePrice::query()->whereNotNull('pack_qty')->count());
    }

    public function test_price_safety_stops_when_the_mapping_does_not_fit_the_file(): void
    {
        $this->stateAfterImportOf1209();
        $before = $this->snapshot();
        $mapping = tempnam(sys_get_temp_dir(), 'mapping').'.json';
        $sheets = [];
        foreach (['Maty przemysłowe', 'Noże bezpieczne'] as $sheet) {
            // cena z kolumny transportu zamiast ExWorks
            $sheets[] = ['sheet' => $sheet, 'include' => true, 'header_excel_row' => 1, 'columns' => ['sku' => 0, 'name' => 1, 'catalog_price' => 4]];
        }
        file_put_contents($mapping, json_encode(['sheets' => $sheets]));

        try {
            $this->artisan('products:repair-price-list-codes', [
                'file' => $this->file, '--price-list' => $this->list->id, '--mapping' => $mapping, '--apply' => true, '--backup' => $this->backup,
            ])->expectsOutputToContain('Bezpiecznik')->assertFailed();
        } finally {
            @unlink($mapping);
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertFileDoesNotExist($this->backup);
    }

    /**
     * Karty cennika jak po imporcie z 12.09.2026 dawną regułą.
     *
     * @return array<string, Product>
     */
    private function stateAfterImportOf1209(bool $running = true): array
    {
        $state = [
            'exact' => $this->card('AF010003', 'Orthomat Standard', 1934.02),
            'af' => $this->card('AF0100', 'Orthomat Standard Czarny 1.2m x 18.3m (9.5mm)', 2608.70),
            'dp4' => $this->card('DP0100-4', 'Deckplate', 4107.35, ['enrichment_status' => Product::ENRICHMENT_DONE,
                'enrichment_payload' => ['parts_table' => ['part' => 'AF0107', 'via_short_code' => true, 'page_url' => 'https://www.coba.com/x']]]),
            'dp5' => $this->card('DP0100-5', 'Mata Deckplate 0,9 x 18,3 (nazwa ręczna)', 6623.02),
            'dp11' => $this->card('DP0100-11', 'Deckplate Czarny 0.6m x 18.3m (15mm)', 9318.12),
            'hi' => $this->card('HI0100', 'High-Duty', 302.32),
            'redirect' => $this->card('RR-POLACZONA', 'COBArib ręcznie połączony', 456.98),
            'orphan' => $this->card('XX0100', 'Mata wycofana z cennika', 99.00),
        ];
        if ($running) {
            $state['running'] = $this->card('OU0100', 'Orthomat Ultimate', 5120.10, ['enrichment_status' => Product::ENRICHMENT_RUNNING]);
        } else {
            $state['running'] = $this->card('OU0100', 'Orthomat Ultimate', 5120.10);
        }
        // karta usunięta z pominięciem przy imporcie (blokada po SKU dawnego rdzenia)
        $deleted = $this->card('BF0100-4', 'Bubblemat', 238.12);
        $this->assertSame(1, app(ProductDeletionService::class)->deleteMany([(int) $deleted->id], $this->user, true)['deleted']);
        // cudza karta z kodem wiersza
        $state['foreign'] = Product::query()->create(['sku' => 'CRS00005', 'name' => 'Guma innego producenta', 'manufacturer' => 'Inny', 'catalog_price_net' => 1, 'purchase_price' => 1]);
        CardRedirect::query()->create([
            'source_key' => 'file:'.$this->list->id, 'position_key' => 'RR010010', 'price_list_id' => $this->list->id,
            'product_id' => $state['redirect']->id, 'reason' => CardRedirect::REASON_MERGE, 'target_snapshot' => CardRedirectStore::snapshot($state['redirect']),
        ]);
        ProductPriceHistory::query()->create(['product_id' => $state['af']->id, 'price_list_id' => $this->list->id, 'catalog_price_net' => 2608.70, 'purchase_price' => 2608.70, 'currency' => 'PLN', 'source' => 'price_list_import']);
        ProductEnrichmentCache::query()->create(['manufacturer' => 'coba', 'sku' => 'af0100', 'description' => 'Opis maty Orthomat']);

        return $state;
    }

    /** @param  array<string, mixed>  $extra */
    private function card(string $sku, string $name, float $price, array $extra = []): Product
    {
        $card = Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => 'Coba', 'catalog_price_net' => $price, 'purchase_price' => $price, 'currency' => 'PLN',
            ...$extra,
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $this->list->id,
            'catalog_price_net' => $price, 'purchase_price' => $price, 'currency' => 'PLN',
        ]);

        return $card;
    }

    /** @return array<int, array{sku: string, name: string}> */
    private function snapshot(): array
    {
        return Product::query()->orderBy('id')->get()
            ->mapWithKeys(static fn (Product $p): array => [(int) $p->id => ['sku' => (string) $p->sku, 'name' => (string) $p->name]])
            ->all();
    }

    /** @return list<array<string, string>> */
    private function report(string $output): array
    {
        $this->assertSame(1, preg_match('/Raport CSV: (\S+\.csv)/u', $output, $m), $output);
        $lines = array_map(static fn (string $line): array => str_getcsv($line, ';'), preg_split('/\R/u', trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($m[1])))) ?: []);
        $header = array_shift($lines);

        return array_map(static fn (array $line): array => array_combine($header, $line), $lines);
    }

    /** @param  array<string, list<list<mixed>>>|null  $sheets */
    private function workbook(?array $sheets = null): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        foreach ($sheets ?? ['Maty przemysłowe' => self::MATS, 'Noże bezpieczne' => self::KNIVES] as $title => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $sheet->fromArray([self::HEADER, ...$rows], null, 'A1', true);
        }
        $transport = $spreadsheet->createSheet();
        $transport->setTitle('TRANSPORT');
        $transport->fromArray([
            ['Waga przesyłki [kg]', 'Koszt transportu do Polski [PLN net]', 'Operator'],
            ['do 2', 57.45, 'GLS'],
            ['2 - 5', 74.05, 'GLS'],
        ], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'coba').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
