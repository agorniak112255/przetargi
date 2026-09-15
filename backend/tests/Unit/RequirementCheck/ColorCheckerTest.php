<?php

declare(strict_types=1);

namespace Tests\Unit\RequirementCheck;

use App\Support\RequirementCheck\CardSource;
use App\Support\RequirementCheck\CardSources;
use App\Support\RequirementCheck\CheckRow;
use App\Support\RequirementCheck\ColorChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

final class ColorCheckerTest extends TestCase
{
    public function test_poz_1_karta_11202000_zolty_fluorescencyjny_wprost_w_opisie(): void
    {
        $row = $this->row(Opisowy15Fixture::requirement(1), CardSources::fromProduct(Opisowy15Fixture::product('11202000')));

        $this->assertSame('żółty (wysoka widoczność)', $row->required['text']);
        $this->assertStringContainsString('w kolorze fluorescencyjnym żółtym', $row->required['quote']);
        // „Single fluorescent yellow, high-vis sleeve” — barwa i hi-vis w jednym zdaniu; samo HI-VIZ z cech nie jest już potrzebne
        $this->assertSame('ok', $row->status->value);
        $this->assertSame(['fluorescent yellow, high-vis'], array_column($row->card, 'text'));
    }

    public function test_poz_1_karta_produkcyjna_sama_wysoka_widocznosc_bez_barwy_do_sprawdzenia(): void
    {
        $row = $this->row(Opisowy15Fixture::requirement(1), [
            new CardSource(CardSource::SPECS, 'Kolor: wysoka widoczność'),
            new CardSource(CardSource::FEATURES, 'Wysoka widoczność – kolor ostrzegawczy'),
            new CardSource(CardSource::DESCRIPTION, 'Rękaw w kolorze wysokiej widoczności wykonano z nylonu.'),
        ]);

        $this->assertSame('unclear', $row->status->value);
        $this->assertSame(['unclear', 'unclear', 'unclear'], array_column($row->card, 'verdict'));
    }

    public function test_poz_15_karta_87320100_pomaranczowa(): void
    {
        $row = $this->row(Opisowy15Fixture::requirement(15), CardSources::fromProduct(Opisowy15Fixture::product('87320100-BULK')));

        $this->assertSame('pomarańczowy', $row->required['text']);
        $this->assertSame('ok', $row->status->value);
    }

    public function test_inna_barwa_to_wariant_do_sprawdzenia_nie_fail(): void
    {
        // karta pomarańczowa przy wymaganym żółtym fluorescencyjnym (poz. 1 × 87320100)
        $row = $this->row(Opisowy15Fixture::requirement(1), CardSources::fromProduct(Opisowy15Fixture::product('87320100-BULK')));

        $this->assertSame('unclear', $row->status->value);
        $this->assertNotContains('fail', array_column($row->card, 'verdict'));
        $this->assertStringContainsString('wariantem', (string) $row->note);
    }

    public function test_odblaskowy_nie_jest_fluorescencyjnym(): void
    {
        $this->assertSame('unclear', $this->statusFor('Kamizelka w kolorze fluorescencyjnym żółtym', ['Kolor: żółty odblaskowy']));
    }

    public function test_barwa_i_hi_vis_w_roznych_polach_to_wniosek(): void
    {
        $this->assertSame('unclear', $this->statusFor('Kamizelka w kolorze fluorescencyjnym żółtym', ['Kolor: żółty', 'Wysoka widoczność']));
        $this->assertSame('ok', $this->statusFor('Kamizelka w kolorze fluorescencyjnym żółtym', ['Kolor: żółty fluorescencyjny']));
    }

    public function test_brak_barwy_na_karcie_to_missing(): void
    {
        $this->assertSame('missing', $this->statusFor('Rękawice nitrylowe w kolorze niebieskim', ['Materiał: nitryl']));
    }

    public function test_barwa_z_naglowka_i_alternatywy(): void
    {
        $this->assertSame('ok', $this->statusFor('Rękawice nitrylowe niebieskie, bezpudrowe', ['Kolor: niebieski']));
        $this->assertSame('ok', $this->statusFor('Czapka w kolorze żółtym lub pomarańczowym', ['Kolor: pomarańczowy']));
        // „żółto-czarny” to inna barwa niż sam żółty
        $this->assertSame('unclear', $this->statusFor('Czapka w kolorze żółtym', ['Kolor: żółto-czarny']));
        // wymaganie podaje tylko hi-vis — barwy karty nie ma z czym porównać
        $this->assertSame('unclear', $this->statusFor('Kamizelka ostrzegawcza', ['Kolor: żółty fluorescencyjny']));
    }

    public function test_falszywe_barwy_w_wymaganiu_nie_tworza_wiersza(): void
    {
        $checker = new ColorChecker;
        $card = [new CardSource(CardSource::SPECS, 'Kolor: niebieski')];

        $this->assertSame([], $checker->check('Rękawice nitrylowe z niebieskim wkładem', $card));
        $this->assertSame([], $checker->check('Fartuch roboczy. Odporność na zmianę barwy; dostawca z białej listy VAT.', $card));
    }

