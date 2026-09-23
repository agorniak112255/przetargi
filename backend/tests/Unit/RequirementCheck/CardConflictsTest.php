<?php

declare(strict_types=1);

namespace Tests\Unit\RequirementCheck;

use App\Models\Product;
use App\Support\RequirementCheck\CardConflict;
use App\Support\RequirementCheck\CardSource;
use App\Support\RequirementCheck\CardSources;
use App\Support\RequirementCheck\CheckRow;
use App\Support\RequirementCheck\ConflictSummary;
use App\Support\RequirementCheck\LevelChecker;
use App\Support\RequirementCheck\RequirementCheck;
use App\Support\RequirementCheck\Status;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Sprzeczności między polami jednej karty (przycisk „Sprzeczności (N)”). Pomiar na 21 613 kartach: bez reguł
 * „lista klas to warianty” i „kod przeczy tylko na tej samej pozycji” wychodziły fałszywe alarmy.
 */
final class CardConflictsTest extends TestCase
{
    #[Test]
    public function armen_sandal_name_and_specs_give_different_footwear_classes(): void
    {
        $conflicts = $this->byKey((new LevelChecker)->cardConflicts($this->fixtureCard('ARMEN 9007 6660 S1 P')));

        $this->assertSame(['footwear_class'], array_keys($conflicts));
        $this->assertSame('Klasa obuwia', $conflicts['footwear_class']['label']);
        $this->assertNull($conflicts['footwear_class']['row'], 'bez wymagania nie ma wiersza w groups');
        $values = $this->values($conflicts['footwear_class']);
        $this->assertSame(['S1P', 'S1'], array_keys($values));
        $this->assertContains('name: S1 P', $values['S1P']);
        $this->assertContains('specs: S1 P', $values['S1P'], '„Kod producenta: ARMEN 9007 6660 S1 P”');
        $this->assertContains('specs: S1', $values['S1'], '„Klasa ochrony: S1”');
        $this->assertNotContains('description: S1 P', $values['S1P'], 'opis wymienia S1 P i S1 w jednym polu — to warianty, nie wartość');
    }

    #[Test]
    public function different_category_and_en388_position_are_conflicts(): void
    {
        $conflicts = $this->byKey((new LevelChecker)->cardConflicts([
            new CardSource(CardSource::NORMS, 'EN 388: 4X42C, kat. II'),
            new CardSource(CardSource::SPECS, 'Kategoria: III'),
            new CardSource(CardSource::SPECS, 'EN 388: 3X42C'),
        ]));

        $this->assertSame(['ppe_category', 'en388'], array_keys($conflicts));
        $this->assertSame(['II' => ['norms: kat. II'], 'III' => ['specs: Kategoria: III']], $this->values($conflicts['ppe_category']));
        $this->assertSame(['4X42C' => ['norms: 4X42C'], '3X42C' => ['specs: 3X42C']], $this->values($conflicts['en388']));
        $this->assertSame('norms', $conflicts['ppe_category']['values'][0]['findings'][0]['source']);
        $this->assertSame('EN 388: 4X42C, kat. II', $conflicts['ppe_category']['values'][0]['findings'][0]['quote']);
    }

    #[Test]
    public function insert_type_suffix_only_is_labelled_as_different_notation(): void
    {
        $conflicts = $this->byKey((new LevelChecker)->cardConflicts([
            new CardSource(CardSource::NAME, 'Półbuty XYZ S1PL'),
            new CardSource(CardSource::SPECS, 'Klasa ochrony: S1P'),
        ]));

        $this->assertSame('Klasa obuwia (różne zapisy)', $conflicts['footwear_class']['label']);
        $this->assertSame(['S1PL', 'S1P'], array_keys($this->values($conflicts['footwear_class'])));
    }

