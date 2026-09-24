<?php

declare(strict_types=1);

namespace Tests\Unit\RequirementCheck;

use App\Support\RequirementCheck\CardSource;
use App\Support\RequirementCheck\CheckRow;
use App\Support\RequirementCheck\PackageChecker;
use App\Support\RequirementCheck\Status;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Uwagi 24.09 do przetargu 1, poz. 11 (płukanka 500 ml): zamienniki Pocket 235 ml, 2-pack, szafka, walizka i stacja.
 * Nazwy kart z produkcji.
 */
final class PackageCheckerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function line11Cards(): iterable
    {
        yield 'karta wzorcowa 7251' => ['Płukanka do oczu Cederroth Eye Wash 500 ml', ['capacity' => 'ok']];
        yield 'z uchwytem ściennym' => ['Płukanka Cederroth 500ml z uchwytem 7200', ['capacity' => 'ok']];
        yield 'inny producent 500 ml' => ['500 ml roztworu do płukania oczu', ['capacity' => 'ok']];
        yield 'Pocket 235 ml' => ['Płukanka do oczu Cederroth Eye Wash Pocket, 235 ml', ['capacity' => 'fail']];
        yield 'butelka 200 ml' => ['Płyn do oczu PLUM EYE WASH (butelka 200ml)', ['capacity' => 'fail']];
        yield '2-pack' => ['Płukanka do oczu Cederroth Eye Wash 2-pack, 2x500 ml', ['capacity' => 'ok', 'single_item' => 'fail']];
        yield 'szafka' => ['Płukanka do oczu 2x500 ml w szafce Cederroth Eye Wash Cabinet', ['capacity' => 'ok', 'single_item' => 'fail']];
        yield 'walizka' => ['Walizka z płukankami Cederroth Eye Wash, 5x500 ml, zastępuje REF 7255', ['capacity' => 'ok', 'single_item' => 'fail']];
        yield 'stacja' => ['Stacja do płukania oczu Cederroth Eye Wash Station, 2x500 ml', ['capacity' => 'ok', 'single_item' => 'fail']];
        yield 'szafka bez pojemności' => ['Szafka termiczna na butelki z płukanką do oczu Cederroth', ['capacity' => 'missing', 'single_item' => 'fail']];
    }

    /** @param array<string, string> $expected */
    #[Test]
    #[DataProvider('line11Cards')]
    public function line_11_cards(string $name, array $expected): void
    {
        $this->assertSame($expected, $this->statuses(Opisowy15Fixture::requirement(11), [new CardSource(CardSource::NAME, $name)]));
    }

    #[Test]
    public function name_decides_capacity_over_description_listing_other_versions(): void
    {
        $rows = $this->rows(Opisowy15Fixture::requirement(11), [
            new CardSource(CardSource::NAME, 'Płukanka do oczu Cederroth Eye Wash 500 ml'),
            new CardSource(CardSource::DESCRIPTION, 'Dostępna także w wersji Pocket 235 ml.'),
        ]);

        $this->assertSame(Status::Ok, $rows['capacity']->status);
        $this->assertSame(['ok', 'fail'], array_column($rows['capacity']->card, 'verdict'));
        $this->assertSame('500 ml', $rows['capacity']->required['text']);
    }

    #[Test]
    public function requirement_asking_for_a_pack_does_not_reject_sets(): void
    {
        $requirement = 'Płukanka do oczu Cederroth 2-pack 2 x butelka 500 ml (nr 725200)';

        $this->assertSame(['capacity' => 'ok'], $this->statuses($requirement, [new CardSource(CardSource::NAME, 'Płukanka do oczu Cederroth Eye Wash 2-pack, 2x500 ml')]));
        $this->assertSame(['capacity' => 'fail'], $this->statuses($requirement, [new CardSource(CardSource::NAME, 'Spray do oczu i ran CEDERROTH 150ml')]));
    }

    #[Test]
    public function minimum_capacity_and_litres(): void
    {
        $requirement = 'Mydło w płynie do rąk, opakowanie min. 500 ml.';

        $this->assertSame(['capacity' => 'ok'], $this->statuses($requirement, [new CardSource(CardSource::NAME, 'Mydło w płynie 5 L')]));
        $this->assertSame(['capacity' => 'ok'], $this->statuses($requirement, [new CardSource(CardSource::NAME, 'Mydło w płynie 0,5 l')]));
        $this->assertSame(['capacity' => 'fail'], $this->statuses($requirement, [new CardSource(CardSource::NAME, 'Mydło w płynie 250 ml')]));
        $this->assertSame('min. 500 ml', $this->rows($requirement, [])['capacity']->required['text']);
    }

    #[Test]
    public function requirement_without_capacity_gives_no_rows_and_size_is_not_litres(): void
    {
        $this->assertSame([], $this->rows('Rękawice nitrylowe, rozm. 9 L, EN 388', [new CardSource(CardSource::NAME, 'Zestaw rękawic 3-pack')]));
        $this->assertSame([], PackageChecker::capacities('Rękawice rozmiar 9 L'));
        $this->assertSame([], PackageChecker::capacities('Kitel laboratoryjny biały'), 'kitel to nie kit');
        $this->assertSame(['capacity' => 'missing'], $this->statuses('Krem ochronny 100 ml', [new CardSource(CardSource::NAME, 'Kitel ochronny')]), 'kitel to nie zestaw — tylko brak pojemności');
    }

    /**
     * @param  list<CardSource>  $sources
     * @return array<string, CheckRow>
     */
    private function rows(string $requirement, array $sources): array
    {
        $out = [];
        foreach ((new PackageChecker)->check($requirement, $sources) as $row) {
            $out[$row->key] = $row;
        }

        return $out;
    }

    /**
     * @param  list<CardSource>  $sources
     * @return array<string, string>
     */
    private function statuses(string $requirement, array $sources): array
    {
        return array_map(static fn (CheckRow $row): string => $row->status->value, $this->rows($requirement, $sources));
    }
}
