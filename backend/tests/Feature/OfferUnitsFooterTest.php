<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\OfferSend;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\Campaigns\CampaignRenderer;
use App\Services\Offers\OfferRenderer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\CampaignFixtures;
use Tests\TestCase;

/**
 * Uwagi handlowca do ofert (08.10.2026): jednostka ceny pozycji (para, opak., karton), ceny w mailu netto albo brutto,
 * rozmiary pozycji i stopka maila pracownika z „Moje konto” (zamiast podpisu pod ofertą i kampanią).
 */
final class OfferUnitsFooterTest extends TestCase
{
    use CampaignFixtures;
    use RefreshDatabase;

    private const FOOTER = [
        'name' => 'Anna Nowak',
        'position' => 'Specjalista ds. sprzedaży',
        'mobile' => '600 903 483',
        'phone' => '(17) 860-28-49',
        'email' => 'anna@supon.example.pl',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::findOrCreate('offers.use', 'web');
        Permission::findOrCreate('inspections.offer', 'web');
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(10, 0));
        $this->setUpCampaigns();
    }

    /** @param  array<string, mixed>  $account */
    private function author(array $account = []): User
    {
        $user = $this->sender($account);
        $user->givePermissionTo('offers.use');

        return $user->fresh();
    }

    /** @param  array<string, mixed>  $attrs */
    private function offerWith(User $user, array $attrs, float $price = 100): Offer
    {
        $offer = Offer::query()->create(['user_id' => $user->id, 'subject' => 'Oferta BHP', 'layout' => 'grid3', 'valid_until' => '2026-10-31']);
        OfferItem::query()->create(['offer_id' => $offer->id, 'position' => 1, 'price_net' => $price, ...$attrs]);

        return $offer;
    }

    public function test_price_unit_in_mail_while_stock_stays_in_xl_unit(): void
    {
        $user = $this->author();
        // buty: XL liczy w parach („par”), koszt 50 zł za parę
        $boots = $this->erpItem('B20417', 144, ['unit' => 'par', 'stock_value' => 7200]);
        $offer = $this->offerWith($user, ['erp_item_id' => $boots->id], 60);
        $item = $offer->items()->firstOrFail();
        Sanctum::actingAs($user);

        // bez wyboru: jednostka XL
        $row = $this->getJson("/api/offers/{$offer->id}")->assertOk()->json('items.0');
        $this->assertNull($row['price_unit']);
        $this->assertSame('par', $row['price_unit_label']);
        $this->assertFalse($row['unit_mismatch']);

        $row = $this->patchJson("/api/offers/{$offer->id}/items/{$item->id}", ['price_unit' => 'para'])->assertOk()->json('items.0');
        $this->assertSame('para', $row['price_unit']);
        $this->assertSame('para', $row['price_unit_label']);
        // „par” w XL to ta sama jednostka co „para”
        $this->assertFalse($row['unit_mismatch']);
        $this->assertSame('para', $item->fresh()->price_unit);

        $preview = $this->getJson("/api/offers/{$offer->id}/preview")->assertOk()->json();
        $this->assertStringContainsString('netto / para', $preview['html']);
        $this->assertStringContainsString("60,00\u{00A0}zł netto / para", $preview['text']);
        // stan w jednostce XL — tak liczy go magazyn
        $this->assertStringContainsString('Na stanie: 144 par (29.09)', $preview['html']);
        $this->assertStringContainsString('Na stanie: 144 par (29.09)', $preview['text']);

        // karton przy koszcie za parę: cena 40 zł „poniżej kosztu” 50 zł to porównanie innych jednostek — bez ostrzeżenia
        $this->patchJson("/api/offers/{$offer->id}/items/{$item->id}", ['price_net' => 40])->assertOk()
            ->assertJsonPath('items.0.warnings.below_cost', true);
        $row = $this->patchJson("/api/offers/{$offer->id}/items/{$item->id}", ['price_unit' => 'karton'])->assertOk()->json('items.0');
        $this->assertTrue($row['unit_mismatch']);
        $this->assertSame('karton', $row['price_unit_label']);
        $this->assertFalse($row['warnings']['below_cost']);
        $this->assertStringContainsString('netto / karton', $this->getJson("/api/offers/{$offer->id}/preview")->json('html'));

        // opakowanie ma kropkę w mailu; sztuka bez kropki
        $this->patchJson("/api/offers/{$offer->id}/items/{$item->id}", ['price_unit' => 'opak'])->assertOk()
            ->assertJsonPath('items.0.price_unit_label', 'opak.');
        $this->patchJson("/api/offers/{$offer->id}/items/{$item->id}", ['price_unit' => 'szt'])->assertOk()
            ->assertJsonPath('items.0.price_unit_label', 'szt');

        // powrót do jednostki XL
        $this->patchJson("/api/offers/{$offer->id}/items/{$item->id}", ['price_unit' => null])->assertOk()
            ->assertJsonPath('items.0.price_unit', null)
            ->assertJsonPath('items.0.price_unit_label', 'par');

        $this->patchJson("/api/offers/{$offer->id}/items/{$item->id}", ['price_unit' => 'kg'])->assertStatus(422)
            ->assertJsonPath('errors.price_unit.0', 'Wybierz jednostkę ceny z listy.');
        $this->assertNull($item->fresh()->price_unit);
    }

    public function test_card_without_xl_item_compares_unit_with_piece(): void
    {
        $user = $this->author();
        $card = $this->card('KARTA-1', 'Rękawice nitrylowe');
        $offer = $this->offerWith($user, ['product_id' => $card->id]);
        $item = $offer->items()->firstOrFail();
        Sanctum::actingAs($user);

        $row = $this->getJson("/api/offers/{$offer->id}")->json('items.0');
        $this->assertSame('szt', $row['price_unit_label']);
        $this->assertFalse($row['unit_mismatch']);
        $this->patchJson("/api/offers/{$offer->id}/items/{$item->id}", ['price_unit' => 'szt'])->assertJsonPath('items.0.unit_mismatch', false);
        $this->patchJson("/api/offers/{$offer->id}/items/{$item->id}", ['price_unit' => 'para'])->assertJsonPath('items.0.unit_mismatch', true);
    }

    public function test_gross_prices_in_mail_and_price_mode_validation(): void
    {
        $user = $this->author();
        $offer = $this->offerWith($user, ['erp_item_id' => $this->erpItem('A1', 10)->id], 299.25);
        Sanctum::actingAs($user);

        $res = $this->getJson("/api/offers/{$offer->id}")->assertOk();
        $this->assertSame('net', $res->json('price_mode'));
        $this->assertEquals(23, $res->json('vat_percent'));
        // brutto liczone na backendzie niezależnie od trybu
        $this->assertEquals(round(299.25 * 1.23, 2), $res->json('items.0.price_gross'));
        $this->assertEquals(299.25, $res->json('items.0.price_net'));

        $this->patchJson("/api/offers/{$offer->id}", ['price_mode' => 'gross'])->assertOk()->assertJsonPath('price_mode', 'gross');
        $this->assertSame('gross', $offer->fresh()->price_mode);
        $gross = number_format(round(299.25 * 1.23, 2), 2, ',', "\u{00A0}")."\u{00A0}zł";

        $preview = $this->getJson("/api/offers/{$offer->id}/preview")->assertOk()->json();
        foreach ([$preview['html'], $preview['text']] as $body) {
            $this->assertStringContainsString('Ceny brutto (z VAT 23%). Oferta ważna do 31.10.2026', $body);
            $this->assertStringContainsString($gross, $body);
            $this->assertStringNotContainsString("299,25\u{00A0}zł", $body);
            $this->assertStringNotContainsString('netto', $body);
        }
        $this->assertStringContainsString('brutto / szt', $preview['html']);
        $this->assertStringContainsString($gross.' brutto / szt', $preview['text']);
        // cennik: nagłówek kolumny ceny
        $this->patchJson("/api/offers/{$offer->id}", ['layout' => 'pricelist'])->assertOk();
        $html = $this->getJson("/api/offers/{$offer->id}/preview")->json('html');
        $this->assertStringContainsString('>Cena brutto</td>', $html);
        $this->assertStringNotContainsString('Cena netto', $html);
        // edytor dalej pokazuje netto — wpisane przez handlowca
        $this->assertEquals(299.25, $this->getJson("/api/offers/{$offer->id}")->json('items.0.price_net'));

        // bez daty ważności: przy brutto sama informacja o cenach, przy netto — bez linii
        $this->patchJson("/api/offers/{$offer->id}", ['valid_until' => null])->assertOk();
        $preview = $this->getJson("/api/offers/{$offer->id}/preview")->json();
        $this->assertStringContainsString('Ceny brutto (z VAT 23%).', $preview['html']);
        $this->assertStringNotContainsString('Oferta ważna', $preview['html']);
        $this->patchJson("/api/offers/{$offer->id}", ['price_mode' => 'net', 'layout' => 'grid3'])->assertOk();
        $preview = $this->getJson("/api/offers/{$offer->id}/preview")->json();
        foreach ([$preview['html'], $preview['text']] as $body) {
            $this->assertStringNotContainsString('Ceny', $body);
            $this->assertStringNotContainsString('Oferta ważna', $body);
            $this->assertStringContainsString("299,25\u{00A0}zł", $body);
        }
        // pusta linia ważności nie zostawia pustego wiersza nad produktami
        $this->assertStringNotContainsString('padding:12px 24px 0;', $preview['html']);
        $this->assertStringContainsString("Oferta BHP\n\n* Towar A1", "Oferta BHP\n\n".ltrim(explode("\n\n", $preview['text'], 2)[1]));

        $this->patchJson("/api/offers/{$offer->id}", ['price_mode' => 'vat'])->assertStatus(422)
            ->assertJsonPath('errors.price_mode.0', 'Wybierz ceny netto albo brutto.');
        $this->patchJson("/api/offers/{$offer->id}", ['price_mode' => null])->assertStatus(422);
        $this->assertSame('net', $offer->fresh()->price_mode);
    }

    public function test_inspection_offer_has_no_price_mode(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $user->givePermissionTo('inspections.offer');
        $offer = Offer::query()->create(['user_id' => $user->id, 'subject' => 'Przegląd', 'kind' => Offer::KIND_INSPECTION, 'customer_xl_gid' => 5]);
        Sanctum::actingAs($user);

        $this->patchJson("/api/offers/{$offer->id}", ['price_mode' => 'gross'])->assertStatus(422)
            ->assertJsonPath('errors.price_mode.0', 'Oferta przeglądu nie ma cen.');
        $this->assertSame('net', $offer->fresh()->price_mode);
    }

    public function test_sizes_line_replaces_stock_and_size_choices_come_from_card(): void
    {
        $user = $this->author();
        $card = $this->card('KURTKA-1', 'Kurtka ocieplana');
        // rozmiary karty z dwóch kont dostawcy (ten sam rozmiar raz), usunięty pominięty, wersja to nie rozmiar
        foreach ([['XXXL', 3, null], ['S', 1, null], ['M', 2, null], ['S', 5, null], ['XL', 4, now()]] as $i => [$label, $order, $removed]) {
            ProductVariant::query()->create([
                'product_id' => $card->id, 'kind' => ProductVariant::KIND_SIZE, 'source' => 'b2b:'.($i % 2 + 1), 'remote_id' => 'R'.$i,
                'label' => $label, 'sort_order' => $order, 'removed_at' => $removed,
            ]);
        }
        ProductVariant::query()->create([
            'product_id' => $card->id, 'kind' => ProductVariant::KIND_VERSION, 'source' => 'b2b:1', 'remote_id' => 'V1', 'label' => 'A4 folia', 'sort_order' => 0,
        ]);
        $xl = $this->erpItem('K100', 25);
        $offer = $this->offerWith($user, ['erp_item_id' => $xl->id, 'product_id' => $card->id], 80);
        $item = $offer->items()->firstOrFail();
        Sanctum::actingAs($user);

        $row = $this->getJson("/api/offers/{$offer->id}")->json('items.0');
        $this->assertSame(['S', 'M', 'XXXL'], $row['size_choices']);
        $this->assertNull($row['sizes']);
        $this->assertStringContainsString('Na stanie: 25 szt', $this->getJson("/api/offers/{$offer->id}/preview")->json('html'));

        $this->patchJson("/api/offers/{$offer->id}/items/{$item->id}", ['sizes' => '  S, XXXL  '])->assertOk()
            ->assertJsonPath('items.0.sizes', 'S, XXXL');
        $preview = $this->getJson("/api/offers/{$offer->id}/preview")->json();
        foreach ([$preview['html'], $preview['text']] as $body) {
            $this->assertStringContainsString('Rozmiary: S, XXXL', $body);
            // stan XL dotyczy całego towaru, nie wybranych rozmiarów
            $this->assertStringNotContainsString('Na stanie', $body);
        }
        $this->patchJson("/api/offers/{$offer->id}", ['layout' => 'pricelist'])->assertOk();
        $html = $this->getJson("/api/offers/{$offer->id}/preview")->json('html');
        $this->assertStringContainsString('Rozmiary: S, XXXL', $html);
        $this->assertStringNotContainsString('25 szt', $html);

        $this->patchJson("/api/offers/{$offer->id}/items/{$item->id}", ['sizes' => "S\nM"])->assertStatus(422)
            ->assertJsonPath('errors.sizes.0', 'Rozmiary wpisz w jednej linii.');
        $this->patchJson("/api/offers/{$offer->id}/items/{$item->id}", ['sizes' => str_repeat('S', 201)])->assertStatus(422)
            ->assertJsonPath('errors.sizes.0', 'Rozmiary mogą mieć najwyżej 200 znaków.');
        $this->assertSame('S, XXXL', $item->fresh()->sizes);
        $this->patchJson("/api/offers/{$offer->id}/items/{$item->id}", ['sizes' => '   '])->assertOk()->assertJsonPath('items.0.sizes', null);
        $this->assertNull($item->fresh()->sizes);

        // karta bez rozmiarów
        $plain = $this->offerWith($user, ['product_id' => $this->card('KASK', 'Kask')->id]);
        $this->assertSame([], $this->getJson("/api/offers/{$plain->id}")->json('items.0.size_choices'));
    }

    public function test_mail_footer_api_hints_save_validation_and_delete(): void
    {
        $user = $this->author();
        Sanctum::actingAs($user);

        // bez zapisanej stopki: podpowiedzi z konta i skrzynki, podgląd z podpowiedzi
        $res = $this->getJson('/api/me/mail-footer')->assertOk();
        $this->assertFalse($res->json('saved'));
        $this->assertSame(['name' => 'Jan Handlowiec', 'position' => null, 'mobile' => null, 'phone' => null, 'email' => 'jan@supon.example.pl'], $res->json('footer'));
        $this->assertStringStartsWith('<!DOCTYPE html>', $res->json('preview_html'));
        $this->assertStringContainsString('Jan Handlowiec', $res->json('preview_html'));
        $this->assertStringNotContainsString('<table role="presentation" width="640"', $res->json('preview_html'));

        $res = $this->putJson('/api/me/mail-footer', [...self::FOOTER, 'position' => '  Specjalista   ds. sprzedaży '])->assertOk();
        $this->assertTrue($res->json('saved'));
        $this->assertSame(self::FOOTER, $res->json('footer'));
        $this->assertSame(self::FOOTER, $user->fresh()->mail_footer);
        $html = $res->json('preview_html');
        $this->assertStringContainsString('href="tel:600903483"', $html);
        $this->assertStringContainsString('href="tel:178602849"', $html);
        $this->assertStringContainsString('(17) 860-28-49', $html);
        $this->assertStringContainsString('href="mailto:anna@supon.example.pl"', $html);
        $this->assertStringContainsString('href="https://www.supon.rzeszow.pl"', $html);
        $this->assertStringContainsString('>www.supon.rzeszow.pl<', str_replace(["\n", ' '], '', $html));
        $this->assertStringContainsString('https://przetargi.example.pl/campaign/footer-logo.png', $html);
        $this->assertStringContainsString('PHT SUPON Sp. z o.o. · ul. Miłocińska 17, 35-232 Rzeszów', $html);
        // pasek „Sprawdź: Promocje | Outlet | Blog” usunięty ze stopki (prośba właściciela 08.10.2026)
        $this->assertStringNotContainsString('259-outlet-bhp', $html);
        $this->assertStringNotContainsString('Sprawdź:', $html);
        $this->assertSame(self::FOOTER, $this->getJson('/api/me/mail-footer')->json('footer'));
        // dane stopki nie wychodzą w danych konta
        $this->assertArrayNotHasKey('mail_footer', $user->fresh()->toArray());

        // walidacja: imię wymagane, gdy coś wpisano; telefon tylko cyfry i znaki; adres e-mail
        $this->putJson('/api/me/mail-footer', ['name' => '', 'mobile' => '600 903 483'])->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Wpisz imię i nazwisko.');
        $this->putJson('/api/me/mail-footer', [...self::FOOTER, 'mobile' => '600 abc'])->assertStatus(422)
            ->assertJsonPath('errors.mobile.0', 'Telefon może mieć tylko cyfry, spacje i znaki + ( ) / - .');
        $this->putJson('/api/me/mail-footer', [...self::FOOTER, 'email' => 'anna@'])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Wpisz poprawny adres e-mail.');
        $this->putJson('/api/me/mail-footer', [...self::FOOTER, 'name' => str_repeat('a', 101)])->assertStatus(422);
        $this->putJson('/api/me/mail-footer', [...self::FOOTER, 'name' => "Anna\nNowak"])->assertStatus(422);
        $this->assertSame(self::FOOTER, $user->fresh()->mail_footer);

        // treść escapowana
        $html = $this->putJson('/api/me/mail-footer', [...self::FOOTER, 'name' => '<b>Anna</b>'])->assertOk()->json('preview_html');
        $this->assertStringContainsString('&lt;b&gt;Anna&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Anna</b>', $html);

        // wszystkie pola puste = stopka wyłączona
        $res = $this->putJson('/api/me/mail-footer', ['name' => '', 'position' => null, 'mobile' => '', 'phone' => null, 'email' => ''])->assertOk();
        $this->assertFalse($res->json('saved'));
        $this->assertNull($user->fresh()->mail_footer);

        $this->putJson('/api/me/mail-footer', self::FOOTER)->assertOk();
        $res = $this->deleteJson('/api/me/mail-footer')->assertOk();
        $this->assertFalse($res->json('saved'));
        $this->assertSame('Jan Handlowiec', $res->json('footer.name'));
        $this->assertNull($user->fresh()->mail_footer);

        // bez skrzynki: podpowiedź adresu z konta
        UserMailAccount::query()->where('user_id', $user->id)->delete();
        $this->assertSame((string) $user->email, $this->getJson('/api/me/mail-footer')->json('footer.email'));
    }

    public function test_mail_footer_requires_mail_permission(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/me/mail-footer')->assertForbidden();
        $this->putJson('/api/me/mail-footer', self::FOOTER)->assertForbidden();
        $this->deleteJson('/api/me/mail-footer')->assertForbidden();
    }

    public function test_sent_offer_has_footer_instead_of_signature_but_preview_does_not(): void
    {
        $user = $this->author(['copy_to_self' => true]);
        $user->forceFill(['mail_footer' => self::FOOTER])->save();
        $offer = $this->offerWith($user, ['erp_item_id' => $this->erpItem('A1', 10)->id]);
        Sanctum::actingAs($user);

        // podgląd = kopia do Thunderbirda — bez podpisu i bez stopki (program pocztowy doda własny podpis)
        $preview = $this->getJson("/api/offers/{$offer->id}/preview")->json();
        foreach ([$preview['html'], $preview['text']] as $body) {
            $this->assertStringNotContainsString('Anna Nowak', $body);
            $this->assertStringNotContainsString('footer-logo.png', $body);
        }

        $this->postJson("/api/offers/{$offer->id}/send", ['emails' => ['a@klient.pl']])->assertOk();
        $send = OfferSend::query()->firstOrFail();
        $emails = $this->mailers->emails();
        // do klienta i kopia dla nadawcy — ze stopką zamiast zwykłego podpisu
        $this->assertCount(2, $emails);
        foreach ([[(string) $send->html, (string) $send->text], ...array_map(static fn ($m): array => [(string) $m->getHtmlBody(), (string) $m->getTextBody()], $emails)] as [$html, $text]) {
            $this->assertStringContainsString('Anna Nowak', $html);
            $this->assertStringContainsString('href="tel:600903483"', $html);
            $this->assertStringContainsString('src="https://przetargi.example.pl/campaign/footer-phone.png"', $html);
            $this->assertStringNotContainsString('tel. 600 000 000', $html);
            $this->assertStringContainsString("Anna Nowak\nSpecjalista ds. sprzedaży\ntel. 600 903 483 / (17) 860-28-49\ne-mail: anna@supon.example.pl\nwww: www.supon.rzeszow.pl\nPHT SUPON Sp. z o.o. · ul. Miłocińska 17, 35-232 Rzeszów", $text);
            $this->assertStringNotContainsString('Sprawdź:', $text);
            $this->assertStringNotContainsString('Sprawdź:', $html);
            $this->assertStringNotContainsString('tel. 600 000 000', $text);
        }
    }

    public function test_campaign_mail_has_footer_and_falls_back_to_text_labels_without_public_url(): void
    {
        $author = $this->sender();
        $campaign = $this->campaign($author, [$this->erpItem('B20417', 40)]);
        $renderer = app(CampaignRenderer::class);

        // bez stopki — zwykły podpis jak dotąd
        $mail = $renderer->render($campaign);
        $this->assertStringContainsString('tel. 600 000 000', $mail['html']);
        $this->assertStringNotContainsString('footer-logo.png', $mail['html']);

        $author->forceFill(['mail_footer' => [...self::FOOTER, 'phone' => null, 'position' => null]])->save();
        $mail = $renderer->render($campaign->fresh());
        $this->assertStringContainsString('Anna Nowak', $mail['html']);
        $this->assertStringContainsString('Na stanie: 40 szt', $mail['html']);
        $this->assertStringContainsString('netto / szt', $mail['html']);
        $this->assertStringNotContainsString('tel. 600 000 000', $mail['html']);
        $this->assertStringNotContainsString('Specjalista', $mail['html']);
        $this->assertStringNotContainsString('tel:178602849', $mail['html']);
        $this->assertStringContainsString('src="https://przetargi.example.pl/campaign/footer-logo.png" width="80" height="80"', $mail['html']);
        $this->assertStringContainsString("--\nAnna Nowak\ntel. 600 903 483\ne-mail: anna@supon.example.pl", $mail['text']);
        // linia wypisu kampanii zostaje
        $this->assertStringContainsString('Wypisz mnie z mailingu', $mail['html']);

        // bez publicznego adresu obrazki by nie doszły — bez logo i ikon, napisy zamiast ikon
        config(['campaigns.public_url' => '']);
        $html = $renderer->render($campaign->fresh())['html'];
        $this->assertStringNotContainsString('footer-', $html);
        $this->assertStringContainsString('>tel.</span>', $html);
        $this->assertStringContainsString('>e-mail</span>', $html);
        $this->assertStringContainsString('>www</span>', $html);
    }

    public function test_offer_renderer_signature_switch_controls_footer(): void
    {
        $user = $this->author();
        $user->forceFill(['mail_footer' => self::FOOTER])->save();
        $offer = $this->offerWith($user, ['erp_item_id' => $this->erpItem('A1', 10)->id]);
        $renderer = app(OfferRenderer::class);

        $this->assertStringContainsString('Anna Nowak', $renderer->render($offer, $user)['html']);
        $this->assertStringNotContainsString('Anna Nowak', $renderer->render($offer, $user, null, false)['html']);
        // forma „pdf” (krótki mail) też ze stopką
        $this->assertStringContainsString('Anna Nowak', $renderer->render($offer, $user, null, true, 'pdf')['text']);
    }
}
