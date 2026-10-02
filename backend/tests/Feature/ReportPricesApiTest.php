<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bSyncRun;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Services\Pricing\SupplierSpecialMask;
use App\Support\ProductPriceChangeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SupplierSpecialFixture;
use Tests\TestCase;

/**
 * Raport „Ruchy cen” (GET /api/reports/prices): zmiany cen dostawców w okresie, dodania, zmiany rabatu
 * i podejrzane skoki osobno; maska cen specjalnych B2B jak na karcie.
 * Zegar zamrożony na piątek 02.10.2026 12:00 UTC (14:00 w Polsce).
 */
final class ReportPricesApiTest extends TestCase
{
    use RefreshDatabase;
    use SupplierSpecialFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-02 12:00:00'));
        $this->setUpSupplierSpecial();
        Cache::flush();
    }

    public function test_requires_reports_and_products_permissions(): void
    {
        // handlowiec ma products.view, ale nie reports.view (trasa)
        Sanctum::actingAs($this->userWithRole('handlowiec'));
        $this->getJson('/api/reports/prices')->assertForbidden();

        Sanctum::actingAs($this->userWithCustomRole('tylko-raporty', ['reports.view']));
        $this->getJson('/api/reports/prices')->assertForbidden();

        Sanctum::actingAs($this->userWithCustomRole('raporty-produkty', ['reports.view', 'products.view']));
        $this->getJson('/api/reports/prices')->assertOk()->assertJsonPath('days', 30);
    }

    public function test_days_outside_allowed_list_fall_back_to_30(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $this->getJson('/api/reports/prices?days=45')
            ->assertOk()
            ->assertJsonPath('days', 30)
            // 30 dni kalendarzowych łącznie z dzisiejszym
            ->assertJsonPath('from', '2026-09-03');
        $this->getJson('/api/reports/prices?days=7')
            ->assertOk()
            ->assertJsonPath('days', 7)
            ->assertJsonPath('from', '2026-09-26')
            ->assertJsonCount(2, 'weekly');
        $this->getJson('/api/reports/prices?days=abc')->assertOk()->assertJsonPath('days', 30);
    }

    public function test_empty_history_gives_zeros_and_full_week_series(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $response = $this->getJson('/api/reports/prices')->assertOk();

        $this->assertNull($response->json('history_since'));
        $this->assertFalse($response->json('masked'));
        $this->assertSame(0, $response->json('totals.changes'));
        $this->assertNull($response->json('totals.median_pct'));
        $this->assertSame(
            ['2026-08-31', '2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28'],
            array_column($response->json('weekly'), 'week_start'),
        );
        $this->assertSame([], $response->json('top_increases'));
        $this->assertSame([], $response->json('suspicious'));
    }

    public function test_counts_changes_additions_discounts_and_suspicious_moves(): void
    {
        $this->scenario();
        Sanctum::actingAs($this->userWithRole('admin'));

        $response = $this->getJson('/api/reports/prices?days=30')->assertOk();

        $response->assertJsonPath('from', '2026-09-03')
            // 31.07 22:30 UTC = 01.08 w Polsce
            ->assertJsonPath('history_since', '2026-08-01')
            ->assertJsonPath('masked', false);
        $this->assertSame([
            'changes' => 3,             // plik Uvex +10%, konto Anro −10%, plik Portwest katalog +50%
            'increases' => 2,
            'decreases' => 1,
            'minor_changes' => 0,
            'products' => 3,
            'sources' => 3,
            'additions' => 2,           // pierwsza cena Anro bez przebiegu (C) i pierwsza cena skoku (D)
            'discount_changes' => 1,
            'suspicious' => 1,          // ×5
            // tylko podstawa „purchase”: [−10, 10] — +50% od katalogu się nie liczy
            'median_pct' => 0,
            'q1_pct' => -5,
            'q3_pct' => 5,
        ], $this->normalized($response->json('totals')));

        // B o 22:30 UTC w niedzielę 20.09 = poniedziałek 21.09 w Polsce
        $this->assertSame([
            ['week_start' => '2026-08-31', 'increases' => 0, 'decreases' => 0, 'minor' => 0],
            ['week_start' => '2026-09-07', 'increases' => 1, 'decreases' => 0, 'minor' => 0],
            ['week_start' => '2026-09-14', 'increases' => 0, 'decreases' => 0, 'minor' => 0],
            ['week_start' => '2026-09-21', 'increases' => 1, 'decreases' => 1, 'minor' => 0],
            ['week_start' => '2026-09-28', 'increases' => 0, 'decreases' => 0, 'minor' => 0],
        ], $response->json('weekly'));

        $sources = collect($response->json('sources'))->keyBy('source_label');
        $this->assertEqualsCanonicalizing(['Cennik Uvex 2026', 'Anro B2B', 'Cennik Portwest v1'], $sources->keys()->all());
        $this->assertEquals(10, $sources['Cennik Uvex 2026']['median_pct']);
        $this->assertEquals(-10, $sources['Anro B2B']['max_pct']);
        // procent od katalogu: bez mediany zakupu, ale z maksimum
        $this->assertNull($sources['Cennik Portwest v1']['median_pct']);
        $this->assertEquals(50, $sources['Cennik Portwest v1']['max_pct']);

        $this->assertSame('Portwest', $response->json('manufacturers.0.manufacturer'));
        $this->assertSame(2, $response->json('manufacturers.0.changes'));
        $this->assertEquals(-10, $response->json('manufacturers.0.median_pct'));
        $this->assertSame('Uvex', $response->json('manufacturers.1.manufacturer'));

        $response->assertJsonCount(2, 'top_increases')
            ->assertJsonPath('top_increases.0.sku', 'G-CAT')
            ->assertJsonPath('top_increases.0.basis', 'catalog')
            ->assertJsonPath('top_increases.0.source_label', 'Cennik Portwest v1')
            ->assertJsonPath('top_increases.1.sku', 'A-FILE')
            ->assertJsonPath('top_increases.1.manufacturer', 'Uvex')
            ->assertJsonPath('top_increases.1.at', '2026-09-10T08:00:00+00:00')
            ->assertJsonCount(1, 'top_decreases')
            ->assertJsonPath('top_decreases.0.sku', 'B-B2B')
            ->assertJsonPath('top_decreases.0.basis', 'purchase')
            ->assertJsonPath('top_decreases.0.source_label', 'Anro B2B');
        $this->assertEquals(10, $response->json('top_increases.1.old'));
        $this->assertEquals(11, $response->json('top_increases.1.new'));
        $this->assertEquals(10, $response->json('top_increases.1.pct'));
        $this->assertEquals(100, $response->json('top_decreases.0.old'));
        $this->assertEquals(90, $response->json('top_decreases.0.new'));

        $response->assertJsonCount(1, 'suspicious')
            ->assertJsonPath('suspicious.0.sku', 'D-JUMP')
            ->assertJsonPath('suspicious.0.currency', 'PLN');
        $this->assertEquals(10, $response->json('suspicious.0.old'));
        $this->assertEquals(50, $response->json('suspicious.0.new'));
        $this->assertEquals(400, $response->json('suspicious.0.pct'));
    }

    public function test_minor_changes_count_as_changes_but_stay_off_the_weekly_chart(): void
    {
        $uvex = PriceList::query()->create(['manufacturer' => 'Uvex', 'version' => '2026']);
        $a = $this->product('M-SMALL', 'Uvex');
        $this->historyRow($a, 200, 100, 'price_list_import', '2026-09-01 08:00:00', $uvex->id);
        // +0,5% — drobna korekta
        $this->historyRow($a, 200, 100.5, 'price_list_import', '2026-09-29 08:00:00', $uvex->id);
        $b = $this->product('M-BIG', 'Uvex');
        $this->historyRow($b, 200, 100, 'price_list_import', '2026-09-01 08:00:00', $uvex->id);
        // −1% — na granicy: już istotna
        $this->historyRow($b, 200, 99, 'price_list_import', '2026-09-29 08:00:00', $uvex->id);
        // dokładnie ×4 (np. opakowanie 4 szt. zamiast sztuki) to już skok do sprawdzenia, nie podwyżka +300%
        $c = $this->product('M-X4', 'Uvex');
        $this->historyRow($c, 200, 10, 'price_list_import', '2026-09-01 08:00:00', $uvex->id);
        $this->historyRow($c, 200, 40, 'price_list_import', '2026-09-29 08:00:00', $uvex->id);
        Sanctum::actingAs($this->userWithRole('admin'));

        $response = $this->getJson('/api/reports/prices?days=7')->assertOk();

        $response->assertJsonPath('totals.suspicious', 1)
            ->assertJsonPath('suspicious.0.sku', 'M-X4')
            ->assertJsonPath('totals.changes', 2)
            ->assertJsonPath('totals.increases', 1)
            ->assertJsonPath('totals.decreases', 1)
            ->assertJsonPath('totals.minor_changes', 1)
            ->assertJsonPath('sources.0.minor', 1)
            // w wierszach źródeł i producentów podwyżki/obniżki tylko istotne
            ->assertJsonPath('sources.0.increases', 0)
            ->assertJsonPath('sources.0.decreases', 1)
            ->assertJsonPath('manufacturers.0.minor', 1)
            ->assertJsonPath('manufacturers.0.increases', 0);
        $this->assertSame([
            ['week_start' => '2026-09-21', 'increases' => 0, 'decreases' => 0, 'minor' => 0],
            ['week_start' => '2026-09-28', 'increases' => 0, 'decreases' => 1, 'minor' => 1],
        ], $response->json('weekly'));
    }

    public function test_changes_since_returns_moves_in_period_with_kind_and_group(): void
    {
        $this->scenario();

        $moves = iterator_to_array(app(ProductPriceChangeResolver::class)->changesSince(
            Carbon::parse('2026-09-03 00:00:00', 'Europe/Warsaw'),
            SupplierSpecialMask::revealing(),
        ), false);

        $byKind = collect($moves)->groupBy('kind')->map->count()->all();
        $this->assertEquals(['addition' => 2, 'change' => 5], $byKind);

        $file = collect($moves)->firstWhere('purchase_new', 11.0);
        $this->assertSame('change', $file['kind']);
        $this->assertSame('file', $file['source_group']);
        // poprzedni wiersz sprzed okresu
        $this->assertEquals(10, $file['purchase_old']);
        $this->assertSame('Cennik Uvex 2026', $file['source_label']);

        $discount = collect($moves)->firstWhere('source', 'price_list_discount');
        $this->assertSame('Zmiana rabatu · Cennik Uvex 2026', $discount['source_label']);
        $this->assertSame('file', $discount['source_group']);

        $b2b = collect($moves)->firstWhere('purchase_new', 90.0);
        $this->assertMatchesRegularExpression('/^account:\d+$/', $b2b['source_group']);

        $addition = collect($moves)->firstWhere('kind', 'addition');
        $this->assertNull($addition['purchase_old']);
        $this->assertNull($addition['pct']);
        $this->assertNull($addition['pct_basis']);

        // zmiana waluty w źródle (E) — ani zmiana, ani dodanie; wiersze sprzed okresu pominięte
        $this->assertNull(collect($moves)->firstWhere('purchase_new', 43.0));
        $this->assertSame([], array_values(array_filter($moves, static fn (array $m): bool => $m['at'] < '2026-09-02T22:00')));
    }

    public function test_special_price_account_changes_are_hidden_without_permission(): void
    {
        $card = $this->supplierSpecialCard('MASK-1');
        // wcześniejsza cena tego samego konta (przebieg UVEX) — fixture dopisuje 173,19 „teraz”
        $this->historyRow($card['product'], 160.0, 160.0, 'b2b:uvex', '2026-09-20 06:00:00', runId: (int) $card['run']->id, currency: 'PLN');

        // kierownik: reports.view + products.view, bez prices.supplier_special.view
        Sanctum::actingAs($this->userWithRole('kierownik'));
        $masked = $this->getJson('/api/reports/prices')->assertOk();
        $masked->assertJsonPath('masked', true)
            ->assertJsonPath('totals.changes', 0)
            ->assertJsonPath('totals.additions', 0)
            ->assertJsonCount(0, 'top_increases');
        $this->assertNoSpecialLeak((string) $masked->getContent());

        foreach (['dyrektor', 'admin'] as $role) {
            Sanctum::actingAs($this->userWithRole($role));
            $full = $this->getJson('/api/reports/prices')->assertOk();
            $full->assertJsonPath('masked', false)
                ->assertJsonPath('totals.changes', 1)
                ->assertJsonPath('totals.additions', 1)
                ->assertJsonPath('top_increases.0.sku', 'MASK-1')
                ->assertJsonPath('top_increases.0.source_label', 'UVEX B2B');
            $this->assertEquals(173.19, $full->json('top_increases.0.new'), $role);
        }
    }

    public function test_result_is_cached_per_mask_and_days(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $this->getJson('/api/reports/prices')->assertOk()->assertJsonPath('totals.changes', 0);

        $this->scenario();

        // 10 minut z pamięci podręcznej, potem świeże dane
        $this->getJson('/api/reports/prices')->assertOk()->assertJsonPath('totals.changes', 0);
        $this->getJson('/api/reports/prices?days=90')->assertOk()->assertJsonPath('totals.changes', 3);
        $this->travel(11)->minutes();
        $this->getJson('/api/reports/prices')->assertOk()->assertJsonPath('totals.changes', 3);
    }

    /**
     * A: plik Uvex, poprzedni wiersz sprzed okresu, zmiana +10% i rabat; B: konto Anro −10% (granica tygodnia
     * w czasie polskim); C: pierwsza cena Anro bez przebiegu (dodanie); D: dodanie i skok ×5; E: zmiana waluty;
     * G: plik Portwest, zmiana tylko katalogu (+50% od katalogu).
     */
    private function scenario(): void
    {
        $uvex = PriceList::query()->create(['manufacturer' => 'Uvex', 'version' => '2026']);
        $portwest = PriceList::query()->create(['manufacturer' => 'Portwest', 'version' => 'v1']);
        $anro = $this->otherAccount('anro');
        $run = B2bSyncRun::query()->create([
            'b2b_account_id' => $anro->id,
            'status' => 'finished',
            'trigger' => 'manual',
            'started_at' => Carbon::parse('2026-08-20 06:00:00'),
            'finished_at' => Carbon::parse('2026-08-20 06:10:00'),
        ]);

        $a = $this->product('A-FILE', 'Uvex');
        $this->historyRow($a, 20, 10, 'price_list_import', '2026-07-31 22:30:00', $uvex->id);
        $this->historyRow($a, 20, 11, 'price_list_import', '2026-09-10 08:00:00', $uvex->id);
        $this->historyRow($a, 20, 9.9, 'price_list_discount', '2026-09-12 08:00:00', $uvex->id);
        // ta sama cena ponownie — nie zmiana
        $this->historyRow($a, 20, 9.9, 'price_list_import', '2026-09-25 08:00:00', $uvex->id);

        $b = $this->product('B-B2B', 'Portwest');
        $this->historyRow($b, 120, 100, 'b2b:anro', '2026-08-20 06:05:00', runId: (int) $run->id);
        $this->historyRow($b, 120, 90, 'b2b:anro', '2026-09-20 22:30:00', runId: (int) $run->id);

        $c = $this->product('C-NEW', 'Portwest');
        $this->historyRow($c, 30, 25, 'b2b:anro', '2026-09-15 08:00:00');

        $d = $this->product('D-JUMP', 'Ardon');
        $this->historyRow($d, 10, 10, 'b2b:ardon', '2026-09-06 08:00:00', currency: 'PLN');
        $this->historyRow($d, 50, 50, 'b2b:ardon', '2026-09-16 08:00:00', currency: 'PLN');

        $e = $this->product('E-CUR', 'Bolle');
        $this->historyRow($e, 10, 10, 'b2b:bolle', '2026-08-15 08:00:00', currency: 'EUR');
        $this->historyRow($e, 43, 43, 'b2b:bolle', '2026-09-18 08:00:00', currency: 'PLN');

        $g = $this->product('G-CAT', 'Portwest');
        $this->historyRow($g, 20, 15, 'price_list_import', '2026-08-25 08:00:00', $portwest->id);
        $this->historyRow($g, 30, 15, 'price_list_import', '2026-09-22 08:00:00', $portwest->id);
    }

    private function product(string $sku, string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Produkt '.$sku,
            'manufacturer' => $manufacturer,
            'catalog_price_net' => 10,
            'purchase_price' => 10,
            'stock' => 1,
        ]);
    }

    private function historyRow(
        Product $product,
        float $catalog,
        float $purchase,
        string $source,
        string $at,
        ?int $priceListId = null,
        ?int $runId = null,
        ?string $currency = null,
    ): void {
        $row = new ProductPriceHistory;
        $row->forceFill([
            'product_id' => $product->id,
            'price_list_id' => $priceListId,
            'b2b_sync_run_id' => $runId,
            'catalog_price_net' => $catalog,
            'purchase_price' => $purchase,
            'currency' => $currency,
            'source' => $source,
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
        ])->save();
    }

    /**
     * Liczby z JSON-a bez rozróżnienia 0 / 0.0 (json_encode gubi część ułamkową).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalized(array $values): array
    {
        return array_map(static fn (mixed $v): mixed => is_float($v) && floor($v) === $v ? (int) $v : $v, $values);
    }
}
