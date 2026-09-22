<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Enrichment\SourceClaimGuard;
use PHPUnit\Framework\TestCase;

/**
 * Twierdzenia opisu z karty katalogowej B2B wobec źródeł. Teksty PDF — jak w kartach ARTRY z produkcji 22.09.2026
 * (9495, 9524), skrócone.
 */
final class SourceClaimGuardTest extends TestCase
{
    private const ARCASIO_PDF = "KARTA PRODUKTU\nARCASIO 732 616560 S1 P ESD\nNorma: EN ISO 20345:2011 S1 P SRC\n"
        ."ESD według EN IEC 61340-4-3:2018\nPodnosek: stalowy\nWkładka: FLEXYUM\nPodeszwa: RAPTOR PU.2D\nRozmiar: 36-48";

    private const ARMEN_PDF = "KARTA PRODUKTU\nARMEN 9003 2360 S1\nsandały bezpieczne Metal free\nNorma: EN ISO 20345:2022 S1 FO SR\n"
        ."Podnosek: kompozytowy\nRozmiar: 36-48";

    public function test_water_resistance_is_not_covered_by_s1_p(): void
    {
        $guard = new SourceClaimGuard(self::ARCASIO_PDF);

        $this->assertSame(['wodoodporność'], $guard->uncoveredClaims('Cholewka wodoodporna chroni przed przenikaniem płynów.'));
        $this->assertSame(['wodoodporność'], $guard->uncoveredClaims('Odporność na przenikanie płynów.'));
    }

    public function test_class_markings_cover_what_the_class_contains(): void
    {
        $guard = new SourceClaimGuard(self::ARCASIO_PDF);

        // S1 = antystatyczność, P = wkładka antyprzebiciowa, SRC = antypoślizg, 2011 S1 = olej
        $this->assertSame([], $guard->uncoveredClaims('Obuwie antystatyczne z wkładką antyprzebiciową.'));
        $this->assertSame([], $guard->uncoveredClaims('Podeszwa odporna na poślizg i oleje.'));
        $this->assertSame([], $guard->uncoveredClaims('Właściwości ESD.'));
        $this->assertSame([], (new SourceClaimGuard('EN ISO 20345:2011 S3 SRC'))->uncoveredClaims('Wodoodporna cholewka.'));
        $this->assertSame([], (new SourceClaimGuard('EN ISO 20345:2022 S1PL FO SR'))->uncoveredClaims('Wkładka antyprzebiciowa.'));
    }

    /** Przegląd 22.09.2026: wodoodporność innymi słowami, „bez przemakania” to twierdzenie, nie przeczenie. */
    public function test_water_resistance_in_other_words_and_hazard_after_negation_is_a_claim(): void
    {
        $guard = new SourceClaimGuard(self::ARCASIO_PDF);

        foreach (['Cholewka nie przemaka.', 'Materiał nie przepuszcza wody.', 'Cholewka odporna na wodę.',
            'Chroni stopy bez ryzyka przemakania.', 'Wygoda bez przemakania butów.', 'Powłoka wodoodpychająca.'] as $sentence) {
            $this->assertSame(['wodoodporność'], $guard->uncoveredClaims($sentence), $sentence);
        }
        $this->assertSame(['elektroizolacja'], $guard->uncoveredClaims('Praca bez ryzyka porażenia.'));
        // prawdziwe przeczenie zostaje przeczeniem
        $this->assertSame([], $guard->uncoveredClaims('Obuwie nie jest wodoodporne.'));
        $this->assertSame([], (new SourceClaimGuard('EN ISO 20345:2011 S1 SRC'))->uncoveredClaims('Obuwie nie jest odporne na przebicie.'));
    }

    /** „kolejny” to nie olej, „wyspawany” to nie spawanie; „zaolejone” dalej jest olejem. */
    public function test_word_start_for_oil_and_welding(): void
    {
        $guard = new SourceClaimGuard('EN 388:2016 4131X');

        $this->assertSame([], $guard->uncoveredClaims('Kolejną zaletą jest wygodny ściągacz przy kolejnych czynnościach.'));
        $this->assertSame([], $guard->uncoveredClaims('Mankiet wyspawany z tworzywa.'));
        $this->assertSame(['odporność na oleje'], $guard->uncoveredClaims('Pewny chwyt na zaolejonych powierzchniach.'));
        $this->assertSame([], (new SourceClaimGuard('Chwyt na powierzchniach zaolejonych'))->uncoveredClaims('Chwyt na zaolejonych częściach.'));
        $this->assertSame(['odporność na oleje'], (new SourceClaimGuard('kolejny model serii'))->uncoveredClaims('Podeszwa olejoodporna.'));
    }

