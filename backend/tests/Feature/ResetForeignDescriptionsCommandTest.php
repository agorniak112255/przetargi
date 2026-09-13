<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Karta CERRO z opisem sklepu elektronarzędzi o JOKI (audyt: „unrelated”) wraca do kolejki
 * wzbogacania; karta z własnym opisem i karta z rozjazdem rodziny („family”) zostają nietknięte.
 */
final class ResetForeignDescriptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_but_does_not_change(): void
    {
        $foreign = $this->foreignCard();
        $own = $this->ownCard();

        $this->artisan('products:reset-foreign-descriptions')
            ->expectsOutputToContain('Do wyczyszczenia: 1 kart')
            ->assertSuccessful();

        $this->assertNotNull($foreign->fresh()->description);
        $this->assertSame(Product::ENRICHMENT_DONE, $foreign->fresh()->enrichment_status);
        $this->assertNotNull($own->fresh()->description);
    }

    public function test_apply_resets_only_cards_with_foreign_description(): void
    {
        $foreign = $this->foreignCard();
        $own = $this->ownCard();
        $familyMismatch = Product::query()->create([
            'sku' => 'HC24BLK',
            'name' => 'Poziomy system asekuracyjny do linek bezpieczeństwa 3M DBI-SALA',
            'manufacturer' => '3M',
            'description' => 'Hełm ochronny 3M DBI-SALA z regulacją — opis dotyczy hełmu, nie systemu asekuracyjnego.',
            'catalog_price_net' => 500,
            'purchase_price' => 400,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        ProductEnrichmentCache::query()->create([
            ...ProductEnrichmentCache::normalizeKey('Canis', '3410-140-410-00'),
            'description' => 'Centrum Elektronarzedzi — obcy opis JOKI',
            'source_urls' => ['https://example.com/joki'],
        ]);

        $this->artisan('products:reset-foreign-descriptions', ['--apply' => true, '--backup' => $this->backupPath()])
            ->expectsOutputToContain('Wyczyszczono 1 kart')
            ->assertSuccessful();

        $foreign->refresh();
        $this->assertNull($foreign->description);
        $this->assertNull($foreign->norms);
        $this->assertNull($foreign->enrichment_payload);
        $this->assertNull($foreign->shop_source_url);
        $this->assertNull($foreign->enriched_at);
        $this->assertSame(Product::ENRICHMENT_NONE, $foreign->enrichment_status);
        $this->assertSame(0, ProductEnrichmentCache::query()->count(), 'cache SKU→karta wskazywał obcą stronę');

        $this->assertNotNull($own->fresh()->description);
        $this->assertNotNull($familyMismatch->fresh()->description, 'rozjazd rodziny to inny powód — nie kasujemy automatycznie');
    }

    /** Batch #298: angielski zrzut coba.com (tabela części, cookies) zapisany jako opis wraca do pobrania. */
    public function test_apply_resets_page_dump_descriptions(): void
    {
        $dump = Product::query()->create([
            'sku' => 'DS0106',
            'name' => 'DeckStep Matting Czarny ~0.59m/0.6m x 10m (11.5mm)',
            'manufacturer' => 'Coba',
            'description' => "Parts\n\nPart Number\n\nSize\n\nColour\n\nDS010610\n\n0.59 m x 10 m\n\nBlack\n\nQty:\n\nRequest Price\n\n"
                .'DeckStep | Multi-purpose Ribbed Vinyl Matting | COBA. Raise workers off the ground like traditional duckboard.',
            'catalog_price_net' => 500,
            'purchase_price' => 400,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        ProductEnrichmentCache::query()->create([
            ...ProductEnrichmentCache::normalizeKey('Coba', 'DS0106'),
            'description' => (string) $dump->description,
            'source_urls' => ['https://www.coba.com/product/deckstep'],
        ]);
        $own = $this->ownCard();

        $this->artisan('products:audit-descriptions', ['--only' => 'page_dump'])
            ->expectsOutputToContain('DS0106')
            ->assertSuccessful();
        $this->artisan('products:reset-foreign-descriptions', ['--apply' => true, '--backup' => $this->backupPath()])
            ->expectsOutputToContain('Wyczyszczono 1 kart')
            ->assertSuccessful();

        $dump->refresh();
        $this->assertNull($dump->description);
        $this->assertSame(Product::ENRICHMENT_NONE, $dump->enrichment_status);
        $this->assertSame(0, ProductEnrichmentCache::query()->count(), 'zrzut w cache SKU kopiowałby się dalej');
        $this->assertNotNull($own->fresh()->description);
    }

    /**
     * Batch #312 (audyt z drugim agentem): tytuł strony sklepu jako opis — tylko page_dump, z kopią
     * zapasową, zdjęciem z sieci usuniętym i przywróceniem 1:1.
     */
    public function test_only_page_dump_with_backup_and_restore(): void
    {
        $titleDump = Product::query()->create([
            'sku' => '1010-130-260-00',
            'name' => 'Men´s jacket CXS SOLIS FLEX, red-black',
            'manufacturer' => 'Canis',
            'description' => "Kurtka polar CANIS CXS 4ENVI SOLIS szaro-czarna - BLUZY\nKurtka polar CANIS CXS 4ENVI SOLIS szaro-czarna\nKurtka polar CANIS CXS 4ENVI SOLIS szaro-czarna",
            'shop_source_url' => 'https://sklep.example.pl/kurtka-4envi-solis',
            'catalog_price_net' => 50,
            'purchase_price' => 40,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        ProductImage::query()->create([
            'product_id' => $titleDump->id, 'path' => 'products/'.$titleDump->id.'/4envi.jpg',
            'source_url' => 'https://sklep.example.pl/4envi.jpg', 'is_primary' => true, 'sort_order' => 0,
            'checksum' => str_repeat('d', 64),
        ]);
        // opis obcego wyrobu bez tytułu sklepu — powód „unrelated”, nie „page_dump”
        $foreign = Product::query()->create([
            'sku' => '3410-141-410-00',
            'name' => 'Rukavice CERRO, máčené v nitrilu BLISTR, modro-šedé',
            'manufacturer' => 'Canis',
            'description' => 'Rękawice JOKI powlekane w 3/4 nitrylem, dziane z poliestru, do prac montażowych.',
            'catalog_price_net' => 5,
            'purchase_price' => 3,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $backup = $this->backupPath();

        $this->artisan('products:reset-foreign-descriptions', ['--only' => 'page_dump', '--apply' => true, '--backup' => $backup])
            ->expectsOutputToContain('Wyczyszczono 1 kart')
            ->assertSuccessful();

        $this->assertNull($titleDump->fresh()->description);
        $this->assertSame(Product::ENRICHMENT_NONE, $titleDump->fresh()->enrichment_status);
        $this->assertSame(0, ProductImage::query()->count(), 'zdjęcie z tej samej złej karty');
        $this->assertNotNull($foreign->fresh()->description, '--only=page_dump nie rusza opisów „unrelated”');

        $this->artisan('products:reset-foreign-descriptions', ['--restore' => $backup])
            ->expectsOutputToContain('Przywrócono 1 kart')
            ->assertSuccessful();

        $titleDump->refresh();
        $this->assertStringStartsWith('Kurtka polar CANIS CXS 4ENVI SOLIS', (string) $titleDump->description);
        $this->assertSame(Product::ENRICHMENT_DONE, $titleDump->enrichment_status);
        $this->assertSame(1, ProductImage::query()->count());
        @unlink($backup);
    }

    private function backupPath(): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'reset-foreign-'.uniqid('', true).'.json';
    }

    private function foreignCard(): Product
    {
        return Product::query()->create([
            'sku' => '3410-140-410-00',
            'name' => 'Rukavice CERRO, máčené v nitrilu BLISTR, modro-šedé',
            'manufacturer' => 'Canis',
            'category' => null,
            'description' => 'Centrum Elektronarzedzi - Elektronarzędzia - Sklep online Rękawice JOKI powlekane w 3/4 nitryl 3410-005.',
            'norms' => 'EN 388',
            'shop_source_url' => 'https://example.com/joki',
            'enrichment_payload' => ['materials' => ['nitryl']],
            'catalog_price_net' => 5,
            'purchase_price' => 3,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }

    private function ownCard(): Product
    {
        return Product::query()->create([
            'sku' => '3420-123-000-07',
            'name' => 'Rukavice CXS MERU, polomáčené v latexu, BLISTR',
            'manufacturer' => 'Canis',
            'description' => 'Rękawice robocze Canis CXS MERU to bezszwowe, dziane rękawice powlekane lateksem.',
            'catalog_price_net' => 5,
            'purchase_price' => 3,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }
}
