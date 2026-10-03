<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tender;
use App\Models\TenderInvitation;
use App\Models\TenderItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Kalendarz terminów składania ofert (GET /api/tenders/calendar): stany i ich kolejność, widoczność (view_all albo
 * opiekun i zaproszony), filtry jak na liście, zakres najwyżej 62 dni, „dziś” w czasie polskim.
 */
final class TenderCalendarApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // sobota 3.10.2026, 9:00 w Polsce (7:00 UTC)
        $this->travelTo(Carbon::parse('2026-10-03 07:00:00', 'UTC'));
    }

    public function test_states_follow_the_documented_order(): void
    {
        $me = $this->userWith(['tenders.view_own']);

        $ready = $this->tender($me, 'wycena', '2026-10-05', '10:00', 'Gotowy');
        $this->item($ready, 1, 'Rękawice', 10.0);
        $this->item($ready, 2, 'Okulary', 20.0);

        $urgent = $this->tender($me, 'wycena', '2026-10-06', null, 'Braki blisko');   // za 3 dni
        $this->item($urgent, 1, 'Rękawice', null);
        $empty = $this->tender($me, 'wycena', '2026-10-04', null, 'Bez pozycji');    // jutro, żadnej pozycji
        $farGaps = $this->tender($me, 'wycena', '2026-10-07', null, 'Braki dalej');  // za 4 dni
        $this->item($farGaps, 1, null, 5.0);

        $resultNeeded = $this->tender($me, 'zatwierdzona', '2026-10-01', '12:00', 'Po terminie');
        $this->item($resultNeeded, 1, 'Rękawice', 10.0);
        // po terminie, bez wyniku, ale archiwum — wynik nadal potrzebny (jak przypomnienie „wpisz wynik”)
        $archivedNoResult = $this->tender($me, 'archiwum', '2026-10-02', null, 'Archiwum bez wyniku');

        $exported = $this->tender($me, 'exported', '2026-10-08', null, 'Wysłany');
        $withResult = $this->tender($me, 'zatwierdzona', '2026-09-30', null, 'Z wynikiem');
        $withResult->forceFill(['result_status' => 'won'])->save();
        $rejected = $this->tender($me, 'odrzucony', '2026-09-29', null, 'Odrzucony');
        // szkic po terminie nie czeka na wynik; z brakami, a termin minął — „w toku”, nie „blisko”
        $draftPast = $this->tender($me, 'draft', '2026-09-28', null, 'Szkic po terminie');
        $this->item($draftPast, 1, null, null);

        Sanctum::actingAs($me);
        $response = $this->getJson('/api/tenders/calendar?from=2026-09-28&to=2026-11-08')->assertOk()
            ->assertJsonPath('from', '2026-09-28')
            ->assertJsonPath('to', '2026-11-08')
            ->assertJsonPath('today', '2026-10-03');
        $states = collect($response->json('events'))->pluck('state', 'tender_id')->all();

        $this->assertSame('ready', $states[$ready->id]);
        $this->assertSame('urgent', $states[$urgent->id]);
        $this->assertSame('urgent', $states[$empty->id], 'Brak pozycji to też brak.');
        $this->assertSame('in_progress', $states[$farGaps->id]);
        $this->assertSame('result_needed', $states[$resultNeeded->id]);
        $this->assertSame('result_needed', $states[$archivedNoResult->id]);
        $this->assertSame('closed', $states[$exported->id]);
        $this->assertSame('closed', $states[$withResult->id]);
        $this->assertSame('closed', $states[$rejected->id]);
        $this->assertSame('in_progress', $states[$draftPast->id]);

        $event = collect($response->json('events'))->firstWhere('tender_id', $resultNeeded->id);
        $this->assertSame('/tenders/'.$resultNeeded->id.'?tab=wynik', $event['url'], '„Wpisz wynik” otwiera sekcję wyniku.');
        $this->assertSame('2026-10-01', $event['date']);
        $this->assertSame('12:00', $event['time']);
        $this->assertSame('Po terminie', $event['client']);
        $readyEvent = collect($response->json('events'))->firstWhere('tender_id', $ready->id);
        $this->assertSame('/tenders/'.$ready->id, $readyEvent['url']);
        $this->assertSame(['items' => 2, 'without_product' => 0, 'without_price' => 0], $readyEvent['missing']);
        $this->assertSame(['items' => 1, 'without_product' => 1, 'without_price' => 0], collect($response->json('events'))->firstWhere('tender_id', $farGaps->id)['missing']);
        $this->assertSame(['items' => 0, 'without_product' => 0, 'without_price' => 0], collect($response->json('events'))->firstWhere('tender_id', $empty->id)['missing']);
    }

    public function test_result_is_needed_only_up_to_sixty_days_after_the_deadline(): void
    {
        $me = $this->userWith(['tenders.view_own']);
        $sixty = $this->tender($me, 'zatwierdzona', '2026-08-04', null, '60 dni temu');
        $this->item($sixty, 1, 'Rękawice', 10.0);
        $older = $this->tender($me, 'zatwierdzona', '2026-08-03', null, '61 dni temu');
        $this->item($older, 1, 'Rękawice', 10.0);

        Sanctum::actingAs($me);
        $states = collect($this->getJson('/api/tenders/calendar?from=2026-08-01&to=2026-08-31')->assertOk()->json('events'))
            ->pluck('state', 'tender_id')->all();

        $this->assertSame('result_needed', $states[$sixty->id]);
        $this->assertSame('ready', $states[$older->id], 'Starszy niż 60 dni — bez „wpisz wynik”, stan oferty jak przed terminem.');
    }

    public function test_events_are_sorted_by_day_then_timed_before_all_day(): void
    {
        $me = $this->userWith(['tenders.view_own']);
        $allDay = $this->tender($me, 'wycena', '2026-10-05', null, 'Całodniowy');
        $late = $this->tender($me, 'wycena', '2026-10-05', '12:00', 'Południe');
        $early = $this->tender($me, 'wycena', '2026-10-05', '09:30', 'Rano');
        $before = $this->tender($me, 'wycena', '2026-10-04', null, 'Dzień wcześniej');
        $this->tender($me, 'wycena', null, null, 'Bez terminu');
        $this->tender($me, 'wycena', '2026-11-02', null, 'Poza zakresem');

        Sanctum::actingAs($me);
        $ids = collect($this->getJson('/api/tenders/calendar?from=2026-09-28&to=2026-11-01')->assertOk()->json('events'))
            ->pluck('tender_id')->all();

        $this->assertSame([$before->id, $early->id, $late->id, $allDay->id], $ids);
    }

    public function test_only_tenders_the_user_may_see_and_list_filters(): void
    {
        $me = $this->userWith(['tenders.view_own']);
        $other = User::factory()->create();
        $mine = $this->tender($me, 'wycena', '2026-10-05', null, 'Mój');
        $invited = $this->tender($other, 'wycena', '2026-10-06', null, 'Zaproszony');
        TenderInvitation::query()->create(['tender_id' => $invited->id, 'user_id' => $me->id, 'invited_by' => $other->id]);
        $foreign = $this->tender($other, 'wycena', '2026-10-07', null, 'Cudzy');

        Sanctum::actingAs($me);
        $this->assertSame([$mine->id, $invited->id], $this->ids(''));
        $this->assertSame([$mine->id], $this->ids('mine'));
        $this->assertSame([$invited->id], $this->ids('invited'));

        $boss = $this->userWith(['tenders.view_all']);
        Sanctum::actingAs($boss);
        $this->assertSame([$mine->id, $invited->id, $foreign->id], $this->ids(''));
        $this->assertSame([], $this->ids('mine'), 'Filtr „prowadzę” dotyczy zalogowanego, także przy dostępie do wszystkich.');

        Sanctum::actingAs($this->userWith(['dashboard.view']));
        $this->getJson('/api/tenders/calendar?from=2026-10-01&to=2026-10-31')->assertForbidden();
    }

    public function test_range_is_at_most_62_days_and_dates_are_validated(): void
    {
        Sanctum::actingAs($this->userWith(['tenders.view_own']));

        $this->getJson('/api/tenders/calendar?from=2026-10-01&to=2026-12-02')->assertOk();
        $this->getJson('/api/tenders/calendar?from=2026-10-01&to=2026-12-03')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->getJson('/api/tenders/calendar?from=2026-10-31&to=2026-10-01')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->getJson('/api/tenders/calendar?from=2026-10-01')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->getJson('/api/tenders/calendar?from=1.10.2026&to=2026-10-31')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->getJson('/api/tenders/calendar?from=2026-10-01&to=2026-10-31&filter=no_result')->assertStatus(422)->assertJsonValidationErrors('filter');
    }

    public function test_today_and_states_use_polish_time(): void
    {
        $me = $this->userWith(['tenders.view_own']);
        $tender = $this->tender($me, 'zatwierdzona', '2026-10-03', null, 'Dziś');
        $this->item($tender, 1, 'Rękawice', 10.0);
        // 3.10, 23:30 UTC = 4.10, 1:30 w Polsce — termin 3.10 już minął
        $this->travelTo(Carbon::parse('2026-10-03 23:30:00', 'UTC'));

        Sanctum::actingAs($me);
        $response = $this->getJson('/api/tenders/calendar?from=2026-10-01&to=2026-10-31')->assertOk()
            ->assertJsonPath('today', '2026-10-04');
        $this->assertSame('result_needed', $response->json('events.0.state'));
    }

    /** @return list<int> */
    private function ids(string $filter): array
    {
        $qs = 'from=2026-10-01&to=2026-10-31'.($filter !== '' ? '&filter='.$filter : '');

        return collect($this->getJson('/api/tenders/calendar?'.$qs)->assertOk()->json('events'))->pluck('tender_id')->all();
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::findOrCreate('cal-'.Str::random(6), 'web');
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

    private function item(Tender $tender, int $line, ?string $customName, ?float $price): void
    {
        $item = TenderItem::query()->create(['tender_id' => $tender->id, 'line_no' => $line, 'requirement' => 'Pozycja '.$line]);
        $item->forceFill(['custom_name' => $customName, 'offer_price' => $price])->save();
    }
}
