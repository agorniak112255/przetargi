<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\TranslateB2bProductTextJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\User;
use App\Services\B2b\B2bTextTranslator;
use App\Services\B2b\B2bTranslationRejected;
use App\Services\B2b\BolleB2bConnector;
use App\Services\Enrichment\EnrichmentSlots;
use Closure;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Job tłumaczenia tekstów z importu B2B: tylko tekst zapisany przez import i nietknięty, zapis compare-and-set,
 * niezmiennik hashy linku, odrzucenie bez ponawiania, slot limitu AI.
 */
final class TranslateB2bProductTextJobTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_DESCRIPTION = 'Copper lens, anti-fog and anti-scratch coating. EN 166, EN 170.';

    private const POLISH_DESCRIPTION = 'Soczewka miedziana, powłoka przeciwmgielna i odporna na zarysowania. EN 166, EN 170.';

    private const SOURCE_NAME = 'TRYON BSSI – Copper safety glasses';

    private const POLISH_NAME = 'TRYON BSSI – okulary ochronne, soczewka miedziana';

    private B2bAccount $account;

    private FakeB2bTextTranslator $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $user = User::factory()->withRole('admin')->create();
        $this->account = B2bAccount::query()->create([
            'username' => 'jan',
            'password' => 'sekret',
            'sites' => [BolleB2bConnector::host()],
            'connector' => BolleB2bConnector::key(),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $this->translator = new FakeB2bTextTranslator;
        $this->app->instance(B2bTextTranslator::class, $this->translator);
    }

    public function test_translates_description_and_sets_both_hashes_without_touching_name(): void
    {
        [$product, $link] = $this->importedCard();

        $this->runJob($product);

        $product->refresh();
        $link->refresh();
        $this->assertSame(self::POLISH_DESCRIPTION, $product->description);
        $this->assertSame(self::SOURCE_NAME, $product->name);
        $this->assertSame(sha1(self::POLISH_DESCRIPTION), $link->description_hash);
        $this->assertSame(sha1(self::SOURCE_DESCRIPTION), $link->source_description_hash);
        $this->assertSame(self::SOURCE_NAME, $link->remote_name);
        $this->assertSame([[self::SOURCE_DESCRIPTION, null]], $this->translator->calls);
        // haki modelu przebudowały indeks tekstowy (małe litery, bez polskich znaków)
        $this->assertStringContainsString('soczewka miedziana, powloka', (string) $product->search_blob);
    }

    public function test_translates_name_of_new_card_when_it_equals_remote_name(): void
    {
        [$product, $link] = $this->importedCard();

        $this->runJob($product, translateName: true);

        $product->refresh();
        $link->refresh();
        $this->assertSame(self::POLISH_NAME, $product->name);
        $this->assertSame(self::POLISH_DESCRIPTION, $product->description);
        $this->assertSame(self::SOURCE_NAME, $link->remote_name, 'Nazwa u dostawcy zostaje jako proweniencja');
        $this->assertSame([[self::SOURCE_DESCRIPTION, self::SOURCE_NAME]], $this->translator->calls);
    }

    public function test_translates_only_name_when_description_was_edited(): void
    {
        [$product, $link] = $this->importedCard();
        $product->update(['description' => 'Opis poprawiony ręcznie']);

        $this->runJob($product, translateName: true);

        $product->refresh();
        $link->refresh();
        $this->assertSame(self::POLISH_NAME, $product->name);
        $this->assertSame('Opis poprawiony ręcznie', $product->description);
        $this->assertSame(sha1(self::SOURCE_DESCRIPTION), $link->description_hash);
        $this->assertNull($link->source_description_hash, 'Opis karty nie jest tłumaczeniem');
        $this->assertSame([['', self::SOURCE_NAME]], $this->translator->calls);
    }

    public function test_name_changed_by_hand_is_not_translated(): void
    {
        [$product, $link] = $this->importedCard();
        $product->update(['name' => 'Okulary Tryon — nazwa nadana ręcznie']);

        $this->runJob($product, translateName: true);

        $product->refresh();
        $this->assertSame('Okulary Tryon — nazwa nadana ręcznie', $product->name);
        $this->assertSame(self::POLISH_DESCRIPTION, $product->description);
        $this->assertSame([[self::SOURCE_DESCRIPTION, null]], $this->translator->calls);
    }

    public function test_description_edited_by_hand_is_not_sent_to_translator(): void
    {
        [$product, $link] = $this->importedCard();
        $product->update(['description' => 'Opis poprawiony ręcznie']);

        $this->runJob($product);

        $this->assertSame([], $this->translator->calls);
        $this->assertSame('Opis poprawiony ręcznie', $product->fresh()->description);
        $this->assertNull($link->fresh()->source_description_hash);
    }

    public function test_already_translated_card_is_not_sent_to_translator(): void
    {
        [$product, $link] = $this->importedCard();
        $link->update([
            'description_hash' => sha1(self::POLISH_DESCRIPTION),
            'source_description_hash' => sha1(self::SOURCE_DESCRIPTION),
        ]);
        $product->update(['description' => self::POLISH_DESCRIPTION]);

        $this->runJob($product, translateName: true);

        $this->assertSame([], $this->translator->calls);
        $this->assertSame(self::SOURCE_NAME, $product->fresh()->name);
    }

    public function test_description_changed_while_model_answers_is_not_overwritten(): void
    {
        [$product, $link] = $this->importedCard();
        $this->translator->during = static function () use ($product): void {
            Product::query()->findOrFail($product->id)->update(['description' => 'Opis poprawiony w trakcie']);
        };

        $this->runJob($product, translateName: true);

        $product->refresh();
        $link->refresh();
        $this->assertCount(1, $this->translator->calls);
        $this->assertSame('Opis poprawiony w trakcie', $product->description);
        $this->assertSame(self::SOURCE_NAME, $product->name, 'Compare-and-set odrzuca cały zapis');
        $this->assertSame(sha1(self::SOURCE_DESCRIPTION), $link->description_hash);
        $this->assertNull($link->source_description_hash);
    }

    public function test_rejected_translation_leaves_card_unchanged_without_exception(): void
    {
        [$product, $link] = $this->importedCard();
        $this->translator->during = static function (): void {
            throw new B2bTranslationRejected('zgubiona norma EN 170');
        };

        $this->runJob($product, translateName: true);

        $product->refresh();
        $link->refresh();
        $this->assertSame(self::SOURCE_DESCRIPTION, $product->description);
        $this->assertSame(self::SOURCE_NAME, $product->name);
        $this->assertSame(sha1(self::SOURCE_DESCRIPTION), $link->description_hash);
        $this->assertNull($link->source_description_hash);
    }

    public function test_model_error_propagates_and_releases_slot(): void
    {
        $this->setConcurrency(1);
        [$product] = $this->importedCard();
        $this->translator->during = static function (): void {
            throw new RuntimeException('HTTP 429');
        };

        try {
            $this->runJob($product);
            $this->fail('Błąd modelu musi wyjść z joba (ponowienie)');
        } catch (RuntimeException $e) {
            $this->assertSame('HTTP 429', $e->getMessage());
        }

        $this->assertSame(self::SOURCE_DESCRIPTION, $product->fresh()->description);
        $this->assertNotNull(app(EnrichmentSlots::class)->acquire(60, 0.0), 'Slot zwolniony mimo błędu');
    }

    public function test_requeues_with_delay_when_all_slots_busy(): void
    {
        $this->setConcurrency(1);
        $busy = app(EnrichmentSlots::class)->acquire(600, 0.0);
        $this->assertNotNull($busy);
        config(['ai.enrichment_slot_wait_seconds' => 0]);
        [$product] = $this->importedCard();

        $this->runJob($product, translateName: true);

        $this->assertSame([], $this->translator->calls);
        $this->assertSame(self::SOURCE_DESCRIPTION, $product->fresh()->description);
        Queue::assertPushedOn(TranslateB2bProductTextJob::QUEUE, TranslateB2bProductTextJob::class);
        Queue::assertPushed(
            TranslateB2bProductTextJob::class,
            fn (TranslateB2bProductTextJob $job): bool => $job->productId === $product->id
                && $job->b2bAccountId === $this->account->id
                && $job->translateName
                && $job->delay !== null,
        );
        $busy->release();
    }

    private function runJob(Product $product, bool $translateName = false): void
    {
        app()->call([new TranslateB2bProductTextJob((int) $product->id, (int) $this->account->id, $translateName), 'handle']);
    }

    /**
     * Karta i powiązanie tak, jak zostawia je import: opis i nazwa ze źródła, hash opisu, nazwa u dostawcy.
     *
     * @return array{0: Product, 1: B2bProductLink}
     */
    private function importedCard(): array
    {
        $product = Product::query()->create([
            'sku' => 'BOL-1',
            'name' => self::SOURCE_NAME,
            'description' => self::SOURCE_DESCRIPTION,
            'manufacturer' => 'Bollé Safety',
            'catalog_price_net' => 60.00,
            'purchase_price' => 50.00,
            'discount_percent' => 0,
            'currency' => 'PLN',
        ]);
        $link = B2bProductLink::query()->create([
            'b2b_account_id' => $this->account->id,
            'remote_id' => '1',
            'product_id' => $product->id,
            'remote_sku' => 'BOL-1',
            'remote_name' => self::SOURCE_NAME,
            'description_hash' => sha1(self::SOURCE_DESCRIPTION),
            'last_seen_at' => now(),
        ]);

        return [$product, $link];
    }

    private function setConcurrency(int $value): void
    {
        DB::table('ai_settings')->updateOrInsert(
            ['id' => 1],
            ['match_concurrency' => $value, 'updated_at' => now(), 'created_at' => now()],
        );
    }
}

/** Atrapa tłumacza: stałe polskie teksty, zapis wywołań, opcjonalny krok „w trakcie odpowiedzi modelu”. */
final class FakeB2bTextTranslator extends B2bTextTranslator
{
    /** @var list<array{0: string, 1: string|null}> */
    public array $calls = [];

    public ?Closure $during = null;

    /** Bez klienta modelu — translate() jest w całości podmienione. */
    public function __construct() {}

    public function translate(string $description, ?string $name = null): array
    {
        $this->calls[] = [$description, $name];
        if ($this->during !== null) {
            ($this->during)();
        }

        return [
            'description' => $description === '' ? '' : 'Soczewka miedziana, powłoka przeciwmgielna i odporna na zarysowania. EN 166, EN 170.',
            'name' => $name === null ? null : 'TRYON BSSI – okulary ochronne, soczewka miedziana',
        ];
    }
}
