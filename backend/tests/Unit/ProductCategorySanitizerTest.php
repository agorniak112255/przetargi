<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Presta\ProductCategorySanitizer;
use Tests\TestCase;

final class ProductCategorySanitizerTest extends TestCase
{
    private ProductCategorySanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = app(ProductCategorySanitizer::class);
    }

    public function test_detects_excel_and_column_garbage(): void
    {
        $this->assertTrue($this->sanitizer->isGarbage("='Trad. gammes'!C10"));
        $this->assertTrue($this->sanitizer->isGarbage('A'));
        $this->assertTrue($this->sanitizer->isGarbage('CENA PLN'));
        $this->assertTrue($this->sanitizer->isGarbage('729591 LELAND GTX lime Low ESD S3'));
        $this->assertFalse($this->sanitizer->isGarbage('Ręczniki'));
        $this->assertFalse($this->sanitizer->isGarbage('07 - Gloves'));
    }

    public function test_imported_excel_uses_product_name(): void
    {
        $this->assertSame('Ręczniki', $this->sanitizer->imported("='Trad. gammes'!C10", 'Ręcznik GOMEZ 500g'));
        $this->assertSame('Rękawice', $this->sanitizer->imported('A', 'Rękawice URGENT 1005'));
        $this->assertNull($this->sanitizer->imported('B', 'Widget bez sensu'));
        $this->assertSame('Ręczniki', $this->sanitizer->imported('Ręczniki', 'cokolwiek'));
    }
}
