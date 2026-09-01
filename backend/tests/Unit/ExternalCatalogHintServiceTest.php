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

    #[Test]
    public function drops_jacket_when_requirement_is_helmet_liner(): void
    {
        $svc = $this->app->make(ExternalCatalogHintService::class);
        $req = 'Wkładka/czepek ocieplana pod hełm antyelektrostatyczna EN 1149-5 lub EN 61340';

        $ranked = $svc->rankResults([
            [
                'url' => 'https://www.krystian.com.pl/produkt/kurtka-antyelektrostatyczna/',
                'title' => 'Kurtka antyelektrostatyczna - STATICGUARD - PW KRYSTIAN',
            ],
            [
                'url' => 'https://sklep.example/produkt/czepek-ocieplany-esd',
                'title' => 'Czepek ocieplany pod hełm ESD EN 1149-5',
            ],
        ], $req);

        $this->assertCount(1, $ranked);
        $this->assertSame('https://sklep.example/produkt/czepek-ocieplany-esd', $ranked[0]['url']);
    }

    #[Test]
    public function coverall_query_asks_for_the_suit_with_acid_as_resistance(): void
    {
        $svc = $this->app->make(ExternalCatalogHintService::class);
        $q = $svc->productSearchQuery(
            'Kombinezon chemoodporny ( w szczególności na kwas siarkowy 96%) antyelektrostatyczny,'
        );

        $this->assertStringContainsString('kombinezon chemoodporny', mb_strtolower($q));
        $this->assertStringContainsString('antyelektrostatyczny', mb_strtolower($q));
        $this->assertStringContainsString('odporność na kwas siarkowy 96%', mb_strtolower($q));
        $this->assertDoesNotMatchRegularExpression('/^kwas siarkowy/ui', trim($q));
    }

    #[Test]
    public function picks_coverall_page_not_the_acid_listing(): void
    {
        $svc = $this->app->make(ExternalCatalogHintService::class);
        $req = 'Kombinezon chemoodporny ( w szczególności na kwas siarkowy 96%) antyelektrostatyczny,';

        $best = $svc->pickBestResult([
            [
                'url' => 'https://sklep.biomus.eu/kategoria-produktu/surowce-i-odczynniki-chemiczne/kwas-siarkowy-96/',
                'title' => 'Kwas siarkowy 96% | Biomus',
            ],
            [
                'url' => 'https://sklep-bhp.example/produkt/kombinezon-tychem-c',
                'title' => 'Kombinezon chemoodporny Tychem C na kwas siarkowy',
            ],
        ], $req);

        $this->assertNotNull($best);
        $this->assertSame('https://sklep-bhp.example/produkt/kombinezon-tychem-c', $best['url']);
    }

    #[Test]
    public function does_not_offer_acid_page_when_no_coverall_hit(): void
    {
        $svc = $this->app->make(ExternalCatalogHintService::class);

        $best = $svc->pickBestResult([
            [
                'url' => 'https://sklep.biomus.eu/kategoria-produktu/surowce-i-odczynniki-chemiczne/kwas-siarkowy-96/',
                'title' => 'Kwas siarkowy 96% | Biomus',
            ],
        ], 'Kombinezon chemoodporny ( w szczególności na kwas siarkowy 96%) antyelektrostatyczny,');

        $this->assertNull($best);
    }
}