    /**
     * Barwa części wyrobu, zaprzeczona albo z nazwy-marki nigdy nie potwierdza koloru wyrobu.
     *
     * @return array<string, array{0: string, 1: list<CardSource>, 2: string}>
     */
    public static function neverOkProvider(): array
    {
        $d = static fn (string $text): CardSource => new CardSource(CardSource::DESCRIPTION, $text);
        $s = static fn (string $text): CardSource => new CardSource(CardSource::SPECS, $text);
        $n = static fn (string $text): CardSource => new CardSource(CardSource::NAME, $text);

        return [
            'czarny mankiet' => ['Rękawice w kolorze czarnym', [$d('Rękawice robocze z czarnym mankietem')], 'części'],
            'podeszwa w specs' => ['Obuwie w kolorze czarnym', [$s('Podeszwa: czarna')], 'części'],
            'kolor podeszwy' => ['Obuwie w kolorze czarnym', [$s('Kolor podeszwy: czarny')], 'części'],
            'czarna podeszwa po barwie' => ['Obuwie w kolorze czarnym', [$d('Buty robocze, czarna gumowa podeszwa')], 'części'],
            'black sole' => ['Obuwie w kolorze czarnym', [$d('Safety boots with black sole')], 'części'],
            'nadruk logo' => ['Kamizelka w kolorze pomarańczowym', [$d('Nadruk logo w kolorze pomarańczowym.')], 'części'],
            'taśmy fluorescencyjne' => ['Kurtka w kolorze żółtym fluorescencyjnym', [$d('Kurtka żółta z fluorescencyjnymi taśmami')], ''],
            'niedostępny' => ['Obuwie w kolorze czarnym', [$d('Model niedostępny w kolorze czarnym.')], 'zaprzecz'],
            'bez wstawek' => ['Rękawice w kolorze czarnym', [$d('Rękawice bez czarnych wstawek')], 'zaprzecz'],
            'zaprzeczenie obok kolor' => ['Obuwie w kolorze czarnym', [$s('Kolor: czarny'), $d('Model niedostępny w kolorze czarnym.')], 'zaprzecz'],
            'blue grip w nazwie' => ['Rękawice w kolorze niebieskim', [$n('Rękawice Portwest A120 Blue Grip')], 'nazw'],
            'black diamond w nazwie' => ['Rękawice w kolorze czarnym', [$n('Black Diamond Pro')], 'nazw'],
            'marka w opisie' => ['Rękawice w kolorze niebieskim', [$d('Rękawice Blue Grip do prac montażowych')], 'nazw'],
        ];
    }

    /**
     * @param  list<CardSource>  $sources
     */
    #[DataProvider('neverOkProvider')]
    public function test_barwa_czesci_zaprzeczona_lub_z_nazwy_nigdy_ok(string $requirement, array $sources, string $note): void
    {
        $row = $this->row($requirement, $sources);

        $this->assertSame('unclear', $row->status->value);
        $this->assertStringContainsString($note, (string) $row->note);
    }

    public function test_barwa_wyrobu_nadal_ok(): void
    {
        $this->assertSame('ok', $this->statusFor('Obuwie w kolorze czarnym', ['Kolor: czarny']));
        $this->assertSame('ok', $this->statusFor('Kamizelka w kolorze pomarańczowym', ['Pomarańczowy']));
        foreach ([
            'Obuwie w kolorze czarnym',
            'Rękawice czarne',
            'czarne rękawice nitrylowe',
            'Kurtka w czarnym kolorze',
            'Rękawice nitrylowe bez lateksu i pudru w kolorze czarnym',
            'Rękawice w kolorze czarnym z mankietem',
        ] as $text) {
            $row = $this->row('Rękawice w kolorze czarnym', [new CardSource(CardSource::DESCRIPTION, $text)]);
            $this->assertSame('ok', $row->status->value, $text);
        }
        // polska nazwa z barwą to nie marka
        $row = $this->row('Rękawice w kolorze czarnym', [new CardSource(CardSource::NAME, 'Rękawice nitrylowe czarne')]);
        $this->assertSame('ok', $row->status->value);
    }

    public function test_barwa_czesci_nie_przeslania_barwy_wyrobu(): void
    {
        // podeszwa czarna przy wymaganym czarnym nie daje ok, ale żółty wyrób obok to już inna barwa
        $row = $this->row('Obuwie w kolorze czarnym', [new CardSource(CardSource::SPECS, 'Kolor: żółty, podeszwa czarna')]);
        $this->assertSame('unclear', $row->status->value);
        $this->assertNotContains('ok', array_column($row->card, 'verdict'));
    }

    /**
     * @param  list<string>  $specs
     */
    private function statusFor(string $requirement, array $specs): string
    {
        $sources = array_map(static fn (string $text): CardSource => new CardSource(CardSource::SPECS, $text), $specs);

        return $this->row($requirement, $sources)->status->value;
    }

    /**
     * @param  list<CardSource>  $sources
     */
    private function row(string $requirement, array $sources): CheckRow
    {
        $rows = (new ColorChecker)->check($requirement, $sources);
        $this->assertCount(1, $rows, "wymaganie „{$requirement}” powinno dać wiersz koloru");

        return $rows[0];
    }
}
