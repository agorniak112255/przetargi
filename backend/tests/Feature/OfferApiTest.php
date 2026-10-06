<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpItemLink;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\OfferRecipient;
use App\Models\OfferSend;
use App\Models\User;
use App\Models\UserMailAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\CampaignFixtures;
use Tests\Support\SupplierSpecialFixture;
use Tests\TestCase;

/** API ofert: dostęp, tworzenie z towarów XL i kart, cena sugerowana, maska ceny specjalnej, pozycje, podgląd. */
final class OfferApiTest extends TestCase
{
    use CampaignFixtures;
    use RefreshDatabase;
    use SupplierSpecialFixture;

    protected function setUp(): void
    {
        parent::setUp();
        // role z katalogu i kurs NBP bez sieci
        $this->setUpSupplierSpecial();
        Permission::findOrCreate('offers.use', 'web');
        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(10, 0));
        $this->setUpCampaigns();
    }

    private function author(float $margin = 20): User
    {
        $user = $this->sender();
        $user->forceFill(['default_margin_percent' => $margin])->save();
        $user->givePermissionTo('offers.use');

        return $user->fresh();
    }

    public function test_requires_offers_use_permission(): void
    {
        Sanctum::actingAs($this->sender());

        $this->getJson('/api/offers')->assertForbidden();
        $this->postJson('/api/offers', [])->assertForbidden();
    }

    public function test_someone_elses_offer_is_not_found(): void
    {
        $owner = $this->author();
        $offer = Offer::query()->create(['user_id' => $owner->id, 'subject' => 'Oferta']);
        $item = OfferItem::query()->create(['offer_id' => $offer->id, 'position' => 1, 'product_id' => $this->card('K1', 'Karta 1')->id]);
        $send = OfferSend::query()->create(['offer_id' => $offer->id, 'subject' => 'S', 'html' => '<p>x</p>', 'text' => 'x']);

        $other = User::factory()->withRole('admin')->create();
        $other->givePermissionTo('offers.use');
        Sanctum::actingAs($other);

        $this->getJson("/api/offers/{$offer->id}")->assertNotFound();
        $this->patchJson("/api/offers/{$offer->id}", ['subject' => 'X'])->assertNotFound();
        $this->deleteJson("/api/offers/{$offer->id}")->assertNotFound();
        $this->postJson("/api/offers/{$offer->id}/items", ['product_ids' => []])->assertNotFound();
        $this->patchJson("/api/offers/{$offer->id}/items/{$item->id}", ['price_net' => 1])->assertNotFound();
        $this->deleteJson("/api/offers/{$offer->id}/items/{$item->id}")->assertNotFound();
        $this->getJson("/api/offers/{$offer->id}/preview")->assertNotFound();
        $this->postJson("/api/offers/{$offer->id}/copied")->assertNotFound();
        $this->postJson("/api/offers/{$offer->id}/send", ['emails' => ['a@klient.pl']])->assertNotFound();
        $this->getJson("/api/offers/{$offer->id}/sends/{$send->id}")->assertNotFound();
        $this->getJson('/api/offers')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('Oferta', $offer->fresh()->subject);
    }

    public function test_creates_offer_from_xl_items_and_cards_with_suggested_prices(): void
    {
        $user = $this->author(20);
        // koszt partii 500 / 10 = 50 zł → sugerowana 60 zł
        $xl = $this->erpItem('B20417', 10, ['stock_value' => 500]);
        // karta bez towaru XL: cena zakupu 80 zł → sugerowana 96 zł
        $card = $this->card('KARTA-1', 'Rękawice nitrylowe');
        $card->forceFill(['purchase_price' => 80, 'currency' => 'PLN'])->save();
        // karta bez ceny zakupu — cena do uzupełnienia
        $noCost = $this->card('KARTA-2', 'Okulary', false);
        $noCost->forceFill(['purchase_price' => 0])->save();
        // karta powiązana pewnie z towarem XL już w ofercie — ta sama pozycja
        $linked = $this->card('KARTA-3', 'Buty');
        ErpItemLink::query()->create(['erp_item_id' => $xl->id, 'product_id' => $linked->id, 'status' => ErpItemLink::STATUS_CONFIRMED, 'method' => ErpItemLink::METHOD_MANUAL]);
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/offers', [
            'erp_item_ids' => [$xl->id],
            'product_ids' => [$card->id, $noCost->id, $linked->id],
        ])->assertCreated();

        $offer = Offer::query()->firstOrFail();
        $this->assertSame('OF-'.str_pad((string) $offer->id, 4, '0', STR_PAD_LEFT), $res->json('code'));
        $this->assertSame('', $res->json('subject'));
        $this->assertSame('grid3', $res->json('layout'));
        $this->assertSame([], $res->json('sends'));
        $this->assertSame(['max_items' => 30, 'max_recipients' => 10], $res->json('limits'));

        $items = $res->json('items');
        $this->assertCount(3, $items);
        $this->assertSame([1, 2, 3], array_column($items, 'position'));
        // towar XL
        $this->assertSame($xl->id, $items[0]['erp_item_id']);
        $this->assertSame('B20417', $items[0]['code']);
        $this->assertSame('szt', $items[0]['unit']);
        $this->assertEquals(10, $items[0]['stock']);
        $this->assertEquals(50, $items[0]['unit_cost']);
        $this->assertEquals(60, $items[0]['suggested_price']);
        $this->assertEquals(60, $items[0]['price_net']);
        // karta: koszt = cena zakupu karty
        $this->assertSame($card->id, $items[1]['product_id']);
        $this->assertNull($items[1]['erp_item_id']);
        $this->assertNull($items[1]['stock']);
        $this->assertEquals(80, $items[1]['unit_cost']);
        $this->assertEquals(96, $items[1]['suggested_price']);
        $this->assertEquals(96, $items[1]['price_net']);
        $this->assertSame('Rękawice nitrylowe', $items[1]['name']);
        $this->assertSame(['below_cost' => false, 'no_price' => false, 'no_image' => false], $items[1]['warnings']);
        // bez ceny zakupu: pusta cena z ostrzeżeniem
        $this->assertNull($items[2]['unit_cost']);
        $this->assertNull($items[2]['suggested_price']);
        $this->assertNull($items[2]['price_net']);
        $this->assertSame(['below_cost' => false, 'no_price' => true, 'no_image' => true], $items[2]['warnings']);
        // dokładnie klucze kontraktu OfferItem (bez pól kampanii)
        $this->assertSame([
            'id', 'position', 'erp_item_id', 'product_id', 'code', 'name', 'unit', 'stock', 'unit_cost', 'suggested_price',
            'price_net', 'note', 'description', 'card_excerpt', 'card', 'image_url', 'link', 'warnings',
        ], array_keys($items[0]));

        // duplikaty pomijane
        $this->postJson("/api/offers/{$offer->id}/items", ['erp_item_ids' => [$xl->id], 'product_ids' => [$card->id, $linked->id]])
            ->assertOk()->assertJsonCount(3, 'items');

        $list = $this->getJson('/api/offers')->assertOk()->json('data');
        $this->assertSame([[
            'id' => $offer->id, 'kind' => 'products', 'customer_name' => null,
            'code' => $offer->code, 'subject' => '', 'items_count' => 3, 'recipients_count' => 0,
            'last_sent_at' => null, 'last_copied_at' => null, 'updated_at' => $offer->fresh()->updated_at->toIso8601String(),
        ]], $list);
    }

    public function test_card_purchase_price_respects_supplier_special_mask(): void
    {
        $card = $this->supplierSpecialCard()['product'];
        $withoutPermission = $this->author(0);
        $offer = Offer::query()->create(['user_id' => $withoutPermission->id]);
        // karta bez towaru XL (powiązanie z XL fixture pomijamy — pozycja zapisana wprost)
        OfferItem::query()->create(['offer_id' => $offer->id, 'position' => 1, 'product_id' => $card->id]);

        Sanctum::actingAs($withoutPermission);
        $item = $this->getJson("/api/offers/{$offer->id}")->assertOk()->json('items.0');
        // bez prices.supplier_special.view: cena standardowa zamiast specjalnej
        $this->assertEquals((float) self::SPECIAL_STANDARD, $item['unit_cost']);
        $this->assertEquals((float) self::SPECIAL_STANDARD, $item['suggested_price']);
        $this->assertStringNotContainsString(self::SPECIAL_PRICE, (string) $this->getJson("/api/offers/{$offer->id}")->getContent());

        $withoutPermission->givePermissionTo('prices.supplier_special.view');
        Sanctum::actingAs($withoutPermission->fresh());
        $this->assertEquals((float) self::SPECIAL_PRICE, $this->getJson("/api/offers/{$offer->id}")->assertOk()->json('items.0.unit_cost'));
    }

    public function test_item_limit(): void
    {
        config(['offers.max_items' => 2]);
        $user = $this->author();
        $a = $this->erpItem('A1');
        $b = $this->erpItem('B1');
        $c = $this->erpItem('C1');
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/offers', ['erp_item_ids' => [$a->id, $b->id]])->assertCreated()->json('id');
        $this->postJson("/api/offers/{$id}/items", ['erp_item_ids' => [$c->id]])
            ->assertStatus(422)->assertJsonValidationErrors('items');
        $this->assertSame(2, OfferItem::query()->count());
        // więcej niż limit w jednym żądaniu — komunikat po polsku (aplikacja nie ma tłumaczeń walidacji)
        $this->postJson('/api/offers', ['erp_item_ids' => [$a->id, $b->id, $c->id]])->assertStatus(422)
            ->assertJsonPath('errors.erp_item_ids.0', 'Oferta mieści najwyżej 2 pozycji — zaznacz mniej.');
        // towar usunięty z listy w międzyczasie
        $this->postJson("/api/offers/{$id}/items", ['product_ids' => [999999]])->assertStatus(422)
            ->assertJsonPath('errors', ['product_ids.0' => ['Część zaznaczonych pozycji już nie istnieje — odśwież listę i zaznacz ponownie.']]);
    }

    public function test_patch_offer_and_items_with_order(): void
    {
        $user = $this->author();
        $a = $this->erpItem('A1', 10, ['stock_value' => 100]);
        $b = $this->erpItem('B1', 10, ['stock_value' => 100]);
        $c = $this->erpItem('C1', 10, ['stock_value' => 100]);
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/offers', ['erp_item_ids' => [$a->id, $b->id, $c->id]])->json('id');

        $this->patchJson("/api/offers/{$id}", [
            'subject' => '  Oferta na rękawice ',
            'intro' => "Dzień dobry,\nprzesyłam ofertę.",
            'layout' => 'list_desc',
            'valid_until' => '2026-10-31',
        ])->assertOk()
            ->assertJsonPath('subject', 'Oferta na rękawice')
            ->assertJsonPath('intro', "Dzień dobry,\nprzesyłam ofertę.")
            ->assertJsonPath('layout', 'list_desc')
            ->assertJsonPath('valid_until', '2026-10-31');
        $this->patchJson("/api/offers/{$id}", ['subject' => "dwie\nlinie"])->assertStatus(422)->assertJsonValidationErrors('subject');
        $this->patchJson("/api/offers/{$id}", ['layout' => 'hero'])->assertStatus(422);
        $this->patchJson("/api/offers/{$id}", ['valid_until' => '31.10.2026'])->assertStatus(422);
        $this->patchJson("/api/offers/{$id}", ['intro' => '', 'valid_until' => null])->assertOk()
            ->assertJsonPath('intro', null)->assertJsonPath('valid_until', null);

        $items = OfferItem::query()->orderBy('position')->get();
        // cena poniżej kosztu (10 zł) — ostrzeżenie, nie blokada
        $res = $this->patchJson("/api/offers/{$id}/items/{$items[0]->id}", ['price_net' => 9.999, 'note' => '  ', 'description' => ' Krótki opis '])->assertOk();
        $first = $res->json('items.0');
        $this->assertEquals(10.0, $first['price_net']);
        $this->assertNull($first['note']);
        $this->assertSame('Krótki opis', $first['description']);
        $this->patchJson("/api/offers/{$id}/items/{$items[0]->id}", ['price_net' => 9.5])->assertOk()
            ->assertJsonPath('items.0.warnings.below_cost', true);
        $this->patchJson("/api/offers/{$id}/items/{$items[1]->id}", ['price_net' => null])->assertOk()
            ->assertJsonPath('items.1.price_net', null)->assertJsonPath('items.1.warnings.no_price', true);
        $this->patchJson("/api/offers/{$id}/items/{$items[0]->id}", ['price_net' => -1])->assertStatus(422);

        // kolejność: trzecia na pierwsze miejsce
        $order = $this->patchJson("/api/offers/{$id}/items/{$items[2]->id}", ['position' => 1])->assertOk()->json('items');
        $this->assertSame([$items[2]->id, $items[0]->id, $items[1]->id], array_column($order, 'id'));
        $this->assertSame([1, 2, 3], array_column($order, 'position'));

        // usunięcie numeruje resztę
        $left = $this->deleteJson("/api/offers/{$id}/items/{$items[2]->id}")->assertOk()->json('items');
        $this->assertSame([$items[0]->id, $items[1]->id], array_column($left, 'id'));
        $this->assertSame([1, 2], array_column($left, 'position'));

        // pozycja innej oferty pod adresem tej oferty — 404
        $otherId = $this->postJson('/api/offers', ['erp_item_ids' => [$a->id]])->json('id');
        $foreign = OfferItem::query()->where('offer_id', $otherId)->firstOrFail();
        $this->patchJson("/api/offers/{$id}/items/{$foreign->id}", ['price_net' => 1])->assertNotFound();

        $this->deleteJson("/api/offers/{$otherId}")->assertNoContent();
        $this->assertNull(Offer::query()->find($otherId));
        $this->assertSame(0, OfferItem::query()->where('offer_id', $otherId)->count());
    }

    public function test_preview_reports_missing_prices_and_has_no_unsubscribe_line(): void
    {
        $user = $this->author();
        $priced = $this->erpItem('A1', 10, ['stock_value' => 100]);
        $card = $this->card('KARTA-9', 'Kask ochronny');
        $card->forceFill(['purchase_price' => 0])->save();
        Sanctum::actingAs($user);
        $offer = $this->postJson('/api/offers', ['erp_item_ids' => [$priced->id], 'product_ids' => [$card->id]])->json();
        $this->patchJson("/api/offers/{$offer['id']}", ['subject' => 'Oferta BHP', 'intro' => 'Dzień dobry, przesyłam ofertę.', 'valid_until' => '2026-10-31'])->assertOk();

        $preview = $this->getJson("/api/offers/{$offer['id']}/preview")->assertOk()->json();

        $this->assertSame('Oferta BHP', $preview['subject']);
        $this->assertSame('Jan – SUPON <jan@supon.example.pl>', $preview['from']);
        $this->assertSame([$offer['items'][1]['id']], $preview['missing_prices']);
        $this->assertFalse($preview['public_url_missing']);
        $this->assertStringContainsString('Kask ochronny', $preview['html']);
        $this->assertStringContainsString('Dzień dobry, przesyłam ofertę.', $preview['html']);
        $this->assertStringContainsString('Ceny netto. Oferta ważna do 31.10.2026', $preview['html']);
        $this->assertStringContainsString('Ceny netto. Oferta ważna do 31.10.2026', $preview['text']);
        // oferta do jednego klienta — bez linii wypisu i bez linków mierzonych
        foreach ([$preview['html'], $preview['text']] as $body) {
            // klient dostaje cenę — bez przycisku „Zapytaj o ofertę” (06.10.2026)
            $this->assertStringNotContainsString('Zapytaj', $body);
            $this->assertStringNotContainsString('mailto:', $body);
            $this->assertStringNotContainsString('Wypisz', $body);
            $this->assertStringNotContainsString('/api/wypis/', $body);
            $this->assertStringNotContainsString('/api/k/', $body);
            // bez notki o administratorze danych mailingu
            $this->assertStringNotContainsString('Administratorem danych', $body);
            // podgląd = kopia do Thunderbirda — bez podpisu, doda go program pocztowy
            $this->assertStringNotContainsString('tel. 600 000 000', $body);
        }

        // bez skrzynki: nadawca pusty
        UserMailAccount::query()->where('user_id', $user->id)->delete();
        config(['campaigns.public_url' => '']);
        $bare = $this->getJson("/api/offers/{$offer['id']}/preview")->assertOk()->json();
        $this->assertSame('', $bare['from']);
        $this->assertTrue($bare['public_url_missing']);
        $this->assertStringNotContainsString('mailto:', $bare['html']);
    }

    public function test_item_link_button_instead_of_ask(): void
    {
        $user = $this->author();
        $card = $this->card('KARTA-7', 'Półbuty S3');
        Sanctum::actingAs($user);
        $offer = $this->postJson('/api/offers', ['product_ids' => [$card->id]])->json();
        $itemId = $offer['items'][0]['id'];
        $this->assertNull($offer['items'][0]['link']);

        $res = $this->patchJson("/api/offers/{$offer['id']}/items/{$itemId}", [
            'link_url' => 'https://sklep.example.pl/polbuty-s3', 'link_label' => 'Zobacz w sklepie', 'link_color' => '#0b7d6a',
        ])->assertOk();
        $this->assertSame(
            ['url' => 'https://sklep.example.pl/polbuty-s3', 'label' => 'Zobacz w sklepie', 'color' => '#0b7d6a'],
            $res->json('items.0.link'),
        );

        $preview = $this->getJson("/api/offers/{$offer['id']}/preview")->assertOk()->json();
        $this->assertStringContainsString('href="https://sklep.example.pl/polbuty-s3"', $preview['html']);
        $this->assertStringContainsString('Zobacz w sklepie', $preview['html']);
        $this->assertStringContainsString('Zobacz w sklepie: https://sklep.example.pl/polbuty-s3', $preview['text']);
        $this->assertStringNotContainsString('Zapytaj', $preview['html']);

        // tylko https://, nazwa wymagana, kolor z listy
        $this->patchJson("/api/offers/{$offer['id']}/items/{$itemId}", ['link_url' => 'http://sklep.example.pl', 'link_label' => 'X', 'link_color' => '#0b7d6a'])
            ->assertStatus(422)->assertJsonValidationErrors('link_url');
        $this->patchJson("/api/offers/{$offer['id']}/items/{$itemId}", ['link_url' => 'https://sklep.example.pl', 'link_label' => '', 'link_color' => '#0b7d6a'])
            ->assertStatus(422)->assertJsonValidationErrors('link_label');
        $this->patchJson("/api/offers/{$offer['id']}/items/{$itemId}", ['link_url' => 'https://sklep.example.pl', 'link_label' => 'X', 'link_color' => '#123456'])
            ->assertStatus(422)->assertJsonValidationErrors('link_color');

        // pusty link usuwa przycisk
        $this->patchJson("/api/offers/{$offer['id']}/items/{$itemId}", ['link_url' => ''])->assertOk()->assertJsonPath('items.0.link', null);
    }

    public function test_copied_sets_timestamp(): void
    {
        $user = $this->author();
        $offer = Offer::query()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $before = $offer->fresh()->updated_at->format('Y-m-d H:i:s');
        $this->travel(2)->hours();
        $this->postJson("/api/offers/{$offer->id}/copied")->assertNoContent();

        $this->assertSame('2026-10-05 12:00:00', $offer->fresh()->last_copied_at->format('Y-m-d H:i:s'));
        // „Zmieniona” na liście to zmiana treści — kopiowanie jej nie przesuwa
        $this->assertSame($before, $offer->fresh()->updated_at->format('Y-m-d H:i:s'));
        $this->getJson("/api/offers/{$offer->id}")->assertJsonPath('last_copied_at', $offer->fresh()->last_copied_at->toIso8601String());
    }

    public function test_sent_mail_and_sends_history(): void
    {
        $user = $this->author();
        $offer = Offer::query()->create(['user_id' => $user->id]);
        $old = OfferSend::query()->create(['offer_id' => $offer->id, 'subject' => 'Pierwsza', 'html' => '<p>1</p>', 'text' => '1']);
        $this->travel(1)->hours();
        $new = OfferSend::query()->create(['offer_id' => $offer->id, 'subject' => 'Druga', 'html' => '<p>2</p>', 'text' => '2']);
        OfferRecipient::query()->create(['offer_id' => $offer->id, 'offer_send_id' => $new->id, 'email' => 'a@klient.pl', 'status' => 'sent', 'sent_at' => now()]);
        OfferRecipient::query()->create(['offer_id' => $offer->id, 'offer_send_id' => $old->id, 'email' => 'A@klient.pl', 'status' => 'sent', 'sent_at' => now()]);
        OfferRecipient::query()->create(['offer_id' => $offer->id, 'offer_send_id' => $new->id, 'email' => 'b@klient.pl', 'status' => 'failed', 'error' => '550 brak']);
        $foreign = OfferSend::query()->create(['offer_id' => Offer::query()->create(['user_id' => $user->id])->id, 'subject' => 'Inna', 'html' => 'x', 'text' => 'x']);
        Sanctum::actingAs($user);

        $sends = $this->getJson("/api/offers/{$offer->id}")->assertOk()->json('sends');
        $this->assertSame([$new->id, $old->id], array_column($sends, 'id'));
        $this->assertSame([
            ['email' => 'a@klient.pl', 'status' => 'sent', 'error' => null, 'sent_at' => now()->toIso8601String()],
            ['email' => 'b@klient.pl', 'status' => 'failed', 'error' => '550 brak', 'sent_at' => null],
        ], $sends[0]['recipients']);

        $this->getJson("/api/offers/{$offer->id}/sends/{$old->id}")->assertOk()->assertExactJson([
            'subject' => 'Pierwsza', 'html' => '<p>1</p>', 'text' => '1', 'created_at' => $old->created_at->toIso8601String(),
        ]);
        $this->getJson("/api/offers/{$offer->id}/sends/{$foreign->id}")->assertNotFound();

        // adresy z udaną wysyłką — ten sam adres (bez względu na wielkość liter) liczony raz
        $row = collect($this->getJson('/api/offers')->json('data'))->firstWhere('id', $offer->id);
        $this->assertSame(1, $row['recipients_count']);
    }

    public function test_user_with_offers_cannot_be_deleted(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $author = User::factory()->withRole('handlowiec')->create();
        Offer::query()->create(['user_id' => $author->id]);

        $this->deleteJson("/api/admin/users/{$author->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Użytkownik ma oferty — nie można go usunąć.');
        $this->assertNotNull($author->fresh());

        $other = User::factory()->withRole('handlowiec')->create();
        $this->deleteJson("/api/admin/users/{$other->id}")->assertOk();
        $this->assertNull($other->fresh());
    }

    public function test_compose_routes_still_work_next_to_offers(): void
    {
        $user = $this->author();
        $user->givePermissionTo('inquiries.use');
        Sanctum::actingAs($user->fresh());

        // /offers/compose/status należy do zapytań klientów, nie do ofert
        $this->getJson('/api/offers/compose/status')->assertOk();
        $this->getJson('/api/offers/abc')->assertNotFound();
    }
}
