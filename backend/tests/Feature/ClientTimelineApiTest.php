<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\ClientNote;
use App\Models\Tender;
use App\Models\TenderInvitation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Oś czasu karty klienta GET /clients/{client}/timeline: każda sekcja z własnym uprawnieniem, kopie tego samego maila
 * raz, tylko zapytania z pewnym powiązaniem, 24 miesiące, najwyżej 200 wpisów na źródło.
 */
final class ClientTimelineApiTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00', 'Europe/Warsaw'));
        $this->client = Client::query()->create([
            'name' => 'Ciepłownia Wisłok S.A.', 'source' => Client::SOURCE_ERP_XL, 'xl_gid' => 4100,
            'emails' => ['biuro@wislok.example.pl'], 'contacts' => [['name' => 'Anna Zaopatrzenie', 'email' => 'Anna@Wislok.example.pl']],
        ]);
    }

    public function test_card_only_user_sees_invoices_and_notes_newest_first_within_24_months(): void
    {
        $me = $this->userWith(['clients.view']);
        $author = User::factory()->create(['name' => 'Piotr Wiśniewski']);
        $this->document(1, '2026-09-22', 6240.00, 'FS-1842/09/2026');
        $this->document(2, '2024-10-05', 100.00, 'FS-1/10/2024');
        // sprzed 24 miesięcy — poza osią czasu
        $this->document(3, '2024-09-30', 100.00, 'FS-STARA');
        $note = $this->note($author, 'Rozmowa z kierowniczką zaopatrzenia: w listopadzie przetarg na odzież zimową.', '2026-10-01 09:00');
        $this->note($author, 'Stara notatka', '2024-09-01 09:00');
        // zapytanie, przetarg i kampania tego klienta istnieją, ale bez uprawnień do modułów ich nie widać
        $this->inquiry($author, $this->client, '2026-09-14 10:00');
        $this->tender($author, '2026-07-30');

        Sanctum::actingAs($me);
        $data = $this->getJson("/api/clients/{$this->client->id}/timeline")->assertOk()
            ->assertJsonPath('truncated', ['invoices' => false, 'notes' => false])
            ->json('data');

        $this->assertSame(['note', 'invoice', 'invoice'], array_column($data, 'type'));
        $this->assertSame([
            'type' => 'note', 'id' => $note->id, 'date' => $data[0]['date'],
            'body' => 'Rozmowa z kierowniczką zaopatrzenia: w listopadzie przetarg na odzież zimową.',
            'author' => ['id' => $author->id, 'name' => 'Piotr Wiśniewski'], 'remind_on' => null, 'can_edit' => false,
        ], $data[0]);
        $this->assertSame('2026-10-01', CarbonImmutable::parse($data[0]['date'])->setTimezone('Europe/Warsaw')->format('Y-m-d'));
        $this->assertSame(['date' => '2026-09-22', 'document_number' => 'FS-1842/09/2026', 'kind' => 'invoice', 'net_value' => '6240.00', 'confirmed_inquiry' => null], array_intersect_key($data[1], array_flip(['date', 'document_number', 'kind', 'net_value', 'confirmed_inquiry'])));

        // filtr sekcji bez uprawnienia — pusto, nie odmowa
        $this->getJson("/api/clients/{$this->client->id}/timeline?type=inquiries")->assertOk()->assertJsonPath('data', []);
        $this->getJson("/api/clients/{$this->client->id}/timeline?type=invoices")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/clients/{$this->client->id}/timeline?type=cokolwiek")->assertStatus(422);
    }

    public function test_copies_of_one_mail_appear_once_and_only_certainly_linked_inquiries_show(): void
    {
        $me = $this->userWith(['clients.view', 'inquiries.use', 'inquiries.view_all']);
        $colleague = User::factory()->create(['name' => 'Kolega']);

        $original = $this->inquiry($colleague, $this->client, '2026-09-14 10:00', ['replied_at' => CarbonImmutable::parse('2026-09-14 12:00', 'Europe/Warsaw')->utc()]);
        $myCopy = $this->inquiry($me, $this->client, '2026-09-14 10:03', ['duplicate_of_id' => $original->id, 'outcome' => 'partial']);
        // kopia kopii — dalej ta sama grupa
        $this->inquiry($colleague, $this->client, '2026-09-14 10:06', ['duplicate_of_id' => $myCopy->id]);
        // ten sam adres co na karcie i domena klienta, ale bez powiązania — nie zgadujemy
        $this->inquiry($me, null, '2026-09-20 10:00', ['source_from_email' => 'biuro@wislok.example.pl']);
        $this->inquiry($me, null, '2026-09-21 10:00', ['source_from_email' => 'ktos@wislok.example.pl']);
        // powiązane z innym klientem
        $this->inquiry($me, Client::query()->create(['name' => 'Inny']), '2026-09-22 10:00');
        // dokument potwierdzony przez handlowca jako zamówienie z mojej kopii
        $myCopy->forceFill(['outcome_document_number' => 'FS-1842/09/2026'])->save();
        $this->document(1, '2026-09-22', 6240.00, 'FS-1842/09/2026');

        Sanctum::actingAs($me);
        $data = $this->getJson("/api/clients/{$this->client->id}/timeline?type=all")->assertOk()->json('data');

        $inquiries = array_values(array_filter($data, static fn (array $e): bool => $e['type'] === 'inquiry'));
        $this->assertCount(1, $inquiries);
        // wpis grupy = mój wiersz; data — najwcześniejsza, odpowiedź — najwcześniejsza w grupie
        $this->assertSame($myCopy->id, $inquiries[0]['id']);
        $this->assertSame('2026-09-14T08:00:00+00:00', $inquiries[0]['date']);
        $this->assertSame('2026-09-14T10:00:00+00:00', $inquiries[0]['replied_at']);
        $this->assertSame('partial', $inquiries[0]['outcome']);
        $this->assertSame(['id' => $me->id, 'name' => $me->name], $inquiries[0]['user']);
        $this->assertTrue($inquiries[0]['can_open']);
        $this->assertSame('email', $inquiries[0]['link_source']);
        $this->assertSame(2, $inquiries[0]['items_count']);

        $invoice = array_values(array_filter($data, static fn (array $e): bool => $e['type'] === 'invoice'))[0];
        $this->assertSame(['id' => $myCopy->id, 'date' => '2026-09-14T08:03:00+00:00'], $invoice['confirmed_inquiry']);
    }

    public function test_inquiries_of_others_need_view_all_and_opening_needs_view_others(): void
    {
        $colleague = User::factory()->create(['name' => 'Kolega']);
        $theirs = $this->inquiry($colleague, $this->client, '2026-09-10 10:00');
        $this->inquiry($colleague, $this->client, '2026-09-11 10:00', ['client_link_source' => 'manual']);

        // bez inquiries.view_all — cudzych nie ma
        $own = $this->userWith(['clients.view', 'inquiries.use']);
        $mine = $this->inquiry($own, $this->client, '2026-09-12 10:00', ['client_link_source' => 'nip']);
        Sanctum::actingAs($own);
        $data = $this->getJson("/api/clients/{$this->client->id}/timeline?type=inquiries")->assertOk()->json('data');
        $this->assertSame([$mine->id], array_column($data, 'id'));
        $this->assertSame('nip', $data[0]['link_source']);

        // lista wszystkich, ale bez otwierania cudzych
        Sanctum::actingAs($this->userWith(['clients.view', 'inquiries.use', 'inquiries.view_all']));
        $data = $this->getJson("/api/clients/{$this->client->id}/timeline?type=inquiries")->assertOk()->json('data');
        $this->assertCount(3, $data);
        $other = array_values(array_filter($data, static fn (array $e): bool => $e['id'] === $theirs->id))[0];
        $this->assertFalse($other['can_open']);

        Sanctum::actingAs($this->userWith(['clients.view', 'inquiries.use', 'inquiries.view_all', 'inquiries.view_others']));
        $data = $this->getJson("/api/clients/{$this->client->id}/timeline?type=inquiries")->assertOk()->json('data');
        $this->assertSame([true, true, true], array_column($data, 'can_open'));
        $this->assertSame(['manual', 'email', 'nip'], [$data[1]['link_source'], $data[2]['link_source'], $data[0]['link_source']]);
    }

    public function test_tenders_need_view_all_or_access_to_the_tender(): void
    {
        $me = $this->userWith(['clients.view', 'tenders.view_own']);
        $colleague = User::factory()->create();
        $owned = $this->tender($me, '2026-07-30', ['result_status' => 'lost']);
        $invited = $this->tender($colleague, '2026-06-30');
        TenderInvitation::query()->create(['tender_id' => $invited->id, 'user_id' => $me->id, 'invited_by' => $colleague->id]);
        $foreign = $this->tender($colleague, '2026-05-30');
        // bez terminu — dzień założenia
        $this->tender($colleague, null);

        Sanctum::actingAs($me);
        $data = $this->getJson("/api/clients/{$this->client->id}/timeline?type=tenders")->assertOk()->json('data');
        $this->assertSame([$owned->id, $invited->id], array_column($data, 'id'));
        $this->assertSame(['date' => '2026-07-30', 'status' => 'wycena', 'result_status' => 'lost', 'url' => '/tenders/'.$owned->id], array_intersect_key($data[0], array_flip(['date', 'status', 'result_status', 'url'])));

        Sanctum::actingAs($this->userWith(['clients.view', 'tenders.view_all']));
        $data = $this->getJson("/api/clients/{$this->client->id}/timeline?type=tenders")->assertOk()->json('data');
        $this->assertCount(4, $data);
        $this->assertSame('2026-10-02', $data[0]['date']);
        $this->assertContains($foreign->id, array_column($data, 'id'));
    }

    public function test_campaigns_by_xl_customer_or_card_email_in_campaign_module_scope(): void
    {
        $me = $this->userWith(['clients.view', 'campaigns.use']);
        $colleague = User::factory()->create(['name' => 'Kolega']);
        $customerId = DB::table('erp_customers')->insertGetId(['xl_gid' => 4100, 'acronym' => 'WISLOK', 'created_at' => now(), 'updated_at' => now()]);

        $sale = $this->campaign($me, 'Rękawice chemiczne — wyprzedaż', Campaign::STATUS_SENT);
        // dwóch odbiorców tego klienta w jednej kampanii — jeden wpis, kliknięcia razem
        $this->recipient($sale, 'zakupy@inna-domena.example.pl', $customerId, '2026-09-16 09:00', 2, null);
        $this->recipient($sale, 'anna@wislok.example.pl', null, '2026-09-16 09:01', 1, '2026-09-17 10:00');
        $mine2 = $this->campaign($me, 'Nowości jesień', Campaign::STATUS_SENT);
        $this->recipient($mine2, 'biuro@wislok.example.pl', null, '2026-08-01 09:00', 0, null);
        // obcy odbiorca, szkic i kampania kolegi
        $this->recipient($this->campaign($me, 'Inna firma', Campaign::STATUS_SENT), 'ktos@obcy.example.pl', null, '2026-09-01 09:00', 5, null);
        $this->recipient($this->campaign($me, 'Szkic', Campaign::STATUS_DRAFT), 'biuro@wislok.example.pl', null, '2026-09-02 09:00', 0, null);
        $theirs = $this->campaign($colleague, 'Kampania kolegi', Campaign::STATUS_SENT);
        $this->recipient($theirs, 'biuro@wislok.example.pl', null, '2026-09-03 09:00', 0, null);

        Sanctum::actingAs($me);
        $data = $this->getJson("/api/clients/{$this->client->id}/timeline?type=campaigns")->assertOk()->json('data');
        $this->assertSame([$sale->id, $mine2->id], array_column($data, 'id'));
        $this->assertSame(['name' => 'Rękawice chemiczne — wyprzedaż', 'clicks' => 3, 'replied' => true], array_intersect_key($data[0], array_flip(['name', 'clicks', 'replied'])));
        $this->assertSame('2026-09-16T07:00:00+00:00', $data[0]['date']);

        // campaigns.view — kampanie wszystkich
        Sanctum::actingAs($this->userWith(['clients.view', 'campaigns.view']));
        $data = $this->getJson("/api/clients/{$this->client->id}/timeline?type=campaigns")->assertOk()->json('data');
        $this->assertSame([$sale->id, $theirs->id, $mine2->id], array_column($data, 'id'));
        $this->assertSame(['id' => $colleague->id, 'name' => 'Kolega'], $data[1]['user']);
    }

    public function test_each_source_is_cut_at_200_entries(): void
    {
        $author = User::factory()->create();
        $rows = [];
        for ($i = 0; $i < 201; $i++) {
            $rows[] = ['client_id' => $this->client->id, 'user_id' => $author->id, 'body' => 'Notatka '.$i, 'created_at' => now()->subMinutes($i), 'updated_at' => now()];
        }
        DB::table('client_notes')->insert($rows);

        Sanctum::actingAs($this->userWith(['clients.view']));
        $this->getJson("/api/clients/{$this->client->id}/timeline?type=notes")->assertOk()
            ->assertJsonCount(200, 'data')
            ->assertJsonPath('truncated.notes', true)
            ->assertJsonPath('data.0.body', 'Notatka 0');
    }

    private function document(int $id, string $date, float $net, string $number): void
    {
        DB::table('erp_sale_documents')->insert([
            'document_type' => 2033, 'document_id' => $id, 'document_number' => $number, 'kind' => 'invoice', 'issued_at' => $date,
            'customer_xl_gid' => 4100, 'client_id' => $this->client->id, 'net_value' => $net,
            'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function note(User $author, string $body, string $createdAt): ClientNote
    {
        $note = ClientNote::query()->create(['client_id' => $this->client->id, 'user_id' => $author->id, 'body' => $body]);
        $note->forceFill(['created_at' => CarbonImmutable::parse($createdAt, 'Europe/Warsaw')->utc()])->save();

        return $note;
    }

    /** @param  array<string, mixed>  $extra */
    private function inquiry(User $user, ?Client $client, string $sentAt, array $extra = []): ClientInquiry
    {
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'client_id' => $client?->id,
            'tone' => 'formal',
            'source_subject' => 'Kombinezony jednorazowe, rękawice chemiczne',
            'source_body' => 'Proszę o ofertę.',
            'source_from_email' => 'anna@wislok.example.pl',
            'source_sent_at' => CarbonImmutable::parse($sentAt, 'Europe/Warsaw')->utc(),
            'analysis' => ['line_items' => [['name' => 'Kombinezon'], ['name' => 'Rękawice']]],
        ]);
        $inquiry->forceFill([
            'client_link_source' => $client !== null ? 'email' : null,
            ...array_intersect_key($extra, array_flip(['outcome', 'duplicate_of_id', 'client_link_source', 'replied_at'])),
        ])->save();
        if (isset($extra['source_from_email'])) {
            $inquiry->forceFill(['source_from_email' => $extra['source_from_email']])->save();
        }

        return $inquiry;
    }

    /** @param  array<string, mixed>  $extra */
    private function tender(User $owner, ?string $deadline, array $extra = []): Tender
    {
        return Tender::query()->create([
            'number' => 'ZP/'.Str::random(6), 'title' => 'Obuwie robocze', 'client_id' => $this->client->id, 'owner_id' => $owner->id,
            'status' => 'wycena', 'deadline' => $deadline, 'ai_percent' => 0, 'last_activity_at' => now(), ...$extra,
        ]);
    }

    private function campaign(User $owner, string $name, string $status): Campaign
    {
        $campaign = Campaign::query()->create(['user_id' => $owner->id, 'name' => $name, 'subject' => $name]);
        $campaign->forceFill(['status' => $status, 'sending_started_at' => $status === Campaign::STATUS_DRAFT ? null : now()->subMonth()])->save();

        return $campaign;
    }

    private function recipient(Campaign $campaign, string $email, ?int $customerId, string $sentAt, int $clicks, ?string $repliedAt): void
    {
        DB::table('campaign_recipients')->insert([
            'campaign_id' => $campaign->id, 'erp_customer_id' => $customerId, 'email' => $email, 'source' => 'xl',
            'token' => Str::random(40), 'status' => 'sent', 'sent_at' => CarbonImmutable::parse($sentAt, 'Europe/Warsaw')->utc(),
            'clicks' => $clicks, 'replied_at' => $repliedAt !== null ? CarbonImmutable::parse($repliedAt, 'Europe/Warsaw')->utc() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::findOrCreate('timeline-'.Str::random(6), 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
