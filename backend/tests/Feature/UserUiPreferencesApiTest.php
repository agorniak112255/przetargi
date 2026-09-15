<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Preferencje wyglądu (szablon i tryb) zapisane na koncie użytkownika — /me zwraca je zawsze znormalizowane.
 */
final class UserUiPreferencesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_me_returns_null_preferences_when_nothing_saved(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('ui_preferences', ['template' => null, 'mode' => null]);
    }

    public function test_patch_saves_preferences_and_second_patch_overwrites_both(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/me/preferences', ['template' => 'nocna-zmiana', 'mode' => 'dark'])
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('ui_preferences', ['template' => 'nocna-zmiana', 'mode' => 'dark']);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('ui_preferences', ['template' => 'nocna-zmiana', 'mode' => 'dark']);

        $this->patchJson('/api/me/preferences', ['template' => 'klasyczny', 'mode' => 'system'])
            ->assertOk()
            ->assertJsonPath('ui_preferences', ['template' => 'klasyczny', 'mode' => 'system']);

        $this->patchJson('/api/me/preferences', ['template' => null, 'mode' => null])
            ->assertOk()
            ->assertJsonPath('ui_preferences', ['template' => null, 'mode' => null]);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('ui_preferences', ['template' => null, 'mode' => null]);
        $this->assertSame(['template' => null, 'mode' => null], $user->fresh()->ui_preferences);
    }

    public function test_invalid_values_are_rejected_and_nothing_changes(): void
    {
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);
        $this->patchJson('/api/me/preferences', ['template' => 'klasyczny', 'mode' => 'light'])->assertOk();

        $invalid = [
            ['template' => 'klasyczny', 'mode' => 'sepia'],
            ['template' => 'Nocna Zmiana!', 'mode' => 'dark'],
            ['template' => str_repeat('a', 41), 'mode' => 'dark'],
            ['template' => 5, 'mode' => 'dark'],
        ];
        foreach ($invalid as $payload) {
            $response = $this->patchJson('/api/me/preferences', $payload);
            $this->assertSame(422, $response->status(), 'Oczekiwano 422 dla: '.json_encode($payload));
        }

        $this->assertSame(['template' => 'klasyczny', 'mode' => 'light'], $user->fresh()->ui_preferences);
        $this->getJson('/api/me')
            ->assertJsonPath('ui_preferences', ['template' => 'klasyczny', 'mode' => 'light']);
    }

    public function test_empty_string_template_is_saved_as_null(): void
    {
        // Globalny ConvertEmptyStringsToNull zamienia "" na null, a null jest dozwolony (brak wybranego szablonu).
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/me/preferences', ['template' => '', 'mode' => 'dark'])
            ->assertOk()
            ->assertJsonPath('ui_preferences', ['template' => null, 'mode' => 'dark']);
    }

    public function test_template_of_exactly_40_chars_is_accepted(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $template = str_repeat('a', 38).'-1';

        $this->patchJson('/api/me/preferences', ['template' => $template, 'mode' => 'light'])
            ->assertOk()
            ->assertJsonPath('ui_preferences.template', $template);
    }

    public function test_missing_key_is_rejected(): void
    {
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/me/preferences', ['template' => 'klasyczny'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mode']);
        $this->patchJson('/api/me/preferences', ['mode' => 'dark'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['template']);

        $this->assertNull($user->fresh()->ui_preferences);
    }

    public function test_requires_authentication(): void
    {
        $this->patchJson('/api/me/preferences', ['template' => 'klasyczny', 'mode' => 'dark'])
            ->assertUnauthorized();
    }

    public function test_saving_preferences_is_not_logged_in_activity_log(): void
    {
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/me/preferences', ['template' => 'klasyczny', 'mode' => 'dark'])->assertOk();

        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_invalid_stored_values_are_returned_as_null(): void
    {
        $user = User::factory()->withRole('admin')->create();
        $user->forceFill(['ui_preferences' => ['template' => 'ZŁE', 'mode' => 'x']])->save();
        Sanctum::actingAs($user);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('ui_preferences', ['template' => null, 'mode' => null]);
    }
}
