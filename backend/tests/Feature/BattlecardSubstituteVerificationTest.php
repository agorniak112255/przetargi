<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Uwagi eksperta 24.09 do przetargu 1: zamienniki z oceną słowną 98–99% przeczyły SIWZ. Poz. 7 (EN 388 4341B, EN 407
 * ciepło kontaktowe 1): zimowe Canis 2X31X i bawełniane frotte bez EN 388 obok MaxiCut Oil. Poz. 2 (odporne na
 * przecięcie, bez poziomu): RCFB-2369 COVENT FOAM 2131X. Zamiennik z katalogu przechodzi Weryfikację karty.
 */
final class BattlecardSubstituteVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const POZ2 = 'Rękawice ochronne odporne na przecięcie, przeznaczone do prac z narzędziami tnącymi, ostrymi elementami i szkłem '
        .'– m.in. w budownictwie, przemyśle szklarskim, chemicznym i motoryzacyjnym oraz przy pracach ze skalpelem lub nożem drukarskim. '
        .'Wymagane: ochrona dłoni przed przecięciem i ścieraniem potwierdzona oznakowaniem zgodnie z EN 388; konstrukcja zapewniająca '
        .'elastyczność, wygodę i precyzję pracy.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_line_7_substitutes_contradicting_levels_are_dropped_and_verified_one_stays(): void
    {
        $ours = $this->glove('44-305', 'MaxiCut Oil rękawice antyprzecięciowe powlekane NBR', 'ATG', 'EN 388:2016 + A1:2018 – 4341B, EN 407:2004 – X1XXXX', 30);
        $this->glove('3700-010-160-09', 'Rękawice zimowe antyprzecięciowe powlekane lateksem, ściągacz', 'Canis', 'EN ISO 21420, EN 388: 2X31X, EN 511: X2X, EN 407: X2XXXX', 6);
        $this->glove('RJ-BAFRO', 'Rękawice ochronne dziane termiczne powlekane, ściągacz, odporne na ciepło kontaktowe', 'JS', '', 5);
        $this->glove('44-304', 'MaxiCut Oil rękawice antyprzecięciowe powlekane NBR, ściągacz', 'ATG', 'EN 388:2016 + A1:2018 – 4341B, EN 407:2004 – X1XXXX', 20);
        [$tender, $item] = $this->item(Opisowy15Fixture::requirement(7), $ours);

        $card = $this->getJson("/api/tenders/{$tender->id}/items/{$item->id}/battlecard")->assertOk()->json('battlecard');
        $subs = collect($card['substitutes'])->keyBy('sku');

        $this->assertSame(['44-304'], $subs->keys()->all(), 'Canis 2X31X przeczy 4341B, frotte nie podaje EN 388 ani EN 407');
        $this->assertSame('ok', $subs['44-304']['verification']['status']);
        $this->assertTrue($subs['44-304']['price_comparable']);
        $this->assertSame('words', $subs['44-304']['match_basis']);
        $this->assertSame(['Zamiennik 44-304 (ATG) tańszy o ok. 33% (po upuście).'], $card['highlights']);
    }

    public function test_saved_list_from_before_verification_is_filtered_on_read(): void
    {
        $ours = $this->glove('44-305', 'MaxiCut Oil rękawice antyprzecięciowe powlekane NBR', 'ATG', 'EN 388:2016 + A1:2018 – 4341B, EN 407:2004 – X1XXXX', 30);
        $canis = $this->glove('3700-010-160-09', 'Rękawice zimowe powlekane lateksem', 'Canis', 'EN ISO 21420, EN 388: 2X31X, EN 511: X2X, EN 407: X2XXXX', 6);
        [$tender, $item] = $this->item(Opisowy15Fixture::requirement(7), $ours);
        // zapis z produkcji sprzed 24.09: 99% z oceny słownej, bez podstawy wyniku
        $item->battlecard_substitutes = [['product_id' => $canis->id, 'match_percent' => 99, 'source' => 'catalog', 'substitute_type' => 'katalog', 'approval_status' => null, 'reason' => null]];
        $item->save();

        $this->getJson("/api/tenders/{$tender->id}/items/{$item->id}/battlecard")
            ->assertOk()
            ->assertJsonCount(0, 'battlecard.substitutes')
            ->assertJsonPath('battlecard.highlights', []);

        $this->postJson("/api/tenders/{$tender->id}/items/apply-cheaper-substitutes", ['dry_run' => true])
            ->assertOk()
            ->assertJsonPath('candidates_count', 0);
    }

    public function test_line_2_coup_1_glove_is_not_a_cut_resistant_substitute(): void
    {
        $ours = $this->glove('SHARK 6', 'Rękawice antyprzecięciowe TK Gloves SHARK powlekane nitrylem', 'TK GLOVES', 'EN ISO 21420:2020, EN 388:2016+A1:2019 (4544C)', 3.3);
        $this->glove('RCFB-2369', 'Rękawice COVENT FOAM powlekane lateksem, odporne na przecięcie, ściągacz', 'Polstar', 'EN 388:2016+A1:2018 – poziom 2131X, EN ISO 21420:2020', 1.35);
        $this->glove('3630-020-000-00', 'Rękawice antyprzecięciowe powlekane nitrylem, odporne na przecięcie', 'Canis', 'EN 388: 4544 (przetarcie 4, przecięcie Coup X, rozerwanie 4, przekłucie 3), EN 388: odporność na przecięcie ISO – klasa D, EN 420', 7.38);
        [$tender, $item] = $this->item(self::POZ2, $ours);

        $subs = collect($this->getJson("/api/tenders/{$tender->id}/items/{$item->id}/battlecard")->assertOk()->json('battlecard.substitutes'))->keyBy('sku');

        $this->assertArrayNotHasKey('RCFB-2369', $subs->all(), 'Coup Test 1 bez litery ISO to nie odporność na przecięcie (min. B)');
        $this->assertArrayHasKey('3630-020-000-00', $subs->all());
        $this->assertSame('ok', $subs['3630-020-000-00']['verification']['status']);
        $this->assertSame([['label' => 'Poziom cięcia ISO 13997', 'status' => 'ok']], array_map(
            static fn (array $row): array => ['label' => $row['label'], 'status' => $row['status']],
            $subs['3630-020-000-00']['verification']['rows'],
        ));
    }

    public function test_requirement_without_levels_keeps_substitute_but_not_for_price_swap(): void
    {
        $ours = $this->glove('NITRYL-MAIN', 'Rękawice robocze nitrylowe ze ściągaczem', 'REJS', '', 10);
        $this->glove('NITRYL-B', 'Rękawice robocze nitrylowe ze ściągaczem wariant B', 'OTHER', '', 4);
        [$tender, $item] = $this->item('Rękawice robocze nitrylowe ze ściągaczem', $ours);

        $card = $this->getJson("/api/tenders/{$tender->id}/items/{$item->id}/battlecard")->assertOk()->json('battlecard');

        $this->assertSame('none', $card['substitutes'][0]['verification']['status']);
        $this->assertFalse($card['substitutes'][0]['price_comparable'], 'bez poziomów w SIWZ zamiennik jest niesprawdzony');
        $this->assertSame([], $card['highlights']);
    }

    private function glove(string $sku, string $name, string $manufacturer, string $norms, float $price): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'category' => 'Rękawice',
            'norms' => $norms === '' ? null : $norms,
            'description' => $name.'. '.$norms,
            'catalog_price_net' => $price,
            'purchase_price' => $price,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }

    /**
     * @return array{0: Tender, 1: TenderItem}
     */
    private function item(string $requirement, Product $ours): array
    {
        $tender = Tender::query()->create([
            'number' => 'PRZ/BC/VERIFY/'.$ours->sku,
            'title' => 'Weryfikacja zamienników',
            'client_id' => Client::query()->create(['name' => 'Klient weryfikacji'])->id,
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
