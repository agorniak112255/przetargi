<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\EnrichmentDescriptionTemplates;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminEnrichmentDescriptionTemplatesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_list_family_templates(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->getJson('/api/admin/enrichment-description-templates')
            ->assertOk()
            ->assertJsonCount(count(EnrichmentDescriptionTemplates::keys()), 'templates')
            ->assertJsonPath('templates.0.kategoria_bhp', 'rekawice')
            ->assertJsonPath('labels.obuwie', 'Obuwie');
    }

    public function test_admin_can_update_and_restore_template(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $custom = "To rękawice testowe.\nZbierz poziomy EN 388 ze źródeł i nic więcej.";

        $this->putJson('/api/admin/enrichment-description-templates/rekawice', [
            'instructions' => $custom,
        ])
            ->assertOk()
            ->assertJsonPath('kategoria_bhp', 'rekawice')
            ->assertJsonPath('is_customized', true)
            ->assertJsonPath('instructions', $custom);

        $this->postJson('/api/admin/enrichment-description-templates/rekawice/restore')
            ->assertOk()
            ->assertJsonPath('is_customized', false)
            ->assertJsonPath('instructions', EnrichmentDescriptionTemplates::defaultInstructions('rekawice'));
    }

    public function test_unknown_family_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson('/api/admin/enrichment-description-templates/nieistnieje', [
            'instructions' => str_repeat('Instrukcja testowa. ', 4),
        ])->assertStatus(422);
    }

    public function test_handlowiec_cannot_access_templates(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->getJson('/api/admin/enrichment-description-templates')->assertForbidden();
        $this->putJson('/api/admin/enrichment-description-templates/rekawice', [
            'instructions' => str_repeat('Instrukcja testowa. ', 4),
        ])->assertForbidden();
    }
}
