<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\User;
use App\Support\CompanyName;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Podpowiedzi firm konkurencji: GET /competitors?q= (najwyżej 20, po nazwie bez form prawnych albo po NIP).
 */
final class CompetitorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_requires_tender_view_permission(): void
    {
        $this->getJson('/api/competitors?q=bhp')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/competitors?q=bhp')->assertForbidden();

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson('/api/competitors?q=bhp')->assertOk()->assertJsonPath('data', []);
    }

    public function test_search_by_name_ignores_case_polish_letters_and_legal_form(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $pro = $this->competitor('BHP-Pro Handel sp. z o.o.', '1181625269');
        $this->competitor('Ochrona Plus S.A.', null);
        $starts = $this->competitor('Żółta Odzież Robocza', null);
        $contains = $this->competitor('Hurtownia Żółta Odzież', null);

        $this->getJson('/api/competitors?q='.urlencode('bhp pro spółka z o.o.'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pro->id)
            ->assertJsonPath('data.0.name', 'BHP-Pro Handel sp. z o.o.')
            ->assertJsonPath('data.0.nip', '1181625269')
            ->assertJsonMissingPath('data.0.name_key');

        // najpierw nazwy zaczynające się od wpisanego tekstu
        $this->getJson('/api/competitors?q='.urlencode('zolta odziez'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $starts->id)
            ->assertJsonPath('data.1.id', $contains->id);
    }

    public function test_search_by_nip_prefix(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $pro = $this->competitor('BHP-Pro Handel sp. z o.o.', '1181625269');
        $this->competitor('Ochrona Plus', '5260250274');

        $this->getJson('/api/competitors?q='.urlencode('118-16'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pro->id);
        $this->getJson('/api/competitors?q=PL1181625269')
            ->assertOk()
            ->assertJsonPath('data.0.id', $pro->id);
    }

    public function test_limit_and_empty_query(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        for ($i = 1; $i <= 25; $i++) {
            $this->competitor('Firma Odzieżowa '.$i, null);
        }

        $this->getJson('/api/competitors?q=odziezowa')->assertOk()->assertJsonCount(20, 'data');
        $this->getJson('/api/competitors')->assertOk()->assertJsonCount(20, 'data');
        $this->getJson('/api/competitors?q='.urlencode('%'))->assertOk()->assertJsonPath('data', []);
    }

    private function competitor(string $name, ?string $nip): Competitor
    {
        return Competitor::query()->create(['name' => $name, 'name_key' => CompanyName::key($name), 'nip' => $nip]);
    }
}
