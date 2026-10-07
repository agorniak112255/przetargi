<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductSourceDocument;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\SourceDocumentStore;
use App\Services\Enrichment\StoredSourcesDescriptionRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Opis z zapisanych źródeł karty (etap 1, tryb cienia): model dostaje wyłącznie zapisane teksty, nic nie jest
 * zapisywane ani pobierane, kody norm spoza źródeł wypadają, dowody liczone z tekstu surowego, werdykt ze strony.
 */
final class StoredSourcesDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://sklep.example/rekawice-testex-tx4521';

    private const RAW = 'Sklep BHP — koszyk, logowanie. Rękawice ochronne Testex TX4521 z dzianiny nylonowej powlekanej nitrylem. '
        .'Norma EN 388:2016, poziomy 4131A. Grubość powłoki 1,2 mm. Do prac montażowych. Dostawa 24 h.';

    private const FILTERED = 'Rękawice ochronne Testex TX4521 z dzianiny nylonowej powlekanej nitrylem. Norma EN 388:2016, poziomy 4131A. '
        .'Grubość powłoki 1,2 mm. Do prac montażowych.';

    private const DESCRIPTION = 'Rękawice ochronne Testex TX4521 z dzianiny nylonowej powlekanej nitrylem, grubość powłoki 1,2 mm. '
        .'Spełniają normę EN 388:2016 z poziomami 4131A. Przeznaczone do prac montażowych, w których liczy się pewny chwyt '
        .'drobnych elementów i ochrona dłoni przed ścieraniem.';

    /** @var list<array<int, array<string, mixed>>> */
    private array $prompts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(SourceDocumentStore::DISK);
        config()->set('enrichment.store_sources', true);
        Http::preventStrayRequests();
    }

    public function test_describes_from_stored_sources_without_saving_anything(): void
    {
        $product = $this->cardWithSource();
        $this->model(self::DESCRIPTION, ['EN 388:2016 4131A']);

        $result = $this->service()->describeFromStoredSources($product, app(SourceDocumentStore::class)->forProduct($product));

        $this->assertSame(self::DESCRIPTION, $result['description']);
        $this->assertSame('EN 388:2016 4131A', $result['norms']);
        $payload = $result['payload'];
        $this->assertSame([self::URL], $payload['source_urls']);
        $this->assertSame(self::URL, $payload['primary_source_url']);
        $this->assertSame('shop', $payload['primary_source_kind']);
        $this->assertSame('hard', $payload['identity']['verdict']);
        $this->assertSame('SKU TX4521 w adresie', $payload['identity']['reason']);
        $explicit = array_column(array_filter($payload['evidence'], static fn (array $e): bool => $e['status'] === 'explicit'), 'value');
        $this->assertContains('EN388:2016', $explicit);
        $this->assertSame($payload['evidence_summary']['explicit'], count($explicit));

        // model dostaje tekst po filtrze stron i listę zapisanych źródeł, bez zrzutu sklepu
        $user = (string) ($this->prompts[0][1]['content'] ?? '');
        $this->assertStringContainsString('zapisane teksty stron wyrobu', $user);
        $this->assertStringContainsString('Grubość powłoki 1,2 mm', $user);
        $this->assertStringNotContainsString('koszyk, logowanie', $user);

        $product->refresh();
        $this->assertNull($product->description);
        $this->assertSame(Product::ENRICHMENT_NONE, $product->enrichment_status);
        $this->assertSame(0, ProductDescriptionVersion::query()->count());
    }

    public function test_norm_code_outside_sources_is_cut_from_description(): void
    {
        $product = $this->cardWithSource();
        $this->model(self::DESCRIPTION.' Chronią też przed ciepłem kontaktowym według EN 407:2020 X1XXXX.', ['EN 388:2016 4131A', 'EN 407:2020 X1XXXX']);

        $result = $this->service()->describeFromStoredSources($product, app(SourceDocumentStore::class)->forProduct($product));

        $this->assertStringNotContainsString('EN 407', $result['description']);
        $this->assertSame('EN 388:2016 4131A', $result['norms']);
        $this->assertNotSame([], $result['dropped_norm_claims']);
        $this->assertSame($result['dropped_norm_claims'], $result['payload']['dropped_norm_claims']);
    }

    public function test_sources_given_as_arrays_and_primary_source_chosen_like_in_enrichment(): void
    {
        config()->set('enrichment.manufacturer_domains.testex', ['testex.example']);
        $product = $this->card();
        $this->model(self::DESCRIPTION, ['EN 388:2016 4131A']);

        $result = $this->service()->describeFromStoredSources($product, [
            ['url' => self::URL, 'text' => self::RAW, 'verdict' => 'hard'],
            ['url' => 'https://testex.example/produkty/rekawice', 'text' => self::FILTERED, 'verdict' => 'soft', 'verdict_reason' => 'bez kodu karty'],
            ['url' => '', 'text' => 'bez adresu'],
        ]);

        // strona producenta przed sklepem — jak primarySource w zwykłym przebiegu
        $this->assertSame('https://testex.example/produkty/rekawice', $result['payload']['primary_source_url']);
        $this->assertSame('manufacturer', $result['payload']['primary_source_kind']);
        $this->assertSame('soft', $result['payload']['identity']['verdict']);
        $this->assertCount(2, $result['payload']['source_urls']);
    }

    public function test_rejects_without_stored_text(): void
    {
        $product = $this->cardWithSource();
        Storage::disk(SourceDocumentStore::DISK)->delete(Storage::disk(SourceDocumentStore::DISK)->allFiles());
        $this->model(self::DESCRIPTION, []);

        $this->expectException(StoredSourcesDescriptionRejected::class);
        $this->expectExceptionMessage('brak zapisanych źródeł z tekstem');

        $this->service()->describeFromStoredSources($product, app(SourceDocumentStore::class)->forProduct($product));
    }

    public function test_rejects_when_model_does_not_confirm_sources(): void
    {
        $product = $this->cardWithSource();
        $this->model(self::DESCRIPTION, [], confidence: 0.0);

        $this->expectException(StoredSourcesDescriptionRejected::class);
        $this->expectExceptionMessage('confidence 0');

        $this->service()->describeFromStoredSources($product, app(SourceDocumentStore::class)->forProduct($product));
    }

    private function card(): Product
    {
        return Product::query()->create([
            'sku' => 'TX4521',
            'name' => 'Rękawice montażowe Testex TX4521 powlekane nitrylem',
            'manufacturer' => 'Testex',
            'catalog_price_net' => 12,
            'purchase_price' => 10,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);
    }

    private function cardWithSource(): Product
    {
        $product = $this->card();
        app(SourceDocumentStore::class)->record($product, [[
            'url' => self::URL,
            'text' => self::RAW,
            'filtered_text' => self::FILTERED,
            'roles' => [ProductSourceDocument::ROLE_DESCRIPTION],
            'identity' => ['verdict' => 'hard', 'reason' => 'SKU TX4521 w adresie', 'key_type' => 'sku', 'key' => 'TX4521'],
        ]], null);

        return $product;
    }

    /** @param  list<string>  $norms */
    private function model(string $description, array $norms, float $confidence = 0.9): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->zeroOrMoreTimes()->andReturnUsing(function (array $messages) use ($description, $norms, $confidence): array {
            $this->prompts[] = $messages;

            return [
                'features' => [], 'specs' => [], 'norms' => $norms, 'certificates' => [], 'materials' => ['nitryl'],
                'use_cases' => ['prace montażowe'], 'image_urls' => [], 'source_urls' => [self::URL],
                'description' => $description, 'confidence' => $confidence,
            ];
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    private function service(): ProductEnrichmentService
    {
        return app(ProductEnrichmentService::class);
    }
}
