<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentBatchItem;
use App\Models\User;
use App\Services\Enrichment\ModelGroup;
use App\Services\Enrichment\ModelGroupPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan partii po modelach (etap 2 opisów z cenników): grupy, lider, cięcie limitem, nota modelu, członkowie i sztafeta.
 */
final class ModelGroupPlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_cards_by_model_key_keep_input_order_and_leave_other_brands_alone(): void
    {
        $a1 = $this->coba('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)');
        $a2 = $this->coba('AF060003C', 'Orthomat Standard Szary 0.9m x mb. (9.5mm)');
        $d1 = $this->coba('DP0106', 'Deckplate Czarny 0.6m x 0.9m (15mm)');
        $d2 = $this->coba('SD010701', 'Deckplate Czarny/Żółte krawędzie 0.6m x 0.9m (15mm)');
        $mapa = Product::query()->create(['sku' => '34115', 'name' => 'VITAL 115', 'manufacturer' => 'MAPA']);
        $a3 = $this->coba('AF010001', 'Orthomat Standard Czarny 0.9m x 1.5m (9.5mm)');

        $groups = $this->planner()->groups([(int) $a2->id, (int) $d1->id, (int) $a1->id, (int) $mapa->id, (int) $d2->id, (int) $a3->id, 999999]);

        $this->assertCount(4, $groups);
        $this->assertContainsOnlyInstancesOf(ModelGroup::class, $groups);
        [$orthomat, $deckplate, $single, $edges] = $groups;

        $this->assertSame('coba|AF|orthomat standard', $orthomat->key);
        $this->assertSame('Orthomat Standard', $orthomat->stem);
        $this->assertSame((int) $a1->id, $orthomat->leaderId, 'SKU bazowe przed „C” na metry; najniższy id spośród bazowych');
        $this->assertSame([(int) $a1->id, (int) $a2->id, (int) $a3->id], $orthomat->memberIds, 'lider pierwszy, dalej kolejność wejściowa');

        $this->assertSame([(int) $d1->id], $deckplate->memberIds);
        $this->assertSame('coba|DP|deckplate', $deckplate->key);

        $this->assertSame('', $single->key, 'marka bez grupowania: pozycja partii bez klucza');
        $this->assertSame('', $single->stem);
        $this->assertSame([(int) $mapa->id], $single->memberIds);
        $this->assertSame((int) $mapa->id, $single->leaderId);

        $this->assertSame('coba|SD|deckplate krawędzie', $edges->key);
        $this->assertSame([(int) $d2->id], $edges->memberIds);

        $this->assertSame([], $this->planner()->groups([]));
    }

    public function test_leader_prefers_manual_url_then_base_sku_then_hard_base_then_lowest_id(): void
    {
        // bazowe SKU wygrywa z niższym id końcówki „C”
        $cut = $this->coba('AF060003C', 'Orthomat Standard Szary 0.9m x mb. (9.5mm)');
        $base = $this->coba('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)');
        $this->assertSame((int) $base->id, (int) $this->planner()->chooseLeader([$cut, $base])->id);

        // twarda baza wygrywa z niższym id, gdy żadna karta nie jest bazowa
        $plainLow = $this->coba('FF010003C', 'Orthomat Premium Czarny 0.9m x mb. (12.5mm)');
        $hard = $this->coba('FF010005C', 'Orthomat Premium Czarny 0.6m x mb. (12.5mm)');
        ProductDescriptionVersion::query()->create([
            'product_id' => $hard->id, 'status' => ProductDescriptionVersion::STATUS_PUBLISHED, 'origin' => ProductDescriptionVersion::ORIGIN_ENRICHMENT,
            'description' => 'Opis', 'identity_verdict' => ProductDescriptionVersion::VERDICT_HARD,
        ]);
        ProductDescriptionVersion::query()->create([
            'product_id' => $plainLow->id, 'status' => ProductDescriptionVersion::STATUS_PROPOSED, 'origin' => ProductDescriptionVersion::ORIGIN_ENRICHMENT,
            'description' => 'Opis', 'identity_verdict' => ProductDescriptionVersion::VERDICT_HARD,
        ]);
        $this->assertSame((int) $hard->id, (int) $this->planner()->chooseLeader([$plainLow, $hard])->id, 'tylko wersja published liczy się jako baza');

        // ręczny adres wygrywa z bazowym SKU; „-N” nie jest bazowe
        $baseSku = $this->coba('DP0106', 'Deckplate Czarny 0.6m x 0.9m (15mm)');
        $manual = $this->coba('DP0100-4', 'Deckplate Czarny 0.6m x 18.3m (15mm)', ['shop_source_url' => 'https://sklep.example/deckplate']);
        $this->assertSame((int) $manual->id, (int) $this->planner()->chooseLeader([$baseSku, $manual])->id);
        $this->assertSame((int) $baseSku->id, (int) $this->planner()->chooseLeader([$baseSku, $this->coba('DP0100-5', 'Deckplate Czarny 0.9m x 18.3m (15mm)')])->id);

        // równe reguły — najniższy id
        $first = $this->coba('TR060001', 'Toughrib Szary 0.6m x 0.9m');
        $second = $this->coba('TR060002', 'Toughrib Szary 0.9m x 1.5m');
        $this->assertSame((int) $first->id, (int) $this->planner()->chooseLeader([$second, $first])->id);

        $groups = $this->planner()->groups([(int) $cut->id, (int) $base->id]);
        $this->assertSame((int) $base->id, $groups[0]->leaderId);
    }

    public function test_card_with_other_manual_url_than_leader_is_a_separate_group(): void
    {
        $leader = $this->coba('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)', ['shop_source_url' => 'https://a.example/orthomat']);
        $plain = $this->coba('AF060002', 'Orthomat Standard Szary 0.9m x 1.5m (9.5mm)');
        $other = $this->coba('AF060003', 'Orthomat Standard Szary 0.9m x 18.3m (9.5mm)', ['shop_source_url' => 'https://b.example/orthomat-grey']);
        $sameUrl = $this->coba('AF0100', 'Orthomat Standard Czarny 0.9m x 1.5m (9.5mm)', ['shop_source_url' => 'https://a.example/orthomat/']);
        $otherToo = $this->coba('AF010002', 'Orthomat Standard Czarny 0.9m x 18.3m (9.5mm)', ['shop_source_url' => 'https://b.example/orthomat-grey']);

        $groups = $this->planner()->groups([(int) $plain->id, (int) $other->id, (int) $leader->id, (int) $sameUrl->id, (int) $otherToo->id]);

        $this->assertCount(2, $groups);
        $this->assertSame((int) $leader->id, $groups[0]->leaderId);
        $this->assertSame([(int) $leader->id, (int) $plain->id, (int) $sameUrl->id], $groups[0]->memberIds, 'bez adresu albo ten sam adres (po normalizacji) zostaje przy liderze');
        $this->assertSame((int) $other->id, $groups[1]->leaderId);
        $this->assertSame([(int) $other->id, (int) $otherToo->id], $groups[1]->memberIds);
        $this->assertSame($groups[0]->key, $groups[1]->key, 'ten sam model, osobne przebiegi');
    }

    public function test_slice_by_limit_takes_whole_groups_and_oldest_first_orders_by_newest_enriched_at_in_group(): void
    {
        $g1 = [$this->coba('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)', ['enriched_at' => '2026-09-13 08:00:00']),
            $this->coba('AF060002', 'Orthomat Standard Szary 0.9m x 1.5m (9.5mm)', ['enriched_at' => '2026-09-14 08:00:00']),
            $this->coba('AF060003', 'Orthomat Standard Szary 0.9m x 18.3m (9.5mm)', ['enriched_at' => '2026-09-15 08:00:00'])];
        // lider opisany 20.09, członek z propozycją/błędem bez enriched_at — model tknięty 20.09, nie „nigdy”
        $g2 = [$this->coba('DP0106', 'Deckplate Czarny 0.6m x 0.9m (15mm)', ['enriched_at' => '2026-09-20 08:00:00']),
            $this->coba('DP010915', 'Deckplate Czarny 0.9m x 1.5m (15mm)')];
        // członek ze starym opisem z 01.09, lider świeżo z 30.09 — model tknięty ostatnio idzie na koniec
        $g3 = [$this->coba('TR060001', 'Toughrib Szary 0.6m x 0.9m', ['enriched_at' => '2026-09-01 08:00:00']),
            $this->coba('TR060002', 'Toughrib Szary 0.9m x 1.5m', ['enriched_at' => '2026-09-30 08:00:00'])];
        // żadna karta bez daty — model nigdy nie opisany idzie pierwszy
        $g4 = [$this->coba('SN0100-7', 'Senso Runner Czarny 1m x 10m (3mm)'), $this->coba('SN060007C', 'Senso Runner Szary 1m x mb. (3mm) - maks. 10m')];
        $ids = static fn (array $products): array => array_map(static fn (Product $p): int => (int) $p->id, $products);
        $groups = $this->planner()->groups([...$ids($g1), ...$ids($g2), ...$ids($g3), ...$ids($g4)]);
        $this->assertSame([3, 2, 2, 2], array_map(static fn (ModelGroup $g): int => count($g->memberIds), $groups));

        $slice = $this->planner()->sliceByLimit($groups, 4, false);
        $this->assertSame(['coba|AF|orthomat standard'], array_map(static fn (ModelGroup $g): string => $g->key, $slice['groups']), 'druga grupa nie mieści się — kończy partię');
        $this->assertSame($ids($g1), $slice['product_ids']);

        $oversized = $this->planner()->sliceByLimit($groups, 2, false);
        $this->assertCount(1, $oversized['groups']);
        $this->assertSame($ids($g1), $oversized['product_ids'], 'pierwszy model większy niż limit wchodzi cały');

        $all = $this->planner()->sliceByLimit($groups, 9, false);
        $this->assertCount(4, $all['groups']);
        $this->assertSame([...$ids($g1), ...$ids($g2), ...$ids($g3), ...$ids($g4)], $all['product_ids']);

        $oldest = $this->planner()->sliceByLimit($groups, 100, true);
        $this->assertSame(
            ['coba|SN|senso runner', 'coba|AF|orthomat standard', 'coba|DP|deckplate', 'coba|TR|toughrib'],
            array_map(static fn (ModelGroup $g): string => $g->key, $oldest['groups']),
            'grupa bez żadnego enriched_at pierwsza, potem od najstarszego z najnowszych enriched_at w grupie'
        );
        $this->assertSame([...$ids($g4), ...$ids($g1), ...$ids($g2), ...$ids($g3)], $oldest['product_ids']);

        // kolejna partia --force po przebiegu lidera: członek Orthomata został z propozycją (stary enriched_at),
        // lider ma dzisiejszą datę — model nie wraca na początek, czyli bez powtórnego wywołania modelu językowego
        Product::query()->whereKey($g1[0]->id)->update(['enriched_at' => '2026-10-08 10:00:00']);
        $again = $this->planner()->sliceByLimit($groups, 100, true);
        $this->assertSame(
            ['coba|SN|senso runner', 'coba|DP|deckplate', 'coba|TR|toughrib', 'coba|AF|orthomat standard'],
            array_map(static fn (ModelGroup $g): string => $g->key, $again['groups'])
        );
        $this->assertSame(['groups' => [], 'product_ids' => []], $this->planner()->sliceByLimit([], 10, true));
    }

    public function test_two_mapa_cards_with_identical_names_are_not_grouped_without_model_profile(): void
    {
        // MAPA: profil bez model.group — identyczna nazwa nie składa kart w model; każda jest grupą jednoelementową
        $a = Product::query()->create(['sku' => '34115', 'name' => 'VITAL 115', 'manufacturer' => 'MAPA']);
        $b = Product::query()->create(['sku' => '34115-8', 'name' => 'VITAL 115', 'manufacturer' => 'MAPA']);

        $groups = $this->planner()->groups([(int) $a->id, (int) $b->id]);

        $this->assertCount(2, $groups);
        $this->assertSame(['', ''], array_map(static fn (ModelGroup $g): string => $g->key, $groups));
        $this->assertSame([[(int) $a->id], [(int) $b->id]], array_map(static fn (ModelGroup $g): array => $g->memberIds, $groups));
        $this->assertSame([(int) $a->id, (int) $b->id], array_map(static fn (ModelGroup $g): int => $g->leaderId, $groups));
    }

    public function test_context_members_and_next_leader_read_frozen_batch_items(): void
    {
        $leader = $this->coba('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)');
        $cut = $this->coba('AF060003C', 'Orthomat Standard Szary 0.9m x mb. (9.5mm)');
        $roll = $this->coba('AF060003', 'Orthomat Standard Szary 0.9m x 18.3m (9.5mm)');
        $done = $this->coba('AF060002', 'Orthomat Standard Szary 0.9m x 1.5m (9.5mm)');
        $deckplate = $this->coba('DP0106', 'Deckplate Czarny 0.6m x 0.9m (15mm)');
        $mapa = Product::query()->create(['sku' => '34115', 'name' => 'VITAL 115', 'manufacturer' => 'MAPA']);
        $batch = $this->batch();
        $key = 'coba|AF|orthomat standard';
        $this->item($batch, $leader, $key, $leader);
        $this->item($batch, $cut, $key, $leader);
        $this->item($batch, $roll, $key, $leader);
        $this->item($batch, $done, $key, $leader, ProductEnrichmentBatchItem::STATUS_DONE);
        $this->item($batch, $deckplate, 'coba|DP|deckplate', $deckplate);
        $this->item($batch, $mapa, null, null);

        $context = $this->planner()->contextFor($leader, (int) $batch->id);
        $this->assertSame($key, $context['key'] ?? null);
        $this->assertSame('Orthomat Standard', $context['stem'] ?? null);
        $this->assertSame(
            [(int) $leader->id, (int) $cut->id, (int) $roll->id, (int) $done->id],
            array_column($context['members'] ?? [], 'id')
        );
        $this->assertSame(['id' => (int) $cut->id, 'sku' => 'AF060003C', 'name' => 'Orthomat Standard Szary 0.9m x mb. (9.5mm)'], $context['members'][1] ?? null);

        $this->assertNull($this->planner()->contextFor($deckplate, (int) $batch->id), 'jedna karta modelu w partii — poniżej min_members');
        $this->assertNull($this->planner()->contextFor($mapa, (int) $batch->id), 'pozycja bez klucza');
        $this->assertNull($this->planner()->contextFor($leader, (int) $batch->id + 1), 'inna partia');

        $this->assertSame([(int) $cut->id, (int) $roll->id], $this->planner()->membersOf((int) $batch->id, (int) $leader->id), 'tylko queued, bez lidera');
        $this->assertSame([], $this->planner()->membersOf((int) $batch->id, (int) $deckplate->id));

        // sztafeta: lider padł — kolejny wg reguły lidera (SKU bazowe „AF060003” przed „AF060003C”), reszta przepięta;
        // jak w EnrichProductJob: pozycja lidera ma stan końcowy (ręcznie), zanim oddaje model
        ProductEnrichmentBatchItem::query()->where('batch_id', $batch->id)->where('product_id', $leader->id)->update(['status' => ProductEnrichmentBatchItem::STATUS_MANUAL]);
        $next = $this->planner()->nextLeader((int) $batch->id, (int) $leader->id);
        $this->assertSame((int) $roll->id, $next);
        $this->assertSame((int) $roll->id, (int) ProductEnrichmentBatchItem::query()->where('batch_id', $batch->id)->where('product_id', $cut->id)->value('model_leader_id'));
        $this->assertSame((int) $roll->id, (int) ProductEnrichmentBatchItem::query()->where('batch_id', $batch->id)->where('product_id', $roll->id)->value('model_leader_id'));
        // zmiana zamierzona (08.10.2026, pełne pobranie Coby #501): upadły lider przechodzi pod nowego lidera, żeby dostać
        // opis modelu, gdy ten da wersję (ApplyModelDescriptionJob::describeRelayedLeaders) — wcześniej zostawał „ręcznie”
        $this->assertSame((int) $roll->id, (int) ProductEnrichmentBatchItem::query()->where('batch_id', $batch->id)->where('product_id', $leader->id)->value('model_leader_id'), 'pozycja padłego lidera pod nowym liderem');
        $this->assertSame((int) $leader->id, (int) ProductEnrichmentBatchItem::query()->where('batch_id', $batch->id)->where('product_id', $done->id)->value('model_leader_id'), 'zakończona pozycja bez zmian');
        $this->assertSame([(int) $cut->id], $this->planner()->membersOf((int) $batch->id, (int) $roll->id));

        ProductEnrichmentBatchItem::query()->where('batch_id', $batch->id)->where('product_id', $roll->id)->update(['status' => ProductEnrichmentBatchItem::STATUS_MANUAL]);
        $this->assertSame((int) $cut->id, $this->planner()->nextLeader((int) $batch->id, (int) $roll->id));
        // obaj upadli liderzy (AF060001, AF060003) pod ostatnim liderem — dostaną jego opis (relayedLeaders)
        $this->assertSame([(int) $leader->id, (int) $roll->id], ProductEnrichmentBatchItem::query()->where('batch_id', $batch->id)->where('model_leader_id', $cut->id)->where('product_id', '!=', $cut->id)->orderBy('product_id')->pluck('product_id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertNull($this->planner()->nextLeader((int) $batch->id, (int) $cut->id), 'nikt już nie czeka');
    }

    /** @param  array<string, mixed>  $extra */
    private function coba(string $sku, string $name, array $extra = []): Product
    {
        return Product::query()->create(['sku' => $sku, 'name' => $name, 'manufacturer' => 'Coba'] + $extra);
    }

    private function batch(): ProductEnrichmentBatch
    {
        $user = User::factory()->create();

        return ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRICE_LIST, 'scope_id' => 14, 'total' => 6, 'done' => 0, 'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_RUNNING, 'created_by' => $user->id, 'force' => true,
        ]);
    }

    private function item(ProductEnrichmentBatch $batch, Product $product, ?string $key, ?Product $leader, string $status = ProductEnrichmentBatchItem::STATUS_QUEUED): void
    {
        ProductEnrichmentBatchItem::query()->create([
            'batch_id' => $batch->id, 'product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'status' => $status,
            'model_key' => $key, 'model_leader_id' => $leader?->id,
        ]);
    }

    private function planner(): ModelGroupPlanner
    {
        return app(ModelGroupPlanner::class);
    }
}
