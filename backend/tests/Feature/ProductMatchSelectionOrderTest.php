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
     * Pomiar 20260914_200209 poz. 6: przetarg wymaga EN 166, EN 172 i EN ISO 16321-1. Bollé SWIFTN20E (tylko EN 166) wygrała
     * ceną z Bollé RUSHPTWI, która podaje wszystkie trzy normy, przy ocenie 99 modelu dla obu.
     */
    public function test_card_showing_all_required_norms_beats_cheaper_card_missing_one(): void
    {
        $requirement = 'Okulary ochronne z przyciemnianymi (smoke) soczewkami poliwęglanowymi. Zgodność z EN 166, EN 172 oraz EN ISO 16321-1; '
            .'filtr przeciwsłoneczny o stopniu zaciemnienia 3, klasa optyczna 1.';
        $rush = $this->card('RUSHPTWI', 48.0, 'EN166 – ochrona oczu, EN172 – filtry przeciwsłoneczne do użytku przemysłowego, EN ISO 16321-1 – indywidualna ochrona wzroku');
        $swift = $this->card('SWIFTN20E', 19.5, 'EN166 – ochrona oczu, wymagania ogólne, Klasa F – odporność na uderzenie (0,86 g, 45 m/s)');
        $service = app(ProductMatchService::class);
        $shows = new \ReflectionMethod($service, 'showsAllRequiredNorms');

        $this->assertTrue($shows->invoke($service, $requirement, $rush));
        $this->assertFalse($shows->invoke($service, $requirement, $swift));
        $this->assertTrue($shows->invoke($service, 'Okulary ochronne przyciemniane do pracy w słońcu', $swift), 'przetarg bez norm');

        $picked = $this->pick([
            $this->option($swift, 99, 90, $shows->invoke($service, $requirement, $swift)),
            $this->option($rush, 99, 90, $shows->invoke($service, $requirement, $rush)),
        ]);

        $this->assertSame('RUSHPTWI', $picked, 'komplet norm z przetargu przed ceną');
    }

    public function test_newer_iso_21420_satisfies_en_420_and_price_decides_without_complete_card(): void
    {
        $service = app(ProductMatchService::class);
        $shows = new \ReflectionMethod($service, 'showsAllRequiredNorms');
        $new = $this->card('NEW-21420', 20, 'EN ISO 21420:2020, EN 388:2016 4X42C');
        $this->assertTrue($shows->invoke($service, 'Rękawice powlekane, zgodne z EN 420 i EN 388', $new));

        $dearer = $this->card('A-1', 50);
        $cheaper = $this->card('B-1', 30);
        $picked = $this->pick([
            $this->option($dearer, 95, 60, false),
            $this->option($cheaper, 95, 60, false),
        ]);
        $this->assertSame('B-1', $picked, 'żadna karta bez kompletu norm — decyduje cena');
    }

    public function test_norm_completeness_does_not_override_higher_model_tier(): void
    {
        $complete = $this->card('C-90', 10);
        $partial = $this->card('P-99', 40);

        $picked = $this->pick([
            $this->option($complete, 90, 90, true),
            $this->option($partial, 99, 90, false),
        ]);

        $this->assertSame('P-99', $picked, 'normy rozstrzygają tylko w oknie remisu ocen modelu');
    }

    /** @param  list<array{product: Product, score: int, source: string, evidence: int, hard: int}>  $options */
    private function pick(array $options): string
    {
        $service = app(ProductMatchService::class);
        $picked = (new \ReflectionMethod($service, 'preferCheapestAmongCloseScores'))->invoke($service, $options);

        return (string) $picked['product']->sku;
    }

    /** @return array{product: Product, score: int, source: string, evidence: int, hard: int, norms_complete: bool} */
    private function option(Product $product, int $score, int $evidence, bool $normsComplete = true): array
    {
        return ['product' => $product, 'score' => $score, 'source' => 'ai', 'evidence' => $evidence, 'hard' => 0, 'norms_complete' => $normsComplete];
    }

    private function card(string $sku, float $price, ?string $norms = null): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Karta '.$sku,
            'manufacturer' => 'X',
            'norms' => $norms,
            'description' => 'Karta testowa z opisem dłuższym niż dwadzieścia cztery znaki.',
            'catalog_price_net' => $price,
            'purchase_price' => $price,
            'currency' => 'PLN',
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
    }
}
