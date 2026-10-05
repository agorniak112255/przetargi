<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Wiadomości czatu. Trasy są poza grupą `log.activity` — treść wiadomości nie może trafić do dziennika aktywności.
 */
class ChatMessageController extends Controller
{
    public function __construct(
        private readonly ChatService $chat,
    ) {}

    public function index(Request $request, int $conversation): JsonResponse
    {
        $me = $this->me($request);
        $row = $this->chat->findForUser($me, $conversation);
        $data = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0', 'prohibits:before_id'],
            'before_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'after_id.prohibits' => 'Podaj after_id albo before_id, nie oba naraz.',
            'limit.max' => 'Na raz można pobrać najwyżej 100 wiadomości.',
        ]);

        return response()->json($this->chat->listMessages(
            $row,
            isset($data['after_id']) ? (int) $data['after_id'] : null,
            isset($data['before_id']) ? (int) $data['before_id'] : null,
            isset($data['limit']) ? (int) $data['limit'] : 50,
        ));
    }

    /** 201 — nowa wiadomość; 200 — powtórka tego samego client_uuid w tej rozmowie (np. po zerwanym połączeniu). */
    public function store(Request $request, int $conversation): JsonResponse
    {
        $me = $this->me($request);
        $row = $this->chat->findForUser($me, $conversation);
        [$message, $created] = $this->chat->send($me, $row, $this->validatedMessage($request));

        return response()->json(['data' => $this->chat->presentMessage($message)], $created ? 201 : 200);
    }

    /** „Wyślij koledze” z dodatku do Thunderbirda: rozmowa 1:1 z {user} (istniejąca albo nowa) i wysyłka. */
    public function storeDirect(Request $request, int $user): JsonResponse
    {
        $me = $this->me($request);
        $data = $this->validatedMessage($request);
        [$conversation] = $this->chat->findOrCreateDirect($me, $user);
        [$message, $created] = $this->chat->send($me, $conversation, $data);

        return response()->json([
            'data' => $this->chat->presentMessage($message),
            'conversation_id' => (int) $conversation->id,
        ], $created ? 201 : 200);
    }

    /**
     * Wyszukiwanie wiadomości (od najnowszych) we wszystkich moich rozmowach albo w jednej (`conversation_id`).
     * `type`: all, links (karty linków i wiadomości z adresem http/https), mails, calls, images. Bez `q` tylko
     * w jednej rozmowie — to jej historia.
     */
    public function search(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', 'string', Rule::in(ChatService::SEARCH_TYPES)],
            'before_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ], [
            'q.max' => 'Szukany tekst może mieć najwyżej 100 znaków.',
            'type.in' => 'Nieznany rodzaj wpisów.',
            'limit.max' => 'Na raz można pobrać najwyżej 50 wyników.',
        ]);

        $q = trim((string) ($data['q'] ?? ''));
        $conversation = isset($data['conversation_id']) ? $this->chat->findForUser($me, (int) $data['conversation_id']) : null;
        if ($conversation === null && mb_strlen($q) < 2) {
            throw ValidationException::withMessages(['q' => 'Wpisz co najmniej 2 znaki.']);
        }

        return response()->json($this->chat->searchMessages(
            $me,
            $q,
            $conversation,
            (string) ($data['type'] ?? 'all'),
            isset($data['before_id']) ? (int) $data['before_id'] : null,
            isset($data['limit']) ? (int) $data['limit'] : 30,
        ));
    }

    /**
     * Zdjęcie z opcjonalnym podpisem (multipart: client_uuid, image, body). 201 — nowa wiadomość; 200 — powtórka
     * tego samego client_uuid w tej rozmowie.
     */
    public function storeImage(Request $request, int $conversation): JsonResponse
    {
        $me = $this->me($request);
        $row = $this->chat->findForUser($me, $conversation);
        $maxBody = (int) config('chat.max_body', 4000);
        $maxKb = (int) config('chat.image_max_kb', 10240);
        $data = $request->validate([
            'client_uuid' => ['required', 'string', 'uuid'],
            'image' => ['required', 'file', 'max:'.$maxKb, 'mimes:jpg,jpeg,png,gif,webp'],
            'body' => ['nullable', 'string', 'max:'.$maxBody],
        ], [
            'client_uuid.required' => 'Brak identyfikatora wiadomości (client_uuid).',
            'client_uuid.uuid' => 'Identyfikator wiadomości (client_uuid) musi być w formacie UUID.',
            'image.required' => 'Wybierz zdjęcie.',
            'image.file' => 'Zdjęcie nie doszło na serwer — spróbuj jeszcze raz.',
            'image.uploaded' => 'Zdjęcie nie doszło na serwer — może jest za duże (najwyżej '.intdiv($maxKb, 1024).' MB).',
            'image.mimes' => 'Można wysłać zdjęcie JPG, PNG, GIF albo WEBP.',
            'image.max' => 'Zdjęcie może mieć najwyżej '.intdiv($maxKb, 1024).' MB.',
            'body.max' => 'Podpis może mieć najwyżej '.$maxBody.' znaków.',
        ]);
        [$message, $created] = $this->chat->sendImage(
            $me,
            $row,
            (string) $data['client_uuid'],
            $request->file('image'),
            isset($data['body']) ? (string) $data['body'] : null,
        );

        return response()->json(['data' => $this->chat->presentMessage($message)], $created ? 201 : 200);
    }

    /** Plik zdjęcia — tylko dla uczestnika rozmowy. Adres stały dla wiadomości, więc przeglądarka może go trzymać. */
    public function image(Request $request, int $message): StreamedResponse
    {
        $file = $this->chat->imageFile($this->me($request), $message);

        return Storage::disk('local')->response($file['path'], null, [
            'Content-Type' => $file['mime'],
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    public function destroy(Request $request, int $message): JsonResponse
    {
        return response()->json(['data' => $this->chat->presentMessage($this->chat->deleteMessage($this->me($request), $message))]);
    }

    /**
     * @return array{client_uuid: string, body?: string|null, link?: array<string, mixed>|null, mail?: array<string, mixed>|null}
     */
    private function validatedMessage(Request $request): array
    {
        $maxBody = (int) config('chat.max_body', 4000);

        /** @var array{client_uuid: string, body?: string|null, link?: array<string, mixed>|null, mail?: array<string, mixed>|null} $data */
        $data = $request->validate([
            'client_uuid' => ['required', 'string', 'uuid'],
            'body' => ['nullable', 'string', 'max:'.$maxBody, 'required_without_all:link,mail'],
            'link' => ['nullable', 'array', 'prohibits:mail'],
            'link.type' => ['required_with:link', 'string', Rule::in(['inquiry', 'tender'])],
            'link.id' => ['required_with:link', 'integer', 'min:1'],
            'link.item' => ['nullable', 'integer', 'min:1'],
            'mail' => ['nullable', 'array'],
            'mail.subject' => ['nullable', 'string', 'max:300'],
            'mail.from' => ['nullable', 'string', 'max:300'],
            'mail.date' => ['nullable', 'string', 'max:64'],
            'mail.message_id' => ['nullable', 'string', 'max:998'],
            'mail.body' => ['nullable', 'string', 'max:20000'],
        ], [
            'client_uuid.required' => 'Brak identyfikatora wiadomości (client_uuid).',
            'client_uuid.uuid' => 'Identyfikator wiadomości (client_uuid) musi być w formacie UUID.',
            'body.required_without_all' => 'Wpisz treść wiadomości.',
            'body.max' => 'Wiadomość może mieć najwyżej '.$maxBody.' znaków.',
            'link.prohibits' => 'Wiadomość może nieść link albo mail, nie oba naraz.',
            'link.type.in' => 'Link może prowadzić do zapytania albo przetargu.',
            'link.id.required_with' => 'Brak numeru zapytania albo przetargu.',
            'mail.subject.max' => 'Temat maila może mieć najwyżej 300 znaków.',
            'mail.from.max' => 'Nadawca maila może mieć najwyżej 300 znaków.',
            'mail.body.max' => 'Treść maila może mieć najwyżej 20 000 znaków.',
        ]);

        return $data;
    }

    private function me(Request $request): User
    {
        /** @var User $me */
        $me = $request->user();

        return $me;
    }
}
