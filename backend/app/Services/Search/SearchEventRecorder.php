<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\SearchEvent;
use App\Models\SearchEventAction;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Zapis telemetrii wyszukiwania. Nigdy nie może wywrócić samego wyszukiwania —
 * każdy błąd kończy się wpisem w logu i null-em.
 */
final class SearchEventRecorder
{
    /** Ile pozycji zapisujemy z każdej listy — zdarzenie ma być tanie w bazie. */
    private const MAX_IDS = 200;

    public function enabled(): bool
    {
        return (bool) config('ai.search_events_enabled', true);
    }

    /**
     * @param  array<string, mixed>  $result  odpowiedź z ProductAiSearchService::search() albo wiersz searchMany()
     * @param  array<string, mixed>  $trace  ProductAiSearchService::lastTrace() albo `trace` wiersza fali
     * @param  array{run_id?: ?string, context_type?: ?string, context_id?: ?int, context_items?: list<int>}  $context  przetarg / zapytanie i przebieg
     */
    public function record(
        string $query,
        array $result,
        array $trace,
        ?int $userId,
        string $task = SearchEvent::TASK_PRODUCT_SEARCH,
        array $context = [],
    ): ?SearchEvent {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $products = is_array($result['products'] ?? null) ? $result['products'] : [];
            $candidates = $this->ids($trace['candidate_ids'] ?? []);
            $timings = is_array($trace['timings_ms'] ?? null) ? $trace['timings_ms'] : [];

            return SearchEvent::query()->create([
                ...$this->usageColumns($result),
                ...$this->contextColumns($context),
                'user_id' => $userId,
                'task' => $task,
                'prompt_version' => (string) ($trace['prompt_version'] ?? ''),
                'query' => mb_substr($query, 0, 2000),
                'needed' => $this->clip($result['needed'] ?? null, 255),
                'intent' => [
                    'needed' => (string) ($result['needed'] ?? ''),
                    'search_phrases' => array_slice(
                        is_array($result['search_phrases'] ?? null) ? $result['search_phrases'] : [],
                        0,
                        12
                    ),
                    // Awaria kroku „zrozum” — bez niej intent lokalny (cały tekst
                    // jako `needed`) wygląda w telemetrii jak decyzja modelu.
                    ...(is_string($trace['intent_error'] ?? null) && $trace['intent_error'] !== ''
                        ? ['model_error' => mb_substr($trace['intent_error'], 0, 300)]
                        : []),
                ],
                'candidate_ids' => $candidates,
                'rank_card_ids' => $this->ids($trace['rank_card_ids'] ?? []),
                'llm_matches' => $this->matches($trace['llm_matches'] ?? []),
                'returned' => $this->returned($products),
                'result_count' => count($products),
                'candidate_count' => count($candidates),
                'passes' => (int) ($trace['passes'] ?? 0),
                'duration_ms' => isset($timings['total']) ? (int) $timings['total'] : null,
                'timings_ms' => $timings,
                'ai_note' => $this->clip($result['ai_note'] ?? null, 255),
            ]);
        } catch (Throwable $e) {
            Log::warning('search-event.record-failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function recordAction(
        SearchEvent $event,
        int $productId,
        string $action,
        ?int $userId,
        ?int $position = null,
    ): ?SearchEventAction {
        if (! in_array($action, SearchEventAction::ACTIONS, true)) {
            return null;
        }

        try {
            return SearchEventAction::query()->updateOrCreate(
                [
                    'search_event_id' => $event->id,
                    'product_id' => $productId,
                    'action' => $action,
                ],
                [
                    'user_id' => $userId,
                    'position' => $position ?? $event->positionOf($productId),
                ],
            );
        } catch (Throwable $e) {
            Log::warning('search-event.action-failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[] = $id;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, self::MAX_IDS);
    }

    /**
     * @return list<array{id: int, score: int}>
     */
    private function matches(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $out[] = ['id' => $id, 'score' => (int) ($row['score'] ?? 0)];
            }
        }

        return array_slice($out, 0, self::MAX_IDS);
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return list<array{id: int, sku: string, score: int}>
     */
    private function returned(array $products): array
    {
        $out = [];
        foreach ($products as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'sku' => mb_substr((string) ($row['sku'] ?? ''), 0, 100),
                'score' => (int) ($row['ai_match_percent'] ?? 0),
            ];
        }

        return array_slice($out, 0, self::MAX_IDS);
    }

    /**
     * Koszt pozycji z `ai_usage` wyniku wyszukiwarki (kontrakt „Statystyki AI”). Brak `ai_usage` (stary wywołujący,
     * wyszukiwanie w sieci) zostawia kolumny puste — statystyka liczy tylko pozycje z danymi.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function usageColumns(array $result): array
    {
        $state = is_string($result['model_state'] ?? null) ? mb_substr($result['model_state'], 0, 16) : null;
        $usage = is_array($result['ai_usage'] ?? null) ? $result['ai_usage'] : null;
        if ($usage === null) {
            return ['model_state' => $state];
        }
        $int = static fn (string $key): int => max(0, (int) ($usage[$key] ?? 0));
        $stages = is_array($usage['stages'] ?? null) ? array_values(array_slice($usage['stages'], 0, 20)) : [];

        return [
            'model_state' => $state,
            'prompt_tokens' => $int('prompt_tokens'),
            'completion_tokens' => $int('completion_tokens'),
            'reasoning_tokens' => $int('reasoning_tokens'),
            'llm_calls' => min(65535, $int('calls') + $int('failed_calls')),
            'rank_calls' => min(255, $int('rank_calls')),
            'rank_card_count' => isset($usage['rank_card_count']) ? min(65535, max(0, (int) $usage['rank_card_count'])) : null,
            'model' => $this->clip($usage['model'] ?? null, 191),
            'provider' => $this->clip($usage['provider'] ?? null, 191),
            'fallback' => isset($usage['fallback']) ? (bool) $usage['fallback'] : null,
            'usage' => $stages,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function contextColumns(array $context): array
    {
        $items = [];
        foreach (is_array($context['context_items'] ?? null) ? $context['context_items'] : [] as $item) {
            if (is_numeric($item)) {
                $items[] = (int) $item;
            }
        }

        return [
            'run_id' => $this->clip($context['run_id'] ?? null, 26),
            'context_type' => $this->clip($context['context_type'] ?? null, 24),
            'context_id' => isset($context['context_id']) ? (int) $context['context_id'] : null,
            'context_items' => $items === [] ? null : array_slice(array_values(array_unique($items)), 0, 200),
        ];
    }

    private function clip(mixed $value, int $length): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
