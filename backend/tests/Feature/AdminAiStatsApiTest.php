<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\SearchEvent;
use App\Models\Tender;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/admin/ai-stats — ekran „Statystyki AI”: koszt i przebieg pozycji wyszukiwania.
 * Dni liczone w czasie polskim; teraz = 25.09.2026 12:00 w Warszawie (10:00 UTC).
 */
final class AdminAiStatsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00:00', 'Europe/Warsaw'));
    }

    public function test_requires_ai_stats_permission(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson('/api/admin/ai-stats')->assertForbidden();

        // Sam dostęp do Administracji nie wystarcza — listy pokazują treść maili klientów.
        $adminAccessOnly = User::factory()->create();
        $adminAccessOnly->givePermissionTo('admin.access');
        Sanctum::actingAs($adminAccessOnly);
        $this->getJson('/api/admin/ai-stats')->assertForbidden();

        $adminAccessOnly->givePermissionTo('admin.ai_stats.view');
        Sanctum::actingAs($adminAccessOnly->fresh());
        $this->getJson('/api/admin/ai-stats')->assertOk();
    }

    public function test_rejects_bad_parameters(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        foreach ([
            'days=0', 'days=91', 'days=abc',
            'task=product_search_web', 'task=foo',
            'limit=0', 'limit=101',
            'small_pool=0', 'small_pool=81',
        ] as $query) {
            $this->getJson('/api/admin/ai-stats?'.$query)->assertStatus(422);
        }

        $this->getJson('/api/admin/ai-stats?days=90&task=battlecard&limit=100&small_pool=80')->assertOk();
    }

    public function test_empty_database_shape_and_recording_flag(): void
    {
        config(['ai.search_events_enabled' => false]);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $json = $this->getJson('/api/admin/ai-stats')
            ->assertOk()
            ->assertJsonPath('range', [
                'from' => '2026-09-19',
                'to' => '2026-09-25',
                'days' => 7,
                'timezone' => 'Europe/Warsaw',
            ])
            ->assertJsonPath('recording_enabled', false)
            ->assertJsonPath('usage_since', null)
            ->assertJsonPath('thresholds.small_pool', 24)
            ->assertJsonPath('daily', [])
            ->assertJsonPath('histogram.edges', [0, 2000, 5000, 10000, 20000, 40000])
            ->assertJsonPath('histogram.counts.tender_match', [0, 0, 0, 0, 0, 0])
            ->json();

        $this->assertSame(SearchEvent::AI_TASKS, array_column($json['summary'], 'task'));
        $this->assertSame(
            ['top_tokens', 'no_cards', 'small_pool', 'model_failed', 'model_empty'],
            array_keys($json['outliers']),
        );

        $agg = $json['summary'][0];
        $this->assertSame(0, $agg['events']);
        $this->assertSame(['sum' => 0, 'avg' => null, 'p50' => null, 'p95' => null, 'max' => null], $agg['prompt_tokens']);
        $this->assertNull($agg['unavailable_pct']);
        $this->assertSame(['ranked' => 0, 'empty' => 0, 'unavailable' => 0, 'skipped' => 0], $agg['states']);
    }

    public function test_aggregates_percentiles_histogram_and_daily(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->seedScenario();

        $json = $this->getJson('/api/admin/ai-stats')->assertOk()->json();

        $this->assertTrue($json['recording_enabled']);
        // Najstarsze zdarzenie z tokenami — także spoza zakresu; product_search_web i wiersze bez tokenów się nie liczą.
        $this->assertSame('2026-09-18T23:30:00+02:00', $json['usage_since']);

        $summary = collect($json['summary'])->keyBy('task');
        $tm = $summary['tender_match'];

        // 5 pozycji z kosztem + 1 sprzed zapisu kosztu; zdarzenie sprzed 19.09 (czas polski) poza zakresem.
        $this->assertSame(6, $tm['events']);
        $this->assertSame(5, $tm['with_usage']);
        $this->assertSame(4, $tm['model_asked']);
        $this->assertSame(['sum' => 72000, 'avg' => 14400, 'p50' => 6000, 'p95' => 50000, 'max' => 50000], $tm['prompt_tokens']);
        $this->assertSame(['sum' => 4700, 'avg' => 940, 'p50' => 500, 'p95' => 2600, 'max' => 2600], $tm['completion_tokens']);
        $this->assertEquals(['avg' => 3.4, 'max' => 7], $tm['llm_calls']);
        // Karty ostatniej oceny tylko tam, gdzie model był pytany: 5, 10, 24, 24.
        $this->assertEquals(['avg' => 15.8, 'p50' => 10, 'min' => 5], $tm['rank_cards']);
        // Pula ze wszystkich pozycji: 0, 5, 12, 30, 60, 80.
        $this->assertEquals(['avg' => 31.2, 'p50' => 12], $tm['candidate_count']);
        $this->assertSame(['ranked' => 2, 'empty' => 1, 'unavailable' => 1, 'skipped' => 1], $tm['states']);
        // Odsetki od pozycji ze znanym stanem modelu (5), nie od wszystkich (6).
        $this->assertEquals(20.0, $tm['unavailable_pct']);
        $this->assertEquals(20.0, $tm['empty_pct']);
        $this->assertSame(1, $tm['fallback']);
        // Czas fali jest wspólny — duration_ms tylko dla wyszukiwarki.
        $this->assertNull($tm['duration_ms']);

        $ps = $summary['product_search'];
        $this->assertSame(2, $ps['events']);
        $this->assertSame(['avg' => 2000, 'p50' => 1000, 'p95' => 3000], $ps['duration_ms']);
        $this->assertSame(0, $summary['inquiry']['events']);
        $this->assertNull($summary['inquiry']['duration_ms']);
        $this->assertFalse($summary->has('product_search_web'));

        $this->assertSame([1, 1, 1, 1, 0, 1], $json['histogram']['counts']['tender_match']);
        $this->assertSame([0, 2, 0, 0, 0, 0], $json['histogram']['counts']['product_search']);
        $this->assertArrayNotHasKey('product_search_web', $json['histogram']['counts']);

        $daily = collect($json['daily'])->where('task', 'tender_match')->keyBy('date');
        $this->assertSame(['2026-09-19', '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-25'], $daily->keys()->all());
        // 24.09 23:30 UTC to już 25.09 w Polsce.
        $this->assertSame(2, $daily['2026-09-25']['events']);
        $this->assertSame(4000, $daily['2026-09-25']['prompt_tokens']['sum']);
        $this->assertSame('2026-09-19', $json['daily'][0]['date']);
    }

    public function test_outlier_lists_rows_and_context(): void
    {
        $admin = User::factory()->withRole('admin')->create(['name' => 'Anna Admin']);
        Sanctum::actingAs($admin);
        $ids = $this->seedScenario($admin);

        $json = $this->getJson('/api/admin/ai-stats')->assertOk()->json();
        $outliers = $json['outliers'];

        $this->assertSame(
            [$ids['e5'], $ids['e4'], $ids['e3'], $ids['ps2'], $ids['e2'], $ids['ps1'], $ids['e1']],
            array_column($outliers['top_tokens'], 'id'),
        );
        $this->assertSame([$ids['e4']], array_column($outliers['no_cards'], 'id'));
        $this->assertSame([$ids['e3'], $ids['e2']], array_column($outliers['small_pool'], 'id'));
        $this->assertSame([$ids['e5']], array_column($outliers['model_failed'], 'id'));
        $this->assertSame([$ids['e3']], array_column($outliers['model_empty'], 'id'));

        $failed = $outliers['model_failed'][0];
        $this->assertSame('2026-09-23T12:00:00+02:00', $failed['created_at']);
        $this->assertSame('tender_match', $failed['task']);
        $this->assertSame(300, mb_strlen($failed['query']));
        $this->assertStringEndsWith('…', $failed['query']);
        $this->assertSame('unavailable', $failed['model_state']);
        $this->assertSame(50000, $failed['prompt_tokens']);
        $this->assertSame(2600, $failed['completion_tokens']);
        $this->assertSame(2300, $failed['reasoning_tokens']);
        $this->assertSame(7, $failed['llm_calls']);
        $this->assertSame(1, $failed['rank_calls']);
        $this->assertSame(24, $failed['rank_card_count']);
        $this->assertSame(80, $failed['candidate_count']);
        $this->assertSame(0, $failed['result_count']);
        $this->assertSame('qwen', $failed['model']);
        $this->assertSame('spark', $failed['provider']);
        $this->assertFalse($failed['fallback']);
        $this->assertSame('Model nie odpowiedział', $failed['ai_note']);
        $this->assertSame(['understand', 'rank'], array_column($failed['stages'], 'stage'));
        $this->assertSame('01JABCDEFGHJKMNPQRSTVWXYZ0', $failed['run_id']);
        $this->assertSame(['id' => $admin->id, 'name' => 'Anna Admin'], $failed['user']);
        $this->assertSame([
            'type' => 'tender',
            'id' => $ids['tender'],
            'label' => 'Przetarg PRZ/AI/1: Dostawa rękawic',
            'url' => '/tenders/'.$ids['tender'],
            'line_nos' => [7, 8],
        ], $failed['context']);

        $empty = $outliers['model_empty'][0];
        $this->assertSame([
            'type' => 'inquiry',
            'id' => $ids['inquiry'],
            'label' => 'Zapytanie #'.$ids['inquiry'].': Rękawice nitrylowe',
            'url' => '/inquiries/'.$ids['inquiry'],
            'line_nos' => [],
        ], $empty['context']);

        // Przetarg skasowany — bez linku, który prowadziłby donikąd.
        $noCards = $outliers['no_cards'][0];
        $this->assertNull($noCards['context']['url']);
        $this->assertSame('Przetarg #999999 (usunięty)', $noCards['context']['label']);
        $this->assertNull($noCards['user']);

        // Wyszukiwarka bez kontekstu.
        $ps = collect($outliers['top_tokens'])->firstWhere('id', $ids['ps2']);
        $this->assertNull($ps['context']);
        $this->assertSame([], $ps['stages']);
    }

    public function test_limit_small_pool_and_task_filter(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $ids = $this->seedScenario();

        $json = $this->getJson('/api/admin/ai-stats?limit=3&small_pool=8')->assertOk()->json();
        $this->assertSame([$ids['e5'], $ids['e4'], $ids['e3']], array_column($json['outliers']['top_tokens'], 'id'));
        $this->assertSame([$ids['e3']], array_column($json['outliers']['small_pool'], 'id'));
        $this->assertSame(8, $json['thresholds']['small_pool']);

        $json = $this->getJson('/api/admin/ai-stats?task=product_search')->assertOk()->json();
        $this->assertSame(['product_search'], array_column($json['summary'], 'task'));
        $this->assertSame(['product_search'], array_keys($json['histogram']['counts']));
        $this->assertSame([$ids['ps2'], $ids['ps1']], array_column($json['outliers']['top_tokens'], 'id'));
        $this->assertSame([], $json['outliers']['model_failed']);
        $this->assertSame(['product_search'], array_values(array_unique(array_column($json['daily'], 'task'))));

        // Zakres 3 dni: od 23.09 (czas polski).
        $json = $this->getJson('/api/admin/ai-stats?days=3&task=tender_match')->assertOk()->json();
        $this->assertSame('2026-09-23', $json['range']['from']);
        $this->assertSame(3, $json['summary'][0]['events']);
    }

    /**
     * @return array<string, int>
     */
    private function seedScenario(?User $user = null): array
    {
        $tender = Tender::query()->create([
            'number' => 'PRZ/AI/1',
            'title' => 'Dostawa rękawic',
            'client_id' => Client::query()->create(['name' => 'Szpital'])->id,
            'status' => 'wycena',
        ]);
        $inquiry = ClientInquiry::query()->create([
            'user_id' => ($user ?? User::factory()->create())->id,
            'tone' => 'formal',
            'source_subject' => 'Rękawice nitrylowe',
            'source_body' => 'Prosimy o ofertę.',
        ]);

        $ids = ['tender' => $tender->id, 'inquiry' => $inquiry->id];

        $ids['e1'] = $this->event([
            'prompt_tokens' => 1000, 'completion_tokens' => 100, 'llm_calls' => 1, 'rank_calls' => 1,
            'rank_card_count' => 24, 'candidate_count' => 60, 'model_state' => 'ranked', 'duration_ms' => 5000,
        ], '2026-09-25 08:00:00');
        $ids['e2'] = $this->event([
            'prompt_tokens' => 3000, 'completion_tokens' => 300, 'llm_calls' => 2, 'rank_calls' => 1,
            'rank_card_count' => 10, 'candidate_count' => 12, 'model_state' => 'ranked', 'fallback' => true,
        ], '2026-09-24 23:30:00');
        $ids['e3'] = $this->event([
            'prompt_tokens' => 6000, 'completion_tokens' => 500, 'llm_calls' => 3, 'rank_calls' => 2,
            'rank_card_count' => 5, 'candidate_count' => 5, 'model_state' => 'empty',
            'context_type' => SearchEvent::CONTEXT_INQUIRY, 'context_id' => $inquiry->id,
        ], '2026-09-22 10:00:00');
        $ids['e4'] = $this->event([
            'prompt_tokens' => 12000, 'completion_tokens' => 1200, 'llm_calls' => 4, 'rank_calls' => 0,
            'candidate_count' => 0, 'result_count' => 0, 'model_state' => 'skipped',
            'context_type' => SearchEvent::CONTEXT_TENDER, 'context_id' => 999999,
        ], '2026-09-18 22:30:00');
        $ids['e5'] = $this->event([
            'user_id' => $user?->id,
            'query' => str_repeat('Rękawice ochronne nitrylowe ', 20),
            'prompt_tokens' => 50000, 'completion_tokens' => 2600, 'reasoning_tokens' => 2300, 'llm_calls' => 7,
            'rank_calls' => 1, 'rank_card_count' => 24, 'candidate_count' => 80, 'result_count' => 0,
            'model_state' => 'unavailable', 'model' => 'qwen', 'provider' => 'spark', 'fallback' => false,
            'ai_note' => 'Model nie odpowiedział',
            'usage' => [
                ['stage' => 'understand', 'prompt_tokens' => 1000, 'completion_tokens' => 100, 'calls' => 1],
                ['stage' => 'rank', 'cards' => 24, 'prompt_tokens' => 49000, 'completion_tokens' => 2500, 'calls' => 6],
            ],
            'run_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0',
            'context_type' => SearchEvent::CONTEXT_TENDER, 'context_id' => $tender->id, 'context_items' => [7, 8],
        ], '2026-09-23 10:00:00');
        // Pozycja sprzed zapisu kosztu.
        $ids['e6'] = $this->event(['candidate_count' => 30], '2026-09-21 10:00:00');
        // 18.09 23:30 w Polsce — poza zakresem 7 dni, ale to najstarsze zdarzenie z tokenami.
        $ids['e7'] = $this->event(['prompt_tokens' => 99999, 'completion_tokens' => 1, 'rank_calls' => 0, 'result_count' => 0], '2026-09-18 21:30:00');
        // Starsze bez tokenów i wyszukiwanie w sieci — nie wyznaczają „dane od”.
        $this->event(['candidate_count' => 1], '2026-09-01 10:00:00');
        $this->event(['task' => SearchEvent::TASK_PRODUCT_SEARCH_WEB, 'prompt_tokens' => 70000], '2026-09-10 10:00:00');
        // Wyszukiwanie w sieci w zakresie — pomijane wszędzie.
        $this->event([
            'task' => SearchEvent::TASK_PRODUCT_SEARCH_WEB, 'prompt_tokens' => 80000, 'rank_calls' => 0,
            'result_count' => 0, 'model_state' => 'unavailable',
        ], '2026-09-24 10:00:00');

        $ids['ps1'] = $this->event([
            'task' => SearchEvent::TASK_PRODUCT_SEARCH, 'prompt_tokens' => 2000, 'completion_tokens' => 50,
            'rank_calls' => 0, 'result_count' => 3, 'duration_ms' => 1000, 'model_state' => 'skipped',
        ], '2026-09-24 09:00:00');
        $ids['ps2'] = $this->event([
            'task' => SearchEvent::TASK_PRODUCT_SEARCH, 'prompt_tokens' => 4000, 'completion_tokens' => 80,
            'rank_calls' => 1, 'rank_card_count' => 24, 'duration_ms' => 3000, 'model_state' => 'ranked',
        ], '2026-09-24 09:30:00');

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(array $attributes, string $createdAtUtc): int
    {
        $event = new SearchEvent;
        $event->forceFill(array_merge([
            'task' => SearchEvent::TASK_TENDER_MATCH,
            'query' => 'Rękawice nitrylowe',
            'result_count' => 1,
            'candidate_count' => 0,
        ], $attributes));
        $event->created_at = CarbonImmutable::parse($createdAtUtc, 'UTC');
        $event->updated_at = CarbonImmutable::parse($createdAtUtc, 'UTC');
        $event->save();

        return (int) $event->id;
    }
}
