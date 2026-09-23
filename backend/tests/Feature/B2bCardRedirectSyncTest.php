<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\CardRedirect;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\Catalog\CardRedirectStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Synchronizacja B2B czyta mapę połączeń (card_redirects — plan łączenia kart, krok 3, 24.09.2026). Testy odtwarzają
 * zastane wiersze z produkcji: karta dystrybutora z pierwszego przebiegu (powiązania wszystkich rozmiarów na niej),
 * potem decyzja człowieka w mapie i DWA kolejne przebiegi — drugi przebieg na zastanych wierszach to miejsce, w którym
 * synchronizacja kasowała dane (20.09.2026 UVEX: 648 opisów).
 * Przypadek wzorcowy: P4S „6X00 Półmaska 3M 6000” (#56362) — jedna grupa rozmiarów 6100 S / 6200 M / 6300 L, a 3M ma
 * kartę na każdy rozmiar (#40819, #40815, #40814).
 */
final class B2bCardRedirectSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private B2bAccount $mmm;

    private B2bAccount $p4s;

    private RedirectFakeConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
        $this->mmm = $this->account('mmm', '3m', 'b2b.3m.example.test');
        $this->p4s = $this->account('p4s', 'p4s', 'b2b.p4s.example.test');
        $this->connector = new RedirectFakeConnector;
    }

    public function test_split_group_stays_split_over_two_runs_and_new_size_gets_own_card(): void
    {
        [$small, $medium, $large] = $this->producerCards();
        $distributor = $this->firstP4sRun();
        $this->redirect('1001', $small, CardRedirect::REASON_SPLIT);
        $this->redirect('1002', $medium, CardRedirect::REASON_SPLIT);
        $this->redirect('1003', $large, CardRedirect::REASON_SPLIT);
        $before = $this->cardFields([$small, $medium, $large]);

        $this->connector->items = [$this->halfMask(['S', 'M', 'L'], withIdentifiers: true)];
        $first = $this->sync();

        $this->assertSame(0, $first['created']);
        $this->assertSame(0, $first['skipped']);
        $this->assertSame([], $first['errors']);
        $this->assertSame(
            ['1001' => $small->id, '1002' => $medium->id, '1003' => $large->id],
            $this->links(),
        );
        $log = $this->logTexts($first);
        $this->assertContains(
            '6X00: pozycja 6100: powiązanie wskazywało kartę #'.$distributor->id.' — wraca na kartę #'.$small->id.' (3M-6100) z mapy połączeń',
            $log,
        );

        // drugi przebieg na tych samych wierszach: bez scalenia, bez ostrzeżeń o powrocie, bez nowych kart
        $second = $this->sync();
        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['skipped']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(
            ['1001' => $small->id, '1002' => $medium->id, '1003' => $large->id],
            $this->links(),
        );
        $this->assertEmpty(array_filter($this->logTexts($second), static fn (string $t): bool => str_contains($t, 'wraca na kartę')));

        // karty producenta: nazwa, producent i lista rozmiarów nietknięte; slot P4S z ceną grupy i dostępnością rozmiaru
        $this->assertSame($before, $this->cardFields([$small, $medium, $large]));
        $expected = [$small->id => 'Produkt dostępny', $medium->id => 'Produkt niedostępny', $large->id => 'Produkt dostępny'];
        foreach ($expected as $cardId => $availability) {
            $slot = $this->p4sSlot($cardId);
            $this->assertSame('31.20', $slot->purchase_price);
            $this->assertSame($availability, $slot->availability);
        }
        // karta dystrybutora zostaje (bez kodów konta — decyzja człowieka), jej slot nietknięty
        $this->assertNotNull($distributor->fresh());
        $this->assertSame('31.20', $this->p4sSlot($distributor->id)->purchase_price);
        // cena przy każdej pozycji
        foreach (B2bProductLink::query()->where('b2b_account_id', $this->p4s->id)->get() as $link) {
            $this->assertSame('31.20', $link->last_purchase_price);
            $this->assertSame('PLN', $link->last_currency);
            $this->assertNotNull($link->last_price_at);
        }
        // identyfikatory pozycji idą za pozycją; identyfikator poziomu karty (kod modelu grupy) nie trafia na kartę rozmiaru
        $this->assertSame(
            ['1001' => $small->id, '1002' => $medium->id, '1003' => $large->id],
            ProductIdentifier::query()->where('type', ProductIdentifier::TYPE_MANUFACTURER_CODE)
                ->where('source_key', ProductSourcePrice::b2bKey($this->p4s->id))
                ->orderBy('position_key')->pluck('product_id', 'position_key')->all(),
        );
        $this->assertFalse(ProductIdentifier::query()->where('type', ProductIdentifier::TYPE_MODEL_CODE)->exists());

        // nowy rozmiar XL bez wpisu mapy i bez powiązania — własna nowa karta (później propozycja), nie karta innego rozmiaru
        $this->connector->items = [$this->halfMask(['S', 'M', 'L', 'XL'])];
        $third = $this->sync();
        $this->assertSame(1, $third['created']);
        $xl = Product::query()->where('sku', '6400')->sole();
        $this->assertSame($xl->id, $this->links()['1004']);
        $this->assertSame('Półmaska 3M 6000, rozmiar XL', $xl->name);
        $this->assertNull($xl->variant_summary);
        $this->assertSame('31.20', $this->p4sSlot($xl->id)->purchase_price);
        $this->assertSame(
            ['1001' => $small->id, '1002' => $medium->id, '1003' => $large->id, '1004' => $xl->id],
            $this->links(),
        );
        $this->assertSame($before, $this->cardFields([$small, $medium, $large]));
    }

    public function test_price_group_change_with_split_entries_keeps_each_size_on_its_card(): void
    {
        [$small, $medium, $large] = $this->producerCards();
        $this->firstP4sRun();
        $this->redirect('1001', $small, CardRedirect::REASON_SPLIT);
        $this->redirect('1002', $medium, CardRedirect::REASON_SPLIT);
        $this->redirect('1003', $large, CardRedirect::REASON_SPLIT);

        // P4S dzieli wyrób według ceny: {S, M} po 31,20 i {L} po 35,00 (L prowadzi swoją grupę)
        $this->connector->items = [$this->halfMask(['S', 'M']), $this->halfMask(['L'])];
        $this->connector->prices = ['1001' => 31.20, '1003' => 35.00];
        foreach ([1, 2] as $run) {
            $result = $this->sync();
            $this->assertSame(0, $result['created'], 'przebieg '.$run);
            $this->assertSame(0, $result['skipped'], 'przebieg '.$run);
            $this->assertSame(
                ['1001' => $small->id, '1002' => $medium->id, '1003' => $large->id],
                $this->links(),
            );
            $this->assertSame('31.20', $this->p4sSlot($small->id)->purchase_price);
            $this->assertSame('31.20', $this->p4sSlot($medium->id)->purchase_price);
            $this->assertSame('35.00', $this->p4sSlot($large->id)->purchase_price);
            $this->assertSame('35.00', B2bProductLink::query()->where('remote_id', '1003')->value('last_purchase_price'));
        }

        // i z powrotem: jedna grupa {S, M, L} — dalej każdy rozmiar na swojej karcie
        $this->connector->items = [$this->halfMask(['S', 'M', 'L'])];
        $this->connector->prices = ['1001' => 31.20];
        $this->sync();
        $this->assertSame(
            ['1001' => $small->id, '1002' => $medium->id, '1003' => $large->id],
            $this->links(),
        );
        $this->assertSame('31.20', $this->p4sSlot($large->id)->purchase_price);
    }

    public function test_merge_entries_make_target_the_group_card_and_new_size_joins_it(): void
    {
        // karta dystrybutora z pierwszego przebiegu i karta docelowa bez właściciela (przypadek MAVIBO)
        $this->connector->manufacturer = 'Mavibo';
        $this->connector->items = [$this->group('M1', ['S', 'M'], 'S; M')];
        $this->connector->prices = ['M1-S' => 12.00];
        $this->sync();
        $old = Product::query()->where('sku', 'M1-S')->sole();
        $target = Product::query()->create([
            'sku' => 'MAV-100',
            'name' => 'Koszulka Mavibo 100',
            'manufacturer' => 'Mavibo',
            'variant_summary' => 'S',
            'catalog_price_net' => 0,
            'purchase_price' => 0,
            'currency' => 'PLN',
        ]);
        $this->redirect('M1-S', $target, CardRedirect::REASON_MERGE);
        $this->redirect('M1-M', $target, CardRedirect::REASON_MERGE);

        // nowy rozmiar L bez wpisu — dołącza do karty z mapy razem z grupą
        $this->connector->items = [$this->group('M1', ['S', 'M', 'L'], 'S; M; L')];
        $first = $this->sync();
        $this->assertSame(0, $first['created']);
        $this->assertSame(1, $first['updated']);
        $this->assertSame(['M1-L' => $target->id, 'M1-M' => $target->id, 'M1-S' => $target->id], $this->links());
        $this->assertSame('S; M; L', $target->fresh()->variant_summary);
        $this->assertSame('12.00', $this->p4sSlot($target->id)->purchase_price);
        $log = $this->logTexts($first);
        $this->assertContains('M1-S: pozycja M1-S: powiązanie wskazywało kartę #'.$old->id.' — wraca na kartę #'.$target->id.' (MAV-100) z mapy połączeń', $log);
        $this->assertContains('M1-S: karta #'.$old->id.' (M1-S) nie ma już kodów w B2B tego konta — jej cena z konta zostaje do decyzji', $log);
        $this->assertSame('Koszulka Mavibo 100', $target->fresh()->name);

        $second = $this->sync();
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(['M1-L' => $target->id, 'M1-M' => $target->id, 'M1-S' => $target->id], $this->links());
        $this->assertEmpty(array_filter($this->logTexts($second), static fn (string $t): bool => str_contains($t, 'wraca na kartę')));
        foreach (B2bProductLink::query()->get() as $link) {
            $this->assertSame('12.00', $link->last_purchase_price);
        }

        // grupy cenowe rozjechane po połączeniu: druga grupa trafia w kartę użytą już w przebiegu — tylko powiązania
        // i ostrzeżenie o innej cenie; cena pozycji zostaje przy jej powiązaniu
        $this->redirect('M1-L', $target, CardRedirect::REASON_MERGE);
        $this->connector->items = [$this->group('M1', ['S', 'M'], 'S; M'), $this->group('M1', ['L'], 'L')];
        $this->connector->prices = ['M1-S' => 12.00, 'M1-L' => 14.00];
        $third = $this->sync();
        $this->assertSame(0, $third['created']);
        $this->assertSame(0, $third['skipped']);
        $this->assertSame(['M1-L' => $target->id, 'M1-M' => $target->id, 'M1-S' => $target->id], $this->links());
        $this->assertSame('12.00', $this->p4sSlot($target->id)->purchase_price);
        $this->assertSame('14.00', B2bProductLink::query()->where('remote_id', 'M1-L')->value('last_purchase_price'));
        $this->assertNotEmpty(array_filter(
            $this->logTexts($third),
            static fn (string $t): bool => str_starts_with($t, 'M1-L: karta #'.$target->id) && str_contains($t, 'ma już cenę z innego kodu tego konta'),
        ));
    }

    public function test_single_position_goes_to_map_card_even_when_link_points_elsewhere(): void
    {
        $this->connector->items = [$this->single('P1', 'Kask Uvex Pheos')];
        $this->connector->prices = ['P1' => 40.00];
        $this->sync();
        $own = Product::query()->where('sku', 'P1')->sole();
        $target = $this->producerCard('3M-P1', 'Kask 3M SecureFit', 'uniwersalny');
        $this->redirect('P1', $target, CardRedirect::REASON_MERGE);

        $first = $this->sync();
        $this->assertSame(['P1' => $target->id], $this->links());
        $this->assertContains('P1: powiązanie wskazywało kartę #'.$own->id.' — wraca na kartę #'.$target->id.' (3M-P1) z mapy połączeń', $this->logTexts($first));
        $this->assertSame('40.00', $this->p4sSlot($target->id)->purchase_price);
        // karta producenta chroniona — nazwa i producent zostają
        $this->assertSame('Kask 3M SecureFit', $target->fresh()->name);
        $this->assertSame('3M', $target->fresh()->manufacturer);

        $second = $this->sync();
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(['P1' => $target->id], $this->links());
        $this->assertEmpty(array_filter($this->logTexts($second), static fn (string $t): bool => str_contains($t, 'wraca na kartę')));
        $this->assertSame('40.00', B2bProductLink::query()->where('remote_id', 'P1')->sole()->last_purchase_price);
    }

    public function test_entry_without_card_warns_and_takes_the_usual_path(): void
    {
        $this->connector->items = [$this->single('P1', 'Kask'), $this->group('G1', ['S', 'M'])];
        $this->connector->prices = ['P1' => 40.00, 'G1-S' => 10.00];
        $this->sync();
        $single = Product::query()->where('sku', 'P1')->sole();
        $group = Product::query()->where('sku', 'G1-S')->sole();
        $gone = $this->producerCard('3M-GONE', 'Usunięta karta', null);
        $this->redirect('P1', $gone, CardRedirect::REASON_MERGE);
        $this->redirect('G1-M', $gone, CardRedirect::REASON_MERGE);
        $gone->delete();
        $this->assertNull(CardRedirect::query()->where('position_key', 'P1')->value('product_id'));

        $result = $this->sync();

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(['G1-M' => $group->id, 'G1-S' => $group->id, 'P1' => $single->id], $this->links());
        $log = $this->logTexts($result);
        $this->assertContains('P1: mapa połączeń ma decyzję bez karty (karta docelowa #'.$gone->id.' (3M-GONE) usunięta) — pozycja idzie zwykłą drogą', $log);
        $this->assertContains('G1-S: pozycja G1-M: mapa połączeń ma decyzję bez karty (karta docelowa #'.$gone->id.' (3M-GONE) usunięta) — pozycja idzie zwykłą drogą', $log);
    }

    public function test_every_link_gets_its_last_price_in_group_path(): void
    {
        $this->connector->items = [$this->group('G1', ['S', 'M', 'L'])];
        $this->connector->prices = ['G1-S' => 10.50];
        $this->sync();

        $links = B2bProductLink::query()->orderBy('id')->get();
        $this->assertCount(3, $links);
        foreach ($links as $link) {
            $this->assertSame('10.50', $link->last_purchase_price);
            $this->assertSame('PLN', $link->last_currency);
            $this->assertTrue($link->last_price_at->isToday());
        }

        // dry-run niczego nie zapisuje
        $this->connector->prices = ['G1-S' => 11.00];
        $this->sync(dryRun: true);
        $this->assertSame(['10.50'], B2bProductLink::query()->pluck('last_purchase_price')->unique()->values()->all());
    }

    public function test_map_is_read_once_per_run(): void
    {
        [$small, $medium, $large] = $this->producerCards();
        $this->firstP4sRun();
        $this->redirect('1001', $small, CardRedirect::REASON_SPLIT);
        $this->redirect('1002', $medium, CardRedirect::REASON_SPLIT);
        $this->redirect('1003', $large, CardRedirect::REASON_SPLIT);
        $this->connector->items = [
            $this->halfMask(['S', 'M', 'L']),
            $this->single('P1', 'Kask'),
            $this->single('P2', 'Okulary'),
            $this->group('G1', ['S', 'M']),
        ];
        $this->connector->prices += ['P1' => 40.00, 'P2' => 20.00, 'G1-S' => 10.00];

        $queries = 0;
        DB::listen(static function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'card_redirects')) {
                $queries++;
            }
        });
        $this->sync();

        $this->assertSame(1, $queries);
    }

    /**
     * Karty 3M rozmiarów S/M/L — każda z powiązaniem konta 3M (właściciel marki: karta chroniona) i własną listą rozmiarów.
     *
     * @return list<Product>
     */
    private function producerCards(): array
    {
        $cards = [];
        foreach (['6100' => 'S', '6200' => 'M', '6300' => 'L'] as $code => $size) {
            $cards[] = $this->producerCard('3M-'.$code, 'Półmaska 3M '.$code.' rozmiar '.$size, 'Rozmiar '.$size.' ('.$code.')');
        }

        return $cards;
    }

    private function producerCard(string $sku, string $name, ?string $sizes): Product
    {
        $card = Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => '3M',
            'variant_summary' => $sizes,
            'catalog_price_net' => 30.00,
            'purchase_price' => 30.00,
            'currency' => 'PLN',
        ]);
        B2bProductLink::query()->create(['b2b_account_id' => $this->mmm->id, 'remote_id' => 'MMM-'.$sku, 'product_id' => $card->id]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id,
            'source_key' => ProductSourcePrice::b2bKey((int) $this->mmm->id),
            'b2b_account_id' => $this->mmm->id,
            'catalog_price_net' => 30.00,
            'purchase_price' => 30.00,
            'currency' => 'PLN',
            'checked_at' => now(),
        ]);

        return $card;
    }

    /** Pierwszy przebieg P4S: karta dystrybutora 6X00 z trzema rozmiarami (zastane wiersze sprzed decyzji). */
    private function firstP4sRun(): Product
    {
        $this->connector->items = [$this->halfMask(['S', 'M', 'L'])];
        $this->connector->prices = ['1001' => 31.20];
        $this->sync();
        $card = Product::query()->where('sku', '6X00')->sole();
        $this->assertSame([$card->id], B2bProductLink::query()->where('b2b_account_id', $this->p4s->id)->pluck('product_id')->unique()->values()->all());

        return $card;
    }

    /**
     * Grupa P4S „6X00”: rozmiar → id pozycji, kod rozmiaru i dostępność (M niedostępny).
     *
     * @param  list<string>  $sizes
     */
    private function halfMask(array $sizes, bool $withIdentifiers = false): B2bRemoteProduct
    {
        $all = [
            'S' => ['1001', '6100', 'Produkt dostępny', '7000146845'],
            'M' => ['1002', '6200', 'Produkt niedostępny', '7000146847'],
            'L' => ['1003', '6300', 'Produkt dostępny', '7000146849'],
            'XL' => ['1004', '6400', 'Produkt dostępny', '7000146851'],
        ];
        $members = [];
        $identifiers = [new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MODEL_CODE, value: '6X00', field: 'code')];
        foreach ($sizes as $size) {
            [$id, $code, $availability, $manufacturerCode] = $all[$size];
            $members[] = ['remote_id' => $id, 'sku' => $code, 'name' => 'Półmaska 3M 6000, rozmiar '.$size, 'availability' => $availability];
            $identifiers[] = new B2bRemoteIdentifier(
                type: ProductIdentifier::TYPE_MANUFACTURER_CODE,
                value: $manufacturerCode,
                remoteId: $id,
                label: 'rozmiar '.$size,
                field: 'manufacturerCode',
            );
        }

        return new B2bRemoteProduct(
            remoteId: $members[0]['remote_id'],
            sku: count($sizes) > 1 && $sizes[0] === 'S' ? '6X00' : $members[0]['sku'],
            name: 'Półmaska 3M 6000',
            category: 'Półmaski',
            availability: 'Produkt dostępny: rozmiar S, rozmiar L; Produkt niedostępny: rozmiar M',
            variantSummary: 'Warianty: '.implode('; ', array_map(static fn (array $m): string => $m['sku'], $members)),
            members: $members,
            identifiers: $withIdentifiers ? $identifiers : null,
        );
    }

    /**
     * @param  list<string>  $sizes
     */
    private function group(string $prefix, array $sizes, ?string $summary = null): B2bRemoteProduct
    {
        $members = array_map(static fn (string $size): array => [
            'remote_id' => $prefix.'-'.$size,
            'sku' => $prefix.'-'.$size,
            'name' => 'Koszulka '.$prefix.' rozm. '.$size,
        ], $sizes);

        return new B2bRemoteProduct(
            remoteId: $members[0]['remote_id'],
            sku: $members[0]['sku'],
            name: 'Koszulka '.$prefix,
            variantSummary: $summary,
            members: $members,
        );
    }

    private function single(string $code, string $name): B2bRemoteProduct
    {
        return new B2bRemoteProduct(remoteId: $code, sku: $code, name: $name);
    }

    private function redirect(string $position, Product $target, string $reason): void
    {
        CardRedirect::query()->updateOrCreate(
            ['source_key' => ProductSourcePrice::b2bKey((int) $this->p4s->id), 'position_key' => $position],
            [
                'b2b_account_id' => $this->p4s->id,
                'product_id' => $target->id,
                'reason' => $reason,
                'target_snapshot' => CardRedirectStore::snapshot($target),
                'created_by' => $this->user->id,
            ],
        );
    }

    /**
     * @return array<string, int> powiązania konta P4S: pozycja → karta
     */
    private function links(): array
    {
        return B2bProductLink::query()
            ->where('b2b_account_id', $this->p4s->id)
            ->orderBy('remote_id')
            ->pluck('product_id', 'remote_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function p4sSlot(int $productId): ProductSourcePrice
    {
        return ProductSourcePrice::query()
            ->where('product_id', $productId)
            ->where('source_key', ProductSourcePrice::b2bKey((int) $this->p4s->id))
            ->sole();
    }

    /**
     * @param  list<Product>  $cards
     * @return list<array<string, mixed>>
     */
    private function cardFields(array $cards): array
    {
        return array_map(static function (Product $card): array {
            $fresh = $card->fresh();

            return [$fresh->name, $fresh->manufacturer, $fresh->variant_summary, $fresh->sku];
        }, $cards);
    }

    private function account(string $username, string $connector, string $site): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $username,
            'password' => 'sekret',
            'sites' => [$site],
            'connector' => $connector,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sync(bool $dryRun = false): array
    {
        return app(B2bAccountSyncRunner::class)->run(
            $this->p4s->fresh(),
            dryRun: $dryRun,
            delayMs: 0,
            connector: $this->connector,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function logTexts(array $result): array
    {
        return array_column(B2bSyncRun::query()->findOrFail($result['sync_run_id'])->log, 'text');
    }
}

/**
 * Łącznik testowy dystrybutora bez sieci. Cena wg remoteId PRODUKTU łącznika (grupy) — pozycja grupy rozdzielonej mapą
 * nie ma własnej ceny u dostawcy, więc synchronizacja musi pytać łącznik o jego produkt, nie o pozycję.
 */
final class RedirectFakeConnector implements B2bConnector
{
    /** @var list<B2bRemoteProduct> */
    public array $items = [];

    /** @var array<string, float> */
    public array $prices = [];

    public string $manufacturer = '3M';

    public static function key(): string
    {
        return 'redirect-fake';
    }

    public static function label(): string
    {
        return 'Mapa połączeń — test';
    }

    public static function host(): string
    {
        return 'redirect.example.test';
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
        return $this->manufacturer;
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        $net = $this->prices[$product->remoteId] ?? null;

        return $net !== null ? new B2bRemotePrice(net: $net) : null;
    }

    public function description(B2bRemoteProduct $product): string
    {
        return '';
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }
}
