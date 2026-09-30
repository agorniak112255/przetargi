<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignReply;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\Campaigns\CampaignReplySync;
use App\Services\Campaigns\ImapHeaderReader;
use App\Services\Campaigns\SmtpHostGuard;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CampaignFixtures;
use Tests\Support\FakeImapHeaderReader;
use Tests\TestCase;

/** Odpowiedzi klientów ze skrzynki handlowca: kod kampanii w temacie, wątek wysłanego maila, pomijanie szumu, przyrostowo. */
final class CampaignReplySyncTest extends TestCase
{
    use CampaignFixtures;
    use RefreshDatabase;

    private FakeImapHeaderReader $imap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(10, 0));
        $this->setUpCampaigns();
        $this->app->instance(SmtpHostGuard::class, new SmtpHostGuard(static fn (string $host): array => ['212.77.98.9']));
        $this->imap = new FakeImapHeaderReader;
        $this->app->instance(ImapHeaderReader::class, $this->imap);
    }

    /** @return array{0: Campaign, 1: CampaignRecipient, 2: User} */
    private function sentCampaign(): array
    {
        $author = $this->sender();
        $item = $this->erpItem('B20417', 40, ['name' => 'PÓŁBUTY S3 TIGER']);
        $campaign = $this->campaign($author, [$item], ['status' => 'sent', 'sending_started_at' => now()->subDays(2), 'sent_at' => now()->subDays(2)]);
        $campaign->items()->first()->forceFill(['snap_name' => 'PÓŁBUTY S3 TIGER', 'snap_code' => 'B20417', 'snap_price' => 89])->save();
        $recipient = CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id, 'email' => 'klient@alfa.pl', 'source' => 'list', 'token' => str_repeat('a', 40),
            'status' => 'sent', 'sent_at' => now()->subDays(2), 'message_id' => 'abc123@supon.example.pl',
        ]);

        return [$campaign->fresh(), $recipient, $author];
    }

    /** @return array<string, string> */
    private function mail(string $from, string $subject, array $extra = []): array
    {
        return ['from' => $from, 'subject' => $subject, 'date' => now()->subDay()->toRfc2822String(), 'message-id' => '<'.md5($from.$subject.json_encode($extra)).'@x.pl>', ...$extra];
    }

    public function test_code_in_subject_and_thread_reply_are_counted_noise_is_skipped(): void
    {
        [$campaign, $recipient, $author] = $this->sentCampaign();
        $code = $campaign->code;
        $this->imap->messages = [
            // przycisk „Zapytaj o ofertę”
            10 => $this->mail('"Anna Klient" <Klient@Alfa.pl>', "Zapytanie {$code} B20417"),
            // odpowiedź w wątku z innego adresu tej firmy
            11 => $this->mail('biuro@alfa.pl', 'Re: Promocja', ['in-reply-to' => '<ABC123@supon.example.pl>']),
            // kod kampanii, ale kod towaru spoza kampanii — bez zgadywania towaru
            12 => $this->mail('nowy@gamma.pl', "Zapytanie {$code} XYZ999"),
            // autoodpowiedź w wątku, zwrotka, własny mail handlowca, inna kampania, zwykła poczta, sprzed wysyłki
            13 => $this->mail('klient@alfa.pl', 'Automatyczna odpowiedź: Promocja', ['in-reply-to' => '<abc123@supon.example.pl>', 'auto-submitted' => 'auto-replied']),
            14 => $this->mail('MAILER-DAEMON@supon.example.pl', 'Undelivered', ['references' => '<abc123@supon.example.pl>']),
            15 => $this->mail('jan@supon.example.pl', "Zapytanie {$code} B20417"),
            16 => $this->mail('klient@alfa.pl', 'Zapytanie K-9999 B20417'),
            17 => $this->mail('klient@alfa.pl', 'Faktura za wrzesień'),
            18 => [...$this->mail('stary@delta.pl', "Zapytanie {$code}"), 'date' => now()->subDays(5)->toRfc2822String()],
            // własna odpowiedź handlowca w wątku klienta z kopią do siebie
            19 => $this->mail('Jan <jan@supon.example.pl>', 'Re: Promocja', ['references' => '<abc123@supon.example.pl> <x@alfa.pl>']),
        ];

        $stats = app(CampaignReplySync::class)->run();

        $this->assertSame(['accounts' => 1, 'messages' => 10, 'replies' => 3, 'errors' => 0], $stats);
        $replies = CampaignReply::query()->orderBy('id')->get();
        $this->assertSame(['klient@alfa.pl', 'biuro@alfa.pl', 'nowy@gamma.pl'], $replies->pluck('from_email')->all());
        $this->assertSame(['B20417', null, null], $replies->pluck('item_code')->all());
        $this->assertSame(['code', 'thread', 'code'], $replies->pluck('matched_by')->all());
        $this->assertSame([$recipient->id, $recipient->id, null], $replies->pluck('campaign_recipient_id')->all());
        $this->assertSame('Anna Klient', $replies[0]->from_name);
        $this->assertSame(now()->subDay()->toIso8601String(), $recipient->fresh()->replied_at?->toIso8601String());
        // tylko odczyt: skrzynka otwarta przez EXAMINE, nic poza nagłówkami
        $this->assertSame(['open', 'login', 'examine', 'searchSince', 'fetchHeaders', 'logout'], array_column($this->imap->calls, 0));
        $this->assertSame(['smtp.example.pl', 993, true], $this->imap->calls[0][1]);

        $account = UserMailAccount::query()->where('user_id', $author->id)->firstOrFail();
        $this->assertSame(7, (int) $account->imap_uidvalidity);
        $this->assertSame(19, (int) $account->imap_last_uid);
        $this->assertNotNull($account->imap_checked_at);
        $this->assertNull($account->imap_error);
    }

    public function test_next_run_is_incremental_and_never_duplicates(): void
    {
        [$campaign] = $this->sentCampaign();
        $this->imap->messages = [5 => $this->mail('klient@alfa.pl', "Zapytanie {$campaign->code} B20417")];
        app(CampaignReplySync::class)->run();

        $this->imap->calls = [];
        $this->imap->messages[6] = $this->mail('drugi@beta.pl', "RE: Zapytanie {$campaign->code} b20417 — 20 par");
        $stats = app(CampaignReplySync::class)->run();
        $this->assertSame(['searchAfterUid', 5], $this->imap->calls[3]);
        $this->assertSame(1, $stats['replies']);
        $this->assertSame('B20417', CampaignReply::query()->where('from_email', 'drugi@beta.pl')->value('item_code'));

        // serwer przenumerował skrzynkę: czytamy od nowa, te same wiadomości nie liczą się drugi raz
        $this->imap->uidValidity = 8;
        $this->imap->calls = [];
        $stats = app(CampaignReplySync::class)->run();
        $this->assertSame('searchSince', $this->imap->calls[3][0]);
        $this->assertSame(0, $stats['replies']);
        $this->assertSame(2, CampaignReply::query()->count());
    }

    public function test_login_error_is_saved_without_password_and_disabled_or_idle_accounts_are_not_read(): void
    {
        [, , $author] = $this->sentCampaign();
        $this->imap->loginError = 'Serwer odrzucił hasło tajne-haslo-123';

        $stats = app(CampaignReplySync::class)->run();

        $this->assertSame(1, $stats['errors']);
        $account = UserMailAccount::query()->where('user_id', $author->id)->firstOrFail();
        $this->assertSame('Serwer odrzucił hasło ***', $account->imap_error);
        $this->assertNull($account->imap_last_uid);

        // wyłączony odczyt i skrzynka bez kampanii z ostatnich 60 dni — bez połączenia
        $account->forceFill(['imap_enabled' => false])->save();
        $this->sender(['from_address' => 'ewa@supon.example.pl', 'username' => 'ewa@supon.example.pl']);
        $this->imap->calls = [];
        $this->assertSame(['accounts' => 0, 'messages' => 0, 'replies' => 0, 'errors' => 0], app(CampaignReplySync::class)->run());
        $this->assertSame([], $this->imap->calls);
    }

    public function test_connection_test_updates_read_state_at_once(): void
    {
        $author = $this->sender(['imap_host' => 'imap.example.pl']);
        $account = UserMailAccount::query()->where('user_id', $author->id)->firstOrFail();
        $this->imap->loginError = 'Odmowa dla tajne-haslo-123';

        $result = app(CampaignReplySync::class)->test($account);
        $this->assertFalse($result['ok']);
        $this->assertSame('Odczyt odpowiedzi (IMAP) nie działa: Odmowa dla ***', $result['message']);
        $this->assertSame('Odmowa dla ***', $account->fresh()->imap_error);
        $this->assertSame(['imap.example.pl', 993, true], $this->imap->calls[0][1]);

        $this->imap->loginError = null;
        $this->imap->messages = [1 => [], 2 => []];
        $this->assertSame(['ok' => true, 'message' => 'Odczyt odpowiedzi działa (skrzynka odbiorcza: 2 wiadomości).'], app(CampaignReplySync::class)->test($account->fresh()));
        $this->assertNull($account->fresh()->imap_error);
        // test nie przesuwa pozycji odczytu
        $this->assertNull($account->fresh()->imap_last_uid);
    }

    public function test_command_and_api_show_replies(): void
    {
        [$campaign, $recipient, $author] = $this->sentCampaign();
        $this->imap->messages = [3 => $this->mail('"Anna" <klient@alfa.pl>', "Zapytanie {$campaign->code} B20417")];

        $this->artisan('campaigns:replies')->expectsOutputToContain('nowych odpowiedzi: 1')->assertSuccessful();

        Sanctum::actingAs($author);
        $this->getJson("/api/campaigns/{$campaign->id}")->assertOk()
            ->assertJsonPath('replies.total', 1)
            ->assertJsonPath('replies.recipients', 1)
            ->assertJsonPath('replies.enabled', true)
            ->assertJsonPath('replies.error', null)
            ->assertJsonPath('replies.list.0.from_email', 'klient@alfa.pl')
            ->assertJsonPath('replies.list.0.from_name', 'Anna')
            ->assertJsonPath('replies.list.0.item_code', 'B20417')
            ->assertJsonPath('replies.list.0.matched_by', 'code')
            ->assertJsonPath('replies.list.0.recipient_email', 'klient@alfa.pl');
        $this->getJson("/api/campaigns/{$campaign->id}/recipients")->assertOk()
            ->assertJsonPath('data.0.replied_at', $recipient->fresh()->replied_at?->toIso8601String());
        $this->getJson('/api/campaigns')->assertOk()->assertJsonPath('data.0.replies', 1);

        // szkic bez wysyłki — brak sekcji odpowiedzi
        $draft = $this->campaign($author);
        $this->getJson("/api/campaigns/{$draft->id}")->assertOk()->assertJsonPath('replies', null);
    }
}
