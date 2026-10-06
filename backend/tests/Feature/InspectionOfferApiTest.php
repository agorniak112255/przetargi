<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EmailSuppression;
use App\Models\ErpCustomer;
use App\Models\InspectionDismissal;
use App\Models\InspectionDue;
use App\Models\InspectionPosition;
use App\Models\Offer;
use App\Models\OfferInspectionLine;
use App\Models\OfferItem;
use App\Models\OfferSend;
use App\Models\User;
use App\Services\Offers\InspectionOfferRenderer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\CampaignFixtures;
use Tests\TestCase;

/**
 * Oferty przeglądu (moduł Przeglądy → Oferty, 06.10.2026): przygotowanie z wyliczonych terminów dla kilku klientów,
 * uprawnienia offers.use / inspections.offer, lista, edycja wierszy, podgląd i PDF bez cen, wysyłka.
 */
final class InspectionOfferApiTest extends TestCase
{
    use CampaignFixtures;
    use RefreshDatabase;

    private User $author;

    private InspectionPosition $service;

    private InspectionPosition $goods;

    private int $gid = 5000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::findOrCreate('offers.use', 'web');
        Permission::findOrCreate('inspections.offer', 'web');
        Permission::findOrCreate('inspections.view', 'web');
        // 05.10.2026 12:00 w Polsce — „dziś” modułu Przeglądy
        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(10, 0));
        $this->setUpCampaigns();
        Storage::fake('local');

        $this->author = $this->sender();
        $this->author->givePermissionTo(['inspections.offer', 'inspections.view']);
        $this->service = $this->position('UPRGP6', 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', InspectionPosition::TYPE_SERVICE);
        $this->goods = $this->position('GP6X', 'GAŚNICA PROSZKOWA GP-6X <ABC>', InspectionPosition::TYPE_GOODS);
        Sanctum::actingAs($this->author);
    }

    private function position(string $code, string $name, int $type, bool $active = true): InspectionPosition
    {
        return InspectionPosition::query()->create([
            'xl_gid' => $this->gid++, 'xl_type' => $type, 'code' => $code, 'name' => $name, 'unit' => 'szt',
            'interval_months' => 12, 'active' => $active, 'source' => InspectionPosition::SOURCE_MANUAL,
        ]);
    }

    /** @param  list<string>  $emails */
    private function xlCustomer(int $xlGid, string $acronym, ?string $name, array $emails = []): ErpCustomer
    {
        return ErpCustomer::query()->create([
            'xl_gid' => $xlGid, 'acronym' => $acronym, 'name' => $name, 'city' => 'Rzeszów', 'emails' => $emails, 'archived' => false,
        ]);
    }

    private function due(int $customerGid, InspectionPosition $position, string $dueOn, float $quantity = 10, string $lastOn = '2025-10-20'): InspectionDue
    {
        return InspectionDue::query()->create([
            'customer_xl_gid' => $customerGid, 'inspection_position_id' => $position->id, 'due_on' => $dueOn,
            'open_count' => 1, 'open_quantity' => $quantity, 'last_on' => $lastOn, 'last_quantity' => $quantity,
            'last_net' => 91.4, 'last_documents' => [['number' => 'FS-1/25', 'issued_on' => $lastOn, 'quantity' => $quantity]],
            'first_on' => $lastOn, 'computed_at' => now(),
        ]);
    }

    private function dismiss(int $customerGid, ?InspectionPosition $position, ?string $until = null): void
    {
        InspectionDismissal::query()->create([
            'customer_xl_gid' => $customerGid, 'inspection_position_id' => $position?->id, 'until_on' => $until, 'reason' => 'other_company',
        ]);
    }

    /** Oferta przeglądu klienta 101 z dwoma wierszami — przez API, jak z modułu Przeglądy. */
    private function inspectionOffer(): Offer
    {
        $this->xlCustomer(101, 'ALFA', 'Firma Alfa Sp. z o.o.', ['biuro@alfa.pl']);
        $this->due(101, $this->service, '2026-10-20');
        $this->due(101, $this->goods, '2026-09-01', 2, '2025-09-01');
        $id = $this->postJson('/api/inspections/offers', ['customer_xl_gids' => [101]])->assertCreated()->json('offers.0.id');

        return Offer::query()->findOrFail($id);
    }

    public function test_preparing_offers_needs_list_permission_too(): void
    {
        // przygotowanie pokazuje klientów, adresy i terminy — jak lista Przeglądów
        $this->author->revokePermissionTo('inspections.view');
        $this->xlCustomer(101, 'ALFA', 'Alfa', ['a@alfa.pl']);
        $this->due(101, $this->service, '2026-10-20');

        $this->postJson('/api/inspections/offers', ['customer_xl_gids' => [101]])->assertForbidden();
        $this->assertSame(0, Offer::query()->count());
    }

    public function test_result_warns_about_same_nip_and_colleague_draft_and_names_dismissed_positions(): void
    {
        $this->xlCustomer(101, 'ALFA', 'Alfa', ['a@alfa.pl']);
        $this->due(101, $this->service, '2026-10-20')->forceFill(['same_nip_newer' => true])->save();
        $this->due(101, $this->goods, '2026-10-21');
        // szkic innej osoby sprzed 3 dni, niewysłany
        $anna = User::factory()->create(['name' => 'Anna Nowak']);
        $draft = Offer::query()->create(['user_id' => $anna->id, 'kind' => Offer::KIND_INSPECTION, 'customer_xl_gid' => 101, 'subject' => 'x']);
        $draft->forceFill(['created_at' => now()->subDays(3)])->save();
        // 102: jedyna pozycja w oknie jest pominięta
        $this->xlCustomer(102, 'BETA', 'Beta');
        $this->due(102, $this->service, '2026-10-15');
        $this->dismiss(102, $this->service);

        $res = $this->postJson('/api/inspections/offers', ['customer_xl_gids' => [101, 102]])->assertCreated();

        $res->assertJsonPath('offers.0.same_nip_newer_lines', 1)
            ->assertJsonPath('offers.0.previous.code', $draft->fresh()->code)
            ->assertJsonPath('offers.0.previous.sent_at', null)
            ->assertJsonPath('offers.0.previous.user_name', 'Anna Nowak')
            ->assertJsonPath('skipped.0.reason', 'Wszystkie pozycje klienta z terminem w oknie są pominięte');
    }

    public function test_thunderbird_request_cannot_attach_inspection_offer_without_permission(): void
    {
        $offer = $this->inspectionOffer();
        $this->author->revokePermissionTo('inspections.offer');
        $this->author->givePermissionTo('offers.use');

        $this->postJson('/api/offers/compose', [
            'subject' => 'Oferta', 'body_html' => '<p>Oferta</p>', 'offer_id' => $offer->id, 'attach_pdf' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('offer_id');
    }

    public function test_prepares_one_offer_per_customer_with_window_dismissals_and_previous(): void
    {
        $inactive = $this->position('UPRX', 'PRZEGLĄD WYCOFANY', InspectionPosition::TYPE_SERVICE, false);
        $hose = $this->position('UPRHYD', 'PRZEGLĄD HYDRANTU', InspectionPosition::TYPE_SERVICE);
        $other = $this->position('UPRAGR', 'PRZEGLĄD AGREGATU', InspectionPosition::TYPE_SERVICE);

        // 101: dwa wiersze w oknie (zaległy też), nieaktywna pozycja i pozycja pominięta do grudnia odpadają, pominięcie
        // z przeszłości (do wczoraj) już nie działa
        $this->xlCustomer(101, 'ALFA', 'Firma Alfa Sp. z o.o.', ['biuro@alfa.pl', 'serwis@alfa.pl']);
        $this->due(101, $this->service, '2026-10-20', 10);
        $this->due(101, $this->goods, '2025-01-15', 2, '2024-01-15');
        $this->due(101, $inactive, '2026-10-10');
        $this->due(101, $hose, '2026-10-12');
        $this->dismiss(101, $hose, '2026-12-31');
        $this->dismiss(101, $this->goods, '2026-10-04');
        // 102: pominięty cały klient
        $this->xlCustomer(102, 'BETA', 'Beta', ['beta@b.pl']);
        $this->due(102, $this->service, '2026-10-15');
        $this->dismiss(102, null);
        // 103: termin poza oknem 30 dni
        $this->xlCustomer(103, 'GAMMA', null);
        $this->due(103, $this->service, '2026-12-20');
        // 104: klienta nie ma w kartotece; termin na granicy okna (dziś + 30 dni)
        $this->due(104, $other, '2026-11-04', 1);

        // wysłana wcześniej oferta przeglądu do 101 (inny handlowiec) i stara (ponad 90 dni) do 104
        $anna = User::factory()->create(['name' => 'Anna Nowak']);
        $sent = Offer::query()->create(['user_id' => $anna->id, 'kind' => Offer::KIND_INSPECTION, 'customer_xl_gid' => 101, 'subject' => 'S']);
        $sent->forceFill(['last_sent_at' => '2026-09-01 10:00:00'])->save();
        Offer::query()->create(['user_id' => $anna->id, 'kind' => Offer::KIND_INSPECTION, 'customer_xl_gid' => 104, 'subject' => 'S'])
            ->forceFill(['last_sent_at' => '2026-06-01 10:00:00'])->save();

        $res = $this->postJson('/api/inspections/offers', ['customer_xl_gids' => [101, 102, 103, 104]])->assertCreated();

        $this->assertSame([
            ['customer_xl_gid' => 102, 'customer_name' => 'Beta', 'reason' => 'Klient pominięty'],
            ['customer_xl_gid' => 103, 'customer_name' => 'GAMMA', 'reason' => 'Brak pozycji z terminem w oknie'],
        ], $res->json('skipped'));
        $this->assertCount(2, $res->json('offers'));
        $alfa = $res->json('offers.0');
        $this->assertSame(101, $alfa['customer_xl_gid']);
        $this->assertSame('Firma Alfa Sp. z o.o.', $alfa['customer_name']);
        $this->assertSame(2, $alfa['lines_count']);
        $this->assertSame(['biuro@alfa.pl', 'serwis@alfa.pl'], $alfa['emails']);
        $this->assertSame([
            'code' => $sent->code, 'sent_at' => '2026-09-01T10:00:00+00:00',
            'created_at' => $sent->fresh()->created_at->toIso8601String(), 'user_name' => 'Anna Nowak',
        ], $alfa['previous']);
        $unknown = $res->json('offers.1');
        $this->assertSame(104, $unknown['customer_xl_gid']);
        $this->assertSame('Klient XL 104', $unknown['customer_name']);
        $this->assertSame([], $unknown['emails']);
        $this->assertNull($unknown['previous']);

        $offer = Offer::query()->findOrFail($alfa['id']);
        $this->assertSame($alfa['code'], $offer->code);
        $this->assertSame(Offer::KIND_INSPECTION, $offer->kind);
        $this->assertSame(101, $offer->customer_xl_gid);
        $this->assertSame($this->author->id, (int) $offer->user_id);
        $this->assertSame('body', $offer->delivery);
        $this->assertSame('Przypomnienie o terminie przeglądu — Firma Alfa Sp. z o.o.', $offer->subject);
        $this->assertStringStartsWith('Dzień dobry,', (string) $offer->intro);
        $this->assertNull($offer->last_sent_at);
        // zaległy pierwszy (po terminie), wiersze z pozycji i z wyliczonego terminu
        $lines = $offer->inspectionLines()->get();
        $this->assertSame(['GAŚNICA PROSZKOWA GP-6X <ABC>', 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6'], $lines->pluck('name')->all());
        $this->assertSame([1, 2], $lines->pluck('position')->all());
        $this->assertSame([$this->goods->id, $this->service->id], $lines->pluck('inspection_position_id')->all());
        $this->assertSame([$this->goods->xl_gid, $this->service->xl_gid], $lines->pluck('xl_gid')->all());
        $this->assertSame(['2025-01-15', '2026-10-20'], $lines->map(fn (OfferInspectionLine $l) => $l->due_on->toDateString())->all());
        $this->assertSame(['2024-01-15', '2025-10-20'], $lines->map(fn (OfferInspectionLine $l) => $l->last_on->toDateString())->all());
        $this->assertSame([2.0, 10.0], $lines->map(fn (OfferInspectionLine $l) => (float) $l->quantity)->all());
        $this->assertSame(['szt', 'szt'], $lines->pluck('unit')->all());
        $this->assertSame('Przypomnienie o terminie przeglądu', Offer::query()->findOrFail($unknown['id'])->subject);

        // dłuższe okno obejmuje 103; position_ids zawęża
        $wider = $this->postJson('/api/inspections/offers', ['customer_xl_gids' => [103], 'days' => 90])->assertCreated();
        $this->assertSame(1, $wider->json('offers.0.lines_count'));
        $only = $this->postJson('/api/inspections/offers', ['customer_xl_gids' => [101], 'position_ids' => [$this->service->id]])->assertCreated();
        $this->assertSame(1, $only->json('offers.0.lines_count'));
        $this->assertSame('PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', OfferInspectionLine::query()->where('offer_id', $only->json('offers.0.id'))->sole()->name);
    }

    public function test_prepare_validation(): void
    {
        $this->postJson('/api/inspections/offers', ['customer_xl_gids' => []])->assertStatus(422)
            ->assertJsonPath('errors.customer_xl_gids.0', 'Zaznacz co najmniej jednego klienta.');
        $this->postJson('/api/inspections/offers', ['customer_xl_gids' => range(1, 51)])->assertStatus(422)
            ->assertJsonValidationErrors('customer_xl_gids');
        $this->postJson('/api/inspections/offers', ['customer_xl_gids' => [1], 'days' => 731])->assertStatus(422)
            ->assertJsonValidationErrors('days');
        $this->assertSame(0, Offer::query()->count());
    }

    public function test_inspection_permission_without_offers_use_sees_only_inspection_offers(): void
    {
        $offer = $this->inspectionOffer();
        $products = Offer::query()->create(['user_id' => $this->author->id, 'subject' => 'Rękawice']);
        $line = $offer->inspectionLines()->firstOrFail();

        $list = $this->getJson('/api/offers')->assertOk()->json('data');
        $this->assertSame([$offer->id], array_column($list, 'id'));
        $this->assertSame('inspection', $list[0]['kind']);
        $this->assertSame('Firma Alfa Sp. z o.o.', $list[0]['customer_name']);
        $this->assertSame(2, $list[0]['items_count']);

        $this->getJson("/api/offers/{$offer->id}")->assertOk();
        $this->patchJson("/api/offers/{$offer->id}", ['subject' => 'Przegląd gaśnic'])->assertOk()->assertJsonPath('subject', 'Przegląd gaśnic');
        $this->patchJson("/api/offers/{$offer->id}/inspection-lines/{$line->id}", ['note' => 'w magazynie'])->assertOk();
        // oferta z produktami i nowa oferta z produktami — tylko z offers.use
        $this->getJson("/api/offers/{$products->id}")->assertForbidden();
        $this->patchJson("/api/offers/{$products->id}", ['subject' => 'X'])->assertForbidden();
        $this->postJson('/api/offers', [])->assertForbidden();
        $this->assertSame('Rękawice', $products->fresh()->subject);
    }

    public function test_offers_use_without_inspection_permission_does_not_see_inspection_offers(): void
    {
        $offer = $this->inspectionOffer();
        $line = $offer->inspectionLines()->firstOrFail();
        $products = Offer::query()->create(['user_id' => $this->author->id, 'subject' => 'Rękawice']);
        $this->author->revokePermissionTo('inspections.offer');
        $this->author->givePermissionTo('offers.use');
        Sanctum::actingAs($this->author->fresh());

        $list = $this->getJson('/api/offers')->assertOk()->json('data');
        $this->assertSame([$products->id], array_column($list, 'id'));
        // rodzaj i klient są zawsze w wierszu listy; oferta z produktami nie ma klienta
        $this->assertSame('products', $list[0]['kind']);
        $this->assertNull($list[0]['customer_name']);

        $this->getJson("/api/offers/{$offer->id}")->assertNotFound();
        $this->patchJson("/api/offers/{$offer->id}", ['subject' => 'X'])->assertNotFound();
        $this->getJson("/api/offers/{$offer->id}/preview")->assertNotFound();
        $this->getJson("/api/offers/{$offer->id}/pdf")->assertNotFound();
        $this->postJson("/api/offers/{$offer->id}/send", ['emails' => ['biuro@alfa.pl']])->assertNotFound();
        $this->patchJson("/api/offers/{$offer->id}/inspection-lines/{$line->id}", ['note' => 'x'])->assertNotFound();
        $this->deleteJson("/api/offers/{$offer->id}")->assertNotFound();
        $this->postJson('/api/inspections/offers', ['customer_xl_gids' => [101]])->assertForbidden();
        // produktowa oferta działa jak dotąd i ma puste pola przeglądu
        $this->getJson("/api/offers/{$products->id}")->assertOk()
            ->assertJsonPath('kind', 'products')->assertJsonPath('customer', null)->assertJsonPath('inspection_lines', []);

        // bez obu uprawnień — moduł ofert zamknięty
        $this->author->revokePermissionTo('offers.use');
        Sanctum::actingAs($this->author->fresh());
        $this->getJson('/api/offers')->assertForbidden();
    }

    public function test_someone_elses_inspection_offer_is_not_found(): void
    {
        $offer = $this->inspectionOffer();
        $other = $this->sender();
        $other->givePermissionTo('inspections.offer');
        Sanctum::actingAs($other);

        $this->getJson("/api/offers/{$offer->id}")->assertNotFound();
        $this->getJson('/api/offers')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_list_with_both_permissions_shows_kind_of_every_offer(): void
    {
        $offer = $this->inspectionOffer();
        $this->author->givePermissionTo('offers.use');
        Sanctum::actingAs($this->author->fresh());
        $products = Offer::query()->create(['user_id' => $this->author->id, 'subject' => 'Rękawice']);
        OfferItem::query()->create(['offer_id' => $products->id, 'position' => 1, 'product_id' => $this->card('K1', 'Karta')->id, 'price_net' => 5]);

        $rows = collect($this->getJson('/api/offers')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('products', $rows[$products->id]['kind']);
        $this->assertNull($rows[$products->id]['customer_name']);
        $this->assertSame(1, $rows[$products->id]['items_count']);
        $this->assertSame('inspection', $rows[$offer->id]['kind']);
        $this->assertSame('Firma Alfa Sp. z o.o.', $rows[$offer->id]['customer_name']);
        $this->assertSame(2, $rows[$offer->id]['items_count']);
    }

    public function test_offer_json_and_line_edits(): void
    {
        $offer = $this->inspectionOffer();
        $json = $this->getJson("/api/offers/{$offer->id}")->assertOk()->json();

        $this->assertSame('inspection', $json['kind']);
        $this->assertSame(['xl_gid' => 101, 'acronym' => 'ALFA', 'name' => 'Firma Alfa Sp. z o.o.', 'city' => 'Rzeszów', 'emails' => ['biuro@alfa.pl']], $json['customer']);
        $this->assertSame([], $json['items']);
        [$first, $second] = $json['inspection_lines'];
        $this->assertSame([
            'id' => $first['id'], 'position' => 1, 'inspection_position_id' => $this->goods->id, 'xl_gid' => $this->goods->xl_gid,
            'name' => 'GAŚNICA PROSZKOWA GP-6X <ABC>', 'unit' => 'szt', 'quantity' => 2, 'last_on' => '2025-09-01',
            'due_on' => '2026-09-01', 'note' => null,
        ], $first);

        $patched = $this->patchJson("/api/offers/{$offer->id}/inspection-lines/{$second['id']}", [
            'quantity' => 12.5, 'due_on' => '2026-11-15', 'note' => '  w budynku B  ', 'position' => 1,
        ])->assertOk()->json('inspection_lines');
        $this->assertSame([$second['id'], $first['id']], array_column($patched, 'id'));
        $this->assertSame([1, 2], array_column($patched, 'position'));
        $this->assertSame(12.5, $patched[0]['quantity']);
        $this->assertSame('2026-11-15', $patched[0]['due_on']);
        $this->assertSame('w budynku B', $patched[0]['note']);

        $cleared = $this->patchJson("/api/offers/{$offer->id}/inspection-lines/{$second['id']}", ['quantity' => null, 'due_on' => null, 'note' => ''])
            ->assertOk()->json('inspection_lines.0');
        $this->assertNull($cleared['quantity']);
        $this->assertNull($cleared['due_on']);
        $this->assertNull($cleared['note']);

        $this->patchJson("/api/offers/{$offer->id}/inspection-lines/{$second['id']}", ['quantity' => -1])->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', 'Ilość nie może być ujemna.');
        $this->patchJson("/api/offers/{$offer->id}/inspection-lines/{$second['id']}", ['due_on' => '15.11.2026'])->assertStatus(422)
            ->assertJsonPath('errors.due_on.0', 'Termin przeglądu musi mieć postać RRRR-MM-DD.');
        $this->patchJson("/api/offers/{$offer->id}/inspection-lines/{$second['id']}", ['note' => str_repeat('x', 301)])->assertStatus(422)
            ->assertJsonValidationErrors('note');

        // do oferty przeglądu nie dopisuje się produktów
        $this->postJson("/api/offers/{$offer->id}/items", ['product_ids' => [$this->card('K1', 'Karta')->id]])->assertStatus(422)
            ->assertJsonPath('errors.items.0', 'Do oferty przeglądu nie dopisuje się produktów — ma tylko wiersze przeglądu z modułu Przeglądy.');
        $this->assertSame(0, OfferItem::query()->count());

        $after = $this->deleteJson("/api/offers/{$offer->id}/inspection-lines/{$second['id']}")->assertOk()->json('inspection_lines');
        $this->assertSame([['id' => $first['id'], 'position' => 1]], array_map(fn (array $l) => ['id' => $l['id'], 'position' => $l['position']], $after));
        $this->deleteJson("/api/offers/{$offer->id}/inspection-lines/{$second['id']}")->assertNotFound();
    }

    public function test_inspection_lines_of_products_offer_or_other_offer_are_not_found(): void
    {
        $offer = $this->inspectionOffer();
        $line = $offer->inspectionLines()->firstOrFail();
        $this->author->givePermissionTo('offers.use');
        $products = Offer::query()->create(['user_id' => $this->author->id, 'subject' => 'Rękawice']);
        // wiersz przeglądu podpięty do oferty z produktami (dane z ręki) — i tak 404
        $stray = OfferInspectionLine::query()->create(['offer_id' => $products->id, 'position' => 1, 'name' => 'X']);

        $this->patchJson("/api/offers/{$products->id}/inspection-lines/{$stray->id}", ['note' => 'x'])->assertNotFound();
        $this->deleteJson("/api/offers/{$products->id}/inspection-lines/{$stray->id}")->assertNotFound();
        $this->patchJson("/api/offers/{$products->id}/inspection-lines/{$line->id}", ['note' => 'x'])->assertNotFound();
        $this->patchJson("/api/offers/{$offer->id}/inspection-lines/{$stray->id}", ['note' => 'x'])->assertNotFound();
        $this->assertNull($line->fresh()->note);
    }

    public function test_preview_has_table_without_prices_and_ask_button(): void
    {
        $offer = $this->inspectionOffer();
        $line = $offer->inspectionLines()->firstOrFail();
        $this->patchJson("/api/offers/{$offer->id}/inspection-lines/{$line->id}", ['note' => 'magazyn <hala 2> $0'])->assertOk();
        $this->patchJson("/api/offers/{$offer->id}", ['valid_until' => '2026-10-31'])->assertOk();

        $preview = $this->getJson("/api/offers/{$offer->id}/preview")->assertOk()->json();

        $this->assertSame('Przypomnienie o terminie przeglądu — Firma Alfa Sp. z o.o.', $preview['subject']);
        $this->assertSame([], $preview['missing_prices']);
        $this->assertNull($preview['pdf_filename']);
        $html = $preview['html'];
        foreach (['Co wymaga przeglądu', 'Urządzenie lub usługa', 'Ilość', 'Ostatni przegląd lub zakup u nas', 'Proponowany termin przeglądu',
            'Proponowany termin wyliczyliśmy z daty ostatniego przeglądu lub zakupu',
            // termin sprzed dziś (05.10.2026) z dopiskiem
            '01.09.2026 (termin minął)',
            'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', 'GAŚNICA PROSZKOWA GP-6X &lt;ABC&gt;', 'magazyn &lt;hala 2&gt; $0', '01.09.2025', '01.09.2026',
            '20.10.2025', '20.10.2026', "10\u{00A0}szt", "2\u{00A0}szt", 'Oferta ważna do 31.10.2026.', 'Dzień dobry,'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringNotContainsString('<ABC>', $html);
        $this->assertStringNotContainsString('INSPECTIONTABLE', $html);
        $text = $preview['text'];
        foreach (['Co wymaga przeglądu:', '* GAŚNICA PROSZKOWA GP-6X <ABC>', '  magazyn <hala 2> $0', '  Ilość: 2 szt',
            '  Ostatni przegląd lub zakup u nas: 01.09.2025', '  Proponowany termin przeglądu: 01.09.2026',
            InspectionOfferRenderer::BASIS, 'Oferta ważna do 31.10.2026.'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        $this->assertStringNotContainsString('INSPECTIONTABLE', $text);
        foreach ([$html, $text] as $body) {
            $this->assertStringNotContainsString('Ceny netto', $body);
            $this->assertStringNotContainsString('Cena', $body);
            $this->assertStringNotContainsString('zł', $body);
            $this->assertStringNotContainsString('Zapytaj', $body);
            $this->assertStringNotContainsString('mailto:', $body);
            $this->assertStringNotContainsString('Wypisz', $body);
        }

        // forma „pdf”: krótki mail (wstęp) bez tabeli
        $this->patchJson("/api/offers/{$offer->id}", ['delivery' => 'pdf'])->assertOk();
        $short = $this->getJson("/api/offers/{$offer->id}/preview")->assertOk()->json();
        $this->assertStringNotContainsString('Co wymaga przeglądu', $short['html']);
        $this->assertStringContainsString('Dzień dobry,', $short['html']);
        $this->assertSame('Oferta-'.$offer->code.'.pdf', $short['pdf_filename']);
    }

    public function test_sends_mail_with_table_and_signature(): void
    {
        $offer = $this->inspectionOffer();

        $res = $this->postJson("/api/offers/{$offer->id}/send", ['emails' => ['biuro@alfa.pl']])->assertOk();

        $this->assertSame([['email' => 'biuro@alfa.pl', 'status' => 'sent', 'error' => null]], $res->json('results'));
        $this->assertSame(['biuro@alfa.pl', 'jan@supon.example.pl'], $this->mailers->recipients());
        $mail = $this->mailers->emails()[0];
        $this->assertSame('Przypomnienie o terminie przeglądu — Firma Alfa Sp. z o.o.', $mail->getSubject());
        foreach ([(string) $mail->getHtmlBody(), (string) $mail->getTextBody()] as $body) {
            $this->assertStringContainsString('PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', $body);
            $this->assertStringContainsString('tel. 600 000 000', $body);
            $this->assertStringNotContainsString('Ceny netto', $body);
            $this->assertStringNotContainsString('Zapytaj', $body);
        }
        $this->assertSame((string) $mail->getHtmlBody(), OfferSend::query()->sole()->html);
        $this->assertNotNull($offer->fresh()->last_sent_at);
        $this->assertSame('inspection', $res->json('offer.kind'));

        // kolejna oferta dla tego klienta pokazuje poprzednią wysyłkę
        $next = $this->postJson('/api/inspections/offers', ['customer_xl_gids' => [101]])->assertCreated();
        $this->assertSame($offer->code, $next->json('offers.0.previous.code'));
        $this->assertSame('Jan Handlowiec', $next->json('offers.0.previous.user_name'));
    }

    public function test_send_rules_suppression_validity_and_empty_offer(): void
    {
        $offer = $this->inspectionOffer();
        EmailSuppression::query()->create(['email' => 'wypisany@alfa.pl', 'reason' => EmailSuppression::REASON_UNSUBSCRIBE]);

        $this->postJson("/api/offers/{$offer->id}/send", ['emails' => ['Wypisany@alfa.pl']])->assertStatus(422)->assertJsonValidationErrors('emails');

        $offer->forceFill(['valid_until' => '2026-10-04'])->save();
        $this->postJson("/api/offers/{$offer->id}/send", ['emails' => ['biuro@alfa.pl']])->assertStatus(422)->assertJsonValidationErrors('valid_until');
        $offer->forceFill(['valid_until' => null])->save();

        OfferInspectionLine::query()->where('offer_id', $offer->id)->delete();
        $this->postJson("/api/offers/{$offer->id}/send", ['emails' => ['biuro@alfa.pl']])->assertStatus(422)
            ->assertJsonPath('errors.items.0', 'Dodaj do oferty co najmniej jeden wiersz przeglądu.');
        $this->getJson("/api/offers/{$offer->id}/pdf")->assertStatus(422)
            ->assertJsonPath('errors.items.0', 'Dodaj do oferty co najmniej jeden wiersz przeglądu.');
        $this->assertSame([], $this->mailers->recipients());
        $this->assertSame(0, OfferSend::query()->count());
    }

    public function test_pdf_download_and_both_delivery_attachment(): void
    {
        $offer = $this->inspectionOffer();
        // długa lista nie gubi wierszy na kolejnych stronach
        for ($i = 3; $i <= 60; $i++) {
            OfferInspectionLine::query()->create(['offer_id' => $offer->id, 'position' => $i, 'name' => 'Urządzenie '.$i, 'unit' => 'szt', 'quantity' => 1, 'due_on' => '2026-10-30']);
        }

        $res = $this->get("/api/offers/{$offer->id}/pdf")->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $pdf = (string) $res->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('DejaVuSans', $pdf);
        $this->assertGreaterThanOrEqual(2, preg_match_all('#/Type /Page\b#', $pdf));

        $this->patchJson("/api/offers/{$offer->id}", ['delivery' => 'both'])->assertOk();
        $this->postJson("/api/offers/{$offer->id}/send", ['emails' => ['biuro@alfa.pl']])->assertOk();
        $mail = $this->mailers->emails()[0];
        $this->assertCount(1, $mail->getAttachments());
        $this->assertStringContainsString('Co wymaga przeglądu', (string) $mail->getHtmlBody());
        Storage::disk('local')->assertExists((string) OfferSend::query()->sole()->pdf_path);
    }
}
