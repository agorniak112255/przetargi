<?php

declare(strict_types=1);

namespace Tests\Feature\Norms;

use App\Models\PriceList;
use App\Models\Product;
use App\Services\Norms\AnsellNormPageReader;
use App\Services\Norms\ManufacturerNormIdentity;
use App\Services\Norms\ReaderMarkdownPage;
use App\Support\ManufacturerNormFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Plan norm z 23.09.2026, etap 3b: ansell.com stoi za zaporą (Incapsula), karta produktu i deklaracja w PDF to obrazy
 * bez tekstu, a poziomy stoją tylko w sekcji „Normy i certyfikaty” strony — jako podpis ikony i kod za nią. Markdown
 * w testach to zapisane odpowiedzi readera dla prawdziwych stron (23.09.2026).
 */
final class AnsellManufacturerNormsTest extends TestCase
{
    use RefreshDatabase;

    private const R065 = 'https://www.ansell.com/pl/pl/products/ringers-r065';

    public function test_reader_takes_icon_captions_with_the_code_next_to_them(): void
    {
        $reading = (new AnsellNormPageReader)->read($this->page('ansell-ringers-r065-pl.md'), self::R065);

        $this->assertNotNull($reading);
        $this->assertSame([
            ['label' => 'EN 388:2016', 'value' => '4X43EP'],
            ['label' => 'EN407:2020', 'value' => 'X1XXXX'],
            ['label' => 'EN ISO 21420:2020', 'value' => null],
        ], $reading->rows, 'CE, ANSI/ISEA i kategoria to nie normy EN — odpadają');
        $this->assertSame('ansell', $reading->reader);

        $hyflex = (new AnsellNormPageReader)->read($this->page('ansell-hyflex-11-800-pl.md'), 'https://www.ansell.com/pl/pl/products/hyflex-11-800');
        $this->assertSame(['label' => 'EN 388:2016 +A1:2018', 'value' => '3131A'], $hyflex?->rows[0]);
        $this->assertCount(3, $hyflex->rows, 'ikony powtórzone w karuzeli liczymy raz');
    }

    public function test_ansell_price_list_card_gets_model_codes_and_passes_the_gate_on_the_slug(): void
    {
        $card = $this->ringers();
        $identity = app(ManufacturerNormIdentity::class);

        $codes = array_column($identity->codesFor($card), 'code');
        $this->assertContains('r065', array_map('mb_strtolower', $codes));
        $this->assertNotContains('ringers', array_map('mb_strtolower', $codes), 'nazwa linii niczego nie potwierdza');

        $confirmed = $identity->confirm($card, self::R065, $this->page('ansell-ringers-r065-pl.md'), $identity->codesFor($card));
        $this->assertSame('model_code', $confirmed['by'] ?? null);
        $this->assertContains($confirmed['where'] ?? null, ['url', 'title']);
    }

    public function test_walled_page_goes_through_the_reader_and_lands_in_the_plan(): void
    {
        config(['norms.host_delay_ms' => 0]);
        Queue::fake();
        Http::fake([
            'r.jina.ai/*' => Http::response((string) file_get_contents(base_path('tests/Fixtures/norms/ansell-ringers-r065-pl.md')), 200),
            'www.ansell.com/*' => Http::response((string) file_get_contents(base_path('tests/Fixtures/norms/ansell-incapsula-wall.html')), 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('', 404),
        ]);
        $card = $this->ringers();
        $plan = storage_path('app/testing/plan-'.uniqid().'.json');

        $this->artisan('norms:from-manufacturer-pages', ['--manufacturer' => 'Ansell', '--plan' => $plan])->assertSuccessful();

        $entry = json_decode((string) file_get_contents($plan), true)['entries'][0];
        $this->assertSame('do zapisu', $entry['status']);
        $this->assertSame('ansell', $entry['column']['source']['reader']);
        $this->assertSame('4X43EP', $entry['column']['en388'] ?? null);

        $this->artisan('norms:from-manufacturer-pages', ['--apply' => $plan, '--backup' => storage_path('app/testing/b-'.uniqid().'.json')])
            ->assertSuccessful();
        $this->assertSame('4X43EP', ManufacturerNormFacts::context($card->fresh()->manufacturer_norms)['en388'] ?? null);
        @unlink($plan);
    }

    private function page(string $fixture): string
    {
        return ReaderMarkdownPage::toHtml((string) file_get_contents(base_path('tests/Fixtures/norms/'.$fixture)));
    }

    private function ringers(): Product
    {
        $product = Product::query()->create([
            'sku' => '065-13',
            'name' => 'RINGERS 065',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice robocze',
            'description' => 'Rękawice RINGERS R065 do prac udarowych. Spełniają normę EN 388 dotyczącą ochrony mechanicznej.',
            'catalog_price_net' => 100,
            'purchase_price' => 80,
            'stock' => 1,
            'enrichment_payload' => ['source_urls' => [self::R065], 'primary_source_url' => self::R065, 'primary_source_kind' => 'manufacturer'],
        ]);
        PriceList::query()->create([
            'original_filename' => 'ansell.xlsx',
            'manufacturer' => 'Ansell',
            'version' => '2026',
            'rows_total' => 1,
            'products_created' => 1,
            'product_ids' => [$product->id],
        ]);

        return $product;
    }
}
