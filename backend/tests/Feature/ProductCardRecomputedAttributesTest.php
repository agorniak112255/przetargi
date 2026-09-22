<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\ProductController;
use App\Models\Product;
use App\Services\Presta\PrestaDescriptionHtml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Karta w panelu i opis na nasz sklep pokazują atrybuty przeliczone według hierarchii źródeł, także bez norm
 * producenta. Przypadek z produkcji (ARTRA 9577, 22.09.2026): payload ze strony empiku miał klasę „S2”
 * i normę „EN ISO 20345:2011 S2 CI SRC” przy sandale „ARMEN 900 6060 O1 FO” z klasą O1 w cenniku.
 */
final class ProductCardRecomputedAttributesTest extends TestCase
{
    use RefreshDatabase;

    private function armenO1(): Product
    {
        return Product::query()->create([
            'sku' => 'ARMEN 900 6060 O1 FO',
            'name' => 'ARMEN 900 6060 O1 FO',
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie robocze i ochronne / Sandały ochronne',
            'catalog_price_net' => 100,
            'purchase_price' => 50,
            'stock' => 1,
            'description' => "Sandał ochronny z cholewką z mikrofibry.\n\nPodeszwa PU z technologią LEVITARYUM.",
            'shop_fields_summary' => "podnosek: stalowy LIBERYUM™\nnorma: EN ISO 20345:2011 S1 P SRC",
            'price_list_attributes' => ['klasa_ochrony' => 'O1'],
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'norms' => ['EN ISO 20345:2011 S2 CI SRC'],
                'specs' => ['Norma: EN ISO 20345:2011 S2 CI SRC'],
                'attributes' => [
                    'kategoria_bhp' => 'obuwie',
                    'klasa_ochrony' => 'S2',
                    'normy_en' => ['EN ISO 20345:2011 S2 CI SRC'],
                    'oznaczenia' => ['CI', 'SRC', 'FO'],
                    'przeznaczenie' => 'electric',
                ],
            ],
        ]);
    }

    public function test_panel_shows_recomputed_class_without_manufacturer_norms(): void
    {
        $product = $this->armenO1();

        $shown = app(ProductController::class)->show($product)->getData(true);

        $attributes = $shown['enrichment_payload']['attributes'];
        $this->assertSame('O1', $attributes['klasa_ochrony']);
        $this->assertSame(['FO'], $attributes['oznaczenia']);
        $this->assertNull($attributes['przeznaczenie']);
        $this->assertNotContains('EN ISO 20345:2011 S2 CI SRC', $shown['enrichment_payload']['norms']);
        $this->assertNotContains('EN ISO 20345:2011 S2 CI SRC', $attributes['normy_en']);
        // ani norma z tabelki zamienionej z wariantem S1 P — EN ISO 20345 to obuwie S, karta jest O1
        foreach ($shown['enrichment_payload']['norms'] as $norm) {
            $this->assertStringNotContainsString('20345', $norm);
        }
        // lista specyfikacji to cytat ze źródła — zostaje, jak była
        $this->assertSame(['Norma: EN ISO 20345:2011 S2 CI SRC'], $shown['enrichment_payload']['specs']);

        // payload w bazie zostaje zapisem tego, co przyniosło wzbogacanie
        $this->assertSame('S2', $product->refresh()->enrichment_payload['attributes']['klasa_ochrony']);
    }

    public function test_card_without_attributes_is_not_given_a_payload(): void
    {
        $product = Product::query()->create([
            'sku' => 'X-1',
            'name' => 'Rękawice robocze',
            'manufacturer' => 'Test',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);

        $shown = app(ProductController::class)->show($product->refresh())->getData(true);

        $this->assertArrayHasKey('enrichment_payload', $shown);
        $this->assertNull($shown['enrichment_payload']);
    }

    /**
     * Przegląd 22.09.2026: przeliczone normy_en mają normy wyczytane z całego opisu, także ze zdań przeczących.
     * Karta w panelu i opis na sklep pokazują normy zapisane i z tabelki/cennika — brak informacji nie jest faktem.
     */
    public function test_negated_norm_in_description_is_not_shown_in_panel_or_shop(): void
    {
        $product = Product::query()->create([
            'sku' => '6094209',
            'name' => 'Rękawice uvex unidur 6648 60942',
            'manufacturer' => 'UVEX',
            'category' => 'Rękawice',
            'catalog_price_net' => 20,
            'purchase_price' => 10,
            'stock' => 1,
            'description' => 'Rękawice powlekane nitrylem, odporne na przecięcie. Źródła nie podają zgodności z EN 407 ani EN ISO 374-1.',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'norms' => ['EN 388:2016 4X43C'],
                'attributes' => [
                    'kod_producenta' => '60942',
                    'kategoria_bhp' => 'rekawice',
                    'normy_en' => ['EN 388:2016 4X43C'],
                    'rozmiar' => '7-11',
                ],
            ],
        ]);

        $shown = app(ProductController::class)->show($product)->getData(true)['enrichment_payload'];
        $this->assertSame(['EN 388:2016 4X43C'], $shown['norms']);
        $this->assertSame(['EN 388:2016 4X43C'], $shown['attributes']['normy_en']);
        $this->assertSame('60942', $shown['attributes']['kod_producenta']);

        $html = app(PrestaDescriptionHtml::class)->fromProduct($product);
        $this->assertStringContainsString('>EN 388:2016 4X43C<', $html);
        $this->assertStringNotContainsString('<li style="margin:0 0 4px;font-size:13px">EN 407', $html);
        $this->assertStringNotContainsString('>EN ISO 374<', $html);
        $this->assertStringContainsString('>60942<', $html);
    }

    /** Karta 9524: payload bez norm, norma z klasą jest tylko w tabelce dostawcy — karta ją pokazuje. */
    public function test_norm_from_supplier_table_is_shown_on_the_card(): void
    {
        $product = Product::query()->create([
            'sku' => 'ARMEN 9003 2360 S1',
            'name' => 'ARMEN 9003 2360 S1',
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie robocze i ochronne / Sandały ochronne',
            'catalog_price_net' => 100,
            'purchase_price' => 50,
            'stock' => 1,
            'shop_fields_summary' => "Parametry\npodnosek: kompozytowy LIBERYUM™\nnorma: EN ISO 20345:2022 S1 FO SR\n"
                ."Przewodnik po rozmiarach\nRozmiar EU 35: 21,8",
            'price_list_attributes' => ['klasa_ochrony' => 'S1'],
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['attributes' => ['kategoria_bhp' => 'obuwie', 'normy_en' => [], 'klasa_ochrony' => null]],
        ]);

        $shown = app(ProductController::class)->show($product)->getData(true)['enrichment_payload'];

        $this->assertSame('S1', $shown['attributes']['klasa_ochrony']);
        $this->assertSame(['EN ISO 20345:2022'], $shown['norms'], 'odczyt normy z tabelki jak w normalize — klasa stoi osobno');
    }

    public function test_shop_description_uses_recomputed_class_and_norms(): void
    {
        $html = app(PrestaDescriptionHtml::class)->fromProduct($this->armenO1());

        $this->assertStringContainsString('>O1<', $html);
        $this->assertStringNotContainsString('>S2<', $html);
        // przeliczone normy nie mają zapisu cudzej klasy, więc sekcji norm nie ma wcale (specyfikacja zostaje
        // cytatem ze źródła — jej przesiew to sito stron wzbogacania, nie ten widok)
        $this->assertStringNotContainsString('>Normy<', $html);
    }
}
