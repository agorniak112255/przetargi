<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Substitutes\SubstituteMatcher;
use App\Services\Substitutes\SubstituteProfiler;
use App\Support\CanonicalBrand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Profil ochronny karty i porównanie pary dla automatu zamienników: zamiennik podaje wprost każdy parametr ochronny
 * karty głównej i mieści się w paśmie podobieństwa; brak w karcie zamiennika to odrzucenie, nie domysł.
 */
final class SubstituteMatcherTest extends TestCase
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

    public function test_glove_profile_reads_code_and_passes_round_trip(): void
    {
        $glove = $this->card('gloves', 'Rękawice powlekane nitrylem HyFlex 11-800', 'Ansell', 'EN ISO 21420, EN 388:2016 4131X', 'Rękawice powlekane nitrylem na dłoni i palcach, dzianina nylonowa. EN 388:2016 4131X.');

        $profile = $this->profiler->profile($glove);

        $this->assertNotNull($profile);
        $this->assertSame('4131X', $profile->levels['en388']['value']);
        $this->assertSame('EN 388:2016 4131X', $profile->levels['en388']['fragment']);
        $this->assertSame(['en21420', 'en388'], array_keys($profile->norms));
        $this->assertNull($this->profiler->mainIneligibility($glove, $profile));
    }

    public function test_main_with_unread_norm_or_conflicting_codes_is_not_eligible(): void
    {
        $chemical = $this->card('gloves', 'Rękawice powlekane nitrylem odporne na oleje', 'Ansell', 'EN 388:2016 4121X, EN ISO 374-1:2016 typ B', 'Rękawice powlekane nitrylem na dłoni i palcach.');
        $conflict = $this->card('gloves', 'Rękawice powlekane nitrylem Grip', 'MAPA', 'EN 388:2016 4131X', 'Rękawice powlekane nitrylem. Poziomy EN 388:2016 4121X.');

        $this->assertStringStartsWith('norma spoza zakresu automatu', (string) $this->profiler->mainIneligibility($chemical, $this->profiler->profile($chemical)));
        $this->assertNotNull($this->profiler->mainIneligibility($conflict, $this->profiler->profile($conflict)));
    }

    public function test_equal_glove_is_preferred_substitute_with_quoted_evidence(): void
    {
        $main = $this->card('gloves', 'Rękawice powlekane nitrylem HyFlex 11-800', 'Ansell', 'EN ISO 21420, EN 388:2016 4131X', 'Rękawice powlekane nitrylem na dłoni i palcach, dzianina nylonowa.');
        $sub = $this->card('gloves', 'Rękawice powlekane nitrylem MaxiFlex Ultimate', 'ATG', 'EN ISO 21420, EN 388:2016 4131X', 'Rękawice powlekane nitrylem na dłoni i palcach, dzianina.');

        $result = $this->compare($main, $sub);

        $this->assertTrue($result['ok'], (string) ($result['reason'] ?? ''));
        $this->assertSame('preferowany', $result['verdict']);
        $en388 = collect($result['params'])->firstWhere('key', 'en388');
        $this->assertSame('equal', $en388['relation']);
        $this->assertSame('norms', $en388['sub']['source']);
        $this->assertFalse($en388['sub']['inferred']);
        $this->assertTrue(collect($result['params'])->firstWhere('key', 'article_type')['main']['inferred']);
    }

    public function test_glove_below_main_or_far_above_on_cut_is_rejected(): void
    {
        $main = $this->card('gloves', 'Rękawice powlekane nitrylem antyprzecięciowe A', 'Ansell', 'EN 388:2016 4X42B', 'Rękawice powlekane nitrylem na dłoni i palcach.');
        $lower = $this->card('gloves', 'Rękawice powlekane nitrylem antyprzecięciowe B', 'ATG', 'EN 388:2016 4X42A', 'Rękawice powlekane nitrylem na dłoni i palcach.');
        $muchHigher = $this->card('gloves', 'Rękawice powlekane nitrylem antyprzecięciowe C', 'MAPA', 'EN 388:2016 4X42F', 'Rękawice powlekane nitrylem na dłoni i palcach.');
        $oneUp = $this->card('gloves', 'Rękawice powlekane nitrylem antyprzecięciowe D', 'UVEX', 'EN 388:2016 4X42C', 'Rękawice powlekane nitrylem na dłoni i palcach.');

        $this->assertFalse($this->compare($main, $lower)['ok']);
        $this->assertFalse($this->compare($main, $muchHigher)['ok'], 'litera F przy B to inny wyrób, nie premium');
        $up = $this->compare($main, $oneUp);
        $this->assertTrue($up['ok'], (string) ($up['reason'] ?? ''));
        $this->assertSame('premium', $up['verdict']);
    }

    public function test_substitute_without_stated_level_is_rejected_not_assumed(): void
    {
        $main = $this->card('gloves', 'Rękawice powlekane nitrylem Grip 1', 'Ansell', 'EN 388:2016 4131X', 'Rękawice powlekane nitrylem na dłoni i palcach.');
        $silent = $this->card('gloves', 'Rękawice powlekane nitrylem Grip 2', 'ATG', 'EN 388', 'Rękawice powlekane nitrylem na dłoni i palcach spełniające normę EN 388.');

        $result = $this->compare($main, $silent);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('EN 388', $result['reason']);
    }

    public function test_ffp_valve_class_band_and_english_valve_wording(): void
    {
        $main = $this->card('respiratory', 'Półmaska filtrująca FFP2 z zaworem Aura', '3M', 'EN 149:2001+A1:2009', 'Półmaska kubkowa FFP2 z zaworem wydechowym.');
        $ffp3 = $this->card('respiratory', 'Półmaska filtrująca FFP3 z zaworem Typhoon', 'JSP', 'EN 149:2001+A1:2009', 'Półmaska kubkowa FFP3 z zaworem wydechowym.');
        $noValve = $this->card('respiratory', 'Respirator SPIRO FFP2 without valve, foldable', 'Canis', 'EN 149', 'Respirator FFP2 without valve, cup shaped.');

        $up = $this->compare($main, $ffp3);
        $this->assertTrue($up['ok'], (string) ($up['reason'] ?? ''));
        $this->assertSame('premium', $up['verdict']);
        $this->assertSame('bez zaworu', $this->profiler->profile($noValve)->flags['k']['valve']);
        $this->assertFalse($this->compare($main, $noValve)['ok']);
        $this->assertSame(null, $this->matcher->relation('ffp', 'FFP1', 'FFP3'), 'FFP1 → FFP3 poza pasmem');
    }

    public function test_hearing_snr_band_and_plug_features(): void
    {
        $this->assertSame('meets', $this->matcher->relation('snr', '27', '30'));
        $this->assertNull($this->matcher->relation('snr', '27', '33'), 'nadmierne tłumienie to inna klasa ochronnika');

        $corded = $this->card('hearing', 'Wkładki przeciwhałasowe jednorazowe ze sznurkiem SNR 37 dB', '3M', 'EN 352-2', 'Wkładki przeciwhałasowe z pianki ze sznurkiem.');
        $uncorded = $this->card('hearing', 'Zatyczki jednorazowe bez sznurka SNR 37 dB', 'UVEX', 'EN 352-2', 'Wkładki przeciwhałasowe z pianki bez sznurka.');

        $this->assertTrue($this->profiler->profile($corded)->flags['k']['corded']);
        $this->assertFalse($this->profiler->profile($uncorded)->flags['k']['corded']);
        $this->assertFalse($this->compare($corded, $uncorded)['ok']);
    }

    public function test_footwear_type_class_and_markings_gates(): void
    {
        $main = $this->card('footwear', 'Półbuty S3 SRC ESD Arox', 'ARTRA', 'EN ISO 20345:2011', 'Półbuty bezpieczne S3 SRC ESD z podnoskiem kompozytowym i wkładką antyprzebiciową tekstylną.');
        $same = $this->card('footwear', 'Półbuty uvex 1 S3 SRC ESD', 'UVEX', 'EN ISO 20345:2011', 'Półbuty bezpieczne S3 SRC ESD z podnoskiem kompozytowym i wkładką antyprzebiciową tekstylną.');
        $noEsd = $this->card('footwear', 'Półbuty Heckel S3 SRC', 'HECKEL', 'EN ISO 20345:2011', 'Półbuty bezpieczne S3 SRC z podnoskiem kompozytowym i wkładką antyprzebiciową tekstylną.');
        $boot = $this->card('footwear', 'Trzewiki Canis S3 SRC ESD', 'Canis', 'EN ISO 20345:2011', 'Trzewiki bezpieczne S3 SRC ESD z podnoskiem kompozytowym i wkładką antyprzebiciową tekstylną.');
        $s1p = $this->card('footwear', 'Półbuty Elten S1P SRC ESD', 'ELTEN', 'EN ISO 20345:2011', 'Półbuty bezpieczne S1P SRC ESD z podnoskiem kompozytowym i wkładką antyprzebiciową tekstylną.');

        $ok = $this->compare($main, $same);
        $this->assertTrue($ok['ok'], (string) ($ok['reason'] ?? ''));
        $this->assertSame('brak oznaczenia ESD', $this->compare($main, $noEsd)['reason']);
        $this->assertSame('inny rodzaj wyrobu', $this->compare($main, $boot)['reason']);
        $this->assertFalse($this->compare($main, $s1p)['ok']);
    }

    public function test_same_brand_named_in_distributor_card_and_same_model_are_not_substitutes(): void
    {
        $this->card('respiratory', 'Półmaska 3M Aura FFP2 z zaworem 9322+', '3M', 'EN 149', 'Półmaska kubkowa FFP2 z zaworem.');
        $main = $this->card('respiratory', 'Półmaska filtrująca FFP2 z zaworem Typhoon', 'JSP', 'EN 149', 'Półmaska kubkowa FFP2 z zaworem.');
        $resold = $this->card('respiratory', 'Respirator 3M AURA 9322+ FFP2 with valve', 'Canis', 'EN 149', 'Respirator FFP2 with valve, cup shaped.');
        $jspResold = $this->card('respiratory', 'Respirator JSP Typhoon FFP2 with valve', 'Canis', 'EN 149', 'Respirator FFP2 with valve, cup shaped.');

        $this->assertSame(CanonicalBrand::key('3M'), $this->profiler->profile($resold)->brandKey);
        $this->assertTrue($this->compare($main, $resold)['ok'], 'karta dystrybutora 3M to inny producent niż JSP');
        $this->assertSame('ten sam producent', $this->compare($main, $jspResold)['reason']);
    }

    public function test_card_whose_name_names_another_garment_is_not_a_main(): void
    {
        $knickers = $this->card('footwear', 'Knickers, 100% cotton, size M-3XL', 'Canis', '', 'Trzewiki bezpieczne S3 SRC, podnosek stalowy.');

        $this->assertSame('nazwa wskazuje inną grupę wyrobów', $this->profiler->mainIneligibility($knickers, $this->profiler->profile($knickers)));
    }

    /**
     * @return array<string, mixed>
     */
    private function compare(Product $main, Product $sub): array
    {
        return $this->matcher->compare($main, $this->profiler->profile($main), $sub, $this->profiler->profile($sub));
    }

    private function card(string $family, string $name, string $manufacturer, string $norms, string $description): Product
    {
        static $n = 0;
        $n++;

        return Product::query()->create([
            'sku' => 'SUB-'.$n,
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
