<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Chat\ChatCallService;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rozmowy głosowe i wideo w czacie (LiveKit). Każda trasa sprawdza członkostwo w rozmowie czatu (404 dla obcych).
 * Poza grupą `log.activity`, jak cały czat.
 */
class ChatCallController extends Controller
{
    public function __construct(
        private readonly ChatService $chat,
        private readonly ChatCallService $calls,
    ) {}

    public function config(): JsonResponse
    {
        return response()->json($this->calls->config());
    }

    /** 201 — nowa rozmowa; 200 — w tej rozmowie czatu już dzwoni albo trwa rozmowa: dołączenie do niej. */
    public function store(Request $request, int $conversation): JsonResponse
    {
        $me = $this->me($request);
        $row = $this->chat->findForUser($me, $conversation);
        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in(['audio', 'video'])],
        ], [
            'kind.required' => 'Wybierz rozmowę głosową albo wideo.',
            'kind.in' => 'Rozmowa może być głosowa albo wideo.',
        ]);

        [$call, $created, $join] = $this->calls->start($me, $row, (string) $data['kind']);

        return response()->json(['data' => $this->calls->present($call), 'join' => $join], $created ? 201 : 200);
    }

    public function show(Request $request, int $call): JsonResponse
    {
        $row = $this->calls->findForUser($this->me($request), $call);

        return response()->json(['data' => $this->calls->present($row)]);
    }

    public function join(Request $request, int $call): JsonResponse
    {
        $me = $this->me($request);
        [$row, $join] = $this->calls->join($me, $this->calls->findForUser($me, $call));

        return response()->json(['data' => $this->calls->present($row), 'join' => $join]);
    }

    public function decline(Request $request, int $call): JsonResponse
    {
        $me = $this->me($request);
        $row = $this->calls->decline($me, $this->calls->findForUser($me, $call));

        return response()->json(['data' => $this->calls->present($row)]);
    }

    public function leave(Request $request, int $call): JsonResponse
    {
        $me = $this->me($request);
        $row = $this->calls->leave($me, $this->calls->findForUser($me, $call));

        return response()->json(['data' => $this->calls->present($row)]);
    }

    private function me(Request $request): User
    {
        /** @var User $me */
        $me = $request->user();

        return $me;
    }
}
