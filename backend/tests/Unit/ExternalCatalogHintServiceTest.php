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
}
