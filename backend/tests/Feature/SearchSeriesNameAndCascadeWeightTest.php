<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductAiSearchService;
use App\Support\CatalogCascadeRecall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Audyt wyszukiwania 21.09.2026 (zapytania z serwera):
 * — „Rękawice Ultrane”: krok „zrozum” zwrócił samo „rękawice”, zapisane zrozumienie utrwaliło błąd; w puli 3 z 31 kart
 *   Ultrane, do modelu żadna, model dał 90% innej rękawicy.
 * — MEFISTO: lista kaskady „rękawice + dowolne słowo” (497 kart po dacie opisu) ważyła 2,0 i wypychała z puli karty,
 *   które wyszukiwanie po tekście stawiało na 11. i 15. miejscu.
 */
final class SearchSeriesNameAndCascadeWeightTest extends TestCase
{
    use RefreshDatabase;

    public function test_series_name_dropped_by_the_model_goes_back_to_the_search_phrases(): void
    {
        $this->card('MAPA-500', 'Rękawice MAPA Ultrane 500', 'Rękawice nitrylowe na podkładzie bawełnianym.');
        $this->card('MAPA-553', 'Rękawice MAPA Ultrane 553', 'Rękawice powlekane nitrylem do prac w olejach.');
        $this->card('FAWA-1', 'Rękawice robocze FAWA', 'Rękawice robocze wzmacniane skórą.');

        $intent = $this->parse(
            ['needed' => 'rękawice', 'search_steps' => ['rękawice'], 'search_phrases' => ['rękawice', 'rękawice ochronne', 'rękawice robocze'], 'constraints' => []],
            'Rękawice Ultrane',
        );

        $this->assertSame('rękawice ultrane', $intent['search_phrases'][0], 'nazwa serii na początku fraz — limit tokenów tnie od końca');
        $this->assertNull($intent['model_name'], 'bez przełączania na ścieżkę nazwanego modelu');
        $this->assertSame(['rękawice'], $intent['search_steps'], 'kroki kaskady bez zmian');
    }

    public function test_ordinary_word_found_mostly_in_descriptions_is_not_treated_as_a_series(): void
    {
        $this->card('R-1', 'Rękawice nitrylowe dostawa ekspresowa', 'Rękawice nitrylowe.');
        foreach (range(1, 5) as $i) {
            $this->card('R-D'.$i, 'Rękawice robocze '.$i, 'Szybka dostawa w 24 godziny, rękawice robocze.');
        }

        $intent = $this->parse(
            ['needed' => 'rękawice nitrylowe', 'search_phrases' => ['rękawice nitrylowe'], 'constraints' => []],
            'Zakup i dostawa rękawic nitrylowych',
        );

        $this->assertSame('rękawice nitrylowe', $intent['search_phrases'][0]);
        $this->assertSame([], array_values(array_filter(
            $intent['search_phrases'],
            static fn (string $p): bool => str_contains($p, 'dostaw')
        )), '„dostawa” stoi głównie w opisach — to nie nazwa serii');
    }

    public function test_series_the_model_kept_is_not_added_twice(): void
    {
        $this->card('MAPA-500', 'Rękawice MAPA Ultrane 500', 'Rękawice nitrylowe.');

        $intent = $this->parse(
            ['needed' => 'rękawice Ultrane', 'search_phrases' => ['rękawice Ultrane', 'Ultrane 500'], 'constraints' => []],
            'Rękawice Ultrane',
        );

        $this->assertSame(['rękawice Ultrane', 'Ultrane 500'], array_slice($intent['search_phrases'], 0, 2));
        $this->assertNotContains('rękawice ultrane', $intent['search_phrases']);
    }

    public function test_broad_cascade_lists_weigh_like_the_bare_kind_noun(): void
    {
        $service = app(ProductAiSearchService::class);
        $weight = new ReflectionMethod($service, 'cascadeFusionWeight');
        $intent = ['search_steps' => ['rękawice', 'wysokotemperaturowe']];

        $this->assertSame(0.5, $weight->invoke($service, CatalogCascadeRecall::LEVEL_FAMILY_FEATURE, $intent), 'rodzaj + dowolne słowo z fraz, po dacie');
        $this->assertSame(0.5, $weight->invoke($service, CatalogCascadeRecall::LEVEL_FAMILY, $intent), 'sam rodzaj');
        $this->assertSame(2.0, $weight->invoke($service, CatalogCascadeRecall::LEVEL_FAMILY_FEATURE_BRAND, $intent), 'zawężona marką');
        $this->assertSame(2.0, $weight->invoke($service, 'steps_2', $intent), 'zawężona krokami z nazwy');
        $this->assertSame(0.5, $weight->invoke($service, 'steps_1', ['search_steps' => ['rękawice']]), 'krok to sam rzeczownik — jak dotąd');
    }

