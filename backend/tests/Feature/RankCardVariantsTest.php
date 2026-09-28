<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Ai\AiTask;
use App\Services\ProductAiSearchService;
use App\Support\ProductVariantFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Karta scalona z kilku kolorów idzie do oceny AI z krótką listą wariantów (pole variants), a polecenie mówi, że kolor
 * z tej listy spełnia warunek zwykłego koloru. Karta bez listy barw (bez wariantów albo same rozmiary) i polecenie
 * bez takiej karty zostają bez zmian (etap A łączenia wariantów kolorystycznych, 28.09.2026).
 */
final class RankCardVariantsTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Hełm ochronny G3000 w kolorze żółtym';

    public function test_card_with_colour_variants_carries_the_summary_before_constraint_evidence(): void
    {
        $card = $this->card('Hełm G3000', 'G3000');
        $this->variant($card, 'G3000CUV-RD', 'czerwony');
        $this->variant($card, 'G3000CUV-GB', 'żółty');

        $rank = $this->rankCard($card, false);

        $this->assertSame('kolory: czerwony, żółty; kody: G3000CUV-RD, G3000CUV-GB', $rank['variants'] ?? null);
        $keys = array_keys($rank);
        $this->assertSame(array_search('constraint_evidence', $keys, true) - 1, array_search('variants', $keys, true));
        $this->assertSame($rank['variants'], $this->rankCard($card, true)['variants'] ?? null, 'karta krótka też');
    }

    public function test_card_without_colour_list_has_no_variants_key(): void
    {
        $plain = $this->card('Kask', 'K-1');
        $sizes = $this->card('Spodnie', 'SP-1');
        $this->variant($sizes, 'SP-1-44', '44');
        $this->variant($sizes, 'SP-1-46', '46');

        $this->assertArrayNotHasKey('variants', $this->rankCard($plain, false));
        $this->assertArrayNotHasKey('variants', $this->rankCard($sizes, false));
    }

    public function test_prompt_names_the_variants_field_only_when_a_card_has_it(): void
    {
        $plain = $this->card('Kask', 'K-1');
        $withoutVariants = $this->content($this->messages(collect([$plain])));
        $this->assertStringNotContainsString('variants', $withoutVariants);
        $this->assertStringContainsString('/constraint_evidence/features/use_cases/description. ', $withoutVariants);

        $helmet = $this->card('Hełm G3000', 'G3000');
        $this->variant($helmet, 'G3000CUV-RD', 'czerwony');
        $this->variant($helmet, 'G3000CUV-GB', 'żółty');

        $long = $this->content($this->messages(collect([$plain, $helmet])));
        $this->assertStringContainsString('/features/use_cases/description/variants', $long);
        $this->assertStringContainsString('Pole variants = warianty tej karty do wyboru w ofercie', $long);
        $this->assertStringContainsString('wysoką widzialność (fluo, EN ISO 20471) potwierdzają wyłącznie pozostałe pola karty', $long);
        $this->assertStringContainsString('"variants":"kolory: czerwony, żółty', $long);
    }

    public function test_size_only_variants_leave_the_prompt_unchanged(): void
    {
        $card = $this->card('Spodnie', 'SP-1');
        $before = $this->messages(collect([$card]));

        $this->variant($card, 'SP-1-44', '44');
        $this->variant($card, 'SP-1-46', '46');
        app(ProductVariantFacts::class)->forget();

        $this->assertSame($before, $this->messages(collect([$card->fresh()])));
    }

    public function test_prime_loads_variants_of_all_candidates_in_one_query(): void
    {
        $cards = collect();
        for ($i = 1; $i <= 5; $i++) {
            $card = $this->card('Hełm '.$i, 'H-'.$i);
            $this->variant($card, 'H-'.$i.'-RD', 'czerwony');
            $this->variant($card, 'H-'.$i.'-GB', 'żółty');
            $cards->push($card);
        }
        app(ProductVariantFacts::class)->forget();

        DB::enableQueryLog();
        $this->messages($cards);
        $variantQueries = array_filter(
            DB::getQueryLog(),
            static fn (array $q): bool => str_contains((string) $q['query'], 'product_variants')
        );
        DB::disableQueryLog();

        $this->assertCount(1, $variantQueries);
    }

    /**
     * @return array<string, mixed>
     */
    private function rankCard(Product $product, bool $short): array
    {
        $service = app(ProductAiSearchService::class);

        /** @var array<string, mixed> $card */
        $card = (new \ReflectionMethod($service, 'rankCard'))->invoke($service, $product, $short, []);

        return $card;
    }

    /**
     * @param  Collection<int, Product>  $candidates
     * @return list<array{role: string, content: string}>
     */
    private function messages(Collection $candidates): array
    {
        $service = app(ProductAiSearchService::class);

        /** @var list<array{role: string, content: string}> $messages */
        $messages = (new \ReflectionMethod($service, 'rankMessages'))->invoke(
            $service,
            self::QUERY,
            $candidates,
            10,
            'hełm ochronny',
            ['kolor żółty'],
            AiTask::ProductSearch,
        );

        return $messages;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function content(array $messages): string
    {
        return implode("\n", array_column($messages, 'content'));
    }

    private function card(string $name, string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => 'Test', 'currency' => 'PLN',
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
    }

    private function variant(Product $card, string $sku, string $label): ProductVariant
    {
        return ProductVariant::query()->create([
            'product_id' => $card->id, 'kind' => ProductVariant::KIND_SIZE, 'source' => 'b2b:1', 'remote_id' => $sku,
            'sku' => $sku, 'label' => $label, 'purchase_price' => 10, 'currency' => 'PLN',
        ]);
    }
}
