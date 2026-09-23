<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductImageRetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Karta 8564 (RINGERS R074, 23.09.2026): weryfikator wybrał trzy zdjęcia R-074 z ansell.com, a Incapsula oddała
 * przy każdym stronę zapory (200 text/html) zamiast pliku. W tej samej sekundzie R-065 przeszło — odmowa jest
 * chwilowa, więc karta zapamiętuje adresy, a products:retry-images ponawia je później.
 */
final class ProductImageRetryTest extends TestCase
{
    use RefreshDatabase;

    private const PRIMARY = 'https://www.ansell.com/-/media/projects/ansell/website/pim/product-assets/ringers/r-074/ringers074.ashx?rev=6dd6124447874cdeb6f5bfc23ad23498&mh=872&h=871&w=1016&la=en&hash=5DB3483F1682D9DA3810853CEAFDAF86';

    private const BARRELS = 'https://www.ansell.com/-/media/projects/ansell/website/pim/product-assets/ringers/r-074/ringers-074-chemical-application---examining-barrels.ashx?rev=7cc96bad7f0a4a61a7d5ee83fb21ce6e&mh=872&h=359&w=479&la=en&hash=D16D2D98B1CCC341A1F0624A9310396A';

    private const INCAPSULA = '<html><head><META NAME="robots" CONTENT="noindex,nofollow"><script src="/_Incapsula_Resource?SWJIYLWA=5074a744e2e3d891814e9a2dace20bd4"></script><body></body></html>';

    /** Tak Jina odpowiada dziś na zrzut pliku obrazka: tekst bez obrazka (sprawdzone 23.09.2026). */
    private const JINA_TEXT = "Title: Ringers074.jpg\n\nURL Source: x\n\nMarkdown Content:\nundefined";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_firewall_page_403_and_redirect_loop_are_retryable_but_404_is_not(): void
    {
        $firewall = 'https://www.ansell.com/-/media/pim/product-assets/a.ashx';
        $forbidden = 'https://www.ansell.com/-/media/pim/product-assets/b.ashx';
        $loop = 'https://www.ansell.com/-/media/pim/product-assets/c.ashx';
        $missing = 'https://www.ansell.com/-/media/pim/product-assets/d.ashx';
        Http::fake(function (Request $request) use ($firewall, $forbidden, $loop, $missing) {
            return match (true) {
                str_contains($request->url(), 'r.jina.ai') => Http::response(self::JINA_TEXT, 200, ['Content-Type' => 'text/plain']),
                $request->url() === $firewall => Http::response(self::INCAPSULA, 200, ['Content-Type' => 'text/html']),
                $request->url() === $forbidden => Http::response(self::INCAPSULA, 403, ['Content-Type' => 'text/html']),
                // Incapsula: 302 na ten sam adres z ciasteczkiem — bez słoika ciasteczek pętla
                $request->url() === $loop => Http::response('', 302, ['Location' => $loop]),
                $request->url() === $missing => Http::response('Not found', 404, ['Content-Type' => 'text/html']),
                default => Http::response('unexpected', 500),
            };
        });

        $downloader = new ProductImageDownloader;
        $saved = $downloader->downloadMany($this->product(), [$firewall, $forbidden, $loop, $missing], 1);

        $this->assertSame([], $saved);
        $failures = $downloader->lastFailures();
        $this->assertCount(4, $failures, json_encode($failures, JSON_UNESCAPED_UNICODE));
        $this->assertSame([$firewall, $forbidden, $loop], $downloader->lastRetryLaterUrls());
    }

