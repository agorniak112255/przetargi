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

    /** Wydzielenie stripRegulations() nie zmienia wyniku dla norm, rozporządzeń i miar — bajt w bajt jak wcześniej. */
    #[Test]
    public function strip_output_for_norms_regulations_and_units_is_unchanged(): void
    {
        $this->assertSame(
            'zgodność z rozporządzeniem ; zgodność z normą ( ); ; ; ; ; reach .',
            RequirementCodeNoise::strip(
                'Zgodność z rozporządzeniem (UE) 2016/425; zgodność z normą PN-EN 140:2004 (EN 140:1998); '
                .'EN 388:2016+A1:2018; EN ISO 20345:2011; EN 50321-1; ISO 13997; REACH (WE) 1907/2006.'
            )
        );
        $this->assertSame(
            'stężenie do ( ); butelka ; długość ; do ; trwałość ; co ;',
            RequirementCodeNoise::strip('stężenie do 0,5% (5000 ppm); butelka 500 ml; długość 475 mm; do 17 kV; trwałość 5 lat; co 12 miesięcy; 50°C')
        );
        $this->assertSame(
            'dyrektywa , rozporządzenie , półmaska secura 3000, 6503, hy51, rnitz-m',
            RequirementCodeNoise::strip('Dyrektywa 89/686/EWG, rozporządzenie WE nr 1907/2006, Półmaska SECURA 3000, 3M 6503, HY51, RNITZ-M')
        );
    }

    #[Test]
    public function regulations_without_eu_label_iec_norms_and_slash_amendments_are_removed(): void
    {
        foreach ([
            'zgodne z rozporządzeniem nr 2016/425' => ['2016', '425'],
            // „nr” z rokiem i numerem to akt także bez słowa „rozporządzenie” przed nim
            'deklaracja zgodności z nr 2016/425' => ['2016', '425'],
            'zgodne z rozporządzeniem parlamentu europejskiego i rady 2016/425' => ['2016', '425'],
            'zgodne z rozporzadzeniem 2016/425' => ['2016', '425'],
            'reach 1907/2006' => ['1907', '2006'],
            'esd wg en iec 61340-4-3:2018' => ['61340', '2018'],
            'pn-en iec 61340-5-1:2016+a1:2019 klasa' => ['61340', '2016', '2019'],
            'ubranie iec 61482' => ['61482'],
            'sandały iec61340-4-3' => ['61340'],
            'kamizelka en iso 20471:2013/a1:2016 klasa 2' => ['2016'],
        ] as $text => $numbers) {
            $out = RequirementCodeNoise::stripRegulations($text);
            foreach ($numbers as $number) {
                $this->assertStringNotContainsString($number, $out, "„{$number}” z „{$text}” to akt prawny albo norma");
            }
        }
        // strip() woła stripRegulations(): zmiana normy znika razem z normą
        $this->assertSame('kamizelka klasa 2', RequirementCodeNoise::strip('Kamizelka EN ISO 20471:2013/A1:2016 klasa 2'));
    }

    /** Goły zapis rok/numer bez „nr” ani słowa aktu bywa wyrobem: „SECURA 2000/3000” to dwie serie półmasek. */
    #[Test]
    public function year_slash_number_without_legal_act_word_stays(): void
    {
        $this->assertSame('pochłaniacz do półmasek secura 2000/3000', RequirementCodeNoise::stripRegulations('pochłaniacz do półmasek secura 2000/3000'));
        $this->assertSame('nr kat. 2000/3000', RequirementCodeNoise::stripRegulations('nr kat. 2000/3000'));
        $this->assertStringContainsString('2000/3000', RequirementCodeNoise::strip('Pochłaniacz do półmasek SECURA 2000/3000'));
    }

    /**
     * Między słowem aktu a numerem stoją tylko słowa z nazwy aktu („wykonawczym Komisji”, „PE i Rady”). Zwykłe słowo
     * znaczy, że numer należy do czegoś innego: „rozporządzenia np. SECURA 2000/3000” to dwie serie półmasek.
     */
    #[Test]
    public function only_legal_act_name_words_may_stand_between_the_act_and_its_number(): void
    {
        $this->assertSame('rozporządzeniem wykonawczym komisji ', RequirementCodeNoise::stripRegulations('rozporządzeniem wykonawczym komisji 2019/1020'));
        $this->assertSame('rozporządzenie pe i rady ', RequirementCodeNoise::stripRegulations('rozporządzenie pe i rady 2016/425'));
        $this->assertSame(
            'półmaska zgodna z wymaganiami rozporządzenia np. secura 2000/3000',
            RequirementCodeNoise::stripRegulations('półmaska zgodna z wymaganiami rozporządzenia np. secura 2000/3000')
        );
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
