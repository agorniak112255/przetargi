<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientInquiry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/inquiries/lookup — z tego żyje oznaczanie maili w Thunderbirdzie.
 *
 * Dodatek pyta o paczkę Message-ID i musi dostać to samo na każdym komputerze:
 * czy z maila powstało zapytanie, kto je prowadzi i czy odpowiedź już poszła.
 *
 * Kluczy nie sprawdzamy przez assertJsonPath, bo Message-ID zawiera kropki —
 * ścieżka rozbiłaby się na kawałki. Czytamy więc całą mapę i porównujemy w PHP.
 */
final class ClientInquiryLookupApiTest extends TestCase
{
    use RefreshDatabase;

    private const MAIL = 'wspolny@poczta.example';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function inquiry(User $user, ?string $messageId, array $extra = []): ClientInquiry
    {
        return ClientInquiry::query()->create(array_merge([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_subject' => 'Zapytanie o rękawice',
            'source_message_id' => $messageId,
            'source_body' => 'Proszę o wycenę rękawic nitrylowych.',
            'analysis' => [],
            'answers' => [],
        ], $extra));
    }

    public function test_lookup_shows_who_handles_the_mail_including_other_peoples_inquiries(): void
    {
        $anna = User::factory()->withRole('handlowiec')->create(['name' => 'Anna Kowalska']);
        $piotr = User::factory()->withRole('handlowiec')->create(['name' => 'Piotr Nowak']);

        $hers = $this->inquiry($anna, self::MAIL);

        Sanctum::actingAs($piotr);
        $data = $this->postJson('/api/inquiries/lookup', ['message_ids' => [self::MAIL]])
            ->assertOk()
            ->json('data');

        $this->assertSame([self::MAIL], array_keys($data));
        $row = $data[self::MAIL][0];
        $this->assertSame($hers->id, $row['id']);
        $this->assertSame('Anna Kowalska', $row['user']['name']);
        $this->assertFalse($row['mine']);
        $this->assertNull($row['replied_at']);
        $this->assertNotNull($row['created_at']);
        // Tematu maila endpoint nie oddaje: dodatek ma ten mail u siebie,
        // a odpowiedź ma być jak najkrótsza i jak najmniej wyjawiać.
        $this->assertArrayNotHasKey('source_subject', $row);
    }

    public function test_lookup_marks_own_inquiry_and_a_sent_reply(): void
    {
        $anna = User::factory()->withRole('handlowiec')->create(['name' => 'Anna Kowalska']);
        $this->inquiry($anna, self::MAIL, ['replied_at' => now()]);

        Sanctum::actingAs($anna);
        $data = $this->postJson('/api/inquiries/lookup', ['message_ids' => [self::MAIL]])
            ->assertOk()
            ->json('data');

        $this->assertTrue($data[self::MAIL][0]['mine']);
        $this->assertNotNull($data[self::MAIL][0]['replied_at']);
    }

    public function test_lookup_ignores_angle_brackets_and_unknown_mails(): void
    {
        $anna = User::factory()->withRole('handlowiec')->create();
        $this->inquiry($anna, self::MAIL);

        Sanctum::actingAs($anna);
        // Thunderbird podaje identyfikator bez nawiasów, nagłówek maila z nawiasami.
        $data = $this->postJson('/api/inquiries/lookup', [
            'message_ids' => ['<'.self::MAIL.'>', 'nieznany@poczta.example'],
        ])->assertOk()->json('data');

        $this->assertSame([self::MAIL], array_keys($data));
    }

    public function test_lookup_returns_every_inquiry_from_the_same_mail_oldest_first(): void
    {
        $anna = User::factory()->withRole('handlowiec')->create(['name' => 'Anna Kowalska']);
        $piotr = User::factory()->withRole('handlowiec')->create(['name' => 'Piotr Nowak']);

        $first = $this->inquiry($anna, self::MAIL);
        $second = $this->inquiry($piotr, self::MAIL, ['duplicate_of_id' => $first->id]);

        Sanctum::actingAs($piotr);
        $rows = $this->postJson('/api/inquiries/lookup', ['message_ids' => [self::MAIL]])
            ->assertOk()
            ->json('data')[self::MAIL];

        $this->assertSame([$first->id, $second->id], array_column($rows, 'id'));
        $this->assertSame([false, true], array_column($rows, 'mine'));
    }

