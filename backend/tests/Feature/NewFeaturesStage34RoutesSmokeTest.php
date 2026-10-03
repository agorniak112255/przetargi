<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\ClientNote;
use App\Models\User;
use App\Support\PermissionCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Etapy 3–4 (03.10.2026): nowe trasy istnieją i pilnują uprawnień. „Przepuszczony” = odpowiedział kontroler: 2xx,
 * 422 przy pustym żądaniu albo 501 zaślepki kroku 0 — nie 401/403/404/405 ani błąd serwera. Zachowanie tras
 * sprawdzają testy strumieni (ten test zostaje bez zmian, gdy zaślepki zastąpi prawdziwy kod).
 */
final class NewFeaturesStage34RoutesSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    private ClientNote $note;

    private ClientInquiry $inquiry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->withRole('admin')->create();
        $this->client = Client::query()->create(['name' => 'Szpital']);
        $this->note = ClientNote::query()->create(['client_id' => $this->client->id, 'user_id' => $this->admin->id, 'body' => 'Zadzwonić w piątek']);
        // zapytanie administratora — trasy wyniku i powiązania są tylko dla autora
        $this->inquiry = ClientInquiry::query()->create([
            'user_id' => $this->admin->id,
            'tone' => 'formal',
            'source_subject' => 'Rękawice',
            'source_body' => 'Proszę o ofertę.',
        ]);
    }

    /**
     * Metoda, adres i czy przechodzi u zalogowanego bez żadnych uprawnień (true = tak, false = 403).
     *
     * @return list<array{string, string, bool}>
     */
    private function routes(): array
    {
        $c = $this->client->id;
        $i = $this->inquiry->id;

        return [
            ['GET', '/api/tenders/calendar?from=2026-10-01&to=2026-10-31', false],
            ['GET', '/api/me/calendar-feed', false],
            ['POST', '/api/me/calendar-feed', false],
            ['DELETE', '/api/me/calendar-feed', false],
            // każda grupa wyników sprawdza własne uprawnienie — bez uprawnień pusta odpowiedź, nie odmowa
            ['GET', '/api/search?q=rękawice', true],
            ['GET', "/api/clients/{$c}", false],
            ['GET', "/api/clients/{$c}/timeline?type=all", false],
            ['POST', "/api/clients/{$c}/notes", false],
            ['PATCH', "/api/clients/{$c}/notes/{$this->note->id}", false],
            ['PUT', "/api/inquiries/{$i}/outcome", false],
            ['PUT', "/api/inquiries/{$i}/client", false],
            ['GET', '/api/me/sales-target', true],
            ['GET', '/api/reports/targets?month=2026-10', false],
            ['PUT', '/api/reports/targets/2026-10', false],
            ['GET', '/api/admin/erp-employees', false],
            // usunięcie na końcu — w teście administratora kasuje notatkę
            ['DELETE', "/api/clients/{$c}/notes/{$this->note->id}", false],
        ];
    }

    private function assertPassed(TestResponse $response, string $method, string $uri): void
    {
        $status = $response->getStatusCode();
        $this->assertTrue(
            ($status >= 200 && $status < 300) || $status === 422 || $status === 501,
            "{$method} {$uri}: oczekiwano odpowiedzi kontrolera, jest {$status}: ".mb_substr((string) $response->getContent(), 0, 300),
        );
    }

    public function test_new_routes_require_login(): void
    {
        foreach ($this->routes() as [$method, $uri]) {
            $this->json($method, $uri)->assertStatus(401);
        }
    }

    public function test_user_without_permissions_is_refused_except_search_and_own_target(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach ($this->routes() as [$method, $uri, $open]) {
            $response = $this->json($method, $uri);
            $open ? $this->assertPassed($response, $method, $uri) : $response->assertStatus(403);
        }
    }

    public function test_admin_reaches_every_new_route(): void
    {
        Sanctum::actingAs($this->admin);

        foreach ($this->routes() as [$method, $uri]) {
            $this->assertPassed($this->json($method, $uri), $method, $uri);
        }
    }

    public function test_targets_need_both_reports_view_and_targets_permission(): void
    {
        // kierownik ma raporty, ale nie cele
        Sanctum::actingAs(User::factory()->withRole('kierownik')->create());
        $this->getJson('/api/reports/targets')->assertForbidden();
        $this->putJson('/api/reports/targets/2026-10')->assertForbidden();

        $targetsOnly = User::factory()->create();
        $targetsOnly->givePermissionTo('reports.targets.manage');
        Sanctum::actingAs($targetsOnly);
        $this->getJson('/api/reports/targets')->assertForbidden();

        $targetsOnly->givePermissionTo('reports.view');
        Sanctum::actingAs($targetsOnly->fresh());
        $this->assertPassed($this->getJson('/api/reports/targets'), 'GET', 'targets');
        // miesiąc w adresie tylko jako RRRR-MM
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/reports/targets/2026-1')->assertNotFound();
    }

    public function test_salesperson_reaches_client_card_calendar_and_own_target(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->assertPassed($this->getJson("/api/clients/{$this->client->id}"), 'GET', 'client card');
        $this->assertPassed($this->getJson('/api/tenders/calendar?from=2026-10-01&to=2026-10-31'), 'GET', 'calendar');
        $this->assertPassed($this->getJson('/api/me/sales-target'), 'GET', 'sales-target');
        $this->getJson('/api/reports/targets')->assertForbidden();
        $this->getJson('/api/admin/erp-employees')->assertForbidden();
    }

    public function test_note_of_another_client_and_unknown_client_are_not_found(): void
    {
        $other = Client::query()->create(['name' => 'Inny']);
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/clients/{$other->id}/notes/{$this->note->id}")->assertNotFound();
        $this->deleteJson("/api/clients/{$other->id}/notes/{$this->note->id}")->assertNotFound();
        $this->getJson('/api/clients/999999')->assertNotFound();
        $this->getJson('/api/clients/999999/timeline')->assertNotFound();
    }

    public function test_public_calendar_file_is_outside_login_and_activity_log_with_throttle(): void
    {
        // bez logowania: odpowiada kontroler (nieznany klucz → 404 po wdrożeniu, 501 w zaślepce), nie 401
        $status = $this->get('/api/calendar/'.str_repeat('a', 40).'.ics')->getStatusCode();
        $this->assertContains($status, [404, 501]);
        // klucz innej długości albo ze znakami spoza A-Z a-z 0-9 — trasy nie ma
        $this->get('/api/calendar/'.str_repeat('a', 39).'.ics')->assertNotFound();
        $this->get('/api/calendar/'.str_repeat('a', 39).'-.ics')->assertNotFound();

        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(static fn (Route $r): bool => $r->uri() === 'api/calendar/{token}.ics');
        $this->assertNotNull($route);
        $middleware = $route->gatherMiddleware();
        $this->assertContains('throttle:60,1', $middleware);
        $this->assertNotContains('auth:sanctum', $middleware);
        $this->assertNotContains('log.activity', $middleware);
    }

    public function test_calendar_route_is_not_taken_for_a_tender_number(): void
    {
        $route = app('router')->getRoutes()->match(request()->create('/api/tenders/calendar', 'GET'));

        $this->assertSame('api/tenders/calendar', $route->uri());
    }

    public function test_targets_permission_is_in_catalog_and_created_by_migration(): void
    {
        $this->assertContains('reports.targets.manage', PermissionCatalog::ALL);
        $definition = PermissionCatalog::definitions()['reports.targets.manage'] ?? null;
        $this->assertNotNull($definition);
        $this->assertSame(['Cele handlowców', 'Pulpit'], [$definition['label'], $definition['group']]);
        $this->assertTrue(Permission::query()->where('name', 'reports.targets.manage')->where('guard_name', 'web')->exists());
        $this->assertTrue($this->admin->can('reports.targets.manage'));
        $this->assertFalse(User::factory()->withRole('kierownik')->create()->can('reports.targets.manage'));
        $this->assertFalse(User::factory()->withRole('dyrektor')->create()->can('reports.targets.manage'));
    }
}
