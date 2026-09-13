<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AiSetting;
use App\Models\Product;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Services\ProductMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeSearchLlm;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Wybór kandydata w dopasowaniu przetargu (pickAuto / resolveBestPick i okno kandydatów AI):
 * karta wygrywa dowodami z karty, nie ceną przy płaskim procencie; wiersz listy katalogowej
 * bez zgody admina nie dochodzi do zapisu; okno kandydatów obejmuje 10 pierwszych wierszy
 * odpowiedzi wyszukiwarki w jej kolejności. Model zastąpiony stubem — bez wywołań AI.
 */
final class ProductMatchPickTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Poz. 13 bez liczb (°C, rok normy) — te do scalenia W1 dają igłę „modelu” i przełączają
     * wyszukiwarkę w tryb zakotwiczony na modelu, a tu badamy samo okno kandydatów.
     */
    private const REUSABLE_HALF_MASK = 'Półmaska wielokrotnego użytku do ochrony układu oddechowego, korpus z dwoma '
        .'zaworami wdechowymi z łącznikami bagnetowymi, zawór wydechowy z pokrywą, jednoczęściowe nagłowie tekstylne';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    // ------------------------------------------------------------ okno i kolejność kandydatów

    /**
     * Poz. 13 (półmaska wielokrotnego użytku): wyszukiwarka miała SECURA 3000 na 8. miejscu
     * z 16, a przetarg brał 5 pierwszych wierszy — właściwa karta nigdy nie była kandydatem.
     */
    #[Test]
    public function candidate_window_keeps_eighth_search_row_for_reusable_half_mask(): void
    {
        $this->settings();
        $ids = Opisowy15Fixture::seed(['9310+', '9312+', '9914', '7100329384', 'S56T0SM0']);
        $ids += $this->respirators([
            '7100113104' => 'Niewymagająca konserwacji półmaska wielokrotnego użytku 3M, FFP2',
            '9320+' => '3M Aura półmaska filtrująca, FFP2, bez zaworu, 9320+',
            '9322+' => '3M Aura półmaska filtrująca, FFP2, z zaworem, 9322+',
            'AURA 9322+GEN3' => '3M Aura półmaska filtrująca, FFP2, z zaworem, 9322+Gen3',
            '6581-EN' => 'Półmaska wielokrotnego użytku 3M, mechanizm szybkozatrzaskowy, 6500',
            '9926' => '3M Półmaska filtrująca 9926, specjalistyczna, z zaworem, FFP2',
        ]);
        // Kolejność odpowiedzi wyszukiwarki z produkcji (r_12.json): SECURA 3000 jako 8. wiersz.
        // Malejący procent utrwala tę kolejność w rankingu wyszukiwarki (procent, potem cena).
        $order = ['9310+', '7100113104', '9312+', '9320+', '9322+', 'AURA 9322+GEN3', '6581-EN',
            'S56T0SM0', '9914', '7100329384', '9926'];
        $matches = [];
        foreach ($order as $i => $sku) {
            $matches[] = ['id' => $ids[$sku], 'score' => 72 - $i, 'reason' => 'stub: półmaska'];
        }
        $this->stubSearchModel($matches);

        $matcher = app(ProductMatchService::class);
        $this->invoke($matcher, 'prefetchAiCandidates', [self::REUSABLE_HALF_MASK]);
        $candidates = $this->invoke($matcher, 'aiTopCandidates', self::REUSABLE_HALF_MASK);

        $skus = array_column($candidates, 'sku');
        $this->assertContains('S56T0SM0', $skus, 'SECURA 3000 (8. wiersz odpowiedzi) musi być kandydatem');
        $this->assertNotContains('9926', $skus, 'okno obejmuje 10 pierwszych wierszy odpowiedzi');
        $this->assertSame(array_slice($order, 0, 10), $skus, 'kolejność kandydatów = kolejność odpowiedzi wyszukiwarki');
    }

    #[Test]
    public function search_order_is_kept_and_catalog_rows_follow_model_rows(): void
    {
        $matcher = app(ProductMatchService::class);
        $rows = [
            ['id' => 1, 'sku' => 'A', 'ai_match_percent' => 80],
            ['id' => 2, 'sku' => 'B', 'ai_match_percent' => 90, 'ai_match_source' => ProductAiSearchService::MATCH_SOURCE_CATALOG],
            ['id' => 3, 'sku' => 'C', 'ai_match_percent' => 70],
            ['id' => 4, 'sku' => 'D', 'ai_match_percent' => 60, 'ai_match_source' => ProductAiSearchService::MATCH_SOURCE_CATALOG],
        ];

        $mapped = $this->invoke($matcher, 'mapAiSearchRows', $rows, 10, 'ai');

        $this->assertSame(['A', 'C', 'B', 'D'], array_column($mapped, 'sku'));
        $this->assertSame(['ai', 'ai', 'catalog', 'catalog'], array_column($mapped, 'source'));
        $this->assertSame(['A', 'C', 'B'], array_column($this->invoke($matcher, 'mapAiSearchRows', $rows, 3, 'ai'), 'sku'));
    }

    // ------------------------------------------------------------------------- pomocnicze

    /**
     * @param  array<string, mixed>  $extra
     */
    private function settings(array $extra = []): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
            ...$extra,
        ]);
    }

    /**
     * Stub modelu dla fali przetargu (searchMany → chatJsonMany): „zrozum” bez odpowiedzi
     * (intencja lokalna), ranking z podanymi trafieniami. Pojedyncze wyszukiwanie (chatJson)
     * jest zakazane — oznaczałoby chybienie cache między prefetch a odczytem kandydatów.
     *
     * @param  list<array{id: int, score: int, reason: string}>  $matches
     */
    private function stubSearchModel(array $matches): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static function (array $messageSets) use ($matches): array {
            $out = [];
            foreach ($messageSets as $messages) {
                $out[] = FakeSearchLlm::kind($messages) === FakeSearchLlm::KIND_RANK ? ['matches' => $matches] : [];
            }

            return $out;
        });
        $llm->shouldNotReceive('chatJson');
        $llm->shouldNotReceive('chat');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    /**
     * Karty półmasek spoza fixture (z odpowiedzi wyszukiwarki poz. 13) — tylko rodzaj i nazwa.
     *
     * @param  array<string, string>  $bySku  sku => nazwa
     * @return array<string, int> sku => id
     */
    private function respirators(array $bySku): array
    {
        $ids = [];
        $price = 1.0;
        foreach ($bySku as $sku => $name) {
            $product = Product::query()->create([
                'sku' => $sku,
                'name' => $name,
                'manufacturer' => '3M',
                'category' => 'Ochrona dróg oddechowych',
                'description' => $name.' — ochrona układu oddechowego przed aerozolami.',
                'catalog_price_net' => $price,
                'purchase_price' => $price,
                'currency' => 'PLN',
                'stock' => 5,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
            $price += 1.0;
            $ids[$sku] = (int) $product->id;
        }

        return $ids;
    }

    private function invoke(ProductMatchService $matcher, string $method, mixed ...$args): mixed
    {
        return (fn () => $this->{$method}(...$args))->call($matcher);
    }
}
