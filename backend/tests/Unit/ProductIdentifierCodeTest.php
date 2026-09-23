<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductIdentifier;
use App\Support\ProductIdentifierCode;
use PHPUnit\Framework\TestCase;

final class ProductIdentifierCodeTest extends TestCase
{
    public function test_ean13_becomes_gtin14_with_leading_zero(): void
    {
        // EAN rozmiaru Mascot i EAN Protekt z produkcji
        $this->assertSame('05711074644834', ProductIdentifierCode::gtin('5711074644834'));
        $this->assertSame('05906800613776', ProductIdentifierCode::gtin(' 590 6800-613776 '));
    }

    public function test_gtin14_with_zero_upc12_and_ean13_share_one_key(): void
    {
        // 3M podaje GTIN-14 z zerem, dystrybutor ten sam wyrób jako EAN-13
        $this->assertSame(ProductIdentifierCode::gtin('5039269215752'), ProductIdentifierCode::gtin('05039269215752'));
        $this->assertSame(ProductIdentifierCode::gtin('0036000291452'), ProductIdentifierCode::gtin('036000291452'));
        $this->assertSame('00036000291452', ProductIdentifierCode::gtin('036000291452'));
    }

    public function test_carton_gtin_with_indicator_stays_different_from_unit(): void
    {
        $carton = ProductIdentifierCode::gtin('50051138661972');

        // wskaźnik opakowania (5) zostaje w kluczu — karton nie sprowadza się do EAN-13 sztuki
        $this->assertSame('50051138661972', $carton);
    }

    public function test_ean8_is_accepted(): void
    {
        $this->assertSame('00000096385074', ProductIdentifierCode::gtin('96385074'));
    }

    public function test_wrong_check_digit_spreadsheet_exponent_and_junk_give_null(): void
    {
        $this->assertNull(ProductIdentifierCode::gtin('5711074644835'));
        $this->assertNull(ProductIdentifierCode::gtin('5.90680E+12'));
        $this->assertNull(ProductIdentifierCode::gtin('0000000000000'));
        $this->assertNull(ProductIdentifierCode::gtin('59068006137'));
        $this->assertNull(ProductIdentifierCode::gtin('EAN 5906800613776'));
        $this->assertNull(ProductIdentifierCode::gtin(''));
    }

    public function test_code_keeps_letters_digits_and_leading_zeros(): void
    {
        $this->assertSame('3MMAS6000', ProductIdentifierCode::code('3m-mas-6000'));
        $this->assertSame(ProductIdentifierCode::code('2111 237'), ProductIdentifierCode::code('2111.237'));
        $this->assertSame('000418', ProductIdentifierCode::code('000418'));
        $this->assertSame('180012491809', ProductIdentifierCode::code('18001-249-1809'));
        $this->assertSame('ZOLTY', ProductIdentifierCode::code('żółty'));
        $this->assertNull(ProductIdentifierCode::code(' - / '));
    }

    public function test_normalize_picks_rule_by_type(): void
    {
        $this->assertSame('05711074644834', ProductIdentifierCode::normalize(ProductIdentifier::TYPE_EAN, '5711074644834'));
        $this->assertSame('50051138661972', ProductIdentifierCode::normalize(ProductIdentifier::TYPE_PACK_EAN, '50051138661972'));
        $this->assertSame('5711074644834', ProductIdentifierCode::normalize(ProductIdentifier::TYPE_SOURCE_CODE, '5711074644834'));
        $this->assertSame('PS18', ProductIdentifierCode::normalize(ProductIdentifier::TYPE_MANUFACTURER_CODE, 'ps-18'));
    }
}
