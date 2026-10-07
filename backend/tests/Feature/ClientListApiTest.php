<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Zakładka Klienci: GET /clients?page=… — strona listy, wyszukiwanie, filtry i sortowanie po stronie serwera. */
final class ClientListApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['name' => 'Anna Handlowa']);
        Sanctum::actingAs($this->userWith(['clients.view']));
    }

    public function test_pages_carry_full_rows_and_meta_and_summary_from_the_whole_list(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->xlClient(['name' => sprintf('Firma %02d', $i), 'sales_net' => $i * 100, 'city' => $i % 2 ? 'Rzeszów' : 'RZESZÓW']);
        }
        $this->manual(['name' => 'Ręczny', 'city' => 'Rzeszow']);

        $res = $this->getJson('/api/clients?page=2&per_page=25')->assertOk();

        $this->assertSame(['current_page' => 2, 'last_page' => 2, 'per_page' => 25, 'total' => 31], $res->json('meta'));
        // domyślnie zakupy malejąco, klient bez zakupów na końcu
        $this->assertSame(['Firma 05', 'Firma 04', 'Firma 03', 'Firma 02', 'Firma 01', 'Ręczny'], array_column($res->json('data'), 'name'));
        $row = $res->json('data.0');
        $this->assertSame(['500.00', 0, 'Rzeszów'], [$row['sales_net'], $row['tenders_count'], $row['city']]);
        $this->assertArrayHasKey('contacts', $row);
        $this->assertSame(31, $res->json('summary.total'));
        $this->assertSame(30, $res->json('summary.xl'));
        $this->assertSame(2026, $res->json('summary.sales_year'));
        // trzy zapisy tej samej miejscowości to jedna opcja filtra, opisana pisownią z polskimi znakami
        $this->assertSame([['value' => 'RZESZOW', 'label' => 'Rzeszów', 'count' => 31]], $res->json('summary.cities'));
    }

    public function test_search_finds_polish_contact_names_emails_and_nip_with_dashes(): void
    {
        $this->xlClient(['name' => 'Alfa', 'nip' => '813-000-00-01', 'contacts' => [['name' => 'Łukasz Żółć', 'mobile' => '600 100 200']]]);
        $this->xlClient(['name' => 'Beta', 'emails' => ['zakupy@beta-bhp.pl']]);
        $this->xlClient(['name' => 'Gamma Łódź', 'city' => 'Łódź']);

        $this->assertSame(['Alfa'], $this->names('q=łukasz'));
        $this->assertSame(['Alfa'], $this->names('q='.urlencode('ŁUKASZ żółć')));
        $this->assertSame(['Alfa'], $this->names('q=8130000001'));
        $this->assertSame(['Alfa'], $this->names('q=600+100'));
        $this->assertSame(['Beta'], $this->names('q=beta-bhp'));
        // każde słowo musi być w danych klienta
        $this->assertSame([], $this->names('q='.urlencode('gamma warszawa')));
        $this->assertSame(['Gamma Łódź'], $this->names('q='.urlencode('gamma łódź')));
    }

    public function test_source_city_and_manager_filters(): void
    {
        $this->xlClient(['name' => 'XL z opiekunem', 'account_manager' => 'Jan Opiekun', 'city' => 'Kraków']);
        $this->xlClient(['name' => 'XL bez opiekuna', 'city' => 'KRAKOW']);
        $this->manual(['name' => 'Ręczny z opiekunem w aplikacji', 'owner_id' => $this->owner->id, 'city' => 'Rzeszów']);

        $this->assertSame(['Ręczny z opiekunem w aplikacji'], $this->names('source=manual'));
        $this->assertSame(['XL bez opiekuna', 'XL z opiekunem'], $this->names('source=xl&sort=name&dir=asc'));
        $this->assertSame(['XL bez opiekuna', 'XL z opiekunem'], $this->names('city=KRAKOW&sort=name&dir=asc'));
        $this->assertSame(['XL bez opiekuna'], $this->names('manager=__none'));
        $this->assertSame(['Ręczny z opiekunem w aplikacji'], $this->names('manager='.urlencode('Anna Handlowa')));

        $summary = $this->getJson('/api/clients?page=1&manager=__none')->assertOk()->json('summary');
        // opcje i liczniki z całej listy, nie z wyniku filtra
        $this->assertSame(1, $summary['without_manager']);
        $this->assertSame(
            [['value' => 'Anna Handlowa', 'label' => 'Anna Handlowa', 'count' => 1], ['value' => 'Jan Opiekun', 'label' => 'Jan Opiekun', 'count' => 1]],
            $summary['managers'],
        );
        $this->assertSame(['KRAKOW', 'RZESZOW'], array_column($summary['cities'], 'value'));
    }

    public function test_sorting_keeps_empty_values_last_in_both_directions(): void
    {
        $this->xlClient(['name' => 'B', 'account_manager' => 'Zenon', 'last_sale_at' => '2026-09-01']);
        $this->xlClient(['name' => 'A', 'last_sale_at' => null]);
        $this->manual(['name' => 'C', 'owner_id' => $this->owner->id]);
        Tender::query()->create([
            'number' => 'PRZ/KL/1', 'title' => 'Dostawa rękawic', 'status' => 'wycena',
            'client_id' => Client::query()->where('name', 'A')->value('id'),
        ]);

        $this->assertSame(['C', 'B', 'A'], $this->names('sort=manager&dir=asc'));
        $this->assertSame(['B', 'C', 'A'], $this->names('sort=manager&dir=desc'));
        $this->assertSame(['B', 'A', 'C'], $this->names('sort=last_sale&dir=asc'));
        $this->assertSame(['A', 'B', 'C'], $this->names('sort=tenders&dir=desc'));
        $this->assertSame(['C', 'B', 'A'], $this->names('sort=name&dir=desc'));
    }

    public function test_page_past_the_end_is_empty_and_bad_parameters_are_rejected(): void
    {
        $this->xlClient(['name' => 'Jedyny']);

        $res = $this->getJson('/api/clients?page=5')->assertOk();
        $this->assertSame([], $res->json('data'));
        $this->assertSame(1, $res->json('meta.last_page'));

        $this->getJson('/api/clients?page=1&sort=password')->assertStatus(422)->assertJsonPath('errors.sort.0', 'Nieznana wartość filtra albo sortowania.');
        $this->getJson('/api/clients?page=1&per_page=1000')->assertStatus(422);
        $this->getJson('/api/clients?page=0')->assertStatus(422);
    }

    /** @return list<string> */
    private function names(string $query): array
    {
        return array_column($this->getJson('/api/clients?page=1&'.$query)->assertOk()->json('data'), 'name');
    }

    /** @param  array<string, mixed>  $attributes */
    private function xlClient(array $attributes): Client
    {
        static $gid = 100;

        return Client::query()->create([
            'source' => Client::SOURCE_ERP_XL, 'xl_gid' => ++$gid, 'sales_year' => 2026, 'sales_net' => 0, ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function manual(array $attributes): Client
    {
        return Client::query()->create(['source' => Client::SOURCE_MANUAL, ...$attributes]);
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::findOrCreate('cl-'.Str::random(6), 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
