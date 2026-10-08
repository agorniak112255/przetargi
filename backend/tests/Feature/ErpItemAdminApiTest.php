<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\ErpItemPurchase;
use App\Models\Product;
use App\Models\User;
use App\Services\Erp\ErpItemMatcher;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ErpItemAdminApiTest extends TestCase
{
    use RefreshDatabase;

    private int $gid = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_screen_and_decisions_need_their_permissions(): void
    {
        $item = $this->item('SOK1', 'OKULARY 1', 'no_code');
        $card = $this->card('X-1', 'UVEX', 'Okulary X');

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson('/api/admin/erp-items')->assertForbidden();

        $viewer = Role::findOrCreate('podglad-erp', 'web');
        $viewer->givePermissionTo(['admin.access', 'admin.erp_links.view']);
        Sanctum::actingAs(User::factory()->create()->assignRole($viewer));
        $this->getJson('/api/admin/erp-items')->assertOk();
        $this->postJson('/api/admin/erp-items/'.$item->id.'/link', ['product_id' => $card->id])->assertForbidden();
    }

    public function test_filters_sorting_and_search_by_card_sku(): void
    {
        $uvex = $this->card('9174.065', 'UVEX', 'Okulary Skylite 9174.065');
        $linked = $this->item('SOK9174065', 'OKULARY UVEX 9174.065', 'auto', stock: 5, sold: '-1 month');
        $this->link($linked, $uvex, ErpItemLink::STATUS_AUTO);
        $review = $this->item('TBUTAR', 'TARCICA 19-25', 'suggested', stock: 40, sold: '-2 months');
        $this->item('ARĘKTACTYL', 'RĘKAWICE TACTYL', 'no_code', stock: 0, sold: '-20 months');
        $noCard = $this->item('BTR123', 'TRZEWIKI 5555-99', 'no_match', stock: 12, sold: '-1 month', matchValue: '5555-99', supplier: 'ARDON PREROV');
        $this->item('SOKOLD', 'OKULARY STARE 9174.065', 'no_match', archived: true);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $all = $this->getJson('/api/admin/erp-items')->assertOk();
        // domyślnie stan HANDEL malejąco; archiwalne poza ekranem
        $this->assertSame(['TBUTAR', 'BTR123', 'SOK9174065', 'ARĘKTACTYL'], array_column($all->json('data'), 'code'));
        $this->assertSame(4, $all->json('meta.total'));
        $this->assertSame('9174.065', $all->json('data.2.links.0.product.sku'));

        $this->assertSame(['TBUTAR', 'BTR123', 'ARĘKTACTYL'], $this->codes('status=unlinked'));
        $this->assertSame(['TBUTAR'], $this->codes('status=review'));
        $this->assertSame(['BTR123'], $this->codes('status=no_card'));
        $this->assertSame('5555-99', $this->getJson('/api/admin/erp-items?status=no_card')->json('data.0.match_value'));
        $this->assertSame(['SOK9174065'], $this->codes('status=linked'));
        $this->assertSame(['BTR123'], $this->codes('group=B'));
        $this->assertSame(['TBUTAR', 'BTR123', 'SOK9174065'], $this->codes('sold_months=3'));
        $this->assertSame(['TBUTAR', 'BTR123', 'SOK9174065'], $this->codes('in_stock=1'));
        $this->assertSame(['BTR123'], $this->codes('supplier=ardon'));
        $this->assertSame(['SOK9174065'], $this->codes('search=9174.065'));
        $this->assertSame(['ARĘKTACTYL', 'BTR123', 'SOK9174065', 'TBUTAR'], $this->codes('sort=code&dir=asc'));
        $this->assertSame($review->id, $this->getJson('/api/admin/erp-items?status=review')->json('data.0.id'));
    }

    public function test_stale_filter_value_and_summary_follow_inventory_rules(): void
    {
        $this->travelTo('2026-10-08 10:00:00');
        $card = $this->card('P-1', 'UVEX', 'Okulary P');
        $this->item('ASTARY', 'KURTKA STARA', 'no_code', stock: 5, sold: '-8 months', value: 120.5);
        $this->item('ASWIEZY', 'KURTKA ŚWIEŻA', 'no_code', stock: 5, sold: '-2 months');
        $this->item('ABRAK', 'KURTKA BEZ STANU', 'no_code', stock: 0, sold: '-20 months');
        // nigdy niesprzedany liczy się dopiero, gdy jego najstarsza partia leży dłużej niż próg
        $this->item('ANIGDY', 'KURTKA NIGDY', 'no_match', stock: 3, oldestLot: '-14 months');
        $this->item('ANOWY', 'KURTKA NOWA', 'no_match', stock: 3, oldestLot: '-1 month');
        // stan tylko w magazynie usługowym: Zapasy liczą wszystkie magazyny, więc to też zalega
        $service = $this->item('AUSLUGA', 'KURTKA USŁUGA', 'search_suggested', stock: 0, stockTotal: 4, sold: '-13 months');
        $this->purchase($service, 2.5);
        $linked = $this->item('APOLACZ', 'KURTKA POŁĄCZONA', 'auto', stock: 2, sold: '-9 months', value: 50);
        $this->link($linked, $card, ErpItemLink::STATUS_AUTO);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->assertSame(['ANIGDY', 'APOLACZ', 'ASTARY', 'AUSLUGA'], $this->codes('stale_months=6&sort=code&dir=asc'));
        $this->assertSame(['ANIGDY', 'AUSLUGA'], $this->codes('stale_months=12&sort=code&dir=asc'));
        $this->assertSame(['ANIGDY', 'ASTARY', 'AUSLUGA'], $this->codes('stale_months=6&status=unlinked&sort=code&dir=asc'));

        // wartość = ilość × cena zakupu: partie, bez nich stan × ostatnia PZ; bez stanu albo ceny — na końcu
        $rows = collect($this->getJson('/api/admin/erp-items?sort=value&dir=desc')->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(['ASTARY', 'APOLACZ', 'AUSLUGA'], array_slice($rows->keys()->all(), 0, 3));
        $this->assertSame(['AUSLUGA', 'APOLACZ', 'ASTARY'], array_slice($this->codes('sort=value&dir=asc'), 0, 3));
        $this->assertEquals(120.5, $rows['ASTARY']['stock_value']);
        $this->assertEquals(10, $rows['AUSLUGA']['stock_value']);
        $this->assertNull($rows['ANIGDY']['stock_value']);
        $this->assertNull($rows['ABRAK']['stock_value']);

        // kafelek: te same liczby co „W tym bez karty” w Zapasach przy domyślnych 6 miesiącach
        $summary = $this->getJson('/api/admin/erp-items/summary')->assertOk()->json('unlinked_stale');
        $this->assertSame(['months' => 6, 'items' => 3, 'value' => 130.5, 'value_unknown' => 1], $summary);
        $inventory = $this->getJson('/api/inventory?months=6&warehouses=all')->assertOk()->json('summary');
        $this->assertSame(4, $inventory['items']);
        $this->assertSame($summary['items'], $inventory['without_card']);

        // eksport: kolumna wartości i filtr w zestawieniu
        $book = IOFactory::load($this->get('/api/admin/erp-items/export?stale_months=6&sort=value&dir=desc')->assertOk()->baseResponse->getFile()->getPathname());
        $sheet = $book->getSheetByName('Towary')?->toArray(null, false, false) ?? [];
        $this->assertSame(['Wartość zapasu (zł, cena zakupu)', 'ASTARY', 120.5], [$sheet[0][6], $sheet[1][0], (float) $sheet[1][6]]);
        $flat = array_map(static fn (array $r): string => implode('|', array_map('strval', array_filter($r, static fn ($c) => $c !== null))),
            $book->getSheetByName('Zestawienie')?->toArray(null, false, false) ?? []);
        $this->assertContains('Zalegające (stan, bez sprzedaży)|od 6 mies.', $flat);
        $this->assertContains('Sortowanie|wartość zapasu, malejąco', $flat);

        $this->assertSame([], $this->codes('stale_months=24'));
        $this->getJson('/api/admin/erp-items?stale_months=5')->assertUnprocessable();
    }

    public function test_proposal_count_and_supplier_evidence_filters(): void
    {
        $a = $this->card('A-1', 'UVEX', 'Okulary A');
        $b = $this->card('B-1', 'UVEX', 'Okulary B');
        $one = $this->item('SONE', 'OKULARY ONE', 'suggested', stock: 1, value: 42);
        $oneLink = $this->link($one, $a, ErpItemLink::STATUS_SUGGESTED, ['supplier_match' => true, 'supplier' => 'UVEX']);
        $weak = $this->item('SWEAK', 'OKULARY WEAK', 'search_suggested');
        $this->link($weak, $a, ErpItemLink::STATUS_SUGGESTED, ['supplier_match' => false, 'supplier' => null]);
        $many = $this->item('SMANY', 'OKULARY MANY', 'ambiguous');
        $this->link($many, $a, ErpItemLink::STATUS_SUGGESTED, ['supplier_match' => false]);
        $this->link($many, $b, ErpItemLink::STATUS_SUGGESTED, ['supplier_match' => true, 'supplier' => 'UVEX']);
        $this->item('SNONE', 'OKULARY NONE', 'no_code');
        $rejected = $this->item('SREJ', 'OKULARY REJ', 'rejected');
        $this->link($rejected, $b, ErpItemLink::STATUS_REJECTED, ['supplier_match' => true]);
        $confirmed = $this->item('SCONF', 'OKULARY CONF', 'confirmed');
        $this->link($confirmed, $b, ErpItemLink::STATUS_CONFIRMED, ['supplier_match' => true]);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->assertSame(['SONE', 'SWEAK'], $this->codes('proposals=one&sort=code&dir=asc'));
        $this->assertSame(['SMANY'], $this->codes('proposals=many'));
        // potwierdzona i odrzucona karta to decyzja, nie propozycja
        $this->assertSame(['SCONF', 'SNONE', 'SREJ'], $this->codes('proposals=none&sort=code&dir=asc'));
        $this->assertSame(['SMANY', 'SONE'], $this->codes('supplier_match=1&sort=code&dir=asc'));
        $this->assertSame(['SONE'], $this->codes('proposals=one&supplier_match=1'));
        $this->getJson('/api/admin/erp-items?proposals=two')->assertUnprocessable();

        // wiersz po decyzji ma wartość zapasu jak lista (ekran podmienia wiersz odpowiedzią)
        $this->postJson('/api/admin/erp-links/'.$oneLink->id.'/confirm')->assertOk()
            ->assertJsonPath('item.outcome', 'confirmed')
            ->assertJsonPath('item.stock_value', 42);
    }

    public function test_summary_counts_gaps(): void
    {
        $this->item('AKUR1', 'KURTKA 1', 'auto', stock: 3, sold: '-1 month');
        $this->item('AKUR2', 'KURTKA 2', 'no_match', stock: 0, sold: '-2 months');
        $this->item('BBUT1', 'BUTY 1', 'no_code', stock: 7, sold: '-30 months');
        $this->item('BBUT2', 'BUTY 2', 'ambiguous');
        $this->item('XINNE', 'INNE', 'confirmed');
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $summary = $this->getJson('/api/admin/erp-items/summary')->assertOk();

        $this->assertSame(5, $summary->json('total'));
        $this->assertSame(1, $summary->json('by_outcome.auto'));
        $this->assertSame(1, $summary->json('by_outcome.ambiguous'));
        $this->assertSame(1, $summary->json('unlinked_sold_12m'));
        $this->assertSame(1, $summary->json('unlinked_in_stock'));
        $this->assertSame([
            ['group' => 'A', 'total' => 2, 'linked' => 1],
            ['group' => 'B', 'total' => 2, 'linked' => 0],
            ['group' => 'other', 'total' => 1, 'linked' => 1],
        ], $summary->json('groups'));
    }

    public function test_decisions_survive_the_next_automatic_matching(): void
    {
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);
        $clear = $this->card('HW-OO-A70060', 'Honeywell', 'Okulary ochronne Honeywell A700.');
        $grey = $this->card('HW-OO-A70061', 'Honeywell', 'Okulary ochronne Honeywell A700.');
        $a700 = $this->item('SOK1015360', 'OKULARY A700 BEZB.', null, name1: '1015360');
        $uvex = $this->card('9174.065', 'UVEX', 'Okulary Skylite 9174.065');
        $wrong = $this->item('SOK9174065', 'OKULARY UVEX 9174.065', null, suppliers: ['UVEX']);
        $manualCard = $this->card('RR-TACTYL', 'Reis', 'Rękawice Tactyl');
        $manual = $this->item('ARĘKTACTYL', 'RĘKAWICE TACTYL', null);
        app(ErpItemMatcher::class)->refresh();
        $this->assertSame('name_suggested', $a700->refresh()->match_outcome);
        $this->assertSame('auto', $wrong->refresh()->match_outcome);

        // „To ta” przy jednej z propozycji z nazwy karty — druga znika
        $clearLink = ErpItemLink::query()->where('erp_item_id', $a700->id)->where('product_id', $clear->id)->sole();
        $response = $this->postJson('/api/admin/erp-links/'.$clearLink->id.'/confirm')->assertOk();
        $this->assertSame('confirmed', $response->json('item.outcome'));
        $this->assertSame([$clear->id], array_column(array_column($response->json('item.links'), 'product'), 'id'));
        $this->assertSame($user->name, $response->json('item.links.0.decided_by'));
        // odrzucenie automatu i ręczny wybór karty dla towaru bez kodu
        $wrongLink = ErpItemLink::query()->where('erp_item_id', $wrong->id)->sole();
        $this->postJson('/api/admin/erp-links/'.$wrongLink->id.'/reject')->assertOk()->assertJsonPath('item.outcome', 'rejected');
        $this->postJson('/api/admin/erp-items/'.$manual->id.'/link', ['product_id' => $manualCard->id])
            ->assertOk()
            ->assertJsonPath('item.outcome', 'confirmed')
            ->assertJsonPath('item.links.0.method', 'manual');

        $this->travel(1)->minutes();
        app(ErpItemMatcher::class)->refresh();

        $this->assertSame([[$clear->id, 'confirmed']], ErpItemLink::query()->where('erp_item_id', $a700->id)->get()->map(fn ($l) => [$l->product_id, $l->status])->all());
        $this->assertSame('confirmed', $a700->refresh()->match_outcome);
        $this->assertSame([[$uvex->id, 'rejected']], ErpItemLink::query()->where('erp_item_id', $wrong->id)->get()->map(fn ($l) => [$l->product_id, $l->status])->all());
        $this->assertSame('rejected', $wrong->refresh()->match_outcome);
        $this->assertSame('confirmed', $manual->refresh()->match_outcome);
        $this->assertSame(0, ErpItemLink::query()->where('product_id', $grey->id)->count());
    }

    public function test_bulk_confirm_takes_only_open_links(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $a = $this->card('A-1', 'UVEX', 'Okulary A');
        $b = $this->card('B-1', 'UVEX', 'Okulary B');
        $first = $this->item('SOKA', 'OKULARY A', 'suggested');
        $second = $this->item('SOKB', 'OKULARY B', 'auto');
        $l1 = $this->link($first, $a, ErpItemLink::STATUS_SUGGESTED);
        $l2 = $this->link($second, $b, ErpItemLink::STATUS_AUTO);
        $l3 = $this->link($second, $a, ErpItemLink::STATUS_REJECTED);

        $this->postJson('/api/admin/erp-links/bulk-confirm', ['ids' => [$l1->id, $l2->id, $l3->id]])
            ->assertOk()
            ->assertJsonPath('confirmed', 2);

        $this->assertSame(ErpItemLink::STATUS_CONFIRMED, $l1->refresh()->status);
        $this->assertSame(ErpItemLink::STATUS_CONFIRMED, $l2->refresh()->status);
        $this->assertSame(ErpItemLink::STATUS_REJECTED, $l3->refresh()->status);
        $this->assertSame('confirmed', $first->refresh()->match_outcome);
    }

    public function test_who_and_when_linked_for_reports(): void
    {
        $ala = User::factory()->withRole('admin')->create(['name' => 'Ala']);
        $bartek = User::factory()->withRole('admin')->create(['name' => 'Bartek']);
        $cardA = $this->card('A-1', 'UVEX', 'Okulary A');
        $cardB = $this->card('B-1', 'UVEX', 'Okulary B');
        $cardC = $this->card('C-1', 'UVEX', 'Okulary C');
        $cardD = $this->card('D-1', 'UVEX', 'Okulary D');

        $this->travelTo('2026-09-29 07:00:00');
        $auto = $this->item('SAUTO', 'AUTOMAT', 'auto');
        $this->link($auto, $cardD, ErpItemLink::STATUS_AUTO);
        $byAla = $this->item('SALA', 'ALA', 'suggested');
        $alaLink = $this->link($byAla, $cardB, ErpItemLink::STATUS_SUGGESTED);
        $byAla2 = $this->item('SALA2', 'ALA 2', 'no_code');
        $byBartek = $this->item('SBART', 'BARTEK', 'no_code');
        $open = $this->item('SOPEN', 'OTWARTY', 'suggested');
        $this->link($open, $cardA, ErpItemLink::STATUS_SUGGESTED);

        $this->travelTo('2026-10-01 10:00:00');
        Sanctum::actingAs($ala);
        $this->postJson('/api/admin/erp-links/'.$alaLink->id.'/confirm')->assertOk();
        $this->travelTo('2026-10-02 12:00:00');
        $this->postJson('/api/admin/erp-items/'.$byAla2->id.'/link', ['product_id' => $cardC->id])->assertOk();
        // 23:30 UTC 3.10 = 01:30 4.10 w Polsce
        $this->travelTo('2026-10-03 23:30:00');
        Sanctum::actingAs($bartek);
        $this->postJson('/api/admin/erp-items/'.$byBartek->id.'/link', ['product_id' => $cardA->id])->assertOk();

        $rows = collect($this->getJson('/api/admin/erp-items?sort=code&dir=asc')->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(['auto' => true, 'by' => null, 'at' => '2026-09-29T07:00:00+00:00', 'auto_at' => '2026-09-29T07:00:00+00:00'], $rows['SAUTO']['linked']);
        // potwierdzona propozycja — automat jej nie łączył
        $this->assertSame(['auto' => false, 'by' => 'Ala', 'at' => '2026-10-01T10:00:00+00:00', 'auto_at' => null], $rows['SALA']['linked']);
        $this->assertSame('Bartek', $rows['SBART']['linked']['by']);
        $this->assertNull($rows['SOPEN']['linked']);

        // filtr osoby i okresu (dni polskie)
        $this->assertSame(['SAUTO'], $this->codes('linked_by=auto'));
        $this->assertSame(['SALA', 'SALA2'], $this->codes('linked_by='.$ala->id.'&sort=code&dir=asc'));
        $this->assertSame(['SALA', 'SALA2'], $this->codes('linked_from=2026-10-01&linked_to=2026-10-03&sort=code&dir=asc'));
        $this->assertSame(['SBART'], $this->codes('linked_from=2026-10-04'));
        $this->assertSame(['SAUTO'], $this->codes('linked_to=2026-09-30'));
        $this->getJson('/api/admin/erp-items?linked_from=2026-10-04&linked_to=2026-10-01')->assertUnprocessable();

        // zestawienie: te same filtry, bez filtra osoby
        $period = $this->getJson('/api/admin/erp-items?linked_from=2026-10-01&linked_by='.$bartek->id)->assertOk();
        $this->assertSame(['SBART'], array_column($period->json('data'), 'code'));
        $this->assertSame([
            ['key' => (string) $ala->id, 'name' => 'Ala', 'count' => 2],
            ['key' => (string) $bartek->id, 'name' => 'Bartek', 'count' => 1],
        ], $period->json('meta.linkers'));
        $this->assertSame([
            ['key' => 'auto', 'name' => 'automat', 'count' => 1],
            ['key' => (string) $ala->id, 'name' => 'Ala', 'count' => 2],
            ['key' => (string) $bartek->id, 'name' => 'Bartek', 'count' => 1],
        ], $this->getJson('/api/admin/erp-items/summary')->json('linkers'));

        // sortowanie: niepołączone zawsze na końcu
        $this->assertSame(['SBART', 'SALA2', 'SALA', 'SAUTO', 'SOPEN'], $this->codes('sort=linked_at&dir=desc'));
        $this->assertSame(['SAUTO', 'SALA', 'SALA2', 'SBART', 'SOPEN'], $this->codes('sort=linked_at&dir=asc'));
        $this->assertSame(['SALA2', 'SALA', 'SAUTO', 'SBART', 'SOPEN'], $this->codes('sort=linked_by&dir=asc'));
        $this->assertSame(['SALA', 'SALA2', 'SBART', 'SAUTO', 'SOPEN'], $this->codes('sort=status&dir=asc&per_page=5'));
        $this->assertSame(['SBART', 'SOPEN', 'SALA', 'SALA2', 'SAUTO'], $this->codes('sort=card&dir=asc'));
    }

    public function test_confirming_what_the_automat_linked_keeps_the_automat_mark(): void
    {
        $user = User::factory()->withRole('admin')->create(['name' => 'Ala']);
        Sanctum::actingAs($user);
        $uvex = $this->card('9174.065', 'UVEX', 'Okulary Skylite 9174.065');
        $auto = $this->item('SOK9174065', 'OKULARY UVEX 9174.065', null, suppliers: ['UVEX']);
        $manualCard = $this->card('RR-TACTYL', 'Reis', 'Rękawice Tactyl');
        $manual = $this->item('ARĘKTACTYL', 'RĘKAWICE TACTYL', null);

        $this->travelTo('2026-10-01 08:00:00');
        app(ErpItemMatcher::class)->refresh();
        $link = ErpItemLink::query()->where('erp_item_id', $auto->id)->sole();
        $this->assertSame(ErpItemLink::STATUS_AUTO, $link->status);
        $this->assertSame('2026-10-01 08:00:00', $link->auto_linked_at?->format('Y-m-d H:i:s'));
        // kolejny przebieg nie przesuwa daty automatu
        $this->travelTo('2026-10-02 08:00:00');
        app(ErpItemMatcher::class)->refresh();
        $this->assertSame('2026-10-01 08:00:00', $link->refresh()->auto_linked_at?->format('Y-m-d H:i:s'));

        $this->travelTo('2026-10-03 09:00:00');
        $item = $this->postJson('/api/admin/erp-links/'.$link->id.'/confirm')->assertOk()->json('item');
        $this->assertSame('confirmed', $item['outcome']);
        $this->assertSame(['auto' => false, 'by' => 'Ala', 'at' => '2026-10-03T09:00:00+00:00', 'auto_at' => '2026-10-01T08:00:00+00:00'], $item['linked']);
        $this->assertSame('2026-10-01T08:00:00+00:00', $item['links'][0]['auto_linked_at']);
        $this->postJson('/api/admin/erp-items/'.$manual->id.'/link', ['product_id' => $manualCard->id])->assertOk()
            ->assertJsonPath('item.linked.auto_at', null);

        $this->assertSame(['SOK9174065'], $this->codes('status=confirmed_after_auto'));
        $this->assertSame(['ARĘKTACTYL', 'SOK9174065'], $this->codes('status=confirmed&sort=code&dir=asc'));
        // potwierdzenie przetrwało następny przebieg automatu razem z datą
        app(ErpItemMatcher::class)->refresh();
        $this->assertSame([ErpItemLink::STATUS_CONFIRMED, '2026-10-01 08:00:00'], [$link->refresh()->status, $link->auto_linked_at?->format('Y-m-d H:i:s')]);

        // odrzucone powiązanie automatu połączone potem ręcznie to już wybór człowieka, nie automatu
        $this->postJson('/api/admin/erp-links/'.$link->id.'/reject')->assertOk();
        $this->postJson('/api/admin/erp-items/'.$auto->id.'/link', ['product_id' => $uvex->id])->assertOk()
            ->assertJsonPath('item.linked.auto_at', null)
            ->assertJsonPath('item.links.0.method', 'manual');
    }

    public function test_excel_export_follows_filters_and_sorting(): void
    {
        $ala = User::factory()->withRole('admin')->create(['name' => 'Ala']);
        $cardA = $this->card('A-1', 'UVEX', 'Okulary A');
        $cardB = $this->card('B-1', 'UVEX', 'Okulary B');
        $this->travelTo('2026-09-29 07:00:00');
        $auto = $this->item('SAUTO', 'OKULARY "AUTO" <A&B>', 'auto', stock: 5, sold: '-1 day');
        $this->link($auto, $cardA, ErpItemLink::STATUS_AUTO);
        $byAla = $this->item('SALA', 'ALA', 'suggested', stock: 2);
        $alaLink = $this->link($byAla, $cardB, ErpItemLink::STATUS_SUGGESTED);
        $this->item('SOPEN', 'OTWARTY', 'no_code', stock: 9);
        $this->travelTo('2026-10-01 10:00:00');
        Sanctum::actingAs($ala);
        $this->postJson('/api/admin/erp-links/'.$alaLink->id.'/confirm')->assertOk();

        $response = $this->get('/api/admin/erp-items/export?status=linked&sort=code&dir=asc')->assertOk();
        $this->assertStringContainsString('powiazania-erp-xl-2026-10-01.xlsx', (string) $response->headers->get('Content-Disposition'));
        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());

        $rows = $book->getSheetByName('Towary')?->toArray(null, false, false);
        $this->assertNotNull($rows);
        $this->assertSame('Kod XL', $rows[0][0]);
        $this->assertSame(['SALA', 'SAUTO'], [$rows[1][0], $rows[2][0]]);
        $this->assertCount(3, $rows);
        $this->assertSame('OKULARY "AUTO" <A&B>', $rows[2][1]);
        $this->assertSame(5, (int) $rows[2][4]);
        // kolumna 6 to wartość zapasu (test zalegających); bez partii i bez PZ — pusta
        $this->assertNull($rows[2][6]);
        $this->assertSame(['potwierdzone', 'B-1', 'Ala'], [$rows[1][10], $rows[1][11], $rows[1][16]]);
        $this->assertSame(['połączone automatycznie', 'A-1', 'automat'], [$rows[2][10], $rows[2][11], $rows[2][16]]);
        // data połączenia: prawdziwa data Excela w czasie polskim (10:00 UTC = 12:00)
        $at = $book->getSheetByName('Towary')?->getCell('R2');
        $this->assertSame('2026-10-01 12:00', ExcelDate::excelToDateTimeObject((float) $at?->getValue())->format('Y-m-d H:i'));
        $this->assertSame('yyyy-mm-dd hh:mm', $at?->getStyle()->getNumberFormat()->getFormatCode());

        $summary = $book->getSheetByName('Zestawienie')?->toArray(null, false, false) ?? [];
        $flat = array_map(static fn (array $r): string => implode('|', array_map('strval', array_filter($r, static fn ($c) => $c !== null))), $summary);
        $this->assertContains('Towarów w pliku|2', $flat);
        $this->assertContains('Status|połączone (auto + potwierdzone)', $flat);
        $this->assertContains('automat|1', $flat);
        $this->assertContains('Ala|1', $flat);

        // filtr osoby zawęża towary, zestawienie zostaje bez niego
        $book = IOFactory::load($this->get('/api/admin/erp-items/export?linked_by='.$ala->id)->assertOk()->baseResponse->getFile()->getPathname());
        $this->assertSame([['Kod XL'], ['SALA']], array_map(static fn (array $r): array => [$r[0]], $book->getSheetByName('Towary')?->toArray(null, false, false) ?? []));

        $viewer = Role::findOrCreate('podglad-erp', 'web');
        $viewer->givePermissionTo(['admin.access', 'admin.erp_links.view']);
        // sam podgląd ekranu nie wystarcza — eksport ma osobne uprawnienie, domyślnie tylko admin
        Sanctum::actingAs(User::factory()->create()->assignRole($viewer));
        $this->getJson('/api/admin/erp-items/export')->assertForbidden();
        $viewer->givePermissionTo('admin.erp_links.export');
        $this->get('/api/admin/erp-items/export')->assertOk();
        $this->assertSame(['admin'], Permission::findByName('admin.erp_links.export', 'web')->roles()
            ->where('name', '!=', 'podglad-erp')->pluck('name')->all());
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson('/api/admin/erp-items/export')->assertForbidden();
    }

    /** @return list<string> */
    private function codes(string $query): array
    {
        return array_column($this->getJson('/api/admin/erp-items?'.$query)->assertOk()->json('data'), 'code');
    }

    private function card(string $sku, string $manufacturer, string $name): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => $manufacturer,
            'catalog_price_net' => 10, 'purchase_price' => 5, 'stock' => 0,
        ]);
    }

    /** @param  list<string>  $suppliers */
    private function item(
        string $code,
        string $name,
        ?string $outcome,
        float $stock = 0,
        ?string $sold = null,
        bool $archived = false,
        ?string $matchValue = null,
        ?string $supplier = null,
        string $name1 = '',
        array $suppliers = [],
        ?float $stockTotal = null,
        ?float $value = null,
        ?string $oldestLot = null,
    ): ErpItem {
        return ErpItem::query()->create([
            'xl_gid' => $this->gid++, 'code' => $code, 'name' => $name, 'name1' => $name1 !== '' ? $name1 : null,
            'unit' => 'szt', 'archived' => $archived, 'stock_trade' => $stock, 'stock_total' => $stockTotal ?? $stock,
            'stock_value' => $value,
            'oldest_lot_at' => $oldestLot !== null ? now()->modify($oldestLot)->toDateString() : null,
            'last_sale_at' => $sold !== null ? now()->modify($sold)->toDateString() : null,
            'last_supplier' => $supplier, 'match_outcome' => $outcome, 'match_value' => $matchValue, 'synced_at' => now(),
            'suppliers' => array_map(static fn (string $s): array => ['supplier' => $s], $suppliers),
        ]);
    }

    /** @param  array<string, mixed>|null  $evidence */
    private function link(ErpItem $item, Product $card, string $status, ?array $evidence = null): ErpItemLink
    {
        return ErpItemLink::query()->create([
            'erp_item_id' => $item->id, 'product_id' => $card->id, 'status' => $status,
            'method' => ErpItemLink::METHOD_NAME, 'matched_value' => $card->sku, 'evidence' => $evidence, 'last_seen_at' => now(),
        ]);
    }

    private function purchase(ErpItem $item, float $unitPrice): void
    {
        ErpItemPurchase::query()->create([
            'erp_item_id' => $item->id, 'document_type' => 1489, 'document_id' => $item->id, 'document_line' => 1,
            'purchased_at' => now()->subYear()->toDateString(), 'supplier' => 'X', 'quantity' => 10, 'document_unit' => 'szt',
            'net_value_pln' => 10 * $unitPrice, 'unit_price_pln' => $unitPrice, 'document_price' => $unitPrice, 'currency' => 'PLN',
        ]);
    }
}
