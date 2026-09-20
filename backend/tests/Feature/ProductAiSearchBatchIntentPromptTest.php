<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Search\AiProductSearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Wsad (dopasowanie całego przetargu) budował prompt rankingu bez intencji z analizy, więc model
 * dostawał twarde „inna marka → nie zwracaj” także wtedy, gdy marki z SIWZ nie ma w katalogu —
 * zamienniki wypadały tylko w przetargu, a wyszukiwarka dla tego samego wymagania je pokazywała.
 * Obie ścieżki mają budować prompt z tej samej intencji.
 */
final class ProductAiSearchBatchIntentPromptTest extends TestCase
{
    use RefreshDatabase;

    private const CERVA_BOOTS = 'BUTY gumowe DAMSKIE antyelektrostatyczne rozm. 35-41 TRONCHETTO OB. SRA prod.CERVA · EN ISO 20347';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_batch_rank_prompt_tells_model_the_brand_is_absent_like_single_search_does(): void
    {
        $substitute = Product::query()->create([
            'sku' => 'ESD-KAL-CERVA-SUB',
            'name' => 'Kalosze damskie gumowe ESD',
            'manufacturer' => 'VM Footwear',
            'description' => 'Antyelektrostatyczne, EN ISO 20347, podeszwa gumowa.',
            'catalog_price_net' => 120,
            'purchase_price' => 80,
            'stock' => 4,
            'ppe_family' => 'footwear',
        ]);

        $rankPrompts = [];
        $dispatch = static function (array $messages) use ($substitute, &$rankPrompts): array {
            $system = (string) ($messages[0]['content'] ?? '');
            if (str_contains($system, '"manufacturer"')) {
                return [
                    'needed' => 'buty gumowe damskie antyelektrostatyczne',
                    'manufacturer' => 'CERVA',
                    'model_name' => 'TRONCHETTO OB SRA',
                    'size_note' => '35-41',
                    'search_phrases' => ['buty gumowe', 'antyelektrostatyczne', 'EN ISO 20347'],
                    'constraints' => ['EN ISO 20347'],
                ];
            }
            $rankPrompts[] = $system;

            return ['matches' => [['id' => $substitute->id, 'score' => 75, 'reason' => 'ESD obuwie damskie']]];
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing($dispatch);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(
            static fn (array $batch): array => array_map($dispatch, $batch)
        );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $results = $this->app->make(AiProductSearch::class)->findMany([self::CERVA_BOOTS], 10, AiTask::ProductSearch, 2);

        $this->assertNotEmpty($rankPrompts, 'wsad nie wysłał promptu rankingu');
        $prompt = implode("\n", $rankPrompts);
        $this->assertStringContainsString(
            'Marki CERVA nie ma w katalogu',
            $prompt,
            'prompt wsadu nie mówi modelowi, że marki z SIWZ nie ma w katalogu — zamienniki wypadną'
        );
        $this->assertStringNotContainsString(
            'inna marka → nie zwracaj',
            $prompt,
            'wsad dalej stawia twardy warunek marki, której katalog nie ma'
        );
        $this->assertContains('ESD-KAL-CERVA-SUB', array_column($results[0]['products'] ?? [], 'sku'));
    }
}
