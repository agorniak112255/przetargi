<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\CardRedirect;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\ProductVariantPriceHistory;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bGroupsSizes;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\Catalog\CardRedirectStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Rozmiary w różnych cenach = jedna karta (decyzja użytkownika 28.09.2026): łącznik podaje cenę każdej pozycji
 * (members[].price), karta ma cenę najtańszego rozmiaru, rozmiary są wierszami product_variants („size”). Najważniejsze
 * są stare dane: karty rozbite dawniej według ceny (stan tworzony tu starą drogą synchronizacji — grupy bez cen pozycji)
 * nie mogą stracić powiązań, cen ani opisów przy pierwszym i drugim przebiegu po zmianie (20.09.2026 drugi przebieg
 * UVEX na zastanych wierszach skasował 648 opisów).
 */
final class B2bSizePriceSyncTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTION = 'Kurtka robocza z membraną. Wodoszczelna, oddychająca, taśmy odblaskowe.';

    private User $user;

    private B2bAccount $account;

    private SizePriceFakeConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
        $this->account = $this->account('jan');
        $this->connector = new SizePriceFakeConnector;
    }

    public function test_priced_group_creates_one_card_with_size_rows_lowest_slot_price_and_member_link_prices(): void
    {
        $this->connector->items = [$this->jacket(['S' => 100.0, 'M' => 100.0, 'L' => 100.0, 'XL' => 120.0])];

        $result = $this->sync();

        $this->assertSame(1, $result['created'], implode(' | ', $result['errors']));
        $card = Product::query()->sole();
        $this->assertSame('K1', $card->sku);
        $this->assertSame('100.00', (string) $card->purchase_price);
        $this->assertSame(self::DESCRIPTION, $card->description);
        $slot = ProductSourcePrice::query()->sole();
        $this->assertSame('100.00', (string) $slot->purchase_price);
        $this->assertSame('100.00', (string) $slot->catalog_price_net);
        $this->assertSame('120.00', (string) $slot->size_price_max);

        $rows = $this->sizeRows($card);
        $this->assertSame(
            [
                ['K1-S', 'K1 S', 'S', '100.00', 'PLN', 'Na stanie', 0],
                ['K1-M', 'K1 M', 'M', '100.00', 'PLN', 'Na stanie', 1],
                ['K1-L', 'K1 L', 'L', '100.00', 'PLN', 'Na stanie', 2],
                ['K1-XL', 'K1 XL', 'XL', '120.00', 'PLN', 'Na zamówienie', 3],
            ],
            $rows->map(static fn (ProductVariant $v): array => [
                $v->remote_id, $v->sku, $v->label, (string) $v->purchase_price, $v->currency, $v->availability, $v->sort_order,
            ])->all(),
        );
        $this->assertSame([ProductSourcePrice::b2bKey((int) $this->account->id)], $rows->pluck('source')->unique()->values()->all());
        $this->assertNull($rows->first()->list_price_net);
        $this->assertSame(4, ProductVariantPriceHistory::query()->count());
        $this->assertSame(1, ProductPriceHistory::query()->count());

        $this->assertSame(
            ['K1-L' => '100.00', 'K1-M' => '100.00', 'K1-S' => '100.00', 'K1-XL' => '120.00'],
            B2bProductLink::query()->orderBy('remote_id')->pluck('last_purchase_price', 'remote_id')->map(static fn ($p): string => (string) $p)->all(),
        );
        // cena karty nie jest ceną 0 Sign Project — wiersze rozmiarów nie wyłączają ceny obowiązującej
        $this->assertSame([], $this->syncRun($result)->size_spread['groups']);
    }

    public function test_one_price_for_all_sizes_has_no_price_max_and_ties_pick_lower_catalog_price(): void
    {
        $this->connector->items = [$this->jacket(['S' => 100.0, 'M' => 100.0], bases: ['S' => 150.0, 'M' => 140.0])];

        $this->sync();

        $slot = ProductSourcePrice::query()->sole();
        $this->assertNull($slot->size_price_max);
        $this->assertSame('100.00', (string) $slot->purchase_price);
        // remis ceny konta — cena katalogowa z rozmiaru z niższą katalogową
        $this->assertSame('140.00', (string) $slot->catalog_price_net);
        $this->assertSame(['150.00', '140.00'], $this->sizeRows(Product::query()->sole())->map(static fn (ProductVariant $v): string => (string) $v->list_price_net)->all());
    }

    public function test_tie_prefers_the_size_with_a_known_catalog_price(): void
    {
        // Raw-Pol: rozmiar bez ceny katalogowej w tej samej cenie konta nie odbiera karcie ceny katalogowej innych
        $this->connector->items = [$this->jacket(['S' => 100.0, 'M' => 100.0], bases: ['M' => 150.0])];

        $this->sync();

        $slot = ProductSourcePrice::query()->sole();
        $this->assertSame(['100.00', '150.00'], [(string) $slot->purchase_price, (string) $slot->catalog_price_net]);
    }

    public function test_second_identical_run_is_unchanged_without_history_and_keeps_description(): void
    {
        $this->connector->items = [$this->jacket(['S' => 100.0, 'M' => 100.0, 'XL' => 120.0])];
        $this->sync();
        $card = Product::query()->sole();
        $before = $this->snapshot();
        $history = ProductVariantPriceHistory::query()->count();
        $this->travel(5)->minutes();

        $second = $this->sync();

        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(0, $second['prices_changed']);
        $this->assertSame($history, ProductVariantPriceHistory::query()->count());
        $this->assertSame(1, ProductPriceHistory::query()->count());
        $this->assertSame($before, $this->snapshot());
        // widoczność odświeżona jednym UPDATE-em (bez updated_at)
        $this->assertTrue($this->sizeRows($card)->every(static fn (ProductVariant $v): bool => $v->last_seen_at->greaterThan($v->updated_at)));
        $this->assertSame(self::DESCRIPTION, $card->fresh()->description);
    }

    public function test_price_change_of_one_size_writes_history_and_reports_sizes(): void
    {
        $this->connector->items = [$this->jacket(['S' => 100.0, 'M' => 100.0, 'XL' => 120.0])];
        $this->sync();
        $card = Product::query()->sole();

        $this->connector->items = [$this->jacket(['S' => 100.0, 'M' => 100.0, 'XL' => 125.0])];
        $result = $this->sync();

        $this->assertSame(1, $result['updated'], implode(' | ', $result['errors']));
        $this->assertSame(1, $result['prices_changed']);
        $xl = ProductVariant::query()->where('remote_id', 'K1-XL')->sole();
        $this->assertSame('125.00', (string) $xl->purchase_price);
        $this->assertSame(['120.00', '125.00'], ProductVariantPriceHistory::query()->where('product_variant_id', $xl->id)->orderBy('id')->pluck('purchase_price')->map(static fn ($p): string => (string) $p)->all());
        $this->assertSame(3 + 1, ProductVariantPriceHistory::query()->count());
        // cena karty (najtańszy rozmiar) bez zmian — bez wpisu historii ceny karty
        $this->assertSame(1, ProductPriceHistory::query()->count());
        $this->assertSame('125.00', (string) ProductSourcePrice::query()->sole()->size_price_max);
        $this->assertSame('100.00', (string) $card->fresh()->purchase_price);

        $change = $this->syncRun($result)->price_changes[0];
        $this->assertSame(['XL', 120, 125, 'up'], [$change['variant_label'], (int) $change['purchase_old'], (int) $change['purchase_new'], $change['direction']]);
        $updated = collect(PriceList::query()->sole()->updated_products)->firstWhere('sku', 'K1');
        $this->assertContains('rozmiary', $updated['fields']);
        $this->assertTrue($updated['price_changed']);
        $this->assertSame('125.00', (string) B2bProductLink::query()->where('remote_id', 'K1-XL')->value('last_purchase_price'));
    }

    public function test_cheapest_size_price_change_moves_the_card_price(): void
    {
        $this->connector->items = [$this->jacket(['S' => 100.0, 'XL' => 120.0])];
        $this->sync();

        $this->connector->items = [$this->jacket(['S' => 90.0, 'XL' => 120.0])];
        $result = $this->sync();

        $this->assertSame(1, $result['updated']);
        $card = Product::query()->sole();
        $this->assertSame('90.00', (string) $card->purchase_price);
        $this->assertSame(2, ProductPriceHistory::query()->count());
        // zmiana ceny karty i zmiana ceny rozmiaru S
        $this->assertSame(2, $result['prices_changed']);
    }

    public function test_legacy_price_split_cards_keep_links_prices_and_descriptions_over_two_runs(): void
    {
        [$small, $large] = $this->legacySplitCards();
        $before = $this->legacyState($small, $large);
        $priceHistory = ProductPriceHistory::query()->count();

        $this->connector->items = [$this->jacket(['S' => 100.0, 'M' => 100.0, 'L' => 100.0, 'XL' => 120.0])];
        $first = $this->sync();

        $this->assertSame(0, $first['created'], implode(' | ', $first['errors']));
        $this->assertSame(0, $first['skipped'], implode(' | ', $first['errors']));
        $this->assertSame(0, $first['prices_changed']);
        // karty dostały tabelę rozmiarów — jeden wyrób łącznika, jeden wynik „zaktualizowany”
        $this->assertSame(1, $first['updated']);
        $this->assertSame(2, Product::query()->count());
        $this->assertFalse(Product::query()->where('sku', 'K1')->exists());
        $this->assertSame($before, $this->legacyState($small, $large));
        $this->assertSame($priceHistory, ProductPriceHistory::query()->count());
        $this->assertSame(['K1-S', 'K1-M', 'K1-L'], $this->sizeRows($small)->pluck('remote_id')->all());
        $this->assertSame(['100.00', '100.00', '100.00'], $this->sizeRows($small)->map(static fn (ProductVariant $v): string => (string) $v->purchase_price)->all());
        $this->assertSame(['K1-XL'], $this->sizeRows($large)->pluck('remote_id')->all());
        $this->assertSame('120.00', (string) $this->sizeRows($large)->first()->purchase_price);
        $this->assertNull(ProductSourcePrice::query()->where('product_id', $small->id)->value('size_price_max'));
        $this->assertNull(ProductSourcePrice::query()->where('product_id', $large->id)->value('size_price_max'));
        $logs = implode("\n", $this->logTexts($first));
        $this->assertStringNotContainsString('nie ma już kodów', $logs);
        $this->assertStringNotContainsString('inna cena', $logs);
        $this->assertStringContainsString('Wyroby z rozmiarami na kilku kartach', $logs);

        $spread = $this->syncRun($first)->size_spread;
        $this->assertSame(1, $spread['total']);
        $this->assertFalse($spread['truncated']);
        $group = $spread['groups'][0];
        $this->assertSame(['K1', 'K1-S'], [$group['sku'], $group['remote_id']]);
        $this->assertSame([$small->id, $large->id], $group['cards']);
        $this->assertSame(
            [['K1-S', 'S', $small->id, 100], ['K1-M', 'M', $small->id, 100], ['K1-L', 'L', $small->id, 100], ['K1-XL', 'XL', $large->id, 120]],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['size'], $m['card_id'], (int) $m['net']], $group['members']),
        );
        // identyfikatory poziomu karty na obu kartach, EAN rozmiaru przy jego karcie
        $this->assertSame(['K1-S', 'K1-M', 'K1-L'], ProductIdentifier::query()->where('product_id', $small->id)->where('type', ProductIdentifier::TYPE_EAN)->orderBy('id')->pluck('position_key')->all());
        $this->assertSame(['K1-XL'], ProductIdentifier::query()->where('product_id', $large->id)->where('type', ProductIdentifier::TYPE_EAN)->pluck('position_key')->all());

        $history = ProductVariantPriceHistory::query()->count();
        $this->travel(5)->minutes();
        $second = $this->sync();

        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        // jeden wyrób łącznika = jeden wynik przebiegu (obie karty bez zmian)
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(0, $second['prices_changed']);
        $this->assertSame($before, $this->legacyState($small, $large));
        $this->assertSame($history, ProductVariantPriceHistory::query()->count());
        $this->assertSame(0, ProductVariant::query()->whereNotNull('removed_at')->count());
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());
        $this->assertSame(1, $this->syncRun($second)->size_spread['total']);
    }

    public function test_legacy_split_new_size_joins_the_card_of_the_lead_position(): void
    {
        [$small, $large] = $this->legacySplitCards();

        $this->connector->items = [$this->jacket(['S' => 100.0, 'M' => 100.0, 'L' => 100.0, 'XL' => 120.0, 'XXL' => 120.0])];
        $result = $this->sync();

        $this->assertSame(0, $result['created'], implode(' | ', $result['errors']));
        $this->assertSame($small->id, B2bProductLink::query()->where('remote_id', 'K1-XXL')->value('product_id'));
        $this->assertSame(['K1-S', 'K1-M', 'K1-L', 'K1-XXL'], $this->sizeRows($small)->pluck('remote_id')->all());
        // karta S–L ma teraz rozmiar droższy — najwyższa cena rozmiaru przy jej slocie, cena karty bez zmian
        $this->assertSame('100.00', (string) ProductSourcePrice::query()->where('product_id', $small->id)->value('purchase_price'));
        $this->assertSame('120.00', (string) ProductSourcePrice::query()->where('product_id', $small->id)->value('size_price_max'));
        $this->assertSame(['K1-XL'], $this->sizeRows($large)->pluck('remote_id')->all());
    }

    public function test_dry_run_writes_nothing(): void
    {
        [$small, $large] = $this->legacySplitCards();
        $before = $this->legacyState($small, $large);
        $this->connector->items = [
            $this->jacket(['S' => 100.0, 'M' => 100.0, 'L' => 100.0, 'XL' => 120.0]),
            $this->jacket(['S' => 50.0, 'XL' => 60.0], code: 'K2'),
        ];

        $result = $this->sync(dryRun: true);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, ProductVariant::query()->count());
        $this->assertSame(0, ProductVariantPriceHistory::query()->count());
        $this->assertSame(2, Product::query()->count());
        $this->assertSame($before, $this->legacyState($small, $large));
        $this->assertSame(1, $result['size_spread_total']);
    }

    public function test_two_accounts_with_the_same_ean_keep_separate_size_rows(): void
    {
        $this->connector->items = [$this->jacket(['S' => 100.0, 'XL' => 120.0])];
        $this->sync();
        $other = $this->account('ola');
        $this->connector->items = [$this->jacket(['S' => 95.0, 'XL' => 130.0])];
        $this->sync(account: $other);

        $card = Product::query()->sole();
        $rows = ProductVariant::query()->where('product_id', $card->id)->orderBy('source')->orderBy('sort_order')->get();
        $this->assertSame(
            [
                [ProductSourcePrice::b2bKey((int) $this->account->id), 'K1-S', '100.00'],
                [ProductSourcePrice::b2bKey((int) $this->account->id), 'K1-XL', '120.00'],
                [ProductSourcePrice::b2bKey((int) $other->id), 'K1-S', '95.00'],
                [ProductSourcePrice::b2bKey((int) $other->id), 'K1-XL', '130.00'],
            ],
            $rows->map(static fn (ProductVariant $v): array => [$v->source, $v->remote_id, (string) $v->purchase_price])->all(),
        );
        $this->assertSame('130.00', (string) ProductSourcePrice::query()->where('source_key', ProductSourcePrice::b2bKey((int) $other->id))->value('size_price_max'));
    }

    public function test_size_gone_from_the_list_is_marked_removed_only_by_a_full_run(): void
    {
        $this->connector->items = [$this->jacket(['S' => 100.0, 'M' => 100.0, 'XL' => 120.0])];
        $this->sync();
        $card = Product::query()->sole();
        $this->travel(5)->minutes();

        $this->connector->items = [$this->jacket(['S' => 100.0, 'M' => 100.0])];
        $limited = $this->sync(limit: 5);
        $this->assertSame(0, ProductVariant::query()->whereNotNull('removed_at')->count());
        $this->assertSame(0, $limited['sizes_removed']);

        $this->travel(5)->minutes();
        $full = $this->sync();

        $this->assertSame(1, $full['sizes_removed']);
        $this->assertNotNull(ProductVariant::query()->where('remote_id', 'K1-XL')->value('removed_at'));
        $this->assertSame(2, $this->sizeRows($card)->whereNull('removed_at')->count());
        $this->assertNull(ProductSourcePrice::query()->sole()->size_price_max);
        $this->assertSame(3, ProductVariant::query()->count());

        // rozmiar wraca — ten sam wiersz, bez removed_at, „rozmiary” w podsumowaniu
        $this->travel(5)->minutes();
        $this->connector->items = [$this->jacket(['S' => 100.0, 'M' => 100.0, 'XL' => 120.0])];
        $back = $this->sync();
        $this->assertSame(1, $back['updated']);
        $this->assertNull(ProductVariant::query()->where('remote_id', 'K1-XL')->value('removed_at'));
        $this->assertSame(3, ProductVariant::query()->count());
    }

    public function test_skipped_article_keeps_its_size_rows_in_a_full_run(): void
    {
        $this->connector->items = [
            $this->jacket(['S' => 100.0, 'XL' => 120.0]),
            $this->jacket(['S' => 50.0, 'XL' => 60.0], code: 'K2'),
        ];
        $this->sync();
        $this->travel(5)->minutes();

        // K2 — błąd ceny u dostawcy (pozycja pominięta); K1 — bez zmian
        $this->connector->priceErrors = ['K2-S'];
        $result = $this->sync();

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['sizes_removed']);
        $this->assertSame(0, ProductVariant::query()->whereNotNull('removed_at')->count());
    }

    public function test_mixed_priced_and_unpriced_members_or_two_currencies_skip_the_group(): void
    {
        $mixed = $this->jacket(['S' => 100.0, 'XL' => null]);
        $currencies = $this->jacket(['S' => 100.0, 'XL' => 120.0], code: 'K2', currencies: ['XL' => 'EUR']);
        $this->connector->items = [$mixed, $currencies];

        $result = $this->sync();

        $this->assertSame(2, $result['skipped']);
        $this->assertSame(0, Product::query()->count());
        $this->assertStringContainsString('ceny tylko części rozmiarów (bez ceny: K1 XL)', $result['errors'][0]);
        $this->assertStringContainsString('różnych walutach', $result['errors'][1]);
    }

    public function test_split_redirect_positions_get_their_own_size_price(): void
    {
        $this->connector->items = [$this->jacket(['S' => 100.0, 'XL' => 120.0])];
        $this->sync();
        $card = Product::query()->sole();
        $xlCard = Product::query()->create([
            'sku' => 'K1-XL-KARTA', 'name' => 'Kurtka K1 XL', 'manufacturer' => 'TESTBRAND',
            'catalog_price_net' => 0, 'discount_percent' => 0, 'purchase_price' => 0, 'currency' => 'PLN',
        ]);
        $this->redirect('K1-S', $card, CardRedirect::REASON_SPLIT);
        $this->redirect('K1-XL', $xlCard, CardRedirect::REASON_SPLIT);

        $result = $this->sync();

        $this->assertSame(0, $result['skipped'], implode(' | ', $result['errors']));
        $this->assertSame('120.00', (string) ProductSourcePrice::query()->where('product_id', $xlCard->id)->value('purchase_price'));
        $this->assertSame('100.00', (string) ProductSourcePrice::query()->where('product_id', $card->id)->value('purchase_price'));
        $this->assertNull(ProductSourcePrice::query()->where('product_id', $card->id)->value('size_price_max'));
        $this->assertSame('120.00', (string) B2bProductLink::query()->where('remote_id', 'K1-XL')->value('last_purchase_price'));
        // wiersz rozmiaru idzie za pozycją na jej kartę — karta grupy nie pokazuje rozmiaru, którego już nie ma
        $this->assertSame(['K1-S'], $this->sizeRows($card)->pluck('remote_id')->all());
        $this->assertSame(['K1-XL'], $this->sizeRows($xlCard)->pluck('remote_id')->all());
        $this->assertSame(2, ProductVariant::query()->count());

        $this->travel(5)->minutes();
        $second = $this->sync();
        $this->assertSame(1, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['sizes_removed']);
        $this->assertSame(0, ProductVariant::query()->whereNotNull('removed_at')->count());
    }

    public function test_second_group_on_a_card_used_in_the_run_adds_its_sizes_and_warns_only_when_cheaper(): void
    {
        $this->connector->items = [$this->jacket(['S' => 100.0, 'XL' => 120.0])];
        $this->sync();
        $card = Product::query()->sole();

        // dostawca podaje ten sam wyrób dwiema grupami, obie połączone mapą z jedną kartą; druga grupa — tylko
        // powiązania i jej wiersze rozmiarów (slot zapisuje pierwsza)
        $second = $this->jacket(['XXL' => 130.0], code: 'K1');
        $cheaper = $this->jacket(['XS' => 80.0], code: 'K1');
        $this->connector->items = [$this->jacket(['S' => 100.0, 'XL' => 120.0]), $second, $cheaper];
        foreach (['K1-S', 'K1-XL', 'K1-XXL', 'K1-XS'] as $position) {
            $this->redirect($position, $card, CardRedirect::REASON_MERGE);
        }

        $result = $this->sync();

        $this->assertSame(0, $result['skipped'], implode(' | ', $result['errors']));
        $this->assertSame(['K1-S', 'K1-XL', 'K1-XXL', 'K1-XS'], $this->sizeRows($card)->sortBy('id')->pluck('remote_id')->values()->all());
        $this->assertSame('100.00', (string) ProductSourcePrice::query()->sole()->purchase_price);
        $this->assertSame('130.00', (string) B2bProductLink::query()->where('remote_id', 'K1-XXL')->value('last_purchase_price'));
        $warnings = array_values(array_filter($this->logTexts($result), static fn (string $t): bool => str_contains($t, 'niższą niż cena karty')));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('rozmiar XS ma cenę 80,00 PLN', $warnings[0]);
        $this->assertEmpty(array_filter($this->logTexts($result), static fn (string $t): bool => str_contains($t, 'ma już cenę z innego kodu')));
    }

    // ---- pomocnicze ----

    /**
     * Stan sprzed 28.09.2026 zapisany starą drogą synchronizacji: łącznik dzielił wyrób na grupy według ceny (bez cen
     * pozycji) — karta „K1 S” (S, M, L po 100) i „K1 XL” (XL po 120), każda z opisem, slotem i historią.
     *
     * @return array{0: Product, 1: Product}
     */
    private function legacySplitCards(): array
    {
        $this->connector->items = [
            $this->legacyGroup(['S', 'M', 'L'], 100.0),
            $this->legacyGroup(['XL'], 120.0),
        ];
        $result = $this->sync();
        $this->assertSame(2, $result['created'], implode(' | ', $result['errors']));
        $small = Product::query()->where('sku', 'K1 S')->sole();
        $large = Product::query()->where('sku', 'K1 XL')->sole();
        $this->assertSame(self::DESCRIPTION, $small->description);
        $this->assertSame(self::DESCRIPTION, $large->description);
        $this->assertSame(0, ProductVariant::query()->count());
        $this->travel(5)->minutes();

        return [$small, $large];
    }

    /**
     * @param  list<string>  $sizes
     */
    private function legacyGroup(array $sizes, float $price): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: 'K1-'.$sizes[0],
            sku: 'K1 '.$sizes[0],
            name: 'Kurtka K1 (rozm. '.implode(', ', $sizes).')',
            raw: ['price' => $price, 'description' => self::DESCRIPTION],
            // dostępność grupy jak u łącznika po zmianie (XL „Na zamówienie”, reszta „Na stanie”)
            availability: $sizes === ['XL'] ? 'Na zamówienie' : 'Na stanie',
            variantSummary: 'Rozmiary: '.implode('; ', $sizes),
            members: array_map(static fn (string $size): array => [
                'remote_id' => 'K1-'.$size, 'sku' => 'K1 '.$size, 'name' => 'Kurtka K1 '.$size,
            ], $sizes),
            identifiers: [
                new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MANUFACTURER_CODE, value: 'K1', field: 'Kod'),
                ...array_map(static fn (string $size): B2bRemoteIdentifier => new B2bRemoteIdentifier(
                    type: ProductIdentifier::TYPE_EAN,
                    value: self::ean($size),
                    remoteId: 'K1-'.$size,
                    label: $size,
                    field: 'EAN',
                ), $sizes),
            ],
        );
    }

    /**
     * Wyrób po zmianie: jedna grupa z ceną każdego rozmiaru (null = rozmiar bez ceny — kontrakt złamany).
     *
     * @param  array<string, float|null>  $prices  rozmiar => cena konta
     * @param  array<string, float>  $bases  rozmiar => cena katalogowa
     * @param  array<string, string>  $currencies  rozmiar => waluta (domyślnie PLN)
     */
    private function jacket(array $prices, array $bases = [], string $code = 'K1', array $currencies = []): B2bRemoteProduct
    {
        $sizes = array_keys($prices);
        $members = [];
        foreach ($prices as $size => $net) {
            $members[] = [
                'remote_id' => $code.'-'.$size,
                'sku' => $code.' '.$size,
                'name' => 'Kurtka '.$code.' '.$size,
                'availability' => $size === 'XL' ? 'Na zamówienie' : 'Na stanie',
                'size' => (string) $size,
                ...($net !== null ? ['price' => new B2bRemotePrice(net: $net, base: $bases[$size] ?? null, currency: $currencies[$size] ?? 'PLN')] : []),
            ];
        }
        $priced = array_filter($prices, static fn (?float $p): bool => $p !== null);

        return new B2bRemoteProduct(
            remoteId: $code.'-'.$sizes[0],
            sku: $code,
            name: 'Kurtka '.$code,
            raw: ['price' => $priced !== [] ? min($priced) : null, 'description' => self::DESCRIPTION],
            availability: 'Na stanie',
            variantSummary: 'Rozmiary: '.implode('; ', $sizes),
            members: $members,
            identifiers: [
                new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MANUFACTURER_CODE, value: $code, field: 'Kod'),
                ...array_map(static fn (string $size): B2bRemoteIdentifier => new B2bRemoteIdentifier(
                    type: ProductIdentifier::TYPE_EAN,
                    value: self::ean($size, $code),
                    remoteId: $code.'-'.$size,
                    label: $size,
                    field: 'EAN',
                ), array_map('strval', $sizes)),
            ],
        );
    }

    private static function ean(string $size, string $code = 'K1'): string
    {
        return '590'.str_pad((string) (crc32($code.'-'.$size) % 10_000_000_000), 10, '0', STR_PAD_LEFT);
    }

    /**
     * Wszystko, czego przebieg po zmianie nie może ruszyć na starych kartach rozbitych według ceny.
     *
     * @return array<string, mixed>
     */
    private function legacyState(Product $small, Product $large): array
    {
        $state = [];
        foreach ([$small, $large] as $card) {
            $card = $card->fresh();
            $state[$card->id] = [
                'sku' => $card->sku,
                'name' => $card->name,
                'description' => $card->description,
                'variant_summary' => $card->variant_summary,
                'price' => [(string) $card->purchase_price, (string) $card->catalog_price_net, $card->currency],
                'slot' => ProductSourcePrice::query()->where('product_id', $card->id)
                    ->get(['source_key', 'purchase_price', 'catalog_price_net', 'size_price_max', 'availability'])->toArray(),
                'links' => B2bProductLink::query()->where('product_id', $card->id)->orderBy('remote_id')
                    ->get(['remote_id', 'remote_sku', 'description_hash', 'last_purchase_price', 'merged_at'])->toArray(),
            ];
        }

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return Product::query()->orderBy('sku')->get()->mapWithKeys(fn (Product $p): array => [$p->sku => [
            'card' => [...$p->only(['name', 'description', 'variant_summary', 'purchase_price', 'catalog_price_net']), 'updated_at' => (string) $p->updated_at],
            'slot' => ProductSourcePrice::query()->where('product_id', $p->id)->get(['purchase_price', 'catalog_price_net', 'size_price_max', 'availability'])->toArray(),
            'sizes' => ProductVariant::query()->where('product_id', $p->id)->orderBy('id')
                ->get(['remote_id', 'sku', 'label', 'purchase_price', 'list_price_net', 'availability', 'removed_at', 'updated_at'])->toArray(),
            'links' => B2bProductLink::query()->where('product_id', $p->id)->orderBy('remote_id')->get(['remote_id', 'last_purchase_price', 'description_hash'])->toArray(),
        ]])->all();
    }

    /**
     * @return Collection<int, ProductVariant>
     */
    private function sizeRows(Product $card)
    {
        return ProductVariant::query()
            ->where('product_id', $card->id)
            ->where('kind', ProductVariant::KIND_SIZE)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function redirect(string $position, Product $target, string $reason): void
    {
        CardRedirect::query()->updateOrCreate(
            ['source_key' => ProductSourcePrice::b2bKey((int) $this->account->id), 'position_key' => $position],
            [
                'b2b_account_id' => $this->account->id,
                'product_id' => $target->id,
                'reason' => $reason,
                'target_snapshot' => CardRedirectStore::snapshot($target),
                'created_by' => $this->user->id,
            ],
        );
    }

    private function account(string $username): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $username,
            'password' => 'sekret',
            'sites' => [SizePriceFakeConnector::host()],
            'connector' => SizePriceFakeConnector::key(),
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function syncRun(array $result): B2bSyncRun
    {
        return B2bSyncRun::query()->findOrFail($result['sync_run_id']);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function logTexts(array $result): array
    {
        return array_column($this->syncRun($result)->log, 'text');
    }

    /**
     * @return array<string, mixed>
     */
    private function sync(bool $dryRun = false, ?int $limit = null, ?B2bAccount $account = null): array
    {
        return app(B2bAccountSyncRunner::class)->run(
            ($account ?? $this->account)->fresh(),
            limit: $limit,
            dryRun: $dryRun,
            delayMs: 0,
            connector: $this->connector,
        );
    }
}

/**
 * Łącznik testowy witryny producenta (opis producenta zastępuje opis karty — najbardziej ryzykowna droga opisu) bez
 * sieci. Cena grupy z raw['price'] (najtańszy rozmiar, jak Mascot); błąd ceny dla remoteId z priceErrors.
 */
final class SizePriceFakeConnector implements B2bConnector, B2bGroupsSizes, B2bManufacturerSite
{
    /** @var list<B2bRemoteProduct> */
    public array $items = [];

    /** @var list<string> */
    public array $priceErrors = [];

    public static function key(): string
    {
        return 'sizeprice';
    }

    public static function label(): string
    {
        return 'Rozmiary w cenach — test';
    }

    public static function host(): string
    {
        return 'sizeprice.example.test';
    }

    public static function ownBrand(): string
    {
        return 'TESTBRAND';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        yield from $this->items;
    }

    public function totalProducts(): int
    {
        return count($this->items);
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'TESTBRAND';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (in_array($product->remoteId, $this->priceErrors, true)) {
            throw new RuntimeException('cena niedostępna');
        }
        $net = $product->raw['price'] ?? null;

        return $net !== null ? new B2bRemotePrice(net: (float) $net) : null;
    }

    public function description(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['description'] ?? '');
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }
}
