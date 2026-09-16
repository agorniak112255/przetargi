<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bDiscountRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Konfiguracja rabatów konta B2B: tabela upustów na grupy asortymentowe, edytowana w panelu.
 * Zapis idzie całą listą, bo kolejność reguł jest ich znaczeniem (pierwsza pasująca wygrywa).
 */
final class B2bDiscountRulesApiTest extends TestCase
{
    use RefreshDatabase;

    private B2bAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->account = B2bAccount::query()->create([
            'username' => 'PROTEKT',
            'sites' => ['protekt.pl'],
            'connector' => 'protekt',
        ]);
    }

    public function test_konto_witryny_publicznej_zapisuje_sie_bez_hasla(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->postJson('/api/b2b-accounts', [
            'username' => 'PROTEKT drugie',
            'sites' => ['protekt.pl'],
        ])
            ->assertCreated()
            ->assertJsonPath('has_password', false)
            ->assertJsonPath('connector', 'protekt');
    }

    public function test_konto_z_logowaniem_nadal_wymaga_hasla(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->postJson('/api/b2b-accounts', [
            'username' => 'jan',
            'sites' => ['b2b.anro.net.pl'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_zapis_i_odczyt_listy_regul(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson("/api/b2b-accounts/{$this->account->id}/discount-rules", [
            'rules' => [
                ['name' => 'Amortyzatory ABM+BW', 'match_field' => 'catalog_no', 'match_type' => 'prefix', 'pattern' => 'BW', 'discount_percent' => 45],
                ['name' => 'Amortyzatory ABW (seria AW)', 'match_field' => 'catalog_no', 'match_type' => 'prefix', 'pattern' => 'AW', 'discount_percent' => 40],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('rules.0.name', 'Amortyzatory ABM+BW')
            ->assertJsonPath('rules.0.position', 0)
            ->assertJsonPath('rules.1.discount_percent', 40);

        $this->getJson("/api/b2b-accounts/{$this->account->id}/discount-rules")
            ->assertOk()
            ->assertJsonCount(2, 'rules');
    }

    public function test_pusty_wzorzec_jest_odrzucany_poza_regula_wszystko(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson("/api/b2b-accounts/{$this->account->id}/discount-rules", [
            'rules' => [
                ['name' => 'Bez wzorca', 'match_field' => 'catalog_no', 'match_type' => 'prefix', 'pattern' => '', 'discount_percent' => 45],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('rules.0.pattern');

        $this->putJson("/api/b2b-accounts/{$this->account->id}/discount-rules", [
            'rules' => [
                ['name' => 'Pozostałe', 'match_field' => 'catalog_no', 'match_type' => 'any', 'pattern' => '', 'discount_percent' => 20],
            ],
        ])->assertOk()->assertJsonCount(1, 'rules');
    }

    public function test_rabat_poza_zakresem_jest_odrzucany(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson("/api/b2b-accounts/{$this->account->id}/discount-rules", [
            'rules' => [
                ['name' => 'Za duży', 'match_field' => 'catalog_no', 'match_type' => 'prefix', 'pattern' => 'BW', 'discount_percent' => 120],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('rules.0.discount_percent');
    }

    public function test_licznik_trafien_przezywa_zmiane_nazwy_i_kolejnosci(): void
    {
        // Po zmianie nazwy reguły panel ma dalej pokazywać, ile kart ona łapie — inaczej wyglądałoby to
        // na regułę, która przestała działać, i kusiło do zbędnych zmian w cenniku.
        $rule = B2bDiscountRule::query()->create([
            'b2b_account_id' => $this->account->id,
            'position' => 0,
            'name' => 'Amortyzatory',
            'match_field' => 'catalog_no',
            'match_type' => 'prefix',
            'pattern' => 'BW',
            'discount_percent' => 45,
        ]);
        $rule->forceFill(['last_matched_count' => 137, 'last_matched_at' => now()])->saveQuietly();

        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson("/api/b2b-accounts/{$this->account->id}/discount-rules", [
            'rules' => [
                ['name' => 'Linki bezpieczeństwa', 'match_field' => 'catalog_no', 'match_type' => 'prefix', 'pattern' => 'LB', 'discount_percent' => 45],
                ['name' => 'Amortyzatory ABM+BW', 'match_field' => 'catalog_no', 'match_type' => 'prefix', 'pattern' => 'BW', 'discount_percent' => 45],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('rules.0.last_matched_count', 0)
            ->assertJsonPath('rules.1.last_matched_count', 137);
    }

    public function test_polecenie_zaklada_tabele_upustow_protekt(): void
    {
        $this->artisan('b2b:protekt-discounts', ['account' => $this->account->id])->assertSuccessful();

        $rules = $this->account->discountRules()->get();
        $this->assertGreaterThan(30, $rules->count());
        // Kolejność jest znaczeniem listy: CR200 (10%) musi być sprawdzane przed regułą na całą kategorię.
        $cr200 = $rules->firstWhere('pattern', 'CR200');
        $samohamowne = $rules->firstWhere('pattern', 'urzadzenia-samohamowne');
        $this->assertNotNull($cr200);
        $this->assertNotNull($samohamowne);
        $this->assertLessThan($samohamowne->position, $cr200->position);
        // Bez reguły „wszystko” karta spoza tabeli zostaje pominięta, zamiast dostać przypadkową stawkę.
        $this->assertFalse($rules->contains(fn ($r): bool => $r->match_type === B2bDiscountRule::TYPE_ANY));
    }

    public function test_polecenie_nie_nadpisuje_listy_bez_force(): void
    {
        $this->artisan('b2b:protekt-discounts', ['account' => $this->account->id])->assertSuccessful();
        $this->artisan('b2b:protekt-discounts', ['account' => $this->account->id])->assertFailed();
        $this->artisan('b2b:protekt-discounts', ['account' => $this->account->id, '--force' => true])->assertSuccessful();
    }

    public function test_polecenie_odrzuca_konto_innego_dostawcy(): void
    {
        $other = B2bAccount::query()->create([
            'username' => 'jan',
            'password' => 'x',
            'sites' => ['b2b.anro.net.pl'],
            'connector' => 'anro',
        ]);

        $this->artisan('b2b:protekt-discounts', ['account' => $other->id])->assertFailed();
        $this->assertSame(0, $other->discountRules()->count());
    }

    public function test_uprawnienia(): void
    {
        $viewer = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($viewer);

        $read = $this->getJson("/api/b2b-accounts/{$this->account->id}/discount-rules");
        if ($read->status() === 403) {
            // Rola bez dostępu do kont B2B nie widzi też rabatów — to też poprawny wynik.
            $this->putJson("/api/b2b-accounts/{$this->account->id}/discount-rules", ['rules' => []])->assertForbidden();

            return;
        }

        $read->assertOk();
        $this->putJson("/api/b2b-accounts/{$this->account->id}/discount-rules", ['rules' => []])->assertForbidden();
    }
}
