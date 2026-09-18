<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\EnrichmentDescriptionTemplateService;
use App\Support\EnrichmentDescriptionLayouts;
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
        $this->assertStringContainsString('Nie tłumacz karty produktu 1 do 1', $prompt);
        $this->assertStringContainsString('Nie powtarzaj zdań z description', $prompt);
        $this->assertStringContainsString('wyłuszcz ochronę', $prompt);
        $this->assertStringContainsString('cenników rozmiarów', $prompt);
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

    /**
     * Szablon obuwia ma pokrywać stały zestaw cech doboru — typ zapięcia (sznurówki / rzepy / BOA)
     * nie występuje w żadnym polu tekstowym u producentów, więc bez jawnego punktu w instrukcji
     * i bez zakazu zgadywania model albo go pomija, albo wymyśla.
     */
    public function test_footwear_template_covers_fastening_and_forbids_guessing(): void
    {
        $product = Product::query()->create([
            'sku' => 'S3-TRZEWIK',
            'name' => 'Trzewiki robocze S3',
            'manufacturer' => 'Artra',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);

        $service = app(EnrichmentDescriptionTemplateService::class);
        $this->assertSame('obuwie', $service->kategoriaForProduct($product));
        $prompt = $service->systemPrompt($product);

        // Stały zestaw cech doboru, z zapięciem na czele zgłoszenia użytkownika.
        $this->assertStringContainsString('Typ zapięcia: sznurowane / rzepy / BOA', $prompt);
        $this->assertStringContainsString('Podnosek: stalowy / kompozytowy / aluminiowy / brak', $prompt);
        $this->assertStringContainsString('Wkładka antyprzebiciowa: stalowa / tekstylna / brak', $prompt);
        $this->assertStringContainsString('EN ISO 20345:2022 S3L', $prompt);

        // Trzy stany cechy: obecna, wywnioskowana, nieobecna — brak nazwany brakiem.
        $this->assertStringContainsString('cecha OBECNA w źródle', $prompt);
        $this->assertStringContainsString('cecha WYWNIOSKOWANA', $prompt);
        $this->assertStringContainsString('cecha NIEOBECNA', $prompt);
        $this->assertStringContainsString('Typ zapięcia: brak danych w źródle', $prompt);

        // Zakaz zgadywania wraz z przykładem ARTRA (zapięcie widoczne wyłącznie na zdjęciu).
        $this->assertStringContainsString('ZAKAZ ZGADYWANIA', $prompt);
        $this->assertStringContainsString('widać je wyłącznie na zdjęciu', $prompt);
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

    public function test_glove_product_inherits_default_layout_until_customized(): void
    {
        $product = Product::query()->create([
            'sku' => '1024',
            'name' => 'Rękawice montażowe',
            'manufacturer' => 'Urgent',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 1,
        ]);
        $service = app(EnrichmentDescriptionTemplateService::class);
        $resolved = $service->resolvedForProduct($product);
        $this->assertSame('rekawice', $resolved['kategoria_bhp']);
        $this->assertSame('description', $resolved['card'][0]['id']);
        // za opisem stoja parametry wpisane recznie, dopiero po nich atrybuty wyliczone
        $this->assertSame('manual_specs', $resolved['card'][1]['id']);
        $this->assertSame('attributes', $resolved['card'][2]['id']);

        $card = EnrichmentDescriptionLayouts::defaultBlocks('card');
        $card[0] = ['id' => 'norms', 'visible' => true, 'emphasis' => 'highlight'];
        $card[5] = ['id' => 'description', 'visible' => true, 'emphasis' => 'none'];
        $service->update('rekawice', null, [
            'inherit_card' => false,
            'inherit_export' => true,
            'card' => $card,
        ]);

        $again = $service->resolvedForProduct($product);
        $this->assertSame('norms', $again['card'][0]['id']);
        $this->assertSame('highlight', $again['card'][0]['emphasis']);
        $this->assertSame('norms', $again['export'][0]['id']);
        $this->assertSame('highlight', $again['export'][0]['emphasis']);
    }
}
