<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PdfDocumentGroupAssigner;
use PHPUnit\Framework\TestCase;

final class PdfDocumentGroupAssignerTest extends TestCase
{
    public function test_assigns_section_heading_before_sku(): void
    {
        $text = <<<'TXT'
rkawice
60038 PHYNOMIC airLite 16,90 zl
zagroenia mechaniczne
60080 uvex phynomic C3 34,50 zl
TXT;
        $rows = (new PdfDocumentGroupAssigner)->assign([
            ['sku' => '60038', 'name' => 'PHYNOMIC airLite', 'category' => null],
            ['sku' => '60080', 'name' => 'phynomic C3', 'category' => null],
        ], $text, '');

        $this->assertSame('rkawice', $rows[0]['category']);
        $this->assertSame('zagroenia mechaniczne', $rows[1]['category']);
    }
}
