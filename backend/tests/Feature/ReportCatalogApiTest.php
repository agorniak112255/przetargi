<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpItemLink;
use App\Models\Product;
use App\Models\User;
use App\Services\Reports\CatalogReport;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/reports/catalog — raport R1 „Baza wiedzy”. Teraz = piątek 02.10.2026 12:00 w Warszawie (10:00 UTC),
 * bieżący tydzień od 28.09.2026.
 */
final class ReportCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    private int $gid = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Europe/Warsaw'));
    }

    public function test_requires_reports_view_and_products_view(): void
    {
        Sanctum::actingAs($this->userWith(['products.view']));
        $this->getJson('/api/reports/catalog')->assertForbidden();

        Sanctum::actingAs($this->userWith(['reports.view']));
        $this->getJson('/api/reports/catalog')->assertForbidden();

        Sanctum::actingAs($this->userWith(['reports.view', 'products.view']));
        $this->getJson('/api/reports/catalog')->assertOk();
    }

    public function test_counts_coverage_manufacturers_documents_sold_and_weeks(): void
    {
        // pełna karta: długi opis, zdjęcie, normy tekstem i u producenta, dwa certyfikaty + karta techniczna, wektor
        $p1 = $this->product('P1', 'UVEX', [
            'description' => str_repeat('Rękawica ochronna. ', 10),
            'norms' => 'EN 388',
            'manufacturer_norms' => ['EN 388' => '4X42C'],
            'embedding_synced_at' => now(),
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
        // opis i normy z samych spacji, producent pusty
        $p2 = $this->product('P2', '', [
            'description' => '   ',
            'norms' => '  ',
            'enrichment_status' => Product::ENRICHMENT_FAILED,
        ]);
        // krótki opis, producent z samych spacji
        $p3 = $this->product('P3', '  ', [
            'description' => 'Krótki opis ąę',
            'enrichment_status' => Product::ENRICHMENT_MANUAL,
        ]);
        // bez opisu, ze zdjęciem; producent ze spacjami wokół — ta sama grupa co UVEX
        $p4 = $this->product('P4', ' UVEX ', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        // dokładnie 120 znaków (polskie litery) — nie jest krótki
        $p5 = $this->product('P5', 'Ansell', [
            'description' => str_repeat('ą', 120),
            'enrichment_status' => Product::ENRICHMENT_RUNNING,
            'enriched_at' => now(),
        ]);
        $p6 = $this->product('P6', 'UVEX', []);

        $this->image($p1->id);
        $this->image($p4->id);
        $this->document($p1->id, 'certificate', 'a');
        $this->document($p1->id, 'certificate', 'b');
        $this->document($p1->id, 'datasheet', 'c');
        $this->document($p3->id, 'datasheet', 'd');

        // daty dodania / wzbogacenia (UTC w bazie)
        DB::table('products')->where('id', $p1->id)->update(['enriched_at' => '2026-09-29 08:00:00']);
        // poniedziałek 00:30 w Warszawie = niedziela 22:30 UTC — tydzień od 28.09, nie od 21.09
        DB::table('products')->where('id', $p5->id)->update(['created_at' => '2026-09-27 22:30:00']);
        DB::table('products')->where('id', $p6->id)->update(['created_at' => '2026-07-20 10:00:00']);
        // poza 26 tygodniami
        DB::table('products')->where('id', $p3->id)->update(['created_at' => '2025-01-01 10:00:00']);

        // sprzedaż w XL: P2 (4 braki), P4 (3 braki), P3 (2 braki, dwa towary — najpóźniejsza data), P1 bez braków
        $this->link($this->erpItem('2026-09-20'), $p2->id, ErpItemLink::STATUS_CONFIRMED);
        $this->link($this->erpItem('2026-05-01'), $p1->id, ErpItemLink::STATUS_AUTO);
        $this->link($this->erpItem('2026-09-25'), $p3->id, ErpItemLink::STATUS_AUTO);
        $this->link($this->erpItem('2026-08-01'), $p3->id, ErpItemLink::STATUS_CONFIRMED);
        $this->link($this->erpItem('2026-09-01'), $p4->id, ErpItemLink::STATUS_AUTO);
        // pominięte: odrzucone powiązanie, towar usunięty z XL, sprzedaż sprzed 12 mies., propozycja
        $this->link($this->erpItem('2026-09-30'), $p5->id, ErpItemLink::STATUS_REJECTED);
        $this->link($this->erpItem('2026-09-29', removed: true), $p5->id, ErpItemLink::STATUS_CONFIRMED);
        $this->link($this->erpItem('2025-09-01'), $p6->id, ErpItemLink::STATUS_AUTO);
        $this->link($this->erpItem('2026-09-28'), $p6->id, ErpItemLink::STATUS_SUGGESTED);

        Sanctum::actingAs($this->userWith(['reports.view', 'products.view']));
        $r = $this->getJson('/api/reports/catalog')->assertOk();

        $this->assertSame(CatalogReport::SHORT_DESCRIPTION_CHARS, $r->json('short_description_chars'));
        $this->assertSame(now()->toIso8601String(), $r->json('generated_at'));
        $this->assertSame([
            'products' => 6,
            'with_description' => 3,
            'short_description' => 1,
            'with_image' => 2,
            'with_norms_text' => 1,
            'with_manufacturer_norms' => 1,
            'with_documents' => 2,
            'vector_indexed' => 1,
            'enrichment' => ['none' => 1, 'queued' => 1, 'running' => 1, 'done' => 1, 'failed' => 1, 'manual' => 1],
        ], $r->json('totals'));

        $this->assertSame([
            ['kind' => 'datasheet', 'products' => 2],
            ['kind' => 'certificate', 'products' => 1],
        ], $r->json('documents_by_kind'));

        $this->assertSame([
            [
                'manufacturer' => 'UVEX', 'products' => 3, 'with_description' => 1, 'with_image' => 2,
                'with_norms_text' => 1, 'with_manufacturer_norms' => 1, 'with_documents' => 1, 'enrichment_failed' => 0,
            ],
            [
                'manufacturer' => CatalogReport::NO_MANUFACTURER, 'products' => 2, 'with_description' => 1, 'with_image' => 0,
                'with_norms_text' => 0, 'with_manufacturer_norms' => 0, 'with_documents' => 1, 'enrichment_failed' => 1,
            ],
            [
                'manufacturer' => 'Ansell', 'products' => 1, 'with_description' => 1, 'with_image' => 0,
                'with_norms_text' => 0, 'with_manufacturer_norms' => 0, 'with_documents' => 0, 'enrichment_failed' => 0,
            ],
        ], $r->json('manufacturers'));

        $this->assertSame([
            'window_months' => 12,
            'products' => 4,
            'with_description' => 2,
            'with_image' => 2,
            'with_manufacturer_norms' => 1,
            'with_documents' => 2,
        ], $r->json('sold'));

        $this->assertSame([
            ['id' => $p2->id, 'sku' => 'P2', 'name' => 'Karta P2', 'manufacturer' => null, 'last_sale_at' => '2026-09-20',
                'missing' => ['description', 'image', 'manufacturer_norms', 'documents']],
            ['id' => $p4->id, 'sku' => 'P4', 'name' => 'Karta P4', 'manufacturer' => 'UVEX', 'last_sale_at' => '2026-09-01',
                'missing' => ['description', 'manufacturer_norms', 'documents']],
            ['id' => $p3->id, 'sku' => 'P3', 'name' => 'Karta P3', 'manufacturer' => null, 'last_sale_at' => '2026-09-25',
                'missing' => ['image', 'manufacturer_norms']],
        ], $r->json('gaps_sold'));

        $weekly = $r->json('weekly');
        $this->assertCount(26, $weekly);
        $this->assertSame('2026-04-06', $weekly[0]['week_start']);
        $this->assertSame(['week_start' => '2026-09-28', 'added' => 4, 'enriched' => 1], $weekly[25]);
        $byWeek = array_column($weekly, null, 'week_start');
        $this->assertSame(['week_start' => '2026-07-20', 'added' => 1, 'enriched' => 0], $byWeek['2026-07-20']);
        $this->assertSame(['week_start' => '2026-09-21', 'added' => 0, 'enriched' => 0], $byWeek['2026-09-21']);
        $this->assertSame(5, array_sum(array_column($weekly, 'added')));
        $this->assertSame(1, array_sum(array_column($weekly, 'enriched')));
    }

    public function test_sold_is_null_without_any_erp_link_and_zero_when_nothing_sold_recently(): void
    {
        $card = $this->product('X1', 'UVEX', ['description' => 'Opis']);

        Sanctum::actingAs($this->userWith(['reports.view', 'products.view']));
        $r = $this->getJson('/api/reports/catalog')->assertOk();
        $this->assertNull($r->json('sold'));
        $this->assertSame([], $r->json('gaps_sold'));

        // powiązanie jest, ale bez sprzedaży w 12 mies. — zera, nie null
        $this->link($this->erpItem('2024-01-01'), $card->id, ErpItemLink::STATUS_CONFIRMED);
        $this->app['cache']->store()->forget(CatalogReport::CACHE_KEY);
        $r = $this->getJson('/api/reports/catalog')->assertOk();
        $this->assertSame([
            'window_months' => 12, 'products' => 0, 'with_description' => 0, 'with_image' => 0,
            'with_manufacturer_norms' => 0, 'with_documents' => 0,
        ], $r->json('sold'));
        $this->assertSame([], $r->json('gaps_sold'));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(string $sku, string $manufacturer, array $attributes): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Karta '.$sku,
            'manufacturer' => $manufacturer,
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 0,
            ...$attributes,
        ]);
    }

    private function image(int $productId): void
    {
        DB::table('product_images')->insert([
            'product_id' => $productId, 'path' => 'img/'.$productId.'.jpg', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function document(int $productId, string $kind, string $checksum): void
    {
        DB::table('product_documents')->insert([
            'product_id' => $productId, 'path' => 'doc/'.$checksum.'.pdf', 'kind' => $kind, 'checksum' => $checksum,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function erpItem(string $lastSale, bool $removed = false): int
    {
        $gid = $this->gid++;

        return (int) DB::table('erp_items')->insertGetId([
            'xl_gid' => $gid,
            'code' => 'T'.$gid,
            'name' => 'TOWAR '.$gid,
            'last_sale_at' => $lastSale,
            'removed_at' => $removed ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function link(int $erpItemId, int $productId, string $status): void
    {
        DB::table('erp_item_links')->insert([
            'erp_item_id' => $erpItemId,
            'product_id' => $productId,
            'status' => $status,
            'method' => ErpItemLink::METHOD_NAME,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
