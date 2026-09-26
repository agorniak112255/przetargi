<?php

declare(strict_types=1);

namespace Tests\Unit\RequirementCheck;

use App\Support\BhpAttributeNormalizer;
use App\Support\RequirementCheck\CardSource;
use App\Support\RequirementCheck\CardSources;
use App\Support\RequirementCheck\CheckRow;
use App\Support\RequirementCheck\LevelChecker;
use App\Support\RequirementCheck\Status;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

final class LevelCheckerTest extends TestCase
{
    /** Karta z bazy produkcyjnej pokazywana przy poz. 1 — ścieranie 1 i kategoria II, reszta jak w wymaganiu. */
    private const PRODUCTION_SLEEVE_NORMS = 'EN 388:2016+A1:2018 (1X42C), EN 407:2020 (X1XXXX), EN ISO 21420:2020, kat. II, EN ISO 13997: odporność na przecięcia poziom C, ANSI/ISEA 105: poziom A3';

    #[Test]
    public function expected_sleeve_meets_line_1_levels(): void
    {
        $rows = $this->rows(Opisowy15Fixture::requirement(1), $this->fixtureCard('11202000'));

        $this->assertSame(['en388' => 'ok', 'en407' => 'ok', 'ppe_category' => 'ok'], $this->statuses($rows));
        $en388 = $rows['en388']->toArray();
        $this->assertSame('cut_level', $en388['gate']);
        $this->assertSame(['ok', 'skip', 'ok', 'ok', 'ok'], array_column($en388['positions'], 'status'), 'X w wymaganiu (coupe) nie wpływa na status');
        $this->assertSame(['norms', '2.X.4.2.C'], [$en388['card'][0]['source'], $en388['card'][0]['text']]);
    }

    #[Test]
    public function production_sleeve_fails_abrasion_and_category_but_meets_contact_heat(): void
    {
        $rows = $this->rows(Opisowy15Fixture::requirement(1), [
            new CardSource(CardSource::NORMS, self::PRODUCTION_SLEEVE_NORMS),
            new CardSource(CardSource::FEATURES, 'Odporność termiczna do 100°C (EN 407 poziom 1)'),
            new CardSource(CardSource::FEATURES, 'Ochrona przed przecięciami (ANSI A3 / EN ISO C)'),
        ]);

        $this->assertSame(['en388' => 'fail', 'en407' => 'ok', 'ppe_category' => 'fail'], $this->statuses($rows));
        $this->assertSame('ścieranie 1 < 2', $rows['en388']->note);
        $this->assertSame('1X42C', $rows['en388']->card[0]['text']);
        $this->assertSame('EN 388:2016+A1:2018 (1X42C), EN 407:2020 (X1XXXX), EN ISO 21420:2020, kat. II, EN ISO 13997: odporność na przecięcia poz…', $rows['en388']->card[0]['quote']);
        $this->assertSame('kat. II', $rows['ppe_category']->card[0]['text']);
        $this->assertCount(1, $rows['en407']->card, '„EN 407 poziom 1” w cechach nie mówi, której pozycji dotyczy — nie jest znaleziskiem');
    }

    #[Test]
    public function x_on_card_where_tender_requires_a_level_is_missing_not_fail(): void
    {
        $rows = $this->rows('EN 388 z poziomami min. 2.X.4.2.C', [new CardSource(CardSource::NORMS, 'EN 388:2016 (2X4XC)')]);

        $this->assertSame(Status::Missing, $rows['en388']->status);
        $this->assertSame('przekłucie: X na karcie (nie badano)', $rows['en388']->note);
    }

    #[Test]
    public function card_without_en388_code_is_missing(): void
    {
        $rows = $this->rows('EN 388 z poziomami min. 2.X.4.2.C', [new CardSource(CardSource::SPECS, 'Normy: EN 420, EN 388, EN 407')]);

        $this->assertSame(Status::Missing, $rows['en388']->status);
        $this->assertSame([], $rows['en388']->card);
        $this->assertSame(['missing', 'skip', 'missing', 'missing', 'missing'], array_column($rows['en388']->positions ?? [], 'status'));
    }

