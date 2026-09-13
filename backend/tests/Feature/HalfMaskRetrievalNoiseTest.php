<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Product;
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
        $this->assertIsArray($trace['cascade'] ?? null, 'ślad kaskady po krokach nazwy jest zapisywany');
        foreach ($trace['cascade'] as $cascade) {
            $this->assertArrayHasKey('level', $cascade);
            $this->assertArrayHasKey('ended_retrieval', $cascade);
        }

        // Błąd dotyczył trafień po kodzie modelu (lata norm jako kody), które szły na przód puli. Pula po fuzji rang
        // zawiera też trafienia tekstowe kart bez rodziny: w małym fixture materiał odblaskowy wchodzi tam po słowie
        // „ochrony”, a na kopii katalogu produkcji (MySQL) pula 80 jest pełna półmasek i tych kart w niej nie ma.
        $codeHits = (new \ReflectionMethod($service, 'retrieveByModelCode'))
            ->invoke($service, Opisowy15Fixture::requirement(13), 80)
            ->pluck('id')
            ->map(intval(...))
            ->all();
        $this->assertNotContains($ids['7100200484'], $codeHits, 'klej epoksydowy z „2004” w SKU nie jest trafieniem po kodzie modelu');
        $this->assertNotContains($ids['1998467'], $codeHits, 'materiał odblaskowy z „1998” w SKU nie jest trafieniem po kodzie modelu');
        $this->assertContains($ids['S56T0SM0'], $candidates, 'półmaska SECURA 3000 (karta oczekiwana) trafia do puli kandydatów');
    }

    /**
     * Produkcja 13.09 (`tenders:debug-match 1 13`, linia „kaskada”): kroki modelu „półmaska”, „wielokrotnego użytku”,
     * „zawory wdechowe”, „łączniki bagnetowe”, „zawór wydechowy”. Kaskada zdjęła trzy ostatnie kroki (steps_2),
     * znalazła 45 półmasek z „wielokrotnego użytku” w nazwie i zakończyła wyszukiwanie. SECURA 3000 ma bagnety,
     * zawory i nagłowie w opisie, ale nie ten zwrot — do puli nie wchodziła na żadnym poziomie kaskady.
     */
    public function test_cascade_that_dropped_steps_does_not_end_retrieval_before_text_search(): void
    {
        Http::fake();
        Opisowy15Fixture::seed();
        $service = app(ProductAiSearchService::class);
        $retrieve = new \ReflectionMethod($service, 'retrieveCandidates');
        $intent = [
            'needed' => 'półmaska wielokrotnego użytku',
            'search_steps' => ['półmaska', 'wielokrotnego użytku', 'zawory wdechowe', 'łączniki bagnetowe', 'zawór wydechowy'],
            'manufacturer_requested' => 'PN-EN',
            'manufacturer_absent_in_catalog' => true,
            'search_phrases' => ['półmaska wielokrotnego użytku', 'półmaska z bagnetami', 'półmaska z zaworami wdechowymi'],
            'constraints' => ['EN 140'],
        ];

        $candidates = $retrieve->invoke($service, Opisowy15Fixture::requirement(13), $intent, 80)->pluck('sku')->all();
        $cascade = $service->lastTrace()['cascade'];
        $last = end($cascade);

        $this->assertSame('steps_2', $last['level']);
        $this->assertSame(3, $last['dropped_steps'] ?? null, 'ślad mówi, ile kroków kaskada zdjęła');
        $this->assertFalse($last['ended_retrieval'], 'kaskada bez trzech kroków nie kończy wyszukiwania');
        $this->assertContains('S56T0SM0', $candidates, 'SECURA 3000 wchodzi do puli z wyszukiwania tekstowego');
        $this->assertContains('7501B', $candidates, 'karty z kaskady zostają w puli');
        $this->assertLessThan(
            array_search('S56T0SM0', $candidates, true),
            array_search('7501B', $candidates, true),
            'karta z kaskady i z wyszukiwania tekstowego stoi w fuzji rang przed kartą tylko z tekstu'
        );
    }

    /**
     * EN 140 stoi na końcu opisu SECURA 3000 (ok. 2700 znaków). Model dostawał 360 pierwszych znaków opisu
     * (karta krótka — wcale), a pole norm ma cechy z enrichmentu („1 sztuka, Bagnetowe Secura…”), więc ranking
     * pisał „brak dowodu kluczowego warunku: EN 140” i obcinał ocenę do 50.
     */
    public function test_rank_card_carries_norms_from_the_whole_description(): void
    {
        $ids = Opisowy15Fixture::seed();
        $service = app(ProductAiSearchService::class);
        $rankCard = new \ReflectionMethod($service, 'rankCard');
        $secura = Product::query()->findOrFail($ids['S56T0SM0']);

        $this->assertStringNotContainsString('140', mb_substr((string) $secura->description, 0, 360));
        foreach ([false, true] as $short) {
            $card = $rankCard->invoke($service, $secura, $short);
            $this->assertContains('EN 140', $card['description_norms'] ?? [], 'normy z całego opisu na karcie dla modelu');
        }

        $withoutDescription = Product::query()->findOrFail($ids['7501B']);
        $this->assertSame([], $rankCard->invoke($service, $withoutDescription, false)['description_norms'] ?? null);
    }
}
