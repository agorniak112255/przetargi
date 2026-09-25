<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardMatchCandidate;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductSourcePrice;
use App\Models\ProductSpecialPrice;
use App\Models\User;
use App\Services\Catalog\CardMatchFinder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ekran „Łączenie kart” — API i łączenie (plan łączenia kart, etap C). Reguły dopasowania (CardMatchFinder) są
 * podmienione atrapą: tu sprawdzamy decyzję, strażników, kopię zapasową i kształt JSON, nie samo dopasowanie.
 * Para z produkcji: karta ANRO (konto Anro, SKU = kod producenta IF/016/F/PS) i karta P4S tego samego wyrobu.
 */
final class CardMatchApiTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>|null> wynik evaluate() po id karty dystrybutora */
    private array $evaluations = [];

    private B2bAccount $anro;

    private B2bAccount $p4s;

    /** konto 3M — właściciel kart rozmiarów w propozycjach z planem (zakładane przy pierwszej takiej propozycji) */
    private ?B2bAccount $mmm = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();

        $test = $this;
        $this->app->instance(CardMatchFinder::class, new class($test)
        {
            public function __construct(private readonly CardMatchApiTest $test) {}

            public function evaluate(Product $source): ?array
            {
                return $this->test->evaluationFor((int) $source->id);
            }

            /** @return array{pending: int, conflict: int, removed: int, refreshed_at: string} */
            public function refresh(): array
            {
                return ['pending' => 0, 'conflict' => 0, 'removed' => 0, 'refreshed_at' => '2026-09-24T06:10:00+00:00'];
            }
        });

        $this->anro = B2bAccount::query()->create(['username' => 'anro', 'password' => 'x', 'sites' => ['b2b.anro.pl'], 'connector' => 'anro']);
        $this->p4s = B2bAccount::query()->create(['username' => 'p4s', 'password' => 'x', 'sites' => ['b2b.p4s.pl'], 'connector' => 'p4s']);
    }

    protected function tearDown(): void
    {
        // kopie zapasowe powstają w prawdziwym storage/app/repair-backups — sprzątamy też po teście, który padł
        foreach (CardMatchCandidate::query()->whereNotNull('backup_path')->pluck('backup_path') as $path) {
            if (is_file((string) $path)) {
                unlink((string) $path);
            }
        }
        parent::tearDown();
    }

    /** @var array<int, list<array<string, mixed>|null>> kolejne wyniki evaluate() po id karty — mają pierwszeństwo przed stałym wynikiem */
    private array $evaluationQueue = [];

    /** @return array<string, mixed>|null */
    public function evaluationFor(int $sourceId): ?array
    {
        if (($this->evaluationQueue[$sourceId] ?? []) !== []) {
            return array_shift($this->evaluationQueue[$sourceId]);
        }

        return $this->evaluations[$sourceId] ?? null;
    }

    public function test_merge_moves_distributor_price_to_manufacturer_card_and_removes_duplicate(): void
    {
        $admin = $this->actingAsRole('admin');
        [$target, $source, $candidate] = $this->pair('IF/016/F/PS', 'ZPPV99C');
        // druga, starsza propozycja tej samej karty dystrybutora — po połączeniu wskazuje kartę, której nie ma
        $other = Product::query()->create(['sku' => 'ANRO-X', 'name' => 'Inny', 'manufacturer' => 'ANRO', 'catalog_price_net' => 1, 'purchase_price' => 1]);
        $stale = CardMatchCandidate::query()->create([
            'source_product_id' => $source->id, 'target_product_id' => $other->id, 'status' => CardMatchCandidate::STATUS_CONFLICT,
            'matched_by' => CardMatchCandidate::BY_EAN, 'matched_value' => '5901234567890', 'reason' => 'kilka kart',
        ]);

        $response = $this->postJson("/api/card-matches/{$candidate->id}/merge")
            ->assertOk()
            ->assertJsonPath('id', $candidate->id)
            ->assertJsonPath('status', 'merged')
            ->assertJsonPath('source', null)
            ->assertJsonPath('source_snapshot.sku', 'ZPPV99C')
            ->assertJsonPath('source_snapshot.manufacturer', 'ANRO')
            ->assertJsonPath('target.id', $target->id)
            ->assertJsonPath('target.sku', 'IF/016/F/PS')
            ->assertJsonPath('target.has_description', true)
            ->assertJsonPath('decided_by.id', $admin->id)
            ->assertJsonPath('decided_by.name', $admin->name);
        $this->assertNotNull($response->json('decided_at'));
        $labels = array_column((array) $response->json('target.sources'), 'label', 'source_key');
        $this->assertSame(['b2b:'.$this->anro->id, 'b2b:'.$this->p4s->id], array_keys($labels));
        $this->assertSame('B2B P4S', $labels['b2b:'.$this->p4s->id]);
        $this->assertSame('7.90', collect($response->json('target.sources'))->firstWhere('source_key', 'b2b:'.$this->p4s->id)['purchase_price']);

        $this->assertNull(Product::query()->find($source->id));
        $kept = $target->fresh();
        $this->assertSame('IF/016/F/PS', $kept->sku);
        $this->assertSame('Półmaska ANRO IF/016/F/PS z filtrem', $kept->name);
        $this->assertSame(['ZPPV99C'], $kept->enrichment_payload['merged_duplicate_skus']);
        $this->assertSame(2, ProductSourcePrice::query()->where('product_id', $target->id)->count());
        $this->assertTrue(ProductSourcePrice::query()->where('product_id', $target->id)->where('source_key', 'b2b:'.$this->p4s->id)->exists());
        $this->assertSame(2, B2bProductLink::query()->where('product_id', $target->id)->count());
        $this->assertNotNull(B2bProductLink::query()->where('b2b_account_id', $this->p4s->id)->value('merged_at'));
        $this->assertSame(1, ProductIdentifier::query()->where('product_id', $target->id)->count());
        // cena karty dalej od producenta (decyzja 23.09) — tańszy dystrybutor to tylko informacja
        $this->assertSame('8.69', (string) $kept->purchase_price);

        // zdjęcie główne karty producenta zostaje główne, zdjęcie dystrybutora dochodzi za nim
        $images = ProductImage::query()->where('product_id', $target->id)->orderBy('sort_order')->get();
        $this->assertSame(['anro.jpg', 'p4s.jpg'], $images->pluck('path')->all());
        $this->assertSame([true, false], $images->pluck('is_primary')->all());
        $this->assertSame([0, 1], $images->pluck('sort_order')->all());

        $merged = $candidate->fresh();
        $this->assertSame(CardMatchCandidate::STATUS_MERGED, $merged->status);
        $this->assertSame($admin->id, (int) $merged->decided_by);
        $this->assertNull(CardMatchCandidate::query()->find($stale->id));

        // pełna kopia zapasowa: obie karty i wiersze, które scalenie przenosi albo kasuje
        $path = (string) $merged->backup_path;
        $this->assertFileExists($path);
        $backup = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($target->id, $backup['keep_product_id']);
        $this->assertSame($source->id, $backup['drop_product_id']);
        $this->assertSame('ZPPV99C', $backup['cards']['source']['product']['sku']);
        $this->assertCount(1, $backup['cards']['source']['rows']['product_source_prices']);
        $this->assertCount(1, $backup['cards']['source']['rows']['b2b_product_links']);
        $this->assertCount(1, $backup['cards']['source']['rows']['product_images']);
        $this->assertCount(1, $backup['cards']['source']['rows']['product_identifiers']);
        $this->assertCount(1, $backup['cards']['target']['rows']['product_source_prices']);
        $this->assertArrayNotHasKey('tender_items', $backup['cards']['target']['rows']);
        $this->assertSame([['id' => $backup['price_lists'][0]['id'], 'product_ids' => [$source->id]]], $backup['price_lists']);
    }

    public function test_merge_is_refused_when_recheck_points_elsewhere_and_nothing_changes(): void
    {
        $this->actingAsRole('admin');
        [$target, $source, $candidate] = $this->pair('IF/016/F/PS', 'ZPPV99C');

        $this->evaluations[$source->id] = $this->evaluation($target->id + 1000);
        $this->postJson("/api/card-matches/{$candidate->id}/merge")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Klucz wskazuje teraz inną kartę producenta (#'.($target->id + 1000).') — odśwież propozycje.');

        $this->evaluations[$source->id] = ['status' => 'conflict', 'target_product_id' => $target->id, 'reason' => 'cel ma już pozycję tego konta — sztuka/karton?'] + $this->evaluation($target->id);
        $this->postJson("/api/card-matches/{$candidate->id}/merge")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ponowne sprawdzenie dało propozycję niepewną: cel ma już pozycję tego konta — sztuka/karton?.');

        $this->evaluations[$source->id] = null;
        $this->postJson("/api/card-matches/{$candidate->id}/merge")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Karta dystrybutora nie ma już wspólnego klucza z kartą producenta — odśwież propozycje.');

        // od odświeżenia klucze karty wskazują kilka kart producenta — to już łączenie rozmiarów, nie zwykłe łączenie
        $this->evaluations[$source->id] = ['kind' => CardMatchCandidate::KIND_SIZE_MERGE, 'target_product_id' => null] + $this->evaluation($target->id);
        $this->postJson("/api/card-matches/{$candidate->id}/merge")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Propozycja zmieniła rodzaj — klucze karty dystrybutora wskazują teraz kilka kart producenta (łączenie rozmiarów). Odśwież propozycje.');

        $this->assertNotNull($source->fresh());
        $this->assertSame(1, ProductSourcePrice::query()->where('product_id', $source->id)->count());
        $fresh = $candidate->fresh();
        $this->assertSame(CardMatchCandidate::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->decided_by);
        $this->assertNull($fresh->backup_path);
    }

    public function test_merge_guards_refuse_special_prices_both_file_slots_and_non_pending(): void
    {
        $this->actingAsRole('admin');
        [, $source, $candidate] = $this->pair('IF/016/F/PS', 'ZPPV99C');
        ProductSpecialPrice::query()->create(['product_id' => $source->id, 'client_name' => 'Klient', 'price' => 1]);
        $this->postJson("/api/card-matches/{$candidate->id}/merge")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Karta dystrybutora ma ceny specjalne (1) — połączenie ich nie przenosi.');

        [$target2, $source2, $candidate2] = $this->pair('IF/020/F/PS', 'ZPPV20C');
        foreach ([$target2, $source2] as $product) {
            ProductSourcePrice::query()->create([
                'product_id' => $product->id, 'source_key' => ProductSourcePrice::SOURCE_FILE,
                'catalog_price_net' => 9, 'purchase_price' => 9, 'currency' => 'PLN', 'checked_at' => now(),
            ]);
        }
        $this->postJson("/api/card-matches/{$candidate2->id}/merge")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Obie karty mają cenę z pliku — starsza by zginęła.');

        $candidate->forceFill(['status' => CardMatchCandidate::STATUS_CONFLICT])->save();
        $this->postJson("/api/card-matches/{$candidate->id}/merge")->assertStatus(422);

        $this->assertNotNull($source->fresh());
        $this->assertNotNull($source2->fresh());
        $this->assertSame(CardMatchCandidate::STATUS_PENDING, $candidate2->fresh()->status);
    }

    public function test_reject_keeps_note_in_reason_and_records_decision(): void
    {
        $admin = $this->actingAsRole('admin');
        [, $source, $candidate] = $this->pair('IF/016/F/PS', 'ZPPV99C');

        $this->postJson("/api/card-matches/{$candidate->id}/reject", ['note' => 'inny kolor filtra'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('reason', 'inny kolor filtra')
            ->assertJsonPath('decided_by.id', $admin->id)
            ->assertJsonPath('source.id', $source->id);
        $this->assertNotNull($source->fresh());

        // drugi raz — już odrzucona
        $this->postJson("/api/card-matches/{$candidate->id}/reject")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Propozycja #'.$candidate->id.' ma już status „rejected”.');

        // niepewna: powód konfliktu zostaje przed notatką; bez notatki powód bez zmian
        [, , $conflict] = $this->pair('IF/020/F/PS', 'ZPPV20C');
        $conflict->forceFill(['status' => CardMatchCandidate::STATUS_CONFLICT, 'reason' => 'obie karty mają cenę z pliku'])->save();
        $this->postJson("/api/card-matches/{$conflict->id}/reject", ['note' => 'zostawiamy osobno'])
            ->assertOk()
            ->assertJsonPath('reason', 'obie karty mają cenę z pliku · zostawiamy osobno');
        [, , $plain] = $this->pair('IF/030/F/PS', 'ZPPV30C');
        $this->postJson("/api/card-matches/{$plain->id}/reject")->assertOk()->assertJsonPath('reason', null);
    }

    public function test_bulk_returns_result_per_row(): void
    {
        $this->actingAsRole('admin');
        [, $okSource, $ok] = $this->pair('IF/016/F/PS', 'ZPPV99C');
        [$target2, $refusedSource, $refused] = $this->pair('IF/020/F/PS', 'ZPPV20C');
        $this->evaluations[$refusedSource->id] = null;
        // niepewna propozycja tej samej karty dystrybutora znika po połączeniu pierwszej pary
        $sibling = CardMatchCandidate::query()->create([
            'source_product_id' => $okSource->id, 'target_product_id' => $target2->id,
            'status' => CardMatchCandidate::STATUS_CONFLICT, 'reason' => 'kilka kart',
        ]);

        $response = $this->postJson('/api/card-matches/bulk', ['action' => 'merge', 'ids' => [$ok->id, $refused->id, 999999, $sibling->id]])
            ->assertOk();
        $results = $response->json('results');
        $this->assertSame(['id' => $ok->id, 'ok' => true, 'error' => null], $results[0]);
        $this->assertSame($refused->id, $results[1]['id']);
        $this->assertFalse($results[1]['ok']);
        $this->assertStringContainsString('nie ma już wspólnego klucza', (string) $results[1]['error']);
        $this->assertSame(['id' => 999999, 'ok' => false, 'error' => 'Brak propozycji #999999.'], $results[2]);
        $this->assertSame(['id' => $sibling->id, 'ok' => false, 'error' => 'Propozycja #'.$sibling->id.' już nie istnieje — odśwież listę.'], $results[3]);

        $this->assertNull(Product::query()->find($okSource->id));
        $this->assertNotNull($refusedSource->fresh());
        $this->assertSame(CardMatchCandidate::STATUS_PENDING, $refused->fresh()->status);

        $this->postJson('/api/card-matches/bulk', ['action' => 'reject', 'ids' => [$refused->id, $ok->id]])
            ->assertOk()
            ->assertJsonPath('results.0.ok', true)
            ->assertJsonPath('results.1.ok', false)
            ->assertJsonPath('results.1.error', 'Propozycja #'.$ok->id.' ma już status „merged”.');

        $this->postJson('/api/card-matches/bulk', ['action' => 'merge', 'ids' => range(1, 201)])->assertStatus(422);
        $this->postJson('/api/card-matches/bulk', ['action' => 'delete', 'ids' => [1]])->assertStatus(422);
    }

    public function test_list_by_status_with_pagination_and_summary(): void
    {
        // last_seen_at propozycji = dzień wcześniej; odświeżenie z 24.09 06:10 ma być najnowsze
        $this->travelTo('2026-09-23 12:00:00');
        $this->actingAsRole('admin');
        $candidates = [];
        foreach (['IF/016/F/PS', 'IF/020/F/PS', 'IF/030/F/PS'] as $i => $code) {
            [, , $candidates[]] = $this->pair($code, 'ZPPV'.$i.'C');
        }
        $candidates[2]->forceFill(['status' => CardMatchCandidate::STATUS_CONFLICT, 'reason' => 'kilka kart', 'conflict_product_ids' => [5, 6]])->save();
        [, , $rejected] = $this->pair('IF/040/F/PS', 'ZPPV40C');
        $rejected->forceFill(['status' => CardMatchCandidate::STATUS_REJECTED, 'decided_at' => now()])->save();

        $this->getJson('/api/card-matches?per_page=1')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('data.0.id', $candidates[0]->id)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.matched_by', 'manufacturer_code')
            ->assertJsonPath('data.0.matched_value', 'IF016FPS')
            ->assertJsonPath('data.0.matched_source_key', 'b2b:'.$this->p4s->id)
            ->assertJsonPath('data.0.brand', 'anro')
            ->assertJsonPath('data.0.hits', 1)
            ->assertJsonPath('data.0.positions', 1)
            ->assertJsonPath('data.0.decided_by', null)
            ->assertJsonPath('data.0.source.sku', 'ZPPV0C')
            ->assertJsonPath('data.0.source.purchase_price', '7.90')
            ->assertJsonPath('data.0.source.currency', 'PLN')
            ->assertJsonPath('data.0.source.has_description', false)
            ->assertJsonPath('data.0.source.sources.0.label', 'B2B P4S')
            ->assertJsonPath('data.0.target.sku', 'IF/016/F/PS')
            ->assertJsonPath('data.0.target.sources.0.source_key', 'b2b:'.$this->anro->id);
        $this->assertStringContainsString('/thumb', (string) $this->getJson('/api/card-matches')->json('data.0.target.thumb_url'));
        $this->getJson('/api/card-matches?per_page=1&page=2')->assertJsonPath('data.0.id', $candidates[1]->id);

        $this->getJson('/api/card-matches?status=conflict')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.reason', 'kilka kart')
            ->assertJsonPath('data.0.conflict_product_ids', [5, 6]);
        $this->getJson('/api/card-matches?status=rejected')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $rejected->id);
        $this->getJson('/api/card-matches?status=merged')->assertJsonPath('total', 0);
        $this->getJson('/api/card-matches?status=other')->assertStatus(422);

        CardMatchCandidate::query()->whereKey($candidates[1]->id)->update(['last_seen_at' => '2026-09-24 06:10:00']);
        $this->getJson('/api/card-matches/summary')
            ->assertOk()
            ->assertExactJson([
                'pending' => 2, 'conflict' => 1, 'rejected' => 1, 'merged' => 0, 'refreshed_at' => '2026-09-24T06:10:00+00:00',
                // krok 5: te same liczniki w podziale na rodzaj i pomiar sygnału (tu same zwykłe pary)
                'by_kind' => [
                    'merge' => ['pending' => 2, 'conflict' => 1, 'rejected' => 1, 'merged' => 0],
                    'size_merge' => ['pending' => 0, 'conflict' => 0, 'rejected' => 0, 'merged' => 0],
                    'split' => ['pending' => 0, 'conflict' => 0, 'rejected' => 0, 'merged' => 0],
                ],
                'signals' => ['size' => 0, 'color' => 0, 'unknown' => 0],
            ]);
        $this->postJson('/api/card-matches/refresh')
            ->assertOk()
            ->assertJsonPath('pending', 2)
            ->assertJsonPath('refreshed_at', '2026-09-24T06:10:00+00:00');
    }

    public function test_role_without_card_matches_permission_does_not_see_screen(): void
    {
        // handlowiec ma podgląd katalogu (products.view), ale łączenie kart to osobne uprawnienie nadawane w rolach
        $this->actingAsRole('handlowiec');
        $this->pair('IF/016/F/PS', 'ZPPV99C');

        $this->getJson('/api/card-matches')->assertForbidden();
        $this->getJson('/api/card-matches/summary')->assertForbidden();
    }

    public function test_viewer_sees_list_but_cannot_decide(): void
    {
        $this->actingAsRole('handlowiec')->givePermissionTo('card_matches.view');
        [, , $candidate] = $this->pair('IF/016/F/PS', 'ZPPV99C');

        $this->getJson('/api/card-matches')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/card-matches/summary')->assertOk();
        $this->postJson("/api/card-matches/{$candidate->id}/merge")->assertForbidden();
        $this->postJson("/api/card-matches/{$candidate->id}/reject")->assertForbidden();
        $this->postJson('/api/card-matches/bulk', ['action' => 'reject', 'ids' => [$candidate->id]])->assertForbidden();
        $this->postJson('/api/card-matches/refresh')->assertForbidden();
        $this->assertSame(CardMatchCandidate::STATUS_PENDING, $candidate->fresh()->status);
    }

    public function test_list_uses_constant_number_of_queries_per_page(): void
    {
        $this->actingAsRole('admin');
        $this->pair('IF/001/F/PS', 'ZPPV01C');
        // pierwsze żądanie wczytuje uprawnienia do pamięci podręcznej — liczymy od drugiego
        $this->getJson('/api/card-matches')->assertOk();
        $one = $this->countQueries('/api/card-matches');

        foreach (['IF/002/F/PS', 'IF/003/F/PS', 'IF/004/F/PS', 'IF/005/F/PS'] as $i => $code) {
            [$target] = $this->pair($code, 'ZPPV1'.$i.'C');
            ProductImage::query()->create(['product_id' => $target->id, 'path' => 'extra'.$i.'.jpg', 'is_primary' => false, 'sort_order' => 1]);
        }
        $five = $this->countQueries('/api/card-matches');

        $this->assertSame($one, $five);
    }

    public function test_list_filters_by_kind_and_presents_plan_with_card_briefs(): void
    {
        $this->actingAsRole('admin');
        [, , $pair] = $this->pair('IF/016/F/PS', 'ZPPV99C');
        [$source, $targets, $sizes] = $this->sizeMergeCandidate('6X00');
        [, , $split] = $this->sizeMergeCandidate('KLODKA', CardMatchCandidate::KIND_SPLIT, CardMatchCandidate::SIGNAL_COLOR);

        // bez rodzaju — wszystkie rodzaje (kolejność jak dotąd: marka, id)
        $this->assertSame([$sizes->id, $split->id, $pair->id], array_column((array) $this->getJson('/api/card-matches')->json('data'), 'id'));
        $this->getJson('/api/card-matches?kind=merge')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $pair->id)
            ->assertJsonPath('data.0.kind', 'merge')
            ->assertJsonPath('data.0.signal', null)
            ->assertJsonPath('data.0.plan_hash', null)
            ->assertJsonPath('data.0.plan', null);
        $this->getJson('/api/card-matches?kind=split')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $split->id);
        $this->getJson('/api/card-matches?kind=other')->assertStatus(422);

        $ids = array_map(static fn (Product $p): int => (int) $p->id, $targets);
        $row = $this->getJson('/api/card-matches?status=pending&kind=size_merge')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $sizes->id)
            ->assertJsonPath('data.0.kind', 'size_merge')
            ->assertJsonPath('data.0.signal', 'size')
            ->assertJsonPath('data.0.plan_hash', $sizes->plan_hash)
            ->assertJsonPath('data.0.target', null)
            ->assertJsonPath('data.0.conflict_product_ids', $ids)
            ->assertJsonPath('data.0.source.id', $source->id)
            ->assertJsonPath('data.0.plan.version', 1)
            ->assertJsonPath('data.0.plan.source_label', 'B2B P4S')
            ->assertJsonPath('data.0.plan.suggested.keep_product_id', $ids[0])
            ->assertJsonPath('data.0.plan.suggested.common_name', 'Półmaska wielokrotnego użytku 3M™')
            ->json('data.0');
        $this->assertCount(3, $row['plan']['positions']);
        foreach ($row['plan']['positions'] as $i => $position) {
            $this->assertSame($ids[$i], $position['target_product_id']);
            $this->assertSame($ids[$i], $position['target']['id']);
            $this->assertSame($targets[$i]->sku, $position['target']['sku']);
            $this->assertSame('3M', $position['target']['manufacturer']);
            $this->assertSame('61.38', $position['target']['purchase_price']);
            $this->assertSame('B2B 3M', $position['target']['sources'][0]['label']);
            $this->assertStringContainsString('/thumb', (string) $position['target']['thumb_url']);
        }
        $this->assertSame('6X00/S', $row['plan']['positions'][0]['remote_sku']);
        $this->assertSame('S (mały)', $row['plan']['positions'][0]['size_label']);

        // niepewna: pozycja bez karty, pozycja w kilka kart i karta usunięta po odświeżeniu — target null
        $plan = $sizes->plan;
        $plan['positions'][0]['target_product_id'] = null;
        $plan['positions'][0]['target_ids'] = [$ids[0], $ids[1]];
        $plan['positions'][1]['target_product_id'] = null;
        $sizes->forceFill(['status' => CardMatchCandidate::STATUS_CONFLICT, 'plan' => $plan])->save();
        $targets[2]->delete();
        $positions = $this->getJson('/api/card-matches?status=conflict')->assertJsonPath('total', 1)->json('data.0.plan.positions');
        $this->assertNull($positions[0]['target']);
        $this->assertSame([$ids[0], $ids[1]], $positions[0]['target_ids']);
        $this->assertNull($positions[1]['target']);
        $this->assertNull($positions[1]['target_ids']);
        $this->assertSame($ids[2], $positions[2]['target_product_id']);
        $this->assertNull($positions[2]['target']);
    }

    public function test_summary_counts_by_kind_and_signals_of_open_plans(): void
    {
        $this->actingAsRole('admin');
        $this->pair('IF/016/F/PS', 'ZPPV99C');
        [, , $conflictPair] = $this->pair('IF/020/F/PS', 'ZPPV20C');
        $conflictPair->forceFill(['status' => CardMatchCandidate::STATUS_CONFLICT])->save();
        $this->sizeMergeCandidate('6X00');
        $this->sizeMergeCandidate('KLODKA', CardMatchCandidate::KIND_SPLIT, CardMatchCandidate::SIGNAL_COLOR);
        [, , $unknown] = $this->sizeMergeCandidate('UVEX', CardMatchCandidate::KIND_SPLIT, CardMatchCandidate::SIGNAL_UNKNOWN);
        $unknown->forceFill(['status' => CardMatchCandidate::STATUS_CONFLICT])->save();
        // odrzucone nie liczą się do pomiaru, ale są w liczniku rodzaju
        [, , $rejected] = $this->sizeMergeCandidate('4520', CardMatchCandidate::KIND_SIZE_MERGE, CardMatchCandidate::SIGNAL_SIZE);
        $rejected->forceFill(['status' => CardMatchCandidate::STATUS_REJECTED, 'decided_at' => now()])->save();

        $this->getJson('/api/card-matches/summary')
            ->assertOk()
            ->assertJsonPath('pending', 3)
            ->assertJsonPath('conflict', 2)
            ->assertJsonPath('rejected', 1)
            ->assertJsonPath('merged', 0)
            ->assertJsonPath('by_kind', [
                'merge' => ['pending' => 1, 'conflict' => 1, 'rejected' => 0, 'merged' => 0],
                'size_merge' => ['pending' => 1, 'conflict' => 0, 'rejected' => 1, 'merged' => 0],
                'split' => ['pending' => 1, 'conflict' => 1, 'rejected' => 0, 'merged' => 0],
            ])
            ->assertJsonPath('signals', ['size' => 1, 'color' => 1, 'unknown' => 1]);
        $this->postJson('/api/card-matches/refresh')->assertOk()->assertJsonPath('by_kind.split.conflict', 1)->assertJsonPath('signals.color', 1);
    }

    public function test_merge_of_size_merge_or_split_is_refused_but_reject_works(): void
    {
        $this->actingAsRole('admin');
        [$source, $targets, $sizes] = $this->sizeMergeCandidate('6X00');
        [$splitSource, , $split] = $this->sizeMergeCandidate('KLODKA', CardMatchCandidate::KIND_SPLIT, CardMatchCandidate::SIGNAL_COLOR);
        [, , $okPair] = $this->pair('IF/016/F/PS', 'ZPPV99C');

        $this->postJson("/api/card-matches/{$sizes->id}/merge")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ta propozycja to łączenie rozmiarów — połącz ją przyciskiem „Połącz rozmiary” na zakładce „Łączenie rozmiarów” albo odrzuć.');
        // rodzaj sprawdzany przed statusem — niepewna propozycja rozmiarów mówi to samo
        $sizes->forceFill(['status' => CardMatchCandidate::STATUS_CONFLICT])->save();
        $this->postJson("/api/card-matches/{$sizes->id}/merge")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ta propozycja to łączenie rozmiarów — połącz ją przyciskiem „Połącz rozmiary” na zakładce „Łączenie rozmiarów” albo odrzuć.');

        $results = $this->postJson('/api/card-matches/bulk', ['action' => 'merge', 'ids' => [$split->id, $okPair->id]])
            ->assertOk()
            ->json('results');
        $this->assertSame([
            'id' => $split->id, 'ok' => false,
            'error' => 'Ta propozycja to rozdzielanie — rozdziel ją przyciskiem „Rozdziel” na zakładce „Rozdzielanie” albo odrzuć.',
        ], $results[0]);
        $this->assertSame(['id' => $okPair->id, 'ok' => true, 'error' => null], $results[1]);

        // nic nie zmienione: karty, sloty, propozycje
        $this->assertNotNull($source->fresh());
        $this->assertNotNull($splitSource->fresh());
        foreach ($targets as $target) {
            $this->assertNotNull($target->fresh());
        }
        $this->assertSame(1, ProductSourcePrice::query()->where('product_id', $source->id)->count());
        $this->assertSame(CardMatchCandidate::STATUS_CONFLICT, $sizes->fresh()->status);
        $this->assertSame(CardMatchCandidate::STATUS_PENDING, $split->fresh()->status);
        $this->assertNull($split->fresh()->decided_by);
        $this->assertNull($split->fresh()->backup_path);

        // odrzucenie działa dla każdego rodzaju, także niepewnej propozycji
        $split->forceFill(['status' => CardMatchCandidate::STATUS_CONFLICT, 'reason' => 'nie wiadomo, czy pozycje różnią się rozmiarem czy kolorem'])->save();
        $this->postJson("/api/card-matches/{$split->id}/reject", ['note' => 'to różne wyroby'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('kind', 'split')
            ->assertJsonPath('reason', 'nie wiadomo, czy pozycje różnią się rozmiarem czy kolorem · to różne wyroby')
            ->assertJsonPath('plan.positions.0.target.manufacturer', '3M');
        $this->postJson("/api/card-matches/{$sizes->id}/reject")->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('kind', 'size_merge');
        $this->assertNotNull($splitSource->fresh());
    }

    public function test_list_with_plans_uses_constant_number_of_queries_per_page(): void
    {
        $this->actingAsRole('admin');
        $this->sizeMergeCandidate('P00');
        $url = '/api/card-matches?status=pending&kind=size_merge&per_page=50';
        $this->getJson($url)->assertOk();
        $one = $this->countQueries($url);

        for ($i = 1; $i < 20; $i++) {
            $this->sizeMergeCandidate('P'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }
        $this->getJson($url)->assertJsonPath('total', 20);
        $twenty = $this->countQueries($url);

        $this->assertSame($one, $twenty);
    }

    public function test_merge_sizes_requires_decide_permission_and_valid_input(): void
    {
        $this->actingAsRole('handlowiec')->givePermissionTo('card_matches.view');
        [, $targets, $sizes] = $this->sizeMergeCandidate('6X00');
        $body = ['keep_product_id' => $targets[0]->id, 'name' => '6X00 Półmaska 3M 6000', 'plan_hash' => $sizes->plan_hash, 'confirm_sizes_only' => true];
        $this->postJson("/api/card-matches/{$sizes->id}/merge-sizes", $body)->assertForbidden();

        $this->actingAsRole('admin');
        $this->postJson("/api/card-matches/{$sizes->id}/merge-sizes", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['keep_product_id', 'name', 'plan_hash', 'confirm_sizes_only']);
        $this->postJson("/api/card-matches/{$sizes->id}/merge-sizes", ['name' => 'ab', 'plan_hash' => 'x', 'confirm_sizes_only' => false] + $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'plan_hash', 'confirm_sizes_only']);
        $this->postJson("/api/card-matches/{$sizes->id}/merge-sizes", ['variant_summary' => str_repeat('x', 1501)] + $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['variant_summary']);

        $this->assertSame(CardMatchCandidate::STATUS_PENDING, $sizes->fresh()->status);
        $this->assertSame(3, Product::query()->where('manufacturer', '3M')->where('sku', 'like', '70001468%')->count());
    }

    public function test_merge_sizes_returns_merged_row_with_decision_input_and_409_when_plan_changed(): void
    {
        $admin = $this->actingAsRole('admin');
        [$source, $targets, $sizes] = $this->sizeMergeCandidate('6X00');
        // karty rozmiarów z pozycją konta 3M (właściciel marki) — jedna pozycja na kartę
        foreach ($targets as $target) {
            B2bProductLink::query()->create(['b2b_account_id' => $this->mmm?->id, 'remote_id' => $target->sku, 'remote_sku' => $target->sku, 'product_id' => $target->id]);
        }
        B2bProductLink::query()->create(['b2b_account_id' => $this->p4s->id, 'remote_id' => 'p4s-6X00', 'product_id' => $source->id]);
        $body = ['keep_product_id' => $targets[0]->id, 'name' => '6X00 Półmaska 3M 6000', 'plan_hash' => $sizes->plan_hash, 'confirm_sizes_only' => true];

        // ekran wczytał inny plan
        $this->postJson("/api/card-matches/{$sizes->id}/merge-sizes", ['plan_hash' => str_repeat('0', 40)] + $body)
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Propozycja zmieniła się od wczytania ekranu — odśwież listę.', 'code' => 'plan_changed']);
        // ponowne sprawdzenie daje inny skrót planu
        $this->evaluationQueue[$source->id] = [['kind' => 'size_merge', 'status' => 'pending', 'plan_hash' => str_repeat('1', 40)]];
        $this->postJson("/api/card-matches/{$sizes->id}/merge-sizes", $body)
            ->assertStatus(409)
            ->assertJsonPath('code', 'plan_changed');
        $this->assertSame(CardMatchCandidate::STATUS_PENDING, $sizes->fresh()->status);

        // plan jak na ekranie, potem pewna para karty dystrybutora z kartą modelu
        $this->evaluationQueue[$source->id] = [
            ['kind' => 'size_merge', 'status' => 'pending', 'plan_hash' => $sizes->plan_hash],
            $this->evaluation((int) $targets[0]->id),
        ];
        $row = $this->postJson("/api/card-matches/{$sizes->id}/merge-sizes", $body)
            ->assertOk()
            ->assertJsonPath('id', $sizes->id)
            ->assertJsonPath('status', 'merged')
            ->assertJsonPath('kind', 'size_merge')
            ->assertJsonPath('decided_by.id', $admin->id)
            ->assertJsonPath('source', null)
            ->assertJsonPath('source_snapshot.sku', '6X00')
            ->json();
        $this->assertSame([
            'keep_product_id', 'drop_product_ids', 'attached_source_product_id', 'name', 'name_suggested', 'variant_summary',
            'confirm_sizes_only', 'plan_hash', 'cards_before', 'anchors',
        ], array_keys($row['decision_input']));
        $this->assertSame([$targets[1]->id, $targets[2]->id], $row['decision_input']['drop_product_ids']);
        $this->assertSame('Rozmiary: S (mały) (700014686X00S); M (średni) (700014686X00M); L (duży) (700014686X00L)', $row['decision_input']['variant_summary']);
        $this->assertSame([['source_key' => 'b2b:'.$this->mmm?->id, 'position_key' => '700014686X00S']], $row['decision_input']['anchors']);
        $this->assertSame('61.38', $row['decision_input']['cards_before'][0]['purchase_price']);
        $this->assertSame('6X00 Półmaska 3M 6000', $targets[0]->fresh()->name);
        $this->assertNull($source->fresh());
        $this->assertFileExists((string) $sizes->fresh()->backup_path);

        // „Zrobione”: ten sam kształt z decision_input
        $this->getJson('/api/card-matches?status=merged&kind=size_merge')
            ->assertOk()
            ->assertJsonPath('data.0.id', $sizes->id)
            ->assertJsonPath('data.0.decision_input.keep_product_id', $targets[0]->id);
    }

    public function test_list_presents_default_variant_summary_of_size_merge_plans(): void
    {
        $this->actingAsRole('admin');
        [, , $pair] = $this->pair('IF/016/F/PS', 'ZPPV99C');
        $this->sizeMergeCandidate('6X00');
        $this->sizeMergeCandidate('KLODKA', CardMatchCandidate::KIND_SPLIT, CardMatchCandidate::SIGNAL_COLOR);

        $this->getJson('/api/card-matches?kind=size_merge')
            ->assertOk()
            ->assertJsonPath('data.0.plan.suggested.variant_summary', 'Rozmiary: S (mały) (700014686X00S); M (średni) (700014686X00M); L (duży) (700014686X00L)')
            ->assertJsonPath('data.0.decision_input', null);
        $this->getJson('/api/card-matches?kind=split')->assertJsonPath('data.0.plan.suggested', null);
        $this->getJson('/api/card-matches?kind=merge')->assertJsonPath('data.0.id', $pair->id)->assertJsonPath('data.0.decision_input', null);
    }

    private function countQueries(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->withRole($role)->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Karta producenta ANRO (powiązanie z kontem Anro, cena 8,69, zdjęcie główne, opis) i karta P4S tego samego
     * wyrobu (cena 7,90, zdjęcie, kod producenta, cennik) z propozycją pending; evaluate() domyślnie potwierdza parę.
     *
     * @return array{0: Product, 1: Product, 2: CardMatchCandidate}
     */
    private function pair(string $code, string $distributorSku): array
    {
        $target = Product::query()->create([
            'sku' => $code, 'name' => 'Półmaska ANRO '.$code.' z filtrem', 'manufacturer' => 'ANRO',
            'description' => 'Półmaska filtrująca z zaworem wydechowym, klasa FFP2, rozmiar uniwersalny.',
            'catalog_price_net' => 8.69, 'purchase_price' => 8.69, 'currency' => 'PLN',
        ]);
        $source = Product::query()->create([
            'sku' => $distributorSku, 'name' => 'Półmaska P4S '.$code, 'manufacturer' => 'ANRO',
            'catalog_price_net' => 7.90, 'purchase_price' => 7.90, 'currency' => 'PLN',
        ]);
        B2bProductLink::query()->create(['b2b_account_id' => $this->anro->id, 'remote_id' => $code, 'remote_sku' => $code, 'product_id' => $target->id]);
        B2bProductLink::query()->create(['b2b_account_id' => $this->p4s->id, 'remote_id' => 'p4s-'.$distributorSku, 'product_id' => $source->id]);
        foreach ([[$target, $this->anro, 8.69], [$source, $this->p4s, 7.90]] as [$product, $account, $price]) {
            ProductSourcePrice::query()->create([
                'product_id' => $product->id, 'source_key' => ProductSourcePrice::b2bKey($account->id),
                'b2b_account_id' => $account->id, 'catalog_price_net' => $price, 'purchase_price' => $price,
                'currency' => 'PLN', 'checked_at' => now(),
            ]);
        }
        ProductImage::query()->create(['product_id' => $target->id, 'b2b_account_id' => $this->anro->id, 'path' => 'anro.jpg', 'is_primary' => true, 'sort_order' => 0, 'checksum' => 'a-'.$code]);
        ProductImage::query()->create(['product_id' => $source->id, 'b2b_account_id' => $this->p4s->id, 'path' => 'p4s.jpg', 'is_primary' => true, 'sort_order' => 0, 'checksum' => 'p-'.$code]);
        ProductIdentifier::query()->create([
            'product_id' => $source->id, 'type' => 'manufacturer_code', 'value' => $code,
            'normalized' => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code)),
            'brand_key' => 'anro', 'source_key' => ProductSourcePrice::b2bKey($this->p4s->id), 'position_key' => $distributorSku,
        ]);
        PriceList::query()->create([
            'manufacturer' => 'P4S', 'version' => 'v1', 'original_filename' => 'p4s.xlsx', 'rows_total' => 1,
            'products_created' => 1, 'products_updated' => 0, 'rows_skipped' => 0, 'product_ids' => [$source->id],
        ]);

        $candidate = CardMatchCandidate::query()->create([
            'source_product_id' => $source->id, 'target_product_id' => $target->id,
            'status' => CardMatchCandidate::STATUS_PENDING, 'matched_by' => CardMatchCandidate::BY_MANUFACTURER_CODE,
            'matched_value' => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code)),
            'matched_source_key' => ProductSourcePrice::b2bKey($this->p4s->id), 'brand' => 'anro', 'hits' => 1, 'positions' => 1,
            'source_snapshot' => ['sku' => $distributorSku, 'name' => (string) $source->name, 'manufacturer' => 'ANRO'],
            'last_seen_at' => now()->subDay(),
        ]);
        $this->evaluations[$source->id] = $this->evaluation((int) $target->id);

        return [$target, $source, $candidate];
    }

    /**
     * Propozycja z planem „pozycja → karta” (krok 5) w kształcie z CardMatchFinder: karta P4S jednego wyrobu
     * w trzech rozmiarach (6X00/S, /M, /L) i trzy karty 3M (konto 3M, cena 61,38, zdjęcie) — po jednej na pozycję.
     * Wiersz tworzony ręcznie: tu sprawdzamy ekran i decyzje, nie reguły planu.
     *
     * @return array{0: Product, 1: list<Product>, 2: CardMatchCandidate}
     */
    private function sizeMergeCandidate(
        string $model,
        string $kind = CardMatchCandidate::KIND_SIZE_MERGE,
        string $signal = CardMatchCandidate::SIGNAL_SIZE,
        string $status = CardMatchCandidate::STATUS_PENDING,
    ): array {
        $this->mmm ??= B2bAccount::query()->create(['username' => 'mmm', 'password' => 'x', 'sites' => ['3mb2b.pl'], 'connector' => '3m']);
        $source = Product::query()->create([
            'sku' => $model, 'name' => 'Półmaska wielokrotnego użytku 3M™ '.$model, 'manufacturer' => '3M',
            'catalog_price_net' => 70, 'purchase_price' => 65, 'currency' => 'PLN',
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $source->id, 'source_key' => ProductSourcePrice::b2bKey($this->p4s->id), 'b2b_account_id' => $this->p4s->id,
            'catalog_price_net' => 70, 'purchase_price' => 65, 'currency' => 'PLN', 'checked_at' => now(),
        ]);

        $targets = [];
        $positions = [];
        foreach (['S' => 'mały', 'M' => 'średni', 'L' => 'duży'] as $size => $word) {
            $code = '70001468'.$model.$size;
            $target = Product::query()->create([
                'sku' => $code, 'name' => 'Półmaska wielokrotnego użytku 3M™, rozmiar '.$word.', '.$model.$size, 'manufacturer' => '3M',
                'catalog_price_net' => 61.38, 'purchase_price' => 61.38, 'currency' => 'PLN',
            ]);
            ProductSourcePrice::query()->create([
                'product_id' => $target->id, 'source_key' => ProductSourcePrice::b2bKey($this->mmm->id), 'b2b_account_id' => $this->mmm->id,
                'catalog_price_net' => 61.38, 'purchase_price' => 61.38, 'currency' => 'PLN', 'checked_at' => now(),
            ]);
            ProductImage::query()->create(['product_id' => $target->id, 'b2b_account_id' => $this->mmm->id, 'path' => $code.'.jpg', 'is_primary' => true, 'sort_order' => 0, 'checksum' => 'm-'.$code]);
            $targets[] = $target;
            $label = $signal === CardMatchCandidate::SIGNAL_COLOR ? 'kolor '.$word : 'rozmiar '.$size.' ('.$word.')';
            $positions[] = [
                'source_key' => ProductSourcePrice::b2bKey($this->p4s->id), 'source_label' => 'B2B P4S',
                'position_key' => 'p4s-'.$model.'-'.$size, 'remote_sku' => $model.'/'.$size,
                'label' => $label, 'size_label' => $signal === CardMatchCandidate::SIGNAL_SIZE ? $size.' ('.$word.')' : null,
                'target_product_id' => (int) $target->id, 'target_ids' => null,
                'matched_by' => 'manufacturer_code', 'matched_value' => $code,
                'signal' => $signal, 'signal_why' => 'etykieta P4S: '.$label,
            ];
        }
        $ids = array_map(static fn (Product $p): int => (int) $p->id, $targets);
        sort($ids);
        $plan = [
            'version' => 1, 'signal' => $signal, 'source_label' => 'B2B P4S', 'same_owner' => true, 'equal_prices' => true,
            'price_differences' => [], 'blockers' => [], 'positions' => $positions,
            'suggested' => $kind === CardMatchCandidate::KIND_SIZE_MERGE ? [
                'keep_product_id' => $ids[0], 'common_name' => 'Półmaska wielokrotnego użytku 3M™',
                'sizes' => array_map(static fn (array $p): array => ['product_id' => $p['target_product_id'], 'label' => $p['size_label'], 'code' => $p['matched_value']], $positions),
            ] : null,
        ];

        $candidate = CardMatchCandidate::query()->create([
            'source_product_id' => $source->id, 'target_product_id' => null, 'status' => $status,
            'kind' => $kind, 'plan' => $plan, 'targets_key' => implode(',', $ids),
            'plan_hash' => sha1((string) json_encode([$kind, array_map(static fn (array $p): array => [$p['source_key'], $p['position_key'], $p['target_product_id']], $positions)])),
            'matched_by' => CardMatchCandidate::BY_MANUFACTURER_CODE, 'matched_value' => $positions[0]['matched_value'],
            'matched_source_key' => ProductSourcePrice::b2bKey($this->p4s->id), 'brand' => '3m', 'hits' => 3, 'positions' => 3,
            'conflict_product_ids' => $ids,
            'source_snapshot' => ['sku' => $model, 'name' => (string) $source->name, 'manufacturer' => '3M'],
            'last_seen_at' => now(),
        ]);

        return [$source, $targets, $candidate];
    }

    /** @return array<string, mixed> */
    private function evaluation(int $targetId): array
    {
        return [
            'status' => 'pending', 'target_product_id' => $targetId, 'matched_by' => 'manufacturer_code',
            'matched_value' => 'X', 'matched_source_key' => 'b2b:2', 'brand' => 'anro', 'hits' => 1, 'positions' => 1,
            'reason' => null, 'conflict_product_ids' => null,
        ];
    }
}