    #[Test]
    public function line_7_worded_requirement_is_met_by_spaced_code_and_worded_description(): void
    {
        $rows = $this->rows(Opisowy15Fixture::requirement(7), $this->fixtureCard('44-304'));

        $this->assertSame(['en388' => 'ok', 'en407' => 'ok'], $this->statuses($rows));
        $this->assertSame('4341B', $rows['en388']->required['text']);
        $this->assertSame('ciepło kontaktowe poziom 1', $rows['en407']->required['text']);
        $this->assertNull($rows['en407']->note, 'specyfikacja „poziom 1” i opis „X1XXXX” podają tę samą pozycję — to nie sprzeczność');
    }

    #[Test]
    public function unspecified_en407_level_is_unclear(): void
    {
        $rows = $this->rows('Rękawice termiczne, EN 407 poziom 1 (do 100°C).', [new CardSource(CardSource::NORMS, 'EN 407:2020 (X1XXXX)')]);

        $this->assertSame(Status::Unclear, $rows['en407']->status);
        $this->assertSame('unclear', $rows['en407']->card[0]['verdict']);
        $this->assertSame([100], $rows['en407']->required['celsius']);
    }

    #[Test]
    public function cut_level_row_for_explicit_letter_without_en388_code(): void
    {
        $card = [
            new CardSource(CardSource::FEATURES, 'Ochrona przed przecięciami (ANSI A3 / EN ISO C)'),
            new CardSource(CardSource::SPECS, 'Odporność na przecięcie wg ISO 13997 - B'),
        ];

        $rows = $this->rows('Rękawice, odporność na przecięcie wg ISO 13997 poziom C.', $card);
        $this->assertSame(['cut_level' => 'unclear'], $this->statuses($rows), 'jedno pole podaje C, drugie B');
        $this->assertSame(['EN ISO C', 'ISO 13997 - B'], array_column($rows['cut_level']->card, 'text'));
        $this->assertSame(['ok', 'fail'], array_column($rows['cut_level']->card, 'verdict'));

        // kod EN 388 z pozycjami porównuje wiersz en388
        $this->assertArrayNotHasKey('cut_level', $this->rows('EN 388 z poziomami min. 2.X.4.2.C', $card));
        // bez rękawic i bez odporności na przecięcie wiersza nie ma
        $this->assertSame([], $this->rows('Rękawice powlekane nitrylem do prac montażowych.', $card));
    }

    /**
     * Decyzja użytkownika 24.09 (uwagi eksperta do przetargu 1, poz. 2): rękawice „odporne na przecięcie” bez poziomu —
     * co najmniej B, jak bramka dopasowania. Wiersz mówi, że poziom jest przyjęty, a nie podany w SIWZ.
     */
    #[Test]
    public function cut_resistant_gloves_without_level_require_at_least_b(): void
    {
        $requirement = 'Rękawice ochronne odporne na przecięcie, do pracy ze szkłem. Oznakowanie zgodnie z EN 388; elastyczność.';

        $ok = $this->rows($requirement, [new CardSource(CardSource::NORMS, 'EN ISO 21420:2020, EN 388:2016+A1:2019 (4544C)')])['cut_level'];
        $this->assertSame(Status::Ok, $ok->status);
        $this->assertSame(['text' => 'min. B (przyjęte)', 'value' => 'B', 'inferred' => true], array_diff_key($ok->required, ['quote' => 1]));
        $this->assertStringContainsString('odporne na przecięcie', $ok->required['quote']);
        $this->assertSame('SIWZ nie podaje poziomu przecięcia — przyjęto minimum B (lekka odporność na przecięcie).', $ok->note);

        $this->assertSame(Status::Fail, $this->rows($requirement, [new CardSource(CardSource::NORMS, 'EN 388: 4X21A')])['cut_level']->status);
    }

