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

    /** Karta #8645 z produkcji (04.10.2026): zapisany typ „ffp”, rodzina z opisu „dróg oddechowych”. */
    public function test_beard_cover_is_not_a_filtering_half_mask(): void
    {
        $beard = $this->card(
            'KLNGD A10 Beard Covers XL',
            '66816',
            'KleenGuard A10 Light Duty Beard Cover to jednorazowa osłona brody przeznaczona do podstawowej ochrony przed '
                .'szkodliwym pyłem oraz zanieczyszczeniami w miejscu pracy. Idealny do codziennego użytku w warunkach '
                .'wymagających podstawowej ochrony dróg oddechowych i higieny osobistej.',
            null,
            ['kategoria_bhp' => 'inne', 'typ_wyrobu' => 'ffp'],
            ['use_cases' => ['Przetwórstwo żywności', 'Ochrona przed pyłem i zanieczyszczeniami'], 'features' => ['Jednorazowa osłona brody w rozmiarze XL']],
        );
        $attrs = $this->normalizer->forProduct($beard);

        $this->assertNull($attrs['typ_wyrobu']);
        $this->assertSame('inne', $attrs['kategoria_bhp']);
        $this->assertNull($beard->ppe_family);
        $this->assertFalse($this->assortment->compatibleProduct('Półmaska filtrująca FFP2 z zaworem', $beard));
        $this->assertTrue($this->assortment->isBeardCover('Osłona na brodę z polipropylenu, op. 100 szt.'));
        // półmaska z osłoną brody zostaje półmaską
        $this->assertFalse($this->assortment->isBeardCover('Półmaska FFP2 z osłoną brody'));
    }

    /** Karta #9045 z produkcji (04.10.2026): zapisany typ „apparatus”, a aparaty SCBA to tylko to, do czego przelotka pasuje. */
    public function test_breathing_air_passthrough_compatible_with_scba_is_apparatus_part(): void
    {
        $passthrough = $this->card(
            'AVNT PASSTHRU WHSTL NO CONNECT',
            'AC01P-00022-00-N00',
            'Węże przyłączeniowe AlphaTec™ Connection Hoses są odporne na ścieranie, antystatyczne i zapewniają dobrą '
                .'odporność chemiczną. Oferują różne złącza/końcówki, aby pasowały do różnych marek i modeli aparatów SCBA.',
            null,
            ['kategoria_bhp' => 'drogi_oddechowe', 'typ_wyrobu' => 'apparatus'],
            ['specs' => ['Typ: przepust powietrza (airline passthrough)', 'Kompatybilność: wszystkie aparaty SCBA', 'Normy: EN 943-1, EN 943-2']],
        );

        $this->assertSame('apparatus_part', $this->normalizer->forProduct($passthrough)['typ_wyrobu']);
        $this->assertSame('apparatus_part', $this->assortment->articleType('Węże przyłączeniowe pasujące do aparatów SCBA', PpeAssortment::FAMILY_RESPIRATORY));
        $this->assertSame('apparatus_part', $this->assortment->articleType('Wąż sprężonego powietrza do aparatów powietrznych', PpeAssortment::FAMILY_RESPIRATORY));
        // aparat nazwany pierwszy zostaje aparatem, choćby dalej był „kompatybilny z” maskami
        $this->assertSame('apparatus', $this->assortment->articleType('Aparat powietrzny butlowy kompatybilny z maskami pełnotwarzowymi', PpeAssortment::FAMILY_RESPIRATORY));
    }

    /** Karty z backfillu 04.10.2026: akcesorium aparatu, półmaski albo systemu z wymuszonym przepływem dostawało typ wyrobu, do którego pasuje. */
    public function test_respiratory_accessories_have_no_article_type(): void
    {
        $msaPart = 'Aparaty oddechowe na sprężone powietrze > Części i akcesoria do aparatów oddechowych';
        $scbaText = 'MSA SCBA Accessories support and enhance the protection grade of your equipment, increase your comfort.';
        $cards = [
            // #66404
            [$this->card('Wężowe aparaty powietrzne – Skórzany pasek biodrowy', 'D3043918',
                'Wężowe aparaty oddechowe sprężonego powietrza są niezależne od otaczającej atmosfery.'),
                'Aparaty oddechowe na sprężone powietrze > Wężowe aparaty oddechowe na sprężone powietrze'],
            // #66439
            [$this->card('SCBA Accessories – Halte-Fix AE/ESA/N alpha B, Demand Valve Holder for SCBA, accessory', '10078512', $scbaText), $msaPart],
            // #66447
            [$this->card('SCBA Accessories – RFID holder for steel cylinders, Ø 26-30 mm, pack of 10', '10146101', $scbaText), $msaPart],
            // #67507
            [$this->card('Torba dla Grupy Szybkiego Reagowania – Torba, wersja SL-Q', '10104598',
                'Torba jest noszona przez strażaka i dostarcza powietrze osobom poszkodowanym. Jest zaopatrzona w system '
                    .'pneumatyczny, który jest podłączony do butli.'), $msaPart],
            // #66613
            [$this->card('SingleLine SCOUT Integrated Monitoring Unit – SLS RFID Kit', '10189001',
                'Podłączenie urządzenia do aparatu oddechowego strażackiego jest bardzo łatwe.'),
                'Aparaty oddechowe na sprężone powietrze > Systemy monitorujące'],
            // #66222
            [$this->card('SavOx – Pouch antistatic SavOx Industr(p. of 3)', '10120086',
                'SavOx jest zapakowany próżniowo, w nierdzewny i stalowy pojemnik.'),
                'Aparaty oddechowe na sprężone powietrze > Urządzenia ucieczkowe z masą tlenotwórczą'],
            // #40372
            [$this->card('3M™ Łatwoczyszczący pas, TR-627', '7100222674',
                'Pasy i zespoły pasów do systemów z wymuszonym przepływem powietrza 3M™. Projektanci zadbali o to, aby aparat '
                    .'można było wygodnie nosić przez cały dzień, korzystając z aparatu oddechowego.'), 'Ochrona układu oddechowego'],
            // #40414
            [$this->card('Etui do przechowywania 3M™ PV-938 systemu z wymuszonym przepływem powietrza PV-300E, 1 szt./opakowanie', '7100265303',
                'Torby, futerały i woreczki do przechowywania systemów z wymuszonym przepływem powietrza 3M™ to wygodne '
                    .'rozwiązanie do przechowywania i transportu aparatów oddechowych z wymuszonym przepływem powietrza.'), ''],
            // #260 — dawniej „fullface”
            [$this->card('Walizka transportowa 3M™ 108, po 2 sztuki na walizkę', '108',
                'Walizka transportowa 3M™ 108 to praktyczne etui przeznaczone do bezpiecznego przechowywania i przenoszenia '
                    .'wielorazowych masek oddechowych 3M, w tym półmasek i masek pełnotwarzowych.'), 'Środki ochrony indywidualnej'],
            // #175 — dawniej „ffp”
            [$this->card('Pokrywa zaworu wydechowego', 'S5621220',
                'Pokrywa zaworu wydechowego to element konstrukcyjny przeznaczony do półmasek ochronnych Secura 3000.', null,
                ['kategoria_bhp' => 'drogi_oddechowe'], ['use_cases' => ['Wymiana elementu w półmaskach przeciwpyłowych']]), ''],
            // #40978 — dawniej „ffp”
            [$this->card('Roztwór do testowania dopasowania 3M™, słodki, 55 ml, FT-12', '7100335089',
                'Produktów można używać do sprawdzania szczelności między twarzą a maską na dowolnej jednorazowej masce lub '
                    .'półmasce, w ramach programu ochrony dróg oddechowych.', null, ['kategoria_bhp' => 'drogi_oddechowe']), ''],
        ];
        foreach ($cards as [$card, $category]) {
            $card->setAttribute('category', $category);
            $this->assertNull($this->normalizer->forProduct($card)['typ_wyrobu'], (string) $card->name);
        }

        // akcesorium jako wyposażenie kompletu nie robi z kompletu akcesorium
        $this->assertFalse($this->assortment->namesRespiratoryAccessory('Aparat powietrzny AirMaXX z pasem biodrowym i torbą'));
        $this->assertFalse($this->assortment->namesRespiratoryAccessory('Noszak aparatu z pasem biodrowym'));
        $this->assertFalse($this->assortment->namesRespiratoryAccessory('Półmaska 3M 7502 w etui'));
        $this->assertTrue($this->assortment->namesRespiratoryAccessory('Pas biodrowy do aparatu powietrznego'));
        $this->assertTrue($this->assortment->namesRespiratoryAccessory('Adapter do ilościowych testów dopasowania 3M™ Secure Click™, FF-800-06'));
        // linia akcesoriów (#67253, #67244): bateria i ładowarka OptimAir dostawały „fullface” z opisu
        $battery = $this->card('OptimAir® 3000 Accessories – OptiBat E, OptimAir 3000, long duration battery', '10022473',
            'Akumulator do aparatu oczyszczającego OptimAir 3000 z maską pełnotwarzową 3S.', null, ['kategoria_bhp' => 'drogi_oddechowe']);
        $this->assertNull($this->normalizer->forProduct($battery)['typ_wyrobu']);
        // …ale maska sprzedawana w linii akcesoriów zostaje maską; maska „do” której część pasuje — nie (#59678)
        $this->assertFalse($this->assortment->namesRespiratoryAccessory('3S Accessories – 3S maska pełnotwarzowa, EPDM'));
        $this->assertTrue($this->assortment->namesRespiratoryAccessory('Opti-Fit Accessories 1715070 – OPTIFIT i PANORAMASQUE: Klaps do maski wewnętrznej'));
        $this->assertTrue($this->assortment->namesRespiratoryAccessory('SCBA Accessories – Rescue Handle for SCBA, Pack Of 4, accessory'));
        $this->assertTrue($this->assortment->namesRespiratoryAccessory('Nebulizator 3M™, FT-13'));
        $apparatus = $this->card('Aparat powietrzny AirMaXX z pasem biodrowym', 'AMX-1', 'Butlowy aparat powietrzny z noszakiem.',
            null, ['kategoria_bhp' => 'drogi_oddechowe']);
        $this->assertSame('apparatus', $this->normalizer->forProduct($apparatus)['typ_wyrobu']);
    }

    /**
     * Karty MSA i Honeywell „Linia – Wyrób” z produkcji (04.10.2026): nazwa linii, dział ścieżki kategorii i opis linii
     * mówią o aparacie, a typ ma wynikać z członu wyrobu i liścia ścieżki.
     */
    public function test_line_named_respiratory_cards_take_type_from_item(): void
    {
        $hoseLine = 'Aparaty oddechowe na sprężone powietrze > Wężowe aparaty oddechowe na sprężone powietrze';
        $hoseText = 'Wężowe aparaty oddechowe sprężonego powietrza są niezależne od otaczającej atmosfery.';
        $parts = 'Aparaty oddechowe na sprężone powietrze > Części i akcesoria do aparatów oddechowych';
        $cases = [
            // #66378, #66407, #66395 — część linii wężowej
            ['Wężowe aparaty powietrzne – Reduktor ciśnienia', 'D4075911', $hoseText, $hoseLine, 'apparatus_part'],
            ['Wężowe aparaty powietrzne – Wąż sprężonego powietrza,10 m', 'D4075914', $hoseText, $hoseLine, 'apparatus_part'],
            ['Wężowe aparaty powietrzne – Automatyczny zawór przełączający', 'D4075940', $hoseText, $hoseLine, 'apparatus_part'],
            // #66373 — wkład oczyszczający to filtr
            ['Wężowe aparaty powietrzne – Wkład oczyszczający AB/St węża powietrza', 'D4075915', $hoseText, $hoseLine, 'filter'],
            // #66166 — automat oddechowy z linii AutoMaXX, liść „Części i akcesoria do aparatów”
            ['AutoMaXX – AutoMaXX-AS', '10023866', 'Automat oddechowy AutoMaXX do aparatów powietrznych MSA.', $parts, 'apparatus_part'],
            // #66272 — oprogramowanie, liść bez typu, aparat tylko w dziale ścieżki i opisie
            ['MSA A2 Software – alphaCONTROL 2 - Oprogramowanie', '10111111', 'Oprogramowanie do aparatów oddechowych MSA.',
                'Aparaty oddechowe na sprężone powietrze > A2 Software', 'apparatus_part'],
            // #59959 — człon wyrobu nazywa maskę pełnotwarzową
            ['Fenzy Aeris Mini Self Contained Breathing Apparatus 1728753 – PANO Rd40 CL2: Pełnotwarzowa maska', '1728753',
                'Aparat oddechowy Fenzy Aeris Mini.', 'Respiratory › Self Contained Breathing Apparatus (Scba)', 'fullface'],
            // #67554 — urządzenie ucieczkowe nazwane samym modelem zostaje aparatem
            ['SSR 90 (K 60) – SSR 90 (K 60)', '10011111', 'Aparat ucieczkowy z masą tlenotwórczą, czas ochrony 60 minut.',
                'Aparaty oddechowe na sprężone powietrze > Urządzenia ucieczkowe z masą tlenotwórczą', 'apparatus'],
            // #58722 — aparat w członie wyrobu
            ['Airvisor 2 MV 1013935 – AIRVISOR II: Aparat oddechowy zasilany sprężonym powietrzem z linii', '1013935',
                'Aparat oddechowy zasilany sprężonym powietrzem.', 'Respiratory › Supplied Air Respirators (Sar)', 'apparatus'],
            // #58634 — goła „półmaska” w członie wyrobu nie przebija FFP1 z prefiksu
            ['5185 FFP1 NR D (SELF-SERVICE PPE) 1030343 – 5185 - Półmaska bez zaworu: opakowanie 20 szt.', '1030343',
                'Półmaska filtrująca FFP1 NR D.', 'Respiratory › Disposable Respirators', 'ffp'],
            // #64633 — liść ścieżki „Półmaski”
            ['Advantage® 200 LS – Advantage 200 LS, mały', '430357', 'O respirador semifacial Advantage 200LS.',
                'Sprzęt filtrujący (APR) > Półmaski', 'reusable_half'],
            // #67471, #67534 — angielski rzeczownik akcesorium na końcu frazy
            ['Storage and Transportation – SCBA wall box Type A', '10040000', 'Skrzynka ścienna na aparat SCBA.', $parts, null],
            ['Osłona na butle sprężone powietrze – SCBA Cylinder Cover Basic, 6-6.9l, Black', '10150000',
                'Osłona butli aparatu.', $parts, null],
        ];
        foreach ($cases as [$name, $sku, $description, $category, $expected]) {
            $card = $this->card($name, $sku, $description, null, ['kategoria_bhp' => 'drogi_oddechowe']);
            $card->setAttribute('category', $category);
            $this->assertSame($expected, $this->normalizer->forProduct($card)['typ_wyrobu'], $name);
        }

        // „Box of 10” to opakowanie (#19420), nie akcesorium
        $this->assertFalse($this->assortment->namesRespiratoryAccessory('SpringFit™ FFP3 431ML - Box of 10 - Individually Wrapped'));
        $this->assertTrue($this->assortment->namesRespiratoryAccessory('Half Mask Storage Bag Portwest B940'));
    }

    /**
     * #65946 i #66029 z produkcji (backfill 04.10.2026): „O2” w nazwie detektora gazu to tlen. Z niego szła kategoria
     * „obuwie”, a kolejne przeliczenie czytało ją jak kategorię od modelu i dopisywało klasę obuwia „O2”.
     */
    public function test_oxygen_in_gas_detector_name_is_not_footwear_class(): void
    {
        foreach ([
            ['Detektor jednogazowy ALTAIR – ALTAIR O2 19,5/23 Vol%', '10092523', 'Mierniki przenośne > Jedno lub dwugazowe',
                'Detektor jednogazowy ALTAIR został zaprojektowany z myślą o żywotności. Jest wyposażony w opcje czujników '
                    .'tlenku węgla, siarkowodoru i tlenu w połączeniu z alarmami diodowymi/dźwiękowymi/wibracyjnymi.'],
            ['Detektor gazu ALTAIR 5X – Kolorowy, LEL PEN , O2 , CO , H2S , 0-10% CO2 , EUROPA', '10119615', 'Mierniki przenośne > Wielogazowe',
                'Detektor gazu ALTAIR 5X wykrywa jednocześnie nawet 6 gazów i jest dostępny ze zintegrowanym czujnikiem PID. '
                    .'Duże przyciski pozwalają na obsługę w rękawicach ochronnych.'],
        ] as [$name, $sku, $category, $description]) {
            // stan po backfillu: zapisane kategoria „obuwie” i klasa „O2”
            $detector = $this->card($name, $sku, $description, null, ['kategoria_bhp' => 'obuwie', 'klasa_ochrony' => 'O2']);
            $detector->setAttribute('category', $category);
            $attrs = $this->normalizer->forProduct($detector);

            $this->assertSame('inne', $attrs['kategoria_bhp'], $name);
            $this->assertNull($attrs['klasa_ochrony'], $name);
            $this->assertNull($attrs['typ_wyrobu'], $name);
            // rodzina po zapisie przeliczonych atrybutów (backfill → Product::saving); opis ALTAIR 5X wspomina rękawice
            $detector->enrichment_payload = ['attributes' => $attrs];
            $this->assertNull($this->assortment->productFamily($detector), $name);
        }
        $this->assertTrue($this->assortment->namesGasDevice('Gaz do kalibracji – Calibration Testing Gas, Gas can 34L, 20ppm H2S, 60ppm CO, CH4, 2.5% CO2, 15% O2'));
        $this->assertFalse($this->assortment->namesGasDevice('Półmaska 3M 6200 z detektorem końca żywotności'));
        // #37687: wyrób ŚOI wykrywalny przez detektor metalu
        $this->assertFalse($this->assortment->namesGasDevice('Zarękawki foliowe wykrywalne przez detektor metalu RFOL-DETECT.'));

        // ochraniacze na obuwie (#9171) zostają przy kategorii obuwia od modelu
        $overshoes = $this->card('2000-WH STD OVERSHOES 400.42-46', '400.42-46', 'Ochraniacze jednorazowe z polipropylenu.', null, ['kategoria_bhp' => 'obuwie']);
        $this->assertSame('obuwie', $this->normalizer->forProduct($overshoes)['kategoria_bhp']);
        $this->assertNotSame(PpeAssortment::FAMILY_FOOTWEAR, $this->assortment->family('Detektor wielogazowy O2, CO, H2S, LEL'));

        // but z samą klasą w nazwie zostaje obuwiem, także z zapisaną kategorią
        $this->assertSame(PpeAssortment::FAMILY_FOOTWEAR, $this->assortment->family('ARYEL 320 671460 O2 FO SRC'));
        $shoe = $this->card('ARYEL 320 671460 S3L', '671460', 'Lekki model z kompozytowym podnoskiem.', null, ['kategoria_bhp' => 'obuwie']);
        $attrs = $this->normalizer->forProduct($shoe);
        $this->assertSame('obuwie', $attrs['kategoria_bhp']);
        $this->assertSame('S3L', $attrs['klasa_ochrony']);
    }

    /** Części 3M Scott i Versaflo bez „Linia – Wyrób” (produkcja 04.10.2026): aparat po rzeczowniku części to aparat, do którego część należy. */
    public function test_3m_scott_parts_and_papr_systems(): void
    {
        $respiratory = PpeAssortment::FAMILY_RESPIRATORY;
        // #40186, #40262, #40337, #40342, #40354
        foreach ([
            'Blok i O-Ring zaworu nadmiarowego ciśnienia 3M™ Scott™ do autonomicznego butlowego aparatu ucieczkowego ELSA, 1029995/061.335.97',
            'Śruba regulacji przepływu 3M™ Scott™ autonomiczny butlowy aparat ucieczkowy ELSA 2000, 1021711/030.256.99',
            'Zespół gwizdka 3M™ Scott™ 55 barów do zestawu SCBA, zielony, 1023232/035.091.95',
            'Zestaw serwisowy 3M™ Scott™ na 5 lat do autonomicznego butlowego aparatu ucieczkowego ELSA, 2002408',
            'Automat dawkujący i wąż 3M™ Scott™ SCBA Tempest, 1029102/060.300.99',
        ] as $name) {
            $this->assertSame('apparatus_part', $this->assortment->articleType($name, $respiratory), $name);
        }
        // kompletne aparaty zostają aparatami (#40572, #40142)
        $this->assertSame('apparatus', $this->assortment->articleType('Aparat wężowy sprężonego powietrza 3M™ Versaflo™, V-500E', $respiratory));
        $this->assertSame('apparatus', $this->assortment->articleType(
            'Autonomiczny butlowy aparat ucieczkowy 3M™ Scott™ ELSA Muster z maską, 15 minut, 200 barów, stal, złącze CEN', $respiratory));
        // zawór półmaski — bez aparatu w tekście reguła części aparatu nie działa
        $this->assertNotSame('apparatus_part', $this->assortment->articleType('Zawór wydechowy do półmaski 3M 6000', $respiratory));

        // #40192, #40526 — akcesoria bez typu
        $this->assertTrue($this->assortment->namesRespiratoryAccessory('Formowane opakowanie transportowe na autonomiczny aparat powietrzny 3M™ Scott™, 2014810'));
        $this->assertTrue($this->assortment->namesRespiratoryAccessory('3M™ Versaflo™ Zestaw do czyszczenia i przechowywania, TR-653'));

        // PAPR: system nazwany w nazwie to „papr” (#40531, #40564), system po „do / w / z” to to, do czego część pasuje
        $this->assertSame('papr', $this->assortment->articleType('Zestaw startowy systemu z wymuszonym przepływem powietrza Versaflo™ 3M™, TR-315E+', $respiratory));
        $this->assertSame('papr', $this->assortment->articleType('3M™ System z wymuszonym przepływem powietrza, PF-602E-ASB', $respiratory));
        $this->assertNull($this->assortment->articleType('Zestaw części zamiennych 3M™ PF-940 do systemów z wymuszonym przepływem powietrza PF-600E', $respiratory));
        $this->assertNull($this->assortment->articleType('Wskaźnik przepływu powietrza w systemie z wymuszonym przepływem powietrza 3M™ Adflo™ 838020', $respiratory));
        // #40500 — PAPR tylko w tabelce dostawcy, nie w nazwie
        $indicator = $this->card('3M™ Versaflo™ Wskaźnik natężenia przepływu powietrza, TR-971', '7000002297', 'Wskaźnik do jednostek Versaflo.',
            null, ['kategoria_bhp' => 'drogi_oddechowe']);
        $indicator->setAttribute('shop_fields_summary', 'Rodzaj produktu: zestaw systemu z wymuszonym przepływem powietrza i aparaty wężowe sprężonego powietrza');
        $this->assertNull($this->normalizer->forProduct($indicator)['typ_wyrobu']);

        // #20963, #22356 — znaki SignProject rozpoznane po ścieżce kategorii
        foreach ([
            ['PA203 Aparatowy', 'PA203', 'Tablice informacyjne różne › Tablice informacyjne - oznaczenie pomieszczeń'],
            ['TP030 Self contained breathing apparatus', 'TP030', 'Znaki morskie › Postery'],
        ] as [$name, $sku, $category]) {
            $sign = $this->card($name, $sku, 'Znak informacyjny wskazujący miejsce aparatu oddechowego.', null, ['kategoria_bhp' => 'drogi_oddechowe']);
            $sign->setAttribute('category', $category);
            $attrs = $this->normalizer->forProduct($sign);
            $this->assertSame('inne', $attrs['kategoria_bhp'], $name);
            $this->assertNull($attrs['typ_wyrobu'], $name);
        }
        $this->assertFalse($this->assortment->isSafetySignCategory('Ochrona dróg oddechowych > Aparaty powietrzne'));
    }

    /** #14315 i #14357 z produkcji: znak BHP to oznakowanie, a nie aparat czy maska z rysunku. */
    public function test_safety_sign_is_outside_ppe(): void
    {
        foreach ([
            ['Znak BHP Stosuj aparat oddechowy 200x300mm F (M047/1)', 'IM/047/1/C1/F',
                'Znak BHP Stosuj aparat oddechowy to tablica informacyjna stosowana w miejscach, gdzie istnieje ryzyko '
                    .'wystąpienia niebezpiecznych substancji w powietrzu.'],
            ['Znak BHP Stosuj maskę przeciwpyłową 200x300mm P (M016/1)', 'IM/016/1/C1/P',
                'Tablica informacyjna stosowana w miejscach, gdzie występuje wysokie stężenie pyłu, mająca na celu ochronę '
                    .'dróg oddechowych pracowników.'],
        ] as [$name, $sku, $description]) {
            $sign = $this->card($name, $sku, $description, null, ['kategoria_bhp' => 'drogi_oddechowe']);
            $sign->setAttribute('category', '03/WGK - BHP');
            $attrs = $this->normalizer->forProduct($sign);

            $this->assertNull($attrs['typ_wyrobu'], $name);
            $this->assertSame('inne', $attrs['kategoria_bhp'], $name);
        }
        $this->assertTrue($this->assortment->namesSafetySign('TABLICA MATERIAŁY TOKSY. CHROŃ DROGI oddechowe'));
        $this->assertTrue($this->assortment->namesSafetySign('Naklejka – znak bezpieczeństwa Uwaga pies'));
        // #18069: arkusz naklejek z kodem przed nazwą, bez słowa „znak”
        $this->assertTrue($this->assortment->namesSafetySign('GJ016 Nakaz stosowania maski przeciwpyłowej - arkusz 12 naklejek'));
        $this->assertFalse($this->assortment->namesSafetySign('Półmaska 3M 6200 ze znakiem CE'));
    }

    /** #64633 i #59403 z produkcji: przymiotnik „filtrujący / jednorazowy” bez maski to nie FFP. */
    public function test_filtering_adjective_without_mask_noun_is_not_ffp(): void
    {
        // kategoria MSA „Sprzęt filtrujący (APR) > Półmaski” przy półmasce wielokrotnego użytku
        $advantage = $this->card('Advantage® 200 LS – Advantage 200 LS, mały', '430357',
            'O respirador semifacial Advantage 200LS foi desenvolvido para oferecer a melhor combinação entre maciez e ajuste.',
            null, ['kategoria_bhp' => 'drogi_oddechowe']);
        $advantage->setAttribute('category', 'Sprzęt filtrujący (APR) > Półmaski');
        $this->assertSame('reusable_half', $this->normalizer->forProduct($advantage)['typ_wyrobu']);

        // „jednorazowy wizjer” hełmu do śrutowania z doprowadzeniem powietrza
        $helmet = $this->card('Commander A133230-00 – COMMANDER: Hełm do śrutowania', 'A133230-00',
            'Rozwiązanie zapewniające bezpieczeństwo i komfort podczas śrutowania • przepływomierz: ze wskaźnikiem '
                .'zwiększającym bezpieczeństwo. • jednorazowy wizjer: chroniący główny wizjer. Norma EN 14594',
            null, ['kategoria_bhp' => 'drogi_oddechowe']);
        $helmet->setAttribute('category', 'Respiratory › Supplied Air Respirators (Sar)');
        $this->assertNotSame('ffp', $this->normalizer->forProduct($helmet)['typ_wyrobu']);

        $respiratory = PpeAssortment::FAMILY_RESPIRATORY;
        $this->assertSame('ffp', $this->assortment->articleType('Półmaska filtrująca FFP2 z zaworem', $respiratory));
        $this->assertSame('ffp', $this->assortment->articleType('Maska przeciwpyłowa jednorazowa', $respiratory));
        $this->assertSame('ffp', $this->assortment->articleType('Jednorazowa półmaska 3M 8710', $respiratory));
        $this->assertSame('ffp', $this->assortment->articleType('Półmaski filtrujące jednorazowego użytku', $respiratory));
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
