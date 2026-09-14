<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductVariant;
use App\Models\ProductVariantPriceHistory;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRemoteVariant;
use App\Services\B2b\B2bVariantConnector;
use App\Services\ProductSizeMergeService;
use App\Services\Vector\ProductEmbeddingIndexer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Synchronizacja łącznika z wersjami (np. SignProject) na sztucznym łączniku — bez HTTP.
 */
final class B2bVariantSyncTest extends TestCase
{
    use RefreshDatabase;

    private const FORMATS = ['10 x 14,8 cm', '20 x 29,6 cm'];

    private const SUBSTRATES = ['FN - folia samoprzylepna', 'PN - płyta sztywna 1mm'];

    private const SOURCE = 'b2b:fakesign';

    private FakeVariantConnector $connector;

    private B2bAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();

        $user = User::factory()->withRole('admin')->create();
        $this->account = B2bAccount::query()->create([
            'username' => 'supon',
            'password' => 'sekret',
            'sites' => ['fakesign.example.test'],
            'connector' => 'fakesign',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $this->connector = new FakeVariantConnector;
    }

    public function test_new_sign_creates_card_without_price_with_variants_history_and_summary(): void
    {
        $this->sign('BB014', ['101' => 0.97, '102' => 1.50, '103' => 2.10, '104' => 4.14]);

        $result = $this->sync();

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['prices_changed']);
        $product = Product::query()->where('sku', 'BB014')->firstOrFail();
        $this->assertSame('0.00', $product->purchase_price);
        $this->assertSame('0.00', $product->catalog_price_net);
        $this->assertSame('0.00', $product->discount_percent);
        $this->assertSame('PLN', $product->currency);
        $this->assertSame('SignProject', $product->manufacturer);
        $this->assertSame('Znaki › Ewakuacyjne', $product->category);
        $this->assertSame(
            'Format: 10 x 14,8 cm; 20 x 29,6 cm | Podłoże: FN - folia samoprzylepna; PN - płyta sztywna 1mm',
            $product->variant_summary,
        );

        $variants = $product->variants()->get();
        $this->assertCount(4, $variants);
        $first = $variants->firstWhere('remote_id', '101');
        $this->assertSame(self::SOURCE, $first->source);
        $this->assertSame($this->account->id, $first->b2b_account_id);
        $this->assertSame('0.97', $first->purchase_price);
        $this->assertNull($first->list_price_net);
        $this->assertSame('PLN', $first->currency);
        $this->assertSame('23.00', $first->vat_rate);
        $this->assertSame('szt.', $first->unit);
        $this->assertSame(['Format' => '10 x 14,8 cm', 'Podłoże' => 'FN - folia samoprzylepna'], $first->attributes);
        $this->assertSame('10 x 14,8 cm \ FN - folia samoprzylepna', $first->label);
        $this->assertNotNull($first->price_checked_at);
        $this->assertNotNull($first->last_seen_at);
        $this->assertNull($first->removed_at);

        $this->assertSame(4, ProductVariantPriceHistory::query()
            ->where('b2b_sync_run_id', $result['sync_run_id'])
            ->where('source', self::SOURCE)
            ->count());
        // historia ceny karty tylko przy przejściu z ceny > 0
        $this->assertSame(0, ProductPriceHistory::query()->count());
        $this->assertTrue(B2bProductLink::query()->where('remote_id', 'BB014')->where('product_id', $product->id)->exists());

        $run = B2bSyncRun::query()->findOrFail($result['sync_run_id']);
        $this->assertSame('variants', $run->progress_unit);
        $this->assertSame(4, $run->total);
        $this->assertSame(4, $run->processed);
        $this->assertSame(1, $run->created);
        $this->assertStringContainsString('wersje: 4/4', (string) $run->message);
        $this->assertContains('[1/1] BB014 — nowy · wersji: 4', array_column($run->log, 'text'));

        // formaty/podłoża w indeksie tekstowym i w dokumencie embeddingu
        $this->assertStringContainsString('folia samoprzylepna', (string) $product->search_blob);
        $this->assertStringContainsString(
            'Podłoże: FN - folia samoprzylepna',
            app(ProductEmbeddingIndexer::class)->documentText($product),
        );
    }