    /** RCFB-2369 COVENT FOAM (produkcja): „2131X” — Coup Test 1, ISO nie badano. Przy wymaganym B nie spełnia. */
    #[Test]
    public function card_with_only_coup_1_and_no_iso_letter_fails_required_b(): void
    {
        $requirement = 'Rękawice ochronne odporne na przecięcie, przeznaczone do prac z narzędziami tnącymi.';
        $rcfb = [new CardSource(CardSource::NORMS, 'EN 388:2016+A1:2018 – poziom 2131X, EN ISO 21420:2020')];

        $row = $this->rows($requirement, $rcfb)['cut_level'];
        $this->assertSame(Status::Fail, $row->status);
        $this->assertSame('2131X', $row->card[0]['text']);
        $this->assertStringContainsString('Coup Test 1 bez litery ISO 13997', (string) $row->note);

        // Canis: zapis słowny „przecięcie 1” to też Coup Test
        $canis = [new CardSource(CardSource::DESCRIPTION, 'Spełnia EN 388 (przetarcie 2, przecięcie 1, rozerwanie 3, przekłucie 1).')];
        $this->assertSame(Status::Fail, $this->rows($requirement, $canis)['cut_level']->status);

        // wyższej cyfry Coup Test nie przeliczamy na literę — brak z notką
        $coup3 = $this->rows($requirement, [new CardSource(CardSource::NORMS, 'EN 388:2016 – 4343X')])['cut_level'];
        $this->assertSame(Status::Missing, $coup3->status);
        $this->assertSame([], $coup3->card);
        $this->assertStringContainsString('Coup Test 3 bez litery ISO 13997', (string) $coup3->note);

        // litera na innym polu karty rozstrzyga — Coup Test 1 bez znaczenia
        $withLetter = [...$rcfb, new CardSource(CardSource::SPECS, 'Odporność na przecięcie wg ISO 13997 - B')];
        $this->assertSame(Status::Ok, $this->rows($requirement, $withLetter)['cut_level']->status);
    }

    #[Test]
    public function higher_category_passes_with_note(): void
    {
        $rows = $this->rows('Środek ochrony indywidualnej kategorii II.', [new CardSource(CardSource::SPECS, 'Kategoria: III')]);

        $this->assertSame(Status::Ok, $rows['ppe_category']->status);
        $this->assertSame('Karta: kategoria III, wyższa niż wymagana II.', $rows['ppe_category']->note);
    }

    #[Test]
    public function conjunction_i_is_not_a_category_requirement(): void
    {
        $this->assertArrayNotHasKey('ppe_category', $this->rows('Produkty tej kategorii i wymagania EN 420.', [new CardSource(CardSource::SPECS, 'Kategoria: I')]));
    }

    #[Test]
    public function line_6_glasses_category_and_impact_class_are_met(): void
    {
        $rows = $this->rows(Opisowy15Fixture::requirement(6), $this->fixtureCard('RUSHPTWI'));

        $this->assertSame(['ppe_category' => 'ok', 'impact_class' => 'ok'], $this->statuses($rows));
        $this->assertSame('F', $rows['impact_class']->required['value']);
        $this->assertSame('description', $rows['impact_class']->card[0]['source']);
    }

    #[Test]
    public function lower_impact_class_on_card_fails(): void
    {
        $rows = $this->rows('Gogle, soczewka o odporności na uderzenia do 120 m/s.', [new CardSource(CardSource::NORMS, 'EN 166: FT – odporność na uderzenia przy niskiej energii')]);

        $this->assertSame(Status::Fail, $rows['impact_class']->status);
        $this->assertSame('impact', $rows['impact_class']->gate);
    }

    /** M6 (25.09.2026): zapis ARDON „OM: F” jest klasą i werdykt cytuje go, a nie zastępcze „klasa F”. */
    #[Test]
    public function ardon_om_impact_class_is_cited(): void
    {
        $rows = $this->rows('Gogle, soczewka o odporności na uderzenia do 120 m/s.', [
            new CardSource(CardSource::DESCRIPTION, "norma: EN 175\nOM: F\noznakowanie: 1 F (bezbarwny)"),
        ]);

        $this->assertSame(Status::Fail, $rows['impact_class']->status);
        $this->assertSame('OM: F', $rows['impact_class']->card[0]['text'] ?? null);
    }

