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
            ['Półmaska 3M 6503 część twarzowa', PpeAssortment::FAMILY_RESPIRATORY],
            ['Hełm przemysłowy z osłoną', PpeAssortment::FAMILY_HEAD],
            ['Kominiarka antyelektrostatyczna', PpeAssortment::FAMILY_HEAD],
            ['Szelki bezpieczeństwa z linką', PpeAssortment::FAMILY_FALL],
            ['Nakolanniki żelowe', PpeAssortment::FAMILY_KNEE],
            ['Rękawice nitrylowe RNITZ', PpeAssortment::FAMILY_GLOVES],
            ['Trzewiki S3 ocieplane', PpeAssortment::FAMILY_FOOTWEAR],
            ['Kurtka ochronna ocieplana z kapturem', PpeAssortment::FAMILY_APPAREL],
            ['POLA - EN 420 KAT. II, EN 388 - 3131', PpeAssortment::FAMILY_GLOVES],
            ['Kalesony bawełniane męskie', PpeAssortment::FAMILY_APPAREL],
            ['Fartuch laboratoryjny', PpeAssortment::FAMILY_APPAREL],
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
}
