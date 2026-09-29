<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\Product;
use App\Services\Erp\ErpItemMatcher;
use App\Services\Erp\ErpSearchSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ErpSearchSuggesterTest extends TestCase
{
    use RefreshDatabase;

    private int $gid = 1;

    public function test_item_without_code_gets_cards_sharing_a_rare_model_word_of_the_same_kind(): void
    {
        $tactyl = $this->card('RR-TACT', 'Reis', 'Rękawice ochronne TACTYL nitrylowe');
        $this->card('RR-NIT', 'Reis', 'Rękawice ochronne nitrylowe');
        $bootsNamedTactyl = $this->card('BT-TACT', 'Reis', 'Trzewiki ochronne TACTYL S3');
        $item = $this->item('ARĘKTACTYL', 'RĘKAWICE TACTYL', stock: 12);

        $stats = $this->suggest();

        $this->assertSame(['checked' => 1, 'with_suggestions' => 1, 'suggestions' => 1], $stats);
        $link = ErpItemLink::query()->where('erp_item_id', $item->id)->sole();
        $this->assertSame($tactyl->id, $link->product_id);
        $this->assertSame(ErpItemLink::STATUS_SUGGESTED, $link->status);
        $this->assertSame(ErpItemLink::METHOD_SEARCH, $link->method);
        $this->assertSame(['TACTYL'], $link->evidence['shared_words']);
        $this->assertTrue($link->evidence['same_family']);
        $item->refresh();
        $this->assertSame('search_suggested', $item->match_outcome);
        $this->assertNotNull($item->search_checked_at);
        $this->assertSame(0, ErpItemLink::query()->where('product_id', $bootsNamedTactyl->id)->count());
    }

    public function test_common_words_and_other_brand_give_no_suggestion(): void
    {
        for ($i = 1; $i <= 41; $i++) {
            $this->card('W-'.$i, 'Reis', 'Rękawice winylowe białe rozmiar '.$i);
        }
        $this->card('PHY-A', 'ANSELL', 'Rękawice PHYNOMIC powlekane');
        $this->card('UV-1', 'UVEX', 'Okulary UVEX inne');
        $common = $this->item('ARKWIN', 'RĘKAWICE WINYLOWE BIAŁE', stock: 5);
        $otherBrand = $this->item('ARKPHY', 'RĘKAWICE UVEX PHYNOMIC', stock: 5);

        $stats = $this->suggest();

        $this->assertSame(2, $stats['checked']);
        $this->assertSame(0, $stats['suggestions']);
        $this->assertSame('no_code', $common->refresh()->match_outcome);
        $this->assertSame('no_code', $otherBrand->refresh()->match_outcome);
    }

    public function test_descriptive_words_frequent_in_xl_and_items_of_unknown_kind_give_no_suggestion(): void
    {
        // „FLANELOWA” rzadkie w katalogu, ale częste w nazwach XL — to opis, nie model
        $this->card('PL-FL', 'Polstar', 'BONO KOSZULA KRATKA czerwień');
        for ($i = 1; $i <= 26; $i++) {
            $this->item('AKOSZ'.$i, 'KOSZULA KRATKA '.$i, outcome: 'auto');
        }
        $shirt = $this->item('AKOSZX', 'KOSZULA KRATKA ROZMIAR', stock: 4);
        // „SKARPETA LEO” — nazwa XL bez rodzaju wyrobu ochronnego, karta to rękawice
        $this->card('ARD-LEO', 'ARDON', 'Rękawice antyprzecięciowe LEO CUT');
        $sock = $this->item('ASKLEO', 'SKARPETA LEO', stock: 4);

        $stats = $this->suggest();

        $this->assertSame(0, $stats['suggestions']);
        $this->assertSame('no_code', $shirt->refresh()->match_outcome);
        $this->assertSame('no_code', $sock->refresh()->match_outcome);
    }

    public function test_only_active_unlinked_items_and_rechecks_after_the_period(): void
    {
        $this->card('RR-TACT', 'Reis', 'Rękawice ochronne TACTYL nitrylowe');
        $this->item('A-OLD', 'RĘKAWICE TACTYL STARE', stock: 0, sold: '-30 months');
        $this->item('A-LINKED', 'RĘKAWICE TACTYL', stock: 5, outcome: 'auto');
        $fresh = $this->item('A-NEW', 'RĘKAWICE TACTYL NOWE', stock: 0, sold: '-2 months');

        $this->assertSame(1, $this->suggest()['checked']);
        $this->assertSame(0, $this->suggest()['checked']);

        $this->travel(31)->days();
        $this->assertSame(1, $this->suggest()['checked']);
        $this->assertSame('search_suggested', $fresh->refresh()->match_outcome);
    }

    public function test_rejected_suggestion_does_not_come_back_and_outcome_falls_back(): void
    {
        $card = $this->card('RR-TACT', 'Reis', 'Rękawice ochronne TACTYL nitrylowe');
        $item = $this->item('ARĘKTACTYL', 'RĘKAWICE TACTYL', stock: 3);
        $this->suggest();
        ErpItemLink::query()->where('erp_item_id', $item->id)->update(['status' => ErpItemLink::STATUS_REJECTED]);

        $this->travel(31)->days();
        $this->suggest();

        $this->assertSame([[$card->id, 'rejected']], ErpItemLink::query()->get()->map(fn ($l) => [$l->product_id, $l->status])->all());
        $this->assertSame('no_code', $item->refresh()->match_outcome);
    }

    public function test_code_matching_keeps_search_suggestions_until_a_code_link_appears(): void
    {
        $tactyl = $this->card('RR-TACT', 'Reis', 'Rękawice ochronne TACTYL nitrylowe');
        $item = $this->item('ARĘKTACTYL', 'RĘKAWICE TACTYL', stock: 3);
        $this->suggest();

        $this->travel(1)->minutes();
        $stats = app(ErpItemMatcher::class)->refresh();
        $this->assertSame(1, $stats['search_suggested']);
        $this->assertSame('search_suggested', $item->refresh()->match_outcome);
        $this->assertSame([$tactyl->id], ErpItemLink::query()->where('erp_item_id', $item->id)->pluck('product_id')->all());

        // w XL dopisano kod producenta do Nazwa1 — łączy się po kodzie, propozycja z wyszukiwarki znika
        $coded = $this->card('90210', 'Reis', 'Rękawice TACTYL 90210');
        $item->update(['name1' => 'RR-90210', 'suppliers' => [['supplier' => 'REIS']]]);
        $coded->update(['sku' => 'RR-90210']);
        $this->travel(1)->minutes();
        app(ErpItemMatcher::class)->refresh();

        $this->assertSame('auto', $item->refresh()->match_outcome);
        $this->assertSame([[$coded->id, 'auto']], ErpItemLink::query()->where('erp_item_id', $item->id)->get()->map(fn ($l) => [$l->product_id, $l->status])->all());
    }

    public function test_search_suggestions_of_archived_items_are_removed_by_code_matching(): void
    {
        $this->card('RR-TACT', 'Reis', 'Rękawice ochronne TACTYL nitrylowe');
        $item = $this->item('ARĘKTACTYL', 'RĘKAWICE TACTYL', stock: 3);
        $this->suggest();
        $item->update(['archived' => true]);

        $this->travel(1)->minutes();
        app(ErpItemMatcher::class)->refresh();

        $this->assertSame(0, ErpItemLink::query()->count());
    }

    /** @return array{checked: int, with_suggestions: int, suggestions: int} */
    private function suggest(): array
    {
        return app(ErpSearchSuggester::class)->run(100, 30);
    }

    private function card(string $sku, string $manufacturer, string $name): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => $manufacturer,
            'catalog_price_net' => 10, 'purchase_price' => 5, 'stock' => 0,
        ]);
    }

    private function item(string $code, string $name, float $stock = 0, ?string $sold = null, string $outcome = 'no_code'): ErpItem
    {
        return ErpItem::query()->create([
            'xl_gid' => $this->gid++, 'code' => $code, 'name' => $name, 'unit' => 'szt', 'archived' => false,
            'stock_trade' => $stock, 'stock_total' => $stock, 'match_outcome' => $outcome, 'synced_at' => now(),
            'last_sale_at' => $sold !== null ? now()->modify($sold)->toDateString() : null,
        ]);
    }
}