    public function test_timeout_is_retryable_but_unknown_host_and_oversized_file_are_not(): void
    {
        $slow = 'https://www.ansell.com/-/media/pim/product-assets/slow.ashx';
        $huge = 'https://www.ansell.com/-/media/pim/product-assets/huge.ashx';
        $gone = 'https://nie-ma-takiego-hosta.example/media/r074.jpg';
        Http::fake(function (Request $request) use ($slow, $huge) {
            return match ($request->url()) {
                $slow => Http::failedConnection('cURL error 28: Operation timed out after 12000 milliseconds with 0 bytes received')($request),
                // z produkcji: ansell.com, film 81 MB zamiast zdjęcia — czas minie przy każdej próbie
                $huge => Http::failedConnection('cURL error 28: Operation timed out after 12000 milliseconds with 2452944 out of 81580324 bytes received')($request),
                default => Http::failedConnection()($request),
            };
        });

        $downloader = new ProductImageDownloader;
        $downloader->downloadMany($this->product(), [$slow, $huge, $gone], 1);

        $this->assertCount(3, $downloader->lastFailures());
        $this->assertSame([$slow], $downloader->lastRetryLaterUrls());
    }

    /** Polecenie obsługuje karty po kolei — karta opisana od nowa w tym czasie nie może dostać starego payloadu. */
    public function test_retry_does_not_overwrite_card_enriched_again_meanwhile(): void
    {
        $product = $this->product(retry: [self::PRIMARY], attempts: 1);
        $product->forceFill(['enriched_at' => now()->subHour()])->save();
        $stale = Product::query()->findOrFail($product->id);
        Http::fake(function () use ($product) {
            // nowy przebieg opisu kończy się w trakcie pobierania zdjęcia
            Product::query()->whereKey($product->id)->update([
                'enriched_at' => now(),
                'enrichment_payload' => json_encode(['norms' => ['EN 388'], ProductImageRetry::PAYLOAD_KEY => ['urls' => [self::BARRELS], 'attempts' => 0]]),
            ]);

            return Http::response(self::INCAPSULA, 200, ['Content-Type' => 'text/html']);
        });

        $this->assertSame('skipped', app(ProductImageRetry::class)->retry($stale));

        $payload = $product->refresh()->enrichment_payload;
        $this->assertSame(['EN 388'], $payload['norms']);
        $this->assertSame(['urls' => [self::BARRELS], 'attempts' => 0], $payload[ProductImageRetry::PAYLOAD_KEY]);
    }

    public function test_running_enrichment_is_left_alone(): void
    {
        $product = $this->product(retry: [self::PRIMARY], attempts: 1);
        $product->forceFill(['enrichment_status' => Product::ENRICHMENT_RUNNING])->save();
        Http::fake();

        $this->assertSame('skipped', app(ProductImageRetry::class)->retry($product));

        Http::assertNothingSent();
        $this->assertSame(1, $product->refresh()->enrichment_payload[ProductImageRetry::PAYLOAD_KEY]['attempts']);
    }

    public function test_from_trace_does_not_revive_card_that_gave_up(): void
    {
        $product = $this->product();
        $product->forceFill([
            'enrichment_error' => 'Opis OK, nie udało się pobrać zdjęcia (odpowiedź nie jest obrazem (text/html)). Ponawianie zakończone po 8 próbach.',
            'enrichment_trace' => ['steps' => [
                ['t' => 'image', 'm' => 'nie pobrano: odpowiedź nie jest obrazem (text/html)', 'urls' => [self::PRIMARY]],
            ]],
        ])->save();

        $this->artisan('products:retry-images', ['--from-trace' => true])
            ->expectsOutputToContain('Dopisano ze śladu: 0 kart.')
            ->assertSuccessful();
        $this->assertArrayNotHasKey(ProductImageRetry::PAYLOAD_KEY, $product->refresh()->enrichment_payload);
    }

    public function test_retry_state_without_urls_drops_the_promise(): void
    {
        $product = $this->product(retry: [], attempts: 2);
        Http::fake();

        $this->assertSame('skipped', app(ProductImageRetry::class)->retry($product));

        $product->refresh();
        $this->assertArrayNotHasKey(ProductImageRetry::PAYLOAD_KEY, $product->enrichment_payload);
        $this->assertSame(
            'Opis OK, nie udało się pobrać zdjęcia (odpowiedź nie jest obrazem (text/html) ×3).',
            $product->enrichment_error
        );
    }

