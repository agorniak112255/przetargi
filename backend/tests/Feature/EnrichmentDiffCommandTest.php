<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Po ponownym pobraniu całych cenników trzeba wiedzieć, co naprawdę się zmieniło, zanim karty
 * zobaczy człowiek. Polecenie zestawia kopię zapasową sprzed pobrania z obecnym stanem bazy:
 * opisy, zdjęcia, źródła i zdublowane normy — i wypisuje karty, które opis STRACIŁY.
 */
final class EnrichmentDiffCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_what_changed_and_names_cards_that_lost_the_description(): void
    {
        $poprawiona = $this->card('34285018', 'ALTO 285', 'Nowy opis rękawicy z karty producenta.', [
            'source_urls' => ['https://www.mapa-pro.pl/produkty/chemioodporne/strona-produktu/alto-285'],
            'norms' => ['EN 388:2016', 'EN ISO 374-1 Typ A'],
        ]);
        ProductImage::query()->create([
            'product_id' => $poprawiona->id,
            'path' => 'products/1/a.jpg',
            'source_url' => 'https://www.mapa-pro.pl/a.jpg',
            'is_primary' => true,
            'sort_order' => 0,
            'checksum' => str_repeat('a', 64),
        ]);
        $pusta = $this->card('34358008', 'TITAN 375', null, null);

        $backup = storage_path('app/repair-backups/test-diff.json');
        @mkdir(dirname($backup), 0775, true);
        file_put_contents($backup, json_encode([
            'label' => 'recheck-skus',
            'products' => [
                [
                    'product' => [
                        'id' => $poprawiona->id,
                        'sku' => '34285018',
                        'description' => 'Stary opis z hurtowni.',
                        'enrichment_payload' => [
                            'source_urls' => ['https://migracja.supon.rzeszow.pl/2809-rekawice.html'],
                            'norms' => ['EN 388', 'EN 388:2016', 'EN ISO 374-1 Typ A'],
                        ],
                    ],
                    'images' => [],
                    'documents' => [],
                ],
                [
                    'product' => [
                        'id' => $pusta->id,
                        'sku' => '34358008',
                        'description' => 'Opis, który zaraz zniknie.',
                        'enrichment_payload' => ['source_urls' => []],
                    ],
                    'images' => [],
                    'documents' => [],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));

        // Artisan::call i porównanie całego wyjścia: wszystkie liczby stoją w JEDNYM wierszu
        // tabeli, a expectsOutputToContain dopasowuje kolejne oczekiwania do kolejnych wierszy.
        $code = Artisan::call('products:enrichment-diff', ['--backup' => [$backup]]);
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('2 → 1', $output, 'opis: dwie karty miały, została jedna');
        $this->assertStringContainsString('0 → 1', $output, 'zdjęcie i źródło producenta: z zera na jeden');
        $this->assertStringContainsString('1 → 0', $output, 'dane z migracji i zdublowane normy zniknęły');
        $this->assertStringContainsString('Straciły opis (1): 34358008', $output);

        @unlink($backup);
    }

    public function test_without_any_backup_it_refuses(): void
    {
        $this->artisan('products:enrichment-diff', ['--backup' => [storage_path('app/repair-backups/nie-ma.json')]])
            ->expectsOutputToContain('Nie znalazłem kopii zapasowej')
            ->assertFailed();
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function card(string $sku, string $name, ?string $description, ?array $payload): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'MAPA',
            'category' => 'Środki ochrony indywidualnej',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
            'description' => $description,
            'enrichment_payload' => $payload,
            'enrichment_status' => $description !== null ? Product::ENRICHMENT_DONE : Product::ENRICHMENT_NONE,
        ]);
    }
}
