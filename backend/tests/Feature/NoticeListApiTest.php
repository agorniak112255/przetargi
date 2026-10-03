<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProcurementNotice;
use App\Models\ProcurementNoticeSkip;
use App\Models\ScheduledTaskRun;
use App\Models\Tender;
use App\Models\TenderInvitation;
use App\Models\User;
use App\Services\Bzp\NoticeListQuery;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/notices — zakładki (nowe / z przetargiem / pominięte), filtry, sortowanie, stronicowanie, uprawnienia,
 * link do przetargu tylko z dostępem. Teraz = sobota 03.10.2026 10:00 w Warszawie (08:00 UTC).
 */
final class NoticeListApiTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00:00', 'Europe/Warsaw'));
    }

    public function test_row_shape_and_list_metadata(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $notice = $this->notice([
            'notice_number' => '2026/BZP 00431178/01',
            'bzp_number' => '2026/BZP 00431178',
            'object_id' => '08df188d-b634-3c3c-db89-6a00017dae13',
            'published_at' => '2026-10-01 07:41:43',
            'submitting_offers_at' => '2026-10-13 08:00:00',
            'order_object' => 'Dostawa rękawic i obuwia ochronnego',
            'cpv_codes' => [
                ['code' => '18141000-9', 'name' => 'Rękawice robocze'],
                ['code' => '18830000-6', 'name' => 'Obuwie ochronne'],
                ['code' => '33141623-3', 'name' => 'Zestawy pierwszej pomocy'],
            ],
            'organization_name' => 'Szpital Wojewódzki',
            'organization_city' => 'Rzeszów',
            'organization_province' => 'PL18',
            'organization_nip' => '8132283737',
            'parsed' => [
                'has_lots' => true,
                'procedure_url' => 'https://platformazakupowa.pl/transakcja/1234',
                'total_value' => ['amount' => '1365178.85', 'currency' => 'PLN'],
                'lots' => [['lot_no' => 1], ['lot_no' => 2]],
            ],
            'fetched_at' => '2026-10-03 04:31:00',
        ]);

        $response = $this->getJson('/api/notices')->assertOk();

        $this->assertSame([[
            'id' => $notice->id,
            'source' => 'bzp',
            'notice_number' => '2026/BZP 00431178/01',
            'published_at' => '2026-10-01T07:41:43.000000Z',
            'submitting_offers_at' => '2026-10-13T08:00:00.000000Z',
            'deadline_local' => '13.10.2026, 10:00',
            'order_object' => 'Dostawa rękawic i obuwia ochronnego',
            'organization' => [
                'name' => 'Szpital Wojewódzki',
                'city' => 'Rzeszów',
                'province_code' => 'PL18',
                'province_name' => 'podkarpackie',
                'nip' => '8132283737',
            ],
            // kolejność rodzajów jak w config bzp.cpv_categories; kod spoza mapy (apteczki) nie daje rodzaju
            'categories' => ['Rękawice', 'Obuwie'],
            'cpv_codes' => ['18141000-9', '18830000-6', '33141623-3'],
            'total_value' => '1 365 178,85 PLN',
            'lots_count' => 2,
            'procedure_url' => 'https://platformazakupowa.pl/transakcja/1234',
            'notice_url' => 'https://ezamowienia.gov.pl/mo-client-board/bzp/notice-details/id/08df188d-b634-3c3c-db89-6a00017dae13',
            'tender' => null,
            'skipped' => null,
            'past' => false,
            'client_match' => null,
        ]], $response->json('data'));
        $this->assertSame(['page' => 1, 'last_page' => 1, 'total' => 1], $response->json('meta'));
        $this->assertSame(['new' => 1, 'created' => 0, 'skipped' => 0], $response->json('counts'));
        $this->assertSame(['key' => 'clothing', 'label' => 'Odzież robocza i ochronna'], $response->json('categories.0'));
        $this->assertCount(7, $response->json('categories'));
        $this->assertSame(['code' => 'PL18', 'name' => 'podkarpackie'], collect($response->json('provinces'))->firstWhere('code', 'PL18'));
        $this->assertCount(16, $response->json('provinces'));
        $this->assertSame('2026-10-03T04:31:00.000000Z', $response->json('fetched_at'));
        $this->assertStringContainsString('Biuletyn Zamówień Publicznych', $response->json('source_note'));
        $this->assertStringContainsString('Dziennika Urzędowego Unii Europejskiej (TED) nie są pobierane', $response->json('source_note'));
        $this->assertStringContainsString('poniżej 130 000 zł', $response->json('source_note'));
    }

    public function test_single_part_notice_value_and_missing_values(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->notice([
            'order_object' => 'Jedna część',
            'parsed' => ['has_lots' => false, 'total_value' => null, 'lots' => [['lot_no' => 1, 'estimated_value' => ['amount' => '143320.83', 'currency' => null]]]],
        ]);
        $this->notice([
            'order_object' => 'Bez danych',
            'object_id' => null,
            'submitting_offers_at' => null,
            'organization_province' => 'XX',
            'parsed' => null,
        ]);

        $rows = collect($this->getJson('/api/notices')->assertOk()->json('data'))->keyBy('order_object');

        // wartość jedynej części, bez zgadywania waluty
        $this->assertSame('143 320,83', $rows['Jedna część']['total_value']);
        $this->assertSame(0, $rows['Jedna część']['lots_count']);
        $this->assertNull($rows['Bez danych']['total_value']);
        $this->assertNull($rows['Bez danych']['procedure_url']);
        $this->assertNull($rows['Bez danych']['notice_url']);
        $this->assertNull($rows['Bez danych']['deadline_local']);
        $this->assertNull($rows['Bez danych']['organization']['province_name']);
        $this->assertFalse($rows['Bez danych']['past']);
    }

    public function test_tabs_are_disjoint_and_new_hides_past_deadlines_by_default(): void
    {
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);
        $client = Client::query()->create(['name' => 'Starostwo']);

        $future = $this->notice(['order_object' => 'przyszły', 'submitting_offers_at' => '2026-10-20 08:00:00']);
        $past = $this->notice(['order_object' => 'po terminie', 'submitting_offers_at' => '2026-10-03 07:59:00']);
        $noDeadline = $this->notice(['order_object' => 'bez terminu', 'submitting_offers_at' => null]);
        $skipped = $this->notice(['order_object' => 'pominięte']);
        ProcurementNoticeSkip::query()->create(['procurement_notice_id' => $skipped->id, 'user_id' => $user->id]);
        // przetarg z numerem bez wersji, przetarg powiązany tylko przez contract_notice_id, przetarg do pominiętego
        $byNumber = $this->notice(['order_object' => 'przetarg po numerze', 'bzp_number' => '2026/BZP 00500001', 'notice_number' => '2026/BZP 00500001/01']);
        $this->tender($client, ['notice_number' => '2026/BZP 00500001']);
        $byId = $this->notice(['order_object' => 'przetarg po powiązaniu']);
        $this->tender($client, ['contract_notice_id' => $byId->id]);
        $skippedWithTender = $this->notice(['order_object' => 'pominięte, potem przetarg', 'bzp_number' => '2026/BZP 00500002', 'notice_number' => '2026/BZP 00500002/01']);
        ProcurementNoticeSkip::query()->create(['procurement_notice_id' => $skippedWithTender->id, 'user_id' => $user->id]);
        $this->tender($client, ['notice_number' => '2026/BZP 00500002/01']);
        // inny numer zaczynający się tak samo nie jest tym postępowaniem
        $this->tender($client, ['notice_number' => '2026/BZP 0050000']);
        // starsza wersja numeru tego samego postępowania i ogłoszenie o wyniku — poza listą
        $this->notice(['order_object' => 'starsza wersja', 'bzp_number' => $future->bzp_number, 'notice_number' => $future->bzp_number.'/00']);
        $this->notice(['order_object' => 'wynik', 'notice_type' => ProcurementNotice::TYPE_RESULT]);

        $new = $this->getJson('/api/notices?tab=new')->assertOk();
        $this->assertSame(['przyszły', 'bez terminu'], array_column($new->json('data'), 'order_object'));
        $this->assertSame(['new' => 2, 'created' => 3, 'skipped' => 1], $new->json('counts'));

        $withPast = $this->getJson('/api/notices?tab=new&past=1')->assertOk();
        // termin rosnąco, bez terminu na końcu
        $this->assertSame(['po terminie', 'przyszły', 'bez terminu'], array_column($withPast->json('data'), 'order_object'));
        $this->assertTrue($withPast->json('data.0.past'));
        $this->assertSame(3, $withPast->json('counts.new'));

        $created = $this->getJson('/api/notices?tab=created')->assertOk();
        $this->assertEqualsCanonicalizing(
            ['przetarg po numerze', 'przetarg po powiązaniu', 'pominięte, potem przetarg'],
            array_column($created->json('data'), 'order_object'),
        );
        $row = collect($created->json('data'))->firstWhere('order_object', 'pominięte, potem przetarg');
        $this->assertNotNull($row['tender']);
        $this->assertSame($user->id, $row['skipped']['by']['id']);

        $skippedTab = $this->getJson('/api/notices?tab=skipped')->assertOk();
        $this->assertSame(['pominięte'], array_column($skippedTab->json('data'), 'order_object'));
        $this->assertSame(['id' => $user->id, 'name' => $user->name], $skippedTab->json('data.0.skipped.by'));
        $this->assertSame('2026-10-03T08:00:00.000000Z', $skippedTab->json('data.0.skipped.at'));

        $this->assertNotNull($past);
        $this->assertNotNull($noDeadline);
        $this->assertNotNull($byNumber);
    }

    public function test_filters_category_province_and_text(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->notice(['order_object' => 'Rękawice robocze', 'cpv_codes' => [['code' => '18141000-9', 'name' => null]]]);
        $this->notice(['order_object' => 'Odzież', 'cpv_codes' => [['code' => '18100000-0', 'name' => null]], 'organization_province' => 'PL12']);
        $this->notice([
            'order_object' => 'Odzież i rękawice',
            'cpv_codes' => [['code' => '18141000-9', 'name' => null], ['code' => '35113400-3', 'name' => null]],
            'organization_name' => 'Komenda Miejska Państwowej Straży Pożarnej',
            'organization_city' => 'Krosno',
        ]);
        $this->notice(['order_object' => 'Hełmy', 'cpv_codes' => [['code' => '18444110-7', 'name' => null]], 'notice_number' => '2026/BZP 00777777/01', 'bzp_number' => '2026/BZP 00777777']);

        // „18141…” to rękawice, choć pasuje też początek odzieży „181” — wygrywa najdłuższy początek
        $gloves = $this->getJson('/api/notices?category=gloves')->assertOk();
        $this->assertEqualsCanonicalizing(['Rękawice robocze', 'Odzież i rękawice'], array_column($gloves->json('data'), 'order_object'));
        $this->assertSame(2, $gloves->json('counts.new'));
        $this->assertSame(2, $gloves->json('meta.total'));

        $clothing = $this->getJson('/api/notices?category=clothing')->assertOk();
        $this->assertEqualsCanonicalizing(['Odzież', 'Odzież i rękawice'], array_column($clothing->json('data'), 'order_object'));

        $this->assertSame(['Odzież'], array_column($this->getJson('/api/notices?province=PL12')->json('data'), 'order_object'));
        $this->assertSame(['Odzież'], array_column($this->getJson('/api/notices?category=clothing&province=PL12')->json('data'), 'order_object'));

        // każde słowo w którymkolwiek polu: przedmiot, zamawiający, miasto, numer
        $this->assertSame(['Odzież i rękawice'], array_column($this->getJson('/api/notices?q='.urlencode('straży krosno'))->json('data'), 'order_object'));
        $this->assertSame(['Hełmy'], array_column($this->getJson('/api/notices?q=00777777')->json('data'), 'order_object'));
        $this->assertSame([], $this->getJson('/api/notices?q='.urlencode('100%'))->json('data'));

        $this->getJson('/api/notices?category=nieznany')->assertUnprocessable();
        $this->getJson('/api/notices?tab=wszystkie')->assertUnprocessable();
        $this->getJson('/api/notices?source=logintrade')->assertUnprocessable();
        $this->getJson('/api/notices?source=bzp')->assertOk();
    }

    public function test_pagination_by_fifty(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        for ($i = 1; $i <= 120; $i++) {
            $this->notice(['order_object' => 'Ogłoszenie '.$i, 'submitting_offers_at' => CarbonImmutable::parse('2026-10-10 08:00:00')->addMinutes($i)->format('Y-m-d H:i:s')]);
        }

        $first = $this->getJson('/api/notices')->assertOk();
        $this->assertCount(50, $first->json('data'));
        $this->assertSame(['page' => 1, 'last_page' => 3, 'total' => 120], $first->json('meta'));
        $this->assertSame('Ogłoszenie 1', $first->json('data.0.order_object'));

        $last = $this->getJson('/api/notices?page=3')->assertOk();
        $this->assertCount(20, $last->json('data'));
        $this->assertSame('Ogłoszenie 101', $last->json('data.0.order_object'));

        // strona poza zakresem → ostatnia
        $beyond = $this->getJson('/api/notices?page=9')->assertOk();
        $this->assertSame(3, $beyond->json('meta.page'));
        $this->assertSame('Ogłoszenie 101', $beyond->json('data.0.order_object'));
    }

    public function test_permissions(): void
    {
        $this->notice();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/notices')->assertForbidden();

        // dyrektor: tenders.view_all bez tenders.create — widzi listę, bez podpowiedzi klienta
        Sanctum::actingAs(User::factory()->withRole('dyrektor')->create());
        $this->getJson('/api/notices')->assertOk()->assertJsonPath('data.0.client_match', null);

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson('/api/notices')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_client_match_hint_for_users_who_can_create_tenders(): void
    {
        $byNip = Client::query()->create(['name' => 'Gmina Moszczenica (XL)', 'nip' => 'PL 738-102-19-58']);
        Client::query()->create(['name' => 'Komenda Powiatowa Policji']);
        $this->notice(['order_object' => 'po NIP', 'organization_name' => 'GMINA MOSZCZENICA', 'organization_nip' => '7381021958']);
        $this->notice(['order_object' => 'po nazwie', 'organization_name' => 'Komenda Powiatowa Policji', 'organization_nip' => null]);
        $this->notice(['order_object' => 'nowy', 'organization_name' => 'Zupełnie inny urząd', 'organization_nip' => null]);
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $rows = collect($this->getJson('/api/notices')->assertOk()->json('data'))->keyBy('order_object');

        $this->assertSame(['id' => $byNip->id, 'name' => 'Gmina Moszczenica (XL)', 'matched_by' => 'nip'], $rows['po NIP']['client_match']);
        $this->assertSame('name', $rows['po nazwie']['client_match']['matched_by']);
        $this->assertNull($rows['nowy']['client_match']);
    }

    public function test_tender_link_only_for_users_with_access(): void
    {
        $owner = User::factory()->withRole('handlowiec')->create();
        $other = User::factory()->withRole('handlowiec')->create();
        $invited = User::factory()->withRole('handlowiec')->create();
        $client = Client::query()->create(['name' => 'Starostwo']);
        $notice = $this->notice(['bzp_number' => '2026/BZP 00600001', 'notice_number' => '2026/BZP 00600001/01']);
        $tender = $this->tender($client, ['notice_number' => '2026/BZP 00600001/01', 'owner_id' => $owner->id, 'number' => 'PRZ/2026/0042']);
        TenderInvitation::query()->create(['tender_id' => $tender->id, 'user_id' => $invited->id, 'invited_by' => $owner->id]);

        $expect = function (User $user, bool $canOpen) use ($tender, $notice): void {
            Sanctum::actingAs($user);
            $row = $this->getJson('/api/notices?tab=created')->assertOk()->json('data.0');
            $this->assertSame($notice->id, $row['id']);
            $this->assertSame(['id' => $tender->id, 'number' => 'PRZ/2026/0042', 'can_open' => $canOpen], $row['tender']);
            $this->assertNull($row['client_match']);
        };

        $expect($owner, true);
        $expect($invited, true);
        $expect($other, false);
        $expect(User::factory()->withRole('dyrektor')->create(), true);
    }

    /**
     * Sprawdzone na MariaDB 03.10.2026: ten sam obiekt listy po założeniu przetargu pokazywał stare zakładki
     * (zbiór postępowań z przetargiem zapamiętany z poprzedniego wywołania).
     */
    public function test_same_list_object_sees_changes_between_calls(): void
    {
        $user = User::factory()->withRole('admin')->create();
        $notice = $this->notice(['bzp_number' => '2026/BZP 00600009', 'notice_number' => '2026/BZP 00600009/01', 'organization_name' => 'Nowy urząd']);
        $list = app(NoticeListQuery::class);
        $this->assertSame(['new' => 1, 'created' => 0, 'skipped' => 0], $list->list($user, ['tab' => 'new'])['counts']);
        $this->assertNull($list->list($user, ['tab' => 'new'])['data'][0]['client_match']);

        $client = Client::query()->create(['name' => 'Nowy urząd']);
        $this->tender($client, ['notice_number' => '2026/BZP 00600009/01']);

        $after = $list->list($user, ['tab' => 'created']);
        $this->assertSame(['new' => 0, 'created' => 1, 'skipped' => 0], $after['counts']);
        $this->assertSame($notice->id, $after['data'][0]['id']);
        ProcurementNoticeSkip::query()->create(['procurement_notice_id' => $notice->id, 'user_id' => $user->id]);
        Tender::query()->delete();
        $this->assertSame(['id' => $client->id, 'name' => 'Nowy urząd', 'matched_by' => 'name'], $list->row($notice, $user)['client_match']);
    }

    public function test_fetched_at_counts_a_successful_run_without_new_notices(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->assertNull($this->getJson('/api/notices')->assertOk()->json('fetched_at'));

        $this->notice(['fetched_at' => '2026-10-01 04:30:00']);
        ScheduledTaskRun::query()->create(['task' => 'bzp:fetch', 'started_at' => '2026-10-03 04:30:00', 'finished_at' => '2026-10-03 04:35:00', 'status' => ScheduledTaskRun::STATUS_OK]);
        ScheduledTaskRun::query()->create(['task' => 'bzp:fetch', 'started_at' => '2026-10-03 06:00:00', 'finished_at' => '2026-10-03 06:05:00', 'status' => ScheduledTaskRun::STATUS_FAILED]);

        $this->assertSame('2026-10-03T04:35:00.000000Z', $this->getJson('/api/notices')->assertOk()->json('fetched_at'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function notice(array $overrides = []): ProcurementNotice
    {
        $this->seq++;
        $number = sprintf('2026/BZP %08d', 400000 + $this->seq);

        return ProcurementNotice::query()->create(array_merge([
            'source' => 'bzp',
            'notice_type' => ProcurementNotice::TYPE_CONTRACT,
            'notice_number' => $number.'/01',
            'bzp_number' => $number,
            'object_id' => 'obj-'.$this->seq,
            'published_at' => '2026-10-01 08:00:00',
            'submitting_offers_at' => '2026-10-13 08:00:00',
            'order_object' => 'Ogłoszenie '.$this->seq,
            'cpv_codes' => [['code' => '18141000-9', 'name' => 'Rękawice robocze']],
            'organization_name' => 'Zamawiający '.$this->seq,
            'organization_city' => 'Rzeszów',
            'organization_province' => 'PL18',
            'organization_nip' => null,
            'parsed' => ['has_lots' => false, 'lots' => []],
            'parser_version' => 1,
            'fetched_at' => '2026-10-03 04:30:00',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function tender(Client $client, array $overrides = []): Tender
    {
        $this->seq++;

        return Tender::query()->create(array_merge([
            'number' => 'PRZ/2026/'.(9000 + $this->seq),
            'title' => 'Przetarg',
            'client_id' => $client->id,
            'status' => 'draft',
            'ai_percent' => 0,
        ], $overrides));
    }
}