    /** Klient żąda serii, model daje wysoką ocenę karcie bez niej — kod obniża ją poniżej progu zapisu i mówi dlaczego. */
    public function test_card_without_the_requested_series_is_marked_as_another_model(): void
    {
        $ultrane = $this->card('MAPA-500', 'Rękawice MAPA Ultrane 500', 'Rękawice nitrylowe na podkładzie bawełnianym.');
        $fawa = $this->card('FAWA-1', 'Rękawice robocze FAWA', 'Rękawice robocze wzmacniane skórą.');

        $rows = $this->rank('Rękawice Ultrane', collect([$ultrane, $fawa]), [
            ['id' => $fawa->id, 'score' => 90, 'reason' => 'Zgodne z wymaganiem (rękawice ochronne).'],
            ['id' => $ultrane->id, 'score' => 95, 'reason' => 'Rękawice MAPA Ultrane.'],
        ]);

        $bySku = collect($rows)->keyBy('sku');
        $this->assertSame(95, $bySku['MAPA-500']['ai_match_percent']);
        $this->assertSame(60, $bySku['FAWA-1']['ai_match_percent'], 'poniżej progu zapisu 65');
        $this->assertStringContainsString('wymienia serię „ultrane”, a karta jej nie ma', (string) $bySku['FAWA-1']['ai_match_reason']);
    }

    public function test_series_rule_leaves_long_tender_paragraphs_and_matching_model_codes_alone(): void
    {
        $ultrane = $this->card('MAPA-500', 'Rękawice MAPA Ultrane 500', 'Rękawice nitrylowe.');
        $fawa = $this->card('FAWA-1', 'Rękawice robocze FAWA', 'Rękawice robocze.');
        $uvex = $this->card('8543/8/35', 'Półbut Uvex 1 8543/8', 'Półbut skórzany S1 SRC.');
        $casual = $this->card('UVX-BC', 'Buty uvex business casual 8540', 'Półbuty S3.');
        $matches = [['id' => $fawa->id, 'score' => 90, 'reason' => 'ok']];

        $long = $this->rank(
            'Rękawice ochronne powlekane nitrylem na podkładzie bawełnianym, do prac w olejach, typu Ultrane lub równoważne, EN 388, rozmiary 8-10',
            collect([$ultrane, $fawa]),
            $matches,
        );
        $this->assertSame(90, $long[0]['ai_match_percent'], 'akapit SIWZ — reguła serii nie działa');

        $code = $this->rank('buty uvex business casual 8543.8 s1 src', collect([$uvex, $casual]), [['id' => $uvex->id, 'score' => 95, 'reason' => 'ok']]);
        $this->assertSame(95, $code[0]['ai_match_percent'], 'kod modelu z zapytania stoi na karcie — to nie inny model');
    }

    /**
     * @param  Collection<int, Product>  $candidates
     * @param  list<array<string, mixed>>  $matches
     * @return list<array<string, mixed>>
     */
    private function rank(string $query, Collection $candidates, array $matches): array
    {
        $service = app(ProductAiSearchService::class);

        return (new ReflectionMethod($service, 'rowsFromLlmMatches'))
            ->invoke($service, $query, $candidates, ['matches' => $matches], 10, null);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function parse(array $raw, string $query): array
    {
        $service = app(ProductAiSearchService::class);

        return (new ReflectionMethod($service, 'parseIntent'))->invoke($service, $raw, $query);
    }

    private function card(string $sku, string $name, string $description): Product
    {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'TEST',
            'description' => $description,
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'ppe_family' => 'gloves',
        ]);
        $product->forceFill(['search_blob' => mb_strtolower(strtr($name.' '.$description, ['ę' => 'e', 'ó' => 'o', 'ą' => 'a', 'ś' => 's', 'ł' => 'l', 'ż' => 'z', 'ź' => 'z', 'ć' => 'c', 'ń' => 'n']))])->save();

        return $product;
    }
}
