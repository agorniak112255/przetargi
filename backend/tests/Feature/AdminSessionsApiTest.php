<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminSessionsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->freezeSecond();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tokenFor(User $user, array $attributes): int
    {
        $token = $user->createToken('spa')->accessToken;
        $token->forceFill($attributes)->save();

        return (int) $token->id;
    }

    public function test_admin_sees_active_session_with_path_and_user(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $worker = User::factory()->withRole('handlowiec')->create(['name' => 'Jan Handlowiec', 'email' => 'jan@test.local']);
        $tokenId = $this->tokenFor($worker, [
            'ip_address' => '10.0.0.5',
            'user_agent' => 'Mozilla/5.0 Test',
            'presence_path' => '/tenders/12',
            'presence_at' => now()->subMinute(),
            'last_used_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.token_id', $tokenId)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.path', '/tenders/12')
            ->assertJsonPath('data.0.ip_address', '10.0.0.5')
            ->assertJsonPath('data.0.user_agent', 'Mozilla/5.0 Test')
            ->assertJsonPath('data.0.presence_at', now()->subMinute()->toIso8601String())
            ->assertJsonPath('data.0.user.id', $worker->id)
            ->assertJsonPath('data.0.user.name', 'Jan Handlowiec')
            ->assertJsonPath('data.0.user.email', 'jan@test.local')
            ->assertJsonPath('data.0.user.role', 'handlowiec')
            ->assertJsonPath('meta.active_minutes', 3)
            ->assertJsonPath('meta.window_minutes', 15);

        $row = $response->json('data.0');
        $this->assertArrayNotHasKey('token', $row);
        $this->assertArrayNotHasKey('abilities', $row);
    }

    public function test_idle_and_expired_sessions_and_ordering(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $idleUser = User::factory()->withRole('handlowiec')->create();
        $activeUser = User::factory()->withRole('kierownik')->create();
        $goneUser = User::factory()->withRole('handlowiec')->create();

        // Bezczynna, ale z nowszym zapytaniem niż aktywna — mimo to aktywna musi być pierwsza.
        $idleId = $this->tokenFor($idleUser, [
            'presence_at' => now()->subMinutes(10),
            'last_used_at' => now()->subSeconds(10),
        ]);
        $activeId = $this->tokenFor($activeUser, [
            'presence_at' => now()->subMinutes(2),
            'last_used_at' => now()->subMinutes(2),
        ]);
        $goneId = $this->tokenFor($goneUser, [
            'presence_at' => now()->subMinutes(30),
            'last_used_at' => now()->subMinutes(30),
        ]);
        // Token osierocony (użytkownik nie istnieje) nie może trafić na listę.
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => 999999,
            'name' => 'spa',
            'token' => hash('sha256', Str::random(40)),
            'presence_at' => now(),
            'last_used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.token_id', $activeId)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.user.role', 'kierownik')
            ->assertJsonPath('data.1.token_id', $idleId)
            ->assertJsonPath('data.1.status', 'idle');

        $this->assertNotContains($goneId, $response->json('data.*.token_id'));
    }

    public function test_users_activity_lists_all_users_with_online_and_sessions_count(): void
    {
        $admin = User::factory()->withRole('admin')->create(['name' => 'Zenon Admin']);
        $online = User::factory()->withRole('handlowiec')->create(['name' => 'Online Olga']);
        $offline = User::factory()->withRole('dyrektor')->create(['name' => 'Offline Oskar']);
        $never = User::factory()->withRole('handlowiec')->create(['name' => 'Adam Nigdy']);

        $online->forceFill(['last_seen_at' => now()->subMinute(), 'last_login_at' => now()->subHour()])->save();
        $offline->forceFill(['last_seen_at' => now()->subDay(), 'last_login_at' => now()->subDays(2)])->save();

        $this->tokenFor($online, ['presence_at' => now()->subMinute()]);
        $this->tokenFor($online, ['presence_at' => now()->subHours(3)]);
        $this->tokenFor($offline, ['presence_at' => now()->subMinutes(20), 'last_used_at' => now()->subMinutes(20)]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/users-activity')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.active_minutes', 3)
            // Sortowanie: last_seen_at malejąco, puste na końcu wg nazwy.
            ->assertJsonPath('data.0.id', $online->id)
            ->assertJsonPath('data.1.id', $offline->id)
            ->assertJsonPath('data.2.id', $never->id)
            ->assertJsonPath('data.3.id', $admin->id)
            ->assertJsonPath('data.0.online', true)
            ->assertJsonPath('data.0.sessions_count', 2)
            ->assertJsonPath('data.0.role', 'handlowiec')
            ->assertJsonPath('data.0.last_seen_at', now()->subMinute()->toIso8601String())
            ->assertJsonPath('data.0.last_login_at', now()->subHour()->toIso8601String())
            ->assertJsonPath('data.1.online', false)
            ->assertJsonPath('data.1.sessions_count', 1)
            ->assertJsonPath('data.1.role', 'dyrektor')
            ->assertJsonPath('data.2.online', false)
            ->assertJsonPath('data.2.sessions_count', 0)
            ->assertJsonPath('data.2.last_seen_at', null)
            ->assertJsonPath('data.2.last_login_at', null);

        $this->assertArrayNotHasKey('token', $response->json('data.0'));
    }

    public function test_users_activity_splits_sessions_into_recent_and_stale(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $worker = User::factory()->withRole('handlowiec')->create();

        // Świeże: wystarczy jeden ślad ruchu z ostatnich 30 dni, a równo 30 dni jeszcze się liczy.
        $this->tokenFor($worker, ['created_at' => now()->subDays(90), 'last_used_at' => now()->subDays(2)]);
        $this->tokenFor($worker, ['created_at' => now()->subDays(90), 'presence_at' => now()->subDays(10)]);
        $this->tokenFor($worker, ['created_at' => now()->subDays(5)]);
        $this->tokenFor($worker, ['created_at' => now()->subDays(90), 'last_used_at' => now()->subDays(30)]);
        // Stare: ostatni ruch sprzed ponad 30 dni albo logowanie, po którym nic się nie działo.
        $this->tokenFor($worker, [
            'created_at' => now()->subDays(90),
            'last_used_at' => now()->subDays(31),
            'presence_at' => now()->subDays(40),
        ]);
        $this->tokenFor($worker, ['created_at' => now()->subDays(30)->subSecond()]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/users-activity')
            ->assertOk()
            ->assertJsonPath('meta.stale_days', 30);

        $row = collect($response->json('data'))->firstWhere('id', $worker->id);
        $this->assertSame(6, $row['sessions_count']);
        $this->assertSame(4, $row['recent_sessions_count']);
        $this->assertSame(2, $row['stale_sessions_count']);
    }

    public function test_admin_logs_out_exactly_the_stale_sessions_counted_in_the_table(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $worker = User::factory()->withRole('handlowiec')->create(['name' => 'Jan Handlowiec', 'email' => 'jan@test.local']);
        $other = User::factory()->withRole('kierownik')->create();

        $keepWorker = $this->tokenFor($worker, ['created_at' => now()->subDays(90), 'last_used_at' => now()->subDay()]);
        $this->tokenFor($worker, ['created_at' => now()->subDays(90), 'last_used_at' => now()->subDays(45)]);
        $this->tokenFor($worker, ['created_at' => now()->subDays(60)]);
        $keepOther = $this->tokenFor($other, ['created_at' => now()->subDays(90), 'presence_at' => now()->subDays(3)]);
        // Token osierocony (konta już nie ma): tabela go nie liczy, więc przycisk też go nie rusza.
        $orphanId = (int) DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => User::class,
            'tokenable_id' => 999999,
            'name' => 'spa',
            'token' => hash('sha256', Str::random(40)),
            'created_at' => now()->subDays(90),
            'updated_at' => now()->subDays(90),
        ]);

        Sanctum::actingAs($admin);

        $shown = collect($this->getJson('/api/admin/users-activity')->json('data'))->sum('stale_sessions_count');
        $this->assertSame(2, $shown);

        $this->deleteJson('/api/admin/sessions/stale')
            ->assertOk()
            ->assertJsonPath('deleted', 2)
            ->assertJsonPath('stale_days', 30);

        $remaining = DB::table('personal_access_tokens')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $expected = [$keepWorker, $keepOther, $orphanId];
        sort($expected);
        $this->assertSame($expected, $remaining);

        // Jeden wpis w dzienniku — z kontrolera, z liczbą i kontem; bez drugiego, ogólnego wpisu z middleware'u.
        $log = ActivityLog::query()->sole();
        $this->assertSame('sessions.stale_revoked', $log->action);
        $this->assertSame($admin->id, (int) $log->user_id);
        $this->assertSame('Wylogowanie starych sesji: 2', $log->meta['label']);
        $this->assertSame(2, $log->meta['count']);
        $this->assertSame(30, $log->meta['stale_days']);
        $this->assertSame(
            [['id' => $worker->id, 'name' => 'Jan Handlowiec', 'email' => 'jan@test.local', 'count' => 2]],
            $log->meta['users'],
        );
    }

    public function test_current_session_survives_even_when_logged_in_long_ago(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $token = $admin->createToken('spa');
        $token->accessToken->forceFill(['created_at' => now()->subDays(90)])->save();

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->deleteJson('/api/admin/sessions/stale')
            ->assertOk()
            ->assertJsonPath('deleted', 0);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_viewing_sessions_does_not_allow_logging_them_out(): void
    {
        $worker = User::factory()->withRole('handlowiec')->create();
        $staleId = $this->tokenFor($worker, ['created_at' => now()->subDays(90)]);

        $role = Role::findOrCreate('podglad_sesji', 'web');
        $role->givePermissionTo(['admin.access', 'admin.sessions.view']);

        Sanctum::actingAs(User::factory()->withRole('podglad_sesji')->create());
        $this->deleteJson('/api/admin/sessions/stale')->assertForbidden();

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->deleteJson('/api/admin/sessions/stale')->assertForbidden();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $staleId]);
    }

    public function test_handlowiec_cannot_view_sessions(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->getJson('/api/admin/sessions')->assertForbidden();
        $this->getJson('/api/admin/users-activity')->assertForbidden();
    }

    public function test_admin_access_without_sessions_permission_is_forbidden(): void
    {
        $role = Role::findOrCreate('panel_bez_sesji', 'web');
        $role->givePermissionTo('admin.access');

        Sanctum::actingAs(User::factory()->withRole('panel_bez_sesji')->create());

        $this->getJson('/api/admin/sessions')->assertForbidden();
        $this->getJson('/api/admin/users-activity')->assertForbidden();
    }

    public function test_custom_role_with_sessions_permission_can_view(): void
    {
        $role = Role::findOrCreate('podglad_sesji', 'web');
        $role->givePermissionTo(['admin.access', 'admin.sessions.view']);

        Sanctum::actingAs(User::factory()->withRole('podglad_sesji')->create());

        $this->getJson('/api/admin/sessions')->assertOk();
        $this->getJson('/api/admin/users-activity')->assertOk();
    }
}
