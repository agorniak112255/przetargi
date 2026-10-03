<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\AppNotificationMail;
use App\Models\Client;
use App\Models\Tender;
use App\Models\TenderComment;
use App\Models\TenderInvitation;
use App\Models\TenderItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Wzmianki „@” w komentarzu przetargu: kandydaci z dostępem, zapis odbiorców, powiadomienie. */
final class TenderCommentMentionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $invitee;

    private User $manager;

    private User $stranger;

    private Tender $tender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutDefer();
        Mail::fake();

        $this->owner = User::factory()->withRole('handlowiec')->create(['name' => 'Jan Opiekun']);
        $this->invitee = User::factory()->withRole('handlowiec')->create(['name' => 'Ewa Zaproszona', 'email' => 'ewa@supon.example.pl']);
        $this->manager = User::factory()->withRole('kierownik')->create(['name' => 'Adam Kierownik']);
        $this->stranger = User::factory()->withRole('handlowiec')->create(['name' => 'Obcy Handlowiec']);

        $client = Client::query()->create(['name' => 'Szpital Wojewódzki nr 2']);
        $this->tender = Tender::query()->create([
            'number' => 'PRZ/2026/0042',
            'title' => 'Rękawice',
            'client_id' => $client->id,
            'owner_id' => $this->owner->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        TenderInvitation::query()->create([
            'tender_id' => $this->tender->id,
            'user_id' => $this->invitee->id,
            'invited_by' => $this->manager->id,
        ]);
    }

    public function test_candidates_are_people_with_access_without_the_asking_person(): void
    {
        Sanctum::actingAs($this->owner);

        $rows = collect($this->getJson("/api/tenders/{$this->tender->id}/mention-candidates")->assertOk()->json('data'))->keyBy('id');

        $this->assertFalse($rows->has($this->owner->id));
        $this->assertFalse($rows->has($this->stranger->id));
        $this->assertSame('Zaproszony do przetargu', $rows[$this->invitee->id]['role']);
        $this->assertSame('Widzi wszystkie przetargi', $rows[$this->manager->id]['role']);
        $this->assertSame('Ewa Zaproszona', $rows[$this->invitee->id]['name']);

        Sanctum::actingAs($this->invitee);
        $first = $this->getJson("/api/tenders/{$this->tender->id}/mention-candidates")->assertOk()->json('data.0');
        $this->assertSame(['id' => $this->owner->id, 'name' => 'Jan Opiekun', 'role' => 'Opiekun przetargu'], $first);

        Sanctum::actingAs($this->stranger);
        $this->getJson("/api/tenders/{$this->tender->id}/mention-candidates")->assertForbidden();
    }

    public function test_comment_with_mentions_is_saved_and_notifies_mentioned_people(): void
    {
        $item = TenderItem::query()->forceCreate(['tender_id' => $this->tender->id, 'line_no' => 12, 'requirement' => 'Obuwie S3']);
        Sanctum::actingAs($this->owner);

        $res = $this->postJson("/api/tenders/{$this->tender->id}/comments", [
            'body' => '@Ewa Zaproszona sprawdź, czy UVEX ma podnosek',
            'tender_item_id' => $item->id,
            'mentioned_user_ids' => [$this->invitee->id, $this->manager->id],
        ])->assertCreated()
            ->assertJsonPath('body', '@Ewa Zaproszona sprawdź, czy UVEX ma podnosek')
            ->assertJsonPath('user.name', 'Jan Opiekun')
            ->assertJsonPath('mentioned_users', [
                ['id' => $this->invitee->id, 'name' => 'Ewa Zaproszona'],
                ['id' => $this->manager->id, 'name' => 'Adam Kierownik'],
            ]);
        $this->assertSame([$this->invitee->id, $this->manager->id], TenderComment::query()->findOrFail($res->json('id'))->mentioned_user_ids);

        $data = $this->invitee->notifications()->firstOrFail()->data;
        $this->assertSame('tender_mention', $data['type']);
        $this->assertSame('Jan Opiekun wspomina o Tobie w komentarzu', $data['title']);
        $this->assertSame('PRZ/2026/0042 · Rękawice, pozycja 12: „@Ewa Zaproszona sprawdź, czy UVEX ma podnosek”', $data['body']);
        $this->assertSame("/tenders/{$this->tender->id}?tab=komentarze", $data['url']);
        $this->assertSame(1, $this->manager->notifications()->count());
        $this->assertSame(0, $this->owner->notifications()->count());
        Mail::assertSent(AppNotificationMail::class, fn (AppNotificationMail $m): bool => $m->hasTo('ewa@supon.example.pl'));

        $this->getJson("/api/tenders/{$this->tender->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.mentioned_users.0.name', 'Ewa Zaproszona');
    }

    public function test_comment_without_mentions_works_as_before(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/tenders/{$this->tender->id}/comments", ['body' => 'Zwykły komentarz'])
            ->assertCreated()
            ->assertJsonPath('mentioned_users', [])
            ->assertJsonPath('mentioned_user_ids', null);

        $this->assertSame(0, $this->invitee->notifications()->count());
        Mail::assertNothingSent();
    }

    public function test_mentions_are_validated(): void
    {
        Sanctum::actingAs($this->owner);
        $url = "/api/tenders/{$this->tender->id}/comments";

        $this->postJson($url, ['body' => 'Obcy', 'mentioned_user_ids' => [$this->stranger->id]])
            ->assertStatus(422)->assertJsonValidationErrors('mentioned_user_ids');
        $this->postJson($url, ['body' => 'Ja', 'mentioned_user_ids' => [$this->owner->id]])
            ->assertStatus(422)->assertJsonValidationErrors('mentioned_user_ids');
        $this->postJson($url, ['body' => 'Nie ma', 'mentioned_user_ids' => [999999]])
            ->assertStatus(422)->assertJsonValidationErrors('mentioned_user_ids');
        $this->postJson($url, ['body' => 'Za dużo', 'mentioned_user_ids' => range(1, 21)])
            ->assertStatus(422)->assertJsonValidationErrors('mentioned_user_ids');
        $this->postJson($url, ['body' => 'Dwa razy', 'mentioned_user_ids' => [$this->invitee->id, $this->invitee->id]])
            ->assertStatus(422);

        $this->assertDatabaseCount('tender_comments', 0);
        $this->assertSame(0, $this->invitee->notifications()->count());
    }
}