    public function test_virus_part_of_en_374_is_not_chemical_resistance(): void
    {
        $this->assertSame(['odporność chemiczna'], (new SourceClaimGuard('EN ISO 374-5:2016 VIRUS'))->uncoveredClaims('Chronią przed chemikaliami.'));
        $this->assertSame([], (new SourceClaimGuard('EN ISO 374-1:2016/Typ B'))->uncoveredClaims('Chronią przed chemikaliami.'));
    }

    public function test_heading_line_in_the_description_stays(): void
    {
        $filtered = (new SourceClaimGuard(self::ARCASIO_PDF))->filterDescription("Półbuty ARCASIO 732 ze stalowym podnoskiem.\n\nNajważniejsze cechy:\nPodeszwa RAPTOR PU.2D.");

        $this->assertStringContainsString('Najważniejsze cechy:', $filtered['text']);
        $this->assertSame([], $filtered['dropped']);
        $this->assertTrue(SourceClaimGuard::statesMissingData('Typ zapięcia:'));
    }

    public function test_oil_resistance_needs_fo_or_the_2011_edition(): void
    {
        $this->assertSame(['odporność na oleje'], (new SourceClaimGuard('EN ISO 20345:2022 S1 SR'))->uncoveredClaims('Podeszwa olejoodporna.'));
        $this->assertSame([], (new SourceClaimGuard('EN ISO 20345:2022 S1 FO SR'))->uncoveredClaims('Podeszwa olejoodporna.'));
        $this->assertSame([], (new SourceClaimGuard('EN ISO 20345:2011 S1 SRC'))->uncoveredClaims('Podeszwa olejoodporna.'));
    }

    public function test_antistatic_is_not_covered_by_sb(): void
    {
        $this->assertSame(['antystatyczność'], (new SourceClaimGuard('EN ISO 20345:2011 SB SRA'))->uncoveredClaims('Obuwie antystatyczne.'));
        $this->assertSame([], (new SourceClaimGuard('EN ISO 20345:2011 SB A SRA'))->uncoveredClaims('Obuwie antystatyczne.'));
    }

    public function test_heat_explanations_and_test_details_are_uncovered_for_armen(): void
    {
        $guard = new SourceClaimGuard(self::ARMEN_PDF);

        $this->assertSame(['ochrona przed ciepłem'], $guard->uncoveredClaims('Przeznaczenie: praca w wysokich temperaturach.'));
        $this->assertSame(
            ['szczegóły badań (200 j)', 'szczegóły badań (15 kn)'],
            $guard->uncoveredClaims('Klasa S1 gwarantuje ochronę palców przy uderzeniu 200 J i zgnieceniu 15 kN.'),
        );
        $this->assertNotSame([], $guard->uncoveredClaims('Oznaczenie SR potwierdza badanie na podłożu z laurylosiarczanem sodu oraz gliceryną.'));
        // metal free i FO są w źródle
        $this->assertSame([], $guard->uncoveredClaims('Sandały bez elementów metalowych, podeszwa odporna na oleje.'));
    }

    public function test_test_details_present_in_the_sources_stay(): void
    {
        $guard = new SourceClaimGuard('Podnosek wytrzymuje uderzenie 200 J. Badanie na płytce ceramicznej.');

        $this->assertSame([], $guard->uncoveredClaims('Podnosek chroni przy uderzeniu o energii 200J, badanie na płytce ceramiczna.'));
    }

    public function test_negations_are_not_claims(): void
    {
        $guard = new SourceClaimGuard('EN ISO 20347:2012 O1 FO SRC. Obuwie zawodowe bez podnoska.');

        $this->assertSame([], $guard->uncoveredClaims('Półbuty zawodowe bez podnoska i bez wkładki antyprzebiciowej.'));
        $this->assertSame([], $guard->uncoveredClaims('Obuwie nie jest wodoodporne.'));
        $this->assertSame([], $guard->uncoveredClaims('Wkładka antyprzebiciowa: brak'));
        $this->assertSame(['wodoodporność'], $guard->uncoveredClaims('Obuwie nie tylko wygodne, ale i wodoodporne.'));
    }

    public function test_negation_of_another_feature_does_not_hide_a_claim(): void
    {
        $guard = new SourceClaimGuard('EN ISO 20347:2012 O1 FO SRC');

        $this->assertSame(['wodoodporność'], $guard->uncoveredClaims('Obuwie bez podnoska, z wodoodporną cholewką.'));
        $this->assertSame(['wodoodporność'], $guard->uncoveredClaims('Obuwie bez podnoska i z wodoodporną cholewką.'));
    }

