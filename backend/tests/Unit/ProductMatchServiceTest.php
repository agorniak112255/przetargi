<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\ProductMatchService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProductMatchServiceTest extends TestCase
{
    private ProductMatchService $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = app(ProductMatchService::class);
    }

    #[Test]
    public function short_numeric_fragment_does_not_match_longer_sku(): void
    {
        $products = new Collection([
            $this->fakeProduct([
                'sku' => '60028',
                'name' => 'ATHLETIC ALLROUND',
                'manufacturer' => 'uvex',
                'description' => 'Rękawice ochronne uvex athletic allround do prac montażowych.',
            ]),
        ]);

        $best = $this->matcher->bestMatch(
            'Rękawice ochronne POLROK Safety PK 600 G - szare',
            $products
        );

        $this->assertNotNull($best);
        $this->assertLessThan(
            ProductMatchService::MIN_MATCH_SCORE,
            $best['score'],
            '600 nie może dawać trafienia na SKU 60028'
        );
    }

    #[Test]
    public function exact_sku_in_requirement_matches_strongly(): void
    {
        $products = new Collection([
            $this->fakeProduct([
                'sku' => '60028',
                'name' => 'ATHLETIC ALLROUND',
                'manufacturer' => 'uvex',
                'category' => 'Rękawice',
                'description' => 'Rękawice montażowe ATHLETIC ALLROUND.',
            ]),
            $this->fakeProduct([
                'sku' => '34-274',
                'name' => 'KW Palm Coated',
                'manufacturer' => 'ATG',
                'description' => 'Rękawice nitrylowe',
                'enrichment_payload' => ['materials' => ['nitryl']],
            ]),
        ]);

        $best = $this->matcher->bestMatch(
            'Rękawice ATHLETIC art. 60028 rozmiar 9',
            $products
        );

        $this->assertNotNull($best);
        $this->assertSame('60028', $best['product']->sku);
        $this->assertGreaterThanOrEqual(ProductMatchService::MIN_MATCH_SCORE, $best['score']);
    }

    #[Test]
    public function gloves_requirement_does_not_match_footwear(): void
    {
        $products = new Collection([
            $this->fakeProduct([
                'sku' => '7255',
                'name' => 'GLOSS UP 2 WINTER S3 CI SRC',
                'manufacturer' => 'uvex',
                'category' => 'obuwie',
                'description' => 'Trzewiki ocieplane winter S3 CI SRC.',
                'norms' => 'EN ISO 20345 S3 CI SRC',
            ]),
            $this->fakeProduct([
                'sku' => 'RWD-1',
                'name' => 'DRAGON WINTER RWD',
                'manufacturer' => 'DRAGON',
                'description' => 'Rękawice 5-palcowe ocieplane powlekane gumą.',
                'enrichment_payload' => ['materials' => ['guma']],
            ]),
        ]);

        $best = $this->matcher->bestMatch(
            'Rękawice 5-palcowe ocieplane termoodporne, powlekane gumą, DRAGON WINTER RWD',
            $products
        );

        $this->assertNotNull($best);
        $this->assertSame('RWD-1', $best['product']->sku);
        $this->assertNotSame('7255', $best['product']->sku);
    }

    #[Test]
    public function matches_by_material_and_model_code_when_sku_missing(): void
    {
        $products = new Collection([
            $this->fakeProduct([
                'sku' => '60028',
                'name' => 'ATHLETIC ALLROUND',
                'manufacturer' => 'uvex',
                'description' => 'Lekkie rękawice montażowe z powłoką piankową.',
            ]),
            $this->fakeProduct([
                'sku' => 'RNITZ-9',
                'name' => 'Rękawice nitrylowe ze ściągaczem',
                'manufacturer' => 'REJS / Raw Pol',
                'description' => 'Rękawice robocze nitrylowe RNITZ kat. 2 ze ściągaczem. Materiał: nitryl.',
                'enrichment_payload' => [
                    'materials' => ['nitryl'],
                    'features' => ['ściągacz'],
                ],
            ]),
        ]);

        $best = $this->matcher->bestMatch(
            'Rękawice robocze nitrylowe REJS (Raw Pol) RNITZ kat. 2 ze ściągaczem',
            $products
        );

        $this->assertNotNull($best);
        $this->assertSame('RNITZ-9', $best['product']->sku);
        $this->assertGreaterThanOrEqual(ProductMatchService::MIN_MATCH_SCORE, $best['score']);
    }

    #[Test]
    public function balaclava_does_not_match_gloves_via_norm_number(): void
    {
        $products = new Collection([
            $this->fakeProduct([
                'sku' => '11612',
                'name' => 'ROS - Rękawice 100% poliester, bezpyłowe',
                'manufacturer' => 'ROS',
                'category' => 'REKAWICE',
                'description' => 'Rękawice poliestrowe bezpyłowe.',
            ]),
            $this->fakeProduct([
                'sku' => 'KOM-ESD',
                'name' => 'Kominiarka antyelektrostatyczna',
                'manufacturer' => 'URGENT',
                'category' => 'ochrona_glowy',
                'norms' => 'EN 1149-5 EN ISO 11612 EN ISO 13688',
                'description' => 'Kominiarka z certyfikatem ESD do prac w strefie zagrożonej wybuchem.',
            ]),
        ]);

        $best = $this->matcher->bestMatch(
            'KOMINIARKA ANTYELEKTROSTATYCZNA z certyfikatem - EN 1149-5 EN ISO 11612 EN ISO 13688',
            $products
        );

        $this->assertNotNull($best);
        $this->assertSame('KOM-ESD', $best['product']->sku);
        $this->assertGreaterThanOrEqual(ProductMatchService::MIN_MATCH_SCORE, $best['score']);

        $explained = $this->matcher->explainMatch(
            'KOMINIARKA ANTYELEKTROSTATYCZNA z certyfikatem - EN 1149-5 EN ISO 11612 EN ISO 13688',
            $products[0]
        );
        $this->assertSame(0, $explained['score']);
        $this->assertSame('asortyment_reject', $explained['reasons'][0]['code'] ?? null);
    }

    #[Test]
    public function hivis_jacket_does_not_match_welding_jacket(): void
    {
        $products = new Collection([
            $this->fakeProduct([
                'sku' => 'VEST/C-M-XL',
                'name' => 'Kurtka spawalnicza Vest C M do XL',
                'manufacturer' => 'X',
                'category' => 'odziez',
                'description' => 'Kurtka spawalnicza do prac spawalniczych.',
                'norms' => 'EN ISO 11611',
            ]),
        ]);

        $best = $this->matcher->bestMatch(
            'KURTKA ROBOCZA ODBLASKOWA żółto-granatowa EN ISO 20471 EN ISO 13688',
            $products
        );

        $this->assertTrue($best === null || $best['score'] < ProductMatchService::MIN_MATCH_SCORE);

        $explained = $this->matcher->explainMatch(
            'KURTKA ROBOCZA ODBLASKOWA żółto-granatowa EN ISO 20471 EN ISO 13688',
            $products[0]
        );
        $this->assertSame(0, $explained['score']);
        $this->assertSame('asortyment_reject', $explained['reasons'][0]['code'] ?? null);
    }

    #[Test]
    public function electrician_set_does_not_match_single_heat_jacket(): void
    {
        $products = new Collection([
            $this->fakeProduct([
                'sku' => 'OB-58',
                'name' => 'Bluza ochronna żaroodporna C',
                'manufacturer' => 'X',
                'category' => 'odziez',
                'description' => 'Bluza żaroodporna.',
                'norms' => 'EN ISO 11612',
            ]),
        ]);

        $best = $this->matcher->bestMatch(
            'Ubranie ochronne dla elektryków (bluza + spodnie) EN 1149-5 EN ISO 11612',
            $products
        );

        $this->assertTrue($best === null || $best['score'] < ProductMatchService::MIN_MATCH_SCORE);
    }

    #[Test]
    public function lab_coat_does_not_match_gloves(): void
    {
        $products = new Collection([
            $this->fakeProduct([
                'sku' => '34-800',
                'name' => 'KW Palm Coated',
                'manufacturer' => 'ATG',
                'category' => 'Rękawice',
                'description' => 'Rękawice powlekane.',
            ]),
            $this->fakeProduct([
                'sku' => 'LAB-COAT',
                'name' => 'Fartuch laboratoryjny elano-bawełna',
                'manufacturer' => 'X',
                'category' => 'odziez',
                'description' => 'Fartuch lab. zapinany na zatrzaski, gramatura 210g.',
            ]),
        ]);

        $best = $this->matcher->bestMatch(
            'FARTUCH LAB. ELANO-BAWEŁNA prosty, biały, rękawy wykończone zatrzaską. EN ISO 13688',
            $products
        );

        $this->assertNotNull($best);
        $this->assertSame('LAB-COAT', $best['product']->sku);
    }

    #[Test]
    public function four_digit_model_matches_sku_with_suffix_without_description(): void
    {
        $products = new Collection([
            $this->fakeProduct([
                'sku' => 'HF-803',
                'name' => '3M Secure Click Półmaska HF-803',
                'manufacturer' => '3M',
                'description' => 'Półmaska wielokrotnego użytku Secure Click, karta z opisem.',
            ]),
            $this->fakeProduct([
                'sku' => '6503-EN',
                'name' => 'Półmaska 6503 część twarzowa, rozmiar: L duży',
                'manufacturer' => '3M',
                'description' => null,
            ]),
        ]);

        $best = $this->matcher->bestMatch('Półmaska 3M 6503', $products);

        $this->assertNotNull($best);
        $this->assertSame('6503-EN', $best['product']->sku);
        $this->assertGreaterThanOrEqual(ProductMatchService::MIN_MATCH_SCORE, $best['score']);
    }

    #[Test]
    public function four_digit_model_does_not_match_sku_with_extra_digits(): void
    {
        $products = new Collection([
            $this->fakeProduct([
                'sku' => '65030',
                'name' => 'Inny model',
                'manufacturer' => 'X',
                'description' => 'Opis wystarczająco długi do karty katalogowej.',
            ]),
        ]);

        $best = $this->matcher->bestMatch('Półmaska 3M 6503', $products);

        $this->assertTrue($best === null || $best['score'] < ProductMatchService::MIN_MATCH_SCORE);
    }

    #[Test]
    public function clothing_size_in_siwz_does_not_match_sku_suffix(): void
    {
        $products = new Collection([
            $this->fakeProduct([
                'sku' => '07-755-XXXXL',
                'name' => 'GVS Heavy Duty Blast Suit - XXXX Large',
                'manufacturer' => 'GVS',
                'category' => 'odziez',
                'description' => 'Kombinezon ochronny blast suit heavy duty.',
            ]),
        ]);

        $best = $this->matcher->bestMatch(
            'KALESONY bawełniane (100% bawełny) męskie (niebieskie) rozmiar od S do XXXXL',
            $products
        );

        $this->assertTrue($best === null || $best['score'] < ProductMatchService::MIN_MATCH_SCORE);

        $explained = $this->matcher->explainMatch(
            'KALESONY bawełniane (100% bawełny) męskie (niebieskie) rozmiar od S do XXXXL',
            $products[0]
        );
        $this->assertLessThan(40, $explained['score']);
    }

    #[Test]
    public function polar_jacket_does_not_match_pola_gloves(): void
    {
        $gloves = $this->fakeProduct([
            'sku' => 'POLA',
            'name' => 'POLA - EN 420 KAT. II, EN 388 - 3131',
            'manufacturer' => 'X',
            'category' => 'Rękawice',
            'description' => 'Rękawice ochronne POLA.',
            'norms' => 'EN 420 EN 388',
        ]);

        $req = 'KURTKA DAMSKA - POLAR granatowy rozm. S - XXXXL';
        $this->assertNull($this->matcher->bestMatch($req, new Collection([$gloves])));

        $explained = $this->matcher->explainMatch($req, $gloves);
        $this->assertSame(0, $explained['score']);
        $this->assertSame('asortyment_reject', $explained['reasons'][0]['code'] ?? null);
    }

    #[Test]
    public function electrician_set_does_not_match_waterproof_jacket_via_fastening_words(): void
    {
        $jacket = $this->fakeProduct([
            'sku' => '103',
            'name' => '103 - Kurtka wodoochronna zapinana na zamek + stójka',
            'manufacturer' => 'PROS / AJ Group',
            'category' => 'odziez',
            'description' => 'Kurtka wodoochronna zapinana na zamek.',
        ]);

        $req = 'Ubranie ochronne dla elektryków (bluza + spodnie) długa bluza zapinana na zatrzaski · EN 1149-5 IEC 61482';
        $this->assertNull($this->matcher->bestMatch($req, new Collection([$jacket])));

        $explained = $this->matcher->explainMatch($req, $jacket);
        $this->assertSame(0, $explained['score']);
        $this->assertSame('asortyment_reject', $explained['reasons'][0]['code'] ?? null);
    }

    #[Test]
    public function glasses_requirement_does_not_match_gloves_even_with_shared_sku(): void
    {
        $gloves = $this->fakeProduct([
            'sku' => '11-541',
            'name' => 'HYFLEX 11-541 Rękawice montażowe',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze HyFlex 11-541.',
        ]);
        $glasses = $this->fakeProduct([
            'sku' => 'SF401',
            'name' => 'Okulary ochronne bezbarwne',
            'manufacturer' => '3M',
            'category' => 'ochrona_oczu',
            'description' => 'Okulary ochronne 3M SecureFit.',
        ]);
        $products = new Collection([$gloves, $glasses]);

        $req = 'Okulary ochronne przyciemniane HYFLEX 11-541';
        $best = $this->matcher->bestMatch($req, $products);
        $this->assertNotNull($best);
        $this->assertSame('SF401', $best['product']->sku);

        $explained = $this->matcher->explainMatch($req, $gloves);
        $this->assertSame(0, $explained['score']);
        $this->assertSame('asortyment_reject', $explained['reasons'][0]['code'] ?? null);
    }

    #[Test]
    public function rain_jacket_does_not_match_respirator(): void
    {
        $mask = $this->fakeProduct([
            'sku' => 'SECURA-3000',
            'name' => 'Półmaska SECURA 3000 część twarzowa',
            'manufacturer' => 'SECURA',
            'category' => 'drogi_oddechowe',
            'description' => 'Półmaska wielokrotnego użytku.',
        ]);
        $explained = $this->matcher->explainMatch(
            'Kurtka przeciwdeszczowa EN 343 EN 1149-5',
            $mask
        );
        $this->assertSame(0, $explained['score']);
        $this->assertSame('asortyment_reject', $explained['reasons'][0]['code'] ?? null);
        $this->assertNull($this->matcher->bestMatch('Kurtka przeciwdeszczowa EN 343 EN 1149-5', new Collection([$mask])));
    }

    #[Test]
    public function gloves_requirement_does_not_match_rain_set(): void
    {
        $set = $this->fakeProduct([
            'sku' => 'B50',
            'name' => 'Komplet przeciwdeszczowy B50 bluza + spodnie',
            'manufacturer' => 'X',
            'category' => 'odziez',
            'description' => 'Ubranie przeciwdeszczowe komplet.',
        ]);
        $explained = $this->matcher->explainMatch('Rękawice lateksowe sterylne', $set);
        $this->assertSame(0, $explained['score']);
        $this->assertSame('asortyment_reject', $explained['reasons'][0]['code'] ?? null);
        $this->assertNull($this->matcher->bestMatch('Rękawice lateksowe sterylne', new Collection([$set])));
    }

    #[Test]
    public function vest_requirement_does_not_match_mesh_face_shield(): void
    {
        $products = new Collection([
            $this->fakeProduct([
                'sku' => '12-0423',
                'name' => 'Osłona twarzy żaroodporna siatkowa',
                'manufacturer' => 'ALWIT POLAND',
                'description' => 'Osłona twarzy siatkowa żaroodporna do prac spawalniczych.',
            ]),
            $this->fakeProduct([
                'sku' => 'V4B',
                'name' => '3M™ Osłona twarzy siatkowa, pol',
                'manufacturer' => '3M',
                'description' => 'Osłona twarzy siatkowa 3M V4B.',
            ]),
            $this->fakeProduct([
                'sku' => 'VEST-HV',
                'name' => 'Kamizelka odblaskowa żółta siatkowa',
                'manufacturer' => 'X',
                'category' => 'odziez',
                'description' => 'Kamizelka ostrzegawcza EN 20471 klasa 1, góra siatkowa.',
                'norms' => 'EN ISO 20471',
            ]),
        ]);

        $req = 'KAMIZELKA ODBLASKOWA żółta SIATKOWA z nadrukiem z przodu, góra siatkowa, dół materiał · EN 20471 kl. 1';
        $best = $this->matcher->bestMatch($req, $products);

        $this->assertNotNull($best);
        $this->assertSame('VEST-HV', $best['product']->sku);

        $explained = $this->matcher->explainMatch($req, $products[0]);
        $this->assertSame(0, $explained['score']);
        $this->assertSame('asortyment_reject', $explained['reasons'][0]['code'] ?? null);
    }

    #[Test]
    public function typo_in_siwz_model_matches_catalog_name_not_other_brand(): void
    {
        $products = new Collection([
            $this->fakeProduct([
                'sku' => '60592',
                'name' => 'Rękawice zimowe Unilite Thermo Plus',
                'manufacturer' => 'uvex',
                'description' => 'Rękawice zimowe zgodne z EN 388 EN 511 EN ISO 21420 karta z opisem.',
                'norms' => 'EN 388 EN 511 EN ISO 21420',
            ]),
            $this->fakeProduct([
                'sku' => '34700018',
                'name' => 'TEMP-ICE 700',
                'manufacturer' => 'MAPA',
                'norms' => 'EN 388 EN 511',
                'description' => null,
            ]),
        ]);

        $best = $this->matcher->bestMatch(
            'Rękawice MAPA TEPM-ICE 700 · EN 388 EN 511 EN ISO 21420',
            $products
        );

        $this->assertNotNull($best);
        $this->assertSame('34700018', $best['product']->sku);
        $this->assertGreaterThanOrEqual(ProductMatchService::MIN_MATCH_SCORE, $best['score']);
    }

    #[Test]
    public function persistable_score_accepts_first_ai_result_from_apply_threshold(): void
    {
        $product = $this->fakeProduct([
            'sku' => 'RNITZ-9',
            'name' => 'Rękawice nitrylowe ze ściągaczem',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze nitrylowe RNITZ kat. 2 ze ściągaczem. Materiał: nitryl.',
            'enrichment_payload' => ['materials' => ['nitryl']],
        ]);
        $req = 'Rękawice robocze nitrylowe REJS RNITZ kat. 2 ze ściągaczem';
        $method = new \ReflectionMethod(ProductMatchService::class, 'persistableScore');

        $accepted = $method->invoke($this->matcher, $req, $product, 45);
        $this->assertSame(45, $accepted);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function fakeProduct(array $attrs): Product
    {
        $p = new Product;
        $p->forceFill(array_merge([
            'id' => random_int(1, 999999),
            'sku' => 'X',
            'name' => 'X',
            'manufacturer' => 'X',
            'category' => null,
            'norms' => null,
            'description' => null,
            'enrichment_payload' => null,
            'purchase_price' => 1,
            'catalog_price_net' => 1,
        ], $attrs));

        return $p;
    }
}
