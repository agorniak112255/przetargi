<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ExternalCatalogHintService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ExternalCatalogHintServiceTest extends TestCase
{
    #[Test]
    public function prefers_product_page_over_certificate_pdf(): void
    {
        $svc = $this->app->make(ExternalCatalogHintService::class);

        $best = $svc->pickBestResult([
            [
                'url' => 'https://files.cnop.pl/swiadectwa/gp-4x-abc.pdf',
                'title' => 'Świadectwo dopuszczenia Gaśnica proszkowa 4kg GP-4x ABC',
            ],
            [
                'url' => 'https://sklep-bhp.example/produkt/gasnica-proszkowa-4kg',
                'title' => 'Gaśnica proszkowa 4 kg GP-4x ABC',
            ],
        ]);

        $this->assertNotNull($best);
        $this->assertSame('https://sklep-bhp.example/produkt/gasnica-proszkowa-4kg', $best['url']);
    }

    #[Test]
    public function prefers_html_page_over_pdf_even_without_shop_path(): void
    {
        $svc = $this->app->make(ExternalCatalogHintService::class);

        $best = $svc->pickBestResult([
            [
                'url' => 'https://files.cnop.pl/swiadectwa/gp-4x-abc.pdf',
                'title' => 'Świadectwo dopuszczenia Gaśnica proszkowa 4kg GP-4x ABC',
            ],
            [
                'url' => 'https://example.com/gasnica-gp-4x-abc',
                'title' => 'Gaśnica proszkowa 4 kg',
            ],
        ]);

        $this->assertNotNull($best);
        $this->assertSame('https://example.com/gasnica-gp-4x-abc', $best['url']);
    }

    #[Test]
    public function falls_back_to_pdf_when_no_product_page(): void
    {
        $svc = $this->app->make(ExternalCatalogHintService::class);

        $best = $svc->pickBestResult([
            [
                'url' => 'https://files.cnop.pl/swiadectwa/gp-4x-abc.pdf',
                'title' => 'Świadectwo dopuszczenia Gaśnica proszkowa 4kg',
            ],
        ]);

        $this->assertNotNull($best);
        $this->assertStringEndsWith('.pdf', $best['url']);
    }

    #[Test]
    public function prefers_full_en14387_no_filter_over_a2b2e2k2(): void
    {
        $svc = $this->app->make(ExternalCatalogHintService::class);
        $req = 'pochłaniacz wielogazowy a2b2e2k2no dodatkowa ochrona na tlenki azoty NO';

        $best = $svc->pickBestResult([
            [
                'url' => 'https://kwasek.pl/produkt/pochlaniacz-wielogazowy-202-a2b2e2k2',
                'title' => 'Pochłaniacz wielogazowy 202 A2B2E2K2 kwasek.pl',
            ],
            [
                'url' => 'https://oxyline.eu/pl/product/275/filter-203-up3-a2-b2-e2-k2-hg-co-no-p3',
                'title' => 'Filtr 203 UP3 A2 B2 E2 K2 Hg CO NO P3',
            ],
        ], $req);

        $this->assertNotNull($best);
        $this->assertSame(
            'https://oxyline.eu/pl/product/275/filter-203-up3-a2-b2-e2-k2-hg-co-no-p3',
            $best['url']
        );
    }

    #[Test]
    public function drops_hint_when_no_result_has_required_no_class(): void
    {
        $svc = $this->app->make(ExternalCatalogHintService::class);

        $best = $svc->pickBestResult([
            [
                'url' => 'https://kwasek.pl/produkt/pochlaniacz-wielogazowy-202-a2b2e2k2',
                'title' => 'Pochłaniacz wielogazowy 202 A2B2E2K2 kwasek.pl',
            ],
        ], 'pochłaniacz wielogazowy a2b2e2k2no dodatkowa ochrona na tlenki azoty NO');

        $this->assertNull($best);
    }

    #[Test]
    public function accepts_no_class_from_snippet_when_title_omits_it(): void
    {
        $svc = $this->app->make(ExternalCatalogHintService::class);

        $best = $svc->pickBestResult([
            [
                'url' => 'https://oxyline.eu/pl/product/275/filter-203-up3',
                'title' => 'Filtr 203 UP3',
                'content' => 'A2-B2-E2-K2-Hg-CO-NO-P3 pochłaniacz wielogazowy',
            ],
        ], 'pochłaniacz wielogazowy a2b2e2k2no (dodatkowa ochrona na tlenki azotu)');

        $this->assertNotNull($best);
        $this->assertSame('https://oxyline.eu/pl/product/275/filter-203-up3', $best['url']);
    }

    #[Test]
    public function rank_results_returns_unique_urls_best_first(): void
    {
        $svc = $this->app->make(ExternalCatalogHintService::class);

        $ranked = $svc->rankResults([
            [
                'url' => 'https://files.cnop.pl/swiadectwa/gp-4x-abc.pdf',
                'title' => 'Świadectwo dopuszczenia Gaśnica 4kg',
            ],
            [
                'url' => 'https://sklep-bhp.example/produkt/gasnica-4kg',
                'title' => 'Gaśnica proszkowa 4 kg',
            ],
            [
                'url' => 'https://sklep-bhp.example/produkt/gasnica-4kg',
                'title' => 'duplikat',
            ],
        ]);

        $this->assertCount(2, $ranked);
        $this->assertSame('https://sklep-bhp.example/produkt/gasnica-4kg', $ranked[0]['url']);
    }
}
