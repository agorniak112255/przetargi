<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\CardMatchCandidate;
use App\Models\CardRedirect;
use App\Models\Client;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Models\ProductPriceHistory;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Catalog\CardMatchFinder;
use App\Services\Pricing\SourcePriceComparison;
use App\Support\ProductIdentifierCode;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * „Rozdziel” (plan łączenia kart, krok 7) — prawdziwe reguły dopasowania (CardMatchFinder), bez atrapy, poza testem
 * strażników niezależnych od reguł propozycji. Przypadek z produkcji 25.09.2026: P4S „2365X0 Hełm ochronny 3M
 * SecureFit serii X5000VE-CE 1000V z wentylacją (LD)” (391,60 zł, zamawiane po 4 szt.) to grupa czterech kolorów
 * 236510/236520/236540/236570, a 3M ma kartę na każdy kolor (7100175101 biały, 7100175511 żółty, 7100175512
 * niebieski, 7100175534 czerwony; po 269,99 zł, konto 3M).
 */
final class CardMatchSplitterTest extends TestCase
{
    use RefreshDatabase;

    private const COLORS = [
        ['236510', '7100175101', 'biały', 'X5001VE-CE'],
        ['236520', '7100175511', 'żółty', 'X5002VE-CE'],
        ['236540', '7100175512', 'niebieski', 'X5003VE-CE'],
        ['236570', '7100175534', 'czerwony', 'X5005VE-CE'],
    ];

    private B2bAccount $mmm;

    private B2bAccount $p4s;

    private User $admin;

    private string $storage;

    private Carbon $checkedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        // kopie zapasowe w katalogu tego testu — testy równoległe mają te same numery propozycji
        $this->storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'card-match-split-'.uniqid('', true);
        mkdir($this->storage.DIRECTORY_SEPARATOR.'app', 0775, true);
        $this->app->useStoragePath($this->storage);

