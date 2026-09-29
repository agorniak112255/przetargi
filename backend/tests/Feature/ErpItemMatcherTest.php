<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Erp\ErpItemMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Przypadki z analizy XL ↔ katalog na danych produkcyjnych (29.09.2026). */
final class ErpItemMatcherTest extends TestCase
{
    use RefreshDatabase;

    private int $gid = 1;

    public function test_code_in_name_with_supplier_being_the_manufacturer_links_automatically(): void
    {
        $card = $this->card('9174.065', 'UVEX', 'Okulary Skylite 9174.065');
        $item = $this->item('SOK9174065', 'OKULARY UVEX 9174.065', suppliers: ['UVEX']);

        $stats = $this->match();

        $this->assertSame(1, $stats['auto']);
        $link = ErpItemLink::query()->where('erp_item_id', $item->id)->sole();
        $this->assertSame($card->id, $link->product_id);
        $this->assertSame(ErpItemLink::STATUS_AUTO, $link->status);
        $this->assertSame(ErpItemLink::METHOD_NAME, $link->method);
        $this->assertSame('9174.065', $link->matched_value);
        $this->assertSame('9174065', $link->matched_code);
        $this->assertTrue($link->evidence['supplier_match']);
        $this->assertTrue($link->evidence['brand_in_name']);
        $this->assertSame(ErpItemMatcher::RULES_VERSION, $link->evidence['rules']);
    }

    public function test_same_code_in_other_kind_of_product_is_rejected(): void
    {
        // „TRZEWIKI OAKLAND 7770” → rękawice TEGERA 12.7770 (kod modelu 7770)
        $gloves = $this->card('12.7770', 'TEGERA', 'Rękawice TEGERA 7770 Impact ochrona przed uderzeniami');
        $this->identifier($gloves, ProductIdentifier::TYPE_MODEL_CODE, '7770');
        $this->item('BTROAKLAND', 'TRZEWIKI OCHR. OAKLAND 7770 S7L', suppliers: ['VM IMPORT']);

        $stats = $this->match();

        $this->assertSame(1, $stats['family_conflict']);
        $this->assertSame(0, ErpItemLink::query()->count());
    }

    public function test_short_number_without_brand_evidence_only_suggests(): void
    {
        // „TARCICA IGLASTA 19-25” → maska Cederroth 1925
        $this->card('1925', 'CEDERROTH', 'Maska oddechowa Cederroth, w breloczku');
        $this->item('TBUTAR/1', 'TARCICA IGLASTA 19-25');

        $stats = $this->match();

        $this->assertSame(1, $stats['suggested']);
        $this->assertSame(ErpItemLink::STATUS_SUGGESTED, ErpItemLink::query()->sole()->status);
        $this->assertTrue(ErpItemLink::query()->sole()->evidence['weak_code']);
    }

    public function test_short_number_with_brand_in_name_links(): void
    {
        $card = $this->card('7000006980', '3M', '3M™ Półmaska filtrująca 8812, z zaworem, FFP1');
        $this->identifier($card, ProductIdentifier::TYPE_ALT_CODE, '8812');
        $this->item('SPŁ3M8812', 'PÓŁMASKA 3M 8812');

        $stats = $this->match();

        $this->assertSame(1, $stats['auto']);
        $this->assertSame($card->id, ErpItemLink::query()->sole()->product_id);
    }

    public function test_code_hitting_several_cards_is_narrowed_by_supplier_or_left_for_choice(): void
    {
        $ardon = $this->card('A8007/06', 'ARDON', 'Rękawice powlekane ARDON PETRAX');
        $other = $this->card('A8007-X', 'Polstar', 'Rękawice powlekane PETRAX inne');
        $this->identifier($ardon, ProductIdentifier::TYPE_MODEL_CODE, 'A8007');
        $this->identifier($other, ProductIdentifier::TYPE_MODEL_CODE, 'A8007');
        $narrowed = $this->item('ARĘKPETRAX8007', 'RĘKAWICE PETRAX', 'A8007', suppliers: ['ARDON PREROV']);

        $h1 = $this->card('11-814', 'ANSELL', 'Rękawice HYFLEX 11-814 powlekane');
        $h2 = $this->card('11-814-XL', 'ANSELL', 'Rękawice HYFLEX 11-814 rozmiar XL');
        $this->identifier($h2, ProductIdentifier::TYPE_MODEL_CODE, '11-814');
        $ambiguous = $this->item('ARĘK11814', 'RĘKAWICE HYFLEX 11-814', suppliers: ['ANSELL']);

        $stats = $this->match();

        $this->assertSame(1, $stats['auto']);
        $this->assertSame(1, $stats['ambiguous']);
        $this->assertSame([$ardon->id], ErpItemLink::query()->where('erp_item_id', $narrowed->id)->pluck('product_id')->all());
        $links = ErpItemLink::query()->where('erp_item_id', $ambiguous->id)->get();
        $this->assertEqualsCanonicalizing([$h1->id, $h2->id], $links->pluck('product_id')->all());
        $this->assertSame([ErpItemLink::STATUS_SUGGESTED], $links->pluck('status')->unique()->values()->all());
        $this->assertSame(2, $links->first()->evidence['candidates']);
    }

