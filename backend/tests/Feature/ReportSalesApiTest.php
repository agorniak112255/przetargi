<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\InquiryOrderHint;
use App\Models\Tender;
use App\Models\TenderInvitation;
use App\Models\User;
use App\Services\Reports\SalesReport;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CampaignFixtures;
use Tests\TestCase;

/**
 * GET /api/reports/sales — R4 „Sprzedaż i oferty”: zapytania, przetargi i kampanie wg uprawnień.
 * Teraz = piątek 02.10.2026 12:00 w Warszawie (10:00 UTC).
 */
final class ReportSalesApiTest extends TestCase
{
    use CampaignFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Europe/Warsaw'));
        $this->setUpCampaigns();
    }

    public function test_sections_are_null_without_module_permission_and_days_fall_back_to_90(): void
    {
        Sanctum::actingAs($this->userWith(['reports.view']));
        $this->getJson('/api/reports/sales?days=45')
            ->assertOk()
            ->assertJsonPath('days', 90)
            ->assertJsonPath('from', '2026-07-05')
            ->assertJsonPath('inquiries', null)
            ->assertJsonPath('tenders', null)
            ->assertJsonPath('campaigns', null);

        Sanctum::actingAs($this->userWith(['reports.view', 'inquiries.use', 'tenders.view_own', 'campaigns.use']));
        $json = $this->getJson('/api/reports/sales?days=30')->assertOk()->json();
        $this->assertSame([30, '2026-09-03'], [$json['days'], $json['from']]);
        $this->assertSame('own', $json['inquiries']['scope']);
        $this->assertNull($json['inquiries']['people']);
        // pełna seria tygodni od poniedziałku tygodnia „from” do bieżącego, zera gdy brak
        $this->assertSame(['2026-08-31', '2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28'], array_column($json['inquiries']['weekly'], 'week_start'));
        $this->assertSame([0, 0, 0, 0, 0], array_column($json['inquiries']['weekly'], 'received'));
        $this->assertSame([['channel' => 'thunderbird', 'received' => 0], ['channel' => 'web', 'received' => 0], ['channel' => 'file', 'received' => 0]], $json['inquiries']['channels']);
        $this->assertSame(['scope' => 'own', 'by_status' => [], 'by_owner' => [], 'upcoming' => []], $json['tenders']);
        $this->assertSame(['scope' => 'own', 'rows' => [], 'totals' => ['campaigns' => 0, 'sent' => 0, 'clicked' => 0, 'replies' => 0, 'unsubscribed' => 0]], $json['campaigns']);

        Sanctum::actingAs($this->userWith(['inquiries.use']));
        $this->getJson('/api/reports/sales')->assertForbidden();
    }

    public function test_one_business_day_skips_weekend(): void
    {
        $at = static fn (string $pl): string => SalesReport::oneBusinessDayAfter(CarbonImmutable::parse($pl, 'Europe/Warsaw'))->format('Y-m-d H:i');

        $this->assertSame('2026-09-28 15:00', $at('2026-09-25 15:00')); // piątek → poniedziałek
        $this->assertSame('2026-09-25 09:30', $at('2026-09-24 09:30')); // czwartek → piątek
        $this->assertSame('2026-09-29 00:00', $at('2026-09-26 10:00')); // sobota: zegar od pon. 00:00
        $this->assertSame('2026-09-29 00:00', $at('2026-09-27 23:00')); // niedziela
    }

    public function test_inquiry_groups_replies_business_day_and_scopes(): void
    {
        $anna = $this->userWith(['reports.view', 'inquiries.use'], 'Anna');
        $bartek = $this->userWith(['reports.view', 'inquiries.use'], 'Bartek');
        $celina = $this->userWith(['reports.view', 'inquiries.use'], 'Celina');
        $boss = $this->userWith(['reports.view', 'inquiries.use', 'inquiries.view_all'], 'Szef');

        // piątek 15:00 → odpowiedź w poniedziałek 14:59 = w 1 dzień roboczy; 15:01 — już nie
        $this->inquiry($anna, ['source_sent_at' => '2026-09-25 15:00', 'created_at' => '2026-09-25 15:10', 'replied_at' => '2026-09-28 14:59', 'source_channel' => 'thunderbird']);
        $this->inquiry($anna, ['source_sent_at' => '2026-09-25 15:00', 'created_at' => '2026-09-25 15:10', 'replied_at' => '2026-09-28 15:01', 'analysis_status' => 'failed']);
        // oryginał bez odpowiedzi, odpowiedź na kopii u Bartka, kopia kopii u Celiny — jedno zapytanie, dwa duplikaty
        $original = $this->inquiry($anna, ['created_at' => '2026-09-29 10:00']);
        $copy = $this->inquiry($bartek, ['created_at' => '2026-09-29 10:30', 'replied_at' => '2026-09-29 12:00', 'duplicate_of_id' => $original->id]);
        $this->inquiry($celina, ['created_at' => '2026-09-29 11:00', 'duplicate_of_id' => $copy->id]);
        // czeka, zgłoszone do wysyłki w Thunderbirdzie, termin jeszcze nie minął (czw. 14:00 → pt. 14:00)
        $this->inquiry($bartek, ['created_at' => '2026-10-01 14:00', 'send_requested_at' => '2026-10-02 11:00', 'source_channel' => 'thunderbird']);
        // czeka po terminie; analiza „running” od 30 min = przerwana (nieudana)
        $this->inquiry($bartek, ['created_at' => '2026-09-20 10:00', 'source_channel' => 'file', 'analysis_status' => 'running', 'analysis_started_at' => '2026-10-02 11:30']);
        // analiza w toku od 10 min — nie jest nieudana
        $this->inquiry($bartek, ['created_at' => '2026-10-02 11:50', 'analysis_status' => 'running', 'analysis_started_at' => '2026-10-02 11:50']);
        // poza okresem: stare i takie, którego mail wysłano przed okresem (liczy się source_sent_at)
        $this->inquiry($bartek, ['created_at' => '2026-06-01 10:00']);
        $this->inquiry($bartek, ['source_sent_at' => '2026-08-30 10:00', 'created_at' => '2026-09-10 10:00']);

        Sanctum::actingAs($boss);
        $all = $this->getJson('/api/reports/sales?days=30')->assertOk()->json('inquiries');
        $this->assertSame('all', $all['scope']);
        $this->assertSame([
            'received' => 6, 'replied' => 3, 'replied_1bd' => 2, 'waiting' => 3, 'waiting_over_1bd' => 1,
            'in_thunderbird' => 1, 'analysis_failed' => 2, 'duplicates' => 2,
        ], $all['totals']);
        $this->assertSame([
            ['week_start' => '2026-08-31', 'received' => 0, 'replied' => 0],
            ['week_start' => '2026-09-07', 'received' => 0, 'replied' => 0],
            ['week_start' => '2026-09-14', 'received' => 1, 'replied' => 0],
            ['week_start' => '2026-09-21', 'received' => 2, 'replied' => 2],
            ['week_start' => '2026-09-28', 'received' => 3, 'replied' => 1],
        ], $all['weekly']);
        $this->assertSame([
            ['channel' => 'thunderbird', 'received' => 2],
            ['channel' => 'web', 'received' => 3],
            ['channel' => 'file', 'received' => 1],
        ], $all['channels']);
        // mail przejęty przez kilka osób liczy się każdej z nich: Bartek (3 własne + kopia z odpowiedzią), Celina (kopia kopii)
        // mediana z własnych odpowiedzi osoby: Bartek 2 h na kopii; Anna (3 dni − 1 min, 3 dni + 1 min) = 3 dni;
        // Celina sama nie odpowiadała — skuteczność (zamówione z własnych odpowiedzi) nie ma mianownika: null
        $this->assertSame([
            ['user_id' => $bartek->id, 'name' => 'Bartek', 'received' => 4, 'replied' => 1, 'replied_1bd' => 1, 'waiting' => 3,
                'median_reply_seconds' => 7200, 'ordered' => 0, 'ordered_percent' => 0, 'order_value' => '0.00'],
            ['user_id' => $anna->id, 'name' => 'Anna', 'received' => 3, 'replied' => 3, 'replied_1bd' => 2, 'waiting' => 0,
                'median_reply_seconds' => 259200, 'ordered' => 0, 'ordered_percent' => 0, 'order_value' => '0.00'],
            ['user_id' => $celina->id, 'name' => 'Celina', 'received' => 1, 'replied' => 1, 'replied_1bd' => 1, 'waiting' => 0,
                'median_reply_seconds' => null, 'ordered' => 0, 'ordered_percent' => null, 'order_value' => '0.00'],
        ], $all['people']);

        // bez inquiries.view_all tylko własne oryginały; odpowiedź na cudzej kopii nadal się liczy
        Sanctum::actingAs($anna);
        $own = $this->getJson('/api/reports/sales?days=30')->assertOk()->json('inquiries');
        $this->assertSame('own', $own['scope']);
        $this->assertNull($own['people']);
        $this->assertSame([
            'received' => 3, 'replied' => 3, 'replied_1bd' => 2, 'waiting' => 0, 'waiting_over_1bd' => 0,
            'in_thunderbird' => 0, 'analysis_failed' => 1, 'duplicates' => 2,
        ], $own['totals']);

        // przejęta kopia cudzego maila to też „moje” zapytanie (Celina ma kopię kopii; odpowiedział Bartek)
        Sanctum::actingAs($celina);
        $celinaTotals = $this->getJson('/api/reports/sales?days=30')->assertOk()->json('inquiries.totals');
        $this->assertSame(1, $celinaTotals['received']);
        $this->assertSame(1, $celinaTotals['replied']);
        $this->assertSame(2, $celinaTotals['duplicates']); // ten sam mail u Anny i Bartka

        // Bartek: 3 własne + przejęta kopia maila Anny; „u innych” to Anna i Celina, nie jego własna kopia
        Sanctum::actingAs($bartek);
        $bartekTotals = $this->getJson('/api/reports/sales?days=30')->assertOk()->json('inquiries.totals');
        $this->assertSame(4, $bartekTotals['received']);
        $this->assertSame(2, $bartekTotals['duplicates']);
    }

    public function test_funnel_counts_mail_group_once_and_possible_orders_separately(): void
    {
        $anna = $this->userWith(['reports.view', 'inquiries.use'], 'Anna');
        $bartek = $this->userWith(['reports.view', 'inquiries.use'], 'Bartek');
        $boss = $this->userWith(['reports.view', 'inquiries.use', 'inquiries.view_all'], 'Szef');

        // 1. ten sam mail u Anny i Bartka: oboje potwierdzają ten sam dokument — grupa raz, wartość raz
        $original = $this->inquiry($anna, ['created_at' => '2026-09-21 08:00', 'replied_at' => '2026-09-21 09:00',
            'outcome' => 'ordered', 'outcome_document_number' => 'FS-1', 'outcome_net_value' => '1000.00']);
        $this->inquiry($bartek, ['created_at' => '2026-09-21 08:10', 'replied_at' => '2026-09-21 11:00', 'duplicate_of_id' => $original->id,
            'outcome' => 'partial', 'outcome_document_number' => 'FS-1', 'outcome_net_value' => '1000.00']);
        // 2. Anna: zamówił bez wskazanego dokumentu
        $this->inquiry($anna, ['created_at' => '2026-09-22 08:00', 'replied_at' => '2026-09-22 12:00', 'outcome' => 'ordered']);
        // 3. Anna: tylko podpowiedź z ERP XL, wynik pusty — „możliwe”
        $possible = $this->inquiry($anna, ['created_at' => '2026-09-23 08:00', 'replied_at' => '2026-09-23 10:00']);
        $this->hint($possible);
        // 4. Bartek: podpowiedź, ale handlowiec wpisał „nie zamówił” — człowiek ma pierwszeństwo, to nie jest „możliwe”
        $rejected = $this->inquiry($bartek, ['created_at' => '2026-09-24 08:00', 'replied_at' => '2026-09-24 08:30', 'outcome' => 'not_ordered', 'outcome_reason' => 'price']);
        $this->hint($rejected);
        // 5. Bartek: bez odpowiedzi
        $this->inquiry($bartek, ['created_at' => '2026-09-25 08:00']);

        Sanctum::actingAs($boss);
        $json = $this->getJson('/api/reports/sales?days=30')->assertOk()->json('inquiries');
        $this->assertSame([
            'received' => 5, 'replied' => 4, 'ordered_confirmed' => 2, 'possible' => 1, 'value_confirmed' => '1000.00', 'ordered_without_document' => 1,
        ], $json['funnel']);
        $people = collect($json['people'])->keyBy('name');
        // Anna: 3 odpowiedzi (1 h, 4 h, 2 h) → mediana 2 h; zamówione 2 z 3 odpowiedzianych grup
        $this->assertSame([3, 7200, 2, 66.7, '1000.00'], [$people['Anna']['replied'], $people['Anna']['median_reply_seconds'], $people['Anna']['ordered'], $people['Anna']['ordered_percent'], $people['Anna']['order_value']]);
        // Bartek: kopia maila (3 h od przyjścia oryginału) i 30 min → mediana 1 h 45 min
        $this->assertSame([2, 6300, 1, 50, '1000.00'], [$people['Bartek']['replied'], $people['Bartek']['median_reply_seconds'], $people['Bartek']['ordered'], $people['Bartek']['ordered_percent'], $people['Bartek']['order_value']]);

        // zakres „own”: tylko grupy Anny
        Sanctum::actingAs($anna);
        $this->getJson('/api/reports/sales?days=30')->assertOk()->assertJsonPath('inquiries.funnel', [
            'received' => 3, 'replied' => 3, 'ordered_confirmed' => 2, 'possible' => 1, 'value_confirmed' => '1000.00', 'ordered_without_document' => 1,
        ]);
    }

    public function test_ordered_percent_counts_only_own_replies_and_stale_hint_is_not_possible(): void
    {
        $anna = $this->userWith(['reports.view', 'inquiries.use'], 'Anna');
        $bartek = $this->userWith(['reports.view', 'inquiries.use'], 'Bartek');
        $boss = $this->userWith(['reports.view', 'inquiries.use', 'inquiries.view_all'], 'Szef');

        // ten sam mail u Anny i Bartka — odpowiedziała tylko Anna (Bartek nie wysłał oferty)
        $original = $this->inquiry($anna, ['created_at' => '2026-09-21 08:00', 'replied_at' => '2026-09-21 09:00']);
        $this->inquiry($bartek, ['created_at' => '2026-09-21 08:10', 'duplicate_of_id' => $original->id]);
        // własne zapytanie Bartka: odpowiedział i klient zamówił
        $this->inquiry($bartek, ['created_at' => '2026-09-22 08:00', 'replied_at' => '2026-09-22 09:00', 'outcome' => 'ordered']);
        // podpowiedź policzona dla poprzedniego klienta zapytania (inny kontrahent XL) — to nie jest „możliwe”
        $client = Client::query()->create(['name' => 'Obecny klient', 'xl_gid' => 60001]);
        $stale = $this->inquiry($anna, ['created_at' => '2026-09-23 08:00', 'replied_at' => '2026-09-23 09:00', 'client_id' => $client->id, 'client_link_source' => 'manual']);
        InquiryOrderHint::query()->create([
            'client_inquiry_id' => $stale->id, 'customer_xl_gid' => 60002, 'document_type' => 2033, 'document_id' => 1, 'document_number' => 'FS-STARY',
            'issued_at' => '2026-09-30', 'document_net' => 500, 'matched_net' => 300, 'offered_items' => 2, 'linked_items' => 2, 'matched_items' => 1,
            'computed_at' => now(),
        ]);

        Sanctum::actingAs($boss);
        $json = $this->getJson('/api/reports/sales?days=30')->assertOk()->json('inquiries');
        $this->assertSame(0, $json['funnel']['possible']);
        $people = collect($json['people'])->keyBy('name');
        // Bartek: grupa z odpowiedzią Anny liczy się do „odpowiedziane” grupy, ale nie do mianownika jego skuteczności
        $this->assertSame([2, 1, 100], [$people['Bartek']['replied'], $people['Bartek']['ordered'], $people['Bartek']['ordered_percent']]);
        $this->assertSame([2, 0, 0], [$people['Anna']['replied'], $people['Anna']['ordered'], $people['Anna']['ordered_percent']]);
    }

    public function test_tenders_scope_weighted_masked_margin_and_upcoming(): void
    {
        $owner = $this->userWith(['reports.view', 'tenders.view_own', 'prices.supplier_special.view'], 'Olga');
        $other = $this->userWith(['reports.view', 'tenders.view_own'], 'Xawery');
        $invited = $this->userWith(['reports.view', 'tenders.view_own'], 'Igor');
        $all = $this->userWith(['reports.view', 'tenders.view_all', 'prices.supplier_special.view'], 'Wanda');
        $allMasked = $this->userWith(['reports.view', 'tenders.view_all'], 'Maria');

        $t1 = $this->tender($owner, 'wycena', 1000, 20, 12, '2026-10-05');
        $t2 = $this->tender($owner, 'wycena', 3000, 10, 6, '2026-10-16');
        $this->tender($owner, 'draft', null, null, null, '2026-10-17');       // po 14 dniach
        $this->tender($owner, 'exported', 500, 30, 30, '2026-10-03');       // wyeksportowany — poza terminami
        $t9 = $this->tender($owner, 'akceptacja_km', null, null, null, '2026-10-10'); // bez wyceny → null, nie 0
        $t5 = $this->tender($other, 'zatwierdzona', 2000, 40, 40, '2026-10-02');
        $this->tender(null, 'odrzucony', 100, null, null, null);
        $this->tender($other, 'wycena', 100, 50, 50, '2026-10-01');          // termin minął
        $this->tender($other, 'nieznany', 0, null, null, null);
        TenderInvitation::query()->create(['tender_id' => $t5->id, 'user_id' => $invited->id, 'invited_by' => $other->id]);

        Sanctum::actingAs($owner);
        $mine = $this->getJson('/api/reports/sales')->assertOk()->json('tenders');
        $this->assertSame('own', $mine['scope']);
        $this->assertEquals([
            ['status' => 'draft', 'count' => 1, 'offer_value_net' => 0.0, 'avg_margin' => null],
            ['status' => 'wycena', 'count' => 2, 'offer_value_net' => 4000.0, 'avg_margin' => 12.5], // (20·1000 + 10·3000) / 4000
            ['status' => 'akceptacja_km', 'count' => 1, 'offer_value_net' => 0.0, 'avg_margin' => null],
            ['status' => 'exported', 'count' => 1, 'offer_value_net' => 500.0, 'avg_margin' => 30.0],
        ], $mine['by_status']);
        $this->assertSame(['2026-10-05', '2026-10-10', '2026-10-16'], array_column($mine['upcoming'], 'deadline'));

        // zaproszony widzi przetarg kolegi (accessibleBy); termin dziś = 0 dni
        Sanctum::actingAs($invited);
        $invitedView = $this->getJson('/api/reports/sales')->assertOk()->json('tenders');
        $this->assertSame(['zatwierdzona'], array_column($invitedView['by_status'], 'status'));
        $this->assertEquals([[
            'id' => $t5->id, 'number' => $t5->number, 'title' => $t5->title, 'client' => 'Klient '.$t5->title,
            'deadline' => '2026-10-02', 'days_left' => 0, 'status' => 'zatwierdzona', 'owner_name' => 'Xawery', 'offer_value_net' => 2000.0,
        ]], $invitedView['upcoming']);

        Sanctum::actingAs($all);
        $everything = $this->getJson('/api/reports/sales')->assertOk()->json('tenders');
        $this->assertSame('all', $everything['scope']);
        $this->assertSame(
            ['draft', 'wycena', 'akceptacja_km', 'zatwierdzona', 'exported', 'odrzucony', 'nieznany'],
            array_column($everything['by_status'], 'status'),
        );
        $this->assertSame(13.4, $everything['by_status'][1]['avg_margin']); // (20000 + 30000 + 5000) / 4100
        $this->assertEquals([
            ['owner_id' => $owner->id, 'owner_name' => 'Olga', 'count' => 5, 'offer_value_net' => 4500.0, 'avg_margin' => 14.4],
            ['owner_id' => $other->id, 'owner_name' => 'Xawery', 'count' => 3, 'offer_value_net' => 2100.0, 'avg_margin' => 40.5],
            ['owner_id' => null, 'owner_name' => 'Nieprzypisany', 'count' => 1, 'offer_value_net' => 100.0, 'avg_margin' => null],
        ], $everything['by_owner']);
        $this->assertSame([$t5->id, $t1->id, $t9->id, $t2->id], array_column($everything['upcoming'], 'id'));
        $this->assertSame([0, 3, 8, 14], array_column($everything['upcoming'], 'days_left'));
        $this->assertNull($everything['upcoming'][2]['offer_value_net']);

        // bez prices.supplier_special.view marża bliźniacza (standardowa): (12·1000 + 6·3000 + 50·100) / 4100
        Sanctum::actingAs($allMasked);
        $this->assertSame(8.5, $this->getJson('/api/reports/sales')->assertOk()->json('tenders.by_status.1.avg_margin'));
    }

    public function test_tender_csv_uses_the_same_scope_as_the_report(): void
    {
        $owner = $this->userWith(['reports.view', 'tenders.view_own'], 'Olga');
        $invited = $this->userWith(['reports.view', 'tenders.view_own'], 'Igor');
        $stranger = $this->userWith(['reports.view', 'tenders.view_own'], 'Obcy');
        $shared = $this->tender($owner, 'wycena', 1000, 20, 20, '2026-10-05');
        $private = $this->tender($owner, 'draft', null, null, null, null);
        TenderInvitation::query()->create(['tender_id' => $shared->id, 'user_id' => $invited->id, 'invited_by' => $owner->id]);

        Sanctum::actingAs($invited);
        $csv = $this->get('/api/reports/csv')->assertOk()->streamedContent();
        $this->assertStringContainsString($shared->number, $csv);
        $this->assertStringNotContainsString($private->number, $csv);

        Sanctum::actingAs($stranger);
        $csv = $this->get('/api/reports/csv')->assertOk()->streamedContent();
        $this->assertStringNotContainsString($shared->number, $csv);
    }

    public function test_campaigns_scope_period_and_counts(): void
    {
        $author = $this->userWith(['reports.view', 'campaigns.use'], 'Autor');
        $colleague = $this->userWith(['reports.view', 'campaigns.use'], 'Kolega');
        $viewer = $this->userWith(['reports.view', 'campaigns.view'], 'Podgląd');

        $mine = $this->campaign($author, [], ['name' => 'Rękawice jesień', 'status' => 'sent', 'sending_started_at' => '2026-09-20 08:00:00', 'sent_at' => '2026-09-20 10:00:00']);
        $this->recipient($mine, 'a@alfa.pl', 'sent', ['clicks' => 2, 'replied_at' => '2026-09-21 09:00:00']);
        $this->recipient($mine, 'b@beta.pl', 'sent', ['unsubscribed_at' => '2026-09-22 09:00:00']);
        $this->recipient($mine, 'c@gamma.pl', 'failed');
        $this->recipient($mine, 'd@delta.pl', 'sent');
        $theirs = $this->campaign($colleague, [], ['name' => 'Obuwie', 'status' => 'sending', 'sending_started_at' => '2026-09-25 08:00:00']);
        $this->recipient($theirs, 'e@eps.pl', 'sent', ['clicks' => 1]);
        // projekt, zaplanowana i wysłana przed okresem — poza raportem
        $this->campaign($author, [], ['status' => 'draft']);
        $this->campaign($colleague, [], ['status' => 'scheduled', 'scheduled_at' => '2026-10-05 08:00:00']);
        $this->campaign($author, [], ['status' => 'sent', 'sending_started_at' => '2026-08-01 08:00:00']);

        Sanctum::actingAs($author);
        $own = $this->getJson('/api/reports/sales?days=30')->assertOk()->json('campaigns');
        $this->assertSame('own', $own['scope']);
        // assertEquals: JSON zapisuje 0.0 jako 0
        $this->assertEquals([[
            'id' => $mine->id, 'code' => $mine->code, 'name' => 'Rękawice jesień', 'status' => 'sent',
            'started_at' => '2026-09-20T08:00:00+00:00', 'sent' => 3, 'clicked' => 1, 'replies' => 1, 'unsubscribed' => 1,
            'buyers' => 0, 'sales_net' => 0.0, 'sales_complete' => false, // 30 dni od 20.09 jeszcze trwa
        ]], $own['rows']);
        $this->assertSame(['campaigns' => 1, 'sent' => 3, 'clicked' => 1, 'replies' => 1, 'unsubscribed' => 1], $own['totals']);

        // podgląd (campaigns.view): wszystkie po starcie wysyłki, najnowsze najpierw
        Sanctum::actingAs($viewer);
        $all = $this->getJson('/api/reports/sales?days=30')->assertOk()->json('campaigns');
        $this->assertSame('all', $all['scope']);
        $this->assertSame([$theirs->id, $mine->id], array_column($all['rows'], 'id'));
        $this->assertSame(['campaigns' => 2, 'sent' => 4, 'clicked' => 2, 'replies' => 1, 'unsubscribed' => 1], $all['totals']);

        // 90 dni obejmuje też kampanię z sierpnia
        $this->assertSame(3, $this->getJson('/api/reports/sales?days=90')->json('campaigns.totals.campaigns'));
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions, string $name = 'Użytkownik'): User
    {
        $role = Role::findOrCreate('rep-'.Str::random(6), 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * Zapytanie z czasami podanymi w czasie polskim (zapis w UTC).
     *
     * @param  array<string, mixed>  $attrs
     */
    private function inquiry(User $user, array $attrs): ClientInquiry
    {
        foreach (['source_sent_at', 'created_at', 'replied_at', 'send_requested_at', 'analysis_started_at'] as $key) {
            if (isset($attrs[$key])) {
                $attrs[$key] = CarbonImmutable::parse((string) $attrs[$key], 'Europe/Warsaw')->utc();
            }
        }
        $inquiry = ClientInquiry::query()->create(['user_id' => $user->id, 'source_body' => 'Treść zapytania', 'source_channel' => 'web']);
        $inquiry->forceFill(['updated_at' => $attrs['created_at'] ?? now(), ...$attrs])->save();

        return $inquiry;
    }

    /** Podpowiedź z ERP XL dla obecnego klienta zapytania (klient z XL dopinany, gdy zapytanie go nie ma). */
    private function hint(ClientInquiry $inquiry): void
    {
        if ($inquiry->client_id === null) {
            $client = Client::query()->create(['name' => 'Klient XL '.$inquiry->id, 'xl_gid' => 50000 + $inquiry->id]);
            $inquiry->forceFill(['client_id' => $client->id, 'client_link_source' => 'email'])->save();
        }
        $gid = (int) Client::query()->whereKey($inquiry->client_id)->value('xl_gid');
        InquiryOrderHint::query()->create([
            'client_inquiry_id' => $inquiry->id, 'customer_xl_gid' => $gid, 'document_type' => 2033, 'document_id' => $inquiry->id, 'document_number' => 'FS-H'.$inquiry->id,
            'issued_at' => '2026-09-30', 'document_net' => 500, 'matched_net' => 300, 'offered_items' => 2, 'linked_items' => 2, 'matched_items' => 1,
            'computed_at' => now(),
        ]);
    }

    private function tender(?User $owner, string $status, ?float $value, ?float $margin, ?float $standardMargin, ?string $deadline): Tender
    {
        $title = 'Przetarg '.Str::random(6);
        $tender = Tender::query()->create([
            'number' => 'ZP/'.Str::random(8),
            'title' => $title,
            'client_id' => Client::query()->create(['name' => 'Klient '.$title])->id,
            'owner_id' => $owner?->id,
            'status' => $status,
            'deadline' => $deadline,
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        $tender->forceFill(['offer_value_net' => $value, 'margin_percent' => $margin, 'margin_percent_standard' => $standardMargin])->save();

        return $tender;
    }

    /** @param  array<string, mixed>  $attrs */
    private function recipient(Campaign $campaign, string $email, string $status, array $attrs = []): void
    {
        CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id, 'email' => $email, 'source' => 'list', 'token' => Str::random(40),
            'status' => $status, 'sent_at' => $status === 'sent' ? $campaign->sending_started_at : null, ...$attrs,
        ]);
    }
}
