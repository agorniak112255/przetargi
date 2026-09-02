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
}
