<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\CardRedirect;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductPriceHistory;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bCatalogSync;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRemoteShopField;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\Catalog\CardRedirectStore;
use App\Services\ProductSizeMergeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pozycja wiodąca karty modelu z łączenia rozmiarów (plan łączenia kart, krok 6, 24.09.2026). Przypadek z produkcji:
 * 3M (konto B2B, pozycje pojedyncze) ma kartę na rozmiar — 6100 S #40819 (7000146845), 6200 M #40815 (7000146847),
 * 6300 L #40814 (7000146849), po 61,38 zł. Po połączeniu zostaje karta S, a mapa połączeń (card_redirects size_merge)
 * wskazuje pozycję S jako wiodącą. Testy odtwarzają zastane wiersze: pierwszy przebieg 3M zakłada trzy karty (opis,
 * galeria, tabelka, slot, historia), potem łączenie i mapa wpisana ręcznie, potem KOLEJNE przebiegi — drugi przebieg
 * na zastanych wierszach to miejsce, w którym synchronizacja kasowała dane (20.09.2026 UVEX: 648 opisów).
 */
final class B2bSizeMergeAnchorSyncTest extends TestCase
{
    use RefreshDatabase;

    private const S = '7000146845';

    private const M = '7000146847';

    private const L = '7000146849';

    private User $user;

    private B2bAccount $mmm;

