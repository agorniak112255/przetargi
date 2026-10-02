<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EmailSuppression;
use App\Models\ErpCustomerItem;
use App\Models\ErpItemLink;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CampaignFixtures;
use Tests\TestCase;

/**
 * GET /api/reports/customers — R5 „Klienci” z ERP XL. Teraz = 02.10.2026 12:00 w Warszawie:
 * 3 mies. = 2026-07-02, 6 = 2026-04-02, 12 = 2025-10-02, 24 = 2024-10-02.
 */
final class ReportCustomersApiTest extends TestCase
{
    use CampaignFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Europe/Warsaw'));
        $this->setUpCampaigns();
    }

    public function test_requires_campaigns_use_besides_reports_view(): void
    {
        Sanctum::actingAs($this->userWith(['reports.view']));
        $this->getJson('/api/reports/customers')->assertForbidden();

        Sanctum::actingAs($this->userWith(['campaigns.use']));
        $this->getJson('/api/reports/customers')->assertForbidden();

        Sanctum::actingAs($this->userWith(['reports.view', 'campaigns.use']));
        $this->getJson('/api/reports/customers')
            ->assertOk()
            ->assertJsonPath('synced_at', null)
            ->assertJsonPath('totals', [
                'customers' => 0, 'archived' => 0, 'buying_24m' => 0, 'active_6m' => 0,
                'dormant_6_12' => 0, 'lapsing_12_24' => 0, 'reachable_active_12m' => 0,
            ])
            ->assertJsonPath('operators', [])
            ->assertJsonPath('cities', [])
            ->assertJsonPath('top_customers', [])
            ->assertJsonPath('top_items', []);
    }

    public function test_activity_reach_operators_cities_and_tops(): void
    {
        $jan = $this->userWith(['reports.view', 'campaigns.use'], 'Jan Kowalski');
        $jan->forceFill(['erp_operator_ident' => 'jan'])->save();
        EmailSuppression::query()->create(['email' => 'wypisany@gamma.pl', 'reason' => 'unsubscribe']);

        $alfa = $this->customer('ALFA', ['zakupy@alfa.pl'], [], ['last_sale_at' => '2026-09-15', 'sale_documents_24m' => 10, 'city' => 'Rzeszów', 'main_operator' => 'JAN']);
        // tylko adres ogólny (faktury@) — aktywny, ale nieosiągalny
        $beta = $this->customer('BETA', ['faktury@beta.pl'], [], ['last_sale_at' => '2026-05-01', 'sale_documents_24m' => 3, 'city' => 'RZESZÓW ', 'main_operator' => 'JAN']);
        // jedyny adres wypisany
        $gamma = $this->customer('GAMMA', ['wypisany@gamma.pl'], [], ['last_sale_at' => '2026-01-10', 'sale_documents_24m' => 1, 'city' => 'Rzeszów', 'main_operator' => 'ANNA']);
        $delta = $this->customer('DELTA', ['biuro@delta.pl'], [], ['last_sale_at' => '2025-06-01', 'sale_documents_24m' => 2, 'city' => 'Kraków', 'main_operator' => null]);
        $this->customer('EPS', [], [], ['last_sale_at' => null, 'sale_documents_24m' => 0, 'city' => 'Kraków', 'emails' => null]);
        $archived = $this->customer('ARCH', ['arch@arch.pl'], [], ['archived' => true, 'last_sale_at' => '2026-09-01', 'sale_documents_24m' => 50, 'city' => 'Rzeszów', 'main_operator' => 'JAN']);
        $removed = $this->customer('USUN', ['usun@usun.pl'], [], ['removed_at' => now(), 'last_sale_at' => '2026-09-01', 'sale_documents_24m' => 60, 'city' => 'Rzeszów', 'main_operator' => 'JAN']);
        // granica 3 mies. (włącznie), adres pisany wielkimi literami ze spacją, operator z małych liter i spacji
        $zeta = $this->customer('ZETA', ['ZETA@zeta.pl ', 'faktury@zeta.pl'], [], ['last_sale_at' => '2026-07-02', 'sale_documents_24m' => 7, 'city' => 'Kraków', 'main_operator' => ' jan ']);
        // dzień przed granicą 6 mies. — uśpiony 6–12
        $omega = $this->customer('OMEGA', [], [], ['last_sale_at' => '2026-04-01', 'sale_documents_24m' => 1, 'city' => null, 'emails' => null, 'main_operator' => 'ANNA']);

        $boots = $this->erpItem('B1');
        $gloves = $this->erpItem('B2');
        $gone = $this->erpItem('B3', 1, ['removed_at' => now()]);
        $bought = static function ($customer, $item, int $documents): void {
            ErpCustomerItem::query()->create(['erp_customer_id' => $customer->id, 'erp_item_id' => $item->id, 'last_sale_at' => '2026-09-01', 'documents' => $documents, 'quantity' => 1]);
        };
        $bought($alfa, $boots, 4);
        $bought($alfa, $gloves, 2);
        $bought($alfa, $gone, 9);
        $bought($beta, $boots, 1);
        $bought($archived, $boots, 30);
        $bought($archived, $gloves, 30);
        $bought($removed, $gloves, 30);
        // powiązania: propozycja się nie liczy, potwierdzone przed automatycznym (mimo późniejszego id)
        $suggested = $this->card('S-1', 'Propozycja', false);
        $auto = $this->card('A-1', 'Automat', false);
        $confirmed = $this->card('C-1', 'Potwierdzona', false);
        $link = static fn ($item, $product, string $status) => ErpItemLink::query()->create(['erp_item_id' => $item->id, 'product_id' => $product->id, 'status' => $status, 'method' => 'name']);
        $link($boots, $suggested, ErpItemLink::STATUS_SUGGESTED);
        $link($boots, $auto, ErpItemLink::STATUS_AUTO);
        $link($boots, $confirmed, ErpItemLink::STATUS_CONFIRMED);
        $link($gloves, $auto, ErpItemLink::STATUS_REJECTED);

        Sanctum::actingAs($jan);
        $json = $this->getJson('/api/reports/customers')->assertOk()->json();

        $this->assertSame('2026-10-02T10:00:00+00:00', $json['synced_at']);
        $this->assertSame([
            'customers' => 7, 'archived' => 1, 'buying_24m' => 6, 'active_6m' => 3,
            'dormant_6_12' => 2, 'lapsing_12_24' => 1, 'reachable_active_12m' => 2,
        ], $json['totals']);
        $this->assertSame([
            ['label' => '0–3 mies.', 'from_months' => 0, 'to_months' => 3, 'customers' => 2],
            ['label' => '3–6 mies.', 'from_months' => 3, 'to_months' => 6, 'customers' => 1],
            ['label' => '6–9 mies.', 'from_months' => 6, 'to_months' => 9, 'customers' => 2],
            ['label' => '9–12 mies.', 'from_months' => 9, 'to_months' => 12, 'customers' => 0],
            ['label' => '12–18 mies.', 'from_months' => 12, 'to_months' => 18, 'customers' => 1],
            ['label' => '18–24 mies.', 'from_months' => 18, 'to_months' => 24, 'customers' => 0],
        ], $json['recency']);
        $this->assertSame([
            ['operator' => 'JAN', 'name' => 'Jan Kowalski', 'active_6m' => 3, 'dormant_6_12' => 0, 'lapsing_12_24' => 0, 'reachable_active_12m' => 2],
            ['operator' => 'ANNA', 'name' => null, 'active_6m' => 0, 'dormant_6_12' => 2, 'lapsing_12_24' => 0, 'reachable_active_12m' => 0],
            ['operator' => '(brak)', 'name' => null, 'active_6m' => 0, 'dormant_6_12' => 0, 'lapsing_12_24' => 1, 'reachable_active_12m' => 0],
        ], $json['operators']);
        // „Rzeszów” / „RZESZÓW ” scalone, wyświetlana najczęstsza pisownia; archiwalni i usunięci poza liczbą
        $this->assertSame([['city' => 'Rzeszów', 'customers' => 3], ['city' => 'Kraków', 'customers' => 1]], $json['cities']);

        $this->assertSame([$alfa->id, $zeta->id, $beta->id, $delta->id, $gamma->id, $omega->id], array_column($json['top_customers'], 'id'));
        $this->assertSame([
            'id' => $alfa->id, 'acronym' => 'ALFA', 'name' => 'Firma ALFA', 'city' => 'Rzeszów',
            'documents_24m' => 10, 'items_24m' => 3, 'last_sale_at' => '2026-09-15',
        ], $json['top_customers'][0]);

        $this->assertSame([
            ['erp_item_id' => $boots->id, 'code' => 'B1', 'name' => 'Towar B1', 'customers' => 2, 'documents' => 5, 'product_id' => $confirmed->id],
            ['erp_item_id' => $gloves->id, 'code' => 'B2', 'name' => 'Towar B2', 'customers' => 1, 'documents' => 2, 'product_id' => null],
        ], $json['top_items']);
    }

    public function test_city_spellings_without_polish_marks_merge_into_one_city(): void
    {
        Sanctum::actingAs($this->userWith(['reports.view', 'campaigns.use']));
        $this->customer('K1', [], [], ['city' => 'Krakow', 'last_sale_at' => '2026-09-01']);
        $this->customer('K2', [], [], ['city' => 'KRAKÓW', 'last_sale_at' => '2026-09-01']);
        $this->customer('K3', [], [], ['city' => 'Kraków ', 'last_sale_at' => '2026-09-01']);
        // skrótu nie rozwijamy — osobne miasto
        $this->customer('G1', [], [], ['city' => 'GŁOGÓW MŁP.', 'last_sale_at' => '2026-09-01']);
        $this->customer('G2', [], [], ['city' => 'Głogów Małopolski', 'last_sale_at' => '2026-09-01']);

        // remis po 1: wygrywa zapis z polskimi znakami, potem nie w całości wielkimi literami
        $this->getJson('/api/reports/customers')->assertOk()->assertJsonPath('cities', [
            ['city' => 'Kraków', 'customers' => 3],
            ['city' => 'GŁOGÓW MŁP.', 'customers' => 1],
            ['city' => 'Głogów Małopolski', 'customers' => 1],
        ]);
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions, string $name = 'Użytkownik'): User
    {
        $role = Role::findOrCreate('rep-'.Str::random(6), 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($role);

        return $user;
    }
}
