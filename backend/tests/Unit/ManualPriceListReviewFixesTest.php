<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\EnrichmentDescriptionTemplateService;
use App\Support\BhpAttributeNormalizer;
use App\Support\PpeAssortment;
use App\Support\ProductSizeVariant;
use Tests\TestCase;

/**
 * Przegląd zmian ręcznych cenników (22.09.2026 wieczór): kategoria-dowód w typie i szablonie, przeczenia
 * w przeznaczeniu, ucięte nazwy Canis w rozmiarze i „MAT” w nazwie. Nazwy, SKU, kategorie i zdania opisów
 * przepisane z kart (other.json / manual.json).
 */
final class ManualPriceListReviewFixesTest extends TestCase
{
    private PpeAssortment $assortment;

    private BhpAttributeNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assortment = new PpeAssortment;
        $this->normalizer = new BhpAttributeNormalizer;
    }

    private function card(
        string $name,
        string $sku,
        string $description,
        ?string $category = null,
        ?string $categorySource = null,
        ?string $norms = null,
    ): Product {
        $product = new Product;
        $product->setRawAttributes([
            'name' => $name,
            'sku' => $sku,
            'description' => $description,
            'category' => $category,
            'category_source' => $categorySource,
            'norms' => $norms,
            'enrichment_payload' => json_encode(['attributes' => []]),
        ]);

        return $product;
    }

    public function test_price_list_category_keeps_coated_glove_type(): void
    {
        $covent = $this->card(
            'COVENT kat. II',
            'RCCO-61',
            'Rękawice ochronne COVENT kat. II to dziane rękawice bawełniano-poliestrowe z powłoką z szorstkowanego lateksu '
                .'na dłoni. Zapewniają ochronę przed ścieraniem, przecięciem, przedarciem i przebiciem. Rękawice są przeznaczone '
                .'do prac, w których występuje ryzyko mechaniczne, ale nie należy ich stosować przy pracach spawalniczych ani '
                .'w bezpośrednim kontakcie z ogniem.',
            'POWLEKANE',
            Product::CATEGORY_SOURCE_IMPORT,
        );
        $attrs = $this->normalizer->forProduct($covent);
        $this->assertSame('coated', $attrs['typ_wyrobu']);
        $this->assertNotSame('welding', $attrs['przeznaczenie']);

        $grex = $this->card(
            'RĘKAWICE G-REX P01',
            'G-REX P01 6',
            'Rękawice G-REX P01 to ultracienkie rękawice montażowe. Dzięki technologii PU-REFORMANCE zapewniają pewny chwyt '
                .'również w środowisku mokrym i zaolejonym, z wysoką odpornością na przetarcia.',
            'RĘKAWICE DZIANE POWLEKANE PU',
        );
        $this->assertSame('coated', $this->normalizer->forProduct($grex)['typ_wyrobu']);
    }

    public function test_automatic_category_gives_no_kategoria_family_type_or_template(): void
    {
        $category = 'Sklep - kategorie / Rękawice ochronne / Rękawice powlekane';
        $auto = $this->card('Uchwyt ścienny UX-100', 'UX-100', 'Uchwyt ścienny z tworzywa, montaż na dwa wkręty.', $category, Product::CATEGORY_SOURCE_PRESTA_REWRITE);
        $attrs = $this->normalizer->forProduct($auto);
        $this->assertNotSame('rekawice', $attrs['kategoria_bhp']);
        $this->assertNull($attrs['typ_wyrobu']);
        $this->assertNull($this->assortment->productFamily($auto));
        $this->assertNotSame('rekawice', app(EnrichmentDescriptionTemplateService::class)->kategoriaForProduct($auto));

        // ta sama ścieżka jako kolumna cennika jest dowodem
        $imported = $this->card('Uchwyt ścienny UX-100', 'UX-100', 'Uchwyt ścienny z tworzywa, montaż na dwa wkręty.', $category, Product::CATEGORY_SOURCE_IMPORT);
        $this->assertSame('rekawice', $this->normalizer->forProduct($imported)['kategoria_bhp']);
        $this->assertSame('rekawice', app(EnrichmentDescriptionTemplateService::class)->kategoriaForProduct($imported));
    }

    public function test_solar_welding_seams_are_not_welding(): void
    {
        $jacket = $this->card(
            'KURTKA MĘSKA',
            '901',
            'Kurtka z materiału Plavitex Eco, który powstaje z recyklingu. Dzięki zgrzewanym szwom wykonanym w technologii '
                .'Solar Welding kurtka jest wyjątkowo trwała i skutecznie chroni przed wilgocią.',
        );
        $this->assertNotSame('welding', $this->normalizer->forProduct($jacket)['przeznaczenie']);
        $this->assertNotSame('welding', $this->assortment->purpose('Szwy zgrzewane w technologii Solar Welding', PpeAssortment::FAMILY_APPAREL));
        // prawdziwe spawanie zostaje
        $this->assertSame('welding', $this->assortment->purpose('Rękawice spawalnicze skórzane EN 12477', PpeAssortment::FAMILY_GLOVES));
    }

    public function test_negated_sector_mentions_give_no_purpose(): void
    {
        $gloves = PpeAssortment::FAMILY_GLOVES;
        $this->assertNull($this->assortment->purpose('Rękawice nie są przeznaczone do kontaktu z ogniem ani do prac spawalniczych.', $gloves));
        $this->assertNull($this->assortment->purpose('Przechowywać w suchych pomieszczeniach, z dala od rozpuszczalników, kwasów i źródeł ciepła.', $gloves));
        $this->assertNull($this->assortment->purpose('Spodnie robocze. Prać w 60°C, bez czyszczenia chemicznego.', PpeAssortment::FAMILY_APPAREL));
        $this->assertNull($this->assortment->purpose('Należy unikać kontaktu z rozpuszczalnikami, kwasami i zasadami.', $gloves));
        // przeczenie innej cechy w tym samym zdaniu nie znosi branży; „unikalny” to nie „unikać”
        $this->assertSame('welding', $this->assortment->purpose('Rękawice nie zawierają lateksu i są przeznaczone do prac spawalniczych.', $gloves));
        $this->assertSame('welding', $this->assortment->purpose('Unikalna, wzmocniona konstrukcja do prac spawalniczych.', $gloves));

        $alphaTec = $this->card(
            'AlphaTec 23200',
            '23200100',
            'Rękawice nitrylowe o grubości 0,5 mm, chwytność piaskowana. Rękawice nie zawierają lateksu, nie są antystatyczne '
                .'ani bezsilikonowe. Rękawice spełniają wymagania normy EN ISO 21420:2020.',
        );
        $this->assertNotSame('electric', $this->normalizer->forProduct($alphaTec)['przeznaczenie']);
    }

    public function test_electric_needs_standard_not_bare_antistatic(): void
    {
        // odzież EN 1149 zostaje electric — także gdy antystatyka stoi w nazwie, a norma w opisie
        $rainJacket = $this->card(
            'Kurtka wodoochronna antystatyczna 3/4 Standard',
            '101/A',
            'Kurtka wodoochronna antystatyczna zapewnia ochronę przed deszczem, wiatrem i wyładowaniami elektrostatycznymi. '
                .'Spełnia wymagania norm EN ISO 13688, EN 343 oraz EN 1149-5.',
        );
        $this->assertSame('electric', $this->normalizer->forProduct($rainJacket)['przeznaczenie']);
        $this->assertSame('electric', $this->assortment->purpose('Kombinezon antyelektrostatyczny EN 1149-5', PpeAssortment::FAMILY_APPAREL));

        // rękawice ESD: antystatyka to cecha materiału, nie przeznaczenie
        $esd = $this->card(
            'HyFlex 11550',
            '11550090',
            'Rękawice zapewniają wygodę, elastyczność i pewny chwyt. Rękawice są antystatyczne, nie zawierają lateksu.',
        );
        $this->assertNotSame('electric', $this->normalizer->forProduct($esd)['przeznaczenie']);
    }

    /**
     * Drugi przegląd 22.09.2026 (N3): „skład chemiczny” i „czyszczenie chemiczne” to nie branża, numer normy
     * sklejony z „EN”, zakaz użycia i „ani” do końca zdania, rękawice elektroizolacyjne i literówka „eletryk”.
     */
    public function test_purpose_second_review_fixes(): void
    {
        $gloves = PpeAssortment::FAMILY_GLOVES;
        $apparel = PpeAssortment::FAMILY_APPAREL;

        // karta 241 (AJ GROUP 101/001/A): „skład chemiczny powleczenia” dawał „chemical”
        $this->assertSame('electric', $this->assortment->purpose(
            'Ubranie wodoochronne antystatyczne. Skład chemiczny powleczenia posiada właściwości antystatyczne. EN 343, EN 1149-5',
            $apparel
        ));
        $suit = $this->card(
            'Ubranie wodoochronne antystatyczne [kurtka 3/4 i spodnie ogrodniczki]',
            '101/001/A',
            'Ubranie wodoochronne antystatyczne. Skład chemiczny powleczenia posiada właściwości antystatyczne. '
                .'Spełnia wymagania norm EN 343 oraz EN 1149-5.',
        );
        $this->assertSame('electric', $this->normalizer->forProduct($suit)['przeznaczenie']);
        $this->assertNotSame('chemical', $this->assortment->purpose('Bluza robocza. Nie nadaje się do czyszczenia chemicznego. Pranie chemiczne zabronione.', $apparel));

        // „EN1149” bez spacji
        $this->assertSame('electric', $this->assortment->purpose('Rękawice nitrylowe antystatyczne EN1149 EN16350', $gloves));
        $this->assertSame('electric', $this->assortment->purpose('Rękawice nitrylowe antystatyczne EN1149-5', $gloves));
        $this->assertContains('electric', $this->assortment->roles('Kombinezon EN1149-5'));
        $this->assertNotContains('electric', $this->assortment->roles('Kod 211490'));

        // zakaz użycia i wyliczenie z „ani” przeczą do końca zdania; zwykłe „nie zawiera” kończy się na przecinku
        $this->assertNotSame('welding', $this->assortment->purpose('Nie zaleca się kontaktu z ogniem, prac spawalniczych ani kontaktu z chemikaliami.', $gloves));
        $this->assertSame('welding', $this->assortment->purpose('Rękawice nie zawierają lateksu, do prac spawalniczych.', $gloves));
        $this->assertSame('welding', $this->assortment->purpose('Rękawice nie zawierają lateksu ani silikonu, do prac spawalniczych.', $gloves));
        $this->assertNull($this->assortment->purpose('Rękawice nie chronią przed porażeniem prądem, promieniowaniem jonizującym ani substancjami chemicznymi.', $gloves));
        $this->assertNull($this->assortment->purpose('Nie chronią przed pracami spawalniczymi, iskrami ani płomieniem.', $gloves));

        // ochrona przed prądem wprost
        $this->assertSame('electric', $this->assortment->purpose('Rękawice elektroizolacyjne klasa 0', $gloves));
        $this->assertSame('electric', $this->assortment->purpose('Rękawice gumowe EN 60903 klasa 00', $gloves));
        $this->assertContains('electric', $this->assortment->roles('Obuwie dla elektryków'));
        $this->assertNotContains('electric', $this->assortment->roles('Kurtka ogrzewana elektrycznie'));
        $this->assertNotSame('electric', $this->assortment->purpose('Kurtka ogrzewana elektrycznie', $apparel));
    }

    public function test_truncated_canis_names_give_no_single_size(): void
    {
        $sizes = new ProductSizeVariant;
        $text = 'Rozmiary: S-3XL';
        foreach ([
            'High visible, softshell jacket with detachable sleeves, segmented tapes , size S',
            'Men´s sweatshirt with zipper, grey-black, dark blue-black, beige-black size S -',
            'Coverall Tyvek 500 Xpert, size  M-3XL (size S to order)',
        ] as $name) {
            $this->assertSame('s-xxxl', $sizes->labelFromTexts(null, $text, 'odziez', $name), $name);
        }
        // krótka nazwa z rozmiarem na końcu dalej jest rozmiarem karty
        $this->assertSame('xl', $sizes->labelFromTexts(null, $text, 'odziez', 'Koszulka polo uvex męska 7071212 rozm. XL'));
    }

    public function test_mat_word_in_ppe_name_is_not_floor_mat(): void
    {
        $this->assertFalse($this->assortment->namesFloorMat('Rękawice nitrylowe MAT'));
        $this->assertFalse($this->assortment->namesFloorMat('Kurtka Thermat'));

        foreach (['Orthomat Standard Szary 0.6m x 0.9m (9.5mm)', 'Rib Mat Czarny 0.9m x 1.5m', 'Mata dielektryczna 1 m', 'Dywanik elektroizolacyjny 20 KV'] as $name) {
            $this->assertTrue($this->assortment->namesFloorMat($name), $name);
        }
        $coba = $this->card(
            'Orthomat Dot Czarny/Żółte krawędzie 0.9m x 1.5m (9.5mm)',
            'AD010702',
            'Mata zwiększa przyczepność obuwia, zmniejszając ryzyko poślizgu. Ułatwia czyszczenie obuwia przy wejściu.',
        );
        $this->assertNull($this->assortment->productFamily($coba));

        $glove = $this->card('Rękawice nitrylowe MAT', 'NM-9', 'Rękawice nitrylowe jednorazowe, matowe.');
        $this->assertSame(PpeAssortment::FAMILY_GLOVES, $this->assortment->productFamily($glove));
        $this->assertNotNull($this->normalizer->forProduct($glove)['typ_wyrobu']);
    }
}
