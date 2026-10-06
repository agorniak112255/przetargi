<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\AnsellAssetBankImagePicker;
use Tests\TestCase;

/** Tytuły i regiony z portalu zdjęć Ansella (wyszukiwania z 06.10.2026). */
final class AnsellAssetBankImagePickerTest extends TestCase
{
    public function test_glove_takes_emea_front_packshot_and_skips_application_shots(): void
    {
        $edge = new Product(['sku' => '48501110', 'name' => 'EDGE 48501', 'manufacturer' => 'Ansell']);
        $assets = [
            $this->asset(24772, 'EDGE 48-501 Black Product Prop EMEA - Pipe', ['EMEA']),
            $this->asset(24775, 'EDGE 48-501 Black Product NA - Front', ['North America']),
            $this->asset(24780, 'EDGE 48-501 Black Product - Back', []),
            $this->asset(24770, 'EDGE 48-501 Black Product EMEA - Front', ['EMEA']),
            $this->asset(42595, '48-501 EDGE Black and white EMEA - U-Card', ['Asia Pacific', 'EMEA']),
        ];

        $this->assertSame([24770, 42595, 24780], array_column($this->picker()->ranked($edge, $assets), 'id'));

        $hyflex = new Product(['sku' => '11840120', 'name' => 'HyFlex 11840', 'manufacturer' => 'Ansell']);
        $this->assertSame(39648, $this->picker()->pick($hyflex, [
            $this->asset(46819, 'HyFlex 11-840 Gray Product - E-Commerce', []),
            $this->asset(45171, 'HyFlex 11-840 Automotive Application NA/LAC - Auto Body Technician', ['Latin America', 'North America']),
            $this->asset(285486, 'Hyflex 11-840 Amazon Image Stack - Single', ['North America']),
            $this->asset(39648, 'HyFlex 11-840 Gray and Black Product- Back', ['Asia Pacific', 'EMEA', 'Latin America', 'North America']),
            $this->asset(50000, 'HyFlex 11-842 Gray Product - Front', ['EMEA']),
        ])['id'] ?? null);
        $this->assertNull($this->picker()->pick($hyflex, [$this->asset(50001, 'HyFlex 11-840 with 11-800 Product - Front', ['EMEA'])]));

        // jedno zdjęcie rodziny z listą numerów
        $isolator = new Product(['sku' => '853011', 'name' => 'ALPHATEC 85301 8Y2432A S110', 'manufacturer' => 'Ansell']);
        $family = [$this->asset(22782, 'AlphaTec 85-300/301/302/303/304/305 CSM White Iso Box Product', [])];
        $this->assertSame(22782, $this->picker()->pick($isolator, $family)['id'] ?? null);
        $other = new Product(['sku' => '855011', 'name' => 'ALPHATEC 85501 ISOLATOR 8Tb2432A S11,0', 'manufacturer' => 'Ansell']);
        $this->assertNull($this->picker()->pick($other, $family));
    }