    /**
     * @return iterable<string, array{list<CardSource>}>
     */
    public static function notConflicts(): iterable
    {
        yield 'kod bez litery ISO obok kodu z literą' => [[
            new CardSource(CardSource::NORMS, 'EN 388: 1241'),
            new CardSource(CardSource::SPECS, 'EN 388: 1241B'),
        ]];
        yield 'kod EN 407 i ta sama jedynka zapisana słownie' => [[
            new CardSource(CardSource::NORMS, 'EN 407: X1XXXX'),
            new CardSource(CardSource::SPECS, 'Ciepło kontaktowe: poziom 1'),
        ]];
        yield 'zakres kategorii to warianty' => [[
            new CardSource(CardSource::DESCRIPTION, 'Rękawice zgodne z rozporządzeniem (kat. I–III)'),
            new CardSource(CardSource::SPECS, 'Kategoria: II'),
        ]];
        yield 'ta sama kategoria w dwóch polach' => [[
            new CardSource(CardSource::NORMS, 'EN ISO 21420, kat. II'),
            new CardSource(CardSource::SPECS, 'Kategoria: II'),
        ]];
        yield 'kilka klas FFP w jednym polu' => [[
            new CardSource(CardSource::DESCRIPTION, 'Dostępne w klasach FFP1, FFP2 i FFP3'),
            new CardSource(CardSource::SPECS, 'Klasa: FFP2'),
        ]];
        yield 'dwa wydania EN 388 w dwóch polach (UVEX C500)' => [[
            new CardSource(CardSource::NORMS, 'EN 388:2003 (4542)'),
            new CardSource(CardSource::SPECS, 'EN 388:2016 (4X42C)'),
        ]];
        yield 'dwa wydania EN 388 w jednym polu' => [[
            new CardSource(CardSource::DESCRIPTION, 'Normy: EN 388:2003 (4542), EN 388:2016 + A1:2018 (4X42C)'),
        ]];
    }

    /**
     * @return iterable<string, array{list<CardSource>, array<string, list<string>>}>
     */
    public static function en388EditionConflicts(): iterable
    {
        yield 'dwa kody 2016 różne na pozycji' => [[
            new CardSource(CardSource::NORMS, 'EN 388:2016 (4X42C)'),
            new CardSource(CardSource::SPECS, 'EN 388:2016 (3X42C)'),
        ], ['4X42C' => ['norms: 4X42C'], '3X42C' => ['specs: 3X42C']]];
        yield '2016 i 2016+A1:2018 to jedno wydanie' => [[
            new CardSource(CardSource::NORMS, 'EN 388:2016+A1:2018 (4X42C)'),
            new CardSource(CardSource::SPECS, 'EN 388:2016 (4X43C)'),
        ], ['4X42C' => ['norms: 4X42C'], '4X43C' => ['specs: 4X43C']]];
        yield 'bez roku po obu stronach (MAPA #112)' => [[
            new CardSource(CardSource::SPECS, 'EN 388 (1.1.2.2)'),
            new CardSource(CardSource::DESCRIPTION, 'Rękawice zgodne z EN 388 1121X.'),
        ], ['1122-' => ['specs: 1.1.2.2'], '1121X' => ['description: 1121X']]];
        yield 'rok tylko po jednej stronie' => [[
            new CardSource(CardSource::NORMS, 'EN 388:2003 (4542)'),
            new CardSource(CardSource::SPECS, 'EN 388: 4X42C'),
        ], ['4542-' => ['norms: 4542'], '4X42C' => ['specs: 4X42C']]];
    }

    #[Test]
    public function manufacturer_value_comes_first_and_stated_edition_is_shown(): void
    {
        $conflicts = $this->byKey((new LevelChecker)->cardConflicts([
            new CardSource(CardSource::PAYLOAD_NORMS, 'EN 388:2016 4121A'),
            new CardSource(CardSource::MANUFACTURER, 'EN 388:2016 + A1:2018: 3121A'),
        ]));

        $values = $conflicts['en388']['values'];
        $this->assertSame('3121A', $values[0]['value'], 'wartość producenta pierwsza');
        $this->assertSame('manufacturer', $values[0]['findings'][0]['source']);
        $this->assertSame('2016', $values[0]['edition'] ?? null, 'rok podany w polu stoi przy wartości');
        $this->assertSame('4121A', $values[1]['value']);
    }

    /**
     * @param  list<CardSource>  $sources
     * @param  array<string, list<string>>  $values
     */
    #[Test]
    #[DataProvider('en388EditionConflicts')]
    public function en388_codes_of_same_or_unstated_edition_conflict(array $sources, array $values): void
    {
        $conflicts = $this->byKey((new LevelChecker)->cardConflicts($sources));

        $this->assertSame(['en388'], array_keys($conflicts));
        $this->assertSame($values, $this->values($conflicts['en388']));
    }

    /**
     * @param  list<CardSource>  $sources
     */
    #[Test]
    #[DataProvider('notConflicts')]
    public function variants_and_partial_codes_are_not_conflicts(array $sources): void
    {
        $this->assertSame([], (new LevelChecker)->cardConflicts($sources));
    }

