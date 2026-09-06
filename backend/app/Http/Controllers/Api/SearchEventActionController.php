<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SearchEvent;
use App\Models\SearchEventAction;
use App\Services\Search\SearchEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sygnał zwrotny do wyniku wyszukiwania AI — co użytkownik faktycznie wziął.
 * Wywołanie jest „fire and forget”: front nie czeka na odpowiedź i nie pokazuje
 * błędu, więc kontroler nigdy nie zwraca 5xx dla samego zapisu.
 */
class SearchEventActionController extends Controller
{
    public function __construct(
        private readonly SearchEventRecorder $events,
    ) {}

    public function store(Request $request, SearchEvent $searchEvent): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'action' => ['required', 'string', Rule::in(SearchEventAction::ACTIONS)],
            'position' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $action = $this->events->recordAction(
            $searchEvent,
            (int) $data['product_id'],
            (string) $data['action'],
            $request->user()?->id,
            isset($data['position']) ? (int) $data['position'] : null,
        );

        return response()->json(['recorded' => $action !== null]);
    }
}
