<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminUserAppearanceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_admin_sets_appearance_when_creating_user(): void
    {
        $this->postJson('/api/admin/users', [
            'name' => 'Nowa',
            'email' => 'nowa-wyglad@test.local',
            'password' => 'password123',
            'role' => 'handlowiec',
            'ui_template' => 'nocna-zmiana',
            'ui_mode' => 'light',
        ])->assertCreated()
            ->assertJsonPath('ui_preferences.template', 'nocna-zmiana')
            ->assertJsonPath('ui_preferences.mode', 'light');

        $user = User::query()->where('email', 'nowa-wyglad@test.local')->sole();
        $this->assertSame(['template' => 'nocna-zmiana', 'mode' => 'light'], $user->ui_preferences);
    }

    public function test_creating_user_without_appearance_leaves_it_unset(): void
    {
        $this->postJson('/api/admin/users', [
            'name' => 'Bez',
            'email' => 'bez-wygladu@test.local',
            'password' => 'password123',
            'role' => 'handlowiec',
        ])->assertCreated()
            ->assertJsonPath('ui_preferences.template', null)
            ->assertJsonPath('ui_preferences.mode', null);

        $this->assertNull(User::query()->where('email', 'bez-wygladu@test.local')->sole()->ui_preferences);
    }

    public function test_admin_changes_and_clears_appearance_when_editing_user(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $user->forceFill(['ui_preferences' => ['template' => 'nocna-zmiana', 'mode' => 'dark']])->save();

        $this->patchJson("/api/admin/users/{$user->id}", [
            'ui_template' => 'klasyczny',
            'ui_mode' => null,
        ])->assertOk()
            ->assertJsonPath('ui_preferences.template', 'klasyczny')
            ->assertJsonPath('ui_preferences.mode', null);

        $this->patchJson("/api/admin/users/{$user->id}", [
            'ui_template' => null,
            'ui_mode' => null,
        ])->assertOk()
            ->assertJsonPath('ui_preferences.template', null);
    }

    public function test_editing_role_only_keeps_appearance(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $user->forceFill(['ui_preferences' => ['template' => 'nocna-zmiana', 'mode' => 'system']])->save();

        $this->patchJson("/api/admin/users/{$user->id}", ['role' => 'kierownik'])
            ->assertOk()
            ->assertJsonPath('ui_preferences.template', 'nocna-zmiana')
            ->assertJsonPath('ui_preferences.mode', 'system');
    }

    public function test_invalid_appearance_is_rejected(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();

        $this->patchJson("/api/admin/users/{$user->id}", ['ui_template' => 'Zły Szablon!'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ui_template');

        $this->patchJson("/api/admin/users/{$user->id}", ['ui_mode' => 'neon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ui_mode');
    }
}
