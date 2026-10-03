<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Rozmowy czatu: lista z nieprzeczytanymi, rozmowa 1:1 i kanały, osoby w kanale, wyjście, przeczytanie. */
class ChatConversationController extends Controller
{
    public function __construct(
        private readonly ChatService $chat,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->chat->listConversations($this->me($request)));
    }

    /** `{user_id}` — rozmowa 1:1 (istniejąca 200 albo nowa 201); `{name, user_ids}` — nowy kanał (201). */
    public function store(Request $request): JsonResponse
    {
        $me = $this->me($request);

        if ($request->has('user_id')) {
            $data = $request->validate([
                'user_id' => ['required', 'integer', 'min:1'],
            ], [
                'user_id.required' => 'Wybierz osobę.',
                'user_id.integer' => 'Wybierz osobę z listy.',
                'user_id.min' => 'Wybierz osobę z listy.',
            ]);
            [$conversation, $created] = $this->chat->findOrCreateDirect($me, (int) $data['user_id']);

            return response()->json(['data' => $this->chat->showConversation($me, $conversation)], $created ? 201 : 200);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'user_ids' => ['required', 'array', 'min:1', 'max:50'],
            'user_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ], [
            'name.required' => 'Podaj nazwę kanału.',
            'name.max' => 'Nazwa kanału może mieć najwyżej 100 znaków.',
            'user_ids.required' => 'Wybierz co najmniej jedną osobę.',
            'user_ids.min' => 'Wybierz co najmniej jedną osobę.',
            'user_ids.max' => 'Kanał może mieć na start najwyżej 50 osób.',
            'user_ids.*.integer' => 'Wybierz osoby z listy.',
            'user_ids.*.distinct' => 'Ta sama osoba jest na liście dwa razy.',
        ]);
        $conversation = $this->chat->createChannel($me, trim((string) $data['name']), array_map('intval', $data['user_ids']));

        return response()->json(['data' => $this->chat->showConversation($me, $conversation)], 201);
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        $me = $this->me($request);

        return response()->json(['data' => $this->chat->showConversation($me, $this->chat->findForUser($me, $conversation))]);
    }

    public function addParticipants(Request $request, int $conversation): JsonResponse
    {
        $me = $this->me($request);
        $row = $this->chat->findForUser($me, $conversation);
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:50'],
            'user_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ], [
            'user_ids.required' => 'Wybierz co najmniej jedną osobę.',
            'user_ids.min' => 'Wybierz co najmniej jedną osobę.',
            'user_ids.max' => 'Na raz można dodać najwyżej 50 osób.',
            'user_ids.*.integer' => 'Wybierz osoby z listy.',
            'user_ids.*.distinct' => 'Ta sama osoba jest na liście dwa razy.',
        ]);
        $this->chat->addParticipants($me, $row, array_map('intval', $data['user_ids']));

        return response()->json(['data' => $this->chat->showConversation($me, $row)]);
    }

    public function leave(Request $request, int $conversation): Response
    {
        $me = $this->me($request);
        $this->chat->leave($me, $this->chat->findForUser($me, $conversation));

        return response()->noContent();
    }

    public function read(Request $request, int $conversation): JsonResponse
    {
        $me = $this->me($request);
        $row = $this->chat->findForUser($me, $conversation);
        $data = $request->validate([
            'message_id' => ['required', 'integer', 'min:1'],
        ], [
            'message_id.required' => 'Brak numeru wiadomości.',
            'message_id.integer' => 'Numer wiadomości musi być liczbą.',
        ]);

        return response()->json(['unread_total' => $this->chat->markRead($me, $row, (int) $data['message_id'])]);
    }

    public function unread(Request $request): JsonResponse
    {
        return response()->json(['unread_total' => $this->chat->unreadTotal($this->me($request))]);
    }

    private function me(Request $request): User
    {
        /** @var User $me */
        $me = $request->user();

        return $me;
    }
}
