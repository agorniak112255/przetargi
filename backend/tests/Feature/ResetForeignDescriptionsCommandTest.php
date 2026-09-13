<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductEnrichmentCache;
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

        $this->artisan('products:reset-foreign-descriptions', ['--apply' => true])
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