        $this->mmm = $this->account('mmm', '3m');
        $this->p4s = $this->account('p4s', 'p4s');
        $this->admin = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($this->admin);
        $this->checkedAt = now()->subDays(2)->startOfSecond();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_2365x0_four_colors_go_to_four_3m_cards_and_p4s_card_disappears(): void
    {
        [$targets, $source] = $this->helmet();
        $candidate = $this->refreshed($source);
        $this->assertSame('split', $candidate->kind);
        $this->assertSame('pending', $candidate->status, (string) $candidate->reason);
        $this->assertSame('color', $candidate->plan['signal']);
        $run = B2bSyncRun::query()->where('b2b_account_id', $this->p4s->id)->sole();
        $otherList = PriceList::query()->create(['manufacturer' => 'Hurtownia', 'version' => '1', 'product_ids' => [$source->id, $targets[0]->id]]);
        // wpis mapy martwej pozycji, która kiedyś trafiała na kartę P4S — po rozdzieleniu decyzja bez karty
        CardRedirect::query()->create([
            'source_key' => 'b2b:'.$this->p4s->id, 'position_key' => '236599', 'b2b_account_id' => $this->p4s->id,
            'product_id' => $source->id, 'reason' => CardRedirect::REASON_MERGE,
        ]);

        $response = $this->postSplit($candidate)
            ->assertOk()
            ->assertJsonPath('id', $candidate->id)
            ->assertJsonPath('status', 'merged')
            ->assertJsonPath('kind', 'split')
            ->assertJsonPath('source', null)
            ->assertJsonPath('source_snapshot.sku', '2365X0')
            ->assertJsonPath('decided_by.id', $this->admin->id)
            ->assertJsonPath('decision_input.kind', 'split')
            ->assertJsonPath('decision_input.plan_hash', $candidate->plan_hash)
            ->assertJsonPath('decision_input.source_product_id', $source->id)
            ->assertJsonPath('decision_input.target_product_ids', array_map(static fn (Product $p): int => $p->id, $targets))
            ->assertJsonPath('decision_input.identifiers_moved', 4)
            ->assertJsonPath('decision_input.identifiers_dropped', 0)
            ->assertJsonPath('decision_input.slots_replaced', [])
            ->assertJsonPath('decision_input.redirects_orphaned', [['source_key' => 'b2b:'.$this->p4s->id, 'position_key' => '236599']]);
        $this->assertSame([], (array) $response->json('decision_input.card_changes'));
        $positions = (array) $response->json('decision_input.positions');
        $this->assertCount(4, $positions);
        foreach (self::COLORS as $i => [$remote, $code, $color]) {
            $this->assertSame([
                'source_key' => 'b2b:'.$this->p4s->id,
                'source_label' => app(SourcePriceComparison::class)->accountLabel($this->p4s),
                'position_key' => $remote,
                'remote_sku' => $remote,
                'label' => 'kolor '.$color,
                'target_product_id' => $targets[$i]->id,
                'target_sku' => $code,
            ], $positions[$i]);
        }
        $this->assertSame('2365X0', $response->json('decision_input.cards_before.0.sku'));
        $this->assertSame('391.60', $response->json('decision_input.cards_before.0.purchase_price'));
        $this->assertSame('7100175101', $response->json('decision_input.cards_before.1.sku'));

        // karta P4S znika, karty 3M zostają ze swoją tożsamością i ceną właściciela
        $this->assertNull(Product::query()->find($source->id));
        $this->assertSame(4, Product::query()->count());
        foreach (self::COLORS as $i => [$remote, $code, $color, $model]) {
            $card = $targets[$i]->fresh();
            $this->assertSame($code, $card->sku);
            $this->assertSame('Hełm ochronny 3M™ SecureFit™ X5000, wentylowany, 1000 V, CE, '.$color.', '.$model, $card->name);
            $this->assertSame('Hełm 3M SecureFit X5000 '.$color.' — opis producenta.', $card->description);
            $this->assertSame('269.99', (string) $card->purchase_price);

            // powiązanie i identyfikator pozycji tego koloru, bez znacznika scalenia
            $link = B2bProductLink::query()->where('product_id', $card->id)->where('b2b_account_id', $this->p4s->id)->sole();
            $this->assertSame($remote, (string) $link->remote_id);
            $this->assertNull($link->merged_at);
            $this->assertSame('391.60', (string) $link->last_purchase_price);
            $identifier = ProductIdentifier::query()->where('source_key', 'b2b:'.$this->p4s->id)->where('position_key', $remote)->sole();
            $this->assertSame($card->id, (int) $identifier->product_id);

            // mapa: pozycja → karta tego koloru, powód split
            $row = CardRedirect::query()->where('source_key', 'b2b:'.$this->p4s->id)->where('position_key', $remote)->sole();
            $this->assertSame($card->id, $row->product_id);
            $this->assertSame('split', $row->reason);
            $this->assertFalse($row->is_anchor);
            $this->assertSame($remote, $row->remote_sku);
            $this->assertSame('kolor '.$color, $row->position_label);
            $this->assertSame($code, $row->target_snapshot['sku']);
            $this->assertSame($candidate->id, (int) $row->card_match_candidate_id);

            // slot P4S: cena, data potwierdzenia i warunek zamawiania karty P4S; dostępność całej grupy — nie
            $slot = ProductSourcePrice::query()->where('product_id', $card->id)->where('source_key', 'b2b:'.$this->p4s->id)->sole();
            $this->assertSame('391.60', (string) $slot->purchase_price);
            $this->assertSame('PLN', $slot->currency);
            $this->assertSame($this->checkedAt->getTimestamp(), $slot->checked_at?->getTimestamp());
            $this->assertEquals(4.0, $slot->order_min_qty);
            $this->assertEquals(4.0, $slot->order_step_qty);
            $this->assertNull($slot->availability);
            $this->assertSame((int) $this->p4s->id, (int) $slot->b2b_account_id);
            // slot 3M bez zmian
            $own = ProductSourcePrice::query()->where('product_id', $card->id)->where('source_key', 'b2b:'.$this->mmm->id)->sole();
            $this->assertSame('269.99', (string) $own->purchase_price);

            // historia: wiersz konta P4S z przebiegu P4S — punkt odniesienia kolejnych zmian
            $history = ProductPriceHistory::query()->where('product_id', $card->id)->sole();
            $this->assertSame($run->id, (int) $history->b2b_sync_run_id);
            $this->assertSame('391.60', (string) $history->purchase_price);
            $this->assertSame('b2b:p4s', $history->source);

            // tabelka P4S i odrzucone zdjęcie
            $shop = ProductShopCard::query()->where('product_id', $card->id)->where('b2b_account_id', $this->p4s->id)->sole();
            $this->assertSame('EN 397', $shop->fields[0]['rows'][0]['value']);
            $this->assertStringContainsString('Norma: EN 397', (string) $card->shop_fields_summary);
            $this->assertTrue(ProductImageRejection::query()->where('product_id', $card->id)->where('file_key', 'https://b2b.p4s.example.test/2365X0-obce.jpg')->exists());
            // zdjęcie P4S nie przechodzi — karta 3M ma swoje
            $this->assertSame(1, ProductImage::query()->where('product_id', $card->id)->count());
        }

        // mapa: 4 pozycje split, martwa pozycja bez karty
        $this->assertSame(4, CardRedirect::query()->where('reason', 'split')->count());
        $this->assertNull(CardRedirect::query()->where('position_key', '236599')->sole()->product_id);

        // cenniki: wpis konta P4S dostaje karty 3M zamiast karty P4S, inny cennik tylko ją traci
        $this->assertSame(
            [999999, ...array_map(static fn (Product $p): int => $p->id, $targets)],
            array_map('intval', PriceList::query()->find($this->p4s->fresh()->last_price_list_id)->product_ids),
        );
        $this->assertSame([$targets[0]->id], array_map('intval', $otherList->fresh()->product_ids));

        // propozycja, kopia zapasowa z pięcioma kartami, dziennik
        $merged = $candidate->fresh();
        $this->assertSame('merged', $merged->status);
        $path = (string) $merged->backup_path;
        $this->assertFileExists($path);
        $this->assertStringContainsString('card-match-split-'.$candidate->id.'-', $path);
        $backup = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('card-match-split', $backup['kind']);
        $this->assertSame(['source', 'target', 'target', 'target', 'target'], array_column($backup['cards'], 'role'));
        $this->assertSame($source->id, $backup['source_product_id']);
        $this->assertCount(4, $backup['positions']);
        $this->assertCount(4, $backup['cards'][0]['rows']['b2b_product_links']);
        $this->assertCount(1, $backup['cards'][0]['rows']['product_price_history']);
        $this->assertCount(1, $backup['cards'][0]['rows']['product_images']);
        $log = ActivityLog::query()->where('action', 'card_match.split')->sole();
        $this->assertSame(CardMatchCandidate::class, $log->subject_type);
        $this->assertSame($candidate->id, (int) $log->subject_id);
        $this->assertSame('Rozdzielenie: 2365X0 → 7100175101, 7100175511, 7100175512, 7100175534', $log->meta['label']);

        // ponowne odświeżenie niczego nie proponuje, decyzja zostaje; drugi POST — już nie do decyzji
        app(CardMatchFinder::class)->refresh();
        $this->assertSame(0, CardMatchCandidate::query()->whereIn('status', ['pending', 'conflict'])->count());
        $this->assertSame('merged', $candidate->fresh()->status);
        $this->postSplit($candidate)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Propozycja #'.$candidate->id.' nie jest rozdzielaniem do decyzji.');
    }

