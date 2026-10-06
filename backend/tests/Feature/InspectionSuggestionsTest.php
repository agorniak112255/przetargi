<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InspectionPosition;
use App\Models\InspectionSuggestionRejection;
use App\Models\User;
use App\Services\Inspections\InspectionDueBuilder;
use App\Services\Inspections\InspectionSuggestions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Podpowiedzi pozycji przeglądów z wzorca: ta sama rodzina nazwy (rdzeń) i token modelu z inną liczbą — tylko
 * propozycje do zatwierdzenia; odrzucone nie wracają.
 */
final class InspectionSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private int $itemGid = 100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(10, 0));
        // przebudowa terminów nie jest tu sprawdzana
        app()->instance(InspectionDueBuilder::class, new class
        {
            /** @param  list<int>|null  $ids */
            public function rebuild(?array $ids = null): int
            {
                return 0;
            }
        });
    }

    public function test_token_key_keeps_whole_number_and_drops_suffix(): void
    {
        $this->assertSame(['GP6'], array_column(InspectionSuggestions::tokens('GAŚNICA PROSZ.GP-6X ABC'), 'key'));
        $this->assertSame(['GP6'], array_column(InspectionSuggestions::tokens('PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6'), 'key'));
        $this->assertSame(['GP60'], array_column(InspectionSuggestions::tokens('GAŚNICA GP-60'), 'key'));
        $this->assertSame(['AP25'], array_column(InspectionSuggestions::tokens('AGREGAT AP-25'), 'key'));
        $this->assertSame(['AP250'], array_column(InspectionSuggestions::tokens('AGREGAT AP-250'), 'key'));
        $this->assertSame(['AWP150'], array_column(InspectionSuggestions::tokens('AGREGAT AWP-150'), 'key'));
        $this->assertSame(['H520'], array_column(InspectionSuggestions::tokens('HYDRANT H-520'), 'key'));
        $this->assertSame(['GS5'], array_column(InspectionSuggestions::tokens('GAŚNICA ŚNIEGOWA GS 5X'), 'key'));
        $this->assertSame(['GP2.5'], array_column(InspectionSuggestions::tokens('GAŚNICA GP-2,5'), 'key'));
        // litery w środku słowa to nie token
        $this->assertSame([], InspectionSuggestions::tokens('GAŚNICA6 PROSZKOWA'));
        $this->assertSame(['GASNICA', 'PROSZ', 'ABC'], InspectionSuggestions::core('GAŚNICA PROSZ.GP-6X ABC'));
        $this->assertSame(['PRZEGLAD', 'GASNICY', 'PROSZKOWEJ'], InspectionSuggestions::core('PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6'));
    }

    public function test_goods_pattern_proposes_other_sizes_with_matching_renewal_service(): void
    {
        $gp6 = $this->service(4737, 'UPRGP6', 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6');
        $this->service(4738, 'UPRGP2', 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-2');
        $this->service(4739, 'UPRGP4', 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-4');
        $pattern = $this->position($this->item('GAŚNICA PROSZ.GP-6X ABC'), 'GAŚNICA PROSZ.GP-6X ABC', 1, renewedBy: $gp6);
        $gp2 = $this->item('GAŚNICA PROSZ.GP-2X ABC');
        $gp4 = $this->item('GAŚNICA PROSZ.GP-4X ABC');
        // ta sama liczba (inny przyrostek), inne litery, brak słowa rdzenia, archiwalny — nie
        $this->item('GAŚNICA PROSZ.GP-6Z ABC');
        $this->item('GAŚNICA ŚNIEGOWA GS-5X ABC PROSZ');
        $this->item('GAŚNICA PROSZ.GP-9X');
        $this->item('GAŚNICA PROSZ.GP-12X ABC', archived: true);
        // GP-1X bez usługi GP-1 w katalogu — bez usługi odnawiającej
        $gp1 = $this->item('GAŚNICA PROSZ.GP-1X ABC');
        $customer = (int) DB::table('erp_customers')->insertGetId(['xl_gid' => 1, 'acronym' => 'K', 'archived' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_customer_items')->insert(['erp_customer_id' => $customer, 'erp_item_id' => $this->itemId($gp2), 'last_sale_at' => '2026-05-01', 'documents' => 1, 'quantity' => 3, 'created_at' => now(), 'updated_at' => now()]);

        Sanctum::actingAs($this->user());
        $res = $this->getJson('/api/inspection-positions/suggestions')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame(['id' => $pattern->id, 'name' => 'GAŚNICA PROSZ.GP-6X ABC', 'interval_months' => 12], $res->json('data.0.pattern'));
        $candidates = $res->json('data.0.candidates');
        $this->assertSame([$gp1, $gp2, $gp4], array_column($candidates, 'xl_gid'));
        $this->assertSame(['GP-1', 'GP-2', 'GP-4'], array_column($candidates, 'token'));
        $this->assertNull($candidates[0]['renewed_by']);
        $this->assertSame(['xl_gid' => 4738, 'code' => 'UPRGP2', 'name' => 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-2'], $candidates[1]['renewed_by']);
        $this->assertSame(4739, $candidates[2]['renewed_by']['xl_gid']);
        $this->assertSame(InspectionPosition::TYPE_GOODS, $candidates[1]['xl_type']);
        $this->assertSame(12, $candidates[1]['interval_months']);
        $this->assertSame(1, $candidates[1]['customers_24m']);
        $this->assertEquals(3, $candidates[1]['quantity_24m']);
        $this->assertSame(0, $candidates[0]['customers_24m']);
    }

    public function test_ap25_does_not_pair_with_ap250(): void
    {
        $ap6 = $this->service(4800, 'UPRAP6', 'PRZEGLĄD AGREGATU AP-6');
        $this->service(4801, 'UPRAP250', 'PRZEGLĄD AGREGATU AP-250');
        $this->position($this->item('AGREGAT GAŚNICZY AP-6'), 'AGREGAT GAŚNICZY AP-6', 1, renewedBy: $ap6);
        $ap25 = $this->item('AGREGAT GAŚNICZY AP-25');
        $ap250 = $this->item('AGREGAT GAŚNICZY AP-250');

        Sanctum::actingAs($this->user());
        $candidates = $this->getJson('/api/inspection-positions/suggestions')->assertOk()->json('data.0.candidates');
        $this->assertSame([$ap25, $ap250], array_column($candidates, 'xl_gid'));
        // AP-25 nie dostaje przeglądu AP-250
        $this->assertNull($candidates[0]['renewed_by']);
        $this->assertSame(4801, $candidates[1]['renewed_by']['xl_gid']);

        $this->service(4802, 'UPRAP25', 'PRZEGLĄD AGREGATU AP-25');
        $candidates = $this->getJson('/api/inspection-positions/suggestions')->assertOk()->json('data.0.candidates');
        $this->assertSame(4802, $candidates[0]['renewed_by']['xl_gid']);
        $this->assertSame(4801, $candidates[1]['renewed_by']['xl_gid']);
    }

    public function test_service_pattern_rejected_candidates_do_not_return_and_accept_sets_source(): void
    {
        $pattern = $this->position($this->service(4737, 'UPRGP6', 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6'), 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', 4);
        $this->service(4738, 'UPRGP2', 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-2');
        $this->service(4739, 'UPRGP4', 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-4');
        $this->service(4740, 'UPRGP60', 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-60');
        $this->service(4741, 'UPRGS5', 'PRZEGLĄD GAŚNICY ŚNIEGOWEJ GS-5X');
        // towar z tym samym rdzeniem nie jest kandydatem wzorca-usługi
        $this->item('PRZEGLĄD GAŚNICY PROSZKOWEJ GP-9');
        $this->saleLine(1, 4739, '2026-01-01', 8);
        $this->saleLine(2, 4739, '2026-02-01', 2);

        Sanctum::actingAs($user = $this->user());
        $candidates = $this->getJson('/api/inspection-positions/suggestions')->assertOk()->json('data.0.candidates');
        $this->assertSame([4738, 4739, 4740], array_column($candidates, 'xl_gid'));
        $this->assertSame(2, $candidates[1]['customers_24m']);
        $this->assertEquals(10, $candidates[1]['quantity_24m']);
        $this->assertNull($candidates[1]['renewed_by']);

        $this->postJson('/api/inspection-positions/suggestions/reject', ['pattern_id' => $pattern->id, 'xl_gids' => [4740]])->assertNoContent();
        // drugi raz — bez błędu i bez duplikatu
        $this->postJson('/api/inspection-positions/suggestions/reject', ['pattern_id' => $pattern->id, 'xl_gids' => [4740]])->assertNoContent();
        $this->assertSame(1, InspectionSuggestionRejection::query()->count());
        $this->assertSame($user->id, InspectionSuggestionRejection::query()->first()?->user_id);

        $res = $this->postJson('/api/inspection-positions/suggestions/accept', [
            'pattern_id' => $pattern->id,
            'items' => [['xl_gid' => 4738, 'interval_months' => 12, 'renewed_by_xl_gid' => null]],
        ])->assertCreated();
        $this->assertSame('suggestion', $res->json('data.0.source'));
        $this->assertSame(['id' => $pattern->id, 'name' => 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6'], $res->json('data.0.pattern'));
        $accepted = InspectionPosition::query()->where('xl_gid', 4738)->firstOrFail();
        $this->assertSame(InspectionPosition::SOURCE_SUGGESTION, $accepted->source);
        $this->assertSame($pattern->id, $accepted->pattern_position_id);

        // odrzucona i przyjęta nie wracają; przyjęta nie jest nowym wzorcem
        $this->assertSame([4739], array_column($this->getJson('/api/inspection-positions/suggestions')->json('data.0.candidates'), 'xl_gid'));
        $this->assertCount(1, $this->getJson('/api/inspection-positions/suggestions')->json('data'));

        $this->postJson('/api/inspection-positions/suggestions/accept', ['pattern_id' => 999, 'items' => [['xl_gid' => 4739, 'interval_months' => 12]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pattern_id' => 'Pozycji wzorcowej nie ma już na liście — odśwież stronę.']);
        $this->postJson('/api/inspection-positions/suggestions/reject', ['pattern_id' => $pattern->id, 'xl_gids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['xl_gids' => 'Zaznacz co najmniej jedną podpowiedź.']);
    }

    public function test_pattern_without_core_words_or_token_gives_nothing(): void
    {
        $this->position($this->service(4737, 'U1', 'GP-6'), 'GP-6', 4);
        $this->position($this->service(4738, 'U2', 'PRZEGLĄD KOCA GAŚNICZEGO'), 'PRZEGLĄD KOCA GAŚNICZEGO', 4);
        $this->service(4739, 'U3', 'GP-2');
        $this->service(4740, 'U4', 'PRZEGLĄD KOCA GAŚNICZEGO DUŻEGO');

        Sanctum::actingAs($this->user());
        $this->getJson('/api/inspection-positions/suggestions')->assertOk()->assertJsonPath('data', []);
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('inspections.manage');

        return $user;
    }

    private function service(int $gid, string $code, string $name): int
    {
        DB::table('erp_services')->insert([
            'xl_gid' => $gid, 'xl_type' => 4, 'code' => $code, 'name' => $name, 'unit' => 'szt', 'archived' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $gid;
    }

    private function item(string $name, bool $archived = false): int
    {
        $gid = ++$this->itemGid;
        DB::table('erp_items')->insert([
            'xl_gid' => $gid, 'code' => 'T'.$gid, 'name' => $name, 'unit' => 'szt', 'archived' => $archived,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $gid;
    }

    private function itemId(int $gid): int
    {
        return (int) DB::table('erp_items')->where('xl_gid', $gid)->value('id');
    }

    private function position(int $gid, string $name, int $type, ?int $renewedBy = null): InspectionPosition
    {
        return InspectionPosition::query()->create([
            'xl_gid' => $gid, 'xl_type' => $type, 'code' => 'P'.$gid, 'name' => $name, 'interval_months' => 12,
            'renewed_by_xl_gid' => $renewedBy,
        ]);
    }

    private function saleLine(int $customer, int $itemGid, string $issued, float $quantity): void
    {
        static $doc = 0;
        $doc++;
        DB::table('inspection_sale_lines')->insert([
            'document_type' => 2033, 'document_id' => $doc, 'line' => 1, 'document_number' => 'FS-'.$doc,
            'issued_on' => $issued, 'sold_on' => $issued, 'customer_xl_gid' => $customer, 'xl_item_gid' => $itemGid,
            'xl_item_type' => 4, 'quantity' => $quantity, 'net_value' => $quantity * 9, 'synced_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
