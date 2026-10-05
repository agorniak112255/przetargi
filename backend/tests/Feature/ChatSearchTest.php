<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Czat — wyszukiwanie wiadomości i historia rozmowy (GET /api/chat/search). */
final class ChatSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function chatUser(string $name): User
    {
        return User::factory()->withRole('handlowiec')->create(['name' => $name]);
    }

    private function direct(User $me, User $other): int
    {
        Sanctum::actingAs($me);

        return (int) $this->postJson('/api/chat/conversations', ['user_id' => $other->id])->json('data.id');
    }

    /** @param  array<string, mixed>  $extra */
    private function send(User $me, int $conversationId, ?string $body, array $extra = []): int
    {
        Sanctum::actingAs($me);

        return (int) $this->postJson("/api/chat/conversations/{$conversationId}/messages", [
            'client_uuid' => (string) Str::uuid(),
            'body' => $body,
            ...$extra,
        ])->assertSuccessful()->json('data.id');
    }

    /** @param  array<string, mixed>  $params */
    private function search(User $me, array $params): TestResponse
    {
        Sanctum::actingAs($me);

        return $this->getJson('/api/chat/search?'.http_build_query($params));
    }

    /** @return list<int> */
    private function ids(TestResponse $response): array
    {
        return array_map('intval', array_column(array_column($response->json('data'), 'message'), 'id'));
    }

    public function test_search_across_my_conversations_without_case_and_with_conversation_name(): void
    {
        $anna = $this->chatUser('Anna Nowak');
        $bartek = $this->chatUser('Bartek Lis');
        $cezary = $this->chatUser('Cezary Wąs');

        $withBartek = $this->direct($anna, $bartek);
        $hit = $this->send($bartek, $withBartek, 'Cennik BOLLE już jest');
        $this->send($anna, $withBartek, 'Dzięki');
        $deleted = $this->send($anna, $withBartek, 'stary cennik bolle');
        Sanctum::actingAs($anna);
        $this->deleteJson("/api/chat/messages/{$deleted}")->assertOk();

        // rozmowa, w której Anny nie ma — jej wiadomości nie wolno pokazać
        $foreign = $this->direct($bartek, $cezary);
        $this->send($cezary, $foreign, 'Cennik Bolle dla Cezarego');

        $response = $this->search($anna, ['q' => 'cennik bolle'])->assertOk();
        $this->assertSame([$hit], $this->ids($response));
        $response->assertJsonPath('has_more', false)
            ->assertJsonPath('data.0.conversation', ['id' => $withBartek, 'type' => 'direct', 'name' => 'Bartek Lis', 'everyone' => false, 'other_user_id' => $bartek->id])
            ->assertJsonPath('data.0.message.user.name', 'Bartek Lis')
            ->assertJsonPath('data.0.message.body', 'Cennik BOLLE już jest');
    }

    public function test_general_channel_is_searchable_before_first_visit(): void
    {
        $anna = $this->chatUser('Anna Nowak');
        $bartek = $this->chatUser('Bartek Lis');
        $general = ChatConversation::query()->where('everyone', true)->firstOrFail();
        $id = $this->send($anna, (int) $general->id, 'Spotkanie o 10');

        // Bartek nie otwierał jeszcze czatu — wiersz uczestnika „Ogólnego” powstaje przy wyszukiwaniu
        $response = $this->search($bartek, ['q' => 'spotkanie'])->assertOk();
        $this->assertSame([$id], $this->ids($response));
        $response->assertJsonPath('data.0.conversation.name', 'Ogólny')
            ->assertJsonPath('data.0.conversation.everyone', true)
            ->assertJsonPath('data.0.conversation.other_user_id', null);
    }

    public function test_search_needs_two_characters_without_conversation(): void
    {
        $anna = $this->chatUser('Anna Nowak');

        $this->search($anna, [])->assertUnprocessable()->assertJsonValidationErrors(['q' => 'Wpisz co najmniej 2 znaki.']);
        $this->search($anna, ['q' => ' a '])->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->search($anna, ['q' => str_repeat('x', 101)])->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->search($anna, ['q' => 'ab', 'type' => 'files'])->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_foreign_conversation_history_is_404_and_without_permission_403(): void
    {
        $anna = $this->chatUser('Anna Nowak');
        $bartek = $this->chatUser('Bartek Lis');
        $cezary = $this->chatUser('Cezary Wąs');
        $foreign = $this->direct($bartek, $cezary);

        $this->search($anna, ['conversation_id' => $foreign])->assertNotFound();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/chat/search?q=cennik')->assertForbidden();
    }

    public function test_conversation_history_filters_links_mails_and_calls(): void
    {
        $anna = $this->chatUser('Anna Nowak');
        $bartek = $this->chatUser('Bartek Lis');
        $conversation = $this->direct($anna, $bartek);

        $plain = $this->send($anna, $conversation, 'Zwykła wiadomość');
        $url = $this->send($bartek, $conversation, 'tu masz https://przetargi.supon.rzeszow.pl/products/23003 sprawdź');
        $mail = $this->send($anna, $conversation, 'Wycenisz?', ['mail' => [
            'subject' => 'Zapytanie ofertowe — rękawice',
            'from' => 'Jan Kowalski <jan@klient.pl>',
            'body' => 'Prosimy o ofertę na rękawice nitrylowe.',
        ]]);
        // karta linku do przetargu i wpis o połączeniu — wprost w bazie (ich wysyłka ma osobne testy)
        $link = ChatMessage::query()->create([
            'conversation_id' => $conversation,
            'user_id' => $bartek->id,
            'kind' => ChatMessage::KIND_LINK,
            'body' => null,
            'meta' => ['link' => ['type' => 'tender', 'id' => 7, 'item' => null, 'title' => 'Przetarg ZP/12: Obuwie robocze', 'path' => '/tenders/7']],
        ])->id;
        $call = ChatMessage::query()->create([
            'conversation_id' => $conversation,
            'user_id' => $anna->id,
            'kind' => ChatMessage::KIND_CALL,
            'meta' => ['call' => ['id' => 1, 'kind' => 'audio', 'status' => 'ended', 'duration_seconds' => 95]],
        ])->id;
        ChatMessage::query()->create([
            'conversation_id' => $conversation,
            'user_id' => null,
            'kind' => ChatMessage::KIND_SYSTEM,
            'body' => 'Wpis systemowy',
        ]);

        $all = ['conversation_id' => $conversation];
        $this->assertSame([$call, $link, $mail, $url, $plain], $this->ids($this->search($anna, $all)->assertOk()));
        $this->assertSame([$link, $url], $this->ids($this->search($anna, [...$all, 'type' => 'links'])));
        $this->assertSame([$mail], $this->ids($this->search($anna, [...$all, 'type' => 'mails'])));
        $this->assertSame([$call], $this->ids($this->search($anna, [...$all, 'type' => 'calls'])));

        // tekst w polach JSON: tytuł linku, temat, nadawca i treść maila
        $this->assertSame([$link], $this->ids($this->search($anna, [...$all, 'q' => 'OBUWIE robocze'])));
        $this->assertSame([$mail], $this->ids($this->search($anna, [...$all, 'q' => 'nitrylowe'])));
        $this->assertSame([$mail], $this->ids($this->search($anna, [...$all, 'q' => 'jan@klient'])));
        $this->assertSame([$mail], $this->ids($this->search($anna, [...$all, 'q' => 'Zapytanie ofertowe', 'type' => 'mails'])));
        $this->assertSame([], $this->ids($this->search($anna, [...$all, 'q' => 'Zapytanie ofertowe', 'type' => 'links'])));
        // jeden znak wystarczy, gdy szukamy w jednej rozmowie
        $this->assertSame([$url], $this->ids($this->search($anna, [...$all, 'q' => '3'])));
    }

    public function test_like_wildcards_are_literal(): void
    {
        $anna = $this->chatUser('Anna Nowak');
        $bartek = $this->chatUser('Bartek Lis');
        $conversation = $this->direct($anna, $bartek);

        $percent = $this->send($anna, $conversation, 'rabat 50% na kaski');
        $this->send($anna, $conversation, 'rabat 500 zł');
        $underscore = $this->send($anna, $conversation, 'kod A_B');
        $this->send($anna, $conversation, 'kod AXB');
        $bang = $this->send($anna, $conversation, 'uwaga!ważne');

        $this->assertSame([$percent], $this->ids($this->search($anna, ['q' => '50%'])));
        $this->assertSame([$underscore], $this->ids($this->search($anna, ['q' => 'a_b'])));
        $this->assertSame([$bang], $this->ids($this->search($anna, ['q' => 'a!w'])));
    }

    public function test_results_are_paged_from_newest(): void
    {
        $anna = $this->chatUser('Anna Nowak');
        $bartek = $this->chatUser('Bartek Lis');
        $conversation = $this->direct($anna, $bartek);
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $ids[] = $this->send($anna, $conversation, "oferta {$i}");
        }

        $first = $this->search($anna, ['q' => 'oferta', 'limit' => 2])->assertOk()->assertJsonPath('has_more', true);
        $this->assertSame([$ids[4], $ids[3]], $this->ids($first));

        $second = $this->search($anna, ['q' => 'oferta', 'limit' => 2, 'before_id' => $ids[3]])->assertJsonPath('has_more', true);
        $this->assertSame([$ids[2], $ids[1]], $this->ids($second));

        $last = $this->search($anna, ['q' => 'oferta', 'limit' => 2, 'before_id' => $ids[1]])->assertJsonPath('has_more', false);
        $this->assertSame([$ids[0]], $this->ids($last));
    }
}
