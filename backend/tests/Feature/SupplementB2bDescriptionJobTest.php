<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SupplementB2bDescriptionJob;
use App\Models\B2bAccount;
use App\Models\B2bDescriptionSupplementAttempt;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bDescriptionSource;
use App\Services\B2b\B2bDescriptionSupplement;
use App\Services\B2b\B2bForeignLanguageSource;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bSupplementContext;
use App\Services\Enrichment\B2bSourcesDescriptionRejected;
use App\Services\Enrichment\B2bSupplementNoPages;
use App\Services\Enrichment\ProductEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * SupplementB2bDescriptionJob — zapis uzupełnionego opisu (compare-and-set), ślady prób i niezmiennik synchronizacji:
 * ten sam tekst u dostawcy zostawia uzupełniony opis, nowy tekst wraca na kartę. ProductEnrichmentService jest final,
 * więc atrapa Mockery podmienia go w kontenerze (job bierze usługę z kontenera). Dane SYNTETYCZNE.
 */
final class SupplementB2bDescriptionJobTest extends TestCase
{
    use RefreshDatabase;

    private const SHORT = 'Rękawica ochronna nitrylowa, kategoria II, dostępna w rozmiarach 7–11.';

    private const AI_TEXT = 'Rękawica ochronna z wkładki nylonowej powlekanej nitrylem, kategoria II. Powłoka zapewnia pewny chwyt '
        .'na suchych i lekko zaolejonych powierzchniach, a ściągacz chroni przed zanieczyszczeniami. Przeznaczona do prac '
        .'montażowych i magazynowych. Dostępna w rozmiarach 7–11.';

    private const PAGE_URL = 'https://b2b.procera.pl/produkt/rekawica-123';

    private const WEB_URL = 'https://www.ansell.com/pl/pl/products/rekawica-123';

    private B2bAccount $account;

