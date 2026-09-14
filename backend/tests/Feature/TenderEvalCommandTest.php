<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\TenderEvalCommand;
use App\Models\AiSetting;
use App\Models\Product;
use App\Models\TenderItem;
use App\Services\Ai\AiServedProviderTally;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

/**
 * `tenders:eval` — decyzja zapisu przetargu na golden secie (werdykt trafna / zakazana / inna / pusta),
 * kilka przebiegów, raport JSON i porównanie z bazą. Nic nie zapisuje do przetargów.
 */
final class TenderEvalCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $golden;

    private string $reportDir;

    protected function setUp(): void
    {
        parent::setUp();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);
        $this->golden = storage_path('framework/testing/tender-eval-golden-'.uniqid().'.json');
        $this->reportDir = storage_path('app/tender-eval/reports');
    }

    protected function tearDown(): void
    {
        @unlink($this->golden);
        parent::tearDown();
    }

    public function test_classifies_decision_saves_report_and_compares_with_baseline(): void
    {
        $base = [
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie',
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'catalog_price_net' => 46,
            'stock' => 0,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ];
        $sandal = Product::query()->create($base + [
            'sku' => 'ARSO 701 616560 S1 P ESD',
            'name' => 'ARSO 701 616560 S1 P ESD',
            'purchase_price' => 46.26,
            'norms' => 'EN ISO 20345 S1 P, EN IEC 61340-4-3 ESD',
            'description' => 'Sandały bezpieczne ARSO 701 616560 S1 P ESD to lekkie obuwie ochronne przeznaczone do pracy w warunkach wymagających ochrony palców oraz kontroli ładunków elektrostatycznych. Cholewka z materiałów przewiewnych, wkładka antyprzebiciowa, podnosek, zabudowana pięta, podeszwa odporna na oleje (FO).',
        ]);
        $sandalId = (int) $sandal->id;
        $answer = static fn (array $messages): array => FakeSearchLlm::kind($messages) === FakeSearchLlm::KIND_RANK
            ? ['matches' => [['id' => $sandalId, 'score' => 95, 'reason' => 'Sandały S1 P ESD', 'missing_key' => []]]]
            : ['matches' => []];
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static fn (array $messages): array => $answer($messages));
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static fn (array $sets): array => array_map($answer, $sets));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        @mkdir(dirname($this->golden), 0775, true);
        file_put_contents($this->golden, json_encode(['cases' => [[
            'id' => 'test-03-sandaly',
            'query' => 'Sandały ochronne (obuwie bezpieczne z odkrytą cholewką) kategorii S1 P wg EN ISO 20345, zabudowana pięta, podnosek, ESD, podeszwa FO.',
            'expected_skus' => ['ARSO 701 616560 S1 P ESD'],
            'forbidden_skus' => ['AROSIO 730 Air 618080 S1 P ESD'],
            'note' => '',
        ]]], JSON_UNESCAPED_UNICODE));
        $before = glob($this->reportDir.'/*.json') ?: [];

        $this->artisan('tenders:eval', ['--file' => $this->golden, '--filter' => '', '--runs' => 2, '--save' => true])
            ->expectsOutputToContain('test-03-sandaly')
            ->expectsOutputToContain('Czas przebiegu 1:')
            ->expectsOutputToContain('Podsumowanie')
            ->expectsOutputToContain('Stabilne między przebiegami')
            ->expectsOutputToContain('razem (2 przebiegi)')
            ->expectsOutputToContain('Stan modelu (wszystkie przebiegi): ranked 2')
            ->doesntExpectOutputToContain('Werdykty wg dostawcy modelu')
            ->expectsOutputToContain('Raport:')
            ->assertSuccessful();

        $created = array_values(array_diff(glob($this->reportDir.'/*.json') ?: [], $before));
        $this->assertCount(1, $created, 'zapisany jeden raport');
        $report = json_decode((string) file_get_contents($created[0]), true);
        $this->assertSame(2, $report['header']['runs']);
        $this->assertCount(2, $report['cases'][0]['runs']);
        $verdict = $report['cases'][0]['runs'][0]['verdict'];
        $this->assertContains($verdict, ['trafna', 'zakazana', 'inna', 'pusta']);
        $this->assertSame('ARSO 701 616560 S1 P ESD=95 (model)', $report['cases'][0]['runs'][0]['top_model']);
        $this->assertSame('ranked', $report['cases'][0]['runs'][0]['model_state'], 'stan rankingu pozycji w paczce');
        $this->assertSame(['ranked' => 2], $report['summary']['model_states']);
        $recorded = $report['cases'][0]['runs'][0]['search'] ?? null;
        $this->assertIsArray($recorded, 'raport zapisuje wynik wyszukiwania do odtworzenia decyzji');
        $this->assertSame('ranked', $recorded['model_state']);
        $this->assertSame([$sandalId, 95], [$recorded['products'][0]['id'], $recorded['products'][0]['ai_match_percent']]);

        // baza z innymi werdyktami w obu przebiegach → tabela zmian liczona ze wszystkich przebiegów
        $other = $verdict === 'trafna' ? 'pusta' : 'trafna';
        $report['cases'][0]['runs'][0]['verdict'] = $other;
        $report['cases'][0]['runs'][1]['verdict'] = $other;
        $baseline = storage_path('framework/testing/tender-eval-baseline-'.uniqid().'.json');
        file_put_contents($baseline, json_encode($report, JSON_UNESCAPED_UNICODE));
        try {
            $this->artisan('tenders:eval', ['--file' => $this->golden, '--filter' => '', '--baseline' => $baseline])
                // jedna linia nagłówka — jedno oczekiwanie (expectsOutputToContain rozlicza po linii)
                ->expectsOutputToContain('.json (wszystkie przebiegi)')
                ->expectsOutputToContain('średnio trafnych na przebieg')
                ->assertSuccessful();
        } finally {
            @unlink($baseline);
            @unlink($created[0]);
        }

        $this->assertSame(0, TenderItem::query()->count(), 'pomiar nie tworzy pozycji przetargu');
    }

    /**
     * Raport 20260914_131814: przebieg 1 dał 5 złych kart z oceną 95, przebiegi 2–3 tym samym kodem żadnej, a serwer
     * pobierał w tym czasie opisy produktów. Przebieg ostrzega o kartach zmienionych w trakcie i pokazuje dostawców modelu.
     */
    public function test_run_warns_about_catalog_changed_during_run_and_lists_served_model_providers(): void
    {
        $sandal = Product::query()->create([
            'sku' => 'ARSO 701 616560 S1 P ESD',
            'name' => 'ARSO 701 616560 S1 P ESD',
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie',
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'catalog_price_net' => 46,
            'purchase_price' => 46.26,
            'stock' => 0,
            'norms' => 'EN ISO 20345 S1 P, EN IEC 61340-4-3 ESD',
            'description' => 'Sandały bezpieczne ARSO 701 616560 S1 P ESD, podnosek, zabudowana pięta, wkładka antyprzebiciowa, ESD, podeszwa FO.',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $sandalId = (int) $sandal->id;
        $this->travel(5)->seconds();
        $mutated = false;
        $answer = function (array $messages) use ($sandalId, &$mutated): array {
            if (FakeSearchLlm::kind($messages) !== FakeSearchLlm::KIND_RANK) {
                return ['matches' => []];
            }
            $tally = app(AiServedProviderTally::class);
            $tally->served(['provider' => 'Makora']);
            if (! $mutated) {
                $mutated = true;
                // opis pobrany w trakcie przebiegu 1, jak równoległe pobieranie opisów na produkcji
                Product::query()->whereKey($sandalId)->update([
                    'description' => 'Sandały bezpieczne ARSO 701 616560 S1 P ESD z podnoskiem, zabudowaną piętą, wkładką antyprzebiciową, ESD i podeszwą FO.',
                ]);
                $tally->served(['provider' => 'DeepInfra']);
                $tally->relaxedPin();
                $this->travel(2)->seconds();
            }

            return ['matches' => [['id' => $sandalId, 'score' => 95, 'reason' => 'Sandały S1 P ESD', 'missing_key' => []]]];
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static fn (array $messages): array => $answer($messages));
        $understood = false;
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static function (array $sets) use ($answer, &$understood): array {
            $kinds = array_map(static fn (array $messages): string => FakeSearchLlm::kind($messages), $sets);
            $understood = $understood || in_array(FakeSearchLlm::KIND_UNDERSTAND, $kinds, true);
            // prawdziwy klient zapisuje dostawcę każdej odpowiedzi paczki
            app(AiServedProviderTally::class)->recordBatch(array_map(
                static fn (string $kind): string => $kind === FakeSearchLlm::KIND_RANK ? 'StreamLake' : 'Makora',
                $kinds,
            ));

            return array_map($answer, $sets);
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        @mkdir(dirname($this->golden), 0775, true);
        file_put_contents($this->golden, json_encode(['cases' => [[
            'id' => 'test-03-sandaly',
            'query' => 'Sandały ochronne (obuwie bezpieczne z odkrytą cholewką) kategorii S1 P wg EN ISO 20345, zabudowana pięta, podnosek, ESD, podeszwa FO.',
            'expected_skus' => ['ARSO 701 616560 S1 P ESD'],
            'forbidden_skus' => [],
            'note' => '',
        ]]], JSON_UNESCAPED_UNICODE));
        $before = glob($this->reportDir.'/*.json') ?: [];

        $this->artisan('tenders:eval', ['--file' => $this->golden, '--filter' => '', '--runs' => 2, '--save' => true])
            ->expectsOutputToContain('Uwaga: w trakcie przebiegu 1 zmieniono karty katalogu: 1 (w wynikach pomiaru: ARSO 701 616560 S1 P ESD)')
            ->expectsOutputToContain('Dostawcy modelu w przebiegu 1: Makora 1 · DeepInfra 1 · poluzowane przypięcie dostawcy: 1')
            ->expectsOutputToContain('Dostawcy modelu w przebiegu 2: Makora 1')
            ->doesntExpectOutputToContain('w trakcie przebiegu 2')
            ->expectsOutputToContain('Werdykty wg dostawcy modelu, który ocenił pozycję')
            ->expectsOutputToContain('| StreamLake')
            ->assertSuccessful();

        $created = array_values(array_diff(glob($this->reportDir.'/*.json') ?: [], $before));
        $this->assertCount(1, $created);
        $report = json_decode((string) file_get_contents($created[0]), true);
        @unlink($created[0]);
        $this->assertSame(1, $report['timings'][0]['catalog_changed']);
        $this->assertSame(['ARSO 701 616560 S1 P ESD'], $report['timings'][0]['catalog_changed_in_results']);
        $this->assertSame(['Makora' => 1, 'DeepInfra' => 1], $report['timings'][0]['providers']);
        $this->assertSame(1, $report['timings'][0]['relaxed_pins']);
        $this->assertSame(0, $report['timings'][1]['catalog_changed'], 'przebieg 2 bez zmian katalogu');
        $this->assertSame(['Makora' => 1], $report['timings'][1]['providers']);
        $expectedProviders = ['understand' => $understood ? 'Makora' : null, 'rank' => 'StreamLake'];
        $this->assertSame($expectedProviders, $report['cases'][0]['runs'][0]['providers'], 'dostawca przy pozycji');
        $this->assertSame($expectedProviders, $report['cases'][0]['runs'][0]['search']['model_providers'], 'zapisany do odtworzenia');
        $this->assertSame(2, array_sum($report['summary']['by_rank_provider']['StreamLake']));
    }

    /**
     * Pomiar 14.09: zmiana kolejności wyboru wdrożona na próbę dała 7 regresów, a z dwóch przebiegów modelu nie dało się
     * odróżnić jej skutku od szumu modelu. Odtworzenie liczy decyzję bieżącym kodem na zapisanych ocenach modelu.
     */
    public function test_replay_recomputes_decision_from_recorded_search_without_calling_model(): void
    {
        $sandal = Product::query()->create([
            'sku' => 'ARSO 701 616560 S1 P ESD',
            'name' => 'ARSO 701 616560 S1 P ESD',
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie',
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'catalog_price_net' => 46,
            'purchase_price' => 46.26,
            'stock' => 0,
            'norms' => 'EN ISO 20345 S1 P, EN IEC 61340-4-3 ESD',
            'description' => 'Sandały bezpieczne ARSO 701 616560 S1 P ESD, podnosek, zabudowana pięta, wkładka antyprzebiciowa, ESD, podeszwa FO.',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldNotReceive('chatJson');
        $llm->shouldNotReceive('chatJsonMany');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        @mkdir(dirname($this->golden), 0775, true);
        $query = 'Sandały ochronne (obuwie bezpieczne z odkrytą cholewką) kategorii S1 P wg EN ISO 20345, zabudowana pięta, podnosek, ESD, podeszwa FO.';
        file_put_contents($this->golden, json_encode(['cases' => [[
            'id' => 'test-03-sandaly',
            'query' => $query,
            'expected_skus' => ['ARSO 701 616560 S1 P ESD'],
            'forbidden_skus' => [],
            'note' => '',
        ]]], JSON_UNESCAPED_UNICODE));
        $recorded = storage_path('framework/testing/tender-eval-recorded-'.uniqid().'.json');
        file_put_contents($recorded, json_encode(['cases' => [[
            'id' => 'test-03-sandaly',
            'runs' => [
                ['verdict' => 'pusta', 'search' => ['model_state' => 'ranked', 'external_hint' => null, 'products' => [
                    ['id' => (int) $sandal->id, 'sku' => $sandal->sku, 'name' => $sandal->name, 'ai_match_percent' => 95, 'ai_match_reason' => 'Sandały S1 P ESD', 'ai_match_source' => null],
                ]]],
                ['verdict' => 'pusta', 'search' => ['model_state' => 'empty', 'external_hint' => null, 'products' => []]],
            ],
        ]]], JSON_UNESCAPED_UNICODE));
        $before = glob($this->reportDir.'/*.json') ?: [];
        try {
            $this->artisan('tenders:eval', ['--file' => $this->golden, '--filter' => '', '--replay' => $recorded, '--save' => true])
                ->expectsOutputToContain('Odtworzenie decyzji z zapisanych wyników wyszukiwania (bez modelu)')
                ->expectsOutputToContain('razem (2 przebiegi)')
                ->assertSuccessful();

            $created = array_values(array_diff(glob($this->reportDir.'/*.json') ?: [], $before));
            $this->assertCount(1, $created);
            $report = json_decode((string) file_get_contents($created[0]), true);
            @unlink($created[0]);
            $this->assertSame($recorded, $report['header']['replay_of']);
            // przebieg 1: ocena modelu 95; przebieg 2: model nic nie wskazał — decyzja po słowach karty (sufit 70%)
            $this->assertSame(['trafna', 'trafna'], array_column($report['cases'][0]['runs'], 'verdict'));
            $this->assertSame(['ai', 'heuristic (po słowach)'], array_column($report['cases'][0]['runs'], 'source'));
        } finally {
            @unlink($recorded);
        }
    }

    /**
     * `tender_expect_empty` (golden poz. 9): katalog nie ma karty spełniającej wymaganie, więc brak propozycji jest trafny,
     * a wybór — nawet karty z `expected_skus`, najlepszej dla pomiaru wyszukiwania — jest zły.
     */
    public function test_case_expecting_no_proposal_counts_empty_decision_as_hit_and_any_pick_as_other(): void
    {
        $sandal = Product::query()->create([
            'sku' => 'ARSO 701 616560 S1 P ESD',
            'name' => 'ARSO 701 616560 S1 P ESD',
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie',
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'catalog_price_net' => 46,
            'purchase_price' => 46.26,
            'stock' => 0,
            'norms' => 'EN ISO 20345 S1 P, EN IEC 61340-4-3 ESD',
            'description' => 'Sandały bezpieczne ARSO 701 616560 S1 P ESD, podnosek, zabudowana pięta, wkładka antyprzebiciowa, ESD, podeszwa FO.',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldNotReceive('chatJson');
        $llm->shouldNotReceive('chatJsonMany');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        @mkdir(dirname($this->golden), 0775, true);
        file_put_contents($this->golden, json_encode(['cases' => [
            [
                'id' => 'test-sandaly-brak',
                'query' => 'Sandały ochronne (obuwie bezpieczne z odkrytą cholewką) kategorii S1 P wg EN ISO 20345, zabudowana pięta, podnosek, ESD, podeszwa FO.',
                'expected_skus' => ['ARSO 701 616560 S1 P ESD'],
                'forbidden_skus' => [],
                'tender_expect_empty' => true,
                'note' => '',
            ],
            [
                'id' => 'test-gogle-brak',
                'query' => 'Gogle ochronne szczelne, spawalnicze, z zaciemnieniem 5.0, soczewka odporna na uderzenia do 120 m/s, EN 166.',
                'expected_skus' => ['NIE-MA-TAKICH-GOGLI'],
                'forbidden_skus' => [],
                'tender_expect_empty' => true,
                'note' => '',
            ],
        ]], JSON_UNESCAPED_UNICODE));
        $recorded = storage_path('framework/testing/tender-eval-recorded-'.uniqid().'.json');
        file_put_contents($recorded, json_encode(['cases' => [
            ['id' => 'test-sandaly-brak', 'runs' => [
                ['verdict' => 'pusta', 'search' => ['model_state' => 'ranked', 'external_hint' => null, 'products' => [
                    ['id' => (int) $sandal->id, 'sku' => $sandal->sku, 'name' => $sandal->name, 'ai_match_percent' => 95, 'ai_match_reason' => 'Sandały S1 P ESD', 'ai_match_source' => null],
                ]]],
            ]],
            ['id' => 'test-gogle-brak', 'runs' => [
                ['verdict' => 'pusta', 'search' => ['model_state' => 'empty', 'external_hint' => null, 'products' => []]],
            ]],
        ]], JSON_UNESCAPED_UNICODE));
        $before = glob($this->reportDir.'/*.json') ?: [];
        try {
            $this->artisan('tenders:eval', ['--file' => $this->golden, '--filter' => '', '--replay' => $recorded, '--save' => true])
                ->expectsOutputToContain('brak (nie')
                ->assertSuccessful();

            $created = array_values(array_diff(glob($this->reportDir.'/*.json') ?: [], $before));
            $this->assertCount(1, $created);
            $report = json_decode((string) file_get_contents($created[0]), true);
            @unlink($created[0]);
            $verdicts = [];
            foreach ($report['cases'] as $case) {
                $verdicts[$case['id']] = array_column($case['runs'], 'verdict');
            }
            $this->assertSame(['inna'], $verdicts['test-sandaly-brak'], 'wybór karty przy oczekiwanym braku to zły wybór');
            $this->assertSame(['trafna'], $verdicts['test-gogle-brak'], 'brak propozycji przy oczekiwanym braku jest trafny');
        } finally {
            @unlink($recorded);
        }
    }

    /** Raport 20260914_161701 poz. 1: 40 wierszy listy zapasowej (48) nad oceną modelu — ocena karty 11202000 nie trafiła do raportu. */
    public function test_recorded_search_keeps_model_rows_below_first_forty_rows(): void
    {
        $rows = [];
        for ($i = 1; $i <= 45; $i++) {
            $rows[] = ['id' => $i, 'sku' => 'CAT-'.$i, 'name' => 'Rękawice ESD', 'ai_match_percent' => 48, 'ai_match_reason' => 'lista zapasowa', 'ai_match_source' => ProductAiSearchService::MATCH_SOURCE_CATALOG];
        }
        $rows[] = ['id' => 99, 'sku' => '11202000', 'name' => 'HyFlex 11202', 'ai_match_percent' => 30, 'ai_match_reason' => 'brak EN 388', 'ai_match_source' => null];
        $command = app(TenderEvalCommand::class);

        $recorded = (new \ReflectionMethod($command, 'recordedSearch'))->invoke($command, ['model_state' => 'ranked', 'products' => $rows]);

        $this->assertCount(41, $recorded['products'], 'pierwsze 40 wierszy + wiersz oceniony przez model');
        $this->assertSame(['11202000', 30], [$recorded['products'][40]['sku'], $recorded['products'][40]['ai_match_percent']]);
    }

    public function test_replay_of_report_without_recorded_search_fails_clearly(): void
    {
        $recorded = storage_path('framework/testing/tender-eval-old-'.uniqid().'.json');
        @mkdir(dirname($recorded), 0775, true);
        file_put_contents($recorded, json_encode(['cases' => [['id' => 'x', 'runs' => [['verdict' => 'trafna']]]]]));
        try {
            $this->artisan('tenders:eval', ['--replay' => $recorded])
                ->expectsOutputToContain('nie zawiera zapisanych wyników wyszukiwania')
                ->assertFailed();
        } finally {
            @unlink($recorded);
        }
    }

    public function test_fails_without_ai_configuration(): void
    {
        AiSetting::query()->delete();

        $this->artisan('tenders:eval')
            ->expectsOutputToContain('AI nie jest skonfigurowane')
            ->assertFailed();
    }
}