    private AnchorFakeConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
        $this->mmm = $this->account('mmm', '3m', 'b2b.3m.example.test');
        $this->connector = new AnchorFakeConnector;
    }

    public function test_anchor_listed_last_still_writes_card_and_other_sizes_only_refresh_links(): void
    {
        $keep = $this->mergedModelCard();
        $historyBefore = $this->historyCount($keep);

        // kolejność M, L, S — wiodąca S ostatnia; każda pozycja ma własny opis, galerię i tabelkę, S nową cenę
        $this->connector->items = [$this->position(self::M), $this->position(self::L), $this->position(self::S)];
        $this->connector->prices = [self::S => 62.00, self::M => 61.38, self::L => 61.38];
        foreach ([self::S, self::M, self::L] as $code) {
            $this->connector->descriptions[$code] = 'Opis producenta pozycji '.$code.' — wersja druga, pełny tekst.';
            $this->connector->galleries[$code] = ['https://b2b.3m.example.test/'.$code.'-1.png', 'https://b2b.3m.example.test/'.$code.'-2.png'];
            $this->connector->shopFields[$code] = [new B2bRemoteShopField('', 'Pozycja', $code.' v2')];
        }
        // po terminie odświeżenia tabelki sklepu — każda pozycja „mogłaby” ją zapisać
        $this->travel(B2bCatalogSync::SHOP_FIELDS_TTL_DAYS + 1)->days();
        $result = $this->sync();

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['skipped']);
        $card = $keep->fresh();
        // tożsamość karty modelu bez zmian
        $this->assertSame(self::S, $card->sku);
        $this->assertSame('6X00 Półmaska 3M 6000', $card->name);
        $this->assertSame('Rozmiary: S (7000146845); M (7000146847); L (7000146849)', $card->variant_summary);
        // opis, slot, historia, zdjęcie główne i tabelka — tylko z S
        $this->assertSame('Opis producenta pozycji '.self::S.' — wersja druga, pełny tekst.', $card->description);
        $this->assertSame('62.00', $this->mmmSlot($keep)->purchase_price);
        $this->assertSame(
            ['62.00'],
            ProductPriceHistory::query()->where('product_id', $keep->id)->where('b2b_sync_run_id', $result['sync_run_id'])
                ->pluck('purchase_price')->map(static fn ($p): string => (string) $p)->all(),
        );
        $this->assertSame($historyBefore + 1, $this->historyCount($keep));
        $primary = ProductImage::query()->where('product_id', $keep->id)->where('is_primary', true)->sole();
        $this->assertSame('https://b2b.3m.example.test/'.self::S.'-1.png', $primary->source_url);
        foreach ([self::M, self::L] as $code) {
            $this->assertFalse(
                ProductImage::query()->where('source_url', 'https://b2b.3m.example.test/'.$code.'-2.png')->exists(),
                'galeria niewiodącej pozycji '.$code.' nie trafia na kartę',
            );
        }
        $this->assertSame(self::S.' v2', $this->mmmShopCard($keep)->fields[0]['rows'][0]['value']);
        $this->assertSame(1, ProductShopCard::query()->where('product_id', $keep->id)->count());
        // M, L: powiązania, identyfikatory i cena pozycji przy powiązaniu
        foreach ([self::S => '62.00', self::M => '61.38', self::L => '61.38'] as $code => $price) {
            $code = (string) $code;
            $link = $this->link($code);
            $this->assertSame($keep->id, (int) $link->product_id);
            $this->assertSame($price, $link->last_purchase_price);
            $this->assertTrue($link->last_seen_at->isToday());
            $this->assertSame($keep->id, (int) ProductIdentifier::query()
                ->where('source_key', ProductSourcePrice::b2bKey($this->mmm->id))
                ->where('position_key', $code)->value('product_id'));
        }
        $this->assertSame([], $this->anchorWarnings($result));
    }

    public function test_second_run_non_anchor_with_other_price_warns_and_keeps_slot(): void
    {
        $keep = $this->mergedModelCard();
        $this->connector->items = [$this->position(self::S), $this->position(self::M), $this->position(self::L)];
        $this->sync();
        $slotBefore = $this->mmmSlot($keep)->only(['purchase_price', 'catalog_price_net', 'currency']);
        $descriptionBefore = $keep->fresh()->description;
        $historyBefore = $this->historyCount($keep);

        // drugi przebieg na zastanych wierszach: M w innej cenie (czyli może to nie jest sam rozmiar)
        $this->travel(1)->days();
        $this->connector->prices[self::M] = 65.00;
        $this->connector->descriptions[self::M] = 'Zupełnie inny opis pozycji M, który nie może trafić na kartę.';
        $result = $this->sync();

        $this->assertSame($slotBefore, $this->mmmSlot($keep)->only(['purchase_price', 'catalog_price_net', 'currency']));
        $this->assertSame($descriptionBefore, $keep->fresh()->description);
        $this->assertSame($historyBefore, $this->historyCount($keep));
        $this->assertSame('65.00', $this->link(self::M)->last_purchase_price);
        $this->assertTrue($this->link(self::M)->last_price_at->isToday());
        $this->assertContains(
            self::M.': karta modelu #'.$keep->id.' ('.self::S.'): rozmiar '.self::M.' ma cenę 65,00 PLN, pozycja wiodąca 61,38 PLN — do sprawdzenia w Łączenie kart (rozdzielenie?)',
            $this->logTexts($result),
        );
    }

    public function test_full_run_without_anchor_warns_and_changes_nothing_on_card(): void
    {
        $keep = $this->mergedModelCard();
        $this->connector->items = [$this->position(self::S), $this->position(self::M), $this->position(self::L)];
        $this->sync();
        $slotBefore = $this->mmmSlot($keep)->only(['purchase_price', 'catalog_price_net', 'currency']);
        $cardBefore = $keep->fresh()->only(['name', 'description', 'variant_summary', 'sku']);
        $imagesBefore = $this->imagesOf($keep);

        // 3M przestał podawać 6100 S; pozostałe rozmiary z nowymi opisami i zdjęciami
        $this->connector->items = [$this->position(self::M), $this->position(self::L)];
        $this->connector->prices = [self::M => 70.00, self::L => 70.00];
        $this->connector->descriptions[self::M] = 'Nowy opis pozycji M, który nie może trafić na kartę modelu.';
        $this->connector->galleries[self::M] = ['https://b2b.3m.example.test/m-nowe.png'];
        $result = $this->sync();

        $warning = 'Pozycja wiodąca '.self::S.' karty modelu #'.$keep->id.' ('.self::S.') nie przyszła w tym przebiegu — cena, opis i zdjęcia tej karty nie są odświeżane. Zmień pozycję wiodącą: php artisan card-redirects:anchor '.$keep->id.' <pozycja> --source=b2b:'.$this->mmm->id.' --apply';
        $this->assertSame([$warning], $this->anchorWarnings($result));
        $this->assertContains($warning, $result['errors']);
        $this->assertSame($slotBefore, $this->mmmSlot($keep)->only(['purchase_price', 'catalog_price_net', 'currency']));
        $this->assertSame($cardBefore, $keep->fresh()->only(['name', 'description', 'variant_summary', 'sku']));
        $this->assertEquals($imagesBefore, $this->imagesOf($keep));
        $this->assertSame('70.00', $this->link(self::M)->last_purchase_price);
        // wiodąca bez zmian — zamianę robi człowiek poleceniem
        $this->assertSame(self::S, CardRedirect::query()->where('is_anchor', true)->sole()->position_key);
    }

    public function test_limited_run_without_anchor_does_not_warn(): void
    {
        $keep = $this->mergedModelCard();
        $this->connector->items = [$this->position(self::M), $this->position(self::L)];
        $result = $this->sync(limit: 5);

        $this->assertSame([], $this->anchorWarnings($result));
        $this->assertSame($keep->id, (int) $this->link(self::M)->product_id);
    }

    public function test_group_with_only_non_anchor_size_merge_entries_refreshes_links_only(): void
    {
        $keep = $this->mergedModelCard();
        $slotBefore = $this->mmmSlot($keep)->only(['purchase_price', 'catalog_price_net', 'currency']);
        $descriptionBefore = $keep->fresh()->description;
        $historyBefore = $this->historyCount($keep);

        // łącznik podał M i L jako grupę rozmiarów (jedna cena grupy) — oba wpisy mapy niewiodące
        $this->connector->items = [new B2bRemoteProduct(
            remoteId: self::M,
            sku: self::M,
            name: '3M 6200 Półmaska M',
            variantSummary: 'Rozmiary: M, L',
            members: [
                ['remote_id' => self::M, 'sku' => self::M, 'name' => '3M 6200 Półmaska M'],
                ['remote_id' => self::L, 'sku' => self::L, 'name' => '3M 6300 Półmaska L'],
            ],
        )];
        $this->connector->prices = [self::M => 64.00];
        $this->connector->descriptions[self::M] = 'Opis grupy M i L, który nie może trafić na kartę modelu.';
        foreach ([1, 2] as $run) {
            $result = $this->sync(limit: 5);
            $this->assertSame(0, $result['created'], 'przebieg '.$run);
            $this->assertSame($slotBefore, $this->mmmSlot($keep)->only(['purchase_price', 'catalog_price_net', 'currency']), 'przebieg '.$run);
            $this->assertSame($descriptionBefore, $keep->fresh()->description, 'przebieg '.$run);
            $this->assertSame('Rozmiary: S (7000146845); M (7000146847); L (7000146849)', $keep->fresh()->variant_summary);
            $this->assertSame($historyBefore, $this->historyCount($keep), 'przebieg '.$run);
            foreach ([self::M, self::L] as $code) {
                $this->assertSame($keep->id, (int) $this->link($code)->product_id);
                $this->assertSame('64.00', $this->link($code)->last_purchase_price);
            }
        }
    }

    public function test_distributor_merge_entries_keep_full_write_as_before(): void
    {
        $keep = $this->mergedModelCard();
        $p4s = $this->account('p4s', 'p4s', 'b2b.p4s.example.test');
        foreach (['1001', '1002', '1003'] as $position) {
            CardRedirect::query()->create([
                'source_key' => ProductSourcePrice::b2bKey((int) $p4s->id),
                'position_key' => $position,
                'b2b_account_id' => $p4s->id,
                'product_id' => $keep->id,
                'reason' => CardRedirect::REASON_MERGE,
                'is_anchor' => false,
                'target_snapshot' => CardRedirectStore::snapshot($keep),
                'created_by' => $this->user->id,
            ]);
        }
        $distributor = new AnchorDistributorConnector;
        $distributor->items = [new B2bRemoteProduct(
            remoteId: '1001',
            sku: '6X00',
            name: 'Półmaska 3M 6000',
            members: [
                ['remote_id' => '1001', 'sku' => '6100', 'name' => 'Półmaska 3M 6000, rozmiar S'],
                ['remote_id' => '1002', 'sku' => '6200', 'name' => 'Półmaska 3M 6000, rozmiar M'],
                ['remote_id' => '1003', 'sku' => '6300', 'name' => 'Półmaska 3M 6000, rozmiar L'],
            ],
        )];

        foreach ([1, 2] as $run) {
            $result = app(B2bAccountSyncRunner::class)->run($p4s->fresh(), delayMs: 0, connector: $distributor);
            $this->assertSame(0, $result['created'], 'przebieg '.$run);
            $slot = ProductSourcePrice::query()->where('product_id', $keep->id)
                ->where('source_key', ProductSourcePrice::b2bKey((int) $p4s->id))->sole();
            $this->assertSame('31.20', $slot->purchase_price);
            $this->assertSame(
                ['1001' => $keep->id, '1002' => $keep->id, '1003' => $keep->id],
                B2bProductLink::query()->where('b2b_account_id', $p4s->id)->orderBy('remote_id')->pluck('product_id', 'remote_id')
                    ->map(static fn ($id): int => (int) $id)->all(),
            );
            $this->assertSame([], $this->anchorWarnings($result));
        }
        // karta 3M: nazwa, lista rozmiarów i slot 3M nietknięte
        $this->assertSame('6X00 Półmaska 3M 6000', $keep->fresh()->name);
        $this->assertSame('Rozmiary: S (7000146845); M (7000146847); L (7000146849)', $keep->fresh()->variant_summary);
        $this->assertSame('61.38', $this->mmmSlot($keep)->purchase_price);
    }

    /**
     * Zastane wiersze: pierwszy przebieg 3M zakłada trzy karty rozmiarów, potem łączenie w kartę S i mapa połączeń
     * (size_merge, wiodąca S) wpisana ręcznie — tak, jak zapisze ją CardRedirectStore::recordSizeMerge.
     */
    private function mergedModelCard(): Product
    {
        $this->connector->items = [$this->position(self::S), $this->position(self::M), $this->position(self::L)];
        $this->connector->prices = [self::S => 61.38, self::M => 61.38, self::L => 61.38];
        foreach ([self::S, self::M, self::L] as $code) {
            $this->connector->descriptions[$code] = 'Opis producenta pozycji '.$code.' — pierwsza wersja tekstu.';
            $this->connector->galleries[$code] = ['https://b2b.3m.example.test/'.$code.'-1.png'];
            $this->connector->shopFields[$code] = [new B2bRemoteShopField('', 'Pozycja', $code)];
        }
        $first = $this->sync();
        $this->assertSame(3, $first['created']);
        $cards = [];
        foreach ([self::S, self::M, self::L] as $code) {
            $cards[$code] = Product::query()->where('sku', $code)->sole();
        }

        $service = app(ProductSizeMergeService::class);
        $images = $service->mergeSizeCards(
            $cards[self::S],
            [$cards[self::M], $cards[self::L]],
            '6X00 Półmaska 3M 6000',
            'Rozmiary: S (7000146845); M (7000146847); L (7000146849)',
        );
        $keep = $cards[self::S]->fresh();
        $service->orderSizeMergeImages($keep, $images['image_ids_keep'], $images['image_ids_drops']);
        foreach ([self::S, self::M, self::L] as $code) {
            CardRedirect::query()->create([
                'source_key' => ProductSourcePrice::b2bKey((int) $this->mmm->id),
                'position_key' => $code,
                'b2b_account_id' => $this->mmm->id,
                'product_id' => $keep->id,
                'reason' => CardRedirect::REASON_SIZE_MERGE,
                'is_anchor' => $code === self::S,
                'remote_sku' => $code,
                'target_snapshot' => CardRedirectStore::snapshot($keep),
                'created_by' => $this->user->id,
            ]);
        }
        $this->assertSame(1, Product::query()->count());

        return $keep;
    }

    private function position(string $code): B2bRemoteProduct
    {
        $sizes = [self::S => '6100 S', self::M => '6200 M', self::L => '6300 L'];

        return new B2bRemoteProduct(
            remoteId: $code,
            sku: $code,
            name: '3M '.$sizes[$code].' Półmaska',
            identifiers: [new B2bRemoteIdentifier(
                type: ProductIdentifier::TYPE_MANUFACTURER_CODE,
                value: 'KOD-'.$code,
                remoteId: $code,
                field: 'code',
            )],
        );
    }

    private function mmmSlot(Product $card): ProductSourcePrice
    {
        return ProductSourcePrice::query()
            ->where('product_id', $card->id)
            ->where('source_key', ProductSourcePrice::b2bKey((int) $this->mmm->id))
            ->sole();
    }

    private function mmmShopCard(Product $card): ProductShopCard
    {
        return ProductShopCard::query()->where('product_id', $card->id)->where('b2b_account_id', $this->mmm->id)->sole();
    }

    private function link(string $code): B2bProductLink
    {
        return B2bProductLink::query()->where('b2b_account_id', $this->mmm->id)->where('remote_id', $code)->sole();
    }

    private function historyCount(Product $card): int
    {
        return ProductPriceHistory::query()->where('product_id', $card->id)->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function imagesOf(Product $card): array
    {
        return ProductImage::query()->where('product_id', $card->id)->orderBy('id')
            ->get(['id', 'source_url', 'b2b_account_id', 'sort_order', 'is_primary'])
            ->toArray();
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
    private function sync(?int $limit = null): array
    {
        return app(B2bAccountSyncRunner::class)->run($this->mmm->fresh(), limit: $limit, delayMs: 0, connector: $this->connector);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function logTexts(array $result): array
    {
        return array_column(B2bSyncRun::query()->findOrFail($result['sync_run_id'])->log, 'text');
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function anchorWarnings(array $result): array
    {
        return array_values(array_filter(
            $this->logTexts($result),
            static fn (string $text): bool => str_starts_with($text, 'Pozycja wiodąca '),
        ));
    }
}

/**
 * Łącznik testowy witryny producenta jak 3M: pozycje pojedyncze, cena, opis, galeria i tabelka według pozycji.
 */
final class AnchorFakeConnector implements B2bConnector, B2bImageGallery, B2bManufacturerSite, B2bShopFieldSource
{
    /** @var list<B2bRemoteProduct> */
    public array $items = [];

    /** @var array<string, float> */
    public array $prices = [];

    /** @var array<string, string> */
    public array $descriptions = [];

    /** @var array<string, list<string>> */
    public array $galleries = [];

    /** @var array<string, list<B2bRemoteShopField>> */
    public array $shopFields = [];

    public static function key(): string
    {
        return 'anchor-fake';
    }

    public static function label(): string
    {
        return 'Pozycja wiodąca — test';
    }

    public static function host(): string
    {
        return 'anchor.example.test';
    }

    public static function ownBrand(): string
    {
        return '3M';
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
        return '3M';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        $net = $this->prices[$product->remoteId] ?? null;

        return $net !== null ? new B2bRemotePrice(net: $net) : null;
    }

    public function description(B2bRemoteProduct $product): string
    {
        return $this->descriptions[$product->remoteId] ?? '';
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $urls = $this->imageUrls($product);

        return $urls === [] ? null : $this->imageAt($urls[0]);
    }

    public function imageUrls(B2bRemoteProduct $product): array
    {
        return $this->galleries[$product->remoteId] ?? [];
    }

    public function imageAt(string $url): ?B2bRemoteImage
    {
        return new B2bRemoteImage(bytes: self::png($url), mime: 'image/png', sourceUrl: $url);
    }

    public function shopFields(B2bRemoteProduct $product): array
    {
        return $this->shopFields[$product->remoteId] ?? [];
    }

    /** Plik PNG zależny od ziarna — różne ziarna, różne sumy kontrolne (dedup zdjęć). */
    public static function png(string $seed): string
    {
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, crc32($seed) & 0xFFFFFF);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}

/** Łącznik testowy dystrybutora (P4S): grupa rozmiarów w jednej cenie, bez opisu i zdjęć. */
final class AnchorDistributorConnector implements B2bConnector
{
    /** @var list<B2bRemoteProduct> */
    public array $items = [];

    public static function key(): string
    {
        return 'anchor-distributor-fake';
    }

    public static function label(): string
    {
        return 'Dystrybutor — test';
    }

    public static function host(): string
    {
        return 'distributor.example.test';
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
        return '3M';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: 31.20);
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
