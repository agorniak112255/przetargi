<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\CampaignRecipient;
use App\Models\CampaignRecipientCustomer;
use App\Models\ErpSaleLine;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\RecipientCustomerFreezer;
use App\Services\Erp\ErpCampaignSalesSync;
use App\Services\Erp\ErpXlGateway;
use App\Support\ClarionDate;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CampaignFixtures;
use Tests\Support\FakeErpXlGateway;
use Tests\TestCase;

/**
 * Dane źródłowe raportu „Wynik kampanii”: koszt i korekty z XL, migawka pozycji przy starcie (koszt, ostatnia
 * sprzedaż, wiek partii) i zamrożenie klientów XL odbiorców (start, dopisanie, migracja uzupełniająca).
 */
final class CampaignReportSourceDataTest extends TestCase
{
    use CampaignFixtures;
    use RefreshDatabase;

    private FakeErpXlGateway $xl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00'));
        $this->setUpCampaigns();
        $this->xl = new FakeErpXlGateway;
        $this->app->instance(ErpXlGateway::class, $this->xl);
    }

    private function d(string $date): int
    {
        return ClarionDate::fromDate(CarbonImmutable::parse($date));
    }

    public function test_sync_saves_cost_and_corrections_with_corrected_document(): void
    {
        $author = $this->sender();
        $boots = $this->erpItem('B20417', 100, ['xl_gid' => 501]);
        $this->customer('ALFA', ['zakupy@alfa.pl'], [], ['xl_gid' => 9001]);
        $this->campaign($author, [$boots], ['status' => 'sent', 'sending_started_at' => '2026-09-20 09:00', 'sent_at' => '2026-09-20 12:00']);

        $this->xl->saleLineRows = [
            FakeErpXlGateway::saleLine(10, $this->d('2026-09-21'), 9001, 501, 10, 890, cost: 512.344),
            // FS do WZ — XL nie podał kosztu
            FakeErpXlGateway::saleLine(11, $this->d('2026-09-22'), 9001, 501, 2, 178),
            // zwrot 3 szt. z FS 10 i korekta ceny (ilość 0) z PA 7
            FakeErpXlGateway::correctionLine(20, $this->d('2026-09-25'), 9001, 501, -3, -267, 10, cost: -153.7),
            FakeErpXlGateway::correctionLine(21, $this->d('2026-09-26'), 9001, 501, 0, -15.5, 7, type: 2042),
        ];
        $this->assertSame(4, app(ErpCampaignSalesSync::class)->run()['lines']);

        $line = static fn (int $type, int $id): ErpSaleLine => ErpSaleLine::query()->where('document_type', $type)->where('document_id', $id)->sole();
        $fs = $line(2033, 10);
        $this->assertSame(['512.34', null, null], [$fs->cost_value, $fs->corrects_document_type, $fs->corrects_document_id]);
        $this->assertNull($line(2033, 11)->cost_value);
        $fsk = $line(2041, 20);
        $this->assertSame(['FSK-01H/20/26/09', '-3.000', '-267.00', '-153.70', 2033, 10], [
            $fsk->document_number, $fsk->quantity, $fsk->net_value, $fsk->cost_value, $fsk->corrects_document_type, $fsk->corrects_document_id,
        ]);
        $pak = $line(2042, 21);
        $this->assertSame(['0.000', '-15.50', null, 2034, 7], [$pak->quantity, $pak->net_value, $pak->cost_value, $pak->corrects_document_type, $pak->corrects_document_id]);

        // ponowny odczyt aktualizuje koszt i dokument korygowany; korekta anulowana w XL znika jak faktura
        $this->travel(1)->days();
        $this->xl->saleLineRows = [
            FakeErpXlGateway::saleLine(10, $this->d('2026-09-21'), 9001, 501, 10, 890, cost: 600),
            FakeErpXlGateway::saleLine(11, $this->d('2026-09-22'), 9001, 501, 2, 178, cost: 100),
            FakeErpXlGateway::correctionLine(20, $this->d('2026-09-25'), 9001, 501, -3, -267, 11, cost: -150),
        ];
        $this->assertSame(1, app(ErpCampaignSalesSync::class)->run()['removed']);
        $this->assertSame(['600.00', '100.00'], [$line(2033, 10)->cost_value, $line(2033, 11)->cost_value]);
        $this->assertSame(['-150.00', 11], [$line(2041, 20)->cost_value, $line(2041, 20)->corrects_document_id]);
        $this->assertFalse(ErpSaleLine::query()->where('document_type', 2042)->exists());
    }

    public function test_start_snapshots_cost_and_idle_dates_and_freezes_recipient_customers(): void
    {
        $author = $this->sender();
        $boots = $this->erpItem('B20417', 100, [
            'stock_value' => 512.5, 'last_sale_at' => '2026-03-01', 'oldest_lot_trade_at' => '2025-01-10', 'oldest_lot_at' => '2024-05-05',
        ]);
        // nigdy niesprzedany, bez partii na magazynach handlowych — wiek z wszystkich magazynów
        $gloves = $this->erpItem('R100', 10, ['stock_value' => 40, 'last_sale_at' => null, 'oldest_lot_trade_at' => null, 'oldest_lot_at' => '2024-02-02']);
        $card = $this->card('KARTA-1', 'Kask bez towaru XL');
        $beta = $this->customer('BETA', ['jan@beta.pl'], [$boots->id => '2026-08-01']);
        $alfa = $this->customer('ALFA', ['zakupy@alfa.pl']);
        $alfaOddzial = $this->customer('ALFA2', ['ZAKUPY@alfa.pl', 'inny@alfa.pl']);
        $this->customer('USUN', ['zakupy@alfa.pl'], [], ['removed_at' => now()]);
        $list = $this->mailingList($author, ['zakupy@alfa.pl', 'nikt@spoza-xl.pl']);
        $campaign = $this->campaign($author, [$boots, $gloves, $card], ['audience' => [
            'list_ids' => [$list->id],
            'xl' => ['mode' => 'items', 'months' => 24, 'only_mine' => false],
        ]]);

        app(CampaignSender::class)->start($campaign, $author);

        $items = CampaignItem::query()->where('campaign_id', $campaign->id)->orderBy('position')->get();
        $snap = static fn (CampaignItem $i): array => [$i->snap_unit_cost, $i->snap_last_sale_at?->toDateString(), $i->snap_oldest_lot_at?->toDateString(), $i->snap_source];
        $this->assertSame(['5.1250', '2026-03-01', '2025-01-10', 'send'], $snap($items[0]));
        $this->assertSame(['4.0000', null, '2024-02-02', 'send'], $snap($items[1]));
        $this->assertSame([null, null, null, null], $snap($items[2]));

        $frozen = CampaignRecipientCustomer::query()->from('campaign_recipient_customers as crc')->where('crc.campaign_id', $campaign->id)
            ->join('campaign_recipients as r', 'r.id', '=', 'crc.campaign_recipient_id')
            ->orderBy('r.email')->orderBy('crc.erp_customer_id')
            ->get(['r.email', 'crc.erp_customer_id', 'crc.matched_by', 'crc.cards_count'])
            ->map(static fn ($row): array => [$row->email, (int) $row->erp_customer_id, $row->matched_by, $row->cards_count])->all();
        $this->assertSame([
            ['jan@beta.pl', $beta->id, 'direct', 1],
            ['zakupy@alfa.pl', $alfa->id, 'email', 2],
            ['zakupy@alfa.pl', $alfaOddzial->id, 'email', 2],
        ], $frozen);
    }

    public function test_freezer_is_idempotent_and_keeps_rows_frozen_after_card_changes(): void
    {
        $author = $this->sender();
        $alfa = $this->customer('ALFA', ['zakupy@alfa.pl']);
        $gamma = $this->customer('GAMMA', ['biuro@gamma.pl']);
        $campaign = $this->campaign($author, [$this->erpItem('B1')], ['status' => 'sent', 'sending_started_at' => '2026-09-20 09:00']);
        $recipient = fn (string $email, ?int $customerId): CampaignRecipient => CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id, 'email' => $email, 'erp_customer_id' => $customerId, 'source' => $customerId !== null ? 'xl' : 'list',
            'token' => str_pad($email, 40, 'x'), 'status' => 'sent', 'sent_at' => '2026-09-20 10:00',
        ]);
        $recipient('biuro@gamma.pl', $gamma->id);
        $recipient('Zakupy@Alfa.pl', null);
        $recipient('nikt@spoza-xl.pl', null);

        $freezer = app(RecipientCustomerFreezer::class);
        $this->assertSame(2, $freezer->freeze($campaign));
        $this->assertSame(0, $freezer->freeze($campaign));

        // adres dopisany później do innej karty nie zmienia zamrożonego odbiorcy
        $this->customer('ALFA2', ['zakupy@alfa.pl']);
        $this->assertSame(0, $freezer->freeze($campaign));
        $this->assertSame(
            [[$gamma->id, 'direct', 1], [$alfa->id, 'email', 1]],
            CampaignRecipientCustomer::query()->where('campaign_id', $campaign->id)->orderBy('id')->get()
                ->map(static fn (CampaignRecipientCustomer $r): array => [(int) $r->erp_customer_id, $r->matched_by, $r->cards_count])->all(),
        );
    }

    public function test_added_recipients_are_frozen_too(): void
    {
        $author = $this->sender();
        $alfa = $this->customer('ALFA', ['a@klient.pl']);
        $beta = $this->customer('BETA', ['c@klient.pl']);
        $list = $this->mailingList($author, ['a@klient.pl']);
        $campaign = app(CampaignSender::class)->start(
            $this->campaign($author, [$this->erpItem('B1', 50)], ['audience' => ['list_ids' => [$list->id]]]),
            $author,
        );
        $this->assertSame([$alfa->id], CampaignRecipientCustomer::query()->where('campaign_id', $campaign->id)->pluck('erp_customer_id')->all());

        $more = $this->mailingList($author, ['c@klient.pl'], false, 'Nowi');
        $campaign->update(['audience' => ['list_ids' => [$list->id, $more->id]]]);
        [, $added] = app(CampaignSender::class)->addRecipients($campaign->fresh(), $author, sha1('c@klient.pl'));

        $this->assertSame(1, $added);
        $added = CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('email', 'c@klient.pl')->sole();
        $row = CampaignRecipientCustomer::query()->where('campaign_recipient_id', $added->id)->sole();
        $this->assertSame([$beta->id, 'email', 1], [(int) $row->erp_customer_id, $row->matched_by, $row->cards_count]);
        $this->assertSame(2, CampaignRecipientCustomer::query()->where('campaign_id', $campaign->id)->count());
    }

    public function test_backfill_migration_freezes_started_campaigns_only(): void
    {
        $author = $this->sender();
        $alfa = $this->customer('ALFA', ['zakupy@alfa.pl']);
        $alfa2 = $this->customer('ALFA2', ['zakupy@alfa.pl']);
        $gamma = $this->customer('GAMMA', ['biuro@gamma.pl']);
        $started = $this->campaign($author, [$this->erpItem('B1')], ['status' => 'sent', 'sending_started_at' => '2026-09-20 09:00']);
        $draft = $this->campaign($author, [$this->erpItem('B2')]);
        $recipient = static fn (Campaign $c, string $email, ?int $customerId): CampaignRecipient => CampaignRecipient::query()->create([
            'campaign_id' => $c->id, 'email' => $email, 'erp_customer_id' => $customerId, 'source' => $customerId !== null ? 'xl' : 'list',
            'token' => str_pad($c->id.$email, 40, 'x'), 'status' => $c->id === $started->id ? 'sent' : 'pending',
        ]);
        $recipient($started, 'biuro@gamma.pl', $gamma->id);
        $recipient($started, 'zakupy@alfa.pl', null);
        $recipient($draft, 'zakupy@alfa.pl', null);

        $migration = require database_path('migrations/2026_10_04_200250_backfill_campaign_recipient_customers.php');
        $migration->up();
        $migration->up();

        $this->assertSame(
            [[$started->id, $gamma->id, 'direct', 1], [$started->id, $alfa->id, 'email', 2], [$started->id, $alfa2->id, 'email', 2]],
            DB::table('campaign_recipient_customers')->orderBy('campaign_recipient_id')->orderBy('erp_customer_id')->get()
                ->map(static fn ($r): array => [(int) $r->campaign_id, (int) $r->erp_customer_id, $r->matched_by, (int) $r->cards_count])->all(),
        );

        $migration->down();
        $this->assertSame(0, DB::table('campaign_recipient_customers')->count());
    }
}
