<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\SourceClaims;
use App\Support\ProductCodeMatch;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * SourceClaims i ProductCodeMatch to kopie 1:1 prywatnych reguł ProductEnrichmentService (sito norm w opisie,
 * kod w tekście uzupełnienia). Dopóki serwis ich nie woła (część A etapu 1), obie wersje muszą dawać to samo.
 */
final class SourceClaimsParityTest extends TestCase
{
    private const PHRASES = [
        'EN 388:2016+A1:2018 4131A',
        'EN 388: 4131A, EN 407 X1XXXX',
        'EN ISO 374-1:2016/Type B (JKL), EN 374-5:2016',
        'PN-EN ISO 20345:2022 S3 SRC',
        'EN ISO 21420:2020',
        'EN-388 (2016) 4544C',
        'ISO 13997 poziom B',
        'Wymiary 1200 x 1800 mm, grubość 9,5 mm, rok 2020',
        'EN 1149-5 i EN ISO 11612 A1 B1 C1',
        'EN 388 4X42C, EN 511 X2X',
        'kategoria III, jednostka notyfikowana 0598',
        'IEC 61340-5-1 ESD',
        '',
        'brak norm w tekście',
        'EN 13501-1 Cfl-s1, ISO 9239, EN 16165',
    ];

    public function test_source_claims_match_enrichment_service(): void
    {
        foreach (self::PHRASES as $phrase) {
            $this->assertSame(self::service('sourceClaims', $phrase), SourceClaims::claims($phrase), $phrase);
            $this->assertSame(self::service('normDesignations', $phrase), SourceClaims::designations($phrase), $phrase);
            $this->assertSame(self::service('claimKey', $phrase), SourceClaims::key($phrase), $phrase);
        }
    }

    public function test_norm_designation_support_matches_enrichment_service(): void
    {
        $sources = array_map(static fn (string $p): array => SourceClaims::designations($p), self::PHRASES);
        $claims = [];
        foreach (self::PHRASES as $phrase) {
            foreach (SourceClaims::claims($phrase) as $claim) {
                if (preg_match('/^(?:EN|ISO)/', $claim) === 1) {
                    $claims[] = $claim;
                }
            }
        }
        $claims = [...$claims, 'EN388:2003', 'ENISO374-1:2016', 'EN374', 'ENISO20345:2011', 'ISO9999'];
        $this->assertNotEmpty($claims);
        foreach ($claims as $claim) {
            foreach ($sources as $i => $norms) {
                $this->assertSame(
                    self::service('normDesignationSupported', $claim, $norms),
                    SourceClaims::designationSupported($claim, $norms),
                    $claim.' wobec '.self::PHRASES[$i]
                );
            }
        }
    }

    public function test_code_match_matches_enrichment_service(): void
    {
        $codes = ['PSSBL30-014', 'TRACPSF', 'AF060001', 'AF060003', 'CCLIP25', 'LCLIP-38', 'SN0100-7', 'VP01'];
        $texts = [
            'Part Number AF060003C 0.9 m x per linear metre AF060003 0.9 m x 18.3 m',
            'kod PSSBL30 014 i TRACPSFX',
            'TRACPSF, AF06-0001, CCLIP 25',
            'LCLIP38 uchwyt typu L',
            'SN0100-7 / VP01',
            '',
        ];
        foreach ($codes as $code) {
            $this->assertSame(self::service('supplementCodeKey', $code), ProductCodeMatch::key($code), $code);
            foreach ($texts as $text) {
                $key = ProductCodeMatch::key($code);
                $this->assertSame(
                    self::service('textCarriesCode', $text, $key),
                    ProductCodeMatch::textCarries($text, $key),
                    $code.' w „'.$text.'”'
                );
            }
        }
    }

    private static function service(string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod(ProductEnrichmentService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$args);
    }
}
