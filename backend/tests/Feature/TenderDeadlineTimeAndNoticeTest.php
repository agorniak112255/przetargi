<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Competitor;
use App\Models\ProcurementNotice;
use App\Models\Tender;
use App\Models\TenderActivity;
use App\Models\TenderLot;
use App\Models\TenderLotOffer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Godzina składania ofert (tenders.deadline_time) i numer ogłoszenia (tenders.notice_number) w API przetargów:
 * zapis, walidacja, czyszczenie godziny razem z datą, historia zmian i filtry listy.
 */
final class TenderDeadlineTimeAndNoticeTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->client = Client::query()->create(['name' => 'Szpital Wojewódzki']);
    }

    public function test_create_stores_time_and_normalized_bzp_notice_number(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $response = $this->postJson('/api/tenders', [
            'title' => 'Rękawice',
            'client_id' => $this->client->id,
            'deadline' => '2026-10-05',
            'deadline_time' => '9:30',
            'notice_number' => ' 2026/bzp  00431178/01 ',
        ])->assertCreated()
            ->assertJsonPath('deadline_time', '09:30')
            ->assertJsonPath('notice_number', '2026/BZP 00431178/01')
            ->assertJsonPath('notice_source', 'bzp');

        $tender = Tender::query()->findOrFail($response->json('id'));
        $this->assertSame('09:30:00', $tender->getRawOriginal('deadline_time'));
    }

    public function test_ted_number_is_normalized_and_shown_in_show_and_index(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $id = $this->postJson('/api/tenders', [
            'title' => 'Obuwie',
            'client_id' => $this->client->id,
            'notice_number' => '2026/S 187-0606345',
        ])->assertCreated()->json('id');

        $this->getJson("/api/tenders/{$id}")
            ->assertOk()
            ->assertJsonPath('tender.notice_number', '606345-2026')
            ->assertJsonPath('tender.notice_source', 'ted')
            ->assertJsonPath('tender.deadline_time', null)
            ->assertJsonPath('tender.result_status', null);

        $row = collect($this->getJson('/api/tenders')->assertOk()->json())->firstWhere('id', $id);
        $this->assertSame('606345-2026', $row['notice_number']);
        $this->assertSame('ted', $row['notice_source']);
        $this->assertArrayHasKey('deadline_time', $row);
        $this->assertArrayHasKey('result_status', $row);
    }

    public function test_invalid_time_and_notice_number_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $base = ['title' => 'X', 'client_id' => $this->client->id, 'deadline' => '2026-10-05'];

        $this->postJson('/api/tenders', [...$base, 'deadline_time' => '24:00'])
            ->assertStatus(422)->assertJsonValidationErrors('deadline_time');
        $this->postJson('/api/tenders', [...$base, 'deadline_time' => '10.00'])
            ->assertStatus(422)->assertJsonValidationErrors('deadline_time');
        $this->postJson('/api/tenders', [...$base, 'notice_number' => 'BZP 431178'])
            ->assertStatus(422)->assertJsonValidationErrors('notice_number');
        $this->postJson('/api/tenders', [...$base, 'notice_number' => '2026/BZP 0043117/01'])
            ->assertStatus(422)->assertJsonValidationErrors('notice_number');

        $this->assertSame(0, Tender::query()->count());
    }

    public function test_time_without_date_is_rejected_on_create_and_update(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->postJson('/api/tenders', ['title' => 'X', 'client_id' => $this->client->id, 'deadline_time' => '10:00'])
            ->assertStatus(422)->assertJsonValidationErrors('deadline_time');

        $tender = $this->tender(['deadline' => null]);
        $this->patchJson("/api/tenders/{$tender->id}", ['deadline_time' => '10:00'])
            ->assertStatus(422)->assertJsonValidationErrors('deadline_time');
        $this->patchJson("/api/tenders/{$tender->id}", ['deadline' => null, 'deadline_time' => '10:00'])
            ->assertStatus(422)->assertJsonValidationErrors('deadline_time');

        // data i godzina w jednym żądaniu
        $this->patchJson("/api/tenders/{$tender->id}", ['deadline' => '2026-10-05', 'deadline_time' => '10:00'])
            ->assertOk()
            ->assertJsonPath('deadline_time', '10:00');
    }

    public function test_clearing_date_clears_time_and_empty_time_clears_time(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $tender = $this->tender(['deadline' => '2026-10-05', 'deadline_time' => '10:00']);

        $this->patchJson("/api/tenders/{$tender->id}", ['deadline_time' => ''])
            ->assertOk()->assertJsonPath('deadline_time', null);

        $tender->forceFill(['deadline_time' => '11:15'])->save();
        $this->patchJson("/api/tenders/{$tender->id}", ['deadline' => null])
            ->assertOk()
            ->assertJsonPath('deadline', null)
            ->assertJsonPath('deadline_time', null);
        $this->assertNull($tender->fresh()->getRawOriginal('deadline_time'));
    }

    public function test_changing_only_the_date_keeps_the_time(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $tender = $this->tender(['deadline' => '2026-10-05', 'deadline_time' => '10:00']);

        $this->patchJson("/api/tenders/{$tender->id}", ['deadline' => '2026-10-07'])
            ->assertOk()->assertJsonPath('deadline_time', '10:00');
    }

    public function test_update_logs_time_and_notice_changes_in_history(): void
    {
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);
        $tender = $this->tender(['deadline' => '2026-10-05']);

        $this->patchJson("/api/tenders/{$tender->id}", [
            'deadline_time' => '10:00',
            'notice_number' => '2026/BZP 00431178/01',
        ])->assertOk()->assertJsonPath('notice_number', '2026/BZP 00431178/01');

        $activity = TenderActivity::query()->where('tender_id', $tender->id)->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertNull($activity->meta['before']['deadline_time']);
        $this->assertSame('10:00', $activity->meta['after']['deadline_time']);
        $this->assertNull($activity->meta['before']['notice_number']);
        $this->assertSame('2026/BZP 00431178/01', $activity->meta['after']['notice_number']);
    }

    public function test_changing_notice_number_drops_bulletin_links_but_same_number_keeps_them(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $notice = ProcurementNotice::query()->create([
            'notice_type' => ProcurementNotice::TYPE_CONTRACT,
            'notice_number' => '2026/BZP 00431178/01',
            'bzp_number' => '2026/BZP 00431178',
            'published_at' => now(),
            'order_object' => 'Dostawa rękawic',
            'cpv_codes' => ['18141000-9'],
            'organization_name' => 'Szpital',
            'parser_version' => 1,
            'fetched_at' => now(),
        ]);
        $tender = $this->tender([
            'notice_number' => '2026/BZP 00431178/01',
            'contract_notice_id' => $notice->id,
            'bzp_checked_at' => now(),
        ]);

        // ten sam numer innym zapisem — bez zmian
        $this->patchJson("/api/tenders/{$tender->id}", ['notice_number' => '2026/bzp 00431178/01'])->assertOk();
        $this->assertSame($notice->id, (int) $tender->fresh()->contract_notice_id);

        $this->patchJson("/api/tenders/{$tender->id}", ['notice_number' => '2026/BZP 00431179/01'])->assertOk();
        $fresh = $tender->fresh();
        $this->assertSame('2026/BZP 00431179/01', $fresh->notice_number);
        $this->assertNull($fresh->contract_notice_id);
        $this->assertNull($fresh->bzp_checked_at);

        $this->patchJson("/api/tenders/{$tender->id}", ['notice_number' => null])
            ->assertOk()
            ->assertJsonPath('notice_number', null)
            ->assertJsonPath('notice_source', null);
    }

    public function test_changing_notice_number_clears_bulletin_data_in_lots_and_keeps_manual_entries(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($admin);
        $notice = ProcurementNotice::query()->create([
            'notice_type' => ProcurementNotice::TYPE_RESULT,
            'notice_number' => '2026/BZP 00461230/01',
            'bzp_number' => '2026/BZP 00461230',
            'published_at' => now(),
            'order_object' => 'Dostawa rękawic',
            'cpv_codes' => ['18141000-9'],
            'organization_name' => 'Szpital',
            'parser_version' => 1,
            'fetched_at' => now(),
        ]);
        $tender = $this->tender([
            'notice_number' => '2026/BZP 00431178/01',
            'result_notice_id' => $notice->id,
            'result_status' => 'partial',
        ]);
        $rival = Competitor::query()->create(['name' => 'BHP-Pro Handel sp. z o.o.', 'name_key' => 'bhp pro handel', 'nip' => null]);
        // część 1: cała z Biuletynu (unieważniona) — do usunięcia
        $auto = TenderLot::query()->create([
            'tender_id' => $tender->id, 'lot_no' => 1, 'name' => 'Rękawice', 'outcome' => TenderLot::OUTCOME_CANCELLED,
            'lowest_price' => '1000.00', 'currency' => 'PLN', 'bzp_notice_id' => $notice->id, 'decided_at' => now(),
        ]);
        // część 2: zwycięzca i ceny z Biuletynu, wynik i nasza cena wpisane ręcznie — zostają tylko wpisy ręczne
        $mixed = TenderLot::query()->create([
            'tender_id' => $tender->id, 'lot_no' => 2, 'name' => 'Obuwie', 'outcome' => TenderLot::OUTCOME_LOST,
            'loss_reason' => 'price', 'our_net' => '900.00', 'winner_competitor_id' => $rival->id, 'winner_price' => '950.00',
            'currency' => 'PLN', 'offers_count' => 3, 'manual_fields' => ['our_net', 'outcome', 'loss_reason'],
            'bzp_notice_id' => $notice->id, 'bzp_applied_at' => now(), 'decided_by' => $admin->id, 'decided_at' => now(),
        ]);
        TenderLotOffer::query()->create(['tender_lot_id' => $mixed->id, 'competitor_id' => $rival->id, 'price' => '950.00', 'currency' => 'PLN', 'source' => TenderLotOffer::SOURCE_BZP]);
        // część 3: założona ręcznie, bez żadnego pola — nie pochodzi z Biuletynu, zostaje
        $manual = TenderLot::query()->create(['tender_id' => $tender->id, 'lot_no' => 3, 'currency' => 'PLN']);

        $this->patchJson("/api/tenders/{$tender->id}", ['notice_number' => '2026/BZP 00431179/01'])->assertOk();

        $this->assertNull(TenderLot::query()->find($auto->id));
        $mixed->refresh();
        $this->assertNull($mixed->name);
        $this->assertNull($mixed->winner_competitor_id);
        $this->assertNull($mixed->winner_price);
        $this->assertNull($mixed->offers_count);
        $this->assertNull($mixed->bzp_notice_id);
        $this->assertNull($mixed->bzp_applied_at);
        $this->assertSame(TenderLot::OUTCOME_LOST, $mixed->outcome);
        $this->assertSame('price', $mixed->loss_reason);
        $this->assertSame('900.00', $mixed->our_net);
        $this->assertSame($admin->id, $mixed->decided_by);
        $this->assertSame(0, TenderLotOffer::query()->where('tender_lot_id', $mixed->id)->count());
        $this->assertNotNull(TenderLot::query()->find($manual->id));
        $fresh = $tender->fresh();
        $this->assertNull($fresh->result_notice_id);
        // wynik przetargu przeliczony bez unieważnionej części z Biuletynu
        $this->assertSame('lost', $fresh->result_status);

        $activity = TenderActivity::query()->where('tender_id', $tender->id)->where('action', 'result_updated')->sole();
        $this->assertSame('notice_number_changed', $activity->meta['source']);
        $this->assertSame('2026/BZP 00431178/01', $activity->meta['notice_number_before']);
        $this->assertSame('2026/BZP 00431179/01', $activity->meta['notice_number_after']);
        $this->assertSame(['partial', 'lost'], [$activity->meta['result_status_before'], $activity->meta['result_status_after']]);
        $this->assertSame([
            ['lot_no' => 1, 'deleted' => true, 'fields' => [], 'offers_changed' => false],
            ['lot_no' => 2, 'fields' => ['name', 'winner', 'winner_price', 'offers_count'], 'offers_changed' => true],
        ], $activity->meta['lots']);
    }

    public function test_list_filters_for_missing_result_time_and_notice(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'Europe/Warsaw'));
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $pastNoResult = $this->tender(['number' => 'P1', 'status' => 'exported', 'deadline' => '2026-10-05']);
        $pastWithResult = $this->tender(['number' => 'P2', 'status' => 'exported', 'deadline' => '2026-10-05', 'result_status' => 'won']);
        $pastDraft = $this->tender(['number' => 'P3', 'status' => 'draft', 'deadline' => '2026-10-05']);
        $todayNoResult = $this->tender(['number' => 'P4', 'status' => 'exported', 'deadline' => '2026-10-10']);
        $inProgressNoTime = $this->tender(['number' => 'P5', 'status' => 'wycena', 'deadline' => '2026-10-20']);
        $inProgressComplete = $this->tender([
            'number' => 'P6',
            'status' => 'wycena',
            'deadline' => '2026-10-20',
            'deadline_time' => '10:00',
            'notice_number' => '2026/BZP 00431178/01',
        ]);

        $ids = fn (string $filter): array => collect($this->getJson('/api/tenders?filter='.$filter)->assertOk()->json())
            ->pluck('id')->sort()->values()->all();

        $this->assertSame([$pastNoResult->id], $ids('no_result'));
        $this->assertSame(
            collect([$pastDraft->id, $inProgressNoTime->id])->sort()->values()->all(),
            $ids('no_deadline_time'),
        );
        $this->assertSame(
            collect([$pastDraft->id, $inProgressNoTime->id])->sort()->values()->all(),
            $ids('no_notice'),
        );
        $this->assertNotContains($pastWithResult->id, $ids('no_result'));
        $this->assertNotContains($todayNoResult->id, $ids('no_result'));
        $this->assertNotContains($inProgressComplete->id, $ids('no_deadline_time'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tender(array $attributes = []): Tender
    {
        return Tender::query()->create([
            'number' => 'PRZ/2026/'.random_int(1000, 9999),
            'title' => 'Rękawice robocze',
            'client_id' => $this->client->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            ...$attributes,
        ]);
    }
}