    public function test_second_identical_run_adds_no_history_and_only_touches_variants(): void
    {
        $this->sign('BB014', ['101' => 0.97, '102' => 1.50]);
        $this->sync();
        $before = ProductVariant::query()->where('remote_id', '101')->firstOrFail();

        $this->travel(1)->days();
        Queue::fake();
        $second = $this->sync();

        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(0, $second['updated'] + $second['created']);
        $this->assertSame(2, ProductVariantPriceHistory::query()->count());
        $after = $before->fresh();
        $this->assertTrue($after->price_checked_at->greaterThan($before->price_checked_at));
        $this->assertTrue($after->last_seen_at->greaterThan($before->last_seen_at));
        $this->assertEquals($before->updated_at, $after->updated_at);
        Queue::assertNotPushed(ReindexProductEmbeddingJob::class);
    }

    public function test_price_change_of_one_variant_adds_one_history_row_and_price_change_entry(): void
    {
        $this->sign('BB014', ['101' => 0.97, '102' => 1.50]);
        $this->sync();

        $this->sign('BB014', ['101' => 0.97, '102' => 1.60]);
        Queue::fake();
        $second = $this->sync();

        $this->assertSame(1, $second['updated']);
        $this->assertSame(1, $second['prices_changed']);
        $variant = ProductVariant::query()->where('remote_id', '102')->firstOrFail();
        $this->assertSame('1.60', $variant->purchase_price);
        $this->assertSame(2, $variant->priceHistory()->count());
        $this->assertSame(1, ProductVariant::query()->where('remote_id', '101')->firstOrFail()->priceHistory()->count());
        $this->assertTrue($variant->priceHistory()->where('b2b_sync_run_id', $second['sync_run_id'])->where('purchase_price', 1.60)->exists());

        $run = B2bSyncRun::query()->findOrFail($second['sync_run_id']);
        $this->assertCount(1, $run->price_changes);
        $change = $run->price_changes[0];
        $this->assertSame($variant->product_id, $change['product_id']);
        $this->assertSame($variant->id, $change['variant_id']);
        $this->assertSame('20 x 29,6 cm \ FN - folia samoprzylepna', $change['variant_label']);
        $this->assertSame('BB014', $change['sku']);
        $this->assertEquals(1.5, $change['purchase_old']);
        $this->assertEquals(1.6, $change['purchase_new']);
        $this->assertNull($change['catalog_old']);
        $this->assertNull($change['catalog_new']);
        $this->assertNull($change['catalog_pct']);
        $this->assertNull($change['discount_old']);
        $this->assertSame('up', $change['direction']);
        $this->assertNotEmpty($change['at']);
        // sama cena wersji nie zmienia dokumentu wyszukiwania
        Queue::assertNotPushed(ReindexProductEmbeddingJob::class);
    }

    public function test_price_error_keeps_stored_price_and_sign_without_any_price_is_skipped(): void
    {
        $this->sign('BB014', ['101' => 0.97, '102' => 1.50]);
        $this->sync();
        $before = ProductVariant::query()->where('remote_id', '102')->firstOrFail();

        $this->travel(1)->days();
        $this->sign('BB014', ['101' => 0.97, '102' => 'HTTP 500']);
        $this->sign('GL031', ['201' => 'HTTP 500', '202' => 'HTTP 500']);
        $second = $this->sync();

        $after = $before->fresh();
        $this->assertSame('1.50', $after->purchase_price);
        $this->assertSame('23.00', $after->vat_rate);
        $this->assertEquals($before->price_checked_at, $after->price_checked_at);
        $this->assertTrue($after->last_seen_at->greaterThan($before->last_seen_at));
        $this->assertSame(1, $after->priceHistory()->count());

        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(1, $second['skipped']);
        $this->assertContains('GL031: brak ceny w B2B (HTTP 500)', $second['errors']);
        $this->assertFalse(Product::query()->where('sku', 'GL031')->exists());
    }

