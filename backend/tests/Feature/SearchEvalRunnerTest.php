<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Product;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Services\Search\SearchEvalRunner;
use App\Support\SearchEvalMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Tests\Support\FakeSearchLlm;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * SearchEvalRunner: wczytanie golden setu i metryki jednego przypadku na fixture „opisowy15”
 * ze stubem modelu. Retrieval w SQLite jest LIKE‑owy, więc test pilnuje kontraktu runnera
 * (co liczy i z czego), nie wartości recall z produkcji.
 */
final class SearchEvalRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const GOLDEN = 'resources/search-eval/golden.json';

    private const K = 10;

    protected function setUp(): void
    {
        parent::setUp();
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
    }

    public function test_golden_set_carries_the_fifteen_descriptive_cases_next_to_the_old_ones(): void
    {
        $cases = app(SearchEvalRunner::class)->loadCases(base_path(self::GOLDEN));
        $ids = array_column($cases, 'id');

        $this->assertSame($ids, array_values(array_unique($ids)), 'zdublowane id w golden.json');
        // Liczba przypadków nie jest miarą jakości pomiaru: 19 przypadków mierzyło SKU, których nie ma
        // w katalogu (marki spoza asortymentu), więc z definicji dawały zero i zaniżały każdą metrykę.
        // W ich miejsce weszły prawdziwe zapytania klientów z poczty. Zamiast progu liczbowego
        // pilnujemy tego, co naprawdę ma znaczenie: każdy przypadek musi cokolwiek mierzyć.
        $this->assertGreaterThanOrEqual(35, count($cases), 'golden.json ma nieść przypadki zakotwiczone, opisowe i z poczty');
        $this->assertContains('peltor-x2-naglowne', $ids, 'dotychczasowe przypadki mają zostać');
        // Pola bez znaczenia dla loadCases (równoważniki, uzasadnienie zamiany listy) czytamy wprost z pliku.
        $raw = [];
        foreach (json_decode((string) file_get_contents(base_path(self::GOLDEN)), true)['cases'] as $rawCase) {
            $raw[(string) $rawCase['id']] = $rawCase;
        }
        foreach ($cases as $case) {
            $this->assertNotEmpty(
                [...($case['expected_skus'] ?? []), ...($case['forbidden_skus'] ?? [])],
                'przypadek '.$case['id'].' nie ma czego mierzyć — ani oczekiwanych, ani zakazanych SKU',
            );
            $expected = SearchEvalMetrics::normalizeAll($case['expected_skus']);
            $forbidden = SearchEvalMetrics::normalizeAll($case['forbidden_skus']);
            $this->assertSame([], array_values(array_intersect($expected, $forbidden)), $case['id'].': SKU jednocześnie wzorcowe i zakazane');

            // Równoważniki (decyzja właściciela 25.09.2026): karta innego producenta albo modelu, która spełnia
            // wszystkie warunki wymagania, liczy się jako trafienie. Lista rozłączna z wzorcowymi i zakazanymi,
            // bez powtórzeń, a każda karta ma w acceptable_note producenta i dowody warunek po warunku.
            $acceptable = $raw[$case['id']]['acceptable_skus'] ?? [];
            $this->assertIsArray($acceptable, $case['id'].': acceptable_skus ma być listą');
            $this->assertSame(SearchEvalMetrics::normalizeAll($acceptable), array_map(SearchEvalMetrics::normalize(...), $acceptable), $case['id'].': zdublowany równoważnik');
            $this->assertSame([], array_values(array_intersect(SearchEvalMetrics::normalizeAll($acceptable), [...$expected, ...$forbidden])), $case['id'].': równoważnik jest już wzorcowy albo zakazany');
            $note = trim((string) ($raw[$case['id']]['acceptable_note'] ?? ''));
            $this->assertSame($acceptable === [], $note === '', $case['id'].': acceptable_note tylko razem z acceptable_skus');
            foreach ($acceptable as $sku) {
                $this->assertStringContainsString((string) $sku, $note, $case['id'].": {$sku} bez uzasadnienia w acceptable_note");
            }
            // catalog_note to lustro eval_catalog_note z fixture opisowy15 (README) — gdzie indziej nie ma czego lustrzyć.
            if (preg_match('/^opisowy15-/', $case['id']) !== 1) {
                $this->assertArrayNotHasKey('catalog_note', $raw[$case['id']], $case['id'].': catalog_note tylko przy opisowy15');
            }
        }

        $byLine = [];
        foreach ($cases as $case) {
            if (preg_match('/^opisowy15-(\d{2})-[a-z0-9-]+$/', $case['id'], $m) === 1) {
                $byLine[(int) $m[1]] = $case;
            }
        }
        $this->assertSame(range(1, 15), array_keys($byLine));

        foreach (Opisowy15Fixture::items() as $line) {
            $case = $byLine[(int) $line['line_no']];
            $this->assertSame($line['requirement'], $case['query']);
            // eval_extra_*: karty tylko dla pomiaru (AUDYT_4, poz. 3: AROSIO/ARDESIO to zakryte buty; ARSO,
            // też sandał S1 P ESD, przeszła 25.09 do listy zastępczej) — bez dopisywania ich do list bramki asortymentu.
            // eval_catalog_expected_skus zastępuje listę z migawki (25.09.2026): karta z fixture ma w katalogu
            // produkcji inny SKU (poz. 9: 1406213 → 7100010431) albo nie spełnia wymagania (poz. 3: 6660 S1 P bez ESD).
            // Fixture i jej expected_sku zostają, bo sprawdzają bramki na migawce.
            $catalog = $line['eval_catalog_expected_skus'] ?? null;
            if ($catalog !== null) {
                $this->assertNotContains($line['expected_sku'], $catalog, "poz. {$line['line_no']}: lista zastępcza z kartą migawki — wystarczy eval_extra_expected_skus");
                $this->assertArrayNotHasKey('eval_extra_expected_skus', $line, "poz. {$line['line_no']}: lista zastępcza nie łączy się z dopiskami");
                $this->assertNotSame('', trim((string) ($line['eval_catalog_note'] ?? '')), "poz. {$line['line_no']}: zamiana listy bez uzasadnienia");
                $this->assertSame(array_values($catalog), $case['expected_skus']);
            } else {
                $this->assertSame(
                    [$line['expected_sku'], ...array_values($line['eval_extra_expected_skus'] ?? [])],
                    $case['expected_skus'],
                );
            }
            $this->assertSame(trim((string) ($line['eval_catalog_note'] ?? '')), trim((string) ($raw[$case['id']]['catalog_note'] ?? '')), "poz. {$line['line_no']}: catalog_note w golden.json");
            $this->assertSame(array_values($line['eval_acceptable_skus'] ?? []), $raw[$case['id']]['acceptable_skus'] ?? [], "poz. {$line['line_no']}: acceptable_skus w golden.json");
            $this->assertSame(trim((string) ($line['eval_acceptable_note'] ?? '')), trim((string) ($raw[$case['id']]['acceptable_note'] ?? '')), "poz. {$line['line_no']}: acceptable_note w golden.json");
            // eval_catalog_forbidden_skus zastępuje listę zakazanych w pomiarze na katalogu produkcji (decyzja właściciela
            // 26.09.2026, poz. 11: zestaw 7251-7200 to równoważnik), a forbidden_skus fixture dalej sprawdza wybór automatu
            // na migawce. Karta zdjęta z zakazanych musi mieć uzasadnienie wśród równoważników.
            $catalogForbidden = $line['eval_catalog_forbidden_skus'] ?? null;
            if ($catalogForbidden !== null) {
                $this->assertArrayNotHasKey('eval_extra_forbidden_skus', $line, "poz. {$line['line_no']}: lista zastępcza zakazanych nie łączy się z dopiskami");
                foreach (array_diff($line['forbidden_skus'], $catalogForbidden) as $dropped) {
                    $this->assertContains($dropped, $line['eval_acceptable_skus'] ?? [], "poz. {$line['line_no']}: {$dropped} zdjęta z zakazanych bez decyzji o równoważniku");
                }
            }
            $this->assertSame(
                $catalogForbidden !== null
                    ? array_values($catalogForbidden)
                    : [...array_values($line['forbidden_skus']), ...array_values($line['eval_extra_forbidden_skus'] ?? [])],
                $case['forbidden_skus'],
            );
            $this->assertSame(trim((string) $line['source_facts']), $case['note']);
            // eval_catalog_expect_empty: werdykt dla karty z katalogu produkcji, gdy różni się od migawki (poz. 9, decyzja
            // D3 z 25.09.2026: 7100010431 spełnia 120 m/s, karta migawki 1406213 z oprawką FT — nie).
            $this->assertSame(
                ($line['eval_catalog_expect_empty'] ?? $line['eval_expect_empty'] ?? false) === true,
                $case['expect_empty'],
                "poz. {$line['line_no']}: tender_expect_empty w golden.json",
            );
        }
    }

    public function test_load_cases_rejects_missing_file_and_case_without_expected_skus(): void
    {
        $runner = app(SearchEvalRunner::class);

        try {
            $runner->loadCases(base_path('resources/search-eval/nie-ma-takiego.json'));
            $this->fail('brak pliku ma być błędem');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Nie ma pliku', $e->getMessage());
        }

        $path = tempnam(sys_get_temp_dir(), 'golden');
        file_put_contents($path, json_encode(['cases' => [['query' => 'Rękawice', 'expected_skus' => []]]]));
        try {
            $runner->loadCases($path);
            $this->fail('przypadek bez expected_skus ma być błędem');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('expected_skus', $e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_evaluate_reports_retrieval_recall_unknown_skus_and_violations_without_model(): void
    {
        Opisowy15Fixture::seed();
        $this->app->instance(OpenAiCompatibleClient::class, FakeSearchLlm::empty());
        $runner = app(SearchEvalRunner::class);
        $cases = [];
        foreach ($runner->loadCases(base_path(self::GOLDEN)) as $case) {
            $cases[$case['id']] = $case;
        }

        // Karta oczekiwana jest w bazie i wchodzi do puli kandydatów — etap 1 zaliczony.
        $apteczka = $runner->evaluate($cases['opisowy15-10-apteczka-scienna-mini'], self::K, ProductAiSearchService::CATALOG_LIMIT);
        $this->assertNull($apteczka['error']);
        $this->assertSame([], $apteczka['unknown_skus']);
        $this->assertGreaterThan(0, $apteczka['candidates']);
        $this->assertSame(1.0, $apteczka['retrieval_recall']);
        $this->assertSame([], $apteczka['violations'], 'brak forbidden_skus → brak naruszeń');
        $this->assertContains($apteczka['recall_at_k'], [0.0, 1.0], 'jedno oczekiwane SKU → recall@k jest 0 albo 1');
        if ($apteczka['missing_skus'] === []) {
            $this->assertSame(1.0, $apteczka['recall_at_k']);
            $this->assertGreaterThan(0.0, $apteczka['mrr']);
        } else {
            $this->assertSame(0.0, $apteczka['recall_at_k']);
            $this->assertSame(SearchEvalMetrics::normalizeAll(['191400']), $apteczka['missing_skus']);
        }

        // SKU spoza katalogu = zły wpis w golden secie, nie zła wyszukiwarka.
        $unknown = $runner->evaluate([
            'id' => 'test-unknown-sku',
            'query' => $cases['opisowy15-10-apteczka-scienna-mini']['query'],
            'expected_skus' => ['NIE-MA-TAKIEJ-KARTY'],
            'forbidden_skus' => [],
            'note' => '',
        ], self::K, ProductAiSearchService::CATALOG_LIMIT);
        $this->assertNull($unknown['error']);
        $this->assertSame(SearchEvalMetrics::normalizeAll(['NIE-MA-TAKIEJ-KARTY']), $unknown['unknown_skus']);
        $this->assertSame(0.0, $unknown['retrieval_recall']);
        $this->assertSame(0.0, $unknown['recall_at_k']);
        $this->assertSame(SearchEvalMetrics::normalizeAll(['NIE-MA-TAKIEJ-KARTY']), $unknown['missing_skus']);

        // Naruszenie: SKU z forbidden_skus w top‑k wyniku. Zakazujemy karty, którą wyszukiwarka
        // faktycznie zwraca dla tego zapytania, więc test sprawdza mechanikę, a nie konkretny błąd.
        $query = $cases['opisowy15-14-pochlaniacz-a2']['query'];
        $returned = array_map(
            static fn (array $row): string => (string) ($row['sku'] ?? ''),
            app(ProductAiSearchService::class)->search($query, ProductAiSearchService::CATALOG_LIMIT)['products'],
        );
        $this->assertNotSame([], $returned, 'pochłaniacz A2 z fixture ma wracać z wyszukiwarki');
        $violation = $runner->evaluate([
            'id' => 'test-violation',
            'query' => $query,
            'expected_skus' => ['S565A202'],
            'forbidden_skus' => [$returned[0]],
            'note' => '',
        ], self::K, ProductAiSearchService::CATALOG_LIMIT);
        $this->assertSame(SearchEvalMetrics::normalizeAll([$returned[0]]), $violation['violations']);
        $this->assertSame(count($returned), $violation['returned']);

        $summary = $runner->summarize([$apteczka, $unknown, $violation]);
        $this->assertSame(3, $summary['cases']);
        $this->assertSame(1, $summary['violations']);
        $this->assertSame(0, $summary['errors']);
        $this->assertEqualsWithDelta(($apteczka['retrieval_recall'] + $unknown['retrieval_recall'] + $violation['retrieval_recall']) / 3, $summary['retrieval_recall'], 0.0001);
    }

    /**
     * Raport „przed/po” zmiany ścieżki po pustej ocenie (intentChanged, 24.09.2026) pokazywał tylko metryki —
     * nie było widać, które przypadki poszły drugą oceną albo przepisaniem zapytania. Każdy wiersz niesie więc
     * stan modelu, liczbę odpowiedzi rankingu i znacznik przepisania.
     */
    public function test_evaluate_reports_model_path_of_each_case(): void
    {
        Opisowy15Fixture::seed();

        // Model ocenia karty i coś wskazuje: jedna odpowiedź rankingu, bez przepisania.
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static function (array $messages): array {
            if (FakeSearchLlm::kind($messages) !== FakeSearchLlm::KIND_RANK) {
                throw new RuntimeException('bez zrozumienia — intencja lokalna');
            }
            preg_match('/"id":(\d+)/', (string) ($messages[1]['content'] ?? ''), $m);

            return ['matches' => [['id' => (int) ($m[1] ?? 0), 'score' => 80, 'reason' => 'stub']]];
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
        // Runner dopiero po podstawieniu modelu — inaczej trzyma prawdziwego klienta.
        $runner = app(SearchEvalRunner::class);
        $cases = [];
        foreach ($runner->loadCases(base_path(self::GOLDEN)) as $case) {
            $cases[$case['id']] = $case;
        }
        $case = $cases['opisowy15-10-apteczka-scienna-mini'];

        $row = $runner->evaluate($case, self::K, ProductAiSearchService::CATALOG_LIMIT);

        $this->assertNull($row['error']);
        $this->assertSame(ProductAiSearchService::MODEL_STATE_RANKED, $row['model_state']);
        $this->assertSame(1, $row['rank_passes']);
        $this->assertFalse($row['rewrite']);

        // Wyszukiwanie się wywróciło (puste wymaganie) — wiersz ma te same klucze, z wartościami „nic się nie wydarzyło”.
        $error = $runner->evaluate([...$case, 'query' => '   '], self::K, ProductAiSearchService::CATALOG_LIMIT);

        $this->assertNotNull($error['error']);
        $this->assertNull($error['model_state']);
        $this->assertSame(0, $error['rank_passes']);
        $this->assertFalse($error['rewrite']);
    }

    /**
     * Decyzja A z 25.09.2026: karta innego producenta/modelu spełniająca wszystkie warunki liczy się jako
     * trafienie. Idzie osobną listą `acceptable_skus`; stare pliki golden bez niej działają jak dotąd.
     */
    public function test_load_cases_reads_acceptable_skus_and_keeps_old_cases_unchanged(): void
    {
        $runner = app(SearchEvalRunner::class);
        $path = tempnam(sys_get_temp_dir(), 'golden');
        file_put_contents($path, json_encode(['cases' => [
            [
                'id' => 'z-rownowaznikami',
                'query' => 'Rękawice antyprzecięciowe',
                'expected_skus' => ['A-1'],
                'acceptable_skus' => ['B-2', ' B-2 ', '', 'C-3'],
                'acceptable_note' => '  B-2 i C-3: inny producent, warunki sprawdzone na karcie.  ',
            ],
            ['id' => 'stary', 'query' => 'Rękawice', 'expected_skus' => ['A-1']],
        ]], JSON_UNESCAPED_UNICODE));

        try {
            $cases = $runner->loadCases($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(['B-2', 'C-3'], $cases[0]['acceptable_skus']);
        $this->assertSame('B-2 i C-3: inny producent, warunki sprawdzone na karcie.', $cases[0]['acceptable_note']);
        $this->assertSame([], $cases[1]['acceptable_skus']);
        $this->assertSame('', $cases[1]['acceptable_note']);
    }

    /** Karta nie może być jednocześnie równoważnikiem i zakazaną albo wzorcową — to błąd wpisu w golden secie. */
    public function test_load_cases_rejects_acceptable_sku_that_is_also_forbidden_or_expected(): void
    {
        $runner = app(SearchEvalRunner::class);

        foreach (['forbidden_skus' => 'x-2', 'expected_skus' => 'x-2'] as $field => $clash) {
            $path = tempnam(sys_get_temp_dir(), 'golden');
            $case = ['query' => 'Rękawice', 'expected_skus' => ['A-1'], 'acceptable_skus' => ['X-2']];
            $case[$field] = [...($case[$field] ?? []), $clash];
            file_put_contents($path, json_encode(['cases' => [$case]]));
            try {
                $runner->loadCases($path);
                $this->fail("acceptable_skus pokrywające się z {$field} ma być błędem");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('acceptable_skus', $e->getMessage());
                $this->assertStringContainsString('X-2', $e->getMessage());
            } finally {
                @unlink($path);
            }
        }
    }

    /**
     * Bez `acceptable_skus` wiersz musi mieć dokładnie te metryki co w raporcie eval_20260925_063133
     * (przypadek ARMEN, oba przebiegi z produkcji dały ten sam wynik). Do tego nowe pola: lista wyniku
     * i zakazana 6660 z 60% pod 1010 z 99% jako ostrzeżenie o kolorze, nie błąd blokujący (decyzja B).
     */
    public function test_score_without_acceptable_skus_reproduces_the_armen_row_of_the_report(): void
    {
        $result = $this->armenResult();
        $row = app(SearchEvalRunner::class)->scoreResult($this->armenCase(), $result, $this->traceFor($result), self::K);

        $this->assertNull($row['error']);
        $this->assertSame(1.0, $row['retrieval_recall']);
        $this->assertSame(1.0, $row['recall_at_k']);
        $this->assertSame(0.3, $row['precision_at_k']);
        $this->assertSame(0.7328286204777911, $row['ndcg_at_k']);
        $this->assertSame(0.5, $row['mrr']);
        $this->assertSame(['ARMEN 9007 6660 S1'], $row['violations'], 'ścisłe naruszenia bez zmian');
        $this->assertSame([], $row['missing_skus']);
        $this->assertSame([], $row['unknown_skus']);
        $this->assertSame(8, $row['returned']);
        $this->assertSame('skipped', $row['model_state']);

        $this->assertSame(['ARMEN 9007 6660 S1'], $row['forbidden_shown']);
        $this->assertSame([], $row['violations_blocking']);
        $this->assertSame([], $row['acceptable_hits']);
        $this->assertSame([], $row['unknown_forbidden_skus']);
        $this->assertSame([], $row['unknown_acceptable_skus']);
        $this->assertSame(0, $row['unrated_rows'], 'wiersze nazwanego modelu nie są listą zapasową');
        $this->assertSame([
            ['sku' => 'ARMEN 9007 Clip 1010 S1', 'percent' => 99, 'source' => null, 'tag' => 'other'],
            ['sku' => 'ARMEN 9007 1010 S1', 'percent' => 99, 'source' => null, 'tag' => 'expected'],
            ['sku' => 'ARMEN 9007 1010 S1 ESD', 'percent' => 99, 'source' => null, 'tag' => 'expected'],
            ['sku' => 'ARMEN 9007 1010 S1 P ESD', 'percent' => 99, 'source' => null, 'tag' => 'expected'],
            ['sku' => 'ARMEN 9007 6660 S1', 'percent' => 60, 'source' => null, 'tag' => 'forbidden'],
            ['sku' => 'ARMEN 9007 9360 S1', 'percent' => 60, 'source' => null, 'tag' => 'other'],
            ['sku' => 'ARMEN 9007 6660 S1 P', 'percent' => 60, 'source' => null, 'tag' => 'other'],
            ['sku' => 'ARMEN 9007 6660 S1 ESD', 'percent' => 60, 'source' => null, 'tag' => 'other'],
        ], array_map(static fn (array $top): array => array_intersect_key($top, array_flip(['sku', 'percent', 'source', 'tag'])), $row['returned_top']));

        // Plan §5b: po decyzji B zakazy rodzeństwa ARMEN (inne kolory) wchodzą do golden — ścisłe naruszenia 1 → 4,
        // a wszystkie cztery to propozycje 60% pod właściwą kartą z 99%, więc blokujących dalej nie ma.
        $siblings = [...$this->armenCase()['forbidden_skus'], 'ARMEN 9007 6660 S1 P', 'ARMEN 9007 6660 S1 ESD', 'ARMEN 9007 9360 S1'];
        $strict = app(SearchEvalRunner::class)->scoreResult(
            [...$this->armenCase(), 'forbidden_skus' => $siblings],
            $result,
            $this->traceFor($result),
            self::K,
        );
        $this->assertCount(4, $strict['violations']);
        $this->assertSame($strict['violations'], $strict['forbidden_shown']);
        $this->assertSame([], $strict['violations_blocking']);
        $this->assertSame(0.7328286204777911, $strict['ndcg_at_k'], 'zakazy nie zmieniają metryk rankingu');
    }

    /**
     * Diagnoza remisów procentu (25.09.2026): wiersz listy wyniku niesie surową ocenę modelu i brakujący kluczowy
     * warunek ze śladu llm_matches oraz cenę zakupu, po której sortowanie rozstrzyga remis.
     */
    public function test_returned_top_carries_model_score_missing_key_and_purchase_price(): void
    {
        $result = ['products' => [
            ['id' => 11, 'sku' => 'A-1', 'ai_match_percent' => 50, 'purchase_price' => 12.5, 'currency' => 'EUR', 'purchase_price_pln' => 53.4],
            ['id' => 12, 'sku' => 'B-2', 'ai_match_percent' => 45, 'ai_match_source' => 'catalog', 'purchase_price' => 9, 'currency' => 'PLN'],
        ]];
        $trace = ['candidate_ids' => [11, 12], 'llm_matches' => [
            ['id' => 11, 'score' => 40, 'missing_key' => []],
            ['id' => 11, 'score' => 72, 'missing_key' => ['EN 388 4121']],
        ]];

        $row = app(SearchEvalRunner::class)->scoreResult(
            ['id' => 'x', 'query' => 'Rękawice', 'expected_skus' => ['A-1'], 'forbidden_skus' => []],
            $result,
            $trace,
            self::K,
        );

        $this->assertSame(72, $row['returned_top'][0]['model_score'], 'ostatni przebieg oceny wygrywa');
        $this->assertSame(['EN 388 4121'], $row['returned_top'][0]['missing_key']);
        $this->assertSame(53.4, $row['returned_top'][0]['purchase_price_pln']);
        $this->assertSame('EUR', $row['returned_top'][0]['currency']);
        $this->assertNull($row['returned_top'][1]['model_score'], 'wiersz listy zapasowej nie ma oceny modelu');
        $this->assertSame([], $row['returned_top'][1]['missing_key']);
        $this->assertSame(9.0, $row['returned_top'][1]['purchase_price']);
    }

    /** Równoważnik podnosi precision, MRR i nDCG; recall dalej mierzy wyłącznie karty wzorcowe. */
    public function test_acceptable_skus_count_in_precision_mrr_and_ndcg_but_not_in_recall(): void
    {
        $result = $this->armenResult();
        $runner = app(SearchEvalRunner::class);
        // Clip 1010 tylko jako przykład mechanizmu — w golden zostaje neutralna (plan §5b).
        $case = [...$this->armenCase(), 'acceptable_skus' => ['ARMEN 9007 Clip 1010 S1']];

        $row = $runner->scoreResult($case, $result, $this->traceFor($result), self::K);

        $this->assertSame(1.0, $row['retrieval_recall']);
        $this->assertSame(1.0, $row['recall_at_k']);
        $this->assertSame(0.4, $row['precision_at_k']);
        $this->assertSame(1.0, $row['mrr']);
        $this->assertSame(1.0, $row['ndcg_at_k'], 'cztery trafne karty na czterech pierwszych miejscach');
        $this->assertSame(['ARMEN 9007 CLIP 1010 S1'], $row['acceptable_hits']);
        $this->assertSame('acceptable', $row['returned_top'][0]['tag']);
        $this->assertSame([], $row['unknown_acceptable_skus']);

        // Idealny DCG z samych wzorcowych (recenzja 25.09.2026): dopisany równoważnik, którego nie ma w wyniku, nie
        // obniża nDCG — ten sam wynik wyszukiwarki ma dawać tę samą ocenę rankingu. Recall bez zmian.
        $wider = $runner->scoreResult(
            [...$case, 'acceptable_skus' => ['ARMEN 9007 Clip 1010 S1', 'NIE-MA-TAKIEJ-KARTY']],
            $result,
            $this->traceFor($result),
            self::K,
        );
        $this->assertSame($row['ndcg_at_k'], $wider['ndcg_at_k']);
        $this->assertSame(1.0, $wider['recall_at_k']);
        $this->assertSame(SearchEvalMetrics::normalizeAll(['NIE-MA-TAKIEJ-KARTY']), $wider['unknown_acceptable_skus']);

        $summary = $runner->summarize([$row, $wider]);
        $this->assertSame(2, $summary['acceptable_hits']);
    }

    /**
     * Decyzja B z 25.09.2026: zakazana karta poniżej progu zapisu (Ustawienia AI: match_min_score) pod kartą
     * wzorcową albo akceptowalną ≥ progu to ostrzeżenie (`forbidden_shown`). Każdy inny zakazany wiersz
     * w top-k zostaje blokujący — wtedy automat przetargu mógłby go wybrać.
     */
    public function test_forbidden_row_is_blocking_unless_shown_below_threshold_under_a_right_card(): void
    {
        $runner = app(SearchEvalRunner::class);
        $case = [
            'id' => 't',
            'query' => 'Rękawice',
            'expected_skus' => ['GOOD'],
            'acceptable_skus' => ['EQUIV'],
            'forbidden_skus' => ['BAD'],
            'note' => '',
        ];
        $score = function (array $rows, ?SearchEvalRunner $with = null) use ($runner, $case): array {
            $result = ['products' => $rows, 'model_state' => 'ranked'];

            return ($with ?? $runner)->scoreResult($case, $result, $this->traceFor($result), self::K);
        };

        $shown = $score([$this->row('GOOD', 99), $this->row('BAD', 60)]);
        $this->assertSame(['BAD'], $shown['violations']);
        $this->assertSame(['BAD'], $shown['forbidden_shown']);
        $this->assertSame([], $shown['violations_blocking']);

        $underEquivalent = $score([$this->row('EQUIV', 95), $this->row('BAD', 60), $this->row('GOOD', 50)]);
        $this->assertSame(['BAD'], $underEquivalent['forbidden_shown'], 'równoważnik ≥ progu też osłania propozycję');

        $high = $score([$this->row('GOOD', 99), $this->row('BAD', 99)]);
        $this->assertSame([], $high['forbidden_shown']);
        $this->assertSame(['BAD'], $high['violations_blocking'], 'zakazana z oceną ≥ progu blokuje');

        $alone = $score([$this->row('NEUTRAL', 99), $this->row('BAD', 60), $this->row('GOOD', 99)]);
        $this->assertSame(['BAD'], $alone['violations_blocking'], 'nad zakazaną nie stoi karta wzorcowa ≥ progu');

        $weak = $score([$this->row('GOOD', 60), $this->row('BAD', 55)]);
        $this->assertSame(['BAD'], $weak['violations_blocking'], 'wzorcowa poniżej progu nie osłania');

        $unscored = $score([$this->row('GOOD', 99), $this->row('BAD', null)]);
        $this->assertSame(['BAD'], $unscored['violations_blocking'], 'wiersz bez oceny blokuje');

        $summary = $runner->summarize([$shown, $high, $alone]);
        $this->assertSame(3, $summary['violations']);
        $this->assertSame(2, $summary['violations_blocking']);
        $this->assertSame(1, $summary['forbidden_shown']);

        // Próg pochodzi z Ustawień AI, nie ze stałej: przy 70 propozycja 68% pod kartą 99% jest ostrzeżeniem.
        $this->assertSame(['BAD'], $score([$this->row('GOOD', 99), $this->row('BAD', 68)])['violations_blocking']);
        AiSetting::query()->firstOrFail()->update(['match_min_score' => 70]);
        $stricter = app(SearchEvalRunner::class);
        $this->assertSame(70, $stricter->matchMinScore());
        $this->assertSame(['BAD'], $score([$this->row('GOOD', 99), $this->row('BAD', 68)], $stricter)['forbidden_shown']);
    }

    /** Raport bez nowych pól (sprzed 25.09.2026): wszystkie naruszenia liczą się jako blokujące, jak dotąd. */
    public function test_summary_of_rows_without_new_fields_counts_every_violation_as_blocking(): void
    {
        $summary = app(SearchEvalRunner::class)->summarize([
            ['id' => 'stary', 'error' => null, 'violations' => ['A', 'B'], 'duration_ms' => 10],
        ]);

        $this->assertSame(2, $summary['violations']);
        $this->assertSame(2, $summary['violations_blocking']);
        $this->assertSame(0, $summary['forbidden_shown']);
        $this->assertSame(0, $summary['acceptable_hits']);
    }

    /**
     * K11: raport niesie listę wyniku (top-k z procentem i źródłem), liczbę wierszy bez oceny modelu
     * (lista zapasowa catalog/rule) i zakazane SKU spoza katalogu — np. karta dystrybutora po łączeniu.
     */
    public function test_evaluate_reports_returned_top_unrated_rows_and_unknown_forbidden_skus(): void
    {
        Opisowy15Fixture::seed();
        $this->app->instance(OpenAiCompatibleClient::class, FakeSearchLlm::empty());
        $runner = app(SearchEvalRunner::class);
        $cases = [];
        foreach ($runner->loadCases(base_path(self::GOLDEN)) as $case) {
            $cases[$case['id']] = $case;
        }
        $query = $cases['opisowy15-14-pochlaniacz-a2']['query'];
        $products = app(ProductAiSearchService::class)->search($query, ProductAiSearchService::CATALOG_LIMIT)['products'];
        $this->assertNotSame([], $products, 'pochłaniacz A2 z fixture ma wracać z wyszukiwarki');

        $row = $runner->evaluate([
            ...$cases['opisowy15-14-pochlaniacz-a2'],
            'forbidden_skus' => ['NIE-MA-ZAKAZANEJ'],
            'acceptable_skus' => ['NIE-MA-ROWNOWAZNIKA'],
        ], 2, ProductAiSearchService::CATALOG_LIMIT);

        $this->assertNull($row['error']);
        $this->assertSame(SearchEvalMetrics::normalizeAll(['NIE-MA-ZAKAZANEJ']), $row['unknown_forbidden_skus']);
        $this->assertSame(SearchEvalMetrics::normalizeAll(['NIE-MA-ROWNOWAZNIKA']), $row['unknown_acceptable_skus']);
        $this->assertSame(
            array_map(static fn (array $p): string => (string) $p['sku'], array_slice($products, 0, 2)),
            array_column($row['returned_top'], 'sku'),
            'returned_top = pierwsze k wierszy wyniku',
        );
        $this->assertSame((int) $products[0]['ai_match_percent'], $row['returned_top'][0]['percent']);
        $this->assertSame($products[0]['ai_match_source'] ?? null, $row['returned_top'][0]['source']);
        $unrated = count(array_filter($products, static fn (array $p): bool => in_array(
            $p['ai_match_source'] ?? null,
            [ProductAiSearchService::MATCH_SOURCE_CATALOG, ProductAiSearchService::MATCH_SOURCE_RULE],
            true,
        )));
        $this->assertGreaterThan(0, $unrated, 'model milczy — wynik to lista zapasowa');
        $this->assertSame($unrated, $row['unrated_rows']);

        // Gałąź błędu ma te same klucze z pustymi wartościami.
        $error = $runner->evaluate([...$cases['opisowy15-14-pochlaniacz-a2'], 'query' => '   '], self::K, ProductAiSearchService::CATALOG_LIMIT);
        $this->assertNotNull($error['error']);
        $this->assertSame(array_keys($row), array_keys($error));
        foreach (['returned_top', 'acceptable_hits', 'unknown_forbidden_skus', 'unknown_acceptable_skus', 'forbidden_shown', 'violations_blocking'] as $key) {
            $this->assertSame([], $error[$key], $key);
        }
        $this->assertSame(0, $error['unrated_rows']);
    }

    /**
     * Zapytanie i listy z golden setu w dniu raportu eval_20260925_063133.
     *
     * @return array{id: string, query: string, expected_skus: list<string>, forbidden_skus: list<string>, note: string}
     */
    private function armenCase(): array
    {
        return [
            'id' => 'mail-artra-armen-9007-1010-s1',
            'query' => 'buty firmy ARTRA model ARMEN 9007 1010 S1',
            'expected_skus' => ['ARMEN 9007 1010 S1', 'ARMEN 9007 1010 S1 ESD', 'ARMEN 9007 1010 S1 P ESD'],
            'forbidden_skus' => ['ARMEN 9007 1010 O1 FO', 'ARMEN 9007 1010 O1 FO ESD', 'ARMEN 9007 6660 S1'],
            'note' => '',
        ];
    }

    /**
     * Wynik wyszukiwania ARMEN z produkcji (diag/cases, 25.09.2026, oba przebiegi identyczne): skrót nazwanego
     * modelu, warianty innego koloru z 60% (VARIANT_MISMATCH_SCORE) pod kartami 1010 z 99%.
     *
     * @return array<string, mixed>
     */
    private function armenResult(): array
    {
        $rows = [
            ['ARMEN 9007 Clip 1010 S1', 99],
            ['ARMEN 9007 1010 S1', 99],
            ['ARMEN 9007 1010 S1 ESD', 99],
            ['ARMEN 9007 1010 S1 P ESD', 99],
            ['ARMEN 9007 6660 S1', 60],
            ['ARMEN 9007 9360 S1', 60],
            ['ARMEN 9007 6660 S1 P', 60],
            ['ARMEN 9007 6660 S1 ESD', 60],
        ];
        foreach (['ARMEN 9007 1010 O1 FO', 'ARMEN 9007 1010 O1 FO ESD'] as $sku) {
            Product::query()->firstOrCreate(['sku' => $sku], ['name' => $sku, 'manufacturer' => 'ARTRA']);
        }

        return [
            'products' => array_map(fn (array $r): array => $this->row($r[0], $r[1]), $rows),
            'model_state' => ProductAiSearchService::MODEL_STATE_SKIPPED,
        ];
    }

    /**
     * Wiersz wyniku jak z wyszukiwarki; karta powstaje w bazie, żeby liczyła się do puli kandydatów.
     *
     * @return array<string, mixed>
     */
    private function row(string $sku, ?int $percent, ?string $source = null): array
    {
        $product = Product::query()->firstOrCreate(['sku' => $sku], ['name' => $sku, 'manufacturer' => 'ARTRA']);
        $row = ['id' => (int) $product->id, 'sku' => $sku, 'ai_match_percent' => $percent];
        if ($source !== null) {
            $row['ai_match_source'] = $source;
        }

        return $row;
    }

    /**
     * Ślad z pulą kandydatów = karty wyniku.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function traceFor(array $result): array
    {
        return ['candidate_ids' => array_column($result['products'], 'id'), 'passes' => 0, 'timings_ms' => []];
    }
}
