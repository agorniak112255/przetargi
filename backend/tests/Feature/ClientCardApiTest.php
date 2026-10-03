<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\Product;
use App\Models\Tender;
use App\Models\User;
use App\Services\Erp\ErpClientDocumentSync;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Karta klienta GET /clients/{client}: opiekun w ERP XL i w aplikacji osobno, przypisanie, kafelki z kopii dokumentów
 * XL (przed pierwszym odczytem — z zakładki Klienci), liczby tylko z uprawnieniem, „Najczęściej kupuje”; zmiana
 * opiekuna w aplikacji (PATCH owner_id, clients.manage).
 */
final class ClientCardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // piątek 2.10.2026, 12:00 w Polsce
        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00', 'Europe/Warsaw'));
    }

    public function test_card_shows_both_managers_assignment_and_tiles_from_xl_documents(): void
    {
        $salesperson = User::factory()->create(['name' => 'Piotr Wiśniewski']);
        $salesperson->forceFill(['erp_employee_gid' => 77])->save();
        $appOwner = User::factory()->create(['name' => 'Anna Panel']);
        $client = $this->xlClient([
            'account_manager' => 'Piotr Wiśniewski', 'account_manager_email' => 'piotr@supon.example.pl', 'xl_manager_gid' => 77,
            'owner_id' => $appOwner->id, 'sales_year' => 2026, 'sales_net' => 123.45, 'last_sale_at' => '2026-09-01',
        ]);
        $this->document($client, 1, '2026-09-22', 6240.00, 'invoice', 'FS-1842/09/2026');
        $this->document($client, 2, '2026-03-10', 1000.00, 'receipt', 'PA-11/03/2026');
        $this->document($client, 3, '2026-09-25', -240.00, 'invoice_correction', 'FSK-5/09/2026');
        // poprzedni rok: nie wchodzi do zakupów w roku, ale jest w ostatnich 12 miesiącach
        $this->document($client, 4, '2025-11-15', 500.00, 'invoice', 'FS-900/11/2025');
        $this->document($client, 5, '2025-09-15', 700.00, 'invoice', 'FS-800/09/2025');
        Cache::forever(ErpClientDocumentSync::SYNCED_AT_CACHE_KEY, '2026-10-02T03:40:00+00:00');

        Sanctum::actingAs($this->userWith(['clients.view']));
        $response = $this->getJson("/api/clients/{$client->id}")->assertOk();

        $response
            ->assertJsonPath('client.name', 'Ciepłownia Wisłok S.A.')
            ->assertJsonPath('client.xl_manager_gid', 77)
            ->assertJsonPath('client.owner.name', 'Anna Panel')
            ->assertJsonPath('xl_manager', ['name' => 'Piotr Wiśniewski', 'email' => 'piotr@supon.example.pl', 'user' => ['id' => $salesperson->id, 'name' => 'Piotr Wiśniewski']])
            ->assertJsonPath('app_owner', ['id' => $appOwner->id, 'name' => 'Anna Panel'])
            // pracownik XL zmapowany na konto ma pierwszeństwo przed opiekunem w aplikacji
            ->assertJsonPath('assignment', ['user' => ['id' => $salesperson->id, 'name' => 'Piotr Wiśniewski'], 'source' => 'xl'])
            // zakupy w roku z dokumentów (korekta pomniejsza), nie z clients.sales_net; korekta nie liczy się jako zakup
            ->assertJsonPath('tiles.sales_year', ['year' => 2026, 'net' => '7000.00', 'documents' => 2])
            ->assertJsonPath('tiles.last_sale', ['date' => '2026-09-22', 'document_number' => 'FS-1842/09/2026'])
            ->assertJsonPath('tiles.last_12m', ['invoices' => 3, 'inquiries' => null, 'tenders' => null, 'ordered_inquiries' => null])
            ->assertJsonPath('sections', ['inquiries' => false, 'tenders' => false, 'campaigns' => false])
            ->assertJsonPath('can_manage', false)
            ->assertJsonPath('documents_synced_at', '2026-10-02T03:40:00+00:00')
            ->assertJsonMissingPath('owner_options');
    }

    public function test_before_first_document_read_tiles_come_from_clients_tab_without_invoice_numbers(): void
    {
        $client = $this->xlClient(['sales_year' => 2026, 'sales_net' => 186420.50, 'sale_documents' => 12, 'last_sale_at' => '2026-09-22']);
        $manual = Client::query()->create(['name' => 'Ręczny']);

        Sanctum::actingAs($this->userWith(['clients.view']));
        $this->getJson("/api/clients/{$client->id}")->assertOk()
            ->assertJsonPath('documents_synced_at', null)
            ->assertJsonPath('tiles.sales_year', ['year' => 2026, 'net' => '186420.50', 'documents' => 12])
            ->assertJsonPath('tiles.last_sale', ['date' => '2026-09-22', 'document_number' => null])
            ->assertJsonPath('tiles.last_12m.invoices', 0)
            ->assertJsonPath('xl_manager', null)
            ->assertJsonPath('assignment', ['user' => null, 'source' => null])
            ->assertJsonPath('top_items', []);

        // klient ręczny bez numeru XL — zakupów z XL nie ma
        $this->getJson("/api/clients/{$manual->id}")->assertOk()
            ->assertJsonPath('tiles.sales_year', null)
            ->assertJsonPath('tiles.last_sale', null);
    }

    public function test_inquiry_and_tender_counts_follow_what_the_user_may_see(): void
    {
        $me = $this->userWith(['clients.view', 'inquiries.use', 'tenders.view_own']);
        $colleague = User::factory()->create();
        $client = $this->xlClient();

        // moje: oryginał i kopia u kolegi (jedna grupa), jedno zamówione, jedno sprzed 12 miesięcy
        $mine = $this->inquiry($me, $client, '2026-09-14 10:00', ['outcome' => 'partial']);
        $this->inquiry($colleague, $client, '2026-09-14 10:05', ['duplicate_of_id' => $mine->id]);
        $this->inquiry($me, $client, '2026-06-01 10:00');
        $this->inquiry($me, $client, '2025-09-01 10:00', ['outcome' => 'ordered']);
        // cudze — bez inquiries.view_all niewidoczne
        $this->inquiry($colleague, $client, '2026-08-01 10:00', ['outcome' => 'ordered']);
        // ten sam adres nadawcy, ale bez pewnego powiązania z klientem — nie liczy się
        $this->inquiry($me, null, '2026-08-02 10:00', ['source_from_email' => 'zaopatrzenie@wislok.example.pl']);

        $this->tender($me, $client, '2026-07-30');
        $this->tender($colleague, $client, '2026-07-31');
        $this->tender($me, $client, '2025-08-01');

        Sanctum::actingAs($me);
        $this->getJson("/api/clients/{$client->id}")->assertOk()
            ->assertJsonPath('tiles.last_12m.inquiries', 2)
            ->assertJsonPath('tiles.last_12m.ordered_inquiries', 1)
            ->assertJsonPath('tiles.last_12m.tenders', 1)
            ->assertJsonPath('sections', ['inquiries' => true, 'tenders' => true, 'campaigns' => false]);

        // z wglądem we wszystkie: kopia dalej jedna grupa, dochodzi zapytanie i przetarg kolegi
        Sanctum::actingAs($this->userWith(['clients.view', 'inquiries.use', 'inquiries.view_all', 'tenders.view_all']));
        $this->getJson("/api/clients/{$client->id}")->assertOk()
            ->assertJsonPath('tiles.last_12m.inquiries', 3)
            ->assertJsonPath('tiles.last_12m.ordered_inquiries', 2)
            ->assertJsonPath('tiles.last_12m.tenders', 2);
    }

    public function test_top_items_by_documents_with_card_only_for_one_certain_link(): void
    {
        $client = $this->xlClient();
        $customerId = DB::table('erp_customers')->insertGetId([
            'xl_gid' => 4100, 'acronym' => 'WISLOK', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $gloves = $this->erpItem(1, 'ANS58270', 'Ansell AlphaTec 58-270', 'para');
        $suit = $this->erpItem(2, 'TYV-CL', 'Kombinezon Tyvek Classic Xpert', 'szt');
        $mask = $this->erpItem(3, '9322', '3M Aura 9322+', 'szt');
        $this->customerItem($customerId, $gloves, 12, 1240, '2026-09-22');
        $this->customerItem($customerId, $suit, 30, 860, '2026-08-01');
        $this->customerItem($customerId, $mask, 12, 2400, '2026-07-01');

        $card = Product::query()->create(['sku' => 'A-58270', 'name' => 'Rękawice AlphaTec 58-270', 'manufacturer' => 'Ansell']);
        $other = Product::query()->create(['sku' => 'T-1', 'name' => 'Tyvek 500', 'manufacturer' => 'DuPont']);
        $second = Product::query()->create(['sku' => 'T-2', 'name' => 'Tyvek 600', 'manufacturer' => 'DuPont']);
        $this->link($gloves, $card, 'confirmed');
        // propozycja nie jest pewnym powiązaniem
        $this->link($mask, $other, 'suggested');
        // dwie karty — nie zgadujemy, która
        $this->link($suit, $other, 'auto');
        $this->link($suit, $second, 'auto');

        Sanctum::actingAs($this->userWith(['clients.view']));
        $items = $this->getJson("/api/clients/{$client->id}")->assertOk()->json('top_items');

        $this->assertSame(['Kombinezon Tyvek Classic Xpert', '3M Aura 9322+', 'Ansell AlphaTec 58-270'], array_column($items, 'name'));
        $this->assertSame([null, null, ['id' => $card->id, 'name' => 'Rękawice AlphaTec 58-270']], array_column($items, 'product'));
        $this->assertSame(['unit' => 'para', 'quantity' => '1240.000', 'documents' => 12, 'last_sale_at' => '2026-09-22'], array_intersect_key($items[2], array_flip(['quantity', 'unit', 'documents', 'last_sale_at'])));
    }

    public function test_manager_changes_app_owner_without_touching_other_fields(): void
    {
        $owner = User::factory()->create(['name' => 'Ewa Handlowiec']);
        $client = $this->xlClient(['nip' => '8130000001', 'city' => 'Dolina']);
        $manager = $this->userWith(['clients.view', 'clients.manage']);

        Sanctum::actingAs($manager);
        $this->getJson("/api/clients/{$client->id}")->assertOk()
            ->assertJsonPath('can_manage', true)
            ->assertJsonFragment(['id' => $owner->id, 'name' => 'Ewa Handlowiec']);

        $this->patchJson("/api/clients/{$client->id}", ['owner_id' => $owner->id])->assertOk()
            ->assertJsonPath('owner_id', $owner->id)
            ->assertJsonPath('owner.name', 'Ewa Handlowiec')
            ->assertJsonPath('nip', '8130000001')
            ->assertJsonPath('city', 'Dolina');
        $this->getJson("/api/clients/{$client->id}")->assertOk()
            ->assertJsonPath('app_owner', ['id' => $owner->id, 'name' => 'Ewa Handlowiec'])
            ->assertJsonPath('assignment', ['user' => ['id' => $owner->id, 'name' => 'Ewa Handlowiec'], 'source' => 'app']);

        $this->patchJson("/api/clients/{$client->id}", ['owner_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('owner_id');
        $this->patchJson("/api/clients/{$client->id}", ['owner_id' => null])->assertOk()->assertJsonPath('owner_id', null);
        $this->assertSame('Dolina', $client->fresh()->city);

        // bez clients.manage — odmowa
        Sanctum::actingAs($this->userWith(['clients.view']));
        $this->patchJson("/api/clients/{$client->id}", ['owner_id' => $owner->id])->assertForbidden();
        $this->assertNull($client->fresh()->owner_id);
    }

    public function test_unknown_client_is_not_found_and_card_needs_clients_view(): void
    {
        Sanctum::actingAs($this->userWith(['clients.view']));
        $this->getJson('/api/clients/999999')->assertNotFound();

        $client = $this->xlClient();
        Sanctum::actingAs($this->userWith(['inquiries.use']));
        $this->getJson("/api/clients/{$client->id}")->assertForbidden();
    }

    /** @param  array<string, mixed>  $extra */
    private function xlClient(array $extra = []): Client
    {
        return Client::query()->create([
            'name' => 'Ciepłownia Wisłok S.A.', 'source' => Client::SOURCE_ERP_XL, 'xl_gid' => 4100,
            'emails' => ['zaopatrzenie@wislok.example.pl'], ...$extra,
        ]);
    }

    private function document(Client $client, int $id, string $date, float $net, string $kind, string $number): void
    {
        DB::table('erp_sale_documents')->insert([
            'document_type' => (int) ([
                'invoice' => 2033, 'receipt' => 2034, 'export_invoice' => 2037, 'invoice_correction' => 2041, 'receipt_correction' => 2042,
            ][$kind]),
            'document_id' => $id, 'document_number' => $number, 'kind' => $kind, 'issued_at' => $date,
            'customer_xl_gid' => (int) $client->xl_gid, 'client_id' => $client->id, 'net_value' => $net,
            'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function inquiry(User $user, ?Client $client, string $sentAt, array $extra = []): ClientInquiry
    {
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'client_id' => $client?->id,
            'tone' => 'formal',
            'source_subject' => 'Zapytanie '.$sentAt,
            'source_body' => 'Proszę o ofertę.',
            'source_from_email' => 'zaopatrzenie@wislok.example.pl',
            'source_sent_at' => CarbonImmutable::parse($sentAt, 'Europe/Warsaw')->utc(),
            ...$extra,
        ]);
        $inquiry->forceFill(['client_link_source' => $client !== null ? 'email' : null, ...array_intersect_key($extra, array_flip(['outcome', 'duplicate_of_id']))])->save();

        return $inquiry;
    }

    private function tender(User $owner, Client $client, string $deadline): Tender
    {
        return Tender::query()->create([
            'number' => 'ZP/'.Str::random(6), 'title' => 'Obuwie robocze', 'client_id' => $client->id, 'owner_id' => $owner->id,
            'status' => 'wycena', 'deadline' => $deadline, 'ai_percent' => 0, 'last_activity_at' => now(),
        ]);
    }

    private function erpItem(int $gid, string $code, string $name, string $unit): int
    {
        return DB::table('erp_items')->insertGetId([
            'xl_gid' => $gid, 'code' => $code, 'name' => $name, 'unit' => $unit, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function customerItem(int $customerId, int $itemId, int $documents, float $quantity, string $lastSale): void
    {
        DB::table('erp_customer_items')->insert([
            'erp_customer_id' => $customerId, 'erp_item_id' => $itemId, 'documents' => $documents, 'quantity' => $quantity,
            'last_sale_at' => $lastSale, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function link(int $itemId, Product $product, string $status): void
    {
        DB::table('erp_item_links')->insert([
            'erp_item_id' => $itemId, 'product_id' => $product->id, 'status' => $status, 'method' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::findOrCreate('card-'.Str::random(6), 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