    public function test_plan_hash_from_screen_or_changed_plan_gives_409_and_changes_nothing(): void
    {
        [$targets, $source] = $this->helmet();
        $candidate = $this->refreshed($source);

        $this->postSplit($candidate, ['plan_hash' => str_repeat('a', 40)])
            ->assertStatus(409)
            ->assertJsonPath('code', 'plan_changed')
            ->assertJsonPath('message', 'Propozycja zmieniła się od wczytania ekranu — odśwież listę.');

        // po odświeżeniu P4S dodał piąty kolor z kartą 3M — plan ma inne pozycje
        $green = $this->mmmCard('7100175999', 'zielony', 'X5004VE-CE');
        B2bProductLink::query()->create(['b2b_account_id' => $this->p4s->id, 'remote_id' => '236580', 'remote_sku' => '236580', 'product_id' => $source->id, 'last_purchase_price' => 391.60, 'last_currency' => 'PLN']);
        $this->identifier($source, '236580', '7100175999', 'kolor zielony');
        $this->postSplit($candidate)
            ->assertStatus(409)
            ->assertJsonPath('code', 'plan_changed')
            ->assertJsonPath('message', 'Plan pozycji zmienił się od odświeżenia propozycji — odśwież listę.');

        $this->assertNothingChanged($candidate, $source, 6);
        $this->assertNotNull($green->fresh());
        $this->assertCount(4, $targets);
    }

