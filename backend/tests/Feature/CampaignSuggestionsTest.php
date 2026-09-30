<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\ErpItemLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CampaignFixtures;
use Tests\TestCase;

/** „Zaproponuj pozycje”: ranking zalegającego towaru z powodami, bez pozycji z innych aktywnych kampanii. */
final class CampaignSuggestionsTest extends TestCase
{
    use CampaignFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(10, 0));
        $this->setUpCampaigns();
    }

    public function test_ranking_with_reasons_skips_fresh_busy_and_empty_stock(): void
    {
        $author = $this->sender(operator: 'JK');
        // zalega długo, drogi, z kartą ze zdjęciem i opisem, kupowało 3 klientów z e-mailem
        $boots = $this->erpItem('B100', 420, ['stock_value' => 31000, 'last_sale_at' => '2025-07-15']);
        $card = $this->card('TIGER', 'Półbuty Tiger S3');
        $card->update(['description' => 'Lekkie półbuty S3 ze skóry licowej.']);
        ErpItemLink::query()->create(['erp_item_id' => $boots->id, 'product_id' => $card->id, 'status' => ErpItemLink::STATUS_CONFIRMED, 'method' => 'name']);
        // tani, krócej zalega, bez karty, nikt nie kupował
        $gloves = $this->erpItem('R200', 50, ['stock_value' => 300, 'last_sale_at' => '2026-02-01']);
        // nigdy niesprzedany, stara partia
        $never = $this->erpItem('N300', 10, ['stock_value' => 900, 'last_sale_at' => null, 'oldest_lot_at' => '2024-03-10', 'oldest_lot_trade_at' => '2024-03-10']);
        // nie zalega (sprzedany w tym miesiącu), brak stanu, towar innej aktywnej kampanii, towar archiwalny
        $fresh = $this->erpItem('F400', 100, ['stock_value' => 99000, 'last_sale_at' => '2026-09-20']);
        $empty = $this->erpItem('E500', 0, ['stock_value' => 0, 'last_sale_at' => '2024-01-01']);
        $busy = $this->erpItem('X600', 100, ['stock_value' => 50000, 'last_sale_at' => '2024-01-01']);
        $archived = $this->erpItem('A700', 100, ['stock_value' => 50000, 'last_sale_at' => '2024-01-01', 'archived' => true]);
        $this->campaign($this->sender(), [$busy], ['status' => Campaign::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()]);
        foreach (['alfa', 'beta', 'gamma'] as $i => $name) {
            $this->customer(strtoupper($name), [$name.'@klient.pl'], [$boots->id => '2025-06-01'], ['main_operator' => $i === 0 ? 'JK' : 'ZZ']);
        }
        // klient bez e-maila się nie liczy
        $this->customer('BEZMAILA', [], [$boots->id => '2025-06-01'], ['emails' => null]);
        $campaign = $this->campaign($author);

        Sanctum::actingAs($author);
        $res = $this->getJson("/api/campaigns/{$campaign->id}/suggestions")->assertOk()
            ->assertJsonPath('free', 12)
            ->assertJsonPath('mine_available', true)
            ->assertJsonPath('min_months', 6);

        $this->assertSame(['B100', 'N300', 'R200'], array_column($res->json('data'), 'code'));
        $top = $res->json('data.0');
        $this->assertSame(3, $top['buyers']);
        $this->assertSame('TIGER', $top['card']['sku']);
        $this->assertTrue($top['has_description']);
        $this->assertSame([
            'nie sprzedaje się od 14 mies. (ostatnio 15.07.2025)',
            "zapas 31\u{00A0}000\u{00A0}zł (420 szt)",
            'kupowało 3 klientów z e-mailem (24 mies.)',
        ], $top['reasons']);
        $this->assertGreaterThan($res->json('data.1.score'), $top['score']);
        $this->assertSame('nigdy nie sprzedany (partia z 03.2024)', $res->json('data.1.reasons.0'));
        $this->assertContains('bez karty — w mailu bez zdjęcia i opisu', $res->json('data.2.reasons'));
        $this->assertContains('nikt z klientów z e-mailem nie kupował w 24 mies.', $res->json('data.2.reasons'));

        // tylko moi klienci: towar kupowany przez klientów opiekuna JK
        $mine = $this->getJson("/api/campaigns/{$campaign->id}/suggestions?mine=1")->assertOk();
        $this->assertSame(['B100'], array_column($mine->json('data'), 'code'));
        $this->assertSame('kupowało 1 Twoich klientów z e-mailem (24 mies.)', $mine->json('data.0.reasons.2'));

        // pozycja już w tej kampanii nie wraca; wolne miejsca maleją
        CampaignItem::query()->create(['campaign_id' => $campaign->id, 'position' => 1, 'erp_item_id' => $boots->id]);
        $res = $this->getJson("/api/campaigns/{$campaign->id}/suggestions")->assertOk()->assertJsonPath('free', 11);
        $this->assertNotContains('B100', array_column($res->json('data'), 'code'));
        $this->assertNotContains($fresh->code, array_column($res->json('data'), 'code'));
        $this->assertNotContains($empty->code, array_column($res->json('data'), 'code'));
        $this->assertNotContains($archived->code, array_column($res->json('data'), 'code'));
    }

    public function test_only_draft_of_visible_campaign_and_mine_needs_operator(): void
    {
        $author = $this->sender();
        $campaign = $this->campaign($author);

        Sanctum::actingAs($this->sender());
        $this->getJson("/api/campaigns/{$campaign->id}/suggestions")->assertNotFound();

        Sanctum::actingAs($author);
        // autor bez operatora XL — „tylko moi klienci” niedostępne
        $this->getJson("/api/campaigns/{$campaign->id}/suggestions?mine=1")->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('mine_available', false);

        $sent = $this->campaign($author, [], ['status' => Campaign::STATUS_SENT, 'sent_at' => now()]);
        $this->getJson("/api/campaigns/{$sent->id}/suggestions")->assertUnprocessable();
    }

    public function test_item_in_scheduled_campaign_is_flagged_as_in_other_campaign(): void
    {
        $author = $this->sender();
        $item = $this->erpItem('B100');
        $this->campaign($this->sender(), [$item], ['status' => Campaign::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay(), 'name' => 'Zaplanowana']);
        $campaign = $this->campaign($author, [$item]);

        Sanctum::actingAs($author);
        $this->getJson("/api/campaigns/{$campaign->id}")->assertOk()
            ->assertJsonPath('items.0.warnings.other_campaigns.0.name', 'Zaplanowana');
    }
}
