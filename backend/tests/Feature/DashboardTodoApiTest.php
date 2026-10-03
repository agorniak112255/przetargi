<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\Tender;
use App\Models\TenderInvitation;
use App\Models\TenderItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Dashboard › „Do zrobienia dziś” (klucz `todo` w GET /dashboard): tylko sprawy zalogowanej osoby, „dziś” w czasie
 * polskim, kafelek „Wygrane, 90 dni”.
 */
class DashboardTodoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // sobota 3.10.2026, 9:00 w Polsce (7:00 UTC)
        $this->travelTo(Carbon::parse('2026-10-03 07:00:00', 'UTC'));
    }

    public function test_only_dashboard_permission_gives_empty_list_without_tender_tile(): void
    {
        Sanctum::actingAs($this->userWith(['dashboard.view']));

        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('todo.items', [])
            ->assertJsonPath('todo.won_90d', null);
    }

    public function test_deadlines_within_seven_days_are_listed_with_missing_offer_parts(): void
    {
        $me = $this->userWith(['dashboard.view', 'tenders.view_own']);
        $other = User::factory()->create();

        $soon = $this->tender($me, 'wycena', '2026-10-05', '10:00', 'Szpital Wojewódzki nr 2');
        $soon->forceFill(['notice_number' => '2026/BZP 00431178/01'])->save();
        $this->item($soon, 1, productId: null, customName: null, price: null);
        $this->item($soon, 2, productId: null, customName: '  ', price: 12.5);
        $this->item($soon, 3, productId: null, customName: 'Rękawice własne', price: null);
        $this->item($soon, 4, productId: null, customName: 'Kurtka', price: 99.0);

        $invited = $this->tender($other, 'zatwierdzona', '2026-10-03', null, 'Gmina');
        TenderInvitation::query()->create(['tender_id' => $invited->id, 'user_id' => $me->id, 'invited_by' => $other->id]);

        $this->tender($me, 'wycena', '2026-10-11', null, 'Za daleko');          // 8 dni
        $this->tender($me, 'exported', '2026-10-04', null, 'Złożony');          // oferta już złożona
        $this->tender($me, 'wycena', '2026-10-02', null, 'Po terminie');        // wczoraj
        $this->tender($other, 'wycena', '2026-10-04', null, 'Cudzy');           // nie mój i nie zaproszono mnie

        Sanctum::actingAs($me);
        $items = collect($this->getJson('/api/dashboard')->assertOk()->json('todo.items'))->where('kind', 'tender_deadline')->values();

        $this->assertSame([$invited->id, $soon->id], $items->pluck('tender_id')->all(), 'Dziś (zaproszony) i poniedziałek — po terminie rosnąco.');
        $row = $items[1];
        $this->assertSame('2026-10-05', $row['deadline']);
        $this->assertSame('10:00', $row['deadline_time']);
        $this->assertSame('2026/BZP 00431178/01', $row['notice_number']);
        $this->assertSame('Szpital Wojewódzki nr 2', $row['client']);
        $this->assertSame(['without_product' => 2, 'without_price' => 2], $row['missing'], 'Spacje to nie nazwa własna; własna nazwa to produkt.');
        $this->assertSame('/tenders/'.$soon->id, $row['url']);
        $this->assertSame(['without_product' => 0, 'without_price' => 0], $items[0]['missing']);
    }

    public function test_today_is_counted_in_polish_time(): void
    {
        $me = $this->userWith(['dashboard.view', 'tenders.view_own']);
        $tender = $this->tender($me, 'wycena', '2026-10-04', null, 'Jutro rano');
        // 3.10, 23:30 UTC = 4.10, 1:30 w Polsce — termin jest „dziś”, a nie jutro; termin 3.10 już minął
        $yesterday = $this->tender($me, 'wycena', '2026-10-03', null, 'Wczoraj');
        $this->travelTo(Carbon::parse('2026-10-03 23:30:00', 'UTC'));

        Sanctum::actingAs($me);
        $items = collect($this->getJson('/api/dashboard')->assertOk()->json('todo.items'));
        $this->assertSame([$tender->id], $items->where('kind', 'tender_deadline')->pluck('tender_id')->values()->all());
        $this->assertSame([$yesterday->id], $items->where('kind', 'tender_result_needed')->pluck('tender_id')->values()->all(), 'W Polsce to już dzień po terminie.');
    }

    public function test_results_needed_after_deadline_for_sixty_days_without_drafts_and_rejected(): void
    {
        $me = $this->userWith(['dashboard.view', 'tenders.view_own']);
        $needed = $this->tender($me, 'exported', '2026-09-30', null, 'Zakład Energetyczny');
        $this->tender($me, 'exported', '2026-08-03', null, 'Ponad 60 dni');      // 61 dni temu
        $this->tender($me, 'draft', '2026-09-30', null, 'Szkic');
        $this->tender($me, 'odrzucony', '2026-09-30', null, 'Odrzucony');
        $this->tender($me, 'exported', '2026-10-03', null, 'Dziś');
        $withResult = $this->tender($me, 'exported', '2026-09-20', null, 'Z wynikiem');
        $withResult->forceFill(['result_status' => 'lost'])->save();
        $oldest = $this->tender($me, 'archiwum', '2026-08-04', null, 'Archiwum 60 dni');

        Sanctum::actingAs($me);
        $items = collect($this->getJson('/api/dashboard')->assertOk()->json('todo.items'))->where('kind', 'tender_result_needed')->values();

        $this->assertSame([$oldest->id, $needed->id], $items->pluck('tender_id')->all());
        $this->assertSame('/tenders/'.$needed->id.'?tab=wynik', $items[1]['url']);
        $this->assertSame('2026-09-30', $items[1]['deadline']);
    }

    public function test_waiting_inquiries_count_only_mine_older_than_a_day_without_duplicates(): void
    {
        $me = $this->userWith(['dashboard.view', 'inquiries.use']);
        $other = User::factory()->create();
        $client = Client::query()->create(['name' => 'Ciepłownia Wisłok']);

        $oldest = $this->inquiry($me, now()->subDays(3), clientId: $client->id, sentAt: now()->subDays(4));
        $this->inquiry($me, now()->subHours(30));
        $this->inquiry($me, now()->subHours(5));                                   // mniej niż doba
        $this->inquiry($me, now()->subDays(3), repliedAt: now()->subDay());        // z odpowiedzią
        $this->inquiry($me, now()->subDays(20));                                   // starsze niż 14 dni
        $this->inquiry($me, now()->subDays(2), duplicateOf: $oldest->id);          // duplikat
        $this->inquiry($other, now()->subDays(2));                                 // cudze

        Sanctum::actingAs($me);
        $items = collect($this->getJson('/api/dashboard')->assertOk()->json('todo.items'))->where('kind', 'inquiries_waiting')->values();

        $this->assertCount(1, $items);
        $this->assertSame(2, $items[0]['count']);
        $this->assertSame($oldest->id, $items[0]['oldest']['id']);
        $this->assertSame('Ciepłownia Wisłok', $items[0]['oldest']['client']);
        $this->assertSame(now()->subDays(4)->toIso8601String(), $items[0]['oldest']['since'], 'Od chwili wysłania przez klienta.');
        $this->assertSame('/inquiries?status=waiting', $items[0]['url']);
    }

    public function test_today_deadline_with_passed_hour_is_not_on_the_list(): void
    {
        $me = $this->userWith(['dashboard.view', 'tenders.view_own']);
        // 9:00 w Polsce (7:00 UTC)
        $this->tender($me, 'wycena', '2026-10-03', '08:30', 'Minęło dziś rano');
        $this->tender($me, 'wycena', '2026-10-03', '09:00', 'Właśnie mija');
        $later = $this->tender($me, 'wycena', '2026-10-03', '12:00', 'Dziś w południe');
        $noTime = $this->tender($me, 'wycena', '2026-10-03', null, 'Dziś bez godziny');
        $tomorrow = $this->tender($me, 'wycena', '2026-10-04', '08:00', 'Jutro rano');

        Sanctum::actingAs($me);
        $items = collect($this->getJson('/api/dashboard')->assertOk()->json('todo.items'))->where('kind', 'tender_deadline')->values();

        $this->assertSame([$later->id, $noTime->id, $tomorrow->id], $items->pluck('tender_id')->all());
    }

    public function test_passed_deadlines_do_not_take_places_in_the_limit(): void
    {
        $me = $this->userWith(['dashboard.view', 'tenders.view_own']);
        for ($i = 0; $i < 10; $i++) {
            $this->tender($me, 'wycena', '2026-10-03', '08:0'.$i, 'Minęło '.$i);
        }
        $open = $this->tender($me, 'wycena', '2026-10-06', null, 'Wtorek');

        Sanctum::actingAs($me);
        $items = collect($this->getJson('/api/dashboard')->assertOk()->json('todo.items'))->where('kind', 'tender_deadline')->values();

        $this->assertSame([$open->id], $items->pluck('tender_id')->all());
    }

    public function test_waiting_inquiries_item_says_what_it_counts(): void
    {
        $me = $this->userWith(['dashboard.view', 'inquiries.use']);
        $this->inquiry($me, now()->subDays(2));

        Sanctum::actingAs($me);
        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('todo.items.0.kind', 'inquiries_waiting')
            ->assertJsonPath('todo.items.0.scope_label', 'bez odpowiedzi ponad dobę, z ostatnich 14 dni');
    }

    public function test_no_waiting_inquiries_item_without_permission_or_when_nothing_waits(): void
    {
        $me = $this->userWith(['dashboard.view']);
        $this->inquiry($me, now()->subDays(3));
        Sanctum::actingAs($me);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('todo.items', []);

        $empty = $this->userWith(['dashboard.view', 'inquiries.use']);
        Sanctum::actingAs($empty);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('todo.items', []);
    }

    public function test_offers_with_ending_validity_and_no_outcome_are_listed_from_last_business_day(): void
    {
        $me = $this->userWith(['dashboard.view', 'inquiries.use']);
        $other = $this->userWith(['dashboard.view', 'inquiries.use']);
        // podpowiedź z ERP XL liczy się tylko dla obecnego klienta zapytania (ten sam kontrahent XL)
        $client = Client::query()->create(['name' => 'Szpital Miejski nr 3', 'xl_gid' => 7001]);
        // dziś sobota 3.10: oferta do poniedziałku 5.10 — od piątku 2.10 (ostatni dzień roboczy przed) jest sprawą na dziś
        $monday = $this->offer($me, '2026-09-21 12:00', '14 dni', ['client_id' => $client->id, 'source_subject' => 'Półmaski i fartuchy']);
        DB::table('inquiry_order_hints')->insert([
            'client_inquiry_id' => $monday->id, 'customer_xl_gid' => 7001, 'document_type' => 2033, 'document_id' => 1, 'document_number' => 'FS-1', 'issued_at' => '2026-09-30',
            'document_net' => 10, 'matched_net' => 10, 'offered_items' => 1, 'linked_items' => 1, 'matched_items' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // ważna do dziś — jeszcze na liście; firma ze stopki, gdy nie ma klienta
        $today = $this->offer($me, '2026-09-19 12:00', '14 dni', ['contact' => ['company' => 'Zakłady Metalowe Sanwal']]);
        // podpowiedź z czasu, gdy zapytanie miało klienta — dziś zapytanie jest bez klienta, więc się nie liczy
        DB::table('inquiry_order_hints')->insert([
            'client_inquiry_id' => $today->id, 'customer_xl_gid' => 7001, 'document_type' => 2033, 'document_id' => 2, 'document_number' => 'FS-2', 'issued_at' => '2026-09-30',
            'document_net' => 10, 'matched_net' => 10, 'offered_items' => 1, 'linked_items' => 1, 'matched_items' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->offer($me, '2026-09-18 12:00', '14 dni');                          // ważność minęła wczoraj
        $this->offer($me, '2026-09-22 12:00', '14 dni');                          // do wtorku 6.10 — od poniedziałku
        $this->offer($me, '2026-09-21 12:00', '14 dni', ['outcome' => 'ordered']); // wynik wpisany
        $this->offer($me, '2026-09-21 12:00', 'do odwołania');                   // bez daty — nie zgadujemy
        $this->offer($other, '2026-09-21 12:00', '14 dni');                       // cudza

        Sanctum::actingAs($me);
        $items = collect($this->getJson('/api/dashboard')->assertOk()->json('todo.items'))->where('kind', 'offer_validity_ending')->values();

        $this->assertSame([$today->id, $monday->id], $items->pluck('inquiry_id')->all(), 'Najbliższy koniec ważności pierwszy.');
        $this->assertSame([
            'kind' => 'offer_validity_ending',
            'inquiry_id' => $monday->id,
            'client' => 'Szpital Miejski nr 3',
            'subject' => 'Półmaski i fartuchy',
            'valid_until' => '2026-10-05',
            'has_hint' => true,
            'url' => '/inquiries/'.$monday->id,
        ], $items[1]);
        $this->assertSame(['Zakłady Metalowe Sanwal', '2026-10-03', false], [$items[0]['client'], $items[0]['valid_until'], $items[0]['has_hint']]);

        // bez inquiries.use sprawy nie ma
        Sanctum::actingAs($this->userWith(['dashboard.view']));
        $this->assertSame([], collect($this->getJson('/api/dashboard')->json('todo.items'))->where('kind', 'offer_validity_ending')->values()->all());
    }

    public function test_unread_mentions_are_listed_newest_first(): void
    {
        $me = $this->userWith(['dashboard.view']);
        $older = $this->notification($me, ['type' => 'tender_mention', 'title' => 'Piotr wspomniał o Tobie', 'body' => '„Sprawdź podnosek”', 'url' => '/tenders/5?tab=komentarze'], now()->subHours(3));
        $newer = $this->notification($me, ['type' => 'tender_mention', 'title' => 'Anna wspomniała o Tobie', 'body' => 'Treść', 'url' => '/tenders/6?tab=komentarze'], now()->subHour());
        $this->notification($me, ['type' => 'tender_mention', 'title' => 'Przeczytana'], now()->subMinutes(5), read: true);
        $this->notification($me, ['type' => 'tender_deadline', 'title' => 'Inne', 'body' => 'tender_mention w treści'], now()->subMinutes(5));
        $this->notification(User::factory()->create(), ['type' => 'tender_mention', 'title' => 'Cudza'], now());

        Sanctum::actingAs($me);
        $items = collect($this->getJson('/api/dashboard')->assertOk()->json('todo.items'))->where('kind', 'mention')->values();

        $this->assertSame([$newer, $older], $items->pluck('notification_id')->all());
        $this->assertSame('Anna wspomniała o Tobie', $items[0]['title']);
        $this->assertSame('Treść', $items[0]['body']);
        $this->assertSame('/tenders/6?tab=komentarze', $items[0]['url']);
    }

    public function test_items_are_ordered_deadlines_inquiries_results_mentions(): void
    {
        $me = $this->userWith(['dashboard.view', 'tenders.view_own', 'inquiries.use']);
        $this->tender($me, 'wycena', '2026-10-05', null, 'Termin');
        $this->tender($me, 'exported', '2026-09-30', null, 'Wynik');
        $this->inquiry($me, now()->subDays(2));
        $this->notification($me, ['type' => 'tender_mention', 'title' => 'Wzmianka'], now());

        Sanctum::actingAs($me);
        $kinds = array_column($this->getJson('/api/dashboard')->assertOk()->json('todo.items'), 'kind');
        $this->assertSame(['tender_deadline', 'inquiries_waiting', 'tender_result_needed', 'mention'], $kinds);
    }

    public function test_won_90_days_counts_decided_lots_by_deadline_in_card_scope(): void
    {
        $me = $this->userWith(['dashboard.view', 'tenders.view_own']);
        $other = User::factory()->create();
        $a = $this->tender($me, 'exported', '2026-09-01', null, 'A');
        $this->lot($a, 1, 'won');
        $this->lot($a, 2, 'lost');
        $this->lot($a, 3, 'cancelled');
        $this->lot($a, 4, null);
        $b = $this->tender($me, 'exported', '2026-07-05', null, 'B');               // 90 dni temu — w okresie
        $this->lot($b, 1, 'won');
        $c = $this->tender($me, 'exported', '2026-07-04', null, 'C');               // 91 dni — poza
        $this->lot($c, 1, 'won');
        $d = $this->tender($other, 'exported', '2026-09-01', null, 'Cudzy');
        $this->lot($d, 1, 'lost');

        Sanctum::actingAs($me);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('todo.won_90d', ['won_lots' => 2, 'decided_lots' => 3]);

        $boss = $this->userWith(['dashboard.view', 'tenders.view_all']);
        Sanctum::actingAs($boss);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('todo.won_90d', ['won_lots' => 2, 'decided_lots' => 4]);
    }

    public function test_view_all_does_not_put_other_peoples_tenders_on_my_list(): void
    {
        $boss = $this->userWith(['dashboard.view', 'tenders.view_all']);
        $this->tender(User::factory()->create(), 'wycena', '2026-10-05', null, 'Cudzy');

        Sanctum::actingAs($boss);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('todo.items', []);
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::findOrCreate('todo-'.Str::random(6), 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function tender(User $owner, string $status, ?string $deadline, ?string $time, string $client): Tender
    {
        return Tender::query()->create([
            'number' => 'PRZ/'.uniqid('', true),
            'title' => 'Przetarg '.$client,
            'client_id' => Client::query()->create(['name' => $client])->id,
            'owner_id' => $owner->id,
            'status' => $status,
            'deadline' => $deadline,
            'deadline_time' => $time,
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
    }

    private function item(Tender $tender, int $line, ?int $productId, ?string $customName, ?float $price): void
    {
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id, 'line_no' => $line, 'requirement' => 'Pozycja '.$line, 'main_product_id' => $productId,
        ]);
        $item->forceFill(['custom_name' => $customName, 'offer_price' => $price])->save();
    }

    private function inquiry(User $user, \DateTimeInterface $createdAt, ?int $clientId = null, ?\DateTimeInterface $sentAt = null, ?\DateTimeInterface $repliedAt = null, ?int $duplicateOf = null): ClientInquiry
    {
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id, 'client_id' => $clientId, 'source_body' => 'Prośba o ofertę', 'source_sent_at' => $sentAt,
            'replied_at' => $repliedAt, 'duplicate_of_id' => $duplicateOf,
        ]);
        $inquiry->forceFill(['created_at' => $createdAt])->save();

        return $inquiry;
    }

    /**
     * Wysłana oferta z zapytania: odpowiedź w podanej chwili (czas polski) i tekst ważności z warunków oferty.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function offer(User $user, string $repliedAt, string $validity, array $attrs = []): ClientInquiry
    {
        $inquiry = ClientInquiry::query()->create(['user_id' => $user->id, 'source_body' => 'Prośba o ofertę']);
        $inquiry->forceFill([
            'replied_at' => Carbon::parse($repliedAt, 'Europe/Warsaw')->utc(),
            'offer_terms' => ['validity' => $validity],
            ...$attrs,
        ])->save();

        return $inquiry;
    }

    /** @param  array<string, mixed>  $data */
    private function notification(User $user, array $data, \DateTimeInterface $at, bool $read = false): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id, 'type' => 'App\\Notifications\\AppNotification', 'notifiable_type' => User::class, 'notifiable_id' => $user->id,
            'data' => json_encode($data), 'read_at' => $read ? now() : null, 'created_at' => $at, 'updated_at' => $at,
        ]);

        return $id;
    }

    private function lot(Tender $tender, int $no, ?string $outcome): void
    {
        DB::table('tender_lots')->insert([
            'tender_id' => $tender->id, 'lot_no' => $no, 'outcome' => $outcome, 'currency' => 'PLN', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