    public function test_ringers_suit_and_rigs_titles(): void
    {
        $ringers = new Product(['sku' => '840-12VP', 'name' => 'Ringers R840VP', 'manufacturer' => 'Ansell']);
        $this->assertSame(189938, $this->picker()->pick($ringers, [
            $this->asset(189540, 'R840 test', ['Global']),
            $this->asset(189939, 'Ringers R840 Side Photo Global', ['Global']),
            $this->asset(189938, 'Ringers R840 UCard Global', ['Global']),
            $this->asset(190001, 'Ringers R169 UCard Global', ['Global']),
        ])['id'] ?? null);

        // „Ringers 267 primary/top/palm/pair” — bez litery R; zdjęcie reklamowe „Super Hero” odpada
        $r267 = new Product(['sku' => '267-14', 'name' => 'Ringers 267', 'manufacturer' => 'Ansell']);
        $this->assertSame(['Ringers 267', 'Ringers R267'], $this->picker()->target($r267)['needles'] ?? null);
        $this->assertSame([48773, 48774, 48772, 48771], array_column($this->picker()->ranked($r267, [
            $this->asset(48772, 'Ringers 267 palm', []),
            $this->asset(48771, 'Ringers 267 pair', ['Global']),
            $this->asset(45255, 'Ringers 267 Super Hero', []),
            $this->asset(48774, 'Ringers 267 top', []),
            $this->asset(48773, 'Ringers 267 primary', []),
        ]), 'id'));

        $suit = new Product(['sku' => 'GR40T-00162-09', 'name' => '4000-GR CVRL HOOD 162.5XL', 'manufacturer' => 'Ansell']);
        $this->assertSame(141532, $this->picker()->pick($suit, [
            $this->asset(48306, 'AlphaTec 4000-185 Green Product - Back 1', ['EMEA']),
            $this->asset(141532, 'AlphaTec 4000-162 Green Product - Back', ['Asia Pacific', 'EMEA', 'North America']),
            $this->asset(141533, 'AlphaTec 4000-162 Orange Product - Front', ['EMEA']),
        ])['id'] ?? null);

        $rigs = new Product(['sku' => 'RIG0011B120', 'name' => 'ACTIVARMR RIG CL00 11in B SZ 12', 'manufacturer' => 'Ansell']);
        $this->assertSame(155827, $this->picker()->pick($rigs, [
            $this->asset(155829, 'RIG CL1 Black Product EMEA/APAC', ['Asia Pacific', 'EMEA']),
            $this->asset(202362, 'ActivArmr RIG CL00 Red Product Front', ['Global']),
            $this->asset(155827, 'RIG CL00 Black Product EMEA/APAC', ['Asia Pacific', 'EMEA']),
            $this->asset(202358, 'ActivArmr RIGS Bi-Color Class 0 Yellow 14 _ Front_1', ['Global']),
        ])['id'] ?? null);

        $bicolour = new Product(['sku' => 'RIG014YBSC120', 'name' => 'ACTIVARMR RIG CL0 14in YB SC SZ 12', 'manufacturer' => 'Ansell']);
        $this->assertSame(202358, $this->picker()->pick($bicolour, [
            $this->asset(155827, 'RIG CL00 Black Product EMEA/APAC', ['Asia Pacific', 'EMEA']),
            $this->asset(202358, 'ActivArmr RIGS Bi-Color Class 0 Yellow 14 _ Front_1', ['Global']),
            $this->asset(167347, 'ActivArmr RIGS Bi-Color Class 1 14 – Front', ['Global']),
        ])['id'] ?? null);
    }

