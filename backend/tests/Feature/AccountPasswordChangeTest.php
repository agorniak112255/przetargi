<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AccountPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function login(User $user, string $password): string
    {
        $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => $password])->assertOk();

        return (string) $response->json('token');
    }

    public function test_user_changes_own_password_and_logs_in_with_the_new_one(): void
    {
        $user = User::factory()->withRole('handlowiec')->create([
            'email' => 'zmiana@test.local',
            'password' => Hash::make('stare-haslo-123'),
        ]);
        $token = $this->login($user, 'stare-haslo-123');

        $this->withToken($token)->postJson('/api/me/password', [
            'current_password' => 'stare-haslo-123',
            'password' => 'nowe-haslo-456',
            'password_confirmation' => 'nowe-haslo-456',
        ])->assertOk();

        $this->assertTrue(Hash::check('nowe-haslo-456', (string) $user->refresh()->password));

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'stare-haslo-123'])
            ->assertStatus(422);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'nowe-haslo-456'])
            ->assertOk();
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->withRole('handlowiec')->create(['password' => Hash::make('stare-haslo-123')]);
        $token = $this->login($user, 'stare-haslo-123');

        $this->withToken($token)->postJson('/api/me/password', [
            'current_password' => 'nie-to-haslo',
            'password' => 'nowe-haslo-456',
            'password_confirmation' => 'nowe-haslo-456',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('stare-haslo-123', (string) $user->refresh()->password));
    }

    public function test_confirmation_and_length_are_required(): void
    {
        $user = User::factory()->withRole('handlowiec')->create(['password' => Hash::make('stare-haslo-123')]);
        $token = $this->login($user, 'stare-haslo-123');

        $this->withToken($token)->postJson('/api/me/password', [
            'current_password' => 'stare-haslo-123',
            'password' => 'nowe-haslo-456',
            'password_confirmation' => 'inne-haslo-789',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->withToken($token)->postJson('/api/me/password', [
            'current_password' => 'stare-haslo-123',
            'password' => 'krotkie',
            'password_confirmation' => 'krotkie',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('stare-haslo-123', (string) $user->refresh()->password));
    }

    public function test_new_password_must_differ_from_the_old_one(): void
    {
        $user = User::factory()->withRole('handlowiec')->create(['password' => Hash::make('stare-haslo-123')]);
        $token = $this->login($user, 'stare-haslo-123');

        $this->withToken($token)->postJson('/api/me/password', [
            'current_password' => 'stare-haslo-123',
            'password' => 'stare-haslo-123',
            'password_confirmation' => 'stare-haslo-123',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_change_keeps_current_session_and_drops_the_others(): void
    {
        $user = User::factory()->withRole('handlowiec')->create(['password' => Hash::make('stare-haslo-123')]);
        $otherToken = $this->login($user, 'stare-haslo-123');
        $currentToken = $this->login($user, 'stare-haslo-123');

        $this->withToken($currentToken)->postJson('/api/me/password', [
            'current_password' => 'stare-haslo-123',
            'password' => 'nowe-haslo-456',
            'password_confirmation' => 'nowe-haslo-456',
        ])->assertOk();

        // Uwierzytelnienie sprawdzamy w bazie: w jednym teście guard trzyma raz zalogowanego użytkownika,
        // więc kolejne żądanie przeszłoby nawet z unieważnionym tokenem.
        $currentId = (int) explode('|', $currentToken)[0];
        $otherId = (int) explode('|', $otherToken)[0];

        $this->assertSame([$currentId], $user->tokens()->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherId]);
    }

    public function test_guest_cannot_change_password(): void
    {
        $this->postJson('/api/me/password', [
            'current_password' => 'stare-haslo-123',
            'password' => 'nowe-haslo-456',
            'password_confirmation' => 'nowe-haslo-456',
        ])->assertUnauthorized();
    }
}