    public function test_other_brand_in_xl_name_without_card_brand_blocks_auto(): void
    {
        $this->card('PW-1', 'PORTWEST', 'Kurtka Portwest inna');
        $this->card('S470', 'ARDON', 'Kurtka NORDIC S470');
        $this->item('AKURS470', 'KURTKA PORTWEST ZIMOWA S470 NORDIC');

        $stats = $this->match();

        $this->assertSame(1, $stats['suggested']);
        $link = ErpItemLink::query()->sole();
        $this->assertSame('PORTWEST', $link->evidence['other_brand']);
        $this->assertSame(['NORDIC'], $link->evidence['shared_words']);
    }

    public function test_size_code_from_variants_and_xl_code_suffix_are_searched(): void
    {
        $card = $this->card('H9302-KARTA', 'ARDON', 'Spodnie ARDON 4TECH szwedzkie');
        ProductVariant::query()->create([
            'product_id' => $card->id, 'kind' => 'size', 'source' => 'b2b', 'remote_id' => 'r1', 'sku' => 'H9302/50',
            'label' => '50', 'purchase_price' => 1.00, 'currency' => 'PLN',
        ]);
        $helmet = $this->card('9762.130', 'UVEX', 'Hełm Airwing B-WR, żółty 9762.130');
        $this->item('ASPH930250', 'SPODNIE 4TECH SZWED H9302/50', suppliers: ['ARDON PREROV']);
        $this->item('SHE9762130', 'HEŁM AIRWING B-WR żółty', suppliers: ['UVEX']);

        $stats = $this->match();

        $this->assertSame(2, $stats['auto']);
        $this->assertSame(ErpItemLink::METHOD_XL_CODE, ErpItemLink::query()->where('product_id', $helmet->id)->sole()->method);
        $this->assertSame(1, ErpItemLink::query()->where('product_id', $card->id)->count());
    }

    public function test_human_decisions_are_kept_and_stale_automatic_links_removed(): void
    {
        $user = User::factory()->create();
        $a = $this->card('9174.065', 'UVEX', 'Okulary 9174.065');
        $b = $this->card('9161.145', 'UVEX', 'Okulary 9161.145');
        $c = $this->card('9178.065', 'UVEX', 'Okulary 9178.065');
        $rejectedItem = $this->item('SOK9174065', 'OKULARY UVEX 9174.065', suppliers: ['UVEX']);
        $confirmedItem = $this->item('SOK9161145', 'OKULARY UVEX 9161.145', suppliers: ['UVEX']);
        $staleItem = $this->item('SOK9178065', 'OKULARY UVEX 9178.065', suppliers: ['UVEX']);
        ErpItemLink::query()->create(['erp_item_id' => $rejectedItem->id, 'product_id' => $a->id, 'status' => ErpItemLink::STATUS_REJECTED,
            'method' => ErpItemLink::METHOD_NAME, 'decided_by' => $user->id, 'decided_at' => now()]);
        ErpItemLink::query()->create(['erp_item_id' => $confirmedItem->id, 'product_id' => $c->id, 'status' => ErpItemLink::STATUS_CONFIRMED,
            'method' => ErpItemLink::METHOD_MANUAL, 'decided_by' => $user->id, 'decided_at' => now()]);
        $this->match();
        $this->assertSame(ErpItemLink::STATUS_AUTO, ErpItemLink::query()->where('erp_item_id', $staleItem->id)->sole()->status);

        // karta zmienia kod — automatyczne powiązanie znika przy kolejnym przeliczeniu
        $c->update(['sku' => 'INNY-KOD']);
        $this->travel(1)->minutes();
        $stats = $this->match();

        $this->assertSame(1, $stats['confirmed_kept']);
        $this->assertSame(ErpItemLink::STATUS_REJECTED, ErpItemLink::query()->where('erp_item_id', $rejectedItem->id)->sole()->status);
        $this->assertSame([$c->id], ErpItemLink::query()->where('erp_item_id', $confirmedItem->id)->pluck('product_id')->all());
        $this->assertSame(0, ErpItemLink::query()->where('erp_item_id', $staleItem->id)->count());
        $this->assertSame(0, ErpItemLink::query()->where('product_id', $b->id)->count());
    }

