<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PpeFilterType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PpeFilterTypeTest extends TestCase
{
    private PpeFilterType $filters;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filters = new PpeFilterType;
    }

    #[Test]
    public function compact_code_reads_glued_and_hyphenated_forms(): void
    {
        $this->assertContains('a2b2e2k2no', $this->filters->compactCodes(
            'pochłaniacz wielogazowy a2b2e2k2no dodatkowa ochrona na tlenki azoty NO'
        ));
        $this->assertContains('a2b2e2k2hgconop3', $this->filters->compactCodes(
            'Filtr 203 UP3 A2-B2-E2-K2-Hg-CO-NO-P3'
        ));
    }

    #[Test]
    public function rejects_a2b2e2k2_when_requirement_needs_no(): void
    {
        $req = 'pochłaniacz wielogazowy a2b2e2k2no dodatkowa ochrona na tlenki azoty NO';

        $this->assertFalse($this->filters->covers($req, 'Pochłaniacz wielogazowy 202 A2B2E2K2 kwasek.pl'));
        $this->assertTrue($this->filters->covers(
            $req,
            'https://oxyline.eu/pl/product/275/filter-203-up3-a2-b2-e2-k2-hg-co-no-p3 Filtr 203 UP3'
        ));
    }

    #[Test]
    public function tlenki_azotu_imply_no_even_without_glued_code(): void
    {
        $req = 'pochłaniacz A2B2E2K2 dodatkowa ochrona na tlenki azoty';

        $this->assertFalse($this->filters->covers($req, 'Pochłaniacz A2B2E2K2'));
        $this->assertTrue($this->filters->covers($req, 'Pochłaniacz A2B2E2K2NO'));
    }

    #[Test]
    public function clothing_requirement_has_no_filter_gate(): void
    {
        $this->assertTrue($this->filters->covers(
            'Kurtka ochronna ocieplana z kapturem',
            'Gaśnica proszkowa 4 kg'
        ));
    }
}
