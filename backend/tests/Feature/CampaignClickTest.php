<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignClick;
use App\Models\CampaignRecipient;
use App\Models\User;
use App\Services\Campaigns\CampaignRenderer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CampaignFixtures;
use Tests\TestCase;

/** Kliknięcia w linki kampanii: linki przez aplikację, przekierowanie do maila, strona produktu, skanery poczty, statystyki. */
final class CampaignClickTest extends TestCase
{
    use CampaignFixtures;
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(10, 0));
        $this->setUpCampaigns();
    }

    /** @return array{0: Campaign, 1: CampaignRecipient, 2: User} */
    private function sentCampaign(): array
    {
        $author = $this->sender();
        $item = $this->erpItem('B20417', 40, ['name' => 'PÓŁBUTY S3 <b>TIGER</b>']);
        $campaign = $this->campaign($author, [$item], ['status' => 'sent', 'sending_started_at' => now()->subHours(2), 'sent_at' => now()->subHours(2)]);
        $campaign->items()->first()->forceFill(['snap_name' => 'PÓŁBUTY S3 <b>TIGER</b>', 'snap_code' => 'B20417', 'snap_price' => 89, 'snap_stock' => 40, 'snap_unit' => 'par'])->save();
        $recipient = CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id, 'email' => 'klient@alfa.pl', 'source' => 'list', 'token' => str_repeat('a', 40),
            'status' => 'sent', 'sent_at' => now()->subHours(2),
        ]);

        return [$campaign->fresh(), $recipient, $author];
    }

    public function test_recipient_mail_links_go_through_app_and_preview_keeps_plain_mailto(): void
    {
        [$campaign, $recipient] = $this->sentCampaign();
        $itemId = $campaign->items()->first()->id;

        $mail = app(CampaignRenderer::class)->render($campaign, $recipient);
        // „Zapytaj o ofertę” wprost do programu pocztowego — bez przeglądarki i pytania o zgodę
        $this->assertStringNotContainsString('/o/'.$itemId, $mail['html']);
        $this->assertStringContainsString('mailto:jan@supon.example.pl?subject='.rawurlencode('Zapytanie '.$campaign->code.' B20417'), $mail['html']);
        $this->assertStringContainsString('https://przetargi.example.pl/api/k/'.$recipient->token.'/p/'.$itemId, $mail['html']);
        $this->assertStringContainsString('Zobacz produkt: https://przetargi.example.pl/api/k/'.$recipient->token.'/p/'.$itemId, $mail['text']);

        $preview = app(CampaignRenderer::class)->render($campaign);
        $this->assertStringNotContainsString('/api/k/', $preview['html']);
        $this->assertStringContainsString('mailto:jan@supon.example.pl?subject=', $preview['html']);
    }

    public function test_old_offer_link_shows_product_page_and_counts_only_people(): void
    {
        [$campaign, $recipient] = $this->sentCampaign();
        $itemId = $campaign->items()->first()->id;
        $url = "/api/k/{$recipient->token}/o/{$itemId}";

        // link zapytania ze starszych maili: strona produktu (bez przekierowania do mailto) z przyciskiem mailto
        $this->get($url, ['User-Agent' => self::BROWSER])->assertOk()
            ->assertSee('mailto:jan@supon.example.pl?subject='.rawurlencode('Zapytanie '.$campaign->code.' B20417'), false);
        // skaner poczty po nagłówku i zapytanie HEAD — zapisane, ale nie liczą się
        $this->get($url, ['User-Agent' => 'Barracuda Sentinel (EE)'])->assertOk();
        $this->call('HEAD', $url, [], [], [], ['HTTP_USER_AGENT' => self::BROWSER])->assertOk();

        $fresh = $recipient->fresh();
        $this->assertSame(1, $fresh->clicks);
        $this->assertSame('2026-09-30 10:00:00', $fresh->first_clicked_at?->toDateTimeString());
        $this->assertSame([false, true, true], CampaignClick::query()->orderBy('id')->pluck('suspected_bot')->all());
    }

    public function test_click_right_after_delivery_without_browser_language_is_a_mail_scanner(): void
    {
        [$campaign, $recipient] = $this->sentCampaign();
        $recipient->forceFill(['sent_at' => now()->subSeconds(20)])->save();

        // skaner: bez języka przeglądarki (Request::create w testach domyślnie go dodaje — czyścimy)
        $this->get("/api/k/{$recipient->token}/p/{$campaign->items()->first()->id}", ['User-Agent' => self::BROWSER, 'Accept-Language' => ''])->assertOk();
        $this->assertSame(0, $recipient->fresh()->clicks);
        $this->assertTrue(CampaignClick::query()->sole()->suspected_bot);
    }

    public function test_quick_click_from_a_real_browser_counts(): void
    {
        [$campaign, $recipient] = $this->sentCampaign();
        $recipient->forceFill(['sent_at' => now()->subSeconds(10)])->save();

        // przeglądarka człowieka wysyła język — klient, który kliknął od razu, jest zainteresowany
        $this->get("/api/k/{$recipient->token}/p/{$campaign->items()->first()->id}", [
            'User-Agent' => self::BROWSER,
            'Accept-Language' => 'pl,en-US;q=0.7,en;q=0.3',
        ])->assertOk();
        $this->assertSame(1, $recipient->fresh()->clicks);
        $this->assertFalse(CampaignClick::query()->sole()->suspected_bot);
    }

    public function test_product_page_shows_escaped_snapshot_and_tracked_ask_link(): void
    {
        [$campaign, $recipient] = $this->sentCampaign();
        $itemId = $campaign->items()->first()->id;

        $res = $this->get("/api/k/{$recipient->token}/p/{$itemId}", ['User-Agent' => self::BROWSER])->assertOk();
        $res->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $html = $res->getContent();
        $this->assertStringContainsString('PÓŁBUTY S3 &lt;b&gt;TIGER&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>TIGER</b>', $html);
        $this->assertStringContainsString("89,00\u{00A0}zł", $html);
        $this->assertStringContainsString('href="mailto:jan@supon.example.pl?subject='.rawurlencode('Zapytanie '.$campaign->code.' B20417').'"', $html);
        $this->assertStringNotContainsString('/o/'.$itemId, $html);
        $this->assertSame(CampaignClick::KIND_PRODUCT, CampaignClick::query()->sole()->kind);
    }

    public function test_wrong_token_or_item_of_other_campaign_is_generic_not_found(): void
    {
        [$campaign, $recipient, $author] = $this->sentCampaign();
        $other = $this->campaign($author, [$this->erpItem('A1')]);

        $this->get('/api/k/'.str_repeat('z', 40).'/p/'.$campaign->items()->first()->id)->assertNotFound()->assertSee('Nie znaleziono produktu');
        $this->get("/api/k/{$recipient->token}/o/{$other->items()->first()->id}")->assertNotFound();
        $this->assertSame(0, CampaignClick::query()->count());
    }

    public function test_api_shows_click_summary_clicked_recipients_and_list_count(): void
    {
        [$campaign, $recipient, $author] = $this->sentCampaign();
        $itemId = $campaign->items()->first()->id;
        CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id, 'email' => 'cichy@beta.pl', 'source' => 'list', 'token' => str_repeat('b', 40),
            'status' => 'sent', 'sent_at' => now()->subHours(2),
        ]);
        $this->get("/api/k/{$recipient->token}/p/{$itemId}", ['User-Agent' => self::BROWSER]);
        $this->get("/api/k/{$recipient->token}/o/{$itemId}", ['User-Agent' => self::BROWSER]);
        $this->get("/api/k/{$recipient->token}/o/{$itemId}", ['User-Agent' => 'Mimecast scanner']);

        Sanctum::actingAs($author);
        $this->getJson("/api/campaigns/{$campaign->id}")->assertOk()
            ->assertJsonPath('clicks', ['recipients' => 1, 'total' => 2, 'bots' => 1, 'items' => [['campaign_item_id' => $itemId, 'offer' => 1, 'product' => 1]]]);
        $this->getJson("/api/campaigns/{$campaign->id}/recipients?clicked=1")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'klient@alfa.pl')->assertJsonPath('data.0.clicks', 2);
        $this->getJson('/api/campaigns')->assertOk()->assertJsonPath('data.0.clicked', 1);
    }
}