    #[Test]
    public function database_sleeve_card_has_no_card_field_conflicts(): void
    {
        // 1X42C, X1XXXX i kat. II w normach, „EN 407 poziom 1” w cechach — nic sobie nie przeczy; niespełnienia to `requirement`
        $conflicts = app(RequirementCheck::class)->compare(Opisowy15Fixture::requirement(1), $this->databaseCard())['conflicts'];

        $this->assertSame([], $conflicts['card_fields']);
        $this->assertSame(['en388', 'ppe_category', 'seamless'], $conflicts['requirement']);
        $this->assertSame(3, $conflicts['count']);
    }

    #[Test]
    public function row_with_ok_and_fail_findings_becomes_card_field_conflict_linked_to_row(): void
    {
        $seamless = new CardSource(CardSource::FEATURES, 'Konstrukcja bezszwowa');
        $sewn = new CardSource(CardSource::DESCRIPTION, 'Konstrukcja cięta i szyta zapewnia trwałość.');
        $row = new CheckRow('seamless', 'Bezszwowa', ['text' => 'tak', 'quote' => 'Konstrukcja bezszwowa'], [
            CheckRow::finding($seamless, 'bezszwowa', Status::Ok),
            CheckRow::finding($sewn, 'cięta i szyta', Status::Fail),
        ], Status::Unclear);

        $summary = ConflictSummary::build([$row], []);

        $this->assertSame(1, $summary['count']);
        $this->assertSame([], $summary['requirement']);
        $this->assertSame('seamless', $summary['card_fields'][0]['row']);
        $this->assertSame('Bezszwowa', $summary['card_fields'][0]['label']);
        $this->assertSame(['spełnia' => ['features: bezszwowa'], 'nie spełnia' => ['description: cięta i szyta']], $this->values($summary['card_fields'][0]));
    }

    #[Test]
    public function armen_line_3_merges_row_conflict_with_card_conflict_into_one_entry(): void
    {
        $conflicts = app(RequirementCheck::class)->compare(Opisowy15Fixture::requirement(3), Opisowy15Fixture::product('ARMEN 9007 6660 S1 P'))['conflicts'];

        $this->assertSame(1, $conflicts['count']);
        $this->assertSame([], $conflicts['requirement']);
        $this->assertCount(1, $conflicts['card_fields']);
        $entry = $conflicts['card_fields'][0];
        $this->assertSame(['footwear_class', 'Klasa obuwia', 'footwear_class'], [$entry['key'], $entry['label'], $entry['row']]);
        $values = $this->values($entry);
        $this->assertSame(['S1P', 'S1'], array_keys($values));
        $this->assertSame($values['S1P'], array_values(array_unique($values['S1P'])), 'znaleziska z porównania i z samej karty bez powtórzeń');
    }

    #[Test]
    public function summary_takes_row_from_requirement_when_only_card_rule_finds_conflict(): void
    {
        // wymaganie kat. I: obie kategorie karty je spełniają (brak ok+fail), ale II i III nadal sobie przeczą
        $sources = [new CardSource(CardSource::NORMS, 'kat. II'), new CardSource(CardSource::SPECS, 'Kategoria: III')];
        $rows = (new LevelChecker)->check('Rękawice kat. I', $sources);

        $summary = ConflictSummary::build($rows, (new LevelChecker)->cardConflicts($sources));

        $this->assertSame('ppe_category', $summary['card_fields'][0]['row']);
        $this->assertSame(1, $summary['count']);
    }

    /**
     * @param  list<CardConflict>  $conflicts
     * @return array<string, array<string, mixed>>
     */
    private function byKey(array $conflicts): array
    {
        $out = [];
        foreach ($conflicts as $conflict) {
            $out[$conflict->key] = $conflict->toArray();
        }

        return $out;
    }

    /**
     * Wartość => „pole: zapis” każdego znaleziska.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, list<string>>
     */
    private function values(array $entry): array
    {
        $out = [];
        foreach ($entry['values'] as $value) {
            $out[$value['value']] = array_map(static fn (array $f): string => "{$f['source']}: {$f['text']}", $value['findings']);
        }

        return $out;
    }

    /**
     * @return list<CardSource>
     */
    private function fixtureCard(string $sku): array
    {
        return CardSources::fromProduct(Opisowy15Fixture::product($sku));
    }

    /** Ta sama karta z bazy co w RequirementCheckCardVersionsTest — opis pobrany później niż migawka. */
    private function databaseCard(): Product
    {
        $card = json_decode((string) file_get_contents(base_path('tests/Fixtures/catalog/requirement-check/11202000-db.json')), true, flags: JSON_THROW_ON_ERROR);
        unset($card['_meta']);

        return (new Product)->forceFill(['id' => 900001] + $card);
    }
}
