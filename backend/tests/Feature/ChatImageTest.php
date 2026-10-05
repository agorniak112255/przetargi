<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\Chat\ChatMessageSent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use GdImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Czat firmowy — zdjęcia: wgranie z przekodowaniem, odczyt tylko dla uczestników, usunięcie razem z plikiem. */
final class ChatImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    public function test_pasted_screenshot_becomes_image_message_with_caption(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $id = $this->direct($anna, $bartek);

        Event::fake([ChatMessageSent::class]);
        // zrzut ekranu ze schowka: PNG z kanałem alfa, szerszy niż limit
        $res = $this->sendImage($id, $this->upload('image.png', $this->image(2400, 1200, true), 'png'), '  Zobacz ten błąd  ')
            ->assertCreated()
            ->assertJsonPath('data.kind', 'image')
            ->assertJsonPath('data.body', 'Zobacz ten błąd')
            ->assertJsonPath('data.meta.image.mime', 'image/png')
            ->assertJsonPath('data.meta.image.width', 1920)
            ->assertJsonPath('data.meta.image.height', 960)
            ->assertJsonPath('data.user.id', $anna->id);
        $key = (string) $res->json('data.meta.image.key');
        $this->assertTrue(Str::isUuid($key));
        $this->assertSame(['chat-images/'.$key.'.png'], Storage::disk('local')->allFiles());
        $this->assertSame($res->json('data.meta.image.size'), strlen((string) Storage::disk('local')->get('chat-images/'.$key.'.png')));

        Event::assertDispatched(ChatMessageSent::class, function (ChatMessageSent $event): bool {
            $this->assertSame('image', $event->broadcastWith()['kind']);
            $this->assertSame('Zdjęcie: Zobacz ten błąd', $event->broadcastWith()['preview']);

            return true;
        });

        // zdjęcie bez podpisu, JPEG bez przezroczystości
        $this->sendImage($id, $this->upload('foto.jpg', $this->image(800, 600), 'jpeg'))
            ->assertCreated()
            ->assertJsonPath('data.body', null)
            ->assertJsonPath('data.meta.image.mime', 'image/jpeg')
            ->assertJsonPath('data.meta.image.width', 800);

        // rozmowa przesuwa się na górę listy, a druga osoba ma 2 nieprzeczytane
        $this->actingAsUser($bartek);
        $this->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.last_message.kind', 'image')
            ->assertJsonPath('data.0.unread', 2);
    }

    public function test_image_is_reencoded_without_metadata(): void
    {
        $anna = $this->chatUser('Anna');
        $id = $this->direct($anna, $this->chatUser('Bartek'));
        // JPEG z doklejonym komentarzem (jak metadane aparatu) — po przekodowaniu go nie ma
        $jpeg = $this->bytes($this->image(300, 200), 'jpeg');
        $tagged = substr($jpeg, 0, 2)."\xFF\xFE\x00\x14".'GPS 50.04 21.99 tajne'.substr($jpeg, 2);
        $this->assertNotFalse(@imagecreatefromstring($tagged));

        $key = (string) $this->sendImage($id, UploadedFile::fake()->createWithContent('foto.jpg', $tagged))
            ->assertCreated()
            ->json('data.meta.image.key');
        $stored = (string) Storage::disk('local')->get('chat-images/'.$key.'.jpg');
        $this->assertStringNotContainsString('GPS 50.04', $stored);
        $this->assertSame([300, 200], array_slice((array) getimagesizefromstring($stored), 0, 2));
    }

    public function test_upload_validation_and_non_images_leave_no_files(): void
    {
        $anna = $this->chatUser('Anna');
        $id = $this->direct($anna, $this->chatUser('Bartek'));

        $this->sendImage($id, null)->assertUnprocessable()->assertJsonPath('errors.image.0', 'Wybierz zdjęcie.');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $this->sendImage($id, UploadedFile::fake()->createWithContent('zrzut.svg', $svg))
            ->assertUnprocessable()
            ->assertJsonPath('errors.image.0', 'Można wysłać zdjęcie JPG, PNG, GIF albo WEBP.');
        // rozszerzenie .png, w środku skrypt — mimes przepuszcza po nazwie? GD i tak odrzuci
        $this->sendImage($id, UploadedFile::fake()->createWithContent('zrzut.png', '<?php echo 1; ?>'))
            ->assertUnprocessable();
        $this->sendImage($id, UploadedFile::fake()->create('duzy.png', 10241, 'image/png'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.image.0', 'Zdjęcie może mieć najwyżej 10 MB.');
        $this->postJson("/api/chat/conversations/{$id}/images", [
            'client_uuid' => 'nie-uuid',
            'image' => $this->upload('a.png', $this->image(10, 10), 'png'),
        ])->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
        $this->sendImage($id, $this->upload('a.png', $this->image(10, 10), 'png'), str_repeat('x', 4001))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        $this->assertSame(0, ChatMessage::query()->where('kind', 'image')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_client_uuid_replay_returns_same_message_and_keeps_one_file(): void
    {
        $anna = $this->chatUser('Anna');
        $id = $this->direct($anna, $this->chatUser('Bartek'));
        $uuid = (string) Str::uuid();

        $first = $this->sendImage($id, $this->upload('a.png', $this->image(50, 40), 'png'), null, $uuid)->assertCreated();
        // ponowienie po zerwanym połączeniu — ten sam wiersz, bez drugiego pliku
        $this->sendImage($id, $this->upload('a.png', $this->image(50, 40), 'png'), null, $uuid)
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, ChatMessage::query()->where('kind', 'image')->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());

        // ten sam identyfikator w innej rozmowie — błąd, plik nie zostaje
        $general = (int) ChatConversation::query()->where('everyone', true)->value('id');
        $this->sendImage($general, $this->upload('a.png', $this->image(50, 40), 'png'), null, $uuid)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_uuid');
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_only_participants_can_send_or_view_image(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $obcy = $this->chatUser('Celina');
        $id = $this->direct($anna, $bartek);
        $messageId = (int) $this->sendImage($id, $this->upload('a.jpg', $this->image(64, 48), 'jpeg'))->json('data.id');

        // uczestnik: obrazek z bezpiecznymi nagłówkami
        $this->actingAsUser($bartek);
        $res = $this->get("/api/chat/messages/{$messageId}/image")->assertOk();
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame("default-src 'none'", $res->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('private', (string) $res->headers->get('Cache-Control'));
        $this->assertSame([64, 48], array_slice((array) getimagesizefromstring($res->streamedContent()), 0, 2));

        // ktoś spoza rozmowy: ani odczytu, ani wysyłki („nie ma takiej rozmowy”)
        $this->actingAsUser($obcy);
        $this->getJson("/api/chat/messages/{$messageId}/image")->assertNotFound();
        $this->sendImage($id, $this->upload('b.jpg', $this->image(10, 10), 'jpeg'))->assertNotFound();
        $this->getJson('/api/chat/messages/999999/image')->assertNotFound();

        // zwykła wiadomość tekstowa nie ma zdjęcia
        $this->actingAsUser($anna);
        $textId = (int) $this->postJson("/api/chat/conversations/{$id}/messages", [
            'client_uuid' => (string) Str::uuid(),
            'body' => 'tekst',
        ])->json('data.id');
        $this->getJson("/api/chat/messages/{$textId}/image")->assertNotFound();

        // bez uprawnienia czatu i bez logowania
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/chat/messages/{$messageId}/image")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/chat/messages/{$messageId}/image")->assertUnauthorized();
    }

    public function test_deleting_image_message_removes_file(): void
    {
        $anna = $this->chatUser('Anna');
        $bartek = $this->chatUser('Bartek');
        $id = $this->direct($anna, $bartek);
        $messageId = (int) $this->sendImage($id, $this->upload('a.png', $this->image(30, 30), 'png'), 'podpis')->json('data.id');
        $this->assertCount(1, Storage::disk('local')->allFiles());

        $this->deleteJson("/api/chat/messages/{$messageId}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.meta', null)
            ->assertJsonPath('data.body', null);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->actingAsUser($bartek);
        $this->getJson("/api/chat/messages/{$messageId}/image")->assertNotFound();
    }

    public function test_history_and_search_find_images_by_caption(): void
    {
        $anna = $this->chatUser('Anna');
        $id = $this->direct($anna, $this->chatUser('Bartek'));
        $withCaption = (int) $this->sendImage($id, $this->upload('a.png', $this->image(20, 20), 'png'), 'Błąd w cenniku Ansella')->json('data.id');
        $bare = (int) $this->sendImage($id, $this->upload('b.png', $this->image(20, 20), 'png'))->json('data.id');
        $this->postJson("/api/chat/conversations/{$id}/messages", ['client_uuid' => (string) Str::uuid(), 'body' => 'zwykły tekst'])->assertCreated();

        $ids = fn (TestResponse $res): array => array_map(static fn (array $hit): int => (int) $hit['message']['id'], $res->assertOk()->json('data'));
        // filtr „Zdjęcia” w historii rozmowy — od najnowszych, także bez podpisu
        $this->assertSame([$bare, $withCaption], $ids($this->getJson("/api/chat/search?conversation_id={$id}&type=images")));
        // podpis da się znaleźć (bez wielkości liter i z polskimi literami), także we wszystkich rozmowach
        $this->assertSame([$withCaption], $ids($this->getJson('/api/chat/search?q=b%C5%82%C4%85d%20w%20CENNIKU')));
        $this->getJson("/api/chat/search?conversation_id={$id}")->assertJsonPath('data.1.message.kind', 'image');
    }

    public function test_missing_file_gives_404(): void
    {
        $anna = $this->chatUser('Anna');
        $id = $this->direct($anna, $this->chatUser('Bartek'));
        $res = $this->sendImage($id, $this->upload('a.png', $this->image(30, 30, true), 'png'))->assertJsonPath('data.meta.image.mime', 'image/png');
        Storage::disk('local')->delete('chat-images/'.$res->json('data.meta.image.key').'.png');
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->getJson('/api/chat/messages/'.$res->json('data.id').'/image')->assertNotFound();
    }

    private function chatUser(string $name): User
    {
        return User::factory()->withRole('handlowiec')->create(['name' => $name]);
    }

    private function actingAsUser(User $user): self
    {
        Sanctum::actingAs($user);

        return $this;
    }

    private function direct(User $me, User $other): int
    {
        $this->actingAsUser($me);

        return (int) $this->postJson('/api/chat/conversations', ['user_id' => $other->id])->json('data.id');
    }

    private function sendImage(int $conversationId, ?UploadedFile $file, ?string $body = null, ?string $uuid = null): TestResponse
    {
        $data = ['client_uuid' => $uuid ?? (string) Str::uuid()];
        if ($file !== null) {
            $data['image'] = $file;
        }
        if ($body !== null) {
            $data['body'] = $body;
        }

        return $this->post("/api/chat/conversations/{$conversationId}/images", $data, ['Accept' => 'application/json']);
    }

    private function image(int $width, int $height, bool $alpha = false): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        if ($alpha) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 0));
        } else {
            imagefill($image, 0, 0, imagecolorallocate($image, 11, 125, 106));
        }

        return $image;
    }

    private function bytes(GdImage $image, string $format): string
    {
        ob_start();
        $format === 'png' ? imagepng($image) : imagejpeg($image);

        return (string) ob_get_clean();
    }

    private function upload(string $name, GdImage $image, string $format): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->bytes($image, $format));
    }
}
