<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CatalogSlangDictionary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogSlangDictionaryTest extends TestCase
{
    use RefreshDatabase;
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

    private function dict(): CatalogSlangDictionary
    {
        return $this->app->make(CatalogSlangDictionary::class);
    }
}
