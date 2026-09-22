<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\BhpAttributeNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BhpAttributeNormalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalizes_llm_attributes_and_derives_missing(): void
    {
        $n = new BhpAttributeNormalizer;
        $attrs = $n->normalize(
            [
                'kategoria_bhp' => 'rękawice',
                'material' => 'nitryl',
                'normy_en' => ['EN 388:2016'],
                'poziomy_en388' => '4X42C',
            ],
            [
                'materials' => ['poliamid'],
                'specs' => ['Rozmiar: 9', 'Klasa: S3'],
                'sku' => 'RNITZ-9',
                'name' => 'Rękawice nitrylowe',
                'category' => 'Rękawice',
            ]
        );

        $this->assertSame('rekawice', $attrs['kategoria_bhp']);
        $this->assertSame('nitryl', $attrs['material']);
        $this->assertContains('nitryl', $attrs['materialy']);
        $this->assertContains('poliamid', $attrs['materialy']);
        $this->assertSame('RNITZ-9', $attrs['kod_producenta']);
        $this->assertSame('4X42C', $attrs['poziomy_en388']);
        $this->assertSame('9', $attrs['rozmiar']);
    }

    public function test_detects_size_ranges_from_description(): void
    {
        $n = new BhpAttributeNormalizer;

        $this->assertSame('36-48', $n->normalize([], [
            'name' => 'Artra AROSIO Air S1P',
            'description' => 'Rozmiary unisex od 36 do 48. Tabela rozmiarów producenta.',
        ])['rozmiar']);
        $this->assertSame('7-11', $n->normalize([], [
            'name' => 'Rękawice nitrylowe',
            'description' => 'Dostępne rozmiary: 7, 8, 9, 10, 11',
        ])['rozmiar']);
        $this->assertSame('s-xxl', $n->normalize([], [
            'name' => 'Spodnie robocze',
            'description' => 'Rozmiary od S do XXL.',
        ])['rozmiar']);
        $this->assertNull($n->normalize(
            ['kategoria_bhp' => 'obuwie', 'rozmiar' => '1-5XL'],
            [
                'name' => 'OWERTON (05 NERO)',
                'description' => 'Chodaki Cofra, EN 20347. Brak tabeli w akapicie.',
                'category' => 'Obuwie',
            ]
        )['rozmiar']);
        $this->assertSame('39-47', $n->normalize(
            ['kategoria_bhp' => 'obuwie', 'rozmiar' => '1-5XL'],
            [
                'name' => 'OWERTON',
                'description' => 'Chodaki Cofra. Taglie 39-47.',
                'category' => 'Obuwie',
            ]
        )['rozmiar']);
    }

    public function test_for_product_derives_from_enrichment_lists(): void
    {
        $product = Product::query()->create([
            'sku' => 'C300',
            'name' => 'Rękawice ochronne cut',
            'manufacturer' => 'uvex',
            'category' => 'Rękawice',
            'norms' => 'EN 388:2016 4X42C',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['Dyneema', 'poliamid'],
                'norms' => ['EN 388:2016 4X42C'],
                'specs' => [],
            ],
        ]);

        $attrs = (new BhpAttributeNormalizer)->forProduct($product);

        $this->assertSame('rekawice', $attrs['kategoria_bhp']);
        $this->assertSame('Dyneema', $attrs['material']);
        $this->assertNotEmpty($attrs['normy_en']);
        $this->assertSame('4X42C', $attrs['poziomy_en388']);
    }

    public function test_for_product_extracts_from_polish_description(): void
    {
        $product = Product::query()->create([
            'sku' => '34-848',
            'name' => 'KW Palm Coated',
            'manufacturer' => 'ATG / Maxiflex',
            'category' => null,
            'description' => "Rękawica ochronna ATG z powłoką nitrylową.\n\nNormy:\n- EN 388:2016 + A1:2018 - 4131A\n- EN ISO 21420:2020",
            'catalog_price_net' => 20,
            'purchase_price' => 15,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
            'enrichment_payload' => null,
        ]);

        $attrs = (new BhpAttributeNormalizer)->forProduct($product);

        $this->assertSame('rekawice', $attrs['kategoria_bhp']);
        $this->assertSame('nitryl', $attrs['material']);
        $this->assertNotEmpty($attrs['normy_en']);
        $this->assertSame('4131A', $attrs['poziomy_en388']);
    }

    public function test_detects_face_protection_not_head(): void
    {
        $n = new BhpAttributeNormalizer;
        $this->assertSame('ochrona_twarzy', $n->normalize(
            [],
            ['name' => 'Osłona twarzy żaroodporna siatkowa', 'category' => '']
        )['kategoria_bhp']);
        $this->assertSame('ochrona_oczu', $n->normalize(
            [],
            ['name' => 'Gogle chemiczne', 'category' => '']
        )['kategoria_bhp']);
        $this->assertSame('odziez', $n->normalize(
            [],
            ['name' => 'Kamizelka odblaskowa siatkowa', 'category' => '']
        )['kategoria_bhp']);
    }

    public function test_parses_s5_ci_sra_and_purofort_as_wellington(): void
    {
        $attrs = (new BhpAttributeNormalizer)->normalize(
            [
                'kategoria_bhp' => 'obuwie',
                'material' => 'Purofort',
                'klasa_ochrony' => 'S5.CI.SRA',
                'normy_en' => ['EN ISO 20345:2011'],
            ],
            [
                'name' => 'DUNLOP 462933 PUROFORT',
                'description' => 'Kalosz do rolnictwa, izolacja -20°C.',
                'category' => 'Obuwie',
                'use_cases' => ['rolnictwo'],
            ]
        );

        $this->assertSame('S5', $attrs['klasa_ochrony']);
        $this->assertContains('CI', $attrs['oznaczenia']);
        $this->assertContains('SRA', $attrs['oznaczenia']);
        $this->assertSame('kalosz', $attrs['typ_wyrobu']);
        $this->assertSame('guma', $attrs['rodzina_materialu']);
        $this->assertSame('agriculture', $attrs['przeznaczenie']);
        $this->assertNotContains('SRC', $attrs['oznaczenia']);
    }

    public function test_does_not_treat_src_or_hro_as_protection_class(): void
    {
        $attrs = (new BhpAttributeNormalizer)->normalize(
            ['kategoria_bhp' => 'obuwie'],
            [
                'name' => 'Trzewiki spawalnicze S3 HRO SRC',
                'description' => 'Skóra wodoodporna, spawanie.',
                'category' => 'Obuwie',
            ]
        );

        $this->assertSame('S3', $attrs['klasa_ochrony']);
        $this->assertContains('HRO', $attrs['oznaczenia']);
        $this->assertContains('SRC', $attrs['oznaczenia']);
        $this->assertNotContains('SR', $attrs['oznaczenia'], 'SRC to jedno oznaczenie, nie SR + C');
        $this->assertSame('trzewik', $attrs['typ_wyrobu']);
        $this->assertSame('skora', $attrs['rodzina_materialu']);
        $this->assertSame('welding', $attrs['przeznaczenie']);
    }

    public function test_reads_esd_wru_and_standalone_sr_markings(): void
    {
        $attrs = (new BhpAttributeNormalizer)->normalize(
            ['kategoria_bhp' => 'obuwie'],
            [
                'name' => 'ARYEL 320 671460 S3L',
                'description' => 'Trzewik bezpieczny, EN ISO 20345:2022 S3L FO SR WRU, obuwie ESD.',
                'category' => 'Obuwie',
            ]
        );

        $this->assertSame('S3L', $attrs['klasa_ochrony']);
        $this->assertContains('ESD', $attrs['oznaczenia']);
        $this->assertContains('WRU', $attrs['oznaczenia']);
        $this->assertContains('SR', $attrs['oznaczenia']);
        $this->assertContains('FO', $attrs['oznaczenia']);
        $this->assertNotContains('WR', $attrs['oznaczenia'], 'WRU (cholewka) to nie WR (cały wyrób)');
    }

    public function test_reads_o2_class_from_sztyblety_name(): void
    {
        $n = new BhpAttributeNormalizer;
        $this->assertSame('O2', $n->footwearClass('sztyblety O2'));
        $this->assertSame('S2', $n->footwearClass('półbuty s2 na zam.'));
        $this->assertSame('klasao2', $n->footwearClassToken('O2'));
        $this->assertTrue($n->footwearClassMeets('S3', 'S3'));
        $this->assertTrue($n->footwearClassMeets('S3', 'S3L'));
        $this->assertFalse($n->footwearClassMeets('S3', 'S1P'));
        $this->assertFalse($n->footwearClassMeets('S3', 'S5'));

        $attrs = $n->normalize(
            ['kategoria_bhp' => 'obuwie'],
            [
                'name' => 'sztyblety O2',
                'category' => 'Obuwie zawodowe',
            ]
        );
        $this->assertSame('O2', $attrs['klasa_ochrony']);
        $this->assertSame('sztyblet', $attrs['typ_wyrobu']);
    }

    public function test_parses_ffp_class_for_filtering_half_mask(): void
    {
        $attrs = (new BhpAttributeNormalizer)->normalize(
            [
                'kategoria_bhp' => 'drogi_oddechowe',
                'normy_en' => ['EN 149'],
            ],
            [
                'name' => 'Półmaska filtrująca 9914',
                'description' => 'FFP1 z węglem, EN 149.',
                'category' => 'Ochrona dróg oddechowych',
            ]
        );

        $this->assertSame('FFP1', $attrs['klasa_ochrony']);
        $this->assertSame('ffp', $attrs['typ_wyrobu']);
        $this->assertSame('drogi_oddechowe', $attrs['kategoria_bhp']);
    }

    public function test_reads_celsius_from_requirement_and_ignores_grammage(): void
    {
        $n = new BhpAttributeNormalizer;

        $this->assertSame(200, $n->requiredCelsius('rękawice do pracy przy 200 C'));
        $this->assertSame(200, $n->requiredCelsius('rękawice do pracy przy 200 stopni'));
        $this->assertSame(200, $n->requiredCelsius('rękawice do pracy przy 200 stopni C'));
        $this->assertSame(250, $n->maxCelsius('Kontakt 250°C, konwekcja 100°C. EN 407.'));
        $this->assertSame(100, $n->maxCelsius(
            'gwarantuje ochronę 360 stopni przed zadrapaniami. odporność termiczna: do 100°C przez 15s (EN407)'
        ));
        $this->assertNull($n->maxCelsius('zapewniające 360° ochrony przed otarciami'));
        $this->assertNull($n->maxCelsius('Ochrona 360 stopni, w tym w okolicy nadgarstka'));
        $this->assertNull($n->maxCelsius('ochronę 360° dookoła dłoni'));
        $this->assertNull($n->requiredCelsius('spodnie o gramaturze 250 g/m²'));
        $this->assertNull($n->maxCelsius('Opakowanie 200 szt.'));
        $this->assertSame(350, $n->maxCelsius('Rękawice termiczne 350°C'));
        $this->assertNull($n->maxCelsius('Rękawice termiczne 350°C', true));
        $this->assertSame(350, $n->maxCelsius(
            'Rękawice termiczne. Kontakt 350°C, EN 407.',
            true
        ));
        $this->assertNull($n->maxCelsius(
            'Rękawice dziane z poliamidu. Końcówki palców powlekane poliuretanem.',
            true
        ));
    }

    public function test_reads_snr_threshold_from_requirement_and_card(): void
    {
        $n = new BhpAttributeNormalizer;

        $this->assertSame(30, $n->requiredSnr('Ochronniki słuchu nagłowne o tłumieniu SNR minimum 30 dB'));
        $this->assertSame(31, $n->requiredSnr('nauszniki SNR ≥ 31'));
        $this->assertSame(28, $n->requiredSnr('tłumienie min. 28 dB'));
        $this->assertNull($n->requiredSnr('Ochronniki słuchu na hełm MSA - niski poziom tłumienia'));
        $this->assertNull($n->requiredSnr('Nauszniki przeciwhałasowe 3M Peltor X2 wersja nagłowna'));
        $this->assertSame(31, $n->snrRating('3M Nauszniki PELTOR X2 - wersja nagłowna (SNR 31 dB)'));
        $this->assertSame(27, $n->snrRating('PELTOR X1 (SNR 27 dB)'));
        $this->assertNull($n->snrRating('Nauszniki przeciwhałasowe bez podanego tłumienia'));
    }

    public function test_drops_foreign_model_code_when_name_has_other_word_digit_pair(): void
    {
        $attrs = (new BhpAttributeNormalizer)->normalize(
            ['kod_producenta' => 'ARAGONIT 8423 1010 S2', 'kategoria_bhp' => 'obuwie'],
            [
                'name' => 'ARTRA Półbuty ARGON 8229 1010 S2',
                'sku' => 'ARGON8229',
                'category' => 'Obuwie',
            ]
        );

        $this->assertSame('ARGON 8229 1010 S2', $attrs['kod_producenta']);
    }

    public function test_valve_state_from_name(): void
    {
        $n = new BhpAttributeNormalizer;

        $this->assertSame(1, $n->valveState('3M™ Aura™ półmaska filtrująca, FFP1, z zaworem, 9312+'));
        $this->assertSame(1, $n->valveState('Półmaska FFP2 z zaworkiem Cool Flow'));
        $this->assertSame(0, $n->valveState('3M™ Aura™ półmaska filtrująca, FFP1, bez zaworu, 9310+'));
        $this->assertSame(0, $n->valveState('polmaska ffp1 bez zaworu 8710e'));
        $this->assertNull($n->valveState('Półmaska filtrująca FFP1 X'));
        $this->assertNull($n->valveState(''));
    }

    public function test_footwear_class_reads_s1_p_with_space(): void
    {
        $n = new BhpAttributeNormalizer;

        $this->assertSame('S1P', $n->footwearClass('ARMEN 9007 6660 S1 P'));
        $this->assertSame('S1P', $n->footwearClass('Sandały ochronne kategorii S1 P wg EN ISO 20345'));
        $this->assertSame('S1P', $n->footwearClass('półbuty S1P ESD'));
        $this->assertSame('S1', $n->footwearClass('AROX 733 641460 S1 ESD'));
        $this->assertSame('S1', $n->footwearClass('półbuty S1 PU podeszwa'), 'S1 + inne słowo na P to nie S1P');
        $this->assertFalse($n->footwearClassMeets('S1P', 'S1'));
        $this->assertTrue($n->footwearClassMeets('S1P', 'S1P'));
        $this->assertTrue($n->footwearClassMeets('S1', 'S1P'));
        // klasa wyższa spełnia niższą: S3 ma wkładkę antyprzebiciową (S1P) i wodoodporność (S2)
        $this->assertTrue($n->footwearClassMeets('S1P', 'S3'));
        $this->assertTrue($n->footwearClassMeets('S1P', 'S3L'));
        $this->assertTrue($n->footwearClassMeets('S2', 'S3'));
        $this->assertTrue($n->footwearClassMeets('SB', 'S1'));
        $this->assertFalse($n->footwearClassMeets('S1P', 'S2'));
        $this->assertFalse($n->footwearClassMeets('S2', 'S1P'));
        $this->assertTrue($n->footwearClassMeets('O1', 'O2'));
        $this->assertFalse($n->footwearClassMeets('O2', 'O1'));
        $this->assertFalse($n->footwearClassMeets('O1', 'S1'));

        $attrs = $n->normalize(['kategoria_bhp' => 'obuwie'], [
            'name' => 'ARMEN 9007 6660 S1 P',
            'description' => 'Sandały robocze, klasa S1 P wg EN ISO 20345.',
            'category' => 'Obuwie',
        ]);
        $this->assertSame('S1P', $attrs['klasa_ochrony']);
    }

    public function test_footwear_class_reads_2022_insert_suffixes(): void
    {
        $n = new BhpAttributeNormalizer;

        $this->assertSame('S3L', $n->footwearClass('ARYEL 320 671460 S3L'));
        $this->assertSame('S3L', $n->footwearClass('Trzewik EN ISO 20345:2022 S3 L'), 'sufiks wkładki ze spacją');
        $this->assertSame('S3S', $n->footwearClass('Półbut S3S'));
        $this->assertSame('S1PL', $n->footwearClass('ARDEUS 350 Air 618080 S1 PL ESD'));
        $this->assertSame('S1PL', $n->footwearClass('Sandał S1 P L'));
        $this->assertSame('S5L', $n->footwearClass('Kalosz S5L'));
        $this->assertSame('S6', $n->footwearClass('Trzewik S6'));
        $this->assertSame('S7L', $n->footwearClass('Trzewik S7L'));
        $this->assertSame('S3', $n->footwearClass('Trzewik S3 SRC'), 'SRC to nie sufiks wkładki');
        $this->assertSame('S2', $n->footwearClass('Trzewik S2 L'), 'S2 nie ma wkładki, więc nie bierze L');

        // S7 = S3 + wodoodporność, S6 = S2 + wodoodporność (wydanie 2022).
        $this->assertTrue($n->footwearClassMeets('S3', 'S7'));
        $this->assertTrue($n->footwearClassMeets('S2', 'S6'));
        $this->assertTrue($n->footwearClassMeets('S6', 'S7'));
        $this->assertFalse($n->footwearClassMeets('S7', 'S3'));
        $this->assertFalse($n->footwearClassMeets('S6', 'S2'));
        // Typ wkładki nie jest podstawą do odrzucenia karty — rozstrzyga go wiersz „Klasa obuwia”.
        $this->assertTrue($n->footwearClassMeets('S3', 'S3L'));
        $this->assertTrue($n->footwearClassMeets('S3L', 'S3'));
        $this->assertTrue($n->footwearClassMeets('S3L', 'S7S'));
        // Obuwie całogumowe stoi osobno: S5 nie jest zamiennikiem trzewika S3.
        $this->assertFalse($n->footwearClassMeets('S3', 'S5'));
        $this->assertFalse($n->footwearClassMeets('S1', 'S4'));
        $this->assertTrue($n->footwearClassMeets('S4', 'S5'));
        $this->assertTrue($n->footwearClassMeets('O2', 'O7'));
        $this->assertFalse($n->footwearClassMeets('O3', 'S3'));

        $this->assertSame(['S3', 'S7'], $n->footwearClassesSatisfying('S3L'));
        $this->assertSame(['S1P', 'S3', 'S7'], $n->footwearClassesSatisfying('S1 P'));
    }

    public function test_degraded_class_in_payload_is_repaired_from_card_text(): void
    {
        // Karta z bazy: stary parser zapisał „S1”, bo gubił sufiks wkładki z nazwy „S1 PL”.
        $attrs = (new BhpAttributeNormalizer)->normalize(
            ['kategoria_bhp' => 'obuwie', 'klasa_ochrony' => 'S1'],
            [
                'name' => 'ARDEUS 350 Air 618080 S1 PL ESD',
                'description' => 'Trzewik bezpieczny z wkładką antyprzebiciową.',
                'category' => 'Obuwie robocze S1-S3',
            ]
        );

        $this->assertSame('S1PL', $attrs['klasa_ochrony']);
    }

    public function test_class_from_card_text_never_replaces_another_class_family(): void
    {
        // Folder sklepu i opis mogą wymieniać inne klasy — uszczegóławiamy zapis, nie podmieniamy klasy.
        $attrs = (new BhpAttributeNormalizer)->normalize(
            ['kategoria_bhp' => 'obuwie', 'klasa_ochrony' => 'S3'],
            [
                'name' => 'Trzewik roboczy 671460',
                'description' => 'Zastępuje wcześniejszy model O1 i sandały S1 P.',
                'category' => 'Obuwie robocze S1-S3',
            ]
        );

        $this->assertSame('S3', $attrs['klasa_ochrony']);
    }

    public function test_ffp_class_meets_reads_both_sides(): void
    {
        $n = new BhpAttributeNormalizer;

        $this->assertSame('FFP2', $n->ffpClass('Półmaska filtrująca FFP2 NR D'));
        $this->assertSame('FFP1', $n->ffpClass('3M Aura FFP-1 9310+'));
        $this->assertNull($n->ffpClass('Półmaska SECURA 3000'));
        $this->assertFalse($n->ffpClassMeets('Półmaska FFP2 z zaworem', '3M Aura półmaska filtrująca FFP1'));
        $this->assertTrue($n->ffpClassMeets('Półmaska FFP2 z zaworem', '3M Aura FFP2 9322+'));
        $this->assertTrue($n->ffpClassMeets('Półmaska FFP2 z zaworem', '3M Aura FFP3 9332+'));
        $this->assertTrue($n->ffpClassMeets('Półmaska FFP2 z zaworem', 'Półmaska SECURA 3000'), 'brak klasy = brak wiedzy');
        $this->assertTrue($n->ffpClassMeets('Półmaska wielorazowa', 'FFP1'));
    }

    /**
     * Zgłoszenie testerki „normy podane podwójnie”: ten sam kod wchodził raz jako goły zapis
     * (regex z opisu), raz z objaśnieniem (lista z karty). NormCode::dedupe tego nie zwija,
     * bo zapisu z nawiasem nie uznaje za oznaczenie normy.
     */
    public function test_norm_with_explanation_replaces_the_bare_code(): void
    {
        $n = new BhpAttributeNormalizer;
        $attrs = $n->normalize(
            ['normy_en' => ['EN 14126', 'Typ 3-B', 'EN 14605']],
            [
                'norms' => [
                    'EN 14126 (ochrona przed czynnikami biologicznymi)',
                    'Typ 3-B (ochrona przed cieczami)',
                    'EN 14605 (Typ PB[3]-B, PB[4]-B, PB[6]-B)',
                ],
                'sku' => 'GR40T-00126-07',
                'name' => '4000-GR APOL ENCAP SCBA 126-G02.3XL',
            ]
        );

        $this->assertSame([
            'EN 14126 (ochrona przed czynnikami biologicznymi)',
            'Typ 3-B (ochrona przed cieczami)',
            'EN 14605 (Typ PB[3]-B, PB[4]-B, PB[6]-B)',
        ], $attrs['normy_en']);
    }

    /**
     * Podobny początek to nie ten sam kod: „EN 1497” tylko zaczyna się jak „EN 149”,
     * a część normy („EN 374-1” wobec „EN 374”) i typ badania to w przetargu różne wymagania.
     */
    public function test_different_norms_with_shared_prefix_are_kept_apart(): void
    {
        $n = new BhpAttributeNormalizer;
        $attrs = $n->normalize(
            ['normy_en' => [
                'EN 149', 'EN 1497',
                'EN 374', 'EN 374-1', 'EN 374-5',
                'EN 374-1 (Typ A)', 'EN 374-1 (Typ B)',
            ]],
            ['sku' => 'TEST-1', 'name' => 'Wyrób testowy']
        );

        // „EN 374-1” wchłania się w pierwszy zapis z dopiskiem, bo sam z siebie nic nie wnosi;
        // „EN 374” i „EN 374-5” to inne części normy i zostają osobno.
        $this->assertSame(
            ['EN 149', 'EN 1497', 'EN 374', 'EN 374-1 (Typ A)', 'EN 374-5', 'EN 374-1 (Typ B)'],
            $attrs['normy_en']
        );
    }

    /** Tabelka z karty ARTRA — w produkcji identyczna poza wierszem normy (i kodem koloru w cholewce). */
    private static function artraShopFields(string $normLine): string
    {
        return "Parametry\ncholewka: wegańska RACYA SKINYUM™ – Zacznijcie tutaj.\npodszewka: DRYUM™\n"
            ."podeszwa: GRIPPER PU.2D z technologią LEVITARYUM™\n{$normLine}\nWaga: 430 gramów dla rozmiaru 42\n"
            ."Rozmiary: EU 35, EU 36, EU 37\nRozmiar EU 35: 21,8";
    }

    /**
     * Karta 9577 z produkcji (22.09.2026): cennik i nazwa mówią O1, empik w payloadzie „S2 CI SRC”, a tabelka
     * ARTRA (zamieniona z wariantem S1 P) — „EN ISO 20345:2011 S1 P SRC”. Karta zostaje O1, a zapisy cudzej
     * klasy nie wchodzą ani do norm, ani do oznaczeń.
     */
    public function test_class_from_price_list_and_name_beats_payload_and_supplier_table_of_another_variant(): void
    {
        $product = new Product([
            'sku' => 'ARMEN 900 6060 O1 FO',
            'name' => 'ARMEN 900 6060 O1 FO',
            'category' => 'Sklep - kategorie / Obuwie robocze i ochronne / Sandały ochronne',
            'description' => 'Konstrukcja obuwia ARELAX® zapewnia przestrzeń dla wszystkich palców i swobodę ruchu.',
            'shop_fields_summary' => self::artraShopFields('norma: EN ISO 20345:2011 S1 P SRC'),
            'price_list_attributes' => ['klasa_ochrony' => 'O1', 'rozmiar' => '35-48', 'typ_wyrobu' => 'sandały'],
            'enrichment_payload' => [
                'norms' => ['EN ISO 20345:2011 S2 CI SRC'],
                'specs' => ['Kod producenta: ARMEN 900 6060 O1 FO', 'Norma: EN ISO 20345:2011 S2 CI SRC'],
                'attributes' => [
                    'kategoria_bhp' => 'obuwie',
                    'klasa_ochrony' => 'S2',
                    'normy_en' => ['EN ISO 20345:2011', 'EN ISO 20345:2011 S2 CI SRC'],
                    'oznaczenia' => ['CI', 'SRC', 'FO'],
                    'przeznaczenie' => 'electric',
                ],
            ],
        ]);

        $attrs = (new BhpAttributeNormalizer)->forProduct($product);

        $this->assertSame('O1', $attrs['klasa_ochrony']);
        $this->assertNotContains('EN ISO 20345:2011 S2 CI SRC', $attrs['normy_en']);
        // EN ISO 20345 to obuwie S — przy klasie O1 goły zapis z tabelki też nie jest normą tego wyrobu
        foreach ($attrs['normy_en'] as $norm) {
            $this->assertStringNotContainsString('20345', $norm);
        }
        $this->assertSame(['FO'], $attrs['oznaczenia'], 'CI i SRC należą do zapisu klasy S2 / S1 P, nie do tej karty');
        $this->assertNull($attrs['przeznaczenie'], 'zapisane wyliczenie „electric” nie jest czytane z payloadu');
    }

    /** Karta 9524: payload pusty, klasa i norma są tylko w tabelce dostawcy. */
    public function test_supplier_table_gives_class_and_norm_when_payload_is_empty(): void
    {
        $product = new Product([
            'sku' => 'ARMEN 9003 2360 S1',
            'name' => 'ARMEN 9003 2360 S1',
            'category' => 'Sklep - kategorie / Obuwie robocze i ochronne / Sandały ochronne',
            'shop_fields_summary' => self::artraShopFields('norma: EN ISO 20345:2022 S1 FO SR'),
            'enrichment_payload' => ['attributes' => ['kategoria_bhp' => 'obuwie', 'klasa_ochrony' => null]],
        ]);

        $attrs = (new BhpAttributeNormalizer)->forProduct($product);

        $this->assertSame('S1', $attrs['klasa_ochrony']);
        $this->assertSame(['EN ISO 20345:2022'], $attrs['normy_en']);
        $this->assertContains('FO', $attrs['oznaczenia']);
        $this->assertContains('SR', $attrs['oznaczenia']);

        // Ta sama tabelka w ścieżce wzbogacania (normalize z kontekstem jak w ProductEnrichmentService).
        $enriched = (new BhpAttributeNormalizer)->normalize(null, [
            'name' => 'ARMEN 9003',
            'category' => 'Obuwie robocze i ochronne',
            'shop_fields' => self::artraShopFields('norma: EN ISO 20345:2022 S1 FO SR'),
        ]);
        $this->assertSame('S1', $enriched['klasa_ochrony'], 'nazwa milczy, więc klasę daje tabelka dostawcy');
        $this->assertSame(['EN ISO 20345:2022'], $enriched['normy_en']);
    }

    /** Tabelka dostawcy uszczegóławia klasę z nazwy tylko w obrębie tej samej bazy. */
    public function test_supplier_table_only_refines_the_class_from_the_name(): void
    {
        $n = new BhpAttributeNormalizer;
        $refined = $n->normalize(['kategoria_bhp' => 'obuwie'], [
            'name' => 'ARMEN 9007 6660 S1 P',
            'shop_fields' => 'norma: EN ISO 20345:2022 S1 PL FO SR',
        ]);
        $this->assertSame('S1PL', $refined['klasa_ochrony']);

        $other = $n->normalize(['kategoria_bhp' => 'obuwie'], [
            'name' => 'ARMEN 900 6060 O1 FO',
            'shop_fields' => 'norma: EN ISO 20345:2011 S1 P SRC',
        ]);
        $this->assertSame('O1', $other['klasa_ochrony'], 'inna baza w tabelce to sprzeczność do sprawdzenia, nie fakt');
    }

    /**
     * Nazwa, SKU i kolumna norm to trzy kolejne źródła klasy; SKU, kolumnę norm i tabelkę czytamy tylko wielkimi
     * literami i jako osobny wyraz. Złączony tekst czytany luźnym wzorcem brał „s1” z kodu „BRS-s1-42” (i przesiewał
     * nim prawdziwe normy S3), a z tabelki „Indeks: OB-4512” klasę OB.
     */
    public function test_class_from_sku_norms_column_and_table_needs_a_standalone_uppercase_record(): void
    {
        $n = new BhpAttributeNormalizer;

        $sku = $n->normalize(
            ['kategoria_bhp' => 'obuwie', 'klasa_ochrony' => 'S3', 'normy_en' => ['EN ISO 20345:2011 S3 SRC']],
            ['name' => 'Trzewik Reis BRS', 'sku' => 'BRS-s1-42']
        );
        $this->assertSame('S3', $sku['klasa_ochrony']);
        $this->assertSame(['EN ISO 20345:2011 S3 SRC'], $sku['normy_en']);

        $index = $n->normalize(['kategoria_bhp' => 'obuwie'], [
            'name' => 'Trzewik Polstar BRYES',
            'sku' => 'BRYES',
            'shop_fields' => "Indeks: OB-4512\nKolor: czarny",
        ]);
        $this->assertNull($index['klasa_ochrony']);

        $table = $n->normalize(['kategoria_bhp' => 'obuwie'], [
            'name' => 'Półbuty ARTRA ARYEL 320',
            'sku' => 'AR-320',
            'shop_fields' => "podnosek: kompozytowy\nnorma: EN ISO 20345:2022 S1 PL FO SR\nIndeks: OB-4512",
        ]);
        $this->assertSame('S1PL', $table['klasa_ochrony']);

        $artraSku = $n->normalize(['kategoria_bhp' => 'obuwie'], [
            'name' => 'Półbuty ARTRA ARYEL 320',
            'sku' => 'ARYEL 320 Air 618080 S1 PL ESD',
        ]);
        $this->assertSame('S1PL', $artraSku['klasa_ochrony']);

        $normsColumn = $n->normalize(['kategoria_bhp' => 'obuwie'], [
            'name' => 'Trzewik roboczy X',
            'sku' => 'X-1',
            'norms_column' => 'EN ISO 20345 S3 SRC',
        ]);
        $this->assertSame('S3', $normsColumn['klasa_ochrony']);

        $this->assertSame('S1PL', $n->footwearClassFromCode('ARYEL 320 Air 618080 S1 PL ESD'));
        $this->assertSame('S3', $n->footwearClassFromCode('X (S3)'));
        $this->assertNull($n->footwearClassFromCode('BRS-s1-42'));
        $this->assertNull($n->footwearClassFromCode('SB-123'));
        $this->assertNull($n->footwearClassFromCode('BRS s1 42'));
        $this->assertNull($n->footwearClassFromCode('G3070/S3'));
    }

    /** Karta 9495: nazwa „S1 P ESD”, a payload ze strony natare dla wariantu „O1 FO ESD”. */
    public function test_class_in_the_name_beats_payload_class_of_another_family(): void
    {
        $attrs = (new BhpAttributeNormalizer)->normalize(
            ['kategoria_bhp' => 'obuwie', 'klasa_ochrony' => 'O1', 'oznaczenia' => ['FO', 'ESD']],
            [
                'name' => 'ARCASIO 732 616560 S1 P ESD',
                'sku' => 'ARCASIO 732 616560 S1 P ESD',
                'category' => 'Półbuty ochronne',
                'specs' => ['Kod produktu: ARCASIO 732 616560', 'Klasa ochrony: O1 FO ESD'],
                'shop_fields' => "podnosek: stalowy LIBERYUM™\nnorma: EN ISO 20345:2011 S1 P SRC\nnorma: ESD według EN IEC 61340-4-3:2018",
            ]
        );

        $this->assertSame('S1P', $attrs['klasa_ochrony']);
        $this->assertContains('ESD', $attrs['oznaczenia']);
        $this->assertContains('SRC', $attrs['oznaczenia']);
        $this->assertNotContains('FO', $attrs['oznaczenia'], 'FO przyszło z zapisu klasy O1 cudzej strony');
    }

    /** Karta 9433: cennik „S1 PL”, nazwa „S1 PL ESD”. */
    public function test_price_list_class_with_insert_suffix(): void
    {
        $attrs = (new BhpAttributeNormalizer)->normalize(
            ['kategoria_bhp' => 'obuwie', 'klasa_ochrony' => 'S1PL'],
            [
                'name' => 'AROX 7333 641460 S1 PL ESD',
                'sku' => 'AROX 7333 641460 S1 PL ESD',
                'price_list' => ['klasa_ochrony' => 'S1 PL'],
                'norms' => ['EN ISO 20345:2022 S1 PL FO SR', 'EN IEC 61340-4-3:2018'],
            ]
        );

        $this->assertSame('S1PL', $attrs['klasa_ochrony']);
        $this->assertContains('EN ISO 20345:2022 S1 PL FO SR', $attrs['normy_en']);
        $this->assertSame(['ESD', 'FO', 'SR'], $attrs['oznaczenia']);
    }

    /**
     * Karta 9463: sklep dał normę wariantu S3L przy półbucie S1 PL i sam kod koloru jako kod producenta.
     * Pełnym kodem producenta jest SKU ARTRY (model, kod koloru i klasa) — kod koloru to tylko jego część.
     */
    public function test_norm_with_another_class_base_and_colour_code_are_dropped(): void
    {
        $attrs = (new BhpAttributeNormalizer)->normalize(
            ['kategoria_bhp' => 'obuwie', 'kod_producenta' => '618080', 'normy_en' => ['EN ISO 20345:2022 S3L FO SR']],
            [
                'name' => 'ARYEL 320 Air 618080 S1 PL ESD',
                'sku' => 'ARYEL 320 Air 618080 S1 PL ESD',
                'price_list' => ['klasa_ochrony' => 'S1 PL'],
                'norms' => ['EN ISO 20345:2022 S3L FO SR', 'EN IEC 61340-4-3:2018 (ESD)'],
            ]
        );

        $this->assertSame('S1PL', $attrs['klasa_ochrony']);
        $this->assertSame(['EN IEC 61340-4-3:2018 (ESD)'], $attrs['normy_en']);
        $this->assertSame('ARYEL 320 Air 618080 S1 PL ESD', $attrs['kod_producenta']);
    }

    public function test_purpose_electric_for_insulating_footwear_and_en_1149_apparel_only(): void
    {
        $n = new BhpAttributeNormalizer;

        $this->assertNull($n->normalize(['kategoria_bhp' => 'obuwie', 'przeznaczenie' => 'electric'], [
            'name' => 'Półbuty ochronne S1 ESD',
            'description' => 'Podeszwa antystatyczna, obuwie ESD wg EN IEC 61340-4-3.',
        ])['przeznaczenie']);
        $this->assertSame('electric', $n->normalize(['kategoria_bhp' => 'obuwie'], [
            'name' => 'Półbuty elektroizolacyjne',
            'norms' => ['EN 50321:2018'],
        ])['przeznaczenie']);
        $this->assertSame('electric', $n->normalize(['kategoria_bhp' => 'odziez'], [
            'name' => 'Kurtka antystatyczna',
            'norms' => ['EN 1149-5'],
        ])['przeznaczenie']);
    }

    public function test_numeric_code_equal_to_sku_or_model_number_stays(): void
    {
        $n = new BhpAttributeNormalizer;

        $this->assertSame('618080', $n->normalize(
            ['kod_producenta' => '618080'],
            ['name' => 'ARYEL 320 Air 618080 S1 PL ESD', 'sku' => '618080']
        )['kod_producenta'], 'kod równy SKU to kod wyrobu');
        $this->assertSame('9322', $n->normalize(
            ['kod_producenta' => '9322'],
            ['name' => 'Półmaska Aura 9322', 'sku' => '9322+']
        )['kod_producenta']);
        $this->assertSame('4011', $n->normalize(
            ['kod_producenta' => '4011'],
            ['name' => 'Rękawice nitrylowe 4011 Powerflex', 'sku' => 'PF-4011']
        )['kod_producenta'], 'bez pary model–numer przed kodem nie ma czego uznać za model');
    }

    /**
     * Kod koloru rozpoznajemy tylko po SKU, które niesie jednocześnie parę modelu i ten numer osobno — wtedy
     * kodem producenta jest całe SKU. Rękawica uvex kończy nazwę kodem wyrobu (SKU 6094209 to kod z rozmiarem)
     * — przegląd 22.09.2026: kod zamieniał się w „UNIDUR 6648”; Tegro z SKU bez pary modelu dawało kod
     * sklejony z nazwy „TEGRO 250 3021 S3”.
     */
    public function test_numeric_code_after_model_stays_without_footwear_class_behind_it(): void
    {
        $n = new BhpAttributeNormalizer;

        $this->assertSame('60942', $n->normalize(
            ['kod_producenta' => '60942', 'kategoria_bhp' => 'rekawice'],
            ['name' => 'Rękawice uvex unidur 6648 60942', 'sku' => '6094209']
        )['kod_producenta']);
        $this->assertSame('ARYEL 320 618080 S1PL ESD', $n->normalize(
            ['kod_producenta' => '618080', 'kategoria_bhp' => 'obuwie'],
            ['name' => 'ARYEL 320 Air 618080 S1 PL ESD', 'sku' => 'ARYEL 320 618080 S1PL ESD']
        )['kod_producenta'], 'SKU niesie model i kod barwy — to pełny kod producenta, a 618080 tylko jego część');
        $this->assertSame('3021', $n->normalize(
            ['kod_producenta' => '3021', 'kategoria_bhp' => 'obuwie'],
            ['name' => 'Trzewik Tegro 250 3021 S3 SRC', 'sku' => 'TG250-42']
        )['kod_producenta'], 'SKU bez pary modelu nie potwierdza kodu barwy');
    }

    /**
     * Przecertyfikowanie na wydanie 2022: „uvex 2 S3 WR SRC” to dziś „S7S” (S7 = S3 + wodoodporność całego
     * wyrobu). Norma ze strony producenta i jej oznaczenia zostają przy karcie; norma innej klasy — nie.
     */
    public function test_recertified_class_norm_stays_with_markings(): void
    {
        $attrs = (new BhpAttributeNormalizer)->normalize(
            ['kategoria_bhp' => 'obuwie', 'normy_en' => ['EN ISO 20345:2022 S7S CI SR', 'EN ISO 20345:2011 S1 P SRC']],
            ['name' => 'Trzewik uvex 2 S3 WR SRC', 'sku' => '6502242']
        );

        $this->assertSame('S3', $attrs['klasa_ochrony']);
        $this->assertContains('EN ISO 20345:2022 S7S CI SR', $attrs['normy_en']);
        $this->assertNotContains('EN ISO 20345:2011 S1 P SRC', $attrs['normy_en']);
        $this->assertContains('CI', $attrs['oznaczenia']);
        $this->assertContains('SR', $attrs['oznaczenia']);
        $this->assertContains('WR', $attrs['oznaczenia']);

        // Bez WR przy klasie karty S7 to inny wariant — przesiew zostaje.
        $plain = (new BhpAttributeNormalizer)->normalize(
            ['kategoria_bhp' => 'obuwie', 'normy_en' => ['EN ISO 20345:2022 S7S CI SR']],
            ['name' => 'Trzewik uvex 2 S3 SRC', 'sku' => '6502243']
        );
        $this->assertSame([], $plain['normy_en']);
        $this->assertNotContains('CI', $plain['oznaczenia']);
    }

    /**
     * Normy na karcie i na sklepie to normy zapisane i z tabelki/cennika, nie odczyt z prozy: zdanie
     * „nie podają zgodności z EN 407” nie może dać karcie EN 407 (przegląd 22.09.2026).
     */
    public function test_displayed_norms_do_not_come_from_negated_prose(): void
    {
        $n = new BhpAttributeNormalizer;
        $product = new Product([
            'sku' => '6094209',
            'name' => 'Rękawice uvex unidur 6648 60942',
            'category' => 'Rękawice',
            'description' => 'Rękawice powlekane nitrylem. Źródła nie podają zgodności z EN 407 ani EN ISO 374-1.',
            'price_list_attributes' => ['normy' => 'EN 420'],
            'enrichment_payload' => [
                'norms' => ['EN 388:2016 4X43C'],
                'attributes' => ['kategoria_bhp' => 'rekawice', 'normy_en' => ['EN 388:2016 4X43C']],
            ],
        ]);

        $shown = $n->forDisplay($product);

        $this->assertSame(['EN 420', 'EN 388:2016 4X43C'], $shown['norms']);
        $this->assertSame($shown['norms'], $shown['attributes']['normy_en']);
        $this->assertContains('EN 407', $n->forProduct($product)['normy_en'], 'dopasowanie dalej widzi kandydata z opisu');
    }

    /**
     * Klasa obuwia z nazwy tylko u obuwia rozpoznanego po słowie albo jawnej kategorii — statyw Protekt
     * „TM 14-SB” i instrukcja SignProject „OB 750 A” dostawały klasę SB/OB.
     */
    public function test_non_footwear_card_with_class_like_token_gets_no_footwear_class(): void
    {
        $n = new BhpAttributeNormalizer;

        $this->assertNull($n->normalize(null, ['sku' => 'AT016', 'name' => 'Statyw TM 14-SB', 'category' => 'Asekuracja'])['klasa_ochrony']);
        $this->assertNull($n->normalize(null, ['sku' => 'IAC30', 'name' => 'Instrukcja IAC30 OB 750 A', 'category' => 'Znaki'])['klasa_ochrony']);
        $this->assertSame('S1PL', $n->normalize(null, ['sku' => 'ARYA 300 671460 S1 PL', 'name' => 'sandały', 'category' => '144'])['klasa_ochrony']);
        $this->assertSame('S3', $n->normalize(['kategoria_bhp' => 'obuwie'], ['sku' => 'X-1', 'name' => 'Model 5 S3'])['klasa_ochrony']);
    }

    /**
     * Przeznaczenie przeliczamy na nowo tylko u obuwia („electric” przy antystatyce przyklejało się w payloadzie);
     * innym rodzinom zostaje zapis — kurtka ostrzegawcza z „rolnictwem” na liście zastosowań zostaje hivis.
     */
    public function test_stored_purpose_stays_outside_footwear(): void
    {
        $n = new BhpAttributeNormalizer;

        $kurtka = $n->normalize(
            ['kategoria_bhp' => 'odziez', 'przeznaczenie' => 'hivis'],
            ['sku' => 'K-1', 'name' => 'Kurtka ostrzegawcza', 'use_cases' => ['rolnictwo', 'drogownictwo']],
        );
        $this->assertSame('hivis', $kurtka['przeznaczenie']);

        $but = $n->normalize(
            ['kategoria_bhp' => 'obuwie', 'przeznaczenie' => 'electric'],
            ['sku' => 'B-1', 'name' => 'Półbuty S1 antystatyczne'],
        );
        $this->assertNull($but['przeznaczenie']);
    }

    /**
     * Zapisanego `przeznaczenie` nie czytamy także poza obuwiem (model go nie zwraca — w payloadzie leży dawne
     * wyliczenie): kurtka ostrzegawcza jest hivis z nazwy, choćby w payloadzie stało „agriculture”, a lista
     * zastosowań wymieniała rolnictwo.
     */
    public function test_stored_purpose_is_ignored_for_apparel_too(): void
    {
        $kurtka = (new BhpAttributeNormalizer)->normalize(
            ['kategoria_bhp' => 'odziez', 'przeznaczenie' => 'agriculture'],
            ['sku' => 'K-2', 'name' => 'Kurtka ostrzegawcza', 'use_cases' => ['rolnictwo', 'drogownictwo']],
        );

        $this->assertSame('hivis', $kurtka['przeznaczenie']);
    }

    /** Rok i poprawkę zwija NormCode, a sprzeczne poziomy zostają obie — tego nie wolno zgubić. */
    public function test_year_and_amendment_collapse_but_contradictory_levels_stay(): void
    {
        $n = new BhpAttributeNormalizer;
        $attrs = $n->normalize(
            ['normy_en' => ['EN 388', 'EN 388:2016', 'EN 388:2016+A1:2018']],
            ['sku' => 'TEST-2', 'name' => 'Wyrób testowy']
        );
        $this->assertSame(['EN 388:2016+A1:2018'], $attrs['normy_en']);

        $sprzeczne = $n->normalize(
            ['normy_en' => ['EN 388 4X42C', 'EN 388 3121X']],
            ['sku' => 'TEST-3', 'name' => 'Wyrób testowy']
        );
        $this->assertSame(['EN 388 4X42C', 'EN 388 3121X'], $sprzeczne['normy_en']);
    }
}
