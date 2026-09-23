<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardMatchCandidate;
use App\Models\Client;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductSourcePrice;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Catalog\CardMatchFinder;
use App\Support\ProductIdentifierCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Plan „pozycja → karta” propozycji „Łączenie kart” (krok 5): P4S 6X00 „Półmaska 3M 6000” w trzech rozmiarach obok
 * trzech kart 3M (7000146845/46/47 — osobna karta na rozmiar w tej samej cenie), kłódki w kolorach, rozmiary w różnych
 * cenach, właściciel łączący rozmiary sam (UVEX), niepewne plany i blokady.
 */
final class CardMatchPlanTest extends TestCase
{
    use RefreshDatabase;

    private B2bAccount $p4s;

    private B2bAccount $mmm;

    private B2bAccount $uvex;

    private B2bAccount $anro;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->p4s = $this->account('p4s');
        $this->mmm = $this->account('3m');
        $this->uvex = $this->account('uvex');
        $this->anro = $this->account('anro');
    }

    public function test_p4s_sizes_on_three_3m_cards_in_one_price_are_size_merge(): void
    {
        [$s, $m, $l, $source] = $this->halfMask();
        ProductImage::query()->create(['product_id' => $s->id, 'b2b_account_id' => $this->mmm->id, 'path' => 's.jpg', 'is_primary' => true, 'sort_order' => 0, 'checksum' => 's']);
        $finder = app(CardMatchFinder::class);

        $summary = $finder->refresh();

        $this->assertSame(1, $summary['pending']);
        $this->assertSame(['pending' => 1, 'conflict' => 0], $summary['by_kind']['size_merge']);
        $this->assertSame(['size' => 1, 'color' => 0, 'unknown' => 0], $summary['signals']);
        $row = CardMatchCandidate::query()->sole();
        $ids = [$s->id, $m->id, $l->id];
        sort($ids);
        $this->assertSame('size_merge', $row->kind);
        $this->assertSame('pending', $row->status);
        $this->assertNull($row->target_product_id);
        $this->assertSame($ids, $row->conflict_product_ids);
        $this->assertSame(implode(',', $ids), $row->targets_key);
        $this->assertSame($source->id, $row->source_product_id);
        $this->assertNull($row->reason);
        $this->assertSame(40, strlen((string) $row->plan_hash));

        $plan = $row->plan;
        $this->assertSame(1, $plan['version']);
        $this->assertSame('size', $plan['signal']);
        $this->assertStringContainsString('P4S', $plan['source_label']);
        $this->assertTrue($plan['same_owner']);
        $this->assertTrue($plan['equal_prices']);
        $this->assertSame([], $plan['price_differences']);
        $this->assertSame([], $plan['blockers']);
        // naturalnie wg kodu pozycji u dystrybutora
        $this->assertSame(['6X00/L', '6X00/M', '6X00/S'], array_column($plan['positions'], 'remote_sku'));
        $third = $plan['positions'][2];
        $this->assertSame('b2b:'.$this->p4s->id, $third['source_key']);
        $this->assertSame('p-s', $third['position_key']);
        $this->assertSame('rozmiar S (mały)', $third['label']);
        $this->assertSame('S (mały)', $third['size_label']);
        $this->assertSame($s->id, $third['target_product_id']);
        $this->assertNull($third['target_ids']);
        $this->assertSame('manufacturer_code', $third['matched_by']);
        $this->assertSame('7000146845', $third['matched_value']);
        $this->assertSame('size', $third['signal']);
        $this->assertStringContainsString('rozmiar S (mały)', $third['signal_why']);
        $this->assertStringContainsString('nazwa 3M różni się: mały, 6100', $third['signal_why']);
        $this->assertSame([
            'keep_product_id' => $s->id,
            'common_name' => 'Półmaska wielokrotnego użytku 3M™',
            'sizes' => [
                ['product_id' => $l->id, 'label' => 'L (duży)', 'code' => '7000146847'],
                ['product_id' => $m->id, 'label' => 'M (średni)', 'code' => '7000146846'],
                ['product_id' => $s->id, 'label' => 'S (mały)', 'code' => '7000146845'],
            ],
        ], $plan['suggested']);

        // evaluate z planem = zapis; bez planu — pola sprzed kroku 5
        $evaluated = $finder->evaluate($source, true);
        $this->assertSame($row->plan_hash, $evaluated['plan_hash']);
        $this->assertSame('size_merge', $evaluated['kind']);
        $this->assertArrayNotHasKey('kind', $finder->evaluate($source));

        // dwa kolejne odświeżenia — ten sam wiersz, ten sam skrót planu
        $finder->refresh();
        $finder->refresh();
        $again = CardMatchCandidate::query()->sole();
        $this->assertSame($row->id, $again->id);
        $this->assertSame($row->plan_hash, $again->plan_hash);
    }

    public function test_padlock_colors_on_one_distributor_card_are_split_pending(): void
    {
        [, $source] = $this->padlocks();

        app(CardMatchFinder::class)->refresh();

        $row = CardMatchCandidate::query()->sole();
        $this->assertSame($source->id, $row->source_product_id);
        $this->assertSame('split', $row->kind);
        $this->assertSame('pending', $row->status);
        $this->assertSame('color', $row->plan['signal']);
        $this->assertSame(['color', 'color', 'color'], array_column($row->plan['positions'], 'signal'));
        $this->assertStringContainsString('kolor w etykiecie', $row->plan['positions'][0]['signal_why']);
        $this->assertNull($row->plan['suggested']);
        $this->assertTrue($row->plan['equal_prices']);
        $this->assertTrue($row->plan['same_owner']);
    }

    public function test_sizes_in_different_prices_are_split_pending_with_price_differences(): void
    {
        [$s, $m, $l] = $this->halfMask(prices: [61.38, 61.38, 70.00]);

        app(CardMatchFinder::class)->refresh();

        $row = CardMatchCandidate::query()->sole();
        $this->assertSame('split', $row->kind);
        $this->assertSame('pending', $row->status);
        $this->assertSame('size', $row->plan['signal']);
        $this->assertFalse($row->plan['equal_prices']);
        $this->assertTrue($row->plan['same_owner']);
        $difference = $row->plan['price_differences'][0];
        $this->assertSame('b2b:'.$this->mmm->id, $difference['source_key']);
        $this->assertSame(
            [$s->id => '61.38', $m->id => '61.38', $l->id => '70.00'],
            array_column($difference['values'], 'purchase_price', 'product_id'),
        );
        $this->assertSame('PLN', $difference['values'][0]['currency']);
    }

    public function test_owner_connector_grouping_sizes_itself_makes_signal_unknown_conflict(): void
    {
        $cards = [];
        foreach (['7' => '9600010', '8' => '9600020'] as $size => $code) {
            $cards[] = $this->producerCard($this->uvex, 'UVEX', 'Rękawice uvex phynomic '.$size, $code, 20.0);
        }
        $this->distributorCard('PHY', 'UVEX', [
            ['p-7', 'PHY/7', '9600010', 'rozmiar 7'],
            ['p-8', 'PHY/8', '9600020', 'rozmiar 8'],
        ]);

        app(CardMatchFinder::class)->refresh();

        $row = CardMatchCandidate::query()->sole();
        $this->assertSame('split', $row->kind);
        $this->assertSame('conflict', $row->status);
        $this->assertSame('unknown', $row->plan['signal']);
        $this->assertSame(['unknown_signal'], array_column($row->plan['blockers'], 'code'));
        $this->assertStringContainsString('sam łączy rozmiary, a te kody trzyma osobno — to nie są rozmiary', (string) $row->reason);
        $this->assertStringContainsString('sam łączy rozmiary', $row->plan['positions'][0]['signal_why']);
        $this->assertCount(2, $cards);
    }

    public function test_empty_labels_with_equal_prices_are_unknown_conflict(): void
    {
        $this->halfMask(labels: [null, null, null]);

        app(CardMatchFinder::class)->refresh();

        $row = CardMatchCandidate::query()->sole();
        $this->assertSame('split', $row->kind);
        $this->assertSame('conflict', $row->status);
        $this->assertSame('unknown', $row->plan['signal']);
        $this->assertSame(['unknown_signal'], array_column($row->plan['blockers'], 'code'));
        $this->assertSame('nie wiadomo, czy pozycje różnią się rozmiarem czy kolorem', $row->reason);
    }

    public function test_positions_without_key_ambiguous_or_missing_make_conflict_with_codes(): void
    {
        $a = $this->producerCard($this->mmm, '3M', 'Filtr 3M 6051', '7000000601', 30.0);
        $b = $this->producerCard($this->mmm, '3M', 'Filtr 3M 6055', '7000000605', 30.0);
        // kod pozycji C jest na obu kartach 3M
        foreach ([$a, $b] as $card) {
            $this->identifier($card, 'b2b:'.$this->mmm->id, 'shared-'.$card->id, 'source_code', '7000009999', null);
        }
        $source = $this->distributorCard('FILTR', '3M', [
            ['p-a', 'FILTR/A', '7000000601', 'rozmiar S'],
            ['p-c', 'FILTR/C', '7000009999', 'rozmiar L'],
            ['p-d', 'FILTR/D', 'ZZ-99999', 'rozmiar XL'],
        ]);
        // pozycja B bez identyfikatorów
        $this->link($source, $this->p4s, 'p-b', 'FILTR/B');

        app(CardMatchFinder::class)->refresh();

        $row = CardMatchCandidate::query()->sole();
        $this->assertSame('split', $row->kind);
        $this->assertSame('conflict', $row->status);
        $this->assertSame([$a->id, $b->id], $row->conflict_product_ids);
        $codes = array_column($row->plan['blockers'], 'code');
        $this->assertContains('position_no_key', $codes);
        $this->assertContains('position_ambiguous', $codes);
        $this->assertContains('position_missing', $codes);
        $this->assertStringContainsString('pozycja FILTR/B nie ma EAN-u ani kodu producenta', (string) $row->reason);
        $this->assertStringContainsString('pozycja FILTR/C wskazuje kilka kart producenta (#'.$a->id.', #'.$b->id.')', (string) $row->reason);
        $this->assertStringContainsString('pozycja FILTR/D nie trafia w żadną kartę producenta', (string) $row->reason);
        $byCode = array_column($row->plan['positions'], null, 'remote_sku');
        $this->assertSame([$a->id, $b->id], $byCode['FILTR/C']['target_ids']);
        $this->assertNull($byCode['FILTR/C']['target_product_id']);
        $this->assertNull($byCode['FILTR/B']['target_product_id']);
        $this->assertNull($byCode['FILTR/B']['matched_by']);
    }

    public function test_size_merge_blockers_on_producer_cards(): void
    {
        [$s, $m, $l] = $this->halfMask();
        $other = Product::query()->create(['sku' => 'FILTR', 'name' => 'Filtr', 'manufacturer' => '3M']);
        $tender = Tender::query()->create([
            'number' => 'PRZ/1', 'title' => 'Test', 'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => User::factory()->create()->id, 'status' => 'wycena', 'ai_percent' => 0, 'last_activity_at' => now(),
        ]);
        TenderItem::query()->create(['tender_id' => $tender->id, 'line_no' => 1, 'requirement' => 'Półmaska', 'main_product_id' => $s->id]);
        TenderItem::query()->create(['tender_id' => $tender->id, 'line_no' => 2, 'requirement' => 'Półmaska', 'companion_product_id' => $m->id]);
        DB::table('presta_product_matches')->insert([
            'product_id' => $l->id, 'presta_id' => 10, 'method' => 'sku', 'score' => 100, 'status' => 'confirmed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_accessories')->insert([
            'product_id' => $other->id, 'related_product_id' => $s->id, 'source' => 'presta', 'link_key' => 'k1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(CardMatchFinder::class)->refresh();

        $row = CardMatchCandidate::query()->sole();
        $this->assertSame('size_merge', $row->kind);
        $this->assertSame('conflict', $row->status);
        $blockers = array_map(static fn (array $b): string => $b['code'].':'.$b['text'], $row->plan['blockers']);
        $this->assertContains('target_tender:karta producenta #'.$s->id.' jest w pozycjach przetargów (1)', $blockers);
        $this->assertContains('target_tender:karta producenta #'.$m->id.' jest w pozycjach przetargów (1)', $blockers);
        $this->assertContains('target_presta:karta producenta #'.$l->id.' jest powiązana z Prestą (1)', $blockers);
        $this->assertContains('target_accessories:karta producenta #'.$s->id.' ma akcesoria albo jest akcesorium innej karty (1)', $blockers);
        $this->assertCount(4, $blockers);
        // sugestia zostaje do wglądu
        $this->assertNotNull($row->plan['suggested']);
    }

    public function test_split_blockers_on_distributor_card(): void
    {
        [, $source] = $this->padlocks();
        $other = Product::query()->create(['sku' => 'INNA', 'name' => 'Inna kłódka', 'manufacturer' => 'ABUS']);
        $tender = Tender::query()->create([
            'number' => 'PRZ/2', 'title' => 'Test', 'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => User::factory()->create()->id, 'status' => 'wycena', 'ai_percent' => 0, 'last_activity_at' => now(),
        ]);
        TenderItem::query()->create(['tender_id' => $tender->id, 'line_no' => 1, 'requirement' => 'Kłódka', 'main_product_id' => $source->id]);
        DB::table('product_substitutes')->insert([
            'main_product_id' => $other->id, 'substitute_product_id' => $source->id, 'type' => 'tanszy',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(CardMatchFinder::class)->refresh();

        $row = CardMatchCandidate::query()->sole();
        $this->assertSame('split', $row->kind);
        $this->assertSame('conflict', $row->status);
        $this->assertSame(['source_tender', 'source_substitutes'], array_column($row->plan['blockers'], 'code'));
        $this->assertStringContainsString('karta dystrybutora jest w pozycjach przetargów (1)', (string) $row->reason);
        $this->assertStringContainsString('karta dystrybutora ma zamienniki albo jest zamiennikiem (1)', (string) $row->reason);
    }

    public function test_sizes_with_different_owners_are_split_pending(): void
    {
        $list = PriceList::query()->create(['manufacturer' => '3M', 'version' => '2026-09']);
        $s = $this->producerCard($this->mmm, '3M', 'Półmaska wielokrotnego użytku 3M™, rozmiar mały, 6100', '7000146845', 61.38);
        $m = $this->producerCard($this->mmm, '3M', 'Półmaska wielokrotnego użytku 3M™, rozmiar średni, 6200', '7000146846', 61.38);
        // karta L tylko z cennika producenta z pliku
        $l = Product::query()->create(['sku' => '7000146847', 'name' => 'Półmaska wielokrotnego użytku 3M™, rozmiar duży, 6300', 'manufacturer' => '3M']);
        ProductSourcePrice::query()->create([
            'product_id' => $l->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id,
            'purchase_price' => 61.38, 'catalog_price_net' => 61.38, 'currency' => 'PLN',
        ]);
        $this->identifier($l, 'file:'.$list->id, '7000146847', 'manufacturer_code', '7000146847', null);
        $this->distributorCard('6X00', '3M', [
            ['p-s', '6X00/S', '7000146845', 'rozmiar S (mały)'],
            ['p-m', '6X00/M', '7000146846', 'rozmiar M (średni)'],
            ['p-l', '6X00/L', '7000146847', 'rozmiar L (duży)'],
        ]);

        app(CardMatchFinder::class)->refresh();

        $row = CardMatchCandidate::query()->sole();
        $this->assertSame('split', $row->kind);
        $this->assertSame('pending', $row->status);
        $this->assertSame('size', $row->plan['signal']);
        $this->assertFalse($row->plan['same_owner']);
        $this->assertTrue($row->plan['equal_prices']);
        $this->assertSame([$s->id, $m->id, $l->id], $row->conflict_product_ids);
    }

    public function test_rejected_size_merge_does_not_return_after_labels_change_to_colors(): void
    {
        [, , , $source] = $this->halfMask();
        $finder = app(CardMatchFinder::class);
        $finder->refresh();
        $row = CardMatchCandidate::query()->sole();
        $row->forceFill(['status' => 'rejected', 'decided_at' => now()])->save();

        ProductIdentifier::query()->where('product_id', $source->id)->update(['variant_label' => 'kolor czarny']);
        $summary = $finder->refresh();

        $this->assertSame(0, $summary['pending'] + $summary['conflict']);
        $after = CardMatchCandidate::query()->sole();
        $this->assertSame($row->id, $after->id);
        $this->assertSame('rejected', $after->status);
        $this->assertSame('size_merge', $after->kind);
    }

    public function test_single_target_pair_stays_merge(): void
    {
        $anroCard = Product::query()->create(['sku' => 'IF/016/F/PS', 'name' => 'Półmaska ANRO IF/016/F/PS', 'manufacturer' => 'Anro']);
        $this->link($anroCard, $this->anro, 'A-IF/016/F/PS', 'IF/016/F/PS');
        $this->distributorCard('ZPPV99C', 'ANRO', [['P-ZPPV99C', 'ZPPV99C', 'IF/016/F/PS', 'rozmiar M']]);

        $summary = app(CardMatchFinder::class)->refresh();

        $this->assertSame(['pending' => 1, 'conflict' => 0], $summary['by_kind']['merge']);
        $this->assertSame(['size' => 0, 'color' => 0, 'unknown' => 0], $summary['signals']);
        $row = CardMatchCandidate::query()->sole();
        $this->assertSame('merge', $row->kind);
        $this->assertSame('pending', $row->status);
        $this->assertSame($anroCard->id, $row->target_product_id);
        $this->assertSame((string) $anroCard->id, $row->targets_key);
        $this->assertNull($row->plan);
        $this->assertNull($row->plan_hash);
        $this->assertNull($row->conflict_product_ids);
    }

    public function test_plan_data_queries_do_not_grow_with_number_of_plans(): void
    {
        $this->halfMask();
        $finder = app(CardMatchFinder::class);
        $finder->refresh();
        $before = $this->countPlanQueries(fn () => $finder->refresh());

        $this->halfMask(model: '7X00', codeBase: '70001469');
        $this->halfMask(model: '8X00', codeBase: '70001470');
        $finder->refresh();
        $after = $this->countPlanQueries(fn () => $finder->refresh());

        $this->assertSame(3, CardMatchCandidate::query()->where('kind', 'size_merge')->count());
        $this->assertGreaterThan(0, $before);
        $this->assertSame($before, $after);
    }

    /**
     * Zapytania danych planu (liczniki blokad, etykiety pozycji, karty z liczbą zdjęć) — bez zapytań CardOwnership,
     * które idą karta po karcie od kroku B.
     */
    private function countPlanQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(static function ($query) use (&$count): void {
            if (preg_match('/tender_items|presta_product_matches|product_substitutes|product_accessories|product_special_prices|variant_label|product_images/', $query->sql) === 1) {
                $count++;
            }
        });
        $callback();

        return $count;
    }

    /**
     * Trzy karty 3M (konto 3M, kod = remote_sku) i karta P4S z trzema rozmiarami.
     *
     * @param  list<float>  $prices
     * @param  list<string|null>  $labels
     * @return array{0: Product, 1: Product, 2: Product, 3: Product}
     */
    private function halfMask(array $prices = [61.38, 61.38, 61.38], array $labels = ['rozmiar S (mały)', 'rozmiar M (średni)', 'rozmiar L (duży)'], string $model = '6X00', string $codeBase = '70001468'): array
    {
        $cards = [];
        $positions = [];
        foreach ([['S', 'mały', '6100', '45'], ['M', 'średni', '6200', '46'], ['L', 'duży', '6300', '47']] as $i => [$size, $word, $number, $suffix]) {
            $code = $codeBase.$suffix;
            $cards[] = $this->producerCard($this->mmm, '3M', 'Półmaska wielokrotnego użytku 3M™, rozmiar '.$word.', '.$number, $code, $prices[$i]);
            $positions[] = ['p-'.strtolower($size).($model !== '6X00' ? '-'.$model : ''), $model.'/'.$size, $code, $labels[$i]];
        }
        $source = $this->distributorCard($model, '3M', $positions);

        return [$cards[0], $cards[1], $cards[2], $source];
    }

    /**
     * Trzy kłódki ABUS z cennika producenta (plik) i karta P4S z trzema kolorami.
     *
     * @return array{0: list<Product>, 1: Product}
     */
    private function padlocks(): array
    {
        $list = PriceList::query()->create(['manufacturer' => 'ABUS', 'version' => '2026-09']);
        $cards = [];
        $positions = [];
        foreach (['CZERWONA' => 'czerwony', 'ZIELONA' => 'zielony', 'ZOLTA' => 'żółty'] as $name => $color) {
            $code = 'KLB-'.substr($name, 0, 3).'-01';
            $card = Product::query()->create(['sku' => $code, 'name' => 'KLODKA B '.$name.' RK', 'manufacturer' => 'ABUS']);
            ProductSourcePrice::query()->create([
                'product_id' => $card->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id,
                'purchase_price' => 12.5, 'catalog_price_net' => 15, 'currency' => 'PLN',
            ]);
            $this->identifier($card, 'file:'.$list->id, $code, 'manufacturer_code', $code, null);
            $cards[] = $card;
            $positions[] = ['k-'.$color, 'KLB/'.$name, $code, 'kolor '.$color];
        }

        return [$cards, $this->distributorCard('KLB', 'ABUS', $positions)];
    }

    /** Karta producenta z konta producenta: kod producenta = remote_sku powiązania, slot ceny konta. */
    private function producerCard(B2bAccount $account, string $manufacturer, string $name, string $code, float $price): Product
    {
        $card = Product::query()->create(['sku' => $code, 'name' => $name, 'manufacturer' => $manufacturer]);
        $this->link($card, $account, 'r-'.$code, $code);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::b2bKey($account->id), 'b2b_account_id' => $account->id,
            'purchase_price' => $price, 'catalog_price_net' => $price, 'currency' => 'PLN',
        ]);

        return $card;
    }

    /**
     * Karta P4S: pozycja = [remote_id, remote_sku, kod producenta, etykieta].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string|null}>  $positions
     */
    private function distributorCard(string $sku, string $manufacturer, array $positions): Product
    {
        $card = Product::query()->create(['sku' => $sku, 'name' => 'P4S '.$sku, 'manufacturer' => $manufacturer]);
        foreach ($positions as [$remoteId, $remoteSku, $code, $label]) {
            $this->link($card, $this->p4s, $remoteId, $remoteSku);
            $this->identifier($card, 'b2b:'.$this->p4s->id, $remoteId, 'manufacturer_code', $code, $label);
        }

        return $card;
    }

    private function link(Product $card, B2bAccount $account, string $remoteId, ?string $remoteSku): void
    {
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id, 'remote_id' => $remoteId, 'remote_sku' => $remoteSku, 'product_id' => $card->id,
        ]);
    }

    private function identifier(Product $card, string $source, string $position, string $type, string $value, ?string $label): void
    {
        ProductIdentifier::query()->create([
            'product_id' => $card->id,
            'source_key' => $source,
            'position_key' => $position,
            'type' => $type,
            'value' => $value,
            'normalized' => ProductIdentifierCode::normalize($type, $value),
            'variant_label' => $label,
            'manufacturer' => $card->manufacturer,
            'last_seen_at' => now(),
        ]);
    }

    private function account(string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $connector, 'password' => 'x', 'connector' => $connector, 'sites' => ['b2b.'.$connector.'.example.test'],
        ]);
    }
}
