<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\TenderInvitationMail;
use App\Models\Client;
use App\Models\Tender;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/** Zaproszenie do przetargu przez wspólną obsługę powiadomień: dzwonek, e-mail z godziną terminu, preferencje. */
final class TenderInvitationNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
    }

    public function test_bell_has_title_and_link_and_mail_has_deadline_time(): void
    {
        $manager = User::factory()->withRole('kierownik')->create(['name' => 'Marek Zieliński']);
        $invitee = User::factory()->withRole('handlowiec')->create(['email' => 'ewa@supon.example.pl']);
        $tender = $this->tender($manager);
        Sanctum::actingAs($manager);

        $this->postJson("/api/tenders/{$tender->id}/invitations", ['user_id' => $invitee->id, 'note' => 'Pomóż z obuwiem'])
            ->assertCreated()
            ->assertJsonPath('email_sent', true)
            ->assertJsonPath('email_status', 'sent');

        $data = $invitee->notifications()->firstOrFail()->data;
        $this->assertSame('tender_invitation', $data['type']);
        $this->assertSame('Marek Zieliński zaprasza Cię do przetargu '.$tender->number, $data['title']);
        $this->assertSame($data['title'], $data['message']);
        $this->assertSame('/tenders/'.$tender->id, $data['url']);
        $this->assertSame($tender->id, $data['tender_id']);
        $this->assertSame('Marek Zieliński', $data['inviter_name']);
        $this->assertStringContainsString('Termin składania: 5.10.2026, 10:00', $data['body']);

        Mail::assertSent(TenderInvitationMail::class, function (TenderInvitationMail $mail): bool {
            $mail->assertSeeInText('Termin składania: 5.10.2026, 10:00');

            return $mail->hasTo('ewa@supon.example.pl');
        });
        $this->assertNotNull($invitee->tenderInvitations()->firstOrFail()->email_sent_at);
    }

    public function test_invitee_without_mail_gets_only_bell(): void
    {
        $manager = User::factory()->withRole('kierownik')->create();
        $invitee = User::factory()->withRole('handlowiec')->create();
        app(NotificationPreferences::class)->update($invitee, ['events' => ['tender_invitation' => ['mail' => false]]]);
        $tender = $this->tender($manager);
        Sanctum::actingAs($manager);

        $this->postJson("/api/tenders/{$tender->id}/invitations", ['user_id' => $invitee->id])
            ->assertCreated()
            ->assertJsonPath('email_sent', false)
            ->assertJsonPath('email_status', 'opted_out');

        Mail::assertNothingSent();
        $this->assertSame(1, $invitee->notifications()->count());
        $this->assertNull($invitee->tenderInvitations()->firstOrFail()->email_sent_at);
    }

    public function test_mail_error_is_reported_as_failed_not_as_opted_out(): void
    {
        $manager = User::factory()->withRole('kierownik')->create();
        $invitee = User::factory()->withRole('handlowiec')->create();
        $tender = $this->tender($manager);
        Mail::shouldReceive('to')->once()->andThrow(new TransportException('Connection refused'));
        Sanctum::actingAs($manager);

        $this->postJson("/api/tenders/{$tender->id}/invitations", ['user_id' => $invitee->id])
            ->assertCreated()
            ->assertJsonPath('email_sent', false)
            ->assertJsonPath('email_status', 'failed');

        $this->assertSame(1, $invitee->notifications()->count());
    }

    private function tender(User $owner): Tender
    {
        $client = Client::query()->create(['name' => 'Klient testowy']);

        return Tender::query()->create([
            'number' => 'PRZ/INV/'.uniqid(),
            'title' => 'Zaproszenia test',
            'client_id' => $client->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
            'deadline' => '2026-10-05',
            'deadline_time' => '10:00',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
    }
}
