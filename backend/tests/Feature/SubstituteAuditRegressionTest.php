<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Substitutes\SubstituteMatcher;
use App\Services\Substitutes\SubstituteProfiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pary, które audyt 125 propozycji (02.10.2026, lokalna kopia bazy) ocenił jako błędne — każda musi zostać odrzucona.
 * Teksty skrócone z prawdziwych kart, z tym samym zapisem, który mylił automat.
 */
final class SubstituteAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    private SubstituteProfiler $profiler;

    private SubstituteMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profiler = app(SubstituteProfiler::class);
        $this->matcher = app(SubstituteMatcher::class);
    }

    public function test_valve_field_saying_none_is_read_as_without_valve(): void
    {
        $silvAir = $this->card('respiratory', 'Półmaska filtrująca uvex silv-Air classic 8762110 FFP1 kształtowa z.z', 'UVEX', 'EN 149:2001+A1:2009',
            'Półmaska kubkowa FFP1. Zawór wydechowy: brak. Wersja bez zaworu wydechowego.');
        $valved = $this->card('respiratory', 'Półmaska filtrująca 8812 FFP1 z zaworem', '3M', 'EN 149:2001+A1:2009', 'Półmaska kubkowa FFP1 z zaworem wydechowym.');

        $this->assertSame('bez zaworu', $this->profiler->profile($silvAir)->flags['k']['valve']);
        $this->assertSame('inny lub nieznany zawór', $this->compare($valved, $silvAir)['reason']);
    }

    public function test_carbon_mask_is_not_replaced_by_plain_one_and_odorair_is_not_carbon(): void
    {
        $carbon = $this->card('respiratory', 'Półmaska filtrująca 9926 FFP2 z zaworem', '3M', 'EN 149:2001+A1:2009',
            'Półmaska kubkowa FFP2 z zaworem i zintegrowaną warstwą węgla aktywowanego chroniąca przed gazami kwaśnymi.');
        $plain = $this->card('respiratory', 'Półmaska filtrująca Typhoon FFP2 z zaworem', 'JSP', 'EN 149:2001+A1:2009', 'Półmaska kubkowa FFP2 z zaworem.');
        $odorair = $this->card('respiratory', 'Półmaska Odorair FFP2 z zaworem', 'JSP', 'EN 149:2001+A1:2009', 'Półmaska kubkowa FFP2 z zaworem wydechowym.');

        $this->assertStringContainsString('węgiel aktywny', $this->compare($carbon, $plain)['reason']);
        $this->assertFalse($this->profiler->profile($odorair)->flags['k']['carbon']);
    }

    public function test_snr_with_decimal_is_not_rounded_down_to_equal(): void
    {
        $main = $this->card('hearing', 'Nauszniki EP106 na pałąku', 'Canis', 'EN 352-1', 'Nauszniki na pałąku. SNR: 27,5 dB.');
        $weaker = $this->card('hearing', 'Nauszniki Optime I na pałąku SNR 27 dB', '3M', 'EN 352-1', 'Nauszniki na pałąku. SNR: 27 dB.');

        $this->assertSame('27.5', $this->profiler->profile($main)->levels['snr']['value']);
        $this->assertStringContainsString('Tłumienie SNR', $this->compare($main, $weaker)['reason']);
    }

    public function test_dispenser_refill_and_reusable_plugs_are_not_disposable_pairs(): void
    {
        $refill = $this->card('hearing', 'Wkładki przeciwhałasowe Classic, butla uzupełniająca do dozownika, SNR 28 dB', '3M', 'EN 352-2', 'Wkładki z pianki do dozownika.');
        $pair = $this->card('hearing', 'Zatyczki z pianki, 1 para, SNR 29 dB', 'UVEX', 'EN 352-2', 'Wkładki jednorazowe z pianki.');
        $reusable = $this->card('hearing', 'Wkładki przeciwhałasowe Caboflex na pałąku SNR 21 dB', '3M', 'EN 352-2', 'Wielokrotnego użytku, końcówki z pianki.');

        $this->assertStringContainsString('wkład do dozownika', $this->compare($pair, $refill)['reason']);
        $this->assertSame('wielokrotne', $this->profiler->profile($reusable)->flags['k']['plug_kind']);
    }

    public function test_hi_pole_insole_name_is_not_hi_marking(): void
    {
        $main = $this->card('footwear', 'Trzewiki S3 HI HRO SRC', 'HECKEL', 'EN ISO 20345:2011', 'Trzewiki bezpieczne S3 HI HRO SRC.');
        $cand = $this->card('footwear', 'Trzewiki S3S FO SR SC HRO ESD', 'ARTRA', 'EN ISO 20345:2022',
            'Trzewiki bezpieczne S3S FO SR SC HRO ESD. Wkładka: HI-POLY.');

        $this->assertNotContains('HI', $this->profiler->profile($cand)->strongMarkings);
        $this->assertSame('brak oznaczenia HI', $this->compare($main, $cand)['reason']);
    }

    public function test_metal_free_main_needs_metal_free_toe_and_plate(): void
    {
        $main = $this->card('footwear', 'Trzewiki S3 SRC', 'HECKEL', 'EN ISO 20345:2011',
            'Trzewiki bezpieczne S3 SRC. Podnosek kompozytowy, wkładka antyprzebiciowa tekstylna.');
        $steelPlate = $this->card('footwear', 'Trzewik uvex 3 S3 SRC', 'UVEX', 'EN ISO 20345:2011',
            'Trzewiki bezpieczne S3 SRC. Podnosek ochronny bez metalu, stalowa wkładka antyprzebiciowa.');

        $this->assertSame('metal', $this->profiler->profile($steelPlate)->flags['k']['strong_plate']);
        $this->assertStringStartsWith('brak potwierdzenia: wkładka antyprzebiciowa bez metalu', $this->compare($main, $steelPlate)['reason']);
    }

    public function test_full_coating_is_not_replaced_by_three_quarter_and_needle_glove_by_knit(): void
    {
        $full = $this->card('gloves', 'Rękawice Nitrotough N660', 'Ansell', 'EN 388:2016 4121X',
            'Rękawice z pełną powłoką nitrylową, chronią przed olejami.');
        $threeQuarter = $this->card('gloves', 'Rękawice MaxiFlex', 'ATG', 'EN 388:2016 4121X', 'Rękawice powlekane nitrylem 3/4, wentylowany grzbiet.');
        $needle = $this->card('gloves', 'Rękawice HexArmor 3041 NSR', 'HexArmor', 'EN 388:2016 4X43F',
            'Rękawice powlekane nitrylem na dłoni i palcach, chronią przed przekłuciem igłami.');
        $knit = $this->card('gloves', 'Rękawice phynomic F XG', 'UVEX', 'EN 388:2016 4X43F', 'Rękawice powlekane nitrylem na dłoni i palcach.');

        $this->assertSame('inny zakres powłoki', $this->compare($full, $threeQuarter)['reason']);
        $this->assertStringContainsString('ochrona przed igłami', $this->compare($needle, $knit)['reason']);
    }

    public function test_en407_must_match_exactly_and_en388_positions_stay_within_one_level(): void
    {
        $this->assertNull($this->matcher->relation('en407', 'X1XXXX', 'X2XXXX'));
        $this->assertSame('equal', $this->matcher->relation('en407', 'X1XXX', 'X1XXXX'));
        $this->assertNull($this->matcher->relation('en388', '2121X', '4121X'), 'ścieranie 2 → 4 to inna rękawica');
        $this->assertSame('higher', $this->matcher->relation('en388', '3121X', '4121X'));
    }

    public function test_main_with_iso_18889_or_single_size_card_is_not_eligible(): void
    {
        $iso = $this->card('gloves', 'Rękawice MaxiFlex Endurance', 'ATG', 'EN 388:2016 4131A, ISO 18889:2019 GR',
            'Rękawice powlekane nitrylem na dłoni i palcach.');
        $sized = $this->card('gloves', 'Rękawice HexArmor 3023 rozmiar 8', 'HexArmor', 'EN 388:2016 4X43D', 'Rękawice powlekane nitrylem na dłoni i palcach.');

        $this->assertStringStartsWith('norma spoza zakresu automatu', (string) $this->profiler->mainIneligibility($iso, $this->profiler->profile($iso)));
        $this->assertSame('karta jednego rozmiaru', $this->profiler->mainIneligibility($sized, $this->profiler->profile($sized)));
    }

    public function test_ffp_substitute_without_en149_is_rejected(): void
    {
        $main = $this->card('respiratory', 'Półmaska Flexinet FFP3 z zaworem', 'JSP', 'EN 149:2001+A1:2009', 'Półmaska kubkowa FFP3 z zaworem.');
        $noNorm = $this->card('respiratory', 'Respirator SPIRO P3 with valve, formed FFP3', 'Canis', '', 'Respirator FFP3 with valve, formed.');

        $this->assertStringContainsString('EN 149', $this->compare($main, $noNorm)['reason']);
    }

    public function test_esd_and_ci_written_in_sentences_are_required_from_substitute(): void
    {
        $esd = $this->card('footwear', 'Półbuty uvex 1 BOA S1 SRC', 'UVEX', 'EN ISO 20345:2011',
            'Półbuty bezpieczne S1 SRC, podnosek stalowy. Obuwie spełnia kryteria ESD – opór poniżej 35MΩ.');
        $noEsd = $this->card('footwear', 'Półbuty ARYK S1 PL FO SR', 'ARTRA', 'EN ISO 20345:2022', 'Półbuty bezpieczne S1 PL FO SR, podnosek stalowy.');
        $ci = $this->card('footwear', 'Półbuty HECKEL LOW S3 SRC', 'HECKEL', 'EN ISO 20345:2011',
            'Półbuty bezpieczne S3 SRC, podnosek stalowy, wkładka stalowa. CI – izolacja spodu od zimna.');
        $noCi = $this->card('footwear', 'Półbuty uvex 1 sport S3 SRC', 'UVEX', 'EN ISO 20345:2011', 'Półbuty bezpieczne S3 SRC, podnosek stalowy, wkładka stalowa.');

        $this->assertSame('brak oznaczenia ESD', $this->compare($esd, $noEsd)['reason']);
        $this->assertSame('brak oznaczenia CI', $this->compare($ci, $noCi)['reason']);
    }

    public function test_helmet_mounted_earmuffs_are_out_and_neckband_differs_from_headband(): void
    {
        $helmet = $this->card('hearing', 'Nauszniki nahełmowe X2P3 SNR 30 dB', '3M', 'EN 352-3', 'Nauszniki mocowane do hełmów ochronnych.');
        $neck = $this->card('hearing', 'Nauszniki H505B SNR 27 dB', '3M', 'EN 352-1', 'Nauszniki z pałąkiem nakarkowym do przyłbicy spawalniczej.');
        $head = $this->card('hearing', 'Nauszniki EP106 SNR 27 dB', 'Canis', 'EN 352-1', 'Nauszniki, montaż: pałąk nagłowny.');

        $this->assertSame('nauszniki nahełmowe poza automatem', $this->profiler->mainIneligibility($helmet, $this->profiler->profile($helmet)));
        $this->assertSame('inny sposób noszenia nauszników', $this->compare($neck, $head)['reason']);
    }

    public function test_mask_shape_must_be_known_and_nose_clip_wording_is_not_a_shape(): void
    {
        $folded = $this->card('respiratory', 'Półmaska Aura 9312+ FFP1 z zaworem', '3M', 'EN 149:2001+A1:2009',
            'Półmaska składana FFP1 z zaworem, wyprofilowana blaszka nosowa.');
        $cup = $this->card('respiratory', 'Półmaska Typhoon 725 FFP2 z zaworem', 'JSP', 'EN 149:2001+A1:2009', 'Półmaska formowana FFP2 z zaworem.');
        $unknown = $this->card('respiratory', 'Półmaska 8833 FFP3 z zaworem', '3M', 'EN 149:2001+A1:2009', 'Półmaska FFP3 z zaworem wydechowym.');

        $this->assertSame('składana', $this->profiler->profile($folded)->flags['k']['shape']);
        $this->assertSame('inny lub nieznany kształt półmaski', $this->compare($folded, $cup)['reason']);
        $this->assertSame('karta nie podaje jednoznacznie kształtu półmaski', $this->profiler->mainIneligibility($unknown, $this->profiler->profile($unknown)));
    }

    public function test_description_with_other_product_code_disqualifies_card(): void
    {
        $glued = $this->card('respiratory', 'Respirátor SPIRO, P2, BLISTR skládací, 3 ks', 'Canis', 'EN 149:2001+A1:2009',
            'Filtrační polomaska CXS SPIRO P2, HY8222, skládací s ventilkem. 4510-004-000-00. Półmaska składana FFP2 z zaworem.', '4510-074-000-00');

        $this->assertSame('opis podaje kod innego wyrobu (opis sklejony albo cudzy)', $this->profiler->cardIssue($glued, $this->profiler->profile($glued)));
    }

    public function test_distributor_card_with_model_code_parts_is_the_same_model(): void
    {
        $canis = $this->card('hearing', 'Ear muffs Peltor H510A-401-GU, Optime I, headband SNR 27 dB', 'Canis', 'EN 352-1', 'Nauszniki na pałąku nagłownym.');
        $peltor = $this->card('hearing', 'Nauszniki Optime H510A na pałąku SNR 27 dB', 'MSA', 'EN 352-1', 'Nauszniki na pałąku nagłownym.');

        $this->assertTrue($this->matcher->sameModel($canis, $peltor));
        $this->assertTrue($this->matcher->sameModel($peltor, $canis));
    }

    public function test_size_line_with_available_range_is_single_size_card_and_foam_alone_is_not_disposable(): void
    {
        $sized = $this->card('gloves', 'Rękawice HyFlex 11-561', 'Ansell', 'EN 388:2016 4X42C', 'Rękawice powlekane nitrylem na dłoni i palcach.');
        $sized->update(['shop_fields_summary' => "Parametry\nRozmiar: 12 (dostępne 5-12)"]);
        $foam = $this->card('hearing', 'Zatyczki do uszu z pianki PU Soundstopper SNR 36 dB', 'JSP', 'EN 352-2', 'Wkładki z pianki PU, 1 para.');

        $this->assertSame('karta jednego rozmiaru', $this->profiler->cardIssue($sized->fresh(), $this->profiler->profile($sized->fresh())));
        $this->assertNull($this->profiler->profile($foam)->flags['k']['plug_kind']);
    }

    public function test_hygiene_slip_on_footwear_is_not_replaced_by_perforated_industrial_one(): void
    {
        $hygiene = $this->card('footwear', 'Półbuty uvex 1 sport hygiene S2 SRC', 'UVEX', 'EN ISO 20345:2011',
            'Wsuwane półbuty S2 SRC z wodoodporną cholewką łatwą do mycia, podnosek kompozytowy.');
        $industrial = $this->card('footwear', 'Półbuty Dorsata S2 SRC', 'Canis', 'EN ISO 20345:2011',
            'Półbuty S2 SRC, mikrofibra z perforacją, podnosek kompozytowy.');

        $this->assertStringContainsString('tylko w jednej karcie', $this->compare($hygiene, $industrial)['reason']);
    }

    public function test_toe_without_metal_next_to_steel_plate_bullet_is_read_per_element(): void
    {
        $uvex = $this->card('footwear', 'Trzewik uvex 2 construction S3 SRC', 'UVEX', 'EN ISO 20345:2011',
            "Trzewiki S3 SRC.\n• Stalowa wkładka antyprzebiciowa\n• Podnosek uvex xenova® w 100 % bez zawartości metalu – kompaktowa budowa");

        $k = $this->profiler->profile($uvex)->flags['k'];
        $this->assertSame('nonmetal', $k['main_toe']);
        $this->assertSame('metal', $k['main_plate']);
    }

    public function test_antistatic_called_esd_does_not_confirm_esd_and_hro_sentence_is_required(): void
    {
        $esd = $this->card('footwear', 'Półbuty uvex 1 S1P SRC', 'UVEX', 'EN ISO 20345:2011',
            'Półbuty S1P SRC, podnosek kompozytowy, wkładka antyprzebiciowa tekstylna. Spełnia kryteria ESD – opór poniżej 35MΩ.');
        $antistatic = $this->card('footwear', 'Półbuty Canis Falster S1P SRC HRO', 'Canis', 'EN ISO 20345:2011',
            'Półbuty S1P SRC HRO, podnosek kompozytowy, wkładka antyprzebiciowa tekstylna. Właściwości antystatyczne (ESD).');
        $hro = $this->card('footwear', 'Trzewiki S3 SRC', 'ELTEN', 'EN ISO 20345:2011',
            'Trzewiki S3 SRC, podnosek stalowy, wkładka antyprzebiciowa stalowa, podeszwa odporna na kontakt z ciepłem do 300°C.');
        $noHro = $this->card('footwear', 'Trzewiki ARDEUS S3L FO SR', 'ARTRA', 'EN ISO 20345:2022', 'Trzewiki S3L FO SR, podnosek stalowy.');

        $this->assertSame('brak oznaczenia ESD', $this->compare($esd, $antistatic)['reason']);
        $this->assertSame('brak oznaczenia HRO', $this->compare($hro, $noHro)['reason']);
    }

    public function test_metal_free_whole_shoe_needs_metal_free_substitute_and_high_shoe_is_not_low(): void
    {
        $main = $this->card('footwear', 'Trzewiki S2 FO SR Metal free', 'ARTRA', 'EN ISO 20345:2022', 'Trzewiki S2 FO SR, podnosek kompozytowy. Metal free.');
        $steelPlate = $this->card('footwear', 'Trzewik uvex 3 quatro S3 SRC', 'UVEX', 'EN ISO 20345:2011',
            'Trzewiki S3 SRC, podnosek bez metalu, stalowa wkładka antyprzebiciowa.');
        $high = $this->card('footwear', 'Półbuty robocze S3 SRC', 'Polstar', 'EN ISO 20345:2011',
            'Obuwie wysokie robocze S3 SRC, podnosek stalowy, wkładka antyprzebiciowa stalowa.');

        $toeOnly = $this->card('footwear', 'Trzewik uvex 1 G2 S3 SRC', 'UVEX', 'EN ISO 20345:2011',
            'Trzewiki S3 SRC, w 100% wolny od metalu podnosek, wkładka antyprzebiciowa tekstylna.');

        $this->assertSame('brak potwierdzenia: cały but bez metalu', $this->compare($main, $steelPlate)['reason']);
        $this->assertSame('brak potwierdzenia: cały but bez metalu', $this->compare($main, $toeOnly)['reason'], 'sam podnosek bez metalu to za mało');
        $this->assertSame('wysokość cholewki przeczy rodzajowi wyrobu', $this->profiler->cardIssue($high, $this->profiler->profile($high)));
    }

    public function test_cold_store_wording_hygienic_insole_and_missing_toe_cap_are_not_misread(): void
    {
        $artra = $this->card('footwear', 'Półbuty ARZAWA O2 FO', 'ARTRA', 'EN ISO 20347:2012',
            'Półbuty zawodowe O2 FO SRC bez podnoska. Sprawdzą się w logistyce chłodniach. Wkładka higieniczna.');
        $canis = $this->card('footwear', 'Low leather footwear O2 without steel toe cap', 'Canis', 'EN ISO 20347:2012', 'Półbuty zawodowe O2 FO SRC.');

        $k = $this->profiler->profile($artra)->flags['k'];
        $this->assertFalse($k['winter']);
        $this->assertFalse($k['hygiene']);
        $this->assertNull($this->profiler->profile($canis)->flags['k']['main_toe']);
    }

    public function test_sc_marking_high_shoe_and_whole_metal_free_wording_from_round_six(): void
    {
        $sc = $this->card('footwear', 'Trzewiki ARLES O2 FO SR SC', 'ARTRA', 'EN ISO 20347:2022', 'Trzewiki zawodowe O2 FO SR SC.');
        $noSc = $this->card('footwear', 'Trzewiki ARICA O2 FO SRC', 'Canis', 'EN ISO 20347:2012', 'Trzewiki zawodowe O2 FO SRC.');
        $semiShank = $this->card('footwear', 'Semi shank footwear S3 SRC', 'Canis', 'EN ISO 20345:2011',
            'Wysokie, wodoodporne obuwie S3 SRC, podnosek stalowy, wkładka antyprzebiciowa stalowa.');
        $noMetal = $this->card('footwear', 'Półbuty S1P SRC', 'Reis', 'EN ISO 20345:2011',
            'Półbuty S1P SRC, podnosek kompozytowy, wkładka antyprzebiciowa tekstylna, brak elementów metalowych.');
        $tread = $this->card('footwear', 'Trzewiki S3 SRC', 'Polstar', 'EN ISO 20345:2011',
            'Trzewiki S3 SRC, podnosek stalowy, wkładka antyprzebiciowa stalowa, bieżnik z wzmocnieniem śródstopia.');

        $this->assertSame('brak oznaczenia SC', $this->compare($sc, $noSc)['reason']);
        $this->assertTrue($this->profiler->profile($semiShank)->flags['k']['calf']);
        $this->assertTrue($this->profiler->profile($noMetal)->flags['c']['metal_free']['weak']);
        $this->assertFalse($this->profiler->profile($tread)->flags['k']['metatarsal']);
    }

    public function test_snr_range_makes_card_ambiguous(): void
    {
        $range = $this->card('hearing', 'Nauszniki X2P3E na pałąku', '3M', 'EN 352-1', 'Nauszniki na pałąku nagłownym, SNR 30–31 dB.');

        $this->assertArrayNotHasKey('snr', $this->profiler->profile($range)->levels);
    }

    /**
     * @return array<string, mixed>
     */
    private function compare(Product $main, Product $sub): array
    {
        return $this->matcher->compare($main, $this->profiler->profile($main), $sub, $this->profiler->profile($sub));
    }

    private function card(string $family, string $name, string $manufacturer, string $norms, string $description, ?string $sku = null): Product
    {
        static $n = 0;
        $n++;

        return Product::query()->create([
            'sku' => $sku ?? 'AUD-'.$n,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'ppe_family' => $family,
            'norms' => $norms === '' ? null : $norms,
            'description' => $description.' Opis karty testowej z wystarczającą liczbą znaków, żeby był opisem wyrobu.',
            'catalog_price_net' => 10 + $n,
            'purchase_price' => 10 + $n,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }
}
