<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardMatchCandidate;
use App\Models\CardRedirect;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Catalog\CardMatchFinder;
use App\Services\Catalog\CardMatchMerger;
use App\Services\Catalog\CardRedirectStore;
use App\Services\ProductSizeMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Mapa połączeń (card_redirects) — zapis decyzji przy połączeniu kart i przepinanie przy scaleniach (plan łączenia
 * kart, „Wersja uzgodniona”, krok 2). Czytanie mapy przez synchronizację i import to kolejne kroki.
 */
final class CardRedirectTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, int> karta dystrybutora => karta producenta, którą potwierdza atrapa evaluate() */
    private array $targets = [];

    private B2bAccount $anro;

    private B2bAccount $p4s;

    private B2bAccount $procera;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $test = $this;
        $this->app->instance(CardMatchFinder::class, new class($test)
        {
            public function __construct(private readonly CardRedirectTest $test) {}

            public function evaluate(Product $source): ?array
            {
                $target = $this->test->targetFor((int) $source->id);

                return $target === null ? null : [
                    'status' => 'pending', 'target_product_id' => $target, 'matched_by' => 'manufacturer_code',
                    'matched_value' => 'X', 'matched_source_key' => 'b2b:2', 'brand' => 'anro', 'hits' => 1, 'positions' => 1,
                    'reason' => null, 'conflict_product_ids' => null,
                ];
            }
        });

        $this->anro = B2bAccount::query()->create(['username' => 'anro', 'password' => 'x', 'sites' => ['b2b.anro.pl'], 'connector' => 'anro']);
        $this->p4s = B2bAccount::query()->create(['username' => 'p4s', 'password' => 'x', 'sites' => ['b2b.p4s.pl'], 'connector' => 'p4s']);
        $this->procera = B2bAccount::query()->create(['username' => 'procera', 'password' => 'x', 'sites' => ['b2b.procera.pl'], 'connector' => 'procera']);
    }

    protected function tearDown(): void
    {
        foreach (CardMatchCandidate::query()->whereNotNull('backup_path')->pluck('backup_path') as $path) {
            if (is_file((string) $path)) {
                unlink((string) $path);
            }
        }
        parent::tearDown();
    }

    public function targetFor(int $sourceId): ?int
    {
        return $this->targets[$sourceId] ?? null;
    }

    public function test_card_match_merge_records_every_position_of_distributor_card(): void
    {
        $user = User::factory()->create();
        [$target, $source] = $this->cards();
        // dwa rozmiary P4S i kod drugiego dystrybutora na karcie dystrybutora
        B2bProductLink::query()->create(['b2b_account_id' => $this->p4s->id, 'remote_id' => '99254-S', 'remote_sku' => 'ZPPV99C-S', 'product_id' => $source->id]);
        B2bProductLink::query()->create(['b2b_account_id' => $this->p4s->id, 'remote_id' => '99254-M', 'remote_sku' => 'ZPPV99C-M', 'product_id' => $source->id]);
        B2bProductLink::query()->create(['b2b_account_id' => $this->procera->id, 'remote_id' => 'PR-77', 'product_id' => $source->id]);
        $this->identifier($source, ProductSourcePrice::b2bKey($this->p4s->id), '99254-S', 'ean', '5901234567894', 'S');
        $this->identifier($source, ProductSourcePrice::b2bKey($this->p4s->id), '99254-S', 'source_code', 'ZPPV99C-S', null);
        // pozycja cennika z pliku: dwa identyfikatory tego samego wiersza = jeden wiersz mapy
        $list = PriceList::query()->create([
            'manufacturer' => 'P4S', 'version' => 'v1', 'original_filename' => 'p4s.xlsx', 'rows_total' => 1,
            'products_created' => 1, 'products_updated' => 0, 'rows_skipped' => 0,
        ]);
        $fileKey = 'file:'.$list->id;
        $this->identifier($source, $fileKey, 'ZPPV99C-L', 'source_code', 'ZPPV99C-L', null, $list->id);
        $this->identifier($source, $fileKey, 'ZPPV99C-L', 'ean', '5901234567900', 'L', $list->id);
        // wcześniejsza decyzja wskazująca kartę dystrybutora (np. połączona wcześniej trzecia karta) — idzie za nią
        $older = CardRedirect::query()->create([
            'source_key' => ProductSourcePrice::b2bKey($this->procera->id), 'position_key' => 'PR-OLD',
            'b2b_account_id' => $this->procera->id, 'product_id' => $source->id, 'reason' => CardRedirect::REASON_MERGE,
            'target_snapshot' => ['id' => $source->id, 'sku' => 'ZPPV99C', 'name' => 'x', 'manufacturer' => 'ANRO'],
        ]);
        // stara decyzja dla kodu, który jest dziś na karcie dystrybutora — nowa decyzja ją zastępuje
        $other = Product::query()->create(['sku' => 'OTHER', 'name' => 'Inna', 'manufacturer' => 'ANRO', 'catalog_price_net' => 1, 'purchase_price' => 1]);
        CardRedirect::query()->create([
            'source_key' => ProductSourcePrice::b2bKey($this->p4s->id), 'position_key' => '99254-M', 'b2b_account_id' => $this->p4s->id,
            'product_id' => $other->id, 'reason' => CardRedirect::REASON_SPLIT, 'is_anchor' => true,
        ]);
        $candidate = $this->candidate($source, $target);

        $this->travelTo('2026-09-24 10:15:00');
        app(CardMatchMerger::class)->merge($candidate, $user);

        $rows = CardRedirect::query()->orderBy('source_key')->orderBy('position_key')->get()->keyBy('position_key');
        $this->assertCount(5, $rows);
        foreach (['99254-S', '99254-M', 'PR-77', 'ZPPV99C-L'] as $position) {
            $row = $rows[$position];
            $this->assertSame($target->id, $row->product_id, $position);
            $this->assertSame(CardRedirect::REASON_MERGE, $row->reason);
            $this->assertFalse($row->is_anchor);
            $this->assertSame($candidate->id, $row->card_match_candidate_id);
            $this->assertSame($user->id, $row->created_by);
            $this->assertSame('2026-09-24 10:15:00', $row->created_at->format('Y-m-d H:i:s'));
            $this->assertSame(
                ['id' => $target->id, 'sku' => 'IF/016/F/PS', 'name' => 'Półmaska ANRO IF/016/F/PS', 'manufacturer' => 'ANRO'],
                $row->target_snapshot,
            );
        }
        $this->assertSame('b2b:'.$this->p4s->id, $rows['99254-S']->source_key);
        $this->assertSame($this->p4s->id, $rows['99254-S']->b2b_account_id);
        $this->assertSame('ZPPV99C-S', $rows['99254-S']->remote_sku);
        $this->assertSame('S', $rows['99254-S']->position_label);
        $this->assertNull($rows['99254-M']->position_label);
        $this->assertSame('b2b:'.$this->procera->id, $rows['PR-77']->source_key);
        $this->assertNull($rows['PR-77']->remote_sku);
        $this->assertSame($fileKey, $rows['ZPPV99C-L']->source_key);
        $this->assertSame($list->id, $rows['ZPPV99C-L']->price_list_id);
        $this->assertNull($rows['ZPPV99C-L']->b2b_account_id);
        $this->assertSame('L', $rows['ZPPV99C-L']->position_label);
        // karta dystrybutora przejęła wcześniej kod PR-OLD — decyzja przechodzi na kartę producenta, ślad zostaje
        $this->assertSame($target->id, $older->fresh()->product_id);
        $this->assertSame($source->id, $older->fresh()->target_snapshot['id']);
        // pozycja karty producenta (konto właściciela) nie trafia do mapy
        $this->assertFalse(CardRedirect::query()->where('source_key', 'b2b:'.$this->anro->id)->exists());
        $this->assertSame(5, CardRedirect::forProduct($target->id)->count());

        // kopia zapasowa ma wiersze mapy obu kart sprzed decyzji
        $backup = json_decode((string) file_get_contents((string) $candidate->fresh()->backup_path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['PR-OLD'], array_column($backup['cards']['source']['rows']['card_redirects'], 'position_key'));
        $this->assertSame([], $backup['cards']['target']['rows']['card_redirects']);
    }

    public function test_refused_merge_leaves_map_untouched(): void
    {
        $user = User::factory()->create();
        [$target, $source] = $this->cards();
        B2bProductLink::query()->create(['b2b_account_id' => $this->p4s->id, 'remote_id' => '99254', 'product_id' => $source->id]);
        $candidate = $this->candidate($source, $target);
        unset($this->targets[$source->id]);

        try {
            app(CardMatchMerger::class)->merge($candidate, $user);
            $this->fail('Połączenie powinno zostać odrzucone.');
        } catch (\DomainException) {
        }

        $this->assertSame(0, CardRedirect::query()->count());
    }

    public function test_console_merge_duplicate_records_map_without_candidate(): void
    {
        [$keep, $drop] = $this->cards();
        B2bProductLink::query()->create(['b2b_account_id' => $this->p4s->id, 'remote_id' => '99254', 'remote_sku' => 'ZPPV99C', 'product_id' => $drop->id]);
        $backup = storage_path('framework/testing/merge-duplicate-map.json');

        $this->artisan('products:merge-duplicate', ['--pair' => [$keep->id.':'.$drop->id], '--apply' => true, '--backup' => $backup])
            ->expectsOutputToContain('Scalono 1 par.')
            ->assertSuccessful();
        @unlink($backup);

        $row = CardRedirect::query()->sole();
        $this->assertSame('b2b:'.$this->p4s->id, $row->source_key);
        $this->assertSame('99254', $row->position_key);
        $this->assertSame('ZPPV99C', $row->remote_sku);
        $this->assertSame($keep->id, $row->product_id);
        $this->assertSame(CardRedirect::REASON_MERGE, $row->reason);
        $this->assertNull($row->card_match_candidate_id);
        $this->assertNull($row->created_by);
        $this->assertSame('IF/016/F/PS', $row->target_snapshot['sku']);
        $this->assertNull(Product::query()->find($drop->id));
    }

    public function test_size_merge_repoints_map_rows_to_card_that_stays(): void
    {
        $small = Product::query()->create(['sku' => '37695VP070', 'name' => 'AlphaTec 37695VP Size 7.0', 'manufacturer' => 'Ansell', 'catalog_price_net' => 2.85, 'purchase_price' => 2.85]);
        $large = Product::query()->create(['sku' => '37695VP100', 'name' => 'AlphaTec 37695VP Size 10.0', 'manufacturer' => 'Ansell', 'catalog_price_net' => 2.85, 'purchase_price' => 2.85]);
        foreach ([$small, $large] as $i => $product) {
            CardRedirect::query()->create([
                'source_key' => ProductSourcePrice::b2bKey($this->p4s->id), 'position_key' => 'ansell-'.$i,
                'b2b_account_id' => $this->p4s->id, 'product_id' => $product->id, 'reason' => CardRedirect::REASON_MERGE,
                'target_snapshot' => CardRedirectStore::snapshot($product),
            ]);
        }

        $result = app(ProductSizeMergeService::class)->merge('Ansell');

        $this->assertSame(1, $result['deleted']);
        $kept = Product::query()->where('manufacturer', 'Ansell')->sole();
        $this->assertSame([$kept->id, $kept->id], CardRedirect::query()->orderBy('position_key')->pluck('product_id')->all());
        // ślad decyzji bez zmian
        $this->assertSame(['37695VP070', '37695VP100'], CardRedirect::query()->orderBy('position_key')->get()->pluck('target_snapshot.sku')->all());
    }

    public function test_deleted_target_card_keeps_row_as_decision_without_card(): void
    {
        [$target] = $this->cards();
        $row = CardRedirect::query()->create([
            'source_key' => 'b2b:'.$this->p4s->id, 'position_key' => '99254', 'b2b_account_id' => $this->p4s->id,
            'product_id' => $target->id, 'reason' => CardRedirect::REASON_MERGE, 'target_snapshot' => CardRedirectStore::snapshot($target),
        ]);

        $target->delete();

        $fresh = $row->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->product_id);
        $this->assertSame('IF/016/F/PS', $fresh->target_snapshot['sku']);
    }

    public function test_repoint_skips_target_itself_and_counts_rows(): void
    {
        [$target, $source] = $this->cards();
        foreach ([[$source, 'a'], [$source, 'b'], [$target, 'c']] as [$product, $position]) {
            CardRedirect::query()->create([
                'source_key' => 'b2b:'.$this->p4s->id, 'position_key' => $position, 'b2b_account_id' => $this->p4s->id,
                'product_id' => $product->id, 'reason' => CardRedirect::REASON_MERGE,
            ]);
        }

        $store = app(CardRedirectStore::class);
        $this->assertSame(2, $store->repoint([$source->id, $target->id], $target->id));
        $this->assertSame(0, $store->repoint([$target->id], $target->id));
        $this->assertSame(3, CardRedirect::forProduct($target->id)->count());
    }

    /**
     * Karta producenta ANRO (powiązanie konta Anro — właściciel) i karta dystrybutora tego samego wyrobu.
     *
     * @return array{0: Product, 1: Product}
     */
    private function cards(): array
    {
        $target = Product::query()->create([
            'sku' => 'IF/016/F/PS', 'name' => 'Półmaska ANRO IF/016/F/PS', 'manufacturer' => 'ANRO',
            'catalog_price_net' => 8.69, 'purchase_price' => 8.69, 'currency' => 'PLN',
        ]);
        $source = Product::query()->create([
            'sku' => 'ZPPV99C', 'name' => 'Półmaska P4S IF/016/F/PS', 'manufacturer' => 'ANRO',
            'catalog_price_net' => 7.90, 'purchase_price' => 7.90, 'currency' => 'PLN',
        ]);
        B2bProductLink::query()->create(['b2b_account_id' => $this->anro->id, 'remote_id' => 'IF/016/F/PS', 'product_id' => $target->id]);

        return [$target, $source];
    }

    private function candidate(Product $source, Product $target): CardMatchCandidate
    {
        $this->targets[$source->id] = (int) $target->id;

        return CardMatchCandidate::query()->create([
            'source_product_id' => $source->id, 'target_product_id' => $target->id,
            'status' => CardMatchCandidate::STATUS_PENDING, 'matched_by' => CardMatchCandidate::BY_MANUFACTURER_CODE,
            'matched_value' => 'IF016FPS', 'brand' => 'anro', 'hits' => 1, 'positions' => 1,
        ]);
    }

    private function identifier(Product $product, string $sourceKey, string $position, string $type, string $value, ?string $label, ?int $priceListId = null): void
    {
        ProductIdentifier::query()->create([
            'product_id' => $product->id, 'source_key' => $sourceKey, 'position_key' => $position, 'type' => $type,
            'value' => $value, 'normalized' => $value, 'variant_label' => $label, 'price_list_id' => $priceListId,
            'b2b_account_id' => str_starts_with($sourceKey, 'b2b:') ? (int) substr($sourceKey, 4) : null,
        ]);
    }
}
