<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductEnrichmentService;
use ReflectionClass;
use Tests\TestCase;

final class EnrichmentStructuredFieldsTest extends TestCase
{
    public function test_extracts_norms_materials_and_use_cases_from_page_text(): void
    {
        $service = app(ProductEnrichmentService::class);
        $text = <<<'TXT'
uvex C300 Dry. Materiały: wiskoza bambusowa, Dyneema, szkło i poliamid.
Norma EN 388:2016 4X42C. Przeznaczone do montażu i prac z ryzykiem przecięcia w warunkach suchych.
TXT;

        $extracted = $this->invoke($service, 'enrichStructuredFieldsFromPages', [
            [
                'description' => 'Krótki opis.',
                'norms' => [],
                'materials' => [],
                'use_cases' => [],
            ],
            [['url' => 'https://shop.example/p', 'text' => $text]],
            'Krótki opis.',
        ]);

        $this->assertNotEmpty($extracted['norms']);
        $this->assertTrue(collect($extracted['norms'])->contains(
            fn (string $n): bool => str_contains(mb_strtolower($n), 'en 388')
        ));
        $this->assertContains('Dyneema', $extracted['materials']);
        $this->assertContains('wiskoza bambusowa', $extracted['materials']);
        $this->assertNotEmpty($extracted['use_cases']);
    }

    public function test_sparse_payload_detection(): void
    {
        $service = app(ProductEnrichmentService::class);

        $this->assertTrue($this->invoke($service, 'looksLikeSparsePayload', [[
            'norms' => [],
            'materials' => [],
            'use_cases' => [],
            'features' => [],
        ]]));

        $this->assertFalse($this->invoke($service, 'looksLikeSparsePayload', [[
            'norms' => ['EN 388'],
            'materials' => ['nitryl'],
            'use_cases' => [],
            'features' => [],
        ]]));
    }

    public function test_rejects_real_estate_description_for_gloves(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product([
            'sku' => 'PILNE-1019',
            'name' => '1019 ZIMA Z POLARU',
            'manufacturer' => 'PILNE',
            'category' => 'REKAWICE',
        ]);
        $junk = 'Find the perfect office, industrial or commercial real estate for your team '
            .'or get specialized space for multi-family housing, healthcare, technology and others. '
            .'Let us help you find your next investment or leasing opportunity.';

        $this->assertTrue($this->invoke($service, 'looksLikeOffTopicDescription', [$junk]));
        $this->assertFalse($this->invoke($service, 'isUsableProductDescription', [$junk, $product]));
        $this->assertTrue($this->invoke($service, 'isUsableProductDescription', [
            'Rękawice zimowe 1019 z polaru marki Urgent. Przeznaczone do prac na zewnątrz w niskich temperaturach. '
            .'Materiał polarowy zapewnia izolację termiczną. Stosowane w magazynach, transporcie i na budowie zimą. '
            .'Model katalogowy 1019. Kategoria PPE — rękawice ochronne.',
            $product,
        ]));
    }

    /**
     * @param  list<mixed>  $args
     */
    private function invoke(object $service, string $method, array $args): mixed
    {
        $ref = new ReflectionClass($service);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($service, ...$args);
    }
}
