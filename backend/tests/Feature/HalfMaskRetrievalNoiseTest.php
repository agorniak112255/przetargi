<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeSearchLlm;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * AUDYT_4 §15, przetarg 1 poz. 13 (tenders:debug-match na produkcji): „PN-EN 140:2004 (EN 140:1998)”
 * dawało wyszukiwaniu po kodzie modelu „2004” i „1998” — do puli półmaski wchodziły klej 3M 7100200484
 * i materiał odblaskowy 1998467, a półmaska SECURA 3000 (S56T0SM0, karta oczekiwana) nie trafiała
 * do kandydatów. Karty z produkcji są w fixture opisowy15.
 */
final class HalfMaskRetrievalNoiseTest extends TestCase
{
    use RefreshDatabase;

    public function test_norm_years_do_not_become_model_codes_for_line_13(): void
    {
        $service = app(ProductAiSearchService::class);
        $method = new \ReflectionMethod($service, 'modelCodePhrases');

        $codes = $method->invoke($service, Opisowy15Fixture::requirement(13));

        foreach (['2004', '1998', '2016425', '2016', '425', '140'] as $junk) {
            $this->assertNotContains($junk, $codes, "„{$junk}” z normy/rozporządzenia to nie kod modelu");
        }
    }

    public function test_half_mask_pool_keeps_secura_3000_and_drops_norm_year_junk(): void
    {
        Http::fake();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);
        $ids = Opisowy15Fixture::seed();
        $this->app->instance(
            OpenAiCompatibleClient::class,
            FakeSearchLlm::replay(Opisowy15Fixture::items(), $ids, FakeSearchLlm::rankExpectedAndForbidden()),
        );
        $service = app(ProductAiSearchService::class);

        $service->searchMany([Opisowy15Fixture::requirement(13)], 80, false, AiTask::ProductSearch, 16);
        $trace = $service->lastTrace();
        $candidates = array_map('intval', $trace['candidate_ids'] ?? []);

        $this->assertNotContains($ids['7100200484'], $candidates, 'klej epoksydowy z „2004” w SKU nie jest kandydatem na półmaskę');
        $this->assertNotContains($ids['1998467'], $candidates, 'materiał odblaskowy z „1998” w SKU nie jest kandydatem na półmaskę');
        $this->assertContains($ids['S56T0SM0'], $candidates, 'półmaska SECURA 3000 (karta oczekiwana) trafia do puli kandydatów');
    }
}
