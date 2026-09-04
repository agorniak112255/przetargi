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
