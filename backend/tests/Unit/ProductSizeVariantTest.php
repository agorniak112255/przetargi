<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProductSizeVariant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProductSizeVariantTest extends TestCase
{
    #[Test]
    public function groups_ansell_names_that_differ_only_by_size(): void
    {
        $svc = new ProductSizeVariant;

        $a = $svc->groupKey('Ansell', 'AlphaTec 37695VP Size 7.0', '37695VP070');
        $b = $svc->groupKey('Ansell', 'AlphaTec 37695VP Size 10.0', '37695VP100');

        $this->assertNotNull($a);
        $this->assertSame($a, $b);
        $this->assertSame('37695VP', $svc->skuCore('37695VP100', 'AlphaTec 37695VP Size 10.0'));
        $this->assertSame('AlphaTec 37695VP', $svc->stripSizeFromName('AlphaTec 37695VP Size 10.0'));
    }

    #[Test]
    public function does_not_group_different_models(): void
    {
        $svc = new ProductSizeVariant;

        $a = $svc->groupKey('Ansell', 'AlphaTec 37695VP Size 10.0', '37695VP100');
        $b = $svc->groupKey('Ansell', 'AlphaTec 37900VP Size 10.0', '37900VP100');

        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function same_price_bucket_only_when_catalog_and_purchase_match(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertSame($svc->priceBucket(2.85, 2.85), $svc->priceBucket('2.85', 2.85));
        $this->assertNotSame($svc->priceBucket(2.85, 2.85), $svc->priceBucket(3.96, 3.96));
    }

    #[Test]
    public function ignores_sku_without_size_in_name_or_known_suffix(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertNull($svc->groupKey('X', 'Rękawice test', 'MAXIFLEX34874'));
    }

    #[Test]
    public function parse_size_list_from_packaging(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertSame(['7', '8', '9', '10', '11'], $svc->parseSizeList('7, 8, 9, 10, 11'));
        $this->assertSame(['6.5-7', '7.5-8'], $svc->parseSizeList('6.5-7, 7.5-8'));
        $this->assertSame(['10'], $svc->parseSizeList(null, 'AlphaTec Size 10.0', '37695VP100'));
        $this->assertSame(
            ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47', '48'],
            $svc->parseSizeList('36-48')
        );
        $this->assertSame(
            ['46', '48', '50', '52', '54', '56', '58', '60', '62'],
            $svc->parseSizeList('od 46 do 62')
        );
        $this->assertSame(['s', 'm', 'l', 'xl', 'xxl'], $svc->parseSizeList('S-XXL'));
    }

    #[Test]
    public function extracts_footwear_range_from_description(): void
    {
        $svc = new ProductSizeVariant;
        $text = "Normy i certyfikaty\n— EN ISO 20345:2011 S1 P SRC\n"
            ."Rozmiary obuwia\n— Rozmiary unisex od 36 do 48. Aby dobrać odpowiedni rozmiar, "
            .'sprawdź tabelę rozmiarów producenta.';

        $this->assertSame(
            ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47', '48'],
            $svc->parseSizesFromText($text)
        );
        $this->assertSame('36-48', $svc->formatPackaging($svc->parseSizesFromText($text)));
    }

    #[Test]
    public function extracts_glove_and_clothing_ranges(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertSame(
            ['7', '8', '9', '10', '11'],
            $svc->parseSizesFromText('Dostępne rozmiary: 7, 8, 9, 10, 11')
        );
        $this->assertSame(
            ['s', 'm', 'l', 'xl', 'xxl', 'xxxl'],
            $svc->parseSizesFromText('Rozmiary odzieży od S do XXXL.')
        );
        $this->assertSame(
            ['46', '48', '50', '52', '54', '56', '58', '60', '62'],
            $svc->parseSizesFromText('Rozmiary spodni: 46-62')
        );
        $this->assertSame(['9'], $svc->parseSizesFromText('Rozmiar: 9'));
        $this->assertSame([], $svc->parseSizesFromText(
            'EN ISO 20345:2011 S1 P SRC — pełna ochrona. ESD wg EN IEC 61340-4-3:2018.'
        ));
    }

    #[Test]
    public function fills_empty_packaging_from_description_range(): void
    {
        $svc = new ProductSizeVariant;
        $sizes = $svc->parseSizesFromText('Rozmiary unisex od 36 do 48.');

        $this->assertTrue($svc->shouldFillPackaging(null, $sizes));
        $this->assertTrue($svc->shouldFillPackaging('para', $sizes));
        $this->assertTrue($svc->shouldFillPackaging('42', $sizes));
        $this->assertFalse($svc->shouldFillPackaging('7, 8, 9, 10, 11', $sizes));
    }

    #[Test]
    public function rejects_clothing_label_for_footwear_and_reads_bare_eu_range(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertSame([], $svc->parseSizesFromText('1-5XL'));
        $this->assertNull($svc->labelFromTexts('1-5XL', 'EN 20347, EN 13688', 'obuwie'));
        $this->assertSame('39-47', $svc->labelFromTexts('1-5XL', 'Taglie disponibili 39-47', 'obuwie'));
        $this->assertSame(
            ['38', '39', '40', '41', '42', '43', '44', '45', '46', '47'],
            $svc->parseBareFootwearRange('EN 20347 SRC. 38-47. ESD.')
        );
        $this->assertSame([], $svc->parseBareFootwearRange('EN ISO 20345:2011 S1 P SRC'));
    }

    #[Test]
    public function extracts_shop_buy_options_and_spaced_size_grid(): void
    {
        $svc = new ProductSizeVariant;
        $this->assertSame(
            ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47'],
            $svc->parseSizesFromText('Rozmiar: 36 37 38 39 40 41 42 43 44 45 46 47')
        );
        $this->assertSame(
            ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47'],
            $svc->parseShopOptionSizes(
                '<label>Rozmiar:</label><div class="product-sizes">'
                .'<button>36</button><button>37</button><button>38</button>'
                .'<button>39</button><button>40</button><button>41</button>'
                .'<button>42</button><button>43</button><button>44</button>'
                .'<button>45</button><button>46</button><button>47</button></div>'
            )
        );
        $this->assertSame(
            ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47'],
            $svc->parseShopOptionSizes(
                'Kody producenta: JET3SPNO36, JET3SPNO47, JET3SPNO46, JET3SPNO45, '
                .'JET3SPNO44, JET3SPNO43, JET3SPNO42, JET3SPNO41, JET3SPNO40, '
                .'JET3SPNO39, JET3SPNO38, JET3SPNO37'
            )
        );
        $this->assertSame(
            ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47'],
            $svc->parseShopOptionSizes(
                '<select id="group_1" class="form-control" name="group[1]" aria-label="Rozmiar">'
                .'<option>36</option><option>37</option><option>38</option><option>39</option>'
                .'<option>40</option><option>41</option><option>42</option><option>43</option>'
                .'<option>44</option><option>45</option><option>46</option><option>47</option>'
                .'</select>'
            )
        );
        $this->assertSame(
            [],
            $svc->parseShopOptionSizes('EN ISO 20345:2011 S1 P SRC. ID produktu 22243.')
        );
        $this->assertSame(
            ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47'],
            $svc->parseShopOptionSizes(
                '<div class="attributes-box"><div class="attribute-a">'
                .'<div class="name"><p>Rozmiar:</p><div class="selected"><p>Wybrano:</p></div></div>'
                .'<div class="list"><div id="opcja_22243_20_0">'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0">'
                .' <label for="atrybuty_22243_20_0">36</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_2">'
                .' <label for="atrybuty_22243_20_0_2">37</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_3">'
                .' <label for="atrybuty_22243_20_0_3">38</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_4">'
                .' <label for="atrybuty_22243_20_0_4">39</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_5">'
                .' <label for="atrybuty_22243_20_0_5">40</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_6">'
                .' <label for="atrybuty_22243_20_0_6">41</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_7">'
                .' <label for="atrybuty_22243_20_0_7">42</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_8">'
                .' <label for="atrybuty_22243_20_0_8">43</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_9">'
                .' <label for="atrybuty_22243_20_0_9">44</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_10">'
                .' <label for="atrybuty_22243_20_0_10">45</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_11">'
                .' <label for="atrybuty_22243_20_0_11">46</label></p>'
                .'<p><input type="radio" name="atrybuty_22243[20]" id="atrybuty_22243_20_0_12">'
                .' <label for="atrybuty_22243_20_0_12">47</label></p>'
                .'</div></div></div></div>'
            )
        );
        $this->assertSame(
            '36-47',
            $svc->labelFromTexts(null, 'Rozmiar: 36 37 38 39 40 41 42 43 44 45 46 47', 'obuwie')
        );
    }

    #[Test]
    public function reads_idosell_select2_glove_sizes_not_footwear_chart(): void
    {
        $svc = new ProductSizeVariant;
        $html = '<table class="product-parameters"><tr><td>'
            .'<span class="parameter-name">Rozmiary rękawic</span> <br></td><td>'
            .'<select class="select-field-select2 core_parseOption" data-placeholder="Wybierz">'
            .'<option></option>'
            .'<option value="14220" name="option_15-134792">6</option>'
            .'<option value="14221" name="option_15-134792">7</option>'
            .'<option value="14222" name="option_15-134792">8</option>'
            .'<option value="14223" name="option_15-134792">9</option>'
            .'<option value="14224" name="option_15-134792">10</option>'
            .'<option value="14225" name="option_15-134792">11</option>'
            .'</select></td></tr></table>'
            .'<footer>Rozmiary unisex od 35 do 49. Tabela obuwia.</footer>';

        $this->assertSame(
            ['6', '7', '8', '9', '10', '11'],
            $svc->parseShopOptionSizes($html)
        );
        $this->assertSame(
            ['6', '7', '8', '9', '10', '11'],
            $svc->pickBestSizeList(
                [$svc->parseShopOptionSizes($html), ['35', '36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47', '48', '49']],
                'rekawice'
            )
        );
    }

    #[Test]
    public function reads_glove_sizes_from_sku_slash_table(): void
    {
        $svc = new ProductSizeVariant;
        $html = '<table><tr><td>A5016/06</td><td>A5016/07</td><td>A5016/08</td>'
            .'<td>A5016/09</td><td>A5016/10</td><td>A5016/11</td></tr></table>';
        $this->assertSame(
            ['6', '7', '8', '9', '10', '11'],
            $svc->parseShopOptionSizes('A5016/06 A5016/07 A5016/08 A5016/09 A5016/10 A5016/11')
        );

        $this->assertSame(
            ['6', '7', '8', '9', '10', '11'],
            $svc->parseShopOptionSizes($html)
        );
    }
}
