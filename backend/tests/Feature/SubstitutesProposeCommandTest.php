<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSubstitute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * substitutes:propose — bez --write nic nie zapisuje; zapis to propozycje „oczekuje” z dowodami; ponowny przebieg nie
 * rusza decyzji ludzi, odrzucona para (w którąkolwiek stronę) nie wraca, a propozycja, której reguły już nie przepuszczają,
 * znika („oczekuje”) albo dostaje znak „automat już nie potwierdza” (zatwierdzona).
 */
final class SubstitutesProposeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_must_be_given_until_one_passes_full_audit(): void
    {
        $this->pairOfGloves();

        $this->artisan('substitutes:propose', ['--write' => true])->assertFailed();

        $this->assertSame(0, ProductSubstitute::query()->count());
    }

    public function test_max_pairs_limits_first_batch(): void
    {
        $this->pairOfGloves();
        $this->glove('C-1', 'Rękawice powlekane nitrylem Grip Pro', 'UVEX');

        $this->artisan('substitutes:propose', ['--write' => true, '--family' => ['gloves'], '--max-pairs' => 1])->assertSuccessful();

        $this->assertSame(1, ProductSubstitute::query()->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->pairOfGloves();

        $this->artisan('substitutes:propose', ['--family' => ['gloves']])->assertSuccessful();

        $this->assertSame(0, ProductSubstitute::query()->count());
    }

    public function test_write_creates_pending_automat_rows_with_evidence(): void
    {
        [$main, $sub] = $this->pairOfGloves();

        $this->artisan('substitutes:propose', ['--write' => true, '--family' => ['gloves']])->assertSuccessful();

        $row = ProductSubstitute::query()->where('main_product_id', $main->id)->where('substitute_product_id', $sub->id)->firstOrFail();
        $this->assertSame('oczekuje', $row->approval_status);
        $this->assertSame(ProductSubstitute::SOURCE_AUTO, $row->source);
        $this->assertSame('preferowany', $row->type);
        $this->assertSame(1, $row->evidence['version']);
        $this->assertSame('gloves', $row->evidence['family']);
        $this->assertNull($row->evidence['stale']);
        $this->assertNotEmpty($row->evidence['not_checked']);
        $this->assertSame('4131X', collect($row->evidence['params'])->firstWhere('key', 'en388')['sub']['value']);
        $this->assertNotNull($row->generated_at);
    }

    public function test_rerun_keeps_human_decisions_and_rejected_pairs_do_not_return(): void
    {
        [$main, $sub] = $this->pairOfGloves();
        $this->artisan('substitutes:propose', ['--write' => true, '--family' => ['gloves']])->assertSuccessful();
        $forward = ProductSubstitute::query()->where('main_product_id', $main->id)->firstOrFail();
        $reverse = ProductSubstitute::query()->where('main_product_id', $sub->id)->firstOrFail();
        $forward->update(['approval_status' => 'zatwierdzony']);
        $reverse->update(['approval_status' => 'odrzucony', 'decision_note' => 'inny chwyt']);

        $this->artisan('substitutes:propose', ['--write' => true, '--family' => ['gloves']])->assertSuccessful();

        $this->assertSame(2, ProductSubstitute::query()->count());
        $this->assertSame('zatwierdzony', $forward->fresh()->approval_status);
        $this->assertSame('odrzucony', $reverse->fresh()->approval_status);
        $this->assertSame('inny chwyt', $reverse->fresh()->decision_note);
    }

    public function test_manual_row_is_never_touched(): void
    {
        [$main, $sub] = $this->pairOfGloves();
        ProductSubstitute::query()->create([
            'main_product_id' => $main->id,
            'substitute_product_id' => $sub->id,
            'type' => 'tanszy',
            'match_percent' => 80,
            'reason' => 'ręcznie',
            'approval_status' => 'oczekuje',
        ]);

        $this->artisan('substitutes:propose', ['--write' => true, '--family' => ['gloves']])->assertSuccessful();

        $row = ProductSubstitute::query()->where('main_product_id', $main->id)->firstOrFail();
        $this->assertSame(ProductSubstitute::SOURCE_MANUAL, $row->source);
        $this->assertSame('tanszy', $row->type);
        $this->assertNull($row->evidence);
    }

    public function test_pair_that_stops_passing_is_removed_when_pending_and_flagged_when_approved(): void
    {
        [$main, $sub] = $this->pairOfGloves();
        $this->artisan('substitutes:propose', ['--write' => true, '--family' => ['gloves']])->assertSuccessful();
        ProductSubstitute::query()->where('main_product_id', $sub->id)->update(['approval_status' => 'zatwierdzony']);

        // karta zamiennika poprawiona: nie podaje już kodu EN 388 — para nie przechodzi w żadną stronę (niższy kod nie
        // wystarczy: wtedy A dalej spełnia B i para odwrotna jest poprawna)
        $sub->update(['norms' => 'EN ISO 21420']);
        $this->artisan('substitutes:propose', ['--write' => true, '--family' => ['gloves']])->assertSuccessful();

        $this->assertFalse(ProductSubstitute::query()->where('main_product_id', $main->id)->exists(), 'oczekująca propozycja znika');
        $approved = ProductSubstitute::query()->where('main_product_id', $sub->id)->firstOrFail();
        $this->assertSame('zatwierdzony', $approved->approval_status);
        $this->assertNotNull($approved->evidence['stale']);
        $this->assertNotSame('', $approved->evidence['stale']['reason']);
    }

    /**
     * @return array{0: Product, 1: Product}
     */
    private function pairOfGloves(): array
    {
        return [
            $this->glove('A-1', 'Rękawice powlekane nitrylem HyFlex 11-800', 'Ansell'),
            $this->glove('B-1', 'Rękawice powlekane nitrylem MaxiFlex Ultimate', 'ATG'),
        ];
    }

    private function glove(string $sku, string $name, string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'ppe_family' => 'gloves',
            'norms' => 'EN ISO 21420, EN 388:2016 4131X',
            'description' => 'Rękawice powlekane nitrylem na dłoni i palcach, dzianina nylonowa, do prac montażowych i precyzyjnych w suchym środowisku.',
            'catalog_price_net' => 9.5,
            'purchase_price' => 9.5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }
}
