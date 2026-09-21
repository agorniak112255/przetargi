<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\Product;
use App\Models\ProductDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * documents:repair-polish-text — teksty kart katalogowych odczytane w złej stronie kodowej (Windows-1250 jako
 * Windows-1252). Dane syntetyczne, fragment tekstu jak z karty TK GLOVES z Tegro.
 */
final class RepairPolishDocumentTextCommandTest extends TestCase
{
    use RefreshDatabase;

    private const BROKEN = "SHARK\nRêkawica antyprzeciêciowa\nœci¹gacz\nwystêpuje zagro¿enie przeciêciem\nw³ókien";

    private const FIXED = "SHARK\nRękawica antyprzecięciowa\nściągacz\nwystępuje zagrożenie przecięciem\nwłókien";

    private function document(string $text): ProductDocument
    {
        $product = Product::query()->create([
            'sku' => 'SHARK '.random_int(1, 999999), 'name' => 'RĘKAWICE TK GLOVES SHARK', 'manufacturer' => 'TK GLOVES',
            'catalog_price_net' => 0, 'purchase_price' => 0,
        ]);

        return ProductDocument::query()->create([
            'product_id' => $product->id,
            'path' => 'products/'.$product->id.'/docs/x.pdf',
            'source_url' => 'https://b2b.tegro.pl/zasoby/import/t/tk-shark-karta-katalogowa-pl.pdf',
            'title' => 'tk-shark-karta-katalogowa-pl.pdf',
            'kind' => ProductDocument::KIND_DATASHEET,
            'text' => $text,
        ]);
    }

    public function test_without_apply_it_only_reports(): void
    {
        $broken = $this->document(self::BROKEN);
        $good = $this->document('Rękawica z włókna HPPE, ściągacz.');
        // zakładanie kart zleca własne reindeksowanie — liczymy tylko to, co zleci polecenie
        Queue::fake();

        $this->artisan('documents:repair-polish-text')
            ->expectsOutputToContain('Teksty do poprawy: 1 (kart: 1)')
            ->expectsOutputToContain('Nic nie zapisano')
            ->assertSuccessful();

        $this->assertSame(self::BROKEN, $broken->fresh()?->text);
        $this->assertSame('Rękawica z włókna HPPE, ściągacz.', $good->fresh()?->text);
        Queue::assertNothingPushed();
    }

    public function test_apply_fixes_the_text_and_reindexes_the_card(): void
    {
        $broken = $this->document(self::BROKEN);
        $good = $this->document('Rękawica z włókna HPPE, ściągacz.');
        // zakładanie kart zleca własne reindeksowanie — liczymy tylko to, co zleci polecenie
        Queue::fake();

        $this->artisan('documents:repair-polish-text', ['--apply' => true])->assertSuccessful();

        $this->assertSame(self::FIXED, $broken->fresh()?->text);
        $this->assertSame('Rękawica z włókna HPPE, ściągacz.', $good->fresh()?->text);
        Queue::assertPushed(ReindexProductEmbeddingJob::class, fn (ReindexProductEmbeddingJob $job): bool => $job->productId === (int) $broken->product_id);
        Queue::assertPushed(ReindexProductEmbeddingJob::class, 1);
    }
}
