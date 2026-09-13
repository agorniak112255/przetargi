<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Product;
use App\Models\TenderItem;
use App\Services\Ai\OpenAiCompatibleClient;
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
            ->expectsOutputToContain('Podsumowanie')
            ->expectsOutputToContain('Stabilne między przebiegami')
            ->expectsOutputToContain('razem (2 przebiegi)')
            ->expectsOutputToContain('Stan modelu (wszystkie przebiegi): ranked 2')
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

    public function test_fails_without_ai_configuration(): void
    {
        AiSetting::query()->delete();

        $this->artisan('tenders:eval')
            ->expectsOutputToContain('AI nie jest skonfigurowane')
            ->assertFailed();
    }
}
