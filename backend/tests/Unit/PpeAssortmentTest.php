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
}
