<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\CampaignRecipient;
use App\Models\CampaignRecipientCustomer;
use App\Models\ErpCustomer;
use App\Models\ErpItem;
use App\Models\ErpSaleLine;
use App\Models\User;
use App\Services\Campaigns\CampaignWindow;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CampaignFixtures;
use Tests\TestCase;

/**
 * Raport „Wynik kampanii” (GET /api/reports/campaigns, /csv): przypisanie pozycji faktur kampaniom („ostatni mail
 * wygrywa”), okno w czasie polskim, limit stanu, korekty, koszt z ERP XL / szacunek / brak, zalegający towar, zakres.
 */
final class CampaignsReportTest extends TestCase
{
    use CampaignFixtures;
    use RefreshDatabase;

    private int $token = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-04 10:00'));
        $this->setUpCampaigns();
    }

    /** @param  array<string, mixed>  $attrs */
    private function started(User $author, ErpItem $item, string $startedAt, array $snap = [], array $attrs = []): Campaign
    {
        $campaign = $this->campaign($author, [$item], ['status' => 'sent', 'sending_started_at' => $startedAt, 'sent_at' => $startedAt, ...$attrs]);
        CampaignItem::query()->where('campaign_id', $campaign->id)->update([
            'snap_stock' => 100,
            'snap_unit_cost' => 20,
            'snap_last_sale_at' => '2026-01-01',
            'snap_oldest_lot_at' => '2025-06-01',
            'snap_source' => 'send',
            ...$snap,
        ]);

        return $campaign;
    }

    private function mail(Campaign $campaign, ErpCustomer $customer, string $sentAt, string $matchedBy = CampaignRecipientCustomer::MATCHED_DIRECT, int $cards = 1): void
    {
        $email = 'k'.(++$this->token).'@example.pl';
        $recipient = CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id, 'email' => $email, 'erp_customer_id' => $matchedBy === 'direct' ? $customer->id : null,
            'source' => 'xl', 'token' => str_pad((string) $this->token, 40, 't'), 'status' => 'sent', 'sent_at' => $sentAt,
        ]);
        CampaignRecipientCustomer::query()->create([
            'campaign_id' => $campaign->id, 'campaign_recipient_id' => $recipient->id, 'erp_customer_id' => $customer->id,
            'matched_by' => $matchedBy, 'cards_count' => $cards,
        ]);
    }

    /** @param  array<string, mixed>  $attrs */
    private function line(int $documentId, string $date, ErpCustomer $customer, ErpItem $item, float $quantity, float $net, ?float $cost, array $attrs = []): ErpSaleLine
    {
        return ErpSaleLine::query()->create([
            'document_type' => 2033, 'document_id' => $documentId, 'line' => 1, 'document_number' => 'FS-'.$documentId,
            'sold_at' => $date, 'customer_xl_gid' => $customer->xl_gid, 'erp_customer_id' => $customer->id,
            'erp_item_id' => $item->id, 'quantity' => $quantity, 'net_value' => $net, 'cost_value' => $cost, 'synced_at' => now(),
            ...$attrs,
        ]);
    }

    /** Liczba z JSON (12.0 przychodzi jako 12) — null albo tekst nie przejdzie jako kwota. */
    private function zl(mixed $value): float
    {
        $this->assertTrue(is_int($value) || is_float($value), 'Oczekiwana liczba, jest: '.var_export($value, true));

        return (float) $value;
    }

    private function admin(): User
    {
        return User::factory()->withRole('admin')->create(['name' => 'Zarząd']);
    }

    /** @return array<string, mixed> */
    private function report(string $month, ?int $userId = null): array
    {
        return $this->getJson('/api/reports/campaigns?month='.$month.($userId !== null ? '&user_id='.$userId : ''))->assertOk()->json();
    }

    /** @param  array<string, mixed>  $report */
    private function campaignRow(array $report, Campaign $campaign): array
    {
        foreach ($report['campaigns'] as $row) {
            if ($row['id'] === $campaign->id) {
                return $row;
            }
        }
        $this->fail('Brak kampanii '.$campaign->id.' w raporcie.');
    }

    public function test_latest_mail_wins_and_other_campaign_sees_it_as_taken_over(): void
    {
        $jan = $this->sender();
        $ewa = User::factory()->withRole('handlowiec')->create(['name' => 'Ewa Handlowiec']);
        $boots = $this->erpItem('B1');
        $alfa = $this->customer('ALFA', ['a@alfa.pl']);
        $first = $this->started($jan, $boots, '2026-09-01 08:00');
        $second = $this->started($ewa, $boots, '2026-09-10 08:00');
        $this->mail($first, $alfa, '2026-09-01 08:10');
        $this->mail($second, $alfa, '2026-09-10 08:10');
        $this->line(1, '2026-09-05', $alfa, $boots, 1, 100, 20);  // tylko pierwsza kampania
        $this->line(2, '2026-09-15', $alfa, $boots, 2, 200, 40);  // obie — wygrywa późniejszy mail
        $this->line(3, '2026-10-05', $alfa, $boots, 1, 100, 20);  // po oknie pierwszej (1.10), w oknie drugiej (do 10.10)

        Sanctum::actingAs($this->admin());
        $r = $this->report('2026-09');
        // nagłówek odpowiedzi: miesiąc jako RRRR-MM (pętla po kampaniach nie może go nadpisać — biały ekran na produkcji)
        $this->assertSame('2026-09', $r['month']);
        $this->assertTrue($r['closed']);
        $this->assertSame('2026-10-07', $r['final_after']);
        $a = $this->campaignRow($r, $first);
        $b = $this->campaignRow($r, $second);
        $this->assertSame([100.0, 200.0], [$this->zl($a['month']['sales_net']), $this->zl($b['month']['sales_net'])]);
        $this->assertSame(1, $a['taken_over']['lines']);
        $this->assertSame(200.0, $this->zl($a['taken_over']['sales_net']));
        $this->assertSame([['campaign_id' => $second->id, 'code' => $second->code, 'user_name' => 'Ewa Handlowiec']], $a['taken_over']['by']);
        $this->assertSame(0, $b['taken_over']['lines']);
        // pozycja liczy się raz: suma = 300, nie 500
        $this->assertSame(300.0, $this->zl($r['totals']['sales_net']));
        $this->assertSame(1, $r['totals']['buyers']);
        $this->assertSame(['Ewa Handlowiec', 'Jan Handlowiec'], array_column($r['people'], 'name'));

        // ten sam dzień maila — wyższy numer kampanii
        $third = $this->started($jan, $boots, '2026-09-10 06:00');
        $this->mail($third, $alfa, '2026-09-10 06:30');
        $r = $this->report('2026-09');
        $this->assertSame(200.0, $this->zl($this->campaignRow($r, $third)['month']['sales_net']));
        $this->assertSame(0.0, $this->zl($this->campaignRow($r, $second)['month']['sales_net']));
        $this->assertSame(300.0, $this->zl($r['totals']['sales_net']));
    }

    public function test_recipient_added_later_counts_from_his_own_mail_day(): void
    {
        $jan = $this->sender();
        $boots = $this->erpItem('B1');
        $alfa = $this->customer('ALFA', ['a@alfa.pl']);
        $beta = $this->customer('BETA', ['b@beta.pl']);
        $campaign = $this->started($jan, $boots, '2026-09-01 08:00');
        $this->mail($campaign, $alfa, '2026-09-01 08:10');
        $this->mail($campaign, $beta, '2026-09-20 09:00');
        $this->line(1, '2026-09-15', $beta, $boots, 1, 100, 20);  // przed mailem BETY
        $this->line(2, '2026-09-20', $beta, $boots, 1, 150, 20);
        $this->line(3, '2026-09-02', $alfa, $boots, 1, 50, 20);

        Sanctum::actingAs($jan);
        $r = $this->report('2026-09');
        $this->assertSame('own', $r['scope']);
        $this->assertSame(200.0, $this->zl($r['totals']['sales_net']));
        $this->assertSame(2, $r['totals']['buyers']);
        $this->assertSame(2, $r['totals']['recipients_sent']);
    }

    public function test_window_uses_polish_days_for_start_and_mail(): void
    {
        $jan = $this->sender();
        $boots = $this->erpItem('B1');
        $alfa = $this->customer('ALFA', ['a@alfa.pl']);
        // 31.08 22:30 UTC = 1.09 00:30 w Polsce — okno 1.09–1.10 (w UTC byłoby 31.08–30.09)
        $campaign = $this->started($jan, $boots, '2026-08-31 22:30');
        $this->mail($campaign, $alfa, '2026-08-31 22:30');
        $this->assertSame('2026-09-01', CampaignWindow::startDay($campaign)?->toDateString());
        $this->assertSame('2026-10-01', CampaignWindow::endDay($campaign)?->toDateString());
        $this->line(1, '2026-08-31', $alfa, $boots, 1, 100, 20);
        $this->line(2, '2026-10-01', $alfa, $boots, 1, 70, 20);
        $this->line(3, '2026-10-02', $alfa, $boots, 1, 30, 20);

        Sanctum::actingAs($jan);
        $this->assertSame(0.0, $this->zl($this->report('2026-08')['totals']['sales_net']));
        $october = $this->report('2026-10');
        $this->assertSame(70.0, $this->zl($october['totals']['sales_net']));
        $row = $this->campaignRow($october, $campaign);
        $this->assertSame(['2026-10-01', false], [$row['window_end'], $row['window_open']]);

        // strona kampanii liczy to samo okno
        $sales = $this->getJson("/api/campaigns/{$campaign->id}")->assertOk()->json('sales');
        $this->assertSame(['2026-09-01', '2026-10-01', true], [$sales['from'], $sales['to'], $sales['complete']]);
        $this->assertEquals(['customers' => 1, 'net_value' => 70.0], $sales['recipients']);
    }

    public function test_stock_limit_cost_sources_and_over_stock(): void
    {
        $jan = $this->sender();
        $boots = $this->erpItem('B1');
        $gloves = $this->erpItem('R1');
        $alfa = $this->customer('ALFA', ['a@alfa.pl']);
        $campaign = $this->campaign($jan, [$boots, $gloves], ['status' => 'sent', 'sending_started_at' => '2026-09-01 08:00']);
        // buty: zalegające (243 dni bez sprzedaży), stan 10, koszt jednostki 20; rękawice: bez kosztu w migawce
        CampaignItem::query()->where('erp_item_id', $boots->id)->update([
            'snap_stock' => 10, 'snap_unit_cost' => 20, 'snap_last_sale_at' => '2026-01-01', 'snap_source' => 'send',
        ]);
        CampaignItem::query()->where('erp_item_id', $gloves->id)->update([
            'snap_stock' => 5, 'snap_unit_cost' => null, 'snap_last_sale_at' => '2026-01-01', 'snap_source' => 'send',
        ]);
        $this->mail($campaign, $alfa, '2026-09-01 08:10');
        $this->line(1, '2026-09-03', $alfa, $boots, 6, 300, 120);    // koszt z ERP XL, cała w limicie
        $this->line(2, '2026-09-04', $alfa, $boots, 6, 300, null);   // szacunek 6 × 20 = 120, w limicie 4 z 6
        $this->line(3, '2026-09-05', $alfa, $gloves, 1, 40, null);   // koszt nieznany

        Sanctum::actingAs($jan);
        $r = $this->report('2026-09');
        $t = $r['totals'];
        $this->assertSame(640.0, $this->zl($t['sales_net']));
        $this->assertSame(200.0, $this->zl($t['freed_capital']));          // 120 + 120 × 4/6
        $this->assertSame(80.0, $this->zl($t['freed_estimated']));
        $this->assertSame(500.0, $this->zl($t['freed_sales_net']));        // 300 + 300 × 4/6
        $this->assertSame(250.0, $this->zl($t['recovery_percent']));
        $this->assertSame(360.0, $this->zl($t['margin']));                 // rękawice bez kosztu poza marżą
        $this->assertSame(60.0, $this->zl($t['margin_percent']));
        $this->assertSame(1, $t['lines_without_cost']);
        $this->assertSame(243, $t['avg_idle_days']);
        // rękawice: zalegające bez kosztu — nie wiadomo, ile uwolniły (freed_unknown_lines), nie „0 zł”
        $this->assertSame(['cost_estimated_lines' => 1, 'multi_card_lines' => 0, 'unlinked_corrections' => 0, 'over_stock_lines' => 1, 'no_snapshot_items' => 0, 'freed_unknown_lines' => 1], $r['warnings']);

        $items = $this->campaignRow($r, $campaign)['items'];
        $this->assertSame([true, 243, 12.0, 2.0, true, 50.0, 2], [
            $items[0]['stagnant'], $items[0]['idle_days'], $this->zl($items[0]['window']['quantity']), $this->zl($items[0]['window']['over_stock_quantity']),
            $items[0]['cost_estimated'], $this->zl($items[0]['window']['avg_price']), $items[0]['window']['first_sale_days'],
        ]);
        $this->assertNull($items[1]['month']['margin']);
        $this->assertSame(200.0, $this->zl($this->campaignRow($r, $campaign)['window']['offered_stock_value']));
        $this->assertSame(100.0, $this->zl($this->campaignRow($r, $campaign)['window']['effectiveness_percent']));
        $this->assertSame(200.0, $this->zl($r['top_items'][0]['freed_capital']));
        $this->assertCount(1, $r['top_items']);
    }

    public function test_wz_lines_are_sales_and_wzk_corrects_them_like_an_invoice_correction(): void
    {
        $jan = $this->sender();
        $boots = $this->erpItem('B1');
        $alfa = $this->customer('ALFA', ['a@alfa.pl']);
        $campaign = $this->started($jan, $boots, '2026-09-10 08:00');
        $this->mail($campaign, $alfa, '2026-09-10 08:10');
        // faktura do WZ nie ma pozycji w XL: towar z WZ (data wydania), numer z faktury ze spinacza
        $this->line(31, '2026-09-15', $alfa, $boots, 4, 400, 160, ['document_type' => 2001, 'document_number' => 'FS-01H/77/26/09']);
        // WZ jeszcze bez faktury — własny numer
        $this->line(32, '2026-09-28', $alfa, $boots, 1, 100, 40, ['document_type' => 2001, 'document_number' => 'WZ-01H/32/26/09']);
        // WZK zwrotu 1 szt. z pierwszej WZ (korekta faktury do WZ jest w XL bez pozycji) — dziedziczy kampanię po WZ
        $this->line(33, '2026-10-02', $alfa, $boots, -1, -100, -40, [
            'document_type' => 2009, 'document_number' => 'FSK-01H/5/26/10', 'corrects_document_type' => 2001, 'corrects_document_id' => 31,
        ]);

        Sanctum::actingAs($jan);
        $sep = $this->report('2026-09');
        $this->assertSame(500.0, $this->zl($sep['totals']['sales_net']));
        $this->assertSame(1, $sep['totals']['buyers']);
        $oct = $this->report('2026-10');
        $this->assertSame(-100.0, $this->zl($oct['totals']['corrections_net']));
        $this->assertSame(0, $oct['warnings']['unlinked_corrections']);

        $csv = $this->get('/api/reports/campaigns/csv?month=2026-09')->assertOk()->streamedContent();
        $rows = array_map(static fn (string $l): array => str_getcsv($l, ';'), array_values(array_filter(explode("\n", substr($csv, 3)))));
        $this->assertSame([['2026-09-15', 'FS-01H/77/26/09', 'faktura do WZ'], ['2026-09-28', 'WZ-01H/32/26/09', 'WZ bez faktury']], [
            array_slice($rows[1], 0, 3), array_slice($rows[2], 0, 3),
        ]);

        // strona kampanii: 400 + 100 − 100
        $this->assertEquals(['customers' => 1, 'net_value' => 400.0], $this->getJson("/api/campaigns/{$campaign->id}")->assertOk()->json('sales.recipients'));
    }

    public function test_corrections_inherit_campaign_and_fraction_and_unlinked_ones_only_warn(): void
    {
        $jan = $this->sender();
        $boots = $this->erpItem('B1');
        $alfa = $this->customer('ALFA', ['a@alfa.pl']);
        $campaign = $this->started($jan, $boots, '2026-09-10 08:00', ['snap_stock' => 5]);
        $this->mail($campaign, $alfa, '2026-09-10 08:10');
        $this->line(1, '2026-09-20', $alfa, $boots, 10, 500, 200);   // w limicie połowa
        // październik: zwrot 2 szt., korekta ceny (ilość 0, koszt 0 zapisany jako brak) i korekta bez faktury w kopii
        $this->line(7, '2026-10-02', $alfa, $boots, -2, -100, -40, ['document_type' => 2041, 'document_number' => 'FSK-7', 'corrects_document_type' => 2033, 'corrects_document_id' => 1]);
        $this->line(8, '2026-10-03', $alfa, $boots, 0, -50, null, ['document_type' => 2041, 'document_number' => 'FSK-8', 'corrects_document_type' => 2033, 'corrects_document_id' => 1]);
        // korekta korekty (do FSK-7)
        $this->line(9, '2026-10-03', $alfa, $boots, 1, 50, 20, ['document_type' => 2041, 'document_number' => 'FSK-9', 'corrects_document_type' => 2041, 'corrects_document_id' => 7]);
        // korekta faktury spoza kopii (sprzed startu kampanii) — nie mogła należeć do kampanii, bez ostrzeżenia
        $this->line(10, '2026-10-03', $alfa, $boots, -1, -60, -20, ['document_type' => 2041, 'document_number' => 'FSK-10', 'corrects_document_type' => 2033, 'corrects_document_id' => 999]);
        // korekta, przy której ERP XL nie wskazał dokumentu korygowanego — ostrzeżenie, poza wynikiem
        $this->line(11, '2026-10-03', $alfa, $boots, -1, -60, -20, ['document_type' => 2041, 'document_number' => 'FSK-11']);

        Sanctum::actingAs($jan);
        $sep = $this->report('2026-09')['totals'];
        $this->assertSame([500.0, 100.0, 250.0], [$this->zl($sep['sales_net']), $this->zl($sep['freed_capital']), $this->zl($sep['freed_sales_net'])]);

        $oct = $this->report('2026-10');
        $t = $oct['totals'];
        $this->assertSame(-100.0, $this->zl($t['sales_net']));                 // −100 − 50 + 50
        $this->assertSame(-100.0, $this->zl($t['corrections_net']));
        $this->assertSame(-10.0, $this->zl($t['freed_capital']));              // (−40 + 0 + 20) × ½
        $this->assertSame(-50.0, $this->zl($t['freed_sales_net']));
        $this->assertSame(-80.0, $this->zl($t['margin']));                     // (−100+40) + (−50−0) + (50−20)
        $this->assertSame(0, $t['buyers']);
        $this->assertSame(0, $t['below_cost_lines']);
        $this->assertSame(0, $oct['warnings']['cost_estimated_lines']);
        $this->assertSame(1, $oct['warnings']['unlinked_corrections']);
        // kampania z 10.09 ma okno do 10.10 — jest na liście października
        $this->assertSame(-100.0, $this->zl($this->campaignRow($oct, $campaign)['month']['sales_net']));

        $csv = $this->get('/api/reports/campaigns/csv?month=2026-10')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = array_map(static fn (string $l): array => str_getcsv($l, ';'), array_values(array_filter(explode("\n", substr($csv, 3)))));
        $this->assertSame('Data', $rows[0][0]);
        $this->assertCount(4, $rows);   // nagłówek + 3 korekty z fakturą (bez FSK-10 i FSK-11)
        $this->assertSame(['2026-10-02', 'FSK-7', 'korekta', 'ALFA', 'B1', '-2', '-100,00', '-40,00', 'ERP XL', 'tak', '50', '-20,00', '-60,00'], [
            $rows[1][0], $rows[1][1], $rows[1][2], $rows[1][3], $rows[1][4], $rows[1][6], $rows[1][8], $rows[1][9], $rows[1][10],
            $rows[1][15], $rows[1][17], $rows[1][18], $rows[1][19],
        ]);
        $this->assertSame('wybrany z ERP XL', $rows[1][14]);

        // strona kampanii: korekty odejmują wartość (500 − 100 − 50 + 50 − pomijana bez faktury)
        $sales = $this->getJson("/api/campaigns/{$campaign->id}")->assertOk()->json('sales');
        $this->assertEquals(['customers' => 1, 'net_value' => 400.0], $sales['recipients']);
        $this->assertEquals(9.0, $sales['items'][0]['quantity_recipients']);
        $this->assertEquals(['customers' => 1, 'net_value' => 400.0, 'complete' => false], $this->getJson('/api/campaigns')->json('data.0.sales'));
    }

    public function test_stock_at_send_is_shared_by_all_sales_after_start(): void
    {
        $jan = $this->sender();
        $ewa = User::factory()->withRole('handlowiec')->create(['name' => 'Ewa Handlowiec']);
        $boots = $this->erpItem('B1');
        $alfa = $this->customer('ALFA', ['a@alfa.pl']);
        $beta = $this->customer('BETA', ['b@beta.pl']);
        $gamma = $this->customer('GAMMA', ['g@gamma.pl']);
        // ten sam zalegający towar, stan 10 przy obu wysyłkach, koszt 20 zł
        $first = $this->started($jan, $boots, '2026-09-01 08:00', ['snap_stock' => 10]);
        $second = $this->started($ewa, $boots, '2026-09-02 08:00', ['snap_stock' => 10]);
        $this->mail($first, $alfa, '2026-09-01 08:10');
        $this->mail($second, $beta, '2026-09-02 08:10');
        $this->line(1, '2026-09-03', $gamma, $boots, 4, 400, 80);   // klient spoza kampanii zjada 4 z 10
        $this->line(2, '2026-09-04', $alfa, $boots, 4, 400, 80);    // kampania 1: 4 w limicie (zostało 2)
        $this->line(3, '2026-09-05', $beta, $boots, 4, 400, 80);    // kampania 2: tylko 2 w limicie

        Sanctum::actingAs($this->admin());
        $r = $this->report('2026-09');
        $this->assertSame(80.0, $this->zl($this->campaignRow($r, $first)['month']['freed_capital']));
        $this->assertSame(40.0, $this->zl($this->campaignRow($r, $second)['month']['freed_capital']));
        // razem nie więcej niż koszt stanu z dnia wysyłki, pomniejszonego o sprzedaż spoza kampanii
        $this->assertSame(120.0, $this->zl($r['totals']['freed_capital']));
        $this->assertSame(800.0, $this->zl($r['totals']['sales_net']));
        $this->assertSame(1, $r['warnings']['over_stock_lines']);
        // sprzedaż przed startem drugiej kampanii nie zmniejsza jej stanu (migawka już ją uwzględnia)
        $this->assertSame(2.0, $this->zl($this->campaignRow($r, $second)['items'][0]['window']['over_stock_quantity']));
        $this->assertLessThanOrEqual(100.0, $this->zl($this->campaignRow($r, $first)['window']['effectiveness_percent']));
    }

    public function test_late_correction_of_old_campaign_counts_in_its_month(): void
    {
        $jan = $this->sender();
        $boots = $this->erpItem('B1');
        $alfa = $this->customer('ALFA', ['a@alfa.pl']);
        // okno 1.07–31.07: bez doczytania pozycji korygowanej ta kampania nie weszłaby do października
        $campaign = $this->started($jan, $boots, '2026-07-01 08:00', ['snap_stock' => 4]);
        $this->mail($campaign, $alfa, '2026-07-01 08:10');
        $this->line(1, '2026-07-20', $alfa, $boots, 8, 400, 160);
        $this->line(5, '2026-10-01', $alfa, $boots, -1, -50, -20, ['document_type' => 2041, 'document_number' => 'FSK-5', 'corrects_document_type' => 2033, 'corrects_document_id' => 1]);

        Sanctum::actingAs($jan);
        $r = $this->report('2026-10');
        $this->assertSame(-50.0, $this->zl($r['totals']['sales_net']));
        $this->assertSame(-10.0, $this->zl($r['totals']['freed_capital']));    // −20 × ½ (limit 4 z 8)
        $this->assertSame(0, $r['totals']['campaigns']);                      // okno nie nachodzi na październik
        $this->assertSame([$campaign->id], array_column($r['campaigns'], 'id'));
        $this->assertSame(0, $r['warnings']['unlinked_corrections']);
    }

    public function test_campaign_without_frozen_customers_falls_back_to_recipient_email_cards(): void
    {
        $jan = $this->sender();
        $boots = $this->erpItem('B1');
        $north = $this->customer('ALFA-PN', ['zakupy@alfa.pl']);
        $south = $this->customer('ALFA-PD', ['zakupy@alfa.pl']);
        $campaign = $this->started($jan, $boots, '2026-09-01 08:00');
        CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id, 'email' => 'Zakupy@Alfa.pl', 'erp_customer_id' => null, 'source' => 'list',
            'token' => str_repeat('z', 40), 'status' => 'sent', 'sent_at' => '2026-09-01 08:10',
        ]);
        $this->line(1, '2026-09-03', $north, $boots, 1, 100, 20);
        $this->line(2, '2026-09-04', $south, $boots, 1, 100, 20);

        Sanctum::actingAs($jan);
        $r = $this->report('2026-09');
        $this->assertSame(200.0, $this->zl($r['totals']['sales_net']));
        $this->assertSame(2, $r['totals']['buyers']);
        $this->assertSame(2, $r['warnings']['multi_card_lines']);
        $csv = $this->get('/api/reports/campaigns/csv?month=2026-09')->assertOk()->streamedContent();
        $this->assertStringContainsString('adres e-mail na 2 kartach', $csv);
    }

    public function test_stagnation_from_snapshot_and_missing_snapshot(): void
    {
        $jan = $this->sender();
        $fresh = $this->erpItem('F1');
        $neverSold = $this->erpItem('N1');
        $old = $this->erpItem('O1');
        $alfa = $this->customer('ALFA', ['a@alfa.pl']);
        $campaign = $this->campaign($jan, [$fresh, $neverSold, $old], ['status' => 'sent', 'sending_started_at' => '2026-09-01 08:00']);
        CampaignItem::query()->where('erp_item_id', $fresh->id)->update(['snap_stock' => 10, 'snap_unit_cost' => 10, 'snap_last_sale_at' => '2026-08-01', 'snap_source' => 'send']);
        CampaignItem::query()->where('erp_item_id', $neverSold->id)->update(['snap_stock' => 10, 'snap_unit_cost' => 10, 'snap_oldest_lot_at' => '2026-02-13', 'snap_source' => 'send']);
        CampaignItem::query()->where('erp_item_id', $old->id)->update(['snap_stock' => 10, 'snap_unit_cost' => 10]); // kampania sprzed raportu
        $this->mail($campaign, $alfa, '2026-09-01 08:10', CampaignRecipientCustomer::MATCHED_EMAIL, 2);
        $this->line(1, '2026-09-02', $alfa, $fresh, 1, 15, 10);
        $this->line(2, '2026-09-02', $alfa, $neverSold, 1, 15, 10, ['line' => 2]);
        $this->line(3, '2026-09-02', $alfa, $old, 1, 15, 10, ['line' => 3]);

        Sanctum::actingAs($jan);
        $r = $this->report('2026-09');
        $items = $this->campaignRow($r, $campaign)['items'];
        $this->assertSame([false, 31, false, null], [$items[0]['stagnant'], $items[0]['idle_days'], $items[0]['never_sold'], $items[0]['lot_age_days']]);
        $this->assertSame([true, null, true, 200], [$items[1]['stagnant'], $items[1]['idle_days'], $items[1]['never_sold'], $items[1]['lot_age_days']]);
        $this->assertSame([null, null, null], [$items[2]['stagnant'], $items[2]['idle_days'], $items[2]['snap_source']]);
        $this->assertSame([0.0, 10.0, 0.0], array_map(static fn (array $i): float => $i['month']['freed_capital'], $items));
        $this->assertSame(10.0, $this->zl($r['totals']['freed_capital']));
        $this->assertNull($r['totals']['avg_idle_days']);       // jedyny uwolniony nigdy nie był sprzedany
        $this->assertSame(1, $r['warnings']['no_snapshot_items']);
        $this->assertSame(3, $r['warnings']['multi_card_lines']);
    }

    public function test_access_scope_filter_and_month_window(): void
    {
        $jan = $this->sender();
        $ewa = User::factory()->withRole('handlowiec')->create(['name' => 'Ewa Handlowiec']);
        $boots = $this->erpItem('B1');
        $alfa = $this->customer('ALFA', ['a@alfa.pl']);
        $mine = $this->started($jan, $boots, '2026-10-01 08:00');
        $hers = $this->started($ewa, $boots, '2026-10-01 08:00');
        $this->mail($mine, $alfa, '2026-10-01 08:10');
        $this->mail($hers, $alfa, '2026-10-01 07:10');
        $this->line(1, '2026-10-02', $alfa, $boots, 1, 100, 20);

        Sanctum::actingAs($jan);
        $r = $this->report('2026-10');
        $this->assertSame([$mine->id], array_column($r['campaigns'], 'id'));
        $this->assertSame([['user_id' => $jan->id, 'name' => 'Jan Handlowiec']], $r['people_options']);
        // ten sam dzień maila — wygrywa wyższy numer (kampania Ewy), Jan widzi tylko przejęcie
        $this->assertSame(0.0, $this->zl($r['totals']['sales_net']));
        $this->assertSame(1, $r['campaigns'][0]['taken_over']['lines']);
        $this->assertSame('2026-11-07', $r['final_after']);
        $this->assertFalse($r['closed']);
        $this->assertSame(['2026-10', '2025-11'], [$r['months'][0]['key'], $r['months'][11]['key']]);
        $this->assertCount(12, $r['months']);

        $this->getJson('/api/reports/campaigns?month=2026-10&user_id='.$ewa->id)->assertForbidden();
        $this->get('/api/reports/campaigns/csv?month=2026-10&user_id='.$ewa->id)->assertForbidden();
        $this->getJson('/api/reports/campaigns?month=2025-10')->assertStatus(422);
        $this->getJson('/api/reports/campaigns?month=2026-11')->assertStatus(422);
        $this->getJson('/api/reports/campaigns?month=10-2026')->assertStatus(422);

        Sanctum::actingAs($this->admin());
        $r = $this->report('2026-10', $ewa->id);
        $this->assertSame('all', $r['scope']);
        $this->assertSame($ewa->id, $r['user_id']);
        $this->assertSame([$hers->id], array_column($r['campaigns'], 'id'));
        $this->assertSame(100.0, $this->zl($r['totals']['sales_net']));
        $this->assertSame(['Ewa Handlowiec', 'Jan Handlowiec'], array_column($r['people_options'], 'name'));

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/reports/campaigns')->assertForbidden();
        $this->get('/api/reports/campaigns/csv')->assertForbidden();
    }
}