    public function test_storage_temperature_does_not_cover_heat_protection(): void
    {
        $guard = new SourceClaimGuard('Przechowywać w temperaturze pokojowej. EN 388:2016');

        $this->assertSame(['ochrona przed ciepłem'], $guard->uncoveredClaims('Do pracy w wysokich temperaturach.'));
        $this->assertSame([], (new SourceClaimGuard('temperatury przekraczającej 50°C'))->uncoveredClaims('Chroni przed gorącymi przedmiotami.'));
    }

    public function test_negated_source_word_does_not_cover_the_claim(): void
    {
        $guard = new SourceClaimGuard('Obuwie nie jest wodoodporne. EN ISO 20345:2022 S1 SR');

        $this->assertSame(['wodoodporność'], $guard->uncoveredClaims('Cholewka wodoodporna.'));
    }

    public function test_norms_cover_their_properties(): void
    {
        $this->assertSame([], (new SourceClaimGuard('EN 511:2006 (X1X)'))->uncoveredClaims('Chroni przed zimnem.'));
        $this->assertSame([], (new SourceClaimGuard('EN 407:2020 (X1XXXX)'))->uncoveredClaims('Chroni przed kontaktem z gorącymi przedmiotami.'));
        $this->assertSame([], (new SourceClaimGuard('EN ISO 374-1:2016/Type C'))->uncoveredClaims('Chroni przed chemikaliami.'));
        $this->assertSame(['odporność chemiczna'], (new SourceClaimGuard('EN 388:2016'))->uncoveredClaims('Chroni przed kwasami.'));
        $this->assertSame(['spawanie'], (new SourceClaimGuard('EN 388:2016'))->uncoveredClaims('Do prac spawalniczych.'));
        $this->assertSame(['elektroizolacja'], (new SourceClaimGuard('EN 388:2016'))->uncoveredClaims('Rękawica elektroizolacyjna.'));
        $this->assertSame([], (new SourceClaimGuard('EN 60903 klasa 00'))->uncoveredClaims('Rękawica elektroizolacyjna do pracy pod napięciem.'));
    }

    public function test_lowercase_words_are_not_markings(): void
    {
        $guard = new SourceClaimGuard('EN 388:2016');

        // „ci” (zaimek), „hi” — nie oznaczenia CI/HI
        $this->assertSame([], $guard->uncoveredClaims('Rękawica da ci pewny chwyt, hi.'));
        $this->assertSame(['izolacja od zimna'], $guard->uncoveredClaims('Oznaczenie CI.'));
    }

    public function test_description_filter_drops_only_uncovered_sentences(): void
    {
        $guard = new SourceClaimGuard(self::ARCASIO_PDF);

        $result = $guard->filterDescription(
            "Półbuty bezpieczne ARCASIO 732 z podnoskiem stalowym. Odporność na przenikanie płynów dzięki wodoodpornej cholewce.\n\n"
            ."Podeszwa RAPTOR PU.2D odporna na poślizg (kat. SRC). Źródła nie podają typu zapięcia.\n\nObuwie spełnia EN ISO 20345:2011."
        );

        $this->assertSame(
            "Półbuty bezpieczne ARCASIO 732 z podnoskiem stalowym.\n\nPodeszwa RAPTOR PU.2D odporna na poślizg (kat. SRC).\n\nObuwie spełnia EN ISO 20345:2011.",
            $result['text'],
        );
        $this->assertCount(2, $result['dropped']);
        $this->assertStringStartsWith('wodoodporność: Odporność na przenikanie', $result['dropped'][0]);
        $this->assertStringStartsWith('brak danych: Źródła nie podają', $result['dropped'][1]);
    }

    public function test_abbreviation_does_not_split_a_sentence(): void
    {
        $guard = new SourceClaimGuard('Rękawica kat. II. EN 388:2016');

        $result = $guard->filterDescription('Rękawica ochronna kat. II chroni przed zimnem. Wkładka z nylonu.');

        $this->assertSame('Wkładka z nylonu.', $result['text']);
    }

    public function test_list_items(): void
    {
        $guard = new SourceClaimGuard(self::ARMEN_PDF);

        $this->assertFalse($guard->keeps('Typ zapięcia: brak danych w źródle'));
        $this->assertFalse($guard->keeps('Typ zapięcia:'));
        $this->assertFalse($guard->keeps('praca w wysokich temperaturach'));
        $this->assertTrue($guard->keeps('Podnosek: kompozytowy'));
        $this->assertTrue($guard->keeps('Wkładka antyprzebiciowa: brak'));
        $this->assertTrue($guard->keeps('Metal free'));
    }
}
