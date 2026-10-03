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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Etapy 0–1 (03.10.2026): nowe trasy istnieją i pilnują uprawnień. „Przepuszczony” = kontroler odpowiedział sam
 * (2xx albo 422 przy pustym żądaniu), a nie 401/403/404/405 ani błąd serwera. Zachowanie tras sprawdzają testy strumieni.
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

    /**
     * Uprawnienia przepuściły żądanie do kontrolera: odpowiedź własna (2xx albo 422 przy pustym żądaniu),
     * nie odmowa, nie brak trasy i nie błąd serwera.
     */
    private function assertPassed(TestResponse $response, string $method, string $uri): void
    {
        $status = $response->getStatusCode();
        $this->assertTrue(
            ($status >= 200 && $status < 300) || $status === 422,
            "{$method} {$uri}: oczekiwano odpowiedzi kontrolera, jest {$status}: ".mb_substr((string) $response->getContent(), 0, 300),
        );
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
            $response = $this->json($method, $uri);
            str_starts_with($uri, '/api/me/') ? $this->assertPassed($response, $method, $uri) : $response->assertStatus(403);
        }
    }

    public function test_admin_reaches_every_new_route(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        foreach ($this->routes() as [$method, $uri]) {
            $this->assertPassed($this->json($method, $uri), $method, $uri);
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
        $this->assertPassed($this->getJson("/api/tenders/{$t}/result"), 'GET', 'result');
        $this->assertPassed($this->putJson("/api/tenders/{$t}/result"), 'PUT', 'result');
        $this->assertPassed($this->getJson("/api/tenders/{$t}/mention-candidates"), 'GET', 'mention-candidates');

        // dyrektor widzi wszystkie przetargi, ale nie edytuje oferty
        Sanctum::actingAs(User::factory()->withRole('dyrektor')->create());
        $this->assertPassed($this->getJson("/api/tenders/{$t}/result"), 'GET', 'result');
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
        $this->assertPassed($this->getJson('/api/reports/effectiveness'), 'GET', 'effectiveness');
    }

    public function test_system_status_needs_its_own_permission(): void
    {
        $adminAccessOnly = User::factory()->create();
        $adminAccessOnly->givePermissionTo('admin.access');
        Sanctum::actingAs($adminAccessOnly);
        $this->getJson('/api/admin/system-status')->assertForbidden();

        $adminAccessOnly->givePermissionTo('admin.system.view');
        Sanctum::actingAs($adminAccessOnly->fresh());
        $this->assertPassed($this->getJson('/api/admin/system-status'), 'GET', 'system-status');
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