    public function test_confirmation_whose_card_was_deleted_does_not_block_matching(): void
    {
        $gone = $this->card('STARY-1', 'UVEX', 'Karta do usunięcia');
        $card = $this->card('9174.065', 'UVEX', 'Okulary Skylite 9174.065');
        $item = $this->item('SOK9174065', 'OKULARY UVEX 9174.065', suppliers: ['UVEX']);
        ErpItemLink::query()->create(['erp_item_id' => $item->id, 'product_id' => $gone->id, 'status' => ErpItemLink::STATUS_CONFIRMED,
            'method' => ErpItemLink::METHOD_MANUAL, 'decided_at' => now()]);
        $gone->delete();

        $stats = $this->match();

        $this->assertSame(1, $stats['auto']);
        $this->assertSame(0, $stats['confirmed_kept']);
        // ślad decyzji zostaje obok nowego powiązania
        $this->assertSame(1, ErpItemLink::query()->where('status', ErpItemLink::STATUS_CONFIRMED)->whereNull('product_id')->count());
        $this->assertSame($card->id, ErpItemLink::query()->where('status', ErpItemLink::STATUS_AUTO)->sole()->product_id);
    }

    public function test_items_without_code_or_without_card_and_archived_items_get_no_links(): void
    {
        $this->card('9174.065', 'UVEX', 'Okulary 9174.065');
        $this->item('ARĘKTACTYL', 'RĘKAWICE TACTYL');
        $this->item('SOKX', 'OKULARY 5555-99');
        $this->item('SOK9174065', 'OKULARY UVEX 9174.065', suppliers: ['UVEX'], archived: true);

        $stats = $this->match();

        $this->assertSame(1, $stats['no_code']);
        $this->assertSame(1, $stats['no_match']);
        $this->assertSame(2, $stats['items']);
        $this->assertSame(0, ErpItemLink::query()->count());
    }

    public function test_norm_number_is_not_a_product_code(): void
    {
        // „APTECZKA W-3 DIN 13164” trafiała we wkład do apteczki Procera DIN13164
        $this->card('DIN13164', 'Procera', 'WYPOSAŻENIE DO APTECZKI DIN13164');
        $item = $this->item('SAPW3', 'APTECZKA W-3 DIN 13164', suppliers: ['VERA']);

        $stats = $this->match();

        $this->assertNotContains('DIN13164', array_column(app(ErpItemMatcher::class)->extract($item), 'code'));
        $this->assertSame(0, $stats['auto'] + $stats['suggested'] + $stats['ambiguous']);
    }

    public function test_generic_word_alone_is_not_evidence(): void
    {
        // „SZYBKA WEWN. 528005” — wspólne tylko „szybka”, dostawca nie jest producentem
        $this->card('7000000225', '3M', 'Wewnętrzna szybka ochronna 3M Speedglas 9100');
        $this->identifier(Product::query()->where('sku', '7000000225')->sole(), ProductIdentifier::TYPE_ALT_CODE, '528005');
        $this->item('SSZ528005', 'SZYBKA WEWN. 528005', suppliers: ['TONEX']);

        $stats = $this->match();

        $this->assertSame(1, $stats['suggested']);
        $this->assertSame([], ErpItemLink::query()->sole()->evidence['shared_words']);
    }

    public function test_measurements_are_not_codes(): void
    {
        $this->card('600ML', 'X', 'Płyn 600ml');
        $item = $this->item('HNEU600', 'NEUTRALIZATOR ZAPACHÓW 600ml', 'ODS/600');

        $codes = array_column(app(ErpItemMatcher::class)->extract($item), 'code');

        $this->assertNotContains('600ML', $codes);
        $this->assertContains('ODS600', $codes);
    }

    /** @return array<string, int> */
    private function match(): array
    {
        return app(ErpItemMatcher::class)->refresh();
    }

    private function card(string $sku, string $manufacturer, string $name): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => $manufacturer,
            'catalog_price_net' => 10, 'purchase_price' => 5, 'stock' => 0,
        ]);
    }

    private function identifier(Product $card, string $type, string $value): void
    {
        ProductIdentifier::query()->create([
            'product_id' => $card->id, 'type' => $type, 'value' => $value,
            'normalized' => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value)),
            'source_key' => 'b2b:1', 'position_key' => $value.'#'.$card->id,
        ]);
    }

    /** @param  list<string>  $suppliers */
    private function item(string $code, string $name, string $name1 = '', array $suppliers = [], bool $archived = false): ErpItem
    {
        return ErpItem::query()->create([
            'xl_gid' => $this->gid++, 'code' => $code, 'name' => $name, 'name1' => $name1 !== '' ? $name1 : null,
            'unit' => 'szt', 'archived' => $archived, 'synced_at' => now(),
            'suppliers' => array_map(static fn (string $s): array => ['supplier_id' => null, 'supplier' => $s, 'price' => 0, 'currency' => 'PLN', 'updated_at' => null], $suppliers),
        ]);
    }
}
