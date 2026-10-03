<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\SystemAlert;
use App\Models\Tender;
use App\Models\TenderLot;
use App\Models\User;
use App\Support\PermissionCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Krok 0 etapów 0–1 (03.10.2026): nowe trasy istnieją i pilnują uprawnień. Kontrolery są na razie zaślepkami
 * (501), więc „przepuszczony” = 501, a nie 401/403/404. Strumienie A–D zastępują zaślepki właściwymi testami.
 */
final class NewFeaturesRoutesSmokeTest extends TestCase
{
    use RefreshDatabase;

    private Tender $tender;

    private TenderLot $lot;

    private SystemAlert $alert;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $owner = User::factory()->withRole('handlowiec')->create();
        $this->tender = Tender::query()->create([
            'number' => 'PRZ/2026/9100',
            'title' => 'Rękawice robocze',
            'client_id' => Client::query()->create(['name' => 'Szpital'])->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
            'ai_percent' => 0,
        ]);
        $this->lot = TenderLot::query()->create(['tender_id' => $this->tender->id, 'lot_no' => 1]);
        $this->alert = SystemAlert::query()->create([
            'kind' => 'task',
            'subject_key' => 'task:erp:sync --match',
            'title' => 'Towary i stany z ERP XL',
            'first_failed_at' => now(),
            'last_failed_at' => now(),
            'failures' => 1,
        ]);
    }

    /**
     * @return list<array{string, string}>
     */
    private function routes(): array
    {
        $t = $this->tender->id;

        return [
            ['GET', '/api/me/notification-preferences'],
            ['PUT', '/api/me/notification-preferences'],
            ['GET', '/api/reports/effectiveness?period=90d'],
            ['GET', '/api/reports/effectiveness/csv?period=90d'],
            ['GET', '/api/competitors?q=abc'],
            ['GET', "/api/tenders/{$t}/result"],
            ['PUT', "/api/tenders/{$t}/result"],
            ['DELETE', "/api/tenders/{$t}/result/lots/{$this->lot->id}"],
            ['POST', "/api/tenders/{$t}/result/bzp-check"],
            ['GET', "/api/tenders/{$t}/mention-candidates"],
            ['GET', '/api/admin/system-status'],
            ['GET', '/api/admin/system-status/gaps/tenders_without_time'],
            ['POST', "/api/admin/system-alerts/{$this->alert->id}/mute"],
            ['POST', "/api/admin/system-alerts/{$this->alert->id}/unmute"],
        ];
    }

    public function test_new_routes_require_login(): void
    {
        foreach ($this->routes() as [$method, $uri]) {
            $this->json($method, $uri)->assertStatus(401);
        }
    }

    public function test_user_without_permissions_is_refused_everywhere_except_own_account(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach ($this->routes() as [$method, $uri]) {
            $expected = str_starts_with($uri, '/api/me/') ? 501 : 403;
            $this->json($method, $uri)->assertStatus($expected);
        }
    }

    public function test_admin_reaches_every_new_route(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        foreach ($this->routes() as [$method, $uri]) {
            $this->json($method, $uri)
                ->assertStatus(501)
                ->assertJsonPath('message', 'Jeszcze niegotowe');
        }
    }

    public function test_tender_result_follows_tender_access_and_offer_editing(): void
    {
        $t = $this->tender->id;

        // handlowiec bez dostępu do cudzego przetargu
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson("/api/tenders/{$t}/result")->assertForbidden();
        $this->putJson("/api/tenders/{$t}/result")->assertForbidden();

        // opiekun przetargu widzi i edytuje
        Sanctum::actingAs($this->tender->owner()->firstOrFail());
        $this->getJson("/api/tenders/{$t}/result")->assertStatus(501);
        $this->putJson("/api/tenders/{$t}/result")->assertStatus(501);
        $this->getJson("/api/tenders/{$t}/mention-candidates")->assertStatus(501);

        // dyrektor widzi wszystkie przetargi, ale nie edytuje oferty
        Sanctum::actingAs(User::factory()->withRole('dyrektor')->create());
        $this->getJson("/api/tenders/{$t}/result")->assertStatus(501);
        $this->putJson("/api/tenders/{$t}/result")->assertForbidden();
        $this->deleteJson("/api/tenders/{$t}/result/lots/{$this->lot->id}")->assertForbidden();
        $this->postJson("/api/tenders/{$t}/result/bzp-check")->assertForbidden();
    }

    public function test_lot_of_another_tender_is_not_found(): void
    {
        $other = Tender::query()->create([
            'number' => 'PRZ/2026/9101',
            'title' => 'Obuwie',
            'client_id' => $this->tender->client_id,
            'owner_id' => $this->tender->owner_id,
            'status' => 'wycena',
            'ai_percent' => 0,
        ]);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->deleteJson("/api/tenders/{$other->id}/result/lots/{$this->lot->id}")->assertNotFound();
    }

    public function test_effectiveness_report_needs_tender_data_permission(): void
    {
        $reportsOnly = User::factory()->create();
        $reportsOnly->givePermissionTo('reports.view');
        Sanctum::actingAs($reportsOnly);
        $this->getJson('/api/reports/effectiveness')->assertForbidden();
        $this->getJson('/api/reports/effectiveness/csv')->assertForbidden();

        $reportsOnly->givePermissionTo('tenders.view_own');
        Sanctum::actingAs($reportsOnly->fresh());
        $this->getJson('/api/reports/effectiveness')->assertStatus(501);
    }

    public function test_system_status_needs_its_own_permission(): void
    {
        $adminAccessOnly = User::factory()->create();
        $adminAccessOnly->givePermissionTo('admin.access');
        Sanctum::actingAs($adminAccessOnly);
        $this->getJson('/api/admin/system-status')->assertForbidden();

        $adminAccessOnly->givePermissionTo('admin.system.view');
        Sanctum::actingAs($adminAccessOnly->fresh());
        $this->getJson('/api/admin/system-status')->assertStatus(501);
        $this->getJson('/api/admin/system-status/gaps/nieznany_rodzaj')->assertNotFound();
        $this->postJson('/api/admin/system-alerts/999999/mute')->assertNotFound();
    }

    public function test_system_view_permission_is_in_catalog_and_created_by_migration(): void
    {
        $this->assertContains('admin.system.view', PermissionCatalog::ALL);
        $definition = PermissionCatalog::definitions()['admin.system.view'] ?? null;
        $this->assertNotNull($definition);
        $this->assertSame('Administracja', $definition['group']);
        $this->assertSame('Stan systemu', $definition['label']);
        $this->assertTrue(Permission::query()->where('name', 'admin.system.view')->where('guard_name', 'web')->exists());
        $this->assertTrue(User::factory()->withRole('admin')->create()->can('admin.system.view'));
        $this->assertFalse(User::factory()->withRole('kierownik')->create()->can('admin.system.view'));
    }

    public function test_every_catalog_permission_has_a_definition_for_the_roles_editor(): void
    {
        // panel „Role” pokazuje tylko uprawnienia z definicją — nowe uprawnienie bez opisu nie dałoby się nadać
        $this->assertSame([], array_values(array_diff(PermissionCatalog::ALL, array_keys(PermissionCatalog::definitions()))));
    }
}
