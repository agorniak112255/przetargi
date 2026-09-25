<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardMatchCandidate;
use App\Models\CardRedirect;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * card-redirects:backfill — mapa połączeń z połączeń sprzed mapy: propozycje „Łączenie kart” (z kopii zapasowej)
 * i ręczne łączenia z konsoli (merged_duplicate_skus + merged_at powiązań kont spoza właścicieli).
 */
final class CardRedirectsBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $files = [];

    private B2bAccount $anro;

    private B2bAccount $p4s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anro = B2bAccount::query()->create(['username' => 'anro', 'password' => 'x', 'sites' => ['b2b.anro.pl'], 'connector' => 'anro']);
        $this->p4s = B2bAccount::query()->create(['username' => 'p4s', 'password' => 'x', 'sites' => ['b2b.p4s.pl'], 'connector' => 'p4s']);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_preview_writes_nothing_and_apply_fills_map_from_candidate_backup(): void
    {
        $user = User::factory()->create();
        $target = $this->card('IF/016/F/PS', 'Półmaska ANRO IF/016/F/PS (dziś)');
        $list = PriceList::query()->create([
            'manufacturer' => 'P4S', 'version' => 'v1', 'original_filename' => 'p4s.xlsx', 'rows_total' => 1,
            'products_created' => 1, 'products_updated' => 0, 'rows_skipped' => 0,
        ]);
        // połączenie z ekranu: powiązanie przeszło na kartę producenta
        B2bProductLink::query()->create(['b2b_account_id' => $this->p4s->id, 'remote_id' => '99254-S', 'remote_sku' => 'ZPPV99C-S', 'product_id' => $target->id, 'merged_at' => '2026-09-24 08:00:00']);
        $candidate = $this->mergedCandidate(55501, $target, $user, [
            'b2b_product_links' => [
                ['id' => 1, 'b2b_account_id' => $this->p4s->id, 'remote_id' => '99254-S', 'remote_sku' => 'ZPPV99C-S', 'product_id' => 55501],
                ['id' => 2, 'b2b_account_id' => $this->p4s->id, 'remote_id' => '99254-M', 'remote_sku' => 'ZPPV99C-M', 'product_id' => 55501],
            ],
            'product_identifiers' => [
                ['id' => 1, 'product_id' => 55501, 'source_key' => 'b2b:'.$this->p4s->id, 'position_key' => '99254-S', 'type' => 'ean', 'value' => '5901234567894', 'variant_label' => 'S', 'price_list_id' => null],
                ['id' => 2, 'product_id' => 55501, 'source_key' => 'file:'.$list->id, 'position_key' => 'ZPPV99C-L', 'type' => 'source_code', 'value' => 'ZPPV99C-L', 'variant_label' => 'L', 'price_list_id' => $list->id],
                ['id' => 3, 'product_id' => 55501, 'source_key' => 'file:'.$list->id, 'position_key' => 'ZPPV99C-L', 'type' => 'ean', 'value' => '5901234567900', 'variant_label' => null, 'price_list_id' => $list->id],
            ],
        ]);

        $this->artisan('card-redirects:backfill')
            ->expectsOutputToContain('Do zapisu: 3 (z propozycji: 3, z łączeń z konsoli: 0)')
            ->expectsOutputToContain('Podgląd — nic nie zapisano.')
            ->assertSuccessful();
        $this->assertSame(0, CardRedirect::query()->count());

        $this->artisan('card-redirects:backfill', ['--apply' => true])
            ->expectsOutputToContain('Zapisano 3 wierszy mapy połączeń.')
            ->assertSuccessful();

        $rows = CardRedirect::query()->get()->keyBy('position_key');
        $this->assertSame(['99254-M', '99254-S', 'ZPPV99C-L'], $rows->keys()->sort()->values()->all());
        foreach ($rows as $row) {
            $this->assertSame($target->id, $row->product_id);
            $this->assertSame(CardRedirect::REASON_MERGE, $row->reason);
            $this->assertSame($candidate->id, $row->card_match_candidate_id);
            $this->assertSame($user->id, $row->created_by);
            $this->assertSame('2026-09-24 08:00:00', $row->created_at->format('Y-m-d H:i:s'));
            // karta z chwili decyzji — z kopii, nie dzisiejsza nazwa
            $this->assertSame('Półmaska ANRO IF/016/F/PS', $row->target_snapshot['name']);
            $this->assertSame($target->id, $row->target_snapshot['id']);
        }
        $this->assertSame('S', $rows['99254-S']->position_label);
        $this->assertSame('ZPPV99C-S', $rows['99254-S']->remote_sku);
        $this->assertSame('file:'.$list->id, $rows['ZPPV99C-L']->source_key);
        $this->assertSame($list->id, $rows['ZPPV99C-L']->price_list_id);
        $this->assertSame('L', $rows['ZPPV99C-L']->position_label);

        // drugi przebieg: nic nowego, istniejące wiersze bez zmian
        $rows['99254-M']->forceFill(['position_label' => 'ręcznie'])->save();
        $this->artisan('card-redirects:backfill', ['--apply' => true])
            ->expectsOutputToContain('już w mapie: 3')
            ->expectsOutputToContain('Zapisano 0 wierszy mapy połączeń.')
            ->assertSuccessful();
        $this->assertSame(3, CardRedirect::query()->count());
        $this->assertSame('ręcznie', $rows['99254-M']->fresh()->position_label);
    }

    public function test_console_merges_come_from_merged_duplicate_skus_only(): void
    {
        // ręczne łączenie ANRO↔P4S z konsoli: kod P4S z merged_at na karcie z merged_duplicate_skus
        $console = $this->card('IF/020/F/PS', 'Półmaska ANRO IF/020/F/PS', ['merged_duplicate_skus' => ['ZPPV20C']]);
        // kod konta właściciela też z merged_at (np. wcześniejsze łączenie rozmiarów) — to nie jest kod dystrybutora
        B2bProductLink::query()->where('b2b_account_id', $this->anro->id)->where('product_id', $console->id)->update(['merged_at' => '2026-09-23 12:00:00']);
        B2bProductLink::query()->create(['b2b_account_id' => $this->p4s->id, 'remote_id' => '99220', 'remote_sku' => 'ZPPV20C', 'product_id' => $console->id, 'merged_at' => '2026-09-23 12:00:00']);
        // kod P4S dopięty synchronizacją (bez merged_at) — nie jest decyzją człowieka
        B2bProductLink::query()->create(['b2b_account_id' => $this->p4s->id, 'remote_id' => '99221', 'product_id' => $console->id]);

        // automatyczne łączenie rozmiarów: sam merged_at nie wystarcza
        $sizes = $this->card('IF/030/F/PS', 'Półmaska ANRO IF/030/F/PS', ['merged_size_skus' => ['IF/030/F/PS-M']]);
        B2bProductLink::query()->create(['b2b_account_id' => $this->p4s->id, 'remote_id' => '99230', 'product_id' => $sizes->id, 'merged_at' => '2026-09-16 12:00:00']);

        // oba scalenia na jednej karcie — nie wiadomo, które przeniosło kod
        $both = $this->card('IF/040/F/PS', 'Półmaska ANRO IF/040/F/PS', ['merged_duplicate_skus' => ['ZPPV40C'], 'merged_size_skus' => ['IF/040/F/PS-M']]);
        B2bProductLink::query()->create(['b2b_account_id' => $this->p4s->id, 'remote_id' => '99240', 'product_id' => $both->id, 'merged_at' => '2026-09-23 12:00:00']);

        $this->artisan('card-redirects:backfill', ['--apply' => true])
            ->expectsOutputToContain('nie wiadomo, które scalenie przeniosło kod')
            ->expectsOutputToContain('Do zapisu: 1 (z propozycji: 0, z łączeń z konsoli: 1); już w mapie: 0; pominięte niejednoznaczne: 1.')
            ->assertSuccessful();

        $row = CardRedirect::query()->sole();
        $this->assertSame('b2b:'.$this->p4s->id, $row->source_key);
        $this->assertSame('99220', $row->position_key);
        $this->assertSame('ZPPV20C', $row->remote_sku);
        $this->assertSame($console->id, $row->product_id);
        $this->assertNull($row->card_match_candidate_id);
        $this->assertNull($row->created_by);
        $this->assertSame('2026-09-23 12:00:00', $row->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('IF/020/F/PS', $row->target_snapshot['sku']);
    }

    public function test_position_pointed_at_two_cards_is_skipped_and_candidate_wins_over_console_for_same_card(): void
    {
        $user = User::factory()->create();
        $first = $this->card('IF/016/F/PS', 'Półmaska ANRO IF/016/F/PS', ['merged_duplicate_skus' => ['ZPPV99C']]);
        $second = $this->card('IF/017/F/PS', 'Półmaska ANRO IF/017/F/PS');
        // ta sama pozycja w kopiach dwóch propozycji z różnymi kartami producenta
        $this->mergedCandidate(55601, $first, $user, ['b2b_product_links' => [['b2b_account_id' => $this->p4s->id, 'remote_id' => 'SHARED']]]);
        $this->mergedCandidate(55602, $second, $user, ['b2b_product_links' => [['b2b_account_id' => $this->p4s->id, 'remote_id' => 'SHARED']]]);
        // pozycja z propozycji widoczna też jako łączenie z konsoli (merged_duplicate_skus + merged_at) na tej samej
        // karcie — jeden wiersz, z numerem propozycji
        $candidate = $this->mergedCandidate(55603, $first, $user, ['b2b_product_links' => [['b2b_account_id' => $this->p4s->id, 'remote_id' => '99254']]]);
        B2bProductLink::query()->create(['b2b_account_id' => $this->p4s->id, 'remote_id' => '99254', 'product_id' => $first->id, 'merged_at' => '2026-09-24 08:00:00']);
        CardMatchCandidate::query()->whereKey($candidate->id)->update(['decided_at' => '2026-09-24 09:00:00']);

        $this->artisan('card-redirects:backfill', ['--apply' => true])
            ->expectsOutputToContain('wskazana na różne karty')
            ->expectsOutputToContain('Do zapisu: 1 (z propozycji: 1, z łączeń z konsoli: 0)')
            ->assertSuccessful();

        $row = CardRedirect::query()->sole();
        $this->assertSame('99254', $row->position_key);
        $this->assertSame($candidate->id, $row->card_match_candidate_id);
        $this->assertSame($first->id, $row->product_id);
    }

    public function test_missing_backup_and_deleted_target_are_reported(): void
    {
        $user = User::factory()->create();
        $target = $this->card('IF/016/F/PS', 'Półmaska ANRO IF/016/F/PS');
        $gone = $this->mergedCandidate(55701, $target, $user, ['b2b_product_links' => [['b2b_account_id' => $this->p4s->id, 'remote_id' => '99254']]]);
        $target->delete();
        $missing = CardMatchCandidate::query()->create([
            'source_product_id' => 55702, 'status' => CardMatchCandidate::STATUS_MERGED,
            'backup_path' => storage_path('framework/testing/nie-ma-takiej-kopii.json'), 'decided_at' => now(),
        ]);

        $this->artisan('card-redirects:backfill', ['--apply' => true])
            ->expectsOutputToContain('propozycja #'.$missing->id.': brak kopii zapasowej')
            ->expectsOutputToContain('Zapisano 1 wierszy mapy połączeń.')
            ->assertSuccessful();

        // karta producenta usunięta po połączeniu — decyzja bez karty, ślad karty z kopii
        $row = CardRedirect::query()->sole();
        $this->assertNull($row->product_id);
        $this->assertSame($gone->id, $row->card_match_candidate_id);
        $this->assertSame('IF/016/F/PS', $row->target_snapshot['sku']);
    }

    public function test_size_merge_and_split_decisions_are_not_backfilled_nor_reported(): void
    {
        $user = User::factory()->create();
        $target = $this->card('7100175101', 'Hełm ochronny 3M™ SecureFit™ X5000, biały');
        foreach ([55801 => [CardMatchCandidate::KIND_SIZE_MERGE, 'card-match-size-merge'], 55802 => [CardMatchCandidate::KIND_SPLIT, 'card-match-split']] as $sourceId => [$kind, $backupKind]) {
            $candidate = CardMatchCandidate::query()->create([
                'source_product_id' => $sourceId, 'status' => CardMatchCandidate::STATUS_MERGED, 'kind' => $kind,
                'conflict_product_ids' => [$target->id, 99999], 'decided_by' => $user->id, 'decided_at' => now(),
            ]);
            $path = storage_path('framework/testing/card-match-backfill-'.$candidate->id.'.json');
            $this->files[] = $path;
            file_put_contents($path, json_encode(['kind' => $backupKind, 'source_product_id' => $sourceId, 'cards' => []], JSON_THROW_ON_ERROR));
            $candidate->forceFill(['backup_path' => $path])->save();
        }

        $this->artisan('card-redirects:backfill', ['--apply' => true])
            ->doesntExpectOutputToContain('nie pasuje do propozycji')
            ->expectsOutputToContain('Zapisano 0 wierszy mapy połączeń.')
            ->assertSuccessful();
        $this->assertSame(0, CardRedirect::query()->count());
    }

    /** @param  array<string, mixed>  $payload */
    private function card(string $sku, string $name, array $payload = []): Product
    {
        $card = Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => 'ANRO', 'catalog_price_net' => 8.69, 'purchase_price' => 8.69,
            'enrichment_payload' => $payload !== [] ? $payload : null,
        ]);
        B2bProductLink::query()->firstOrCreate(
            ['b2b_account_id' => $this->anro->id, 'remote_id' => $sku],
            ['product_id' => $card->id],
        );

        return $card;
    }

    /**
     * Propozycja połączona na ekranie z kopią zapasową w kształcie CardMatchMerger::writeBackup.
     *
     * @param  array<string, list<array<string, mixed>>>  $sourceRows
     */
    private function mergedCandidate(int $sourceId, Product $target, User $user, array $sourceRows): CardMatchCandidate
    {
        $candidate = CardMatchCandidate::query()->create([
            'source_product_id' => $sourceId, 'target_product_id' => $target->id, 'status' => CardMatchCandidate::STATUS_MERGED,
            'matched_by' => CardMatchCandidate::BY_MANUFACTURER_CODE, 'decided_by' => $user->id, 'decided_at' => '2026-09-24 08:00:00',
        ]);
        $path = storage_path('framework/testing/card-match-backfill-'.$candidate->id.'.json');
        $this->files[] = $path;
        file_put_contents($path, json_encode([
            'kind' => 'card-match-merge',
            'keep_product_id' => $target->id,
            'drop_product_id' => $sourceId,
            'cards' => [
                'target' => ['product' => ['id' => $target->id, 'sku' => $target->sku, 'name' => 'Półmaska ANRO '.$target->sku, 'manufacturer' => 'ANRO'], 'rows' => []],
                'source' => ['product' => ['id' => $sourceId, 'sku' => 'ZPPV99C'], 'rows' => $sourceRows],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $candidate->forceFill(['backup_path' => $path])->save();

        return $candidate;
    }
}
