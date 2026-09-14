<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\PpeAssortment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PpeAssortmentTest extends TestCase
{
    private PpeAssortment $assortment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assortment = new PpeAssortment;
    }

    #[Test]
    #[DataProvider('familyCases')]
    public function detects_ppe_family(string $text, string $family): void
    {
        $this->assertSame($family, $this->assortment->family($text));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function familyCases(): array
    {
        return [
            ['KAMIZELKA ODBLASKOWA żółta SIATKOWA EN 20471', PpeAssortment::FAMILY_APPAREL],
            ['Osłona twarzy żaroodporna siatkowa', PpeAssortment::FAMILY_FACE],
            ['3M Osłona twarzy siatkowa V4B', PpeAssortment::FAMILY_FACE],
            ['Przyłbica spawalnicza', PpeAssortment::FAMILY_FACE],
            ['Okulary ochronne przyciemniane', PpeAssortment::FAMILY_EYES],
            ['Gogle chemiczne', PpeAssortment::FAMILY_EYES],
            ['Nauszniki przeciwhałasowe', PpeAssortment::FAMILY_HEARING],
            ['Ochronniki słuchu na hełm MSA - niski poziom tłumienia', PpeAssortment::FAMILY_HEARING],
            ['Półmaska 3M 6503 część twarzowa', PpeAssortment::FAMILY_RESPIRATORY],
            ['Pochłaniacz wielogazowy A2B2E2K2NO', PpeAssortment::FAMILY_RESPIRATORY],
            ['Filtropochłaniacz FP 211/1', PpeAssortment::FAMILY_RESPIRATORY],
            ['Hełm przemysłowy z osłoną', PpeAssortment::FAMILY_HEAD],
            ['Kominiarka antyelektrostatyczna', PpeAssortment::FAMILY_HEAD],
            ['Wkładka/czepek ocieplana pod hełm ESD EN 1149-5', PpeAssortment::FAMILY_HEAD],
            ['Szelki bezpieczeństwa z linką', PpeAssortment::FAMILY_FALL],
            ['RUP 502-U Ewakuacyjne urządzenie podnosząco-opuszczające PROTEKT', PpeAssortment::FAMILY_FALL],
            ['Nakolanniki żelowe', PpeAssortment::FAMILY_KNEE],
            ['Rękawice nitrylowe RNITZ', PpeAssortment::FAMILY_GLOVES],
            ['Trzewiki S3 ocieplane', PpeAssortment::FAMILY_FOOTWEAR],
            ['sztyblety O2', PpeAssortment::FAMILY_FOOTWEAR],
            ['mokasyny S2 non-metalic', PpeAssortment::FAMILY_FOOTWEAR],
            ['Półmaska filtrująca 9914', PpeAssortment::FAMILY_RESPIRATORY],
            ['3M 8822 FFP2', PpeAssortment::FAMILY_RESPIRATORY],
            ['Kurtka ochronna ocieplana z kapturem', PpeAssortment::FAMILY_APPAREL],
            ['POLA - EN 420 KAT. II, EN 388 - 3131', PpeAssortment::FAMILY_GLOVES],
            ['Kalesony bawełniane męskie', PpeAssortment::FAMILY_APPAREL],
            ['Fartuch laboratoryjny', PpeAssortment::FAMILY_APPAREL],
            ['podnie gramatura 250 gr', PpeAssortment::FAMILY_APPAREL],
            ['kamizelaka odblaskowa', PpeAssortment::FAMILY_APPAREL],
            // Canis/CXS: nazwy czeskie i angielskie — rzeczownik musi być znany, inaczej rodzina z kategorii importu
            ['Rukavice CERRO, máčené v nitrilu BLISTR, modro-šedé', PpeAssortment::FAMILY_GLOVES],
            ['Rukavice ANSELL EDGE ESD 48-140, blistr, vel. 8', PpeAssortment::FAMILY_GLOVES],
            ['3410-140-410-00 Rukavice CERRO, máčené v nitrilu', PpeAssortment::FAMILY_GLOVES],
            ['Gloves EDGE 48-140 ESD seamless polyester and carbon fiber, PU coating', PpeAssortment::FAMILY_GLOVES],
            ['Men´s jacket CXS SOLIS FLEX, blue-black, size 46 - 68', PpeAssortment::FAMILY_APPAREL],
            ['Men´s trousers CXS SOLIS FLEX, grey-black', PpeAssortment::FAMILY_APPAREL],
            ['Kalhoty do pasu CXS ORION TEODOR', PpeAssortment::FAMILY_APPAREL],
            ['Working T-shirt CXS DANIEL, white', PpeAssortment::FAMILY_APPAREL],
            ['Low ankle shoe CXS ROCK PYRIT S1P', PpeAssortment::FAMILY_FOOTWEAR],
            ['Polobotka CXS MARBLE O1', PpeAssortment::FAMILY_FOOTWEAR],
            ['Spectacles CXS SPYDER, smoke lens', PpeAssortment::FAMILY_EYES],
            ['Brýle ochranné CXS VISITOR', PpeAssortment::FAMILY_EYES],
            ['Ear muffs CXS EP101, SNR 27 dB', PpeAssortment::FAMILY_HEARING],
            ['Respirator FFP2 with valve, CXS', PpeAssortment::FAMILY_RESPIRATORY],
            ['Polomaska CXS 3000 s bajonetovým závitem', PpeAssortment::FAMILY_RESPIRATORY],
            ['Půlmaska EN 140', PpeAssortment::FAMILY_RESPIRATORY],
            ['Přilba ochranná CXS, bílá', PpeAssortment::FAMILY_HEAD],
            ['Pas monterski EN 358', PpeAssortment::FAMILY_FALL],
            ['Helmet liner winter, fleece', PpeAssortment::FAMILY_HEAD],
            // kombinezon Ansell z wgrzanymi skarpetami/butami — „CVRL” stoi przed „BOOTS”
            ['1800-WH TSPLUS CVRL HOOD BOOTS 122.5XL', PpeAssortment::FAMILY_APPAREL],
        ];
    }

    #[Test]
    #[DataProvider('incompatiblePairs')]
    public function rejects_different_ppe_kind(string $requirement, string $productName): void
    {
        $this->assertFalse($this->assortment->compatible($requirement, $productName));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function incompatiblePairs(): array
    {
        $vest = 'KAMIZELKA ODBLASKOWA żółta SIATKOWA z nadrukiem · EN 20471 kl. 1';

        return [
            [$vest, 'Osłona twarzy żaroodporna siatkowa'],
            [$vest, '3M™ Osłona twarzy siatkowa'],
            [$vest, 'Okulary ochronne żółte'],
            ['Półmaska 3M 6503', 'Osłona twarzy siatkowa'],
            ['Nauszniki przeciwhałasowe', 'Hełm przemysłowy'],
            ['Szelki bezpieczeństwa', 'Kamizelka odblaskowa'],
            [$vest, 'Kurtka robocza odblaskowa EN 20471'],
            ['KALESONY bawełniane męskie', 'Kombinezon ochronny blast suit'],
            ['FARTUCH laboratoryjny', 'Kamizelka odblaskowa'],
            ['Rękawice nitrylowe', 'Trzewiki S3'],
            ['Okulary ochronne przyciemniane', 'Rękawice lateksowe HYFLEX 11-541'],
            ['Kurtka przeciwdeszczowa EN 343 EN 1149', 'Półmaska SECURA 3000'],
            ['Rękawice lateksowe sterylne', 'Komplet przeciwdeszczowy B50 bluza + spodnie'],
            ['Rękawice BORDER', 'Kalesony bawełniane męskie'],
            [
                'Ubranie ochronne dla elektryków (bluza + spodnie) EN 1149-5 IEC 61482',
                '103 - Kurtka wodoochronna zapinana na zamek',
            ],
            ['KURTKA DAMSKA - POLAR granatowy', 'POLA - EN 420 KAT. II, EN 388 - 3131'],
            [
                'Wkładka/czepek ocieplana pod hełm antyelektrostatyczna EN 1149-5',
                'Kurtka antyelektrostatyczna STATICGUARD',
            ],
            [
                'Wkładka/czepek ocieplana pod hełm antyelektrostatyczna EN 1149-5',
                'Hełm przemysłowy EN 397',
            ],
        ];
    }

    #[Test]
    public function accepts_same_vest(): void
    {
        $req = 'KAMIZELKA ODBLASKOWA żółta SIATKOWA · EN 20471 kl. 1';
        $this->assertTrue($this->assortment->compatible($req, 'Kamizelka ostrzegawcza żółta siatkowa'));
        $this->assertTrue($this->assortment->compatible($req, 'Kamizelka odblaskowa EN 20471'));
    }

    #[Test]
    public function jacket_with_hood_is_apparel_not_head(): void
    {
        $this->assertSame(
            PpeAssortment::FAMILY_APPAREL,
            $this->assortment->family('Kurtka ochronna ocieplana z odpinanym kapturem')
        );
    }

    #[Test]
    public function shared_adjective_siatkowa_does_not_classify_family(): void
    {
        $this->assertNull($this->assortment->family('żółta SIATKOWA z nadrukiem EN 20471'));
    }

    #[Test]
    public function helmet_adapter_allows_face_mount(): void
    {
        $adapter = new Product;
        $adapter->forceFill([
            'name' => '3M Adapter P3E do mocowania osłony twarzy',
            'sku' => 'P3E',
            'category' => 'Ochrona twarzy',
            'manufacturer' => '3M',
        ]);

        $this->assertTrue($this->assortment->compatibleProduct(
            'Adapter P3E do hełmu 3M',
            $adapter
        ));
        $this->assertFalse($this->assortment->compatibleProduct(
            'Hełm przemysłowy 3M',
            $adapter
        ));
    }

    #[Test]
    public function compatible_product_drops_face_shield_for_vest(): void
    {
        $shield = new Product;
        $shield->forceFill([
            'name' => 'Osłona twarzy żaroodporna siatkowa',
            'sku' => '12-0423',
            'category' => 'Ochrona twarzy',
            'description' => 'Siatkowa osłona twarzy ALWIT.',
        ]);

        $this->assertFalse($this->assortment->compatibleProduct(
            'KAMIZELKA ODBLASKOWA żółta SIATKOWA · EN 20471',
            $shield
        ));
    }

    #[Test]
    public function accessory_mentioned_in_description_does_not_move_product_to_another_family(): void
    {
        $trousers = new Product;
        $trousers->forceFill([
            'name' => 'CXS STRETCH',
            'sku' => 'CXS-STRETCH',
            'category' => 'Odzież robocza',
            'description' => 'Spodnie robocze męskie CXS STRETCH, gramatura 250 g/m². '
                .'Wyposażone w kieszenie na nakolanniki i wzmocnienia z poliestru 600D.',
        ]);

        $this->assertTrue($this->assortment->compatibleProduct('spodnie o gramaturze 250gr', $trousers));
    }

    #[Test]
    public function article_type_splits_wellington_from_welding_boot(): void
    {
        $this->assertSame(
            PpeAssortment::TYPE_KALOSZ,
            $this->assortment->articleType('DUNLOP 462933 PUROFORT kalosz S5', PpeAssortment::FAMILY_FOOTWEAR)
        );
        $this->assertSame(
            PpeAssortment::TYPE_TRZEWIK,
            $this->assortment->articleType('Trzewiki spawalnicze DEMAR 9-075', PpeAssortment::FAMILY_FOOTWEAR)
        );
        $this->assertSame(
            PpeAssortment::TYPE_SZTYBLET,
            $this->assortment->articleType('sztyblety O2', PpeAssortment::FAMILY_FOOTWEAR)
        );
        $this->assertSame(
            PpeAssortment::TYPE_POLBUT,
            $this->assortment->articleType('mokasyny S2 non-metalic', PpeAssortment::FAMILY_FOOTWEAR)
        );
        // angielskie i czeskie nazwy Canis/CXS
        $this->assertSame(PpeAssortment::TYPE_POLBUT, $this->assortment->articleType('Low perforated leather footwear, PU-PU, oil resistant', PpeAssortment::FAMILY_FOOTWEAR));
        $this->assertSame(PpeAssortment::TYPE_POLBUT, $this->assortment->articleType('Low shoe CXS ROCK PYRIT S1P', PpeAssortment::FAMILY_FOOTWEAR));
        $this->assertSame(PpeAssortment::TYPE_POLBUT, $this->assortment->articleType('Polobotka CXS MARBLE O1', PpeAssortment::FAMILY_FOOTWEAR));
        $this->assertSame(PpeAssortment::TYPE_TRZEWIK, $this->assortment->articleType('Ankle shoe CXS STONE TOPAZ S3 Winter', PpeAssortment::FAMILY_FOOTWEAR));
        $this->assertSame(PpeAssortment::TYPE_TRZEWIK, $this->assortment->articleType('Kotníková obuv CXS SAFETY STEEL S1', PpeAssortment::FAMILY_FOOTWEAR));
        $this->assertSame('agriculture', $this->assortment->purpose('Kalosz do rolnictwa i gospodarstw'));
        $this->assertSame('welding', $this->assortment->purpose('Trzewiki spawalnicze HRO'));
        $this->assertSame('ffp', $this->assortment->articleType('Półmaska filtrująca 9914 FFP1', PpeAssortment::FAMILY_RESPIRATORY));
        $this->assertSame('reusable_half', $this->assortment->articleType('Półmaska wielorazowa 6500 silikon', PpeAssortment::FAMILY_RESPIRATORY));
        $this->assertSame('goggles', $this->assortment->articleType('Gogle chemiczne', PpeAssortment::FAMILY_EYES));
        $this->assertSame('glasses', $this->assortment->articleType('Okulary ochronne', PpeAssortment::FAMILY_EYES));
        $this->assertSame('earmuff', $this->assortment->articleType('Nauszniki przeciwhałasowe', PpeAssortment::FAMILY_HEARING));
        $this->assertSame(
            'filter',
            $this->assortment->articleTypePreferIdentity(
                'Pochłaniacz 3031 A2',
                'Pochłaniacz do półmasek SECURA i masek pełnotwarzowych EN 14387',
                PpeAssortment::FAMILY_RESPIRATORY
            )
        );
        $this->assertSame(
            'fullface',
            $this->assortment->articleTypePreferIdentity(
                'Maska pełnotwarzowa 3M 6800',
                'Kompatybilna z filtrami serii 2000 oraz 500, pochłaniacze bagnetowe.',
                PpeAssortment::FAMILY_RESPIRATORY
            )
        );
    }

    #[Test]
    public function rubber_material_alone_does_not_make_a_wellington_of_a_mat(): void
    {
        // batch #298: DeckStep — „Materiał: guma (winyl)” dawało typ „kalosz”
        $this->assertNull($this->assortment->articleTypePreferIdentity(
            'DeckStep Matting Czarny ~0.59m/0.6m x 10m (11.5mm) DS0106',
            'SKU: DS0106 Wymiary: 0,59 m x 10 m Grubość: 11,5 mm Waga: 45 kg Materiał: guma (winyl)'
        ));
        // jawne słowo nadal wystarcza, także bez rozpoznanej rodziny
        $this->assertSame(PpeAssortment::TYPE_KALOSZ, $this->assortment->articleType('DUNLOP 462933 PUROFORT'));
    }

    #[Test]
    public function family_still_falls_back_to_description_when_name_says_nothing(): void
    {
        $gloves = new Product;
        $gloves->forceFill([
            'name' => 'TEMP-ICE 700',
            'sku' => '34700018',
            'category' => null,
            'description' => 'Rękawice zimowe odporne na kontakt z zimnem.',
        ]);

        $this->assertTrue($this->assortment->compatibleProduct('rękawice zimowe MAPA', $gloves));
    }

    #[Test]
    public function glove_requirement_rejects_arm_sleeve(): void
    {
        $sleeve = new Product;
        $sleeve->forceFill([
            'name' => 'Naramiennik MBCK 40 cm',
            'sku' => 'MBCK/40/P',
            'category' => 'Zarękawki antyprzecięciowe',
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'description' => 'Zarękawki para-aramid, ochrona 360 stopni, kontakt 250°C.',
        ]);

        $this->assertTrue($this->assortment->isArmSleeve((string) $sleeve->name));
        $this->assertFalse($this->assortment->compatibleProduct('rękawice do pracy przy 200 C', $sleeve));

        $glove = new Product;
        $glove->forceFill([
            'name' => 'Rękawice termoochronne 250',
            'sku' => 'HEAT-250',
            'category' => 'Rękawice',
        ]);
        $this->assertTrue($this->assortment->compatibleProduct('rękawice do pracy przy 200 C', $glove));

        $cuffs = new Product;
        $cuffs->forceFill([
            'name' => '35CM CUT-RESISTANT KNITTED CUFFS',
            'sku' => 'PRIMACUFF35PO',
            'category' => 'Rękawice',
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
        ]);
        $this->assertTrue($this->assortment->isArmSleeve((string) $cuffs->name));
        $this->assertFalse($this->assortment->compatibleProduct('rękawice dzianinowe', $cuffs));

        $withCuff = new Product;
        $withCuff->forceFill([
            'name' => 'Rękawice dzianinowe z mankietem safety cuff',
            'sku' => 'VE-CUFF',
            'category' => 'Rękawice',
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
        ]);
        $this->assertFalse($this->assortment->isArmSleeve((string) $withCuff->name));
        $this->assertTrue($this->assortment->compatibleProduct('rękawice dzianinowe', $withCuff));
    }

    #[Test]
    public function name_beats_stale_apparel_family_for_balaclava(): void
    {
        $cap = new Product;
        $cap->forceFill([
            'name' => 'KOMINIARKA Z POLARU POLIESTRU',
            'sku' => 'BALTIC',
            'category' => 'Odzież',
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
        ]);

        $this->assertSame(PpeAssortment::FAMILY_HEAD, $this->assortment->productFamily($cap));
        $this->assertTrue($this->assortment->compatibleProduct(
            'KOMINIARKA ANTYELEKTROSTATYCZNA EN 1149-5',
            $cap
        ));
        $this->assertSame(['kominiark', 'balaclava'], $this->assortment->catalogNounLikes(
            'KOMINIARKA ANTYELEKTROSTATYCZNA'
        ));
    }

    /**
     * Numer modelu w nazwie to nie numer normy: MAPA ULTRANITRIL 358 robiło z rękawic
     * asekurację (EN 358), TITAN 397 — hełm (EN 397), a „poly liner” taśmy — wkładkę pod kask.
     */
    #[Test]
    public function bare_model_number_or_liner_word_is_not_a_family(): void
    {
        $this->assertNull($this->assortment->family('ULTRANITRIL 358 - POLYBAG'));
        $this->assertNull($this->assortment->family('TITAN 397'));
        $this->assertNull($this->assortment->family('Tasma P7100B (poly liner), czarna, 1500 mm x 66 m'));
        $this->assertSame(PpeAssortment::FAMILY_FALL, $this->assortment->family('Szelki wg EN 358'));
    }

    /**
     * Poz. 3 przetargu opisowego: sandały S1 P dostawały AROX „S1 ESD” (klasa niższa) —
     * klasa z nazwy karty niższa niż wymagana to sprzeczność, wyższa (S3) spełnia S1 P.
     */
    #[Test]
    public function footwear_class_lower_than_required_is_rejected(): void
    {
        $req = 'Sandały ochronne (obuwie bezpieczne z odkrytą cholewką) kategorii S1 P wg EN ISO 20345, ESD';
        $s1 = new Product;
        $s1->forceFill(['name' => 'AROX 733 641460 S1 ESD', 'sku' => 'AROX 733 641460 S1 ESD', 'category' => 'Obuwie', 'description' => 'Sandały ochronne ESD klasy S1.']);
        $s1p = new Product;
        $s1p->forceFill(['name' => 'ARMEN 9007 6660 S1 P', 'sku' => 'ARMEN 9007 6660 S1 P', 'category' => 'Obuwie', 'description' => 'Sandały ochronne S1 P ESD z wkładką antyprzebiciową.']);
        $s3 = new Product;
        $s3->forceFill(['name' => 'Sandały ochronne ESD S3 SRC', 'sku' => 'S3-ESD', 'category' => 'Obuwie']);
        $unknown = new Product;
        $unknown->forceFill(['name' => 'Sandały ochronne ESD', 'sku' => 'X-1', 'category' => 'Obuwie']);

        $this->assertFalse($this->assortment->compatibleProduct($req, $s1));
        $this->assertTrue($this->assortment->compatibleProduct($req, $s1p));
        $this->assertTrue($this->assortment->compatibleProduct($req, $s3));
        $this->assertTrue($this->assortment->compatibleProduct($req, $unknown), 'brak klasy na karcie = brak wiedzy');
    }

    /**
     * Przetarg 1, poz. 3: sandały S1 P dostawały AROSERIO 750 618080 S3 ESD (trzewik bez typu w nazwie).
     * S2+/O2+ wymagają cholewki odpornej na wodę — odkryty sandał tej klasy nie ma, więc karta z taką
     * klasą, która sama nie nazywa się sandałem, to zakryte obuwie. Półbuty dalej przyjmują S3.
     */
    #[Test]
    public function sandal_requirement_rejects_closed_shoe_with_water_resistant_class(): void
    {
        $req = 'Sandały ochronne (obuwie bezpieczne z odkrytą cholewką) kategorii S1 P wg EN ISO 20345, do prac w suchych pomieszczeniach. Właściwości ESD.';
        $closed = $this->card('AROSERIO 750 618080 S3 ESD', 'AROSERIO 750 618080 S3 ESD', [
            'category' => 'Obuwie',
            'norms' => 'EN ISO 20345:2011 S3 SRC, EN IEC 61340-4-3:2018',
            'description' => 'Obuwie ochronne AROSERIO 750 618080 S3 ESD. Kontrola ładunków elektrostatycznych (ESD).',
        ]);
        $namedSandal = $this->card('S3-ESD', 'Sandały ochronne ESD S3 SRC', ['category' => 'Obuwie']);
        $s1p = $this->card('ARMEN 9007 6660 S1 P', 'ARMEN 9007 6660 S1 P', [
            'category' => 'Obuwie',
            'description' => 'Sandały robocze ARMEN S1 P z właściwościami antyelektrostatycznymi (ESD).',
        ]);

        $this->assertFalse($this->assortment->compatibleProduct($req, $closed), 'S3 bez typu sandała to zakryte obuwie');
        $this->assertTrue($this->assortment->compatibleProduct($req, $namedSandal), 'karta nazwana sandałem zostaje');
        $this->assertTrue($this->assortment->compatibleProduct($req, $s1p));
        $this->assertTrue(
            $this->assortment->compatibleProduct('Półbuty ochronne S1 P ESD', $closed),
            'bramka dotyczy tylko sandałów'
        );
    }

    /**
     * Poz. 14: pochłaniacz gazów klasy A2 dostawał filtr cząstek stałych P1 R — inna klasa
     * elementu oczyszczającego (EN 14387 vs EN 143); karta bez klas nadal przechodzi.
     */
    #[Test]
    public function gas_absorber_requirement_rejects_particle_filter(): void
    {
        $req = 'Pochłaniacz gazów i par klasy A2 – element oczyszczający do sprzętu ochrony układu oddechowego, złącze bagnetowe';
        $p1 = new Product;
        $p1->forceFill(['name' => 'Filtr cząstek stałych 3M™, P1 R, 5911', 'sku' => '5911B', 'category' => 'Środki ochrony indywidualnej']);
        $a2 = new Product;
        $a2->forceFill(['name' => 'Pochłaniacz 3031 A2', 'sku' => 'S565A202', 'category' => 'Pochłaniacze']);
        $a1 = new Product;
        $a1->forceFill(['name' => 'Pochłaniacz 3021 A1', 'sku' => 'S565A102', 'category' => 'Pochłaniacze']);
        $abek = new Product;
        $abek->forceFill(['name' => 'Pochłaniacz 3M 6059 A2B2E2K2', 'sku' => '6059', 'category' => 'Pochłaniacze']);
        $bare = new Product;
        $bare->forceFill(['name' => 'Pochłaniacz do półmaski, złącze bagnetowe', 'sku' => 'PX-1', 'category' => 'Pochłaniacze']);

        $this->assertFalse($this->assortment->compatibleProduct($req, $p1));
        $this->assertFalse($this->assortment->compatibleProduct($req, $a1), 'A1 nie spełnia A2');
        $this->assertTrue($this->assortment->compatibleProduct($req, $a2));
        $this->assertTrue($this->assortment->compatibleProduct($req, $abek), 'A2B2E2K2 obejmuje A2');
        $this->assertTrue($this->assortment->compatibleProduct($req, $bare), 'brak klas na karcie = brak wiedzy');
        $this->assertFalse($this->assortment->compatibleProduct('Filtr cząstek stałych P3 R do półmaski', $a2), 'pochłaniacz gazów to nie filtr P3');
    }

    #[Test]
    public function under_helmet_liner_accepts_cap_and_rejects_jacket(): void
    {
        $req = 'Wkładka/czepek ocieplana pod hełm antyelektrostatyczna z certyfikatem ESD EN 1149-5';
        $this->assertTrue($this->assortment->isUnderHelmetLiner($req));
        $this->assertTrue($this->assortment->compatible($req, 'Czepek ocieplany pod hełm ESD'));
        $this->assertTrue($this->assortment->compatible($req, 'Wkładka polarowa pod hełm'));
        $this->assertFalse($this->assortment->compatible($req, 'Kurtka antyelektrostatyczna STATICGUARD'));
    }

    #[Test]
    public function gallet_earmuffs_match_msa_helmet_requirement_but_hygiene_kit_does_not(): void
    {
        $req = 'Ochronniki słuchu na hełm MSA - niski poziom tłumienia';
        $earmuff = new Product;
        $earmuff->forceFill([
            'name' => 'Aktywne ochronniki słuchu do GALLET F1XF, kable podhełmowe, przyciski na czaszy',
            'sku' => 'GA010002D3X',
            'manufacturer' => 'MSA',
            'category' => 'Ochrona słuchu',
        ]);
        $kit = new Product;
        $kit->forceFill([
            'name' => 'Komplet higieniczny do left/RIGHT, niski st. tłumienia',
            'sku' => '10092878',
            'manufacturer' => 'MSA',
            'category' => 'Ochrona słuchu',
        ]);

        $this->assertSame(PpeAssortment::FAMILY_HEARING, $this->assortment->family((string) $earmuff->name));
        $this->assertNull($this->assortment->family((string) $kit->name));
        $this->assertTrue($this->assortment->isHearingHygieneKit((string) $kit->name));
        $this->assertFalse($this->assortment->isHearingHygieneKit((string) $earmuff->name));
        $this->assertTrue($this->assortment->compatibleProduct($req, $earmuff));
        $this->assertFalse($this->assortment->compatibleProduct($req, $kit));
        $this->assertFalse($this->assortment->isUnderHelmetLiner($req));
        $this->assertSame(PpeAssortment::MOUNT_HELMET, $this->assortment->hearingMount($req));
        $this->assertSame(PpeAssortment::MOUNT_HELMET, $this->assortment->hearingMount(
            '3M Nauszniki PELTOR X1 - wersja nahełmowa (SNR 27 dB)'
        ));
        $this->assertSame(PpeAssortment::MOUNT_HEADBAND, $this->assortment->hearingMount(
            '3M Nauszniki PELTOR X1 - wersja nagłowna (SNR 27 dB) Nauszniki do hełmu'
        ));
        $helmetMuff = new Product;
        $helmetMuff->forceFill([
            'name' => '3M Nauszniki PELTOR X1 - wersja nahełmowa (SNR 27 dB)',
            'sku' => 'X1P3E-EU',
            'category' => 'Nauszniki do hełmu',
        ]);
        $headband = new Product;
        $headband->forceFill([
            'name' => '3M Nauszniki PELTOR X1 - wersja nagłowna (SNR 27 dB)',
            'sku' => 'X1A-EU',
            'category' => 'Nauszniki przeciwhałasowe',
        ]);
        $helmQ = 'Nauszniki przeciwhałasowe montowane na hełm ochronny';
        $this->assertTrue($this->assortment->compatibleProduct($helmQ, $helmetMuff));
        $this->assertFalse($this->assortment->compatibleProduct($helmQ, $headband));

        $helmet = new Product;
        $helmet->forceFill([
            'name' => 'V-Gard 500',
            'sku' => 'VGARD-500',
            'manufacturer' => 'MSA',
            'category' => 'Ochrona głowy',
        ]);
        $this->assertFalse($this->assortment->compatibleProduct($req, $helmet));
    }

    #[Test]
    public function perspecta_requirement_rejects_etui_and_accepts_glasses(): void
    {
        $req = 'OKULARY OCHRONNE MSA PERSPECTA 010';
        $glasses = new Product;
        $glasses->forceFill([
            'name' => 'MSA PERSPECTA 010 - Okulary ochronne',
            'sku' => '10061279',
            'manufacturer' => 'MSA',
            'category' => 'Ochrona oczu',
        ]);
        $case = new Product;
        $case->forceFill([
            'name' => 'Etui na okulary ochronne MSA',
            'sku' => 'ETUI-MSA',
            'manufacturer' => 'MSA',
            'category' => 'Ochrona oczu',
        ]);

        $this->assertTrue($this->assortment->isEyeWearAccessory((string) $case->name));
        $this->assertFalse($this->assortment->isEyeWearAccessory((string) $glasses->name));
        $this->assertTrue($this->assortment->compatibleProduct($req, $glasses));
        $this->assertFalse($this->assortment->compatibleProduct($req, $case));
    }

    #[Test]
    public function glasses_plus_etui_accepts_both(): void
    {
        $req = 'Okulary ochronne HUBIX H049 + etui';
        $glasses = new Product;
        $glasses->forceFill([
            'name' => '3M Virtua AP Okulary ochronne',
            'sku' => '7100010692',
            'manufacturer' => '3M',
            'ppe_family' => PpeAssortment::FAMILY_EYES,
        ]);
        $case = new Product;
        $case->forceFill([
            'name' => 'Sztywne etui na okulary ochronne',
            'sku' => 'ETUI-1',
            'manufacturer' => 'HUBIX',
            'ppe_family' => PpeAssortment::FAMILY_EYES,
        ]);

        $this->assertTrue($this->assortment->isEyeWearSet($req));
        $this->assertSame('glasses', $this->assortment->eyeWearRole((string) $glasses->name));
        $this->assertSame('case', $this->assortment->eyeWearRole((string) $case->name));
        $this->assertSame('case', $this->assortment->eyeWearRole('woreczek dla wszystkich modeli okularów'));
        $this->assertTrue($this->assortment->compatibleProduct($req, $glasses));
        $this->assertTrue($this->assortment->compatibleProduct($req, $case));
    }

    #[Test]
    public function shop_category_gogli_does_not_override_glasses_in_name(): void
    {
        $req = 'OKULARY OCHRONNE MSA PERSPECTA 010';
        $glasses = new Product;
        $glasses->forceFill([
            'name' => 'Okulary PERSPECTA 010 (12szt), bezbarwne',
            'sku' => '10045641',
            'manufacturer' => 'MSA',
            'ppe_family' => PpeAssortment::FAMILY_EYES,
            'category' => 'Sklep - kategorie / Ochrona wzroku i twarzy / Akcesoria do okularów i gogli',
        ]);

        $this->assertSame('glasses', $this->assortment->articleType((string) $glasses->name, PpeAssortment::FAMILY_EYES));
        $this->assertTrue($this->assortment->compatibleProduct($req, $glasses));
    }

    #[Test]
    public function rubber_boot_requirement_rejects_gaiters(): void
    {
        $req = 'BUTY gumowe DAMSKIE antyelektrostatyczne rozm. 35-41 TRONCHETTO prod.CERVA EN ISO 20347';
        $gaiters = new Product;
        $gaiters->forceFill([
            'name' => 'Getry żaroodp. metalizowane 858.0 wys. 34 cm, rozm. 41-42',
            'sku' => '28-0001.00/858.0_3400',
            'manufacturer' => 'ALWIT POLAND',
            'category' => 'Ochrona ciała',
        ]);

        $this->assertTrue($this->assortment->isFootwearLegwear((string) $gaiters->name));
        $this->assertFalse($this->assortment->compatibleProduct($req, $gaiters));
    }

    #[Test]
    public function fireman_gum_boot_is_not_antistatic_for_esd_requirement(): void
    {
        $req = 'BUTY gumowe DAMSKIE antyelektrostatyczne';
        $fireman = new Product;
        $fireman->forceFill([
            'name' => 'FIREMAN (02 NAVY)',
            'sku' => 'V262-0-02',
            'manufacturer' => 'Cofra',
            'description' => 'Buty gumowe FIREMAN',
        ]);
        $esd = new Product;
        $esd->forceFill([
            'name' => 'Kalosze damskie ESD',
            'sku' => 'ESD-1',
            'manufacturer' => 'VM',
            'description' => 'antyelektrostatyczne EN 1149',
        ]);

        $this->assertTrue($this->assortment->requiresAntistatic($req));
        $this->assertFalse($this->assortment->compatibleProduct($req, $fireman));
        $this->assertTrue($this->assortment->compatibleProduct($req, $esd));
    }

    #[Test]
    public function visor_carrier_query_accepts_face_mount_and_v5_sku(): void
    {
        $req = 'System łączenia osłony z hełmem ochronnym 3M V5 tzw. nośnik osłony';
        $this->assertSame(PpeAssortment::FAMILY_HEAD, $this->assortment->family($req));

        $v5 = new Product;
        $v5->forceFill([
            'name' => '3M System łączenia osłony z hełmem ochronnym, V5',
            'sku' => 'V5',
            'manufacturer' => '3M',
            'ppe_family' => PpeAssortment::FAMILY_FACE,
        ]);
        $face = new Product;
        $face->forceFill([
            'name' => '3M Osłona twarzy siatkowa V4B',
            'sku' => 'V4B',
            'manufacturer' => '3M',
            'ppe_family' => PpeAssortment::FAMILY_FACE,
        ]);

        $this->assertTrue($this->assortment->compatibleProduct($req, $v5));
        $this->assertTrue($this->assortment->compatibleProduct($req, $face));
    }

    #[Test]
    public function apparel_set_accepts_jacket_and_bibs_with_shared_norms(): void
    {
        $req = 'Ubranie antyelektrostatyczne, trudnopalne (bluza + spodnie do pasa lub ogrodniczki) EN ISO 11611 kl. 2 EN 1149-5';
        $this->assertTrue($this->assortment->isApparelSet($req));
        $this->assertSame('set', $this->assortment->garment($req));

        $jacket = new Product;
        $jacket->forceFill([
            'name' => 'Bluza KOLPEO BASIC ZIPPER - zamek',
            'sku' => 'BLUZA-KOLPEO',
            'category' => 'Sklep / Kombinezony robocze / Akcesoria do kombinezonów',
            'norms' => 'EN ISO 11611:2015, EN 1149-5:2018, EN ISO 11612:2015',
            'description' => 'Odzież wielonormowa, też EN ISO 20471 i kombinezon w zestawie.',
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
        ]);
        $bibs = new Product;
        $bibs->forceFill([
            'name' => 'Spodnie ogrodniczki KOLPEO BASIC',
            'sku' => 'OGROD-KOLPEO',
            'category' => 'Sklep / Kombinezony robocze / Akcesoria do kombinezonów',
            'norms' => 'EN ISO 11611:2015, EN 1149-5:2018',
            'description' => 'Ogrodniczki, wzmianka o kamizelce odblaskowej EN 20471.',
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
        ]);
        $hood = new Product;
        $hood->forceFill([
            'name' => 'KAPTUR NIEPALNY I ANTYELEKTROSTATYCZNY',
            'sku' => 'CAFR1',
            'norms' => 'EN 1149-5',
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
        ]);
        $rain = new Product;
        $rain->forceFill([
            'name' => '103 - Kurtka wodoochronna zapinana na zamek',
            'sku' => 'RAIN-103',
            'norms' => 'EN 343',
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
        ]);

        $this->assertTrue($this->assortment->compatibleProduct($req, $jacket));
        $this->assertTrue($this->assortment->compatibleProduct($req, $bibs));
        $this->assertFalse($this->assortment->compatibleProduct($req, $hood));
        $this->assertFalse($this->assortment->compatibleProduct($req, $rain));
    }

    #[Test]
    public function helmet_fas_trac_vent_drops_push_key_and_unvented(): void
    {
        $req = 'Hełm wentylowany MSA SUPER V - GARD 500 ATEX czasza ABS - różne kolory, więźba Fas-Trac';

        $this->assertSame(PpeAssortment::HARNESS_FASTRAC, $this->assortment->helmetHarness($req));
        $this->assertSame(PpeAssortment::VENT_OPEN, $this->assortment->helmetVent($req));
        $this->assertTrue($this->assortment->helmetSpecAllows(
            $req,
            'V-Gard 500, biały, wentylowany, więźba Fas-Trac III'
        ));
        $this->assertFalse($this->assortment->helmetSpecAllows(
            $req,
            'V-Gard 500, biały, wentylowany, więźba Push-Key'
        ));
        $this->assertFalse($this->assortment->helmetSpecAllows(
            $req,
            'V-Gard 500, biały, więźba Fas-Trac III'
        ));
        $this->assertFalse($this->assortment->helmetSpecAllows(
            $req,
            'V-Gard, biały, więźba Fas-Trac III'
        ));
    }

    #[Test]
    public function cut_resistance_accepts_fiber_name_not_only_adjective(): void
    {
        $req = 'Rękawice antyprzecięciowe powlekane nitrylem do prac montażowych';

        $this->assertTrue($this->assortment->wantsCutResistance($req));
        $this->assertFalse($this->assortment->wantsCutResistance('Rękawice powlekane nitrylem do prac montażowych'));
        $this->assertTrue($this->assortment->showsCutResistance(
            'RĘKAWICE DZIANE Z WŁÓKNA XTREMCUT, DŁON POWLEKANA PIANKĄ NITRYLOWĄ'
        ));
        $this->assertTrue($this->assortment->showsCutResistance('EOS NOCUT VV910 Rękawice antyprzecięciowe'));
        $this->assertFalse($this->assortment->showsCutResistance(
            'Rękawice dziane, dłoń powlekana pianką nitrylową'
        ));
    }

    #[Test]
    public function welded_boots_coverall_is_not_jacket_or_waders(): void
    {
        $req = 'Kombinezon wodoochronny z wgrzanymi kaloszami';

        $this->assertTrue($this->assortment->wantsWeldedBootsCoverall($req));
        $this->assertFalse($this->assortment->wantsWeldedBootsCoverall('Kombinezon wodoochronny'));
        $this->assertFalse($this->assortment->wantsWeldedBootsCoverall(
            'Spodniobuty wodoodporne z kaloszem typ S5'
        ));
        $this->assertTrue($this->assortment->showsWeldedBootsCoverall(
            'Kombinezon wodoochronny z wgrzanymi kaloszami 104/K'
        ));
        $this->assertTrue($this->assortment->showsAttachedBootsCoverall(
            'Kombinezon Wodoochronny z Kaloszami 0404'
        ));
        $this->assertFalse($this->assortment->showsWeldedBootsCoverall(
            'Kombinezon Wodoochronny z Kaloszami 0404'
        ));
        $this->assertFalse($this->assortment->showsAttachedBootsCoverall('Kombinezon Wodoochronny 0403'));
        $this->assertFalse($this->assortment->showsAttachedBootsCoverall('Spodniobuty Standard SB01'));
        $this->assertFalse($this->assortment->showsAttachedBootsCoverall(
            '103 - Kurtka wodoochronna zapinana na zamek'
        ));
    }

    /**
     * Rodzinę wskazuje rzeczownik główny (pierwszy w tekście), nie pierwszy regex z listy:
     * „wymienne szelki” przy spodniobutach i „łącznie z półmaskami” przy goglach to akcesoria.
     */
    #[Test]
    #[DataProvider('leadingNounFamilyCases')]
    public function detects_family_by_leading_noun(string $text, string $family): void
    {
        $this->assertSame($family, $this->assortment->family($text));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function leadingNounFamilyCases(): array
    {
        return [
            ['Fartuch przedni wodoochronny 120 × 75 cm. Wymagane: szeroka szelka i wiązanie z tyłu; EN 343.', PpeAssortment::FAMILY_APPAREL],
            ['Spodniobuty wodoochronne z wgrzanymi na stałe kaloszami – obuwie bezpieczne typu S5 SRC; wymienne szelki z szerokiej elastycznej gumy', PpeAssortment::FAMILY_APPAREL],
            ['Gogle ochronne szczelne, spawalnicze, z zaciemnieniem 5.0; możliwość stosowania łącznie z półmaskami oddechowymi', PpeAssortment::FAMILY_EYES],
            ['Spodnie do pasa z szelkami Extreme', PpeAssortment::FAMILY_APPAREL],
            ['Kurtka z odblaskowymi szelkami', PpeAssortment::FAMILY_APPAREL],
            ['Kamizelka z szelkami odblaskowymi', PpeAssortment::FAMILY_APPAREL],
            ['Gogle spawalnicze do stosowania z półmaską', PpeAssortment::FAMILY_EYES],
            ['Okulary ochronne kompatybilne z półmaskami', PpeAssortment::FAMILY_EYES],
            ['Szelki bezpieczeństwa typu kamizelka 3M', PpeAssortment::FAMILY_FALL],
            ['Spodnie z kieszeniami na nakolanniki', PpeAssortment::FAMILY_APPAREL],
            ['Trzewiki ocieplane', PpeAssortment::FAMILY_FOOTWEAR],
            ['Półbuty robocze', PpeAssortment::FAMILY_FOOTWEAR],
            ['Półbuty elektroizolacyjne 20 kV', PpeAssortment::FAMILY_FOOTWEAR],
            ['Sandały ochronne', PpeAssortment::FAMILY_FOOTWEAR],
            ['Kalosze gumowe', PpeAssortment::FAMILY_FOOTWEAR],
            ['Rękawice butylowe', PpeAssortment::FAMILY_GLOVES],
            ['Wodery z kaloszami', PpeAssortment::FAMILY_APPAREL],
            ['Spodniobuty oddychające AIR', PpeAssortment::FAMILY_APPAREL],
            ['Pochłaniacz gazów i par klasy A2 – bagnetowy system mocowania na półmaskach i maskach pełnotwarzowych', PpeAssortment::FAMILY_RESPIRATORY],
        ];
    }

    #[Test]
    public function waders_requirement_rejects_harness_and_bib_pants(): void
    {
        $req = 'Spodniobuty wodoochronne z wgrzanymi na stałe kaloszami – obuwie bezpieczne typu S5 SRC wg EN ISO 20345, '
            .'z wkładką antyprzebiciową. Wymagane: tkanina powlekana PVC, EN 343; szwy zgrzewane; wzmocnienia na kolanach; '
            .'regulacja w pasie sznurkiem; wymienne szelki z szerokiej elastycznej gumy; odporność do -50°C. Rozmiary: 39–48.';

        $this->assertSame(PpeAssortment::FAMILY_APPAREL, $this->assortment->family($req));
        $this->assertSame('waders', $this->assortment->garment($req));
        $this->assertSame('waders', $this->assortment->garment('Spodniobuty oddychające AIR'));
        $this->assertSame('pants', $this->assortment->garment('Spodnie do pasa z szelkami Extreme'));

        $harness = $this->card('AB178', 'Szelki bezpieczeństwa typu kamizelka 3M™ Protecta® FIRST, kolor niebieski', [
            'category' => 'Środki ochrony indywidualnej',
            'description' => 'Szelki typu kamizelka zabezpieczające przed upadkiem z wysokości 3M Protecta E50.',
        ]);
        $bibs = $this->card('3112', 'Spodnie do pasa z szelkami Extreme', [
            'category' => 'Asekuracja',
            'description' => 'Spodnie do pasa z szelkami do pracy na morzu lub w porcie. Elastyczne szelki, wzmocnienia na kolanach.',
        ]);
        $waders = $this->card('SB04 AIR', 'Spodniobuty oddychające AIR', [
            'category' => 'Odzież',
            'description' => 'Wymienne szelki z elastycznej, szerokiej gumy. Wgrzane na stałe kalosze typu S5 z wkładką antyprzebiciową. EN ISO 20345, EN 343.',
        ]);

        $this->assertFalse($this->assortment->compatibleProduct($req, $harness));
        $this->assertFalse($this->assortment->compatibleProduct($req, $bibs));
        $this->assertTrue($this->assortment->compatibleProduct($req, $waders));
        $this->assertSame(PpeAssortment::FAMILY_APPAREL, $this->assortment->productFamily($bibs));
        $this->assertFalse($this->assortment->wantsWeldedBootsCoverall($req));
    }

    #[Test]
    public function apron_requirement_rejects_bib_pants_and_harness(): void
    {
        $req = 'Fartuch przedni wodoochronny, wymiary 120 × 75 cm, z tkaniny poliestrowej powlekanej poliuretanem. '
            .'Wymagane: regulacja na pasku szyjnym; szeroka szelka i wiązanie z tyłu; zgodność z EN ISO 13688 i EN 343.';

        $this->assertSame(PpeAssortment::FAMILY_APPAREL, $this->assortment->family($req));
        $this->assertSame('coat', $this->assortment->garment($req));

        $bibs = $this->card('3112', 'Spodnie do pasa z szelkami Extreme', ['category' => 'Asekuracja']);
        $harness = $this->card('AB178', 'Szelki bezpieczeństwa typu kamizelka 3M™ Protecta® FIRST');
        $apron = $this->card('202', 'Fartuch wodoochronny 120/75 PU Poliester', [
            'category' => 'Odzież',
            'description' => 'Bardzo lekki fartuch. Wygodne zawieszanie na szerokiej szelce oraz możliwość wiązania z tyłu. EN ISO 13688 i EN 343.',
        ]);

        $this->assertFalse($this->assortment->compatibleProduct($req, $bibs));
        $this->assertFalse($this->assortment->compatibleProduct($req, $harness));
        $this->assertTrue($this->assortment->compatibleProduct($req, $apron));
    }

    #[Test]
    public function welding_goggles_requirement_keeps_goggles_and_drops_ffp(): void
    {
        $req = 'Gogle ochronne szczelne, spawalnicze, z zaciemnieniem 5.0 – do ochrony oczu podczas spawania gazowego. '
            .'Wymagane: soczewka poliwęglanowa; wysoki profil umożliwiający noszenie na okularach korekcyjnych; '
            .'możliwość stosowania łącznie z półmaskami oddechowymi. Zgodność z EN 166.';

        $this->assertSame(PpeAssortment::FAMILY_EYES, $this->assortment->family($req));
        $this->assertSame('goggles', $this->assortment->articleType($req));

        $goggles = $this->card('34340', '3M™ 2890 Gogle ochronne, szczelne, zaciemnienie spawalnicze 5.0, 2895S', [
            'category' => 'Materiały ścierne',
        ]);
        $ffp = $this->card('9310+', '3M™ Aura™ półmaska filtrująca, FFP1, bez zaworu, 9310+');

        $this->assertTrue($this->assortment->compatibleProduct($req, $goggles));
        $this->assertFalse($this->assortment->compatibleProduct($req, $ffp));
    }

    #[Test]
    public function respiratory_type_reads_leading_noun(): void
    {
        $req = 'Pochłaniacz gazów i par klasy A2 – element oczyszczający do sprzętu ochrony układu oddechowego. '
            .'Wymagane: bagnetowy system mocowania na półmaskach i maskach pełnotwarzowych ze złączem bagnetowym.';

        $this->assertSame('filter', $this->assortment->articleType($req, PpeAssortment::FAMILY_RESPIRATORY));
        $this->assertSame('filter', $this->assortment->articleType('Filtr P3 R do masek pełnotwarzowych', PpeAssortment::FAMILY_RESPIRATORY));
        $this->assertSame('fullface', $this->assortment->articleType('Maska pełnotwarzowa 3M 6800 z filtrami', PpeAssortment::FAMILY_RESPIRATORY));
        $this->assertSame('ffp', $this->assortment->articleType('Półmaska filtrująca FFP2 z filtrem węglowym', PpeAssortment::FAMILY_RESPIRATORY));
    }

    #[Test]
    public function reusable_half_mask_requirement_rejects_disposable_ffp(): void
    {
        $req = 'Półmaska wielokrotnego użytku do ochrony układu oddechowego – po skompletowaniu z odpowiednimi elementami '
            .'oczyszczającymi chroni przed aerozolami, parami i gazami. Wymagane: korpus z dwoma zaworami wdechowymi z łącznikami '
            .'bagnetowymi; zawór wydechowy z pokrywą; jednoczęściowe nagłowie tekstylne; zgodność z PN-EN 140:2004.';

        $this->assertSame('reusable_half', $this->assortment->articleType($req));

        $secura = $this->card('S56T0SM0', 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)', [
            'category' => 'PÓŁMASKA SECURA 3000',
            'description' => 'Półmaska SECURA 3000 składa się z korpusu, dwóch zaworów wdechowych z łącznikami bagnetowymi, zaworu wydechowego oraz nagłowia.',
        ]);
        $ffp = $this->card('9310+', '3M™ Aura™ półmaska filtrująca, FFP1, bez zaworu, 9310+', [
            'category' => 'Środki ochrony indywidualnej',
            'description' => 'Półmaska filtrująca 3M Aura 9310+ to jednorazowa półmaska klasy FFP1.',
        ]);
        $maintenanceFree = $this->card('4279+', 'Niewymagająca konserwacji półmaska wielokrotnego użytku 3M™ 4279+, FFABEK1P3 R D');
        $filter = $this->card('S565A202', 'Pochłaniacz 3031 A2', ['category' => 'Pochłaniacze']);

        $this->assertTrue($this->assortment->compatibleProduct($req, $secura));
        $this->assertFalse($this->assortment->compatibleProduct($req, $ffp));
        $this->assertTrue($this->assortment->compatibleProduct($req, $maintenanceFree));

        // Pochłaniacz (poz. 14) nie jest maską — to filtr do niej.
        $filterReq = 'Pochłaniacz gazów i par klasy A2 – bagnetowy system mocowania na półmaskach i maskach pełnotwarzowych. EN 14387.';
        $this->assertTrue($this->assortment->compatibleProduct($filterReq, $filter));
        $this->assertFalse($this->assortment->compatibleProduct($filterReq, $secura));

        // Samo „półmaska” obejmuje też FFP — bez jawnych słów o wielorazowości podtyp jest nieznany, nie sprzeczny.
        $aura = $this->card('9322+', '3M Aura 9322+ półmaska');
        $this->assertTrue($this->assortment->compatibleProduct('Półmaska FFP2', $aura));
        $this->assertTrue($this->assortment->compatibleProduct('Półmaska filtrująca FFP1 z zaworem', $secura));
    }

    #[Test]
    public function ffp_with_valve_rejects_explicit_no_valve_mask(): void
    {
        $req = 'Półmaska filtrująca klasy FFP1 z zaworem wydechowym, specjalistyczna – do ochrony dróg oddechowych przed pyłami, '
            .'mgłami oraz uciążliwym poziomem par organicznych. Wymagane: warstwa węgla aktywowanego; zawór wydechowy. Zgodność z EN 149.';

        $noValve = $this->card('9310+', '3M™ Aura™ półmaska filtrująca, FFP1, bez zaworu, 9310+');
        $foreignDescription = $this->card('7100329384', '3M™ półmaska do cząstek stałych 8710E, FFP1, bez zaworu, 3 szt./opakowanie', [
            'category' => 'Taśmy',
            'description' => 'Taśma maskująca odporna na promieniowanie UV 3M™ 2814, Zielony, 30 mm x 50 m.',
        ]);
        $valve = $this->card('9312+', '3M™ Aura™ półmaska filtrująca, FFP1, z zaworem, 9312+ (LANG B)');
        $carbon = $this->card('9914', '3M™ Półmaska filtrująca 9914, specjalistyczna, z zaworem, FFP1 (pyły i pary organiczne)');
        $silent = $this->card('FFP1-X', 'Półmaska filtrująca FFP1 X');

        $this->assertFalse($this->assortment->compatibleProduct($req, $noValve));
        $this->assertFalse($this->assortment->compatibleProduct($req, $foreignDescription));
        $this->assertTrue($this->assortment->compatibleProduct($req, $valve));
        $this->assertTrue($this->assortment->compatibleProduct($req, $carbon));
        $this->assertTrue($this->assortment->compatibleProduct($req, $silent), 'karta milczy o zaworze — brak wiedzy, nie sprzeczność');

        // Klasa FFP niższa niż wymagana odpada, wyższa przechodzi.
        $ffp2Req = 'Półmaska filtrująca FFP2 NR D do pyłów';
        $this->assertFalse($this->assortment->compatibleProduct($ffp2Req, $valve));
        $this->assertTrue($this->assortment->compatibleProduct($ffp2Req, $this->card('9332+', '3M Aura 9332+ półmaska filtrująca FFP3 z zaworem')));
        $this->assertTrue($this->assortment->compatibleProduct($ffp2Req, $this->card('X-2', 'Półmaska filtrująca składana')));
    }

    #[Test]
    public function electrical_insulation_requirement_rejects_plain_ob_shoe(): void
    {
        $req = 'Półbuty elektroizolacyjne do prac przy urządzeniach elektroenergetycznych o napięciu do 17 kV, do nakładania na inne '
            .'obuwie robocze. Wymagane: klasa 2 AC zgodnie z normą EN 50321-1; wykonanie z gumy naturalnej z dodatkiem antystarzeniowym; '
            .'zgodność z normą EN 20347:2012 dla obuwia kategorii OB; odporność na poślizg SRA. Rozmiary 41–45.';

        $this->assertTrue($this->assortment->requiresElectricalInsulation($req));
        $this->assertTrue($this->assortment->requiresElectricalInsulation('Rękawice dielektryczne EN 60903 klasa 0'));
        $this->assertFalse($this->assortment->requiresElectricalInsulation('Półbuty robocze OB SRA'));
        $this->assertFalse($this->assortment->requiresElectricalInsulation(
            'Półbuty ESD S1 do stref zagrożonych porażeniem prądem elektrostatycznym'
        ));

        $insulating = $this->card('T5912100', 'Półbuty elektroizolacyjne 20 kV - ANTYAMPER', [
            'category' => '11.1 OBUWIE ELEKTROIZOLACYJNE',
            'description' => 'Półbuty elektroizolacyjne ANTYAMPER 20 kV. Produkt klasy 2 AC zgodnie z normą EN 50321-1. EN 20347:2012 kategorii OB, SRA.',
        ]);
        $plainOb = $this->card('ART 702 Air 6660 OB A E FO', 'ART 702 Air 6660 OB A E FO', [
            'description' => 'Obuwie robocze ART 702 Air 6660 OB A E FO do kontroli ładunków elektrostatycznych. '
                .'Spełnia normę EN ISO 20347:2012 w klasie OB A E FO SRC oraz wymagania ESD zgodnie z EN IEC 61340-4-3:2018.',
        ]);
        $silentOb = $this->card('OB-1', 'Półbuty robocze OB SRA');

        $this->assertTrue($this->assortment->compatibleProduct($req, $insulating));
        $this->assertFalse($this->assortment->compatibleProduct($req, $plainOb));
        $this->assertFalse($this->assortment->compatibleProduct($req, $silentOb), 'brak dowodu elektroizolacji = odrzuć, jak przy antystatyce');
        $this->assertTrue($this->assortment->compatibleProduct('Półbuty robocze OB SRA', $plainOb), 'zwykłe OB dalej przechodzi');

        $gloveReq = 'Rękawice elektroizolacyjne klasa 0 EN 60903 do 1 kV';
        $this->assertFalse($this->assortment->compatibleProduct($gloveReq, $this->card('RNITZ', 'Rękawice nitrylowe RNITZ')));
        $this->assertTrue($this->assortment->compatibleProduct($gloveReq, $this->card('ELSEC-0', 'Rękawice elektroizolacyjne ELSEC klasa 0')));
        $this->assertTrue($this->assortment->productMeetsElectricalInsulationRequirement(
            $gloveReq,
            $this->card('SECURA-D', 'Rękawice SECURA klasa 00', ['description' => 'Rękawice dielektryczne do 500 V wg EN 60903.'])
        ));
    }

    /**
     * W3‑10: nazwa karty to goły kod bez typu, a własny opis w pierwszym zdaniu nazywa inny typ
     * („Trzewik bezpieczny ARDEUS 350…” przy wymaganych sandałach) → odrzuć. Opis bez typu albo
     * opis, który nie nazywa modelu, nie jest dowodem.
     */
    #[Test]
    public function sandal_requirement_rejects_code_named_card_whose_own_description_says_boot(): void
    {
        $req = 'Sandały ochronne (obuwie bezpieczne z odkrytą cholewką) kategorii S1 P wg EN ISO 20345, do prac w suchych '
            .'pomieszczeniach. Wymagane: zabudowana pięta; podnosek ochronny; właściwości antyelektrostatyczne (ESD); podeszwa FO.';

        $sandals = $this->card('ARMEN 9007 6660 S1 P', 'ARMEN 9007 6660 S1 P', [
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie',
            'description' => 'Sandały robocze ARTRA ARMEN 9007 6660 S1 P to lekkie obuwie ochronne. Właściwości antyelektrostatyczne (ESD).',
        ]);
        $boot = $this->card('ARDEUS 350 Air 618080 S1 PL ESD', 'ARDEUS 350 Air 618080 S1 PL ESD', [
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie',
            'description' => 'Trzewik bezpieczny ARDEUS 350 Air 618080 S1 PL ESD to lekkie obuwie ochronne. '
                .'W odróżnieniu od sandałów zabudowany. Dodatkowa ochrona ESD.',
        ]);
        // Klasa w nazwach = wymagana (S1 P): ten test pilnuje typu z opisu, nie klasy — klasę niższą
        // (AROX „S1 ESD” przy S1 P) odrzuca osobna bramka, patrz footwear_class_lower_than_required_is_rejected.
        $typeless = $this->card('AROX 733 641460 S1 P ESD', 'AROX 733 641460 S1 P ESD', [
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie',
            'description' => 'Obuwie ochronne AROX 733 641460 S1 P ESD marki ARTRA do kontroli wyładowań elektrostatycznych. Podnosek stalowy.',
        ]);
        $foreignDescription = $this->card('X-1', 'X-1 S1 P ESD', [
            'manufacturer' => null,
            'description' => 'Trzewiki robocze innego modelu. ESD.',
        ]);
        $laterSentence = $this->card('Y-1', 'Y-1 S1 P ESD', [
            'manufacturer' => 'ARTRA',
            'description' => 'Obuwie ochronne ARTRA Y-1 ESD. Lżejsze niż trzewiki tej serii.',
        ]);

        $this->assertTrue($this->assortment->compatibleProduct($req, $sandals));
        $this->assertFalse($this->assortment->compatibleProduct($req, $boot), 'opis nazywa trzewik, wymagane sandały');
        $this->assertTrue($this->assortment->compatibleProduct($req, $typeless), 'opis bez typu = brak wiedzy');
        $this->assertTrue($this->assortment->compatibleProduct($req, $foreignDescription), 'opis nie nazywa modelu — nie świadczy o typie');
        $this->assertTrue($this->assortment->compatibleProduct($req, $laterSentence), 'typ liczy się tylko z pierwszego zdania');
        $this->assertTrue($this->assortment->compatibleProduct('Trzewiki ochronne S1 ESD', $boot));

        // Canis: angielska nazwa niesie typ (półbut), a obcy opis mówi o trzewiku zimowym S3 — sandały to nie to
        $lowShoe = $this->card('2340-002-800-00', 'Low perforated leather footwear, PU-PU, oil resistant, antistatic, antislippery outsole, sizes 35 -50', [
            'manufacturer' => 'Canis',
            'category' => 'Obuwie',
            'description' => 'Zimowe, wodoodporne buty robocze CANIS Stone Topaz S3 Winter to obuwie ochronne do pracy w chłodnych warunkach.',
        ]);
        $this->assertFalse($this->assortment->compatibleProduct($req, $lowShoe), 'półbut (low shoe) to nie sandał');
    }

    #[Test]
    public function glasses_named_by_lens_are_not_rejected_for_missing_noun(): void
    {
        $req = 'Okulary ochronne z przyciemnianymi (smoke) soczewkami poliwęglanowymi do pracy na zewnątrz. Zgodność z EN 166, EN 172.';
        $rush = $this->card('RUSHPTWI', 'Przyciemnione (smoke) soczewki PC - powłoki PLATINUM® - czerwono-czarne, bi-materiałowe zauszniki PC+TPR', [
            'manufacturer' => 'Bolle',
            'description' => 'Okulary ochronne Bolle Rush z przyciemnianymi (smoke) soczewkami poliwęglanowymi. Spełniają EN166 i EN172.',
        ]);
        $gogglesByDescription = $this->card('GG-1', 'Soczewka acetatowa bezbarwna', [
            'description' => 'Gogle ochronne szczelne z soczewką acetatową.',
        ]);
        $unknown = $this->card('UNK-1', 'Szyba ochronna zapasowa 2C-1.2', ['category' => 'Ochrona oczu']);
        $case = $this->card('ETUI-1', 'Etui na okulary', ['description' => 'Okulary ochronne pasują do etui.']);

        $this->assertNull($this->assortment->family((string) $rush->name));
        $this->assertTrue($this->assortment->compatibleProduct($req, $rush));
        $this->assertFalse($this->assortment->compatibleProduct($req, $gogglesByDescription), 'opis nazywa inny typ (gogle)');
        $this->assertTrue($this->assortment->compatibleProduct($req, $unknown), 'nieznany typ ≠ sprzeczność');
        $this->assertFalse($this->assortment->compatibleProduct($req, $case), 'etui dalej odpada');
    }

    #[Test]
    public function sleeve_requirement_rejects_gloves_and_accepts_sleeve(): void
    {
        $req = 'Ochraniacz przedramienia (rękaw) chroniący przed przecięciem, długość ok. 475 mm (19\'\'), w kolorze fluorescencyjnym '
            .'żółtym. Konstrukcja bezszwowa, dzianina nylon/poliester/włókno szklane; regulowane zapięcie na rzep. '
            .'Wymagane: ŚOI kategorii III; EN 420:2003+A1:2009; EN 388 z poziomami min. 2.X.4.2.C; EN 407 poziom 1.';

        $this->assertSame(PpeAssortment::FAMILY_GLOVES, $this->assortment->family($req));
        $this->assertTrue($this->assortment->isArmSleeve($req));
        $this->assertTrue($this->assortment->isArmSleeve('Ochraniacz przedramienia (rękaw) antyprzecięciowy'));
        $this->assertTrue($this->assortment->isArmSleeve('Zarękawek antyprzecięciowy 45 cm'));
        $this->assertTrue($this->assortment->isArmSleeve('Rękaw antyprzecięciowy HPPE 45 cm'), 'goły rzeczownik „rękaw” poza odzieżą');
        $this->assertTrue($this->assortment->isArmSleeve('Rękawy ochronne termoodporne, para'));
        $this->assertTrue($this->assortment->isArmSleeve('Naramiennik z rękawem do rękawic spawalniczych'), 'rękawice wymienione po rękawie nie odbierają typu');
        $this->assertFalse($this->assortment->isArmSleeve('Rękawice dzianinowe z mankietem safety cuff'));
        $this->assertFalse($this->assortment->isArmSleeve('Rękawice antyprzecięciowe z rękawem 40 cm'));
        $this->assertFalse($this->assortment->isArmSleeve('Kurtka robocza z długimi rękawami'));
        $this->assertFalse($this->assortment->isArmSleeve('Fartuch laboratoryjny, rękawy wykończone zatrzaskami'));

        $gloves = $this->card('48130110', 'HyFlex 48130', [
            'manufacturer' => 'Ansell',
            'description' => 'Rękawice ochronne Ansell HyFlex 48-130 to lekkie rękawice montażowe z powłoką poliuretanową. EN 388, EN 420.',
        ]);
        $esdGloves = $this->card('3440-003-100-00', 'Gloves EDGE 48-140 ESD seamless polyester and carbon fiber, PU coating', [
            'category' => 'Rękawice',
        ]);
        $sleeve = $this->card('11202000', 'HyFlex 11202 SIZE 19\'\'/47,5 cm', [
            'manufacturer' => 'Ansell',
            'description' => 'The new HyFlex® 11-202 HI-VIZ™ arm protector offers optimum wearing comfort. '
                .'Ansell HyFlex 11-202 Hi-Vis Cut-Resistant Sleeve with Velcro Fixing System - Gloves.co.uk',
        ]);
        $foreignDescription = $this->card('11202000', 'HyFlex 11202 SIZE 19\'\'/47,5 cm', [
            'manufacturer' => null,
            'description' => 'Cut-resistant arm sleeve for sheet metal handling.',
        ]);

        $this->assertFalse($this->assortment->compatibleProduct($req, $gloves));
        $this->assertFalse($this->assortment->compatibleProduct($req, $esdGloves));
        $this->assertTrue($this->assortment->compatibleProduct($req, $sleeve));
        $this->assertFalse(
            $this->assortment->compatibleProduct($req, $foreignDescription),
            'opis, który nie nazywa modelu, nie świadczy o typie karty'
        );
        $this->assertFalse($this->assortment->compatibleProduct('Rękawice antyprzecięciowe HyFlex EN 388', $sleeve));
    }

    /** Przetarg 1 poz. 1: karta rękawa z polskim opisem, bez „rękawic” i bez EN 388, zostawała bez rodziny. */
    #[Test]
    public function arm_sleeve_card_without_family_noun_belongs_to_gloves(): void
    {
        $sleeve = $this->card('11202000', 'HyFlex 11202 SIZE 19\'\'/47,5 cm', [
            'manufacturer' => 'Ansell',
            'norms' => 'EN 407: poziom 1 (ochrona termiczna do 100°C), EN ISO 13997: odporność na przecięcie poziom C',
            'description' => 'Rękaw ochronny Ansell HyFlex 11-202 o wysokiej widoczności chroni przedramię przed przecięciem, zapięcie na rzep.',
        ]);
        $this->assertSame(PpeAssortment::FAMILY_GLOVES, $this->assortment->productFamily($sleeve));

        $foreign = $this->card('X-1', 'HyFlex 99999', [
            'description' => 'Rękaw ochronny o wysokiej widoczności, zapięcie na rzep.',
        ]);
        $this->assertNull($this->assortment->productFamily($foreign), 'opis, który nie nazywa karty, nie świadczy o typie');

        $jacket = $this->card('K-1', 'Kurtka robocza', [
            'description' => 'Kurtka robocza z długim rękawem i mankietem na rzep.',
        ]);
        $this->assertSame(PpeAssortment::FAMILY_APPAREL, $this->assortment->productFamily($jacket), 'rękaw kurtki to odzież');

        // przebudowa indeksu 14.09: osprzęt kablowy i sorbent z „rękawem” w nazwie trafiły do rękawic
        $heatShrink = $this->card('7000032369', 'Rękaw termokurczliwy 3M™ HDCW, 55/15-500 mm', ['manufacturer' => '3M']);
        $this->assertNull($this->assortment->productFamily($heatShrink), 'rękaw termokurczliwy to osprzęt kablowy');
        $sorbent = $this->card('T-270', '3M™ Sorbent do substancji ropopochodnych rękaw, 200 mm, 300 mm', ['manufacturer' => '3M']);
        $this->assertNull($this->assortment->productFamily($sorbent), 'sorbent w rękawie to nie środek ochrony');
        $welding = $this->card('59416260', 'ActivArmr 59416 Size 26,0', [
            'manufacturer' => 'Ansell',
            'description' => 'ActivArmr 59-416 to średnio wytrzymałe rękawy spawalnicze przeznaczone do ochrony przed gorącem i oparzeniami.',
        ]);
        $this->assertSame(PpeAssortment::FAMILY_GLOVES, $this->assortment->productFamily($welding), 'rękaw spawalniczy to ochrona ramion');
    }

    /** Przetarg 1 poz. 1: „wyrób antystatyczny” w SIWZ, a karta HyFlex 11-202 mówi po angielsku „extra features: antistatic”. */
    #[Test]
    public function antistatic_evidence_accepts_english_wording(): void
    {
        $this->assertTrue($this->assortment->productShowsAntistatic('Lining material: nylon, polyester, glass fibre. extra features: antistatic, latex-free'));
        $this->assertTrue($this->assortment->productShowsAntistatic('Anti-static PU coated gloves'));
        $this->assertFalse($this->assortment->productShowsAntistatic('Rękawice montażowe powlekane poliuretanem, EN 388 4131X'));

        $req = 'Ochraniacz przedramienia (rękaw) chroniący przed przecięciem; wyrób antystatyczny, bez lateksu; EN 388 min. 2.X.4.2.C.';
        $sleeve = $this->card('11202000', 'HyFlex 11202 SIZE 19\'\'/47,5 cm', [
            'manufacturer' => 'Ansell',
            'norms' => 'EN 420:2003 + A1:2009, EN 388:2016 (2.X.4.2.C), EN 407 (X.1.X.X.X)',
            'description' => 'The new HyFlex® 11-202 HI-VIZ™ arm protector offers optimum wearing comfort. extra features: antistatic, latex-free',
        ]);
        $this->assertTrue($this->assortment->productMeetsAntistaticRequirement($req, $sleeve));
        $this->assertFalse($this->assortment->productMeetsAntistaticRequirement($req, $this->card('11200000', 'HyFlex 11200', [
            'description' => 'Rękawy ochronne HyFlex 11-200 o wysokiej widoczności, ochrona przed przecięciem.',
        ])), 'bez słowa o antystatyce dalej brak dowodu');
    }

    #[Test]
    public function footwear_antistatic_accepts_esd_stated_in_description(): void
    {
        $req = 'Sandały ochronne kategorii S1 P wg EN ISO 20345; właściwości antyelektrostatyczne (ESD); podeszwa FO.';
        $sandals = $this->card('ARMEN 9007 6660 S1 P', 'ARMEN 9007 6660 S1 P', [
            'category' => 'Obuwie',
            'description' => 'Sandały robocze ARTRA ARMEN 9007 6660 S1 P. Model spełnia klasę S1 według EN ISO 20345, '
                .'co oznacza podnosek ochronny oraz właściwości antyelektrostatyczne (ESD).',
        ]);
        $fireman = $this->card('V262-0-02', 'FIREMAN (02 NAVY)', ['description' => 'Buty gumowe FIREMAN']);
        $softClaim = $this->card('TRZ-1', 'Trzewiki robocze S1', ['description' => 'Antystatyczna podeszwa PU.']);
        $rubberSoft = $this->card('KAL-1', 'Kalosze gumowe S5', ['description' => 'Antystatyczna podeszwa.']);

        $this->assertTrue($this->assortment->productMeetsAntistaticRequirement($req, $sandals));
        $this->assertFalse($this->assortment->productMeetsAntistaticRequirement($req, $fireman));
        $this->assertFalse($this->assortment->productMeetsAntistaticRequirement($req, $softClaim));
        $this->assertTrue($this->assortment->productMeetsAntistaticRequirement('Kalosze antyelektrostatyczne S5', $rubberSoft));
    }

    #[Test]
    public function subtypes_conflict_only_when_both_known(): void
    {
        $reusable = 'Półmaska wielokrotnego użytku z łącznikami bagnetowymi PN-EN 140';
        $ffp = $this->card('9310+', '3M™ Aura™ półmaska filtrująca, FFP1, bez zaworu, 9310+');
        $secura = $this->card('S56T0SM0', 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)');

        $this->assertTrue($this->assortment->subtypesConflict($reusable, $ffp));
        $this->assertFalse($this->assortment->subtypesConflict($reusable, $secura), 'goła „półmaska” to nieznany podtyp');
        $this->assertTrue($this->assortment->subtypesConflict('Szelki bezpieczeństwa', $this->card('L-1', 'Linka bezpieczeństwa z amortyzatorem')));
        $this->assertFalse($this->assortment->subtypesConflict(
            'Ubranie robocze bluza + spodnie',
            $this->card('B-1', 'Bluza robocza KOLPEO')
        ), 'komplet przyjmuje bluzę');
        $this->assertFalse($this->assortment->subtypesConflict(
            'Rękawice nitrylowe antyprzecięciowe',
            $this->card('R-1', 'Rękawice powlekane poliuretanem')
        ), 'typy rękawic to nakładające się cechy');
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function card(string $sku, string $name, array $attrs = []): Product
    {
        $product = new Product;
        $product->forceFill(array_merge(['sku' => $sku, 'name' => $name], $attrs));

        return $product;
    }
}
