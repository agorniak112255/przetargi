<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SearchEvent;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\NbpExchangeRateService;
use App\Services\ProductAiSearchService;
use App\Services\Search\AiProductSearch;
use App\Services\Search\SearchEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ProductAiSearchController extends Controller
{
    public function __construct(
        private readonly AiProductSearch $search,
        private readonly NbpExchangeRateService $fx,
        private readonly AiSettingsService $aiSettings,
        private readonly SearchEventRecorder $events,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'min:3', 'max:2000'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:80'],
            'web' => ['sometimes', 'boolean'],
        ]);

        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $webOnly = (bool) ($data['web'] ?? false);

        try {
            $result = $this->search->find(
                (string) $data['query'],
                (int) ($data['limit'] ?? ($webOnly ? ProductAiSearchService::WEB_LIMIT : $this->aiSettings->catalogSearchLimit())),
                AiTask::ProductSearch,
                false,
                $webOnly,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Błąd wyszukiwania AI: '.$e->getMessage(),
            ], 422);
        }

        $result['products'] = array_map(
            fn (array $row): array => $this->fx->appendPricePln($row),
            $result['products'],
        );

        // Ślad wykonania służy telemetrii, nie klientowi — do odpowiedzi API nie wchodzi.
        $trace = is_array($result['trace'] ?? null) ? $result['trace'] : [];
        unset($result['trace']);

        // Telemetria: bez niej nie da się zmierzyć, czy zmiana w rankingu pomogła.
        $event = $this->events->record(
            (string) $data['query'],
            $result,
            $trace,
            $request->user()?->id,
            $webOnly ? SearchEvent::TASK_PRODUCT_SEARCH_WEB : SearchEvent::TASK_PRODUCT_SEARCH,
        );
        $result['search_event_id'] = $event?->id;

        return response()->json($result);
    }
}
