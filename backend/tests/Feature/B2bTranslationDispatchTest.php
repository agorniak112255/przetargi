<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\TranslateB2bProductTextJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bForeignLanguageSource;
use App\Services\B2b\B2bKeepsExistingNames;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Import B2B łącznika B2bForeignLanguageSource zleca TranslateB2bProductTextJob po zapisie opisu ze źródła
 * (i przy nowej karcie łącznika B2bKeepsExistingNames — z nazwą). Niezmiennik hashy powiązania: tłumaczenie
 * niezmienionego źródła, nietknięte ręcznie, nie jest nadpisywane oryginałem (decyzja 15.09.2026).
 */
final class B2bTranslationDispatchTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_NAME = 'TRYON BSSI – Copper safety glasses';

    private const POLISH_NAME = 'Okulary ochronne Tryon BSSI, soczewka miedziana';

    private const SOURCE_DESCRIPTION = 'Copper safety glasses with anti-fog coating.';

    private const TRANSLATION = 'Polski opis';

    private B2bAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $user = User::factory()->withRole('admin')->create();
        $this->account = B2bAccount::query()->create([
            'username' => 'jan',
            'password' => 'sekret',
            'sites' => [TranslationPlainConnector::host()],
            'connector' => TranslationPlainConnector::key(),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    public function test_new_card_of_foreign_connector_keeping_names_queues_translation_with_name(): void
    {
        $shop = $this->shop(new TranslationForeignConnector);

        $result = $this->runSync($shop);

        $product = Product::query()->where('sku', 'BOL-1')->sole();
        $this->assertSame(1, $result['created']);
        // import zapisuje tekst źródła; tłumaczy dopiero job
        $this->assertSame(self::SOURCE_NAME, $product->name);
        $this->assertSame(self::SOURCE_DESCRIPTION, $product->description);
        $link = $this->link();
        $this->assertSame(self::SOURCE_NAME, $link->remote_name);
        $this->assertSame(sha1(self::SOURCE_DESCRIPTION), $link->description_hash);
        $this->assertNull($link->source_description_hash);
        $this->assertSame(1, $shop->descriptionCalls);
        Queue::assertPushed(TranslateB2bProductTextJob::class, 1);
        Queue::assertPushed(
            TranslateB2bProductTextJob::class,
            fn (TranslateB2bProductTextJob $job): bool => $job->productId === (int) $product->id
                && $job->b2bAccountId === (int) $this->account->id
                && $job->translateName === true,
        );
        Queue::assertPushedOn(TranslateB2bProductTextJob::QUEUE, TranslateB2bProductTextJob::class);
    }

    public function test_existing_card_with_polish_name_gets_description_and_translation_without_name(): void
    {
        $existing = $this->existingCard();
        $shop = $this->shop(new TranslationForeignConnector);

        $result = $this->runSync($shop);

        $existing->refresh();
        $this->assertSame(1, $result['updated']);
        $this->assertSame(self::POLISH_NAME, $existing->name);
        $this->assertSame(self::SOURCE_DESCRIPTION, $existing->description);
        $this->assertSame(self::SOURCE_NAME, $this->link()->remote_name);
        Queue::assertPushed(
            TranslateB2bProductTextJob::class,
            fn (TranslateB2bProductTextJob $job): bool => $job->productId === (int) $existing->id && $job->translateName === false,
        );
    }

    public function test_dry_run_queues_nothing(): void
    {
        $result = app(B2bAccountSyncRunner::class)->run(
            $this->account->fresh(),
            dryRun: true,
            delayMs: 0,
            connector: $this->shop(new TranslationForeignConnector),
        );

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['translations_queued']);
        $this->assertSame(0, Product::query()->count());
        Queue::assertNotPushed(TranslateB2bProductTextJob::class);
    }

    public function test_connector_without_foreign_language_marker_queues_nothing(): void
    {
        $result = $this->runSync($this->shop(new TranslationPlainConnector));

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['translations_queued']);
        $this->assertSame(self::SOURCE_DESCRIPTION, Product::query()->where('sku', 'BOL-1')->sole()->description);
        $this->assertSame(self::SOURCE_NAME, $this->link()->remote_name);
        Queue::assertNotPushed(TranslateB2bProductTextJob::class);
    }

    public function test_translated_description_of_unchanged_source_is_kept(): void
    {
        $shop = $this->shop(new TranslationForeignConnector);
        $this->runSync($shop);
        $product = $this->simulateTranslation();
        Queue::fake();

        $second = $this->runSync($shop);

        $product->refresh();
        $link = $this->link();
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(0, $second['translations_queued']);
        $this->assertSame(self::TRANSLATION, $product->description);
        $this->assertSame(sha1(self::TRANSLATION), $link->description_hash);
        $this->assertSame(sha1(self::SOURCE_DESCRIPTION), $link->source_description_hash);
        Queue::assertNotPushed(TranslateB2bProductTextJob::class);
    }

    public function test_changed_source_replaces_translation_and_queues_again(): void
    {
        $shop = $this->shop(new TranslationForeignConnector);
        $this->runSync($shop);
        $product = $this->simulateTranslation();
        Queue::fake();
        $shop->description = 'Copper safety glasses, new coating.';

        $second = $this->runSync($shop);

        $product->refresh();
        $link = $this->link();
        $this->assertSame(1, $second['updated']);
        $this->assertSame(1, $second['translations_queued']);
        $this->assertSame('Copper safety glasses, new coating.', $product->description);
        $this->assertSame(sha1('Copper safety glasses, new coating.'), $link->description_hash);
        $this->assertNull($link->source_description_hash);
        // nazwa istniejącej karty zostaje, więc job bez nazwy
        Queue::assertPushed(
            TranslateB2bProductTextJob::class,
            fn (TranslateB2bProductTextJob $job): bool => $job->productId === (int) $product->id && $job->translateName === false,
        );
    }

    public function test_manually_edited_description_is_not_overwritten_and_not_queued(): void
    {
        $shop = $this->shop(new TranslationForeignConnector);
        $this->runSync($shop);
        $product = $this->simulateTranslation();
        // ≥ 24 znaki — krótszy opis Product::hasDescriptionText() uznaje za brak opisu (wtedy import go uzupełnia)
        $product->forceFill(['description' => 'Opis poprawiony ręcznie przez dział zakupów'])->save();
        Queue::fake();
        $shop->description = 'Copper safety glasses, new coating.';

        $second = $this->runSync($shop);

        $product->refresh();
        $link = $this->link();
        $this->assertSame(0, $second['translations_queued']);
        $this->assertSame('Opis poprawiony ręcznie przez dział zakupów', $product->description);
        $this->assertSame(sha1(self::TRANSLATION), $link->description_hash);
        $this->assertSame(sha1(self::SOURCE_DESCRIPTION), $link->source_description_hash);
        Queue::assertNotPushed(TranslateB2bProductTextJob::class);
    }

    public function test_run_result_and_log_count_queued_translations(): void
    {
        $shop = new TranslationForeignConnector;
        $shop->items = [
            '1' => ['sku' => 'BOL-1', 'name' => self::SOURCE_NAME],
            '2' => ['sku' => 'BOL-2', 'name' => self::SOURCE_NAME.' 2'],
        ];

        $result = $this->runSync($shop);

        $this->assertSame(2, $result['translations_queued']);
        Queue::assertPushed(TranslateB2bProductTextJob::class, 2);
        $this->assertTrue($this->logHas($result, 'Opisy zlecone do tłumaczenia na polski: 2'));

        // drugi przebieg: nic nowego do tłumaczenia — licznik 0 i bez linii dziennika
        Queue::fake();
        $second = $this->runSync($shop);

        $this->assertSame(0, $second['translations_queued']);
        $this->assertFalse($this->logHas($second, 'Opisy zlecone do tłumaczenia na polski'));
    }

    /**
     * @return array<string, mixed>
     */
    private function runSync(B2bConnector $connector): array
    {
        return app(B2bAccountSyncRunner::class)->run($this->account->fresh(), delayMs: 0, connector: $connector);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function logHas(array $result, string $text): bool
    {
        $run = B2bSyncRun::query()->findOrFail($result['sync_run_id']);

        return collect($run->log)->contains(
            static fn (array $line): bool => $line['level'] === 'info' && str_starts_with($line['text'], $text),
        );
    }

    /** Stan po wykonanym jobie tłumaczenia: opis karty i hashe powiązania ustawione ręcznie. */
    private function simulateTranslation(): Product
    {
        $product = Product::query()->where('sku', 'BOL-1')->sole();
        $product->forceFill(['description' => self::TRANSLATION])->save();
        $this->link()->forceFill([
            'description_hash' => sha1(self::TRANSLATION),
            'source_description_hash' => sha1(self::SOURCE_DESCRIPTION),
        ])->save();

        return $product;
    }

    private function link(): B2bProductLink
    {
        return B2bProductLink::query()->where('b2b_account_id', $this->account->id)->where('remote_id', '1')->sole();
    }

    private function shop(TranslationPlainConnector $shop): TranslationPlainConnector
    {
        $shop->items = ['1' => ['sku' => 'BOL-1', 'name' => self::SOURCE_NAME]];

        return $shop;
    }

    private function existingCard(): Product
    {
        return Product::query()->create([
            'sku' => 'BOL-1',
            'name' => self::POLISH_NAME,
            'manufacturer' => 'Testowy',
            'catalog_price_net' => 60.00,
            'purchase_price' => 50.00,
            'discount_percent' => 0,
            'currency' => 'PLN',
        ]);
    }
}

/** Łącznik testowy bez sieci: stałe produkty, cena zakupu 50, katalogowa 60, ten sam opis dla każdej pozycji. */
class TranslationPlainConnector implements B2bConnector
{
    /** @var array<string, array{sku: string, name: string}> */
    public array $items = [];

    public string $description = 'Copper safety glasses with anti-fog coating.';

    public int $descriptionCalls = 0;

    public static function key(): string
    {
        return 'translationtest';
    }

    public static function label(): string
    {
        return 'Testowy';
    }

    public static function host(): string
    {
        return 'translation.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new static;
    }

    public function login(): void {}

    public function products(): iterable
    {
        foreach ($this->items as $id => $item) {
            yield new B2bRemoteProduct(remoteId: (string) $id, sku: $item['sku'], name: $item['name']);
        }
    }

    public function totalProducts(): int
    {
        return count($this->items);
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'Testowy';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: 50.0, base: 60.0);
    }

    public function description(B2bRemoteProduct $product): string
    {
        $this->descriptionCalls++;

        return $this->description;
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }
}

final class TranslationForeignConnector extends TranslationPlainConnector implements B2bForeignLanguageSource, B2bKeepsExistingNames {}