    #[Test]
    public function line_3_sandal_name_and_specs_contradict_each_other(): void
    {
        // nazwa „S1 P”, specyfikacja „Klasa ochrony: S1” — nie wiemy, któremu polu wierzyć
        $rows = $this->rows(Opisowy15Fixture::requirement(3), $this->fixtureCard('ARMEN 9007 6660 S1 P'));

        $this->assertSame(['footwear_class' => 'unclear'], $this->statuses($rows));
        $verdicts = [];
        foreach ($rows['footwear_class']->card as $finding) {
            $verdicts[$finding['source'].': '.$finding['text']] = $finding['verdict'];
        }
        $this->assertSame('ok', $verdicts['name: S1 P']);
        $this->assertSame('fail', $verdicts['specs: S1']);
    }

    #[Test]
    public function footwear_class_uses_gate_hierarchy(): void
    {
        $requirement = 'Sandały ochronne kategorii S1 P wg EN ISO 20345.';

        $this->assertSame(Status::Fail, $this->rows($requirement, [new CardSource(CardSource::SPECS, 'Klasa ochrony: S1')])['footwear_class']->status, 'S1 nie spełnia S1P');
        $this->assertSame(Status::Ok, $this->rows($requirement, [new CardSource(CardSource::SPECS, 'Klasa ochrony: S3')])['footwear_class']->status, 'S3 ma wkładkę antyprzebiciową');
        $this->assertSame(Status::Ok, $this->rows($requirement, [new CardSource(CardSource::SPECS, 'klasa_ochrony: S1 PL')])['footwear_class']->status, 'S1 PL to S1P z wkładką typu L, nie S1');
        $this->assertSame(Status::Missing, $this->rows($requirement, [new CardSource(CardSource::SPECS, 'Norma: EN ISO 20345')])['footwear_class']->status);
    }

    #[Test]
    public function line_8_ffp_class_is_compared_without_passing_missing_data(): void
    {
        $rows = $this->rows(Opisowy15Fixture::requirement(8), $this->fixtureCard('9914'));
        $this->assertSame(['ffp' => 'ok'], $this->statuses($rows));

        $requirement = 'Półmaska filtrująca klasy FFP2 z zaworem.';
        $this->assertSame(Status::Fail, $this->rows($requirement, [new CardSource(CardSource::NAME, 'Półmaska 9914, FFP1')])['ffp']->status);
        $this->assertSame(Status::Missing, $this->rows($requirement, [new CardSource(CardSource::FEATURES, 'P1')])['ffp']->status, 'ffpClassMeets przepuściłoby brak klasy jako spełnienie');
    }

    #[Test]
    public function snr_below_required_fails(): void
    {
        $requirement = 'Nauszniki przeciwhałasowe, SNR minimum 30 dB.';

        $this->assertSame(Status::Fail, $this->rows($requirement, [new CardSource(CardSource::SPECS, 'Tłumienie: SNR 28 dB')])['snr']->status);
        $this->assertSame(Status::Ok, $this->rows($requirement, [new CardSource(CardSource::SPECS, 'Tłumienie: SNR 31 dB')])['snr']->status);
    }