    public function test_guards_refuse_with_422_before_any_write(): void
    {
        [$targets, $source] = $this->helmet();
        $candidate = $this->refreshed($source);
        // strażnicy niezależni od reguł propozycji — ocena zawsze potwierdza plan z chwili odświeżenia
        $result = app(CardMatchFinder::class)->evaluate($source, true);
        $this->assertSame('pending', $result['status']);
        $this->app->instance(CardMatchFinder::class, new class($result)
        {
            /** @param  array<string, mixed>  $result */
            public function __construct(private readonly array $result) {}

            /** @return array<string, mixed> */
            public function evaluate(Product $source, bool $withPlan = false): array
            {
                return $this->result;
            }
        });
        $p4sLabel = app(SourcePriceComparison::class)->accountLabel($this->p4s);

        // karta P4S w przetargu
        $item = TenderItem::query()->create(['tender_id' => $this->tender()->id, 'line_no' => 1, 'requirement' => 'Hełm', 'main_product_id' => $source->id]);
        $this->postSplit($candidate)->assertStatus(422)
            ->assertJsonPath('message', 'Karta dystrybutora 2365X0 jest w pozycjach przetargów (1) — rozdzielenie wyłączone.');
        $item->delete();

        // cena specjalna karty P4S
        DB::table('product_special_prices')->insert([
            'product_id' => $source->id, 'client_name' => 'Szpital', 'price' => 350, 'currency' => 'PLN',
            'contract_ref' => '', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postSplit($candidate)->assertStatus(422)
            ->assertJsonPath('message', 'Karta dystrybutora 2365X0 ma ceny specjalne (1) — rozdzielenie wyłączone.');
        DB::table('product_special_prices')->delete();

        // karta 3M ma już pozycję konta P4S (sztuka / karton)
        $carton = B2bProductLink::query()->create(['b2b_account_id' => $this->p4s->id, 'remote_id' => 'K-236510', 'remote_sku' => 'K-236510', 'product_id' => $targets[0]->id]);
        $this->postSplit($candidate)->assertStatus(422)
            ->assertJsonPath('message', 'Karta 7100175101 ma już pozycję konta '.$p4sLabel.' (K-236510) — sztuka czy karton? Rozdzielenie wyłączone.');
        $carton->delete();

        // powiązanie pozycji planu przeszło na inną kartę (spoza planu)
        $other = Product::query()->create(['sku' => '236520', 'name' => 'Hełm 3M żółty P4S', 'manufacturer' => '3M', 'catalog_price_net' => 391.60, 'purchase_price' => 391.60, 'currency' => 'PLN']);
        $link = B2bProductLink::query()->where('remote_id', '236520')->sole();
        $link->forceFill(['product_id' => $other->id])->save();
        $this->postSplit($candidate)->assertStatus(422)
            ->assertJsonPath('message', 'Pozycja 236520 jest już na innej karcie (#'.$other->id.') — odśwież propozycje.');
        $link->forceFill(['product_id' => $source->id])->save();
        $other->delete();

        // mapa połączeń kieruje pozycję planu na inną kartę
        $redirect = CardRedirect::query()->create([
            'source_key' => 'b2b:'.$this->p4s->id, 'position_key' => '236540', 'b2b_account_id' => $this->p4s->id,
            'product_id' => $targets[0]->id, 'reason' => CardRedirect::REASON_MERGE,
        ]);
        $this->postSplit($candidate)->assertStatus(422)
            ->assertJsonPath('message', 'Pozycja 236540: mapa połączeń kieruje ją już na kartę #'.$targets[0]->id.' — odśwież propozycje.');
        $redirect->delete();

        // pozycja ostatnio w innej cenie niż karta P4S — kopia slotu dałaby karcie 3M cenę innej pozycji
        B2bProductLink::query()->where('remote_id', '236570')->update(['last_purchase_price' => 402.10]);
        $this->postSplit($candidate)->assertStatus(422)
            ->assertJsonPath('message', 'Pozycja 236570 ma ostatnio cenę 402,10 PLN, a karta dystrybutora 391,60 PLN — pozycje w różnych cenach, rozdzielenie wyłączone.');
        B2bProductLink::query()->where('remote_id', '236570')->update(['last_purchase_price' => 391.60]);

        // karta 3M straciła właściciela
        B2bProductLink::query()->where('product_id', $targets[1]->id)->where('b2b_account_id', $this->mmm->id)->update(['b2b_account_id' => $this->p4s->id, 'remote_id' => 'X-1']);
        $this->postSplit($candidate)->assertStatus(422)
            ->assertJsonPath('message', 'Karta 7100175511 nie ma już właściciela (konta B2B ani cennika producenta) — odśwież propozycje.');
        B2bProductLink::query()->where('remote_id', 'X-1')->update(['b2b_account_id' => $this->mmm->id, 'remote_id' => '7100175511']);

        // trwająca synchronizacja konta P4S — status konta albo wpis przebiegu
        $this->p4s->forceFill(['last_sync_status' => 'running'])->save();
        $this->postSplit($candidate)->assertStatus(422)
            ->assertJsonPath('message', 'Trwa synchronizacja konta '.$p4sLabel.' — spróbuj po jej zakończeniu.');
        $this->p4s->forceFill(['last_sync_status' => 'ok'])->save();
        $run = B2bSyncRun::query()->create(['b2b_account_id' => $this->mmm->id, 'status' => B2bSyncRun::STATUS_RUNNING, 'trigger' => B2bSyncRun::TRIGGER_MANUAL, 'started_at' => now()]);
        $this->postSplit($candidate)->assertStatus(422)
            ->assertJsonPath('message', 'Trwa synchronizacja konta '.app(SourcePriceComparison::class)->accountLabel($this->mmm).' — spróbuj po jej zakończeniu.');
        $run->forceFill(['status' => B2bSyncRun::STATUS_OK])->save();

        // walidacja
        $this->postSplit($candidate, ['confirm_split' => null])->assertStatus(422)->assertJsonValidationErrors('confirm_split');
        $this->postSplit($candidate, ['confirm_split' => false])->assertStatus(422)->assertJsonValidationErrors('confirm_split');
        $this->postSplit($candidate, ['plan_hash' => 'krótki'])->assertStatus(422)->assertJsonValidationErrors('plan_hash');

        $this->assertNothingChanged($candidate, $source, 5);
    }

    public function test_price_change_of_3m_card_in_tender_rolls_everything_back(): void
    {
        [$targets, $source] = $this->helmet();
        // karta 3M bez slotu 3M (właściciel z powiązania) — po rozdzieleniu jej cenę dałby slot P4S
        ProductSourcePrice::query()->where('product_id', $targets[2]->id)->where('source_key', 'b2b:'.$this->mmm->id)->delete();
        TenderItem::query()->create(['tender_id' => $this->tender()->id, 'line_no' => 1, 'requirement' => 'Hełm niebieski', 'main_product_id' => $targets[2]->id]);
        $candidate = $this->refreshed($source);
        $this->assertSame('pending', $candidate->status, (string) $candidate->reason);

        $this->postSplit($candidate)->assertStatus(422)
            ->assertJsonPath('message', 'Karta 7100175512 jest w pozycjach przetargów (1), a rozdzielenie zmieniłoby jej cenę (269,99 PLN → 391,60 PLN) — rozdzielenie wyłączone.');

        $this->assertNothingChanged($candidate, $source, 5);
        $this->assertSame('269.99', (string) $targets[2]->fresh()->purchase_price);
    }

    public function test_orphan_slot_and_shop_card_on_3m_card_are_replaced_and_recorded(): void
    {
        [$targets, $source] = $this->helmet();
        // sierota: slot i tabelka P4S na karcie 3M bez powiązania P4S (pozycja, której tu już nie ma)
        ProductSourcePrice::query()->create([
            'product_id' => $targets[0]->id, 'source_key' => 'b2b:'.$this->p4s->id, 'b2b_account_id' => $this->p4s->id,
            'purchase_price' => 350.00, 'catalog_price_net' => 350.00, 'currency' => 'PLN', 'checked_at' => now()->subDays(40),
        ]);
        ProductShopCard::query()->create([
            'product_id' => $targets[0]->id, 'b2b_account_id' => $this->p4s->id, 'source_url' => 'https://b2b.p4s.example.test/stary',
            'fields' => [['section' => 'Dane', 'rows' => [['name' => 'Norma', 'value' => 'stara']]]], 'synced_at' => now()->subDays(40),
        ]);
        $candidate = $this->refreshed($source);

        $response = $this->postSplit($candidate)->assertOk();

        $replaced = (array) $response->json('decision_input.slots_replaced');
        $this->assertCount(1, $replaced);
        $this->assertSame($targets[0]->id, $replaced[0]['product_id']);
        $this->assertSame('b2b:'.$this->p4s->id, $replaced[0]['source_key']);
        $this->assertSame('350.00', $replaced[0]['purchase_price']);
        $slot = ProductSourcePrice::query()->where('product_id', $targets[0]->id)->where('source_key', 'b2b:'.$this->p4s->id)->sole();
        $this->assertSame('391.60', (string) $slot->purchase_price);
        $this->assertSame($this->checkedAt->getTimestamp(), $slot->checked_at?->getTimestamp());
        $shop = ProductShopCard::query()->where('product_id', $targets[0]->id)->where('b2b_account_id', $this->p4s->id)->sole();
        $this->assertSame('EN 397', $shop->fields[0]['rows'][0]['value']);
    }

    public function test_second_distributor_account_goes_only_to_card_of_its_position(): void
    {
        [$targets, $source] = $this->helmet();
        $rawpol = $this->account('rawpol', 'rawpol');
        B2bProductLink::query()->create(['b2b_account_id' => $rawpol->id, 'remote_id' => 'RP-1', 'remote_sku' => 'RP-2365-B', 'product_id' => $source->id]);
        ProductIdentifier::query()->create([
            'product_id' => $source->id, 'source_key' => 'b2b:'.$rawpol->id, 'position_key' => 'RP-1', 'type' => ProductIdentifier::TYPE_MANUFACTURER_CODE,
            'value' => '7100175101', 'normalized' => '7100175101', 'manufacturer' => '3M', 'last_seen_at' => now(),
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $source->id, 'source_key' => 'b2b:'.$rawpol->id, 'b2b_account_id' => $rawpol->id,
            'purchase_price' => 380.00, 'catalog_price_net' => 380.00, 'currency' => 'PLN', 'availability' => 'dostępny', 'checked_at' => now()->subDay(),
        ]);
        $candidate = $this->refreshed($source);
        $this->assertSame('split', $candidate->kind);
        $this->assertSame('pending', $candidate->status, (string) $candidate->reason);

        $this->postSplit($candidate)->assertOk();

        $rawpolKey = 'b2b:'.$rawpol->id;
        $this->assertSame([$targets[0]->id], ProductSourcePrice::query()->where('source_key', $rawpolKey)->pluck('product_id')->map(static fn ($id): int => (int) $id)->all());
        // jedna pozycja konta — dostępność zostaje
        $this->assertSame('dostępny', ProductSourcePrice::query()->where('source_key', $rawpolKey)->sole()->availability);
        $this->assertSame(4, ProductSourcePrice::query()->where('source_key', 'b2b:'.$this->p4s->id)->count());
        $this->assertSame($targets[0]->id, (int) B2bProductLink::query()->where('b2b_account_id', $rawpol->id)->sole()->product_id);
        $this->assertSame($targets[0]->id, CardRedirect::query()->where('source_key', $rawpolKey)->sole()->product_id);
        // historia konta Raw-Pol bez przebiegu tego konta — źródło po kluczu łącznika
        $this->assertSame(['b2b:p4s', 'b2b:rawpol'], ProductPriceHistory::query()->where('product_id', $targets[0]->id)->orderBy('source')->pluck('source')->all());
    }

    public function test_file_position_makes_split_plan_uncertain_and_refused(): void
    {
        [, $source] = $this->helmet();
        $list = PriceList::query()->create(['manufacturer' => 'Hurtownia', 'version' => '2026-09']);
        ProductIdentifier::query()->create([
            'product_id' => $source->id, 'source_key' => 'file:'.$list->id, 'position_key' => 'H-2365', 'price_list_id' => $list->id,
            'type' => ProductIdentifier::TYPE_MANUFACTURER_CODE, 'value' => '7100175534', 'normalized' => '7100175534',
            'manufacturer' => '3M', 'last_seen_at' => now(),
        ]);

        $candidate = $this->refreshed($source);

        $this->assertSame('split', $candidate->kind);
        $this->assertSame('conflict', $candidate->status);
        $this->assertStringContainsString('pozycja H-2365 pochodzi z pliku', (string) $candidate->reason);
        $this->assertContains('split_file_position', array_column($candidate->plan['blockers'], 'code'));
        $this->postSplit($candidate)->assertStatus(422)
            ->assertJsonPath('message', 'Propozycja #'.$candidate->id.' nie jest rozdzielaniem do decyzji.');
    }

    public function test_split_requires_decide_permission(): void
    {
        [, $source] = $this->helmet();
        $candidate = $this->refreshed($source);
        $viewer = User::factory()->withRole('handlowiec')->create();
        $viewer->givePermissionTo('card_matches.view');
        Sanctum::actingAs($viewer);

        $this->postSplit($candidate)->assertForbidden();

        $this->assertNothingChanged($candidate, $source, 5);
    }

    private function assertNothingChanged(CardMatchCandidate $candidate, Product $source, int $cards): void
    {
        $this->assertSame($cards, Product::query()->count());
        $this->assertNotNull($source->fresh());
        $fresh = $candidate->fresh();
        $this->assertSame(CardMatchCandidate::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->decided_by);
        $this->assertNull($fresh->backup_path);
        $this->assertNull($fresh->decision_input);
        $this->assertSame(0, CardRedirect::query()->where('reason', CardRedirect::REASON_SPLIT)->count());
        $this->assertSame(0, ActivityLog::query()->where('action', 'card_match.split')->count());
        $this->assertSame(0, ProductSourcePrice::query()->where('source_key', 'b2b:'.$this->p4s->id)->where('product_id', '!=', $source->id)->count());
        $this->assertSame(0, ProductPriceHistory::query()->where('product_id', '!=', $source->id)->count());
        $this->assertSame(4, B2bProductLink::query()->where('product_id', $source->id)->where('b2b_account_id', $this->p4s->id)->whereIn('remote_id', array_column(self::COLORS, 0))->count());
        // kopia zapasowa wycofanego rozdzielenia nie zostaje
        $this->assertSame([], glob($this->storage.'/app/repair-backups/card-match-split-*.json') ?: []);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postSplit(CardMatchCandidate $candidate, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/card-matches/'.$candidate->id.'/split', array_merge([
            'plan_hash' => $candidate->plan_hash,
            'confirm_split' => true,
        ], $overrides));
    }

