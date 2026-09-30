<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CampaignItem;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CampaignFixtures;
use Tests\TestCase;

/** Wynik kampanii: stan magazynów handlowych pozycji 7 i 30 dni po wysyłce. */
final class CampaignStockFollowupTest extends TestCase
{
    use CampaignFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(4, 40));
        $this->setUpCampaigns();
    }

    public function test_fills_7_and_30_day_stock_once_and_only_on_time(): void
    {
        $author = $this->sender();
        // stan handlowy = całość − usługowe
        $item = $this->erpItem('B20417', 120, ['stock_service' => 20]);
        $week = $this->campaign($author, [$item], ['status' => 'sent', 'sent_at' => now()->subDays(8)]);
        $month = $this->campaign($author, [$item], ['status' => 'sent', 'sent_at' => now()->subDays(31)]);
        $fresh = $this->campaign($author, [$item], ['status' => 'sent', 'sent_at' => now()->subDays(3)]);
        $draft = $this->campaign($author, [$item]);
        $cancelled = $this->campaign($author, [$item], ['status' => 'cancelled', 'sent_at' => now()->subDays(10)]);
        // przerwa harmonogramu: 60 dni po wysyłce nie udajemy stanu „po 30 dniach”
        $late = $this->campaign($author, [$item], ['status' => 'sent', 'sent_at' => now()->subDays(60)]);
        $done = $this->campaign($author, [$item], ['status' => 'sent', 'sent_at' => now()->subDays(9)]);
        CampaignItem::query()->where('campaign_id', $done->id)->update(['stock_after_7d' => 55]);

        $this->artisan('campaigns:stock-followup')->assertSuccessful();

        $this->assertStock($week->id, 100, null);
        // 31 dni: po 30 dniach tak; „po 7 dniach” spóźnione o 24 dni — zostaje puste
        $this->assertStock($month->id, null, 100);
        $this->assertStock($fresh->id, null, null);
        $this->assertStock($draft->id, null, null);
        $this->assertStock($cancelled->id, null, null);
        $this->assertStock($late->id, null, null);
        // raz zapisany stan się nie zmienia
        $this->assertStock($done->id, 55, null);

        $item->update(['stock_total' => 50]);
        $this->artisan('campaigns:stock-followup')->assertSuccessful();
        $this->assertStock($week->id, 100, null);
    }

    public function test_waits_for_xl_reading_taken_after_the_deadline(): void
    {
        $author = $this->sender();
        // nocna synchronizacja XL stoi od 2 dni: stan sprzed terminu nie może udawać „po 7 / 30 dniach”
        $item = $this->erpItem('B20417', 80, ['stock_synced_at' => '2026-09-28 04:39:00']);
        $week = $this->campaign($author, [$item], ['status' => 'sent', 'sent_at' => now()->subDays(8)]);
        $month = $this->campaign($author, [$item], ['status' => 'sent', 'sent_at' => now()->subDays(31)]);
        // karta bez towaru XL obok towaru z XL — nie blokuje zapisu pozycji z XL
        $card = $this->card('KARTA-1', 'Karta bez XL');
        $mixed = $this->campaign($author, [$item, $card], ['status' => 'sent', 'sent_at' => now()->subDays(8)]);

        $this->artisan('campaigns:stock-followup')->assertSuccessful();
        $this->assertStock($week->id, null, null);
        $this->assertStock($month->id, null, null);

        // odczyt dokładnie z wysyłki + N dni − 1 dzień już się liczy
        $item->update(['stock_synced_at' => '2026-09-28 04:40:00']);
        $this->artisan('campaigns:stock-followup')->assertSuccessful();
        $this->assertStock($week->id, 80, null);
        $this->assertStock($month->id, null, 80);
        $this->assertEquals(80, CampaignItem::query()->where('campaign_id', $mixed->id)->whereNotNull('erp_item_id')->value('stock_after_7d'));
        $this->assertNull(CampaignItem::query()->where('campaign_id', $mixed->id)->whereNull('erp_item_id')->value('stock_after_7d'));
    }

    private function assertStock(int $campaignId, ?float $after7, ?float $after30): void
    {
        $row = CampaignItem::query()->where('campaign_id', $campaignId)->firstOrFail();
        $this->assertEquals($after7, $row->stock_after_7d, 'po 7 dniach, kampania '.$campaignId);
        $this->assertEquals($after30, $row->stock_after_30d, 'po 30 dniach, kampania '.$campaignId);
    }
}
