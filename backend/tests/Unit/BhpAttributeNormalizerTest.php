<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\BhpAttributeNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BhpAttributeNormalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalizes_llm_attributes_and_derives_missing(): void
    {
        $n = new BhpAttributeNormalizer;
        $attrs = $n->normalize(
            [
                'kategoria_bhp' => 'rękawice',
                'material' => 'nitryl',
                'normy_en' => ['EN 388:2016'],
                'poziomy_en388' => '4X42C',
            ],
            [
                'materials' => ['poliamid'],
                'specs' => ['Rozmiar: 9', 'Klasa: S3'],
                'sku' => 'RNITZ-9',
                'name' => 'Rękawice nitrylowe',
                'category' => 'Rękawice',
            ]
        );

        $this->assertSame('rekawice', $attrs['kategoria_bhp']);
        $this->assertSame('nitryl', $attrs['material']);
        $this->assertContains('nitryl', $attrs['materialy']);
        $this->assertContains('poliamid', $attrs['materialy']);
        $this->assertSame('RNITZ-9', $attrs['kod_producenta']);
        $this->assertSame('4X42C', $attrs['poziomy_en388']);
        $this->assertSame('9', $attrs['rozmiar']);
    }

    public function test_detects_size_ranges_from_description(): void
    {
        $n = new BhpAttributeNormalizer;

        $this->assertSame('36-48', $n->normalize([], [
            'name' => 'Artra AROSIO Air S1P',
            'description' => 'Rozmiary unisex od 36 do 48. Tabela rozmiarów producenta.',
        ])['rozmiar']);
        $this->assertSame('7-11', $n->normalize([], [
            'name' => 'Rękawice nitrylowe',
            'description' => 'Dostępne rozmiary: 7, 8, 9, 10, 11',
        ])['rozmiar']);
        $this->assertSame('s-xxl', $n->normalize([], [
            'name' => 'Spodnie robocze',
            'description' => 'Rozmiary od S do XXL.',
        ])['rozmiar']);
        $this->assertNull($n->normalize(
            ['kategoria_bhp' => 'obuwie', 'rozmiar' => '1-5XL'],
            [
                'name' => 'OWERTON (05 NERO)',
                'description' => 'Chodaki Cofra, EN 20347. Brak tabeli w akapicie.',
                'category' => 'Obuwie',
            ]
        )['rozmiar']);
        $this->assertSame('39-47', $n->normalize(
            ['kategoria_bhp' => 'obuwie', 'rozmiar' => '1-5XL'],
            [
                'name' => 'OWERTON',
                'description' => 'Chodaki Cofra. Taglie 39-47.',
                'category' => 'Obuwie',
            ]
        )['rozmiar']);
    }

    public function test_for_product_derives_from_enrichment_lists(): void
    {
        $product = Product::query()->create([
            'sku' => 'C300',
            'name' => 'Rękawice ochronne cut',
            'manufacturer' => 'uvex',
            'category' => 'Rękawice',
            'norms' => 'EN 388:2016 4X42C',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['Dyneema', 'poliamid'],
                'norms' => ['EN 388:2016 4X42C'],
                'specs' => [],
            ],
        ]);

        $attrs = (new BhpAttributeNormalizer)->forProduct($product);

        $this->assertSame('rekawice', $attrs['kategoria_bhp']);
        $this->assertSame('Dyneema', $attrs['material']);
        $this->assertNotEmpty($attrs['normy_en']);
        $this->assertSame('4X42C', $attrs['poziomy_en388']);
    }

    public function test_for_product_extracts_from_polish_description(): void
    {
        $product = Product::query()->create([
            'sku' => '34-848',
            'name' => 'KW Palm Coated',
            'manufacturer' => 'ATG / Maxiflex',
            'category' => null,
            'description' => "Rękawica ochronna ATG z powłoką nitrylową.\n\nNormy:\n- EN 388:2016 + A1:2018 - 4131A\n- EN ISO 21420:2020",
            'catalog_price_net' => 20,
            'purchase_price' => 15,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
            'enrichment_payload' => null,
        ]);

        $attrs = (new BhpAttributeNormalizer)->forProduct($product);

        $this->assertSame('rekawice', $attrs['kategoria_bhp']);
        $this->assertSame('nitryl', $attrs['material']);
        $this->assertNotEmpty($attrs['normy_en']);
        $this->assertSame('4131A', $attrs['poziomy_en388']);
    }

    public function test_detects_face_protection_not_head(): void
    {
        $n = new BhpAttributeNormalizer;
        $this->assertSame('ochrona_twarzy', $n->normalize(
            [],
            ['name' => 'Osłona twarzy żaroodporna siatkowa', 'category' => '']
        )['kategoria_bhp']);
        $this->assertSame('ochrona_oczu', $n->normalize(
            [],
            ['name' => 'Gogle chemiczne', 'category' => '']
        )['kategoria_bhp']);
        $this->assertSame('odziez', $n->normalize(
            [],
            ['name' => 'Kamizelka odblaskowa siatkowa', 'category' => '']
        )['kategoria_bhp']);
    }

    public function test_parses_s5_ci_sra_and_purofort_as_wellington(): void
    {
        $attrs = (new BhpAttributeNormalizer)->normalize(
            [
                'kategoria_bhp' => 'obuwie',
                'material' => 'Purofort',
                'klasa_ochrony' => 'S5.CI.SRA',
                'normy_en' => ['EN ISO 20345:2011'],
            ],
            [
                'name' => 'DUNLOP 462933 PUROFORT',
                'description' => 'Kalosz do rolnictwa, izolacja -20°C.',
                'category' => 'Obuwie',
                'use_cases' => ['rolnictwo'],
            ]
        );

        $this->assertSame('S5', $attrs['klasa_ochrony']);
        $this->assertContains('CI', $attrs['oznaczenia']);
        $this->assertContains('SRA', $attrs['oznaczenia']);
        $this->assertSame('kalosz', $attrs['typ_wyrobu']);
        $this->assertSame('guma', $attrs['rodzina_materialu']);
        $this->assertSame('agriculture', $attrs['przeznaczenie']);
        $this->assertNotContains('SRC', $attrs['oznaczenia']);
    }

    public function test_does_not_treat_src_or_hro_as_protection_class(): void
    {
        $attrs = (new BhpAttributeNormalizer)->normalize(
            ['kategoria_bhp' => 'obuwie'],
            [
                'name' => 'Trzewiki spawalnicze S3 HRO SRC',
                'description' => 'Skóra wodoodporna, spawanie.',
                'category' => 'Obuwie',
            ]
        );

        $this->assertSame('S3', $attrs['klasa_ochrony']);
        $this->assertContains('HRO', $attrs['oznaczenia']);
        $this->assertContains('SRC', $attrs['oznaczenia']);
        $this->assertSame('trzewik', $attrs['typ_wyrobu']);
        $this->assertSame('skora', $attrs['rodzina_materialu']);
        $this->assertSame('welding', $attrs['przeznaczenie']);
    }

    public function test_reads_o2_class_from_sztyblety_name(): void
    {
        $n = new BhpAttributeNormalizer;
        $this->assertSame('O2', $n->footwearClass('sztyblety O2'));
        $this->assertSame('S2', $n->footwearClass('półbuty s2 na zam.'));
        $this->assertSame('klasao2', $n->footwearClassToken('O2'));

        $attrs = $n->normalize(
            ['kategoria_bhp' => 'obuwie'],
            [
                'name' => 'sztyblety O2',
                'category' => 'Obuwie zawodowe',
            ]
        );
        $this->assertSame('O2', $attrs['klasa_ochrony']);
        $this->assertSame('sztyblet', $attrs['typ_wyrobu']);
    }

    public function test_parses_ffp_class_for_filtering_half_mask(): void
    {
        $attrs = (new BhpAttributeNormalizer)->normalize(
            [
                'kategoria_bhp' => 'drogi_oddechowe',
                'normy_en' => ['EN 149'],
            ],
            [
                'name' => 'Półmaska filtrująca 9914',
                'description' => 'FFP1 z węglem, EN 149.',
                'category' => 'Ochrona dróg oddechowych',
            ]
        );

        $this->assertSame('FFP1', $attrs['klasa_ochrony']);
        $this->assertSame('ffp', $attrs['typ_wyrobu']);
        $this->assertSame('drogi_oddechowe', $attrs['kategoria_bhp']);
    }

    public function test_reads_celsius_from_requirement_and_ignores_grammage(): void
    {
        $n = new BhpAttributeNormalizer;

        $this->assertSame(200, $n->requiredCelsius('rękawice do pracy przy 200 C'));
        $this->assertSame(200, $n->requiredCelsius('rękawice do pracy przy 200 stopni'));
        $this->assertSame(200, $n->requiredCelsius('rękawice do pracy przy 200 stopni C'));
        $this->assertSame(250, $n->maxCelsius('Kontakt 250°C, konwekcja 100°C. EN 407.'));
        $this->assertSame(100, $n->maxCelsius(
            'gwarantuje ochronę 360 stopni przed zadrapaniami. odporność termiczna: do 100°C przez 15s (EN407)'
        ));
        $this->assertNull($n->maxCelsius('zapewniające 360° ochrony przed otarciami'));
        $this->assertNull($n->maxCelsius('Ochrona 360 stopni, w tym w okolicy nadgarstka'));
        $this->assertNull($n->maxCelsius('ochronę 360° dookoła dłoni'));
        $this->assertNull($n->requiredCelsius('spodnie o gramaturze 250 g/m²'));
        $this->assertNull($n->maxCelsius('Opakowanie 200 szt.'));
        $this->assertSame(350, $n->maxCelsius('Rękawice termiczne 350°C'));
        $this->assertNull($n->maxCelsius('Rękawice termiczne 350°C', true));
        $this->assertSame(350, $n->maxCelsius(
            'Rękawice termiczne. Kontakt 350°C, EN 407.',
            true
        ));
        $this->assertNull($n->maxCelsius(
            'Rękawice dziane z poliamidu. Końcówki palców powlekane poliuretanem.',
            true
        ));
    }
}
