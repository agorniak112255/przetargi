<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CatalogSlangDictionary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogSlangDictionaryTest extends TestCase
{
    use RefreshDatabase;

    /** Pamięć wyników analizy zapytania (wydajność bramek) nie miesza zapytań — wynik jak ze świeżego słownika. */
    public function test_query_analysis_cache_returns_same_results_as_fresh_dictionary(): void
    {
        $cached = app()->make(CatalogSlangDictionary::class);
        $queries = [
            'Rękawice wampirki uniwersalne',
            'Rękawice nitrylowe lekkie',
            'Płukanka do oczu 500 ml w butelce z wanienką',
            'Rękawice wampirki uniwersalne',
        ];
        foreach ($queries as $query) {
            $fresh = app()->make(CatalogSlangDictionary::class);
            $this->assertSame($fresh->evidenceGroups($query), $cached->evidenceGroups($query), $query);
            $this->assertSame($fresh->isNitrileMaterialQuery($query), $cached->isNitrileMaterialQuery($query), $query);
        }
    }

    public function test_wampirki_expand_to_coated_knit_gloves(): void
    {
        $phrases = $this->dict()->phrasesFor('Rękawice wampirki uniwersalne');
        $hay = mb_strtolower(implode(' ', $phrases));

        $this->assertNotSame([], $phrases);
        $this->assertTrue(str_contains($hay, 'powlekan') || str_contains($hay, 'dzianin'));
    }

    public function test_bare_wampirki_still_maps_without_glove_noun(): void
    {
        $phrases = $this->dict()->phrasesFor('wampirki');

        $this->assertNotSame([], $phrases);
    }

    public function test_ambiguous_pianki_without_family_does_not_expand(): void
    {
        $this->assertSame([], $this->dict()->phrasesFor('pianki'));
    }

    public function test_glove_pianki_does_not_pull_earplugs(): void
    {
        $hay = mb_strtolower(implode(' ', $this->dict()->phrasesFor('Rękawice pianki nitrylowe')));

        $this->assertStringContainsString('piank', $hay);
        $this->assertStringNotContainsString('wkladki', $hay);
        $this->assertStringNotContainsString('zatycz', $hay);
    }

    public function test_ogrodniczki_on_gloves_are_not_bib_overalls(): void
    {
        $hay = mb_strtolower(implode(' ', $this->dict()->phrasesFor('Rękawice ogrodniczki')));

        $this->assertStringContainsString('ogrodnic', $hay);
        $this->assertStringNotContainsString('spodnie', $hay);
    }

    public function test_bejsbolowka_and_bryle_are_indexed(): void
    {
        $dict = $this->dict();
        $cap = mb_strtolower(implode(' ', $dict->phrasesFor('bejsbolówka')));
        $this->assertStringContainsString('czapka', $cap);

        $eye = mb_strtolower(implode(' ', $dict->phrasesFor('bryle ochronne')));
        $this->assertStringContainsString('okulary', $eye);
    }

    public function test_kwasoodporne_keeps_word_and_adds_slang(): void
    {
        $rewrite = $this->dict()->searchRewrite('Rękawice kwasoodporne');
        $this->assertNotNull($rewrite);
        $hay = mb_strtolower(implode(' ', $rewrite['search_phrases']));
        $this->assertStringContainsString('kwasoodporne', $hay);
        $this->assertStringContainsString('chemiczne', $hay);
        $this->assertTrue($this->dict()->matchesEvidence(
            'Rękawice kwasoodporne',
            'Rękawica Kwasoodporna z Narękawnikiem'
        ));
        $this->assertTrue($this->dict()->matchesEvidence(
            'Rękawice kwasoodporne',
            'Rękawice chemiczne do kwasów EN 374'
        ));
        $this->assertFalse($this->dict()->matchesEvidence(
            'Rękawice kwasoodporne',
            'Rękawice montażowe nitrylowe EN 374 do oleju'
        ));
    }

    public function test_literal_catalog_noun_comes_before_slang_and_counts_as_evidence(): void
    {
        $rewrite = $this->dict()->searchRewrite('KALESONY bawełniane męskie');
        $this->assertNotNull($rewrite);
        $this->assertSame('kalesony', $rewrite['search_phrases'][0] ?? null);
        $this->assertStringContainsString('bielizna', mb_strtolower(implode(' ', $rewrite['search_phrases'])));
        $this->assertTrue($this->dict()->matchesEvidence(
            'KALESONY bawełniane męskie',
            'DŁUGIE KALESONY Z POLIAMIDU'
        ));
    }

    public function test_generic_assortment_noun_is_not_required_on_the_card(): void
    {
        // Karta zgodnego asortymentu bywa opisana bez rzeczownika rodzaju — o tym,
        // że to spodnie, mówi rodzina i kategoria, a nie słowo w nazwie.
        $this->assertTrue($this->dict()->matchesEvidence(
            'spodnie robocze do magazynu',
            'URG-A Urgent Odzież robocza Model z karczkiem i kieszeniami cargo'
        ));
    }

    public function test_jargon_noun_stays_an_evidence_needle(): void
    {
        // „kalesony” to nazwa konkretnego wyrobu — zostaje dowodem. „spodnie” to
        // rodzaj, więc z dowodów wypada; inaczej cała rodzina musiałaby mieć to
        // słowo w tekście karty.
        $this->assertContains('kales', $this->dict()->evidenceNeedles('KALESONY bawełniane męskie'));
        $this->assertNotContains('spodn', $this->dict()->evidenceNeedles('spodnie robocze do magazynu'));
    }

    public function test_slang_is_appended_not_replacing_query(): void
    {
        $appendix = $this->dict()->queryAppendix('Rękawice wampirki uniwersalne');
        $this->assertStringContainsString('dodatek do wymagania', mb_strtolower($appendix));
        $this->assertStringContainsString('wampirki', mb_strtolower($appendix));
        $this->assertStringContainsString('powlekan', mb_strtolower($appendix));
    }

    public function test_wampirki_rewrite_uses_note_and_catalog_phrases(): void
    {
        $rewrite = $this->dict()->searchRewrite('Rękawice wampirki uniwersalne');
        $this->assertNotNull($rewrite);
        $this->assertStringContainsString('rękawice dzianinowe powlekane', mb_strtolower($rewrite['needed']));
        $this->assertStringContainsString('ciecz', mb_strtolower($rewrite['needed']));
        $hay = mb_strtolower(implode(' ', $rewrite['search_phrases']));
        $this->assertStringContainsString('rękawice dzianinowe powlekane', $hay);
        $this->assertStringNotContainsString('proste', $hay);
        $this->assertSame('gloves', $rewrite['family']);
        $needles = $this->dict()->evidenceNeedles('Rękawice wampirki uniwersalne');
        $this->assertNotSame([], $needles);
        $this->assertFalse(in_array('uniwer', $needles, true));
        $this->assertFalse(in_array('wampi', $needles, true));
        $flat = array_merge(...$this->dict()->evidenceGroups('Rękawice wampirki uniwersalne'));
        $this->assertFalse(in_array('ciecz', $flat, true));
    }

    public function test_wampir_prefix_maps_like_wampirki(): void
    {
        $this->assertNotSame([], $this->dict()->phrasesFor('wampir'));
        $this->assertSame(
            $this->dict()->searchRewrite('wampirki')['needed'] ?? null,
            $this->dict()->searchRewrite('wampir')['needed'] ?? null,
        );
    }

    public function test_liquid_jargon_rejects_esd_and_fingertip_gloves(): void
    {
        $q = 'Rękawice wampirki uniwersalne';
        $this->assertTrue($this->dict()->rejectsProduct(
            $q,
            'RĘKAWICE ANTYSTATYCZNE WĘGLOWE nakrapiane PCV oraz PALCE POWLEKANE POLIURETANEM'
        ));
        $this->assertTrue($this->dict()->rejectsProduct(
            $q,
            'THEMIS VV792 ESD RĘKAWICE DZIANE Z POLIAMIDU I MIEDZI, KOŃCE PALCÓW POWLEKANE'
        ));
        $this->assertFalse($this->dict()->rejectsProduct(
            $q,
            '1016 (NOWO) Rękawice dziane powlekane do oleju, ochrona przed cieczą.'
        ));
        $this->assertTrue($this->dict()->rejectsProduct(
            $q,
            'RĘKAWICE Z GRUBEGO NITRYLU NA WKŁADZIE Z DŻERSEJU, POWLEKANE W CAŁOŚCI'
        ));
        $this->assertTrue($this->dict()->rejectsProduct(
            $q,
            'Całkowicie powlekane rękawice z długim mankietem'
        ));
        $this->assertTrue($this->dict()->rejectsProduct(
            $q,
            'RĘKAWICE Z PARA-ARAMIDU, WŁÓKNA SZKLANEGO I MODAKRYLU, POWLEKANE PIANKĄ NEOPRENOWĄ ARC FLASH'
        ));
        $this->assertFalse($this->dict()->rejectsProduct(
            $q,
            'OPAKOWANIE 10 PAR RĘKAWIC DZIANYCH Z POLIESTRU, DŁOŃ POWLEKANA NITRYLEM'
        ));
        $this->assertTrue($this->dict()->rejectsProduct(
            $q,
            'Rękawice nitrylowe nieflokowane'
        ));
        $this->assertTrue($this->dict()->rejectsProduct(
            $q,
            'PRIMACUFF35PO 35CM CUT-RESISTANT KNITTED CUFFS'
        ));
        $this->assertTrue($this->dict()->rejectsProduct(
            $q,
            'T6 COLD GLOVES 0 C POLYPRO BLUE'
        ));
    }

    public function test_pcv_maps_to_pvc_material_not_coating(): void
    {
        $rewrite = $this->dict()->searchRewrite('Rękawice PCV długie do łokci');
        $this->assertNotNull($rewrite);
        $hay = mb_strtolower(implode(' ', $rewrite['search_phrases']));
        $this->assertTrue(str_contains($hay, 'pvc') || str_contains($hay, 'pcv'));
        $this->assertStringNotContainsString('powlekan', $hay);
        $this->assertTrue($this->dict()->isIndexedTerm('pcv'));
        $this->assertEqualsCanonicalizing(['pcv', 'pvc'], $this->dict()->searchAliases('pcv'));
    }

    public function test_nitrile_material_rejects_knit_palm_coat_but_keeps_disposable(): void
    {
        $q = 'Rękawice nitrylowe lekkie';
        $this->assertTrue($this->dict()->isNitrileMaterialQuery($q));
        $this->assertTrue($this->dict()->rejectsProduct(
            $q,
            'R840 Dziane rękawice przeznaczone do prac lekkich z powlekaną nitrylem dłonią'
        ));
        $this->assertFalse($this->dict()->rejectsProduct(
            $q,
            '93-843 Niebieskie bezpudrowe rękawice nitrylowe. Jednorazowe rękawice nitrylowe.'
        ));
        $this->assertFalse($this->dict()->isNitrileMaterialQuery('Rękawice pianki nitrylowe'));
        $this->assertFalse($this->dict()->rejectsProduct(
            'Rękawice wampirki uniwersalne',
            'OPAKOWANIE 10 PAR RĘKAWIC DZIANYCH Z POLIESTRU, DŁOŃ POWLEKANA NITRYLEM'
        ));
    }

    public function test_every_slang_term_is_indexed_as_jargon(): void
    {
        $this->assertTrue($this->dict()->isJargonNorm('wampirki'));
        $this->assertTrue($this->dict()->isJargonNorm('tyvek'));
    }

    public function test_maska_3s_rewrites_to_full_face_not_half_mask(): void
    {
        $rewrite = $this->dict()->searchRewrite('Maska 3S BASIS PLUS MSA');
        $this->assertNotNull($rewrite);
        $this->assertSame('maska 3S', $rewrite['needed']);
        $hay = mb_strtolower(implode(' ', $rewrite['search_phrases']));
        $this->assertStringContainsString('3s', $hay);
        $this->assertStringContainsString('pełnotwarz', $hay);
        $this->assertTrue($this->dict()->matchesEvidence(
            'Maska 3S BASIS PLUS MSA',
            'Maska 3S MSA część twarzowa'
        ));
    }

    public function test_defaults_cover_all_categories(): void
    {
        $seen = [];
        foreach (CatalogSlangDictionary::defaults() as $row) {
            $seen[$row['category']] = true;
        }

        $this->assertArrayHasKey('rece', $seen);
        $this->assertArrayHasKey('stopy', $seen);
        $this->assertArrayHasKey('odziez', $seen);
        $this->assertGreaterThan(80, count(CatalogSlangDictionary::defaults()));
    }

    public function test_ciop_hazard_phrases_expand_to_catalog_classes(): void
    {
        $dict = $this->dict();

        $mud = mb_strtolower(implode(' ', $dict->phrasesFor('Trzewiki do pracy w błocie')));
        $this->assertStringContainsString('s3', $mud);
        $this->assertTrue($dict->matchesEvidence('Trzewiki do pracy w błocie', 'Trzewiki S3 SRC'));

        $s5 = mb_strtolower(implode(' ', $dict->phrasesFor('Kalosze z podnoskiem')));
        $this->assertStringContainsString('s5', $s5);

        $spray = mb_strtolower(implode(' ', $dict->phrasesFor('Kombinezon do oprysków')));
        $this->assertTrue(str_contains($spray, 'typ 4') || str_contains($spray, 'typ 6'));

        $cut = mb_strtolower(implode(' ', $dict->phrasesFor('Rękawice z trójką na przecięcie')));
        $this->assertStringContainsString('antyprzecięc', $cut);

        $tig = mb_strtolower(implode(' ', $dict->phrasesFor('Rękawice do spawania TIG')));
        $this->assertStringContainsString('tig', $tig);
        $this->assertStringNotContainsString('odziez', $tig);

        $hro = mb_strtolower(implode(' ', $dict->phrasesFor('Trzewiki HRO')));
        $this->assertStringContainsString('hro', $hro);

        $abek = mb_strtolower(implode(' ', $dict->phrasesFor('Pochłaniacz wielogazowy ABEK')));
        $this->assertTrue(str_contains($abek, 'abek') || str_contains($abek, 'a2b2e2k2'));
    }

    /**
     * Przetarg 1 poz. 1 (3/3 przebiegi puste): Ansell HyFlex 11-202 ma angielski opis („arm protector”, „cut-resistant
     * sleeve”) bez słowa „rękaw” — dowód żargonu „narękawniki” odrzucał kartę, którą bramka rodziny już rozpoznała jako rękaw.
     */
    public function test_narekawniki_evidence_accepts_english_arm_protector(): void
    {
        $q = 'Ochraniacz przedramienia (rękaw) chroniący przed przecięciem, długość ok. 475 mm (19\'\'), regulowane zapięcie na rzep.';
        $this->assertNotNull($this->dict()->searchRewrite($q), 'zapytanie trafia w hasło „narękawniki / rękawy”');

        $this->assertTrue($this->dict()->matchesEvidence(
            $q,
            'HyFlex 11202 SIZE 19\'\'/47,5 cm 11202000 The new HyFlex® 11-202 HI-VIZ™ arm protector offers optimum wearing comfort. '
            .'Ansell HyFlex 11-202 Hi-Vis Cut-Resistant Sleeve with Velcro Fixing System.'
        ));
        $this->assertTrue($this->dict()->matchesEvidence($q, 'Rękaw antyprzecięciowy HPPE 45 cm'));
        $this->assertFalse($this->dict()->matchesEvidence(
            $q,
            'HyFlex 11724 11724110 Gloves HPPE with PU palm coating, seamless knit, cut level B'
        ), 'angielska rękawica bez słowa o rękawie nie jest dowodem');
    }

    public function test_antyprzecieciowe_evidence_accepts_xtremcut_fiber(): void
    {
        $q = 'Rękawice antyprzecięciowe powlekane nitrylem do prac montażowych';

        $this->assertTrue($this->dict()->matchesEvidence(
            $q,
            'RĘKAWICE DZIANE Z WŁÓKNA XTREMCUT, DŁON POWLEKANA PIANKĄ NITRYLOWĄ'
        ));
        $this->assertFalse($this->dict()->matchesEvidence(
            $q,
            'Rękawice dziane, dłoń powlekana pianką nitrylową'
        ));
    }

    public function test_normalize_honours_jargon_flag_from_config(): void
    {
        $rows = CatalogSlangDictionary::normalize([
            ['category' => 'stopy', 'terms' => ['S5'], 'phrases' => ['obuwie ochronne'], 'jargon' => false],
            ['category' => 'rece', 'terms' => ['wampirki'], 'phrases' => ['rękawice powlekane'], 'jargon' => true],
            // Wpis admina bez flagi — żargon (tak jak zapisywał go stary panel).
            ['category' => 'rece', 'terms' => ['gumówki'], 'phrases' => ['rękawice gumowe']],
        ]);

        $this->assertFalse($rows[0]['jargon']);
        $this->assertTrue($rows[1]['jargon']);
        $this->assertTrue($rows[2]['jargon']);
        // Domyślny słownik: klasa obuwia i cecha techniczna nie są żargonem, wampirki są.
        $dict = $this->dict();
        $this->assertFalse($dict->isJargonNorm('antyprzebiciowe'));
        $this->assertFalse($dict->isJargonNorm('chemiczne'));
        $this->assertTrue($dict->isJargonNorm('wampirki'));
    }

    public function test_polmaska_is_a_family_noun_not_jargon(): void
    {
        // Wpis „półmaska → półmaska wielorazowa” usunięty: „półmaska” to rodzina wyrobu,
        // a FFP1 nie może dostawać frazy „wielorazowa”.
        $this->assertFalse($this->dict()->isJargonNorm('polmaska'));
        $this->assertFalse($this->dict()->isJargonNorm('półmaska'));
    }

    public function test_ffp_query_does_not_get_reusable_phrase(): void
    {
        $rewrite = $this->dict()->searchRewrite('Półmaska filtrująca FFP1');
        $this->assertNotNull($rewrite);
        $hay = mb_strtolower(implode(' ', $rewrite['search_phrases']));
        $this->assertStringNotContainsString('wielorazow', $hay);
        $this->assertStringContainsString('ffp', $hay);

        $valve = $this->dict()->searchRewrite('Półmaska filtrująca FFP2 z zaworem');
        $this->assertNotNull($valve);
        $this->assertStringContainsString('półmaska z zaworem', mb_strtolower(implode(' ', $valve['search_phrases'])));
        $this->assertStringNotContainsString('wielorazow', mb_strtolower(implode(' ', $valve['search_phrases'])));
    }

    public function test_szelki_in_rain_trousers_are_not_harness(): void
    {
        $waders = 'Spodniobuty wodoochronne z wgrzanymi na stałe kaloszami – obuwie bezpieczne typu S5 SRC '
            .'wg EN ISO 20345, z wkładką antyprzebiciową. Wymagane: tkanina na podkładzie poliestrowym, '
            .'jednostronnie powlekana PVC, o zwiększonej widzialności (kolor fluorescencyjny); '
            .'wymienne szelki z szerokiej elastycznej gumy; materiał odporny na zginanie bez pękania '
            .'w temperaturze do -50°C. Rozmiary: 39–48.';
        $rewrite = $this->dict()->searchRewrite($waders);
        $this->assertNotNull($rewrite);
        $this->assertSame('apparel', $rewrite['family']);
        $hay = mb_strtolower($rewrite['needed'].' '.implode(' ', $rewrite['search_phrases']));
        $this->assertStringNotContainsString('szelki bezpieczeństwa', $hay);
        $this->assertStringNotContainsString('uprząż', $hay);

        // Regresja: prawdziwe szelki asekuracyjne dalej idą w asekurację.
        $harness = $this->dict()->searchRewrite('Szelki bezpieczeństwa z linką');
        $this->assertNotNull($harness);
        $this->assertSame('fall', $harness['family']);
        $this->assertStringContainsString('szelki bezpieczeństwa', mb_strtolower($harness['needed']));
    }

    public function test_context_comes_from_requirement_head_not_from_use_cases(): void
    {
        // „magazyny chemiczne” na końcu opisu nie może tłumić wpisu „płukanka” (pierwsza pomoc).
        $eyewash = 'Płukanka do oczu – sterylny, izotoniczny roztwór soli fizjologicznej bez konserwantów. '
            .'Zastosowanie: laboratoria, magazyny chemiczne, zakłady przemysłowe.';
        $rewrite = $this->dict()->searchRewrite($eyewash);
        $this->assertNotNull($rewrite);
        $this->assertStringContainsString('płukan', mb_strtolower($rewrite['needed']));

        // „przemysł chemiczny” w zastosowaniach pochłaniacza nie dokłada „kombinezon chemiczny”.
        $filter = 'Pochłaniacz gazów i par klasy A2 – element oczyszczający do sprzętu ochrony układu oddechowego. '
            .'Zastosowanie: przemysł chemiczny, lakierniczy, prace z rozpuszczalnikami organicznymi.';
        $phrases = mb_strtolower(implode(' ', $this->dict()->phrasesFor($filter)));
        $this->assertStringContainsString('pochłaniacz', $phrases);
        $this->assertStringNotContainsString('kombinezon', $phrases);
    }

    public function test_glove_rejections_do_not_apply_to_other_families(): void
    {
        // „płyn do płukania oczu” zawiera „płyn” — reguła „ochrona przed cieczą” (rękawice
        // jednorazowe odpadają) nie może odrzucać płukanki „do jednorazowego płukania oczu”.
        $eyewash = 'Płukanka do oczu – sterylny, izotoniczny roztwór soli fizjologicznej bez konserwantów, '
            .'do jednorazowego płukania oczu w razie zaprószenia. Zastosowanie: laboratoria, magazyny chemiczne.';
        $card = 'Płukanka do oczu Cederroth Eye Wash 7251 Płukanie oczu Płukanka do oczu Cederroth Eye Wash (SKU 7251) '
            .'to sterylny, izotoniczny roztwór soli fizjologicznej bez konserwantów, przeznaczony do jednorazowego płukania oczu.';
        $this->assertFalse($this->dict()->rejectsProduct($eyewash, $card));
        $this->assertTrue($this->dict()->matchesEvidence($eyewash, $card));
        // Dla rękawic reguła działa jak dotąd.
        $this->assertTrue($this->dict()->rejectsProduct('Rękawice wampirki uniwersalne', 'Rękawice nitrylowe jednorazowe bezpudrowe'));
    }

    public function test_term_root_matching_ignores_unrelated_longer_words(): void
    {
        $dict = $this->dict();
        // „montaż” to nie „montażowe”, „mankiet zawinięty” to nie „mankietówki”.
        $sleeve = 'Ochraniacz przedramienia (rękaw) chroniący przed przecięciem. Zastosowanie: montaż i naprawa elementów karoserii.';
        $this->assertStringNotContainsString('montażow', mb_strtolower(implode(' ', $dict->phrasesFor($sleeve))));
        $latex = 'Rękawice ochronne z lateksu naturalnego, flokowane; mankiet zawinięty (rolowany) ułatwiający zakładanie.';
        $this->assertStringNotContainsString('mankiet', mb_strtolower(implode(' ', $dict->phrasesFor($latex))));
        // Odmiana terminu dalej trafia (pinowane: wampir → wampirki).
        $this->assertNotSame([], $dict->phrasesFor('wampir'));
        $this->assertNotSame([], $dict->phrasesFor('Trzewik ocieplany S3'));
    }

    public function test_electro_insulating_is_not_proven_by_electrostatic(): void
    {
        $q = 'Półbuty elektroizolacyjne do prac przy urządzeniach i instalacjach elektroenergetycznych o napięciu '
            .'przemiennym do 17 kV, przeznaczone do nakładania na inne obuwie robocze. Wymagane: klasa 2 AC zgodnie '
            .'z normą EN 50321-1; zgodność z normą EN 20347:2012 dla obuwia zawodowego kategorii OB; odporność na poślizg SRA.';
        $ob = 'ART 702 Air 6660 OB A E FO Obuwie robocze ART 702 Air 6660 OB A E FO to lekkie buty przeznaczone do pracy '
            .'w środowiskach wymagających ochrony antypoślizgowej oraz kontroli ładunków elektrostatycznych. '
            .'Spełnia normę EN ISO 20347:2012 w klasie OB A E FO SRC oraz wymagania ESD zgodnie z EN IEC 61340-4-3:2018.';
        $insulating = 'Półbuty elektroizolacyjne 20 kV - ANTYAMPER T5912100 11.1 OBUWIE ELEKTROIZOLACYJNE Półbuty '
            .'elektroizolacyjne ANTYAMPER 20 kV marki SECURA to specjalistyczne obuwie ochronne przeznaczone do pracy '
            .'przy urządzeniach i instalacjach elektroenergetycznych o napięciu przemiennym do 17 kV.';

        $this->assertFalse($this->dict()->matchesEvidence($q, $ob));
        $this->assertTrue($this->dict()->matchesEvidence($q, $insulating));
        // Długa igła zostaje rdzeniem, nie 5 znakami: „elekt” potwierdzałoby „elektrostatyczne”.
        $needles = $this->dict()->evidenceNeedles($q);
        $this->assertContains('elektroizolac', $needles);
        $this->assertNotContains('elekt', $needles);
        // Warunek jako osobna grupa AND obok rzeczownika.
        $this->assertContains(['elektroizolac'], $this->dict()->evidenceGroups($q));
    }

    public function test_condition_needles_come_only_from_words_the_requirement_uses(): void
    {
        // „obuwie bezpieczne” → słownik: „obuwie z podnoskiem”; wymóg „podnosek” jest w SIWZ, więc
        // zostaje warunkiem, a igła to rdzeń łapiący „podnosek” i „podnoskiem”.
        $sandals = 'Sandały ochronne (obuwie bezpieczne z odkrytą cholewką) kategorii S1 P wg EN ISO 20345. '
            .'Wymagane: zabudowana pięta; podnosek ochronny; właściwości antyelektrostatyczne (ESD).';
        $this->assertContains(['podnos'], $this->dict()->evidenceGroups($sandals));
        $this->assertTrue($this->dict()->matchesEvidence($sandals, 'Sandały robocze ARMEN S1 P z podnoskiem kompozytowym, ESD'));
        $this->assertTrue($this->dict()->matchesEvidence($sandals, 'Sandały robocze ARMEN S1 P: podnosek kompozytowy, antystatyczne'));

        // Komplet: bluzę potwierdza „trudnopalna”, choć słownik dołożył „ogrodniczki”/„szelki”.
        $set = "Ubranie antyelektrostatyczne, trudnopalne (bluza + spodnie do pasa lub ogrodniczki)\n· EN ISO 11611 kl. 2\nEN 1149-5";
        $this->assertTrue($this->dict()->matchesEvidence($set, 'Bluza KOLPEO BASIC ZIPPER Bluza trudnopalna. EN ISO 11611, EN 1149-5'));
        $this->assertFalse($this->dict()->matchesEvidence($set, 'FR740 FR EN ISO 11611:2015, EN 1149-5:2018'));
    }

    public function test_short_needles_stay_five_characters(): void
    {
        // „kalesony” (8 znaków) — pinowane 5 znaków; skrócenie długich igieł nie dotyka krótkich.
        $needles = $this->dict()->evidenceNeedles('KALESONY bawełniane męskie');
        $this->assertContains('kales', $needles);
        foreach ($needles as $needle) {
            $this->assertGreaterThanOrEqual(5, mb_strlen($needle));
        }
    }

    public function test_requirement_head_is_first_sentence_without_abbreviation_dots(): void
    {
        $this->assertSame(
            'Ochraniacz przedramienia (rękaw) chroniący przed przecięciem',
            CatalogSlangDictionary::requirementHead('Ochraniacz przedramienia (rękaw) chroniący przed przecięciem, długość ok. 475 mm (19\'\'), w kolorze żółtym. Konstrukcja bezszwowa.')
        );
        $this->assertSame('Płukanka do oczu', CatalogSlangDictionary::requirementHead('Płukanka do oczu – sterylny roztwór soli. Wymagane: butelka 500 ml.'));
        $this->assertSame('Rękawice nitrylowe RTELA', CatalogSlangDictionary::requirementHead('Rękawice nitrylowe RTELA'));
        $this->assertSame(
            'Apteczka ścienna pierwszej pomocy (panel) w wersji mini',
            CatalogSlangDictionary::requirementHead('Apteczka ścienna pierwszej pomocy (panel) w wersji mini, do mniejszych pomieszczeń (np. biura) lub jako uzupełnienie innych zestawów pierwszej pomocy. Wymagane: konstrukcja otwarta.')
        );
    }

    private function dict(): CatalogSlangDictionary
    {
        return $this->app->make(CatalogSlangDictionary::class);
    }
}
