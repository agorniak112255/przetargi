<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Enrichment\EvidenceExtractor;
use PHPUnit\Framework\TestCase;

final class EvidenceExtractorTest extends TestCase
{
    private const SOURCE = "MAPA Professional Ultranitril 492\nNitrile glove, length 380 mm, thickness 0,45 mm.\n"
        ."EN 388:2016 4101X, EN ISO 374-1:2016 Type A (JKLMNO).\nCategory III, notified body 0598.";

    public function test_values_found_in_source_are_explicit_with_quote_and_source(): void
    {
        $sha = hash('sha256', self::SOURCE);
        $result = (new EvidenceExtractor)->extract([
            'norms' => ['EN 388:2016 4101X', 'EN ISO 374-1:2016'],
            'materials' => ['nitryl'],
            'specs' => ['Długość: 380 mm', 'Grubość: 0.45 mm'],
            'certificates' => ['Kategoria III, jednostka notyfikowana 0598'],
        ], [['sha256' => $sha, 'text' => self::SOURCE]]);

        $byValue = array_column($result['entries'], null, 'value');
        foreach (['EN388:2016', '4101X', 'ENISO374-1:2016', 'nitryl', '380 mm', '0.45 mm', 'kategoria III', 'NB 0598'] as $value) {
            $this->assertArrayHasKey($value, $byValue, $value);
            $this->assertSame('explicit', $byValue[$value]['status'], $value);
            $this->assertSame($sha, $byValue[$value]['source_sha256'], $value);
            $this->assertNotSame('', (string) $byValue[$value]['quote'], $value);
        }
        $this->assertStringContainsString('4101X', (string) $byValue['4101X']['quote']);
        $this->assertSame('norms', $byValue['4101X']['field']);
        $this->assertSame(0, $result['inferred']);
        $this->assertSame(count($result['entries']), $result['explicit']);
        $this->assertNull($result['completeness'], 'braki per kategoria — etap 3');
    }

    public function test_values_missing_from_sources_are_inferred(): void
    {
        $result = (new EvidenceExtractor)->extract([
            'norms' => ['EN 388:2016 4131A', 'EN 388:2003'],
            'materials' => ['lateks'],
            'specs' => ['Długość: 300 mm'],
            'attributes' => ['material' => ['neopren'], 'klasa' => 'klasa 2'],
        ], [['sha256' => 'abc', 'text' => self::SOURCE]]);

        $byValue = array_column($result['entries'], null, 'value');
        foreach (['4131A', 'EN388:2003', 'lateks', '300 mm', 'neopren', 'klasa 2'] as $value) {
            $this->assertSame('inferred', $byValue[$value]['status'] ?? null, $value);
            $this->assertNull($byValue[$value]['quote']);
            $this->assertNull($byValue[$value]['source_sha256']);
        }
        $this->assertSame('attributes.material', $byValue['neopren']['field']);
        $this->assertSame('explicit', $byValue['EN388:2016']['status'], 'norma jest, poziomy 4131A — nie');
    }

    public function test_dimension_without_norm_context_is_not_a_protection_level(): void
    {
        $result = (new EvidenceExtractor)->extract(['specs' => ['Wymiary 1200 x 1800 mm']], []);

        $this->assertSame(['1800 mm'], array_column($result['entries'], 'value'));
    }

    public function test_no_lists_no_entries(): void
    {
        $this->assertSame(
            ['entries' => [], 'explicit' => 0, 'inferred' => 0, 'completeness' => null],
            (new EvidenceExtractor)->extract([], [['sha256' => 'x', 'text' => self::SOURCE]])
        );
    }
}
