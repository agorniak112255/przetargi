<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Competitor;
use App\Models\ProcurementNotice;
use App\Models\Tender;
use App\Models\TenderActivity;
use App\Models\TenderInvitation;
use App\Models\TenderLot;
use App\Models\TenderLotOffer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Wynik przetargu per część zamówienia: GET/PUT /tenders/{id}/result, DELETE …/result/lots/{lot}.
 */
final class TenderResultApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tender $tender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->owner = User::factory()->withRole('handlowiec')->create(['name' => 'Piotr Wiśniewski']);
        $this->tender = $this->makeTender('PRZ/2026/0001');
    }

    public function test_tender_without_lots_returns_virtual_lot(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('tender_id', $this->tender->id)
            ->assertJsonPath('result_status', null)
            ->assertJsonPath('can_edit', true)
            ->assertJsonPath('bzp.contract_notice', null)
            ->assertJsonPath('bzp.result_notice', null)
            ->assertJsonCount(1, 'lots')
            ->assertJsonPath('lots.0.id', null)
            ->assertJsonPath('lots.0.lot_no', 1)
            ->assertJsonPath('lots.0.currency', 'PLN')
            ->assertJsonPath('lots.0.manual_fields', [])
            ->assertJsonPath('lots.0.offers', []);

        $this->assertSame(0, TenderLot::query()->count());
    }

    public function test_saving_virtual_lot_creates_it_and_next_save_updates_same_row(): void
    {
        Sanctum::actingAs($this->owner);

        $first = $this->putJson($this->url(), ['lots' => [['id' => null, 'lot_no' => 1, 'name' => 'Odzież robocza', 'outcome' => 'won']]])
            ->assertOk()
            ->assertJsonPath('result_status', 'won')
            ->assertJsonPath('lots.0.name', 'Odzież robocza')
            ->assertJsonPath('lots.0.decided_by.name', 'Piotr Wiśniewski');
        $lotId = $first->json('lots.0.id');
        $this->assertIsInt($lotId);

        // po id
        $this->putJson($this->url(), ['lots' => [['id' => $lotId, 'lot_no' => 1, 'name' => 'Odzież robocza letnia']]])
            ->assertOk()
            ->assertJsonPath('lots.0.id', $lotId)
            ->assertJsonPath('lots.0.name', 'Odzież robocza letnia');
        // po numerze części, bez id
        $this->putJson($this->url(), ['lots' => [['lot_no' => 1, 'note' => 'Najtańsi']]])
            ->assertOk()
            ->assertJsonPath('lots.0.id', $lotId)
            ->assertJsonPath('lots.0.name', 'Odzież robocza letnia')
            ->assertJsonPath('lots.0.note', 'Najtańsi');
        // nowy numer = nowa część; część 1 bez zmian
        $this->putJson($this->url(), ['lots' => [['lot_no' => 2, 'name' => 'Obuwie robocze']]])
            ->assertOk()
            ->assertJsonCount(2, 'lots')
            ->assertJsonPath('lots.0.lot_no', 1)
            ->assertJsonPath('lots.1.lot_no', 2);

        $this->assertSame(2, TenderLot::query()->where('tender_id', $this->tender->id)->count());
        $this->assertSame('won', $this->tender->fresh()->result_status);
    }

    public function test_gross_is_computed_explicitly_and_price_gap_compares_gross_prices(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson($this->url(), ['lots' => [
            ['lot_no' => 1, 'our_net' => '74310', 'our_vat_rate' => 23, 'outcome' => 'won'],
            [
                'lot_no' => 2,
                'our_net' => '54 630,00',
                'our_vat_rate' => '23',
                'outcome' => 'lost',
                'loss_reason' => 'price',
                'winner' => ['name' => 'BHP-Pro Handel sp. z o.o.'],
                'winner_price' => '62900.00',
                'offers_count' => 3,
            ],
            ['lot_no' => 3, 'our_net' => '0.05', 'our_vat_rate' => '8'],
            ['lot_no' => 4, 'our_net' => '100.00'],
        ]])
            ->assertOk()
            ->assertJsonPath('result_status', 'partial')
            ->assertJsonPath('lots.0.our_net', '74310.00')
            ->assertJsonPath('lots.0.our_gross', '91401.30')
            ->assertJsonPath('lots.0.price_gap', null)
            ->assertJsonPath('lots.1.our_gross', '67194.90')
            ->assertJsonPath('lots.1.price_gap.amount', '4294.90')
            ->assertJsonPath('lots.1.price_gap.percent', 6.4)
            ->assertJsonPath('lots.1.winner.name', 'BHP-Pro Handel sp. z o.o.')
            ->assertJsonPath('lots.1.offers_count', 3)
            // 0,05 × 1,08 = 0,054 → 0,05 (zaokrąglenie do grosza)
            ->assertJsonPath('lots.2.our_gross', '0.05')
            // bez stawki VAT brutto nie jest zgadywane
            ->assertJsonPath('lots.3.our_gross', null);
    }

    public function test_price_gap_is_not_computed_across_currencies(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson($this->url(), ['lots' => [[
            'lot_no' => 1, 'our_net' => '100', 'our_vat_rate' => '23', 'winner_price' => '90', 'currency' => 'eur',
        ]]])
            ->assertOk()
            ->assertJsonPath('lots.0.currency', 'EUR')
            ->assertJsonPath('lots.0.our_gross', '123.00')
            ->assertJsonPath('lots.0.price_gap', null);
    }

    public function test_manual_fields_record_only_values_changed_by_a_person(): void
    {
        Sanctum::actingAs($this->owner);
        $payload = ['lot_no' => 1, 'outcome' => 'lost', 'loss_reason' => 'price', 'our_net' => '100.00', 'our_vat_rate' => '23', 'note' => null];

        $this->putJson($this->url(), ['lots' => [$payload]])
            ->assertOk()
            ->assertJsonPath('lots.0.manual_fields', ['our_net', 'our_vat_rate', 'outcome', 'loss_reason']);

        $lot = TenderLot::query()->firstOrFail();
        $decidedAt = $lot->decided_at;
        $activities = TenderActivity::query()->where('action', 'result_updated')->count();

        // te same wartości w innym zapisie — bez zmian, bez nowego wpisu w historii
        $this->travel(5)->minutes();
        $this->putJson($this->url(), ['lots' => [[...$payload, 'id' => $lot->id, 'our_net' => 100, 'our_vat_rate' => '23.00']]])
            ->assertOk()
            ->assertJsonPath('lots.0.manual_fields', ['our_net', 'our_vat_rate', 'outcome', 'loss_reason']);
        $this->assertSame($activities, TenderActivity::query()->where('action', 'result_updated')->count());
        $this->assertEquals($decidedAt, $lot->fresh()->decided_at);

        $this->putJson($this->url(), ['lots' => [['id' => $lot->id, 'lot_no' => 1, 'offers_count' => 4]]])
            ->assertOk()
            ->assertJsonPath('lots.0.manual_fields', ['our_net', 'our_vat_rate', 'outcome', 'offers_count', 'loss_reason']);
    }

    public function test_bulletin_fields_are_marked_until_a_person_changes_them(): void
    {
        $notice = $this->resultNotice();
        $winner = Competitor::query()->create(['name' => 'Ochrona Plus sp. z o.o.', 'name_key' => 'ochrona plus', 'nip' => '5260250274']);
        $lot = TenderLot::query()->create([
            'tender_id' => $this->tender->id,
            'lot_no' => 1,
            'winner_competitor_id' => $winner->id,
            'winner_price' => '62900.00',
            'offers_count' => 3,
            'lowest_price' => '62900.00',
            'highest_price' => '69870.00',
            'bzp_notice_id' => $notice->id,
            'bzp_applied_at' => now(),
        ]);
        $this->tender->forceFill(['result_notice_id' => $notice->id])->save();
        Sanctum::actingAs($this->owner);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('lots.0.bzp_fields', ['winner', 'winner_price', 'offers_count', 'lowest_price', 'highest_price'])
            ->assertJsonPath('lots.0.bzp_notice_number', '2026/BZP 00461230/01')
            ->assertJsonPath('lots.0.bzp_conflict', null)
            ->assertJsonPath('bzp.result_notice.notice_number', '2026/BZP 00461230/01')
            ->assertJsonPath('bzp.result_notice.url', 'https://ezamowienia.gov.pl/mo-client-board/bzp/notice-details/id/08df0819-6a33-b243-ab56-9400018aa89f');

        // człowiek poprawia liczbę ofert i dopisuje wynik — reszta pól z Biuletynu bez zmian
        $this->putJson($this->url(), ['lots' => [[
            'id' => $lot->id,
            'lot_no' => 1,
            'winner' => ['competitor_id' => $winner->id],
            'winner_price' => '62900',
            'offers_count' => 4,
            'outcome' => 'lost',
            'loss_reason' => 'price',
        ]]])
            ->assertOk()
            ->assertJsonPath('lots.0.manual_fields', ['outcome', 'offers_count', 'loss_reason'])
            ->assertJsonPath('lots.0.bzp_fields', ['winner', 'winner_price', 'lowest_price', 'highest_price'])
            ->assertJsonPath('lots.0.winner.nip', '5260250274');
    }

    public function test_loss_reason_only_with_lost_outcome(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson($this->url(), ['lots' => [['lot_no' => 1, 'outcome' => 'won', 'loss_reason' => 'price']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lots.0.loss_reason');
        $this->assertSame(0, TenderLot::query()->count());

        $this->putJson($this->url(), ['lots' => [['lot_no' => 1, 'outcome' => 'lost', 'loss_reason' => 'delivery']]])
            ->assertOk()
            ->assertJsonPath('lots.0.loss_reason', 'delivery');

        // zmiana wyniku z przegranej na unieważnioną czyści powód
        $this->putJson($this->url(), ['lots' => [['lot_no' => 1, 'outcome' => 'cancelled']]])
            ->assertOk()
            ->assertJsonPath('lots.0.loss_reason', null)
            ->assertJsonPath('result_status', 'cancelled');
    }

    public function test_validation_of_amounts_vat_and_codes(): void
    {
        Sanctum::actingAs($this->owner);

        foreach ([
            ['our_vat_rate', '101'],
            ['our_vat_rate', '-1'],
            ['our_net', '100.123'],
            ['our_net', '-5'],
            ['our_net', 'sto'],
            ['winner_price', '1e5'],
            ['outcome', 'wygrana'],
            ['loss_reason', 'drogo'],
            ['cpv_main', '1810'],
            ['currency', 'ZŁ'],
        ] as [$field, $value]) {
            $this->putJson($this->url(), ['lots' => [['lot_no' => 1, $field => $value]]])
                ->assertStatus(422)
                ->assertJsonValidationErrors("lots.0.$field");
        }
        $this->putJson($this->url(), ['lots' => []])->assertStatus(422)->assertJsonValidationErrors('lots');
        $this->putJson($this->url(), ['lots' => [['name' => 'bez numeru']]])->assertStatus(422)->assertJsonValidationErrors('lots.0.lot_no');
        $this->putJson($this->url(), ['lots' => [['lot_no' => 1], ['lot_no' => 1]]])->assertStatus(422);

        $this->putJson($this->url(), ['lots' => [['lot_no' => 1, 'our_vat_rate' => '100', 'cpv_main' => '18100000-0']]])
            ->assertOk()
            ->assertJsonPath('lots.0.our_vat_rate', '100.00')
            ->assertJsonPath('lots.0.cpv_main', '18100000-0');
        $this->assertSame(1, TenderLot::query()->count());
    }

    public function test_lot_of_another_tender_cannot_be_updated_by_id(): void
    {
        $other = $this->makeTender('PRZ/2026/0002');
        $foreignLot = TenderLot::query()->create(['tender_id' => $other->id, 'lot_no' => 1, 'name' => 'Cudza']);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson($this->url(), ['lots' => [['id' => $foreignLot->id, 'lot_no' => 1, 'name' => 'Przejęta']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lots.0.id');
        $this->assertSame('Cudza', $foreignLot->fresh()->name);
    }

    public function test_lot_numbers_can_be_swapped_in_one_save(): void
    {
        Sanctum::actingAs($this->owner);
        $a = TenderLot::query()->create(['tender_id' => $this->tender->id, 'lot_no' => 1, 'name' => 'A']);
        $b = TenderLot::query()->create(['tender_id' => $this->tender->id, 'lot_no' => 2, 'name' => 'B']);

        $this->putJson($this->url(), ['lots' => [['id' => $a->id, 'lot_no' => 2], ['id' => $b->id, 'lot_no' => 1]]])
            ->assertOk()
            ->assertJsonPath('lots.0.name', 'B')
            ->assertJsonPath('lots.1.name', 'A');

        // numer zajęty przez część spoza żądania
        $this->putJson($this->url(), ['lots' => [['id' => $a->id, 'lot_no' => 1]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lots.0.lot_no');
    }

    public function test_other_salesperson_has_no_access(): void
    {
        TenderLot::query()->create(['tender_id' => $this->tender->id, 'lot_no' => 1]);
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->getJson($this->url())->assertForbidden();
        $this->putJson($this->url(), ['lots' => [['lot_no' => 1, 'outcome' => 'won']]])->assertForbidden();
        $this->assertNull(TenderLot::query()->firstOrFail()->outcome);
    }

    public function test_invited_salesperson_can_edit_result(): void
    {
        $invited = User::factory()->withRole('handlowiec')->create();
        TenderInvitation::query()->create([
            'tender_id' => $this->tender->id,
            'user_id' => $invited->id,
            'invited_by' => $this->owner->id,
        ]);
        Sanctum::actingAs($invited);

        $this->putJson($this->url(), ['lots' => [['lot_no' => 1, 'outcome' => 'not_submitted']]])
            ->assertOk()
            ->assertJsonPath('result_status', 'not_submitted');
    }

    public function test_decision_author_changes_only_when_outcome_changes(): void
    {
        Sanctum::actingAs($this->owner);
        $lotId = $this->putJson($this->url(), ['lots' => [['lot_no' => 1, 'outcome' => 'won']]])->assertOk()->json('lots.0.id');
        $decidedAt = TenderLot::query()->findOrFail($lotId)->decided_at;

        $invited = User::factory()->withRole('handlowiec')->create(['name' => 'Ewa Kowalska']);
        TenderInvitation::query()->create(['tender_id' => $this->tender->id, 'user_id' => $invited->id, 'invited_by' => $this->owner->id]);
        Sanctum::actingAs($invited);
        $this->travel(10)->minutes();

        // notatka i nasza cena to nie rozstrzygnięcie — autor i chwila wyniku bez zmian
        $this->putJson($this->url(), ['lots' => [['id' => $lotId, 'lot_no' => 1, 'outcome' => 'won', 'note' => 'Umowa podpisana', 'our_net' => '100.00']]])
            ->assertOk()
            ->assertJsonPath('lots.0.decided_by.name', 'Piotr Wiśniewski');
        $this->assertEquals($decidedAt, TenderLot::query()->findOrFail($lotId)->decided_at);

        // zmiana wyniku — nowy autor i chwila
        $this->putJson($this->url(), ['lots' => [['id' => $lotId, 'lot_no' => 1, 'outcome' => 'cancelled']]])
            ->assertOk()
            ->assertJsonPath('lots.0.decided_by.name', 'Ewa Kowalska');
        $this->assertNotEquals($decidedAt, TenderLot::query()->findOrFail($lotId)->decided_at);

        // wyczyszczenie wyniku czyści autora
        $this->putJson($this->url(), ['lots' => [['id' => $lotId, 'lot_no' => 1, 'outcome' => null]]])
            ->assertOk()
            ->assertJsonPath('lots.0.decided_by', null)
            ->assertJsonPath('lots.0.decided_at', null);
    }

    public function test_user_without_offer_editing_sees_but_cannot_save(): void
    {
        $lot = TenderLot::query()->create(['tender_id' => $this->tender->id, 'lot_no' => 1]);

        // dyrektor widzi wszystkie przetargi, ale nie edytuje oferty
        Sanctum::actingAs(User::factory()->withRole('dyrektor')->create());
        $this->getJson($this->url())->assertOk()->assertJsonPath('can_edit', false);
        $this->putJson($this->url(), ['lots' => [['lot_no' => 1, 'outcome' => 'won']]])->assertForbidden();
        $this->deleteJson($this->url()."/lots/{$lot->id}")->assertForbidden();

        // opiekun bez uprawnienia do edycji oferty
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('tenders.view_own');
        $this->tender->forceFill(['owner_id' => $viewer->id])->save();
        Sanctum::actingAs($viewer);
        $this->getJson($this->url())->assertOk()->assertJsonPath('can_edit', false);
        $this->putJson($this->url(), ['lots' => [['lot_no' => 1, 'outcome' => 'won']]])->assertForbidden();
        $this->assertNull($lot->fresh()->outcome);
    }

    public function test_result_status_matrix_through_api(): void
    {
        Sanctum::actingAs($this->owner);
        $cases = [
            [['won'], 'won'],
            [['won', 'won'], 'won'],
            [['won', 'lost'], 'partial'],
            [['lost'], 'lost'],
            [['lost', 'cancelled'], 'lost'],
            [['won', 'cancelled'], 'won'],
            [['won', null], 'won'],
            [['cancelled', 'cancelled'], 'cancelled'],
            [['not_submitted'], 'not_submitted'],
            [['not_submitted', 'cancelled'], 'not_submitted'],
            [['cancelled', null], null],
            [[null], null],
        ];

        foreach ($cases as $n => [$outcomes, $expected]) {
            $tender = $this->makeTender('PRZ/2026/1'.str_pad((string) $n, 3, '0', STR_PAD_LEFT));
            $lots = [];
            foreach ($outcomes as $i => $outcome) {
                $lots[] = ['lot_no' => $i + 1, 'outcome' => $outcome];
            }
            $this->putJson("/api/tenders/{$tender->id}/result", ['lots' => $lots])
                ->assertOk()
                ->assertJsonPath('result_status', $expected);
            $this->assertSame($expected, $tender->fresh()->result_status, 'przypadek '.json_encode($outcomes));
            $this->getJson("/api/tenders/{$tender->id}")->assertJsonPath('tender.result_status', $expected);
        }
    }

    public function test_offers_of_other_companies_are_replaced_in_full(): void
    {
        Sanctum::actingAs($this->owner);
        $known = Competitor::query()->create(['name' => 'BHP-Pro Handel sp. z o.o.', 'name_key' => 'bhp pro handel', 'nip' => null]);

        $response = $this->putJson($this->url(), ['lots' => [[
            'lot_no' => 1,
            'offers' => [
                ['competitor_id' => $known->id, 'price' => '62 900,00'],
                ['name' => 'Ochrona Plus sp. z o.o.', 'nip' => '526-025-02-74', 'price' => 69870],
            ],
        ]]])
            ->assertOk()
            ->assertJsonCount(2, 'lots.0.offers')
            ->assertJsonPath('lots.0.offers.0.competitor.name', 'BHP-Pro Handel sp. z o.o.')
            ->assertJsonPath('lots.0.offers.0.price', '62900.00')
            ->assertJsonPath('lots.0.offers.0.source', 'manual')
            ->assertJsonPath('lots.0.offers.1.competitor.nip', '5260250274')
            ->assertJsonPath('lots.0.offers.1.currency', 'PLN');
        $keptId = $response->json('lots.0.offers.0.id');

        // nowa lista: BHP-Pro zostaje (ten sam wiersz), Ochrona Plus znika, dochodzi nowa firma
        $this->putJson($this->url(), ['lots' => [[
            'lot_no' => 1,
            'offers' => [
                ['competitor_id' => $known->id, 'price' => '62900'],
                ['name' => 'Odzież Pro s.c.', 'price' => '70000'],
            ],
        ]]])
            ->assertOk()
            ->assertJsonCount(2, 'lots.0.offers')
            ->assertJsonPath('lots.0.offers.0.id', $keptId)
            ->assertJsonPath('lots.0.offers.1.competitor.name', 'Odzież Pro s.c.');

        // ta sama firma dwa razy (inny zapis nazwy) — błąd
        $this->putJson($this->url(), ['lots' => [[
            'lot_no' => 1,
            'offers' => [
                ['competitor_id' => $known->id, 'price' => '1'],
                ['name' => 'Bhp Pro Handel Spółka z ograniczoną odpowiedzialnością', 'price' => '2'],
            ],
        ]]])->assertStatus(422)->assertJsonValidationErrors('lots.0.offers.1');

        // pusta lista czyści oferty; brak klucza offers zostawia je bez zmian
        $this->putJson($this->url(), ['lots' => [['lot_no' => 1, 'note' => 'x']]])->assertOk()->assertJsonCount(2, 'lots.0.offers');
        $this->putJson($this->url(), ['lots' => [['lot_no' => 1, 'offers' => []]]])->assertOk()->assertJsonCount(0, 'lots.0.offers');
        $this->assertSame(0, TenderLotOffer::query()->count());
    }

    public function test_competitor_is_unified_by_nip_and_by_name(): void
    {
        Sanctum::actingAs($this->owner);

        // najpierw sama nazwa, potem ta sama firma z NIP-em i inną formą prawną — jedna firma, NIP dopisany
        $this->putJson($this->url(), ['lots' => [
            ['lot_no' => 1, 'outcome' => 'lost', 'winner' => ['name' => 'BHP-Pro Handel sp. z o.o.']],
            ['lot_no' => 2, 'outcome' => 'lost', 'winner' => ['name' => 'Bhp Pro Handel Spółka z ograniczoną odpowiedzialnością', 'nip' => 'NIP: 118-16-25-269']],
        ]])->assertOk();
        // po NIP-ie — nazwa inna, firma ta sama
        $this->putJson($this->url(), ['lots' => [
            ['lot_no' => 3, 'outcome' => 'lost', 'winner' => ['name' => 'BHP PRO', 'nip' => '1181625269']],
        ]])->assertOk();

        $this->assertSame(1, Competitor::query()->count());
        $competitor = Competitor::query()->firstOrFail();
        $this->assertSame('1181625269', $competitor->nip);
        $this->assertSame('BHP-Pro Handel sp. z o.o.', $competitor->name);
        $this->assertSame(
            [$competitor->id],
            TenderLot::query()->pluck('winner_competitor_id')->unique()->values()->all(),
        );
        $this->assertSame([null], TenderLot::query()->pluck('winner_national_id_raw')->unique()->values()->all());
    }

    public function test_invalid_nip_is_kept_raw_and_not_given_to_the_company(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson($this->url(), ['lots' => [[
            'lot_no' => 1, 'outcome' => 'lost', 'winner' => ['name' => 'Firma Testowa', 'nip' => '123-456-78-90'],
        ]]])
            ->assertOk()
            ->assertJsonPath('lots.0.winner.name', 'Firma Testowa')
            ->assertJsonPath('lots.0.winner.nip', null)
            ->assertJsonPath('lots.0.winner_national_id_raw', '123-456-78-90');

        $lotId = TenderLot::query()->value('id');
        // ponowny zapis tej samej firmy z listy nie gubi surowego NIP-u
        $competitorId = Competitor::query()->value('id');
        $this->putJson($this->url(), ['lots' => [['id' => $lotId, 'lot_no' => 1, 'winner' => ['competitor_id' => $competitorId], 'note' => 'a']]])
            ->assertOk()
            ->assertJsonPath('lots.0.winner_national_id_raw', '123-456-78-90');

        // wyczyszczenie zwycięzcy
        $this->putJson($this->url(), ['lots' => [['id' => $lotId, 'lot_no' => 1, 'winner' => null]]])
            ->assertOk()
            ->assertJsonPath('lots.0.winner', null)
            ->assertJsonPath('lots.0.winner_national_id_raw', null);
    }

    public function test_winner_without_name_and_nip_is_rejected(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson($this->url(), ['lots' => [['lot_no' => 1, 'winner' => ['name' => ' „…” ', 'nip' => '']]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lots.0.winner');
        $this->assertSame(0, Competitor::query()->count());
        $this->assertSame(0, TenderLot::query()->count());
    }

    public function test_save_is_logged_in_tender_history(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson($this->url(), ['lots' => [['lot_no' => 1, 'outcome' => 'lost', 'loss_reason' => 'price', 'offers' => [['name' => 'Ochrona Plus', 'price' => '10']]]]])
            ->assertOk();

        $activity = TenderActivity::query()->where('tender_id', $this->tender->id)->where('action', 'result_updated')->firstOrFail();
        $this->assertSame($this->owner->id, (int) $activity->user_id);
        $this->assertSame(null, $activity->meta['result_status_before']);
        $this->assertSame('lost', $activity->meta['result_status_after']);
        $this->assertSame(1, $activity->meta['lots'][0]['lot_no']);
        $this->assertTrue($activity->meta['lots'][0]['created']);
        $this->assertTrue($activity->meta['lots'][0]['offers_changed']);
        $this->assertSame(['outcome', 'loss_reason'], $activity->meta['lots'][0]['fields']);
    }

    public function test_delete_lot_recomputes_result_and_lot_of_another_tender_is_not_found(): void
    {
        Sanctum::actingAs($this->owner);
        $won = TenderLot::query()->create(['tender_id' => $this->tender->id, 'lot_no' => 1, 'outcome' => 'won']);
        $lost = TenderLot::query()->create(['tender_id' => $this->tender->id, 'lot_no' => 2, 'outcome' => 'lost']);
        $this->tender->forceFill(['result_status' => 'partial'])->save();

        $other = $this->makeTender('PRZ/2026/0003');
        $foreign = TenderLot::query()->create(['tender_id' => $other->id, 'lot_no' => 1]);
        $this->deleteJson($this->url()."/lots/{$foreign->id}")->assertNotFound();
        $this->assertNotNull($foreign->fresh());

        $this->deleteJson($this->url()."/lots/{$lost->id}")->assertOk()->assertJsonPath('ok', true);
        $this->assertNull($lost->fresh());
        $this->assertSame('won', $this->tender->fresh()->result_status);
        $this->assertTrue(TenderActivity::query()->where('action', 'result_updated')->exists());

        $this->deleteJson($this->url()."/lots/{$won->id}")->assertOk();
        $this->assertNull($this->tender->fresh()->result_status);
        $this->getJson($this->url())->assertOk()->assertJsonPath('lots.0.id', null);
    }

    private function url(): string
    {
        return "/api/tenders/{$this->tender->id}/result";
    }

    private function makeTender(string $number): Tender
    {
        return Tender::query()->create([
            'number' => $number,
            'title' => 'Dostawa odzieży i obuwia roboczego',
            'client_id' => Client::query()->firstOrCreate(['name' => 'Zakład Energetyczny'])->id,
            'owner_id' => $this->owner->id,
            'status' => 'exported',
            'ai_percent' => 0,
            'deadline' => '2026-09-30',
        ]);
    }

    private function resultNotice(): ProcurementNotice
    {
        return ProcurementNotice::query()->create([
            'notice_type' => ProcurementNotice::TYPE_RESULT,
            'notice_number' => '2026/BZP 00461230/01',
            'bzp_number' => '2026/BZP 00461230',
            'object_id' => '08df0819-6a33-b243-ab56-9400018aa89f',
            'published_at' => now(),
            'order_object' => 'Dostawa odzieży i obuwia roboczego',
            'cpv_codes' => ['18100000-0'],
            'organization_name' => 'Zakład Energetyczny',
            'parser_version' => 1,
            'fetched_at' => now(),
        ]);
    }
}
