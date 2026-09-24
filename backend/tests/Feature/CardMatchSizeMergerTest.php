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
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductSourcePrice;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Catalog\CardMatchFinder;
use App\Services\Catalog\CardRedirectStore;
use App\Support\ProductIdentifierCode;
use Database\Seeders\RolesAndPermissionsSeeder;
use DomainException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * „Połącz rozmiary” (plan łączenia kart, krok 6) — prawdziwe reguły dopasowania (CardMatchFinder), bez atrapy.
 * Przypadek z produkcji: 3M ma kartę na rozmiar (6100 S 7000146845, 6200 M 7000146847, 6300 L 7000146849, po 61,38 zł,
 * konto 3M, pozycje pojedyncze), P4S „6X00 Półmaska 3M 6000” to jedna karta z trzema rozmiarami (#56362), a P4S
 * „6X00P” (M, L) wskazuje karty M i L.
 */
final class CardMatchSizeMergerTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = '6X00 Półmaska 3M 6000';

    private B2bAccount $mmm;

    private B2bAccount $p4s;

    private User $admin;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        // kopie zapasowe w katalogu tego testu — testy równoległe mają te same numery propozycji
        $this->storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'card-match-sizes-'.uniqid('', true);
        mkdir($this->storage.DIRECTORY_SEPARATOR.'app', 0775, true);
        $this->app->useStoragePath($this->storage);

        $this->mmm = $this->account('mmm', '3m');
        $this->p4s = $this->account('p4s', 'p4s');
        $this->admin = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_6x00_three_3m_size_cards_and_p4s_card_become_one_model_card(): void
    {
        [$s, $m, $l, $source] = $this->halfMask();
        $candidate = $this->refreshed($source);
        $this->assertSame('size_merge', $candidate->kind);
        $this->assertSame('pending', $candidate->status);

        $response = $this->postSizes($candidate, $s)
            ->assertOk()
            ->assertJsonPath('id', $candidate->id)
            ->assertJsonPath('status', 'merged')
            ->assertJsonPath('kind', 'size_merge')
            ->assertJsonPath('decided_by.id', $this->admin->id)
            ->assertJsonPath('source_snapshot.sku', '6X00')
            ->assertJsonPath('decision_input.keep_product_id', $s->id)
            ->assertJsonPath('decision_input.drop_product_ids', [$m->id, $l->id])
            ->assertJsonPath('decision_input.attached_source_product_id', $source->id)
            ->assertJsonPath('decision_input.name', self::NAME)
            ->assertJsonPath('decision_input.name_suggested', 'Półmaska wielokrotnego użytku 3M™')
            ->assertJsonPath('decision_input.confirm_sizes_only', true)
            ->assertJsonPath('decision_input.plan_hash', $candidate->plan_hash)
            ->assertJsonPath('decision_input.anchors', [['source_key' => 'b2b:'.$this->mmm->id, 'position_key' => '7000146845']]);
        $summary = 'Rozmiary: S (mały) (7000146845); M (średni) (7000146847); L (duży) (7000146849)';
        $response->assertJsonPath('decision_input.variant_summary', $summary);
        $this->assertSame([
            ['id' => $s->id, 'sku' => '7000146845', 'name' => $s->name, 'purchase_price' => '61.38', 'currency' => 'PLN'],
            ['id' => $m->id, 'sku' => '7000146847', 'name' => $m->name, 'purchase_price' => '61.38', 'currency' => 'PLN'],
            ['id' => $l->id, 'sku' => '7000146849', 'name' => $l->name, 'purchase_price' => '61.38', 'currency' => 'PLN'],
        ], $response->json('decision_input.cards_before'));

        // jedna karta modelu: tożsamość karty, która zostaje, nazwa i lista z decyzji
        $model = $s->fresh();
        $this->assertSame('7000146845', $model->sku);
        $this->assertSame(self::NAME, $model->name);
        $this->assertSame($summary, $model->variant_summary);
        $this->assertSame('szt', $model->packaging);
        $this->assertNull(Product::query()->find($m->id));
        $this->assertNull(Product::query()->find($l->id));
        $this->assertNull(Product::query()->find($source->id));
        $this->assertSame(1, Product::query()->count());

        // powiązania: 3 pozycje 3M i 3 pozycje P4S na karcie modelu
        $this->assertSame(6, B2bProductLink::query()->where('product_id', $s->id)->count());
        // mapa połączeń: 3 wiersze 3M size_merge (wiodąca = pozycja karty, która zostaje) i 3 wiersze P4S merge
        $rows = CardRedirect::query()->orderBy('source_key')->orderBy('position_key')->get();
        $this->assertCount(6, $rows);
        $this->assertSame([$s->id], $rows->pluck('product_id')->unique()->values()->all());
        $mmm = $rows->where('source_key', 'b2b:'.$this->mmm->id);
        $this->assertSame(['7000146845', '7000146847', '7000146849'], $mmm->pluck('position_key')->values()->all());
        $this->assertSame(['size_merge'], $mmm->pluck('reason')->unique()->values()->all());
        $this->assertSame([true, false, false], $mmm->pluck('is_anchor')->values()->all());
        $this->assertSame(self::NAME, $mmm->first()->target_snapshot['name']);
        $this->assertSame($candidate->id, (int) $mmm->first()->card_match_candidate_id);
        $p4s = $rows->where('source_key', 'b2b:'.$this->p4s->id);
        $this->assertSame(['1001', '1002', '1003'], $p4s->pluck('position_key')->values()->all());
        $this->assertSame(['merge'], $p4s->pluck('reason')->unique()->values()->all());
        $this->assertSame([false], $p4s->pluck('is_anchor')->unique()->values()->all());
        $this->assertSame('rozmiar S (mały)', $p4s->first()->position_label);

        // zdjęcie główne z karty, która zostaje
        $primary = ProductImage::query()->where('product_id', $s->id)->where('is_primary', true)->sole();
        $this->assertSame('m-7000146845', $primary->checksum);

        // propozycja połączona, kopia z czterema kartami, dziennik
        $merged = $candidate->fresh();
        $this->assertSame('merged', $merged->status);
        $this->assertSame($this->admin->id, (int) $merged->decided_by);
        $path = (string) $merged->backup_path;
        $this->assertFileExists($path);
        $this->assertStringContainsString('card-match-sizes-'.$candidate->id.'-', $path);
        $backup = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('card-match-size-merge', $backup['kind']);
        $this->assertCount(4, $backup['cards']);
        $this->assertSame(['source', 'keep', 'drop', 'drop'], array_column($backup['cards'], 'role'));
        $this->assertSame($s->id, $backup['keep_product_id']);
        $this->assertSame([$m->id, $l->id], $backup['drop_product_ids']);
        $this->assertCount(3, $backup['cards'][0]['rows']['b2b_product_links']);
        $this->assertCount(1, $backup['card_match_candidates']);
        $log = ActivityLog::query()->where('action', 'card_match.size_merge')->sole();
        $this->assertSame($this->admin->id, (int) $log->user_id);
        $this->assertSame(Product::class, $log->subject_type);
        $this->assertSame($s->id, (int) $log->subject_id);
        $this->assertSame('Łączenie rozmiarów: 7000146847, 7000146849 → 7000146845, dołączona karta 6X00', $log->meta['label']);
        $this->assertSame($candidate->id, $log->meta['candidate_id']);
        $this->assertSame($path, $log->meta['backup_path']);
        $this->assertSame([$m->id, $l->id], $log->meta['drop_product_ids']);

        // ponowne odświeżenie niczego nie proponuje (karta dystrybutora dołączona)
        app(CardMatchFinder::class)->refresh();
        $this->assertSame(0, CardMatchCandidate::query()->whereIn('status', ['pending', 'conflict'])->count());
    }

    public function test_plan_hash_from_screen_or_changed_data_gives_409_and_changes_nothing(): void
    {
        [$s, , , $source] = $this->halfMask();
        $candidate = $this->refreshed($source);

        $this->postSizes($candidate, $s, ['plan_hash' => str_repeat('a', 40)])
            ->assertStatus(409)
            ->assertJsonPath('code', 'plan_changed')
            ->assertJsonPath('message', 'Propozycja zmieniła się od wczytania ekranu — odśwież listę.');

        // po odświeżeniu dystrybutor opisał pozycje kolorem — to już rozdzielanie
        ProductIdentifier::query()->where('product_id', $source->id)->update(['variant_label' => 'kolor czarny']);
        $this->postSizes($candidate, $s)
            ->assertStatus(409)
            ->assertJsonPath('code', 'plan_changed')
            ->assertJsonPath('message', 'Propozycja zmieniła rodzaj — ponowne sprawdzenie daje rozdzielanie. Odśwież listę.');

        $this->assertNothingChanged($candidate, 4);
    }

    public function test_refusals_give_422_and_change_nothing(): void
    {
        [$s, $m, , $source] = $this->halfMask();
        $candidate = $this->refreshed($source);

        // karta spoza planu
        $this->postSizes($candidate, $source)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Karta #'.$source->id.' nie jest w planie tej propozycji — wybierz jedną z kart planu.');
        // walidacja: nazwa pusta i za krótka, brak potwierdzenia
        $this->postSizes($candidate, $s, ['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postSizes($candidate, $s, ['name' => '  ab  '])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postSizes($candidate, $s, ['confirm_sizes_only' => null])->assertStatus(422)->assertJsonValidationErrors('confirm_sizes_only');
        $this->postSizes($candidate, $s, ['confirm_sizes_only' => false])->assertStatus(422)->assertJsonValidationErrors('confirm_sizes_only');
        $this->postSizes($candidate, $s, ['plan_hash' => 'krótki'])->assertStatus(422)->assertJsonValidationErrors('plan_hash');

        // trwająca synchronizacja konta 3M
        $run = B2bSyncRun::query()->create(['b2b_account_id' => $this->mmm->id, 'status' => B2bSyncRun::STATUS_RUNNING, 'trigger' => B2bSyncRun::TRIGGER_MANUAL, 'started_at' => now()]);
        $this->postSizes($candidate, $s)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Trwa synchronizacja konta B2B 3M — spróbuj po jej zakończeniu.');
        $run->forceFill(['status' => B2bSyncRun::STATUS_OK])->save();

        // karta rozmiaru jako produkt dodatkowy w przetargu (po odświeżeniu propozycji)
        $tender = Tender::query()->create([
            'number' => 'PRZ/6', 'title' => 'Test', 'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => $this->admin->id, 'status' => 'wycena', 'ai_percent' => 0, 'last_activity_at' => now(),
        ]);
        $item = TenderItem::query()->create(['tender_id' => $tender->id, 'line_no' => 1, 'requirement' => 'Półmaska', 'companion_product_id' => $m->id]);
        $message = (string) $this->postSizes($candidate, $s)->assertStatus(422)->json('message');
        $this->assertStringContainsString('jest w pozycjach przetargów (1)', $message);
        $item->delete();

        // karta, która zostaje, ma dwie pozycje konta 3M — nie wiadomo, która jest wiodąca
        B2bProductLink::query()->create(['b2b_account_id' => $this->mmm->id, 'remote_id' => 'K-7000146845', 'remote_sku' => 'K-7000146845', 'product_id' => $s->id]);
        $this->postSizes($candidate, $s)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Karta 7000146845, która zostaje, ma kilka pozycji konta B2B 3M — nie wiadomo, która jest wiodąca.');
        B2bProductLink::query()->where('remote_id', 'K-7000146845')->delete();

        // niepewna i zwykłe łączenie nie są łączeniem rozmiarów do decyzji
        $candidate->forceFill(['status' => CardMatchCandidate::STATUS_CONFLICT])->save();
        $this->postSizes($candidate, $s)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Propozycja #'.$candidate->id.' nie jest łączeniem rozmiarów do decyzji.');
        $candidate->forceFill(['status' => CardMatchCandidate::STATUS_PENDING])->save();
        $pair = CardMatchCandidate::query()->create([
            'source_product_id' => $source->id, 'target_product_id' => $s->id, 'status' => CardMatchCandidate::STATUS_PENDING,
            'kind' => CardMatchCandidate::KIND_MERGE, 'matched_by' => 'manufacturer_code', 'matched_value' => '7000146845',
        ]);
        $this->postJson('/api/card-matches/'.$pair->id.'/merge-sizes', [
            'keep_product_id' => $s->id, 'name' => self::NAME, 'plan_hash' => str_repeat('b', 40), 'confirm_sizes_only' => true,
        ])->assertStatus(422)->assertJsonPath('message', 'Propozycja #'.$pair->id.' nie jest łączeniem rozmiarów do decyzji.');
        $pair->delete();

        $this->assertNothingChanged($candidate, 4);
    }

    public function test_keep_without_position_of_owner_account_is_refused_before_any_write(): void
    {
        [$s, $m, $l] = $this->halfMask();
        B2bProductLink::query()->where('product_id', $s->id)->delete();
        $candidate = CardMatchCandidate::query()->create([
            'source_product_id' => $m->id, 'status' => 'pending', 'kind' => 'size_merge', 'targets_key' => 'x',
        ]);

        try {
            app(CardRedirectStore::class)->recordSizeMerge($s, [$m, $l], self::NAME, $candidate, $this->admin);
            $this->fail('Oczekiwano odmowy.');
        } catch (DomainException $e) {
            $this->assertSame('Karta 7000146845, która zostaje, nie ma pozycji konta B2B 3M — wybierz kartę z pozycją tego konta.', $e->getMessage());
        }
        $this->assertSame(0, CardRedirect::query()->count());
    }

    public function test_failed_attach_of_distributor_card_rolls_everything_back(): void
    {
        [$s, , , $source] = $this->halfMask();
        $candidate = $this->refreshed($source);
        // pierwsze sprawdzenie (plan) prawdziwe, drugie (dołączenie po łączeniu rozmiarów) — karta modelu ma już
        // pozycję P4S (sztuka czy karton?)
        $real = app(CardMatchFinder::class);
        $this->app->instance(CardMatchFinder::class, new class($real, (int) $s->id)
        {
            private int $calls = 0;

            public function __construct(private readonly CardMatchFinder $real, private readonly int $keepId) {}

            /** @return array<string, mixed>|null */
            public function evaluate(Product $source, bool $withPlan = false): ?array
            {
                if (++$this->calls === 1) {
                    return $this->real->evaluate($source, $withPlan);
                }

                return [
                    'status' => 'conflict', 'kind' => 'merge', 'target_product_id' => $this->keepId,
                    'reason' => 'karta producenta ma już pozycję tego konta (B2B P4S: 2001) — sztuka czy karton?',
                ];
            }
        });

        $this->postSizes($candidate, $s)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Po połączeniu rozmiarów karta dystrybutora nie daje pewnej pary: Ponowne sprawdzenie dało propozycję niepewną: '
                .'karta producenta ma już pozycję tego konta (B2B P4S: 2001) — sztuka czy karton? — nic nie zmieniono.');

        $this->assertNothingChanged($candidate, 4);
    }

    public function test_6x00p_candidate_disappears_and_comes_back_as_conflict_merge(): void
    {
        [$s, $m, $l, $source] = $this->halfMask();
        $partial = $this->distributorCard('6X00P', [
            ['2002', '6200P', '7000146847', 'rozmiar M (średni)'],
            ['2003', '6300P', '7000146849', 'rozmiar L (duży)'],
        ]);
        app(CardMatchFinder::class)->refresh();
        $candidate = CardMatchCandidate::query()->where('source_product_id', $source->id)->sole();
        $other = CardMatchCandidate::query()->where('source_product_id', $partial->id)->sole();
        $this->assertSame('size_merge', $other->kind);
        $this->assertSame(implode(',', [$m->id, $l->id]), $other->targets_key);

        $this->postSizes($candidate, $s)->assertOk();

        // w tej samej transakcji: propozycja 6X00P wskazywała łączone karty
        $this->assertNull(CardMatchCandidate::query()->find($other->id));
        $this->assertNotNull($partial->fresh());

        // odświeżenie: 6X00P trafia teraz w kartę modelu, która ma już pozycje P4S — nic nie łączy się samo
        app(CardMatchFinder::class)->refresh();
        $again = CardMatchCandidate::query()->where('source_product_id', $partial->id)->sole();
        $this->assertSame('merge', $again->kind);
        $this->assertSame('conflict', $again->status);
        $this->assertSame($s->id, $again->target_product_id);
        $this->assertStringContainsString('karta producenta ma już pozycję tego konta (B2B P4S', (string) $again->reason);
        $this->assertStringContainsString('sztuka czy karton?', (string) $again->reason);
        $this->postJson('/api/card-matches/'.$again->id.'/merge')->assertStatus(422);
        $this->assertNotNull($partial->fresh());
    }

    public function test_rejected_pair_of_other_distributor_follows_the_model_card_and_does_not_return(): void
    {
        [$s, $m, , $source] = $this->halfMask();
        $rawpol = $this->account('rawpol', 'rawpol');
        $raw = Product::query()->create(['sku' => 'RP-6200', 'name' => 'Półmaska 3M 6200 Raw-Pol', 'manufacturer' => '3M']);
        B2bProductLink::query()->create(['b2b_account_id' => $rawpol->id, 'remote_id' => 'RP-6200', 'remote_sku' => 'RP-6200', 'product_id' => $raw->id]);
        $this->identifier($raw, 'b2b:'.$rawpol->id, 'RP-6200', '7000146847', null);
        app(CardMatchFinder::class)->refresh();
        $rejected = CardMatchCandidate::query()->where('source_product_id', $raw->id)->sole();
        $this->assertSame($m->id, $rejected->target_product_id);
        $this->postJson('/api/card-matches/'.$rejected->id.'/reject', ['note' => 'inny wyrób'])->assertOk();
        $candidate = CardMatchCandidate::query()->where('source_product_id', $source->id)->sole();

        $this->postSizes($candidate, $s)->assertOk();

        $fresh = $rejected->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame($s->id, $fresh->target_product_id);
        $this->assertSame((string) $s->id, $fresh->targets_key);
        app(CardMatchFinder::class)->refresh();
        $this->assertSame(1, CardMatchCandidate::query()->where('source_product_id', $raw->id)->count());
        $this->assertSame('rejected', CardMatchCandidate::query()->where('source_product_id', $raw->id)->sole()->status);
        $this->assertNotNull($raw->fresh());
    }

    private function assertNothingChanged(CardMatchCandidate $candidate, int $cards): void
    {
        $this->assertSame($cards, Product::query()->count());
        $fresh = $candidate->fresh();
        $this->assertSame(CardMatchCandidate::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->decided_by);
        $this->assertNull($fresh->backup_path);
        $this->assertNull($fresh->decision_input);
        $this->assertSame(0, CardRedirect::query()->count());
        $this->assertSame(0, ActivityLog::query()->where('action', 'card_match.size_merge')->count());
        foreach (Product::query()->where('manufacturer', '3M')->where('sku', 'like', '7000%')->get() as $card) {
            $this->assertSame(1, B2bProductLink::query()->where('product_id', $card->id)->where('b2b_account_id', $this->mmm->id)->count());
            $this->assertStringStartsWith('Półmaska wielokrotnego użytku 3M™', $card->name);
            $this->assertNull($card->variant_summary);
        }
        // kopia zapasowa wycofanego łączenia nie zostaje
        $this->assertSame([], glob($this->storage.'/app/repair-backups/card-match-sizes-*.json') ?: []);
    }

    private function postSizes(CardMatchCandidate $candidate, Product $keep, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/card-matches/'.$candidate->id.'/merge-sizes', array_merge([
            'keep_product_id' => $keep->id,
            'name' => self::NAME,
            'plan_hash' => $candidate->plan_hash,
            'confirm_sizes_only' => true,
        ], $overrides));
    }

    private function refreshed(Product $source): CardMatchCandidate
    {
        app(CardMatchFinder::class)->refresh();

        return CardMatchCandidate::query()->where('source_product_id', $source->id)->sole();
    }

    /**
     * Trzy karty 3M (konto 3M, pozycja = SKU = kod producenta, cena 61,38, zdjęcie, opis) i karta P4S 6X00 z trzema
     * rozmiarami (pozycje 1001–1003, kody P4S 6100/6200/6300, kody producenta 3M i etykiety rozmiaru).
     *
     * @return array{0: Product, 1: Product, 2: Product, 3: Product}
     */
    private function halfMask(): array
    {
        $cards = [];
        foreach ([['7000146845', 'mały', '6100'], ['7000146847', 'średni', '6200'], ['7000146849', 'duży', '6300']] as [$code, $word, $number]) {
            $card = Product::query()->create([
                'sku' => $code, 'name' => 'Półmaska wielokrotnego użytku 3M™, rozmiar '.$word.', '.$number, 'manufacturer' => '3M',
                'packaging' => 'szt', 'description' => 'Półmaska wielokrotnego użytku z elastomeru termoplastycznego, rozmiar '.$word.'.',
                'catalog_price_net' => 61.38, 'purchase_price' => 61.38, 'currency' => 'PLN',
            ]);
            B2bProductLink::query()->create(['b2b_account_id' => $this->mmm->id, 'remote_id' => $code, 'remote_sku' => $code, 'product_id' => $card->id]);
            ProductSourcePrice::query()->create([
                'product_id' => $card->id, 'source_key' => ProductSourcePrice::b2bKey($this->mmm->id), 'b2b_account_id' => $this->mmm->id,
                'purchase_price' => 61.38, 'catalog_price_net' => 61.38, 'currency' => 'PLN', 'checked_at' => now(),
            ]);
            ProductImage::query()->create(['product_id' => $card->id, 'b2b_account_id' => $this->mmm->id, 'path' => $code.'.jpg', 'is_primary' => true, 'sort_order' => 0, 'checksum' => 'm-'.$code]);
            $cards[] = $card;
        }
        $source = $this->distributorCard('6X00', [
            ['1001', '6100', '7000146845', 'rozmiar S (mały)'],
            ['1002', '6200', '7000146847', 'rozmiar M (średni)'],
            ['1003', '6300', '7000146849', 'rozmiar L (duży)'],
        ]);

        return [$cards[0], $cards[1], $cards[2], $source];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $positions  pozycja, kod P4S, kod producenta, etykieta
     */
    private function distributorCard(string $sku, array $positions): Product
    {
        $card = Product::query()->create([
            'sku' => $sku, 'name' => 'Półmaska 3M 6000 '.$sku, 'manufacturer' => '3M',
            'catalog_price_net' => 31.20, 'purchase_price' => 31.20, 'currency' => 'PLN',
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::b2bKey($this->p4s->id), 'b2b_account_id' => $this->p4s->id,
            'purchase_price' => 31.20, 'catalog_price_net' => 31.20, 'currency' => 'PLN', 'checked_at' => now(),
        ]);
        ProductImage::query()->create(['product_id' => $card->id, 'b2b_account_id' => $this->p4s->id, 'path' => $sku.'.jpg', 'is_primary' => true, 'sort_order' => 0, 'checksum' => 'p-'.$sku]);
        foreach ($positions as [$remoteId, $remoteSku, $code, $label]) {
            B2bProductLink::query()->create(['b2b_account_id' => $this->p4s->id, 'remote_id' => $remoteId, 'remote_sku' => $remoteSku, 'product_id' => $card->id]);
            $this->identifier($card, 'b2b:'.$this->p4s->id, $remoteId, $code, $label);
        }

        return $card;
    }

    private function identifier(Product $card, string $source, string $position, string $code, ?string $label): void
    {
        ProductIdentifier::query()->create([
            'product_id' => $card->id,
            'source_key' => $source,
            'position_key' => $position,
            'type' => ProductIdentifier::TYPE_MANUFACTURER_CODE,
            'value' => $code,
            'normalized' => ProductIdentifierCode::normalize(ProductIdentifier::TYPE_MANUFACTURER_CODE, $code),
            'variant_label' => $label,
            'manufacturer' => $card->manufacturer,
            'last_seen_at' => now(),
        ]);
    }

    private function account(string $username, string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $username, 'password' => 'x', 'connector' => $connector, 'sites' => ['b2b.'.$connector.'.example.test'],
        ]);
    }
}
