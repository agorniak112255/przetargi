<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderInvitation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Jedno pole wyszukiwania (GET /api/search?q=): grupy tylko z uprawnieniami, żadnych cen, stan z XL tylko
 * z inventory.view, cudze zapytania tylko z inquiries.view_others, treść maila tylko z 90 dni, 5 wyników + has_more.
 */
final class GlobalSearchApiTest extends TestCase
{
    use RefreshDatabase;

    private int $gid = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-03 07:00:00', 'UTC'));
    }

    public function test_groups_appear_only_with_their_permissions_in_fixed_order(): void
    {
        Sanctum::actingAs($this->userWith([]));
        $this->search('rękawice')->assertOk()->assertExactJson(['query' => 'rękawice', 'groups' => []]);

        Sanctum::actingAs($this->userWith(['clients.view']));
        $this->assertSame(['clients'], $this->keys('rękawice'));

        Sanctum::actingAs($this->userWith(['tenders.view_own', 'products.view']));
        $this->assertSame(['products', 'tenders'], $this->keys('rękawice'));

        Sanctum::actingAs($this->userWith(['clients.view', 'inquiries.use', 'tenders.view_all', 'products.view']));
        $response = $this->search('rękawice');
        $this->assertSame(['products', 'tenders', 'inquiries', 'clients'], array_column($response->json('groups'), 'key'));
        $this->assertSame(['Produkty', 'Przetargi', 'Zapytania', 'Klienci'], array_column($response->json('groups'), 'label'));
        $this->assertSame(
            ['/products?q=r%C4%99kawice', null, '/inquiries?q=r%C4%99kawice', null],
            array_column($response->json('groups'), 'more_url'),
        );
    }

    public function test_show_all_inquiries_link_never_shows_less_than_the_search(): void
    {
        $moreUrl = fn (): ?string => collect($this->search('rękawice')->assertOk()->json('groups'))->firstWhere('key', 'inquiries')['more_url'];

        // lista wszystkich osób — parametr zakresu listy zapytań to scope=all
        Sanctum::actingAs($this->userWith(['inquiries.use', 'inquiries.view_all', 'inquiries.view_others']));
        $this->assertSame('/inquiries?q=r%C4%99kawice&scope=all', $moreUrl());
        Sanctum::actingAs($this->userWith(['inquiries.use', 'inquiries.view_all']));
        $this->assertSame('/inquiries?q=r%C4%99kawice&scope=all', $moreUrl());

        // wyszukiwanie znajduje cudze (view_others), a lista bez view_all pokazałaby tylko własne — bez linku
        Sanctum::actingAs($this->userWith(['inquiries.use', 'inquiries.view_others']));
        $this->assertNull($moreUrl());

        Sanctum::actingAs($this->userWith(['inquiries.use']));
        $this->assertSame('/inquiries?q=r%C4%99kawice', $moreUrl());
    }

    public function test_query_length_is_validated_after_trimming(): void
    {
        Sanctum::actingAs($this->userWith(['products.view']));

        $this->getJson('/api/search')->assertStatus(422)->assertJsonValidationErrors('q');
        $this->search('a')->assertStatus(422)->assertJsonValidationErrors('q');
        $this->search('  a  ')->assertStatus(422)->assertJsonValidationErrors('q');
        $this->search(str_repeat('ż', 101))->assertStatus(422)->assertJsonValidationErrors('q');
        $this->search(str_repeat('ż', 100))->assertOk();
        $this->search(' ab ')->assertOk()->assertJsonPath('query', 'ab');
    }

    public function test_no_price_or_value_fields_anywhere(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $product = $this->card('9322+', 'Półmaska Aura 9322+ FFP2', '3M');
        $product->forceFill(['catalog_price_net' => 7654.32, 'purchase_price' => 6543.21, 'discount_percent' => 12.5])->save();
        $this->link($this->item('MAS9322', 1840, 'szt'), $product, ErpItemLink::STATUS_AUTO);
        $tender = $this->tender($admin, 'wycena', 'Szpital 9322', 'Półmaski 9322');
        $tender->forceFill(['offer_value_net' => 98765.43, 'margin_percent' => 17.25])->save();
        $tender->items()->first()->forceFill(['offer_price' => 5432.1])->save();
        $this->inquiry($admin, 'Półmaski 9322 — prośba o ofertę', 'Proszę o cenę 9322, budżet 4321,09 zł');
        Client::query()->create(['name' => 'Ciepłownia 9322', 'sales_net' => 87654.32]);

        Sanctum::actingAs($admin);
        $response = $this->search('9322')->assertOk();
        $groups = $response->json('groups');
        $this->assertSame(['products', 'tenders', 'inquiries', 'clients'], array_column($groups, 'key'));
        foreach ($groups as $group) {
            $this->assertNotEmpty($group['items'], $group['key']);
        }

        $keys = [];
        $collect = static function (array $node) use (&$collect, &$keys): void {
            foreach ($node as $key => $value) {
                $keys[] = (string) $key;
                if (is_array($value)) {
                    $collect($value);
                }
            }
        };
        $collect($groups);
        $this->assertContains('stock', $keys, 'Administrator widzi stan — sprawdzamy też jego pola.');
        foreach (array_unique($keys) as $key) {
            $this->assertDoesNotMatchRegularExpression('/price|net|value|margin|cost|amount|purchase|discount|sales/i', $key, "Pole {$key} wygląda na cenę.");
        }
        $body = (string) $response->getContent();
        foreach (['7654', '6543', '98765', '5432', '87654', '17.25', '12.5'] as $amount) {
            $this->assertStringNotContainsString($amount, $body, "Kwota {$amount} w wyniku.");
        }
    }

    public function test_stock_from_erp_only_with_inventory_permission_and_one_unit(): void
    {
        $single = $this->card('A-1', 'Rękawice nitrylowe A', 'Ansell');
        $this->link($this->item('ARK1', 1840, 'szt'), $single, ErpItemLink::STATUS_AUTO);
        $this->link($this->item('ARK2', 10.5, 'szt.'), $single, ErpItemLink::STATUS_CONFIRMED);
        // propozycja i towar usunięty z XL się nie liczą
        $this->link($this->item('ARK3', 500, 'szt'), $single, ErpItemLink::STATUS_SUGGESTED);
        $removed = $this->item('ARK4', 700, 'szt');
        $removed->update(['removed_at' => now()]);
        $this->link($removed, $single, ErpItemLink::STATUS_AUTO);

        $mixed = $this->card('B-1', 'Rękawice nitrylowe B', 'Ansell');
        $this->link($this->item('ARK5', 5, 'szt'), $mixed, ErpItemLink::STATUS_AUTO);
        $this->link($this->item('ARK6', 2, 'opk'), $mixed, ErpItemLink::STATUS_AUTO);
        $unlinked = $this->card('C-1', 'Rękawice nitrylowe C', 'Ansell');

        Sanctum::actingAs($this->userWith(['products.view']));
        foreach ($this->items('nitrylowe', 'products') as $hit) {
            $this->assertArrayNotHasKey('stock', $hit, 'Bez inventory.view nie ma stanu.');
        }

        Sanctum::actingAs($this->userWith(['products.view', 'inventory.view']));
        $hits = collect($this->items('nitrylowe', 'products'))->keyBy('id');
        $this->assertSame(['quantity' => '1850.5', 'unit' => 'szt'], $hits[$single->id]['stock']);
        $this->assertNull($hits[$mixed->id]['stock'], 'Różne jednostki — bez sumy.');
        $this->assertNull($hits[$unlinked->id]['stock']);
        $this->assertSame('kod produktu A-1 · Ansell', $hits[$single->id]['subtitle']);
        $this->assertSame('/products/'.$single->id, $hits[$single->id]['url']);
    }

    public function test_products_match_the_product_list_including_erp_code(): void
    {
        $pheos = $this->card('9198.014', 'Okulary Pheos CX2', 'UVEX');
        $this->card('A-1', 'Etykieta SOK9198014 zapas', 'UVEX');
        $this->card('B-1', 'Kurtka', 'PROS');
        $this->link($this->item('SOK9198014', 0, 'szt'), $pheos, ErpItemLink::STATUS_AUTO);

        Sanctum::actingAs($this->userWith(['products.view']));
        $hits = $this->items('sok9198014', 'products');

        $this->assertSame([$pheos->id], [$hits[0]['id']], 'Karta wskazana kodem XL pierwsza, jak na liście produktów.');
        $this->assertSame('kod w ERP XL: SOK9198014', $hits[0]['detail']);
        $this->assertSame(2, count($hits));
        $this->assertNull($hits[1]['detail']);
    }

    public function test_other_peoples_inquiries_only_with_view_others(): void
    {
        $me = $this->userWith(['inquiries.use']);
        $colleague = User::factory()->create(['name' => 'Anna Nowak']);
        $mine = $this->inquiry($me, 'Rękawice nitrylowe — zapytanie', 'Treść');
        $theirs = $this->inquiry($colleague, 'Rękawice nitrylowe dla szpitala', 'Treść');

        Sanctum::actingAs($me);
        $this->assertSame([$mine->id], array_column($this->items('nitrylowe', 'inquiries'), 'id'));

        // samo view_all (lista cudzych) nie wystarcza — otwarcie cudzego wymaga view_others
        $lister = $this->userWith(['inquiries.use', 'inquiries.view_all']);
        $this->inquiry($lister, 'Inne', 'Treść');
        Sanctum::actingAs($lister);
        $this->assertSame([], $this->items('nitrylowe', 'inquiries'));

        $manager = $this->userWith(['inquiries.use', 'inquiries.view_others']);
        Sanctum::actingAs($manager);
        $hits = collect($this->items('nitrylowe', 'inquiries'))->keyBy('id');
        $this->assertSame([$mine->id, $theirs->id], $hits->keys()->sort()->values()->all());
        $this->assertSame('Rękawice nitrylowe dla szpitala · prowadzi Anna Nowak', $hits[$theirs->id]['subtitle']);
        $this->assertSame('/inquiries/'.$theirs->id, $hits[$theirs->id]['url']);
        $this->assertSame('W przygotowaniu', $hits[$theirs->id]['badge']);
    }

    public function test_inquiry_body_is_searched_only_within_90_days_and_header_fields_without_limit(): void
    {
        $me = $this->userWith(['inquiries.use']);
        $recent = $this->inquiry($me, 'Zapytanie ofertowe', "Dzień dobry,\nproszę o półmaski typu 9322 lub równoważne, 400 sztuk.", now()->subDays(89));
        $old = $this->inquiry($me, 'Stare zapytanie', 'proszę o półmaski typu 9322', now()->subDays(91));
        $oldBySubject = $this->inquiry($me, 'Półmaski 9322 — dawno', 'bez numeru w treści', now()->subDays(400));
        $byCompany = $this->inquiry($me, 'Oferta', 'Treść', now()->subDays(400));
        $byCompany->forceFill(['contact' => ['company' => 'Ciepłownia Wisłok S.A.'], 'replied_at' => now()->subDays(399)])->save();

        Sanctum::actingAs($me);
        $hits = collect($this->items('9322', 'inquiries'))->keyBy('id');
        $this->assertSame([$recent->id, $oldBySubject->id], $hits->keys()->sort()->values()->all());
        $this->assertArrayNotHasKey($old->id, $hits->all(), 'Treść starsza niż 90 dni nie jest przeszukiwana.');
        $this->assertSame('„Dzień dobry, proszę o półmaski typu 9322 lub równoważne, 400 sztuk.”', $hits[$recent->id]['detail']);

        $company = $this->items('Wisłok', 'inquiries');
        $this->assertSame([$byCompany->id], array_column($company, 'id'));
        $this->assertSame('Ciepłownia Wisłok S.A.', $company[0]['title']);
        $this->assertSame('Wysłano', $company[0]['badge']);
    }

    public function test_five_items_per_group_with_has_more(): void
    {
        foreach (range(1, 6) as $i) {
            Client::query()->create(['name' => "Szpital Miejski nr {$i}", 'city' => 'Rzeszów']);
        }
        Sanctum::actingAs($this->userWith(['clients.view']));

        $group = $this->group('Szpital Miejski', 'clients');
        $this->assertCount(5, $group['items']);
        $this->assertTrue($group['has_more']);
        $this->assertNull($group['more_url']);
        $this->assertSame('Szpital Miejski nr 1', $group['items'][0]['title']);
        $this->assertSame('Rzeszów', $group['items'][0]['subtitle']);

        Client::query()->where('name', 'Szpital Miejski nr 6')->delete();
        $group = $this->group('Szpital Miejski', 'clients');
        $this->assertCount(5, $group['items']);
        $this->assertFalse($group['has_more']);
    }

    public function test_clients_by_acronym_city_and_nip_digits(): void
    {
        $client = Client::query()->create(['name' => 'Miejskie Przedsiębiorstwo Energetyki Cieplnej', 'acronym' => 'MPEC', 'nip' => '526-10-00-000', 'city' => 'Krosno']);
        Client::query()->create(['name' => 'Inny', 'nip' => '111-22-33-444', 'city' => 'Jasło']);
        Sanctum::actingAs($this->userWith(['clients.view']));

        $this->assertSame([$client->id], array_column($this->items('mpec', 'clients'), 'id'));
        $this->assertSame([$client->id], array_column($this->items('Krosno', 'clients'), 'id'));
        $this->assertSame([$client->id], array_column($this->items('5261000', 'clients'), 'id'));
        $this->assertSame([$client->id], array_column($this->items('526 10 00', 'clients'), 'id'));
        $this->assertSame('MPEC · Krosno · NIP 526-10-00-000', $this->items('mpec', 'clients')[0]['subtitle']);
        $this->assertSame('/clients/'.$client->id, $this->items('mpec', 'clients')[0]['url']);
    }

    public function test_tenders_only_accessible_and_items_from_three_characters_without_archive(): void
    {
        $me = $this->userWith(['tenders.view_own']);
        $other = User::factory()->create();
        $mine = $this->tender($me, 'wycena', 'Szpital Wojewódzki nr 2', 'Półmaska filtrująca FFP2 typu 9322');
        $invited = $this->tender($other, 'wycena', 'Gmina Tyczyn', 'Rękawice');
        TenderInvitation::query()->create(['tender_id' => $invited->id, 'user_id' => $me->id, 'invited_by' => $other->id]);
        $foreign = $this->tender($other, 'wycena', 'Szpital Cudzy', 'Półmaska filtrująca 9322');
        $archived = $this->tender($me, 'archiwum', 'Archiwalny', 'Półmaska 9322 stara');

        Sanctum::actingAs($me);
        $hits = $this->items('9322', 'tenders');
        $this->assertSame([$mine->id], array_column($hits, 'id'), 'Cudzy bez dostępu, archiwum nie po pozycjach.');
        $this->assertSame('pozycja 1: Półmaska filtrująca FFP2 typu 9322 · termin 5.10.2026, 10:00', $hits[0]['detail']);
        $this->assertSame('Wycena', $hits[0]['badge']);
        $this->assertSame($mine->number.' · Szpital Wojewódzki nr 2', $hits[0]['subtitle']);
        $this->assertSame('/tenders/'.$mine->id, $hits[0]['url']);

        // nazwa zamawiającego i numer — także w archiwum; zaproszenie widać
        $this->assertSame([$archived->id], array_column($this->items('Archiwalny', 'tenders'), 'id'));
        $this->assertSame([$invited->id], array_column($this->items('Tyczyn', 'tenders'), 'id'));
        $this->assertSame([$mine->id], array_column($this->items($mine->number, 'tenders'), 'id'));
        // dwa znaki — bez przeszukiwania pozycji
        $this->assertSame([], $this->items('93', 'tenders'));

        Sanctum::actingAs($this->userWith(['tenders.view_all']));
        $this->assertContains($foreign->id, array_column($this->items('9322', 'tenders'), 'id'));
    }

    private function search(string $q): TestResponse
    {
        return $this->getJson('/api/search?q='.rawurlencode($q));
    }

    /** @return list<string> */
    private function keys(string $q): array
    {
        return array_column($this->search($q)->assertOk()->json('groups'), 'key');
    }

    /** @return array<string, mixed> */
    private function group(string $q, string $key): array
    {
        $group = collect($this->search($q)->assertOk()->json('groups'))->firstWhere('key', $key);
        $this->assertNotNull($group, "Brak grupy {$key}.");

        return $group;
    }

    /** @return list<array<string, mixed>> */
    private function items(string $q, string $key): array
    {
        return $this->group($q, $key)['items'];
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::findOrCreate('search-'.Str::random(6), 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function card(string $sku, string $name, string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => $manufacturer,
            'catalog_price_net' => 10, 'purchase_price' => 5, 'stock' => 0,
        ]);
    }

    private function item(string $code, float $stock, string $unit): ErpItem
    {
        return ErpItem::query()->create([
            'xl_gid' => $this->gid++, 'code' => $code, 'name' => 'Towar XL', 'unit' => $unit, 'archived' => false,
            'stock_trade' => $stock, 'stock_total' => $stock, 'synced_at' => now(),
        ]);
    }

    private function link(ErpItem $item, Product $card, string $status): void
    {
        ErpItemLink::query()->create([
            'erp_item_id' => $item->id, 'product_id' => $card->id, 'status' => $status,
            'method' => ErpItemLink::METHOD_NAME, 'matched_value' => $item->code, 'last_seen_at' => now(),
        ]);
    }

    private function tender(User $owner, string $status, string $client, string $requirement): Tender
    {
        $tender = Tender::query()->create([
            'number' => 'PRZ/2026/'.Str::upper(Str::random(5)),
            'title' => 'Dostawa środków ochrony',
            'client_id' => Client::query()->create(['name' => $client])->id,
            'owner_id' => $owner->id,
            'status' => $status,
            'deadline' => '2026-10-05',
            'deadline_time' => '10:00',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        $tender->items()->create(['line_no' => 1, 'requirement' => $requirement]);

        return $tender;
    }

    private function inquiry(User $user, string $subject, string $body, ?\DateTimeInterface $createdAt = null): ClientInquiry
    {
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id, 'tone' => 'formal', 'source_subject' => $subject, 'source_body' => $body,
        ]);
        if ($createdAt !== null) {
            $inquiry->forceFill(['created_at' => $createdAt])->save();
        }

        return $inquiry;
    }
}