    /** @var MockInterface&ProductEnrichmentService */
    private MockInterface $enrichment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = B2bAccount::query()->create([
            'username' => 'konto-procera',
            'password' => 'haslo',
            'sites' => ['b2b.procera.pl'],
            'connector' => 'procera',
            'enrichment_sites' => ['ansell.com'],
        ]);
        $this->enrichment = Mockery::mock();
        $this->app->instance(ProductEnrichmentService::class, $this->enrichment);
    }

    public function test_replaces_short_description_with_trace_and_link_hashes(): void
    {
        $card = $this->card(self::SHORT, [
            'shop_source_url' => self::PAGE_URL,
            'norms' => 'EN 420',
            'enrichment_payload' => [
                'features' => ['stara cecha'],
                'attributes' => ['kategoria_bhp' => 'ochrona_oczu'],
            ],
        ]);
        $links = [$this->link($card, 'a', sha1(self::SHORT)), $this->link($card, 'b', sha1(self::SHORT))];
        $this->queued($card);
        $this->enrichment->shouldReceive('supplementB2bDescription')
            ->once()
            ->withArgs(fn (Product $product, B2bSupplementContext $context): bool => (int) $product->id === (int) $card->id
                && $context->b2bText === self::SHORT
                && $context->b2bUrl === self::PAGE_URL
                && $context->hosts === ['ansell.com'])
            ->andReturn($this->modelResult());

        $this->runJob($card);

        $card->refresh();
        $this->assertSame(self::AI_TEXT, $card->description);
        // stan wzbogacania i kolumna norm bez zmian
        $this->assertSame(Product::ENRICHMENT_NONE, $card->enrichment_status);
        $this->assertSame('EN 420', $card->norms);
        $payload = (array) $card->enrichment_payload;
        $this->assertSame(['powłoka nitrylowa'], $payload['features']);
        $this->assertSame([self::PAGE_URL, self::WEB_URL], $payload['source_urls']);
        $this->assertSame('b2b_supplement', $payload['primary_source_kind']);
        $this->assertSame(self::SHORT, $payload['replaced_description']);
        $this->assertSame(sha1(self::AI_TEXT), $payload['replaced_description_hash']);
        $trace = $payload['b2b_supplement'];
        $this->assertSame((int) $this->account->id, $trace['b2b_account_id']);
        $this->assertSame(self::SHORT, $trace['b2b_text']);
        $this->assertSame(sha1(self::SHORT), $trace['source_sha1']);
        $this->assertSame(['ansell.com'], $trace['hosts']);
        $this->assertSame([self::WEB_URL], $trace['web_source_urls']);
        $this->assertSame(['EN 388 4131X'], $trace['dropped']);
        $this->assertSame(['odporność na przecięcie'], $trace['dropped_claims']);
        $this->assertSame(sha1(self::AI_TEXT), $trace['result_sha1']);
        $this->assertNotEmpty($trace['described_at']);
        // atrybuty liczone od nowa z karty po zmianie, nie przepisane ze starego payloadu
        $this->assertIsArray($payload['attributes']);
        $this->assertNotSame('ochrona_oczu', $payload['attributes']['kategoria_bhp'] ?? null);

        foreach ($links as $link) {
            $link->refresh();
            $this->assertSame(sha1(self::AI_TEXT), $link->description_hash);
            $this->assertSame(sha1(self::SHORT), $link->source_description_hash);
        }
        // opis wciąż liczy się jako „z B2B” — zbiorcze AI kartę omija
        $this->assertArrayHasKey((int) $card->id, app(B2bDescriptionSource::class)->productIds([(int) $card->id]));

        $attempt = $this->attemptOf($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_REPLACED, $attempt->status);
        $this->assertSame(1, $attempt->attempts);
        $this->assertSame(sha1(self::AI_TEXT), $attempt->result_sha1);
        $this->assertSame([self::PAGE_URL, self::WEB_URL], $attempt->source_urls);
        $this->assertNotNull($attempt->attempted_at);
        $this->assertNull($attempt->message);
        // opis już nie jest tekstem z B2B — karta nie wraca do uzupełniania
        $this->assertSame([], app(B2bDescriptionSupplement::class)->candidateIds($this->account, false));
    }

    public function test_existing_replaced_description_is_not_overwritten(): void
    {
        $card = $this->card(self::SHORT, ['enrichment_payload' => [
            'replaced_description' => 'Opis sprzed tekstu z B2B',
            'replaced_description_hash' => sha1(self::SHORT),
        ]]);
        $this->link($card, 'a', sha1(self::SHORT));
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()->andReturn($this->modelResult());

        $this->runJob($card);

        $payload = (array) $card->refresh()->enrichment_payload;
        $this->assertSame(self::AI_TEXT, $card->description);
        $this->assertSame('Opis sprzed tekstu z B2B', $payload['replaced_description']);
        $this->assertSame(sha1(self::SHORT), $payload['replaced_description_hash']);
        $this->assertSame(self::SHORT, $payload['b2b_supplement']['b2b_text']);
        // job zlecony bez próby (poza queue()) zakłada ją sam
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_REPLACED, $this->attemptOf($card)->status);
    }

    public function test_rejected_description_keeps_the_card_untouched(): void
    {
        [$card, $before] = $this->untouchedSetup();
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()
            ->andThrow(new B2bSourcesDescriptionRejected('opis nie jest dłuższy niż tekst B2B'));

        $this->runJob($card);

        $this->assertUntouched($card, $before);
        $attempt = $this->attemptOf($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_KEPT, $attempt->status);
        $this->assertSame('opis nie jest dłuższy niż tekst B2B', $attempt->message);
        $this->assertSame(1, $attempt->attempts);
        $this->assertNull($attempt->result_sha1);
        $this->assertNotNull($attempt->attempted_at);
    }

    public function test_no_pages_keeps_the_card_untouched(): void
    {
        [$card, $before] = $this->untouchedSetup();
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()
            ->andThrow(new B2bSupplementNoPages('brak potwierdzonych stron wyrobu'));

        $this->runJob($card);

        $this->assertUntouched($card, $before);
        $attempt = $this->attemptOf($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_NO_PAGES, $attempt->status);
        $this->assertSame('brak potwierdzonych stron wyrobu', $attempt->message);
        $this->assertSame(1, $attempt->attempts);
        // wynik ostateczny dla tego wejścia — kolejny przebieg karty nie zleca
        $this->assertSame([], app(B2bDescriptionSupplement::class)->candidateIds($this->account));
        $this->assertSame([(int) $card->id], app(B2bDescriptionSupplement::class)->candidateIds($this->account, false));
    }

    public function test_error_records_failed_attempt_and_is_rethrown(): void
    {
        [$card, $before] = $this->untouchedSetup();
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()
            ->andThrow(new RuntimeException('model odpowiedział 500'));

        try {
            $this->runJob($card);
            $this->fail('wyjątek miał polecieć dalej');
        } catch (RuntimeException $e) {
            $this->assertSame('model odpowiedział 500', $e->getMessage());
        }

        $this->assertUntouched($card, $before);
        $attempt = $this->attemptOf($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('model odpowiedział 500', $attempt->message);
        $this->assertSame(1, $attempt->attempts);
        // do ponowienia
        $this->assertSame([(int) $card->id], app(B2bDescriptionSupplement::class)->candidateIds($this->account));
    }

    public function test_card_no_longer_eligible_closes_the_attempt_without_model(): void
    {
        [$card] = $this->untouchedSetup();
        // opis poprawiony ręcznie po zleceniu
        $card->forceFill(['description' => 'Opis poprawiony ręcznie przez dział zakupów.'])->save();
        $this->enrichment->shouldNotReceive('supplementB2bDescription');

        $this->runJob($card);

        $this->assertSame('Opis poprawiony ręcznie przez dział zakupów.', $card->refresh()->description);
        $this->assertSame(sha1(self::SHORT), B2bProductLink::query()->where('product_id', $card->id)->value('description_hash'));
        $attempt = $this->attemptOf($card);
        // nie wynik ostateczny — powód bywa chwilowy, kolejne zlecenie sprawdzi kartę od nowa
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(SupplementB2bDescriptionJob::NOT_ELIGIBLE_MESSAGE, $attempt->message);
        $this->assertSame(0, $attempt->attempts);
        $this->assertSame(sha1(self::SHORT), $attempt->source_sha1);
    }

    public function test_description_changed_while_the_model_answered_is_not_overwritten(): void
    {
        [$card] = $this->untouchedSetup();
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()
            ->andReturnUsing(function (Product $product): array {
                Product::query()->whereKey($product->id)->update(['description' => 'Opis poprawiony ręcznie w trakcie.']);

                return $this->modelResult();
            });

        $this->runJob($card);

        $card->refresh();
        $this->assertSame('Opis poprawiony ręcznie w trakcie.', $card->description);
        $this->assertArrayNotHasKey('b2b_supplement', (array) $card->enrichment_payload);
        $link = B2bProductLink::query()->where('product_id', $card->id)->sole();
        $this->assertSame(sha1(self::SHORT), $link->description_hash);
        $this->assertNull($link->source_description_hash);
        $attempt = $this->attemptOf($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_FAILED, $attempt->status);
        $this->assertStringContainsString('opis karty zmieniony', (string) $attempt->message);
    }

    public function test_sync_writing_new_text_while_the_model_answered_wins(): void
    {
        [$card] = $this->untouchedSetup();
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()
            ->andReturnUsing(function (Product $product): array {
                B2bProductLink::query()->where('product_id', $product->id)->update(['description_hash' => sha1('nowy tekst')]);

                return $this->modelResult();
            });

        $this->runJob($card);

        $this->assertSame(self::SHORT, $card->refresh()->description);
        $this->assertStringContainsString('import zapisał nowy opis', (string) $this->attemptOf($card)->message);
    }

    /**
     * Dystrybutor: synchronizacja zleca uzupełnienie (kolejka w testach jest synchroniczna), ten sam tekst u dostawcy
     * zostawia uzupełniony opis bez nowego pytania modelu, nowy tekst wraca na kartę i karta znowu czeka.
     */
    public function test_sync_keeps_supplement_for_same_source_and_restores_changed_source(): void
    {
        $shop = new SupplementSyncConnector;
        $shop->description = self::SHORT;
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()->andReturn($this->modelResult());

        $this->sync($shop);

        $card = Product::query()->where('sku', 'SUP-1')->sole();
        $this->assertSame(self::AI_TEXT, $card->description);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_REPLACED, $this->attemptOf($card)->status);

        // ten sam tekst u dostawcy: opis zostaje, model niepytany (once), powiązanie bez zmian
        $this->sync($shop);

        $this->assertSame(self::AI_TEXT, $card->refresh()->description);
        $link = B2bProductLink::query()->where('product_id', $card->id)->sole();
        $this->assertSame(sha1(self::AI_TEXT), $link->description_hash);
        $this->assertSame(sha1(self::SHORT), $link->source_description_hash);

        // nowy tekst u dostawcy wraca na kartę; uzupełniony opis zostaje w replaced_description i w śladzie
        Queue::fake();
        $shop->description = 'Rękawica nitrylowa kat. II — nowy opis w sklepie dostawcy.';
        $this->sync($shop);

        $card->refresh();
        $this->assertSame($shop->description, $card->description);
        $payload = (array) $card->enrichment_payload;
        $this->assertSame(self::AI_TEXT, $payload['replaced_description']);
        $this->assertSame(sha1(self::AI_TEXT), $payload['b2b_supplement']['result_sha1']);
        $link->refresh();
        $this->assertSame(sha1($shop->description), $link->description_hash);
        $this->assertNull($link->source_description_hash);
        // nowy tekst = nowe wejście: karta znowu zlecona
        Queue::assertPushed(SupplementB2bDescriptionJob::class, fn (SupplementB2bDescriptionJob $job): bool => $job->productId === (int) $card->id);
        $attempt = $this->attemptOf($card);
        $this->assertSame(B2bDescriptionSupplementAttempt::STATUS_QUEUED, $attempt->status);
        $this->assertSame(sha1($shop->description), $attempt->source_sha1);
    }

    /**
     * Witryna producenta z obcojęzycznym tekstem (jak Bolle): opis producenta zastępuje każdy opis karty, ale
     * uzupełnienie przetłumaczonego tekstu zostaje, dopóki tekst źródła się nie zmieni (keepsTranslation).
     */
    public function test_manufacturer_site_keeps_supplement_of_translation_until_source_changes(): void
    {
        $this->account->forceFill(['connector' => 'bolle', 'sites' => ['bolle-safety.com']])->save();
        $shop = new SupplementManufacturerConnector;
        $english = 'Nitrile coated safety glove, category II.';
        $shop->description = $english;
        Queue::fake();

        $this->sync($shop);

        $card = Product::query()->where('sku', 'SUP-1')->sole();
        $this->assertSame($english, $card->description);
        // tekst obcojęzyczny przed tłumaczeniem nie jest uzupełniany
        Queue::assertNotPushed(SupplementB2bDescriptionJob::class);

        // stan po TranslateB2bProductTextJob
        $card->forceFill(['description' => self::SHORT])->save();
        B2bProductLink::query()->where('product_id', $card->id)->update([
            'description_hash' => sha1(self::SHORT),
            'source_description_hash' => sha1($english),
        ]);
        $this->enrichment->shouldReceive('supplementB2bDescription')->once()
            ->withArgs(fn (Product $product, B2bSupplementContext $context): bool => $context->sourceSha1 === sha1($english))
            ->andReturn($this->modelResult());
        app(B2bDescriptionSupplement::class)->queue($this->account->fresh());
        $this->runJob($card);
        $this->assertSame(self::AI_TEXT, $card->refresh()->description);

        $this->sync($shop);

        $this->assertSame(self::AI_TEXT, $card->refresh()->description);
        $link = B2bProductLink::query()->where('product_id', $card->id)->sole();
        $this->assertSame(sha1(self::AI_TEXT), $link->description_hash);
        $this->assertSame(sha1($english), $link->source_description_hash);

        $shop->description = 'Nitrile coated safety glove, category II, new coating.';
        $this->sync($shop);

        $card->refresh();
        $this->assertSame($shop->description, $card->description);
        $this->assertSame(self::AI_TEXT, $card->enrichment_payload['replaced_description']);
        $this->assertNull(B2bProductLink::query()->where('product_id', $card->id)->value('source_description_hash'));
    }

    /**
     * @return array{description: string, payload: array<string, mixed>, norms: string|null, packaging: string|null, web_source_urls: list<string>, dropped: list<string>, dropped_claims: list<string>}
     */
    private function modelResult(): array
    {
        return [
            'description' => self::AI_TEXT,
            'payload' => [
                'features' => ['powłoka nitrylowa'],
                'norms' => [],
                'source_urls' => [self::PAGE_URL, self::WEB_URL],
                'primary_source_url' => self::WEB_URL,
                'primary_source_kind' => 'b2b_supplement',
                'confidence' => 0.8,
            ],
            'norms' => null,
            'packaging' => null,
            'web_source_urls' => [self::WEB_URL],
            'dropped' => ['EN 388 4131X'],
            'dropped_claims' => ['odporność na przecięcie'],
        ];
    }

    /**
     * @return array{0: Product, 1: array<string, mixed>}
     */
    private function untouchedSetup(): array
    {
        $card = $this->card(self::SHORT, ['enrichment_payload' => ['features' => ['cecha z B2B']]]);
        $this->link($card, 'a', sha1(self::SHORT));
        $this->queued($card);

        return [$card, $this->snapshot($card)];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Product $card): array
    {
        $card->refresh();

        return [
            'description' => $card->description,
            'payload' => $card->enrichment_payload,
            'status' => $card->enrichment_status,
            'links' => B2bProductLink::query()->where('product_id', $card->id)->orderBy('id')
                ->get(['description_hash', 'source_description_hash'])->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function assertUntouched(Product $card, array $before): void
    {
        $this->assertSame($before, $this->snapshot($card));
    }

    private function runJob(Product $card): void
    {
        app()->call([new SupplementB2bDescriptionJob((int) $card->id, (int) $this->account->id), 'handle']);
    }

    private function queued(Product $card): void
    {
        B2bDescriptionSupplementAttempt::query()->create([
            'product_id' => $card->id,
            'b2b_account_id' => $this->account->id,
            'source_sha1' => sha1((string) $card->description),
            'hosts_sha1' => $this->account->enrichmentHostsSha1(),
            'status' => B2bDescriptionSupplementAttempt::STATUS_QUEUED,
        ]);
    }

    private function sync(B2bConnector $connector): void
    {
        app(B2bAccountSyncRunner::class)->run($this->account->fresh(), delayMs: 0, connector: $connector);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function card(string $description, array $extra = []): Product
    {
        $product = Product::query()->create([
            'sku' => 'R-1',
            'name' => 'Rękawica nitrylowa R-1',
            'manufacturer' => 'Testowy',
            'description' => $description,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'currency' => 'PLN',
            ...$extra,
        ]);

        return $product->refresh();
    }

    private function link(Product $product, string $remoteId, string $descriptionHash): B2bProductLink
    {
        return B2bProductLink::query()->create([
            'b2b_account_id' => $this->account->id,
            'remote_id' => $remoteId,
            'product_id' => $product->id,
            'remote_sku' => $product->sku,
            'description_hash' => $descriptionHash,
        ]);
    }

    private function attemptOf(Product $product): B2bDescriptionSupplementAttempt
    {
        return B2bDescriptionSupplementAttempt::query()
            ->where('product_id', $product->id)
            ->where('b2b_account_id', $this->account->id)
            ->sole();
    }
}

/** Łącznik testowy dystrybutora bez sieci: jedna pozycja SUP-1, opis z pola $description. */
class SupplementSyncConnector implements B2bConnector
{
    public string $description = '';

    public static function key(): string
    {
        return 'supplementsynctest';
    }

    public static function label(): string
    {
        return 'Testowy';
    }

    public static function host(): string
    {
        return 'supplement-sync.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): static
    {
        return new static;
    }

    public function login(): void {}

    public function products(): iterable
    {
        yield new B2bRemoteProduct(remoteId: '1', sku: 'SUP-1', name: 'Rękawica nitrylowa SUP-1');
    }

    public function totalProducts(): int
    {
        return 1;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'Testowy';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: 8.0, base: 10.0);
    }

    public function description(B2bRemoteProduct $product): string
    {
        return $this->description;
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }
}

/** Witryna producenta marki „Testowy” z tekstem po angielsku (jak Bolle). */
final class SupplementManufacturerConnector extends SupplementSyncConnector implements B2bForeignLanguageSource, B2bManufacturerSite
{
    public static function ownBrand(): string
    {
        return 'Testowy';
    }
}
