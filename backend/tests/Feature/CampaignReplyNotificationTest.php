<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignReply;
use App\Models\User;
use App\Services\Campaigns\CampaignReplySync;
use App\Services\Campaigns\ImapHeaderReader;
use App\Services\Campaigns\SmtpHostGuard;
use Database\Seeders\RolesAndPermissionsSeeder;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CampaignFixtures;
use Tests\Support\FakeImapHeaderReader;
use Tests\TestCase;

/** Odpowiedź klienta na kampanię → powiadomienie autora, tylko o odpowiedziach z ostatnich 48 godzin, raz. */
final class CampaignReplyNotificationTest extends TestCase
{
    use CampaignFixtures;
    use RefreshDatabase;

    private FakeImapHeaderReader $imap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(10, 0));
        $this->setUpCampaigns();
        $this->app->instance(SmtpHostGuard::class, new SmtpHostGuard(static fn (string $host): array => ['212.77.98.9']));
        $this->imap = new FakeImapHeaderReader;
        $this->app->instance(ImapHeaderReader::class, $this->imap);
    }

    public function test_only_fresh_replies_notify_the_author_once(): void
    {
        [$campaign, $author] = $this->sentCampaign();
        $code = $campaign->code;
        $this->imap->messages = [
            10 => $this->mail('"Anna Klient" <klient@alfa.pl>', "Zapytanie {$code} B20417", now()->subHours(20)),
            // zapisana jako odpowiedź, ale starsza niż 48 godzin — bez powiadomienia
            11 => $this->mail('stary@beta.pl', "Zapytanie {$code}", now()->subHours(60)),
        ];

        $stats = app(CampaignReplySync::class)->run();

        $this->assertSame(2, $stats['replies']);
        $this->assertSame(2, CampaignReply::query()->count());
        $rows = $author->notifications()->get();
        $this->assertCount(1, $rows);
        $this->assertSame('campaign_reply', $rows[0]->data['type']);
        $this->assertSame('Klient odpowiedział na kampanię '.$code, $rows[0]->data['title']);
        $this->assertSame('Anna Klient <klient@alfa.pl> · temat: Zapytanie '.$code.' B20417', $rows[0]->data['body']);
        $this->assertSame('/kampanie/'.$campaign->id, $rows[0]->data['url']);
        $this->assertSame($campaign->id, $rows[0]->data['campaign_id']);
        // domyślnie tylko w dzwonku
        Mail::assertNothingSent();

        // skrzynka przenumerowana — te same wiadomości czytane od nowa nie dają drugiego powiadomienia
        $this->imap->uidValidity = 8;
        app(CampaignReplySync::class)->run();
        $this->assertSame(1, $author->notifications()->count());
    }

    /** @return array{0: Campaign, 1: User} */
    private function sentCampaign(): array
    {
        $author = $this->sender();
        $item = $this->erpItem('B20417', 40, ['name' => 'PÓŁBUTY S3 TIGER']);
        $campaign = $this->campaign($author, [$item], ['status' => 'sent', 'sending_started_at' => now()->subDays(5), 'sent_at' => now()->subDays(5)]);
        $campaign->items()->first()->forceFill(['snap_name' => 'PÓŁBUTY S3 TIGER', 'snap_code' => 'B20417', 'snap_price' => 89])->save();
        CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id, 'email' => 'klient@alfa.pl', 'source' => 'list', 'token' => str_repeat('a', 40),
            'status' => 'sent', 'sent_at' => now()->subDays(5), 'message_id' => 'abc123@supon.example.pl',
        ]);

        return [$campaign->fresh(), $author];
    }

    /** @return array<string, string> */
    private function mail(string $from, string $subject, DateTimeInterface $date): array
    {
        return ['from' => $from, 'subject' => $subject, 'date' => $date->format(DATE_RFC2822), 'message-id' => '<'.md5($from.$subject).'@x.pl>'];
    }
}
