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
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_ambiguous_name_creates_new_client(): void
    {
        Client::query()->create(['name' => 'Stołeczny Zarząd Infrastruktury']);
        Client::query()->create(['name' => 'Stołeczny Zarząd Infrastruktury.']);
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $notice = $this->fixtureNotice();

        $tenderId = $this->postJson("/api/notices/{$notice->id}/tender")->assertCreated()->json('tender_id');

        $this->assertSame(3, Client::query()->count());
        $this->assertSame('5262200493', Tender::query()->findOrFail($tenderId)->client->nip);
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

    private function notice(string $bzpNumber, ?string $deadline, string $object = 'Rękawice', string $type = ProcurementNotice::TYPE_CONTRACT): ProcurementNotice
    {
        return ProcurementNotice::query()->create([
            'source' => 'bzp',
            'notice_type' => $type,
            'notice_number' => $bzpNumber.'/01',
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
