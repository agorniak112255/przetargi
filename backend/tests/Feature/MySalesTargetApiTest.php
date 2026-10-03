<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ErpSaleDocument;
use App\Models\SalesTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kafelek „Mój cel” na Dashboardzie (GET /api/me/sales-target): każdy widzi tylko swój cel bieżącego miesiąca.
 * Teraz = sobota 3.10.2026 w Polsce (22 dni robocze, minęły 2).
 */
final class MySalesTargetApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-03 09:14:00', 'Europe/Warsaw'));
    }

    public function test_without_target_returns_null_target(): void
    {
        $seller = User::factory()->withRole('handlowiec')->create();
        SalesTarget::query()->create(['user_id' => $seller->id, 'year' => 2026, 'month' => 9, 'amount' => '1000.00']);
        Sanctum::actingAs($seller);

        $this->getJson('/api/me/sales-target')->assertOk()->assertExactJson([
            'month' => '2026-10',
            'target' => null,
            'sales' => '0.00',
            'percent' => null,
            'workdays' => ['total' => 22, 'elapsed' => 2],
            'clients_bought' => 0,
            'new_clients' => 0,
        ]);
    }

    public function test_own_target_and_sales_of_own_clients_only(): void
    {
        $anna = User::factory()->withRole('handlowiec')->create();
        $anna->forceFill(['erp_employee_gid' => 501])->save();
        $piotr = User::factory()->withRole('handlowiec')->create();
        SalesTarget::query()->create(['user_id' => $anna->id, 'year' => 2026, 'month' => 10, 'amount' => '2000.00']);
        SalesTarget::query()->create(['user_id' => $piotr->id, 'year' => 2026, 'month' => 10, 'amount' => '9000.00']);

        $mine = Client::query()->create(['name' => 'Mój', 'xl_manager_gid' => 501, 'owner_id' => $piotr->id]);
        $mine->forceFill(['xl_gid' => 1])->save();
        $old = Client::query()->create(['name' => 'Stały', 'owner_id' => $anna->id]);
        $old->forceFill(['xl_gid' => 2])->save();
        $theirs = Client::query()->create(['name' => 'Piotra', 'owner_id' => $piotr->id]);
        $theirs->forceFill(['xl_gid' => 3])->save();

        $this->doc(1, $mine, 2033, '2026-10-01', '500.00');
        $this->doc(2, $mine, 2041, '2026-10-02', '-100.00');
        $this->doc(3, $old, 2034, '2026-10-02', '100.00');
        $this->doc(4, $old, 2033, '2026-01-15', '100.00');
        $this->doc(5, $theirs, 2033, '2026-10-02', '7000.00');

        Sanctum::actingAs($anna);
        $this->getJson('/api/me/sales-target')->assertOk()->assertExactJson([
            'month' => '2026-10',
            'target' => '2000.00',
            'sales' => '500.00',
            'percent' => 25,
            'workdays' => ['total' => 22, 'elapsed' => 2],
            'clients_bought' => 2,
            'new_clients' => 1,
        ]);
    }

    private function doc(int $id, Client $client, int $type, string $date, string $net): void
    {
        ErpSaleDocument::query()->create([
            'document_type' => $type,
            'document_id' => $id,
            'document_number' => 'D-'.$id,
            'kind' => ErpSaleDocument::TYPE_KIND[$type],
            'issued_at' => $date,
            'customer_xl_gid' => (int) $client->xl_gid,
            'client_id' => $client->id,
            'net_value' => $net,
            'synced_at' => '2026-10-03 03:41:00',
        ]);
    }
}
