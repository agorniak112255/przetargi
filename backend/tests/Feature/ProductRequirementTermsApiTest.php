<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

final class ProductRequirementTermsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // Znaczniki liczą same reguły — żadne żądanie do modelu nie może wyjść.
        Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_goggles_have_head_noun_and_en_166_with_four_needles(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->postJson('/api/products/requirement-terms', ['query' => Opisowy15Fixture::requirement(9)])
            ->assertOk()
            ->assertJsonPath('head', 'Gogle ochronne szczelne, spawalnicze, z zaciemnieniem 5.0')
            ->assertJsonPath('noun.label', 'Gogle')
            ->assertJsonPath('norms', [
                ['label' => 'EN 166', 'needles' => ['EN 166', 'EN166', 'ISO 166', 'ISO166']],
            ]);
    }

    public function test_forearm_sleeve_lists_all_norms_without_family_noun(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $response = $this->postJson('/api/products/requirement-terms', ['query' => Opisowy15Fixture::requirement(1)])
            ->assertOk()
            ->assertJsonPath('head', 'Ochraniacz przedramienia (rękaw) chroniący przed przecięciem')
            ->assertJsonPath('noun', null);

        $this->assertSame(['EN 388', 'EN 407', 'EN 420'], array_column($response->json('norms'), 'label'));
    }

    public function test_norm_written_with_iso_gets_en_iso_label(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->postJson('/api/products/requirement-terms', ['query' => Opisowy15Fixture::requirement(3)])
            ->assertOk()
            ->assertJsonPath('norms', [
                ['label' => 'EN ISO 20345', 'needles' => ['EN 20345', 'EN20345', 'ISO 20345', 'ISO20345']],
            ]);

        // „EN 343” bez ISO obok „EN ISO 20345” — ISO jednej normy nie przechodzi na drugą.
        $response = $this->postJson('/api/products/requirement-terms', ['query' => Opisowy15Fixture::requirement(5)])
            ->assertOk();
        $this->assertSame(['EN 343', 'EN ISO 20345'], array_column($response->json('norms'), 'label'));
    }

    public function test_iso_label_ignores_part_number_suffix(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        // norms() bierze sam numer bez części („16321-1” → „16321”).
        $this->postJson('/api/products/requirement-terms', ['query' => 'Okulary korekcyjne ochronne wg EN ISO 16321-1'])
            ->assertOk()
            ->assertJsonPath('norms.0.label', 'EN ISO 16321');
    }

    public function test_non_ppe_query_has_no_noun_and_no_norms(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->postJson('/api/products/requirement-terms', ['query' => 'Klej termotopliwy biały'])
            ->assertOk()
            ->assertJsonPath('head', 'Klej termotopliwy biały')
            ->assertJsonPath('noun', null)
            ->assertJsonPath('norms', []);
    }

    public function test_needles_never_contain_one_another(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $norms = [];
        foreach ([1, 3, 5, 9] as $line) {
            $norms = [
                ...$norms,
                ...$this->postJson('/api/products/requirement-terms', ['query' => Opisowy15Fixture::requirement($line)])
                    ->assertOk()
                    ->json('norms'),
            ];
        }

        $this->assertNotEmpty($norms);
        foreach ($norms as $norm) {
            foreach ($norm['needles'] as $i => $needle) {
                foreach ($norm['needles'] as $j => $other) {
                    if ($i !== $j) {
                        $this->assertFalse(
                            str_contains(mb_strtolower($other), mb_strtolower($needle)),
                            "Igła „{$needle}” zawiera się w „{$other}” — jedno wystąpienie liczyłoby się dwa razy."
                        );
                    }
                }
            }
        }
    }

    public function test_query_is_required_and_at_least_three_characters(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->postJson('/api/products/requirement-terms', [])->assertStatus(422);
        $this->postJson('/api/products/requirement-terms', ['query' => ''])->assertStatus(422);
        $this->postJson('/api/products/requirement-terms', ['query' => 'ab'])->assertStatus(422);
    }

    public function test_user_without_products_view_is_forbidden(): void
    {
        // Każda rola z seedera ma products.view — użytkownik bez ról nie ma żadnego uprawnienia.
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/products/requirement-terms', ['query' => Opisowy15Fixture::requirement(9)])
            ->assertForbidden();
    }
}
