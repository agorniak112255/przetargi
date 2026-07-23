<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\TenderInvitationMail;
use App\Models\Client;
use App\Models\Tender;
use App\Models\TenderInvitation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenderInvitationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
    }

    public function test_kierownik_can_invite_user_and_invitee_gains_access(): void
    {
        $kierownik = User::factory()->withRole('kierownik')->create();
        $invitee = User::factory()->withRole('handlowiec')->create();
        $tender = $this->makeTender($kierownik);

        Sanctum::actingAs($kierownik);

        $this->postJson("/api/tenders/{$tender->id}/invitations", [
            'user_id' => $invitee->id,
            'note' => 'Potrzebna wycena pozycji medycznych',
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.id', $invitee->id)
            ->assertJsonPath('email_sent', true);

        Mail::assertSent(TenderInvitationMail::class, function (TenderInvitationMail $mail) use ($invitee): bool {
            return $mail->hasTo($invitee->email);
        });

        $this->assertDatabaseHas('tender_invitations', [
            'tender_id' => $tender->id,
            'user_id' => $invitee->id,
            'invited_by' => $kierownik->id,
        ]);

        $this->assertSame(1, $invitee->fresh()->unreadNotifications()->count());

        Sanctum::actingAs($invitee);

        $this->getJson("/api/tenders/{$tender->id}")
            ->assertOk()
            ->assertJsonPath('tender.id', $tender->id);

        $this->getJson('/api/tenders?filter=invited')
            ->assertOk()
            ->assertJsonFragment(['id' => $tender->id]);
    }

    public function test_handlowiec_cannot_invite(): void
    {
        $handlowiec = User::factory()->withRole('handlowiec')->create();
        $invitee = User::factory()->withRole('handlowiec')->create();
        $tender = $this->makeTender($handlowiec);

        Sanctum::actingAs($handlowiec);

        $this->postJson("/api/tenders/{$tender->id}/invitations", [
            'user_id' => $invitee->id,
        ])->assertForbidden();
    }

    public function test_stranger_without_invite_cannot_view_tender(): void
    {
        $owner = User::factory()->withRole('handlowiec')->create();
        $stranger = User::factory()->withRole('handlowiec')->create();
        $tender = $this->makeTender($owner);

        Sanctum::actingAs($stranger);

        $this->getJson("/api/tenders/{$tender->id}")->assertForbidden();
        $this->getJson('/api/tenders')->assertOk()->assertJsonMissing(['id' => $tender->id]);
    }

    public function test_role_without_view_all_does_not_see_foreign_tenders(): void
    {
        $handlowiec = User::factory()->withRole('handlowiec')->create();
        $owner = User::factory()->withRole('handlowiec')->create();
        $tender = $this->makeTender($owner);

        Sanctum::actingAs($handlowiec);

        $this->getJson('/api/tenders')
            ->assertOk()
            ->assertJsonMissing(['id' => $tender->id]);

        $this->getJson("/api/tenders/{$tender->id}")->assertForbidden();
    }

    public function test_view_all_sees_foreign_tenders(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $owner = User::factory()->withRole('handlowiec')->create();
        $tender = $this->makeTender($owner);

        Sanctum::actingAs($admin);

        $this->getJson('/api/tenders')
            ->assertOk()
            ->assertJsonFragment(['id' => $tender->id]);

        $this->getJson("/api/tenders/{$tender->id}")->assertOk();
    }

    public function test_can_remove_invitation(): void
    {
        $dyrektor = User::factory()->withRole('dyrektor')->create();
        $invitee = User::factory()->withRole('handlowiec')->create();
        $tender = $this->makeTender($dyrektor);

        $invitation = TenderInvitation::query()->create([
            'tender_id' => $tender->id,
            'user_id' => $invitee->id,
            'invited_by' => $dyrektor->id,
            'note' => null,
            'email_sent_at' => now(),
        ]);

        Sanctum::actingAs($dyrektor);

        $this->deleteJson("/api/tenders/{$tender->id}/invitations/{$invitation->id}")
            ->assertOk();

        $this->assertDatabaseMissing('tender_invitations', ['id' => $invitation->id]);

        Sanctum::actingAs($invitee);
        $this->getJson("/api/tenders/{$tender->id}")->assertForbidden();
    }

    private function makeTender(User $owner): Tender
    {
        $client = Client::query()->create(['name' => 'Klient testowy']);

        return Tender::query()->create([
            'number' => 'PRZ/INV/'.uniqid(),
            'title' => 'Zaproszenia test',
            'client_id' => $client->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
    }
}
