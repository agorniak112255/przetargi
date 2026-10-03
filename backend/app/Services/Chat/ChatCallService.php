<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Events\Chat\ChatCallRinging;
use App\Events\Chat\ChatCallUpdated;
use App\Models\ChatCall;
use App\Models\ChatCallMember;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Rozmowy głosowe i wideo w czacie (LiveKit) — maszyna stanów:
 *
 * - ringing → active: dołącza (webhook participant_joined albo uzgodnienie z LiveKit) ktoś inny niż dzwoniący;
 * - ringing/active → ended (z answered_at) albo missed (bez) — końcowe, nic ich już nie zmienia;
 * - stan osoby: invited → connecting (POST /join, dostała token) → joined (tylko LiveKit) → left; declined.
 *
 * Każda zmiana w transakcji z blokadą wiersza chat_calls; zdarzenia wychodzą po zatwierdzeniu i raz na przejście
 * (wysyła je tylko ten, kto pod blokadą faktycznie zmienił stan). Rozmowę 1:1 kończy tylko jawne /leave,
 * odrzucenie przed odebraniem, room_finished albo komenda chat:calls-expire — rozłączenie z LiveKit nie, bo
 * zmiana urządzenia albo chwilowa utrata sieci też wygląda jak wyjście.
 */
final class ChatCallService
{
    /** Rozmowa 1:1 z mniej niż dwiema połączonymi osobami kończy się po tylu sekundach (po odebraniu). */
    private const DIRECT_ALONE_SECONDS = 60;

    /** Rozmowa grupowa bez nikogo połączonego kończy się po tylu sekundach. */
    private const GROUP_EMPTY_SECONDS = 120;

    /** Najdłuższa rozmowa — po tylu godzinach kończy ją komenda expire. */
    private const MAX_HOURS = 12;

    private const LIVE_STATES = [ChatCallMember::STATE_CONNECTING, ChatCallMember::STATE_JOINED];

    public function __construct(
        private readonly ChatService $chat,
        private readonly LiveKitRooms $rooms,
    ) {}

    /** @return array{enabled: bool, max_participants: int} */
    public function config(): array
    {
        return ['enabled' => $this->rooms->enabled(), 'max_participants' => $this->rooms->maxParticipants()];
    }

    // ---------------------------------------------------------------- akcje użytkownika

