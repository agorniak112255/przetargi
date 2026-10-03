<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\AppNotificationMail;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * crm:remind — przypomnienie z notatki o kliencie: tylko do autora, w dniu remind_on od 7:00 czasu polskiego, raz;
 * nowy dzień przypomnienia = przypomnienie znowu; nieudany e-mail nie zamyka przypomnienia.
 */
final class ClientNoteReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $colleague;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        $this->author = User::factory()->withRole('handlowiec')->create(['name' => 'Piotr Wiśniewski', 'email' => 'piotr@supon.example.pl']);
        $this->colleague = User::factory()->withRole('handlowiec')->create(['email' => 'ewa@supon.example.pl']);
        $this->client = Client::query()->create(['name' => 'Ciepłownia Wisłok S.A.']);
    }

    public function test_reminds_the_author_once_from_seven_oclock_on_the_chosen_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'Europe/Warsaw'));
        $note = $this->note('2026-10-05', 'Rozmowa z kierowniczką zaopatrzenia: w listopadzie przetarg na odzież zimową.');
        // notatka bez przypomnienia i notatka kolegi na inny dzień
        $this->note(null, 'Bez przypomnienia');
        ClientNote::query()->create(['client_id' => $this->client->id, 'user_id' => $this->colleague->id, 'body' => 'Inna', 'remind_on' => '2026-10-06']);

        $this->runAt('2026-10-04 12:00');
        $this->runAt('2026-10-05 06:50');
        $this->assertSame(0, $this->author->notifications()->count());

        $this->runAt('2026-10-05 07:05');
        $this->runAt('2026-10-05 15:30');
        $this->assertSame(1, $this->author->notifications()->count());
        $this->assertSame(0, $this->colleague->notifications()->count());
        $data = $this->author->notifications()->firstOrFail()->data;
        $this->assertSame('client_note_reminder', $data['type']);
        $this->assertSame('Przypomnienie: Ciepłownia Wisłok S.A.', $data['title']);
        $this->assertStringContainsString('w listopadzie przetarg na odzież zimową.', $data['body']);
        $this->assertStringContainsString('Twoja notatka na karcie klienta z 1.10.2026.', $data['body']);
        $this->assertSame('/clients/'.$this->client->id, $data['url']);
        Mail::assertSent(AppNotificationMail::class, fn (AppNotificationMail $m): bool => $m->hasTo('piotr@supon.example.pl'));
        Mail::assertSentCount(1);
        $this->assertNotNull($note->fresh()->reminded_at);

        $this->runAt('2026-10-06 08:00');
        $this->assertSame(1, $this->author->notifications()->count());
        $this->assertSame(1, $this->colleague->notifications()->count());
    }

    public function test_new_reminder_day_reminds_again(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'Europe/Warsaw'));
        $note = $this->note('2026-10-05', 'Oferta na rękawice');
        $this->runAt('2026-10-05 07:05');
        $this->assertSame(1, $this->author->notifications()->count());

        // autor przesuwa przypomnienie na inny dzień — zmiana zeruje reminded_at
        Sanctum::actingAs($this->author);
        $this->patchJson("/api/clients/{$this->client->id}/notes/{$note->id}", ['remind_on' => '2026-10-07'])->assertOk();
        $this->assertNull($note->fresh()->reminded_at);

        $this->runAt('2026-10-06 09:00');
        $this->assertSame(1, $this->author->notifications()->count());
        $this->runAt('2026-10-07 07:01');
        $this->runAt('2026-10-07 07:16');
        $this->assertSame(2, $this->author->notifications()->count());
        $this->assertNotNull($note->fresh()->reminded_at);
    }

    public function test_missed_day_is_caught_up_within_a_week_only(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'Europe/Warsaw'));
        $recent = $this->note('2026-10-05', 'Serwer stał w poniedziałek');
        $old = $this->note('2026-10-02', 'Za stare');

        // pierwszy przebieg dopiero 10.10: 5.10 mieści się w tygodniu, 2.10 już nie
        $this->runAt('2026-10-10 08:00');
        $this->assertSame(1, $this->author->notifications()->count());
        $this->assertNotNull($recent->fresh()->reminded_at);
        $this->assertNull($old->fresh()->reminded_at);
    }

    public function test_mail_error_keeps_the_reminder_open_and_only_mail_is_retried(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'Europe/Warsaw'));
        $note = $this->note('2026-10-05', 'Oferta na rękawice');
        $fake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new TransportException('Connection could not be established'));

        $this->travelTo(CarbonImmutable::parse('2026-10-05 07:05', 'Europe/Warsaw'));
        $this->artisan('crm:remind')->expectsOutputToContain('nieudane e-maile: 1')->assertFailed();
        $this->assertSame(1, $this->author->notifications()->count());
        $this->assertNull($note->fresh()->reminded_at);

        Mail::swap($fake);
        $this->runAt('2026-10-05 07:25');
        Mail::assertSentCount(1);
        $this->assertSame(1, $this->author->notifications()->count());
        $this->assertNotNull($note->fresh()->reminded_at);
    }

    public function test_note_without_author_is_not_reminded(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'Europe/Warsaw'));
        ClientNote::query()->create(['client_id' => $this->client->id, 'user_id' => null, 'body' => 'Konto usunięte', 'remind_on' => '2026-10-05']);

        $this->runAt('2026-10-05 08:00');
        $this->assertSame(0, $this->author->notifications()->count() + $this->colleague->notifications()->count());
    }

    private function note(?string $remindOn, string $body): ClientNote
    {
        return ClientNote::query()->create(['client_id' => $this->client->id, 'user_id' => $this->author->id, 'body' => $body, 'remind_on' => $remindOn]);
    }

    private function runAt(string $polishTime): void
    {
        $this->travelTo(CarbonImmutable::parse($polishTime, 'Europe/Warsaw'));
        $this->artisan('crm:remind')->assertSuccessful();
    }
}
