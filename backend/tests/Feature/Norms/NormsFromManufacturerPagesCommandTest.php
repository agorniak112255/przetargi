<?php

declare(strict_types=1);

namespace Tests\Feature\Norms;

use App\Models\PriceList;
use App\Models\Product;
use App\Support\ManufacturerNormFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Plan norm z 23.09.2026, etap 3a: karta Canis 3210-012-000-00 mówi „EN 388” bez poziomów, a karta wyrobu na cxs.net.pl
 * podaje poziomy słownie. Strony w testach to zapisane prawdziwe strony CXS (23.09.2026).
 */
final class NormsFromManufacturerPagesCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH = 'https://cxs.net.pl/catalogsearch/result/?q=3210-012-000-00';

    private const CARD = 'https://cxs.net.pl/rekawice-cxs-tale.html';

    protected function setUp(): void
    {
        parent::setUp();
        config(['norms.host_delay_ms' => 0]);
        Queue::fake();
    }

    public function test_preview_writes_a_plan_and_apply_writes_exactly_the_plan(): void
    {
        $this->fakeCxs();
        $card = $this->canisGlove('3210-012-000-00');
        $plan = storage_path('app/testing/plan-'.uniqid().'.json');

        $this->artisan('norms:from-manufacturer-pages', ['--manufacturer' => 'Canis', '--plan' => $plan])->assertSuccessful();

        $this->assertNull($card->fresh()->manufacturer_norms, 'podgląd niczego nie zapisuje');
        $entry = json_decode((string) file_get_contents($plan), true)['entries'][0];
        $this->assertSame('do zapisu', $entry['status']);
        $this->assertSame(self::CARD, $entry['url']);
        $this->assertSame('3210-012-000-00', $entry['column']['source']['identity']['value']);
        $this->assertStringContainsString('przetarcie', $entry['column']['source']['block']);

        Http::fake(['*' => Http::response('', 500)]);
        $backup = storage_path('app/testing/backup-'.uniqid().'.json');
        $this->artisan('norms:from-manufacturer-pages', ['--apply' => $plan, '--backup' => $backup])->assertSuccessful();
        Http::assertNothingSent();

        $column = $card->fresh()->manufacturer_norms;
        $this->assertSame(ManufacturerNormFacts::WEB_PAGE_CONNECTOR, $column['source']['connector']);
        $this->assertTrue(ManufacturerNormFacts::verified($column));
        $this->assertContains('EN 388', array_column(ManufacturerNormFacts::rows($column), 'label'));

        $this->artisan('norms:from-manufacturer-pages', ['--restore' => $backup])->assertSuccessful();
        $this->assertNull($card->fresh()->manufacturer_norms);
        @unlink($plan);
        @unlink($backup);
    }

    public function test_page_of_another_variant_does_not_pass_the_gate(): void
    {
        $this->fakeCxs('3210-010-251-00');
        $card = $this->canisGlove('3210-010-251-00');
        $plan = storage_path('app/testing/plan-'.uniqid().'.json');

        $this->artisan('norms:from-manufacturer-pages', ['--manufacturer' => 'Canis', '--plan' => $plan])->assertSuccessful();

        $entry = json_decode((string) file_get_contents($plan), true)['entries'][0];
        $this->assertSame('odrzucone', $entry['status'], 'karta CXS TALE nosi kod 3210-012-000-00, nie 3210-010-251-00');
        $this->assertNull($card->fresh()->manufacturer_norms);
        @unlink($plan);
    }

    public function test_apply_skips_a_card_whose_norms_changed_after_preview(): void
    {
        $this->fakeCxs();
        $card = $this->canisGlove('3210-012-000-00');
        $plan = storage_path('app/testing/plan-'.uniqid().'.json');
        $this->artisan('norms:from-manufacturer-pages', ['--manufacturer' => 'Canis', '--plan' => $plan])->assertSuccessful();

        $b2b = ManufacturerNormFacts::build([['label' => 'EN 388', 'value' => '2121X']], 'atg', 'Canis', 'https://b2b.example/karta');
        $card->manufacturer_norms = $b2b;
        $card->save();
        $this->artisan('norms:from-manufacturer-pages', ['--apply' => $plan, '--backup' => storage_path('app/testing/b-'.uniqid().'.json')])
            ->assertSuccessful();

        $this->assertSame('atg', $card->fresh()->manufacturer_norms['source']['connector']);
        @unlink($plan);
    }

    public function test_verified_page_norms_are_not_replaced_by_enrichment(): void
    {
        $verified = ManufacturerNormFacts::build([['label' => 'EN 388', 'value' => '2112']], ManufacturerNormFacts::WEB_PAGE_CONNECTOR, 'Canis',
            self::CARD, null, ['identity' => ['by' => 'sku', 'value' => '3210-012-000-00', 'where' => 'markup']]);

        $this->assertFalse(ManufacturerNormFacts::replaceableFromWebPage($verified), 'wzbogacanie (bez bramki) nie podmienia');
        $this->assertTrue(ManufacturerNormFacts::replaceableFromWebPage($verified, true), 'odczyt po bramce podmienia');
    }

    private function fakeCxs(string $sku = '3210-012-000-00'): void
    {
        $fixtures = base_path('tests/Fixtures/norms/');
        Http::fake([
            'cxs.net.pl/catalogsearch/*' => Http::response((string) file_get_contents($fixtures.'cxs-search-3210-012.html'), 200, ['Content-Type' => 'text/html']),
            self::CARD => Http::response((string) file_get_contents($fixtures.'cxs-tale-3210-012.html'), 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('', 404),
        ]);
    }

    private function canisGlove(string $sku): Product
    {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => 'Gloves, grain leather palm, cotton jersey back, elastic wrist',
            'manufacturer' => 'Canis',
            'category' => 'Rękawice robocze',
            'description' => 'Rękawice robocze skórzane. Spełniają normy EN 388 oraz EN ISO 21420 i należą do kategorii ochrony II.',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
        ]);
        PriceList::query()->create([
            'original_filename' => 'canis.xlsx',
            'manufacturer' => 'Canis',
            'version' => '2026',
            'rows_total' => 1,
            'products_created' => 1,
            'product_ids' => [$product->id],
        ]);

        return $product;
    }
}
