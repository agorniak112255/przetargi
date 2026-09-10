<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentBatchItem;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\EnrichmentLiveProgress;
use App\Services\Enrichment\ProductEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class EnrichmentLiveProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_waiting_and_done_write_model_and_duration_to_batch_log(): void
    {
        [$batch, $product] = $this->makeRunningBatch();
        $live = app(EnrichmentLiveProgress::class);
        $live->bind($batch->id, $product);
        $live->step('filtr stron');
        $live->waiting('google/gemini-2.5-flash', 'OpenRouter');

        $batch->refresh();
        $item = ProductEnrichmentBatchItem::query()
            ->where('batch_id', $batch->id)
            ->where('product_id', $product->id)
            ->first();
        $this->assertStringContainsString('OpenRouter', (string) $batch->message);
        $this->assertStringContainsString('filtr stron', (string) $batch->message);
        $this->assertStringContainsString('google/gemini-2.5-flash', (string) $batch->message);
        $this->assertStringContainsString('czeka', (string) $batch->message);
        $this->assertSame($batch->message, $item?->message);

        $live->done('google/gemini-2.5-flash', 12.4, 800, 120);
        $batch->refresh();
        $item?->refresh();
        $this->assertStringContainsString('12,4 s', (string) $batch->message);
        $this->assertStringContainsString('tok/s', (string) $batch->message);
        $this->assertSame($batch->message, $item?->message);
    }

    public function test_ai_call_writes_openrouter_duration_when_progress_is_bound(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'openai/gpt-4o',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
            'web_search_enabled' => false,
        ]);
        [$batch, $product] = $this->makeRunningBatch();
        $live = app(EnrichmentLiveProgress::class);
        $live->bind($batch->id, $product);
        $live->step('opis produktu');

        Http::fake([
            '*' => Http::response([
                'id' => 'gen-1',
                'model' => 'openai/gpt-4o',
                'choices' => [[
                    'message' => ['content' => '{"ok":true}'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 40,
                ],
            ]),
        ]);

        app(OpenAiCompatibleClient::class)->chatJsonEnrichment([
            ['role' => 'user', 'content' => 'x'],
        ]);

        $batch->refresh();
        $this->assertStringContainsString('OpenRouter', (string) $batch->message);
        $this->assertStringContainsString('opis produktu', (string) $batch->message);
        $this->assertStringContainsString('openai/gpt-4o', (string) $batch->message);
        $this->assertMatchesRegularExpression('/\d+,\d s|\d+ s/', (string) $batch->message);
        $this->assertStringNotContainsString('czeka', (string) $batch->message);
    }

    public function test_unbound_ai_call_does_not_change_batch_message(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'openai/gpt-4o',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
            'web_search_enabled' => false,
        ]);
        [$batch] = $this->makeRunningBatch();
        $batch->update(['message' => 'Prefetch źródeł (wyszukiwarka)…']);

        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"ok":true}'],
                    'finish_reason' => 'stop',
                ]],
            ]),
        ]);

        app(OpenAiCompatibleClient::class)->chatJsonEnrichment([
            ['role' => 'user', 'content' => 'x'],
        ]);

        $this->assertSame('Prefetch źródeł (wyszukiwarka)…', $batch->fresh()?->message);
    }

    /**
     * @return array{0: ProductEnrichmentBatch, 1: Product}
     */
    private function makeRunningBatch(): array
    {
        $product = Product::query()->create([
            'sku' => 'AURA9322+GEN3',
            'name' => 'Aura 9322',
            'manufacturer' => '3M',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_RUNNING,
        ]);
        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCTS,
            'scope_id' => 15,
            'total' => 10,
            'done' => 0,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_RUNNING,
            'force' => false,
            'current_sku' => $product->sku,
            'message' => 'Prefetch źródeł (wyszukiwarka)…',
        ]);
        app(ProductEnrichmentService::class)->recordBatchProduct(
            $batch,
            $product,
            ProductEnrichmentBatchItem::STATUS_RUNNING,
            'Prefetch źródeł (wyszukiwarka)…',
        );

        return [$batch, $product];
    }
}
