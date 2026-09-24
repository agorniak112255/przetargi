<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bAccountManufacturerRule;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Pricing\ProductEffectivePrice;
use App\Services\Pricing\SourcePriceComparison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ceny od najtańszej (etap E planu łączenia kart, 23.09.2026): porównanie cen zakupu ze źródeł karty w PLN,
 * źródła poza porównaniem z powodem, „taniej u …” na liście i w przetargu. Cena karty bez zmian — decyzja 3.
 */
final class SourcePriceComparisonTest extends TestCase
{
    use RefreshDatabase;

    private ProductEffectivePrice $prices;

    private SourcePriceComparison $comparison;

    private User $user;

    private int $sku = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::forget('nbp.table_a.rates');
        Http::fake([
            'api.nbp.pl/*' => Http::response([[
                'effectiveDate' => '2026-09-22',
                'rates' => [['code' => 'EUR', 'mid' => 4.0]],
            ]]),
        ]);
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00'));

        $this->prices = app(ProductEffectivePrice::class);
        $this->comparison = app(SourcePriceComparison::class);
        $this->user = User::factory()->create();
    }

    public function test_production_atg_card_distributor_is_cheapest_and_suggested_list_is_out(): void
    {
        // karta 9669 ATG „MaxiChem Cut”: Ardon 33,50 zł obowiązuje, cennik ATG sugerowany 52,72 = 52,72
        $card = $this->card('ATG');
        $ardon = $this->account('ardon');
        $list = PriceList::query()->create([
            'manufacturer' => 'ATG', 'manufacturer_key' => 'atg', 'version' => 'sugerowany', 'suggested_prices' => true,
        ]);
        $this->link($card, $ardon, 'ATG');
        $this->b2bSlot($card, $ardon, 33.50, 'PLN', '2026-09-22 03:00');
        $this->fileSlot($card, $list, 52.72);

        $result = $this->forCard($card);
        $ardonRow = $result['rows'][ProductSourcePrice::b2bKey($ardon->id)];
        $fileRow = $result['rows'][ProductSourcePrice::SOURCE_FILE];

        $this->assertSame(33.5, $ardonRow['purchase_price_pln']);
        $this->assertTrue($ardonRow['comparable']);
        $this->assertSame(1, $ardonRow['price_rank']);
        $this->assertTrue($ardonRow['is_cheapest']);
        $this->assertSame(0.0, $ardonRow['diff_to_effective_pct']);
        $this->assertFalse($fileRow['comparable']);
        $this->assertSame('cennik sugerowany', $fileRow['not_comparable_reason']);
        $this->assertNull($fileRow['price_rank']);
        $this->assertFalse($fileRow['is_cheapest']);
        // same złote: kurs nieużyty, NBP nie pytany
        $this->assertSame(['as_of' => null, 'source' => 'nbp'], $result['rates']);

        $this->assertNull($this->comparison->cheaperSources(collect([$card->fresh()]))[$card->id]);
        $this->assertConsistent($card);
        Http::assertNothingSent();
    }

    public function test_cheaper_distributor_is_shown_but_manufacturer_price_stays_effective(): void
    {
        $card = $this->card('ATG');
        $atg = $this->account('atg');
        $ardon = $this->account('ardon');
        $this->link($card, $atg);
        $this->link($card, $ardon);
        $this->b2bSlot($card, $atg, 10.00, 'PLN', '2026-09-20 10:00');
        $this->b2bSlot($card, $ardon, 9.00, 'PLN', '2026-09-22 10:00');

        // decyzja 3: cena karty dalej od producenta
        $this->assertSame('10.00', $card->fresh()->purchase_price);

        $rows = $this->forCard($card)['rows'];
        $this->assertSame(1, $rows[ProductSourcePrice::b2bKey($ardon->id)]['price_rank']);
        $this->assertSame(-10.0, $rows[ProductSourcePrice::b2bKey($ardon->id)]['diff_to_effective_pct']);
        $this->assertSame(2, $rows[ProductSourcePrice::b2bKey($atg->id)]['price_rank']);
        $this->assertSame(0.0, $rows[ProductSourcePrice::b2bKey($atg->id)]['diff_to_effective_pct']);

        $this->assertSame([
            'source_key' => ProductSourcePrice::b2bKey($ardon->id),
            'label' => 'B2B Ardon',
            'purchase_price_pln' => 9.0,
            'diff_pct' => -10.0,
        ], $this->comparison->cheaperSources(collect([$card->fresh()]))[$card->id]);
        $this->assertConsistent($card);
    }

    public function test_eur_price_is_converted_with_nbp_rate(): void
    {
        $card = $this->card('Bolle');
        $bolle = $this->account('bolle');
        $procera = $this->account('procera');
        $this->link($card, $bolle);
        $this->link($card, $procera);
        // Bolle (producent) 2,50 EUR = 10,00 zł obowiązuje; Procera 8,00 zł
        $this->b2bSlot($card, $bolle, 2.50, 'EUR', '2026-09-22 10:00');
        $this->b2bSlot($card, $procera, 8.00, 'PLN', '2026-09-22 11:00');

        $result = $this->forCard($card);
        $rows = $result['rows'];
        $this->assertSame(['as_of' => '2026-09-22', 'source' => 'nbp'], $result['rates']);
        $this->assertSame(10.0, $rows[ProductSourcePrice::b2bKey($bolle->id)]['purchase_price_pln']);
        $this->assertSame(0.0, $rows[ProductSourcePrice::b2bKey($bolle->id)]['diff_to_effective_pct']);
        $this->assertSame(-20.0, $rows[ProductSourcePrice::b2bKey($procera->id)]['diff_to_effective_pct']);
        $this->assertSame(-20.0, $this->comparison->cheaperSources(collect([$card->fresh()]))[$card->id]['diff_pct']);
        $this->assertConsistent($card);
    }

    public function test_each_out_of_comparison_source_says_why(): void
    {
        $card = $this->card('ATG');
        $atg = $this->account('atg');
        $stale = $this->account('ardon');
        $unlinked = $this->account('tegro');
        $foreign = $this->account('p4s');
        $noPurchase = $this->account('polstar');
        $unit = $this->account('rawpol');
        $this->link($card, $atg);
        $this->link($card, $stale);
        $this->link($card, $foreign);
        $this->link($card, $noPurchase);
        $this->link($card, $unit);
        $list = PriceList::query()->create(['manufacturer' => 'Hurtownia', 'manufacturer_key' => 'hurtownia', 'version' => 'v1']);

        $this->b2bSlot($card, $atg, 30.00, 'PLN', '2026-09-22 10:00');
        $this->b2bSlot($card, $stale, 20.00, 'PLN', '2026-08-14 12:00'); // 40 dni
        $this->b2bSlot($card, $unlinked, 21.00, 'PLN', '2026-09-22 10:00');
        $this->b2bSlot($card, $foreign, 5.00, 'XYZ', '2026-09-22 10:00');
        $this->b2bSlot($card, $noPurchase, null, 'PLN', '2026-09-22 10:00', catalog: 25.00);
        $this->b2bSlot($card, $unit, 9.00, 'PLN', '2026-09-22 10:00'); // 30 / 9 > 3
        // karton po 10 szt. przy cenie za sztukę — ilość w opakowaniu sama nie wyklucza ceny z porównania
        $this->fileSlot($card, $list, 35.00, packQty: 10);

        $rows = $this->forCard($card)['rows'];
        $reason = static fn (B2bAccount|string $source): ?string => $rows[is_string($source) ? $source : ProductSourcePrice::b2bKey($source->id)]['not_comparable_reason'];

        $this->assertNull($reason($atg));
        $this->assertSame('cena niepotwierdzona od 40 dni', $reason($stale));
        $this->assertSame('dostawca nie ma już tej pozycji', $reason($unlinked));
        $this->assertSame('nieznana waluta XYZ', $reason($foreign));
        $this->assertNull($rows[ProductSourcePrice::b2bKey($foreign->id)]['purchase_price_pln']);
        $this->assertSame('brak ceny zakupu', $reason($noPurchase));
        $this->assertSame('sprawdź jednostkę (ponad 3× taniej)', $reason($unit));
        $this->assertNull($reason(ProductSourcePrice::SOURCE_FILE));
        // najtańsze porównywalne źródło to obowiązujące konto producenta — nic tańszego do pokazania
        $this->assertSame(1, $rows[ProductSourcePrice::b2bKey($atg->id)]['price_rank']);
        $this->assertTrue($rows[ProductSourcePrice::b2bKey($atg->id)]['is_cheapest']);
        $this->assertSame(2, $rows[ProductSourcePrice::SOURCE_FILE]['price_rank']);
        // różnica liczona także poza porównaniem, gdy jest cena zakupu w PLN (np. stara cena −33,3%)
        $this->assertSame(-33.3, $rows[ProductSourcePrice::b2bKey($stale->id)]['diff_to_effective_pct']);
        $this->assertNull($this->comparison->cheaperSources(collect([$card->fresh()]))[$card->id]);
        $this->assertConsistent($card);
    }

    public function test_disabled_manufacturer_price_has_the_explain_reason(): void
    {
        $card = $this->card('ATG');
        $ardon = $this->account('ardon');
        $tegro = $this->account('tegro');
        $this->link($card, $ardon, 'ATG Glovesolutions');
        $this->link($card, $tegro);
        $this->b2bSlot($card, $tegro, 12.00, 'PLN', '2026-09-21 10:00');
        $this->b2bSlot($card, $ardon, 8.00, 'PLN', '2026-09-22 10:00');
        B2bAccountManufacturerRule::query()->create([
            'b2b_account_id' => $ardon->id, 'manufacturer' => 'ATG Glovesolutions',
            'manufacturer_key' => PriceList::manufacturerKey('ATG Glovesolutions'), 'take_price' => false, 'take_description' => true,
        ]);
        $this->prices->refresh($card);

        $explain = $this->prices->explain($card->fresh());
        $rows = $this->forCard($card)['rows'];
        $key = ProductSourcePrice::b2bKey($ardon->id);
        $this->assertSame($explain['reasons'][$key], $rows[$key]['not_comparable_reason']);
        $this->assertSame('cena producenta ATG Glovesolutions wyłączona w tym cenniku', $rows[$key]['not_comparable_reason']);
        $this->assertNull($this->comparison->cheaperSources(collect([$card->fresh()]))[$card->id]);
        $this->assertConsistent($card);
    }

    public function test_card_with_active_variants_compares_nothing(): void
    {
        $card = $this->card('SignProject');
        $a = $this->account('tegro');
        $b = $this->account('polstar');
        $this->link($card, $a);
        $this->link($card, $b);
        $this->b2bSlot($card, $a, 10.00, 'PLN', '2026-09-22 10:00');
        $this->b2bSlot($card, $b, 5.00, 'PLN', '2026-09-22 10:00');
        ProductVariant::query()->create([
            'product_id' => $card->id, 'source' => 'b2b:'.$a->id, 'remote_id' => 'W1', 'label' => 'wersja', 'purchase_price' => 1.00, 'currency' => 'PLN',
        ]);

        $rows = $this->forCard($card)['rows'];
        foreach ($rows as $row) {
            $this->assertFalse($row['comparable']);
            $this->assertSame('ceny w wersjach karty', $row['not_comparable_reason']);
            $this->assertNull($row['price_rank']);
        }
        $this->assertNull($this->comparison->cheaperSources(collect([$card->fresh()]))[$card->id]);
    }

    public function test_tie_puts_effective_first_and_small_saving_is_not_shown(): void
    {
        $card = $this->card('ATG');
        $atg = $this->account('atg');
        $ardon = $this->account('ardon');
        $tegro = $this->account('tegro');
        foreach ([$atg, $ardon, $tegro] as $account) {
            $this->link($card, $account);
        }
        $this->b2bSlot($card, $atg, 10.00, 'PLN', '2026-09-20 10:00');
        $this->b2bSlot($card, $ardon, 10.00, 'PLN', '2026-09-22 10:00');
        $this->b2bSlot($card, $tegro, 10.05, 'PLN', '2026-09-22 10:00');

        $rows = $this->forCard($card)['rows'];
        $this->assertTrue($rows[ProductSourcePrice::b2bKey($atg->id)]['is_cheapest']);
        $this->assertSame(2, $rows[ProductSourcePrice::b2bKey($ardon->id)]['price_rank']);
        $this->assertNull($this->comparison->cheaperSources(collect([$card->fresh()]))[$card->id]);

        // −0,5% to szum — „taniej u …” dopiero od −1%
        $this->b2bSlot($card, $tegro, 9.95, 'PLN', '2026-09-22 11:00');
        $this->assertSame(-0.5, $this->forCard($card)['rows'][ProductSourcePrice::b2bKey($tegro->id)]['diff_to_effective_pct']);
        $this->assertNull($this->comparison->cheaperSources(collect([$card->fresh()]))[$card->id]);
        $this->assertConsistent($card);
    }

    public function test_list_uses_effective_slot_not_stale_card_price(): void
    {
        // cena karty sprzed przeliczenia (12 zł), a obowiązuje konto producenta 10 zł — najtańsze jest obowiązujące,
        // więc „taniej u …” nie ma, choć 10 zł < 12 zł
        $card = $this->card('ATG');
        $atg = $this->account('atg');
        $ardon = $this->account('ardon');
        $this->link($card, $atg);
        $this->link($card, $ardon);
        $this->b2bSlot($card, $atg, 10.00, 'PLN', '2026-09-20 10:00');
        $this->b2bSlot($card, $ardon, 11.00, 'PLN', '2026-09-22 10:00');
        Product::query()->whereKey($card->id)->update(['purchase_price' => 12.00]);

        $rows = $this->forCard($card)['rows'];
        $this->assertTrue($rows[ProductSourcePrice::b2bKey($atg->id)]['is_cheapest']);
        $this->assertNull($this->comparison->cheaperSources(collect([$card->fresh()]))[$card->id]);
        $this->assertConsistent($card);
    }

    public function test_card_without_purchase_price_has_no_cheaper_source_and_no_diff(): void
    {
        $card = $this->card('ATG');
        $ardon = $this->account('ardon');
        $tegro = $this->account('tegro');
        $this->link($card, $ardon);
        $this->link($card, $tegro);
        $this->b2bSlot($card, $ardon, 10.00, 'PLN', '2026-09-22 10:00');
        $this->b2bSlot($card, $tegro, 8.00, 'PLN', '2026-09-21 10:00');
        Product::query()->whereKey($card->id)->update(['purchase_price' => 0]);

        $rows = $this->forCard($card)['rows'];
        $this->assertTrue($rows[ProductSourcePrice::b2bKey($tegro->id)]['is_cheapest']);
        $this->assertNull($rows[ProductSourcePrice::b2bKey($tegro->id)]['diff_to_effective_pct']);
        $this->assertNull($this->comparison->cheaperSources(collect([$card->fresh()]))[$card->id]);
    }

    public function test_cheaper_sources_query_count_does_not_grow_with_cards(): void
    {
        $atg = $this->account('atg');
        $ardon = $this->account('ardon');
        $make = function (int $n) use ($atg, $ardon): array {
            $ids = [];
            for ($i = 0; $i < $n; $i++) {
                $card = $this->card('ATG');
                $this->link($card, $atg);
                $this->link($card, $ardon);
                $this->b2bSlot($card, $atg, 10.00, 'PLN', '2026-09-20 10:00');
                $this->b2bSlot($card, $ardon, 9.00, 'PLN', '2026-09-22 10:00');
                $ids[] = $card->id;
            }

            return $ids;
        };
        $few = Product::query()->whereIn('id', $make(2))->get();
        $many = Product::query()->whereIn('id', $make(25))->get();

        $count = function ($cards): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $result = $this->comparison->cheaperSources($cards);
            DB::disableQueryLog();
            foreach ($result as $cheaper) {
                $this->assertSame('B2B Ardon', $cheaper['label']);
            }

            return count(DB::getQueryLog());
        };

        $this->assertSame($count($few), $count($many));
    }

    public function test_order_quantity_comes_from_effective_uvex_slot(): void
    {
        // gogle UVEX: koszyk konta przyjmuje tylko wielokrotności 10 szt.
        $card = $this->card('UVEX');
        $uvex = $this->account('uvex');
        $this->link($card, $uvex);
        $this->b2bSlot($card, $uvex, 40.00, 'PLN', '2026-09-22 10:00');
        $this->order($card, $uvex, 10.0, 10.0, 'szt');

        $this->assertSame([
            'min' => 10.0,
            'step' => 10.0,
            'unit' => 'szt',
            'varies' => false,
            'price_note' => null,
            'price_carton_qty' => null,
            'source_key' => ProductSourcePrice::b2bKey($uvex->id),
            'source_label' => 'B2B UVEX',
        ], $this->comparison->orderQuantities(collect([$card->fresh()]))[$card->id]);
        // karta wyrobu liczy to samo ze zwycięzcy explain()
        $winner = $this->prices->explain($card->fresh())['winner'];
        $this->assertNotNull($winner);
        $this->assertSame(
            $this->comparison->orderQuantities(collect([$card->fresh()]))[$card->id],
            $this->comparison->orderQuantityOf($winner),
        );
    }

    public function test_restricted_distributor_losing_to_manufacturer_has_no_order_quantity(): void
    {
        // konto producenta ATG bez warunku obowiązuje, warunek Ardonu (przegranego) nie trafia do listy
        $own = $this->card('ATG');
        $atg = $this->account('atg');
        $ardon = $this->account('ardon');
        $this->link($own, $atg);
        $this->link($own, $ardon);
        $this->b2bSlot($own, $atg, 10.00, 'PLN', '2026-09-20 10:00');
        $this->b2bSlot($own, $ardon, 9.00, 'PLN', '2026-09-22 10:00');
        $this->order($own, $ardon, 12.0, 12.0, 'szt');

        // cennik producenta z pliku wygrywa z dystrybutorem
        $file = $this->card('ATG');
        $list = PriceList::query()->create(['manufacturer' => 'ATG', 'manufacturer_key' => 'atg', 'version' => '2026']);
        $this->link($file, $ardon);
        $this->b2bSlot($file, $ardon, 9.00, 'PLN', '2026-09-22 10:00');
        $this->order($file, $ardon, 12.0, 12.0, 'szt');
        $this->fileSlot($file, $list, 10.00);

        $this->assertSame([], $this->comparison->orderQuantities(collect([$own->fresh(), $file->fresh()])));
    }

    public function test_order_quantity_without_restriction_varies_and_variants(): void
    {
        $uvex = $this->account('uvex');
        // min 1 bez kroku = zwykłe zamówienie
        $plain = $this->card('UVEX');
        $this->link($plain, $uvex);
        $this->b2bSlot($plain, $uvex, 40.00, 'PLN', '2026-09-22 10:00');
        $this->order($plain, $uvex, 1.0, null, 'szt');
        // rozmiary karty mają różne warunki
        $varies = $this->card('UVEX');
        $this->link($varies, $uvex);
        $this->b2bSlot($varies, $uvex, 40.00, 'PLN', '2026-09-22 10:00');
        $this->order($varies, $uvex, null, null, 'szt', true);
        // karta z aktywnymi wersjami nie ma obowiązującego slotu
        $withVariants = $this->card('UVEX');
        $this->link($withVariants, $uvex);
        $this->b2bSlot($withVariants, $uvex, 40.00, 'PLN', '2026-09-22 10:00');
        $this->order($withVariants, $uvex, 10.0, 10.0, 'szt');
        ProductVariant::query()->create([
            'product_id' => $withVariants->id, 'source' => 'b2b:'.$uvex->id, 'remote_id' => 'W1', 'label' => 'wersja', 'purchase_price' => 1.00, 'currency' => 'PLN',
        ]);

        $result = $this->comparison->orderQuantities(collect([$plain->fresh(), $varies->fresh(), $withVariants->fresh()]));

        $this->assertSame([$varies->id], array_keys($result));
        $this->assertTrue($result[$varies->id]['varies']);
        $this->assertNull($result[$varies->id]['min']);
        $this->assertNull($result[$varies->id]['step']);
        $this->assertSame('szt', $result[$varies->id]['unit']);
    }

    public function test_price_condition_alone_gives_the_card_its_purchase_terms_without_an_order_condition(): void
    {
        // Delta Plus: „Min. zam.” 1, ale cena konta tylko przy pełnym kartonie 120
        $card = $this->card('Delta Plus');
        $deltaplus = $this->account('deltaplus');
        $this->link($card, $deltaplus);
        $this->b2bSlot($card, $deltaplus, 14.07, 'PLN', '2026-09-22 10:00');
        $this->order($card, $deltaplus, 1.0, null, null);
        ProductSourcePrice::query()->where('product_id', $card->id)->update([
            'price_note' => 'Cena jednostkowa za pełny karton tego samego rozmiaru i koloru',
            'price_carton_qty' => 120,
        ]);

        $terms = $this->comparison->orderQuantities(collect([$card->fresh()]))[$card->id];

        // bez ograniczenia zamówienia min i step puste — widok pokazuje sam warunek ceny
        $this->assertNull($terms['min']);
        $this->assertNull($terms['step']);
        $this->assertFalse($terms['varies']);
        $this->assertSame('Cena jednostkowa za pełny karton tego samego rozmiaru i koloru', $terms['price_note']);
        $this->assertSame(120.0, $terms['price_carton_qty']);
        $this->assertSame(ProductSourcePrice::b2bKey($deltaplus->id), $terms['source_key']);
    }

    /**
     * forCard jak w GET /products/{id}: sloty z account i priceList, explain z ProductEffectivePrice.
     *
     * @return array{rows: array<string, array<string, mixed>>, rates: array{as_of: string|null, source: string}}
     */
    private function forCard(Product $card): array
    {
        $card = $card->fresh();
        $slots = ProductSourcePrice::query()
            ->with(['account:id,connector,sites', 'priceList:id,manufacturer,version,suggested_prices'])
            ->where('product_id', $card->id)
            ->get();

        return $this->comparison->forCard($card, $slots, $this->prices->explain($card));
    }

    /**
     * Karta i lista mówią to samo: „taniej u …” z listy = najtańszy wiersz karty, o ile nie jest obowiązującym
     * i daje co najmniej −1%.
     */
    private function assertConsistent(Product $card): void
    {
        $card = $card->fresh();
        $rows = $this->forCard($card)['rows'];
        $effective = $this->prices->explain($card)['winner']?->source_key;
        $expected = null;
        foreach ($rows as $key => $row) {
            if ($row['is_cheapest'] && $key !== $effective && $row['diff_to_effective_pct'] !== null && $row['diff_to_effective_pct'] <= -1.0) {
                $expected = [$key, $row['purchase_price_pln'], $row['diff_to_effective_pct']];
            }
        }
        $cheaper = $this->comparison->cheaperSources(collect([$card]))[$card->id];
        $this->assertSame(
            $expected,
            $cheaper === null ? null : [$cheaper['source_key'], $cheaper['purchase_price_pln'], $cheaper['diff_pct']],
        );
    }

    private function card(string $manufacturer): Product
    {
        $this->sku++;

        return Product::query()->create([
            'sku' => 'CMP-'.$this->sku,
            'name' => 'Rękawice ochronne',
            'manufacturer' => $manufacturer,
            'catalog_price_net' => 99.00,
            'discount_percent' => 0,
            'purchase_price' => 99.00,
            'currency' => 'PLN',
        ]);
    }

    private function account(string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $connector.'-konto', 'password' => 'sekret', 'sites' => ['b2b.example.pl'], 'connector' => $connector,
            'created_by' => $this->user->id, 'updated_by' => $this->user->id,
        ]);
    }

    private function link(Product $card, B2bAccount $account, ?string $manufacturer = null): void
    {
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id, 'remote_id' => 'R'.$card->id.'-'.$account->id, 'product_id' => $card->id,
            'manufacturer' => $manufacturer,
        ]);
    }

    private function b2bSlot(Product $card, B2bAccount $account, ?float $purchase, string $currency, string $checkedAt, ?float $catalog = null): void
    {
        $this->prices->saveSlot($card, ProductSourcePrice::b2bKey($account->id), [
            'b2b_account_id' => $account->id, 'catalog_price_net' => $catalog ?? $purchase, 'purchase_price' => $purchase,
            'currency' => $currency, 'checked_at' => Carbon::parse($checkedAt),
        ]);
    }

    /** Warunek zamawiania w slocie konta — jak zapis synchronizacji (B2bOrderQuantity::slotValues). */
    private function order(Product $card, B2bAccount $account, ?float $min, ?float $step, ?string $unit, bool $varies = false): void
    {
        ProductSourcePrice::query()
            ->where('product_id', $card->id)
            ->where('source_key', ProductSourcePrice::b2bKey($account->id))
            ->firstOrFail()
            ->update(['order_min_qty' => $min, 'order_step_qty' => $step, 'order_unit' => $unit, 'order_varies' => $varies]);
    }

    private function fileSlot(Product $card, PriceList $list, float $purchase, ?int $packQty = null): void
    {
        $this->prices->saveSlot($card, ProductSourcePrice::SOURCE_FILE, [
            'price_list_id' => $list->id, 'catalog_price_net' => $purchase, 'purchase_price' => $purchase,
            'currency' => 'PLN', 'pack_qty' => $packQty, 'checked_at' => Carbon::parse('2026-09-15 10:00'),
        ]);
    }
}
