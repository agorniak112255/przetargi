<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class PresenceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_migration_adds_presence_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['last_login_at', 'last_seen_at']));
        $this->assertTrue(Schema::hasColumns('personal_access_tokens', ['ip_address', 'user_agent', 'presence_path', 'presence_at']));
    }

    public function test_migration_backfills_last_login_and_last_seen(): void
    {
        $withBoth = User::factory()->create();
        $onlyLogin = User::factory()->create();
        $untouched = User::factory()->create();

        $this->insertLogin($withBoth, '2026-09-01 08:00:00');
        $this->insertLogin($withBoth, '2026-09-10 09:00:00');
        $this->insertLogin($onlyLogin, '2026-09-05 10:00:00');
        $withBoth->createToken('spa')->accessToken->forceFill(['last_used_at' => '2026-09-12 11:00:00'])->save();
        $withBoth->createToken('spa')->accessToken->forceFill(['last_used_at' => '2026-09-11 11:00:00'])->save();

        $migration = require database_path('migrations/2026_09_15_190000_add_presence_to_users_and_tokens.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'last_seen_at'));
        $this->assertFalse(Schema::hasColumn('personal_access_tokens', 'presence_at'));
        $migration->up();

        $withBoth->refresh();
        $this->assertSame('2026-09-10 09:00:00', $withBoth->last_login_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-12 11:00:00', $withBoth->last_seen_at?->format('Y-m-d H:i:s'));

        $onlyLogin->refresh();
        $this->assertSame('2026-09-05 10:00:00', $onlyLogin->last_login_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-05 10:00:00', $onlyLogin->last_seen_at?->format('Y-m-d H:i:s'));

        $untouched->refresh();
        $this->assertNull($untouched->last_login_at);
        $this->assertNull($untouched->last_seen_at);
    }

    public function test_login_records_presence_on_user_and_token(): void
    {
        $user = User::factory()->withRole('handlowiec')->create([
            'email' => 'presence-login@test.local',
            'password' => 'password',
        ]);

        $this->withHeader('User-Agent', 'PresenceTest/1.0')
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertNotNull($user->last_seen_at);

        /** @var PersonalAccessToken $token */
        $token = $user->tokens()->sole();
        $this->assertSame('spa', $token->name);
        $this->assertSame('127.0.0.1', $token->ip_address);
        $this->assertSame('PresenceTest/1.0', $token->user_agent);
        $this->assertNotNull($token->presence_at);
        $this->assertNull($token->presence_path);
    }

    public function test_presence_updates_only_the_current_token(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $current = $user->createToken('spa');
        $other = $user->createToken('spa');

        $this->withHeader('Authorization', 'Bearer '.$current->plainTextToken)
            ->postJson('/api/me/presence', ['path' => '/tenders/12'])
            ->assertNoContent();

        $currentRow = $current->accessToken->fresh();
        $this->assertSame('/tenders/12', $currentRow->presence_path);
        $this->assertNotNull($currentRow->presence_at);

        $otherRow = $other->accessToken->fresh();
        $this->assertNull($otherRow->presence_path);
        $this->assertNull($otherRow->presence_at);

        $this->assertNotNull($user->fresh()->last_seen_at);

        // Druga sesja tego samego użytkownika zapisuje własną podstronę, nie ruszając pierwszej.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$other->plainTextToken)
            ->postJson('/api/me/presence', ['path' => '/products'])
            ->assertNoContent();

        $this->assertSame('/products', $other->accessToken->fresh()->presence_path);
        $this->assertSame('/tenders/12', $current->accessToken->fresh()->presence_path);
    }

    public function test_presence_validates_path(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $token = $user->createToken('spa')->plainTextToken;

        foreach ([[], ['path' => 'tenders'], ['path' => '/tenders 12'], ['path' => '/products?q=rekawice'], ['path' => '/tenders/12#pozycje'], ['path' => '/'.str_repeat('a', 255)]] as $payload) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson('/api/me/presence', $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors('path');
        }
    }

    public function test_presence_requires_authentication(): void
    {
        $this->postJson('/api/me/presence', ['path' => '/'])->assertUnauthorized();
    }

    public function test_presence_is_not_written_to_activity_log(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $token = $user->createToken('spa')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/me/presence', ['path' => '/dashboard'])
            ->assertNoContent();

        $this->assertSame(0, ActivityLog::query()->count());
    }

    private function insertLogin(User $user, string $at): void
    {
        $log = ActivityLog::query()->create([
            'user_id' => $user->id,
            'action' => 'login',
            'meta' => ['label' => 'Logowanie'],
        ]);
        $log->created_at = $at;
        $log->save();
    }
}
