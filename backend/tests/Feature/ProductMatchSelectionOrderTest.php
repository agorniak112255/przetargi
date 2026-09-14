<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wybór między kartami, które przeszły progi: ocena modelu → weto dowodów ze słów przy dużej różnicy → twarde
 * dowody → cena. Przetarg 1 poz. 2 (tenders:debug-match): model dał 95 osiemnastu rękawicom antyprzecięciowym,
 * a wygrywała karta z najdłuższym opisem zamiast pasującej i najtańszej.
 */
final class ProductMatchSelectionOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_equal_model_scores_pick_cheaper_card_over_richer_description(): void
    {
        $chemical = $this->card('76-833', 59.24);
        $canis = $this->card('3630-024-700-00', 6.09);
        $thin = $this->card('16500100', 13.83);

        $picked = $this->pick([
            $this->option($chemical, 95, 98),
            $this->option($canis, 95, 81),
            $this->option($thin, 95, 40),
        ]);

        $this->assertSame('3630-024-700-00', $picked, 'najdłuższy opis nie wygrywa z tańszą kartą o równej ocenie modelu');
    }

    public function test_large_word_evidence_gap_still_vetoes_cheap_card(): void
    {
        $insulating = $this->card('T5912100', 200);
        $esd = $this->card('ART 702 Air 6660 OB A E FO', 100);

        $picked = $this->pick([
            $this->option($insulating, 92, 99),
            $this->option($esd, 92, 35),
        ]);

        $this->assertSame('T5912100', $picked, 'karta z dowodami 35 nie wygrywa ceną z kartą z dowodami 99');
    }

    public function test_higher_model_tier_beats_cheaper_card_with_same_evidence(): void
    {
        $atg = $this->card('44-304', 32.84);
        $mapa = $this->card('34580008', 17.90);

        $picked = $this->pick([
            $this->option($atg, 95, 99),
            $this->option($mapa, 90, 99),
        ]);

        $this->assertSame('44-304', $picked, '95 i 90 modelu to różne poziomy — cena nie przestawia oceny');
    }

    public function test_model_scores_within_margin_are_a_tie_decided_by_price(): void
    {
        $dearer = $this->card('A-96', 50);
        $cheaper = $this->card('B-93', 30);

        $picked = $this->pick([
            $this->option($dearer, 96, 60),
            $this->option($cheaper, 93, 60),
        ]);

        $this->assertSame('B-93', $picked);
    }

    /**
     * Poz. 8 z produkcji (14.09): model dał 95 obu półmaskom FFP1 — 9914 z węglem aktywnym (potwierdza „pary organiczne”)
     * i 9312+ bez węgla; dowody ze słów równe (99). Rozstrzygają potwierdzone warunki, nie cena 0,88 EUR wobec 305 EUR
     * (karton) — a rozrzut cen ponad 20× oznacza pozycję ostrzeżeniem.
     */
    public function test_more_confirmed_conditions_beat_price_at_equal_model_score_and_flag_pack_price(): void
    {
        $carbon = $this->card('9914', 1318.45);
        $plain = $this->card('9312+', 3.80);

        $picked = $this->pickFull([
            $this->option($plain, 95, 99, 4),
            $this->option($carbon, 95, 99, 5),
        ]);

        $this->assertSame('9914', (string) $picked['product']->sku);
        $this->assertTrue($picked['price_suspect'], 'ceny równo ocenionych kart różnią się ponad 20-krotnie');
    }

    /**
     * Poz. 15 z produkcji: zakazana 87-063 (0,75 mm, 320 mm) dostała 95, a właściwa 87-320 90. 87-320 potwierdza
     * wszystkie 4 warunki, 87-063 tylko 3 — pretendent do 10 pkt niżej z większą liczbą warunków zostaje w grze.
     */
    public function test_challenger_with_more_confirmed_conditions_beats_higher_model_tier(): void
    {
        $forbidden = $this->card('87063100BP', 8.39);
        $bulk = $this->card('87320100-BULK', 6.79);
        $pair = $this->card('87320100-PAIR', 6.79);

        $picked = $this->pickFull([
            $this->option($forbidden, 95, 36, 3),
            $this->option($bulk, 90, 51, 4),
            $this->option($pair, 90, 36, 4),
        ]);

        $this->assertSame('87320100-BULK', (string) $picked['product']->sku);
        $this->assertFalse($picked['price_suspect']);
    }

    /** Poz. 7: KRYTECH 580 (90) potwierdza tyle samo warunków co 44-304 (95) — nie jest pretendentem, wygrywa poziom modelu. */
    public function test_lower_tier_card_with_equal_conditions_is_not_a_challenger(): void
    {
        $atg = $this->card('44-304', 32.84);
        $mapa = $this->card('34580008', 17.90);

        $this->assertSame('44-304', $this->pick([
            $this->option($atg, 95, 99, 8),
            $this->option($mapa, 90, 99, 8),
        ]));
    }

    /** Poz. 2 (ogólne wymaganie, wszystkie karty 95 i 1/1 warunków): weto dowodów zdejmuje ULTRANE (39), cena wybiera Canis. */
    public function test_generic_requirement_equal_conditions_fall_back_to_price_after_evidence_veto(): void
    {
        $chemical = $this->card('76-833', 59.24);
        $canis = $this->card('3630-024-700-00', 6.09);
        $ultrane = $this->card('34681008', 8.39);

        $picked = $this->pickFull([
            $this->option($chemical, 95, 98, 1),
            $this->option($ultrane, 95, 39, 1),
            $this->option($canis, 95, 81, 1),
        ]);

        $this->assertSame('3630-024-700-00', (string) $picked['product']->sku);
        $this->assertFalse($picked['price_suspect'], 'rozrzut 59 zł wobec 6 zł to prawdziwe rękawice, nie karton');
    }

    /** @param  list<array{product: Product, score: int, source: string, evidence: int, hard: int, hits: int}>  $options */
    private function pick(array $options): string
    {
        return (string) $this->pickFull($options)['product']->sku;
    }

    /**
     * @param  list<array{product: Product, score: int, source: string, evidence: int, hard: int, hits: int}>  $options
     * @return array{product: Product, score: int, source: string, price_suspect: bool}
     */
    private function pickFull(array $options): array
    {
        $service = app(ProductMatchService::class);

        return (new \ReflectionMethod($service, 'preferCheapestAmongCloseScores'))->invoke($service, $options);
    }

    /** @return array{product: Product, score: int, source: string, evidence: int, hard: int, hits: int} */
    private function option(Product $product, int $score, int $evidence, int $hits = 0): array
    {
        return ['product' => $product, 'score' => $score, 'source' => 'ai', 'evidence' => $evidence, 'hard' => 0, 'hits' => $hits];
    }

    private function card(string $sku, float $price): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Karta '.$sku,
            'manufacturer' => 'X',
            'description' => 'Karta testowa z opisem dłuższym niż dwadzieścia cztery znaki.',
            'catalog_price_net' => $price,
            'purchase_price' => $price,
            'currency' => 'PLN',
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
    }
}
