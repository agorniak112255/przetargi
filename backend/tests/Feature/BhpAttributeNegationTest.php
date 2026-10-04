<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Support\BhpAttributeNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Przeczenia materiałów: „bez lateksu”, „bezlateksowy”, „nie zawiera lateksu ani silikonu”, „latex-free” czy
 * „dla osób uczulonych na lateks” nie dają materiału ani rodziny materiału. Przypadki z produkcji (04.10.2026):
 * rękawice HPPE/Dyneema z rodziną „lateks” wypadały z zamienników innych rękawic antyprzecięciowych.
 */
final class BhpAttributeNegationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dyneema_glove_8718_drops_latex_and_negation_entries_from_model_list(): void
    {
        $attrs = (new BhpAttributeNormalizer)->forProduct($this->card(
            'Rękawice antyprzecięciowe Dyneema Diamond',
            'Rękawice antyprzecięciowe z włókna Dyneema® Diamond Technology, poziom odporności na przecięcie F. '
                .'Wyrób bezsilikonowy i bezlateksowy.',
            [
                'material' => 'Dyneema® Diamond Technology',
                'materialy' => ['Dyneema® Diamond Technology', 'bez lateksu', 'bez silikonu', 'lateks'],
                'rodzina_materialu' => 'lateks',
            ],
        ));

        $this->assertSame(['Dyneema® Diamond Technology'], $attrs['materialy']);
        $this->assertSame('Dyneema® Diamond Technology', $attrs['material']);
        $this->assertSame('cut', $attrs['rodzina_materialu']);
    }

    public function test_hppe_glove_8698_without_latex_nor_silicone(): void
    {
        $attrs = (new BhpAttributeNormalizer)->forProduct($this->card(
            'Rękawice antyprzecięciowe HPPE',
            'Rękawice antyprzecięciowe z włókna HPPE, odporność na przecięcie poziom C. Nie zawiera lateksu ani silikonu.',
            ['materialy' => ['HPPE', 'lateks', 'silikon'], 'rodzina_materialu' => 'lateks'],
        ));

        $this->assertSame(['HPPE'], $attrs['materialy']);
        $this->assertSame('cut', $attrs['rodzina_materialu']);
    }

    public function test_nitrile_glove_8864_allergy_sentence_is_not_latex(): void
    {
        $description = 'Jednorazowe rękawice nitrylowe, bezpudrowe. Bezpieczne dla osób uczulonych na lateks, '
            .'ponieważ produkt nie zawiera tego alergenu.';
        $n = new BhpAttributeNormalizer;

        $stored = $n->forProduct($this->card('Rękawice nitrylowe jednorazowe', $description, [
            'material' => 'nitryl',
            'materialy' => ['nitryl', 'lateks'],
        ]));
        $this->assertSame(['nitryl'], $stored['materialy']);
        $this->assertSame('nitryl', $stored['rodzina_materialu']);

        // bez zapisanych atrybutów: odczyt z samego tekstu
        $fromText = $n->forProduct($this->card('Rękawice nitrylowe jednorazowe', $description, null));
        $this->assertNotContains('lateks', $fromText['materialy']);
        $this->assertContains('nitryl', $fromText['materialy']);
        $this->assertSame('nitryl', $fromText['rodzina_materialu']);
    }

    public function test_latex_coated_glove_keeps_latex(): void
    {
        $attrs = (new BhpAttributeNormalizer)->forProduct($this->card(
            'Rękawice gospodarcze lateksowe',
            'Rękawice gospodarcze, powłoka z lateksu naturalnego, wnętrze flokowane bawełną.',
            null,
        ));

        $this->assertContains('lateks', $attrs['materialy']);
        $this->assertSame('lateks', $attrs['rodzina_materialu']);
    }

    public function test_negation_does_not_swallow_the_next_positive_material(): void
    {
        $n = new BhpAttributeNormalizer;

        $withNitrile = $n->forProduct($this->card('Rękawice robocze', 'Rękawice bez lateksu i z nitrylem na dłoni.', null));
        $this->assertSame(['nitryl'], $withNitrile['materialy']);
        $this->assertSame('nitryl', $withNitrile['rodzina_materialu']);

        // narzędnik i przymiotnik po przecinku to nowe twierdzenie, nie ciąg przeczenia
        foreach ([
            'Rękawice bez szwów, powlekane lateksem.',
            'Nie zawiera silikonu, powłoka lateksowa.',
            'Brak pudru, lateksowa powłoka chwytna.',
        ] as $description) {
            $attrs = $n->forProduct($this->card('Rękawice robocze', $description, null));
            $this->assertContains('lateks', $attrs['materialy'], $description);
            $this->assertSame('lateks', $attrs['rodzina_materialu'], $description);
        }
    }

    public function test_english_and_list_negations(): void
    {
        $n = new BhpAttributeNormalizer;

        $latexFree = $n->forProduct($this->card('Nitrile disposable glove', 'Latex-free nitrile glove, powder free.', null));
        $this->assertSame(['nitryl'], $latexFree['materialy']);
        $this->assertSame('nitryl', $latexFree['rodzina_materialu']);

        $freeOf = $n->forProduct($this->card('Cut sleeve', 'Nylon liner, free of latex and silicone.', null));
        $this->assertSame(['nylon'], $freeOf['materialy']);
        $this->assertSame('tkanina', $freeOf['rodzina_materialu']);

        $list = $n->forProduct($this->card(
            'Rękawice HPPE',
            'Rękawice z włókna HPPE. Nie zawiera ftalanów, silikonu i lateksu. Wolne od lateksu naturalnego.',
            ['materialy' => ['HPPE', 'Lateks naturalny']],
        ));
        $this->assertSame(['HPPE'], $list['materialy']);
        $this->assertSame('cut', $list['rodzina_materialu']);
    }

    public function test_material_without_negation_in_text_or_confirmed_by_price_list_stays(): void
    {
        $n = new BhpAttributeNormalizer;

        // brak przeczenia w tekście — lista modelu bez zmian
        $this->assertSame(['lateks'], $n->normalize(['materialy' => ['lateks']], ['description' => 'Rękawice ochronne.'])['materialy']);

        // kolumna materiału w cenniku (dokument producenta) jest dowodem pozytywnym
        $priceList = $n->normalize(
            ['materialy' => ['lateks']],
            ['description' => 'Rękawice bez lateksu.', 'price_list' => ['material' => 'lateks']],
        );
        $this->assertSame('lateks', $priceList['material']);
        $this->assertSame(['lateks'], $priceList['materialy']);

        // lateks wymieniony w tekście pozytywnie obok przeczenia innego materiału
        $positive = $n->normalize(
            ['materialy' => ['lateks', 'bez silikonu']],
            ['description' => 'Powłoka z lateksu, nie zawiera silikonu.'],
        );
        $this->assertSame(['lateks'], $positive['materialy']);
        $this->assertSame('lateks', $positive['rodzina_materialu']);
    }

    /**
     * Rękaw HyFlex 11-202 z produkcji (fixture opisowy15): opis „latex-free”, cechy „Bez lateksu i silikonu”, a zapisane
     * atrybuty mają rodzinę „lateks”. Przeliczenie już jej nie daje, a panel (forDisplay bierze zapisane materiały
     * i rodzinę) pokazuje poprawkę dopiero po products:backfill-bhp-attributes --force.
     */
    public function test_hyflex_11202000_family_is_rewritten_by_backfill_force(): void
    {
        $id = Opisowy15Fixture::seed(['11202000'])['11202000'];
        $n = new BhpAttributeNormalizer;
        $product = Product::query()->findOrFail($id);
        $this->assertSame('lateks', $product->enrichment_payload['attributes']['rodzina_materialu'], 'fixture: zapis sprzed poprawki');
        $this->assertSame('lateks', $n->forDisplay($product)['attributes']['rodzina_materialu'], 'panel pokazuje zapis do czasu przeliczenia');

        $computed = $n->forProduct($product);
        $this->assertNotContains('lateks', $computed['materialy']);
        $this->assertSame('tkanina', $computed['rodzina_materialu']);

        $backup = storage_path('app/repair-backups/test-bhp-negation.json');
        @unlink($backup);
        $this->artisan('products:backfill-bhp-attributes', [
            '--force' => true,
            '--id' => [$id],
            '--apply' => true,
            '--backup' => $backup,
        ])->assertSuccessful();
        @unlink($backup);

        $saved = Product::query()->findOrFail($id);
        $this->assertSame('tkanina', $saved->enrichment_payload['attributes']['rodzina_materialu']);
        $this->assertNotContains('lateks', $saved->enrichment_payload['attributes']['materialy']);
        $this->assertSame('tkanina', $n->forDisplay($saved)['attributes']['rodzina_materialu']);
    }

    /**
     * @param  array<string, mixed>|null  $attributes  zapisane enrichment_payload.attributes
     */
    private function card(string $name, string $description, ?array $attributes): Product
    {
        return Product::query()->create([
            'sku' => 'NEG-'.md5($name.$description.json_encode($attributes)),
            'name' => $name,
            'manufacturer' => 'Test',
            'description' => $description,
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => $attributes === null ? null : ['attributes' => $attributes],
        ]);
    }
}
