<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\BhpAttributeNormalizer;
use App\Support\PpeAssortment;
use App\Support\ProductSizeVariant;
use Tests\TestCase;

/**
 * Przypadki z audytu ręcznych cenników 22.09.2026 (Coba, Canis, Ansell, CEDERROTH, MAPA) — nazwy, SKU
 * i fragmenty opisów przepisane z kart. Rodzina, typ, przeznaczenie i rozmiar liczone z tego, co karta
 * mówi o sobie, a nie z przypadkowego słowa opisu.
 */
final class ManualPriceListClassificationTest extends TestCase
{
    private PpeAssortment $assortment;

    private BhpAttributeNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assortment = new PpeAssortment;
        $this->normalizer = new BhpAttributeNormalizer;
    }

    /** @param  array<string, mixed>  $attrs */
    private function card(string $name, string $sku, string $description, ?string $norms = null, array $attrs = [], array $payload = []): Product
    {
        $product = new Product;
        $product->setRawAttributes([
            'name' => $name,
            'sku' => $sku,
            'description' => $description,
            'norms' => $norms,
            'enrichment_payload' => json_encode(['attributes' => $attrs, ...$payload], JSON_UNESCAPED_UNICODE),
        ]);
        // jak po zapisie karty: ppe_family przeliczone obecnym kodem (Product::saving → ProductSearchBlob)
        $product->setAttribute('ppe_family', $this->assortment->productFamily($product));

        return $product;
    }

    private function cobaMatDfl(): Product
    {
        return $this->card(
            'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)',
            'AF060001',
            'Mata przeciwzmęczeniowa Orthomat Standard. Przetestowana ogniowo zgodnie z normą BS EN 13501-1 (klasa Dfl-s1), '
                .'pracuje w zakresie temperatur od 0°C do +60°C. Spodnia warstwa z pianki, podniesiona krawędź.',
            'BS EN 13501-1 klasa Dfl-s1',
            ['kategoria_bhp' => 'obuwie'],
        );
    }

    private function cobaMatCleaning(): Product
    {
        return $this->card(
            'Orthomat Dot Czarny/Żółte krawędzie 0.9m x 1.5m (9.5mm)',
            'AD010702',
            'Dzięki strukturze powierzchni typu „moneta” mata zwiększa przyczepność obuwia, zmniejszając ryzyko poślizgu. '
                .'Ułatwia czyszczenie obuwia przy wejściu.',
            'BS EN 13501-1 (odporność ogniowa)',
        );
    }

    public function test_coba_mat_gets_no_family_from_fire_class_or_shoe_cleaning(): void
    {
        $this->assertNull($this->cobaMatDfl()->ppe_family);
        $this->assertNull($this->cobaMatCleaning()->ppe_family);
        // Mata bez rzeczownika „mata” w nazwie („COBAscrape”) — samo „usuwa brud z obuwia” też nie czyni jej obuwiem.
        $this->assertNull($this->card(
            'COBAscrape Czarny 0.85m x 1.5m (6mm)',
            'CS010002',
            'Wycieraczka skutecznie usuwa brud i wilgoć z obuwia. Podniesiony wzór zdrapuje zanieczyszczenia z podeszw.',
        )->ppe_family);
    }

    public function test_family_gate_rejects_mat_under_hearing_and_footwear_requirements(): void
    {
        foreach ([$this->cobaMatDfl(), $this->cobaMatCleaning()] as $mat) {
            $this->assertFalse($this->assortment->compatibleProduct('Nauszniki przeciwhałasowe EN 352-1', $mat));
            $this->assertFalse($this->assortment->compatibleProduct('Półbuty ochronne S1P', $mat));
            $this->assertFalse($this->assortment->compatibleProduct('Trzewiki ochronne S3', $mat));
        }
    }

    public function test_mat_attributes_are_outside_ppe(): void
    {
        $attrs = $this->normalizer->forProduct($this->cobaMatDfl());

        $this->assertSame('inne', $attrs['kategoria_bhp']);
        $this->assertNull($attrs['klasa_ochrony']);
        $this->assertNull($attrs['typ_wyrobu']);
        $this->assertNull($attrs['przeznaczenie']);
    }

    public function test_footwear_class_in_name_still_makes_footwear(): void
    {
        $this->assertSame(PpeAssortment::FAMILY_FOOTWEAR, $this->assortment->family('ARYEL 320 671460 S3L'));
        $this->assertSame(
            PpeAssortment::FAMILY_FOOTWEAR,
            $this->card('ARYEL 320 671460 S3L', 'ARYEL 320 671460 S3L', 'Półbut ochronny z noskiem kompozytowym.')->ppe_family,
        );
        // „Canis” skarpety: małe „mat.” to skrót materiału, nie mata.
        $this->assertFalse($this->assortment->namesFloorMat('Socks, white, mat. 100% cotton'));
        $this->assertFalse($this->assortment->namesFloorMat('Semi-mask 3M 7502 – size M – medium, for 2 changeable filters, soft silicone mat'));
        $this->assertTrue($this->assortment->namesFloorMat('COBATrack HD Mat Czarna 1.2m x 2.4m (15mm)'));
        $this->assertTrue($this->assortment->namesFloorMat('Clean-Step Niebieski 0.6m x 0.76m - mata 60-warstw'));
    }

    public function test_trouser_braces_are_not_fall_arrest_harness(): void
    {
        $braces = $this->card(
            'Braces CXS DARREN, black, printing CXS',
            '1900-001-800-00',
            'Szelki CXS Darren to elastyczne szelki przeznaczone do podtrzymywania spodni, szczególnie w odzieży roboczej i mundurowej.',
            null,
            ['kategoria_bhp' => 'inne', 'typ_wyrobu' => 'harness'],
        );

        $this->assertNotSame(PpeAssortment::FAMILY_FALL, $braces->ppe_family);
        $this->assertFalse($this->assortment->compatibleProduct('Szelki bezpieczeństwa EN 361 z dwoma punktami zaczepienia', $braces));
        // prawdziwa uprząż zostaje asekuracją
        $this->assertSame(PpeAssortment::FAMILY_FALL, $this->assortment->family('Szelki bezpieczeństwa EN 361 z dwoma punktami zaczepienia'));
    }

    public function test_hyflex_sleeves_are_arm_sleeves_not_gloves(): void
    {
        foreach (['HyFlex 11250 NARROW NO THUMB S', 'HyFlex 11251 " thumbslot Narrow', 'HYFLEX 11281 THUMBSLOT WIDE', 'HYFLEX 70114'] as $name) {
            $this->assertTrue($this->assortment->isArmSleeve($name), $name);
        }
        $this->assertFalse($this->assortment->isArmSleeve('HyFlex 11-800 rękawice'));

        $sleeve = $this->card(
            'HyFlex 11251 " thumbslot Narrow',
            '11251120-N',
            'Rękawice ochronne HyFlex 11-251 firmy Ansell to lekka, dzianinowa rękawica przeznaczona do prac wymagających ochrony przed przecięciami.',
            'ANSI/ISEA 105-2024 CUT A3, EN ISO B (odporność na przecięcie)',
            ['kategoria_bhp' => 'rekawice'],
        );
        $this->assertFalse($this->assortment->compatibleProduct('Rękawice ochronne antyprzecięciowe EN 388 poziom C', $sleeve));

        // HyFlex 11-281 z kategorią „odziez” od modelu — rękaw trafia do rękawic, gdzie działa bramka rękawa.
        $this->assertSame(PpeAssortment::FAMILY_GLOVES, $this->card(
            'HYFLEX 11281 THUMBSLOT WIDE',
            '11281120-W',
            'Rękaw ochronny na ramię HyFlex 11-281 z otworem na kciuk, chroni przed przecięciami (EN 388).',
            null,
            ['kategoria_bhp' => 'odziez'],
        )->ppe_family);
    }

    public function test_resuscitation_mask_is_not_respiratory_protection(): void
    {
        $mask = $this->card(
            'Maska oddechowa Cederroth',
            '1921',
            'Maska oddechowa Cederroth to jednorazowe narzędzie do bezpiecznego prowadzenia resuscytacji metodą usta-usta.',
            null,
            ['kategoria_bhp' => 'drogi_oddechowe', 'typ_wyrobu' => 'ffp'],
        );
        $attrs = $this->normalizer->forProduct($mask);

        $this->assertNotSame('drogi_oddechowe', $attrs['kategoria_bhp']);
        $this->assertNotSame('ffp', $attrs['typ_wyrobu']);
        $this->assertNull($mask->ppe_family);
        $this->assertFalse($this->assortment->compatibleProduct('Półmaska filtrująca FFP2 z zaworem', $mask));
        // półmaska z rzeczownikiem rodziny nie wpada tu przez wzmiankę o pierwszej pomocy
        $this->assertFalse($this->assortment->isResuscitationMask('Półmaska filtrująca FFP2', 'Do apteczek i szkoleń z resuscytacji.'));
    }

    public function test_chemical_glove_for_pharmaceutical_industry_is_not_agriculture(): void
    {
        $glove = $this->card(
            'ALTO 298',
            '34298158',
            'Sprawdzą się w przemyśle farmaceutycznym (serwisowanie w mokrym środowisku) oraz w przemyśle mechanicznym przy pracach z wodą, olejami i smarami.',
            null,
            ['kategoria_bhp' => 'rekawice', 'przeznaczenie' => 'agriculture'],
            ['use_cases' => ['Przemysł farmaceutyczny – serwisowanie w mokrym środowisku', 'Ochrona przed substancjami chemicznymi i mikroorganizmami']],
        );

        $this->assertSame('chemical', $this->normalizer->forProduct($glove)['przeznaczenie']);
        $this->assertNull($this->assortment->purpose('Rękawice do rolnictwa, przemysłu spożywczego i gastronomii'));
        $this->assertSame('chemical', $this->assortment->purpose('Rękawice chemiczne do oprysków w rolnictwie'));
        $this->assertSame('agriculture', $this->assortment->purpose('Gumowce do gospodarstwa i na farmę'));
    }

    public function test_footwear_with_reflective_detail_is_not_hivis(): void
    {
        $this->assertNull($this->assortment->purpose('Trzewiki S3 z odblaskowym elementem na pięcie', PpeAssortment::FAMILY_FOOTWEAR));
        $this->assertSame('hivis', $this->assortment->purpose('Trzewiki ostrzegawcze EN ISO 20471', PpeAssortment::FAMILY_FOOTWEAR));
    }

    public function test_leather_ankle_footwear_with_rubber_sole_is_trzewik(): void
    {
        $boot = $this->card(
            'Ankle leather footwear with steel toe cap S2, waterproof leather upper, PU-PU, o',
            '2117-001-800-00',
            'Skórzane trzewiki robocze CXS STONE MARBLE S2 SRC. Anatomiczna zelówka oraz gumowa podeszwa i gumowy nadlew na czubku.',
            'EN ISO 20345:2011 S2 SRC, EN ISO 20344:2011',
            ['kategoria_bhp' => 'obuwie', 'typ_wyrobu' => 'kalosz'],
        );

        $this->assertSame(PpeAssortment::TYPE_TRZEWIK, $this->normalizer->forProduct($boot)['typ_wyrobu']);
        $this->assertSame(PpeAssortment::TYPE_TRZEWIK, $this->assortment->articleType('Ankle leather footwear', PpeAssortment::FAMILY_FOOTWEAR));
        // „gumowa podeszwa” w opisie skórzanego buta to nie kalosz; gumowe buty bez skóry i podeszwy w tekście — tak
        $this->assertNotSame(PpeAssortment::TYPE_KALOSZ, $this->assortment->articleType('Buty skórzane z gumową podeszwą', PpeAssortment::FAMILY_FOOTWEAR));
        $this->assertSame(PpeAssortment::TYPE_KALOSZ, $this->assortment->articleType('Buty gumowe robocze', PpeAssortment::FAMILY_FOOTWEAR));
    }

    public function test_single_size_from_name_beats_range_from_description(): void
    {
        $glove = $this->card(
            'TouchNTuff 92600 SIZE XXL (10.5-11.0)',
            '92600110',
            'Rękawice jednorazowe TouchNTuff 92-600. DOSTĘPNE ROZMIARY XS, S, M, L, XL, XXL.',
            null,
            ['kategoria_bhp' => 'rekawice'],
        );
        $this->assertSame('xxl', $this->normalizer->forProduct($glove)['rozmiar']);

        $sizes = new ProductSizeVariant;
        $this->assertSame('9', $sizes->labelFromTexts(null, 'Rozmiary: 6-11', 'rekawice', 'Rukavice KASA, textilní, bílé, blistr, vel. 9'));
        $this->assertSame('xxxl', $sizes->labelFromTexts(null, 'Rozmiary: S-XXXL', 'odziez', '4000-GR TROUSER 301.3XL'));
        // zakres w nazwie nie jest pojedynczym rozmiarem — zostaje odczyt z tekstu
        $this->assertSame('s-xxxl', $sizes->labelFromTexts(null, 'Rozmiary: S-XXXL', 'odziez', 'Jacket CXS SOLIS FLEX, ladies, red-black, size S - 3XL'));
        $this->assertNull($sizes->labelFromTexts(null, '', 'odziez', 'Men´s jacket SIRIUS, grey-orange, sizes 46-64'));
        // ucięta nazwa Ansella: końcowe „S” to „SLOT”, nie rozmiar
        $this->assertNull($sizes->labelFromTexts(null, '', 'rekawice', 'HyFlex 11250 NARROW NO THUMB S'));
    }
}
