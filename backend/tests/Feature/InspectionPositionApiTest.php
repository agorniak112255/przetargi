<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InspectionPosition;
use App\Models\User;
use App\Services\Inspections\InspectionDueBuilder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Przeglądy — lista pozycji (usługi i towary XL z interwałem), katalog XL do dodawania, zapis i przebudowa terminów. */
final class InspectionPositionApiTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<list<int>|null> wywołania przebudowy (atrapa) */
    private array $rebuilds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(10, 0));
    }

    public function test_routes_need_manage_permission(): void
    {
        $position = InspectionPosition::query()->create(['xl_gid' => 1, 'xl_type' => 4, 'code' => 'U', 'name' => 'PRZEGLĄD', 'interval_months' => 12]);
        Sanctum::actingAs($this->user(['inspections.view', 'inspections.offer']));
        $this->getJson('/api/inspection-positions')->assertForbidden();
        $this->getJson('/api/inspection-positions/catalog')->assertForbidden();
        $this->getJson('/api/inspection-positions/suggestions')->assertForbidden();
        $this->postJson('/api/inspection-positions', [])->assertForbidden();
        $this->patchJson('/api/inspection-positions/'.$position->id, [])->assertForbidden();
        $this->deleteJson('/api/inspection-positions/'.$position->id)->assertForbidden();
        $this->postJson('/api/inspection-positions/suggestions/accept', [])->assertForbidden();
        $this->postJson('/api/inspection-positions/suggestions/reject', [])->assertForbidden();
    }

    public function test_index_lists_positions_with_renewal_pattern_and_due_counts(): void
    {
        $this->service(4737, 'UPRGP6', 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6');
        $author = User::factory()->create(['name' => 'Jan Nowak']);
        $service = InspectionPosition::query()->create(['xl_gid' => 4737, 'xl_type' => 4, 'code' => 'UPRGP6', 'name' => 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', 'unit' => 'szt', 'interval_months' => 12, 'created_by' => $author->id, 'updated_by' => $author->id]);
        $goods = InspectionPosition::query()->create(['xl_gid' => 100, 'xl_type' => 1, 'code' => 'GP6X', 'name' => 'GAŚNICA PROSZ.GP-6X ABC', 'unit' => 'szt', 'interval_months' => 12, 'renewed_by_xl_gid' => 4737, 'source' => 'suggestion', 'pattern_position_id' => $service->id, 'history_loaded_at' => now()]);
        // usługa odnawiająca, której nie ma już w kopii katalogu
        InspectionPosition::query()->create(['xl_gid' => 101, 'xl_type' => 1, 'code' => 'X', 'name' => 'ZZ TOWAR', 'interval_months' => 6, 'renewed_by_xl_gid' => 999]);
        $this->due(1, $service, '2026-09-01');
        $this->due(2, $service, '2026-12-01');
        $this->due(3, $goods, '2026-10-06');

        Sanctum::actingAs($this->user(['inspections.manage']));
        $res = $this->getJson('/api/inspection-positions')->assertOk();
        $this->assertSame([1, 3, 6, 9, 12, 15, 18, 24], $res->json('meta.intervals'));
        $this->assertSame(['GAŚNICA PROSZ.GP-6X ABC', 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', 'ZZ TOWAR'], array_column($res->json('data'), 'name'));
        $g = $res->json('data.0');
        $this->assertSame(['xl_gid' => 4737, 'code' => 'UPRGP6', 'name' => 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6'], $g['renewed_by']);
        $this->assertSame(['id' => $service->id, 'name' => 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6'], $g['pattern']);
        $this->assertSame('suggestion', $g['source']);
        $this->assertTrue($g['history_loaded']);
        $this->assertSame(1, $g['customers_due']);
        // termin dziś nie jest zaległy
        $this->assertSame(0, $g['customers_overdue']);
        $s = $res->json('data.1');
        $this->assertSame(2, $s['customers_due']);
        $this->assertSame(1, $s['customers_overdue']);
        $this->assertSame('Jan Nowak', $s['created_by_name']);
        $this->assertNull($s['renewed_by']);
        $this->assertFalse($s['history_loaded']);
        $this->assertSame(['xl_gid' => 999, 'code' => null, 'name' => null], $res->json('data.2.renewed_by'));
    }

    public function test_catalog_searches_active_services_and_goods_with_24_month_counts(): void
    {
        $this->service(4737, 'UPRGP6', 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6');
        $this->service(4738, 'UPRGP2', 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-2');
        $this->service(4739, 'UPRHYD', 'PRZEGLĄD HYDRANTU');
        $this->service(4740, 'UPROLD', 'PRZEGLĄD GAŚNICY STARY', archived: true);
        $this->service(4741, 'UPRDEL', 'PRZEGLĄD GAŚNICY USUNIĘTY', removed: true);
        $itemId = $this->item(100, 'GP6X', 'GAŚNICA PROSZKOWA GP-6X');
        $this->item(101, 'GP6Y', 'GAŚNICA PROSZKOWA GP-6 ARCH', archived: true);
        $position = InspectionPosition::query()->create(['xl_gid' => 4737, 'xl_type' => 4, 'code' => 'UPRGP6', 'name' => 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', 'interval_months' => 12]);
        // usługa: 2 klientów w 24 miesiącach (korekta ze znakiem), sprzedaż sprzed okna się nie liczy
        $this->saleLine(1, 4737, '2025-05-01', 10, 1);
        $this->saleLine(1, 4737, '2025-05-10', -2, 2, 2041);
        $this->saleLine(2, 4737, '2026-01-01', 5, 3);
        $this->saleLine(3, 4737, '2024-10-05', 7, 4);
        // towar: z erp_customer_items (24 miesiące)
        $c1 = $this->customer(10);
        $c2 = $this->customer(11);
        DB::table('erp_customer_items')->insert([
            ['erp_customer_id' => $c1, 'erp_item_id' => $itemId, 'last_sale_at' => '2026-03-01', 'documents' => 2, 'quantity' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['erp_customer_id' => $c2, 'erp_item_id' => $itemId, 'last_sale_at' => '2024-01-01', 'documents' => 1, 'quantity' => 9, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Sanctum::actingAs($this->user(['inspections.manage']));
        $res = $this->getJson('/api/inspection-positions/catalog?q=GAŚNIC%20GP-6')->assertOk();
        $this->assertSame([4737, 100], array_column($res->json('data'), 'xl_gid'));
        // liczby z JSON-a (13.0 przychodzi jako 13) — porównanie wartości
        $this->assertEquals([
            'xl_gid' => 4737, 'xl_type' => 4, 'code' => 'UPRGP6', 'name' => 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', 'unit' => 'szt',
            'position_id' => $position->id, 'customers_24m' => 2, 'quantity_24m' => 13.0,
        ], $res->json('data.0'));
        $this->assertEquals(['position_id' => null, 'customers_24m' => 1, 'quantity_24m' => 4.0, 'xl_type' => 1], [
            'position_id' => $res->json('data.1.position_id'), 'customers_24m' => $res->json('data.1.customers_24m'),
            'quantity_24m' => $res->json('data.1.quantity_24m'), 'xl_type' => $res->json('data.1.xl_type'),
        ]);

        $this->assertSame([4738, 4737], array_column($this->getJson('/api/inspection-positions/catalog?q=GAŚNIC&type=service')->json('data'), 'xl_gid'));
        $this->assertSame([100], array_column($this->getJson('/api/inspection-positions/catalog?q=GAŚNIC&type=goods')->json('data'), 'xl_gid'));
        $this->assertSame([4739], array_column($this->getJson('/api/inspection-positions/catalog?q=hydrant')->json('data'), 'xl_gid'));
        $this->getJson('/api/inspection-positions/catalog?type=bad')->assertUnprocessable();
    }

    public function test_store_creates_positions_and_rebuilds_their_terms(): void
    {
        $this->fakeBuilder();
        $this->service(4737, 'UPRGP6', 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6');
        $this->item(100, 'GP6X', 'GAŚNICA PROSZ.GP-6X ABC');
        Sanctum::actingAs($user = $this->user(['inspections.manage'], 'Ewa Lis'));

        $res = $this->postJson('/api/inspection-positions', ['items' => [
            ['xl_gid' => 4737, 'interval_months' => 12],
            ['xl_gid' => 100, 'interval_months' => 12, 'renewed_by_xl_gid' => 4737, 'note' => '  przegląd co rok  '],
        ]])->assertCreated();

        $this->assertSame([4, 1], array_column($res->json('data'), 'xl_type'));
        $this->assertSame('UPRGP6', $res->json('data.0.code'));
        $this->assertSame('PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', $res->json('data.0.name'));
        $this->assertSame('manual', $res->json('data.0.source'));
        $this->assertSame('Ewa Lis', $res->json('data.0.created_by_name'));
        $this->assertSame(['xl_gid' => 4737, 'code' => 'UPRGP6', 'name' => 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6'], $res->json('data.1.renewed_by'));
        $this->assertSame('przegląd co rok', $res->json('data.1.note'));
        $ids = array_column($res->json('data'), 'id');
        $this->assertSame([$ids], $this->rebuilds);
        $this->assertSame($user->id, InspectionPosition::query()->find($ids[0])?->created_by);
    }

    public function test_store_validation(): void
    {
        $this->fakeBuilder();
        $this->service(4737, 'UPRGP6', 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6');
        $this->service(4800, 'UPRDEL', 'PRZEGLĄD USUNIĘTY', removed: true);
        $this->item(100, 'GP6X', 'GAŚNICA PROSZ.GP-6X ABC');
        $this->item(101, 'GP2X', 'GAŚNICA PROSZ.GP-2X ABC');
        InspectionPosition::query()->create(['xl_gid' => 101, 'xl_type' => 1, 'code' => 'GP2X', 'name' => 'GAŚNICA PROSZ.GP-2X ABC', 'interval_months' => 12]);
        Sanctum::actingAs($this->user(['inspections.manage']));

        $this->postJson('/api/inspection-positions', ['items' => [['xl_gid' => 4737, 'interval_months' => 5]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.interval_months' => 'Wybierz interwał z listy: 1, 3, 6, 9, 12, 15, 18, 24 miesięcy.']);
        $this->postJson('/api/inspection-positions', ['items' => [['xl_gid' => 101, 'interval_months' => 12]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.xl_gid' => 'Pozycja „GAŚNICA PROSZ.GP-2X ABC” jest już na liście przeglądów.']);
        $this->postJson('/api/inspection-positions', ['items' => [['xl_gid' => 4737, 'interval_months' => 12], ['xl_gid' => 4737, 'interval_months' => 6]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.xl_gid' => 'Ta sama pozycja jest zaznaczona dwa razy.']);
        $this->postJson('/api/inspection-positions', ['items' => [['xl_gid' => 4800, 'interval_months' => 12]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.xl_gid' => 'Tej pozycji nie ma w katalogu ERP XL (albo usunięto ją z XL) — odśwież wyszukiwanie.']);
        // usługa odnawiająca: tylko dla towaru i tylko usługa
        $this->postJson('/api/inspection-positions', ['items' => [['xl_gid' => 4737, 'interval_months' => 12, 'renewed_by_xl_gid' => 4737]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.renewed_by_xl_gid' => 'Usługę odnawiającą można wskazać tylko dla towaru (dla usługi termin liczy się od jej sprzedaży).']);
        $this->postJson('/api/inspection-positions', ['items' => [['xl_gid' => 100, 'interval_months' => 12, 'renewed_by_xl_gid' => 101]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.renewed_by_xl_gid' => 'Usługa odnawiająca musi być usługą z katalogu ERP XL.']);
        $this->postJson('/api/inspection-positions', ['items' => []])
            ->assertUnprocessable()
            ->assertJsonPath('errors.items.0', 'Zaznacz co najmniej jedną pozycję.');

        $this->assertSame(1, InspectionPosition::query()->count());
        $this->assertSame([], $this->rebuilds);
    }

    public function test_update_and_destroy(): void
    {
        $this->fakeBuilder();
        $this->service(4737, 'UPRGP6', 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6');
        $service = InspectionPosition::query()->create(['xl_gid' => 4737, 'xl_type' => 4, 'code' => 'UPRGP6', 'name' => 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', 'interval_months' => 12]);
        $goods = InspectionPosition::query()->create(['xl_gid' => 100, 'xl_type' => 1, 'code' => 'GP6X', 'name' => 'GAŚNICA', 'interval_months' => 12]);
        Sanctum::actingAs($user = $this->user(['inspections.manage'], 'Ewa Lis'));

        $this->patchJson('/api/inspection-positions/'.$goods->id, ['interval_months' => 24, 'renewed_by_xl_gid' => 4737, 'note' => 'x', 'active' => false])
            ->assertOk()
            ->assertJsonPath('data.interval_months', 24)
            ->assertJsonPath('data.renewed_by.code', 'UPRGP6')
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.updated_by_name', 'Ewa Lis');
        $this->assertSame([[$goods->id]], $this->rebuilds);
        $this->patchJson('/api/inspection-positions/'.$goods->id, ['renewed_by_xl_gid' => null])->assertOk()->assertJsonPath('data.renewed_by', null);

        $this->patchJson('/api/inspection-positions/'.$goods->id, ['interval_months' => 2])
            ->assertUnprocessable()
            ->assertJsonPath('errors.interval_months.0', 'Wybierz interwał z listy: 1, 3, 6, 9, 12, 15, 18, 24 miesięcy.');
        $this->patchJson('/api/inspection-positions/'.$service->id, ['renewed_by_xl_gid' => 4737])
            ->assertUnprocessable()
            ->assertJsonPath('errors.renewed_by_xl_gid.0', 'Usługę odnawiającą można wskazać tylko dla towaru (dla usługi termin liczy się od jej sprzedaży).');
        $this->patchJson('/api/inspection-positions/999', ['active' => true])->assertNotFound();

        $this->due(1, $service, '2026-10-10');
        $this->deleteJson('/api/inspection-positions/'.$service->id)->assertNoContent();
        $this->assertNull(InspectionPosition::query()->find($service->id));
        $this->assertSame(0, DB::table('inspection_due')->count());
    }

    public function test_store_with_real_builder_computes_terms_from_invoices(): void
    {
        $this->service(4737, 'UPRGP6', 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6');
        $this->saleLine(55, 4737, '2026-01-10', 10, 1);
        Sanctum::actingAs($this->user(['inspections.manage', 'inspections.view']));

        $this->postJson('/api/inspection-positions', ['items' => [['xl_gid' => 4737, 'interval_months' => 12]]])
            ->assertCreated()
            ->assertJsonPath('data.0.customers_due', 1);
        $this->getJson('/api/inspections?days=730')->assertOk()
            ->assertJsonPath('data.0.customer.xl_gid', 55)
            ->assertJsonPath('data.0.positions.0.due_on', '2027-01-10');
    }

    private function fakeBuilder(): void
    {
        $test = $this;
        // atrapa przebudowy — zapisuje, o które pozycje poproszono
        app()->instance(InspectionDueBuilder::class, new class($test)
        {
            public function __construct(private readonly InspectionPositionApiTest $test) {}

            /** @param  list<int>|null  $ids */
            public function rebuild(?array $ids = null): int
            {
                $this->test->recordRebuild($ids);

                return 0;
            }
        });
    }

    /** @param  list<int>|null  $ids */
    public function recordRebuild(?array $ids): void
    {
        $this->rebuilds[] = $ids;
    }

    /** @param  list<string>  $permissions */
    private function user(array $permissions, string $name = 'Tester'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function service(int $gid, string $code, string $name, bool $archived = false, bool $removed = false): void
    {
        DB::table('erp_services')->insert([
            'xl_gid' => $gid, 'xl_type' => 4, 'code' => $code, 'name' => $name, 'unit' => 'szt', 'archived' => $archived,
            'removed_at' => $removed ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function item(int $gid, string $code, string $name, bool $archived = false): int
    {
        return (int) DB::table('erp_items')->insertGetId([
            'xl_gid' => $gid, 'code' => $code, 'name' => $name, 'unit' => 'szt', 'archived' => $archived,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function customer(int $gid): int
    {
        return (int) DB::table('erp_customers')->insertGetId([
            'xl_gid' => $gid, 'acronym' => 'K'.$gid, 'archived' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function due(int $customer, InspectionPosition $position, string $dueOn): void
    {
        DB::table('inspection_due')->insert([
            'customer_xl_gid' => $customer, 'inspection_position_id' => $position->id, 'due_on' => $dueOn, 'open_count' => 1,
            'open_quantity' => 1, 'last_on' => '2025-10-01', 'last_quantity' => 1, 'last_net' => 10, 'last_documents' => '[]',
            'first_on' => '2025-10-01', 'same_nip_newer' => false, 'computed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function saleLine(int $customer, int $itemGid, string $issued, float $quantity, int $documentId, int $type = 2033): void
    {
        DB::table('inspection_sale_lines')->insert([
            'document_type' => $type, 'document_id' => $documentId, 'line' => 1, 'document_number' => 'FS-'.$documentId,
            'issued_on' => $issued, 'sold_on' => $issued, 'customer_xl_gid' => $customer, 'xl_item_gid' => $itemGid,
            'xl_item_type' => 4, 'quantity' => $quantity, 'net_value' => $quantity * 9, 'synced_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
