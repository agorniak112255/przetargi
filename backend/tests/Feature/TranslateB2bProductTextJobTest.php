<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\TranslateB2bProductTextJob;
use App\Models\B2bAccount;
use App\Models\B2bAccountManufacturerRule;
use App\Models\B2bProductLink;
use App\Models\PriceList;
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
use Illuminate\Support\Facades\Log;
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
        // trzeci element to nazwa karty w katalogu — kontekst terminologiczny dla modelu
        $this->assertSame([[self::SOURCE_DESCRIPTION, null, self::SOURCE_NAME]], $this->translator->calls);
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
        $this->assertSame([[self::SOURCE_DESCRIPTION, self::SOURCE_NAME, self::SOURCE_NAME]], $this->translator->calls);
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
        $this->assertSame([['', self::SOURCE_NAME, self::SOURCE_NAME]], $this->translator->calls);
    }

    public function test_description_off_in_producers_window_translates_only_name(): void
    {
        [$product, $link] = $this->importedCard();
        // opis producenta wyłączony w oknie „Producenci” już po zleceniu joba
        B2bAccountManufacturerRule::query()->create([
            'b2b_account_id' => $this->account->id,
            'manufacturer' => (string) $product->manufacturer,
            'manufacturer_key' => PriceList::manufacturerKey((string) $product->manufacturer),
            'take_price' => true,
            'take_description' => false,
        ]);

        $this->runJob($product, translateName: true);

        $product->refresh();
        $link->refresh();
        $this->assertSame(self::POLISH_NAME, $product->name);
        $this->assertSame(self::SOURCE_DESCRIPTION, $product->description);
        $this->assertNull($link->source_description_hash);
        $this->assertSame([['', self::SOURCE_NAME, self::SOURCE_NAME]], $this->translator->calls);
    }

    public function test_name_changed_by_hand_is_not_translated(): void
    {
        [$product, $link] = $this->importedCard();
        $product->update(['name' => 'Okulary Tryon — nazwa nadana ręcznie']);

        $this->runJob($product, translateName: true);

        $product->refresh();
        $this->assertSame('Okulary Tryon — nazwa nadana ręcznie', $product->name);
        $this->assertSame(self::POLISH_DESCRIPTION, $product->description);
        // trzeci element to nazwa karty w katalogu — kontekst terminologiczny dla modelu
        $this->assertSame([[self::SOURCE_DESCRIPTION, null, 'Okulary Tryon — nazwa nadana ręcznie']], $this->translator->calls);
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

    public function test_rejection_is_remembered_and_the_same_text_is_not_sent_again(): void
    {
        // 23.09.2026: 7 kart Bolle odrzucanych przy każdym przebiegu (model z temperaturą 0 odpowiada tak samo)
        Log::spy();
        [$product, $link] = $this->importedCard();
        $this->translator->during = static function (): void {
            throw new B2bTranslationRejected('zgubiony token: PVC', ['segments' => ['Oprawka z PCW.']]);
        };

        $this->runJob($product);

        $link->refresh();
        $this->assertSame('zgubiony token: PVC', $link->translation_rejected_reason);
        $this->assertNotNull($link->translation_rejected_hash);
        $this->assertNotNull($link->translation_rejected_at);
        $this->assertNull($link->source_description_hash);
        $this->assertSame(['description' => false, 'name' => false], TranslateB2bProductTextJob::pending($product->fresh(), $link, false));
        Log::shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => $message === 'Tłumaczenie tekstu B2B odrzucone'
            && $context['reason'] === 'zgubiony token: PVC'
            && str_contains((string) ($context['model_response'] ?? ''), 'Oprawka z PCW.'));

        $this->runJob($product);
        $this->assertCount(1, $this->translator->calls, 'ten sam tekst nie idzie drugi raz do modelu');

        // dostawca zmienił tekst — import zapisał nowy opis i jego odcisk: znów do tłumaczenia
        $newSource = 'Clear lens, PVC frame. EN 166.';
        $product->update(['description' => $newSource]);
        $link->update(['description_hash' => sha1($newSource)]);
        $this->assertTrue(TranslateB2bProductTextJob::pending($product->fresh(), $link->fresh(), false)['description']);
        $this->runJob($product);
        $this->assertCount(2, $this->translator->calls);
    }

    public function test_successful_translation_clears_earlier_rejection(): void
    {
        [$product, $link] = $this->importedCard();
        // odrzucenie wcześniejszego tekstu — ten na karcie jest inny, więc idzie do tłumaczenia
        $link->update([
            'translation_rejected_hash' => sha1('inny tekst'),
            'translation_rejected_reason' => 'zgubiony token: THE',
            'translation_rejected_at' => now(),
        ]);

        $this->runJob($product);

        $link->refresh();
        $this->assertSame(self::POLISH_DESCRIPTION, $product->fresh()->description);
        $this->assertNull($link->translation_rejected_hash);
        $this->assertNull($link->translation_rejected_reason);
        $this->assertNull($link->translation_rejected_at);
    }

    public function test_rejection_is_not_recorded_when_import_saved_new_text_meanwhile(): void
    {
        [$product, $link] = $this->importedCard();
        $this->translator->during = static function () use ($link): void {
            $link->update(['description_hash' => sha1('nowy tekst ze sklepu')]);
            throw new B2bTranslationRejected('inna liczba segmentów: w źródle 3, w odpowiedzi 6');
        };

        $this->runJob($product);

        $this->assertNull($link->fresh()->translation_rejected_hash);
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

    public function translate(string $description, ?string $name = null, ?string $cardName = null): array
    {
        $this->calls[] = [$description, $name, $cardName];
        if ($this->during !== null) {
            ($this->during)();
        }

        return [
            'description' => $description === '' ? '' : 'Soczewka miedziana, powłoka przeciwmgielna i odporna na zarysowania. EN 166, EN 170.',
            'name' => $name === null ? null : 'TRYON BSSI – okulary ochronne, soczewka miedziana',
        ];
    }
}
