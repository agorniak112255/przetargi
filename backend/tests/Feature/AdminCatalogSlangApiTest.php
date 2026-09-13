<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminCatalogSlangApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_read_and_save_catalog_slang(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->getJson('/api/admin/catalog-slang')
            ->assertOk()
            ->assertJsonStructure([
                'entries',
                'defaults',
                'categories',
            ])
            ->assertJsonPath('categories.rece', 'Ręce');

        $this->putJson('/api/admin/catalog-slang', [
            'catalog_slang' => [[
                'category' => 'rece',
                'terms' => ['wampirki'],
                'phrases' => ['rękawice powlekane'],
                'note' => 'z admina',
                'jargon' => true,
                'keywords' => ['dzianina'],
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('entries.0.terms.0', 'wampirki')
            ->assertJsonPath('entries.0.phrases.0', 'rękawice powlekane')
            ->assertJsonPath('entries.0.note', 'z admina')
            ->assertJsonPath('entries.0.keywords.0', 'dzianina');
    }

    public function test_jargon_flag_false_survives_save_and_read(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        // Cecha techniczna (klasa obuwia) zapisana z `jargon=false` musi wrócić jako `false` —
        // stare `normalize()` wymuszało `true` i sanitizer kroków wycinał S5 jak żargon.
        $this->putJson('/api/admin/catalog-slang', [
            'catalog_slang' => [
                ['category' => 'stopy', 'terms' => ['S5'], 'phrases' => ['obuwie ochronne'], 'jargon' => false],
                ['category' => 'rece', 'terms' => ['wampirki'], 'phrases' => ['rękawice powlekane'], 'jargon' => true],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('entries.0.jargon', false)
            ->assertJsonPath('entries.1.jargon', true);

        $this->getJson('/api/admin/catalog-slang')
            ->assertOk()
            ->assertJsonPath('entries.0.terms.0', 'S5')
            ->assertJsonPath('entries.0.jargon', false)
            ->assertJsonPath('entries.1.jargon', true);
    }

    public function test_catalog_slang_put_rejects_empty_terms(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson('/api/admin/catalog-slang', [
            'catalog_slang' => [[
                'category' => 'rece',
                'terms' => [],
                'phrases' => ['rękawice'],
            ]],
        ])->assertStatus(422);
    }

    public function test_handlowiec_cannot_access_catalog_slang(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->getJson('/api/admin/catalog-slang')->assertForbidden();
        $this->putJson('/api/admin/catalog-slang', ['catalog_slang' => []])->assertForbidden();
    }
}
