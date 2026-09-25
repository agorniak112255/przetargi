<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Limit P8 (decyzja właściciela z 25.09.2026): numer normy z warunku zrozumienia, którego karta nigdzie nie podaje,
 * obniża ocenę modelu do 50 — model potrafił „zmyślić” normę w uzasadnieniu (rękawice chemoodporne ESD, EN 374).
 * Limit działa tylko wtedy, gdy numer ma co najmniej jedna oceniana karta.
 */
final class ProductAiSearchNormEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_card_without_constraint_norm_is_capped_when_another_card_has_it(): void
    {
        $silent = $this->card('REK-BEZ-374', 'Rękawice nitrylowe chemoodporne', 'Rękawice nitrylowe odporne na oleje i smary, EN 388.');
        $proven = $this->card('REK-Z-374', 'Rękawice nitrylowe chemoodporne', 'Rękawice nitrylowe, EN ISO 374-1:2016/Typ A, EN 388.');
        $this->stubRanking('rękawice chemoodporne', ['EN ISO 374-1'], [
            ['id' => $silent->id, 'score' => 95, 'reason' => 'Rękawice chemoodporne EN 374.', 'missing_key' => []],
            ['id' => $proven->id, 'score' => 95, 'reason' => 'Rękawice chemoodporne EN 374.', 'missing_key' => []],
        ]);

        $rows = $this->search('Rękawice ochronne chemoodporne nitrylowe EN ISO 374-1');

        $this->assertSame(95, $this->percent($rows, 'REK-Z-374'));
        $this->assertSame(50, $this->percent($rows, 'REK-BEZ-374'));
        $this->assertStringContainsString('Brak dowodu normy EN 374', (string) $rows['REK-BEZ-374']['ai_match_reason']);
        $this->assertSame('REK-Z-374', $rows->keys()->first());
    }

    public function test_no_cap_when_no_rated_card_has_the_norm(): void
    {
        $a = $this->card('REK-A', 'Rękawice nitrylowe chemoodporne', 'Rękawice nitrylowe odporne na oleje.');
        $b = $this->card('REK-B', 'Rękawice nitrylowe chemoodporne', 'Rękawice nitrylowe do chemii.');
        $this->stubRanking('rękawice chemoodporne', ['EN ISO 374-1'], [
            ['id' => $a->id, 'score' => 92, 'reason' => 'ok', 'missing_key' => []],
            ['id' => $b->id, 'score' => 90, 'reason' => 'ok', 'missing_key' => []],
        ]);

        $rows = $this->search('Rękawice ochronne chemoodporne nitrylowe EN ISO 374-1');

        $this->assertSame(92, $this->percent($rows, 'REK-A'));
        $this->assertSame(90, $this->percent($rows, 'REK-B'));
    }

    public function test_constraints_without_norm_numbers_change_nothing(): void
    {
        $a = $this->card('REK-A', 'Rękawice nitrylowe chemoodporne', 'Rękawice nitrylowe, EN ISO 374-1:2016.');
        $b = $this->card('REK-B', 'Rękawice nitrylowe chemoodporne', 'Rękawice nitrylowe do chemii.');
        $this->stubRanking('rękawice chemoodporne', ['nitryl', 'chemoodporne'], [
            ['id' => $a->id, 'score' => 92, 'reason' => 'ok', 'missing_key' => []],
            ['id' => $b->id, 'score' => 90, 'reason' => 'ok', 'missing_key' => []],
        ]);

        $rows = $this->search('Rękawice ochronne chemoodporne nitrylowe EN ISO 374-1');

        $this->assertSame(92, $this->percent($rows, 'REK-A'));
        $this->assertSame(90, $this->percent($rows, 'REK-B'));
    }

    public function test_en_60903_card_satisfies_en_50321_constraint(): void
    {
        $gloves = $this->card('REK-60903', 'Rękawice elektroizolacyjne klasa 0', 'Rękawice dielektryczne klasa 0, 1000 V, EN 60903.');
        $silent = $this->card('REK-DIEL', 'Rękawice elektroizolacyjne klasa 0', 'Rękawice dielektryczne do pracy pod napięciem.');
        $this->stubRanking('rękawice elektroizolacyjne', ['EN 50321', 'klasa 0'], [
            ['id' => $gloves->id, 'score' => 90, 'reason' => 'ok', 'missing_key' => []],
            ['id' => $silent->id, 'score' => 90, 'reason' => 'ok', 'missing_key' => []],
        ]);

        $rows = $this->search('Rękawice elektroizolacyjne klasa 0 wg EN 50321');

        $this->assertSame(90, $this->percent($rows, 'REK-60903'));
        $this->assertSame(50, $this->percent($rows, 'REK-DIEL'));
        $this->assertStringContainsString('Brak dowodu normy EN 50321', (string) $rows['REK-DIEL']['ai_match_reason']);
    }

    /**
     * SB04 AIR na produkcji: klasa S5 bez numeru „20345” — klasa obuwia bezpiecznego jest dowodem normy (F7, S21).
     */
    public function test_footwear_safety_class_is_evidence_of_en_iso_20345(): void
    {
        $classOnly = $this->card('TRZ-S3', 'Trzewiki bezpieczne S3 SRC', 'Trzewik skórzany z podnoskiem, podeszwa PU/TPU.', 'Obuwie');
        $classInPayload = $this->card('TRZ-PAYLOAD', 'Trzewiki bezpieczne skórzane', 'Trzewik skórzany z podnoskiem kompozytowym.', 'Obuwie', [
            'attributes' => ['klasa_ochrony' => 'S3'],
        ]);
        $numbered = $this->card('TRZ-20345', 'Trzewiki bezpieczne skórzane', 'Trzewik EN ISO 20345:2011, podnosek stalowy.', 'Obuwie');
        $silent = $this->card('TRZ-BEZ', 'Trzewiki bezpieczne skórzane', 'Trzewik skórzany z podnoskiem stalowym.', 'Obuwie');
        // Zapytanie bez klasy: klasa w zapytaniu uruchamia bramkę klasy obuwia, która odrzuca karty bez S3 wcześniej.
        $this->stubRanking('trzewiki bezpieczne', ['EN ISO 20345 S3'], [
            ['id' => $classOnly->id, 'score' => 90, 'reason' => 'ok', 'missing_key' => []],
            ['id' => $classInPayload->id, 'score' => 90, 'reason' => 'ok', 'missing_key' => []],
            ['id' => $numbered->id, 'score' => 90, 'reason' => 'ok', 'missing_key' => []],
            ['id' => $silent->id, 'score' => 90, 'reason' => 'ok', 'missing_key' => []],
        ], 'trzewiki');

        $rows = $this->search('Trzewiki bezpieczne skórzane wg EN ISO 20345');

        $this->assertSame(90, $this->percent($rows, 'TRZ-S3'));
        $this->assertSame(90, $this->percent($rows, 'TRZ-PAYLOAD'));
        $this->assertSame(90, $this->percent($rows, 'TRZ-20345'));
        $this->assertSame(50, $this->percent($rows, 'TRZ-BEZ'));
    }

    public function test_occupational_class_is_not_evidence_of_safety_footwear_norm(): void
    {
        $occupational = $this->card('POL-O2', 'Półbuty robocze O2', 'Półbut zawodowy bez podnoska, EN ISO 20347.', 'Obuwie');
        $safety = $this->card('POL-S1', 'Półbuty robocze S1', 'Półbut bezpieczny, EN ISO 20345.', 'Obuwie');
        $this->stubRanking('półbuty robocze', ['EN ISO 20345'], [
            ['id' => $occupational->id, 'score' => 80, 'reason' => 'ok', 'missing_key' => []],
            ['id' => $safety->id, 'score' => 80, 'reason' => 'ok', 'missing_key' => []],
        ], 'półbuty');

        $rows = $this->search('Półbuty robocze skórzane wg EN ISO 20345');

        $this->assertSame(80, $this->percent($rows, 'POL-S1'));
        if ($rows->has('POL-O2')) {
            $this->assertSame(50, $this->percent($rows, 'POL-O2'));
        }
    }

    /**
     * Karta 3M 7100010431: soczewka „5 3M 1 B K N” bez słów „EN 166” — pełne oznaczenie soczewki jest dowodem normy (D3).
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function lensMarkings(): array
    {
        return [
            'soczewka spawalnicza 3M' => ['Gogle szczelne 3M 2895S, oznaczenie soczewki: 5 3M 1 B K N.', 90],
            'soczewka UV uvex' => ['Gogle szczelne, oznaczenie soczewki 2C-1.2 W 1 BT K N.', 90],
            'samo „1 B” w opisie' => ['Gogle szczelne, klasa optyczna 1 B, odporne na zaparowanie.', 50],
            'klasa i wytrzymałość osobno' => ['Gogle szczelne. Klasa optyczna 1, odporność mechaniczna B (120 m/s).', 50],
            'kod EN 388 ze spacjami' => ['Gogle szczelne w zestawie z rękawicami EN 388: 2 X 1 1 B.', 50],
        ];
    }

    #[DataProvider('lensMarkings')]
    public function test_full_en_166_lens_marking_is_evidence_of_en_166(string $description, int $expected): void
    {
        $marked = $this->card('GOG-TEST', 'Gogle ochronne szczelne', $description, 'Ochrona oczu');
        $proven = $this->card('GOG-166', 'Gogle ochronne szczelne', 'Gogle szczelne, EN 166, odporność B.', 'Ochrona oczu');
        $this->stubRanking('gogle ochronne', ['EN 166', 'odporność na uderzenia 120 m/s'], [
            ['id' => $marked->id, 'score' => 90, 'reason' => 'ok', 'missing_key' => []],
            ['id' => $proven->id, 'score' => 90, 'reason' => 'ok', 'missing_key' => []],
        ], 'gogle');

        $rows = $this->search('Gogle ochronne szczelne EN 166 odporne na uderzenia 120 m/s');

        $this->assertSame(90, $this->percent($rows, 'GOG-166'));
        $this->assertSame($expected, $this->percent($rows, 'GOG-TEST'));
    }

    public function test_manufacturer_norms_are_evidence(): void
    {
        $marked = $this->card('GOG-PROD', 'Gogle ochronne szczelne', 'Gogle szczelne z powłoką przeciwmgielną.', 'Ochrona oczu');
        $marked->forceFill(['manufacturer_norms' => ['rows' => [['label' => 'EN 166', 'value' => '1 B']]]])->save();
        $proven = $this->card('GOG-166', 'Gogle ochronne szczelne', 'Gogle szczelne, EN 166.', 'Ochrona oczu');
        $this->stubRanking('gogle ochronne', ['EN 166'], [
            ['id' => $marked->id, 'score' => 90, 'reason' => 'ok', 'missing_key' => []],
            ['id' => $proven->id, 'score' => 90, 'reason' => 'ok', 'missing_key' => []],
        ], 'gogle');

        $rows = $this->search('Gogle ochronne szczelne EN 166');

        $this->assertSame(90, $this->percent($rows, 'GOG-PROD'));
    }

    /**
     * Remis 50%: karta obcięta kodem i karta, którą model sam ocenił na 50, mają ten sam procent. Remis rozstrzyga
     * cena (b0165e1 z pierwszeństwem oceny modelu wycofany w 6a91569) — limit nie daje ani nie odbiera pierwszeństwa.
     */
    public function test_capped_card_ties_with_model_fifty_and_price_decides(): void
    {
        $capped = $this->card('REK-OBCIETA', 'Rękawice nitrylowe chemoodporne', 'Rękawice nitrylowe do chemii.', purchase: 30);
        $modelFifty = $this->card('REK-MODEL-50', 'Rękawice nitrylowe chemoodporne', 'Rękawice nitrylowe, EN ISO 374-1:2016.', purchase: 20);
        $this->stubRanking('rękawice chemoodporne', ['EN ISO 374-1'], [
            ['id' => $capped->id, 'score' => 95, 'reason' => 'ok', 'missing_key' => []],
            ['id' => $modelFifty->id, 'score' => 50, 'reason' => 'ok', 'missing_key' => []],
        ]);

        $rows = $this->search('Rękawice ochronne chemoodporne nitrylowe EN ISO 374-1');

        $this->assertSame(50, $this->percent($rows, 'REK-OBCIETA'));
        $this->assertSame(50, $this->percent($rows, 'REK-MODEL-50'));
        $this->assertSame(['REK-MODEL-50', 'REK-OBCIETA'], $rows->keys()->all(), 'tańsza karta pierwsza, obcięta kodem nie wyprzedza');
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    /** Przegląd 25.09.2026: SIWZ cytuje stary numer (EN 420), karta nowy (EN ISO 21420) — to ta sama norma. */
    public function test_newer_edition_of_the_norm_meets_old_number(): void
    {
        $newer = $this->card('REK-21420', 'Rękawice nitrylowe chemoodporne', 'Rękawice nitrylowe, EN ISO 21420:2020, EN 388.');
        $older = $this->card('REK-420', 'Rękawice nitrylowe chemoodporne', 'Rękawice nitrylowe, EN 420, EN 388.');
        $this->stubRanking('rękawice chemoodporne', ['EN 420', 'EN 388'], [
            ['id' => $newer->id, 'score' => 95, 'reason' => 'ok', 'missing_key' => []],
            ['id' => $older->id, 'score' => 95, 'reason' => 'ok', 'missing_key' => []],
        ]);

        $rows = $this->search('Rękawice ochronne chemoodporne nitrylowe EN 420 i EN 388');

        $this->assertSame(95, $this->percent($rows, 'REK-21420'));
        $this->assertSame(95, $this->percent($rows, 'REK-420'));
    }

    /** „EN 374 lub EN 455” — wystarczy jedna z norm. */
    public function test_alternative_norms_are_not_each_required(): void
    {
        $a = $this->card('REK-374', 'Rękawice nitrylowe chemoodporne', 'Rękawice nitrylowe, EN ISO 374-1:2016.');
        $b = $this->card('REK-455', 'Rękawice nitrylowe chemoodporne', 'Rękawice nitrylowe medyczne, EN 455.');
        $this->stubRanking('rękawice nitrylowe', ['EN 374 lub EN 455'], [
            ['id' => $a->id, 'score' => 92, 'reason' => 'ok', 'missing_key' => []],
            ['id' => $b->id, 'score' => 90, 'reason' => 'ok', 'missing_key' => []],
        ]);

        $rows = $this->search('Rękawice nitrylowe chemoodporne EN 374 lub EN 455');

        $this->assertSame(92, $this->percent($rows, 'REK-374'));
        $this->assertSame(90, $this->percent($rows, 'REK-455'));
    }

    /** Norma dopisana przez model w warunkach, a nie przez klienta, nie obcina kart. */
    public function test_norm_only_in_model_constraints_is_not_enforced(): void
    {
        $silent = $this->card('REK-BEZ', 'Rękawice nitrylowe chemoodporne', 'Rękawice nitrylowe odporne na oleje.');
        $proven = $this->card('REK-Z', 'Rękawice nitrylowe chemoodporne', 'Rękawice nitrylowe, EN ISO 374-1:2016.');
        $this->stubRanking('rękawice chemoodporne', ['EN ISO 374-1'], [
            ['id' => $silent->id, 'score' => 95, 'reason' => 'ok', 'missing_key' => []],
            ['id' => $proven->id, 'score' => 95, 'reason' => 'ok', 'missing_key' => []],
        ]);

        $rows = $this->search('Rękawice ochronne chemoodporne nitrylowe');

        $this->assertSame(95, $this->percent($rows, 'REK-BEZ'));
    }

    /** Litera przecięcia w kodzie EN 388:2016 to wynik wg EN ISO 13997. */
    public function test_en_388_cut_letter_is_evidence_of_iso_13997(): void
    {
        $code = $this->card('CUT-388', 'Rękawice antyprzecięciowe powlekane', 'Rękawice antyprzecięciowe HPPE, EN 388:2016 4X44F.');
        $iso = $this->card('CUT-ISO', 'Rękawice antyprzecięciowe powlekane', 'Rękawice antyprzecięciowe, odporność na przecięcie wg EN ISO 13997: F.');
        $this->stubRanking('rękawice antyprzecięciowe', ['poziom F wg EN ISO 13997'], [
            ['id' => $code->id, 'score' => 95, 'reason' => 'ok', 'missing_key' => []],
            ['id' => $iso->id, 'score' => 95, 'reason' => 'ok', 'missing_key' => []],
        ]);

        $rows = $this->search('Rękawice ochronne antyprzecięciowe powlekane, poziom F wg EN ISO 13997');

        $this->assertSame(95, $this->percent($rows, 'CUT-388'));
    }

    private function search(string $query): Collection
    {
        return collect(
            $this->postJson('/api/products/ai-search', ['query' => $query, 'limit' => 10])
                ->assertOk()
                ->json('products')
        )->keyBy('sku');
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $rows
     */
    private function percent(Collection $rows, string $sku): int
    {
        $this->assertTrue($rows->has($sku), $sku.' wypadła z wyniku: '.json_encode($rows->map(static fn (array $r): mixed => $r['ai_match_percent'] ?? null)->all()));

        return (int) $rows[$sku]['ai_match_percent'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function card(string $sku, string $name, string $description, string $category = 'Rękawice', array $payload = [], float $purchase = 40): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'TEST',
            'category' => $category,
            'description' => $description,
            'enrichment_payload' => $payload,
            'catalog_price_net' => 60,
            'purchase_price' => $purchase,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $constraints
     * @param  list<array<string, mixed>>  $matches
     */
    private function stubRanking(string $needed, array $constraints, array $matches, ?string $phrase = null): void
    {
        $phrases = array_values(array_unique([$needed, $phrase ?? $needed]));
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static function (array $messages) use ($needed, $phrases, $constraints, $matches): array {
            $system = (string) ($messages[0]['content'] ?? '');
            $intent = ['needed' => $needed, 'search_phrases' => $phrases, 'constraints' => $constraints];

            return str_contains($system, '"matches"') ? $intent + ['matches' => $matches] : $intent;
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }
}