    public function test_named_model_width_and_rigs_without_view_word(): void
    {
        $chemSuits = [
            $this->asset(26590, 'AlphaTec 66-300 Model 122 Product Back', []),
            $this->asset(284781, 'AlphaTec 66-300 Model 122-G09 - Front', ['Global']),
            $this->asset(305605, 'AlphaTec 66-300 Red Front static', ['Global']),
        ];
        $model111 = new Product(['sku' => '66300111', 'name' => 'AlphaTec 66-300 model 111-G09, 3XL', 'manufacturer' => 'Ansell']);
        $this->assertSame([], $this->picker()->ranked($model111, $chemSuits), 'inny model kombinezonu i tytuł bez modelu nie pasują');
        $model122 = new Product(['sku' => '66300122', 'name' => 'AlphaTec 66-300 model 122-G09, 3XL/48', 'manufacturer' => 'Ansell']);
        $this->assertSame([284781, 26590], array_column($this->picker()->ranked($model122, $chemSuits), 'id'));
        $model156 = new Product(['sku' => '66330156', 'name' => 'AlphaTec 66330 model 156-G09 Orange 3XL', 'manufacturer' => 'Ansell']);
        $this->assertSame(305612, $this->picker()->pick($model156, [
            $this->asset(305612, 'AlphaTec 66-330 Model 151-G09 and 156-G09 Orange Static Front', ['Global']),
        ])['id'] ?? null);

        // linia kombinezonu między serią a krojem; detale (zamek, kostka) i dwa kombinezony na zdjęciu odpadają
        $comfort = new Product(['sku' => 'WH20C-00129-16', 'name' => '2000-WH CMF CVRL HOOD SMS BACK 129.6XL', 'manufacturer' => 'Ansell']);
        $this->assertSame([46552, 274670], array_column($this->picker()->ranked($comfort, [
            $this->asset(274672, 'AlphaTec 2000 129 static white product - zip flap', ['Global']),
            $this->asset(274665, 'AlphaTec 2000 129 static white product - elasticated ankle', ['Global']),
            $this->asset(46552, 'AlphaTec 2000 COMFORT_129_White_Product_Front', ['Asia Pacific', 'EMEA', 'Latin America']),
            $this->asset(274670, 'AlphaTec 2000 129 static white product - front', ['Global']),
            $this->asset(46554, 'AlphaTec 2000 COMFORT-129 White Product NA - Front', ['North America']),
        ]), 'id'));
        $standard = new Product(['sku' => 'WH20S-00156-13', 'name' => '2000-WH STD CVRL HOOD SOCKS 156.3XL', 'manufacturer' => 'Ansell']);
        $this->assertSame(47809, $this->picker()->pick($standard, [
            $this->asset(46569, 'AlphaTec 2000 Ts PLUS_156_White_Product_Front', ['Asia Pacific', 'EMEA', 'Latin America']),
            $this->asset(47809, 'AlphaTec 2000 STANDARD-156 White Product - Front', ['Asia Pacific', 'EMEA', 'Latin America']),
        ])['id'] ?? null);
        $plus = new Product(['sku' => 'BL15P-00111-15', 'name' => '1500-BL PLUS CVRL HOOD 111.5XL', 'manufacturer' => 'Ansell']);
        $this->assertSame(47752, $this->picker()->pick($plus, [
            $this->asset(48411, 'AlphaTec 1500 PLUS FR-111 Blue Product - Front', ['EMEA']),
            $this->asset(47752, 'AlphaTec 1500 PLUS-111 Blue Product - Front', ['Asia Pacific', 'EMEA', 'Latin America']),
        ])['id'] ?? null);
        $white2000 = new Product(['sku' => 'WH20S-00111-15', 'name' => '2000-WH STD CVRL HOOD 111.5XL', 'manufacturer' => 'Ansell']);
        $this->assertNull($this->picker()->pick($white2000, [
            $this->asset(189801, 'AlphaTec 2300 PLUS-205 Yellow Product with AlphaTec 2000 STANDARD-111 White Product - Front', ['EMEA']),
        ]));
        $apron = new Product(['sku' => 'GR40T-00215-13', 'name' => '4000-GR SLEEVED APRON 215.3XL', 'manufacturer' => 'Ansell']);
        $this->assertSame(48319, $this->picker()->pick($apron, [
            $this->asset(48319, 'AlphaTec 4000 Apron with Sleeves-215 Green Product - Front', ['Asia Pacific', 'EMEA', 'North America']),
        ])['id'] ?? null);

        $cfrRed = [$this->asset(48424, 'AlphaTec CFR-111 Red Product - Front', ['Asia Pacific', 'EMEA', 'Latin America', 'North America'])];
        $frSuit = new Product(['sku' => 'WR17T-00111-15', 'name' => '1500-WR PLUS FR CVRL HOOD 111.5XL', 'manufacturer' => 'Ansell']);
        $this->assertNotContains('CFR-111', $this->picker()->target($frSuit)['needles'] ?? []);
        $this->assertNull($this->picker()->pick($frSuit, $cfrRed), '1500 PLUS FR to nie AlphaTec CFR');
        $sameNumber = new Product(['sku' => 'YY23T-00111-15', 'name' => '2300-YY STD CVRL HOOD 111.5XL', 'manufacturer' => 'Ansell']);
        $this->assertNull($this->picker()->pick($sameNumber, $cfrRed));
        $cfr = new Product(['sku' => 'OR47T-00111-15', 'name' => '4000 CFR-OR CVRL HOOD 111.5XL', 'manufacturer' => 'Ansell']);
        $this->assertContains('CFR-111', $this->picker()->target($cfr)['needles'] ?? []);
        $this->assertNull($this->picker()->pick($cfr, $cfrRed), 'pomarańczowy CFR to nie czerwony');
        $this->assertSame(48425, $this->picker()->pick($cfr, [
            $this->asset(48425, 'AlphaTec CFR-111 Orange Product - Front', ['EMEA']),
        ])['id'] ?? null);

        $sleeves = [$this->asset(26407, 'HyFlex 11-251 Sleeve Narrow Black Product - Front', [])];
        $wide = new Product(['sku' => '11251180W', 'name' => 'HyFlex 11251 SIZE 18.0 EXTRA WIDE THUMB', 'manufacturer' => 'Ansell']);
        $this->assertNull($this->picker()->pick($wide, $sleeves));
        $cutName = new Product(['sku' => '11251000XW', 'name' => 'HyFlex 11251 " THUMBSLOT EXTRA WI', 'manufacturer' => 'Ansell']);
        $this->assertNull($this->picker()->pick($cutName, $sleeves), 'ucięte „EXTRA WI” to też szeroki rękaw');
        $narrow = new Product(['sku' => '11251180N', 'name' => 'HyFlex 11251 " thumbslot Narrow', 'manufacturer' => 'Ansell']);
        $this->assertSame(26407, $this->picker()->pick($narrow, $sleeves)['id'] ?? null);

        $rigs = [
            $this->asset(196868, 'ActivArmr RIG Bi-Color Group Image', ['Global']),
            $this->asset(155835, 'ActivArmr RIGS Class 2- Black', ['Asia Pacific', 'EMEA', 'Latin America', 'North America']),
            $this->asset(166761, 'ActivArmr RIG Bi-Color Class 4 18 inch Global', ['Asia Pacific', 'EMEA', 'Latin America', 'North America']),
        ];
        $cl2 = new Product(['sku' => 'RIG214B120', 'name' => 'ACTIVARMR RIG CL2 14in B SZ 12', 'manufacturer' => 'Ansell']);
        $this->assertSame(155835, $this->picker()->pick($cl2, $rigs)['id'] ?? null);
        $cl2Red = new Product(['sku' => 'RIG214R120', 'name' => 'ACTIVARMR RIG CL2 14in R SZ 12', 'manufacturer' => 'Ansell']);
        $this->assertNull($this->picker()->pick($cl2Red, $rigs));
        $cl4 = new Product(['sku' => 'RIG418YBSC120', 'name' => 'ACTIVARMR RIG CL 4 18in YB SC SZ 12', 'manufacturer' => 'Ansell']);
        $this->assertSame(166761, $this->picker()->pick($cl4, $rigs)['id'] ?? null);
    }

    public function test_cards_without_portal_model_and_rights(): void
    {
        $this->assertNull($this->picker()->target(new Product(['sku' => '98800', 'name' => 'KLNGD A40 Overboot White Univ', 'manufacturer' => 'Ansell'])));
        $this->assertTrue(AnsellAssetBankImagePicker::rightsAllowed('Unlimited Use'));
        $this->assertTrue(AnsellAssetBankImagePicker::rightsAllowed('Limited Use - Region-specific'));
        $this->assertFalse(AnsellAssetBankImagePicker::rightsAllowed('Internal Use Only'));
        $this->assertFalse(AnsellAssetBankImagePicker::rightsAllowed('Limited Use - Customer-specific'));
    }

    private function picker(): AnsellAssetBankImagePicker
    {
        return app(AnsellAssetBankImagePicker::class);
    }

    /**
     * @param  list<string>  $regions
     * @return array{id: int, title: string, subcategory: string, regions: list<string>}
     */
    private function asset(int $id, string $title, array $regions): array
    {
        return ['id' => $id, 'title' => $title, 'subcategory' => 'Product images - static', 'regions' => $regions];
    }
}
