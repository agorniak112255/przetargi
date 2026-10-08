<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\OfferComposeRequest;
use App\Models\OfferItem;
use App\Models\OfferSend;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Tests\Support\CampaignFixtures;
use Tests\TestCase;

/**
 * Forma oferty (05.10.2026): treść maila / tylko PDF / treść i PDF — zapis przy ofercie, podgląd krótkiego maila, PDF
 * do pobrania, załącznik w wysyłce (zapisany przy wysyłce) i PDF dla dodatku Thunderbirda.
 */
final class OfferPdfTest extends TestCase
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
        Storage::fake('local');
        Storage::fake('public');

        $this->author = $this->sender();
        $this->author->givePermissionTo('offers.use');
        $this->offer = Offer::query()->create(['user_id' => $this->author->id, 'subject' => 'Oferta na rękawice', 'valid_until' => '2026-10-31']);
        OfferItem::query()->create(['offer_id' => $this->offer->id, 'position' => 1, 'erp_item_id' => $this->erpItem('B20417', 40)->id, 'price_net' => 12.5]);
        $card = $this->card('KARTA-1', 'Rękawice nitrylowe');
        // prawdziwe zdjęcie karty — PDF dostaje je jako data URI (bez pobierania przez HTTP)
        Storage::disk('public')->put('products/KARTA-1.jpg', $this->jpeg());
        OfferItem::query()->create(['offer_id' => $this->offer->id, 'position' => 2, 'product_id' => $card->id, 'price_net' => 30]);
        Sanctum::actingAs($this->author);
    }

    private function jpeg(): string
    {
        $gd = imagecreatetruecolor(300, 200);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 255, 255, 255));
        imagefilledrectangle($gd, 50, 40, 250, 160, imagecolorallocate($gd, 20, 120, 200));
        ob_start();
        imagejpeg($gd);

        return (string) ob_get_clean();
    }

    private function delivery(string $delivery): void
    {
        $this->offer->forceFill(['delivery' => $delivery])->save();
    }

    /** @return list<DataPart> */
    private function attachments(Email $mail): array
    {
        return array_values($mail->getAttachments());
    }

    public function test_patch_saves_delivery_and_offer_shows_it(): void
    {
        $this->getJson("/api/offers/{$this->offer->id}")->assertOk()->assertJsonPath('delivery', 'body');

        $this->patchJson("/api/offers/{$this->offer->id}", ['delivery' => 'pdf'])->assertOk()->assertJsonPath('delivery', 'pdf');
        $this->assertSame('pdf', $this->offer->fresh()->delivery);
        $this->patchJson("/api/offers/{$this->offer->id}", ['delivery' => 'both'])->assertOk()->assertJsonPath('delivery', 'both');

        $this->patchJson("/api/offers/{$this->offer->id}", ['delivery' => 'fax'])->assertStatus(422)
            ->assertJsonPath('errors.delivery.0', 'Wybierz formę oferty z listy.');
        $this->patchJson("/api/offers/{$this->offer->id}", ['delivery' => null])->assertStatus(422);
        $this->assertSame('both', $this->offer->fresh()->delivery);
    }

    public function test_preview_for_pdf_is_a_short_mail_without_products(): void
    {
        $body = $this->getJson("/api/offers/{$this->offer->id}/preview")->assertOk()->json();
        $this->assertSame('body', $body['delivery']);
        $this->assertNull($body['pdf_filename']);
        $this->assertStringContainsString('Rękawice nitrylowe', $body['html']);

        $this->delivery('pdf');
        $short = $this->getJson("/api/offers/{$this->offer->id}/preview")->assertOk()->json();
        $this->assertSame('pdf', $short['delivery']);
        $this->assertSame('Oferta-OF-0001.pdf', $short['pdf_filename']);
        $this->assertSame('Oferta na rękawice', $short['subject']);
        foreach ([$short['html'], $short['text']] as $mail) {
            $this->assertStringContainsString('w załączeniu przesyłam ofertę OF-0001.', $mail);
            $this->assertStringNotContainsString('Rękawice nitrylowe', $mail);
            $this->assertStringNotContainsString('B20417', $mail);
            $this->assertStringNotContainsString('Ceny netto', $mail);
            $this->assertStringNotContainsString('Zapytaj', $mail);
        }
        $this->assertStringContainsString('Dzień dobry,', $short['text']);

        // wstęp handlowca zamiast domyślnego zdania
        $this->offer->forceFill(['intro' => 'Witam, oferta w pliku.'])->save();
        $withIntro = $this->getJson("/api/offers/{$this->offer->id}/preview")->assertOk()->json();
        $this->assertStringContainsString('Witam, oferta w pliku.', $withIntro['html']);
        $this->assertStringNotContainsString('w załączeniu przesyłam', $withIntro['html']);

        // treść i PDF — pełny mail
        $this->delivery('both');
        $both = $this->getJson("/api/offers/{$this->offer->id}/preview")->assertOk()->json();
        $this->assertSame('Oferta-OF-0001.pdf', $both['pdf_filename']);
        $this->assertStringContainsString('Rękawice nitrylowe', $both['html']);
        // od 08.10.2026 nad produktami tylko ważność oferty — „netto” stoi przy każdej cenie
        $this->assertStringContainsString('Oferta ważna do 31.10.2026', $both['html']);
        $this->assertStringNotContainsString('Ceny netto.', $both['html']);
    }

    public function test_pdf_download_with_images_and_polish_font(): void
    {
        $res = $this->get("/api/offers/{$this->offer->id}/pdf")->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="Oferta-OF-0001.pdf"', $res->headers->get('Content-Disposition'));
        $pdf = (string) $res->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        // DejaVu Sans (ą, ę, ś) zamiast standardowej Helveticy z maila
        $this->assertStringContainsString('DejaVuSans', $pdf);
        $this->assertStringNotContainsString('/Helvetica', $pdf);
        // baner z public/ i zdjęcie karty — dwa obrazki osadzone w pliku
        $this->assertSame(2, substr_count($pdf, '/Subtype /Image'));
    }

    public function test_pdf_embeds_mail_footer_images_from_public(): void
    {
        $this->author->forceFill(['mail_footer' => [
            'name' => 'Anna Nowak', 'position' => 'Handlowiec', 'mobile' => '600 903 483', 'phone' => null, 'email' => 'anna@supon.example.pl',
        ]])->save();

        $pdf = (string) $this->get("/api/offers/{$this->offer->id}/pdf")->assertOk()->getContent();

        // baner i zdjęcie karty (2) + logo stopki i trzy ikony (telefon, e-mail, www) z public/ jako data URI; grafiki
        // stopki to PNG z kanałem alfa — dompdf dokłada do każdej maskę przezroczystości (też /Subtype /Image): 2 + 4×2
        $this->assertSame(10, substr_count($pdf, '/Subtype /Image'));
        $this->assertSame(4, substr_count($pdf, '/SMask'));
    }

    public function test_long_offer_spans_pages_without_losing_rows(): void
    {
        // 30 wierszy cennika to trzy strony; zagnieżdżone tabele maila ucinały dompdf wszystko po drugiej stronie
        $this->offer->forceFill(['layout' => 'pricelist'])->save();
        for ($i = 3; $i <= 30; $i++) {
            OfferItem::query()->create(['offer_id' => $this->offer->id, 'position' => $i, 'erp_item_id' => $this->erpItem('P'.$i)->id, 'price_net' => $i]);
        }

        $pdf = (string) $this->get("/api/offers/{$this->offer->id}/pdf")->assertOk()->getContent();

        $this->assertGreaterThanOrEqual(3, preg_match_all('#/Type /Page\b#', $pdf));
    }

    public function test_pdf_requires_items_with_prices_and_own_offer(): void
    {
        OfferItem::query()->where('position', 2)->update(['price_net' => null]);
        $this->getJson("/api/offers/{$this->offer->id}/pdf")->assertStatus(422)
            ->assertJsonPath('errors.items.0', 'Jedna pozycja nie ma ceny — uzupełnij ją przed pobraniem PDF.');

        $empty = Offer::query()->create(['user_id' => $this->author->id, 'subject' => 'Pusta']);
        $this->getJson("/api/offers/{$empty->id}/pdf")->assertStatus(422)
            ->assertJsonPath('errors.items.0', 'Dodaj do oferty co najmniej jeden produkt.');

        // inna osoba bez podglądu cudzych ofert (administrator ma offers.view_all — widziałby ją, decyzja 06.10.2026)
        $other = User::factory()->withRole('handlowiec')->create();
        $other->givePermissionTo('offers.use');
        Sanctum::actingAs($other);
        $this->getJson("/api/offers/{$empty->id}/pdf")->assertNotFound();
    }

    public function test_pdf_errors_come_as_json_for_clients_asking_json_first(): void
    {
        // ekran oferty i dodatek Thunderbirda pytają „JSON, potem PDF” — błąd nie może skończyć się przekierowaniem
        // na stronę HTML, którą dodatek dołączyłby klientowi jako „Oferta.pdf”
        $headers = ['Accept' => 'application/json, application/pdf'];
        OfferItem::query()->where('position', 2)->update(['price_net' => null]);

        $this->get("/api/offers/{$this->offer->id}/pdf", $headers)->assertStatus(422)
            ->assertJsonPath('errors.items.0', 'Jedna pozycja nie ma ceny — uzupełnij ją przed pobraniem PDF.');

        $id = $this->postJson('/api/offers/compose', [
            'subject' => 'Oferta', 'body_html' => '<p>x</p>', 'offer_id' => $this->offer->id, 'attach_pdf' => true,
        ])->assertCreated()->json('id');
        $this->get('/api/offers/compose/'.$id.'/pdf', $headers)->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('errors.items.0', 'Jedna pozycja nie ma ceny — uzupełnij ją przed pobraniem PDF.');
    }

    public function test_pdf_delivery_sends_short_mail_with_saved_attachment(): void
    {
        $this->delivery('pdf');

        $res = $this->postJson("/api/offers/{$this->offer->id}/send", ['emails' => ['a@klient.pl', 'b@klient.pl']])->assertOk();

        $this->assertSame(['a@klient.pl', 'b@klient.pl', 'jan@supon.example.pl'], $this->mailers->recipients());
        $send = OfferSend::query()->sole();
        $this->assertSame('pdf', $send->delivery);
        $this->assertSame('offer-sends/'.$this->offer->id.'/'.$send->id.'.pdf', $send->pdf_path);
        $stored = Storage::disk('local')->get((string) $send->pdf_path);
        $this->assertStringStartsWith('%PDF', (string) $stored);

        foreach ($this->mailers->emails() as $mail) {
            // każdy mail i kopia dla nadawcy: ten sam zapisany PDF
            $files = $this->attachments($mail);
            $this->assertCount(1, $files);
            $this->assertSame('Oferta-OF-0001.pdf', $files[0]->getFilename());
            $this->assertSame('application/pdf', $files[0]->getContentType());
            $this->assertSame($stored, $files[0]->getBody());
            $this->assertStringContainsString('w załączeniu przesyłam ofertę OF-0001.', (string) $mail->getHtmlBody());
            $this->assertStringNotContainsString('Rękawice nitrylowe', (string) $mail->getHtmlBody());
            // wysyłka z aplikacji — podpis ze skrzynki
            $this->assertStringContainsString('tel. 600 000 000', (string) $mail->getHtmlBody());
        }
        $this->assertSame((string) $this->mailers->emails()[0]->getHtmlBody(), $send->html);

        $res->assertJsonPath('offer.sends.0.delivery', 'pdf')->assertJsonPath('offer.sends.0.has_pdf', true);
        $download = $this->get("/api/offers/{$this->offer->id}/sends/{$send->id}/pdf")->assertOk();
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        $this->assertSame($stored, $download->getContent());

        // oferta zmienia się dalej — PDF wysyłki zostaje taki, jaki dostał klient
        OfferItem::query()->where('position', 1)->update(['price_net' => 99]);
        $this->assertSame($stored, $this->get("/api/offers/{$this->offer->id}/sends/{$send->id}/pdf")->getContent());
    }

    public function test_both_delivery_sends_full_mail_with_attachment(): void
    {
        $this->delivery('both');

        $this->postJson("/api/offers/{$this->offer->id}/send", ['emails' => ['a@klient.pl']])->assertOk();

        $mail = $this->mailers->emails()[0];
        $this->assertCount(1, $this->attachments($mail));
        $this->assertStringContainsString('Rękawice nitrylowe', (string) $mail->getHtmlBody());
        $this->assertStringContainsString('12,50', (string) $mail->getHtmlBody());
        $this->assertSame('both', OfferSend::query()->sole()->delivery);
    }

    public function test_body_delivery_sends_without_attachment(): void
    {
        $res = $this->postJson("/api/offers/{$this->offer->id}/send", ['emails' => ['a@klient.pl']])->assertOk();

        foreach ($this->mailers->emails() as $mail) {
            $this->assertSame([], $this->attachments($mail));
        }
        $send = OfferSend::query()->sole();
        $this->assertSame('body', $send->delivery);
        $this->assertNull($send->pdf_path);
        $this->assertSame([], Storage::disk('local')->allFiles('offer-sends'));
        $res->assertJsonPath('offer.sends.0.delivery', 'body')->assertJsonPath('offer.sends.0.has_pdf', false);
        $this->get("/api/offers/{$this->offer->id}/sends/{$send->id}/pdf")->assertNotFound();
    }

    public function test_sent_pdf_of_another_offer_is_not_found(): void
    {
        $this->delivery('pdf');
        $this->postJson("/api/offers/{$this->offer->id}/send", ['emails' => ['a@klient.pl']])->assertOk();
        $send = OfferSend::query()->sole();
        $second = Offer::query()->create(['user_id' => $this->author->id, 'subject' => 'Druga']);

        $this->get("/api/offers/{$second->id}/sends/{$send->id}/pdf")->assertNotFound();

        // inna osoba bez podglądu cudzych ofert (administrator ma offers.view_all — widziałby ją, decyzja 06.10.2026)
        $other = User::factory()->withRole('handlowiec')->create();
        $other->givePermissionTo('offers.use');
        Sanctum::actingAs($other);
        $this->get("/api/offers/{$this->offer->id}/sends/{$send->id}/pdf")->assertNotFound();
    }

    public function test_thunderbird_request_with_pdf_gives_addon_a_pdf_url(): void
    {
        $id = $this->postJson('/api/offers/compose', [
            'subject' => 'Oferta na rękawice',
            'body_html' => '<p>Dzień dobry</p>',
            'offer_id' => $this->offer->id,
            'attach_pdf' => true,
        ])->assertCreated()->json('id');
        // bez PDF — wiersz dla dodatku bez pól pliku (jak dotąd)
        $plainId = $this->postJson('/api/offers/compose', ['subject' => 'Bez PDF', 'body_html' => '<p>x</p>', 'offer_id' => $this->offer->id])
            ->assertCreated()->json('id');

        $offers = collect($this->getJson('/api/inquiries/queued?with_offers=1')->assertOk()->json('offers'))->keyBy('id');
        $this->assertSame('/api/offers/compose/'.$id.'/pdf', $offers[$id]['pdf_url']);
        $this->assertSame('Oferta-OF-0001.pdf', $offers[$id]['pdf_filename']);
        $this->assertArrayNotHasKey('pdf_url', $offers[$plainId]);
        $this->assertArrayNotHasKey('pdf_filename', $offers[$plainId]);

        $pdf = $this->get('/api/offers/compose/'.$id.'/pdf')->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="Oferta-OF-0001.pdf"', $pdf->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        $this->get('/api/offers/compose/'.$plainId.'/pdf')->assertNotFound();

        // usunięta oferta — prośba zostaje, ale bez pliku
        $this->offer->delete();
        $this->assertNull(OfferComposeRequest::query()->findOrFail($id)->offer_id);
        $this->get('/api/offers/compose/'.$id.'/pdf')->assertNotFound();
    }

    public function test_thunderbird_request_rejects_someone_elses_offer(): void
    {
        $other = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($other);

        $this->postJson('/api/offers/compose', [
            'subject' => 'Cudza', 'body_html' => '<p>x</p>', 'offer_id' => $this->offer->id, 'attach_pdf' => true,
        ])->assertStatus(422)->assertJsonPath('errors.offer_id.0', 'Tej oferty nie ma na Twojej liście — odśwież stronę.');
        $this->assertSame(0, OfferComposeRequest::query()->count());

        // PDF z cudzej prośby — jak każda cudza prośba
        Sanctum::actingAs($this->author);
        $id = $this->postJson('/api/offers/compose', ['subject' => 'S', 'body_html' => '<p>x</p>', 'offer_id' => $this->offer->id, 'attach_pdf' => true])->json('id');
        Sanctum::actingAs($other);
        $this->get('/api/offers/compose/'.$id.'/pdf')->assertForbidden();
    }

    public function test_attach_pdf_without_offer_is_ignored(): void
    {
        $id = $this->postJson('/api/offers/compose', ['subject' => 'Z karty', 'body_html' => '<p>x</p>', 'attach_pdf' => true])
            ->assertCreated()->json('id');

        $this->assertFalse(OfferComposeRequest::query()->findOrFail($id)->attach_pdf);
        $this->assertArrayNotHasKey('pdf_url', $this->getJson('/api/inquiries/queued?with_offers=1')->json('offers.0'));
    }
}
