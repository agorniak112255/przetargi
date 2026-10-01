<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\CampaignRecipient;
use App\Models\EmailSuppression;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\Campaigns\AudienceResolver;
use App\Services\Campaigns\CampaignItemPresenter;
use App\Services\Campaigns\CampaignRenderer;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\SmtpHostGuard;
use App\Services\Campaigns\UserMailerFactory;
use Closure;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\Support\CampaignFixtures;
use Tests\Support\FakeCampaignMailerFactory;
use Tests\TestCase;
use Throwable;

/** Start wysyłki, campaigns:dispatch (rezerwacja, limity, pauza nadawcy), anulowanie, test skrzynki. */
final class CampaignSendingTest extends TestCase
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

    public function test_start_validates_saves_snapshots_recipients_and_copy_to_self(): void
    {
        $author = $this->sender(['copy_to_self' => true]);
        $item = $this->erpItem('B20417', 420);
        $list = $this->mailingList($author, ['a@klient.pl', 'b@klient.pl']);
        $campaign = $this->campaign($author, [$item], ['audience' => ['list_ids' => [$list->id]]]);

        // tylko autor wysyła ze swojej skrzynki
        $this->assertStartFails($campaign, User::factory()->withRole('admin')->create(), 'tylko jej autor');

        $started = app(CampaignSender::class)->start($campaign, $author);

        $this->assertSame(Campaign::STATUS_SENDING, $started->status);
        $this->assertNotNull($started->sending_started_at);
        $this->assertSame(['recipients' => 2, 'sent' => 0, 'failed' => 0, 'skipped' => 0], $started->totals);
        $snap = CampaignItem::query()->firstOrFail();
        $this->assertSame('Towar B20417', $snap->snap_name);
        $this->assertSame('B20417', $snap->snap_code);
        $this->assertSame('szt', $snap->snap_unit);
        $this->assertEquals(89, $snap->snap_price);
        $this->assertEquals(420, $snap->snap_stock);
        $this->assertSame('2026-09-29', $snap->snap_stock_at->toDateString());
        // bez karty i bez opisu przy pozycji — w migawce nie ma opisu ani norm (nic nie dopisujemy)
        $this->assertNull($snap->snap_description);
        $this->assertNull($snap->snap_norms);
        $this->assertSame(['a@klient.pl', 'b@klient.pl'], CampaignRecipient::query()->orderBy('id')->pluck('email')->all());
        $this->assertSame(['pending'], CampaignRecipient::query()->distinct()->pluck('status')->all());
        // kopia do nadawcy po starcie
        $this->assertSame(['jan@supon.example.pl'], $this->mailers->recipients());
        $this->assertStringStartsWith('[Kopia] ', $this->mailers->emails()[0]->getSubject());
        // kopia mówi wprost, że jej kliknięcia się nie liczą, i nie ma linków mierzonych
        $copyHtml = (string) $this->mailers->emails()[0]->getHtmlBody();
        $this->assertStringContainsString('To kopia dla nadawcy — kliknięcia w tej wiadomości nie są liczone', $copyHtml);
        $this->assertStringNotContainsString('/api/k/', $copyHtml);
        $this->assertStringContainsString('To kopia dla nadawcy', (string) $this->mailers->emails()[0]->getTextBody());

        // drugi start tej samej kampanii → 422, bez nowych odbiorców
        $this->assertStartFails($campaign, $author, 'już wysłana');
        $this->assertSame(2, CampaignRecipient::query()->count());
    }

    public function test_start_requires_items_subject_mailbox_public_url_and_recipients(): void
    {
        $author = $this->sender();
        $list = $this->mailingList($author, ['a@klient.pl']);
        $audience = ['audience' => ['list_ids' => [$list->id]]];

        $this->assertStartFails($this->campaign($author, [], $audience), $author, 'co najmniej jedną pozycję');
        $this->assertStartFails($this->campaign($author, [$this->erpItem('A1')], [...$audience, 'subject' => ' ']), $author, 'temat');
        $this->assertStartFails($this->campaign($author, [$this->erpItem('A2')]), $author, 'nie ma odbiorców');

        $noMailbox = User::factory()->withRole('handlowiec')->create();
        $this->assertStartFails($this->campaign($noMailbox, [$this->erpItem('A3')], $audience), $noMailbox, 'Moja poczta');

        config(['campaigns.public_url' => '']);
        $campaign = $this->campaign($author, [$this->erpItem('A4')], $audience);
        $this->assertStartFails($campaign, $author, 'CAMPAIGNS_PUBLIC_URL');
        $this->assertSame(Campaign::STATUS_DRAFT, $campaign->fresh()->status);
        $this->assertNull(CampaignItem::query()->where('campaign_id', $campaign->id)->value('snap_name'));
        $this->assertSame(0, CampaignRecipient::query()->count());
    }

    public function test_start_refuses_incomplete_blocks_saved_in_draft(): void
    {
        $author = $this->sender();
        $list = $this->mailingList($author, ['a@klient.pl']);
        $campaign = $this->campaign($author, [$this->erpItem('A1')], [
            'audience' => ['list_ids' => [$list->id]],
            'blocks' => [['type' => 'products', 'layout' => 'grid3'], ['type' => 'button', 'label' => 'Katalog', 'url' => '']],
        ]);

        $this->assertStartFails($campaign, $author, 'Przycisk (element nr 2): podaj adres https://… albo mailto:…');
        $this->assertSame(Campaign::STATUS_DRAFT, $campaign->fresh()->status);
        $this->assertSame(0, CampaignRecipient::query()->count());
        // projekt zapisuje adres w trakcie pisania — wysyłka go odrzuca
        foreach (['htt', 'javascript:alert(1)'] as $url) {
            $campaign->update(['blocks' => [['type' => 'products', 'layout' => 'grid3'], ['type' => 'button', 'label' => 'Katalog', 'url' => $url]]]);
            $this->assertStartFails($campaign->fresh(), $author, 'Przycisk (element nr 2): podaj adres https://… albo mailto:…');
        }

        $campaign->update(['blocks' => [['type' => 'products', 'layout' => 'grid3'], ['type' => 'button', 'label' => 'Katalog', 'url' => 'https://supon.pl']]]);
        $this->assertSame(Campaign::STATUS_SENDING, app(CampaignSender::class)->start($campaign->fresh(), $author)->status);
    }

    public function test_dispatch_sends_with_unsubscribe_headers_finishes_and_second_run_sends_nothing(): void
    {
        [$campaign] = $this->started(['a@klient.pl', 'b@klient.pl']);

        $this->artisan('campaigns:dispatch')->assertSuccessful();

        $this->assertSame(['a@klient.pl', 'b@klient.pl'], $this->mailers->recipients());
        $email = $this->mailers->emails()[0];
        $recipient = CampaignRecipient::query()->where('email', 'a@klient.pl')->firstOrFail();
        $url = 'https://przetargi.example.pl/api/wypis/'.$recipient->token;
        $this->assertSame('<'.$url.'>, <mailto:jan@supon.example.pl?subject=wypis>', $email->getHeaders()->get('List-Unsubscribe')->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString());
        $this->assertSame('jan@supon.example.pl', $email->getFrom()[0]->getAddress());
        $this->assertSame('Wyprzedaż BHP', $email->getSubject());
        $this->assertStringContainsString('href="'.$url.'"', (string) $email->getHtmlBody());
        $this->assertStringContainsString($url, (string) $email->getTextBody());

        $this->assertSame('sent', $recipient->status);
        $this->assertNotNull($recipient->sent_at);
        $this->assertNotNull($recipient->message_id);
        $campaign->refresh();
        $this->assertSame(Campaign::STATUS_SENT, $campaign->status);
        $this->assertNotNull($campaign->sent_at);
        $this->assertSame(['recipients' => 2, 'sent' => 2, 'failed' => 0, 'skipped' => 0], $campaign->totals);

        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $this->assertCount(2, $this->mailers->transport->sent);
    }

    public function test_reserved_recipient_is_not_sent_by_another_run_and_stale_reservation_fails(): void
    {
        [$campaign] = $this->started(['a@klient.pl', 'b@klient.pl']);
        // „a” właśnie wysyła inny przebieg
        CampaignRecipient::query()->where('email', 'a@klient.pl')->update(['status' => 'sending', 'updated_at' => now()]);

        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $this->assertSame(['b@klient.pl'], $this->mailers->recipients());
        // kampania czeka na wynik rezerwacji
        $this->assertSame(Campaign::STATUS_SENDING, $campaign->fresh()->status);

        // przebieg przerwany > 15 min temu: nie wiadomo, czy wysłano — nigdy ponownie
        $this->travel(16)->minutes();
        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $a = CampaignRecipient::query()->where('email', 'a@klient.pl')->firstOrFail();
        $this->assertSame('failed', $a->status);
        $this->assertStringContainsString('nie wiadomo, czy wysłano', (string) $a->error);
        $this->assertSame(['b@klient.pl'], $this->mailers->recipients());
        $this->assertSame(['recipients' => 2, 'sent' => 1, 'failed' => 1, 'skipped' => 0], $campaign->fresh()->totals);
        $this->assertSame(Campaign::STATUS_SENT, $campaign->fresh()->status);
    }

    public function test_hourly_limit_per_sender_and_per_minute_batch(): void
    {
        $emails = array_map(static fn (int $i): string => 'k'.$i.'@klient.pl', range(1, 6));
        [, $author] = $this->started($emails, ['rate_per_hour' => 120]);

        // 120/h → najwyżej ceil(120/60) + 1 = 3 na minutę
        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $this->assertCount(3, $this->mailers->transport->sent);

        // limit godzinowy liczy wysłane z ostatnich 60 min: przy 4/h zostaje 1
        UserMailAccount::query()->where('user_id', $author->id)->update(['rate_per_hour' => 4]);
        $this->travel(1)->minutes();
        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $this->assertCount(4, $this->mailers->transport->sent);
        $this->travel(1)->minutes();
        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $this->assertCount(4, $this->mailers->transport->sent);

        // po godzinie budżet wraca
        $this->travel(61)->minutes();
        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $this->assertCount(6, $this->mailers->transport->sent);
    }

    public function test_login_error_pauses_sender_without_counting_attempts(): void
    {
        [$campaign, $author] = $this->started(['a@klient.pl', 'b@klient.pl']);
        $this->mailers->transport->failAll = new TransportException('Failed to authenticate on SMTP server with username "jan" using the following authenticators: "LOGIN". Authenticator "LOGIN" returned "535 bad tajne-haslo-123".', 535);

        $this->artisan('campaigns:dispatch')->assertSuccessful();

        // jedna próba i przerwa: odbiorca wraca do kolejki bez zwiększania prób
        $this->assertCount(1, $this->mailers->made);
        $this->assertSame(['pending'], CampaignRecipient::query()->distinct()->pluck('status')->all());
        $this->assertSame([0], CampaignRecipient::query()->distinct()->pluck('attempts')->all());
        $account = UserMailAccount::query()->where('user_id', $author->id)->firstOrFail();
        $this->assertStringContainsString('Failed to authenticate', (string) $account->last_error);
        $this->assertStringNotContainsString('tajne-haslo-123', (string) $account->last_error);
        $this->assertTrue(CampaignSender::isPaused($author->id));

        // w czasie przerwy nic nie idzie, nawet po naprawie serwera
        $this->mailers->transport->failAll = null;
        $this->travel(5)->minutes();
        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $this->assertCount(1, $this->mailers->made);

        $this->travel(11)->minutes();
        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $this->assertSame(['a@klient.pl', 'b@klient.pl'], $this->mailers->recipients());
        $this->assertSame(Campaign::STATUS_SENT, $campaign->fresh()->status);
    }

    public function test_unreadable_password_pauses_sender(): void
    {
        [, $author] = $this->started(['a@klient.pl']);
        $this->mailers->makeError = new DecryptException('The MAC is invalid.');

        $this->artisan('campaigns:dispatch')->assertSuccessful();

        $recipient = CampaignRecipient::query()->firstOrFail();
        $this->assertSame('pending', $recipient->status);
        $this->assertSame(0, $recipient->attempts);
        $this->assertTrue(CampaignSender::isPaused($author->id));
        $this->assertStringContainsString('hasła', (string) UserMailAccount::query()->where('user_id', $author->id)->value('last_error'));
    }

    public function test_unknown_mailbox_fails_at_once_and_is_suppressed_for_next_campaigns(): void
    {
        [$campaign, $author] = $this->started(['zly@klient.pl', 'dobry@klient.pl']);
        $this->mailers->transport->failFor['zly@klient.pl'] = new UnexpectedResponseException('Expected response code "250/251/252" but got code "550", with message "550 5.1.1 <zly@klient.pl>: Recipient address rejected: User unknown in virtual mailbox table".', 550);

        $this->artisan('campaigns:dispatch')->assertSuccessful();

        // nieistniejąca skrzynka: od razu błąd, bez ponowień, i adres na liście wypisanych (powód „bounce”)
        $bad = CampaignRecipient::query()->where('email', 'zly@klient.pl')->firstOrFail();
        $this->assertSame('failed', $bad->status);
        $this->assertSame(1, $bad->attempts);
        $this->assertStringContainsString('User unknown', (string) $bad->error);
        $this->assertFalse(CampaignSender::isPaused($author->id));
        $this->assertSame(['dobry@klient.pl'], $this->mailers->recipients());
        $suppression = EmailSuppression::query()->where('email', 'zly@klient.pl')->sole();
        $this->assertSame(EmailSuppression::REASON_BOUNCE, $suppression->reason);
        $this->assertSame($campaign->id, $suppression->campaign_id);

        $campaign->refresh();
        $this->assertSame(Campaign::STATUS_SENT, $campaign->status);
        $this->assertSame(['recipients' => 2, 'sent' => 1, 'failed' => 1, 'skipped' => 0], $campaign->totals);
    }

    public function test_other_rejection_is_retried_and_fails_after_max_without_suppression(): void
    {
        [$campaign] = $this->started(['blok@klient.pl', 'dobry@klient.pl']);
        // 5.7.1 (polityka serwera odbiorcy) nie mówi, że adres nie istnieje — ponawiamy do limitu prób
        $this->mailers->transport->failFor['blok@klient.pl'] = new UnexpectedResponseException('Expected response code "250/251/252" but got code "550", with message "550 5.7.1 Message rejected by policy".', 550);

        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $bad = CampaignRecipient::query()->where('email', 'blok@klient.pl')->firstOrFail();
        $this->assertSame(['pending', 1], [$bad->status, $bad->attempts]);

        for ($run = 2; $run <= 3; $run++) {
            $this->travel(1)->minutes();
            $this->artisan('campaigns:dispatch')->assertSuccessful();
        }
        $bad->refresh();
        $this->assertSame(['failed', 3], [$bad->status, $bad->attempts]);
        $this->assertSame(0, EmailSuppression::query()->count());
        $this->assertSame(Campaign::STATUS_SENT, $campaign->fresh()->status);
    }

    public function test_cancel_during_sending_and_unsubscribed_recipient_is_skipped(): void
    {
        $emails = array_map(static fn (int $i): string => 'k'.$i.'@klient.pl', range(1, 5));
        [$campaign] = $this->started($emails, ['rate_per_hour' => 120]);
        // wypisał się po starcie wysyłki (z innej kampanii)
        EmailSuppression::query()->create(['email' => 'k2@klient.pl', 'reason' => EmailSuppression::REASON_UNSUBSCRIBE]);

        $this->artisan('campaigns:dispatch')->assertSuccessful();

        $this->assertSame(['k1@klient.pl', 'k3@klient.pl', 'k4@klient.pl'], $this->mailers->recipients());
        $this->assertSame('skipped', CampaignRecipient::query()->where('email', 'k2@klient.pl')->value('status'));

        $cancelled = app(CampaignSender::class)->cancel($campaign);
        $this->assertSame(Campaign::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame(['recipients' => 5, 'sent' => 3, 'failed' => 0, 'skipped' => 2], $cancelled->totals);

        $this->travel(1)->minutes();
        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $this->assertCount(3, $this->mailers->transport->sent);
        $this->assertSame(Campaign::STATUS_CANCELLED, $campaign->fresh()->status);

        // drugie anulowanie → 422
        $this->expectException(ValidationException::class);
        app(CampaignSender::class)->cancel($campaign->fresh());
    }

    public function test_frequency_cap_skips_address_that_got_another_campaign(): void
    {
        [, $author] = $this->started(['a@klient.pl']);
        $this->artisan('campaigns:dispatch')->assertSuccessful();

        $list = $this->mailingList($author, ['a@klient.pl', 'nowy@klient.pl'], name: 'Druga');
        $second = $this->campaign($author, [$this->erpItem('A9')], ['audience' => ['list_ids' => [$list->id]]]);
        app(CampaignSender::class)->start($second, $author);

        $this->assertSame(['nowy@klient.pl'], CampaignRecipient::query()->where('campaign_id', $second->id)->pluck('email')->all());
    }

    public function test_send_test_and_account_test(): void
    {
        $author = $this->sender();
        $campaign = $this->campaign($author, [$this->erpItem('B20417')]);

        app(CampaignSender::class)->sendTest($campaign, 'szef@supon.example.pl');
        $email = $this->mailers->emails()[0];
        $this->assertSame(['szef@supon.example.pl'], $this->mailers->recipients());
        $this->assertSame('[TEST] Wyprzedaż BHP', $email->getSubject());
        $this->assertNull($email->getHeaders()->get('List-Unsubscribe'));
        $this->assertSame(0, CampaignRecipient::query()->count());

        $account = UserMailAccount::query()->where('user_id', $author->id)->firstOrFail();
        $result = app(CampaignSender::class)->testAccount($account);
        $this->assertTrue($result['ok']);
        $this->assertNotNull($account->fresh()->verified_at);
        $this->assertSame('jan@supon.example.pl', $this->mailers->recipients()[1]);

        $this->mailers->transport->failAll = new TransportException('Connection could not be established with host "ssl://smtp.example.pl:465": tajne-haslo-123');
        $result = app(CampaignSender::class)->testAccount($account->fresh());
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Connection could not be established', $result['message']);
        $this->assertStringNotContainsString('tajne-haslo-123', (string) $account->fresh()->last_error);
    }

    public function test_sender_error_classification(): void
    {
        $sender = new class(app(UserMailerFactory::class), app(CampaignRenderer::class), app(AudienceResolver::class), app(CampaignItemPresenter::class)) extends CampaignSender
        {
            public function classify(Throwable $e): bool
            {
                return $this->isSenderError($e);
            }
        };

        // połączenie, logowanie, zamknięcie połączenia przez serwer → skrzynka nadawcy
        $this->assertTrue($sender->classify(new TransportException('Connection to "smtp.example.pl:587" timed out.')));
        $this->assertTrue($sender->classify(new TransportException('Failed to authenticate', 535)));
        $this->assertTrue($sender->classify(new UnexpectedResponseException('Expected response code "250" but got code "421".', 421)));
        $mailFrom = new UnexpectedResponseException('Expected response code "250" but got code "553".', 553);
        $mailFrom->appendDebug("[2026-09-30T10:00:00.000000+02:00] > MAIL FROM:<jan@supon.example.pl>\n[2026-09-30T10:00:00.000000+02:00] < 553 5.7.1 Sender rejected\r\n");
        $this->assertTrue($sender->classify($mailFrom));

        // odrzucony adresat albo treść dla adresata → próba odbiorcy
        $this->assertFalse($sender->classify(new UnexpectedResponseException('Expected response code "250/251/252" but got code "550".', 550)));
        $data = new UnexpectedResponseException('Expected response code "250" but got code "552".', 552);
        $data->appendDebug("[2026-09-30T10:00:00.000000+02:00] > DATA\n[2026-09-30T10:00:00.000000+02:00] < 354 go\r\n[2026-09-30T10:00:00.000000+02:00] > .\n[2026-09-30T10:00:00.000000+02:00] < 552 mailbox full\r\n");
        $this->assertFalse($sender->classify($data));

        // każda odmowa tymczasowa 4xx (limit tempa, szare listy) — także przy RCPT TO — to skrzynka, nie adres
        $this->assertTrue($sender->classify(new UnexpectedResponseException('Expected response code "250/251/252" but got code "450", with message "450 4.7.1 greylisted".', 450)));
        $this->assertTrue($sender->classify(new UnexpectedResponseException('Expected response code "250" but got code "451".', 451)));
        $this->assertTrue($sender->classify(new UnexpectedResponseException('Expected response code "354" but got code "452".', 452)));
    }

    public function test_temporary_rejection_pauses_sender_without_counting_attempts(): void
    {
        [, $author] = $this->started(['a@klient.pl', 'b@klient.pl']);
        $this->mailers->transport->failFor['a@klient.pl'] = new UnexpectedResponseException('Expected response code "250/251/252" but got code "450", with message "450 4.2.1 rate limited".', 450);

        $this->artisan('campaigns:dispatch')->assertSuccessful();

        $a = CampaignRecipient::query()->where('email', 'a@klient.pl')->firstOrFail();
        $this->assertSame('pending', $a->status);
        $this->assertSame(0, $a->attempts);
        $this->assertTrue(CampaignSender::isPaused($author->id));
        // po błędzie skrzynki nikt dalej nie dostaje maili w tym przebiegu
        $this->assertSame([], $this->mailers->recipients());
        $this->assertSame('pending', CampaignRecipient::query()->where('email', 'b@klient.pl')->value('status'));
    }

    public function test_three_rejected_addresses_in_a_row_pause_sender(): void
    {
        $emails = ['z1@klient.pl', 'ok@klient.pl', 'z2@klient.pl', 'z3@klient.pl', 'z4@klient.pl', 'k6@klient.pl'];
        [, $author] = $this->started($emails, ['rate_per_hour' => 600]);
        foreach (['z1', 'z2', 'z3', 'z4'] as $bad) {
            $this->mailers->transport->failFor[$bad.'@klient.pl'] = new UnexpectedResponseException('Expected response code "250/251/252" but got code "550", with message "550 5.7.1 spam".', 550);
        }

        $this->artisan('campaigns:dispatch')->assertSuccessful();

        // z1 odrzucony, ok wysłany (licznik od nowa), z2, z3, z4 pod rząd → przerwa; k6 czeka
        $this->assertSame(['ok@klient.pl'], $this->mailers->recipients());
        $this->assertTrue(CampaignSender::isPaused($author->id));
        $this->assertSame('Serwer odrzuca kolejne adresy — sprawdź skrzynkę', UserMailAccount::query()->where('user_id', $author->id)->value('last_error'));
        $attempts = CampaignRecipient::query()->pluck('attempts', 'email')->all();
        $this->assertSame(['z1@klient.pl' => 1, 'ok@klient.pl' => 0, 'z2@klient.pl' => 1, 'z3@klient.pl' => 1, 'z4@klient.pl' => 1, 'k6@klient.pl' => 0], $attempts);
        $this->assertSame('pending', CampaignRecipient::query()->where('email', 'k6@klient.pl')->value('status'));
    }

    public function test_send_one_skips_address_that_got_another_campaign_meanwhile(): void
    {
        // dwóch handlowców wystartowało równocześnie — obie kampanie zapisały a@, zanim którakolwiek wysłała
        $this->started(['a@klient.pl']);
        [$second] = $this->started(['b@klient.pl']);
        CampaignRecipient::query()->create([
            'campaign_id' => $second->id, 'email' => 'a@klient.pl', 'source' => 'list', 'token' => str_repeat('r', 40), 'status' => 'pending',
        ]);

        $this->artisan('campaigns:dispatch')->assertSuccessful();

        // a@ dostał pierwszą kampanię; druga go pomija
        $this->assertSame(['a@klient.pl', 'b@klient.pl'], $this->mailers->recipients());
        $skipped = CampaignRecipient::query()->where('campaign_id', $second->id)->where('email', 'a@klient.pl')->firstOrFail();
        $this->assertSame('skipped', $skipped->status);
        $this->assertSame('limit częstotliwości', $skipped->error);
    }

    public function test_address_waiting_in_another_campaign_is_skipped_when_that_campaign_delivers(): void
    {
        // drugi handlowiec startuje, gdy a@ czeka jeszcze w kampanii pierwszego
        [$first] = $this->started(['a@klient.pl']);
        [$second] = $this->started(['a@klient.pl', 'b@klient.pl']);
        $waiting = CampaignRecipient::query()->where('campaign_id', $second->id)->where('email', 'a@klient.pl')->firstOrFail();
        $this->assertSame('pending', $waiting->status);
        $this->assertSame(2, $second->totals['recipients']);

        $this->artisan('campaigns:dispatch')->assertSuccessful();

        // a@ dostał tylko pierwszą kampanię
        $this->assertSame(['a@klient.pl', 'b@klient.pl'], $this->mailers->recipients());
        $this->assertSame('sent', CampaignRecipient::query()->where('campaign_id', $first->id)->value('status'));
        $this->assertSame('skipped', $waiting->fresh()->status);
        $this->assertSame('limit częstotliwości', $waiting->fresh()->error);
        $this->assertSame(Campaign::STATUS_SENT, $second->fresh()->status);
    }

    public function test_address_waiting_in_cancelled_or_failed_campaign_gets_the_next_one(): void
    {
        [$first] = $this->started(['a@klient.pl', 'c@klient.pl']);
        [$second] = $this->started(['a@klient.pl', 'c@klient.pl', 'b@klient.pl']);
        // pierwsza kampania stoi (przerwa skrzynki nadawcy) — druga wysyła tylko b@, a@ i c@ czekają
        app(CampaignSender::class)->pause($first->user_id, null, 'test');

        $this->artisan('campaigns:dispatch')->assertSuccessful();

        $this->assertSame(['b@klient.pl'], $this->mailers->recipients());
        $this->assertSame(Campaign::STATUS_SENDING, $second->fresh()->status);
        $this->assertSame(['a@klient.pl' => 'pending', 'c@klient.pl' => 'pending'], CampaignRecipient::query()
            ->where('campaign_id', $second->id)->whereIn('email', ['a@klient.pl', 'c@klient.pl'])->orderBy('email')->pluck('status', 'email')->all());

        // a@ nie doszedł w pierwszej (błąd), c@ — pierwsza anulowana: druga wysyła obu
        CampaignRecipient::query()->where('campaign_id', $first->id)->where('email', 'a@klient.pl')->update(['status' => 'failed', 'error' => 'odrzucony']);
        app(CampaignSender::class)->cancel($first);
        $this->travel(1)->minutes();
        $this->artisan('campaigns:dispatch')->assertSuccessful();

        $this->assertSame(['b@klient.pl', 'a@klient.pl', 'c@klient.pl'], $this->mailers->recipients());
        $this->assertSame(Campaign::STATUS_SENT, $second->fresh()->status);
        $this->assertSame(['recipients' => 3, 'sent' => 3, 'failed' => 0, 'skipped' => 0], $second->fresh()->totals);
    }

    public function test_send_one_returns_waiting_recipient_to_queue_without_attempt(): void
    {
        $this->started(['a@klient.pl']);
        [$second, $author] = $this->started(['a@klient.pl']);
        $waiting = CampaignRecipient::query()->where('campaign_id', $second->id)->firstOrFail();
        $waiting->forceFill(['status' => 'sending'])->save();

        $this->assertFalse(app(CampaignSender::class)->sendOne($waiting));

        $this->assertSame('pending', $waiting->fresh()->status);
        $this->assertSame(0, $waiting->fresh()->attempts);
        $this->assertSame([], $this->mailers->recipients());
        $this->assertFalse(CampaignSender::isPaused($author->id));
    }

    public function test_recipient_finished_after_cancel_updates_totals_of_cancelled_campaign(): void
    {
        [$campaign] = $this->started(['a@klient.pl', 'b@klient.pl', 'c@klient.pl']);
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
        // anulowanie przychodzi w trakcie wysyłki do „a” (odbiorca zarezerwowany)
        $factory->onMake = static fn () => app(CampaignSender::class)->cancel($campaign);
        $a = CampaignRecipient::query()->where('email', 'a@klient.pl')->firstOrFail();
        $a->forceFill(['status' => 'sending'])->save();

        app(CampaignSender::class)->sendOne($a);

        $this->assertSame('sent', $a->fresh()->status);
        $this->assertSame(['recipients' => 3, 'sent' => 1, 'failed' => 0, 'skipped' => 2], $campaign->fresh()->totals);

        // błąd skrzynki po anulowaniu: odbiorca nie wraca do kolejki anulowanej kampanii
        $second = $this->started(['d@klient.pl', 'e@klient.pl'])[0];
        $factory->onMake = static fn () => app(CampaignSender::class)->cancel($second);
        $factory->transport->failAll = new TransportException('Connection to "smtp.example.pl:587" timed out.');
        $d = CampaignRecipient::query()->where('email', 'd@klient.pl')->firstOrFail();
        $d->forceFill(['status' => 'sending'])->save();

        app(CampaignSender::class)->sendOne($d);

        $this->assertSame('skipped', $d->fresh()->status);
        $this->assertSame(['recipients' => 2, 'sent' => 0, 'failed' => 0, 'skipped' => 2], $second->fresh()->totals);
        $this->assertSame(0, CampaignRecipient::query()->where('status', 'pending')->count());

        // przerwana rezerwacja (15 min) w anulowanej kampanii też trafia do liczników
        $factory->onMake = null;
        $factory->transport->failAll = null;
        $third = $this->started(['f@klient.pl', 'g@klient.pl'])[0];
        CampaignRecipient::query()->where('email', 'f@klient.pl')->update(['status' => 'sending', 'updated_at' => now()]);
        app(CampaignSender::class)->cancel($third);
        $this->travel(16)->minutes();
        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $this->assertSame(['recipients' => 2, 'sent' => 0, 'failed' => 1, 'skipped' => 1], $third->fresh()->totals);
    }

    public function test_mailer_factory_requires_tls_and_rechecks_host(): void
    {
        $author = $this->sender();
        $account = UserMailAccount::query()->where('user_id', $author->id)->firstOrFail();
        $dns = ['smtp.example.pl' => ['212.77.98.9']];
        $factory = new UserMailerFactory(new SmtpHostGuard(static function (string $host) use (&$dns): array {
            return $dns[$host] ?? [];
        }));

        // STARTTLS (587): bez szyfrowania serwer nie dostanie hasła
        $mailer = $factory->make($account);
        $this->assertInstanceOf(\Illuminate\Mail\Mailer::class, $mailer);
        $transport = $mailer->getSymfonyTransport();
        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertTrue($transport->isTlsRequired());
        $this->assertFalse($transport->getStream()->isTLS());
        $auto = $factory->make((clone $account)->forceFill(['scheme' => null, 'port' => 25]))->getSymfonyTransport();
        $this->assertInstanceOf(EsmtpTransport::class, $auto);
        $this->assertTrue($auto->isTlsRequired());

        // SSL od początku (465 / smtps) — połączenie szyfrowane
        foreach ([['scheme' => 'smtps', 'port' => 465], ['scheme' => null, 'port' => 465]] as $ssl) {
            $transport = $factory->make((clone $account)->forceFill($ssl))->getSymfonyTransport();
            $this->assertInstanceOf(EsmtpTransport::class, $transport);
            $this->assertTrue($transport->getStream()->isTLS());
        }

        // nazwa, która po zapisie zaczęła wskazywać sieć wewnętrzną — przed połączeniem odmowa
        $dns['smtp.example.pl'] = ['10.0.0.7'];
        try {
            $factory->make($account);
            $this->fail('Mailer nie powinien powstać dla adresu wewnętrznego.');
        } catch (TransportException $e) {
            $this->assertSame(SmtpHostGuard::NOT_PUBLIC, $e->getMessage());
        }
    }

    public function test_blocked_mail_server_pauses_sender(): void
    {
        [, $author] = $this->started(['a@klient.pl']);
        $this->app->instance(UserMailerFactory::class, new UserMailerFactory(new SmtpHostGuard(static fn (string $host): array => ['127.0.0.1'])));
        $this->app->forgetInstance(CampaignSender::class);

        $this->artisan('campaigns:dispatch')->assertSuccessful();

        $recipient = CampaignRecipient::query()->firstOrFail();
        $this->assertSame('pending', $recipient->status);
        $this->assertSame(0, $recipient->attempts);
        $this->assertTrue(CampaignSender::isPaused($author->id));
        $this->assertSame(SmtpHostGuard::NOT_PUBLIC, UserMailAccount::query()->where('user_id', $author->id)->value('last_error'));
    }

    /**
     * Kampania autora w trakcie wysyłki do podanych adresów (z grupy).
     *
     * @param  list<string>  $emails
     * @param  array<string, mixed>  $account
     * @return array{0: Campaign, 1: User}
     */
    private function started(array $emails, array $account = []): array
    {
        $author = $this->sender($account);
        $list = $this->mailingList($author, $emails);
        $campaign = $this->campaign($author, [$this->erpItem('B20417', 420)], ['audience' => ['list_ids' => [$list->id]]]);

        return [app(CampaignSender::class)->start($campaign, $author), $author];
    }

    private function assertStartFails(Campaign $campaign, User $actor, string $message): void
    {
        try {
            app(CampaignSender::class)->start($campaign, $actor);
            $this->fail('Start powinien się nie udać: '.$message);
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertSame(422, $e->status);
        }
    }
}
