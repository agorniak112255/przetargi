<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ActivityLogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_login_and_logout_are_logged(): void
    {
        $user = User::factory()->withRole('admin')->create([
            'email' => 'admin-log@test.local',
            'password' => 'password',
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'login',
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/logout')->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'logout',
        ]);
    }

    public function test_failed_login_is_logged(): void
    {
        $this->postJson('/api/login', [
            'email' => 'nobody@test.local',
            'password' => 'wrong',
        ])->assertStatus(422);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'login_failed',
        ]);
    }

    public function test_mutating_actions_are_logged_via_middleware(): void
    {
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/clients', [
            'name' => 'Klient Audit',
            'nip' => null,
        ])->assertCreated();

        $this->assertTrue(
            ActivityLog::query()
                ->where('user_id', $user->id)
                ->where('action', 'client.created')
                ->exists()
        );
    }

    public function test_admin_can_list_activity_logs(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        ActivityLog::query()->create([
            'user_id' => $admin->id,
            'action' => 'login',
            'meta' => ['label' => 'Logowanie', 'user_name' => $admin->name, 'user_email' => $admin->email],
            'ip_address' => '127.0.0.1',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/activity-logs')
            ->assertOk()
            ->assertJsonPath('meta.retention_days', 120)
            ->assertJsonFragment(['action' => 'login']);
    }

    public function test_handlowiec_cannot_view_activity_logs(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->getJson('/api/admin/activity-logs')->assertForbidden();
    }

    public function test_prune_removes_old_logs(): void
    {
        $old = ActivityLog::query()->create([
            'action' => 'login',
            'meta' => ['label' => 'Stary'],
        ]);
        $old->created_at = now()->subDays(121);
        $old->save();

        $fresh = ActivityLog::query()->create([
            'action' => 'logout',
            'meta' => ['label' => 'Nowy'],
        ]);

        $this->artisan('activity-logs:prune')->assertSuccessful();

        $this->assertDatabaseMissing('activity_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('activity_logs', ['id' => $fresh->id]);
    }
}
