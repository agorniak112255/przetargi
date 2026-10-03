<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Competitor;
use App\Models\Tender;
use App\Models\TenderActivity;
use App\Models\TenderLot;
use App\Models\User;
use App\Services\Bzp\BzpNoticeParser;
use App\Services\Bzp\BzpNoticeStore;
use App\Services\Bzp\BzpTenderLinker;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Łączenie przetargu z ogłoszeniami zapisanymi w bazie (BzpTenderLinker) i „Sprawdź w Biuletynie”
 * (POST /tenders/{id}/result/bzp-check). Ogłoszenia — prawdziwe próbki z tests/Fixtures/bzp.
 */
final class BzpTenderLinkerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00:00', 'Europe/Warsaw'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->owner = User::factory()->withRole('handlowiec')->create(['name' => 'Piotr Wiśniewski']);
        foreach (['contract-lots.json', 'contract-single.json', 'result-lots-cancelled-and-awarded.json', 'result-single-cancelled.json', 'result-single-awarded.json'] as $file) {
            app(BzpNoticeStore::class)->upsert(app(BzpNoticeParser::class)->parse(self::fixture($file)));
        }
    }

    public function test_lot_won_when_winner_has_our_nip(): void
    {
        // w próbce wygrała firma MADA — w teście to „nasz” NIP
        config(['bzp.our_company.nip' => '118-16-25-269', 'bzp.our_company.names' => ['SUPON']]);
        $tender = $this->tender('2026/BZP 00376786');

        $message = app(BzpTenderLinker::class)->linkTender($tender);

        $this->assertStringContainsString('2026/BZP 00453849/01', $message);
        $lot = TenderLot::query()->where('tender_id', $tender->id)->sole();
        $this->assertSame(1, $lot->lot_no);
        $this->assertSame(TenderLot::OUTCOME_WON, $lot->outcome);
        $this->assertNull($lot->decided_by);
        $this->assertNotNull($lot->decided_at);
        $this->assertSame('31740.15', $lot->winner_price);
        $this->assertSame(1, $lot->offers_count);
        $this->assertSame('1181625269', $lot->winner?->nip);
        $this->assertSame('18143000-3', $lot->cpv_main);
        $this->assertNull($lot->manual_fields);
        $this->assertSame('won', $tender->refresh()->result_status);
        $this->assertNotNull($tender->bzp_checked_at);
    }

    public function test_foreign_winner_fills_fields_but_not_the_outcome(): void
    {
        $tender = $this->tender('2026/BZP 00361360');

        $result = app(BzpTenderLinker::class)->link($tender);

        $this->assertSame(2, $result['changed_lots']);
        $this->assertSame([], $result['conflicts']);
        [$lot1, $lot2] = TenderLot::query()->where('tender_id', $tender->id)->orderBy('lot_no')->get()->all();
        // część 1 unieważniona — ten wynik Biuletyn ustawia sam
        $this->assertSame(TenderLot::OUTCOME_CANCELLED, $lot1->outcome);
        $this->assertSame('1365178.85', $lot1->lowest_price);
        $this->assertNull($lot1->winner_competitor_id);
        // część 2 wygrała inna firma: zwycięzca, cena, liczba ofert — wynik czeka na opiekuna
        $this->assertNull($lot2->outcome);
        $this->assertSame('Przedsiębiorstwo Wielobranżowe "MADA" Kosiec i Wspólnicy Sp. k.', $lot2->winner?->name);
        $this->assertSame('1181625269', $lot2->winner?->nip);
        $this->assertNull($lot2->winner_national_id_raw);
        $this->assertSame('143320.83', $lot2->winner_price);
        $this->assertSame('PLN', $lot2->currency);
        $this->assertSame(3, $lot2->offers_count);
        $this->assertSame('97633.44', $lot2->estimated_value);
        $this->assertSame('18830000-6', $lot2->cpv_main);
        $this->assertNull($tender->refresh()->result_status);

        $activity = TenderActivity::query()->where('tender_id', $tender->id)->where('action', 'result_updated')->sole();
        $this->assertSame('bzp', $activity->meta['source']);
        $this->assertSame([1, 2], array_column($activity->meta['lots'], 'lot_no'));

        // drugie sprawdzenie niczego nie zmienia i nie dopisuje historii
        $again = app(BzpTenderLinker::class)->link($tender->refresh());
        $this->assertSame(0, $again['changed_lots']);
        $this->assertStringContainsString('Dane części są aktualne', $again['message']);
        $this->assertSame(1, TenderActivity::query()->where('tender_id', $tender->id)->count());
    }

    public function test_manual_fields_are_untouched_and_contradiction_is_reported(): void
    {
        $tender = $this->tender('2026/BZP 00361360/01');
        $other = Competitor::query()->create(['name' => 'BHP-Pro Handel sp. z o.o.', 'name_key' => 'bhp pro handel', 'nip' => null]);
        $lot = TenderLot::query()->create([
            'tender_id' => $tender->id,
            'lot_no' => 2,
            'outcome' => TenderLot::OUTCOME_LOST,
            'loss_reason' => 'price',
            'note' => 'Zwycięzca dał tańsze obuwie.',
            'winner_competitor_id' => $other->id,
            'winner_price' => '140000.00',
            'currency' => 'PLN',
            'manual_fields' => ['outcome', 'winner', 'winner_price', 'loss_reason', 'note'],
            'decided_by' => $this->owner->id,
        ]);

        $result = app(BzpTenderLinker::class)->link($tender);

        $lot->refresh();
        $this->assertSame(TenderLot::OUTCOME_LOST, $lot->outcome);
        $this->assertSame('price', $lot->loss_reason);
        $this->assertSame('Zwycięzca dał tańsze obuwie.', $lot->note);
        $this->assertSame($other->id, $lot->winner_competitor_id);
        $this->assertSame('140000.00', $lot->winner_price);
        $this->assertSame($this->owner->id, $lot->decided_by);
        // pola bez ręcznego wpisu uzupełnione
        $this->assertSame(3, $lot->offers_count);
        $this->assertSame('143320.83', $lot->lowest_price);
        // przetarg miał już części — część 1 z ogłoszenia nie jest zakładana
        $this->assertSame(1, TenderLot::query()->where('tender_id', $tender->id)->count());
        $this->assertArrayHasKey(2, $result['conflicts']);
        $this->assertStringContainsString('zwycięzcą tej części jest Przedsiębiorstwo Wielobranżowe', $result['conflicts'][2]);
        $this->assertStringContainsString('cena zwycięzcy to 143 320,83 zł, a wpisano 140 000,00 zł', $result['conflicts'][2]);
        $this->assertStringContainsString('Uwaga: w częściach 2', $result['message']);
    }

    public function test_bzp_check_endpoint_returns_result_with_message_and_conflict(): void
    {
        $tender = $this->tender('2026/BZP 00361360');
        TenderLot::query()->create([
            'tender_id' => $tender->id,
            'lot_no' => 2,
            'outcome' => TenderLot::OUTCOME_WON,
            'currency' => 'PLN',
            'manual_fields' => ['outcome'],
        ]);
        Http::fake();
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/tenders/'.$tender->id.'/result/bzp-check')->assertOk();

        Http::assertNothingSent();
        $response->assertJsonPath('tender_id', $tender->id)
            ->assertJsonPath('bzp.result_notice.notice_number', '2026/BZP 00416394/01')
            ->assertJsonPath('lots.0.lot_no', 2)
            ->assertJsonPath('lots.0.outcome', 'won')
            ->assertJsonPath('lots.0.offers_count', 3);
        $this->assertStringContainsString('tę część wygrała firma Przedsiębiorstwo Wielobranżowe', (string) $response->json('lots.0.bzp_conflict'));
        $this->assertStringContainsString('Ogłoszenie o wyniku 2026/BZP 00416394/01', (string) $response->json('bzp_message'));
        $this->assertSame('won', $tender->refresh()->result_status);
    }

    public function test_bzp_check_requires_edit_permission_and_access(): void
    {
        $tender = $this->tender('2026/BZP 00361360');
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->postJson('/api/tenders/'.$tender->id.'/result/bzp-check')->assertForbidden();

        Sanctum::actingAs(User::factory()->withRole('dyrektor')->create());
        $this->postJson('/api/tenders/'.$tender->id.'/result/bzp-check')->assertForbidden();
        $this->assertSame(0, TenderLot::query()->count());
    }

    public function test_deadline_time_filled_only_for_the_same_day(): void
    {
        // ogłoszenie o zamówieniu: termin 24.09.2026 07:00 UTC = 9:00 w Polsce; wynik: unieważnienie
        $sameDay = $this->tender('2026/BZP 00439099/01', '2026-09-24');
        $otherDay = $this->tender('2026/BZP 00439099/01', '2026-09-25');
        $withTime = $this->tender('2026/BZP 00439099/01', '2026-09-24', '10:30');

        foreach ([$sameDay, $otherDay, $withTime] as $tender) {
            app(BzpTenderLinker::class)->link($tender);
        }

        $this->assertSame('09:00', $sameDay->refresh()->deadline_time);
        $this->assertNull($otherDay->refresh()->deadline_time);
        $this->assertSame('10:30', $withTime->refresh()->deadline_time);
        $this->assertSame('cancelled', $sameDay->result_status);
        // uzupełniona godzina jest w historii przetargu: źródło, numer ogłoszenia, przed i po
        $activity = TenderActivity::query()->where('tender_id', $sameDay->id)->where('action', 'updated')->sole();
        $this->assertSame('bzp', $activity->meta['source']);
        $this->assertSame('2026/BZP 00439099/01', $activity->meta['notice_number']);
        $this->assertSame(['deadline_time' => null], $activity->meta['before']);
        $this->assertSame(['deadline_time' => '09:00'], $activity->meta['after']);
        $this->assertSame(0, TenderActivity::query()->whereIn('tender_id', [$otherDay->id, $withTime->id])->where('action', 'updated')->count());
    }

    public function test_contract_notice_without_result_creates_lots_from_contract(): void
    {
        $tender = $this->tender('2026/BZP 00449679/01');

        $message = app(BzpTenderLinker::class)->linkTender($tender);

        $this->assertStringContainsString('Ogłoszenia o wyniku jeszcze nie ma', $message);
        $lots = TenderLot::query()->where('tender_id', $tender->id)->orderBy('lot_no')->get();
        $this->assertSame([1, 2, 3, 4, 5], $lots->pluck('lot_no')->all());
        $this->assertSame('101626.02', $lots[1]->estimated_value);
        $this->assertSame([null], $lots->pluck('outcome')->unique()->values()->all());
        // części założone przez łączenie z ogłoszeniem są oznaczone (raport skuteczności)
        $this->assertSame([true], $lots->pluck('created_by_bzp')->unique()->values()->all());
        $this->assertNull($tender->refresh()->result_status);
        $this->assertNotNull($tender->contract_notice_id);
        $this->assertNull($tender->result_notice_id);
    }

    public function test_messages_without_notice(): void
    {
        $linker = app(BzpTenderLinker::class);

        $this->assertStringContainsString('nie ma numeru ogłoszenia', $linker->linkTender($this->tender(null)));
        $this->assertStringContainsString('Dziennika Urzędowego Unii Europejskiej', $linker->linkTender($this->tender('606345-2026')));
        $missing = $this->tender('2026/BZP 00999999/01');
        $this->assertStringContainsString('nie ma w ogłoszeniach pobranych', $linker->linkTender($missing));
        $this->assertSame(0, TenderLot::query()->count());
        $this->assertNotNull($missing->refresh()->bzp_checked_at);
    }

    public function test_link_all_covers_only_tenders_with_bzp_numbers(): void
    {
        $this->tender('2026/BZP 00361360');
        $this->tender('2026/BZP 00376786');
        $this->tender('606345-2026');
        $this->tender(null);

        $stats = app(BzpTenderLinker::class)->linkAll();

        $this->assertSame(['tenders' => 2, 'linked' => 2, 'changed_lots' => 3, 'failed' => 0], $stats);
    }

    public function test_link_all_continues_after_an_error_and_counts_it(): void
    {
        Exceptions::fake();
        $broken = $this->tender('2026/BZP 00361360');
        $fine = $this->tender('2026/BZP 00376786');
        TenderLot::saving(static function (TenderLot $lot) use ($broken): void {
            if ((int) $lot->tender_id === (int) $broken->id) {
                throw new RuntimeException('Błąd zapisu części');
            }
        });

        $stats = app(BzpTenderLinker::class)->linkAll();

        $this->assertSame(['tenders' => 2, 'linked' => 1, 'changed_lots' => 1, 'failed' => 1], $stats);
        Exceptions::assertReported(RuntimeException::class);
        // transakcja przetargu z błędem wycofana w całości, drugi przetarg połączony
        $this->assertSame(0, TenderLot::query()->where('tender_id', $broken->id)->count());
        $broken->refresh();
        $this->assertNull($broken->contract_notice_id);
        $this->assertNull($broken->result_notice_id);
        $this->assertSame(1, TenderLot::query()->where('tender_id', $fine->id)->count());
    }

    public function test_lone_manual_lot_one_is_not_filled_from_notice_with_many_parts(): void
    {
        // opiekun zapisał wirtualną część 1 („cały przetarg”), zanim znaleziono ogłoszenie z dwiema częściami
        $tender = $this->tender('2026/BZP 00361360');
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/tenders/'.$tender->id.'/result', ['lots' => [['lot_no' => 1, 'our_net' => '120000.00', 'our_vat_rate' => '23']]])->assertOk();

        $response = $this->postJson('/api/tenders/'.$tender->id.'/result/bzp-check')->assertOk();

        // część 1 ogłoszenia (unieważniona) nie trafia do naszej części, część 2 nie jest zakładana
        $response->assertJsonCount(1, 'lots')
            ->assertJsonPath('lots.0.lot_no', 1)
            ->assertJsonPath('lots.0.outcome', null)
            ->assertJsonPath('lots.0.lowest_price', null)
            ->assertJsonPath('lots.0.bzp_notice_number', null)
            ->assertJsonPath('result_status', null);
        $this->assertStringContainsString('Ogłoszenie ma 2 części', (string) $response->json('lots.0.bzp_conflict'));
        $this->assertStringContainsString('Ustaw numer części zgodnie z ogłoszeniem, a wynik uzupełni się sam.', (string) $response->json('lots.0.bzp_conflict'));
        $this->assertStringContainsString('Ogłoszenie ma 2 części. Ustaw numer części zgodnie z ogłoszeniem, a wynik uzupełni się sam.', (string) $response->json('bzp_message'));
        $this->assertStringNotContainsString('Dane części są aktualne', (string) $response->json('bzp_message'));
        $this->assertSame(1, TenderActivity::query()->where('tender_id', $tender->id)->where('action', 'result_updated')->count());

        // opiekun ustawia numer części z ogłoszenia — następne sprawdzenie uzupełnia wynik tej części
        $lotId = (int) $response->json('lots.0.id');
        $this->putJson('/api/tenders/'.$tender->id.'/result', ['lots' => [['id' => $lotId, 'lot_no' => 2]]])->assertOk();
        $this->postJson('/api/tenders/'.$tender->id.'/result/bzp-check')
            ->assertOk()
            ->assertJsonCount(1, 'lots')
            ->assertJsonPath('lots.0.id', $lotId)
            ->assertJsonPath('lots.0.lot_no', 2)
            ->assertJsonPath('lots.0.winner_price', '143320.83')
            ->assertJsonPath('lots.0.our_net', '120000.00')
            ->assertJsonPath('lots.0.bzp_conflict', null);
    }

    public function test_lone_lot_one_conflict_is_shown_on_read_and_confirmed_number_gets_part_one(): void
    {
        $tender = $this->tender('2026/BZP 00361360');
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/tenders/'.$tender->id.'/result', ['lots' => [['lot_no' => 1, 'our_net' => '120000.00', 'our_vat_rate' => '23']]])->assertOk();
        $this->postJson('/api/tenders/'.$tender->id.'/result/bzp-check')->assertOk();

        // zwykły odczyt wyniku (bez „Sprawdź w Biuletynie”) pokazuje prośbę o numer części; dane nie są wpisane
        $read = $this->getJson('/api/tenders/'.$tender->id.'/result')->assertOk()
            ->assertJsonCount(1, 'lots')
            ->assertJsonPath('lots.0.outcome', null)
            ->assertJsonPath('lots.0.lowest_price', null)
            ->assertJsonPath('lots.0.bzp_notice_number', null);
        $this->assertSame(BzpTenderLinker::unnumberedConflict(2), $read->json('lots.0.bzp_conflict'));
        $lotId = (int) $read->json('lots.0.id');

        // „Tak, startowaliśmy w części 1 ogłoszenia” — potwierdzenie numeru, prośba znika
        $this->putJson('/api/tenders/'.$tender->id.'/result', ['lots' => [['id' => $lotId, 'lot_no' => 1, 'lot_no_confirmed' => true]]])
            ->assertOk()
            ->assertJsonPath('lots.0.manual_fields', ['our_net', 'our_vat_rate', TenderLot::LOT_NO_CONFIRMED])
            ->assertJsonPath('lots.0.bzp_conflict', null);
        $confirmLog = TenderActivity::query()->where('tender_id', $tender->id)->where('action', 'result_updated')->latest('id')->firstOrFail();
        $this->assertTrue($confirmLog->meta['lots'][0]['lot_no_confirmed']);

        // sprawdzenie wpisuje dane części 1 ogłoszenia (unieważniona), część 2 nie jest zakładana
        $checked = $this->postJson('/api/tenders/'.$tender->id.'/result/bzp-check')->assertOk()
            ->assertJsonCount(1, 'lots')
            ->assertJsonPath('lots.0.outcome', TenderLot::OUTCOME_CANCELLED)
            ->assertJsonPath('lots.0.lowest_price', '1365178.85')
            ->assertJsonPath('lots.0.our_net', '120000.00')
            ->assertJsonPath('lots.0.bzp_conflict', null);
        $this->assertStringNotContainsString('Ustaw numer części', (string) $checked->json('bzp_message'));

        // edycja innego pola nie gubi potwierdzenia
        $this->putJson('/api/tenders/'.$tender->id.'/result', ['lots' => [['id' => $lotId, 'lot_no' => 1, 'note' => 'Zamawiający unieważnił']]])
            ->assertOk()
            ->assertJsonPath('lots.0.manual_fields', ['our_net', 'our_vat_rate', 'note', TenderLot::LOT_NO_CONFIRMED]);

        // zmiana numeru części kasuje potwierdzenie i dane Biuletynu poprzedniej części (wpisy człowieka zostają)
        $this->putJson('/api/tenders/'.$tender->id.'/result', ['lots' => [['id' => $lotId, 'lot_no' => 2]]])
            ->assertOk()
            ->assertJsonPath('lots.0.manual_fields', ['our_net', 'our_vat_rate', 'note'])
            ->assertJsonPath('lots.0.outcome', null)
            ->assertJsonPath('lots.0.lowest_price', null)
            ->assertJsonPath('lots.0.bzp_notice_number', null)
            ->assertJsonPath('lots.0.our_net', '120000.00')
            ->assertJsonPath('lots.0.bzp_conflict', null);
        $this->assertNull($tender->refresh()->result_status);
        // powrót do numeru 1 bez potwierdzenia — znowu prośba o numer części
        $this->putJson('/api/tenders/'.$tender->id.'/result', ['lots' => [['id' => $lotId, 'lot_no' => 1]]])
            ->assertOk()
            ->assertJsonPath('lots.0.bzp_conflict', BzpTenderLinker::unnumberedConflict(2));

        // po numerze 2 następne sprawdzenie wpisuje dane części 2
        $this->putJson('/api/tenders/'.$tender->id.'/result', ['lots' => [['id' => $lotId, 'lot_no' => 2]]])->assertOk();
        $this->postJson('/api/tenders/'.$tender->id.'/result/bzp-check')->assertOk()
            ->assertJsonPath('lots.0.winner_price', '143320.83')
            ->assertJsonPath('lots.0.outcome', null);
    }

    public function test_lot_number_confirmation_must_be_true_or_false(): void
    {
        $tender = $this->tender('2026/BZP 00361360');
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/tenders/'.$tender->id.'/result', ['lots' => [['lot_no' => 1, 'lot_no_confirmed' => 'może']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lots.0.lot_no_confirmed');
        $this->putJson('/api/tenders/'.$tender->id.'/result', ['lots' => [['lot_no' => 1, 'lot_no_confirmed' => true]]])
            ->assertOk()
            ->assertJsonPath('lots.0.manual_fields', [TenderLot::LOT_NO_CONFIRMED]);
        $lotId = (int) TenderLot::query()->where('tender_id', $tender->id)->value('id');
        $this->putJson('/api/tenders/'.$tender->id.'/result', ['lots' => [['id' => $lotId, 'lot_no' => 1, 'lot_no_confirmed' => false]]])
            ->assertOk()
            ->assertJsonPath('lots.0.manual_fields', []);
    }

    public function test_changing_to_another_notice_drops_lot_number_confirmation(): void
    {
        $tender = $this->tender('2026/BZP 00361360');
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/tenders/'.$tender->id.'/result', ['lots' => [['lot_no' => 1, 'our_net' => '120000.00', 'lot_no_confirmed' => true]]])->assertOk();
        app(BzpTenderLinker::class)->link($tender->refresh());
        $lot = TenderLot::query()->where('tender_id', $tender->id)->sole();
        $this->assertNotNull($lot->bzp_notice_id);

        $this->patchJson('/api/tenders/'.$tender->id, ['notice_number' => '2026/BZP 00376786'])->assertOk();

        $lot->refresh();
        $this->assertSame(['our_net'], $lot->manual_fields);
        $this->assertNull($lot->bzp_notice_id);
        $this->assertNull($lot->outcome);
        $this->assertSame('120000.00', $lot->our_net);
    }

    public function test_manual_lots_one_and_two_then_deleting_two_leaves_lone_lot_one_in_conflict(): void
    {
        $tender = $this->tender('2026/BZP 00361360');
        Sanctum::actingAs($this->owner);
        $saved = $this->putJson('/api/tenders/'.$tender->id.'/result', ['lots' => [
            ['lot_no' => 1, 'our_net' => '1000.00'],
            ['lot_no' => 2, 'our_net' => '2000.00'],
        ]])->assertOk();
        $lot2 = collect($saved->json('lots'))->firstWhere('lot_no', 2);
        $this->deleteJson('/api/tenders/'.$tender->id.'/result/lots/'.$lot2['id'])->assertOk();

        $this->postJson('/api/tenders/'.$tender->id.'/result/bzp-check')->assertOk()
            ->assertJsonCount(1, 'lots')
            ->assertJsonPath('lots.0.outcome', null)
            ->assertJsonPath('lots.0.bzp_conflict', BzpTenderLinker::unnumberedConflict(2));
        $this->getJson('/api/tenders/'.$tender->id.'/result')->assertOk()
            ->assertJsonPath('lots.0.bzp_conflict', BzpTenderLinker::unnumberedConflict(2));
    }

    public function test_adding_notice_version_keeps_lots_data_and_links(): void
    {
        $tender = $this->tender('2026/BZP 00361360');
        app(BzpTenderLinker::class)->link($tender);
        $linked = $tender->refresh();
        $this->assertNotNull($linked->result_notice_id);
        Sanctum::actingAs($this->owner);

        // „/01” = to samo postępowanie: części, dane z Biuletynu i powiązania zostają, noc sprawdzi przetarg na nowo
        $this->patchJson('/api/tenders/'.$tender->id, ['notice_number' => '2026/BZP 00361360/01'])
            ->assertOk()
            ->assertJsonPath('notice_number', '2026/BZP 00361360/01');

        $fresh = $tender->refresh();
        $this->assertSame((int) $linked->result_notice_id, (int) $fresh->result_notice_id);
        $this->assertSame($linked->contract_notice_id, $fresh->contract_notice_id);
        $this->assertNull($fresh->bzp_checked_at);
        $lots = TenderLot::query()->where('tender_id', $tender->id)->orderBy('lot_no')->get();
        $this->assertSame([1, 2], $lots->pluck('lot_no')->all());
        $this->assertSame('143320.83', $lots[1]->winner_price);
        $this->assertSame(0, TenderActivity::query()->where('tender_id', $tender->id)->where('meta->source', 'notice_number_changed')->count());
    }

    public function test_lots_deleted_by_a_person_do_not_return_after_notice_version_change(): void
    {
        $tender = $this->tender('2026/BZP 00361360');
        app(BzpTenderLinker::class)->link($tender);
        Sanctum::actingAs($this->owner);
        foreach (TenderLot::query()->where('tender_id', $tender->id)->pluck('id') as $lotId) {
            $this->deleteJson('/api/tenders/'.$tender->id.'/result/lots/'.$lotId)->assertOk();
        }

        $this->patchJson('/api/tenders/'.$tender->id, ['notice_number' => '2026/BZP 00361360/01'])->assertOk();
        app(BzpTenderLinker::class)->link($tender->refresh());
        app(BzpTenderLinker::class)->linkAll();

        $this->assertSame(0, TenderLot::query()->where('tender_id', $tender->id)->count());
    }

    public function test_changing_to_another_procedure_needs_offer_editing_when_bulletin_data_exists(): void
    {
        $tender = $this->tender('2026/BZP 00361360');
        app(BzpTenderLinker::class)->link($tender);
        Role::findOrCreate('tylko-zakladanie', 'web')->syncPermissions(['tenders.view_own', 'tenders.create']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $creator = User::factory()->create();
        $creator->assignRole('tylko-zakladanie');
        $tender->forceFill(['owner_id' => $creator->id])->save();
        $this->assertFalse($creator->can('tenders.edit_offer'));
        Sanctum::actingAs($creator);

        $this->patchJson('/api/tenders/'.$tender->id, ['notice_number' => '2026/BZP 00376786'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Zmiana numeru ogłoszenia usunie dane pobrane z Biuletynu Zamówień Publicznych. Może to zrobić osoba z uprawnieniem do edycji oferty.');
        $fresh = $tender->refresh();
        $this->assertSame('2026/BZP 00361360', $fresh->notice_number);
        $this->assertNotNull($fresh->result_notice_id);
        $this->assertSame(2, TenderLot::query()->where('tender_id', $tender->id)->whereNotNull('bzp_notice_id')->count());

        // sama wersja numeru nie usuwa danych — bez uprawnienia do edycji oferty
        $this->patchJson('/api/tenders/'.$tender->id, ['notice_number' => '2026/BZP 00361360/01'])->assertOk();
        // przetarg bez danych z Biuletynu — zmiana numeru bez ograniczeń
        $plain = $this->tender('2026/BZP 00999999/01');
        $plain->forceFill(['owner_id' => $creator->id])->save();
        $this->patchJson('/api/tenders/'.$plain->id, ['notice_number' => '2026/BZP 00376786'])->assertOk();
        // osoba z edycją oferty zmienia postępowanie mimo danych z Biuletynu
        Sanctum::actingAs($this->owner);
        $tender->forceFill(['owner_id' => $this->owner->id])->save();
        $this->patchJson('/api/tenders/'.$tender->id, ['notice_number' => '2026/BZP 00376786'])->assertOk();
        $this->assertSame(0, TenderLot::query()->where('tender_id', $tender->id)->whereNotNull('bzp_notice_id')->count());
    }

    public function test_manual_lot_one_is_filled_when_notice_has_one_part(): void
    {
        $tender = $this->tender('2026/BZP 00376786');
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/tenders/'.$tender->id.'/result', ['lots' => [['lot_no' => 1, 'our_net' => '30000.00', 'our_vat_rate' => '23']]])->assertOk();

        $result = app(BzpTenderLinker::class)->link($tender->refresh());

        $this->assertSame(1, $result['changed_lots']);
        $this->assertSame([], $result['conflicts']);
        $lot = TenderLot::query()->where('tender_id', $tender->id)->sole();
        $this->assertSame('31740.15', $lot->winner_price);
        $this->assertSame('30000.00', $lot->our_net);
        $this->assertNotNull($lot->bzp_notice_id);
        $this->assertFalse($lot->created_by_bzp, 'Część założona ręcznie zostaje ręczna.');
    }

    public function test_virtual_lot_saved_together_with_new_part_is_numbered_by_notice(): void
    {
        // ekran dołącza wirtualną część 1 do zapisu nowej części 2 — obie mają numery z ogłoszenia
        $tender = $this->tender('2026/BZP 00361360');
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/tenders/'.$tender->id.'/result', ['lots' => [
            ['id' => null, 'lot_no' => 1],
            ['lot_no' => 2, 'our_net' => '120000.00', 'our_vat_rate' => '23'],
        ]])->assertOk()->assertJsonCount(2, 'lots');

        $result = app(BzpTenderLinker::class)->link($tender->refresh());

        $this->assertSame([], $result['conflicts']);
        [$lot1, $lot2] = TenderLot::query()->where('tender_id', $tender->id)->orderBy('lot_no')->get()->all();
        $this->assertSame(TenderLot::OUTCOME_CANCELLED, $lot1->outcome);
        $this->assertSame('143320.83', $lot2->winner_price);
        $this->assertSame('120000.00', $lot2->our_net);
    }

    public function test_part_deleted_by_a_person_is_not_created_again(): void
    {
        $tender = $this->tender('2026/BZP 00361360');
        app(BzpTenderLinker::class)->link($tender);
        Sanctum::actingAs($this->owner);
        // usunięta jedna część — nie wraca
        $lot1 = TenderLot::query()->where('tender_id', $tender->id)->where('lot_no', 1)->sole();
        $this->deleteJson('/api/tenders/'.$tender->id.'/result/lots/'.$lot1->id)->assertOk();
        app(BzpTenderLinker::class)->link($tender->refresh());
        $this->assertSame([2], TenderLot::query()->where('tender_id', $tender->id)->pluck('lot_no')->all());

        // usunięte wszystkie części — też nie wracają (ani przy sprawdzeniu, ani w nocnym przebiegu)
        $lot2 = TenderLot::query()->where('tender_id', $tender->id)->sole();
        $this->deleteJson('/api/tenders/'.$tender->id.'/result/lots/'.$lot2->id)->assertOk();
        app(BzpTenderLinker::class)->link($tender->refresh());
        app(BzpTenderLinker::class)->linkAll();

        $this->assertSame(0, TenderLot::query()->where('tender_id', $tender->id)->count());
    }

    public function test_bulletin_outcome_is_reverted_when_newer_data_names_another_winner(): void
    {
        config(['bzp.our_company.nip' => '118-16-25-269', 'bzp.our_company.names' => ['SUPON']]);
        $tender = $this->tender('2026/BZP 00376786');
        app(BzpTenderLinker::class)->link($tender);
        $this->assertSame('won', $tender->refresh()->result_status);

        // ponowny odczyt: zwycięzca to nie nasza firma — „wygrana” wpisana przez Biuletyn wraca do opiekuna
        config(['bzp.our_company.nip' => '813-228-37-37']);
        $result = app(BzpTenderLinker::class)->link($tender->refresh());

        $lot = TenderLot::query()->where('tender_id', $tender->id)->sole();
        $this->assertSame(1, $result['changed_lots']);
        $this->assertNull($lot->outcome);
        $this->assertNull($lot->decided_at);
        $this->assertSame('31740.15', $lot->winner_price);
        $this->assertNull($tender->refresh()->result_status);
        $activity = TenderActivity::query()->where('tender_id', $tender->id)->where('action', 'result_updated')->latest('id')->firstOrFail();
        $this->assertSame(['outcome'], $activity->meta['lots'][0]['fields']);
        $this->assertSame('won', $activity->meta['result_status_before']);
        $this->assertNull($activity->meta['result_status_after']);
    }

    public function test_outcome_entered_by_a_person_is_not_reverted(): void
    {
        $tender = $this->tender('2026/BZP 00376786');
        $lot = TenderLot::query()->create([
            'tender_id' => $tender->id,
            'lot_no' => 1,
            'outcome' => TenderLot::OUTCOME_WON,
            'currency' => 'PLN',
            'manual_fields' => ['outcome'],
            'decided_by' => $this->owner->id,
        ]);

        $result = app(BzpTenderLinker::class)->link($tender);

        $this->assertSame(TenderLot::OUTCOME_WON, $lot->refresh()->outcome);
        $this->assertSame($this->owner->id, $lot->decided_by);
        $this->assertStringContainsString('a wpisano wynik „wygrana”', $result['conflicts'][1]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/bzp/'.$name)), true);
    }

    private function tender(?string $notice, string $deadline = '2026-08-20', ?string $time = null): Tender
    {
        static $n = 0;
        $n++;

        return Tender::query()->create([
            'number' => 'PRZ/2026/'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'title' => 'Dostawa odzieży i obuwia roboczego',
            'client_id' => Client::query()->firstOrCreate(['name' => 'Wojskowy Oddział Gospodarczy'])->id,
            'owner_id' => $this->owner->id,
            'status' => 'exported',
            'ai_percent' => 0,
            'deadline' => $deadline,
            'deadline_time' => $time,
            'notice_number' => $notice,
        ]);
    }
}