    /**
     * Nowa rozmowa albo — gdy w rozmowie czatu już dzwoni lub trwa — dołączenie do tej (dwie osoby dzwoniące naraz
     * trafiają do jednej: blokada wiersza rozmowy czatu).
     *
     * @return array{0: ChatCall, 1: bool, 2: array{url: string, token: string}} rozmowa, czy powstała teraz, dołączenie
     */
    public function start(User $me, ChatConversation $conversation, string $kind): array
    {
        $this->assertEnabled();

        /** @var array{0: ChatCall, 1: bool} $result */
        $result = DB::transaction(function () use ($me, $conversation, $kind): array {
            ChatConversation::query()->whereKey($conversation->id)->lockForUpdate()->first();
            $existing = ChatCall::query()
                ->where('conversation_id', $conversation->id)
                ->whereIn('status', [ChatCall::STATUS_RINGING, ChatCall::STATUS_ACTIVE])
                ->orderByDesc('id')
                ->first();
            if ($existing !== null) {
                return [$existing, false];
            }

            $now = now();
            $call = ChatCall::query()->create([
                'conversation_id' => $conversation->id,
                'started_by' => $me->id,
                'kind' => $kind,
                'status' => ChatCall::STATUS_RINGING,
                'started_at' => $now,
            ]);

            // W kanale „everyone” zaproszonych nie zapisujemy (nikt tam nie dzwoni) — wiersz powstaje przy dołączeniu.
            $invited = $conversation->everyone ? [] : $this->chat->recipientIds($conversation);
            $rows = [['call_id' => $call->id, 'user_id' => (int) $me->id, 'state' => ChatCallMember::STATE_CONNECTING]];
            foreach ($invited as $userId) {
                if ($userId !== (int) $me->id) {
                    $rows[] = ['call_id' => $call->id, 'user_id' => $userId, 'state' => ChatCallMember::STATE_INVITED];
                }
            }
            ChatCallMember::query()->insert(array_map(static fn (array $row): array => $row + [
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows));

            try {
                $this->rooms->createRoom($call->roomName());
            } catch (RuntimeException $e) {
                Log::warning('Chat call: LiveKit room not created', ['call_id' => $call->id, 'error' => $e->getMessage()]);

                // wycofuje rozmowę i zaproszenia — nikt nie dostaje dzwonka do pokoju, którego nie ma
                throw new HttpException(503, 'Serwer rozmów nie odpowiada.');
            }

            $message = $this->chat->postCallMessage($me, $conversation, $this->messageMeta($call));
            $call->forceFill(['message_id' => $message->id])->save();

            $ringIds = array_values(array_filter($invited, static fn (int $id): bool => $id !== (int) $me->id));
            if (! $conversation->everyone && $ringIds !== []) {
                event(new ChatCallRinging($ringIds, [
                    'call_id' => (int) $call->id,
                    'conversation_id' => (int) $conversation->id,
                    // w rozmowie 1:1 odbiorca widzi ją pod imieniem dzwoniącego
                    'conversation_name' => $conversation->isDirect() ? (string) $me->name : (string) $conversation->name,
                    'kind' => (string) $call->kind,
                    'started_by' => ['id' => (int) $me->id, 'name' => (string) $me->name],
                    'started_at' => $call->started_at?->toIso8601String(),
                ]));
            }

            return [$call, true];
        });

        [$call, $created] = $result;
        if (! $created) {
            [$call, $join] = $this->join($me, $call);

            return [$call, false, $join];
        }

        return [$call->refresh(), true, $this->joinData($call, $me)];
    }

    /**
     * Dołączenie: token do pokoju i stan `connecting` (połączenie potwierdzi webhook). Pełna → 422, zakończona → 409.
     *
     * @return array{0: ChatCall, 1: array{url: string, token: string}}
     */
    public function join(User $me, ChatCall $call): array
    {
        $this->assertEnabled();

        $call = DB::transaction(function () use ($me, $call): ChatCall {
            $locked = $this->lock($call);
            if ($locked->isFinished()) {
                throw new ConflictHttpException('Ta rozmowa już się zakończyła.');
            }
            $member = $this->member($locked, (int) $me->id);
            if ($member?->state !== ChatCallMember::STATE_JOINED) {
                $joined = ChatCallMember::query()
                    ->where('call_id', $locked->id)
                    ->where('state', ChatCallMember::STATE_JOINED)
                    ->count();
                $max = $this->rooms->maxParticipants();
                if ($joined >= $max) {
                    throw ValidationException::withMessages(['call' => 'Rozmowa jest pełna ('.$max.' osób).']);
                }
            }

            $changed = false;
            if ($member === null) {
                ChatCallMember::query()->create([
                    'call_id' => $locked->id,
                    'user_id' => $me->id,
                    'state' => ChatCallMember::STATE_CONNECTING,
                ]);
                $changed = true;
            } elseif ($member->state === ChatCallMember::STATE_CONNECTING) {
                // nowy token — okno na połączenie liczy się od teraz (uzgadnianie w chat:calls-expire)
                $member->touch();
            } elseif ($member->state !== ChatCallMember::STATE_JOINED) {
                // połączona osoba na drugim urządzeniu zostaje `joined` — LiveKit przejmie sesję po identity
                $member->forceFill(['state' => ChatCallMember::STATE_CONNECTING, 'left_at' => null])->save();
                $changed = true;
            }
            if ($changed) {
                $this->dispatchUpdated($locked, [(int) $me->id], ChatCallUpdated::REASON_JOINED);
            }

            return $locked;
        });

        return [$call, $this->joinData($call, $me)];
    }

    /** Odrzucenie: w 1:1 przed odebraniem kończy rozmowę (missed), w grupie zmienia tylko stan tej osoby. */
    public function decline(User $me, ChatCall $call): ChatCall
    {
        return DB::transaction(function () use ($me, $call): ChatCall {
            $locked = $this->lock($call);
            if ($locked->isFinished()) {
                return $locked;
            }
            $member = $this->member($locked, (int) $me->id);
            $changed = false;
            if ($member === null) {
                ChatCallMember::query()->create([
                    'call_id' => $locked->id,
                    'user_id' => $me->id,
                    'state' => ChatCallMember::STATE_DECLINED,
                ]);
                $changed = true;
            } elseif ($member->state === ChatCallMember::STATE_INVITED) {
                // połączona albo łącząca się osoba (inne urządzenie) zostaje w rozmowie
                $member->forceFill(['state' => ChatCallMember::STATE_DECLINED])->save();
                $changed = true;
            }
            if ($changed) {
                $this->dispatchUpdated($locked, [(int) $me->id], ChatCallUpdated::REASON_DECLINED);
            }

            // 1:1 kończy tylko odrzucenie przez osobę, która nie odebrała — spóźnione „Odrzuć” z drugiego urządzenia
            // osoby, która właśnie łączy się z rozmową, nie przerywa jej
            $declined = $changed || $member?->state === ChatCallMember::STATE_DECLINED;
            if ($declined && $locked->status === ChatCall::STATUS_RINGING && $this->isDirect($locked)) {
                $this->finish($locked);
            }

            return $locked;
        });
    }

    /** Wyjście: 1:1 kończy rozmowę, grupowa kończy się, gdy nikt nie jest połączony ani w trakcie łączenia. */
    public function leave(User $me, ChatCall $call): ChatCall
    {
        return DB::transaction(function () use ($me, $call): ChatCall {
            $locked = $this->lock($call);
            if ($locked->isFinished()) {
                return $locked;
            }
            $member = $this->member($locked, (int) $me->id);
            if ($member !== null && $member->state !== ChatCallMember::STATE_LEFT) {
                $member->forceFill(['state' => ChatCallMember::STATE_LEFT, 'left_at' => now()])->save();
            }

            if ($this->isDirect($locked) || ! $this->anyoneLive($locked)) {
                $this->finish($locked);
            }

            return $locked;
        });
    }

    // ---------------------------------------------------------------- LiveKit

    /**
     * Zdarzenie z webhooka (podpis już sprawdzony). Body w protojson: camelCase, int64 jako napisy.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): void
    {
        $event = (string) ($payload['event'] ?? '');
        $callId = ChatCall::idFromRoom((string) data_get($payload, 'room.name', ''));
        if ($callId === null) {
            return;
        }
        $identity = trim((string) data_get($payload, 'participant.identity', ''));
        $sid = trim((string) data_get($payload, 'participant.sid', ''));
        $userId = preg_match('/^[1-9]\d{0,18}$/', $identity) === 1 ? (int) $identity : null;

        match ($event) {
            'participant_joined' => $userId === null ? null : $this->participantJoined($callId, $userId, $sid),
            'participant_left', 'participant_connection_aborted' => $userId === null || $this->isDuplicateIdentity($payload)
                ? null
                : $this->participantLeft($callId, $userId, $sid),
            'room_finished' => $this->roomFinished($callId),
            default => null,
        };
    }

    /**
     * Komenda chat:calls-expire: uzgadnia stan z listą uczestników pokoju i kończy rozmowy wg reguł czasu.
     *
     * @return int ile rozmów zakończono
     */
    public function expire(): int
    {
        $ended = 0;
        $ids = ChatCall::query()
            ->whereIn('status', [ChatCall::STATUS_RINGING, ChatCall::STATUS_ACTIVE])
            ->orderBy('id')
            ->pluck('id');
        foreach ($ids as $id) {
            try {
                if ($this->expireOne((int) $id)) {
                    $ended++;
                }
            } catch (Throwable $e) {
                // jedna rozmowa z błędem nie zatrzymuje pozostałych
                Log::warning('Chat call expire failed', ['call_id' => (int) $id, 'error' => $e->getMessage()]);
            }
        }

        return $ended;
    }

    private function expireOne(int $id): bool
    {
        // lista uczestników poza transakcją — żądanie HTTP (do 3 s) nie trzyma blokady
        $participants = $this->rooms->listParticipants(ChatCall::ROOM_PREFIX.$id);

        return DB::transaction(function () use ($id, $participants): bool {
            $call = ChatCall::query()->whereKey($id)->lockForUpdate()->first();
            if ($call === null || $call->isFinished()) {
                return false;
            }
            $now = now();
            if ($participants !== null) {
                $this->reconcile($call, $participants, $now);
            }

            if ($call->status === ChatCall::STATUS_RINGING) {
                $ring = (int) config('chat.calls.ring_seconds', 45);
                if ($call->started_at === null || $call->started_at->lte($now->copy()->subSeconds($ring))) {
                    // Ktoś właśnie się łączy (POST /join, a przeglądarka pyta o zgodę na mikrofon i kamerę) —
                    // dzwonienie czeka na niego najwyżej tyle, ile ważny jest jego token; inaczej dostałby
                    // „nieodebrane” w trakcie odbierania. Dotyczy też dzwoniącego, który jeszcze stoi na ekranie
                    // „Dołącz” (03.10.2026: po 45 s pokój znikał mu spod rąk i widział „Nie udało się połączyć”) —
                    // ale tylko gdy serwer rozmów potwierdził, że go w pokoju nie ma (bez odpowiedzi LiveKit nie
                    // przedłużamy).
                    $ttl = (int) config('chat.calls.token_ttl', 120);
                    $answering = $call->started_at !== null
                        && $call->started_at->gt($now->copy()->subSeconds($ring + $ttl))
                        && ChatCallMember::query()
                            ->where('call_id', $call->id)
                            ->where('state', ChatCallMember::STATE_CONNECTING)
                            ->when($participants === null, fn ($q) => $q->where('user_id', '!=', (int) $call->started_by))
                            ->where('updated_at', '>', $now->copy()->subSeconds($ttl))
                            ->exists();
                    if ($answering) {
                        return false;
                    }
                    $this->finish($call);

                    return true;
                }

                return false;
            }

            if ($call->started_at === null || $call->started_at->lte($now->copy()->subHours(self::MAX_HOURS))) {
                $this->finish($call);

                return true;
            }
            $joined = ChatCallMember::query()
                ->where('call_id', $call->id)
                ->where('state', ChatCallMember::STATE_JOINED)
                ->count();
            $lastChange = $this->lastChange($call);
            if ($this->isDirect($call)) {
                $end = $joined < 2 && $lastChange->lte($now->copy()->subSeconds(self::DIRECT_ALONE_SECONDS));
            } else {
                $end = ! $this->anyoneLive($call) && $lastChange->lte($now->copy()->subSeconds(self::GROUP_EMPTY_SECONDS));
            }
            if ($end) {
                $this->finish($call);
            }

            return $end;
        });
    }

    /**
     * Stan osób według LiveKit: jest w pokoju → joined (z sid), połączona, a jej nie ma → left. Łącząca się osoba
     * bez śladu w pokoju odpada dopiero po wygaśnięciu jej tokenu (wcześniej może się jeszcze łączyć).
     *
     * @param  array<string, string>  $participants  identity → sid
     */
    private function reconcile(ChatCall $call, array $participants, CarbonInterface $now): void
    {
        $members = ChatCallMember::query()->where('call_id', $call->id)->get()->keyBy('user_id');
        foreach ($participants as $identity => $sid) {
            if (preg_match('/^[1-9]\d{0,18}$/', (string) $identity) !== 1) {
                continue;
            }
            $this->markJoined($call, (int) $identity, $sid, $members->get((int) $identity));
        }

        $tokenTtl = (int) config('chat.calls.token_ttl', 120);
        foreach ($members as $userId => $member) {
            if (array_key_exists((string) $userId, $participants)) {
                continue;
            }
            $gone = $member->state === ChatCallMember::STATE_JOINED
                || ($member->state === ChatCallMember::STATE_CONNECTING
                    && $member->updated_at !== null
                    && $member->updated_at->lte($now->copy()->subSeconds($tokenTtl)));
            if ($gone) {
                $member->forceFill(['state' => ChatCallMember::STATE_LEFT, 'left_at' => $now])->save();
            }
        }
    }

    private function participantJoined(int $callId, int $userId, string $sid): void
    {
        DB::transaction(function () use ($callId, $userId, $sid): void {
            $call = ChatCall::query()->whereKey($callId)->lockForUpdate()->first();
            // ended/missed są końcowe — spóźniony webhook ich nie zmienia
            if ($call === null || $call->isFinished()) {
                return;
            }
            $this->markJoined($call, $userId, $sid, $this->member($call, $userId));
        });
    }

    private function participantLeft(int $callId, int $userId, string $sid): void
    {
        DB::transaction(function () use ($callId, $userId, $sid): void {
            $call = ChatCall::query()->whereKey($callId)->lockForUpdate()->first();
            if ($call === null || $call->isFinished()) {
                return;
            }
            $member = $this->member($call, $userId);
            // starsza sesja (zmiana urządzenia, ponowne łączenie) — liczy się tylko ta, która ostatnio dołączyła
            if ($member === null || $member->state !== ChatCallMember::STATE_JOINED
                || $member->livekit_sid === null || $sid === '' || ! hash_equals($member->livekit_sid, $sid)) {
                return;
            }
            $member->forceFill(['state' => ChatCallMember::STATE_LEFT, 'left_at' => now()])->save();

            if (! $this->isDirect($call) && ! $this->anyoneLive($call)) {
                $this->finish($call);
            }
        });
    }

    private function roomFinished(int $callId): void
    {
        DB::transaction(function () use ($callId): void {
            $call = ChatCall::query()->whereKey($callId)->lockForUpdate()->first();
            if ($call === null || $call->isFinished()) {
                return;
            }
            // pokoju już nie ma — bez DeleteRoom
            $this->finish($call, deleteRoom: false);
        });
    }

    /** Osoba połączona (webhook albo lista pokoju); pierwsza osoba inna niż dzwoniący odbiera rozmowę. */
    private function markJoined(ChatCall $call, int $userId, string $sid, ?ChatCallMember $member): void
    {
        if ($member === null) {
            if (! User::query()->whereKey($userId)->exists()) {
                return;
            }
            $member = new ChatCallMember(['call_id' => $call->id, 'user_id' => $userId]);
        }
        $sid = $sid === '' ? null : mb_substr($sid, 0, 64);
        $wasJoined = $member->exists && $member->state === ChatCallMember::STATE_JOINED;
        if (! $wasJoined || ($sid !== null && $member->livekit_sid !== $sid)) {
            // nowa sesja tej samej osoby (drugie urządzenie) zmienia sid, ale nie czas dołączenia
            $member->forceFill([
                'state' => ChatCallMember::STATE_JOINED,
                'livekit_sid' => $sid ?? $member->livekit_sid,
                'joined_at' => $wasJoined && $member->joined_at !== null ? $member->joined_at : now(),
                'left_at' => null,
            ])->save();
        }

        if ($call->status === ChatCall::STATUS_RINGING && $userId !== (int) $call->started_by) {
            $call->forceFill(['status' => ChatCall::STATUS_ACTIVE, 'answered_at' => now()])->save();
            $this->syncMessage($call);
            $this->dispatchUpdated($call, $this->recipients($call), ChatCallUpdated::REASON_STATUS);
        }
    }

    /**
     * Koniec rozmowy (wołane pod blokadą, gdy status nie jest końcowy): ended po odebraniu, missed bez. Połączeni
     * i łączący się → left, wpis w czacie dostaje status i czas, pokój LiveKit znika po zatwierdzeniu.
     */
    private function finish(ChatCall $call, bool $deleteRoom = true): void
    {
        $now = now();
        $call->forceFill([
            'status' => $call->answered_at !== null ? ChatCall::STATUS_ENDED : ChatCall::STATUS_MISSED,
            'ended_at' => $now,
        ])->save();
        ChatCallMember::query()
            ->where('call_id', $call->id)
            ->whereIn('state', self::LIVE_STATES)
            ->update(['state' => ChatCallMember::STATE_LEFT, 'left_at' => $now, 'updated_at' => $now]);
        $this->syncMessage($call);
        $this->dispatchUpdated($call, $this->recipients($call), ChatCallUpdated::REASON_STATUS);

        if ($deleteRoom) {
            $room = $call->roomName();
            DB::afterCommit(function () use ($room): void {
                $this->rooms->deleteRoom($room);
            });
        }
    }

    // ---------------------------------------------------------------- prezentacja

    /** @return array<string, mixed> */
    public function present(ChatCall $call): array
    {
        $call->load(['starter:id,name', 'members' => fn ($query) => $query->orderBy('id'), 'members.user:id,name']);
        $members = $call->members
            ->filter(static fn (ChatCallMember $member): bool => $member->user instanceof User)
            ->map(static fn (ChatCallMember $member): array => [
                'id' => (int) $member->user_id,
                'name' => (string) $member->user?->name,
                'state' => (string) $member->state,
            ])
            ->values()
            ->all();
        $starter = $call->starter;

        return [
            'id' => (int) $call->id,
            'conversation_id' => (int) $call->conversation_id,
            'kind' => (string) $call->kind,
            'status' => (string) $call->status,
            'started_by' => $starter instanceof User ? ['id' => (int) $starter->id, 'name' => (string) $starter->name] : null,
            'started_at' => $call->started_at?->toIso8601String(),
            'answered_at' => $call->answered_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
            'duration_seconds' => $call->durationSeconds(),
            'message_id' => $call->message_id === null ? null : (int) $call->message_id,
            'members' => $members,
            'joined_count' => count(array_filter($members, static fn (array $m): bool => $m['state'] === ChatCallMember::STATE_JOINED)),
        ];
    }

    /** Rozmowa, gdy pytający jest uczestnikiem jej rozmowy czatu; inaczej 404. */
    public function findForUser(User $me, int $callId): ChatCall
    {
        $call = ChatCall::query()->find($callId);
        if ($call === null) {
            throw new NotFoundHttpException('Nie ma takiej rozmowy.');
        }
        $this->chat->findForUser($me, (int) $call->conversation_id);

        return $call;
    }

    // ---------------------------------------------------------------- pomocnicze

    private function assertEnabled(): void
    {
        if (! $this->rooms->enabled()) {
            throw ValidationException::withMessages(['call' => 'Rozmowy głosowe i wideo nie są włączone.']);
        }
    }

    /** @return array{url: string, token: string} */
    private function joinData(ChatCall $call, User $me): array
    {
        return [
            'url' => $this->rooms->clientUrl(),
            'token' => $this->rooms->clientToken($call->roomName(), (int) $me->id, (string) $me->name),
        ];
    }

    private function lock(ChatCall $call): ChatCall
    {
        return ChatCall::query()->whereKey($call->id)->lockForUpdate()->firstOrFail();
    }

    private function member(ChatCall $call, int $userId): ?ChatCallMember
    {
        return ChatCallMember::query()->where('call_id', $call->id)->where('user_id', $userId)->first();
    }

    private function anyoneLive(ChatCall $call): bool
    {
        return ChatCallMember::query()->where('call_id', $call->id)->whereIn('state', self::LIVE_STATES)->exists();
    }

    private function isDirect(ChatCall $call): bool
    {
        return ChatConversation::query()->whereKey($call->conversation_id)->value('type') === ChatConversation::TYPE_DIRECT;
    }

    /** Ostatnia zmiana obecności: odebranie albo zmiana stanu którejkolwiek osoby. */
    private function lastChange(ChatCall $call): CarbonInterface
    {
        $latest = ChatCallMember::query()->where('call_id', $call->id)->max('updated_at');
        $candidates = array_filter([
            $call->answered_at,
            $call->started_at,
            $latest === null ? null : Carbon::parse((string) $latest),
        ]);
        usort($candidates, static fn (CarbonInterface $a, CarbonInterface $b): int => $b->getTimestamp() <=> $a->getTimestamp());

        return $candidates[0] ?? now();
    }

    /** @param  array<string, mixed>  $payload */
    private function isDuplicateIdentity(array $payload): bool
    {
        $reason = data_get($payload, 'participant.disconnectReason', data_get($payload, 'participant.disconnect_reason'));

        // protojson zapisuje enum nazwą; liczba 2 = DUPLICATE_IDENTITY, gdyby nadawca kodował enumy liczbami
        return $reason === 'DUPLICATE_IDENTITY' || $reason === 2 || $reason === '2';
    }

    /** @return array{id: int, kind: string, status: string, duration_seconds: int|null} */
    private function messageMeta(ChatCall $call): array
    {
        return [
            'id' => (int) $call->id,
            'kind' => (string) $call->kind,
            'status' => (string) $call->status,
            'duration_seconds' => $call->durationSeconds(),
        ];
    }

    /** Ten sam wpis w czacie dostaje nowy status i czas (bez nowej wiadomości). */
    private function syncMessage(ChatCall $call): void
    {
        if ($call->message_id === null) {
            return;
        }
        ChatMessage::query()
            ->whereKey($call->message_id)
            ->where('kind', ChatMessage::KIND_CALL)
            ->first()
            ?->forceFill(['meta' => ['call' => $this->messageMeta($call)]])
            ->save();
    }

    /** @return list<int> */
    private function recipients(ChatCall $call): array
    {
        $conversation = ChatConversation::query()->find($call->conversation_id);

        return $conversation === null ? [] : $this->chat->recipientIds($conversation);
    }

    /** @param  list<int>  $userIds */
    private function dispatchUpdated(ChatCall $call, array $userIds, string $reason): void
    {
        event(new ChatCallUpdated($userIds, [
            'call_id' => (int) $call->id,
            'conversation_id' => (int) $call->conversation_id,
            'message_id' => $call->message_id === null ? null : (int) $call->message_id,
            'status' => (string) $call->status,
            'kind' => (string) $call->kind,
            'reason' => $reason,
            'duration_seconds' => $call->durationSeconds(),
        ]));
    }
}
