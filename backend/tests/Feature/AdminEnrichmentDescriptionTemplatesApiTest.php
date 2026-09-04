<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\EnrichmentDescriptionLayouts;
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
            ->assertJsonCount(count(EnrichmentDescriptionTemplates::keys()) + 1, 'templates')
            ->assertJsonPath('templates.0.kategoria_bhp', EnrichmentDescriptionLayouts::DEFAULT_KEY)
            ->assertJsonPath('templates.0.is_visual_default', true)
            ->assertJsonPath('templates.1.kategoria_bhp', 'rekawice')
            ->assertJsonPath('labels.obuwie', 'Obuwie')
            ->assertJsonPath('blocks.0.id', 'description');
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

    public function test_admin_can_customize_family_card_layout(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $card = EnrichmentDescriptionLayouts::defaultBlocks('card');
        foreach ($card as $i => $block) {
            if ($block['id'] === 'specs') {
                $card[$i]['visible'] = false;
            }
            if ($block['id'] === 'norms') {
                $card[$i]['emphasis'] = 'highlight';
            }
        }
        $moved = $card[2];
        array_splice($card, 2, 1);
        array_unshift($card, $moved);

        $this->putJson('/api/admin/enrichment-description-templates/rekawice', [
            'layout' => [
                'inherit_card' => false,
                'inherit_export' => true,
                'card' => $card,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('kategoria_bhp', 'rekawice')
            ->assertJsonPath('is_layout_customized', true)
            ->assertJsonPath('layout.inherit_card', false)
            ->assertJsonPath('resolved_layout.card.0.id', $moved['id']);

        $hidden = collect($this->getJson('/api/admin/enrichment-description-templates')->json('templates'))
            ->firstWhere('kategoria_bhp', 'rekawice')['resolved_layout']['card'] ?? [];
        $specs = collect($hidden)->firstWhere('id', 'specs');
        $this->assertFalse((bool) ($specs['visible'] ?? true));
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
