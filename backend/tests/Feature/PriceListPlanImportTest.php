<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AssortmentGroup;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardRedirect;
use App\Models\PriceList;
use App\Models\PriceListFile;
use App\Models\Product;
use App\Models\ProductImportExclusion;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Catalog\CardRedirectStore;
use App\Services\PriceListImportService;
use App\Services\PriceLists\Importers\ImportedRow;
use App\Services\ProductDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * planImport (podgląd importu bez zapisu) podejmuje te same decyzje co zapis importu (importCollected →
 * persistImport): scenariusze z PriceListImportRedirectTest, PriceListImportExclusionTest, PriceListGoodsBrandImportTest
 * i PriceListGluedCodeImportTest. Każdy test: najpierw plan (zero zapisów), potem import tych samych pozycji i porównanie.
 */
final class PriceListPlanImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $this->user = User::factory()->create();
    }

    public function test_redirect_rows_of_one_target_card_plan_one_update_of_that_card(): void
    {
        $list = $this->list('Anro');
        $card = Product::query()->create([
            'sku' => 'KOMPLET-1', 'name' => 'Zestaw kask z nausznikami', 'manufacturer' => 'Anro',
            'catalog_price_net' => 28.00, 'purchase_price' => 24.00, 'currency' => 'PLN',
        ]);
        $this->redirect($list, ['Q-1', 'Z-2'], $card);

        [$plan] = $this->assertPlanMatchesImport($list, [
            new ImportedRow('Q-1', 'Kask ochronny biały', 32.00, 'w2', purchasePrice: 26.00),
            new ImportedRow('Z-2', 'Nauszniki przeciwhałasowe', 32.00, 'w3', purchasePrice: 26.00),
        ]);

        $this->assertSame(0, $plan['created']);
        $this->assertSame(1, $plan['updated']);
        $this->assertSame([(int) $card->id, (int) $card->id], array_column($plan['rows'], 'product_id'));
        $this->assertSame('KOMPLET-1', $card->fresh()?->sku);
    }

    public function test_redirect_to_foreign_brand_card_is_skipped_in_plan_and_import(): void
    {
        $list = $this->list('Anro');
        $card = Product::query()->create(['sku' => 'P-1', 'name' => 'Rękawica producenta', 'manufacturer' => 'Polstar', 'catalog_price_net' => 5, 'purchase_price' => 4, 'currency' => 'PLN']);
        $this->redirect($list, ['D-1'], $card);

        [$plan] = $this->assertPlanMatchesImport($list, [new ImportedRow('D-1', 'Rękawica dystrybutora', 6.00, 'w2')]);

        $this->assertSame(PriceListImportService::ROW_SKIP, $plan['rows'][0]['action']);
        $this->assertStringContainsString('Polstar', (string) $plan['rows'][0]['reason']);
    }

    public function test_excluded_position_is_blocked_in_plan_and_import(): void
    {
        $list = $this->list('Anro');
        $v1 = [
            new ImportedRow('EX-100', 'Rękawica powlekana nitrylem', 12.00, 'w2'),
            new ImportedRow('EX-200', 'Kask ochronny biały', 30.00, 'w3'),
        ];
        app(PriceListImportService::class)->importCollected($list, $this->file($list, $v1), $this->user, $this->collected($list, $v1), null);
        $kept = Product::query()->where('sku', 'EX-200')->sole();
        $deleted = app(ProductDeletionService::class)->deleteMany([(int) Product::query()->where('sku', 'EX-100')->value('id')], $this->user, true);
        $this->assertSame(1, $deleted['deleted']);
        $this->assertSame(1, ProductImportExclusion::query()->count());

        [$plan, $result] = $this->assertPlanMatchesImport($list, [
            new ImportedRow('EX-100', 'Rękawica powlekana nitrylem', 13.00, 'w2'),
            new ImportedRow('EX-200', 'Kask ochronny biały', 31.00, 'w3'),
        ]);

        $this->assertSame(PriceListImportService::ROW_BLOCKED, $plan['rows'][0]['action']);
        $this->assertSame(1, $plan['suppressed']);
        $this->assertSame((int) $kept->id, $plan['rows'][1]['product_id']);
        $this->assertSame(0, Product::query()->where('sku', 'EX-100')->count());
        $this->assertSame(1, $result['suppressed']);
    }

    public function test_canis_goods_brand_rows_plan_like_import(): void
    {
        config(['price_lists.brand_from_name' => ['canis']]);
        $list = $this->list('Canis');
        $other = $this->list('P4S');
        // karta 3M z ceną innego cennika: wiersz z marką z nazwy nie nadpisuje jej ceny
        $this->card('9914', 'Respirator 3M 9914', '3M', 9.00, $other);
        // własna karta Canis bez slotu tego cennika — aktualizuje się
        $this->card('CX-1', 'Rękawica Canis CX-1', 'Canis', 4.00);

        [$plan] = $this->assertPlanMatchesImport($list, [
            new ImportedRow('9914', 'Respirator 3M 9914', 11.00, 'w2'),
            new ImportedRow('CX-1', 'Rękawica Canis CX-1', 5.00, 'w3'),
            new ImportedRow('NEW-3M', 'Półmaska 3M 6200', 70.00, 'w4'),
        ]);

        $this->assertSame([PriceListImportService::ROW_SKIP, PriceListImportService::ROW_UPDATE, PriceListImportService::ROW_CREATE], array_column($plan['rows'], 'action'));
        $this->assertSame('3M', Product::query()->where('sku', 'NEW-3M')->value('manufacturer'));
        $this->assertStringContainsString('Marka z nazwy wyrobu', implode("\n", $plan['notes']));
    }

    public function test_glued_codes_find_legacy_cut_card_and_second_row_reuses_it(): void
    {
        $list = $this->list('Coba');
        $legacy = $this->card('AF0100', 'Orthomat Standard Czarny', 'Coba', 2608.70, $list);

        [$plan, $result] = $this->assertPlanMatchesImport($list, [
            new ImportedRow('AF010005', 'Orthomat Standard Czarny 1.2m x 18.3m (9.5mm)', 2608.70, 'w2'),
            new ImportedRow('AF010006', 'Orthomat Standard Czarny 1.5m x 18.3m (9.5mm)', 2608.70, 'w3'),
            new ImportedRow('AF010003', 'Orthomat Standard Czarny 0.9m x 18.3m (9.5mm)', 1934.02, 'w4'),
        ]);

        $this->assertSame([(int) $legacy->id, (int) $legacy->id], [$plan['rows'][0]['product_id'], $plan['rows'][1]['product_id']]);
        $this->assertSame(PriceListImportService::ROW_CREATE, $plan['rows'][2]['action']);
        $this->assertSame('AF0100', $legacy->fresh()?->sku);
        $this->assertStringContainsString('repair-price-list-codes', implode("\n", $result['errors']));
    }

    public function test_repeated_new_code_plans_create_then_update_of_the_same_new_card(): void
    {
        $list = $this->list('Anro');

        [$plan, $result] = $this->assertPlanMatchesImport($list, [
            new ImportedRow('N-1', 'Nowa rękawica', 10.00, 'w2'),
            new ImportedRow('N-1', 'Nowa rękawica', 12.00, 'w3'),
        ]);

        $this->assertSame([PriceListImportService::ROW_CREATE, PriceListImportService::ROW_UPDATE], array_column($plan['rows'], 'action'));
        $this->assertSame([null, null], array_column($plan['rows'], 'product_id'));
        // druga pozycja zmienia cenę nowej karty — jak przy zapisie
        $this->assertSame(['N-1'], array_column($plan['price_changes'], 'sku'));
        $this->assertSame(1, $result['created']);
    }

    public function test_card_with_b2b_link_keeps_its_name_in_plan_and_import(): void
    {
        $list = $this->list('Anro');
        $card = $this->card('RK-100', 'Rękawica RK-100', 'Anro', 8.00, $list);
        B2bProductLink::query()->create([
            'b2b_account_id' => B2bAccount::query()->create(['username' => 'anro', 'password' => 'x', 'sites' => ['b2b.anro.pl'], 'connector' => 'anro'])->id,
            'remote_id' => '400', 'product_id' => $card->id,
        ]);

        $this->assertPlanMatchesImport($list, [
            new ImportedRow('RK-100', 'Rękawica RK-100 NOWA NAZWA', 8.50, 'w2'),
            new ImportedRow('RK-200', 'Rękawica RK-200', 9.00, 'w3'),
        ]);
        // karta z powiązaniem B2B: nazwa zostaje (zwykła reguła zapisu) — plan i zapis zgodne
        $this->assertSame('Rękawica RK-100', $card->fresh()?->name);
    }

    public function test_card_renamed_by_earlier_row_is_not_found_under_old_code(): void
    {
        $list = $this->list('Rostaing');
        $card = $this->card('CRIOT08', 'Rękawice kriogeniczne CRIOT', 'Rostaing', 82.99, $list);

        [$plan] = $this->assertPlanMatchesImport($list, [
            new ImportedRow('CRIOT09', 'Rękawice kriogeniczne CRIOT', 82.99, 'w2'),
            new ImportedRow('CRIOT08', 'Rękawice kriogeniczne CRIOT', 82.99, 'w3'),
            new ImportedRow('CRIOT10', 'Rękawice kriogeniczne CRIOT', 82.99, 'w4'),
        ]);

        // karta znaleziona rdzeniem zmienia kod przy każdym wierszu (zapis: CRIOT09 → CRIOT08 → CRIOT10); plan widzi
        // te same zmiany kodu w pamięci, więc trafia w tę samą kartę
        $this->assertSame([(int) $card->id, (int) $card->id, (int) $card->id], array_column($plan['rows'], 'product_id'));
        $this->assertSame('CRIOT10', $card->fresh()?->sku);
    }

    public function test_plan_matches_codes_case_insensitively_like_mysql(): void
    {
        $list = $this->list('Anro');
        $card = $this->card('AB1', 'Kask AB1', 'Anro', 10.00, $list);

        $plan = app(PriceListImportService::class)->planImport($list, $this->collected($list, [
            new ImportedRow('ab1', 'Kask AB1', 12.00, 'w2'),
            new ImportedRow('NOWY-x', 'Nowy kask', 5.00, 'w3'),
            new ImportedRow('nowy-X', 'Nowy kask', 6.00, 'w4'),
        ]), null);

        $this->assertSame([PriceListImportService::ROW_UPDATE, PriceListImportService::ROW_CREATE, PriceListImportService::ROW_UPDATE], array_column($plan['rows'], 'action'));
        $this->assertSame((int) $card->id, $plan['rows'][0]['product_id']);
        $this->assertSame($plan['rows'][1]['card'], $plan['rows'][2]['card']);
        $this->assertSame(1, $plan['created']);
        $this->assertSame(2, $plan['updated']);
    }

    public function test_plan_writes_nothing(): void
    {
        $list = $this->list('Anro');
        $this->card('A-1', 'Kask', 'Anro', 10.00, $list);
        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) === 1) {
                $writes++;
            }
        });

        app(PriceListImportService::class)->planImport($list, $this->collected($list, [
            new ImportedRow('A-1', 'Kask', 12.00, 'w2'),
            new ImportedRow('B-2', 'Nowy kask', 15.00, 'w3'),
        ]), ['default_discount' => 10]);

        $this->assertSame(0, $writes);
        $this->assertSame(0, AssortmentGroup::query()->count());
    }

    /**
     * @param  list<ImportedRow>  $rows
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} plan i wynik importu
     */
    private function assertPlanMatchesImport(PriceList $list, array $rows): array
    {
        $service = app(PriceListImportService::class);
        $collected = $this->collected($list, $rows);
        $before = Product::query()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $slots = ProductSourcePrice::query()->count();

        $plan = $service->planImport($list, $collected, null);

        $this->assertSame(count($before), Product::query()->count(), 'plan nie zakłada kart');
        $this->assertSame($slots, ProductSourcePrice::query()->count(), 'plan nie zapisuje slotów');

        $result = $service->importCollected($list->fresh(), $this->file($list, $rows), $this->user, $collected, null);

        $this->assertSame($result['created'], $plan['created'], 'created');
        $this->assertSame($result['updated'], $plan['updated'], 'updated');
        $this->assertSame($result['skipped'], $plan['skipped'], 'skipped');
        $this->assertSame($result['suppressed'], $plan['suppressed'], 'suppressed');
        $this->assertCount(count($rows), $plan['rows']);
        foreach ($plan['rows'] as $row) {
            if ($row['action'] === PriceListImportService::ROW_UPDATE && $row['product_id'] !== null) {
                $this->assertSame($row['product_id'], $result['row_cards'][$row['sku']] ?? null, 'karta wiersza '.$row['sku']);
            }
            if ($row['action'] === PriceListImportService::ROW_CREATE) {
                $this->assertArrayHasKey($row['sku'], $result['row_cards']);
                $this->assertNotContains($result['row_cards'][$row['sku']], $before, 'nowa karta '.$row['sku']);
            }
            if (in_array($row['action'], [PriceListImportService::ROW_SKIP, PriceListImportService::ROW_BLOCKED], true)) {
                $this->assertArrayNotHasKey($row['sku'], $result['row_cards']);
            }
        }
        $planChanges = array_column($plan['price_changes'], 'sku');
        $importChanges = array_column($result['price_changes'], 'sku');
        sort($planChanges);
        sort($importChanges);
        $this->assertSame($importChanges, $planChanges, 'zmiany cen');

        return [$plan, $result];
    }

    /**
     * @param  list<ImportedRow>  $rows
     * @return array<string, mixed>
     */
    private function collected(PriceList $list, array $rows): array
    {
        return [
            'products' => array_map(static fn (ImportedRow $row): array => $row->toPayload((string) $list->manufacturer), $rows),
            'skipped' => 0,
            'errors' => [],
            'rows_total' => count($rows),
            'skipped_details' => [],
        ];
    }

    /** @param  list<ImportedRow>  $rows */
    private function file(PriceList $list, array $rows): PriceListFile
    {
        $content = implode("\n", array_map(static fn (ImportedRow $row): string => $row->sku, $rows));
        $sha = hash('sha256', $content.microtime());
        Storage::disk('local')->put('price-list-files/'.$sha.'.csv', $content);

        return PriceListFile::query()->create([
            'price_list_id' => $list->id, 'sha256' => $sha, 'disk' => 'local', 'path' => 'price-list-files/'.$sha.'.csv',
            'original_name' => 'cennik.csv', 'size' => strlen($content), 'mime' => 'text/csv', 'status' => PriceListFile::STATUS_NEW,
        ]);
    }

    private function list(string $manufacturer): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => $manufacturer, 'manufacturer_key' => PriceList::manufacturerKey($manufacturer), 'version' => '2026',
            'rows_total' => 0, 'products_created' => 0, 'products_updated' => 0, 'rows_skipped' => 0,
            'source_policy' => PriceList::POLICY_MAP_ONLY,
        ]);
    }

    private function card(string $sku, string $name, string $manufacturer, float $price, ?PriceList $list = null): Product
    {
        $card = Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => $manufacturer,
            'catalog_price_net' => $price, 'purchase_price' => $price, 'currency' => 'PLN',
        ]);
        if ($list !== null) {
            ProductSourcePrice::query()->create([
                'product_id' => $card->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id,
                'catalog_price_net' => $price, 'purchase_price' => $price, 'currency' => 'PLN',
            ]);
        }

        return $card;
    }

    /** @param  list<string>  $positions */
    private function redirect(PriceList $list, array $positions, Product $card): void
    {
        foreach ($positions as $position) {
            CardRedirect::query()->create([
                'source_key' => 'file:'.$list->id, 'position_key' => $position, 'price_list_id' => $list->id,
                'product_id' => $card->id, 'reason' => CardRedirect::REASON_MERGE, 'target_snapshot' => CardRedirectStore::snapshot($card),
            ]);
        }
    }
}
