<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProcurementNotice;
use App\Models\Tender;
use App\Models\TenderActivity;
use App\Models\TenderLot;
use App\Models\User;
use App\Services\Bzp\BzpNoticeParser;
use App\Services\Bzp\BzpNoticeStore;
use App\Services\Bzp\BzpTenderLinker;
use App\Services\Bzp\NoticeTenderCreator;
use App\Services\TenderActivityLogger;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/notices/{notice}/tender — szkic przetargu z ogłoszenia o zamówieniu: zamawiający po NIP, po nazwie albo
 * nowy klient, termin w czasie polskim, wpis w historii, części z ogłoszenia, 409 przy istniejącym przetargu.
 * Ogłoszenie z próbki API (Stołeczny Zarząd Infrastruktury, NIP 5262200493, termin 30.09.2026 06:00 UTC).
 */
final class NoticeTenderCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00:00', 'Europe/Warsaw'));
    }

    public function test_creates_draft_with_new_client_history_and_lots_from_notice(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);
        $notice = $this->fixtureNotice();

        $tenderId = $this->postJson("/api/notices/{$notice->id}/tender")
            ->assertCreated()
            ->json('tender_id');

        $tender = Tender::query()->findOrFail($tenderId);
        $this->assertSame('PRZ/2026/0001', $tender->number);
        $this->assertSame($notice->order_object, $tender->title);
        $this->assertSame('2026/BZP 00449679/01', $tender->notice_number);
        $this->assertSame($notice->id, $tender->contract_notice_id);
        $this->assertSame('draft', $tender->status);
        $this->assertSame($user->id, $tender->owner_id);
        // 06:00 UTC = 08:00 w Polsce (czas letni)
        $this->assertSame('2026-09-30', $tender->deadline->format('Y-m-d'));
        $this->assertSame('08:00', $tender->deadline_time);

        $client = Client::query()->findOrFail($tender->client_id);
        $this->assertSame('Stołeczny Zarząd Infrastruktury', $client->name);
        $this->assertSame('5262200493', $client->nip);
        $this->assertSame('Warszawa', $client->city);
        $this->assertSame(Client::SOURCE_MANUAL, $client->source);
        $this->assertSame($user->id, $client->owner_id);

        $activity = TenderActivity::query()->where('tender_id', $tender->id)->where('action', 'created')->firstOrFail();
        $this->assertSame($user->id, $activity->user_id);
        $this->assertSame('2026/BZP 00449679/01', $activity->meta['notice_number']);
        $this->assertSame('new', $activity->meta['client']['matched_by']);
        $this->assertSame(
            'Założony z ogłoszenia 2026/BZP 00449679/01 (Biuletyn Zamówień Publicznych). Zamawiający: Stołeczny Zarząd Infrastruktury — nowy klient z danymi z ogłoszenia.',
            $activity->meta['note'],
        );

        // części z ogłoszenia (pierwsze powiązanie z Biuletynem)
        $lots = is_array($notice->parsed['lots'] ?? null) ? $notice->parsed['lots'] : [];
        $this->assertGreaterThan(1, count($lots));
        $this->assertSame(count($lots), TenderLot::query()->where('tender_id', $tender->id)->count());

        // lista: ogłoszenie w zakładce „z przetargiem”, z linkiem do przetargu
        $this->getJson('/api/notices?tab=created')
            ->assertOk()
            ->assertJsonPath('data.0.id', $notice->id)
            ->assertJsonPath('data.0.tender', ['id' => $tender->id, 'number' => 'PRZ/2026/0001', 'can_open' => true]);
    }

    public function test_existing_client_by_nip_in_any_notation(): void
    {
        $client = Client::query()->create(['name' => 'SZI Warszawa (ERP XL)', 'nip' => 'PL 526-220-04-93', 'source' => Client::SOURCE_ERP_XL]);
        Client::query()->create(['name' => 'Stołeczny Zarząd Infrastruktury']);
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $notice = $this->fixtureNotice();

        $tenderId = $this->postJson("/api/notices/{$notice->id}/tender")->assertCreated()->json('tender_id');

        $this->assertSame($client->id, Tender::query()->findOrFail($tenderId)->client_id);
        $this->assertSame(2, Client::query()->count());
        $this->assertSame('nip', TenderActivity::query()->where('tender_id', $tenderId)->where('action', 'created')->firstOrFail()->meta['client']['matched_by']);
    }

    public function test_existing_client_by_name_ignores_same_name_with_other_nip(): void
    {
        // ta sama nazwa, inny poprawny NIP = inna jednostka
        Client::query()->create(['name' => 'Stołeczny Zarząd Infrastruktury', 'nip' => '8132283737']);
        $client = Client::query()->create(['name' => 'STOŁECZNY ZARZĄD INFRASTRUKTURY']);
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $notice = $this->fixtureNotice();

        $tenderId = $this->postJson("/api/notices/{$notice->id}/tender")->assertCreated()->json('tender_id');

        $this->assertSame($client->id, Tender::query()->findOrFail($tenderId)->client_id);
        $this->assertSame('name', TenderActivity::query()->where('tender_id', $tenderId)->where('action', 'created')->firstOrFail()->meta['client']['matched_by']);
    }

    /**
     * Zmiana wymagania (przegląd 03.10): przy kilku klientach z tą samą nazwą aplikacja nie zakłada trzeciego, tylko
     * prosi o wybór (422 z kandydatami); wybrany może być dowolny istniejący klient.
     */
    public function test_ambiguous_name_asks_for_a_client_instead_of_creating_one(): void
    {
        $first = Client::query()->create(['name' => 'Stołeczny Zarząd Infrastruktury', 'city' => 'Warszawa']);
        $second = Client::query()->create(['name' => 'Stołeczny Zarząd Infrastruktury.']);
        $other = Client::query()->create(['name' => 'Ktoś zupełnie inny']);
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);
        $notice = $this->fixtureNotice();

        // lista: bez podpowiedzi klienta, z kandydatami
        $row = $this->getJson('/api/notices?past=1')->assertOk()->json('data.0');
        $this->assertNull($row['client_match']);
        $this->assertSame([
            ['id' => $first->id, 'name' => 'Stołeczny Zarząd Infrastruktury', 'nip' => null, 'city' => 'Warszawa'],
            ['id' => $second->id, 'name' => 'Stołeczny Zarząd Infrastruktury.', 'nip' => null, 'city' => null],
        ], $row['client_candidates']);

        $this->postJson("/api/notices/{$notice->id}/tender")
            ->assertStatus(422)
            ->assertJsonPath('client_candidates.0.id', $first->id)
            ->assertJsonPath('client_candidates.1.id', $second->id)
            ->assertJsonValidationErrors('client_id');
        $this->assertSame(3, Client::query()->count());
        $this->assertSame(0, Tender::query()->count());

        $this->postJson("/api/notices/{$notice->id}/tender", ['client_id' => 999999])->assertJsonValidationErrors('client_id');
        $this->assertSame(0, Tender::query()->count());

        $tenderId = $this->postJson("/api/notices/{$notice->id}/tender", ['client_id' => $other->id])->assertCreated()->json('tender_id');
        $this->assertSame($other->id, Tender::query()->findOrFail($tenderId)->client_id);
        $this->assertSame(3, Client::query()->count());
        $activity = TenderActivity::query()->where('tender_id', $tenderId)->where('action', 'created')->firstOrFail();
        $this->assertSame('chosen', $activity->meta['client']['matched_by']);
        $this->assertStringEndsWith('Zamawiający: Ktoś zupełnie inny — klient wybrany przy zakładaniu przetargu.', $activity->meta['note']);
    }

    public function test_several_clients_with_the_nip_are_narrowed_by_name(): void
    {
        Client::query()->create(['name' => 'SZI — oddział Praga', 'nip' => '5262200493']);
        $client = Client::query()->create(['name' => 'Stołeczny Zarząd Infrastruktury sp. z o.o.', 'nip' => '526-220-04-93']);
        // ta sama nazwa bez NIP-u nie miesza się z klientami z tym NIP-em
        Client::query()->create(['name' => 'Stołeczny Zarząd Infrastruktury']);
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $notice = $this->fixtureNotice();

        $row = $this->getJson('/api/notices?past=1')->assertOk()->json('data.0');
        $this->assertSame(['id' => $client->id, 'name' => 'Stołeczny Zarząd Infrastruktury sp. z o.o.', 'matched_by' => 'nip_name'], $row['client_match']);
        $this->assertSame([], $row['client_candidates']);

        $tenderId = $this->postJson("/api/notices/{$notice->id}/tender")->assertCreated()->json('tender_id');
        $this->assertSame($client->id, Tender::query()->findOrFail($tenderId)->client_id);
        $this->assertSame(3, Client::query()->count());
        $this->assertSame('nip_name', TenderActivity::query()->where('tender_id', $tenderId)->where('action', 'created')->firstOrFail()->meta['client']['matched_by']);
    }

    public function test_several_clients_with_the_nip_and_other_names_ask_for_a_choice(): void
    {
        $a = Client::query()->create(['name' => 'SZI — oddział Praga', 'nip' => '5262200493', 'city' => 'Warszawa']);
        $b = Client::query()->create(['name' => 'SZI — oddział Wola', 'nip' => 'PL5262200493']);
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $notice = $this->fixtureNotice();

        $this->postJson("/api/notices/{$notice->id}/tender")
            ->assertStatus(422)
            ->assertJsonPath('client_candidates', [
                ['id' => $a->id, 'name' => 'SZI — oddział Praga', 'nip' => '5262200493', 'city' => 'Warszawa'],
                ['id' => $b->id, 'name' => 'SZI — oddział Wola', 'nip' => '5262200493', 'city' => null],
            ]);
        $this->assertSame(2, Client::query()->count());
        $this->assertSame(0, Tender::query()->count());

        $tenderId = $this->postJson("/api/notices/{$notice->id}/tender", ['client_id' => $b->id])->assertCreated()->json('tender_id');
        $this->assertSame($b->id, Tender::query()->findOrFail($tenderId)->client_id);
    }

    /**
     * Zakładanie z ogłoszeń jest szeregowane: gdy ktoś inny trzyma blokadę, drugie żądanie nie dopasowuje i nie
     * zakłada klienta na podstawie stanu sprzed zapisu pierwszego (bez blokady powstaliby dwaj nowi klienci).
     */
    public function test_creation_waits_for_the_lock_and_creates_nothing_without_it(): void
    {
        $this->app->instance(NoticeTenderCreator::class, new NoticeTenderCreator(
            app(TenderActivityLogger::class),
            app(BzpTenderLinker::class),
            0,
        ));
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $notice = $this->fixtureNotice();

        $held = Cache::lock(NoticeTenderCreator::LOCK_KEY, 30);
        $this->assertTrue($held->get());
        $this->postJson("/api/notices/{$notice->id}/tender")
            ->assertStatus(423)
            ->assertJsonPath('message', 'Ktoś inny zakłada w tej chwili przetarg z ogłoszenia. Spróbuj ponownie za kilka sekund.');
        $this->assertSame(0, Client::query()->count());
        $this->assertSame(0, Tender::query()->count());

        $held->release();
        $this->postJson("/api/notices/{$notice->id}/tender")->assertCreated();
        $this->assertSame(1, Client::query()->count());
        // blokada zwolniona po zapisie
        $this->assertTrue(Cache::lock(NoticeTenderCreator::LOCK_KEY, 30)->get());
    }

    /**
     * Starsza wersja ogłoszenia na liście (np. sprzed odświeżenia) — przetarg dostaje dane najnowszej wersji
     * postępowania (termin i numer z …/02).
     */
    public function test_older_version_id_uses_the_latest_version_of_the_procedure(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $old = $this->notice('2026/BZP 00500001', '2026-10-13 08:00:00', 'Rękawice (pierwotnie)');
        $latest = $this->notice('2026/BZP 00500001', '2026-10-20 09:00:00', 'Rękawice (po zmianie)', ProcurementNotice::TYPE_CONTRACT, '02');

        $tender = Tender::query()->findOrFail($this->postJson("/api/notices/{$old->id}/tender")->assertCreated()->json('tender_id'));

        $this->assertSame('2026/BZP 00500001/02', $tender->notice_number);
        $this->assertSame('Rękawice (po zmianie)', $tender->title);
        $this->assertSame('2026-10-20', $tender->deadline->format('Y-m-d'));
        $this->assertSame('11:00', $tender->deadline_time);
        $this->assertSame($latest->id, $tender->contract_notice_id);
        $this->assertSame($latest->id, TenderActivity::query()->where('tender_id', $tender->id)->where('action', 'created')->firstOrFail()->meta['notice_id']);

        // i odwrotnie: druga próba z którejkolwiek wersji → 409
        $this->postJson("/api/notices/{$old->id}/tender")->assertStatus(409)->assertJsonPath('tender_id', $tender->id);
        $this->postJson("/api/notices/{$latest->id}/tender")->assertStatus(409)->assertJsonPath('tender_id', $tender->id);
    }

    public function test_conflict_tells_whether_the_user_can_open_the_existing_tender(): void
    {
        $owner = User::factory()->withRole('handlowiec')->create();
        $notice = $this->notice('2026/BZP 00500001', '2026-10-13 08:00:00');
        $existing = Tender::query()->create([
            'number' => 'PRZ/2026/0007',
            'title' => 'Ręcznie',
            'client_id' => Client::query()->create(['name' => 'Ktoś'])->id,
            'owner_id' => $owner->id,
            'status' => 'draft',
            'ai_percent' => 0,
            'notice_number' => '2026/BZP 00500001',
        ]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/notices/{$notice->id}/tender")
            ->assertStatus(409)
            ->assertJsonPath('tender_id', $existing->id)
            ->assertJsonPath('tender_number', 'PRZ/2026/0007')
            ->assertJsonPath('can_open', true);

        // inny handlowiec bez zaproszenia nie ma dostępu do cudzego przetargu
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->postJson("/api/notices/{$notice->id}/tender")
            ->assertStatus(409)
            ->assertJsonPath('tender_number', 'PRZ/2026/0007')
            ->assertJsonPath('can_open', false);
    }

    /**
     * Wyścig numeru PRZ/…: równoległy zapis zajął wyliczony numer (odczyt w transakcji go nie widział) — przetarg
     * dostaje następny numer zamiast błędu.
     */
    public function test_taken_internal_number_is_retried_with_the_next_one(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $notice = $this->notice('2026/BZP 00500001', '2026-10-13 08:00:00');
        $clientId = Client::query()->create(['name' => 'Równoległy'])->id;
        $raced = false;
        Tender::creating(static function (Tender $tender) use (&$raced, $clientId): void {
            if ($raced) {
                return;
            }
            $raced = true;
            DB::table('tenders')->insert([
                'number' => $tender->number,
                'title' => 'Założony w tej samej chwili',
                'client_id' => $clientId,
                'status' => 'draft',
                'ai_percent' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $tenderId = $this->postJson("/api/notices/{$notice->id}/tender")->assertCreated()->json('tender_id');

        $this->assertSame('PRZ/2026/0002', Tender::query()->findOrFail($tenderId)->number);
        $this->assertSame(['PRZ/2026/0001', 'PRZ/2026/0002'], Tender::query()->orderBy('number')->pluck('number')->all());
    }

    public function test_existing_tender_for_the_procedure_returns_409_without_duplicate(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);
        $notice = $this->fixtureNotice();
        // przetarg założony ręcznie z numerem ogłoszenia bez wersji
        $manual = Tender::query()->create([
            'number' => 'PRZ/2026/0007',
            'title' => 'Ręcznie',
            'client_id' => Client::query()->create(['name' => 'Ktoś'])->id,
            'status' => 'draft',
            'ai_percent' => 0,
            'notice_number' => '2026/BZP 00449679',
        ]);

        $this->postJson("/api/notices/{$notice->id}/tender")
            ->assertStatus(409)
            ->assertJsonPath('tender_id', $manual->id);
        $this->assertSame(1, Tender::query()->count());

        $manual->delete();
        $created = $this->postJson("/api/notices/{$notice->id}/tender")->assertCreated()->json('tender_id');
        $this->postJson("/api/notices/{$notice->id}/tender")
            ->assertStatus(409)
            ->assertJsonPath('tender_id', $created);
        $this->assertSame(1, Tender::query()->count());
    }

    public function test_deadline_in_polish_time_around_midnight_and_without_deadline(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $summer = $this->notice('2026/BZP 00500001', '2026-10-13 22:30:00');
        $winter = $this->notice('2026/BZP 00500002', '2026-11-02 23:30:00');
        $none = $this->notice('2026/BZP 00500003', null, str_repeat('Długi przedmiot zamówienia ', 20));

        $summerTender = Tender::query()->findOrFail($this->postJson("/api/notices/{$summer->id}/tender")->assertCreated()->json('tender_id'));
        $this->assertSame('2026-10-14', $summerTender->deadline->format('Y-m-d'));
        $this->assertSame('00:30', $summerTender->deadline_time);

        $winterTender = Tender::query()->findOrFail($this->postJson("/api/notices/{$winter->id}/tender")->assertCreated()->json('tender_id'));
        $this->assertSame('2026-11-03', $winterTender->deadline->format('Y-m-d'));
        $this->assertSame('00:30', $winterTender->deadline_time);

        $noneTender = Tender::query()->findOrFail($this->postJson("/api/notices/{$none->id}/tender")->assertCreated()->json('tender_id'));
        $this->assertNull($noneTender->deadline);
        $this->assertNull($noneTender->deadline_time);
        $this->assertSame(255, mb_strlen($noneTender->title));
        $this->assertSame(['PRZ/2026/0001', 'PRZ/2026/0002', 'PRZ/2026/0003'], Tender::query()->orderBy('id')->pluck('number')->all());
    }

    public function test_permissions_and_result_notices(): void
    {
        $notice = $this->notice('2026/BZP 00500001', '2026-10-13 08:00:00');
        $result = $this->notice('2026/BZP 00500002', null, 'Wynik', ProcurementNotice::TYPE_RESULT);

        // dyrektor widzi listę, ale nie zakłada przetargów
        Sanctum::actingAs(User::factory()->withRole('dyrektor')->create());
        $this->postJson("/api/notices/{$notice->id}/tender")->assertForbidden();

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->postJson("/api/notices/{$result->id}/tender")->assertNotFound();
        $this->assertSame(0, Tender::query()->count());
        $this->assertSame(0, Client::query()->count());
    }

    private function fixtureNotice(): ProcurementNotice
    {
        $item = json_decode((string) file_get_contents(base_path('tests/Fixtures/bzp/contract-lots.json')), true);

        return app(BzpNoticeStore::class)->upsert(app(BzpNoticeParser::class)->parse($item))->refresh();
    }

    private function notice(string $bzpNumber, ?string $deadline, string $object = 'Rękawice', string $type = ProcurementNotice::TYPE_CONTRACT, string $version = '01'): ProcurementNotice
    {
        return ProcurementNotice::query()->create([
            'source' => 'bzp',
            'notice_type' => $type,
            'notice_number' => $bzpNumber.'/'.$version,
            'bzp_number' => $bzpNumber,
            'published_at' => '2026-10-01 08:00:00',
            'submitting_offers_at' => $deadline,
            'order_object' => $object,
            'cpv_codes' => [['code' => '18141000-9', 'name' => 'Rękawice robocze']],
            'organization_name' => 'Gmina '.$bzpNumber,
            'organization_city' => '',
            'parsed' => ['has_lots' => false, 'lots' => []],
            'parser_version' => 1,
            'fetched_at' => '2026-10-03 04:30:00',
        ]);
    }
}
