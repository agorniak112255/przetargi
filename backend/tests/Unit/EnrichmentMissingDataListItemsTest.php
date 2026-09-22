<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Support\EnrichmentDescriptionTemplates;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Przegląd ręcznych cenników 22.09.2026: szablon `obuwie` w bazie kazał wypisywać „brak danych
 * w źródle”, a przykład kontraktu JSON podpowiadał modelowi pewność 0.0.
 */
final class EnrichmentMissingDataListItemsTest extends TestCase
{
    public function test_list_items_stating_missing_data_are_dropped_from_regular_enrichment_payload(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product([
            'sku' => '2126-049-806-42',
            'name' => 'Półbuty CXS TEXLINE MOLAT S1P ESD',
            'manufacturer' => 'Canis',
        ]);
        $extracted = [
            'specs' => ['Klasa ochrony: S1P', 'Typ zapięcia: brak danych w źródle', 'Wkładka antyprzebiciowa: tekstylna'],
            'features' => ['Lekka cholewka z tkaniny', 'Wodoodporność: źródło nie podaje'],
            'materials' => ['tkanina', 'Materiał podeszwy: brak informacji'],
            'use_cases' => ['magazyny', 'Branże: nie podano'],
            'norms' => ['EN ISO 20345:2011', 'brak danych w źródle'],
            'certificates' => ['CE'],
        ];

        // ta sama kolejność co w enrichProduct: bezpiecznik, potem składanie list
        $filter = new ReflectionMethod($service, 'withoutMissingDataListItems');
        $filter->setAccessible(true);
        $payload = new ReflectionMethod($service, 'payloadFromExtraction');
        $payload->setAccessible(true);
        $lists = $payload->invoke(
            $service,
            $product,
            $filter->invoke($service, $extracted),
            'Półbuty robocze Canis TEXLINE MOLAT S1P ESD.',
            []
        )['lists'];

        $this->assertSame(['Klasa ochrony: S1P', 'Wkładka antyprzebiciowa: tekstylna'], $lists['specs']);
        $this->assertSame(['Lekka cholewka z tkaniny'], $lists['features']);
        $this->assertSame(['tkanina'], $lists['materials']);
        $this->assertSame(['magazyny'], $lists['use_cases']);
        $this->assertSame(['EN ISO 20345:2011'], $lists['norms']);
        $this->assertSame(['CE'], $lists['certificates']);
    }

    public function test_json_contract_example_does_not_suggest_zero_confidence(): void
    {
        $contract = EnrichmentDescriptionTemplates::jsonContract();

        $this->assertDoesNotMatchRegularExpression('/"confidence":\s*0(?:\.0+)?\s*$/m', $contract);
        $this->assertMatchesRegularExpression('/"confidence":\s*0\.[1-9]/', $contract);
    }
}
