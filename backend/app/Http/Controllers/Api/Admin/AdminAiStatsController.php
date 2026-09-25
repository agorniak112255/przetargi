<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SearchEvent;
use App\Services\Search\AiStatsReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Statystyki AI: koszt i przebieg pozycji wyszukiwania (wyszukiwarka, przetargi, zapytania z poczty, zamienniki).
 * Listy odstających pozycji pokazują treść zapytań z maili klientów — stąd osobne uprawnienie admin.ai_stats.view.
 */
class AdminAiStatsController extends Controller
{
    public function __construct(
        private readonly AiStatsReport $report,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:'.AiStatsReport::MAX_DAYS],
            'task' => ['nullable', 'string', Rule::in(SearchEvent::AI_TASKS)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.AiStatsReport::MAX_LIMIT],
            'small_pool' => ['nullable', 'integer', 'min:1', 'max:'.AiStatsReport::MAX_SMALL_POOL],
        ]);

        return response()->json($this->report->build(
            (int) ($validated['days'] ?? AiStatsReport::DEFAULT_DAYS),
            isset($validated['task']) ? (string) $validated['task'] : null,
            (int) ($validated['limit'] ?? AiStatsReport::DEFAULT_LIMIT),
            (int) ($validated['small_pool'] ?? AiStatsReport::DEFAULT_SMALL_POOL),
        ));
    }
}
