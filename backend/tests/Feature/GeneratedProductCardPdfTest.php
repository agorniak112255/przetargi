<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductDocument;
use App\Services\Enrichment\ProductDocumentDownloader;
use App\Services\Enrichment\ProductPageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Producent AJ GROUP (pros.pl) ma przy każdym wyrobie „Pobierz kartę produktu w pliku PDF” —
 * /modules/x13producttopdf/pdf.php?id_product=211&id_product_attribute=3759. Adres nie kończy się na .pdf
 * i nie ma w nim kodu wyrobu, więc karta nigdy nie trafiała do dokumentów. Z wyrobem wiąże ją wyłącznie
 * strona, na której stoi link: potwierdzona karta tego wyrobu na oficjalnym hoście producenta.
 */
final class GeneratedProductCardPdfTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://pros.pl/pl/fartuchy-wodoochronne/211-fartuch-model-202.html';

    private const CARD = 'https://pros.pl/modules/x13producttopdf/pdf.php?id_product=211&id_product_attribute=3759';

    public function test_recognises_only_generated_card_addresses(): void
    {
        $this->assertTrue(ProductDocumentDownloader::looksLikeGeneratedCardUrl(self::CARD));
        $this->assertTrue(ProductDocumentDownloader::looksLikeGeneratedCardUrl('https://sklep.pl/pdf.php?id_product=7'));
        $this->assertFalse(ProductDocumentDownloader::looksLikeGeneratedCardUrl('https://sklep.pl/pliki/karta-202.pdf'));
        $this->assertFalse(ProductDocumentDownloader::looksLikeGeneratedCardUrl('https://sklep.pl/pdf.php'));
        $this->assertFalse(ProductDocumentDownloader::looksLikeGeneratedCardUrl('https://sklep.pl/index.php?id_product=7'));
        // bramka dla adresów z wyszukiwarki i od modelu zostaje bez zmian
        $this->assertFalse(ProductDocumentDownloader::looksLikeDocumentUrl(self::CARD));
    }

    public function test_link_on_confirmed_manufacturer_card_is_collected_without_manufacturer_domains(): void
    {
        $fetched = $this->fetch(self::PAGE, $this->page(self::CARD));

        $this->assertCount(1, $fetched['pages']);
        $this->assertSame([self::CARD], $fetched['document_urls']);
        $this->assertSame('Pobierz kartę produktu w pliku PDF', $fetched['document_labels'][self::CARD] ?? null);
    }

    public function test_link_on_shop_page_is_ignored(): void
    {
        $shop = 'https://bogarobhp.pl/fartuchy/fartuch-wodoochronny-pros-202';
        $fetched = $this->fetch($shop, $this->page('https://bogarobhp.pl/modules/x13producttopdf/pdf.php?id_product=9'));

        $this->assertSame([], $fetched['document_urls']);
    }

    public function test_link_to_another_host_is_ignored(): void
    {
        $fetched = $this->fetch(self::PAGE, $this->page('https://inny-sklep.pl/modules/x13producttopdf/pdf.php?id_product=211'));

        $this->assertSame([], $fetched['document_urls']);
    }

    public function test_link_on_card_of_another_variant_is_ignored(): void
    {
        $html = str_replace(['model 202', '202-00005'], ['model 202/A', '202/A-00005'], $this->page(self::CARD));
        $fetched = $this->fetch(self::PAGE, $html, 'Fartuch model 202/A');

        $this->assertSame([], $fetched['pages'], 'strona wariantu 202/A nie jest kartą 202');
        $this->assertSame([], $fetched['document_urls']);
    }

    public function test_generated_card_given_directly_by_search_engine_is_not_a_document(): void
    {
        Http::fake([
            'pros.pl/modules/*' => Http::response("%PDF-1.4\n%%EOF\n", 200, ['Content-Type' => 'application/pdf']),
            '*' => Http::response('', 404),
        ]);

        $fetched = app(ProductPageFetcher::class)->bypassCache()->fetch(
            [['url' => self::CARD, 'title' => 'Fartuch model 202', 'snippet' => '']],
            '202',
            1,
            [],
            $this->product(),
        );

        $this->assertSame([], $fetched['document_urls'], 'bez strony, która wiąże kartę z wyrobem');
    }

    public function test_card_is_saved_once_as_product_datasheet_even_though_every_copy_differs(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $product->save();
        $copy = 0;
        Http::fake(function () use (&$copy) {
            $copy++;

            // generator wpisuje do pliku datę utworzenia — każda kopia ma inną sumę kontrolną
            return Http::response("%PDF-1.4\n% kopia {$copy}\n%%EOF\n", 200, ['Content-Type' => 'application/pdf']);
        });
        $downloader = app(ProductDocumentDownloader::class);
        $labels = [self::CARD => 'Pobierz kartę produktu w pliku PDF'];

        $first = $downloader->downloadMany($product, [self::CARD], 3, $labels);
        $firstPath = (string) $first[0]->path;
        $second = $downloader->downloadMany($product, [self::CARD], 3, $labels);

        $this->assertCount(1, $first);
        $this->assertSame(ProductDocument::KIND_DATASHEET, $first[0]->kind);
        $this->assertSame('Karta produktu.pdf', $first[0]->title);
        $this->assertSame(1, ProductDocument::query()->where('product_id', $product->id)->count(), 'ponowne pobranie nie dopisuje kopii');
        $this->assertSame($first[0]->id, $second[0]->id);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists((string) $second[0]->path);
    }

    public function test_generated_card_from_a_shop_is_not_downloaded(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $product->save();
        Http::fake(['*' => Http::response("%PDF-1.4\n%%EOF\n", 200, ['Content-Type' => 'application/pdf'])]);

        $saved = app(ProductDocumentDownloader::class)->downloadMany(
            $product,
            ['https://bogarobhp.pl/modules/x13producttopdf/pdf.php?id_product=9'],
        );

        $this->assertSame([], $saved);
        Http::assertNothingSent();
    }

    private function product(): Product
    {
        return new Product(['sku' => '202', 'name' => 'Fartuch wodoochronny 120/75 PU Poliester', 'manufacturer' => 'AJ GROUP']);
    }

    /** @return array<string, mixed> */
    private function fetch(string $url, string $html, string $title = 'Fartuch model 202'): array
    {
        Http::fake([$url => Http::response($html, 200), '*' => Http::response('', 404)]);

        return app(ProductPageFetcher::class)->bypassCache()->fetch(
            [['url' => $url, 'title' => $title, 'snippet' => '']],
            '202',
            1,
            [],
            $this->product(),
        );
    }

    private function page(string $cardHref): string
    {
        return '<!doctype html><html lang="pl"><head><title>Fartuch model 202 - PROS</title></head><body>'
            .'<h1>Fartuch model 202</h1><p>SKU: 202-00005-75/75</p><p>PROS</p>'
            .'<div class="x13producttopdf"><a href="'.htmlspecialchars($cardHref).'">Pobierz kartę produktu w pliku PDF</a></div>'
            .'<div class="product-description"><p>Elegancki i bardzo lekki fartuch przedni model 202 w ciekawym designie. '
            .'Wykonany z lekkiego, zapewniającego wygodę użytkowania, materiału powleczonego poliuretanem. Przeznaczony '
            .'w szczególności dla pracowników barów i restauracji. Praktyczna regulacja paska szyjnego.</p></div>'
            .str_repeat('<p>Fartuch model 202 PROS — odzież wodoochronna dla gastronomii i przetwórstwa spożywczego.</p>', 12)
            .'</body></html>';
    }
}