    public function test_retry_saves_image_and_clears_the_error(): void
    {
        $product = $this->product(retry: [self::PRIMARY], attempts: 2);
        Http::fake([self::PRIMARY => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

        $this->assertSame('saved', app(ProductImageRetry::class)->retry($product));

        $product->refresh();
        $this->assertArrayNotHasKey(ProductImageRetry::PAYLOAD_KEY, $product->enrichment_payload);
        $this->assertSame(['EN 374'], $product->enrichment_payload['norms'], 'reszta payloadu zostaje');
        $this->assertNull($product->enrichment_error);
        $image = ProductImage::query()->where('product_id', $product->id)->sole();
        $this->assertSame(self::PRIMARY, $image->source_url);
        $this->assertTrue((bool) $image->is_primary);
    }

    public function test_still_blocked_waits_then_gives_up_after_max_attempts(): void
    {
        $product = $this->product(retry: [self::BARRELS, self::PRIMARY], attempts: 0);
        $this->fakeFirewall();
        $retry = app(ProductImageRetry::class);

        $this->assertSame('waiting', $retry->retry($product));
        $product->refresh();
        $state = $product->enrichment_payload[ProductImageRetry::PAYLOAD_KEY];
        $this->assertSame(1, $state['attempts']);
        $this->assertSame([self::BARRELS, self::PRIMARY], $state['urls']);
        $this->assertStringEndsWith(ProductImageRetry::ERROR_NOTE, (string) $product->enrichment_error);

        $payload = $product->enrichment_payload;
        $payload[ProductImageRetry::PAYLOAD_KEY]['attempts'] = ProductImageRetry::MAX_ATTEMPTS - 1;
        $product->forceFill(['enrichment_payload' => $payload])->save();

        $this->assertSame('gave_up', $retry->retry($product->refresh()));
        $product->refresh();
        $this->assertArrayNotHasKey(ProductImageRetry::PAYLOAD_KEY, $product->enrichment_payload);
        $this->assertStringEndsWith('Ponawianie zakończone po 8 próbach.', (string) $product->enrichment_error);
        $this->assertStringStartsWith('Opis OK, nie udało się pobrać zdjęcia', (string) $product->enrichment_error);
        $this->assertSame(0, ProductImage::query()->where('product_id', $product->id)->count());
    }

    public function test_permanent_failure_stops_retrying_at_once(): void
    {
        $product = $this->product(retry: [self::PRIMARY], attempts: 0);
        Http::fake(['*' => Http::response('Not found', 404, ['Content-Type' => 'text/html'])]);

        $this->assertSame('gave_up', app(ProductImageRetry::class)->retry($product));
        $this->assertStringEndsWith('Ponawianie zakończone po 1 próbie.', (string) $product->refresh()->enrichment_error);
    }

    public function test_card_that_got_an_image_elsewhere_is_skipped_without_download(): void
    {
        $product = $this->product(retry: [self::PRIMARY], attempts: 3);
        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/'.$product->id.'/b2b.jpg',
            'source_url' => 'https://b2b.example/r074.jpg',
            'is_primary' => true,
            'sort_order' => 0,
            'checksum' => str_repeat('a', 64),
        ]);
        Http::fake();

        $this->assertSame('skipped', app(ProductImageRetry::class)->retry($product));

        Http::assertNothingSent();
        $product->refresh();
        $this->assertArrayNotHasKey(ProductImageRetry::PAYLOAD_KEY, $product->enrichment_payload);
        $this->assertNull($product->enrichment_error);
    }

