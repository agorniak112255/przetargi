<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Competitor;
use App\Models\Tender;
use App\Models\TenderInvitation;
use App\Models\TenderLot;
use App\Models\User;
use App\Services\Tenders\TenderResultStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GET /api/reports/effectiveness i …/csv — „Skuteczność przetargów”: części wygrane / przegrane, zakres
 * przetargów jak w „Sprzedaż i oferty”, okres po dacie terminu. Teraz = sobota 03.10.2026 12:00 w Warszawie.
 */
final class ReportEffectivenessApiTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    private User $marek;

    private Competitor $rival;

    private Tender $t1;

    private Tender $t2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:00', 'Europe/Warsaw'));

        $this->anna = $this->userWith(['reports.view', 'tenders.view_own'], 'Anna Nowak');
        $this->marek = $this->userWith(['reports.view', 'tenders.view_own'], 'Marek Zieliński');
        $this->rival = Competitor::query()->create(['name' => 'BHP-Pro Handel sp. z o.o.', 'name_key' => 'bhp pro handel', 'nip' => '1181625269']);
        $us = Competitor::query()->create(['name' => 'PHT SUPON Sp. z o.o.', 'name_key' => 'pht supon', 'nip' => '8132283737']);

        // Anna, 10.09: część 1 wygrana (rękawice), część 2 przegrana ceną z BHP-Pro (obuwie), znamy obie ceny
        $this->t1 = $this->tender($this->anna, '2026-09-10', 'exported', '2026/BZP 00431178/01');
        $this->lot($this->t1, 1, 'won', ['cpv_main' => '18141000-9', 'winner_competitor_id' => $us->id]);
        $this->lot($this->t1, 2, 'lost', [
            'cpv_main' => '18830000-6', 'loss_reason' => 'price', 'winner_competitor_id' => $this->rival->id,
            'our_net' => '1000.00', 'our_vat_rate' => '23.00', 'winner_price' => '1100.00', 'note' => 'Tańsze obuwie',
        ]);
        // Marek, 20.09: przegrana (wymaganie) z BHP-Pro bez cen, część 2 unieważniona (wynik wpisał opiekun)
        $this->t2 = $this->tender($this->marek, '2026-09-20', 'exported');
        $this->lot($this->t2, 1, 'lost', ['cpv_main' => '18830000-6', 'loss_reason' => 'requirement', 'winner_competitor_id' => $this->rival->id]);
        $this->lot($this->t2, 2, 'cancelled', ['name' => 'Kaski', 'manual_fields' => ['outcome']]);
        // Anna, 01.06 — poza 90 dniami, w tym roku: wygrana bez kodu CPV
        $old = $this->tender($this->anna, '2026-06-01', 'exported');
        $this->lot($old, 1, 'won');
        // Anna: bez wyniku po terminie (liczy się), szkic po terminie (nie), termin w przyszłości (nie)
        $this->tender($this->anna, '2026-09-25', 'exported', null, 'Bez wyniku');
        $this->tender($this->anna, '2026-09-26', 'draft');
        $this->tender($this->anna, '2026-10-10', 'exported');
        // dziś (03.10) termin — jeszcze nie „po terminie”
        $this->tender($this->anna, '2026-10-03', 'exported');
    }

    public function test_salesperson_sees_own_tenders_in_last_90_days(): void
    {
        Sanctum::actingAs($this->anna);

        $json = $this->getJson('/api/reports/effectiveness')->assertOk()->json();

        $this->assertSame(['90d', '2026-07-06', '2026-10-03', 'own'], [$json['period'], $json['from'], $json['to'], $json['scope']]);
        $this->assertSame([
            'decided_lots' => 2, 'won_lots' => 1, 'lost_lots' => 1, 'win_rate' => 50,
            'cancelled_lots' => 0, 'not_submitted_lots' => 0, 'no_result_tenders' => 1,
            'top_loss_reason' => ['reason' => 'price', 'count' => 1],
            // (1230 − 1100) / 1230 = 10,6% — byliśmy drożsi
            'avg_price_gap_percent' => 10.6, 'price_gap_lots' => 1, 'weakest_category' => null,
        ], $json['summary']);
        $this->assertSame([['owner_id' => $this->anna->id, 'owner_name' => 'Anna Nowak', 'decided' => 2, 'won' => 1, 'win_rate' => 50]], $json['by_owner']);
        $this->assertSame([['reason' => 'price', 'label' => 'Cena', 'count' => 1]], $json['loss_reasons']);
        $this->assertSame([[
            'competitor_id' => $this->rival->id, 'name' => 'BHP-Pro Handel sp. z o.o.', 'nip' => '1181625269',
            'won_against_us' => 1, 'avg_cheaper_percent' => 10.6,
        ]], $json['competitors']);
        $this->assertSame([
            ['key' => 'footwear', 'label' => 'Obuwie', 'decided' => 1, 'won' => 0, 'win_rate' => 0],
            ['key' => 'gloves', 'label' => 'Rękawice', 'decided' => 1, 'won' => 1, 'win_rate' => 100],
        ], $json['categories']);
        $this->assertSame([], $json['cancelled']);
        $this->assertCount(1, $json['no_result']);
        $this->assertSame('Bez wyniku', $json['no_result'][0]['title']);
        $this->assertSame('2026-09-25', $json['no_result'][0]['deadline']);
        $this->assertSame([['owner_id' => $this->anna->id, 'owner_name' => 'Anna Nowak', 'count' => 1]], $json['no_result_by_owner']);
    }

    public function test_invited_salesperson_sees_tender_and_view_all_sees_everything(): void
    {
        TenderInvitation::query()->create(['tender_id' => $this->t2->id, 'user_id' => $this->anna->id, 'invited_by' => $this->marek->id]);
        Sanctum::actingAs($this->anna);
        $this->assertSame(3, $this->getJson('/api/reports/effectiveness')->assertOk()->json('summary.decided_lots'));

        Sanctum::actingAs($this->userWith(['reports.view', 'tenders.view_all'], 'Zarząd'));
        $json = $this->getJson('/api/reports/effectiveness')->assertOk()->json();

        $this->assertSame('all', $json['scope']);
        $this->assertSame([3, 1, 2, 1], [$json['summary']['decided_lots'], $json['summary']['won_lots'], $json['summary']['lost_lots'], $json['summary']['cancelled_lots']]);
        $this->assertSame(33.3, $json['summary']['win_rate']);
        $this->assertSame(['Anna Nowak', 'Marek Zieliński'], array_column($json['by_owner'], 'owner_name'));
        $this->assertSame([['reason' => 'price', 'label' => 'Cena', 'count' => 1], ['reason' => 'requirement', 'label' => 'Nie spełniliśmy wymagania', 'count' => 1]], $json['loss_reasons']);
        // dwie przegrane z tą samą firmą, różnica cen znana tylko w jednej; nasza firma (zwycięzca wygranej) poza listą
        $this->assertSame([[
            'competitor_id' => $this->rival->id, 'name' => 'BHP-Pro Handel sp. z o.o.', 'nip' => '1181625269',
            'won_against_us' => 2, 'avg_cheaper_percent' => 10.6,
        ]], $json['competitors']);
        $this->assertSame(['tender_id' => $this->t2->id, 'lot_no' => 2, 'lot_name' => 'Kaski'], array_intersect_key($json['cancelled'][0], array_flip(['tender_id', 'lot_no', 'lot_name'])));
    }

    public function test_cancelled_lots_from_bulletin_without_our_price_are_not_counted(): void
    {
        // ogłoszenie z wieloma częściami: Biuletyn założył i unieważnił części, w których nie startowaliśmy
        $tender = $this->tender($this->anna, '2026-09-15', 'exported', '2026/BZP 00449679/01');
        $this->lot($tender, 1, 'cancelled', ['name' => 'Bez naszej oferty', 'created_by_bzp' => true]);
        $this->lot($tender, 2, 'cancelled', ['name' => 'Nasza cena', 'our_net' => '500.00', 'created_by_bzp' => true]);
        $this->lot($tender, 3, 'cancelled', ['name' => 'Wynik wpisany ręcznie', 'manual_fields' => ['outcome'], 'created_by_bzp' => true]);
        $this->lot($tender, 4, 'cancelled', ['name' => 'Tylko notatka', 'note' => 'Nie startowaliśmy', 'manual_fields' => ['note'], 'created_by_bzp' => true]);
        Sanctum::actingAs($this->anna);

        $json = $this->getJson('/api/reports/effectiveness')->assertOk()->json();

        $this->assertSame(2, $json['summary']['cancelled_lots']);
        $this->assertSame([2, 3], array_column($json['cancelled'], 'lot_no'));
    }

    public function test_only_lot_cancelled_by_bulletin_is_counted(): void
    {
        // przetarg jednoczęściowy: część założył Biuletyn i ją unieważnił, naszej ceny nikt nie wpisał — to nasza część
        $tender = $this->tender($this->anna, '2026-09-15', 'exported', '2026/BZP 00439099/01');
        $this->lot($tender, 1, 'cancelled', ['name' => 'Rękawice', 'created_by_bzp' => true]);
        Sanctum::actingAs($this->anna);

        $json = $this->getJson('/api/reports/effectiveness')->assertOk()->json();

        $this->assertSame(1, $json['summary']['cancelled_lots']);
        $this->assertSame([[$tender->id, 1]], array_map(static fn (array $r): array => [$r['tender_id'], $r['lot_no']], $json['cancelled']));
    }

    public function test_whole_tender_cancelled_with_bulletin_lots_is_counted_once(): void
    {
        // pięć części z Biuletynu, wszystkie unieważnione, bez naszej ceny — przetarg unieważniony liczy się raz
        $tender = $this->tender($this->anna, '2026-09-15', 'exported', '2026/BZP 00449679/01');
        foreach ([3, 1, 5, 2, 4] as $no) {
            $this->lot($tender, $no, 'cancelled', ['name' => 'Część '.$no, 'created_by_bzp' => true]);
        }
        $this->assertSame('cancelled', $tender->refresh()->result_status);
        Sanctum::actingAs($this->anna);

        $json = $this->getJson('/api/reports/effectiveness')->assertOk()->json();

        $this->assertSame(1, $json['summary']['cancelled_lots']);
        $this->assertCount(1, $json['cancelled']);
        $this->assertSame([$tender->id, 1, 'Część 1'], [$json['cancelled'][0]['tender_id'], $json['cancelled'][0]['lot_no'], $json['cancelled'][0]['lot_name']]);
    }

    public function test_manual_lot_cancelled_by_bulletin_is_counted_and_bulletin_lot_without_our_price_is_skipped(): void
    {
        // część 1 założona przez Biuletyn (nie startowaliśmy), część 2 założona ręcznie — obie unieważnione przez Biuletyn
        $tender = $this->tender($this->anna, '2026-09-15', 'exported', '2026/BZP 00361360');
        $this->lot($tender, 1, 'cancelled', ['name' => 'Obuwie', 'created_by_bzp' => true]);
        $this->lot($tender, 2, 'cancelled', ['name' => 'Rękawice']);
        // inny przetarg: część Biuletynu bez naszej ceny obok przegranej — pominięta
        $other = $this->tender($this->anna, '2026-09-16', 'exported', '2026/BZP 00376786');
        $this->lot($other, 1, 'cancelled', ['name' => 'Kaski', 'created_by_bzp' => true]);
        $this->lot($other, 2, 'lost', ['name' => 'Kurtki', 'created_by_bzp' => true]);
        Sanctum::actingAs($this->anna);

        $json = $this->getJson('/api/reports/effectiveness')->assertOk()->json();

        $this->assertSame(1, $json['summary']['cancelled_lots']);
        $this->assertSame([[$tender->id, 2]], array_map(static fn (array $r): array => [$r['tender_id'], $r['lot_no']], $json['cancelled']));
    }

    public function test_year_period_and_lots_without_cpv(): void
    {
        Sanctum::actingAs($this->anna);

        $json = $this->getJson('/api/reports/effectiveness?period=year')->assertOk()->json();

        $this->assertSame(['year', '2026-01-01'], [$json['period'], $json['from']]);
        $this->assertSame([3, 2], [$json['summary']['decided_lots'], $json['summary']['won_lots']]);
        $this->assertSame(66.7, $json['summary']['win_rate']);
        // część bez kodu CPV nie jest liczona w rodzajach towaru — mówi o tym notatka
        $this->assertSame(2, array_sum(array_column($json['categories'], 'decided')));
        $this->assertStringContainsString('Bez kodu: 1 z 3 rozstrzygniętych części', (string) $json['categories_note']);
        // nieznany okres → 90 dni
        $this->assertSame('90d', $this->getJson('/api/reports/effectiveness?period=abc')->json('period'));
    }

    public function test_weakest_category_needs_enough_decided_lots_in_two_categories(): void
    {
        $tender = $this->tender($this->anna, '2026-09-12', 'exported');
        foreach ([[1, 'won', '18141000-9'], [2, 'won', '18424000-7'], [3, 'lost', '18830000-6'], [4, 'lost', '18832000-0']] as [$no, $outcome, $cpv]) {
            $this->lot($tender, $no, $outcome, ['cpv_main' => $cpv]);
        }
        Sanctum::actingAs($this->anna);

        $summary = $this->getJson('/api/reports/effectiveness')->assertOk()->json('summary');

        // obuwie 0 z 3, rękawice 3 z 3
        $this->assertSame(['key' => 'footwear', 'label' => 'Obuwie', 'win_rate' => 0, 'decided' => 3], $summary['weakest_category']);
    }

    public function test_csv_has_bom_one_row_per_lot_and_own_scope(): void
    {
        Sanctum::actingAs($this->anna);

        $response = $this->get('/api/reports/effectiveness/csv')->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('skutecznosc-przetargow.csv', (string) $response->headers->get('content-disposition'));
        $lines = array_values(array_filter(explode("\n", substr($csv, 3))));
        $this->assertStringStartsWith('"Numer przetargu";"Numer ogłoszenia";Tytuł;', $lines[0]);
        // cena zwycięzcy z ogłoszenia jest przyjmowana jako brutto — nagłówki mówią to wprost
        $this->assertStringContainsString('"Cena zwycięzcy (z ogłoszenia, przyjęta jako brutto)"', $lines[0]);
        $this->assertStringContainsString('"Różnica do zwycięzcy % (nasza cena brutto do ceny zwycięzcy)"', $lines[0]);
        $this->assertSame(2, substr_count($csv, $this->t1->number.';'));
        $this->assertStringNotContainsString($this->t2->number, $csv);
        $rows = array_map(static fn (string $line): array => str_getcsv($line, ';'), array_slice($lines, 1));
        $number = $this->t1->number;
        $lost = array_values(array_filter($rows, static fn (array $row): bool => $row[0] === $number && $row[8] === '2'))[0];
        $this->assertSame('2026/BZP 00431178/01', $lost[1]);
        $this->assertSame('częściowo wygrany', $lost[7]);
        $this->assertSame(['przegrana', 'Cena', '1000,00', '23,00', '1230,00', 'BHP-Pro Handel sp. z o.o.', '1181625269', '1100,00', 'PLN', '10,6'], array_slice($lost, 12, 10));
        $this->assertSame('Obuwie', $lost[11]);
        $this->assertSame('Tańsze obuwie', $lost[26]);
        // przetarg bez części — jeden wiersz „bez wyniku”
        $noResult = array_values(array_filter($rows, static fn (array $row): bool => $row[2] === 'Bez wyniku'))[0];
        $this->assertSame('bez wyniku', $noResult[7]);
        $this->assertSame('', $noResult[8]);
        $this->assertCount(27, $noResult);
    }

    public function test_requires_tender_permission(): void
    {
        Sanctum::actingAs($this->userWith(['reports.view']));
        $this->getJson('/api/reports/effectiveness')->assertForbidden();
        $this->get('/api/reports/effectiveness/csv')->assertForbidden();

        Sanctum::actingAs($this->userWith(['tenders.view_all']));
        $this->getJson('/api/reports/effectiveness')->assertForbidden();
    }

    public function test_tender_csv_has_new_columns_at_the_end(): void
    {
        $this->t1->forceFill(['deadline_time' => '10:00', 'result_status' => 'partial'])->save();
        Sanctum::actingAs($this->anna);

        $csv = $this->get('/api/reports/csv')->assertOk()->streamedContent();

        $this->assertStringStartsWith('number;title;client;owner;status;offer_value_net;margin_percent;deadline;ai_percent;deadline_time;notice_number;result_status', $csv);
        $this->assertStringContainsString(';2026-09-10;0;10:00;"2026/BZP 00431178/01";partial', $csv);
    }

    /**
     * @param  list<string>  $permissions
     */
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

    private function tender(User $owner, string $deadline, string $status, ?string $notice = null, ?string $title = null): Tender
    {
        return Tender::query()->create([
            'number' => 'PRZ/2026/'.Str::upper(Str::random(6)),
            'title' => $title ?? 'Przetarg '.Str::random(6),
            'client_id' => Client::query()->create(['name' => 'Zamawiający '.Str::random(4)])->id,
            'owner_id' => $owner->id,
            'status' => $status,
            'deadline' => $deadline,
            'ai_percent' => 0,
            'notice_number' => $notice,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function lot(Tender $tender, int $no, string $outcome, array $attrs = []): void
    {
        TenderLot::query()->create(['tender_id' => $tender->id, 'lot_no' => $no, 'outcome' => $outcome, 'currency' => 'PLN', ...$attrs]);
        $tender->forceFill(['result_status' => TenderResultStatus::compute($tender->lots()->pluck('outcome')->all())])->save();
    }
}
