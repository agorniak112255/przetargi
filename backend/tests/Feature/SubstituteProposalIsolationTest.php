<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Product;
use App\Models\ProductSubstitute;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\ProductSizeMergeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Propozycje automatu (source=automat) czekają na ekranie zamienników i nie trafiają do przetargów, dopóki człowiek
 * ich nie zatwierdzi; odrzucone pary nie wracają ani do przetargów, ani przy scalaniu kart.
 */
final class SubstituteProposalIsolationTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_battlecard_and_tender_show_only_human_vouched_pairs(): void
    {
        $ours = $this->product('Rękawice robocze powlekane nitrylem', 20);
        $manualPending = $this->pair($ours, $this->product('Rękawice robocze ręczne oczekujące', 18));
        $manualRejected = $this->pair($ours, $this->product('Rękawice robocze ręczne odrzucone', 18), ['approval_status' => 'odrzucony']);
        $autoPending = $this->pair($ours, $this->product('Rękawice robocze automat oczekujące', 18), ['source' => 'automat']);
        $autoApproved = $this->pair($ours, $this->product('Rękawice robocze automat zatwierdzone', 18), ['source' => 'automat', 'approval_status' => 'zatwierdzony']);
        $autoRejected = $this->pair($ours, $this->product('Rękawice robocze automat odrzucone', 18), ['source' => 'automat', 'approval_status' => 'odrzucony']);
        [$tender, $item] = $this->item('', $ours);

        $subs = $this->getJson("/api/tenders/{$tender->id}/items/{$item->id}/battlecard")->assertOk()->json('battlecard.substitutes');
        $relation = collect($subs)->where('source', 'relation')->pluck('product_id')->sort()->values()->all();
        $this->assertSame(
            collect([$manualPending, $autoApproved])->pluck('substitute_product_id')->sort()->values()->all(),
            $relation,
        );

        $byMain = $this->getJson("/api/tenders/{$tender->id}")->assertOk()->json('substitutes_by_main.'.$ours->id);
        $this->assertEqualsCanonicalizing([$manualPending->id, $autoApproved->id], array_column($byMain, 'id'));
        $this->assertNotContains($manualRejected->id, array_column($byMain, 'id'));
        $this->assertNotContains($autoPending->id, array_column($byMain, 'id'));
        $this->assertNotContains($autoRejected->id, array_column($byMain, 'id'));
    }

    public function test_coverage_blocker_counts_only_manual_pending_pairs(): void
    {
        $ours = $this->product('Rękawice robocze powlekane nitrylem', 20);
        $this->pair($ours, $this->product('Rękawice automat 1', 18), ['source' => 'automat']);
        $this->pair($ours, $this->product('Rękawice automat 2', 18), ['source' => 'automat']);
        [$tender] = $this->item('Rękawice robocze', $ours);

        $this->getJson("/api/tenders/{$tender->id}")->assertOk()
            ->assertJsonPath('coverage.substitutes_pending', 0);

        $this->pair($ours, $this->product('Rękawice ręczne', 18));
        $coverage = $this->getJson("/api/tenders/{$tender->id}")->assertOk()->json('coverage');
        $this->assertSame(1, $coverage['substitutes_pending']);
        // brzmienie napisu blokera zmienia się w innych pracach — liczy się, że jest i że liczy jedną parę ręczną
        $this->assertCount(1, array_filter($coverage['blockers'], static fn (string $b): bool => str_starts_with($b, 'Zamienniki') && str_ends_with($b, ': 1')));
    }

    public function test_card_merge_keeps_the_human_decision_for_duplicate_pairs(): void
    {
        $keep = $this->product('Rękawice robocze model', 20);
        $drop = $this->product('Rękawice robocze model duplikat', 20);
        $subA = $this->product('Rękawice zamiennik A', 18);
        $subB = $this->product('Rękawice zamiennik B', 18);
        $subC = $this->product('Rękawice zamiennik C', 18);
        $mainX = $this->product('Rękawice inne główne', 25);

        // A: na karcie, która zostaje, świeża propozycja automatu; na duplikacie odrzucenie człowieka → zostaje odrzucenie
        $autoA = $this->pair($keep, $subA, ['source' => 'automat']);
        $rejectedA = $this->pair($drop, $subA, ['approval_status' => 'odrzucony', 'decision_note' => 'Inna norma']);
        // B: ręczny oczekujący (nowszy) bije propozycję automatu (starszą)
        $autoB = $this->pair($drop, $subB, ['source' => 'automat']);
        $manualB = $this->pair($keep, $subB);
        // C: dwa zatwierdzone — remis, zostaje starszy
        $approvedC1 = $this->pair($drop, $subC, ['approval_status' => 'zatwierdzony']);
        $approvedC2 = $this->pair($keep, $subC, ['approval_status' => 'zatwierdzony']);
        // X → duplikat jako zamiennik: przechodzi na kartę, która zostaje (zatwierdzony bije oczekujący)
        $pendingX = $this->pair($mainX, $keep);
        $approvedX = $this->pair($mainX, $drop, ['approval_status' => 'zatwierdzony']);
        // para karta ↔ jej duplikat znika (zamiennik samego siebie)
        $self = $this->pair($keep, $drop);

        app(ProductSizeMergeService::class)->mergeDuplicate($keep, $drop);

        $rows = ProductSubstitute::query()->orderBy('id')->get()->keyBy('id');
        $this->assertSame([$rejectedA->id, $manualB->id, $approvedC1->id, $approvedX->id], $rows->keys()->all());
        foreach ([$rejectedA, $manualB, $approvedC1] as $row) {
            $this->assertSame($keep->id, (int) $rows[$row->id]->main_product_id);
        }
        $this->assertSame('odrzucony', $rows[$rejectedA->id]->approval_status);
        $this->assertSame('Inna norma', $rows[$rejectedA->id]->decision_note);
        $this->assertSame($subA->id, (int) $rows[$rejectedA->id]->substitute_product_id);
        $this->assertSame($keep->id, (int) $rows[$approvedX->id]->substitute_product_id);
        $this->assertSame($mainX->id, (int) $rows[$approvedX->id]->main_product_id);
        foreach ([$autoA, $autoB, $approvedC2, $pendingX, $self] as $gone) {
            $this->assertFalse($rows->has($gone->id));
        }
    }

    private function product(string $name, float $price): Product
    {
        $this->seq++;

        return Product::query()->create([
            'sku' => 'ISO-'.$this->seq,
            'name' => $name,
            'manufacturer' => 'TEST',
            'catalog_price_net' => $price,
            'purchase_price' => $price,
            'stock' => 10,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pair(Product $main, Product $sub, array $attributes = []): ProductSubstitute
    {
        return ProductSubstitute::query()->create([
            'main_product_id' => $main->id,
            'substitute_product_id' => $sub->id,
            'type' => 'preferowany',
            'match_percent' => 80,
            'approval_status' => 'oczekuje',
            ...$attributes,
        ]);
    }

    /**
     * @return array{0: Tender, 1: TenderItem}
     */
    private function item(string $requirement, Product $ours): array
    {
        $tender = Tender::query()->create([
            'number' => 'PRZ/ISO/'.$ours->sku,
            'title' => 'Izolacja propozycji automatu',
            'client_id' => Client::query()->create(['name' => 'Klient izolacji'])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 80,
            'last_activity_at' => now(),
        ]);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => $requirement,
            'main_product_id' => $ours->id,
            'ai_match_percent' => 95,
            'quantity' => 10,
            'status' => 'ok',
        ]);

        return [$tender, $item];
    }
}
