<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminUserNameApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_admin_changes_name_to_full_name_when_editing_user(): void
    {
        $user = User::factory()->withRole('handlowiec')->create(['name' => 'Alina']);

        $this->patchJson("/api/admin/users/{$user->id}", [
            'name' => 'Alina Tomaszewska',
            'email' => $user->email,
            'role' => 'handlowiec',
        ])->assertOk()
            ->assertJsonPath('name', 'Alina Tomaszewska');

        $this->assertSame('Alina Tomaszewska', $user->fresh()->name);
    }

    public function test_blank_name_is_rejected_and_keeps_old_name(): void
    {
        $user = User::factory()->withRole('handlowiec')->create(['name' => 'Alina']);

        $this->patchJson("/api/admin/users/{$user->id}", ['name' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame('Alina', $user->fresh()->name);
    }
}
