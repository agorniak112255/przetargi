<?php

declare(strict_types=1);

namespace Tests\Unit\RequirementCheck;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\RequirementCheck\CardSource;
use App\Support\RequirementCheck\CardSources;
use App\Support\RequirementCheck\CheckRow;
use App\Support\RequirementCheck\ColorChecker;
use App\Support\RequirementCheck\RequirementCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Kolor a warianty karty (product_variants): wymagana barwa wśród etykiet wariantów to „spełnia” z uwagą o wyborze
 * wariantu w ofercie. Gdy żaden wariant nie pasuje — wynik dokładnie taki jak bez wariantów.
 */
final class ColorCheckerVariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_wymagana_barwa_wsrod_wariantow_to_ok_z_uwaga_o_wyborze(): void
    {
        $row = $this->row('Kurtka ochronna w kolorze żółtym', [
            new CardSource(CardSource::SPECS, 'Kolor: czerwony'),
            ...$this->variants(['czerwony', 'żółty', 'biały']),
        ]);

        $this->assertSame('ok', $row->status->value);
        $this->assertSame('Wymagana barwa jest wśród wariantów karty — wybierz wariant w ofercie.', $row->note);
        $this->assertStringNotContainsString('wariantem do zamówienia', (string) $row->note);
        // najpierw pasujący wariant, potem znaleziska z pól karty (inna barwa w opisie zostaje widoczna)
        $this->assertSame([['variant', 'żółty', 'ok'], ['specs', 'czerwony', 'unclear']], array_map(
            static fn (array $f): array => [$f['source'], $f['text'], $f['verdict']],
            $row->card,
        ));
        $this->assertSame(['żółty'], $row->card[0]['colors']);
        // etykiety wariantu nie ma w oknie opisu — bez „Szukaj w opisie”
        $this->assertNull($row->card[0]['find']);
    }

    public function test_ten_sam_kolor_w_wielu_rozmiarach_to_jedno_znalezisko(): void
    {
        $row = $this->row('Kurtka w kolorze żółtym', $this->variants(['żółty 42', 'żółty 44', 'czerwony 42']));

        $this->assertSame('ok', $row->status->value);
        $this->assertSame(['żółty 42'], array_column($row->card, 'text'));
    }

    public function test_alternatywy_z_wymagania_wystarczy_jedna_barwa_wariantu(): void
    {
        $row = $this->row('Czapka w kolorze żółtym lub pomarańczowym', $this->variants(['granatowy', 'pomarańczowy']));

        $this->assertSame('ok', $row->status->value);
        $this->assertSame(['pomarańczowy'], array_column($row->card, 'text'));
    }

    /**
     * @param  list<string>  $specs  pola karty
     * @param  list<string>  $labels  etykiety wariantów, z których żadna nie pasuje
     */
    #[DataProvider('noMatchingVariant')]
    public function test_bez_pasujacego_wariantu_wynik_jak_bez_wariantow(string $requirement, array $specs, array $labels): void
    {
        $sources = array_map(static fn (string $text): CardSource => new CardSource(CardSource::SPECS, $text), $specs);

        $this->assertEquals(
            (new ColorChecker)->check($requirement, $sources),
            (new ColorChecker)->check($requirement, [...$sources, ...$this->variants($labels)]),
        );
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    public static function noMatchingVariant(): array
    {
        return [
            'wymagany zielony, warianty w innych barwach' => ['Rękawice w kolorze zielonym', ['Kolor: czerwony'], ['czerwony', 'żółty']],
            'wymagany zielony, karta bez barwy' => ['Rękawice w kolorze zielonym', ['Materiał: nitryl'], ['czerwony', 'żółty']],
            // etykieta potwierdza tylko barwę — fluorescencji (EN ISO 20471) nie
            'fluorescencyjny żółty, wariant żółty' => ['Kamizelka w kolorze fluorescencyjnym żółtym', ['Kolor: pomarańczowy'], ['żółty', 'pomarańczowy']],
            'sama wysoka widoczność' => ['Kamizelka o wysokiej widoczności', ['Kolor: żółty'], ['żółty']],
            'same rozmiary' => ['Kurtka w kolorze granatowym', ['Kolor: granatowy'], ['42', '44']],
            // „żółto-czarny” to inna barwa niż sam żółty
            'wariant dwubarwny' => ['Czapka w kolorze żółtym', ['Kolor: czerwony'], ['żółto-czarny']],
        ];
    }

    public function test_fluo_zolty_wymagany_a_wariant_zolty_to_nie_ok(): void
    {
        $requirement = 'Kamizelka w kolorze fluorescencyjnym żółtym';
        $row = $this->row($requirement, $this->variants(['żółty', 'pomarańczowy']));

        $this->assertNotSame('ok', $row->status->value);
        $this->assertSame([], $row->card);
    }

    public function test_karty_z_zestawu_opisowy15_bez_pasujacego_wariantu_bez_zmian(): void
    {
        foreach ([[1, '11202000'], [1, '87320100-BULK'], [15, '87320100-BULK']] as [$position, $sku]) {
            $requirement = Opisowy15Fixture::requirement($position);
            $sources = CardSources::fromProduct(Opisowy15Fixture::product($sku));

            $this->assertEquals(
                (new ColorChecker)->check($requirement, $sources),
                (new ColorChecker)->check($requirement, [...$sources, ...$this->variants(['czarny 42', 'biały 43'])]),
                "poz. {$position} × {$sku}",
            );
        }
    }

    public function test_porownanie_karty_z_bazy_bierze_aktywne_warianty_tylko_do_koloru(): void
    {
        $card = Product::query()->create([
            'sku' => 'KURT-1', 'name' => 'Kurtka robocza', 'manufacturer' => 'Test', 'currency' => 'PLN',
            'description' => 'Kurtka robocza. Kolor: czerwony.',
        ]);
        $this->variant($card, 'KURT-1-RD', 'czerwony');
        $this->variant($card, 'KURT-1-YE', 'żółty');
        $this->variant($card, 'KURT-1-GN', 'zielony', removed: true);
        $check = app(RequirementCheck::class);

        $yellow = $check->compare('Kurtka robocza w kolorze żółtym', $card);
        $this->assertSame('ok', $this->colorRow($yellow)['status']);
        $this->assertSame('variant', $this->colorRow($yellow)['card'][0]['source']);

        // wariant usunięty (removed_at) nie jest do wyboru — wynik jak bez wariantów
        $green = $check->compare('Kurtka robocza w kolorze zielonym', $card);
        $this->assertEquals($check->compare('Kurtka robocza w kolorze zielonym', $card, withVariants: false), $green);

        // pozostałe grupy i sprzeczności bez zmian
        $without = $check->compare('Kurtka robocza w kolorze żółtym', $card, withVariants: false);
        $this->assertSame('unclear', $this->colorRow($without)['status']);
        $this->assertEquals($without['conflicts'], $yellow['conflicts']);
        $this->assertEquals(
            array_values(array_filter($without['groups'], static fn (array $g): bool => $g['key'] !== 'color')),
            array_values(array_filter($yellow['groups'], static fn (array $g): bool => $g['key'] !== 'color')),
        );
    }

    public function test_bez_barwy_w_wymaganiu_i_dla_niezapisanej_karty_bez_zapytania_o_warianty(): void
    {
        $card = Product::query()->create(['sku' => 'K-2', 'name' => 'Kask', 'manufacturer' => 'Test', 'currency' => 'PLN']);
        $check = app(RequirementCheck::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $check->compare('Kask ochronny EN 397', $card);
        $check->compare('Kask w kolorze białym', new Product(['name' => 'Kask', 'sku' => 'K-3']));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], array_values(array_filter(
            array_column($queries, 'query'),
            static fn (string $sql): bool => str_contains($sql, 'product_variants'),
        )));
    }

    /**
     * @param  list<string>  $labels
     * @return list<CardSource>
     */
    private function variants(array $labels): array
    {
        return array_map(static fn (string $label): CardSource => new CardSource(CardSource::VARIANT, $label), $labels);
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

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function colorRow(array $result): array
    {
        foreach ($result['groups'] as $group) {
            if ($group['key'] === 'color') {
                return $group['rows'][0];
            }
        }
        $this->fail('brak wiersza koloru');
    }

    private function variant(Product $card, string $sku, string $label, bool $removed = false): ProductVariant
    {
        return ProductVariant::query()->create([
            'product_id' => $card->id, 'kind' => ProductVariant::KIND_SIZE, 'source' => 'b2b:1', 'remote_id' => $sku,
            'sku' => $sku, 'label' => $label, 'purchase_price' => 10, 'currency' => 'PLN',
            'removed_at' => $removed ? now() : null,
        ]);
    }
}