    public function test_price_fuse_skips_sign_with_uniform_large_change_only(): void
    {
        $this->sign('AA001', ['101' => 1.00, '102' => 2.00]);
        $this->sign('AA002', ['201' => 1.00, '202' => 5.00]);
        $this->sync();

        $this->sign('AA001', ['101' => 3.30, '102' => 6.60]);
        // tylko połowa wersji zmieniła cenę — zwykła zmiana cennika
        $this->sign('AA002', ['201' => 2.00, '202' => 5.00]);
        $second = $this->sync();

        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, $second['updated']);
        $this->assertContains('AA001: podejrzana zmiana wszystkich cen — możliwa utrata ceny konta', $second['errors']);
        $this->assertSame('1.00', ProductVariant::query()->where('remote_id', '101')->value('purchase_price'));
        $this->assertSame('2.00', ProductVariant::query()->where('remote_id', '201')->value('purchase_price'));
        $this->assertSame('ok', B2bSyncRun::query()->findOrFail($second['sync_run_id'])->status);
    }

    public function test_three_suspicious_signs_in_a_row_fail_the_run(): void
    {
        foreach (['AA001', 'AA002', 'AA003', 'AA004'] as $n => $code) {
            $this->sign($code, [($n + 1).'01' => 1.00, ($n + 1).'02' => 2.00]);
        }
        $this->sync();

        foreach (['AA001', 'AA002', 'AA003', 'AA004'] as $n => $code) {
            $this->sign($code, [($n + 1).'01' => 3.23, ($n + 1).'02' => 6.46]);
        }
        $error = $this->syncExpectingFailure();

        $this->assertInstanceOf(B2bFatalException::class, $error);
        $this->assertStringContainsString('podejrzana zmiana wszystkich cen', $error->getMessage());
        $run = B2bSyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame(3, $run->skipped);
        $this->assertSame('failed', $this->account->fresh()->last_sync_status);
        $this->assertSame(8, ProductVariant::query()->where('purchase_price', '<', 2.5)->count());
        $this->assertSame(8, ProductVariantPriceHistory::query()->count());
    }

    public function test_fatal_exception_mid_run_fails_run_and_keeps_earlier_signs(): void
    {
        $this->sign('AA001', ['101' => 1.00]);
        $this->sign('AA002', ['201' => 1.00]);
        $this->connector->signs['AA002']['variants'] = new B2bFatalException('Utracono sesję konta SignProject.');
        $this->sign('AA003', ['301' => 1.00]);

        $error = $this->syncExpectingFailure();

        $this->assertInstanceOf(B2bFatalException::class, $error);
        $this->assertTrue(Product::query()->where('sku', 'AA001')->exists());
        $this->assertSame(1, ProductVariant::query()->count());
        $this->assertFalse(Product::query()->whereIn('sku', ['AA002', 'AA003'])->exists());

        $run = B2bSyncRun::query()->sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame('Utracono sesję konta SignProject.', $run->message);
        $account = $this->account->fresh();
        $this->assertSame('failed', $account->last_sync_status);
        $this->assertSame('Utracono sesję konta SignProject.', $account->last_sync_message);
    }

    public function test_variants_missing_from_complete_list_are_marked_removed_and_can_return(): void
    {
        $this->sign('AA001', ['101' => 1.00, '102' => 2.00]);
        $this->sign('AA002', ['201' => 1.00]);
        $this->sync();
        $product = Product::query()->where('sku', 'AA001')->firstOrFail();

        // lista niepełna (null) — nic nie oznaczamy
        $this->sign('AA001', ['101' => 1.00]);
        $this->connector->listed = null;
        $this->sync();
        $this->assertNull(ProductVariant::query()->where('remote_id', '102')->value('removed_at'));

        // wersja widziana w bieżącym przebiegu nie jest wycofywana — kolejny przebieg musi zacząć się później
        $this->travel(1)->minutes();
        $this->connector->listed = ['101', '201'];
        $third = $this->sync();
        $this->assertSame(1, $third['variants_removed']);
        $this->assertNotNull(ProductVariant::query()->where('remote_id', '102')->value('removed_at'));
        $this->assertNull(ProductVariant::query()->where('remote_id', '101')->value('removed_at'));
        $this->assertSame('Format: 10 x 14,8 cm | Podłoże: FN - folia samoprzylepna', $product->fresh()->variant_summary);

        $this->sign('AA001', ['101' => 1.00, '102' => 2.00]);
        $this->connector->listed = ['101', '102', '201'];
        $fourth = $this->sync();
        $this->assertSame(0, $fourth['variants_removed']);
        $this->assertNull(ProductVariant::query()->where('remote_id', '102')->value('removed_at'));
        $this->assertSame(
            'Format: 10 x 14,8 cm; 20 x 29,6 cm | Podłoże: FN - folia samoprzylepna',
            $product->fresh()->variant_summary,
        );
    }

    public function test_removal_keeps_variants_seen_now_and_multi_key_variants_listed_by_base_id(): void
    {
        $this->sign('AA001', ['101:a' => 1.00, '101:b' => 2.00, '102' => 3.00]);
        // mapa strony zna tylko ID bazowe 101; 102 sklep zwrócił, choć mapy w nim nie ma
        $this->connector->listed = ['101'];
        $first = $this->sync();

        $this->assertSame(0, $first['variants_removed']);
        $this->assertSame(0, ProductVariant::query()->whereNotNull('removed_at')->count());

        $this->travel(1)->minutes();
        $this->sign('AA001', ['101:a' => 1.00]);
        $second = $this->sync();

        $this->assertSame(1, $second['variants_removed']);
        $this->assertNull(ProductVariant::query()->where('remote_id', '101:b')->value('removed_at'));
        $this->assertNotNull(ProductVariant::query()->where('remote_id', '102')->value('removed_at'));
    }

    public function test_second_version_group_with_same_code_is_skipped_as_conflict(): void
    {
        $this->sign('BB014', ['101' => 1.00, '102' => 2.00]);
        $this->sign('BB014', ['201' => 5.00], key: 'BB014#2');

        $first = $this->sync();

        $this->assertSame(1, $first['created']);
        $this->assertSame(1, $first['skipped']);
        $card = Product::query()->where('sku', 'BB014')->sole();
        $this->assertStringContainsString(
            'BB014: karta #'.$card->id.' tego kodu ma już inne wersje (2) — inna grupa wersji o tym samym kodzie',
            implode(' | ', $first['errors']),
        );
        $this->assertFalse(ProductVariant::query()->where('remote_id', '201')->exists());

        $second = $this->sync();
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(2, $card->variants()->count());
    }

    public function test_description_fetch_error_keeps_description_and_still_updates_prices(): void
    {
        $this->connector->descriptionText = 'Znak ewakuacyjny fotoluminescencyjny zgodny z PN-EN ISO 7010.';
        $this->sign('BB014', ['101' => 1.00]);
        $this->sync();

        $this->connector->descriptionError = 'Nie pobrano strony produktu (HTTP 503)';
        $this->sign('BB014', ['101' => 1.20]);
        $second = $this->sync();

        $this->assertSame(1, $second['updated']);
        $this->assertSame(1, $second['prices_changed']);
        $this->assertSame('1.20', ProductVariant::query()->where('remote_id', '101')->value('purchase_price'));
        $card = Product::query()->where('sku', 'BB014')->sole();
        $this->assertSame('Znak ewakuacyjny fotoluminescencyjny zgodny z PN-EN ISO 7010.', $card->description);
        $this->assertSame(sha1((string) $card->description), B2bProductLink::query()->where('remote_id', 'BB014')->value('description_hash'));
        $run = B2bSyncRun::query()->findOrFail($second['sync_run_id']);
        $this->assertContains(
            'BB014: opis nie został pobrany (Nie pobrano strony produktu (HTTP 503)) — opis bez zmian, ceny zaktualizowane',
            array_column($run->log, 'text'),
        );
    }

    public function test_run_budget_stops_with_partial_message_and_ok_status(): void
    {
        $this->sign('AA001', ['101' => 1.00, '102' => 2.00]);
        $this->sign('AA002', ['201' => 1.00]);
        $this->connector->budget = 0;

        $result = $this->sync();

        $this->assertTrue($result['partial']);
        $this->assertFalse($result['cancelled']);
        $this->assertSame(1, $result['seen']);
        $this->assertFalse(Product::query()->where('sku', 'AA002')->exists());
        $run = B2bSyncRun::query()->findOrFail($result['sync_run_id']);
        $this->assertSame('ok', $run->status);
        // podsumowanie przebiegu z wersjami zaczyna się od liczby wersji z listy (3), nie od szacunku liczby znaków
        $this->assertStringStartsWith("Częściowy: 2/3 wersji — kontynuacja w następnym przebiegu\nWersji w B2B: 3 · sprawdzone: 1", (string) $run->message);
        $account = $this->account->fresh();
        $this->assertSame('ok', $account->last_sync_status);
        $this->assertStringStartsWith('Częściowy: 2/3 wersji', (string) $account->last_sync_message);
    }

    public function test_previously_priced_card_gets_price_zero_with_one_history_row(): void
    {
        $card = Product::query()->create([
            'sku' => 'BB014',
            'name' => 'Znak ewakuacyjny',
            'manufacturer' => 'SignProject',
            'catalog_price_net' => 15.00,
            'purchase_price' => 12.00,
            'discount_percent' => 20,
            'currency' => 'PLN',
        ]);
        $this->sign('BB014', ['101' => 0.97]);

        $result = $this->sync();

        $card->refresh();
        $this->assertSame(1, $result['updated']);
        $this->assertSame('0.00', $card->purchase_price);
        $this->assertSame('0.00', $card->catalog_price_net);
        $this->assertSame('0.00', $card->discount_percent);
        $history = ProductPriceHistory::query()->where('product_id', $card->id)->sole();
        $this->assertSame(self::SOURCE, $history->source);
        $this->assertSame($result['sync_run_id'], $history->b2b_sync_run_id);
        $this->assertEquals(0, $history->purchase_price);
        $this->assertSame(1, $card->variants()->count());
        $run = B2bSyncRun::query()->findOrFail($result['sync_run_id']);
        $this->assertContains(
            'BB014: cena karty (zakup 12.00, katalog 15.00) zmieniona na 0 — ceny są teraz w wersjach',
            array_column($run->log, 'text'),
        );

        // kolejny przebieg nie dopisuje przejścia drugi raz
        $this->sync();
        $this->assertSame(1, ProductPriceHistory::query()->where('product_id', $card->id)->count());
    }

    public function test_sign_whose_variants_belong_to_two_cards_is_skipped_without_merge(): void
    {
        $x = Product::query()->create(['sku' => 'X1', 'name' => 'Karta X', 'manufacturer' => 'SignProject', 'catalog_price_net' => 0, 'purchase_price' => 0]);
        $y = Product::query()->create(['sku' => 'Y1', 'name' => 'Karta Y', 'manufacturer' => 'SignProject', 'catalog_price_net' => 0, 'purchase_price' => 0]);
        foreach ([[$x, '101'], [$y, '102']] as [$card, $remoteId]) {
            ProductVariant::query()->create([
                'product_id' => $card->id, 'source' => self::SOURCE, 'remote_id' => $remoteId,
                'label' => 'wersja '.$remoteId, 'purchase_price' => 1.00, 'currency' => 'PLN',
            ]);
        }
        $this->sign('BB014', ['101' => 1.00, '102' => 1.00]);

        $result = $this->sync();

        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('BB014: wersje należą do kilku kart (#'.$x->id.', #'.$y->id.')', implode(' | ', $result['errors']));
        $this->assertFalse(Product::query()->where('sku', 'BB014')->exists());
        $this->assertSame($x->id, ProductVariant::query()->where('remote_id', '101')->value('product_id'));
        $this->assertSame($y->id, ProductVariant::query()->where('remote_id', '102')->value('product_id'));
        $this->assertSame(0, ProductVariantPriceHistory::query()->count());
    }

    public function test_code_of_other_manufacturer_card_is_skipped(): void
    {
        Product::query()->create(['sku' => 'BB014', 'name' => 'Karta 3M', 'manufacturer' => '3M', 'catalog_price_net' => 5, 'purchase_price' => 4]);
        $this->sign('BB014', ['101' => 1.00]);

        $result = $this->sync();

        $this->assertSame(1, $result['skipped']);
        $this->assertContains('BB014: kod należy do karty producenta 3M', $result['errors']);
        $this->assertSame(0, ProductVariant::query()->count());
        $this->assertSame('4.00', Product::query()->where('sku', 'BB014')->value('purchase_price'));
        $run = B2bSyncRun::query()->findOrFail($result['sync_run_id']);
        $this->assertSame(1, $run->processed);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->sign('BB014', ['101' => 1.00, '102' => 2.00]);
        $this->connector->listed = ['101', '102'];

        $result = $this->sync(dryRun: true);

        $this->assertSame(1, $result['created']);
        $this->assertNull($result['sync_run_id']);
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, ProductVariant::query()->count());
        $this->assertSame(0, ProductVariantPriceHistory::query()->count());
        $this->assertSame(0, B2bSyncRun::query()->count());
        $this->assertSame(0, B2bProductLink::query()->count());
        $this->assertNull($this->account->fresh()->last_sync_status);
    }

    public function test_size_merge_skips_cards_with_variants(): void
    {
        $withVariants = Product::query()->create([
            'sku' => '37695VP070', 'name' => 'AlphaTec 37695VP Size 7.0', 'manufacturer' => 'Ansell',
            'catalog_price_net' => 2.85, 'purchase_price' => 2.85,
        ]);
        ProductVariant::query()->create([
            'product_id' => $withVariants->id, 'source' => self::SOURCE, 'remote_id' => '1', 'label' => 'wersja',
        ]);
        $plain = Product::query()->create([
            'sku' => '37695VP100', 'name' => 'AlphaTec 37695VP Size 10.0', 'manufacturer' => 'Ansell',
            'catalog_price_net' => 2.85, 'purchase_price' => 2.85,
        ]);

        $result = app(ProductSizeMergeService::class)->merge('Ansell', false);

        $this->assertSame(0, $result['groups']);
        $this->assertNotNull($withVariants->fresh());
        $this->assertNotNull($plain->fresh());
        $this->assertSame(1, ProductVariant::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function sync(bool $dryRun = false, ?int $limit = null): array
    {
        return app(B2bAccountSyncRunner::class)->run(
            $this->account->fresh(),
            limit: $limit,
            dryRun: $dryRun,
            delayMs: 0,
            connector: $this->connector,
        );
    }

    private function syncExpectingFailure(): Throwable
    {
        try {
            $this->sync();
        } catch (Throwable $e) {
            return $e;
        }
        $this->fail('Przebieg miał się zakończyć błędem.');
    }

    /**
     * @param  array<string|int, float|string>  $prices  remote_id => cena konta netto albo tekst błędu pobrania ceny
     */
    private function sign(string $code, array $prices, ?string $key = null): void
    {
        $variants = [];
        $versions = [];
        $i = 0;
        foreach ($prices as $remoteId => $price) {
            $format = self::FORMATS[$i % 2];
            $substrate = self::SUBSTRATES[intdiv($i, 2) % 2];
            $label = $format.' \ '.$substrate;
            $url = 'https://fakesign.example.test/pl/products/znak-'.$remoteId;
            $variants[] = new B2bRemoteVariant(
                remoteId: (string) $remoteId,
                label: $label,
                attributes: ['Format' => $format, 'Podłoże' => $substrate],
                price: is_float($price) ? new B2bRemotePrice($price) : null,
                priceError: is_string($price) ? $price : null,
                sourceUrl: $url,
                sortOrder: $i,
                vatRate: is_float($price) ? 23.0 : null,
                unit: is_float($price) ? 'szt.' : null,
            );
            $versions[] = ['id' => (string) $remoteId, 'name' => $label, 'link' => $url];
            $i++;
        }

        $this->connector->signs[$key ?? $code] = [
            'remote' => new B2bRemoteProduct(
                remoteId: $code,
                sku: $code,
                name: 'Znak '.$code,
                category: 'Znaki › Ewakuacyjne',
                sourceUrl: $versions[0]['link'] ?? null,
                raw: ['versions' => $versions, 'version_header' => 'Format \ Podłoże', 'firm' => 'SignProject'],
            ),
            'variants' => $variants,
        ];
    }
}

final class FakeVariantConnector implements B2bVariantConnector
{
    /**
     * Klucz = kod albo „kod#2” dla drugiej grupy wersji o tym samym kodzie.
     *
     * @var array<string, array{remote: B2bRemoteProduct, variants: list<B2bRemoteVariant>|Throwable}>
     */
    public array $signs = [];

    /** @var list<string>|null */
    public ?array $listed = null;

    public ?int $budget = null;

    public string $descriptionText = '';

    public ?string $descriptionError = null;

    public static function key(): string
    {
        return 'fakesign';
    }

    public static function label(): string
    {
        return 'Fake Sign B2B';
    }

    public static function host(): string
    {
        return 'fakesign.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        foreach ($this->signs as $sign) {
            yield $sign['remote'];
        }
    }

    public function totalProducts(): int
    {
        return count($this->signs);
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'SignProject';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return null;
    }

    public function description(B2bRemoteProduct $product): string
    {
        if ($this->descriptionError !== null) {
            throw new RuntimeException($this->descriptionError);
        }

        return $this->descriptionText;
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }

    public function variants(B2bRemoteProduct $product): array
    {
        $variants = [];
        foreach ($this->signs as $sign) {
            if ($sign['remote'] === $product) {
                $variants = $sign['variants'];
            }
        }
        if ($variants instanceof Throwable) {
            throw $variants;
        }

        return $variants;
    }

    public function totalVariants(): int
    {
        return array_sum(array_map(static fn (array $sign): int => count($sign['remote']->raw['versions']), $this->signs));
    }

    public function listedVariantIds(): ?array
    {
        return $this->listed;
    }

    public function runBudgetMinutes(): ?int
    {
        return $this->budget;
    }
}
