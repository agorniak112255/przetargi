<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\B2bStripDatasheetDescriptionsCommand;
use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\User;
use App\Services\Vector\ProductEmbeddingIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Dosłowny tekst karty technicznej (PDF dostawcy) nie jest opisem wyrobu.
 *
 * Testujący zgłosił opisy brzmiące jak tłumaczenie ulotki — „Delikatny powietrzny dotyk”, „supporting
 * HAPPINESS” — z połamanymi wyrazami („cholewk a”), bo tak wychodzą z PDF-a. Opis wraca do samej prozy, a tekst
 * karty technicznej zostaje przy dokumencie wyrobu i wchodzi do dokumentu embeddingu: wyszukiwanie widzi
 * parametry, a człowiek czyta opis.
 */
final class DatasheetOutsideDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private const DATASHEET = "cholewk a\nwegańska NUVYA SKINYUM™ – Miękkość, która oddycha.\npodeszwa\nGRIPPER PU.2D\nnorma\nEN ISO 20345:2011 S3 SRC";

    public function test_datasheet_text_reaches_the_embedding_document(): void
    {
        $product = $this->product('Półbuty bezpieczne z podnoskiem stalowym i podeszwą PU.');
        $this->datasheet($product);

        $text = app(ProductEmbeddingIndexer::class)->documentText($product->fresh());

        $this->assertStringContainsString('Półbuty bezpieczne z podnoskiem stalowym', $text);
        $this->assertStringContainsString('EN ISO 20345:2011 S3 SRC', $text);
    }

    public function test_attaching_the_datasheet_sends_the_card_back_to_the_embedding_queue(): void
    {
        $product = $this->product('Półbuty bezpieczne.');
        // zlecenie z zapisu karty już poszło — patrzymy tylko na to, co wywoła sam plik
        Queue::fake();

        // plik trafia przy karcie po jej zapisie — bez własnego haka wektor poznałby kartę techniczną
        // dopiero przy najbliższej edycji wyrobu
        $document = $this->datasheet($product);

        Queue::assertPushed(
            ReindexProductEmbeddingJob::class,
            static fn (ReindexProductEmbeddingJob $job): bool => $job->productId === (int) $product->id,
        );

        // skasowanie pliku też zmienia dokument wyszukiwania
        Queue::fake();
        $document->delete();
        Queue::assertPushed(ReindexProductEmbeddingJob::class);
    }

    public function test_command_reports_without_touching_anything(): void
    {
        $product = $this->product("Półbuty bezpieczne.\n\nZ karty technicznej (Karta produktu):\n".self::DATASHEET);

        $this->artisan('b2b:strip-datasheet-descriptions')
            ->expectsOutputToContain('Opisy do skrócenia:      1')
            ->expectsOutputToContain('Raport — nic nie zapisano')
            ->assertSuccessful();

        $this->assertSame($product->description, $product->fresh()->description);
    }

    public function test_apply_leaves_prose_and_moves_the_link_fingerprint(): void
    {
        $old = "Półbuty bezpieczne.\n\nZ karty technicznej (Karta produktu):\n".self::DATASHEET;
        $product = $this->product($old);
        $link = $this->link($product, sha1($old));

        $this->artisan('b2b:strip-datasheet-descriptions --apply')->assertSuccessful();

        $this->assertSame('Półbuty bezpieczne.', $product->fresh()->description);
        // odcisk idzie za opisem — inaczej karta wyglądałaby jak zmieniona ręcznie i nigdy nie dostałaby
        // świeższego opisu od dostawcy
        $this->assertSame(sha1('Półbuty bezpieczne.'), $link->fresh()->description_hash);
    }

    public function test_card_that_would_lose_its_whole_description_is_left_alone(): void
    {
        // opis złożony wyłącznie z karty technicznej: pusty opis wyrzuciłby wyrób z propozycji przetargowych
        $only = "Z karty technicznej (Karta produktu):\n".self::DATASHEET;
        $product = $this->product($only);

        $this->artisan('b2b:strip-datasheet-descriptions --apply')
            ->expectsOutputToContain('Pominięte (byłby pusty): 1')
            ->assertSuccessful();

        $this->assertSame($only, $product->fresh()->description);
    }

    public function test_description_without_the_section_stays_as_it_is(): void
    {
        $text = 'Rękawice powlekane nitrylem, mankiet ściągaczowy.';
        $this->assertSame($text, B2bStripDatasheetDescriptionsCommand::withoutDatasheet($text));
    }

    private function product(string $description): Product
    {
        return Product::query()->create([
            'sku' => 'ARAL 927 4260 S3',
            'name' => 'ARAL 927 4260 S3',
            'manufacturer' => 'ARTRA',
            'description' => $description,
        ]);
    }

    private function datasheet(Product $product): ProductDocument
    {
        return ProductDocument::query()->create([
            'product_id' => $product->id,
            'path' => 'products/'.$product->id.'/karta.pdf',
            'source_url' => 'https://artra.example.test/pl-kp-aral-927.pdf',
            'title' => 'Karta produktu',
            'kind' => ProductDocument::KIND_DATASHEET,
            'sort_order' => 1,
            'text' => self::DATASHEET,
        ]);
    }

    private function link(Product $product, string $descriptionHash): B2bProductLink
    {
        $user = User::factory()->create();
        $account = B2bAccount::query()->create([
            'username' => 'artra',
            'password' => 'sekret',
            'sites' => ['artra.example.test'],
            'connector' => 'artra',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return B2bProductLink::query()->create([
            'b2b_account_id' => $account->id,
            'product_id' => $product->id,
            'remote_id' => 'ARAL 927 4260 S3',
            'description_hash' => $descriptionHash,
        ]);
    }
}
