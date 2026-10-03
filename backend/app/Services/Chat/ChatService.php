<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Events\Chat\ChatConversationChanged;
use App\Events\Chat\ChatMessageDeleted;
use App\Events\Chat\ChatMessageSent;
use App\Events\Chat\ChatRead;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ClientInquiry;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\TenderAccessService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Czat firmowy: dostęp do rozmów, nieprzeczytane, wysyłka z idempotencją client_uuid, linki do zapytań i przetargów
 * i zdarzenia czasu rzeczywistego. Treść wiadomości nie trafia do dziennika aktywności (trasy poza `log.activity`)
 * ani do zdarzeń (tylko skrót ≤ 300 znaków).
 *
 * Bez zapamiętywania wyników w polach obiektu: Laravel trzyma instancję kontrolera (a z nią tę usługę) na trasie,
 * więc w jednym procesie obsłuży nią kolejne żądania.
 */
final class ChatService
{
    public const PERMISSION = 'chat';

    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly TenderAccessService $tenderAccess,
    ) {}

    // ---------------------------------------------------------------- osoby

    /**
     * Wszyscy z uprawnieniem `chat` (przez rolę albo nadane wprost), alfabetycznie.
     *
     * @return Collection<int, User>
     */
    public function chatUsers(): Collection
    {
        try {
            return User::query()
                ->permission(self::PERMISSION)
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name']);
        } catch (PermissionDoesNotExist) {
            return new Collection;
        }
    }

    /**
     * Id osób „dostępnych”: aplikacja (users.last_seen_at) albo dowolny token (dodatek, druga przeglądarka)
     * odezwały się w ciągu config('chat.online_minutes') minut.
     *
     * @return array<int, true>
     */
    public function onlineUserIds(): array
    {
        $since = now()->subMinutes((int) config('chat.online_minutes', 5));
        $ids = User::query()->where('last_seen_at', '>=', $since)->pluck('id')
            ->merge(
                PersonalAccessToken::query()
                    ->where('tokenable_type', (new User)->getMorphClass())
                    ->where('last_used_at', '>=', $since)
                    ->distinct()
                    ->pluck('tokenable_id'),
            );

        $out = [];
        foreach ($ids as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }

    /**
     * @param  array<int, true>  $online
     * @return array{id: int, name: string, online: bool, is_me: bool}
     */
    public function presentUser(User $user, User $me, array $online): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'online' => isset($online[(int) $user->id]),
            'is_me' => (int) $user->id === (int) $me->id,
        ];
    }

    /** @return list<array{id: int, name: string, online: bool, is_me: bool}> */
    public function users(User $me): array
    {
        $online = $this->onlineUserIds();

        return $this->chatUsers()
            ->map(fn (User $user): array => $this->presentUser($user, $me, $online))
            ->values()
            ->all();
    }

    // ---------------------------------------------------------------- rozmowy

    /**
     * Kanały „everyone” obejmują każdego z uprawnieniem — wiersz uczestnika (z miejscem przeczytania) powstaje
     * przy pierwszym kontakcie z czatem. Jedno zapytanie, gdy wszystko już jest.
     */
    public function ensureEveryoneMemberships(User $me): void
    {
        $missing = ChatConversation::query()
            ->where('everyone', true)
            ->whereNotExists(function (QueryBuilder $query) use ($me): void {
                $query->selectRaw('1')
                    ->from('chat_participants')
                    ->whereColumn('chat_participants.conversation_id', 'chat_conversations.id')
                    ->where('chat_participants.user_id', $me->id);
            })
            ->pluck('id');
        if ($missing->isEmpty()) {
            return;
        }
        $now = now();
        // Wiersz powstaje dopiero przy pierwszym wejściu do czatu, a pracownik jest w kanale od założenia konta:
        // wiadomości wysłane, zanim pierwszy raz otworzył czat, liczą się jako nieprzeczytane — ale nie te sprzed
        // założenia konta (nowa osoba za pół roku nie dostaje całej historii kanału jako „99+”).
        $readUpTo = static fn (int $conversationId): ?int => $me->created_at === null ? null : ChatMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('created_at', '<', $me->created_at)
            ->max('id');
        ChatParticipant::query()->insertOrIgnore($missing->map(static fn ($id): array => [
            'conversation_id' => (int) $id,
            'user_id' => (int) $me->id,
            'last_read_message_id' => ($last = $readUpTo((int) $id)) === null ? null : (int) $last,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    /** Rozmowa, w której użytkownik uczestniczy; inaczej 404 (nie zdradzamy, że cudza rozmowa istnieje). */
    public function findForUser(User $me, int $conversationId): ChatConversation
    {
        $conversation = ChatConversation::query()->find($conversationId);
        if ($conversation === null) {
            throw new NotFoundHttpException('Nie ma takiej rozmowy.');
        }
        if ($conversation->everyone) {
            $this->ensureEveryoneMemberships($me);

            return $conversation;
        }
        $member = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $me->id)
            ->exists();
        if (! $member) {
            throw new NotFoundHttpException('Nie ma takiej rozmowy.');
        }

        return $conversation;
    }

    /**
     * Moje rozmowy: z ostatnią wiadomością na górze, bez wiadomości na końcu. Nieprzeczytane jednym zapytaniem.
     *
     * @return array{data: list<array<string, mixed>>, unread_total: int}
     */
    public function listConversations(User $me): array
    {
        $this->ensureEveryoneMemberships($me);
        $context = $this->presentationContext($me);

        $conversations = ChatConversation::query()
            ->whereExists(function (QueryBuilder $query) use ($me): void {
                $query->selectRaw('1')
                    ->from('chat_participants')
                    ->whereColumn('chat_participants.conversation_id', 'chat_conversations.id')
                    ->where('chat_participants.user_id', $me->id);
            })
            ->with(['participants.user:id,name', 'lastMessage.user:id,name'])
            ->orderByRaw('last_message_id IS NULL')
            ->orderByDesc('last_message_id')
            ->orderBy('id')
            ->get();

        $data = $conversations
            ->map(fn (ChatConversation $conversation): array => $this->presentConversation($conversation, $me, $context))
            ->values()
            ->all();

        return ['data' => $data, 'unread_total' => array_sum($context['unread'])];
    }

    /** @return array<string, mixed> */
    public function showConversation(User $me, ChatConversation $conversation): array
    {
        $conversation->load(['participants.user:id,name', 'lastMessage.user:id,name']);

        return $this->presentConversation($conversation, $me, $this->presentationContext($me));
    }

    /**
     * Rozmowa 1:1 — istniejąca albo nowa (jedna na parę osób, klucz „mniejszeId:większeId”).
     *
     * @return array{0: ChatConversation, 1: bool} rozmowa i czy powstała teraz
     */
    public function findOrCreateDirect(User $me, int $otherId): array
    {
        if ($otherId === (int) $me->id) {
            throw ValidationException::withMessages(['user_id' => 'Nie można rozmawiać z samym sobą.']);
        }
        $this->assertChatUsers([$otherId], 'user_id');

        $key = ChatConversation::directKey((int) $me->id, $otherId);
        $existing = ChatConversation::query()->where('direct_key', $key)->first();
        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            $conversation = DB::transaction(function () use ($me, $otherId, $key): ChatConversation {
                $conversation = ChatConversation::query()->create([
                    'type' => ChatConversation::TYPE_DIRECT,
                    'name' => null,
                    'everyone' => false,
                    'direct_key' => $key,
                    'created_by' => $me->id,
                ]);
                $this->insertParticipants($conversation, [(int) $me->id, $otherId]);

                return $conversation;
            });
        } catch (UniqueConstraintViolationException) {
            // dwa równoczesne „napisz do” tej samej osoby — druga prośba dostaje rozmowę pierwszej
            $conversation = ChatConversation::query()->where('direct_key', $key)->firstOrFail();

            return [$conversation, false];
        }

        return [$conversation, true];
    }

    /** @param  list<int>  $userIds */
    public function createChannel(User $me, string $name, array $userIds): ChatConversation
    {
        $userIds = $this->uniqueIds($userIds);
        $this->assertChatUsers($userIds, 'user_ids');
        $members = $this->uniqueIds([(int) $me->id, ...$userIds]);

        $conversation = DB::transaction(function () use ($me, $name, $members): ChatConversation {
            $conversation = ChatConversation::query()->create([
                'type' => ChatConversation::TYPE_CHANNEL,
                'name' => $name,
                'everyone' => false,
                'created_by' => $me->id,
            ]);
            $this->insertParticipants($conversation, $members);

            return $conversation;
        });

        event(new ChatConversationChanged($members, (int) $conversation->id));

        return $conversation;
    }

    /** @param  list<int>  $userIds */
    public function addParticipants(User $me, ChatConversation $conversation, array $userIds): void
    {
        $this->assertOpenChannel($conversation);
        $userIds = $this->uniqueIds($userIds);
        $this->assertChatUsers($userIds, 'user_ids');
        $this->insertParticipants($conversation, $userIds);

        event(new ChatConversationChanged($this->participantIds($conversation), (int) $conversation->id));
    }

    public function leave(User $me, ChatConversation $conversation): void
    {
        $this->assertOpenChannel($conversation);
        ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $me->id)
            ->delete();

        // pozostali odświeżają listę osób, a inne urządzenia wychodzącego usuwają rozmowę z listy
        event(new ChatConversationChanged(
            $this->uniqueIds([...$this->participantIds($conversation), (int) $me->id]),
            (int) $conversation->id,
        ));
    }

    // ---------------------------------------------------------------- wiadomości

    /**
     * Wiadomości rosnąco po id. Bez parametrów i z before_id: ostatnie `limit` (has_more = są starsze);
     * z after_id: następne `limit` (has_more = są nowsze).
     *
     * @return array{data: list<array<string, mixed>>, has_more: bool}
     */
    public function listMessages(ChatConversation $conversation, ?int $afterId, ?int $beforeId, int $limit): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $query = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->with('user:id,name');

        if ($afterId !== null) {
            $rows = $query->where('id', '>', $afterId)->orderBy('id')->limit($limit + 1)->get();
            $hasMore = $rows->count() > $limit;
            $rows = $rows->take($limit);
        } else {
            if ($beforeId !== null) {
                $query->where('id', '<', $beforeId);
            }
            $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
            $hasMore = $rows->count() > $limit;
            $rows = $rows->take($limit)->reverse();
        }

        return [
            'data' => $rows->map(fn (ChatMessage $message): array => $this->presentMessage($message))->values()->all(),
            'has_more' => $hasMore,
        ];
    }

    /**
     * Wysyłka. Powtórka tego samego client_uuid w tej samej rozmowie zwraca zapisany wiersz (created = false),
     * w innej rozmowie — błąd 422 (klient pomylił identyfikatory, nie zgadujemy, o którą rozmowę chodzi).
     *
     * @param  array{client_uuid: string, body?: string|null, link?: array<string, mixed>|null, mail?: array<string, mixed>|null}  $data
     * @return array{0: ChatMessage, 1: bool} wiadomość i czy powstała teraz
     */
    public function send(User $me, ChatConversation $conversation, array $data): array
    {
        // bez zmiany wielkości liter: klient porównuje client_uuid z odpowiedzi z tym, który wysłał
        $uuid = trim((string) $data['client_uuid']);
        $existing = $this->messageByUuid($me, $uuid);
        if ($existing !== null) {
            return [$this->replay($existing, $conversation), false];
        }

        [$kind, $body, $meta] = $this->buildContent($me, $data);

        try {
            $message = DB::transaction(function () use ($me, $conversation, $uuid, $kind, $body, $meta): ChatMessage {
                $message = ChatMessage::query()->create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $me->id,
                    'kind' => $kind,
                    'body' => $body,
                    'meta' => $meta,
                    'client_uuid' => $uuid,
                ]);
                // warunek zamiast nadpisania: równoległa wysyłka z mniejszym id nie cofa ostatniej wiadomości
                ChatConversation::query()
                    ->whereKey($conversation->id)
                    ->where(fn ($query) => $query->whereNull('last_message_id')->orWhere('last_message_id', '<', $message->id))
                    ->update(['last_message_id' => $message->id, 'updated_at' => now()]);

                return $message;
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->messageByUuid($me, $uuid);
            if ($existing === null) {
                throw $e;
            }

            return [$this->replay($existing, $conversation), false];
        }

        $message->setRelation('user', $me);
        $this->broadcastMessage($conversation, $message, $me);

        return [$message, true];
    }

    /**
     * Wpis o rozmowie głosowej/wideo (kind=call, autor = dzwoniący, bez treści) i zwykłe zdarzenie chat.message.
     * Wołane w transakcji rozpoczęcia rozmowy — zdarzenie wychodzi po zatwierdzeniu.
     *
     * @param  array{id: int, kind: string, status: string, duration_seconds: int|null}  $call
     */
    public function postCallMessage(User $me, ChatConversation $conversation, array $call): ChatMessage
    {
        $message = ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $me->id,
            'kind' => ChatMessage::KIND_CALL,
            'body' => null,
            'meta' => ['call' => $call],
            'client_uuid' => null,
        ]);
        ChatConversation::query()
            ->whereKey($conversation->id)
            ->where(fn ($query) => $query->whereNull('last_message_id')->orWhere('last_message_id', '<', $message->id))
            ->update(['last_message_id' => $message->id, 'updated_at' => now()]);

        $message->setRelation('user', $me);
        $this->broadcastMessage($conversation, $message, $me);

        return $message;
    }

    /** Usunięcie własnej wiadomości: treść i meta znikają, wiersz zostaje jako „wiadomość usunięta”. */
    public function deleteMessage(User $me, int $messageId): ChatMessage
    {
        $message = ChatMessage::query()->with('user:id,name')->find($messageId);
        if ($message === null) {
            throw new NotFoundHttpException('Nie ma takiej wiadomości.');
        }
        if ($message->user_id === null || (int) $message->user_id !== (int) $me->id) {
            // cudza wiadomość w rozmowie, w której mnie nie ma, to dla mnie „nie ma takiej” — jak przy rozmowach
            $this->findForUser($me, (int) $message->conversation_id);
            throw new AccessDeniedHttpException('Można usunąć tylko własną wiadomość.');
        }
        if ($message->kind === ChatMessage::KIND_CALL) {
            // wpis prowadzi do rozmowy (Dołącz / Oddzwoń) i niesie jej stan — znika tylko razem z rozmową czatu
            throw ValidationException::withMessages(['message' => 'Wpisu o rozmowie nie można usunąć.']);
        }
        if ($message->deleted_at === null) {
            $message->forceFill(['body' => null, 'meta' => null, 'deleted_at' => now()])->save();
            $conversation = ChatConversation::query()->find($message->conversation_id);
            if ($conversation !== null) {
                event(new ChatMessageDeleted($this->recipientIds($conversation), (int) $conversation->id, (int) $message->id));
            }
        }

        return $message;
    }

    /**
     * Przeczytane do wiadomości `messageId` włącznie. Miejsce przeczytania tylko rośnie — spóźnione żądanie z innego
     * urządzenia nie przywraca nieprzeczytanych.
     */
    public function markRead(User $me, ChatConversation $conversation, int $messageId): int
    {
        $belongs = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereKey($messageId)
            ->exists();
        if (! $belongs) {
            throw ValidationException::withMessages(['message_id' => 'Ta wiadomość nie należy do tej rozmowy.']);
        }

        ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $me->id)
            ->where(fn ($query) => $query->whereNull('last_read_message_id')->orWhere('last_read_message_id', '<', $messageId))
            ->update(['last_read_message_id' => $messageId, 'updated_at' => now()]);

        $lastRead = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $me->id)
            ->value('last_read_message_id');
        $total = $this->unreadTotal($me);

        event(new ChatRead((int) $me->id, (int) $conversation->id, $lastRead === null ? null : (int) $lastRead, $total));

        return $total;
    }

    // ---------------------------------------------------------------- nieprzeczytane

    /** Suma nieprzeczytanych we wszystkich moich rozmowach — jedno zapytanie. */
    public function unreadTotal(User $me): int
    {
        $this->ensureEveryoneMemberships($me);

        return (int) $this->unreadQuery($me)->count();
    }

    /**
     * Nieprzeczytane w każdej rozmowie: wiadomości innych osób (także usuniętych kont i systemowe), nieusunięte,
     * o id większym niż moje miejsce przeczytania (NULL = 0). Jedno zapytanie z GROUP BY tylko po kolumnie
     * z SELECT-a — zgodne z ONLY_FULL_GROUP_BY (MariaDB na produkcji).
     *
     * @return array<int, int>
     */
    public function unreadByConversation(User $me): array
    {
        $rows = $this->unreadQuery($me)
            ->groupBy('p.conversation_id')
            ->select('p.conversation_id')
            ->selectRaw('COUNT(*) AS unread_count')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->conversation_id] = (int) $row->unread_count;
        }

        return $out;
    }

    private function unreadQuery(User $me): QueryBuilder
    {
        $meId = (int) $me->id;

        return DB::table('chat_participants as p')
            ->join('chat_messages as m', function ($join): void {
                $join->on('m.conversation_id', '=', 'p.conversation_id')
                    ->whereRaw('m.id > COALESCE(p.last_read_message_id, 0)');
            })
            ->where('p.user_id', $meId)
            ->whereNull('m.deleted_at')
            ->where(fn (QueryBuilder $query) => $query->whereNull('m.user_id')->orWhere('m.user_id', '<>', $meId));
    }

    // ---------------------------------------------------------------- prezentacja

    /** @return array<string, mixed> */
    public function presentMessage(ChatMessage $message): array
    {
        $user = $message->user;

        return [
            'id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'kind' => (string) $message->kind,
            'body' => $message->body,
            'meta' => $message->meta === null || $message->meta === [] ? null : $message->meta,
            'user' => $user instanceof User ? ['id' => (int) $user->id, 'name' => (string) $user->name] : null,
            'deleted' => $message->deleted_at !== null,
            'client_uuid' => $message->client_uuid,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{chat_users: Collection<int, User>, online: array<int, true>, unread: array<int, int>}
     */
    private function presentationContext(User $me): array
    {
        return [
            'chat_users' => $this->chatUsers(),
            'online' => $this->onlineUserIds(),
            'unread' => $this->unreadByConversation($me),
        ];
    }

    /**
     * @param  array{chat_users: Collection<int, User>, online: array<int, true>, unread: array<int, int>}  $context
     * @return array<string, mixed>
     */
    private function presentConversation(ChatConversation $conversation, User $me, array $context): array
    {
        $rows = $conversation->participants;
        $mine = $rows->first(fn (ChatParticipant $row): bool => (int) $row->user_id === (int) $me->id);

        if ($conversation->everyone) {
            // „Ogólny”: każdy z uprawnieniem, także ten, kto jeszcze nie otworzył czatu (wiersze są leniwe)
            $participants = $context['chat_users']
                ->map(static fn (User $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name])
                ->values()
                ->all();
        } else {
            $participants = $rows
                ->map(static fn (ChatParticipant $row): ?User => $row->user)
                ->filter()
                ->sortBy(static fn (User $user): string => Str::lower((string) $user->name))
                ->map(static fn (User $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name])
                ->values()
                ->all();
        }

        $otherUser = null;
        $name = (string) $conversation->name;
        if ($conversation->isDirect()) {
            $other = $rows->first(fn (ChatParticipant $row): bool => (int) $row->user_id !== (int) $me->id)?->user;
            $otherUser = $other instanceof User ? $this->presentUser($other, $me, $context['online']) : null;
            $name = $other instanceof User ? (string) $other->name : 'Konto usunięte';
        }

        $last = $conversation->lastMessage;

        return [
            'id' => (int) $conversation->id,
            'type' => (string) $conversation->type,
            'name' => $name,
            'everyone' => (bool) $conversation->everyone,
            'other_user' => $otherUser,
            'participants' => $participants,
            'last_message' => $last instanceof ChatMessage ? $this->presentMessage($last) : null,
            'unread' => $context['unread'][(int) $conversation->id] ?? 0,
            'last_read_message_id' => $mine?->last_read_message_id === null ? null : (int) $mine->last_read_message_id,
        ];
    }

    // ---------------------------------------------------------------- pomocnicze

    private function messageByUuid(User $me, string $uuid): ?ChatMessage
    {
        return ChatMessage::query()
            ->with('user:id,name')
            ->where('user_id', $me->id)
            ->where('client_uuid', $uuid)
            ->first();
    }

    private function replay(ChatMessage $existing, ChatConversation $conversation): ChatMessage
    {
        if ((int) $existing->conversation_id !== (int) $conversation->id) {
            throw ValidationException::withMessages([
                'client_uuid' => 'Ta wiadomość została już wysłana w innej rozmowie.',
            ]);
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string|null, 2: array<string, mixed>|null}
     */
    private function buildContent(User $me, array $data): array
    {
        $body = isset($data['body']) && is_string($data['body']) ? trim($data['body']) : null;
        if ($body === '') {
            $body = null;
        }
        $link = isset($data['link']) && is_array($data['link']) && $data['link'] !== [] ? $data['link'] : null;
        $mail = isset($data['mail']) && is_array($data['mail']) && $data['mail'] !== [] ? $data['mail'] : null;

        if ($link !== null && $mail !== null) {
            throw ValidationException::withMessages(['link' => 'Wiadomość może nieść link albo mail, nie oba naraz.']);
        }
        if ($mail !== null) {
            return [ChatMessage::KIND_MAIL, $body, ['mail' => [
                'subject' => (string) ($mail['subject'] ?? ''),
                'from' => (string) ($mail['from'] ?? ''),
                'date' => isset($mail['date']) ? (string) $mail['date'] : null,
                'message_id' => isset($mail['message_id']) ? (string) $mail['message_id'] : null,
                'body' => isset($mail['body']) ? (string) $mail['body'] : null,
            ]]];
        }
        if ($link !== null) {
            return [ChatMessage::KIND_LINK, $body, ['link' => $this->resolveLink($me, $link)]];
        }
        if ($body === null) {
            throw ValidationException::withMessages(['body' => 'Wpisz treść wiadomości.']);
        }

        return [ChatMessage::KIND_TEXT, $body, null];
    }

    /**
     * Link do zapytania albo przetargu. Tytuł i ścieżkę składa serwer z bazy (nie ufamy klientowi), po sprawdzeniu,
     * że nadawca sam może to otworzyć — inaczej czat byłby furtką do cudzych zapytań.
     * Pozycja: przy zapytaniu numer pozycji od 1 (analysis.line_items), przy przetargu id wiersza tender_items.
     *
     * @param  array<string, mixed>  $link
     * @return array{type: string, id: int, item: int|null, title: string, path: string}
     */
    private function resolveLink(User $me, array $link): array
    {
        $type = (string) ($link['type'] ?? '');
        $id = (int) ($link['id'] ?? 0);
        $item = isset($link['item']) ? (int) $link['item'] : null;

        if ($type === 'inquiry') {
            $inquiry = ClientInquiry::query()->find($id, ['id', 'user_id', 'source_subject', 'reply_subject', 'analysis']);
            if ($inquiry === null) {
                throw ValidationException::withMessages(['link.id' => 'Nie ma takiego zapytania.']);
            }
            $owner = (int) $inquiry->user_id === (int) $me->id;
            if (! $me->can('inquiries.use') || (! $owner && ! $me->can('inquiries.view_others'))) {
                throw new AccessDeniedHttpException('Nie masz dostępu do tego zapytania.');
            }
            $subject = trim((string) ($inquiry->source_subject ?: $inquiry->reply_subject ?: ''));
            $title = 'Zapytanie #'.$inquiry->id.($subject !== '' ? ': '.Str::limit($subject, 150) : '');
            if ($item !== null) {
                // jak ClientInquiryService::lineItemsOf: liczą się pozycje z id; starsze zapytanie bez listy pozycji
                // strona pokazuje jako jedną pozycję
                $lines = array_filter(
                    is_array($inquiry->analysis['line_items'] ?? null) ? $inquiry->analysis['line_items'] : [],
                    static fn ($line): bool => is_array($line) && trim((string) ($line['id'] ?? '')) !== '',
                );
                if ($item < 1 || $item > max(1, count($lines))) {
                    throw ValidationException::withMessages(['link.item' => 'W tym zapytaniu nie ma takiej pozycji.']);
                }
                $title .= ' (poz. '.$item.')';
            }

            return ['type' => 'inquiry', 'id' => (int) $inquiry->id, 'item' => $item, 'title' => $title, 'path' => '/inquiries/'.$inquiry->id];
        }

        $tender = Tender::query()->find($id);
        if ($tender === null) {
            throw ValidationException::withMessages(['link.id' => 'Nie ma takiego przetargu.']);
        }
        if (! $this->tenderAccess->canView($me, $tender)) {
            throw new AccessDeniedHttpException('Nie masz dostępu do tego przetargu.');
        }
        $title = 'Przetarg '.$tender->number.': '.Str::limit((string) $tender->title, 150);
        if ($item !== null) {
            $lineNo = TenderItem::query()->where('tender_id', $tender->id)->whereKey($item)->value('line_no');
            if ($lineNo === null) {
                throw ValidationException::withMessages(['link.item' => 'W tym przetargu nie ma takiej pozycji.']);
            }
            $title .= ' (poz. '.$lineNo.')';
        }

        return ['type' => 'tender', 'id' => (int) $tender->id, 'item' => $item, 'title' => $title, 'path' => '/tenders/'.$tender->id];
    }

    /**
     * Odbiorcy zdarzeń rozmowy: w kanale „everyone” każdy z uprawnieniem (także ten, kto jeszcze nie otworzył czatu),
     * w pozostałych — uczestnicy.
     *
     * @return list<int>
     */
    public function recipientIds(ChatConversation $conversation): array
    {
        // Tylko osoby z uprawnieniem — komu odebrano czat, nie dostaje już zapowiedzi wiadomości (skrót treści)
        // ze starych rozmów, choć jego wiersz uczestnika zostaje.
        $allowed = $this->chatUsers()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($conversation->everyone) {
            return $this->uniqueIds($allowed);
        }

        return $this->uniqueIds(array_values(array_intersect($this->participantIds($conversation), $allowed)));
    }

    private function broadcastMessage(ChatConversation $conversation, ChatMessage $message, User $sender): void
    {
        $recipients = $this->recipientIds($conversation);

        $meta = is_array($message->meta) ? $message->meta : [];
        $preview = match ($message->kind) {
            ChatMessage::KIND_MAIL => (string) ($meta['mail']['subject'] ?? ''),
            ChatMessage::KIND_LINK => (string) ($message->body ?? $meta['link']['title'] ?? ''),
            ChatMessage::KIND_CALL => ($meta['call']['kind'] ?? null) === 'video' ? 'Rozmowa wideo' : 'Rozmowa głosowa',
            default => (string) $message->body,
        };

        event(new ChatMessageSent($this->uniqueIds($recipients), [
            'conversation_id' => (int) $conversation->id,
            'message_id' => (int) $message->id,
            'kind' => (string) $message->kind,
            'user' => ['id' => (int) $sender->id, 'name' => (string) $sender->name],
            'preview' => self::preview($preview),
            // w rozmowie 1:1 odbiorca widzi rozmowę pod imieniem nadawcy
            'conversation_name' => $conversation->isDirect() ? (string) $sender->name : (string) $conversation->name,
            'created_at' => $message->created_at?->toIso8601String(),
        ]));
    }

    public static function preview(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $max = (int) config('chat.preview_length', 300);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)).'…';
    }

    /** @return list<int> */
    private function participantIds(ChatConversation $conversation): array
    {
        return ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @param  list<int>  $ids */
    private function insertParticipants(ChatConversation $conversation, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $now = now();
        // Dodana osoba zaczyna od bieżącego miejsca — dawna historia nie liczy się jej jako nieprzeczytana.
        $lastMessageId = ChatConversation::query()->whereKey($conversation->id)->value('last_message_id');
        $lastRead = $lastMessageId === null ? null : (int) $lastMessageId;
        ChatParticipant::query()->insertOrIgnore(array_map(static fn (int $id): array => [
            'conversation_id' => (int) $conversation->id,
            'user_id' => $id,
            'last_read_message_id' => $lastRead,
            'created_at' => $now,
            'updated_at' => $now,
        ], $ids));
    }

    private function assertOpenChannel(ChatConversation $conversation): void
    {
        if (! $conversation->isOpenChannel()) {
            throw ValidationException::withMessages([
                'conversation' => $conversation->everyone
                    ? 'W kanale „'.$conversation->name.'” są wszyscy — nie można z niego wyjść ani dodawać osób.'
                    : 'W rozmowie jeden na jeden nie można dodawać osób ani z niej wychodzić.',
            ]);
        }
    }

    /** @param  list<int>  $ids */
    private function assertChatUsers(array $ids, string $field): void
    {
        if ($ids === []) {
            return;
        }
        $allowed = $this->chatUsers()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $missing = array_values(array_diff($ids, $allowed));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                $field => 'Tej osoby nie ma w czacie (brak konta albo uprawnienia do czatu).',
            ]);
        }
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function uniqueIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }
}
