<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CampaignRecipient;
use App\Models\ErpSaleLine;
use App\Services\Erp\ErpCampaignSalesSync;
use App\Services\Erp\ErpXlGateway;
use App\Support\ClarionDate;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CampaignFixtures;
use Tests\Support\FakeErpXlGateway;
use Tests\TestCase;

/** „Kupili odbiorcy kampanii”: sprzedaż towarów kampanii z XL po wysyłce — odbiorcy osobno, pozostali dla porównania. */
final class CampaignSalesTest extends TestCase
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

    public function test_sync_reads_campaign_items_from_send_day_maps_customers_and_drops_cancelled_documents(): void
    {
        $author = $this->sender();
        $boots = $this->erpItem('B20417', 100, ['xl_gid' => 501]);
        $jacket = $this->erpItem('A11873', 50, ['xl_gid' => 502]);
        $this->erpItem('S99999', 5, ['xl_gid' => 503]);
        $alfa = $this->customer('ALFA', ['zakupy@alfa.pl'], [], ['xl_gid' => 9001]);
        $this->campaign($author, [$boots, $jacket], ['status' => 'sent', 'sending_started_at' => '2026-09-20 09:00', 'sent_at' => '2026-09-20 12:00']);
        // kampania sprzed ponad 37 dni i projekt — nie wchodzą do odczytu
        $this->campaign($author, [$this->erpItem('T1', 1, ['xl_gid' => 504])], ['status' => 'sent', 'sending_started_at' => '2026-08-01 09:00']);
        $this->campaign($author, [$this->erpItem('T2', 1, ['xl_gid' => 505])]);

        $this->xl->saleLineRows = [
            FakeErpXlGateway::saleLine(1, $this->d('2026-09-19'), 9001, 501, 5, 400), // przed dniem wysyłki
            FakeErpXlGateway::saleLine(2, $this->d('2026-09-20'), 9001, 501, 10, 890),
            FakeErpXlGateway::saleLine(3, $this->d('2026-09-25'), 7777, 502, 2, 158),   // klient spoza kopii erp_customers
            FakeErpXlGateway::saleLine(4, $this->d('2026-09-25'), 9001, 503, 1, 10),   // towar spoza kampanii
        ];
        $this->assertSame(['campaigns' => 1, 'items' => 2, 'lines' => 2, 'removed' => 0], app(ErpCampaignSalesSync::class)->run());
        $this->assertCount(1, $this->xl->saleLineCalls);
        $call = $this->xl->saleLineCalls[0];
        sort($call['items']);
        $this->assertSame([[501, 502], $this->d('2026-09-20')], [$call['items'], $call['from']]);
        $line = ErpSaleLine::query()->where('document_id', 2)->sole();
        $this->assertSame([$alfa->id, $boots->id, '2026-09-20', 'FS-01H/2/26/09'], [$line->erp_customer_id, $line->erp_item_id, $line->sold_at->toDateString(), $line->document_number]);
        $this->assertNull(ErpSaleLine::query()->where('document_id', 3)->sole()->erp_customer_id);

        // dokument 3 anulowany w XL — przy kolejnym odczycie znika
        $this->travel(1)->days();
        $this->xl->saleLineRows = [FakeErpXlGateway::saleLine(2, $this->d('2026-09-20'), 9001, 501, 10, 890)];
        $this->assertSame(1, app(ErpCampaignSalesSync::class)->run()['removed']);
        $this->assertSame([2], ErpSaleLine::query()->pluck('document_id')->all());
    }

    public function test_result_splits_recipients_from_other_buyers_within_thirty_days(): void
    {
        $author = $this->sender();
        $boots = $this->erpItem('B20417', 100, ['xl_gid' => 501, 'unit' => 'par']);
        $jacket = $this->erpItem('A11873', 50, ['xl_gid' => 502]);
        $alfa = $this->customer('ALFA', ['zakupy@alfa.pl'], [], ['xl_gid' => 9001]);
        $beta = $this->customer('BETA', ['jan@beta.pl'], [], ['xl_gid' => 9002]);
        $gamma = $this->customer('GAMMA', ['biuro@gamma.pl'], [], ['xl_gid' => 9003]);
        $obcy = $this->customer('OBCY', ['obcy@delta.pl'], [], ['xl_gid' => 9004]);
        $campaign = $this->campaign($author, [$boots, $jacket], ['status' => 'sent', 'sending_started_at' => '2026-09-01 09:00', 'sent_at' => '2026-09-01 11:00']);
        $recipient = static fn (string $email, ?int $customerId, string $status = 'sent', string $source = 'xl') => CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id, 'email' => $email, 'erp_customer_id' => $customerId, 'source' => $source,
            'token' => str_pad($email, 40, 'x'), 'status' => $status, 'sent_at' => $status === 'sent' ? '2026-09-01 10:00' : null,
        ]);
        $recipient('zakupy@alfa.pl', $alfa->id);
        // odbiorca z grupy — dopasowany do kontrahenta po adresie z karty XL
        $recipient('jan@beta.pl', null, source: 'list');
        // mail do GAMMY nie wyszedł — jej zakup liczy się jako „pozostali”
        $recipient('biuro@gamma.pl', $gamma->id, 'failed');
        $recipient('ktos@spoza-xl.pl', null, source: 'list');

        $this->xl->saleLineRows = [
            FakeErpXlGateway::saleLine(10, $this->d('2026-09-05'), 9001, 501, 20, 1780),
            FakeErpXlGateway::saleLine(10, $this->d('2026-09-05'), 9001, 502, 3, 237, line: 2),
            FakeErpXlGateway::saleLine(11, $this->d('2026-09-28'), 9002, 501, 4, 356),
            FakeErpXlGateway::saleLine(12, $this->d('2026-09-10'), 9003, 501, 6, 534),
            FakeErpXlGateway::saleLine(13, $this->d('2026-09-12'), 9004, 502, 1, 79),
        ];
        app(ErpCampaignSalesSync::class)->run();
        // zakup po oknie 30 dni nie liczy się (okno kończy się 1.10) — dopisany ręcznie, jakby był
        ErpSaleLine::query()->create([
            'document_type' => 2033, 'document_id' => 99, 'line' => 1, 'document_number' => 'FS-99', 'sold_at' => '2026-10-02',
            'customer_xl_gid' => 9001, 'erp_customer_id' => $alfa->id, 'erp_item_id' => $boots->id, 'quantity' => 100, 'net_value' => 9999, 'synced_at' => now(),
        ]);

        Sanctum::actingAs($author);
        $sales = $this->getJson("/api/campaigns/{$campaign->id}")->assertOk()->json('sales');
        $this->assertSame(['2026-09-01', '2026-10-01', 30, false], [$sales['from'], $sales['to'], $sales['days'], $sales['complete']]);
        $this->assertSame([3, 2], [$sales['recipients_sent'], $sales['recipients_in_xl']]);
        $this->assertEquals(['customers' => 2, 'net_value' => 2373.0], $sales['recipients']);
        $this->assertEquals(['customers' => 2, 'net_value' => 613.0], $sales['others']);
        $this->assertEquals(
            [['B20417', 'par', 24.0, 6.0, 2136.0, 534.0], ['A11873', 'szt', 3.0, 1.0, 237.0, 79.0]],
            array_map(static fn (array $i): array => [$i['code'], $i['unit'], $i['quantity_recipients'], $i['quantity_others'], $i['value_recipients'], $i['value_others']], $sales['items']),
        );
        $this->assertSame(['ALFA', 'ALFA', 'BETA'], array_column($sales['buyers'], 'acronym'));
        $this->assertSame('jan@beta.pl', $sales['buyers'][2]['email']);

        $row = $this->getJson('/api/campaigns')->assertOk()->json('data.0');
        $this->assertEquals(['customers' => 2, 'net_value' => 2373.0, 'complete' => false], $row['sales']);
    }

    public function test_recipient_added_later_counts_only_purchases_from_his_mail_day(): void
    {
        $author = $this->sender();
        $boots = $this->erpItem('B20417', 100, ['xl_gid' => 501]);
        $alfa = $this->customer('ALFA', ['zakupy@alfa.pl'], [], ['xl_gid' => 9001]);
        $beta = $this->customer('BETA', ['jan@beta.pl'], [], ['xl_gid' => 9002]);
        $campaign = $this->campaign($author, [$boots], ['status' => 'sent', 'sending_started_at' => '2026-09-01 09:00', 'sent_at' => '2026-09-01 11:00']);
        CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id, 'email' => 'zakupy@alfa.pl', 'erp_customer_id' => $alfa->id, 'source' => 'xl',
            'token' => str_repeat('a', 40), 'status' => 'sent', 'sent_at' => '2026-09-01 10:00',
        ]);
        // BETA dopisana 15.09 — zakup z 10.09 był przed jej mailem
        CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id, 'email' => 'jan@beta.pl', 'erp_customer_id' => $beta->id, 'source' => 'xl',
            'token' => str_repeat('b', 40), 'status' => 'sent', 'sent_at' => '2026-09-15 08:00',
        ]);
        $this->xl->saleLineRows = [
            FakeErpXlGateway::saleLine(10, $this->d('2026-09-10'), 9002, 501, 2, 200),
            FakeErpXlGateway::saleLine(11, $this->d('2026-09-15'), 9002, 501, 3, 300),
            FakeErpXlGateway::saleLine(12, $this->d('2026-09-05'), 9001, 501, 1, 100),
        ];
        app(ErpCampaignSalesSync::class)->run();

        Sanctum::actingAs($author);
        $sales = $this->getJson("/api/campaigns/{$campaign->id}")->assertOk()->json('sales');
        $this->assertEquals(['customers' => 2, 'net_value' => 400.0], $sales['recipients']);
        $this->assertEquals(['customers' => 1, 'net_value' => 200.0], $sales['others']);
        $this->assertSame(['2026-09-05', '2026-09-15'], array_column($sales['buyers'], 'sold_at'));
        $this->assertEquals(['customers' => 2, 'net_value' => 400.0, 'complete' => false], $this->getJson('/api/campaigns')->json('data.0.sales'));
    }

    public function test_draft_has_no_sales_and_command_skips_without_xl(): void
    {
        $author = $this->sender();
        $campaign = $this->campaign($author, [$this->erpItem('B1')]);
        Sanctum::actingAs($author);
        $this->getJson("/api/campaigns/{$campaign->id}")->assertOk()->assertJsonPath('sales', null);

        $this->xl->isConfigured = false;
        $this->assertSame(0, Artisan::call('erp:campaign-sales'));
        $this->assertSame([], $this->xl->saleLineCalls);
    }
}
