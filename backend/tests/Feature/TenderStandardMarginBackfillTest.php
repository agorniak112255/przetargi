<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\TenderPricingService;
use App\Support\OfferPricing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SupplierSpecialFixture;
use Tests\TestCase;

/**
 * Marża bliźniacza przy wdrożeniu: migracja 2026_09_30_210100 kopiuje marżę pozycji i przetargów bez kart z ceną
 * specjalną B2B, resztę zostawia NULL; tenders:backfill-standard-margins liczy braki od ceny standardowej, nie rusza
 * margin_percent ani dat zmian i drugi raz nic nie zmienia.
 */
final class TenderStandardMarginBackfillTest extends TestCase
{
    use RefreshDatabase;
    use SupplierSpecialFixture;

    private const OFFER = 204.36;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSupplierSpecial();
    }

    public function test_migration_copies_unaffected_margins_and_backfill_computes_the_rest(): void
    {
        $special = $this->supplierSpecialCard()['product'];
        $plain = Product::query()->create([
            'sku' => 'ZWYKLA-1', 'name' => 'Rękawice zwykłe', 'manufacturer' => 'REJS', 'catalog_price_net' => 50, 'purchase_price' => 40,
            'currency' => 'PLN', 'stock' => 1,
        ]);
        // karta ze slotem ocenionym jako „standard” nie jest dotknięta
        $standardSlotCard = Product::query()->create([
            'sku' => 'STANDARD-1', 'name' => 'Rękawice UVEX po cenie standardowej', 'manufacturer' => 'UVEX', 'catalog_price_net' => 211.37,
            'purchase_price' => 211.37, 'currency' => 'PLN', 'stock' => 1,
        ]);
        $this->uvexSlot($standardSlotCard, 211.37);

        $unaffected = $this->tender('A');
        $plainItem = $this->item($unaffected, $plain, null, 50.0, 20.0);
        $standardItem = $this->item($unaffected, $standardSlotCard, null, 250.0, 15.45);
        $unaffected->forceFill(['margin_percent' => 17.5])->saveQuietly();

        $affected = $this->tender('B');
        $specialItem = $this->item($affected, $special, null, self::OFFER, 15.25);
        $companionItem = $this->item($affected, $plain, $special, 300.0, 28.0);
        $noMarginItem = $this->item($affected, $special, null, null, null);
        $affected->forceFill(['margin_percent' => 22.0])->saveQuietly();
        // pozycja z marżą, której karta została skasowana — prawdziwej marży nie kopiujemy (mogła być od ceny specjalnej)
        $cardlessTender = $this->tender('H');
        $cardlessPlain = $this->item($cardlessTender, $plain, null, 50.0, 20.0);
        $cardless = $this->item($cardlessTender, $plain, null, 100.0, 50.0);
        DB::table('tender_items')->where('id', $cardless->id)->update(['main_product_id' => null]);
        $cardlessTender->forceFill(['margin_percent' => 40.0])->saveQuietly();
        $stamp = TenderItem::query()->whereKey($specialItem->id)->value('updated_at');

        $migration = require database_path('migrations/2026_09_30_210100_add_margin_percent_standard.php');
        $this->assertInstanceOf(Migration::class, $migration);
        $migration->down();
        $migration->up();

        $this->assertTwin('20.00', $plainItem);
        $this->assertTwin('15.45', $standardItem);
        $this->assertTwin('17.50', $unaffected);
        $this->assertTwin(null, $specialItem);
        $this->assertTwin(null, $companionItem);
        $this->assertTwin(null, $noMarginItem);
        $this->assertTwin(null, $affected);
        $this->assertTwin('20.00', $cardlessPlain);
        $this->assertTwin(null, $cardless);
        $this->assertTwin(null, $cardlessTender);

        // podgląd niczego nie zapisuje
        $this->artisan('tenders:backfill-standard-margins')->assertSuccessful();
        $this->assertTwin(null, $specialItem);
        $this->assertTwin(null, $affected);

        $this->artisan('tenders:backfill-standard-margins', ['--apply' => true])->assertSuccessful();

        $specialTwin = round((self::OFFER - 211.37) / self::OFFER * 100, 2);
        $companionTwin = round((300.0 - 40.0 - 211.37) / 300.0 * 100, 2);
        $this->assertTwin(number_format($specialTwin, 2, '.', ''), $specialItem);
        $this->assertTwin(number_format($companionTwin, 2, '.', ''), $companionItem);
        $this->assertTwin(null, $noMarginItem);
        // średnia ważona wartością linii (ilość 1): pozycja bez oferty liczy się jak dotąd — do niczego
        $weighted = round(($specialTwin * self::OFFER + $companionTwin * 300.0) / (self::OFFER + 300.0), 2);
        $this->assertTwin(number_format($weighted, 2, '.', ''), $affected);
        $this->assertTwin(null, $cardless);
        $this->assertTwin('20.00', $cardlessTender, 'pozycja bez kart poza średnią przetargu');

        // margin_percent i daty zmian bez zmian
        $this->assertSame('15.25', $specialItem->fresh()->margin_percent);
        $this->assertSame('28.00', $companionItem->fresh()->margin_percent);
        $this->assertSame('22.00', $affected->fresh()->margin_percent);
        $this->assertSame('20.00', $plainItem->fresh()->margin_percent);
        $this->assertEquals($stamp, TenderItem::query()->whereKey($specialItem->id)->value('updated_at'));
        $this->assertSame('173.19', $special->fresh()->purchase_price);

        // drugi przebieg niczego nie zmienia
        $before = $this->snapshot();
        Carbon::setTestNow(now()->addHour());
        $this->artisan('tenders:backfill-standard-margins', ['--apply' => true])->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_tender_twin_skips_items_that_can_never_get_a_twin(): void
    {
        $special = $this->supplierSpecialCard()['product'];
        $noPrice = Product::query()->create([
            'sku' => 'BEZ-CENY', 'name' => 'Karta bez ceny', 'manufacturer' => 'REJS', 'catalog_price_net' => 0,
            'purchase_price' => 0, 'currency' => 'PLN', 'stock' => 1,
        ]);
        $tender = $this->tender('C');
        $item = $this->item($tender, $special, null, self::OFFER, 15.25);
        // stare marże, których bliźniaczej nie da się dziś policzyć: karta bez ceny zakupu, karta skasowana
        // (klucz obcy wyzerował id) i skasowana karta główna przy zachowanym drugim produkcie
        $stale = $this->item($tender, $noPrice, null, 100.0, 10.0);
        $orphan = $this->item($tender, $special, null, 100.0, 12.0);
        $companionOnly = $this->item($tender, $noPrice, $special, 300.0, 40.0);
        DB::table('tender_items')->whereIn('id', [$orphan->id, $companionOnly->id])->update(['main_product_id' => null]);
        DB::table('tender_items')->whereIn('id', [$item->id, $stale->id, $orphan->id, $companionOnly->id])->update(['margin_percent_standard' => null]);
        $tender->forceFill(['margin_percent' => 14.0])->saveQuietly();
        DB::table('tenders')->where('id', $tender->id)->update(['margin_percent_standard' => null]);

        $this->artisan('tenders:backfill-standard-margins', ['--apply' => true])
            ->expectsOutputToContain('pozycje 1 uzupełnione, 3 bez wyniku; przetargi 1 uzupełnione, 0 bez wyniku')
            ->assertSuccessful();

        $specialTwin = round((self::OFFER - 211.37) / self::OFFER * 100, 2);
        $this->assertTwin(number_format($specialTwin, 2, '.', ''), $item);
        // prawdziwej marży nie kopiujemy nigdy — mogła powstać z ceny specjalnej
        $this->assertTwin(null, $stale);
        $this->assertTwin(null, $orphan);
        $this->assertTwin(null, $companionOnly);
        $this->assertSame('12.00', $orphan->fresh()->margin_percent, 'margin_percent nietknięta');
        // przetarg bez pozycji niepoliczalnych: ani w średniej, ani w wartości — nie czeka na nie w nieskończoność
        $this->assertTwin(number_format($specialTwin, 2, '.', ''), $tender);

        // przeliczenie pozycji bez kart zeruje obie marże — spójnie z pominięciem
        app(TenderPricingService::class)->recalculateItemMargin($orphan->fresh());
        $this->assertNull($orphan->fresh()->margin_percent);
        $this->assertTwin(null, $orphan);
    }

    public function test_backfill_scales_the_selected_size_with_reduced_columns(): void
    {
        $fixture = $this->supplierSpecialCard();
        $xl = $fixture['variants'][1];
        $tender = $this->tender('G');
        $item = $this->item($tender, $fixture['product'], null, 300.0, 36.67);
        DB::table('tender_items')->where('id', $item->id)->update([
            'main_variant_id' => $xl->id, 'main_variant_label' => 'XL', 'margin_percent_standard' => null,
        ]);
        $tender->forceFill(['margin_percent' => 36.67])->saveQuietly();
        DB::table('tenders')->where('id', $tender->id)->update(['margin_percent_standard' => null]);

        $this->artisan('tenders:backfill-standard-margins', ['--apply' => true])
            ->expectsOutputToContain('Marże od ceny standardowej: pozycje 1 uzupełnione')
            ->assertSuccessful();

        // rozmiar XL konta ze slotem specjalnym: 190,00 → 231,89 (kolumna b2b_account_id w wąskim odczycie)
        $twin = number_format(round((300.0 - (float) self::SPECIAL_SIZE_MAX_MASKED) / 300.0 * 100, 2), 2, '.', '');
        $this->assertTwin($twin, $item);
        $this->assertTwin($twin, $tender);
    }

    public function test_tender_twin_is_unknown_while_an_item_twin_is_missing(): void
    {
        $special = $this->supplierSpecialCard()['product'];
        $tender = $this->tender('E');
        $item = $this->item($tender, $special, null, self::OFFER, 15.25);
        $other = $this->item($tender, $special, null, 300.0, 30.0);
        $pricing = app(TenderPricingService::class);
        $pricing->recalculateItemMargin($item->fresh());
        DB::table('tender_items')->where('id', $other->id)->update(['margin_percent_standard' => null]);

        $pricing->recalculateTenderTotals($tender->fresh());

        $fresh = $tender->fresh();
        $this->assertNotNull($fresh->margin_percent, 'prawdziwa marża liczona jak dotąd');
        $this->assertNull($fresh->margin_percent_standard, 'bez bliźniaczej jednej pozycji średnia byłaby zaniżona');

        // po policzeniu brakującej pozycji marża bliźniacza przetargu wraca
        $pricing->recalculateItemMargin($other->fresh());
        $pricing->recalculateTenderTotals($tender->fresh());
        $this->assertNotNull($tender->fresh()->margin_percent_standard);
    }

    public function test_price_list_option_overwrites_twins_of_items_with_cards_of_that_list(): void
    {
        // SECURA dostała cenę specjalną: standard 22,78 zamiast dawnej ceny, od której liczono zapisane marże
        ['product' => $secura, 'list' => $list] = $this->securaFileCard();
        $other = $this->securaFileCard('SEC-OTHER', [], [])['product'];
        $otherList = PriceList::query()->create(['manufacturer' => 'Inny', 'manufacturer_key' => 'inny', 'version' => '1']);
        ProductSourcePrice::query()->where('product_id', $other->id)->update(['price_list_id' => $otherList->id]);
        $plain = Product::query()->create([
            'sku' => 'ZWYKLA-2', 'name' => 'Rękawice zwykłe', 'manufacturer' => 'REJS', 'catalog_price_net' => 50, 'purchase_price' => 40,
            'currency' => 'PLN', 'stock' => 1,
        ]);

        $affected = $this->tender('S1');
        $main = $this->plainItem($affected, $secura, null, 30.0, 40.0, 30.0);
        $companion = $this->plainItem($affected, $plain, $secura, 80.0, 30.0, 30.0);
        $plainItem = $this->plainItem($affected, $plain, null, 50.0, 20.0, 20.0);
        $affected->forceFill(['margin_percent' => 30.0, 'margin_percent_standard' => 27.0])->saveQuietly();
        // karta innego cennika i przetarg bez kart SECURA — bez zmian
        $untouched = $this->tender('S2');
        $otherItem = $this->plainItem($untouched, $other, null, 30.0, 40.0, 33.0);
        $untouched->forceFill(['margin_percent' => 40.0, 'margin_percent_standard' => 33.0])->saveQuietly();
        $stamp = TenderItem::query()->whereKey($main->id)->value('updated_at');
        $before = $this->snapshot();

        // podgląd: liczy, nic nie zapisuje
        $this->artisan('tenders:backfill-standard-margins', ['--price-list' => $list->id])
            ->expectsOutputToContain('pozycje 2 przeliczone, 0 bez wyniku (NULL); przetargi 1 przeliczone')
            ->assertSuccessful();
        $this->assertSame($before, $this->snapshot());

        $this->artisan('tenders:backfill-standard-margins', ['--price-list' => $list->id, '--apply' => true])->assertSuccessful();

        $mainTwin = round((30.0 - 22.78) / 30.0 * 100, 2);
        $companionTwin = round((80.0 - 40.0 - 22.78) / 80.0 * 100, 2);
        $this->assertTwin(number_format($mainTwin, 2, '.', ''), $main);
        $this->assertTwin(number_format($companionTwin, 2, '.', ''), $companion);
        $this->assertTwin('20.00', $plainItem);
        $weighted = round(($mainTwin * 30.0 + $companionTwin * 80.0 + 20.0 * 50.0) / 160.0, 2);
        $this->assertTwin(number_format($weighted, 2, '.', ''), $affected);
        $this->assertTwin('33.00', $otherItem);
        $this->assertTwin('33.00', $untouched);
        // margin_percent i daty zmian nietknięte
        $this->assertSame('40.00', $main->fresh()->margin_percent);
        $this->assertSame('30.00', $affected->fresh()->margin_percent);
        $this->assertEquals($stamp, TenderItem::query()->whereKey($main->id)->value('updated_at'));

        // karta bez ceny: stara bliźniacza mogła zdradzać cenę specjalną — NULL zamiast niej
        $secura->forceFill(['purchase_price' => 0, 'catalog_price_net' => 0])->save();
        ProductSourcePrice::query()->where('product_id', $secura->id)->update(['purchase_price' => 0]);
        $this->artisan('tenders:backfill-standard-margins', ['--price-list' => $list->id, '--apply' => true])
            ->expectsOutputToContain('pozycje 1 przeliczone, 1 bez wyniku (NULL)')
            ->assertSuccessful();
        $this->assertTwin(null, $main);
        $this->assertSame('40.00', $main->fresh()->margin_percent);

        $this->artisan('tenders:backfill-standard-margins', ['--price-list' => 999999])->assertFailed();
    }

    public function test_recalculation_rejects_a_revealing_mask_for_the_twin(): void
    {
        $special = $this->supplierSpecialCard()['product'];
        $item = $this->item($this->tender('F'), $special, null, self::OFFER, null);

        $this->expectException(\InvalidArgumentException::class);
        app(TenderPricingService::class)->recalculateItemMargin($item, SupplierSpecialMask::revealing());
    }

    private function assertTwin(?string $expected, Tender|TenderItem $model, string $message = ''): void
    {
        $table = $model instanceof Tender ? 'tenders' : 'tender_items';
        $value = DB::table($table)->where('id', $model->id)->value('margin_percent_standard');
        $this->assertSame($expected, $value === null ? null : number_format((float) $value, 2, '.', ''), $message ?: $table.' #'.$model->id);
    }

    private function snapshot(): string
    {
        return (string) json_encode([
            'items' => DB::table('tender_items')->orderBy('id')->get(['id', 'margin_percent', 'margin_percent_standard', 'updated_at']),
            'tenders' => DB::table('tenders')->orderBy('id')->get(['id', 'margin_percent', 'margin_percent_standard', 'updated_at']),
        ]);
    }

    private function tender(string $suffix): Tender
    {
        return Tender::query()->create([
            'number' => 'PRZ/BLIZ/'.$suffix,
            'title' => 'Marża bliźniacza '.$suffix,
            'client_id' => Client::query()->create(['name' => 'Klient '.$suffix])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'target_margin_percent' => 18,
            'last_activity_at' => now(),
        ]);
    }

    /** Pozycja z zapisanymi obiema marżami; drugi produkt w osobnej cenie 40,00 zł oferty. */
    private function plainItem(Tender $tender, Product $main, ?Product $companion, float $lineOffer, float $margin, float $twin): TenderItem
    {
        $item = new TenderItem([
            'tender_id' => $tender->id,
            'line_no' => TenderItem::query()->where('tender_id', $tender->id)->count() + 1,
            'requirement' => 'Półmaska',
            'main_product_id' => $main->id,
            'companion_product_id' => $companion?->id,
            'quantity' => 1,
            'offer_price' => $companion !== null ? $lineOffer - 40.0 : $lineOffer,
            'companion_offer_price' => $companion !== null ? 40.0 : null,
            'margin_percent' => $margin,
            'status' => 'matched',
        ]);
        // marża bliźniacza poza fillable — wprost, jak zapisuje ją TenderPricingService
        $item->forceFill(['margin_percent_standard' => $twin])->saveQuietly();

        return $item;
    }

    /** Pozycja zapisana bez przeliczenia (stan sprzed marży bliźniaczej). */
    private function item(Tender $tender, Product $main, ?Product $companion, ?float $offer, ?float $margin): TenderItem
    {
        $item = new TenderItem([
            'tender_id' => $tender->id,
            'line_no' => TenderItem::query()->where('tender_id', $tender->id)->count() + 1,
            'requirement' => 'Rękawice',
            'main_product_id' => $main->id,
            'companion_product_id' => $companion?->id,
            'quantity' => 1,
            'offer_price' => $offer === null ? null : ($companion !== null ? $offer - OfferPricing::fromPurchase(211.37, 18) : $offer),
            'companion_offer_price' => $companion !== null && $offer !== null ? OfferPricing::fromPurchase(211.37, 18) : null,
            'margin_percent' => $margin,
            'status' => 'matched',
        ]);
        $item->saveQuietly();

        return $item;
    }
}
