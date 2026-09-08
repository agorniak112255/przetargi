<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Support\PermissionCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenderDeleteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_catalog_assigns_delete_permissions_to_roles(): void
    {
        $roles = PermissionCatalog::rolePermissions();

        $this->assertContains('tenders.delete_items', $roles['admin']);
        $this->assertContains('tenders.delete', $roles['admin']);
        $this->assertContains('tenders.delete_items', $roles['dyrektor']);
        $this->assertNotContains('tenders.delete', $roles['dyrektor']);
        $this->assertNotContains('tenders.delete_items', $roles['handlowiec']);
        $this->assertNotContains('tenders.delete', $roles['kierownik']);
    }

    public function test_admin_and_dyrektor_can_delete_item(): void
    {
        foreach (['admin', 'dyrektor'] as $role) {
            Sanctum::actingAs(User::factory()->withRole($role)->create());
            [$tender, $item] = $this->makeTenderWithItem('PRZ/DEL/'.$role);

            $this->deleteJson("/api/tenders/{$tender->id}/items/{$item->id}")
                ->assertOk()
                ->assertJson(['ok' => true]);
            $this->assertDatabaseMissing('tender_items', ['id' => $item->id]);
        }
    }

    public function test_handlowiec_cannot_delete_item(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);
        [$tender, $item] = $this->makeTenderWithItem('PRZ/DEL/H', $user);

        $this->deleteJson("/api/tenders/{$tender->id}/items/{$item->id}")
            ->assertForbidden();
        $this->assertDatabaseHas('tender_items', ['id' => $item->id]);
    }

    public function test_admin_can_delete_tender(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        [$tender, $item] = $this->makeTenderWithItem('PRZ/DEL/T');

        $this->deleteJson("/api/tenders/{$tender->id}")
            ->assertOk()
            ->assertJson(['ok' => true]);
        $this->assertDatabaseMissing('tenders', ['id' => $tender->id]);
        $this->assertDatabaseMissing('tender_items', ['id' => $item->id]);
    }

    public function test_dyrektor_cannot_delete_tender(): void
    {
        $user = User::factory()->withRole('dyrektor')->create();
        Sanctum::actingAs($user);
        [$tender] = $this->makeTenderWithItem('PRZ/DEL/D', $user);

        $this->deleteJson("/api/tenders/{$tender->id}")->assertForbidden();
        $this->assertDatabaseHas('tenders', ['id' => $tender->id]);
    }

    /**
     * @return array{0: Tender, 1: TenderItem}
     */
    private function makeTenderWithItem(string $number, ?User $owner = null): array
    {
        $owner ??= User::factory()->create();
        $client = Client::query()->create(['name' => 'Klient DEL']);
        $tender = Tender::query()->create([
            'number' => $number,
            'title' => 'Usuwanie',
            'client_id' => $client->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Kalosze bezpieczne',
            'quantity' => 1,
            'status' => 'brak',
        ]);

        return [$tender, $item];
    }
}
