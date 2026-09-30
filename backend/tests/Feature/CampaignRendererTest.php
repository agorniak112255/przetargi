<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CampaignAsset;
use App\Models\CampaignItem;
use App\Models\CampaignRecipient;
use App\Models\ErpItemLink;
use App\Models\ProductImage;
use App\Services\Campaigns\CampaignItemPresenter;
use App\Services\Campaigns\CampaignRenderer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CampaignFixtures;
use Tests\TestCase;

/** Treść maila kampanii i dane pozycji (CampaignItemPresenter). */
final class CampaignRendererTest extends TestCase
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

    public function test_presenter_card_price_stock_image_and_warnings(): void
    {
        $author = $this->sender();
        $author->forceFill(['default_margin_percent' => 25])->save();
        // stan handlowy = całość − usługowe; koszt z partii 400 zł ÷ 40 szt.
        $boots = $this->erpItem('B20417', 40, ['stock_value' => 400, 'stock_service' => 15]);
        $main = $this->card('TIGER-S3', 'Półbuty Tiger S3');
        $auto = $this->card('TIGER-AUTO', 'Półbuty auto', withImage: false);
        foreach ([[$auto, ErpItemLink::STATUS_AUTO], [$main, ErpItemLink::STATUS_CONFIRMED]] as [$card, $status]) {
            ErpItemLink::query()->create(['erp_item_id' => $boots->id, 'product_id' => $card->id, 'status' => $status, 'method' => 'name']);
        }
        $noCard = $this->erpItem('S40562', 0);
        $suggested = $this->card('NITRO', 'Rękawice NitroFlex');
        ErpItemLink::query()->create(['erp_item_id' => $noCard->id, 'product_id' => $suggested->id, 'status' => ErpItemLink::STATUS_SUGGESTED, 'method' => 'search']);

        $campaign = $this->campaign($author, [$boots, $noCard]);
        CampaignItem::query()->where('erp_item_id', $noCard->id)->update(['promo_price_net' => null]);
        CampaignItem::query()->where('erp_item_id', $boots->id)->update(['promo_price_net' => 8]);
        // ten sam towar w projekcie innego handlowca
        $other = $this->campaign($this->sender(), [$boots], ['name' => 'Obuwie jesień']);

        $rows = app(CampaignItemPresenter::class)->presentMany($campaign->items()->get(), $author);

        $this->assertSame('B20417', $rows[0]['code']);
        $this->assertSame('Półbuty Tiger S3', $rows[0]['name']);
        $this->assertSame('TIGER-S3', $rows[0]['card']['sku']);
        $this->assertEquals(25, $rows[0]['stock']);
        $this->assertEquals(10, $rows[0]['unit_cost']);
        $this->assertEquals(12.5, $rows[0]['suggested_price']);
        $imageId = ProductImage::query()->where('product_id', $main->id)->value('id');
        $this->assertSame('https://przetargi.example.pl/api/product-images/'.$imageId.'/thumb', $rows[0]['image_url']);
        $this->assertTrue($rows[0]['warnings']['below_cost']);
        $this->assertFalse($rows[0]['warnings']['no_stock']);
        $this->assertSame([['id' => $other->id, 'code' => $other->code, 'name' => 'Obuwie jesień', 'author' => 'Jan Handlowiec']], $rows[0]['warnings']['other_campaigns']);
        $this->assertNull($rows[0]['card_suggestion']);
        $this->assertNull($rows[0]['snapshot']);

        // towar bez karty: nazwa z XL, propozycja karty, brak zdjęcia i stanu
        $this->assertSame('Towar S40562', $rows[1]['name']);
        $this->assertNull($rows[1]['card']);
        $this->assertSame('NITRO', $rows[1]['card_suggestion']['sku']);
        $this->assertNull($rows[1]['image_url']);
        $this->assertTrue($rows[1]['warnings']['no_image']);
        $this->assertTrue($rows[1]['warnings']['no_stock']);
        $this->assertNull($rows[1]['unit_cost']);
        $this->assertNull($rows[1]['suggested_price']);

        // karta wybrana ręcznie ma pierwszeństwo przed główną
        CampaignItem::query()->where('erp_item_id', $boots->id)->where('campaign_id', $campaign->id)->update(['product_id' => $auto->id]);
        $row = app(CampaignItemPresenter::class)->present($campaign->items()->firstOrFail(), $author);
        $this->assertSame('TIGER-AUTO', $row['card']['sku']);
        $this->assertTrue($row['warnings']['no_image']);
    }

    public function test_mail_escapes_user_content_and_shows_prices_stock_and_links(): void
    {
        $author = $this->sender(['signature' => "<b>Jan</b>\ntel. 600"]);
        $item = $this->erpItem('B20417', 420, ['name' => 'Półbuty <img src=x onerror=alert(1)>']);
        $campaign = $this->campaign($author, [$item], [
            'heading' => '<script>alert("x")</script>Końcówki serii',
            'intro' => "Dzień dobry,\n<i>mamy</i> towar",
            'preheader' => 'Tylko do 31.10',
            'valid_until' => '2026-10-31',
        ]);
        CampaignItem::query()->update(['price_before_net' => 120, 'note' => 'rozm. 39–47 & więcej']);

        $mail = app(CampaignRenderer::class)->render($campaign->fresh());
        $html = $mail['html'];

        $this->assertSame('Wyprzedaż BHP', $mail['subject']);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<i>mamy</i>', $html);
        $this->assertStringNotContainsString('<b>Jan</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Dzień dobry,<br>', $html);
        $this->assertStringContainsString('&lt;b&gt;Jan&lt;/b&gt;<br>', $html);
        $this->assertStringContainsString('rozm. 39–47 &amp; więcej', $html);
        $this->assertStringContainsString('width="640"', $html);
        $this->assertStringContainsString('Ceny netto ważne do 31.10.2026 lub do wyczerpania zapasów', $html);
        $this->assertStringContainsString("89,00\u{00A0}zł", $html);
        $this->assertStringContainsString('text-decoration:line-through;">120,00', $html);
        $this->assertStringContainsString('Na stanie: 420 szt (29.09)', $html);
        $this->assertStringContainsString('Kod B20417', $html);
        $this->assertStringContainsString('mailto:jan@supon.example.pl?subject='.rawurlencode('Zapytanie '.$campaign->code.' B20417'), $html);
        $this->assertStringContainsString('Tylko do 31.10', $html);
        // bez odbiorcy (podgląd, test) link wypisu nigdzie nie prowadzi
        $this->assertStringContainsString('href="#"', $html);
        $this->assertStringContainsString('Otrzymujesz tę wiadomość jako klient SUPON.', $html);
        // wersja tekstowa bez encji HTML
        $this->assertStringContainsString('rozm. 39–47 & więcej', $mail['text']);
        $this->assertStringContainsString('Kod B20417', $mail['text']);

        $recipient = CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id, 'email' => 'klient@a.pl', 'source' => 'list', 'token' => str_repeat('a', 40), 'status' => 'pending',
        ]);
        $html = app(CampaignRenderer::class)->render($campaign->fresh(), $recipient)['html'];
        $this->assertStringContainsString('href="https://przetargi.example.pl/api/wypis/'.str_repeat('a', 40).'"', $html);
    }

    public function test_layouts_and_price_before_only_when_entered(): void
    {
        $author = $this->sender();
        $items = [$this->erpItem('A1'), $this->erpItem('A2'), $this->erpItem('A3'), $this->erpItem('A4')];
        $campaign = $this->campaign($author, $items, ['layout' => 'grid2']);

        $html = app(CampaignRenderer::class)->render($campaign)['html'];
        $this->assertSame(4, substr_count($html, 'width="50%" valign="top"'));
        $this->assertStringNotContainsString('line-through', $html);

        $campaign->update(['layout' => 'list']);
        $html = app(CampaignRenderer::class)->render($campaign->fresh())['html'];
        $this->assertSame(4, substr_count($html, 'width="100%" valign="top"'));

        $campaign->update(['layout' => 'grid3']);
        $html = app(CampaignRenderer::class)->render($campaign->fresh())['html'];
        // 4 pozycje w 3 kolumnach: druga linia dopełniona pustymi komórkami
        $this->assertSame(4, substr_count($html, 'width="33%" valign="top"'));
        $this->assertSame(2, substr_count($html, '<td width="33%" style="padding:6px;"></td>'));
    }

    public function test_blocks_in_order_escaped_with_images_button_color_and_fixed_parts(): void
    {
        $author = $this->sender();
        $logo = $this->asset(600, 200);
        $banner = $this->asset(1200, 400);
        $small = $this->asset(300, 100);
        $campaign = $this->campaign($author, [$this->erpItem('B20417')], [
            'valid_until' => '2026-10-31',
            'brand_color' => '#b3261e',
            'blocks' => [
                ['type' => 'header', 'logo' => $logo->uuid],
                ['type' => 'heading', 'text' => '<script>alert(1)</script>Nowości'],
                ['type' => 'image', 'asset' => $banner->uuid, 'alt' => '"><img src=x onerror=alert(1)>', 'url' => 'https://supon.pl/promocja?a=1&b=2'],
                ['type' => 'text', 'text' => "Linia <b>1</b>\nLinia 2"],
                ['type' => 'products', 'layout' => 'list'],
                ['type' => 'image', 'asset' => $small->uuid, 'alt' => '', 'url' => ''],
                ['type' => 'button', 'label' => 'Cały <katalog>', 'url' => 'https://supon.pl/katalog'],
                ['type' => 'footer', 'text' => "SUPON sp. z o.o.\nRzeszów"],
            ],
        ]);

        $mail = app(CampaignRenderer::class)->render($campaign);
        $html = $mail['html'];

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>1</b>', $html);
        $this->assertStringNotContainsString('<katalog>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;Nowości', $html);
        $this->assertStringContainsString('alt="&quot;&gt;&lt;img src=x onerror=alert(1)&gt;"', $html);
        $this->assertStringContainsString('Linia &lt;b&gt;1&lt;/b&gt;<br>', $html);
        // logo: wysokość 60, szerokość proporcjonalnie; grafika: najwyżej 592 szerokości; mała w naturalnym rozmiarze
        $base = 'https://przetargi.example.pl/api/campaign-assets/';
        $this->assertStringContainsString('<img src="'.$base.$logo->uuid.'" width="180" height="60"', $html);
        $this->assertStringContainsString('<a href="https://supon.pl/promocja?a=1&amp;b=2" style="text-decoration:none;">', $html);
        $this->assertStringContainsString('<img src="'.$base.$banner->uuid.'" width="592" height="197"', $html);
        $this->assertStringContainsString('<img src="'.$base.$small->uuid.'" width="300" height="100" alt=""', $html);
        // przycisk „bulletproof” w kolorze maila; kolor też na cenach
        $this->assertStringContainsString('bgcolor="#b3261e" style="background:#b3261e;border-radius:4px;">', $html);
        $this->assertStringContainsString('<a href="https://supon.pl/katalog"', $html);
        $this->assertStringContainsString('Cały &lt;katalog&gt;', $html);
        $this->assertStringContainsString('font-size:17px;font-weight:700;color:#b3261e;', $html);
        $this->assertStringNotContainsString('#0b7d6a', $html);
        $this->assertStringContainsString('SUPON sp. z o.o.<br>', $html);

        // kolejność: bloki jak w tablicy, „Ceny netto ważne…” tuż nad produktami, podpis i wypis po blokach
        $order = array_map(static fn (string $needle): int|false => strpos($html, $needle), [
            $logo->uuid, 'Nowości', $banner->uuid, 'Linia 2', 'Ceny netto ważne do 31.10.2026', 'Towar B20417', $small->uuid,
            'Cały &lt;katalog&gt;', 'Rzeszów', 'tel. 600 000 000', 'Wypisz mnie z mailingu',
        ]);
        $this->assertNotContains(false, $order);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order);
        // z logo nie ma tekstowego nagłówka firmy
        $this->assertStringNotContainsString('Odzież robocza i sprzęt BHP', $html);

        $text = $mail['text'];
        $this->assertStringContainsString('<script>alert(1)</script>Nowości', $text);
        $this->assertStringContainsString('["><img src=x onerror=alert(1)>] https://supon.pl/promocja?a=1&b=2', $text);
        $this->assertStringContainsString('Cały <katalog>: https://supon.pl/katalog', $text);
        $this->assertStringContainsString("Ceny netto ważne do 31.10.2026 lub do wyczerpania zapasów\n\n* Towar B20417", $text);
        $this->assertStringContainsString('Wypisz mnie z mailingu: #', $text);
    }

    public function test_empty_and_incomplete_blocks_are_skipped_and_renderer_never_throws(): void
    {
        $author = $this->sender();
        $banner = $this->asset(400, 100);
        $campaign = $this->campaign($author, [$this->erpItem('A1')], ['blocks' => [
            ['type' => 'heading', 'text' => '   '],
            ['type' => 'text', 'text' => ''],
            ['type' => 'image', 'asset' => null, 'alt' => 'Brak obrazka', 'url' => 'https://supon.pl'],
            ['type' => 'image', 'asset' => '9f9f9f9f-1111-4222-8333-444455556666', 'alt' => 'Usunięty', 'url' => ''],
            ['type' => 'button', 'label' => 'Bez adresu', 'url' => ''],
            ['type' => 'button', 'label' => '', 'url' => 'https://supon.pl'],
            ['type' => 'button', 'label' => 'Zły adres', 'url' => 'javascript:alert(1)'],
            ['type' => 'button', 'label' => 'W trakcie pisania', 'url' => 'htt'],
            ['type' => 'image', 'asset' => $banner->uuid, 'alt' => 'Baner', 'url' => 'javascript:alert(1)'],
            ['type' => 'image', 'asset' => $banner->uuid, 'alt' => 'Baner http', 'url' => 'http://supon.pl'],
            ['type' => 'products', 'layout' => 'nieznany'],
            ['type' => 'footer', 'text' => ''],
            ['type' => 'video'],
            'śmieci',
        ]]);

        $mail = app(CampaignRenderer::class)->render($campaign);

        // padding:11px 24px — tylko przycisk z bloku (przyciski pozycji mają padding:7px)
        foreach (['Brak obrazka', 'Usunięty', 'Bez adresu', 'Zły adres', 'W trakcie pisania', 'javascript:', 'http://supon.pl', '<h1', 'padding:11px 24px'] as $needle) {
            $this->assertStringNotContainsString($needle, $mail['html'], $needle);
        }
        // grafika z niepoprawnym linkiem zostaje, ale bez <a>
        $this->assertSame(2, substr_count($mail['html'], '<img src="https://przetargi.example.pl/api/campaign-assets/'.$banner->uuid.'" width="400" height="100"'));
        $this->assertStringNotContainsString('<a href="javascript', $mail['html']);
        $this->assertStringNotContainsString('javascript:', $mail['text']);
        // bez bloku nagłówka nie ma nagłówka firmy, ale produkty, ceny, podpis i wypis są
        $this->assertStringNotContainsString('Odzież robocza i sprzęt BHP', $mail['html']);
        $this->assertSame(1, substr_count($mail['html'], 'width="33%" valign="top"'));
        $this->assertStringContainsString('Ceny netto ważne do wyczerpania zapasów', $mail['html']);
        $this->assertStringContainsString('Otrzymujesz tę wiadomość jako klient SUPON.', $mail['html']);
        $this->assertStringNotContainsString('Brak obrazka', $mail['text']);
    }

    public function test_old_campaign_without_blocks_looks_like_before(): void
    {
        $author = $this->sender();
        $campaign = $this->campaign($author, [$this->erpItem('A1'), $this->erpItem('A2')], [
            'heading' => 'Końcówki serii', 'intro' => 'Dzień dobry', 'layout' => 'grid2',
        ]);
        $this->assertNull($campaign->blocks);

        $html = app(CampaignRenderer::class)->render($campaign)['html'];

        // nagłówek firmowy, nagłówek i wstęp w jednej komórce, ceny nad produktami, układ z kampanii, kolor domyślny
        $this->assertStringContainsString('<div style="font-weight:700;font-size:18px;color:#0b7d6a;letter-spacing:0.02em;">SUPON</div>', $html);
        $this->assertStringContainsString('Odzież robocza i sprzęt BHP', $html);
        $this->assertMatchesRegularExpression('#<td style="padding:20px 24px 8px;[^"]*">\s*<h1[^>]*>Końcówki serii</h1>\s*<p[^>]*>Dzień dobry</p>\s*</td>#', $html);
        $this->assertLessThan(strpos($html, 'Towar A1'), strpos($html, 'Ceny netto ważne do wyczerpania zapasów'));
        $this->assertGreaterThan(strpos($html, 'Dzień dobry'), strpos($html, 'Ceny netto ważne do wyczerpania zapasów'));
        $this->assertSame(2, substr_count($html, 'width="50%" valign="top"'));
    }

    public function test_render_blocks_uses_given_unsaved_blocks(): void
    {
        $author = $this->sender();
        $campaign = $this->campaign($author, [$this->erpItem('A1')], ['heading' => 'Zapisany']);

        $html = app(CampaignRenderer::class)->renderBlocks($campaign, [['type' => 'heading', 'text' => 'Niezapisany'], ['type' => 'products', 'layout' => 'list']], '#1f5fa8')['html'];

        $this->assertStringContainsString('Niezapisany', $html);
        $this->assertStringNotContainsString('Zapisany<', $html);
        $this->assertSame(1, substr_count($html, 'width="100%" valign="top"'));
        $this->assertStringContainsString('bgcolor="#1f5fa8"', $html);
        $this->assertNull($campaign->fresh()->blocks);
    }

    public function test_sent_campaign_uses_snapshot_not_current_data(): void
    {
        $author = $this->sender();
        $item = $this->erpItem('B20417', 420);
        $campaign = $this->campaign($author, [$item], ['status' => 'sent', 'sent_at' => now()]);
        CampaignItem::query()->update([
            'snap_name' => 'Półbuty w dniu wysyłki', 'snap_code' => 'B20417', 'snap_unit' => 'para', 'snap_price' => 79,
            'snap_stock' => 300, 'snap_stock_at' => '2026-09-20', 'snap_image_url' => 'https://przetargi.example.pl/api/product-images/5/thumb',
        ]);
        $item->update(['name' => 'Nowa nazwa', 'stock_total' => 5]);

        $html = app(CampaignRenderer::class)->render($campaign->fresh())['html'];

        $this->assertStringContainsString('Półbuty w dniu wysyłki', $html);
        $this->assertStringNotContainsString('Nowa nazwa', $html);
        $this->assertStringContainsString("79,00\u{00A0}zł", $html);
        $this->assertStringContainsString('netto / para', $html);
        $this->assertStringContainsString('Na stanie: 300 para (20.09)', $html);
        $this->assertStringContainsString('src="https://przetargi.example.pl/api/product-images/5/thumb"', $html);
    }

    private function asset(int $width, int $height): CampaignAsset
    {
        $uuid = (string) Str::uuid();

        return CampaignAsset::query()->create([
            'uuid' => $uuid, 'path' => 'campaign-assets/'.$uuid.'.jpg', 'mime' => 'image/jpeg', 'width' => $width, 'height' => $height, 'size' => 100,
        ]);
    }
}
