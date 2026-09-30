<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Notifications\CampaignScheduleFailedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CampaignFixtures;
use Tests\TestCase;

/** Planowanie wysyłki: walidacja przy planowaniu, blokada zmian, cofnięcie, start o czasie i powrót do projektu po błędzie. */
final class CampaignScheduleTest extends TestCase
{
    use CampaignFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(10, 0));
        $this->setUpCampaigns();
    }

    private function readyCampaign(User $author): Campaign
    {
        $list = $this->mailingList($author, ['klient@alfa.pl', 'klient@beta.pl']);

        return $this->campaign($author, [$this->erpItem('B20417')], ['audience' => ['list_ids' => [$list->id], 'xl' => ['mode' => null]]]);
    }

    public function test_schedule_validates_locks_editing_and_unschedule_returns_to_draft(): void
    {
        $author = $this->sender();
        $campaign = $this->readyCampaign($author);
        Sanctum::actingAs($author);

        $this->postJson("/api/campaigns/{$campaign->id}/schedule", ['scheduled_at' => '2026-09-30T09:00:00+02:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
        $this->postJson("/api/campaigns/{$campaign->id}/schedule", ['scheduled_at' => '2026-12-30T09:00:00+01:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');

        // 2.10 godz. 8:00 czasu polskiego = 6:00 UTC
        $this->postJson("/api/campaigns/{$campaign->id}/schedule", ['scheduled_at' => '2026-10-02T08:00:00+02:00'])
            ->assertOk()
            ->assertJsonPath('status', 'scheduled')
            ->assertJsonPath('scheduled_at', '2026-10-02T06:00:00+00:00')
            ->assertJsonPath('can_edit', false);
        // odbiorców wyliczy dopiero start
        $this->assertSame(0, $campaign->recipients()->count());

        $this->patchJson("/api/campaigns/{$campaign->id}", ['subject' => 'Inny temat'])
            ->assertUnprocessable()->assertJsonPath('message', 'Kampania jest zaplanowana — cofnij planowanie, żeby ją zmienić');
        $this->postJson("/api/campaigns/{$campaign->id}/send")->assertUnprocessable();
        $this->getJson('/api/campaigns?status=scheduled')->assertOk()->assertJsonPath('data.0.scheduled_at', '2026-10-02T06:00:00+00:00');

        $this->postJson("/api/campaigns/{$campaign->id}/unschedule")
            ->assertOk()->assertJsonPath('status', 'draft')->assertJsonPath('scheduled_at', null);
        $this->postJson("/api/campaigns/{$campaign->id}/unschedule")->assertUnprocessable();
    }

    public function test_schedule_requires_author_mailbox_and_recipients(): void
    {
        $author = $this->sender();
        $campaign = $this->readyCampaign($author);
        $in = ['scheduled_at' => '2026-10-02T08:00:00+02:00'];

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->postJson("/api/campaigns/{$campaign->id}/schedule", $in)->assertForbidden();

        Sanctum::actingAs($author);
        $noAudience = $this->campaign($author, [$this->erpItem('A1')]);
        $this->postJson("/api/campaigns/{$noAudience->id}/schedule", $in)
            ->assertUnprocessable()->assertJsonPath('message', 'Kampania nie ma odbiorców — wybierz grupę albo klientów z ERP XL.');

        UserMailAccount::query()->where('user_id', $author->id)->delete();
        $this->postJson("/api/campaigns/{$campaign->id}/schedule", $in)
            ->assertUnprocessable()->assertJsonPath('message', 'Nie ustawiono skrzynki w „Moje konto → Moja poczta”.');
        $this->assertSame('draft', $campaign->fresh()->status);
    }

    public function test_schedule_refuses_incomplete_blocks(): void
    {
        $author = $this->sender();
        $campaign = $this->readyCampaign($author);
        $campaign->update(['blocks' => [['type' => 'image', 'asset' => null, 'alt' => '', 'url' => ''], ['type' => 'products', 'layout' => 'grid3']]]);
        Sanctum::actingAs($author);

        $this->postJson("/api/campaigns/{$campaign->id}/schedule", ['scheduled_at' => '2026-10-02T08:00:00+02:00'])
            ->assertUnprocessable()->assertJsonPath('message', 'Grafika (element nr 1): wgraj obrazek albo usuń ten element.');
        $this->assertSame('draft', $campaign->fresh()->status);
    }

    public function test_dispatch_starts_due_campaign_and_sends_first_batch(): void
    {
        $author = $this->sender();
        $campaign = $this->readyCampaign($author);
        Sanctum::actingAs($author);
        $this->postJson("/api/campaigns/{$campaign->id}/schedule", ['scheduled_at' => '2026-09-30T13:00:00+02:00'])->assertOk();

        // przed godziną nic
        $this->travelTo(now()->setTime(10, 59));
        Artisan::call('campaigns:dispatch');
        $this->assertSame('scheduled', $campaign->fresh()->status);
        $this->assertSame([], $this->mailers->recipients());

        $this->travelTo(now()->setTime(11, 0, 30));
        Artisan::call('campaigns:dispatch');
        $fresh = $campaign->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertNotNull($fresh->sending_started_at);
        $this->assertSame(2, $fresh->recipients()->where('status', 'sent')->count());
        $this->assertEqualsCanonicalizing(['klient@alfa.pl', 'klient@beta.pl'], $this->mailers->recipients());
        $this->assertNotNull($fresh->items()->first()->snap_stock_at);
    }

    public function test_failed_start_returns_to_draft_with_reason_and_notifies_author(): void
    {
        Notification::fake();
        $author = $this->sender();
        $campaign = $this->readyCampaign($author);
        Sanctum::actingAs($author);
        $this->postJson("/api/campaigns/{$campaign->id}/schedule", ['scheduled_at' => '2026-09-30T13:00:00+02:00'])->assertOk();

        // między planowaniem a godziną startu handlowiec usunął skrzynkę
        UserMailAccount::query()->where('user_id', $author->id)->delete();
        $this->travelTo(now()->setTime(11, 1));
        Artisan::call('campaigns:dispatch');

        $fresh = $campaign->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertSame('Nie ustawiono skrzynki w „Moje konto → Moja poczta”.', $fresh->schedule_error);
        Notification::assertSentTo($author, CampaignScheduleFailedNotification::class,
            fn (CampaignScheduleFailedNotification $n): bool => $n->campaign->is($campaign) && str_contains($n->toArray($author)['message'], $campaign->code));
        $this->getJson("/api/campaigns/{$campaign->id}")->assertOk()
            ->assertJsonPath('warnings.0', 'Zaplanowana wysyłka nie wystartowała: Nie ustawiono skrzynki w „Moje konto → Moja poczta”.');

        // kolejny przebieg nie powtarza powiadomienia
        Artisan::call('campaigns:dispatch');
        Notification::assertSentToTimes($author, CampaignScheduleFailedNotification::class, 1);
    }
}
