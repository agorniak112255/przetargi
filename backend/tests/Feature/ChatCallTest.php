<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\Chat\ChatCallRinging;
use App\Events\Chat\ChatCallUpdated;
use App\Events\Chat\ChatMessageSent;
use App\Models\ActivityLog;
use App\Models\ChatCall;
use App\Models\ChatCallMember;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rozmowy głosowe i wideo w czacie (LiveKit): start, dołączenie z tokenem, odrzucenie, wyjście, webhook
 * z podpisem, uzgadnianie w chat:calls-expire, wpis kind=call i zdarzenia (raz na przejście). LiveKit przez Http::fake.
 */
final class ChatCallTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'APItestKey123';

    private const SECRET = 'test-secret-0123456789abcdef0123456789abcdef';

    /** @var array<int, array{sid: string, identity: string, state: string}> uczestnicy pokoju zwracani przez ListParticipants */
    private array $roomParticipants = [];

    private int $createRoomStatus = 200;

    private int $listStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config([
            'chat.calls.url' => 'wss://rtc.example.pl',
            'chat.calls.api_url' => 'http://livekit.test:7880/',
            'chat.calls.api_key' => self::KEY,
            'chat.calls.api_secret' => self::SECRET,
            'chat.calls.max_participants' => 50,
            'chat.calls.ring_seconds' => 45,
            'chat.calls.token_ttl' => 120,
        ]);
        Http::fake([
            '*/twirp/livekit.RoomService/CreateRoom' => fn () => $this->createRoomStatus === 200
                ? Http::response(['sid' => 'RM_x', 'name' => 'call-x'])
                : Http::response(['code' => 'internal', 'msg' => 'boom'], $this->createRoomStatus),
            '*/twirp/livekit.RoomService/DeleteRoom' => Http::response([]),
            '*/twirp/livekit.RoomService/ListParticipants' => fn () => $this->listStatus === 200
                ? Http::response(['participants' => $this->roomParticipants])
                : Http::response(['code' => 'unavailable', 'msg' => 'boom'], $this->listStatus),
        ]);
        Event::fake([ChatCallRinging::class, ChatCallUpdated::class, ChatMessageSent::class]);
    }

    private function chatUser(string $name): User
    {
        return User::factory()->withRole('handlowiec')->create(['name' => $name]);
    }

    private function direct(User $me, User $other): int
    {
        Sanctum::actingAs($me);

        return (int) $this->postJson('/api/chat/conversations', ['user_id' => $other->id])->assertCreated()->json('data.id');
    }

    /** @param  list<User>  $others */
    private function channel(User $me, array $others): int
    {
        Sanctum::actingAs($me);

        return (int) $this->postJson('/api/chat/conversations', [
            'name' => 'Zespół',
            'user_ids' => array_map(static fn (User $u): int => $u->id, $others),
        ])->assertCreated()->json('data.id');
    }

    private function startCall(User $me, int $conversationId, string $kind = 'video'): TestResponse
    {
        Sanctum::actingAs($me);

        return $this->postJson("/api/chat/conversations/{$conversationId}/calls", ['kind' => $kind]);
    }

    /** @return array<string, mixed> zdarzenie LiveKit w protojson (camelCase, int64 jako napisy) */
    private function lkEvent(string $event, int $callId, ?User $user = null, ?string $sid = null, ?string $reason = null): array
    {
        $payload = [
            'event' => $event,
            'room' => ['sid' => 'RM_abc', 'name' => 'call-'.$callId, 'emptyTimeout' => 60, 'creationTime' => '1759490000'],
            'id' => 'EV_'.Str::random(10),
            'createdAt' => (string) now()->getTimestamp(),
        ];
        if ($user !== null) {
            $payload['participant'] = [
                'sid' => $sid ?? 'PA_'.$user->id,
                'identity' => (string) $user->id,
                'name' => $user->name,
                'state' => 'ACTIVE',
                'joinedAt' => '1759490000',
            ] + ($reason !== null ? ['disconnectReason' => $reason] : []);
        }

        return $payload;
    }

    private function sign(string $body, ?string $secret = null, ?string $iss = null, ?string $sha = null, ?int $exp = null): string
    {
        return JWT::encode([
            'iss' => $iss ?? self::KEY,
            'nbf' => now()->getTimestamp() - 5,
            'exp' => $exp ?? now()->getTimestamp() + 300,
            'sha256' => $sha ?? base64_encode(hash('sha256', $body, true)),
        ], $secret ?? self::SECRET, 'HS256');
    }

    /** @param  array<string, mixed>|string  $payload */
    private function webhook(array|string $payload, ?string $authorization = null): TestResponse
    {
        $body = is_string($payload) ? $payload : (string) json_encode($payload);

        return $this->call('POST', '/api/chat/livekit/webhook', [], [], [], [
            'HTTP_AUTHORIZATION' => $authorization ?? $this->sign($body),
            'CONTENT_TYPE' => 'application/webhook+json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    private function sentTo(string $method): int
    {
        return Http::recorded(static fn (HttpRequest $r): bool => str_ends_with($r->url(), '/twirp/livekit.RoomService/'.$method))->count();
    }

    /** @param  list<int>  $expected @param  list<int>  $actual */
    private function sameIds(array $expected, array $actual): bool
    {
        sort($expected);
        sort($actual);

        return $expected === $actual;
    }

    private function statusEvents(int $callId, string $status): int
    {
        return Event::dispatched(ChatCallUpdated::class, static fn (ChatCallUpdated $e): bool => $e->payload['call_id'] === $callId
            && $e->payload['reason'] === 'status'
            && $e->payload['status'] === $status)->count();
    }

    /** @return array{0: User, 1: User, 2: int} Anna dzwoni do Bartka, a Bartek odbiera (webhook) */
    private function answeredDirectCall(): array
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $callId = (int) $this->startCall($anna, $this->direct($anna, $bartek))->assertCreated()->json('data.id');
        $this->webhook($this->lkEvent('participant_joined', $callId, $anna, 'PA_anna'))->assertOk();
        $this->webhook($this->lkEvent('participant_joined', $callId, $bartek, 'PA_bartek'))->assertOk();

        return [$anna, $bartek, $callId];
    }

    // ---------------------------------------------------------------- start

    public function test_start_creates_call_message_room_and_rings_the_other_person(): void
    {
        $this->freezeTime();
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $conversationId = $this->direct($anna, $bartek);

        $response = $this->startCall($anna, $conversationId)->assertCreated();
        $callId = (int) $response->json('data.id');
        $response->assertJsonPath('data.status', 'ringing')
            ->assertJsonPath('data.kind', 'video')
            ->assertJsonPath('data.conversation_id', $conversationId)
            ->assertJsonPath('data.started_by', ['id' => $anna->id, 'name' => 'Anna'])
            ->assertJsonPath('data.duration_seconds', null)
            ->assertJsonPath('data.joined_count', 0)
            ->assertJsonPath('join.url', 'wss://rtc.example.pl');
        $this->assertSame([
            ['id' => $anna->id, 'name' => 'Anna', 'state' => 'connecting'],
            ['id' => $bartek->id, 'name' => 'Bartek', 'state' => 'invited'],
        ], $response->json('data.members'));
        $this->assertNotEmpty($response->json('join.token'));

        // pokój założony z limitem, JWT serwera z grantami do API pokojów
        Http::assertSent(function (HttpRequest $request) use ($callId): bool {
            if (! str_ends_with($request->url(), '/twirp/livekit.RoomService/CreateRoom')) {
                return false;
            }
            $this->assertSame('http://livekit.test:7880/twirp/livekit.RoomService/CreateRoom', $request->url());
            $this->assertSame('call-'.$callId, $request['name']);
            $this->assertSame(50, $request['max_participants']);
            $this->assertSame(60, $request['empty_timeout']);
            $this->assertSame(20, $request['departure_timeout']);
            $jwt = substr($request->header('Authorization')[0], strlen('Bearer '));
            $claims = (array) JWT::decode($jwt, new Key(self::SECRET, 'HS256'));
            $this->assertSame(self::KEY, $claims['iss']);
            $video = (array) $claims['video'];
            $this->assertTrue($video['roomCreate'] && $video['roomList'] && $video['roomAdmin']);
            $this->assertSame('call-'.$callId, $video['room']);

            return true;
        });

        $message = ChatMessage::query()->findOrFail((int) $response->json('data.message_id'));
        $this->assertSame('call', $message->kind);
        $this->assertNull($message->body);
        $this->assertSame($anna->id, $message->user_id);
        $this->assertSame(['call' => ['id' => $callId, 'kind' => 'video', 'status' => 'ringing', 'duration_seconds' => null]], $message->meta);
        $this->assertSame($message->id, ChatConversation::query()->findOrFail($conversationId)->last_message_id);

        Event::assertDispatched(ChatMessageSent::class, fn (ChatMessageSent $e): bool => $e->payload['kind'] === 'call'
            && $e->payload['preview'] === 'Rozmowa wideo'
            && $this->sameIds([$anna->id, $bartek->id], $e->userIds));
        Event::assertDispatchedTimes(ChatCallRinging::class, 1);
        Event::assertDispatched(ChatCallRinging::class, function (ChatCallRinging $e) use ($anna, $bartek, $callId, $conversationId): bool {
            $this->assertSame('chat.call.ringing', $e->broadcastAs());
            $this->assertSame([$bartek->id], $e->userIds);
            $this->assertSame([
                'call_id' => $callId,
                'conversation_id' => $conversationId,
                'conversation_name' => 'Anna',
                'kind' => 'video',
                'started_by' => ['id' => $anna->id, 'name' => 'Anna'],
                'started_at' => now()->toIso8601String(),
            ], $e->broadcastWith());

            return true;
        });

        // podgląd rozmowy głosowej
        $cezary = $this->chatUser('Cezary');
        $this->startCall($anna, $this->direct($anna, $cezary), 'audio')->assertCreated();
        Event::assertDispatched(ChatMessageSent::class, static fn (ChatMessageSent $e): bool => $e->payload['preview'] === 'Rozmowa głosowa');

        // GET rozmowy dla uczestnika
        Sanctum::actingAs($bartek);
        $this->getJson("/api/chat/calls/{$callId}")->assertOk()->assertJsonPath('data.id', $callId);
    }

    public function test_second_caller_joins_the_same_call_with_200(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $conversationId = $this->direct($anna, $bartek);
        $callId = (int) $this->startCall($anna, $conversationId)->assertCreated()->json('data.id');

        $response = $this->startCall($bartek, $conversationId, 'audio')->assertOk();
        $response->assertJsonPath('data.id', $callId)->assertJsonPath('data.kind', 'video');
        $this->assertNotEmpty($response->json('join.token'));
        $this->assertSame('connecting', ChatCallMember::query()->where('call_id', $callId)->where('user_id', $bartek->id)->value('state'));
        $this->assertSame(1, ChatCall::query()->count());
        $this->assertSame(1, ChatMessage::query()->where('kind', 'call')->count());
        $this->assertSame(1, $this->sentTo('CreateRoom'));
        Event::assertDispatchedTimes(ChatCallRinging::class, 1);
    }

    public function test_disabled_calls_return_422_and_config_says_so(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $conversationId = $this->direct($anna, $bartek);

        $this->getJson('/api/chat/calls/config')->assertOk()->assertExactJson(['enabled' => true, 'max_participants' => 50]);

        config(['chat.calls.api_secret' => null]);
        $this->getJson('/api/chat/calls/config')->assertOk()->assertExactJson(['enabled' => false, 'max_participants' => 50]);
        $this->startCall($anna, $conversationId)->assertStatus(422)
            ->assertJsonPath('message', 'Rozmowy głosowe i wideo nie są włączone.');
        $this->assertSame(0, ChatCall::query()->count());
        Http::assertNothingSent();
    }

    public function test_create_room_failure_returns_503_and_creates_nothing(): void
    {
        $this->createRoomStatus = 500;
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $conversationId = $this->direct($anna, $bartek);

        $this->startCall($anna, $conversationId)->assertStatus(503)->assertJsonPath('message', 'Serwer rozmów nie odpowiada.');

        $this->assertSame(0, ChatCall::query()->count());
        $this->assertSame(0, ChatCallMember::query()->count());
        $this->assertSame(0, ChatMessage::query()->count());
        $this->assertNull(ChatConversation::query()->findOrFail($conversationId)->last_message_id);
        Event::assertNotDispatched(ChatCallRinging::class);
        Event::assertNotDispatched(ChatMessageSent::class);
    }

    public function test_kind_is_validated(): void
    {
        $anna = $this->chatUser('Anna');
        $conversationId = $this->direct($anna, $this->chatUser('Bartek'));
        $this->startCall($anna, $conversationId, 'telefon')->assertStatus(422)->assertJsonValidationErrors('kind');
    }

    public function test_non_participant_gets_404_everywhere(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $callId = (int) $this->startCall($anna, $this->direct($anna, $bartek))->json('data.id');

        Sanctum::actingAs($this->chatUser('Cezary'));
        $this->getJson("/api/chat/calls/{$callId}")->assertNotFound();
        $this->postJson("/api/chat/calls/{$callId}/join")->assertNotFound();
        $this->postJson("/api/chat/calls/{$callId}/decline")->assertNotFound();
        $this->postJson("/api/chat/calls/{$callId}/leave")->assertNotFound();
        $this->getJson('/api/chat/calls/999999')->assertNotFound();
        $this->assertSame('ringing', ChatCall::query()->findOrFail($callId)->status);
        $this->assertSame(2, ChatCallMember::query()->count());
    }

    // ---------------------------------------------------------------- join / decline / leave

    public function test_join_sets_connecting_and_returns_token_with_grants(): void
    {
        $this->freezeTime();
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $callId = (int) $this->startCall($anna, $this->direct($anna, $bartek))->json('data.id');

        Sanctum::actingAs($bartek);
        $response = $this->postJson("/api/chat/calls/{$callId}/join")->assertOk();
        $response->assertJsonPath('data.status', 'ringing')->assertJsonPath('join.url', 'wss://rtc.example.pl');
        $this->assertSame('connecting', ChatCallMember::query()->where('call_id', $callId)->where('user_id', $bartek->id)->value('state'));
        $this->assertSame(0, $response->json('data.joined_count'));

        $timestamp = JWT::$timestamp;
        JWT::$timestamp = now()->getTimestamp();
        try {
            $claims = json_decode((string) json_encode(JWT::decode((string) $response->json('join.token'), new Key(self::SECRET, 'HS256'))), true);
        } finally {
            JWT::$timestamp = $timestamp;
        }
        $this->assertSame(self::KEY, $claims['iss']);
        $this->assertSame((string) $bartek->id, $claims['sub']);
        $this->assertSame('Bartek', $claims['name']);
        $this->assertSame(now()->getTimestamp(), $claims['nbf']);
        $this->assertSame(120, $claims['exp'] - $claims['nbf']);
        $this->assertSame([
            'room' => 'call-'.$callId,
            'roomJoin' => true,
            'canPublish' => true,
            'canSubscribe' => true,
            'canUpdateOwnMetadata' => true,
            'canPublishSources' => ['camera', 'microphone', 'screen_share', 'screen_share_audio'],
        ], $claims['video']);
        $this->assertArrayNotHasKey('canPublishData', $claims['video']);
        $this->assertArrayNotHasKey('roomAdmin', $claims['video']);

        // inne urządzenia Bartka przestają dzwonić
        Event::assertDispatched(ChatCallUpdated::class, static fn (ChatCallUpdated $e): bool => $e->payload['reason'] === 'joined'
            && $e->userIds === [$bartek->id]
            && $e->broadcastAs() === 'chat.call.updated');
        // drugi /join tego samego urządzenia nie wysyła sygnału drugi raz
        $this->postJson("/api/chat/calls/{$callId}/join")->assertOk();
        $this->assertSame(1, Event::dispatched(ChatCallUpdated::class, static fn (ChatCallUpdated $e): bool => $e->payload['reason'] === 'joined')->count());
    }

    public function test_join_finished_call_returns_409(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $callId = (int) $this->startCall($anna, $this->direct($anna, $bartek))->json('data.id');
        $this->postJson("/api/chat/calls/{$callId}/leave")->assertOk()->assertJsonPath('data.status', 'missed');

        Sanctum::actingAs($bartek);
        $this->postJson("/api/chat/calls/{$callId}/join")->assertStatus(409)->assertJsonPath('message', 'Ta rozmowa już się zakończyła.');
        $this->assertSame('missed', ChatCall::query()->findOrFail($callId)->status);
    }

    public function test_join_full_call_returns_422(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $callId = (int) $this->startCall($anna, $this->channel($anna, [$bartek]))->json('data.id');
        $now = now();
        ChatCallMember::query()->insert(User::factory()->count(50)->create()->map(static fn (User $u): array => [
            'call_id' => $callId,
            'user_id' => $u->id,
            'state' => 'joined',
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        Sanctum::actingAs($bartek);
        $this->postJson("/api/chat/calls/{$callId}/join")->assertStatus(422)->assertJsonPath('message', 'Rozmowa jest pełna (50 osób).');
        $this->assertSame('invited', ChatCallMember::query()->where('call_id', $callId)->where('user_id', $bartek->id)->value('state'));
    }

    public function test_decline_in_direct_call_makes_it_missed_and_deletes_room(): void
    {
        $this->freezeTime();
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $conversationId = $this->direct($anna, $bartek);
        $callId = (int) $this->startCall($anna, $conversationId)->json('data.id');

        Sanctum::actingAs($bartek);
        $this->postJson("/api/chat/calls/{$callId}/decline")->assertOk()
            ->assertJsonPath('data.status', 'missed')
            ->assertJsonPath('data.ended_at', now()->toIso8601String())
            ->assertJsonPath('data.duration_seconds', null);
        $this->assertSame(1, $this->sentTo('DeleteRoom'));
        Http::assertSent(static fn (HttpRequest $r): bool => str_ends_with($r->url(), '/DeleteRoom') && $r['room'] === 'call-'.$callId);
        $this->assertSame('declined', ChatCallMember::query()->where('call_id', $callId)->where('user_id', $bartek->id)->value('state'));
        $this->assertSame('left', ChatCallMember::query()->where('call_id', $callId)->where('user_id', $anna->id)->value('state'));

        $call = ChatCall::query()->findOrFail($callId);
        $this->assertSame(['call' => ['id' => $callId, 'kind' => 'video', 'status' => 'missed', 'duration_seconds' => null]], ChatMessage::query()->findOrFail($call->message_id)->meta);
        $this->assertSame(1, $this->statusEvents($callId, 'missed'));
        Event::assertDispatched(ChatCallUpdated::class, fn (ChatCallUpdated $e): bool => $e->payload['status'] === 'missed'
            && $this->sameIds([$anna->id, $bartek->id], $e->userIds)
            && array_keys($e->payload) === ['call_id', 'conversation_id', 'message_id', 'status', 'kind', 'reason', 'duration_seconds']);
        Event::assertDispatched(ChatCallUpdated::class, static fn (ChatCallUpdated $e): bool => $e->payload['reason'] === 'declined'
            && $e->userIds === [$bartek->id]);

        // ponowne odrzucenie i wyjście nic już nie zmieniają
        $this->postJson("/api/chat/calls/{$callId}/decline")->assertOk()->assertJsonPath('data.status', 'missed');
        $this->postJson("/api/chat/calls/{$callId}/leave")->assertOk()->assertJsonPath('data.status', 'missed');
        $this->assertSame(1, $this->statusEvents($callId, 'missed'));
        $this->assertSame(1, $this->sentTo('DeleteRoom'));
    }

    public function test_late_decline_from_second_device_does_not_end_direct_call_being_answered(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $callId = (int) $this->startCall($anna, $this->direct($anna, $bartek))->json('data.id');

        Sanctum::actingAs($bartek);
        $this->postJson("/api/chat/calls/{$callId}/join")->assertOk();
        $this->postJson("/api/chat/calls/{$callId}/decline")->assertOk()->assertJsonPath('data.status', 'ringing');

        $this->assertSame('connecting', ChatCallMember::query()->where('call_id', $callId)->where('user_id', $bartek->id)->value('state'));
        $this->assertSame(0, $this->sentTo('DeleteRoom'));
        Event::assertNotDispatched(ChatCallUpdated::class, static fn (ChatCallUpdated $e): bool => $e->payload['reason'] === 'declined');
    }

    public function test_decline_in_group_call_changes_only_that_person(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $cezary = $this->chatUser('Cezary');
        $conversationId = $this->channel($anna, [$bartek, $cezary]);
        $callId = (int) $this->startCall($anna, $conversationId)->assertCreated()->json('data.id');
        Event::assertDispatched(ChatCallRinging::class, fn (ChatCallRinging $e): bool => $this->sameIds([$bartek->id, $cezary->id], $e->userIds)
            && $e->payload['conversation_name'] === 'Zespół');

        Sanctum::actingAs($bartek);
        $this->postJson("/api/chat/calls/{$callId}/decline")->assertOk()->assertJsonPath('data.status', 'ringing');

        $this->assertSame('declined', ChatCallMember::query()->where('call_id', $callId)->where('user_id', $bartek->id)->value('state'));
        $this->assertSame('invited', ChatCallMember::query()->where('call_id', $callId)->where('user_id', $cezary->id)->value('state'));
        $this->assertSame(0, $this->sentTo('DeleteRoom'));
        Event::assertDispatchedTimes(ChatCallUpdated::class, 1);
        Event::assertDispatched(ChatCallUpdated::class, static fn (ChatCallUpdated $e): bool => $e->payload['reason'] === 'declined'
            && $e->payload['status'] === 'ringing'
            && $e->userIds === [$bartek->id]);
    }

    public function test_caller_leaving_before_answer_makes_call_missed(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $callId = (int) $this->startCall($anna, $this->direct($anna, $bartek))->json('data.id');

        $this->postJson("/api/chat/calls/{$callId}/leave")->assertOk()->assertJsonPath('data.status', 'missed');
        $this->assertSame(1, $this->sentTo('DeleteRoom'));
        $this->assertSame(1, $this->statusEvents($callId, 'missed'));
    }

    public function test_group_call_ends_when_last_connected_person_leaves(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $cezary = $this->chatUser('Cezary');
        $callId = (int) $this->startCall($anna, $this->channel($anna, [$bartek, $cezary]))->json('data.id');
        $this->webhook($this->lkEvent('participant_joined', $callId, $anna, 'PA_anna'))->assertOk();
        $this->webhook($this->lkEvent('participant_joined', $callId, $bartek, 'PA_bartek'))->assertOk();
        $this->assertSame('active', ChatCall::query()->findOrFail($callId)->status);

        Sanctum::actingAs($anna);
        $this->postJson("/api/chat/calls/{$callId}/leave")->assertOk()->assertJsonPath('data.status', 'active');
        Sanctum::actingAs($bartek);
        $this->postJson("/api/chat/calls/{$callId}/leave")->assertOk()->assertJsonPath('data.status', 'ended');
        $this->assertSame(1, $this->statusEvents($callId, 'ended'));
    }

    public function test_leave_after_answer_in_direct_call_ends_with_duration(): void
    {
        $this->freezeTime();
        [$anna, $bartek, $callId] = $this->answeredDirectCall();
        $this->travel(5)->minutes();

        Sanctum::actingAs($bartek);
        $this->postJson("/api/chat/calls/{$callId}/leave")->assertOk()
            ->assertJsonPath('data.status', 'ended')
            ->assertJsonPath('data.duration_seconds', 300)
            ->assertJsonPath('data.joined_count', 0);

        $call = ChatCall::query()->findOrFail($callId);
        $this->assertSame(['call' => ['id' => $callId, 'kind' => 'video', 'status' => 'ended', 'duration_seconds' => 300]], ChatMessage::query()->findOrFail($call->message_id)->meta);
        $this->assertSame(1, $this->statusEvents($callId, 'ended'));
        Event::assertDispatched(ChatCallUpdated::class, fn (ChatCallUpdated $e): bool => $e->payload['status'] === 'ended'
            && $e->payload['duration_seconds'] === 300
            && $this->sameIds([$anna->id, $bartek->id], $e->userIds));
        $this->assertSame(1, $this->sentTo('DeleteRoom'));
    }

    // ---------------------------------------------------------------- webhook

    public function test_webhook_rejects_bad_signatures(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $callId = (int) $this->startCall($anna, $this->direct($anna, $bartek))->json('data.id');
        $body = (string) json_encode($this->lkEvent('participant_joined', $callId, $bartek));

        $this->webhook($body, $this->sign($body, secret: 'inny-sekret-0123456789abcdef0123456789'))->assertUnauthorized();
        $this->webhook($body, $this->sign($body, sha: base64_encode(hash('sha256', $body.' ', true))))->assertUnauthorized();
        $this->webhook($body, $this->sign($body, iss: 'obcy-klucz'))->assertUnauthorized();
        $this->webhook($body, $this->sign($body, exp: now()->getTimestamp() - 120))->assertUnauthorized();
        $this->webhook($body, '')->assertUnauthorized();
        $this->webhook($body, 'Bearer nie-jwt')->assertUnauthorized();
        $this->assertSame('ringing', ChatCall::query()->findOrFail($callId)->status);

        // nagłówek z „Bearer ” też jest przyjmowany
        $this->webhook($body, 'Bearer '.$this->sign($body))->assertOk();
        $this->assertSame('active', ChatCall::query()->findOrFail($callId)->status);
    }

    public function test_webhook_signed_sample_body_without_bearer(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $callId = (int) $this->startCall($anna, $this->direct($anna, $bartek))->json('data.id');
        // próbka w kształcie, w jakim LiveKit wysyła webhook (protojson: camelCase, int64 jako napisy)
        $body = '{"event":"participant_joined","room":{"sid":"RM_hycBMAjmt6Ub","name":"call-'.$callId.'","emptyTimeout":60,'
            .'"departureTimeout":20,"maxParticipants":50,"creationTime":"1759490000","turnPassword":"x","enabledCodecs":[{"mime":"audio/opus"}]},'
            .'"participant":{"sid":"PA_39FUYc6QCRbv","identity":"'.$bartek->id.'","state":"JOINED","joinedAt":"1759490012",'
            .'"name":"Bartek","version":2,"permission":{"canSubscribe":true,"canPublish":true}},'
            .'"id":"EV_eugWmGhovZmm","createdAt":"1759490012"}';

        $this->webhook($body, $this->sign($body))->assertOk();

        $call = ChatCall::query()->findOrFail($callId);
        $this->assertSame('active', $call->status);
        $this->assertSame('PA_39FUYc6QCRbv', ChatCallMember::query()->where('call_id', $callId)->where('user_id', $bartek->id)->value('livekit_sid'));
    }

    public function test_webhook_participant_joined_by_other_person_answers_the_call_once(): void
    {
        $this->freezeTime();
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $callId = (int) $this->startCall($anna, $this->direct($anna, $bartek))->json('data.id');

        // dzwoniący w pokoju — nadal dzwoni
        $this->webhook($this->lkEvent('participant_joined', $callId, $anna, 'PA_anna'))->assertOk();
        $call = ChatCall::query()->findOrFail($callId);
        $this->assertSame('ringing', $call->status);
        $this->assertNull($call->answered_at);

        $this->travel(10)->seconds();
        $this->webhook($this->lkEvent('participant_joined', $callId, $bartek, 'PA_bartek'))->assertOk();
        $this->webhook($this->lkEvent('participant_joined', $callId, $bartek, 'PA_bartek'))->assertOk();

        $call->refresh();
        $this->assertSame('active', $call->status);
        $this->assertSame(now()->toIso8601String(), $call->answered_at?->toIso8601String());
        $member = ChatCallMember::query()->where('call_id', $callId)->where('user_id', $bartek->id)->firstOrFail();
        $this->assertSame('joined', $member->state);
        $this->assertSame('PA_bartek', $member->livekit_sid);
        $this->assertSame(1, $this->statusEvents($callId, 'active'));
        $this->assertSame('active', ChatMessage::query()->findOrFail($call->message_id)->meta['call']['status']);

        Sanctum::actingAs($anna);
        $this->getJson("/api/chat/calls/{$callId}")->assertOk()->assertJsonPath('data.joined_count', 2);
    }

    public function test_webhook_left_from_old_session_or_duplicate_identity_is_ignored_and_direct_call_continues(): void
    {
        [$anna, $bartek, $callId] = $this->answeredDirectCall();

        // Bartek przełącza się na telefon: nowa sesja, stara wychodzi z DUPLICATE_IDENTITY
        $this->webhook($this->lkEvent('participant_joined', $callId, $bartek, 'PA_bartek_tel'))->assertOk();
        $this->webhook($this->lkEvent('participant_left', $callId, $bartek, 'PA_bartek', 'DUPLICATE_IDENTITY'))->assertOk();
        $this->webhook($this->lkEvent('participant_left', $callId, $bartek, 'PA_bartek', 'CLIENT_INITIATED'))->assertOk();
        $this->webhook($this->lkEvent('participant_connection_aborted', $callId, $bartek, 'PA_bartek'))->assertOk();
        $this->webhook($this->lkEvent('participant_left', $callId, $bartek, 'PA_bartek_tel', 'DUPLICATE_IDENTITY'))->assertOk();

        $member = ChatCallMember::query()->where('call_id', $callId)->where('user_id', $bartek->id)->firstOrFail();
        $this->assertSame('joined', $member->state);
        $this->assertSame('PA_bartek_tel', $member->livekit_sid);

        // prawdziwe wyjście aktualnej sesji: osoba wychodzi, ale rozmowy 1:1 webhook nie kończy (K1)
        $this->webhook($this->lkEvent('participant_left', $callId, $bartek, 'PA_bartek_tel', 'CLIENT_INITIATED'))->assertOk();
        $this->assertSame('left', $member->refresh()->state);
        $this->assertSame('active', ChatCall::query()->findOrFail($callId)->status);
        $this->assertSame(0, $this->statusEvents($callId, 'ended'));
        $this->assertSame(0, $this->sentTo('DeleteRoom'));
    }

    public function test_webhook_room_finished_ends_or_misses(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $cezary = $this->chatUser('Cezary');
        $ringing = (int) $this->startCall($anna, $this->direct($anna, $bartek))->json('data.id');
        $this->webhook($this->lkEvent('room_finished', $ringing))->assertOk();
        $this->assertSame('missed', ChatCall::query()->findOrFail($ringing)->status);

        $answered = (int) $this->startCall($anna, $this->direct($anna, $cezary))->json('data.id');
        $this->webhook($this->lkEvent('participant_joined', $answered, $cezary))->assertOk();
        $this->webhook($this->lkEvent('room_finished', $answered))->assertOk();
        $this->webhook($this->lkEvent('room_finished', $answered))->assertOk();
        $this->assertSame('ended', ChatCall::query()->findOrFail($answered)->status);
        $this->assertSame(1, $this->statusEvents($answered, 'ended'));
        // pokoju już nie ma — bez DeleteRoom
        $this->assertSame(0, $this->sentTo('DeleteRoom'));
    }

    public function test_webhook_other_events_and_unknown_rooms_are_ignored(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $callId = (int) $this->startCall($anna, $this->direct($anna, $bartek))->json('data.id');

        $this->webhook($this->lkEvent('track_published', $callId, $bartek))->assertOk();
        $this->webhook($this->lkEvent('room_started', $callId))->assertOk();
        $this->webhook(['event' => 'participant_joined', 'room' => ['name' => 'inny-pokoj'], 'participant' => ['identity' => (string) $bartek->id, 'sid' => 'PA_x']])->assertOk();
        $this->webhook($this->lkEvent('participant_joined', 999999, $bartek))->assertOk();
        $this->webhook(['event' => 'participant_joined', 'room' => ['name' => 'call-'.$callId], 'participant' => ['identity' => 'gosc', 'sid' => 'PA_g']])->assertOk();

        $this->assertSame('ringing', ChatCall::query()->findOrFail($callId)->status);
        $this->assertSame('invited', ChatCallMember::query()->where('call_id', $callId)->where('user_id', $bartek->id)->value('state'));
        Event::assertNotDispatched(ChatCallUpdated::class);
    }

    public function test_late_webhook_does_not_change_finished_call(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $callId = (int) $this->startCall($anna, $this->direct($anna, $bartek))->json('data.id');
        $this->postJson("/api/chat/calls/{$callId}/leave")->assertOk()->assertJsonPath('data.status', 'missed');

        $this->webhook($this->lkEvent('participant_joined', $callId, $bartek, 'PA_late'))->assertOk();

        $call = ChatCall::query()->findOrFail($callId);
        $this->assertSame('missed', $call->status);
        $this->assertNull($call->answered_at);
        $this->assertSame('invited', ChatCallMember::query()->where('call_id', $callId)->where('user_id', $bartek->id)->value('state'));
        $this->assertSame(0, $this->statusEvents($callId, 'active'));
    }

    // ---------------------------------------------------------------- chat:calls-expire

    public function test_expire_marks_unanswered_ringing_call_missed_after_ring_time(): void
    {
        $this->freezeTime();
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $callId = (int) $this->startCall($anna, $this->direct($anna, $bartek))->json('data.id');
        $this->roomParticipants = [['sid' => 'PA_anna', 'identity' => (string) $anna->id, 'state' => 'ACTIVE']];

        $this->travel(44)->seconds();
        $this->artisan('chat:calls-expire')->assertSuccessful();
        $this->assertSame('ringing', ChatCall::query()->findOrFail($callId)->status);
        // uzgodnienie: dzwoniąca jest w pokoju
        $this->assertSame('joined', ChatCallMember::query()->where('call_id', $callId)->where('user_id', $anna->id)->value('state'));

        $this->travel(2)->seconds();
        $this->artisan('chat:calls-expire')->assertSuccessful();
        $this->assertSame('missed', ChatCall::query()->findOrFail($callId)->status);
        $this->assertSame(1, $this->statusEvents($callId, 'missed'));
        $this->assertSame(1, $this->sentTo('DeleteRoom'));
    }

    public function test_expire_reconciles_and_answers_when_webhook_was_lost(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $callId = (int) $this->startCall($anna, $this->direct($anna, $bartek))->json('data.id');
        $this->roomParticipants = [
            ['sid' => 'PA_anna', 'identity' => (string) $anna->id, 'state' => 'ACTIVE'],
            ['sid' => 'PA_bartek', 'identity' => (string) $bartek->id, 'state' => 'ACTIVE'],
        ];

        $this->artisan('chat:calls-expire')->assertSuccessful();

        $call = ChatCall::query()->findOrFail($callId);
        $this->assertSame('active', $call->status);
        $this->assertNotNull($call->answered_at);
        $this->assertSame('PA_bartek', ChatCallMember::query()->where('call_id', $callId)->where('user_id', $bartek->id)->value('livekit_sid'));
        $this->assertSame(1, $this->statusEvents($callId, 'active'));
    }

    public function test_expire_ends_direct_call_with_fewer_than_two_connected_for_a_minute(): void
    {
        $this->freezeTime();
        [$anna, $bartek, $callId] = $this->answeredDirectCall();
        $this->travel(10)->minutes();

        // Bartek zniknął z pokoju bez webhooka
        $this->roomParticipants = [['sid' => 'PA_anna', 'identity' => (string) $anna->id, 'state' => 'ACTIVE']];
        $this->artisan('chat:calls-expire')->assertSuccessful();
        $this->assertSame('active', ChatCall::query()->findOrFail($callId)->status);
        $this->assertSame('left', ChatCallMember::query()->where('call_id', $callId)->where('user_id', $bartek->id)->value('state'));

        $this->travel(30)->seconds();
        $this->artisan('chat:calls-expire')->assertSuccessful();
        $this->assertSame('active', ChatCall::query()->findOrFail($callId)->status);

        $this->travel(31)->seconds();
        $this->artisan('chat:calls-expire')->assertSuccessful();
        $call = ChatCall::query()->findOrFail($callId);
        $this->assertSame('ended', $call->status);
        $this->assertSame(10 * 60 + 61, $call->durationSeconds());
        $this->assertSame(1, $this->statusEvents($callId, 'ended'));
        $this->assertSame(1, $this->sentTo('DeleteRoom'));
    }

    public function test_expire_waits_for_recipient_who_is_connecting_until_token_expires(): void
    {
        $this->freezeTime();
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $callId = (int) $this->startCall($anna, $this->direct($anna, $bartek))->json('data.id');
        $this->roomParticipants = [['sid' => 'PA_anna', 'identity' => (string) $anna->id, 'state' => 'ACTIVE']];

        // Bartek kliknął „Dołącz” w 40. sekundzie, przeglądarka pyta go o mikrofon — rozmowa nie może przepaść
        $this->travel(40)->seconds();
        Sanctum::actingAs($bartek);
        $this->postJson("/api/chat/calls/{$callId}/join")->assertOk();
        $this->travel(6)->seconds();
        $this->artisan('chat:calls-expire')->assertSuccessful();
        $this->assertSame('ringing', ChatCall::query()->findOrFail($callId)->status);

        // nie dołączył, a jego token wygasł (120 s) — teraz już nieodebrane
        $this->travel(121)->seconds();
        $this->artisan('chat:calls-expire')->assertSuccessful();
        $this->assertSame('missed', ChatCall::query()->findOrFail($callId)->status);
    }

    public function test_expire_waits_for_caller_still_on_join_screen(): void
    {
        $this->freezeTime();
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $callId = (int) $this->startCall($anna, $this->direct($anna, $bartek))->json('data.id');
        // LiveKit odpowiada, ale Anny jeszcze nie ma w pokoju — wybiera mikrofon na ekranie „Dołącz”
        $this->roomParticipants = [];

        $this->travel(46)->seconds();
        $this->artisan('chat:calls-expire')->assertSuccessful();
        $this->assertSame('ringing', ChatCall::query()->findOrFail($callId)->status);
        $this->assertSame(0, $this->sentTo('DeleteRoom'));

        // nie dołączyła, a jej token wygasł — nieodebrane
        $this->travel(121)->seconds();
        $this->artisan('chat:calls-expire')->assertSuccessful();
        $this->assertSame('missed', ChatCall::query()->findOrFail($callId)->status);
    }

    public function test_expire_without_livekit_answer_still_applies_ring_timeout(): void
    {
        $this->freezeTime();
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $callId = (int) $this->startCall($anna, $this->direct($anna, $bartek))->json('data.id');
        $this->listStatus = 503;

        $this->travel(46)->seconds();
        $this->artisan('chat:calls-expire')->assertSuccessful();
        $this->assertSame('missed', ChatCall::query()->findOrFail($callId)->status);
    }

    // ---------------------------------------------------------------- kanał „everyone”, wpis w czacie, dziennik

    public function test_call_in_everyone_channel_does_not_ring(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        Sanctum::actingAs($bartek);
        $this->getJson('/api/chat/conversations')->assertOk();
        $general = ChatConversation::query()->where('everyone', true)->firstOrFail();

        $response = $this->startCall($anna, (int) $general->id, 'audio')->assertCreated();
        $callId = (int) $response->json('data.id');

        Event::assertNotDispatched(ChatCallRinging::class);
        Event::assertDispatched(ChatMessageSent::class, fn (ChatMessageSent $e): bool => $e->payload['kind'] === 'call'
            && $e->payload['preview'] === 'Rozmowa głosowa'
            && in_array($bartek->id, $e->userIds, true));
        $this->assertSame([['id' => $anna->id, 'name' => 'Anna', 'state' => 'connecting']], $response->json('data.members'));

        // każdy z kanału może dołączyć
        Sanctum::actingAs($bartek);
        $this->postJson("/api/chat/calls/{$callId}/join")->assertOk()->assertJsonPath('data.members.1.state', 'connecting');
    }

    public function test_call_message_cannot_be_deleted(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $messageId = (int) $this->startCall($anna, $this->direct($anna, $bartek))->json('data.message_id');

        $this->deleteJson("/api/chat/messages/{$messageId}")->assertStatus(422)
            ->assertJsonPath('message', 'Wpisu o rozmowie nie można usunąć.');
        $message = ChatMessage::query()->findOrFail($messageId);
        $this->assertNull($message->deleted_at);
        $this->assertSame('ringing', $message->meta['call']['status']);
    }

    public function test_calls_never_reach_activity_log(): void
    {
        [$anna, $bartek, $callId] = $this->answeredDirectCall();
        Sanctum::actingAs($bartek);
        $this->getJson('/api/chat/calls/config')->assertOk();
        $this->getJson("/api/chat/calls/{$callId}")->assertOk();
        $this->postJson("/api/chat/calls/{$callId}/join")->assertOk();
        $this->postJson("/api/chat/calls/{$callId}/decline")->assertOk();
        $this->postJson("/api/chat/calls/{$callId}/leave")->assertOk();

        $this->assertSame(0, ActivityLog::query()->count());
    }
}
