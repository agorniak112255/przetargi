<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Support\RequirementCheck\RequirementCheck;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Przetarg 1 poz. 1 i dwie wersje tej samej karty Ansell HyFlex 11202000. Wymaganie spisano z migawki
 * (EN 388 2.X.4.2.C, kat. III, bezszwowa, bez silikonu), a opis pobrany później podaje 1X42C, kat. II
 * i konstrukcję „ciętą i szytą”. Porównanie ma tę różnicę pokazać, a nie ją ukryć — i nie może
 * zamienić braku informacji na „nie spełnia”.
 */
final class RequirementCheckCardVersionsTest extends TestCase
{
    public function test_snapshot_card_meets_requirement_it_was_written_from(): void
    {
        $statuses = $this->statuses(Opisowy15Fixture::product('11202000'));

        $this->assertSame([
            'length' => 'ok',
            'en388' => 'ok',
            'ppe_category' => 'ok',
            'en407' => 'ok',
            'antistatic' => 'ok',
            'latex_free' => 'ok',
            'silicone_free' => 'ok',
            'seamless' => 'ok',
            'hook_and_loop' => 'ok',
            // „bezpieczny dla żywności” i „Food handling” to wniosek, nie „dopuszczony do kontaktu z żywnością”
            'food_contact' => 'unclear',
            // angielski opis migawki: „Food handling compliant under FDA regulations”
            'fda' => 'ok',
            // ten sam opis: „Single fluorescent yellow, high-vis sleeve” — barwa i hi-vis w jednym zdaniu
            'color' => 'ok',
        ], $this->only($statuses, [
            'length', 'en388', 'ppe_category', 'en407', 'antistatic', 'latex_free', 'silicone_free',
            'seamless', 'hook_and_loop', 'food_contact', 'fda', 'color',
        ]));
    }

    public function test_redownloaded_card_shows_what_it_no_longer_meets_and_what_it_does_not_say(): void
    {
        $statuses = $this->statuses($this->databaseCard());

        $this->assertSame([
            'length' => 'ok',
            // ścieranie 1 < 2
            'en388' => 'fail',
            // kat. II < III
            'ppe_category' => 'fail',
            'en407' => 'ok',
            // „antyelektrostatyczne” — synonim z zamkniętego słownika
            'antistatic' => 'ok',
            'latex_free' => 'ok',
            // „Konstrukcja cięta i szyta” przeczy bezszwowej wprost
            'seamless' => 'fail',
            // VELCRO
            'hook_and_loop' => 'ok',
            // karta nie mówi nic o silikonie, FDA ani żywności — to brak, nie „nie spełnia”
            'silicone_free' => 'missing',
            'fda' => 'missing',
            'food_contact' => 'missing',
            'color' => 'unclear',
        ], $this->only($statuses, [
            'length', 'en388', 'ppe_category', 'en407', 'antistatic', 'latex_free', 'seamless',
            'hook_and_loop', 'silicone_free', 'fda', 'food_contact', 'color',
        ]));
    }

    public function test_every_finding_points_to_a_card_field_and_quotes_it(): void
    {
        $result = app(RequirementCheck::class)->compare(Opisowy15Fixture::requirement(1), $this->databaseCard());

        foreach ($result['groups'] as $group) {
            foreach ($group['rows'] as $row) {
                $this->assertNotSame('', $row['required']['quote'], "{$row['key']}: cytat z wymagania");
                foreach ($row['card'] as $finding) {
                    $this->assertNotSame('', $finding['quote'], "{$row['key']}: cytat z karty");
                    if ($finding['find'] !== null) {
                        $this->assertStringContainsStringIgnoringCase($finding['find'], $finding['quote'].' '.$this->cardText(), "{$row['key']}: fraza do szukania jest na karcie");
                    }
                }
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function statuses(Product $product): array
    {
        $out = [];
        foreach (app(RequirementCheck::class)->compare(Opisowy15Fixture::requirement(1), $product)['groups'] as $group) {
            foreach ($group['rows'] as $row) {
                $out[$row['key']] = $row['status'];
            }
        }

        return $out;
    }

    /**
     * Brakujący wiersz zgłaszamy jako „brak wiersza”, żeby asercja pokazała go obok statusów.
     *
     * @param  array<string, string>  $statuses
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function only(array $statuses, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $statuses[$key] ?? 'brak wiersza';
        }

        return $out;
    }

    private function databaseCard(): Product
    {
        $card = $this->databaseCardData();
        unset($card['_meta']);

        return (new Product)->forceFill(['id' => 900001] + $card);
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseCardData(): array
    {
        $path = base_path('tests/Fixtures/catalog/requirement-check/11202000-db.json');

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function cardText(): string
    {
        $card = $this->databaseCardData();

        return implode(' ', [
            (string) $card['name'],
            (string) $card['norms'],
            (string) $card['description'],
            json_encode($card['enrichment_payload'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);
    }
}
