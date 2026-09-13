<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\TechnicalAbbreviations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Wspólna lista dla wyszukiwarki (token producenta) i dopasowania przetargu (kody z SIWZ):
 * skrót normy/klasy/materiału to ani marka, ani kod produktu; marka i kod modelu przechodzą.
 */
final class TechnicalAbbreviationsTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function abbreviations(): iterable
    {
        foreach (['ESD', 'SRC', 'SRA', 'PVC', 'NBR', 'TPR', 'UHMWPE', 'SVHC', 'FDA', 'NDS', 'AQL', 'III', 'FFP1', 'FFP2',
            'S1P', 'S3', 'OB', 'A2', 'A2B2E2K2', 'ABEK1P3', 'EN388', 'EN 388', 'ISO20345', 'PN-EN', '17kV', 'HRO', 'HV'] as $token) {
            yield $token => [$token];
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function brandsAndModels(): iterable
    {
        foreach (['MSA', 'UVEX', 'ATG', 'MAPA', 'CERVA', 'RNITZ', 'HY51', 'HF803', '6503QL', 'PERSPECTA', 'TRONCHETTO', 'X2A'] as $token) {
            yield $token => [$token];
        }
    }

    #[Test]
    #[DataProvider('abbreviations')]
    public function norm_class_and_material_abbreviations_are_recognised(string $token): void
    {
        $this->assertTrue(TechnicalAbbreviations::isNormOrClass($token));
    }

    #[Test]
    #[DataProvider('brandsAndModels')]
    public function brands_and_model_codes_are_not_abbreviations(string $token): void
    {
        $this->assertFalse(TechnicalAbbreviations::isNormOrClass($token));
    }

    #[Test]
    public function empty_or_punctuation_only_token_is_not_an_abbreviation(): void
    {
        $this->assertFalse(TechnicalAbbreviations::isNormOrClass(''));
        $this->assertFalse(TechnicalAbbreviations::isNormOrClass('-/'));
    }
}
