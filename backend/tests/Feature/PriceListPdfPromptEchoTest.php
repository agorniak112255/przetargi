<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\AnalyzePriceListPdfChunkJob;
use App\Models\AiSetting;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\User;
use App\Services\PriceListAiAnalyzer;
use App\Services\PriceListImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Karta 10134 (CEDERROTH, SKU „SKU”, nazwa „nazwa”, 12,50 zł) nie była pozycją cennika: model czytający PDF przepisał
 * przykład z polecenia ["SKU","nazwa",12.5,"grupa"]. Echo przykładu i wiersz nagłówka tabeli nie zostają pozycją
 * ani w odczycie PDF, ani przy zapisie importu. Przykład „146a” pochodzi z prawdziwego cennika — zostaje, gdy jego kod
 * stoi w czytanym tekście.
 */
final class PriceListPdfPromptEchoTest extends TestCase
{
    use RefreshDatabase;

    private const ECHO_ANSWER = '{"c":"PLN","p":[["SKU","nazwa",12.5,"grupa"],["146a","146a",155,"PU Skóra"],'
        .'["7251","Płukanka do oczu Cederroth Eye Wash 500 ml",76.16,"Płukanki"]]}';

    protected function setUp(): void
    {
        parent::setUp();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'openai/gpt-4o',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
        ]);
    }

    public function test_prompt_shows_the_examples_the_filter_knows(): void
    {
        $prompt = $this->analyzer('pdfCompactChunkPrompt', 'Producent prawdopodobny: CEDERROTH.');

        $this->assertStringContainsString('{"c":"PLN","p":[["SKU","nazwa",12.5,"grupa"]]}', $prompt);
        $this->assertStringContainsString('(np. ["146a","146a",155,"PU Skóra"])', $prompt);
    }

    public function test_text_chunks_drop_echoed_examples_and_keep_real_code_146a(): void
    {
        Http::fake(function (Request $request) {
            $prompt = (string) ($request->data()['messages'][1]['content'] ?? '');

            // część 2 ma w tekście prawdziwy wiersz 146a — ten sam model odpowiada tam tylko nim
            $answer = str_contains($prompt, 'CZĘŚĆ 2/2')
                ? '{"c":"PLN","p":[["146a","146a",155,"PU Skóra"]]}'
                : self::ECHO_ANSWER;

            return Http::response([
                'choices' => [['message' => ['content' => $answer], 'finish_reason' => 'stop']],
                'model' => 'openai/gpt-4o',
            ], 200);
        });

        $ran = $this->analyzer('runPdfChunksInProcess', [
            "SKU nazwa cena\n7251 Płukanka do oczu Cederroth Eye Wash 500 ml 76,16",
            "Obuwie PU Skóra\n146a 155,00",
        ], 'prompt', 'CEDERROTH');

        $this->assertSame(['7251', '146a'], array_column($ran['products'], 'sku'));
        $this->assertSame([76.16, 155.0], array_column($ran['products'], 'catalog_price_net'));
    }

    public function test_worker_chunks_check_the_example_against_their_own_text(): void
    {
        $runId = 'pdfai_test';
        Cache::put(AnalyzePriceListPdfChunkJob::resultKey($runId, 0), ['ok' => true, 'content' => self::ECHO_ANSWER, 'model' => 'm'], 60);
        Cache::put(AnalyzePriceListPdfChunkJob::resultKey($runId, 1), ['ok' => true, 'content' => self::ECHO_ANSWER, 'model' => 'm'], 60);

        $collected = $this->analyzer('collectPdfChunkResults', $runId, 2, 'CEDERROTH', [
            '7251 Płukanka do oczu Cederroth Eye Wash 500 ml 76,16',
            "146a PU Skóra 155,00\n7251 Płukanka do oczu Cederroth Eye Wash 500 ml 76,16",
        ]);

        // „146a” tylko z części, w której tekst go ma; „SKU”/„nazwa” nigdy
        $this->assertSame(['7251', '146a', '7251'], array_column($collected['products'], 'sku'));
        $this->assertNotContains('SKU', array_column($collected['products'], 'sku'));
    }

    public function test_import_skips_header_row_and_says_why(): void
    {
        $file = UploadedFile::fake()->create('Cennik CEDERROTH 07.2026.pdf', 10, 'application/pdf');

        $result = app(PriceListImportService::class)->importFromProducts($file, 'CEDERROTH', '2026', User::factory()->create(), [
            ['sku' => 'SKU', 'name' => 'nazwa', 'catalog_price_net' => 12.5, 'category' => 'grupa'],
            ['sku' => '7251', 'name' => 'Płukanka do oczu Cederroth Eye Wash 500 ml', 'catalog_price_net' => 76.16],
        ]);

        $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));
        $this->assertSame(['7251'], Product::query()->pluck('sku')->all());
        $this->assertSame(1, $result['skipped']);
        $reasons = array_column(PriceList::query()->sole()->skipped_details ?? [], 'reason');
        $this->assertContains('Pozycja 1: nagłówek tabeli („SKU” / „nazwa”), nie produkt', $reasons);
    }

    private function analyzer(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(PriceListAiAnalyzer::class, $method))
            ->invoke(app(PriceListAiAnalyzer::class), ...$args);
    }
}
