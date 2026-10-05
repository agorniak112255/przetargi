<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EmailSuppression;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\OfferRecipient;
use App\Models\OfferSend;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\Campaigns\CampaignSender;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\Support\CampaignFixtures;
use Tests\TestCase;

/** Wysyłka oferty: osobny mail na adres, kopia dla nadawcy, zapis wysyłki, walidacja i błędy skrzynki. */
final class OfferSendingTest extends TestCase
{
    use CampaignFixtures;
    use RefreshDatabase;

    private User $author;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::findOrCreate('offers.use', 'web');
        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(10, 0));
        $this->setUpCampaigns();

        $this->author = $this->sender();
        $this->author->givePermissionTo('offers.use');
        $this->offer = Offer::query()->create(['user_id' => $this->author->id, 'subject' => 'Oferta na rękawice', 'valid_until' => '2026-10-31']);
        OfferItem::query()->create(['offer_id' => $this->offer->id, 'position' => 1, 'erp_item_id' => $this->erpItem('B20417', 40)->id, 'price_net' => 12.5]);
        OfferItem::query()->create(['offer_id' => $this->offer->id, 'position' => 2, 'product_id' => $this->card('KARTA-1', 'Rękawice nitrylowe')->id, 'price_net' => 30]);
        Sanctum::actingAs($this->author);
    }

    /** @param  list<string>  $emails */
    private function send(array $emails): TestResponse
    {
        return $this->postJson("/api/offers/{$this->offer->id}/send", ['emails' => $emails]);
    }

    public function test_sends_separate_mail_per_address_saves_send_and_copies_sender(): void
    {
        $res = $this->send(['a@klient.pl', 'B@Klient.pl'])->assertOk();

        $this->assertSame([
            ['email' => 'a@klient.pl', 'status' => 'sent', 'error' => null],
            ['email' => 'B@Klient.pl', 'status' => 'sent', 'error' => null],
        ], $res->json('results'));
        // osobny mail do każdego adresu, na końcu kopia do nadawcy
        $this->assertSame(['a@klient.pl', 'B@Klient.pl', 'jan@supon.example.pl'], $this->mailers->recipients());
        $emails = $this->mailers->emails();
        foreach ([$emails[0], $emails[1]] as $mail) {
            $this->assertCount(1, $mail->getTo());
            $this->assertSame('Oferta na rękawice', $mail->getSubject());
            $this->assertSame('jan@supon.example.pl', $mail->getFrom()[0]->getAddress());
            // oferta do klienta — bez nagłówków wypisu z mailingu
            $this->assertFalse($mail->getHeaders()->has('List-Unsubscribe'));
            $this->assertStringNotContainsString('/api/wypis/', (string) $mail->getHtmlBody());
            $this->assertStringContainsString('Rękawice nitrylowe', (string) $mail->getHtmlBody());
            $this->assertStringContainsString('12,50', (string) $mail->getHtmlBody());
        }
        $this->assertSame('[Kopia] Oferta na rękawice', $emails[2]->getSubject());
        $this->assertStringContainsString('Kopia dla Ciebie — wysłano do: a@klient.pl, B@Klient.pl', (string) $emails[2]->getHtmlBody());
        $this->assertStringNotContainsString('Kopia dla Ciebie', (string) $emails[0]->getHtmlBody());

        // zapis wysyłki: dokładnie to, co dostał klient
        $send = OfferSend::query()->sole();
        $this->assertSame('Oferta na rękawice', $send->subject);
        $this->assertSame((string) $emails[0]->getHtmlBody(), $send->html);
        $this->assertSame((string) $emails[0]->getTextBody(), $send->text);
        $rows = OfferRecipient::query()->orderBy('id')->get();
        $this->assertSame(['a@klient.pl', 'B@Klient.pl'], $rows->pluck('email')->all());
        $this->assertSame(['sent'], $rows->pluck('status')->unique()->values()->all());
        $this->assertSame([$send->id], $rows->pluck('offer_send_id')->unique()->values()->all());
        $this->assertNotNull($rows[0]->sent_at);
        $this->assertNotNull($rows[0]->message_id);

        $this->assertSame('2026-10-05 10:00:00', $this->offer->fresh()->last_sent_at->format('Y-m-d H:i:s'));
        $this->assertSame($send->id, $res->json('offer.sends.0.id'));
        $this->assertSame('a@klient.pl', $res->json('offer.sends.0.recipients.0.email'));
        $this->assertSame(2, $this->getJson('/api/offers')->json('data.0.recipients_count'));
    }

    public function test_send_does_not_move_updated_at(): void
    {
        $before = $this->offer->fresh()->updated_at->format('Y-m-d H:i:s');
        $this->travel(1)->hours();

        $this->send(['a@klient.pl'])->assertOk();

        $this->assertSame('2026-10-05 11:00:00', $this->offer->fresh()->last_sent_at->format('Y-m-d H:i:s'));
        $this->assertSame($before, $this->offer->fresh()->updated_at->format('Y-m-d H:i:s'));
    }

    public function test_past_valid_until_blocks_sending(): void
    {
        $this->offer->forceFill(['valid_until' => '2026-10-04'])->save();

        $this->send(['a@klient.pl'])->assertStatus(422)
            ->assertJsonPath('errors.valid_until.0', 'Data „Oferta ważna do” (04.10.2026) już minęła — zmień ją albo wyczyść pole.');
        $this->assertSame([], $this->mailers->recipients());
        $this->assertSame(0, OfferSend::query()->count());

        // dzisiejsza data jest jeszcze ważna
        $this->offer->forceFill(['valid_until' => '2026-10-05'])->save();
        $this->send(['a@klient.pl'])->assertOk();
    }

    public function test_missing_price_blocks_sending(): void
    {
        OfferItem::query()->where('position', 2)->update(['price_net' => null]);

        $this->send(['a@klient.pl'])->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame([], $this->mailers->recipients());
        $this->assertSame(0, OfferSend::query()->count());
    }

    public function test_offer_without_products_or_subject_is_not_sent(): void
    {
        $this->offer->forceFill(['subject' => ''])->save();
        $this->send(['a@klient.pl'])->assertStatus(422)->assertJsonValidationErrors('subject');

        OfferItem::query()->delete();
        $this->send(['a@klient.pl'])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->assertSame([], $this->mailers->recipients());
    }

    public function test_suppressed_address_blocks_sending(): void
    {
        EmailSuppression::query()->create(['email' => 'wypisany@klient.pl', 'reason' => EmailSuppression::REASON_UNSUBSCRIBE]);

        $res = $this->send(['a@klient.pl', 'Wypisany@Klient.pl'])->assertStatus(422)->assertJsonValidationErrors('emails');

        $this->assertStringContainsString('Wypisany@Klient.pl', $res->json('errors.emails.0'));
        $this->assertStringContainsString('wypisał się', $res->json('errors.emails.0'));
        $this->assertSame([], $this->mailers->recipients());
        $this->assertSame(0, OfferSend::query()->count());
    }

    public function test_address_validation(): void
    {
        $this->send([])->assertStatus(422)->assertJsonValidationErrors('emails');
        $this->send(['to-nie-adres'])->assertStatus(422)->assertJsonValidationErrors('emails');
        $this->send(["a@klient.pl\r\nBcc: x@y.pl"])->assertStatus(422)->assertJsonValidationErrors('emails');
        $this->send(['a@klient.pl', 'A@KLIENT.PL'])->assertStatus(422)->assertJsonValidationErrors('emails');
        config(['offers.max_recipients' => 2]);
        $this->send(['a@klient.pl', 'b@klient.pl', 'c@klient.pl'])->assertStatus(422)->assertJsonValidationErrors('emails');

        $this->assertSame([], $this->mailers->recipients());
        $this->assertSame(0, OfferSend::query()->count());
    }

    public function test_no_mailbox_paused_mailbox_and_missing_public_url_block_sending(): void
    {
        Cache::put(CampaignSender::pauseKey((int) $this->author->id), 'Logowanie nieudane', now()->addMinutes(15));
        $this->send(['a@klient.pl'])->assertStatus(422)->assertJsonValidationErrors('offer');
        Cache::forget(CampaignSender::pauseKey((int) $this->author->id));

        config(['campaigns.public_url' => '']);
        $this->send(['a@klient.pl'])->assertStatus(422)->assertJsonValidationErrors('offer');
        config(['campaigns.public_url' => 'https://przetargi.example.pl']);

        UserMailAccount::query()->where('user_id', $this->author->id)->delete();
        $res = $this->send(['a@klient.pl'])->assertStatus(422)->assertJsonValidationErrors('offer');
        $this->assertStringContainsString('Moja poczta', $res->json('errors.offer.0'));

        $this->assertSame([], $this->mailers->recipients());
        $this->assertSame(0, OfferSend::query()->count());
    }

    public function test_send_in_progress_is_rejected(): void
    {
        $lock = Cache::lock('offer-send:'.$this->offer->id, 120);
        $this->assertTrue($lock->get());

        $this->send(['a@klient.pl'])->assertStatus(422)->assertJsonValidationErrors('offer');

        $lock->release();
        $this->send(['a@klient.pl'])->assertOk();
    }

    public function test_mailbox_error_skips_remaining_addresses_and_pauses_sender(): void
    {
        $this->mailers->transport->failFor['b@klient.pl'] = new TransportException('Connection to "smtp.example.pl:587" timed out (tajne-haslo-123).');

        $res = $this->send(['a@klient.pl', 'b@klient.pl', 'c@klient.pl'])->assertOk();

        $results = $res->json('results');
        $this->assertSame(['sent', 'failed', 'skipped'], array_column($results, 'status'));
        // hasło nie trafia do komunikatu
        $this->assertStringNotContainsString('tajne-haslo-123', (string) $results[1]['error']);
        $this->assertStringContainsString('timed out', (string) $results[1]['error']);
        $this->assertSame('Nie wysłano — błąd skrzynki nadawcy', $results[2]['error']);
        $this->assertTrue(CampaignSender::isPaused((int) $this->author->id));
        // po błędzie skrzynki bez kopii — wyszedł tylko pierwszy mail
        $this->assertSame(['a@klient.pl'], $this->mailers->recipients());
        $this->assertSame(['sent', 'failed', 'skipped'], OfferRecipient::query()->orderBy('id')->pluck('status')->all());
        $this->assertNotNull($this->offer->fresh()->last_sent_at);
    }

    public function test_address_error_does_not_stop_other_addresses(): void
    {
        $this->mailers->transport->failFor['zly@klient.pl'] = new UnexpectedResponseException('Expected response code "250/251/252" but got code "550", with message "550 5.1.1 <zly@klient.pl>: Recipient address rejected: User unknown".', 550);

        $res = $this->send(['zly@klient.pl', 'a@klient.pl'])->assertOk();

        $this->assertSame(['failed', 'sent'], array_column($res->json('results'), 'status'));
        $this->assertStringContainsString('User unknown', (string) $res->json('results.0.error'));
        $this->assertFalse(CampaignSender::isPaused((int) $this->author->id));
        $this->assertSame(['a@klient.pl', 'jan@supon.example.pl'], $this->mailers->recipients());
        $this->assertStringContainsString('wysłano do: a@klient.pl', (string) $this->mailers->emails()[1]->getHtmlBody());
    }

    public function test_all_addresses_failed_leaves_offer_not_sent(): void
    {
        $this->mailers->transport->failFor['zly@klient.pl'] = new UnexpectedResponseException('Expected response code "250/251/252" but got code "550", with message "550 5.1.1 User unknown".', 550);

        $this->send(['zly@klient.pl'])->assertOk()->assertJsonPath('results.0.status', 'failed');

        $this->assertNull($this->offer->fresh()->last_sent_at);
        // bez udanej wysyłki nie ma kopii
        $this->assertSame([], $this->mailers->recipients());
        $this->assertSame(1, OfferSend::query()->count());
    }
}
