<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Presta\PrestaDescriptionHtml;
use Tests\TestCase;

final class PrestaDescriptionHtmlTest extends TestCase
{
    public function test_builds_bhp_box_and_lists_like_przetargi(): void
    {
        $html = $this->html()->fromProduct($this->product([
            'description' => "Rękawice Camapren 720 z polichloroprenu.\n\nSpecyfikacja:\n- ukryte w opisie",
            'enrichment_payload' => [
                'attributes' => [
                    'kategoria_bhp' => 'rekawice',
                    'kod_producenta' => '072007141E',
                    'material' => 'polichloropren',
                    'rozmiar' => '10',
                    'normy_en' => ['EN 374', 'EN 388'],
                ],
                'specs' => ['nr art./SKU: 072007141E', 'model: Camapren 720'],
                'features' => ['odporność chemiczna'],
                'materials' => ['polichloropren'],
                'norms' => ['EN 374'],
                'certificates' => ['CE'],
                'use_cases' => ['przemysł chemiczny'],
                'source_urls' => ['https://example.com/kcl-camapren'],
            ],
        ]));

        $this->assertStringContainsString('Atrybuty BHP', $html);
        $this->assertStringContainsString('rękawice', $html);
        $this->assertStringContainsString('072007141E', $html);
        $this->assertStringContainsString('polichloropren', $html);
        $this->assertStringContainsString('EN 374, EN 388', $html);
        $this->assertStringContainsString('Specyfikacja', $html);
        $this->assertStringContainsString('<ul', $html);
        $this->assertStringContainsString('odporność chemiczna', $html);
        $this->assertStringContainsString('przemysł chemiczny', $html);
        $this->assertStringContainsString('href="https://example.com/kcl-camapren"', $html);
        $this->assertStringNotContainsString('ukryte w opisie', $html);
        $this->assertStringContainsString('Camapren 720 z polichloroprenu', $html);
        $this->assertLessThan(
            strpos($html, 'Cechy') ?: PHP_INT_MAX,
            strpos($html, 'Specyfikacja') ?: PHP_INT_MAX
        );
    }

    public function test_plain_description_without_payload_stays_a_paragraph(): void
    {
        $html = $this->html()->fromProduct($this->product([
            'description' => "Linia 1.\nLinia 2.",
            'enrichment_payload' => null,
        ]));

        $this->assertStringContainsString('<p', $html);
        $this->assertStringContainsString('Linia 1.', $html);
        $this->assertStringContainsString('<br>', $html);
        $this->assertStringNotContainsString('Atrybuty BHP', $html);
    }

    public function test_escapes_xss_in_payload(): void
    {
        $html = $this->html()->fromProduct($this->product([
            'description' => 'Opis <img src=x onerror=alert(1)>',
            'enrichment_payload' => [
                'features' => ['<script>alert(1)</script>'],
                'source_urls' => ['javascript:alert(1)'],
            ],
        ]));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function test_keeps_existing_html_description_when_no_lists(): void
    {
        $html = $this->html()->fromProduct($this->product([
            'description' => '<p>Pełny opis.</p><ul><li>lina 8 mm</li></ul>',
            'enrichment_payload' => null,
        ]));

        $this->assertSame('<p>Pełny opis.</p><ul><li>lina 8 mm</li></ul>', $html);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function product(array $attrs): Product
    {
        $product = new Product;
        $product->description = $attrs['description'] ?? null;
        $product->enrichment_payload = $attrs['enrichment_payload'] ?? null;

        return $product;
    }

    private function html(): PrestaDescriptionHtml
    {
        return $this->app->make(PrestaDescriptionHtml::class);
    }
}