    public function test_lookup_returns_an_empty_map_when_nothing_matches(): void
    {
        $anna = User::factory()->withRole('handlowiec')->create();

        Sanctum::actingAs($anna);
        $response = $this->postJson('/api/inquiries/lookup', [
            'message_ids' => ['nieznany@poczta.example'],
        ])->assertOk();

        $this->assertSame([], $response->json('data'));
        // Pusty wynik musi zostać mapą „{}”, bo dodatek czyta go po kluczach.
        $this->assertStringContainsString('"data":{}', $response->getContent());
    }

    public function test_lookup_rejects_an_oversized_batch(): void
    {
        $anna = User::factory()->withRole('handlowiec')->create();

        Sanctum::actingAs($anna);
        $this->postJson('/api/inquiries/lookup', [
            'message_ids' => array_map(fn (int $i): string => "mail{$i}@poczta.example", range(1, 201)),
        ])->assertStatus(422)->assertJsonValidationErrors('message_ids');
    }

    public function test_lookup_keeps_my_inquiry_and_a_sent_reply_when_trimming_a_crowded_mail(): void
    {
        $me = User::factory()->withRole('handlowiec')->create(['name' => 'Ja Sam']);
        $replied = User::factory()->withRole('handlowiec')->create(['name' => 'Anna Kowalska']);

        // Sześć zapytań z jednego maila, a wynik obcinamy do pięciu: wypaść
        // może tylko coś obojętnego, nigdy własne i nigdy wysłana odpowiedź.
        $withReply = $this->inquiry($replied, self::MAIL, ['replied_at' => now()]);
        for ($i = 0; $i < 4; $i++) {
            $other = User::factory()->withRole('handlowiec')->create();
            $this->inquiry($other, self::MAIL);
        }
        $mine = $this->inquiry($me, self::MAIL);

        Sanctum::actingAs($me);
        $rows = $this->postJson('/api/inquiries/lookup', ['message_ids' => [self::MAIL]])
            ->assertOk()
            ->json('data')[self::MAIL];

        $ids = array_column($rows, 'id');
        $this->assertCount(5, $rows);
        $this->assertContains($mine->id, $ids);
        $this->assertContains($withReply->id, $ids);
    }

    public function test_message_ids_feed_lists_mails_touched_since_a_moment(): void
    {
        $anna = User::factory()->withRole('handlowiec')->create();

        $old = $this->inquiry($anna, 'stary@poczta.example');
        $old->forceFill(['updated_at' => now()->subDays(120)])->saveQuietly();
        $this->inquiry($anna, 'swiezy@poczta.example');
        // Zapytanie wklejone w przeglądarce nie ma Message-ID — nie ma czego szukać.
        $this->inquiry($anna, null, ['source_message_id' => null]);

        Sanctum::actingAs($anna);

        // Bez „since”: okno 90 dni — stare zapytanie wypada.
        $fresh = $this->getJson('/api/inquiries/message-ids')->assertOk();
        $this->assertSame(['swiezy@poczta.example'], $fresh->json('ids'));
        $this->assertFalse($fresh->json('has_more'));
        $this->assertNotNull($fresh->json('next_since'));

        // Z „since” sprzed 200 dni widać oba.
        $all = $this->getJson('/api/inquiries/message-ids?since='.urlencode(now()->subDays(200)->toIso8601String()))
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            ['stary@poczta.example', 'swiezy@poczta.example'],
            $all->json('ids'),
        );
    }

    public function test_message_ids_feed_needs_the_inquiries_permission(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $user->roles->first()?->revokePermissionTo('inquiries.use');
        $user->forgetCachedPermissions();

        Sanctum::actingAs($user);
        $this->getJson('/api/inquiries/message-ids')->assertForbidden();
    }

    public function test_lookup_needs_the_inquiries_permission(): void
    {
        // rola z odebranym uprawnieniem — tak wygląda konto okrojone w panelu
        $user = User::factory()->withRole('handlowiec')->create();
        $user->roles->first()?->revokePermissionTo('inquiries.use');
        $user->forgetCachedPermissions();

        Sanctum::actingAs($user);
        $this->postJson('/api/inquiries/lookup', ['message_ids' => [self::MAIL]])->assertForbidden();
    }
}
