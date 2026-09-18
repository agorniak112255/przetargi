<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `products:media-report` — co zajmuje miejsce w plikach kart wyrobów.
 *
 * Reguła nadrzędna tego polecenia: plik wskazany przez wiersz w bazie jest
 * **nietykalny**. Kasowane są wyłącznie pozostałości — pliki, do których nic
 * nie prowadzi — i tylko po jawnym `--apply`.
 */
final class ProductMediaReportCommandTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Karta '.$sku,
            'manufacturer' => 'Supon',
        ]);
    }

    public function test_report_counts_used_and_orphaned_files_without_deleting(): void
    {
        Storage::fake('public');
        $product = $this->product('A-1');

        Storage::disk('public')->put('products/'.$product->id.'/uzywane.jpg', 'x');
        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/'.$product->id.'/uzywane.jpg',
        ]);
        // Pozostałość po nieudanym pobraniu — nie ma na nią wiersza w bazie.
        Storage::disk('public')->put('products/'.$product->id.'/smiec.jpg', 'yy');
        // Katalog po karcie, której już nie ma.
        Storage::disk('public')->put('products/999999/po-skasowanej.jpg', 'zzz');

        $this->artisan('products:media-report')
            ->expectsOutputToContain('Plików w products:      3')
            ->expectsOutputToContain('Raport — nic nie skasowano')
            ->assertExitCode(0);

        // Bez --apply nie ginie nic, nawet śmieci.
        Storage::disk('public')->assertExists('products/'.$product->id.'/uzywane.jpg');
        Storage::disk('public')->assertExists('products/'.$product->id.'/smiec.jpg');
        Storage::disk('public')->assertExists('products/999999/po-skasowanej.jpg');
    }

    public function test_apply_removes_only_files_without_a_row_in_the_database(): void
    {
        Storage::fake('public');
        $product = $this->product('A-2');

        Storage::disk('public')->put('products/'.$product->id.'/zdjecie.jpg', 'x');
        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/'.$product->id.'/zdjecie.jpg',
        ]);
        Storage::disk('public')->put('products/'.$product->id.'/docs/karta.pdf', 'x');
        ProductDocument::query()->create([
            'product_id' => $product->id,
            'path' => 'products/'.$product->id.'/docs/karta.pdf',
            'source_url' => 'https://example.test/karta.pdf',
        ]);
        Storage::disk('public')->put('products/'.$product->id.'/smiec.jpg', 'y');
        Storage::disk('public')->put('products/888888/po-skasowanej.jpg', 'z');

        $this->artisan('products:media-report', ['--apply' => true])
            ->expectsOutputToContain('Skasowano 2 plików')
            ->assertExitCode(0);

        // Wszystko, co wskazuje baza, zostaje.
        Storage::disk('public')->assertExists('products/'.$product->id.'/zdjecie.jpg');
        Storage::disk('public')->assertExists('products/'.$product->id.'/docs/karta.pdf');
        // Pozostałości znikają.
        Storage::disk('public')->assertMissing('products/'.$product->id.'/smiec.jpg');
        Storage::disk('public')->assertMissing('products/888888/po-skasowanej.jpg');
    }

    public function test_nothing_to_clean_is_said_plainly(): void
    {
        Storage::fake('public');
        $product = $this->product('A-3');

        Storage::disk('public')->put('products/'.$product->id.'/zdjecie.jpg', 'x');
        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/'.$product->id.'/zdjecie.jpg',
        ]);

        $this->artisan('products:media-report', ['--apply' => true])
            ->expectsOutputToContain('Nie ma czego kasować')
            ->assertExitCode(0);

        Storage::disk('public')->assertExists('products/'.$product->id.'/zdjecie.jpg');
    }
}
