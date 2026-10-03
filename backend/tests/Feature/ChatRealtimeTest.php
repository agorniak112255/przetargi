<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\Chat\ChatConversationChanged;
use App\Events\Chat\ChatMessageSent;
use App\Events\Chat\ChatRead;
use App\Events\Chat\QueueUpdated;
use App\Models\ChatConversation;
use App\Models\ClientInquiry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Czat w czasie rzeczywistym: zdarzenia (sygnał bez pełnej treści), odporność wysyłki na awarię Reverb, autoryzacja
 * kanału user.{id}, GET /realtime, kolejka dodatku do Thunderbirda (queue.updated, X-Poll-After) i migracja uprawnienia.
 */
final class ChatRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function general(): ChatConversation
    {
        return ChatConversation::query()->where('everyone', true)->firstOrFail();
    }

    private function enableReverb(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1001',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
            'chat.realtime.key' => 'test-key',
            'chat.realtime.host' => 'przetargi.example.pl',
            'chat.realtime.port' => 443,
            'chat.realtime.scheme' => 'https',
        ]);
        // kanały zarejestrowano przy starcie na sterowniku z phpunit.xml — rejestrujemy je na Reverbie
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');
    }

    public function test_chat_message_event_goes_to_all_participants_without_full_body(): void
    {
        Event::fake([ChatMessageSent::class, ChatConversationChanged::class]);
        $owner = User::factory()->withRole('handlowiec')->create(['name' => 'Anna']);
        $anna = User::factory()->withRole('kierownik')->create();
        $bartek = User::factory()->withRole('dyrektor')->create();
        Sanctum::actingAs($owner);

        $channelId = (int) $this->postJson('/api/chat/conversations', ['name' => 'Oferta szpital', 'user_ids' => [$anna->id, $bartek->id]])
            ->assertCreated()
            ->json('data.id');
        Event::assertDispatched(ChatConversationChanged::class, fn (ChatConversationChanged $e): bool => $e->conversationId === $channelId
            && $e->broadcastAs() === 'chat.conversation'
            && $this->sameIds([$owner->id, $anna->id, $bartek->id], $e->userIds));

        $body = str_repeat('ą', 4000);
        $messageId = (int) $this->postJson("/api/chat/conversations/{$channelId}/messages", [
            'client_uuid' => (string) Str::uuid(),
            'body' => $body,
        ])->assertCreated()->json('data.id');

        Event::assertDispatchedTimes(ChatMessageSent::class, 1);
        Event::assertDispatched(ChatMessageSent::class, function (ChatMessageSent $event) use ($owner, $anna, $bartek, $channelId, $messageId): bool {
            $payload = $event->broadcastWith();
            $this->assertSame('chat.message', $event->broadcastAs());
            // nadawca też — ma kilka urządzeń
            $this->assertTrue($this->sameIds([$owner->id, $anna->id, $bartek->id], $event->userIds));
            $this->assertEqualsCanonicalizing(
                ['private-user.'.$owner->id, 'private-user.'.$anna->id, 'private-user.'.$bartek->id],
                array_map(static fn (PrivateChannel $c): string => $c->name, $event->broadcastOn()),
            );
            $this->assertSame(['conversation_id', 'message_id', 'kind', 'user', 'preview', 'conversation_name', 'created_at'], array_keys($payload));
            $this->assertSame($channelId, $payload['conversation_id']);
            $this->assertSame($messageId, $payload['message_id']);
            $this->assertSame('text', $payload['kind']);
            $this->assertSame(['id' => $owner->id, 'name' => 'Anna'], $payload['user']);
            $this->assertSame('Oferta szpital', $payload['conversation_name']);
            $this->assertLessThanOrEqual(300, mb_strlen($payload['preview']));
            // limit Reverb: 10 000 bajtów na zdarzenie (Pusher koduje dane jeszcze raz — liczymy z zapasem)
            $this->assertLessThan(10_000, strlen((string) json_encode(['event' => 'chat.message', 'data' => json_encode($payload)])));

            return true;
        });
    }

    public function test_events_for_direct_mail_and_everyone_channel(): void
    {
        Event::fake([ChatMessageSent::class]);
        $anna = User::factory()->withRole('handlowiec')->create(['name' => 'Anna']);
        $bartek = User::factory()->withRole('handlowiec')->create(['name' => 'Bartek']);
        $noChat = User::factory()->create();
        Sanctum::actingAs($anna);

        $this->postJson("/api/chat/direct/{$bartek->id}/messages", [
            'client_uuid' => (string) Str::uuid(),
            'mail' => ['subject' => 'Zapytanie o kaski', 'from' => 'klient@firma.pl', 'body' => str_repeat('Treść maila ', 1000)],
        ])->assertCreated();
        Event::assertDispatched(ChatMessageSent::class, fn (ChatMessageSent $e): bool => $e->payload['kind'] === 'mail'
            && $e->payload['preview'] === 'Zapytanie o kaski'
            // w rozmowie 1:1 odbiorca widzi ją pod imieniem nadawcy
            && $e->payload['conversation_name'] === 'Anna'
            && $this->sameIds([$anna->id, $bartek->id], $e->userIds));

        // „Ogólny”: wszyscy z uprawnieniem czatu, także ci, którzy jeszcze nie otworzyli czatu
        $this->postJson("/api/chat/conversations/{$this->general()->id}/messages", [
            'client_uuid' => (string) Str::uuid(),
            'body' => 'Spotkanie o 10',
        ])->assertCreated();
        Event::assertDispatched(ChatMessageSent::class, fn (ChatMessageSent $e): bool => $e->payload['conversation_name'] === 'Ogólny'
            && $this->sameIds([$anna->id, $bartek->id], $e->userIds)
            && ! in_array($noChat->id, $e->userIds, true));
    }

    public function test_read_event_goes_only_to_reader(): void
    {
        Event::fake([ChatRead::class, ChatMessageSent::class]);
        $anna = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($anna);
        $general = $this->general()->id;
        $messageId = (int) $this->postJson("/api/chat/conversations/{$general}/messages", [
            'client_uuid' => (string) Str::uuid(),
            'body' => 'Notatka',
        ])->json('data.id');

        $this->postJson("/api/chat/conversations/{$general}/read", ['message_id' => $messageId])->assertOk();
        Event::assertDispatched(ChatRead::class, fn (ChatRead $e): bool => $e->broadcastAs() === 'chat.read'
            && $e->broadcastOn()[0]->name === 'private-user.'.$anna->id
            && $e->broadcastWith() === ['conversation_id' => $general, 'last_read_message_id' => $messageId, 'unread_total' => 0]);
    }

    public function test_broadcast_failure_does_not_break_sending(): void
    {
        Broadcast::extend('failing', static fn (): Broadcaster => new class extends Broadcaster
        {
            public function auth($request)
            {
                throw new RuntimeException('Reverb nie działa');
            }

            public function validAuthenticationResponse($request, $result)
            {
                throw new RuntimeException('Reverb nie działa');
            }

            public function broadcast(array $channels, $event, array $payload = [])
            {
                throw new RuntimeException('Reverb nie działa');
            }
        });
        config([
            'broadcasting.default' => 'failing',
            'broadcasting.connections.failing' => ['driver' => 'failing'],
        ]);
        Broadcast::forgetDrivers();

        $anna = User::factory()->withRole('handlowiec')->create();
        $bartek = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($anna);

        $this->postJson("/api/chat/direct/{$bartek->id}/messages", ['client_uuid' => (string) Str::uuid(), 'body' => 'Mimo awarii'])
            ->assertCreated();
        $this->postJson('/api/chat/conversations', ['name' => 'Kanał', 'user_ids' => [$bartek->id]])->assertCreated();
        $this->postJson("/api/chat/conversations/{$this->general()->id}/messages", ['client_uuid' => (string) Str::uuid(), 'body' => 'Hej'])
            ->assertCreated();
        $this->assertDatabaseHas('chat_messages', ['body' => 'Mimo awarii']);
    }

    public function test_private_user_channel_authorization(): void
    {
        $this->enableReverb();
        $anna = User::factory()->withRole('handlowiec')->create();
        $bartek = User::factory()->withRole('handlowiec')->create();
        $token = $anna->createToken('spa')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-user.'.$anna->id])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-user.'.$bartek->id])
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])
            ->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-user.'.$anna->id])
            ->assertUnauthorized();
    }

    public function test_realtime_config_endpoint(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/realtime')->assertOk()->assertExactJson(['realtime' => null]);

        $this->enableReverb();
        $this->getJson('/api/realtime')->assertOk()->assertExactJson(['realtime' => [
            'key' => 'test-key',
            'host' => 'przetargi.example.pl',
            'port' => 443,
            'scheme' => 'https',
        ]]);

        config(['chat.realtime.key' => '']);
        $this->getJson('/api/realtime')->assertOk()->assertExactJson(['realtime' => null]);

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/realtime')->assertUnauthorized();
    }

    public function test_queue_poll_is_slower_for_addon_with_websocket(): void
    {
        $this->freezeTime();
        $user = User::factory()->withRole('handlowiec')->create(['last_seen_at' => now()]);
        Sanctum::actingAs($user);

        // bez Reverba nagłówek niczego nie zmienia
        $this->withHeader('X-Realtime', '1')->getJson('/api/inquiries/queued')->assertOk()->assertHeader('X-Poll-After', '5');

        $this->enableReverb();
        $this->withHeader('X-Realtime', '1')->getJson('/api/inquiries/queued?with_offers=1')->assertOk()->assertHeader('X-Poll-After', '120');
        $this->withHeader('X-Realtime', '1')->getJson('/api/inquiries/queued')->assertOk()->assertHeader('X-Poll-After', '120');
        // stare wersje dodatku (bez nagłówka) — jak dotąd
        $this->withHeaders(['X-Realtime' => ''])->getJson('/api/inquiries/queued')->assertOk()->assertHeader('X-Poll-After', '5');
        $user->forceFill(['last_seen_at' => null])->save();
        $this->withHeaders(['X-Realtime' => ''])->getJson('/api/inquiries/queued')->assertOk()->assertHeader('X-Poll-After', '30');

        // praca czeka w kolejce (np. dodatek nie podjął jej przez błąd sieci) — szybkie tempo mimo websocketu,
        // bo queue.updated przychodzi tylko przy NOWEJ pracy
        ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_channel' => 'thunderbird',
            'source_message_id' => 'czeka@poczta.example',
            'source_body' => 'Proszę o wycenę.',
            'analysis' => [],
            'answers' => [],
            'reply_subject' => 'Oferta',
            'reply_body' => 'Dzień dobry, oferta w załączeniu.',
        ]);
        $this->withHeaders(['X-Realtime' => ''])->postJson('/api/inquiries/'.ClientInquiry::query()->latest('id')->value('id').'/queue-reply', ['queued' => true])->assertOk();
        $this->withHeader('X-Realtime', '1')->getJson('/api/inquiries/queued')->assertOk()->assertHeader('X-Poll-After', '5');
    }

    public function test_person_without_chat_permission_gets_no_message_events(): void
    {
        Event::fake([ChatMessageSent::class]);
        $anna = User::factory()->withRole('handlowiec')->create();
        $bartek = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($anna);
        $id = (int) $this->postJson('/api/chat/conversations', ['user_id' => $bartek->id])->json('data.id');

        // kierownik odbiera Bartkowi czat (rola bez uprawnienia) — wiersz uczestnika zostaje, zapowiedzi już nie
        $bartek->syncRoles([]);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->assertFalse($bartek->fresh()->can('chat'));

        $this->postJson("/api/chat/conversations/{$id}/messages", ['client_uuid' => (string) Str::uuid(), 'body' => 'Jesteś?'])->assertCreated();
        Event::assertDispatched(ChatMessageSent::class, fn (ChatMessageSent $e): bool => $this->sameIds([$anna->id], $e->userIds));
    }

    public function test_general_channel_history_before_account_creation_is_not_unread(): void
    {
        $this->freezeTime();
        $veteran = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($veteran);
        $this->postJson("/api/chat/conversations/{$this->general()->id}/messages", ['client_uuid' => (string) Str::uuid(), 'body' => 'Stara wiadomość'])
            ->assertCreated();

        // pracownik zatrudniony pół roku później nie dostaje całej historii kanału jako nieprzeczytanej
        $this->travel(180)->days();
        $newHire = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($newHire);
        $this->getJson('/api/chat/unread')->assertOk()->assertExactJson(['unread_total' => 0]);

        Sanctum::actingAs($veteran);
        $this->postJson("/api/chat/conversations/{$this->general()->id}/messages", ['client_uuid' => (string) Str::uuid(), 'body' => 'Witamy'])
            ->assertCreated();
        Sanctum::actingAs($newHire);
        $this->getJson('/api/chat/unread')->assertOk()->assertExactJson(['unread_total' => 1]);
    }

    public function test_queue_updated_is_sent_when_work_appears_for_addon(): void
    {
        Event::fake([QueueUpdated::class]);
        $user = User::factory()->withRole('handlowiec')->create();
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_channel' => 'thunderbird',
            'source_message_id' => 'kolejka@poczta.example',
            'source_body' => 'Proszę o wycenę.',
            'analysis' => [],
            'answers' => [],
            'reply_subject' => 'Oferta',
            'reply_body' => 'Dzień dobry, oferta w załączeniu.',
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/inquiries/{$inquiry->id}/queue-reply", ['queued' => true])->assertOk();
        Event::assertDispatchedTimes(QueueUpdated::class, 1);
        Event::assertDispatched(QueueUpdated::class, fn (QueueUpdated $e): bool => $e->userId === $user->id
            && $e->broadcastAs() === 'queue.updated'
            && $e->broadcastOn()[0]->name === 'private-user.'.$user->id
            && $e->broadcastWith() === []);

        // dodatek podjął prośbę i ją kasuje — to nie jest nowa praca
        $this->postJson("/api/inquiries/{$inquiry->id}/queue-reply", ['queued' => false])->assertOk();
        Event::assertDispatchedTimes(QueueUpdated::class, 1);

        $this->postJson('/api/offers/compose', ['subject' => 'Oferta', 'body_html' => '<p>Oferta</p>'])->assertCreated();
        Event::assertDispatchedTimes(QueueUpdated::class, 2);
    }

    public function test_chat_permission_migration_up_and_down(): void
    {
        $migration = require database_path('migrations/2026_10_03_100000_add_chat_permission.php');

        $migration->down();
        $this->assertNull(Permission::query()->where('name', 'chat')->first());

        $migration->up();
        $migration->up();
        $this->assertSame(1, Permission::query()->where('name', 'chat')->count());
        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            $this->assertTrue($role->hasPermissionTo('chat'), $role->name);
        }
    }

    /**
     * @param  list<int>  $expected
     * @param  list<int>  $actual
     */
    private function sameIds(array $expected, array $actual): bool
    {
        sort($expected);
        sort($actual);

        return $expected === $actual;
    }
}
