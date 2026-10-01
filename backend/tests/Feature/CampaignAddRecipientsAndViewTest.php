<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\EmailSuppression;
use App\Models\MailingList;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\UserMailerFactory;
use Closure;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\CampaignFixtures;
use Tests\Support\FakeCampaignMailerFactory;
use Tests\TestCase;

/** „Dopisz odbiorców” do wysłanej kampanii i podgląd kampanii (campaigns.view) z testem tylko na własny adres. */
final class CampaignAddRecipientsAndViewTest extends TestCase
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

    public function test_add_recipients_to_sent_campaign_sends_only_new_and_keeps_first_dates(): void
    {
        [$campaign, $author, $list] = $this->sent(['a@klient.pl', 'b@klient.pl']);
        $startedAt = $campaign->sending_started_at->toIso8601String();
        $sentAt = $campaign->sent_at->toIso8601String();
        $newList = $this->mailingList($author, ['b@klient.pl', 'c@klient.pl', 'd@klient.pl'], false, 'Nowi');
        EmailSuppression::query()->create(['email' => 'd@klient.pl', 'reason' => 'unsubscribe']);

        $this->travel(2)->hours();
        Sanctum::actingAs($author);
        $this->getJson("/api/campaigns/{$campaign->id}")->assertOk()
            ->assertJsonPath('can_add_recipients', true)
            ->assertJsonPath('can_manage', true)
            ->assertJsonPath('can_edit', false);
        // w wysłanej zmienia się tylko wybór odbiorców — treść dalej zamknięta
        $this->patchJson("/api/campaigns/{$campaign->id}", ['name' => 'Inna'])->assertUnprocessable();
        $this->patchJson("/api/campaigns/{$campaign->id}", ['name' => 'Inna', 'audience' => ['list_ids' => [$newList->id]]])->assertUnprocessable();
        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => ['list_ids' => [$list->id, $newList->id]]])->assertOk();

        $summary = $this->getJson("/api/campaigns/{$campaign->id}/audience/recipients")->assertOk();
        // a i b już dostały, d wypisany — zostaje tylko c
        $this->assertSame(['c@klient.pl'], array_column($summary->json('data'), 'email'));
        $this->assertSame(2, $summary->json('summary.already'));
        $this->assertSame(1, $summary->json('summary.skipped.suppressed'));
        $this->assertSame(2, $this->getJson("/api/campaigns/{$campaign->id}/audience")->json('already'));

        $this->postJson("/api/campaigns/{$campaign->id}/recipients/add", ['recipients_checksum' => str_repeat('0', 40)])
            ->assertUnprocessable()->assertJsonValidationErrors('recipients_checksum');
        $this->postJson("/api/campaigns/{$campaign->id}/recipients/add", ['recipients_checksum' => $summary->json('checksum')])
            ->assertOk()
            ->assertJsonPath('added', 1)
            ->assertJsonPath('campaign.status', 'sending')
            ->assertJsonPath('campaign.totals.recipients', 3)
            ->assertJsonPath('campaign.sending_started_at', $startedAt);

        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $this->assertSame(['a@klient.pl', 'b@klient.pl', 'c@klient.pl'], $this->mailers->recipients());
        $fresh = $campaign->fresh();
        $this->assertSame(Campaign::STATUS_SENT, $fresh->status);
        // pierwsze daty zostają — od nich liczą się stany po 7/30 dniach i sprzedaż
        $this->assertSame($sentAt, $fresh->sent_at->toIso8601String());
        $this->assertSame($startedAt, $fresh->sending_started_at->toIso8601String());
        $this->assertSame(['recipients' => 3, 'sent' => 3, 'failed' => 0, 'skipped' => 0], $fresh->totals);
        $recipients = $this->getJson("/api/campaigns/{$campaign->id}/recipients")->assertOk()->json('data');
        $this->assertSame('c@klient.pl', $recipients[2]['email']);
        $this->assertNotNull($recipients[2]['created_at']);

        // nikt nowy → 422, nic się nie zmienia
        $again = $this->getJson("/api/campaigns/{$campaign->id}/audience/recipients")->json('checksum');
        $this->postJson("/api/campaigns/{$campaign->id}/recipients/add", ['recipients_checksum' => $again])
            ->assertUnprocessable()->assertJsonValidationErrors('campaign');
        $this->assertSame(Campaign::STATUS_SENT, $campaign->fresh()->status);
    }

    public function test_add_recipients_only_by_author_and_only_for_sent_or_sending_with_valid_offer(): void
    {
        [$campaign, $author, $list] = $this->sent(['a@klient.pl']);
        $this->mailingList($author, ['n@klient.pl'], false, 'Nowi');
        $admin = User::factory()->withRole('admin')->create();

        // administrator widzi, ale nie wysyła z cudzej skrzynki
        Sanctum::actingAs($admin);
        $this->getJson("/api/campaigns/{$campaign->id}")->assertOk()->assertJsonPath('can_add_recipients', false);
        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => ['list_ids' => [$list->id]]])->assertForbidden();
        $this->postJson("/api/campaigns/{$campaign->id}/recipients/add", ['recipients_checksum' => str_repeat('a', 40)])->assertForbidden();

        Sanctum::actingAs($author);
        $campaign->update(['valid_until' => '2026-09-29']);
        $this->getJson("/api/campaigns/{$campaign->id}")->assertJsonPath('can_add_recipients', false);
        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => ['list_ids' => [$list->id]]])->assertUnprocessable();

        $campaign->update(['valid_until' => null, 'status' => Campaign::STATUS_CANCELLED]);
        $this->postJson("/api/campaigns/{$campaign->id}/recipients/add", ['recipients_checksum' => str_repeat('a', 40)])->assertUnprocessable();

        // projekt dalej zmienia się zwykłą ścieżką (bez dopisywania)
        $draft = $this->campaign($author, [$this->erpItem('D1')]);
        $this->getJson("/api/campaigns/{$draft->id}")->assertJsonPath('can_add_recipients', false);
        $this->postJson("/api/campaigns/{$draft->id}/recipients/add", ['recipients_checksum' => str_repeat('a', 40)])->assertUnprocessable();
        $this->patchJson("/api/campaigns/{$draft->id}", ['audience' => ['list_ids' => [$list->id]]])->assertOk();
    }

    public function test_add_recipients_while_sending_joins_the_running_batch(): void
    {
        $author = $this->sender();
        $list = $this->mailingList($author, ['a@klient.pl', 'b@klient.pl']);
        $campaign = app(CampaignSender::class)->start(
            $this->campaign($author, [$this->erpItem('B1', 50)], ['audience' => ['list_ids' => [$list->id]]]),
            $author,
        );
        $more = $this->mailingList($author, ['c@klient.pl'], false, 'Nowi');
        $campaign->update(['audience' => ['list_ids' => [$list->id, $more->id]]]);
        $checksum = sha1('c@klient.pl');

        [$extended, $added] = app(CampaignSender::class)->addRecipients($campaign->fresh(), $author, $checksum);

        $this->assertSame(1, $added);
        $this->assertSame(Campaign::STATUS_SENDING, $extended->status);
        $this->assertNull($extended->sent_at);
        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $this->assertEqualsCanonicalizing(['a@klient.pl', 'b@klient.pl', 'c@klient.pl'], $this->mailers->recipients());
        $this->assertSame(Campaign::STATUS_SENT, $campaign->fresh()->status);
    }

    public function test_view_permission_sees_started_campaigns_read_only_and_tests_only_to_self(): void
    {
        [$sent, $author] = $this->sent(['a@klient.pl']);
        $draft = $this->campaign($author, [$this->erpItem('D1')]);
        $scheduled = $this->campaign($author, [$this->erpItem('S1')], ['status' => Campaign::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()]);
        $viewer = User::factory()->create(['email' => 'szef@supon.example.pl']);
        $viewer->givePermissionTo('campaigns.view');
        $own = $this->campaign($author, [], ['name' => 'nie widać']);

        Sanctum::actingAs($viewer);
        $this->assertSame([$sent->id], array_column($this->getJson('/api/campaigns?scope=all')->assertOk()->json('data'), 'id'));
        $this->getJson('/api/campaigns')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/campaigns/{$sent->id}")->assertOk()
            ->assertJsonPath('can_manage', false)
            ->assertJsonPath('can_edit', false)
            ->assertJsonPath('can_delete', false)
            ->assertJsonPath('can_add_recipients', false);
        $this->getJson("/api/campaigns/{$sent->id}/preview")->assertOk();
        $this->getJson("/api/campaigns/{$sent->id}/recipients")->assertOk()->assertJsonPath('data.0.email', 'a@klient.pl');
        foreach ([$draft, $scheduled, $own] as $hidden) {
            $this->getJson("/api/campaigns/{$hidden->id}")->assertNotFound();
            $this->postJson("/api/campaigns/{$hidden->id}/test")->assertNotFound();
        }

        // bez campaigns.use: zmiany i czynności niedostępne
        $this->postJson('/api/campaigns', [])->assertForbidden();
        $this->patchJson("/api/campaigns/{$sent->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/campaigns/{$sent->id}")->assertForbidden();
        $this->postJson("/api/campaigns/{$sent->id}/duplicate")->assertForbidden();
        $this->getJson("/api/campaigns/{$sent->id}/audience")->assertForbidden();

        // test: tylko na własny adres, z skrzynki autora
        $this->postJson("/api/campaigns/{$sent->id}/test", ['email' => 'obcy@example.com'])->assertForbidden();
        $this->postJson("/api/campaigns/{$sent->id}/test", ['email' => 'SZEF@supon.example.pl'])->assertOk();
        $this->postJson("/api/campaigns/{$sent->id}/test")->assertOk()
            ->assertJsonPath('message', 'Wysłano wiadomość testową na szef@supon.example.pl.');
        $this->assertSame(['a@klient.pl', 'SZEF@supon.example.pl', 'szef@supon.example.pl'], $this->mailers->recipients());
    }

    public function test_view_with_use_cannot_act_on_others_campaigns(): void
    {
        [$sent, $author] = $this->sent(['a@klient.pl']);
        $sending = app(CampaignSender::class)->start(
            $this->campaign($author, [$this->erpItem('B9')], ['audience' => ['list_ids' => [$this->mailingList($author, ['inny@klient.pl'], false, 'Druga')->id]]]),
            $author,
        );
        $colleague = User::factory()->withRole('handlowiec')->create();
        $colleague->givePermissionTo(['campaigns.view', 'campaigns.delete']);

        Sanctum::actingAs($colleague);
        $this->getJson("/api/campaigns/{$sent->id}")->assertOk()->assertJsonPath('can_delete', false);
        $this->deleteJson("/api/campaigns/{$sent->id}")->assertNotFound();
        $this->postJson("/api/campaigns/{$sent->id}/duplicate")->assertNotFound();
        $this->postJson("/api/campaigns/{$sending->id}/cancel")->assertNotFound();
        $this->postJson("/api/campaigns/{$sent->id}/replies/check")->assertNotFound();
        $this->getJson("/api/campaigns/{$sent->id}/audience/recipients")->assertNotFound();
        $this->getJson("/api/campaigns/{$sent->id}/xl-customers?mode=items")->assertNotFound();
        $this->postJson("/api/campaigns/{$sent->id}/test", ['email' => 'obcy@example.com'])->assertForbidden();
        $this->assertSame(Campaign::STATUS_SENDING, $sending->fresh()->status);
        $this->assertNotNull($sent->fresh());
    }

    public function test_cancel_after_adding_cancels_only_the_addition(): void
    {
        [$campaign, $author, $list] = $this->sent(['a@klient.pl']);
        $sentAt = $campaign->sent_at->toIso8601String();
        $more = $this->mailingList($author, ['n@klient.pl'], false, 'Nowi');
        $campaign->update(['audience' => ['list_ids' => [$list->id, $more->id]]]);
        app(CampaignSender::class)->addRecipients($campaign->fresh(), $author, sha1('n@klient.pl'));

        $cancelled = app(CampaignSender::class)->cancel($campaign->fresh());

        $this->assertSame(Campaign::STATUS_SENT, $cancelled->status);
        $this->assertSame($sentAt, $cancelled->sent_at->toIso8601String());
        $this->assertSame(['recipients' => 2, 'sent' => 1, 'failed' => 0, 'skipped' => 1], $cancelled->totals);
        $this->assertSame('skipped', CampaignRecipient::query()->where('email', 'n@klient.pl')->value('status'));
    }

    public function test_recipient_in_flight_when_addition_is_cancelled_is_counted_and_not_requeued(): void
    {
        [$campaign, $author, $list] = $this->sent(['a@klient.pl']);
        $more = $this->mailingList($author, ['n@klient.pl', 'm@klient.pl'], false, 'Nowi');
        $campaign->update(['audience' => ['list_ids' => [$list->id, $more->id]]]);
        app(CampaignSender::class)->addRecipients($campaign->fresh(), $author, sha1("m@klient.pl\nn@klient.pl"));
        $factory = new class extends FakeCampaignMailerFactory
        {
            public ?Closure $onMake = null;

            public function make(UserMailAccount $account): Mailer
            {
                $mailer = parent::make($account);
                if ($this->onMake !== null) {
                    ($this->onMake)();
                }

                return $mailer;
            }
        };
        $this->app->instance(UserMailerFactory::class, $factory);
        $this->app->forgetInstance(CampaignSender::class);
        // anulowanie dopisania przychodzi, gdy „n” jest już zarezerwowany, a skrzynka chwilowo nie odpowiada
        $factory->onMake = static fn () => app(CampaignSender::class)->cancel($campaign->fresh());
        $factory->transport->failAll = new TransportException('Connection to "smtp.example.pl:587" timed out.');
        $n = CampaignRecipient::query()->where('email', 'n@klient.pl')->firstOrFail();
        $n->forceFill(['status' => 'sending'])->save();

        app(CampaignSender::class)->sendOne($n);

        $this->assertSame('skipped', $n->fresh()->status);
        $this->assertSame(Campaign::STATUS_SENT, $campaign->fresh()->status);
        $this->assertSame(['recipients' => 3, 'sent' => 1, 'failed' => 0, 'skipped' => 2], $campaign->fresh()->totals);
        $this->assertSame(0, CampaignRecipient::query()->whereIn('status', ['pending', 'sending'])->count());

        // przerwana rezerwacja po anulowanym dopisaniu też trafia do liczników
        $factory->onMake = null;
        $factory->transport->failAll = null;
        $more2 = $this->mailingList($author, ['z@klient.pl'], false, 'Jeszcze');
        $campaign->update(['audience' => ['list_ids' => [$list->id, $more->id, $more2->id]]]);
        app(CampaignSender::class)->addRecipients($campaign->fresh(), $author, sha1('z@klient.pl'));
        CampaignRecipient::query()->where('email', 'z@klient.pl')->update(['status' => 'sending', 'updated_at' => now()]);
        app(CampaignSender::class)->cancel($campaign->fresh());
        $this->travel(16)->minutes();
        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $this->assertSame(['recipients' => 4, 'sent' => 1, 'failed' => 1, 'skipped' => 2], $campaign->fresh()->totals);
    }

    public function test_adding_closes_thirty_days_after_start(): void
    {
        [$campaign, $author, $list] = $this->sent(['a@klient.pl']);
        $this->mailingList($author, ['n@klient.pl'], false, 'Nowi');
        Sanctum::actingAs($author);

        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(23, 0));
        $this->getJson("/api/campaigns/{$campaign->id}")->assertJsonPath('can_add_recipients', true);
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(8, 0));
        $this->getJson("/api/campaigns/{$campaign->id}")->assertJsonPath('can_add_recipients', false);
        $this->patchJson("/api/campaigns/{$campaign->id}", ['audience' => ['list_ids' => [$list->id]]])
            ->assertUnprocessable()->assertJsonPath('message', CampaignSender::addClosedMessage());
    }

    public function test_view_only_does_not_see_purchase_cost(): void
    {
        [$campaign, $author] = $this->sent(['a@klient.pl']);
        $campaign->items()->first()->erpItem->update(['stock_value' => 4200]);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('campaigns.view');

        Sanctum::actingAs($author);
        $this->assertEquals(10, $this->getJson("/api/campaigns/{$campaign->id}")->json('items.0.unit_cost'));
        $this->assertNotNull($this->getJson('/api/campaigns')->json('data.0.stock_value'));

        Sanctum::actingAs($viewer);
        $item = $this->getJson("/api/campaigns/{$campaign->id}")->assertOk()->json('items.0');
        $this->assertNull($item['unit_cost']);
        $this->assertNull($item['suggested_price']);
        $this->assertFalse($item['warnings']['below_cost']);
        $this->assertNull($this->getJson('/api/campaigns?scope=all')->json('data.0.stock_value'));
    }

    public function test_view_permission_exists_only_for_admin_role(): void
    {
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('campaigns.view'));
        $this->assertFalse(Role::findByName('handlowiec', 'web')->hasPermissionTo('campaigns.view'));
    }

    /**
     * Wysłana kampania (start + dispatch) z grupą adresów.
     *
     * @param  list<string>  $emails
     * @return array{0: Campaign, 1: User, 2: MailingList}
     */
    private function sent(array $emails): array
    {
        $author = $this->sender();
        $list = $this->mailingList($author, $emails);
        $campaign = $this->campaign($author, [$this->erpItem('B20417', 420)], ['audience' => ['list_ids' => [$list->id]]]);
        app(CampaignSender::class)->start($campaign, $author);
        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $this->assertSame(CampaignRecipient::query()->where('campaign_id', $campaign->id)->count(), count($emails));

        return [$campaign->fresh(), $author, $list];
    }
}
