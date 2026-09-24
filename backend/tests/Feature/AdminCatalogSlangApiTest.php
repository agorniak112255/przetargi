<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\CatalogSlangDictionary;
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

    public function test_default_entries_are_numbered_in_config_order(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $entries = $this->getJson('/api/admin/catalog-slang')->assertOk()->json('entries');

        $this->assertSame(range(1, count(CatalogSlangDictionary::defaults())), array_column($entries, 'id'));
    }

    public function test_deleting_an_entry_keeps_numbers_of_the_others_and_never_reuses_it(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $defaultsMax = count(CatalogSlangDictionary::defaults());

        // Nowe wpisy dostają numery za wpisami startowymi.
        $entries = $this->putJson('/api/admin/catalog-slang', ['catalog_slang' => [
            $this->entry('wampirki'),
            $this->entry('gumówki'),
            $this->entry('pianki'),
        ]])->assertOk()->json('entries');
        $this->assertSame([$defaultsMax + 1, $defaultsMax + 2, $defaultsMax + 3], array_column($entries, 'id'));

        // Usunięcie środkowego nie przenumerowuje pozostałych.
        $entries = $this->putJson('/api/admin/catalog-slang', ['catalog_slang' => [
            $entries[0],
            $entries[2],
        ]])->assertOk()->json('entries');
        $this->assertSame([$defaultsMax + 1, $defaultsMax + 3], array_column($entries, 'id'));
        $this->assertSame(['pianki'], $entries[1]['terms']);

        // Usunięty ostatni numer nie wraca przy następnym nowym wpisie.
        $entries = $this->putJson('/api/admin/catalog-slang', ['catalog_slang' => [
            $entries[0],
        ]])->assertOk()->json('entries');
        $entries = $this->putJson('/api/admin/catalog-slang', ['catalog_slang' => [
            $entries[0],
            $this->entry('nitrylki'),
        ]])->assertOk()->json('entries');
        $this->assertSame([$defaultsMax + 1, $defaultsMax + 4], array_column($entries, 'id'));

        $this->getJson('/api/admin/catalog-slang')
            ->assertOk()
            ->assertJsonPath('entries.1.id', $defaultsMax + 4)
            ->assertJsonPath('entries.1.terms.0', 'nitrylki');
    }

    public function test_duplicate_entry_number_gets_a_new_one(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $defaultsMax = count(CatalogSlangDictionary::defaults());

        $entries = $this->putJson('/api/admin/catalog-slang', ['catalog_slang' => [
            ['id' => 7] + $this->entry('wampirki'),
            ['id' => 7] + $this->entry('gumówki'),
        ]])->assertOk()->json('entries');

        $this->assertSame([7, $defaultsMax + 1], array_column($entries, 'id'));
    }

    public function test_catalog_slang_put_rejects_invalid_entry_number(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson('/api/admin/catalog-slang', ['catalog_slang' => [
            ['id' => 0] + $this->entry('wampirki'),
        ]])->assertStatus(422);
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

    /**
     * @return array{category: string, terms: list<string>, phrases: list<string>}
     */
    private function entry(string $term): array
    {
        return ['category' => 'rece', 'terms' => [$term], 'phrases' => ['rękawice robocze']];
    }
}