    private function refreshed(Product $source): CardMatchCandidate
    {
        app(CardMatchFinder::class)->refresh();

        return CardMatchCandidate::query()->where('source_product_id', $source->id)->sole();
    }

    /**
     * Cztery karty 3M (konto 3M, pozycja = SKU = kod producenta, cena 269,99, zdjęcie, opis) i karta P4S 2365X0
     * z czterema kolorami: slot P4S (391,60, zamawiane po 4 szt., dostępność grupy), powiązania z ostatnią ceną,
     * kody producenta z etykietą koloru, zdjęcie, tabelka, odrzucone zdjęcie, wiersz historii z przebiegu P4S i wpis
     * konta P4S w Cennikach.
     *
     * @return array{0: list<Product>, 1: Product}
     */
    private function helmet(): array
    {
        $targets = [];
        foreach (self::COLORS as [, $code, $color, $model]) {
            $targets[] = $this->mmmCard($code, $color, $model);
        }

        $source = Product::query()->create([
            'sku' => '2365X0', 'name' => 'Hełm ochronny 3M SecureFit serii X5000VE-CE 1000V z wentylacją (LD)', 'manufacturer' => '3M',
            'description' => 'Hełm SecureFit — opis P4S.', 'catalog_price_net' => 391.60, 'purchase_price' => 391.60, 'currency' => 'PLN',
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $source->id, 'source_key' => 'b2b:'.$this->p4s->id, 'b2b_account_id' => $this->p4s->id,
            'purchase_price' => 391.60, 'catalog_price_net' => 391.60, 'currency' => 'PLN', 'checked_at' => $this->checkedAt,
            'availability' => 'Produkt dostępny: kolor biały, kolor żółty; niedostępny: kolor czerwony',
            'order_min_qty' => 4, 'order_step_qty' => 4, 'order_unit' => 'szt',
        ]);
        ProductImage::query()->create(['product_id' => $source->id, 'b2b_account_id' => $this->p4s->id, 'path' => '2365X0.jpg', 'is_primary' => true, 'sort_order' => 0, 'checksum' => 'p-2365X0']);
        foreach (self::COLORS as [$remote, $code, $color]) {
            B2bProductLink::query()->create([
                'b2b_account_id' => $this->p4s->id, 'remote_id' => $remote, 'remote_sku' => $remote, 'product_id' => $source->id,
                'last_purchase_price' => 391.60, 'last_currency' => 'PLN', 'last_price_at' => now(),
            ]);
            $this->identifier($source, $remote, $code, 'kolor '.$color);
        }
        ProductShopCard::query()->create([
            'product_id' => $source->id, 'b2b_account_id' => $this->p4s->id, 'source_url' => 'https://b2b.p4s.example.test/2365X0',
            'fields' => [['section' => 'Dane', 'rows' => [['name' => 'Norma', 'value' => 'EN 397']]]], 'synced_at' => now(),
        ]);
        ProductImageRejection::query()->create([
            'product_id' => $source->id, 'file_key_hash' => hash('sha256', 'https://b2b.p4s.example.test/2365X0-obce.jpg'),
            'file_key' => 'https://b2b.p4s.example.test/2365X0-obce.jpg', 'source_url' => 'https://b2b.p4s.example.test/2365X0-obce.jpg', 'reason' => 'obce',
        ]);
        $run = B2bSyncRun::query()->create(['b2b_account_id' => $this->p4s->id, 'status' => B2bSyncRun::STATUS_OK, 'trigger' => B2bSyncRun::TRIGGER_MANUAL, 'started_at' => now()]);
        $list = PriceList::query()->create(['manufacturer' => 'P4S', 'version' => 'B2B', 'product_ids' => [999999, $source->id]]);
        $this->p4s->forceFill(['last_price_list_id' => $list->id])->save();
        ProductPriceHistory::query()->create([
            'product_id' => $source->id, 'price_list_id' => $list->id, 'b2b_sync_run_id' => $run->id,
            'catalog_price_net' => 391.60, 'purchase_price' => 391.60, 'currency' => 'PLN', 'source' => 'b2b:p4s',
        ]);

        return [$targets, $source];
    }

