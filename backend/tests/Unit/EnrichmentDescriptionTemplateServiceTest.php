<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\EnrichmentDescriptionTemplateService;
use App\Support\EnrichmentDescriptionTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EnrichmentDescriptionTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_glove_product_gets_gloves_template(): void
    {
        $product = Product::query()->create([
            'sku' => 'AN-GLOVE-1',
            'name' => 'Rękawice nitrylowe',
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);

        $service = app(EnrichmentDescriptionTemplateService::class);

        $this->assertSame('rekawice', $service->kategoriaForProduct($product));
        $prompt = $service->systemPrompt($product);
        $this->assertStringContainsString('Rodzina produktu do tej karty: Rękawice (rekawice)', $prompt);
        $this->assertStringContainsString('EN 388', $prompt);
        $this->assertStringContainsString('"description"', $prompt);
    }

    public function test_unknown_product_falls_back_to_inne(): void
    {
        $product = Product::query()->create([
            'sku' => 'X-1',
            'name' => 'Produkt bez rodziny',
            'manufacturer' => 'Test',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);

        $service = app(EnrichmentDescriptionTemplateService::class);

        $this->assertSame('inne', $service->kategoriaForProduct($product));
        $this->assertStringContainsString('(inne)', $service->systemPrompt($product));
    }

    public function test_custom_instructions_land_in_prompt(): void
    {
        $product = Product::query()->create([
            'sku' => 'S3-BOOT',
            'name' => 'Trzewiki ochronne S3',
            'manufacturer' => 'Uvex',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);

        $service = app(EnrichmentDescriptionTemplateService::class);
        $this->assertSame('obuwie', $service->kategoriaForProduct($product));

        $service->update('obuwie', "CUSTOM-OBUWIE-PROMPT\nZbieraj klasę S1–S5 ze źródeł.");
        $this->assertStringContainsString('CUSTOM-OBUWIE-PROMPT', $service->systemPrompt($product));
        $this->assertStringNotContainsString(
            EnrichmentDescriptionTemplates::defaultInstructions('obuwie'),
            $service->systemPrompt($product)
        );
    }

    public function test_presta_category_path_does_not_block_family_from_name(): void
    {
        $product = Product::query()->create([
            'sku' => 'FFP2-1',
            'name' => 'Półmaska filtrująca FFP2',
            'manufacturer' => '3M',
            'category' => 'BHP > Ochrona indywidualna > Inne > Promocje',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);

        $this->assertSame('drogi_oddechowe', app(EnrichmentDescriptionTemplateService::class)->kategoriaForProduct($product));
    }
}
