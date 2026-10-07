<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductSourceDocument;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\SourceDocumentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * products:redescribe-from-sources (etap 1, tryb cienia): porównanie opisu z zapisanych źródeł z obecnym, karty po
 * kolei; bez --apply nic nie zapisuje, z --apply wyłącznie wersje shadow — tabela products bez zmian.
 */
final class RedescribeFromSourcesCommandTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://sklep.example/rekawice-testex-tx4521';

    private const CURRENT = 'Rękawice montażowe Testex TX4521 z dzianiny poliestrowej, opis z poprzedniego pobrania strony sklepu.';

    private const SHADOW = 'Rękawice ochronne Testex TX4521 z dzianiny nylonowej powlekanej nitrylem, grubość powłoki 1,2 mm. '
        .'Spełniają normę EN 388:2016 z poziomami 4131A. Przeznaczone do prac montażowych, w których liczy się pewny chwyt '
        .'drobnych elementów i ochrona dłoni przed ścieraniem.';

    private int $modelCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(SourceDocumentStore::DISK);
        config()->set('enrichment.store_sources', true);
        Http::preventStrayRequests();
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->zeroOrMoreTimes()->andReturnUsing(function (array $messages): array {
            $this->modelCalls++;
            if (str_contains((string) ($messages[1]['content'] ?? ''), 'TX9999')) {
                throw new RuntimeException('Model nie odpowiedział (HTTP 429)');
            }

            return [
                'features' => [], 'specs' => [], 'norms' => ['EN 388:2016 4131A'], 'certificates' => [], 'materials' => ['nitryl'],
                'use_cases' => [], 'image_urls' => [], 'source_urls' => [self::URL], 'description' => self::SHADOW, 'confidence' => 0.9,
            ];
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    public function test_comparison_without_apply_writes_nothing(): void
    {
        $product = $this->card('TX4521');
        $updatedAt = (string) $product->fresh()?->updated_at;

        $this->assertSame(0, Artisan::call('products:redescribe-from-sources', ['--product' => [$product->id]]));
        $output = Artisan::output();
        $this->assertStringContainsString('soft', $output, 'werdykt obecnego opisu');
        $this->assertStringContainsString('hard', $output, 'werdykt strony źródła');
        $this->assertStringContainsString('0 → 3', $output, 'dowody: EN 388:2016, 4131A, nitryl');
        $this->assertStringContainsString('+ EN 388:2016 4131A; − EN 420', $output);
        $this->assertStringContainsString('Porównanie — nic nie zapisano', $output);

        $this->assertSame(1, $this->modelCalls);
        $this->assertSame(0, ProductDescriptionVersion::query()->count());
        $this->assertSame(self::CURRENT, $product->fresh()?->description);
        $this->assertSame($updatedAt, (string) $product->fresh()?->updated_at);
    }

    public function test_apply_writes_only_shadow_versions(): void
    {
        $product = $this->card('TX4521');
        $before = $this->state($product);

        $this->artisan('products:redescribe-from-sources', ['--product' => [$product->id], '--apply' => true])
            ->expectsOutputToContain('Zapisano wersji shadow: 1')
            ->assertExitCode(0);

        $version = ProductDescriptionVersion::query()->sole();
        $this->assertSame(ProductDescriptionVersion::STATUS_SHADOW, $version->status);
        $this->assertSame(ProductDescriptionVersion::ORIGIN_STORED_SOURCES, $version->origin);
        $this->assertSame(self::SHADOW, $version->description);
        $this->assertSame('hard', $version->identity_verdict);
        $this->assertSame(self::URL, $version->primary_source_url);
        $this->assertGreaterThanOrEqual(1, (int) $version->evidence_count);
        $this->assertSame($before, $this->state($product));
    }

    public function test_failed_and_rejected_cards_are_reported_and_the_rest_goes_on(): void
    {
        $failing = $this->card('TX9999');
        $withoutText = $this->card('TX7777');
        Storage::disk(SourceDocumentStore::DISK)->delete(
            ProductSourceDocument::query()->where('product_id', $withoutText->id)->value('sha256').'.txt.gz'
        );
        $good = $this->card('TX4521');
        $notStored = Product::query()->create(['sku' => 'TX1111', 'name' => 'Bez źródeł', 'manufacturer' => 'Testex', 'catalog_price_net' => 1, 'purchase_price' => 1, 'stock' => 0]);

        $this->artisan('products:redescribe-from-sources', ['--apply' => true])
            ->expectsOutputToContain('błąd: Model nie odpowiedział')
            ->expectsOutputToContain('odrzucony: brak zapisanych źródeł z tekstem')
            ->expectsOutputToContain('Kart: 3 — opis ze źródeł 1, odrzucony 1, błąd 1.')
            ->assertExitCode(0);

        $this->assertSame([(int) $good->id], ProductDescriptionVersion::query()->pluck('product_id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertNull($notStored->fresh()?->description);
        $this->assertSame(2, $this->modelCalls, 'karta bez tekstu nie idzie do modelu');
    }

    public function test_limit_and_unknown_price_list(): void
    {
        $this->card('TX4521');
        $this->card('TX4522');

        $this->artisan('products:redescribe-from-sources', ['--limit' => 1])
            ->expectsOutputToContain('Kart: 1 —')
            ->assertExitCode(0);
        $this->artisan('products:redescribe-from-sources', ['--price-list' => 999])
            ->expectsOutputToContain('Nie ma cennika 999.')
            ->assertExitCode(1);
    }

    /** @return array<string, mixed> */
    private function state(Product $product): array
    {
        $fresh = $product->fresh();

        return [...(array) $fresh?->only(['description', 'enrichment_payload', 'norms', 'enrichment_status', 'review_reason']), 'updated_at' => (string) $fresh?->updated_at];
    }

    private function card(string $sku): Product
    {
        $url = str_replace('tx4521', mb_strtolower($sku), self::URL);
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice montażowe Testex '.$sku.' powlekane nitrylem',
            'manufacturer' => 'Testex',
            'catalog_price_net' => 12,
            'purchase_price' => 10,
            'stock' => 1,
            'description' => self::CURRENT,
            'norms' => 'EN 420',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['norms' => ['EN 420'], 'primary_source_url' => $url, 'identity' => ['verdict' => 'soft'], 'evidence_summary' => ['explicit' => 0, 'inferred' => 1]],
        ]);
        app(SourceDocumentStore::class)->record($product, [[
            'url' => $url,
            'text' => 'Rękawice ochronne Testex '.$sku.' z dzianiny nylonowej powlekanej nitrylem. Norma EN 388:2016, poziomy 4131A. '
                .'Grubość powłoki 1,2 mm. Do prac montażowych.',
            'roles' => [ProductSourceDocument::ROLE_DESCRIPTION],
            'identity' => ['verdict' => 'hard', 'reason' => 'SKU '.$sku.' w adresie', 'key_type' => 'sku', 'key' => $sku],
        ]], null);

        return $product;
    }
}