    /**
     * Przypadki z recenzji, które dawały fałszywe ok albo fail: karta nie podaje kodu ani litery, więc tylko brak.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function neverOk(): iterable
    {
        yield 'numer artykułu jako kod EN 388' => ['EN 388 min. 2121', 'Spełnia EN 388, nr art. 4543, rozmiary 7-10', 'en388'];
        yield 'telefon jako kod EN 388' => ['EN 388 3121', 'Rękawice zgodne z EN 388. Producent: ul. Polna 1, tel. 4444 12', 'en388'];
        yield 'liczba par jako kod EN 388' => ['EN 388 4X42C', 'Norma EN 388. Karton 1200 par.', 'en388'];
        yield 'liczba sztuk jako kod EN 407' => ['EN 407 X1XXXX', 'EN 407. Opakowanie 1200 szt', 'en407'];
        yield 'rok normy jako kod z literą ISO' => ['odporność na przecięcie ISO 13997 poziom C', 'EN 388:2015 D', 'cut_level'];
    }

    #[Test]
    #[DataProvider('neverOk')]
    public function digits_that_are_not_a_code_never_pass(string $requirement, string $card, string $key): void
    {
        $row = $this->rows($requirement, [new CardSource(CardSource::DESCRIPTION, $card)])[$key];

        $this->assertSame(Status::Missing, $row->status);
        $this->assertSame([], $row->card);
    }

    #[Test]
    public function lowercase_en388_code_is_read(): void
    {
        $card = [new CardSource(CardSource::NORMS, 'EN 388: 4x43d')];

        $this->assertSame(Status::Ok, $this->rows('EN 388 4X43C', $card)['en388']->status);
        $cut = $this->rows('Odporność na przecięcie ISO 13997 poziom C.', $card)['cut_level'];
        $this->assertSame(Status::Ok, $cut->status);
        $this->assertSame('4x43d', $cut->card[0]['text']);
    }

    #[Test]
    public function en388_code_of_other_edition_is_shown_but_does_not_meet_requirement_with_edition(): void
    {
        // 4542 z 2003 spełniałoby każdą cyfrę 3121X, ale to inne wydanie — Coup Test nie jest literą ISO, nie przeliczamy
        $row = $this->rows('Rękawice EN 388:2016 min. 3121X', [new CardSource(CardSource::NORMS, 'EN 388:2003 (4542)')])['en388'];

        $this->assertSame(Status::Missing, $row->status);
        $this->assertSame('karta podaje tylko EN 388:2003 4542 — inne wydanie normy', $row->note);
        $this->assertCount(1, $row->card);
        $this->assertSame(['4542', 'unclear', '4542-', '2003'], [$row->card[0]['text'], $row->card[0]['verdict'], $row->card[0]['code'], $row->card[0]['edition']]);
        $this->assertSame(['missing', 'missing', 'missing', 'missing', 'skip'], array_column($row->positions ?? [], 'status'), 'pozycje bez wartości z innego wydania');
    }

    #[Test]
    public function uvex_c500_is_judged_by_code_of_required_edition_only(): void
    {
        // UVEX C500: oba wydania w jednym polu, 2003 pierwsze — wiersz ocenia tylko kod 2016
        $row = $this->rows('Rękawice EN 388:2016+A1:2018 min. 3X21C', [
            new CardSource(CardSource::NORMS, 'EN 388:2003 (4542), EN 388:2016 (4X42C)'),
        ])['en388'];

        $this->assertSame(Status::Ok, $row->status);
        $this->assertSame(['4X42C', 'ok'], [$row->card[0]['text'], $row->card[0]['verdict']], 'oceniane pole pierwsze — wiersz ✓ pokazuje jedno znalezisko');
        $this->assertArrayNotHasKey('edition', $row->card[0]);
        $this->assertSame(['4542', 'unclear', '2003'], [$row->card[1]['text'], $row->card[1]['verdict'], $row->card[1]['edition']]);
        $this->assertSame('inne wydanie normy (nie oceniane): EN 388:2003 4542', $row->note);
    }

    #[Test]
    public function card_code_without_edition_is_judged_against_requirement_with_edition(): void
    {
        // rok tylko po jednej stronie — kod karty porównujemy, a nie odrzucamy
        $row = $this->rows('Rękawice EN 388:2016 min. 3121X', [new CardSource(CardSource::NORMS, 'EN 388 4131X')])['en388'];

        $this->assertSame(Status::Ok, $row->status);
        $this->assertSame('ok', $row->card[0]['verdict']);
        $this->assertNull($row->note);
    }

    #[Test]
    public function requirement_without_edition_judges_both_editions_without_conflict_note(): void
    {
        $row = $this->rows('Rękawice EN 388 min. 3121', [
            new CardSource(CardSource::NORMS, 'EN 388:2003 (4542)'),
            new CardSource(CardSource::SPECS, 'EN 388:2016 (4X42C)'),
        ])['en388'];

        $this->assertSame(['ok', 'missing'], array_column($row->card, 'verdict'), 'bez roku w wymaganiu oba pola oceniane jak dotąd');
        $this->assertSame(Status::Missing, $row->status);
        $this->assertSame('przecięcie (Coup Test): X na karcie (nie badano)', $row->note, 'różne wydania to nie „różne kody”');
    }

    #[Test]
    public function same_edition_codes_that_differ_give_conflict_note_with_edition(): void
    {
        $row = $this->rows('Rękawice EN 388 min. 3X21C', [
            new CardSource(CardSource::NORMS, 'EN 388:2016 (4X42C)'),
            new CardSource(CardSource::SPECS, 'EN 388:2016 (3X42C)'),
        ])['en388'];

        $this->assertSame(Status::Ok, $row->status);
        $this->assertSame('pola karty podają różne kody: EN 388:2016 4X42C, EN 388:2016 3X42C', $row->note);
    }

    #[Test]
    public function card_listing_several_categories_is_unclear(): void
    {
        $slashes = $this->rows('Kategoria: II', [new CardSource(CardSource::SPECS, 'Kategoria: I/II/III')])['ppe_category'];
        $range = $this->rows('ŚOI kat. III', [new CardSource(CardSource::DESCRIPTION, 'Rękawice zgodne z rozporządzeniem (kat. I-III)')])['ppe_category'];

        $this->assertSame([Status::Unclear, Status::Unclear], [$slashes->status, $range->status]);
        $this->assertSame('Karta wymienia kilka kategorii: Kategoria: I/II/III (specs) — nie wiadomo, która dotyczy produktu.', $slashes->note);
        $this->assertSame('unclear', $range->card[0]['verdict']);
    }

    #[Test]
    public function field_listing_several_classes_is_unclear(): void
    {
        $ffp = $this->rows('Półmaska FFP3.', [new CardSource(CardSource::DESCRIPTION, 'Dostępne w klasach FFP1, FFP2 i FFP3')])['ffp'];
        $this->assertSame(Status::Unclear, $ffp->status);
        $this->assertSame('FFP1, FFP2 i FFP3', $ffp->card[0]['text']);
        $this->assertSame('Karta wymienia kilka klas FFP: FFP1, FFP2 i FFP3 (description) — nie wiadomo, która dotyczy produktu.', $ffp->note);

        $snr = $this->rows('Nauszniki SNR min. 30 dB.', [new CardSource(CardSource::DESCRIPTION, 'Wkładki SNR 25 dB, nauszniki SNR 32 dB')])['snr'];
        $this->assertSame(Status::Unclear, $snr->status);

        $shoes = $this->rows('Obuwie S3.', [new CardSource(CardSource::DESCRIPTION, 'Wersja S1P; obuwie S3 SRC')])['footwear_class'];
        $this->assertSame(Status::Unclear, $shoes->status);
    }

    #[Test]
    public function footwear_class_reads_2022_suffixes_and_ignores_lowercase(): void
    {
        $spec = static fn (string $text): array => [new CardSource(CardSource::SPECS, $text)];

        $this->assertSame(Status::Ok, $this->rows('Obuwie S3.', $spec('EN ISO 20345:2022 S3S'))['footwear_class']->status, 'S3S to S3 z wkładką typu S');
        $this->assertSame(Status::Fail, $this->rows('Obuwie S3.', $spec('rozmiar sb, klasa S1'))['footwear_class']->status, '„sb” małymi to nie klasa');
        $this->assertSame(Status::Ok, $this->rows('Obuwie S1PL.', $spec('Klasa: S1PL'))['footwear_class']->status);
        $this->assertSame(Status::Unclear, $this->rows('Obuwie S1PL.', $spec('Klasa: S1P'))['footwear_class']->status, 'karta nie podaje typu wkładki');
        $this->assertSame(Status::Fail, $this->rows('Obuwie S1PL.', $spec('Klasa: S1'))['footwear_class']->status);
        $this->assertSame(Status::Ok, $this->rows('Obuwie S1 PL.', $spec('Klasa: S1 P L'))['footwear_class']->status, 'zapis ze spacją to ta sama klasa');
        $this->assertSame(Status::Unclear, $this->rows('Obuwie S3 L.', $spec('Klasa: S3 S'))['footwear_class']->status, 'inny typ wkładki');
        $this->assertSame(Status::Ok, $this->rows('Obuwie S3.', $spec('EN ISO 20345:2022 S7 FO SR'))['footwear_class']->status, 'S7 to S3 z wodoodpornością');
        $this->assertSame(Status::Fail, $this->rows('Obuwie S3.', $spec('Kalosz S5 SRC'))['footwear_class']->status, 'obuwie całogumowe nie zastępuje trzewika');
    }

    /**
     * Wiersz „Klasa obuwia” i bramka przetargowa muszą czytać ten sam napis tak samo — inaczej karta
     * przechodzi bramkę jako S1, a tabela pokazuje S1PL (albo odwrotnie). Jedyna celowa różnica to
     * wielkość liter: tutaj klasą jest wyłącznie zapis wielkimi literami.
     */
    #[Test]
    #[DataProvider('footwearClassVariants')]
    public function footwear_class_is_read_the_same_as_in_product_attributes(string $text): void
    {
        $fromAttributes = (new BhpAttributeNormalizer)->footwearClass($text);
        $row = $this->rows("Obuwie {$text}.", [new CardSource(CardSource::SPECS, $text)])['footwear_class'] ?? null;

        $this->assertNotNull($row, "LevelChecker nie odczytał klasy z „{$text}”");
        $this->assertSame($fromAttributes, $row->required['value']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function footwearClassVariants(): iterable
    {
        foreach ([
            'S1', 'S1P', 'S1 P', 'S1PL', 'S1 PL', 'S1 P L', 'S3', 'S3L', 'S3 L', 'S3S', 'S5L', 'S6',
            'S7L', 'SB', 'OB', 'O1', 'O2 FO', 'OB A E FO', 'ARYEL 320 671460 S3L',
            'EN ISO 20345:2022 S3L FO SR',
        ] as $variant) {
            yield $variant => [$variant];
        }
    }

    #[Test]
    public function requirement_without_levels_gives_no_rows(): void
    {
        $this->assertSame([], $this->rows(Opisowy15Fixture::requirement(10), $this->fixtureCard('191400')));
    }

    /**
     * @param  list<CardSource>  $sources
     * @return array<string, CheckRow>
     */
    #[Test]
    public function manufacturer_code_decides_the_row_and_other_fields_stay_visible(): void
    {
        // ATG (23.09.2026): producent „3121A”, stara lista wzbogacania „4121A” — o wierszu decyduje producent
        $rows = $this->rows('Rękawice EN 388 min. 3121A', [
            new CardSource(CardSource::MANUFACTURER, 'EN 388:2016 + A1:2018: 3121A'),
            new CardSource(CardSource::PAYLOAD_NORMS, 'EN 388:2016 2121X'),
        ]);

        $row = $rows['en388'];
        $this->assertSame(Status::Ok, $row->status, 'producent spełnia — zapis z opisu nie robi z tego „do sprawdzenia”');
        $this->assertSame(CardSource::MANUFACTURER, $row->card[0]['source'], 'w jednej linii pokazujemy zapis producenta');
        $this->assertCount(2, $row->card, 'zapis z opisu zostaje widoczny');
        $this->assertStringContainsString('rozstrzyga norma producenta', (string) $row->note);
    }

    #[Test]
    public function manufacturer_without_the_asked_position_does_not_decide(): void
    {
        // CXS podaje poziomy słownie bez litery ISO — brak u producenta nie jest faktem, liczymy wszystkie pola
        $rows = $this->rows('Rękawice EN 388 min. 2112B', [
            new CardSource(CardSource::MANUFACTURER, 'EN 388: odporność na przetarcie - 2, odporność na przecięcie - 1, odporność na rozerwanie - 1, odporność na przekłucie - 2'),
            new CardSource(CardSource::DESCRIPTION, 'Normy: EN 388:2016 2112B.'),
        ]);

        $this->assertStringNotContainsString('rozstrzyga norma producenta', (string) $rows['en388']->note);
    }

    private function rows(string $requirement, array $sources): array
    {
        $out = [];
        foreach ((new LevelChecker)->check($requirement, $sources) as $row) {
            $out[$row->key] = $row;
        }

        return $out;
    }

    /**
     * @param  array<string, CheckRow>  $rows
     * @return array<string, string>
     */
    private function statuses(array $rows): array
    {
        return array_map(static fn (CheckRow $row): string => $row->status->value, $rows);
    }

    /**
     * @return list<CardSource>
     */
    private function fixtureCard(string $sku): array
    {
        return CardSources::fromProduct(Opisowy15Fixture::product($sku));
    }
}
