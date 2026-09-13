<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RequirementCodeNoise;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Liczby z norm, rozporządzeń i miar nie zostają w tekście, z którego czytane są kody produktu;
 * numery modeli i serii (6503, SECURA 3000, RNITZ-M, HY51) zostają.
 */
final class RequirementCodeNoiseTest extends TestCase
{
    #[Test]
    public function norm_years_amendments_parts_and_regulations_are_removed(): void
    {
        $text = RequirementCodeNoise::strip(
            'Zgodność z rozporządzeniem (UE) 2016/425; zgodność z normą PN-EN 140:2004 (EN 140:1998); '
            .'EN 388:2016+A1:2018; EN ISO 20345:2011; EN 50321-1; ISO 13997; REACH (WE) 1907/2006.'
        );

        foreach (['2016', '425', '2004', '1998', '140', '388', '2018', '20345', '2011', '50321', '13997', '1907', '2006'] as $number) {
            $this->assertStringNotContainsString($number, $text, "„{$number}” to numer normy/rozporządzenia, nie kod produktu");
        }
    }

    #[Test]
    public function numbers_with_units_are_removed(): void
    {
        $text = RequirementCodeNoise::strip('stężenie do 0,5% (5000 ppm); butelka 500 ml; długość 475 mm; do 17 kV; trwałość 5 lat; co 12 miesięcy; 50°C');

        foreach (['5000', '500', '475', '17', '12', '50'] as $number) {
            $this->assertStringNotContainsString($number, $text, "„{$number}” to miara, nie kod produktu");
        }
    }

    #[Test]
    public function model_and_series_numbers_stay(): void
    {
        $this->assertStringContainsString('3000', RequirementCodeNoise::strip('Półmaska SECURA 3000 z łącznikami bagnetowymi, EN 140:1998'));
        $this->assertStringContainsString('6503', RequirementCodeNoise::strip('Półmaska 3M 6503 z zaworem'));
        $this->assertStringContainsString('rnitz-m', RequirementCodeNoise::strip('Rękawice RNITZ-M ze ściągaczem'));
        $this->assertStringContainsString('hy51', RequirementCodeNoise::strip('Zestaw higieniczny 3M HY51 do nauszników'));
        $this->assertStringContainsString('3000 lub 5000', RequirementCodeNoise::strip('kompatybilny z półmaskami serii 3000 lub 5000'));
    }
}