    public function test_command_from_trace_schedules_legacy_card_and_downloads(): void
    {
        $legacy = $this->product();
        $legacy->forceFill(['enrichment_trace' => ['steps' => [
            ['t' => 'image', 'm' => 'nie pobrano: odpowiedź nie jest obrazem (text/html) ×2', 'urls' => [self::BARRELS, self::PRIMARY]],
        ]]])->save();
        // bez zdjęcia z innego powodu (za mały obrazek) — ponawianie nic nie zmieni
        $tooSmall = $this->product(sku: 'R065');
        $tooSmall->forceFill(['enrichment_trace' => ['steps' => [
            ['t' => 'image', 'm' => 'nie pobrano: obrazek za mały (80x80) — miniatura/placeholder', 'urls' => [self::PRIMARY]],
        ]]])->save();
        Http::fake([
            self::BARRELS => Http::response(self::INCAPSULA, 200, ['Content-Type' => 'text/html']),
            self::PRIMARY => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'r.jina.ai/*' => Http::response(self::JINA_TEXT, 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->artisan('products:retry-images', ['--from-trace' => true, '--dry-run' => true])
            ->expectsOutputToContain('Do dopisania ze śladu: 1 kart.')
            ->assertSuccessful();
        $this->assertNull($legacy->refresh()->enrichment_payload[ProductImageRetry::PAYLOAD_KEY] ?? null, '--dry-run nic nie zapisuje');

        $this->artisan('products:retry-images', ['--from-trace' => true])
            ->expectsOutputToContain('Dopisano ze śladu: 1 kart.')
            ->expectsOutputToContain('pobrane 1')
            ->assertSuccessful();

        $legacy->refresh();
        $this->assertSame(self::PRIMARY, ProductImage::query()->where('product_id', $legacy->id)->sole()->source_url);
        $this->assertNull($legacy->enrichment_error);
        $this->assertArrayNotHasKey(ProductImageRetry::PAYLOAD_KEY, $legacy->enrichment_payload);
        $tooSmall->refresh();
        $this->assertArrayNotHasKey(ProductImageRetry::PAYLOAD_KEY, $tooSmall->enrichment_payload);
        $this->assertStringEndsWith('.', (string) $tooSmall->enrichment_error);
        $this->assertStringNotContainsString('Ponowimy', (string) $tooSmall->enrichment_error);
    }

    public function test_truncated_trace_url_is_not_used(): void
    {
        $product = $this->product();
        $cut = mb_substr(self::PRIMARY.str_repeat('x', 300), 0, 300);
        $product->forceFill(['enrichment_trace' => ['steps' => [
            ['t' => 'image', 'm' => 'nie pobrano: http 403', 'urls' => [$cut, self::BARRELS]],
        ]]])->save();

        $this->assertSame([self::BARRELS], app(ProductImageRetry::class)->urlsFromTrace($product));
    }

    /** @param  list<string>|null  $retry */
    private function product(?array $retry = null, int $attempts = 0, string $sku = '074-12'): Product
    {
        $payload = ['norms' => ['EN 374']];
        $error = 'Opis OK, nie udało się pobrać zdjęcia (odpowiedź nie jest obrazem (text/html) ×3).';
        if ($retry !== null) {
            $payload[ProductImageRetry::PAYLOAD_KEY] = ['urls' => $retry, 'attempts' => $attempts];
            $error .= ProductImageRetry::ERROR_NOTE;
        }

        return Product::query()->create([
            'sku' => $sku,
            'name' => 'RINGERS R074 rękawice ochronne',
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 20,
            'purchase_price' => 20,
            'stock' => 1,
            'description' => 'Rękawice powlekane PVC.',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => $payload,
            'enrichment_error' => $error,
        ]);
    }

    private function fakeFirewall(): void
    {
        Http::fake(static fn (Request $request) => str_contains($request->url(), 'r.jina.ai')
            ? Http::response(self::JINA_TEXT, 200, ['Content-Type' => 'text/plain'])
            : Http::response(self::INCAPSULA, 200, ['Content-Type' => 'text/html']));
    }

    private function jpeg(): string
    {
        $im = imagecreatetruecolor(320, 480);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 40, 40));
        ob_start();
        imagejpeg($im, null, 85);
        imagedestroy($im);

        return (string) ob_get_clean();
    }
}
