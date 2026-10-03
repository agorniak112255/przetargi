<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\Chat\ChatMessageDeleted;
use App\Models\ActivityLog;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Czat firmowy — API (kontrakt: rozmowy, wiadomości, nieprzeczytane, linki, maile). Zdarzenia czasu rzeczywistego
 * i kolejka dodatku: ChatRealtimeTest.
 */
final class ChatApiTest extends TestCase
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

    private function chatUser(string $role = 'handlowiec', array $attributes = []): User
    {
        return User::factory()->withRole($role)->create($attributes);
    }

    private function actingAsUser(User $user): self
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /** @param  array<string, mixed>  $extra */
    private function send(int $conversationId, ?string $body, array $extra = []): TestResponse
    {
        return $this->postJson("/api/chat/conversations/{$conversationId}/messages", [
            'client_uuid' => (string) Str::uuid(),
            'body' => $body,
            ...$extra,
        ]);
    }

    private function direct(User $me, User $other): int
    {
        $this->actingAsUser($me);

        return (int) $this->postJson('/api/chat/conversations', ['user_id' => $other->id])->json('data.id');
    }

    public function test_general_channel_exists_after_migration(): void
    {
        $general = $this->general();
        $this->assertSame('channel', $general->type);
        $this->assertSame('Ogólny', $general->name);
    }

    public function test_user_without_chat_permission_gets_403(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->can('chat'));
        $this->actingAsUser($user);

        $this->getJson('/api/chat/conversations')->assertForbidden();
        $this->getJson('/api/chat/unread')->assertForbidden();
        $this->getJson('/api/chat/users')->assertForbidden();
        $this->postJson("/api/chat/conversations/{$this->general()->id}/messages", [
            'client_uuid' => (string) Str::uuid(),
            'body' => 'Cześć',
        ])->assertForbidden();
        // konfiguracja websocketu — bez uprawnienia czatu (dodatek słucha nią kolejki)
        $this->getJson('/api/realtime')->assertOk()->assertExactJson(['realtime' => null]);
    }

    public function test_every_role_has_chat_permission(): void
    {
        foreach (['handlowiec', 'przetargi', 'kierownik', 'dyrektor', 'admin'] as $role) {
            $this->assertTrue($this->chatUser($role)->can('chat'), $role);
        }
    }

    public function test_new_user_sees_general_channel_and_cannot_leave_or_add_people(): void
    {
        $this->freezeTime();
        $first = $this->chatUser();
        // konto sprzed wiadomości — osoba po prostu jeszcze nie otworzyła czatu
        $newcomer = $this->chatUser('dyrektor');
        $this->travel(1)->minutes();
        $this->actingAsUser($first);
        $this->send($this->general()->id, 'Dzień dobry wszystkim')->assertCreated();

        $this->actingAsUser($newcomer);
        $response = $this->getJson('/api/chat/conversations')->assertOk();
        // wiadomość wysłana, zanim osoba pierwszy raz otworzyła czat, jest dla niej nieprzeczytana
        $response->assertJsonPath('data.0.id', $this->general()->id)
            ->assertJsonPath('data.0.everyone', true)
            ->assertJsonPath('data.0.name', 'Ogólny')
            ->assertJsonPath('data.0.unread', 1)
            ->assertJsonPath('data.0.last_message.body', 'Dzień dobry wszystkim')
            ->assertJsonPath('unread_total', 1);
        $participantIds = collect($response->json('data.0.participants'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$first->id, $newcomer->id], $participantIds);

        $this->postJson("/api/chat/conversations/{$this->general()->id}/leave")->assertStatus(422);
        $this->postJson("/api/chat/conversations/{$this->general()->id}/participants", ['user_ids' => [$first->id]])
            ->assertStatus(422);
        $this->assertTrue(ChatParticipant::query()->where('conversation_id', $this->general()->id)->where('user_id', $newcomer->id)->exists());
    }

    public function test_direct_conversation_is_unique_and_returned_again(): void
    {
        $anna = $this->chatUser(attributes: ['name' => 'Anna']);
        $bartek = $this->chatUser(attributes: ['name' => 'Bartek']);

        $this->actingAsUser($anna);
        $id = $this->postJson('/api/chat/conversations', ['user_id' => $bartek->id])
            ->assertCreated()
            ->assertJsonPath('data.type', 'direct')
            ->assertJsonPath('data.name', 'Bartek')
            ->assertJsonPath('data.other_user.id', $bartek->id)
            ->assertJsonPath('data.other_user.is_me', false)
            ->assertJsonPath('data.last_message', null)
            ->assertJsonPath('data.unread', 0)
            ->json('data.id');
        $this->postJson('/api/chat/conversations', ['user_id' => $bartek->id])->assertOk()->assertJsonPath('data.id', $id);

        $this->actingAsUser($bartek);
        $this->postJson('/api/chat/conversations', ['user_id' => $anna->id])
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.name', 'Anna');

        $this->assertSame(1, ChatConversation::query()->where('type', 'direct')->count());
        $this->assertSame(min($anna->id, $bartek->id).':'.max($anna->id, $bartek->id), ChatConversation::query()->findOrFail($id)->direct_key);
    }

    public function test_direct_conversation_with_self_or_person_without_chat_is_rejected(): void
    {
        $me = $this->chatUser();
        $noChat = User::factory()->create();
        $this->actingAsUser($me);

        $this->postJson('/api/chat/conversations', ['user_id' => $me->id])->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->postJson('/api/chat/conversations', ['user_id' => $noChat->id])->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->postJson('/api/chat/conversations', ['user_id' => 999999])->assertStatus(422);
        $this->assertSame(0, ChatConversation::query()->where('type', 'direct')->count());
    }

    public function test_only_participants_can_see_or_write_in_conversation(): void
    {
        $anna = $this->chatUser();
        $bartek = $this->chatUser();
        $outsider = $this->chatUser();
        $id = $this->direct($anna, $bartek);
        $this->actingAsUser($anna);
        $messageId = (int) $this->send($id, 'Tylko dla Bartka')->assertCreated()->json('data.id');

        $this->actingAsUser($outsider);
        $this->getJson("/api/chat/conversations/{$id}")->assertNotFound();
        $this->getJson("/api/chat/conversations/{$id}/messages")->assertNotFound();
        $this->send($id, 'Wtrącam się')->assertNotFound();
        $this->postJson("/api/chat/conversations/{$id}/read", ['message_id' => $messageId])->assertNotFound();
        $this->deleteJson("/api/chat/messages/{$messageId}")->assertNotFound();
        $this->getJson('/api/chat/conversations/999999')->assertNotFound();
        $this->assertNotContains($id, collect($this->getJson('/api/chat/conversations')->json('data'))->pluck('id')->all());

        $this->actingAsUser($bartek);
        $this->getJson("/api/chat/conversations/{$id}/messages")->assertOk()->assertJsonPath('data.0.body', 'Tylko dla Bartka');
    }

    public function test_channel_create_add_people_and_leave(): void
    {
        $owner = $this->chatUser();
        $anna = $this->chatUser();
        $bartek = $this->chatUser();
        $noChat = User::factory()->create();
        $this->actingAsUser($owner);

        $this->postJson('/api/chat/conversations', ['name' => 'Przetarg szpital', 'user_ids' => [$noChat->id]])->assertStatus(422);
        $this->postJson('/api/chat/conversations', ['name' => '', 'user_ids' => [$anna->id]])->assertStatus(422);
        $this->postJson('/api/chat/conversations', ['name' => str_repeat('a', 101), 'user_ids' => [$anna->id]])->assertStatus(422);
        $this->postJson('/api/chat/conversations', ['name' => 'Pusty', 'user_ids' => []])->assertStatus(422);

        $response = $this->postJson('/api/chat/conversations', ['name' => 'Przetarg szpital', 'user_ids' => [$anna->id]])
            ->assertCreated()
            ->assertJsonPath('data.type', 'channel')
            ->assertJsonPath('data.everyone', false)
            ->assertJsonPath('data.name', 'Przetarg szpital')
            ->assertJsonPath('data.other_user', null);
        $id = (int) $response->json('data.id');
        $this->assertEqualsCanonicalizing([$owner->id, $anna->id], collect($response->json('data.participants'))->pluck('id')->all());

        $this->send($id, 'Zanim dołączy Bartek')->assertCreated();
        $this->postJson("/api/chat/conversations/{$id}/participants", ['user_ids' => [$bartek->id, $anna->id]])
            ->assertOk()
            ->assertJsonCount(3, 'data.participants');
        // dodany później nie dostaje starej historii jako nieprzeczytanej; Anna (była wcześniej) ma ją nadal
        $this->actingAsUser($bartek);
        $this->getJson("/api/chat/conversations/{$id}")->assertOk()->assertJsonPath('data.unread', 0);
        $this->actingAsUser($anna);
        $this->getJson("/api/chat/conversations/{$id}")->assertOk()->assertJsonPath('data.unread', 1);
        $this->actingAsUser($owner);
        $this->postJson("/api/chat/conversations/{$id}/participants", ['user_ids' => [$noChat->id]])->assertStatus(422);

        $this->actingAsUser($anna);
        $this->postJson("/api/chat/conversations/{$id}/leave")->assertNoContent();
        $this->getJson("/api/chat/conversations/{$id}")->assertNotFound();

        $this->actingAsUser($bartek);
        $this->getJson("/api/chat/conversations/{$id}")->assertOk()->assertJsonCount(2, 'data.participants');

        // z rozmowy 1:1 nie da się wyjść ani dodać osób
        $directId = $this->direct($owner, $bartek);
        $this->postJson("/api/chat/conversations/{$directId}/leave")->assertStatus(422);
        $this->postJson("/api/chat/conversations/{$directId}/participants", ['user_ids' => [$anna->id]])->assertStatus(422);
    }

    public function test_client_uuid_makes_sending_idempotent(): void
    {
        $anna = $this->chatUser();
        $bartek = $this->chatUser();
        $id = $this->direct($anna, $bartek);
        $uuid = (string) Str::uuid();

        $this->actingAsUser($anna);
        $first = $this->postJson("/api/chat/conversations/{$id}/messages", ['client_uuid' => $uuid, 'body' => 'Raz'])
            ->assertCreated()
            ->assertJsonPath('data.client_uuid', $uuid)
            ->json('data.id');
        $this->postJson("/api/chat/conversations/{$id}/messages", ['client_uuid' => $uuid, 'body' => 'Raz'])
            ->assertOk()
            ->assertJsonPath('data.id', $first);
        // ten sam identyfikator w innej rozmowie to błąd klienta, nie powtórka
        $this->postJson("/api/chat/conversations/{$this->general()->id}/messages", ['client_uuid' => $uuid, 'body' => 'Raz'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_uuid');

        $this->assertSame(1, ChatMessage::query()->count());

        // inny nadawca może użyć tego samego identyfikatora (unikalność na osobę)
        $this->actingAsUser($bartek);
        $this->postJson("/api/chat/conversations/{$id}/messages", ['client_uuid' => $uuid, 'body' => 'Dwa'])->assertCreated();
    }

    public function test_message_validation(): void
    {
        $me = $this->chatUser();
        $this->actingAsUser($me);
        $general = $this->general()->id;

        $this->send($general, null)->assertStatus(422)->assertJsonValidationErrors('body');
        $this->send($general, "   \n  ")->assertStatus(422)->assertJsonValidationErrors('body');
        $this->send($general, str_repeat('a', 4001))->assertStatus(422)->assertJsonValidationErrors('body');
        $this->postJson("/api/chat/conversations/{$general}/messages", ['body' => 'Bez uuid'])->assertStatus(422)->assertJsonValidationErrors('client_uuid');
        $this->postJson("/api/chat/conversations/{$general}/messages", ['client_uuid' => 'nie-uuid', 'body' => 'x'])->assertStatus(422);
        $this->send($general, 'Link i mail', [
            'link' => ['type' => 'tender', 'id' => 1],
            'mail' => ['subject' => 'Temat', 'from' => 'a@b.pl'],
        ])->assertStatus(422);
        $this->send($general, null, ['link' => ['type' => 'produkt', 'id' => 1]])->assertStatus(422);
        $this->send($general, str_repeat('ą', 4000))->assertCreated()->assertJsonPath('data.kind', 'text');

        $this->assertSame(1, ChatMessage::query()->count());
    }

    public function test_link_to_inquiry_is_built_by_server_and_checks_access(): void
    {
        $author = $this->chatUser();
        $colleague = $this->chatUser();
        $manager = $this->chatUser('kierownik');
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $author->id,
            'tone' => 'formal',
            'source_subject' => 'Zapytanie o rękawice nitrylowe',
            'source_body' => 'Proszę o wycenę.',
            'analysis' => ['line_items' => [['id' => 'i1', 'name' => 'Rękawice'], ['id' => 'i2', 'name' => 'Okulary']]],
            'answers' => [],
        ]);
        // starsze zapytanie bez listy pozycji — strona pokazuje je jako jedną pozycję
        $legacy = ClientInquiry::query()->create([
            'user_id' => $author->id,
            'tone' => 'formal',
            'source_subject' => 'Kaski',
            'source_body' => 'Proszę o wycenę kasków.',
            'analysis' => ['product_queries' => ['kask ochronny']],
            'answers' => [],
        ]);
        $general = $this->general()->id;

        $this->actingAsUser($author);
        $this->send($general, 'Zerknij na poz. 2', ['link' => ['type' => 'inquiry', 'id' => $inquiry->id, 'item' => 2, 'title' => 'podrobione', 'path' => '/admin']])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'link')
            ->assertJsonPath('data.body', 'Zerknij na poz. 2')
            ->assertJsonPath('data.meta.link', [
                'type' => 'inquiry',
                'id' => $inquiry->id,
                'item' => 2,
                'title' => "Zapytanie #{$inquiry->id}: Zapytanie o rękawice nitrylowe (poz. 2)",
                'path' => "/inquiries/{$inquiry->id}",
            ]);
        $this->send($general, null, ['link' => ['type' => 'inquiry', 'id' => $inquiry->id, 'item' => 3]])->assertStatus(422);
        $this->send($general, null, ['link' => ['type' => 'inquiry', 'id' => $legacy->id, 'item' => 1]])
            ->assertCreated()
            ->assertJsonPath('data.meta.link.title', "Zapytanie #{$legacy->id}: Kaski (poz. 1)");
        $this->send($general, null, ['link' => ['type' => 'inquiry', 'id' => $legacy->id, 'item' => 2]])->assertStatus(422);
        $this->send($general, null, ['link' => ['type' => 'inquiry', 'id' => 999999]])->assertStatus(422);

        // handlowiec bez „otwieranie cudzych” nie przekaże cudzego zapytania
        $this->actingAsUser($colleague);
        $this->assertFalse($colleague->can('inquiries.view_others'));
        $this->send($general, 'Patrz', ['link' => ['type' => 'inquiry', 'id' => $inquiry->id]])->assertForbidden();

        $this->actingAsUser($manager);
        $this->send($general, null, ['link' => ['type' => 'inquiry', 'id' => $inquiry->id]])
            ->assertCreated()
            ->assertJsonPath('data.body', null)
            ->assertJsonPath('data.meta.link.item', null)
            ->assertJsonPath('data.meta.link.title', "Zapytanie #{$inquiry->id}: Zapytanie o rękawice nitrylowe");
    }

    public function test_link_to_tender_checks_access(): void
    {
        $owner = $this->chatUser();
        $colleague = $this->chatUser();
        $tender = Tender::query()->create([
            'number' => 'PRZ/CZAT/1',
            'title' => 'Dostawa rękawic dla szpitala',
            'client_id' => Client::query()->create(['name' => 'Szpital'])->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
        ]);
        $item = TenderItem::query()->create(['tender_id' => $tender->id, 'line_no' => 4, 'requirement' => 'Rękawice nitrylowe']);
        $general = $this->general()->id;

        $this->actingAsUser($owner);
        $this->send($general, 'Sprawdź cenę', ['link' => ['type' => 'tender', 'id' => $tender->id, 'item' => $item->id]])
            ->assertCreated()
            ->assertJsonPath('data.meta.link.title', 'Przetarg PRZ/CZAT/1: Dostawa rękawic dla szpitala (poz. 4)')
            ->assertJsonPath('data.meta.link.path', "/tenders/{$tender->id}")
            ->assertJsonPath('data.meta.link.item', $item->id);
        $this->send($general, null, ['link' => ['type' => 'tender', 'id' => $tender->id, 'item' => 999999]])->assertStatus(422);

        // handlowiec widzi tylko własne przetargi i te, do których go zaproszono
        $this->actingAsUser($colleague);
        $this->send($general, null, ['link' => ['type' => 'tender', 'id' => $tender->id]])->assertForbidden();
    }

    public function test_mail_message_keeps_mail_fields(): void
    {
        $anna = $this->chatUser();
        $bartek = $this->chatUser();
        $this->actingAsUser($anna);

        $response = $this->postJson("/api/chat/direct/{$bartek->id}/messages", [
            'client_uuid' => (string) Str::uuid(),
            'body' => 'Możesz to wycenić?',
            'mail' => [
                'subject' => 'Zapytanie ofertowe — obuwie',
                'from' => 'Jan Kowalski <jan@klient.pl>',
                'date' => '2026-10-03T08:15:00Z',
                'message_id' => '<abc@klient.pl>',
            ],
        ])->assertCreated();
        $conversationId = (int) $response->json('conversation_id');
        $response->assertJsonPath('data.kind', 'mail')
            ->assertJsonPath('data.conversation_id', $conversationId)
            ->assertJsonPath('data.meta', ['mail' => [
                'subject' => 'Zapytanie ofertowe — obuwie',
                'from' => 'Jan Kowalski <jan@klient.pl>',
                'date' => '2026-10-03T08:15:00Z',
                'message_id' => '<abc@klient.pl>',
                'body' => null,
            ]]);

        // drugi mail do tej samej osoby trafia do tej samej rozmowy 1:1
        $this->postJson("/api/chat/direct/{$bartek->id}/messages", [
            'client_uuid' => (string) Str::uuid(),
            'mail' => ['subject' => 'Drugi', 'from' => 'x@y.pl', 'body' => 'Treść maila'],
        ])->assertCreated()
            ->assertJsonPath('conversation_id', $conversationId)
            ->assertJsonPath('data.body', null)
            ->assertJsonPath('data.meta.mail.body', 'Treść maila');
        $this->postJson("/api/chat/direct/{$anna->id}/messages", [
            'client_uuid' => (string) Str::uuid(),
            'body' => 'Do siebie',
        ])->assertStatus(422);
    }

    public function test_deleting_own_message_clears_content_and_others_cannot_delete(): void
    {
        $anna = $this->chatUser();
        $bartek = $this->chatUser();
        $id = $this->direct($anna, $bartek);
        $this->actingAsUser($anna);
        $messageId = (int) $this->send($id, 'Do usunięcia', ['mail' => ['subject' => 'Temat', 'from' => 'a@b.pl']])->json('data.id');

        $this->actingAsUser($bartek);
        $this->deleteJson("/api/chat/messages/{$messageId}")->assertForbidden();

        Event::fake([ChatMessageDeleted::class]);
        $this->actingAsUser($anna);
        $this->deleteJson("/api/chat/messages/{$messageId}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.body', null)
            ->assertJsonPath('data.meta', null)
            ->assertJsonPath('data.kind', 'mail');
        $row = ChatMessage::query()->findOrFail($messageId);
        $this->assertNull($row->body);
        $this->assertNull($row->meta);
        $this->assertNotNull($row->deleted_at);
        // otwarta rozmowa u drugiej osoby dowiaduje się od razu (sam sygnał, bez treści)
        Event::assertDispatchedTimes(ChatMessageDeleted::class, 1);
        Event::assertDispatched(ChatMessageDeleted::class, function (ChatMessageDeleted $event) use ($anna, $bartek, $id, $messageId): bool {
            $this->assertEqualsCanonicalizing([$anna->id, $bartek->id], $event->userIds);
            $this->assertSame(['conversation_id' => $id, 'message_id' => $messageId], $event->broadcastWith());
            $this->assertSame('chat.deleted', $event->broadcastAs());

            return true;
        });
        // powtórne usunięcie nie wysyła drugiego sygnału
        $this->deleteJson("/api/chat/messages/{$messageId}")->assertOk();
        Event::assertDispatchedTimes(ChatMessageDeleted::class, 1);
        $this->deleteJson('/api/chat/messages/999999')->assertNotFound();
    }

    public function test_unread_counts_skip_own_and_deleted_messages_and_read_only_grows(): void
    {
        $anna = $this->chatUser();
        $bartek = $this->chatUser();
        $id = $this->direct($anna, $bartek);

        $this->actingAsUser($bartek);
        $m1 = (int) $this->send($id, 'Jeden')->json('data.id');
        $m2 = (int) $this->send($id, 'Dwa')->json('data.id');
        $m3 = (int) $this->send($id, 'Trzy')->json('data.id');
        $this->deleteJson("/api/chat/messages/{$m2}")->assertOk();
        $this->send($this->general()->id, 'Do wszystkich')->assertCreated();
        $this->assertSame(0, $this->getJson('/api/chat/unread')->json('unread_total'));

        $this->actingAsUser($anna);
        $this->send($id, 'Moja odpowiedź')->assertCreated();
        $this->getJson('/api/chat/unread')->assertOk()->assertExactJson(['unread_total' => 3]);
        $list = collect($this->getJson('/api/chat/conversations')->assertJsonPath('unread_total', 3)->json('data'))->keyBy('id');
        $this->assertSame(2, $list[$id]['unread']);
        $this->assertSame(1, $list[$this->general()->id]['unread']);

        $this->postJson("/api/chat/conversations/{$id}/read", ['message_id' => $m1])->assertOk()->assertExactJson(['unread_total' => 2]);
        $this->postJson("/api/chat/conversations/{$id}/read", ['message_id' => $m3])->assertOk()->assertExactJson(['unread_total' => 1]);
        // spóźnione żądanie z innego urządzenia nie cofa miejsca przeczytania
        $this->postJson("/api/chat/conversations/{$id}/read", ['message_id' => $m1])->assertOk()->assertExactJson(['unread_total' => 1]);
        $this->getJson("/api/chat/conversations/{$id}")->assertJsonPath('data.last_read_message_id', $m3)->assertJsonPath('data.unread', 0);
        // wiadomość spoza rozmowy nie przesuwa miejsca przeczytania
        $foreign = (int) ChatMessage::query()->where('conversation_id', $this->general()->id)->value('id');
        $this->postJson("/api/chat/conversations/{$id}/read", ['message_id' => $foreign])->assertStatus(422);
        $this->postJson("/api/chat/conversations/{$id}/read", [])->assertStatus(422);
    }

    public function test_conversations_are_sorted_by_last_message(): void
    {
        $anna = $this->chatUser();
        $bartek = $this->chatUser();
        $celina = $this->chatUser();
        $withBartek = $this->direct($anna, $bartek);
        $withCelina = $this->direct($anna, $celina);
        $quiet = $this->direct($anna, $this->chatUser());

        $this->actingAsUser($anna);
        $this->send($withCelina, 'Pierwsza')->assertCreated();
        $this->send($withBartek, 'Druga')->assertCreated();

        $ids = collect($this->getJson('/api/chat/conversations')->json('data'))->pluck('id')->all();
        $this->assertSame($withBartek, $ids[0]);
        $this->assertSame($withCelina, $ids[1]);
        $this->assertContains($this->general()->id, $ids);
        // rozmowy bez wiadomości na końcu
        $this->assertSame($quiet, end($ids));
    }

    public function test_messages_paginate_by_after_and_before(): void
    {
        $me = $this->chatUser();
        $this->actingAsUser($me);
        $general = $this->general()->id;
        $ids = [];
        for ($i = 1; $i <= 7; $i++) {
            $ids[] = (int) $this->send($general, "Wiadomość {$i}")->json('data.id');
        }

        $latest = $this->getJson("/api/chat/conversations/{$general}/messages?limit=3")->assertOk();
        $this->assertSame(array_slice($ids, 4, 3), collect($latest->json('data'))->pluck('id')->all());
        $latest->assertJsonPath('has_more', true);

        $older = $this->getJson("/api/chat/conversations/{$general}/messages?limit=3&before_id={$ids[4]}");
        $this->assertSame(array_slice($ids, 1, 3), collect($older->json('data'))->pluck('id')->all());
        $older->assertJsonPath('has_more', true);
        $oldest = $this->getJson("/api/chat/conversations/{$general}/messages?limit=3&before_id={$ids[1]}");
        $this->assertSame([$ids[0]], collect($oldest->json('data'))->pluck('id')->all());
        $oldest->assertJsonPath('has_more', false);

        $newer = $this->getJson("/api/chat/conversations/{$general}/messages?limit=3&after_id={$ids[1]}");
        $this->assertSame(array_slice($ids, 2, 3), collect($newer->json('data'))->pluck('id')->all());
        $newer->assertJsonPath('has_more', true);
        $newest = $this->getJson("/api/chat/conversations/{$general}/messages?limit=3&after_id={$ids[4]}");
        $this->assertSame(array_slice($ids, 5, 2), collect($newest->json('data'))->pluck('id')->all());
        $newest->assertJsonPath('has_more', false);

        $this->getJson("/api/chat/conversations/{$general}/messages")->assertJsonCount(7, 'data')->assertJsonPath('has_more', false);
        $this->getJson("/api/chat/conversations/{$general}/messages?limit=101")->assertStatus(422);
        $this->getJson("/api/chat/conversations/{$general}/messages?after_id=1&before_id=5")->assertStatus(422);

        $first = $latest->json('data.0');
        $this->assertSame(['id', 'conversation_id', 'kind', 'body', 'meta', 'user', 'deleted', 'client_uuid', 'created_at'], array_keys($first));
        $this->assertSame(['id' => $me->id, 'name' => $me->name], $first['user']);
    }

    public function test_message_content_never_reaches_activity_log(): void
    {
        $anna = $this->chatUser();
        $bartek = $this->chatUser();
        $this->actingAsUser($anna);
        $id = (int) $this->postJson('/api/chat/conversations', ['user_id' => $bartek->id])->json('data.id');
        $this->send($id, 'TAJNE-CZATU-123')->assertCreated();
        $this->postJson("/api/chat/direct/{$bartek->id}/messages", [
            'client_uuid' => (string) Str::uuid(),
            'mail' => ['subject' => 'TAJNY-TEMAT-456', 'from' => 'klient@firma.pl', 'body' => 'TAJNA-TRESC-789'],
        ])->assertCreated();
        $messageId = (int) ChatMessage::query()->max('id');
        $this->postJson('/api/chat/conversations', ['name' => 'TAJNY-KANAL', 'user_ids' => [$bartek->id]])->assertCreated();
        $this->postJson("/api/chat/conversations/{$id}/read", ['message_id' => $messageId])->assertOk();
        $this->deleteJson("/api/chat/messages/{$messageId}")->assertOk();

        $dump = ActivityLog::query()->get()->toJson();
        foreach (['TAJNE-CZATU-123', 'TAJNY-TEMAT-456', 'TAJNA-TRESC-789', 'TAJNY-KANAL'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_message_of_deleted_account_has_no_user(): void
    {
        $anna = $this->chatUser();
        $bartek = $this->chatUser();
        $id = $this->direct($anna, $bartek);
        $this->actingAsUser($bartek);
        $this->send($id, 'Odchodzę z firmy')->assertCreated();
        $bartek->delete();

        $this->actingAsUser($anna);
        $this->getJson("/api/chat/conversations/{$id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'text')
            ->assertJsonPath('data.0.user', null)
            ->assertJsonPath('data.0.body', 'Odchodzę z firmy');
        $this->getJson("/api/chat/conversations/{$id}")
            ->assertOk()
            ->assertJsonPath('data.other_user', null)
            ->assertJsonPath('data.name', 'Konto usunięte')
            ->assertJsonPath('data.unread', 1);
    }

    public function test_users_list_shows_chat_users_with_presence(): void
    {
        $this->freezeTime();
        $me = $this->chatUser(attributes: ['name' => 'Zenon']);
        $inApp = $this->chatUser(attributes: ['name' => 'Adam', 'last_seen_at' => now()->subMinutes(2)]);
        $away = $this->chatUser(attributes: ['name' => 'Beata', 'last_seen_at' => now()->subMinutes(10)]);
        $addonOnly = $this->chatUser(attributes: ['name' => 'Celina']);
        $addonOnly->createToken('thunderbird')->accessToken->forceFill(['last_used_at' => now()->subMinutes(4)])->save();
        User::factory()->create(['name' => 'Bez czatu']);

        $this->actingAsUser($me);
        $this->getJson('/api/chat/users')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $inApp->id, 'name' => 'Adam', 'online' => true, 'is_me' => false],
                ['id' => $away->id, 'name' => 'Beata', 'online' => false, 'is_me' => false],
                ['id' => $addonOnly->id, 'name' => 'Celina', 'online' => true, 'is_me' => false],
                ['id' => $me->id, 'name' => 'Zenon', 'online' => false, 'is_me' => true],
            ]]);
    }
}
