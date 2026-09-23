<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardMatchCandidate;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductSourcePrice;
use App\Services\Catalog\CardMatchFinder;
use App\Support\ProductIdentifierCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Migracja kroku 5 (rodzaj propozycji i targets_key) na wierszach sprzed niej: karta docelowa, konflikt „kilka kart”
 * z nieposortowaną listą, odrzucone bez karty, połączona — i odświeżenie, które aktualizuje stary konflikt w miejscu.
 */
final class CardMatchCandidatesKindMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_24_130000_add_kind_and_plan_to_card_match_candidates.php';

    public function test_existing_rows_get_targets_key_and_old_conflict_is_updated_in_place(): void
    {
        Queue::fake();
        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('card_match_candidates', 'kind'));

        $p4s = $this->account('p4s');
        $mmm = $this->account('3m');
        $small = $this->producerCard($mmm, 'Półmaska wielokrotnego użytku 3M™, rozmiar mały, 6100', '7000146845');
        $medium = $this->producerCard($mmm, 'Półmaska wielokrotnego użytku 3M™, rozmiar średni, 6200', '7000146846');
        $source = Product::query()->create(['sku' => '6X00', 'name' => 'Półmaska 3M 6000', 'manufacturer' => '3M']);
        foreach (['p-s' => ['6X00/S', '7000146845', 'rozmiar S (mały)'], 'p-m' => ['6X00/M', '7000146846', 'rozmiar M (średni)']] as $remoteId => [$sku, $code, $label]) {
            B2bProductLink::query()->create(['b2b_account_id' => $p4s->id, 'remote_id' => $remoteId, 'remote_sku' => $sku, 'product_id' => $source->id]);
            $this->identifier($source, 'b2b:'.$p4s->id, $remoteId, $code, $label);
        }
        $other = Product::query()->create(['sku' => 'X', 'name' => 'Inna', 'manufacturer' => 'ANRO']);

        $pending = $this->oldRow(['source_product_id' => 900001, 'target_product_id' => $other->id, 'status' => 'pending']);
        $conflict = $this->oldRow([
            'source_product_id' => $source->id, 'status' => 'conflict',
            'conflict_product_ids' => json_encode([$medium->id, $small->id]), 'reason' => 'Klucze wskazują kilka kart producenta',
        ]);
        $rejectedA = $this->oldRow(['source_product_id' => 900002, 'status' => 'rejected']);
        $rejectedB = $this->oldRow(['source_product_id' => 900002, 'status' => 'rejected']);
        $merged = $this->oldRow(['source_product_id' => 900003, 'target_product_id' => $other->id, 'status' => 'merged']);

        $this->migration()->up();

        $keys = DB::table('card_match_candidates')->pluck('targets_key', 'id')->all();
        $this->assertSame((string) $other->id, $keys[$pending]);
        $this->assertSame(min($small->id, $medium->id).','.max($small->id, $medium->id), $keys[$conflict]);
        $this->assertSame('gone:'.$rejectedA, $keys[$rejectedA]);
        $this->assertSame('gone:'.$rejectedB, $keys[$rejectedB]);
        $this->assertSame((string) $other->id, $keys[$merged]);
        $this->assertSame(['merge'], DB::table('card_match_candidates')->distinct()->pluck('kind')->all());
        // nowy UNIQUE: ta sama karta źródła i ten sam zestaw kart
        try {
            DB::table('card_match_candidates')->insert([
                'source_product_id' => $source->id, 'status' => 'conflict', 'targets_key' => $keys[$conflict],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('UNIQUE (source_product_id, targets_key) nie zadziałał.');
        } catch (QueryException) {
            // oczekiwane
        }

        app(CardMatchFinder::class)->refresh();

        $row = CardMatchCandidate::query()->findOrFail($conflict);
        $this->assertSame('size_merge', $row->kind);
        $this->assertSame('pending', $row->status);
        $this->assertSame($keys[$conflict], $row->targets_key);
        $this->assertSame(1, CardMatchCandidate::query()->where('source_product_id', $source->id)->count());
        // decyzje zostają
        $this->assertSame(['merged', 'rejected', 'rejected'], CardMatchCandidate::query()->whereIn('id', [$rejectedA, $rejectedB, $merged])->orderBy('status')->pluck('status')->all());
    }

    public function test_duplicate_target_sets_stop_migration_before_schema_changes(): void
    {
        $this->migration()->down();
        $first = $this->oldRow(['source_product_id' => 900010, 'status' => 'conflict', 'conflict_product_ids' => json_encode([2, 1])]);
        $second = $this->oldRow(['source_product_id' => 900010, 'status' => 'rejected', 'conflict_product_ids' => json_encode([1, 2])]);

        try {
            $this->migration()->up();
            $this->fail('Migracja powinna odmówić przy duplikatach.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($first.', '.$second, $e->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('card_match_candidates', 'targets_key'));
    }

    public function test_down_removes_new_kind_proposals_and_restores_pair_unique(): void
    {
        $decided = CardMatchCandidate::query()->create([
            'source_product_id' => 900020, 'status' => 'rejected', 'kind' => 'split', 'conflict_product_ids' => [1, 2],
        ]);
        CardMatchCandidate::query()->create([
            'source_product_id' => 900021, 'status' => 'pending', 'kind' => 'size_merge', 'conflict_product_ids' => [3, 4],
        ]);

        $this->migration()->down();

        $this->assertSame([$decided->id], DB::table('card_match_candidates')->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertFalse(Schema::hasColumn('card_match_candidates', 'plan'));
        $this->migration()->up();
        $this->assertSame('1,2', DB::table('card_match_candidates')->value('targets_key'));
    }

    public function test_model_fills_targets_key_for_rows_saved_without_it(): void
    {
        $target = Product::query()->create(['sku' => 'T', 'name' => 'T', 'manufacturer' => 'ANRO']);
        $withTarget = CardMatchCandidate::query()->create(['source_product_id' => 900030, 'target_product_id' => $target->id, 'status' => 'pending']);
        $withList = CardMatchCandidate::query()->create(['source_product_id' => 900030, 'status' => 'conflict', 'conflict_product_ids' => [9, 3]]);
        $gone = CardMatchCandidate::query()->create(['source_product_id' => 900030, 'status' => 'rejected']);
        $gone2 = CardMatchCandidate::query()->create(['source_product_id' => 900030, 'status' => 'rejected']);

        $this->assertSame((string) $target->id, $withTarget->targets_key);
        $this->assertSame('3,9', $withList->targets_key);
        $this->assertSame('gone:'.$gone->id, $gone->targets_key);
        $this->assertSame('gone:'.$gone2->id, $gone2->fresh()->targets_key);
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }

    /** @param  array<string, mixed>  $values */
    private function oldRow(array $values): int
    {
        return (int) DB::table('card_match_candidates')->insertGetId([...$values, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function producerCard(B2bAccount $account, string $name, string $code): Product
    {
        $card = Product::query()->create(['sku' => $code, 'name' => $name, 'manufacturer' => '3M']);
        B2bProductLink::query()->create(['b2b_account_id' => $account->id, 'remote_id' => 'r-'.$code, 'remote_sku' => $code, 'product_id' => $card->id]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::b2bKey($account->id), 'b2b_account_id' => $account->id,
            'purchase_price' => 61.38, 'catalog_price_net' => 61.38, 'currency' => 'PLN',
        ]);

        return $card;
    }

    private function identifier(Product $card, string $source, string $position, string $value, string $label): void
    {
        ProductIdentifier::query()->create([
            'product_id' => $card->id, 'source_key' => $source, 'position_key' => $position, 'type' => 'manufacturer_code',
            'value' => $value, 'normalized' => ProductIdentifierCode::normalize('manufacturer_code', $value),
            'variant_label' => $label, 'manufacturer' => $card->manufacturer, 'last_seen_at' => now(),
        ]);
    }

    private function account(string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $connector, 'password' => 'x', 'connector' => $connector, 'sites' => ['b2b.'.$connector.'.example.test'],
        ]);
    }
}
