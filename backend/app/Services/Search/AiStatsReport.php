<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\ClientInquiry;
use App\Models\SearchEvent;
use App\Models\Tender;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Ekran „Statystyki AI”: koszt (tokeny) i przebieg (karty do oceny, pula, stan modelu) pozycji wyszukiwania
 * zapisanych w `search_events`.
 *
 * Agregaty i percentyle liczone w PHP po kolumnach skalarnych — bez funkcji JSON ani percentyli bazy (testy idą
 * na sqlite). Zdarzenia czytane paczkami po id i bez modeli Eloquent: 90 dni fal „Dopasuj wszystkie” to
 * dziesiątki tysięcy wierszy. Dni liczone w czasie polskim, `created_at` w bazie jest w strefie aplikacji.
 */
final class AiStatsReport
{
    public const TIMEZONE = 'Europe/Warsaw';

    public const DEFAULT_DAYS = 7;

    public const MAX_DAYS = 90;

    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 100;

    /** Tyle kart model dostaje do oceny przy pełnej puli (ProductAiSearchService::RANK_CARDS). */
    public const DEFAULT_SMALL_POOL = 24;

    /** Górna granica jak limit wyników wyszukiwania w katalogu (AiSettingsService::CATALOG_SEARCH_LIMIT_MAX). */
    public const MAX_SMALL_POOL = 80;

    /** Dolne granice koszy histogramu tokenów wejścia; ostatni kosz jest otwarty. */
    public const HISTOGRAM_EDGES = [0, 2000, 5000, 10000, 20000, 40000];

    public const MODEL_STATES = ['ranked', 'empty', 'unavailable', 'skipped'];

    private const QUERY_CHARS = 300;

    private const LABEL_CHARS = 120;

    private const CHUNK = 2000;

    /** Kolumny potrzebne do agregatów — bez ciężkich list JSON (kandydaci, karty, wynik). */
    private const AGG_COLUMNS = [
        'id', 'task', 'created_at', 'prompt_tokens', 'completion_tokens', 'llm_calls', 'rank_calls',
        'rank_card_count', 'candidate_count', 'model_state', 'fallback', 'duration_ms',
    ];

    private const ROW_COLUMNS = [
        'id', 'user_id', 'task', 'created_at', 'query', 'model_state', 'prompt_tokens', 'completion_tokens',
        'reasoning_tokens', 'llm_calls', 'rank_calls', 'rank_card_count', 'candidate_count', 'result_count', 'model',
        'provider', 'fallback', 'ai_note', 'usage', 'context_type', 'context_id', 'context_items', 'run_id',
    ];

    public function __construct(
        private readonly SearchEventRecorder $recorder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $days, ?string $task, int $limit, int $smallPool): array
    {
        $tasks = $task === null ? SearchEvent::AI_TASKS : [$task];

        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $from = $today->subDays($days - 1);

        // Początki kolejnych dni w czasie polskim zapisane tak jak created_at w bazie — porównanie napisów
        // „Y-m-d H:i:s” zamiast Carbona na każdym wierszu.
        $dates = [];
        $dayStarts = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->addDays($i);
            $dates[] = $day->format('Y-m-d');
            $dayStarts[] = $this->dbTime($day);
        }
        $fromDb = $dayStarts[0];
        $untilDb = $this->dbTime($today->addDay());

        /** @var array<string, array<string, mixed>> $summary */
        $summary = [];
        foreach ($tasks as $t) {
            $summary[$t] = $this->emptyAcc();
        }
        /** @var array<string, array<string, mixed>> $daily klucz „data|rodzaj” */
        $daily = [];

        // lazyById idzie po kluczu głównym — bez dolnej granicy id MySQL mógłby czytać tabelę od najstarszego wiersza
        // (szerokie wiersze z listami JSON) aż do początku zakresu. Granica z indeksu created_at.
        $minId = (int) (DB::table('search_events')->where('created_at', '>=', $fromDb)->min('id') ?? 0);
        $rows = DB::table('search_events')
            ->select(self::AGG_COLUMNS)
            ->where('id', '>=', $minId)
            ->whereIn('task', $tasks)
            ->where('created_at', '>=', $fromDb)
            ->where('created_at', '<', $untilDb)
            ->lazyById(self::CHUNK, 'id');

        foreach ($rows as $row) {
            $t = (string) $row->task;
            if (! isset($summary[$t])) {
                continue;
            }
            $date = $dates[$this->dayIndex($dayStarts, (string) $row->created_at)];
            $this->add($summary[$t], $row);
            $key = $date.'|'.$t;
            $daily[$key] ??= $this->emptyAcc();
            $this->add($daily[$key], $row);
        }

