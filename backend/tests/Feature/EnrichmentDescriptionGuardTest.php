<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductEnrichmentCache;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductSearchIdentity;
use App\Support\ProductDescriptionText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Batch #298 (Coba): opis DeckStep zapisał się jako angielski zrzut coba.com — tabela części,
 * zakładki i zgody na cookies — zamiast polskiego opisu według szablonu.
 */
final class EnrichmentDescriptionGuardTest extends TestCase
{
    use RefreshDatabase;

    private const PARTS_DUMP = "Parts\n\n Technical Specification\n\n Downloads\n\n Test Certificates\n\n"
        ." Parts\n\nPart Number \n\n Size \n\n Colour \n\n Weight (kg)\n\n Price Request \n\n View Retailer\n\n"
        ." DS061210C \n\n 1.2 m x per linear metre \n\n Grey \n\n 9 \n\n Qty: \n\n Request Price\n\n"
        ."DeckStep | Multi-purpose Ribbed Vinyl Matting | COBA\nDeckStep Industrial Matting\n"
        ."Raise workers off the ground like traditional duckboard. Reduce the risk of slips by removing feet from direct contact with spilt liquids.\n\n"
        ."Advertising cookies, including retargeting scripts, tailor ads to your interests based on your online activity, enhancing your browsing experience. You can opt-out here.\n\n"
        ." DS010610 \n\n 0.59 m x 10 m \n\n Black \n\n 45 \n\n Qty: \n\n Request Price\n\n"
        ."Provides a much more comfortable standing surface than concrete.\n\nFlexible and lightweight – easy to move and clean.";

    private const POLISH = 'Mata DeckStep marki COBA Europe to wielofunkcyjna wykładzina z żebrowanego winylu, która unosi '
        .'pracownika nad podłożem jak tradycyjny ruszt. Ogranicza ryzyko poślizgu, oddzielając stopy od rozlanych cieczy. '
        .'Zapewnia wygodniejszą powierzchnię do stania niż beton. Jest elastyczna i lekka, dzięki czemu łatwo ją przenosić '
        .'i czyścić. Pasy są zgrzewane, co zwiększa wytrzymałość maty.';

    public function test_english_parts_table_dump_is_not_a_usable_description(): void
    {
        $product = $this->deckStep();

        $this->assertFalse($this->usable(self::PARTS_DUMP, $product), 'tabela części i cookies to nie opis');
        $this->assertFalse($this->usable(
            'DeckStep multi-purpose ribbed vinyl matting from COBA is designed for industrial areas where the floor '
            .'is wet. It raises workers off the ground and provides a comfortable standing surface. The strips are welded '
            .'together for strength, and the mat is flexible and lightweight, which makes it easy to move and clean.',
            $product
        ), 'angielski opis bez tabeli też nie jest opisem według szablonu');
    }

    /** Audyt produkcji oflagował poprawne polskie opisy przez nagłówki sekcji — to nie zrzut strony. */
    public function test_polish_descriptions_with_section_headers_are_not_dumps(): void
    {
        foreach ([
            'Specyfikacja techniczna: materiał — nylon z podkładem z PVC; podkład — PVC; wykończenie powierzchni — cięte. '
                .'Wycieraczka zatrzymuje brud i wilgoć przy wejściu do budynku, a gumowy spód zapobiega jej przesuwaniu.',
            'Numer części: NP060003. Wymiary: 1 m x 21 m, kolor szary. Specyfikacja techniczna: Materiał: PP (polipropylen). '
                .'Pliki do pobrania: karta techniczna. Mata sprawdza się w strefach wejściowych o dużym natężeniu ruchu.',
            '* **Specjalistyczna ochrona:** Lekki kombinezon ochronny AlphaTec™ 1800 Ts PLUS Stitched & Taped chroni przed '
                .'cząstkami stałymi i cieczami o niskim stopniu zagrożenia. * **Zwiększona oddychalność:** Mikroporowata tkanina '
                .'laminowana zapewnia przepuszczalność powietrza. The fabric is suitable for pharmaceutical and food processing, '
                .'for spray painting and for maintenance of the equipment, with taped seams for the best protection of the user.',
        ] as $text) {
            $this->assertFalse(ProductDescriptionText::looksLikeForeignOrPartsTableDump($text), mb_substr($text, 0, 60));
        }
        $this->assertTrue(ProductDescriptionText::looksLikeForeignOrPartsTableDump(self::PARTS_DUMP));
    }

    public function test_polish_family_description_with_mata_is_usable(): void
    {
        // „DeckStep Matting” wymaga typu mata; polski opis mówi „mata”/„wykładzina”, nie „chodnik”
        $this->assertTrue($this->usable(self::POLISH, $this->deckStep()));
    }

    public function test_mat_type_needs_the_word_not_a_substring(): void
    {
        $identity = app(ProductSearchIdentity::class);
        $product = $this->deckStep();

        $this->assertTrue($identity->hayHasRequiredTypeFromName('Mata DeckStep z winylu', $product));
        $this->assertTrue($identity->hayHasRequiredTypeFromName('Wykładzina DeckStep z winylu', $product));
        $this->assertFalse($identity->hayHasRequiredTypeFromName('DeckStep automatyczna, matowa powierzchnia', $product));
    }

    public function test_dump_in_sku_cache_is_not_copied_to_the_product(): void
    {
        $product = Product::query()->create([
            'sku' => 'DS0106',
            'name' => 'DeckStep Matting Czarny ~0.59m/0.6m x 10m (11.5mm)',
            'manufacturer' => 'Coba',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_QUEUED,
        ]);
        ProductEnrichmentCache::query()->create(ProductEnrichmentCache::normalizeKey('Coba', 'DS0106') + [
            'description' => self::PARTS_DUMP,
            'enrichment_payload' => ['features' => ['Zgrzewane pasy'], 'source_urls' => ['https://www.coba.com/product/deckstep']],
            'image_urls' => [],
            'source_urls' => ['https://www.coba.com/product/deckstep'],
        ]);

        $apply = new ReflectionMethod(ProductEnrichmentService::class, 'applyFromSkuCache');
        $applied = $apply->invoke(app(ProductEnrichmentService::class), $product);

        $this->assertFalse($applied, 'zrzut z cache ma wymusić normalne pobranie');
        $product->refresh();
        $this->assertSame(Product::ENRICHMENT_QUEUED, $product->enrichment_status);
        $this->assertNull($product->description);
    }

    private function deckStep(): Product
    {
        return new Product([
            'sku' => 'DS0106',
            'name' => 'DeckStep Matting Czarny ~0.59m/0.6m x 10m (11.5mm)',
            'manufacturer' => 'Coba',
        ]);
    }

    private function usable(string $description, Product $product): bool
    {
        $method = new ReflectionMethod(ProductEnrichmentService::class, 'isUsableProductDescription');

        return (bool) $method->invoke(app(ProductEnrichmentService::class), $description, $product);
    }
}