    private function mmmCard(string $code, string $color, string $model): Product
    {
        $card = Product::query()->create([
            'sku' => $code, 'name' => 'Hełm ochronny 3M™ SecureFit™ X5000, wentylowany, 1000 V, CE, '.$color.', '.$model, 'manufacturer' => '3M',
            'description' => 'Hełm 3M SecureFit X5000 '.$color.' — opis producenta.',
            'catalog_price_net' => 269.99, 'purchase_price' => 269.99, 'currency' => 'PLN',
        ]);
        B2bProductLink::query()->create(['b2b_account_id' => $this->mmm->id, 'remote_id' => $code, 'remote_sku' => $code, 'product_id' => $card->id]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => 'b2b:'.$this->mmm->id, 'b2b_account_id' => $this->mmm->id,
            'purchase_price' => 269.99, 'catalog_price_net' => 269.99, 'currency' => 'PLN', 'checked_at' => now(),
        ]);
        ProductImage::query()->create(['product_id' => $card->id, 'b2b_account_id' => $this->mmm->id, 'path' => $code.'.jpg', 'is_primary' => true, 'sort_order' => 0, 'checksum' => 'm-'.$code]);

        return $card;
    }

    private function identifier(Product $card, string $position, string $code, string $label): void
    {
        ProductIdentifier::query()->create([
            'product_id' => $card->id,
            'source_key' => 'b2b:'.$this->p4s->id,
            'position_key' => $position,
            'type' => ProductIdentifier::TYPE_MANUFACTURER_CODE,
            'value' => $code,
            'normalized' => ProductIdentifierCode::normalize(ProductIdentifier::TYPE_MANUFACTURER_CODE, $code),
            'variant_label' => $label,
            'manufacturer' => $card->manufacturer,
            'last_seen_at' => now(),
        ]);
    }

    private function tender(): Tender
    {
        return Tender::query()->create([
            'number' => 'PRZ/7', 'title' => 'Test', 'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => $this->admin->id, 'status' => 'wycena', 'ai_percent' => 0, 'last_activity_at' => now(),
        ]);
    }

    private function account(string $username, string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $username, 'password' => 'x', 'connector' => $connector, 'sites' => ['b2b.'.$connector.'.example.test'],
        ]);
    }
}