        $dailyOut = [];
        foreach ($dates as $date) {
            foreach ($tasks as $t) {
                $key = $date.'|'.$t;
                if (isset($daily[$key])) {
                    $dailyOut[] = ['date' => $date] + $this->aggregate($daily[$key], $t);
                }
            }
        }

        $histogram = [];
        foreach ($tasks as $t) {
            $histogram[$t] = $this->histogram($summary[$t]['prompt']);
        }

        $summaryOut = [];
        foreach ($tasks as $t) {
            $summaryOut[] = $this->aggregate($summary[$t], $t);
        }
        unset($summary, $daily);

        return [
            'range' => [
                'from' => $from->format('Y-m-d'),
                'to' => $today->format('Y-m-d'),
                'days' => $days,
                'timezone' => self::TIMEZONE,
            ],
            'recording_enabled' => $this->recorder->enabled(),
            'usage_since' => $this->usageSince(),
            'thresholds' => ['small_pool' => $smallPool],
            'summary' => $summaryOut,
            'daily' => $dailyOut,
            'histogram' => [
                'edges' => self::HISTOGRAM_EDGES,
                'counts' => $histogram,
            ],
            'outliers' => $this->outliers($tasks, $fromDb, $untilDb, $limit, $smallPool),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyAcc(): array
    {
        return [
            'events' => 0,
            'with_usage' => 0,
            'model_asked' => 0,
            'prompt' => [],
            'completion' => [],
            'calls_sum' => 0,
            'calls_n' => 0,
            'calls_max' => null,
            'rank_cards' => [],
            'candidates' => [],
            'durations' => [],
            'states' => array_fill_keys(self::MODEL_STATES, 0),
            'fallback' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $acc
     */
    private function add(array &$acc, object $row): void
    {
        $acc['events']++;

        // Wiersze sprzed zapisu kosztu (prompt_tokens = null) liczą się tylko jako pozycje.
        if ($row->prompt_tokens !== null) {
            $acc['with_usage']++;
            $acc['prompt'][] = (int) $row->prompt_tokens;
            $acc['completion'][] = (int) ($row->completion_tokens ?? 0);
        }

        if ($row->llm_calls !== null) {
            $calls = (int) $row->llm_calls;
            $acc['calls_sum'] += $calls;
            $acc['calls_n']++;
            $acc['calls_max'] = $acc['calls_max'] === null ? $calls : max($acc['calls_max'], $calls);
        }

        if ($row->rank_calls !== null && (int) $row->rank_calls >= 1) {
            $acc['model_asked']++;
            if ($row->rank_card_count !== null) {
                $acc['rank_cards'][] = (int) $row->rank_card_count;
            }
        }

        $acc['candidates'][] = (int) $row->candidate_count;

        if ($row->duration_ms !== null) {
            $acc['durations'][] = (int) $row->duration_ms;
        }

        $state = $row->model_state;
        if (is_string($state) && isset($acc['states'][$state])) {
            $acc['states'][$state]++;
        }

        if ($row->fallback !== null && (bool) (int) $row->fallback) {
            $acc['fallback']++;
        }
    }

    /**
     * @param  array<string, mixed>  $acc
     * @return array<string, mixed>
     */
    private function aggregate(array $acc, string $task): array
    {
        $rankCards = $acc['rank_cards'];
        sort($rankCards);
        $candidates = $acc['candidates'];
        sort($candidates);

        // Odsetki awarii i pustych ocen od pozycji ze znanym stanem modelu — wiersze sprzed zapisu kosztu
        // nie rozmywają wyniku.
        $known = array_sum($acc['states']);

        $duration = null;
        if ($task === SearchEvent::TASK_PRODUCT_SEARCH) {
            $durations = $acc['durations'];
            sort($durations);
            $duration = [
                'avg' => $this->avgInt($durations),
                'p50' => $this->percentile($durations, 50),
                'p95' => $this->percentile($durations, 95),
            ];
        }

        return [
            'task' => $task,
            'events' => $acc['events'],
            'with_usage' => $acc['with_usage'],
            'model_asked' => $acc['model_asked'],
            'prompt_tokens' => $this->tokenStats($acc['prompt']),
            'completion_tokens' => $this->tokenStats($acc['completion']),
            'llm_calls' => [
                'avg' => $acc['calls_n'] > 0 ? round($acc['calls_sum'] / $acc['calls_n'], 1) : null,
                'max' => $acc['calls_max'],
            ],
            'rank_cards' => [
                'avg' => $this->avgOne($rankCards),
                'p50' => $this->percentile($rankCards, 50),
                'min' => $rankCards === [] ? null : $rankCards[0],
            ],
            'candidate_count' => [
                'avg' => $this->avgOne($candidates),
                'p50' => $this->percentile($candidates, 50),
            ],
            'states' => $acc['states'],
            'unavailable_pct' => $known > 0 ? round($acc['states']['unavailable'] * 100 / $known, 1) : null,
            'empty_pct' => $known > 0 ? round($acc['states']['empty'] * 100 / $known, 1) : null,
            'fallback' => $acc['fallback'],
            'duration_ms' => $duration,
        ];
    }

    /**
     * @param  list<int>  $values
     * @return array{sum: int, avg: ?int, p50: ?int, p95: ?int, max: ?int}
     */
    private function tokenStats(array $values): array
    {
        sort($values);

        return [
            'sum' => array_sum($values),
            'avg' => $this->avgInt($values),
            'p50' => $this->percentile($values, 50),
            'p95' => $this->percentile($values, 95),
            'max' => $values === [] ? null : $values[count($values) - 1],
        ];
    }

    /**
     * Percentyl metodą najbliższej rangi: wartość, która faktycznie wystąpiła (przy parzystej liczbie mediana
     * to niższa ze środkowych). Ranga liczona na liczbach całkowitych — 0,95 × n bez błędu zaokrąglenia.
     *
     * @param  list<int>  $sorted  posortowane rosnąco
     */
    private function percentile(array $sorted, int $p): ?int
    {
        $n = count($sorted);
        if ($n === 0) {
            return null;
        }
        $rank = intdiv($p * $n + 99, 100);

        return $sorted[max(0, min($n - 1, $rank - 1))];
    }

    /**
     * @param  list<int>  $values
     */
    private function avgInt(array $values): ?int
    {
        return $values === [] ? null : (int) round(array_sum($values) / count($values));
    }

    /**
     * @param  list<int>  $values
     */
    private function avgOne(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 1);
    }

    /**
     * @param  list<int>  $promptTokens
     * @return list<int>
     */
    private function histogram(array $promptTokens): array
    {
        $edges = self::HISTOGRAM_EDGES;
        $counts = array_fill(0, count($edges), 0);
        foreach ($promptTokens as $value) {
            $bin = 0;
            for ($i = count($edges) - 1; $i >= 0; $i--) {
                if ($value >= $edges[$i]) {
                    $bin = $i;
                    break;
                }
            }
            $counts[$bin]++;
        }

        return $counts;
    }

    /**
     * Indeks dnia, do którego należy znacznik (ostatni początek dnia ≤ znacznik).
     *
     * @param  list<string>  $dayStarts  rosnąco, format „Y-m-d H:i:s”
     */
    private function dayIndex(array $dayStarts, string $createdAt): int
    {
        $lo = 0;
        $hi = count($dayStarts) - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if (strcmp($dayStarts[$mid], $createdAt) <= 0) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        return $lo;
    }

    private function dbTime(CarbonImmutable $moment): string
    {
        return $moment->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    /** Od kiedy zapisuje się koszt: najstarsze zdarzenie z tokenami (niezależnie od wybranego zakresu). */
    private function usageSince(): ?string
    {
        $first = SearchEvent::query()
            ->whereIn('task', SearchEvent::AI_TASKS)
            ->whereNotNull('prompt_tokens')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first(['id', 'created_at']);

        return $first?->created_at?->copy()->setTimezone(self::TIMEZONE)->toIso8601String();
    }

    /**
     * @param  list<string>  $tasks
     * @return array<string, list<array<string, mixed>>>
     */
    private function outliers(array $tasks, string $fromDb, string $untilDb, int $limit, int $smallPool): array
    {
        $base = fn (): Builder => SearchEvent::query()
            ->select(self::ROW_COLUMNS)
            ->with('user:id,name')
            ->whereIn('task', $tasks)
            ->where('created_at', '>=', $fromDb)
            ->where('created_at', '<', $untilDb);

        $lists = [
            'top_tokens' => $base()
                ->whereNotNull('prompt_tokens')
                ->orderByDesc('prompt_tokens')
                ->orderByDesc('id'),
            'no_cards' => $base()
                ->where('rank_calls', 0)
                ->where('result_count', 0)
                ->orderByDesc('created_at')
                ->orderByDesc('id'),
            // Najmniejsza pula najpierw — tu model miał najmniej kart do wyboru.
            'small_pool' => $base()
                ->where('rank_calls', '>=', 1)
                ->where('rank_card_count', '<', $smallPool)
                ->orderBy('rank_card_count')
                ->orderByDesc('id'),
            'model_failed' => $base()
                ->where('model_state', 'unavailable')
                ->orderByDesc('created_at')
                ->orderByDesc('id'),
            'model_empty' => $base()
                ->where('model_state', 'empty')
                ->orderByDesc('created_at')
                ->orderByDesc('id'),
        ];

        /** @var array<string, list<SearchEvent>> $events */
        $events = [];
        foreach ($lists as $name => $query) {
            $events[$name] = $query->limit($limit)->get()->all();
        }

        $tenderIds = [];
        $inquiryIds = [];
        foreach ($events as $list) {
            foreach ($list as $event) {
                $id = (int) ($event->context_id ?? 0);
                if ($id <= 0) {
                    continue;
                }
                if ($event->context_type === SearchEvent::CONTEXT_TENDER) {
                    $tenderIds[$id] = true;
                } elseif ($event->context_type === SearchEvent::CONTEXT_INQUIRY) {
                    $inquiryIds[$id] = true;
                }
            }
        }

        $tenders = $tenderIds === [] ? [] : Tender::query()
            ->whereIn('id', array_keys($tenderIds))
            ->get(['id', 'number', 'title'])
            ->keyBy('id')
            ->all();
        $inquiries = $inquiryIds === [] ? [] : ClientInquiry::query()
            ->whereIn('id', array_keys($inquiryIds))
            ->get(['id', 'source_subject', 'reply_subject'])
            ->keyBy('id')
            ->all();

        $out = [];
        foreach ($events as $name => $list) {
            $out[$name] = array_map(fn (SearchEvent $event): array => $this->row($event, $tenders, $inquiries), $list);
        }

        return $out;
    }

    /**
     * @param  array<int, Tender>  $tenders
     * @param  array<int, ClientInquiry>  $inquiries
     * @return array<string, mixed>
     */
    private function row(SearchEvent $event, array $tenders, array $inquiries): array
    {
        $user = $event->user;

        return [
            'id' => (int) $event->id,
            'created_at' => $event->created_at?->copy()->setTimezone(self::TIMEZONE)->toIso8601String(),
            'task' => (string) $event->task,
            'query' => $this->clip((string) $event->query, self::QUERY_CHARS),
            'model_state' => $event->model_state,
            'prompt_tokens' => $event->prompt_tokens,
            'completion_tokens' => $event->completion_tokens,
            'reasoning_tokens' => $event->reasoning_tokens,
            'llm_calls' => $event->llm_calls,
            'rank_calls' => $event->rank_calls,
            'rank_card_count' => $event->rank_card_count,
            'candidate_count' => (int) $event->candidate_count,
            'result_count' => (int) $event->result_count,
            'model' => $event->model,
            'provider' => $event->provider,
            'fallback' => $event->fallback,
            'ai_note' => $event->ai_note,
            'stages' => $this->stages($event->usage),
            'context' => $this->context($event, $tenders, $inquiries),
            'run_id' => $event->run_id,
            'user' => $user === null ? null : ['id' => (int) $user->id, 'name' => (string) $user->name],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stages(mixed $usage): array
    {
        if (! is_array($usage)) {
            return [];
        }

        return array_values(array_filter($usage, 'is_array'));
    }

    /**
     * @param  array<int, Tender>  $tenders
     * @param  array<int, ClientInquiry>  $inquiries
     * @return array{type: string, id: int, label: string, url: ?string, line_nos: list<int>}|null
     */
    private function context(SearchEvent $event, array $tenders, array $inquiries): ?array
    {
        $id = (int) ($event->context_id ?? 0);
        if ($id <= 0) {
            return null;
        }

        $lineNos = [];
        foreach (is_array($event->context_items) ? $event->context_items : [] as $lineNo) {
            if (is_int($lineNo) || (is_string($lineNo) && ctype_digit($lineNo))) {
                $lineNos[] = (int) $lineNo;
            }
        }

        if ($event->context_type === SearchEvent::CONTEXT_TENDER) {
            $tender = $tenders[$id] ?? null;

            return [
                'type' => SearchEvent::CONTEXT_TENDER,
                'id' => $id,
                'label' => $tender === null
                    ? 'Przetarg #'.$id.' (usunięty)'
                    : 'Przetarg '.((string) $tender->number !== '' ? $tender->number : '#'.$id).': '
                        .$this->clip((string) $tender->title, self::LABEL_CHARS),
                'url' => $tender === null ? null : '/tenders/'.$id,
                'line_nos' => $lineNos,
            ];
        }

        if ($event->context_type === SearchEvent::CONTEXT_INQUIRY) {
            $inquiry = $inquiries[$id] ?? null;
            $subject = $inquiry === null
                ? ''
                : trim((string) ($inquiry->source_subject ?: $inquiry->reply_subject ?: ''));

            return [
                'type' => SearchEvent::CONTEXT_INQUIRY,
                'id' => $id,
                'label' => $inquiry === null
                    ? 'Zapytanie #'.$id.' (usunięte)'
                    : 'Zapytanie #'.$id.($subject !== '' ? ': '.$this->clip($subject, self::LABEL_CHARS) : ''),
                'url' => $inquiry === null ? null : '/inquiries/'.$id,
                'line_nos' => $lineNos,
            ];
        }

        return null;
    }

    private function clip(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
