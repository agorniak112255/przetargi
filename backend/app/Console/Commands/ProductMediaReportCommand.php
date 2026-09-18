<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Co zajmuje miejsce w `storage/app/public/products` i co z tego jest zbędne.
 *
 * Powód: na serwerze ten katalog urósł do 26 GB przy 28 GB całej aplikacji.
 * Zanim cokolwiek zniknie, trzeba wiedzieć, ile z tego jest *używane* — czyli
 * wskazane przez wiersz w `product_images` albo `product_documents` — a ile to
 * pozostałości po nieudanym albo powtórzonym wzbogacaniu i po skasowanych
 * kartach.
 *
 * Domyślnie polecenie **tylko liczy i wypisuje**. Kasuje wyłącznie po jawnym
 * `--apply`, i to wyłącznie pliki, do których nie prowadzi żaden wiersz w bazie
 * — plik używany przez kartę nie zostanie ruszony nigdy.
 */
final class ProductMediaReportCommand extends Command
{
    protected $signature = 'products:media-report
                            {--apply : Skasuj osierocone pliki (bez tej flagi tylko raport)}
                            {--top=15 : Ile największych katalogów wypisać}';

    protected $description = 'Pokazuje, co zajmuje miejsce w plikach kart wyrobów (kasuje osierocone tylko z --apply)';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        if (! $disk->exists('products')) {
            $this->info('Katalogu products nie ma — nie ma czego liczyć.');

            return self::SUCCESS;
        }

        $this->line('Czytam pliki…');
        $files = $disk->allFiles('products');

        // Ścieżki, do których prowadzi wiersz w bazie — te są używane.
        $used = ProductImage::query()->pluck('path')
            ->merge(ProductDocument::query()->pluck('path'))
            ->filter()
            ->map(static fn (string $path): string => ltrim($path, '/'))
            ->flip();

        $live = Product::query()->pluck('id')->flip();

        $total = 0;
        $usedBytes = 0;
        $orphanBytes = 0;
        $goneBytes = 0;
        $orphans = [];
        $byFolder = [];

        foreach ($files as $path) {
            $size = (int) $disk->size($path);
            $total += $size;

            $folder = $this->productFolder($path);
            $byFolder[$folder] = ($byFolder[$folder] ?? 0) + $size;

            if ($used->has($path)) {
                $usedBytes += $size;

                continue;
            }

            // Plik bez wiersza w bazie: albo karta została skasowana, albo
            // pobieranie nie doszło do zapisu w bazie.
            $productId = (int) $folder;
            if ($productId > 0 && ! $live->has($productId)) {
                $goneBytes += $size;
            } else {
                $orphanBytes += $size;
            }
            $orphans[] = $path;
        }

        $this->newLine();
        $this->line('Plików w products:      '.count($files));
        $this->line('Razem:                  '.$this->mb($total));
        $this->line('Używane przez karty:    '.$this->mb($usedBytes));
        $this->line('Po skasowanych kartach: '.$this->mb($goneBytes));
        $this->line('Bez wiersza w bazie:    '.$this->mb($orphanBytes));
        $this->line('Do odzyskania razem:    '.$this->mb($goneBytes + $orphanBytes)
            .' ('.count($orphans).' plików)');

        arsort($byFolder);
        $this->newLine();
        $this->line('Największe katalogi kart:');
        foreach (array_slice($byFolder, 0, (int) $this->option('top'), true) as $folder => $size) {
            $exists = ((int) $folder) > 0 && $live->has((int) $folder);
            $this->line('  products/'.$folder.'  '.$this->mb($size).($exists ? '' : '   (karty już nie ma)'));
        }

        if ($orphans === []) {
            $this->newLine();
            $this->info('Nie ma czego kasować — każdy plik ma swój wiersz w bazie.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('Raport — nic nie skasowano. Żeby usunąć osierocone pliki: '
                .'php artisan products:media-report --apply');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Kasuję osierocone pliki…');
        $removed = 0;
        foreach ($orphans as $path) {
            if ($disk->delete($path)) {
                $removed++;
            }
        }
        $this->info('Skasowano '.$removed.' plików, odzyskano '.$this->mb($goneBytes + $orphanBytes).'.');

        return self::SUCCESS;
    }

    /** Pierwszy człon ścieżki po „products/” — numer karty wyrobu. */
    private function productFolder(string $path): string
    {
        $rest = substr($path, strlen('products/'));
        $at = strpos($rest, '/');

        return $at === false ? $rest : substr($rest, 0, $at);
    }

    private function mb(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024 * 1024), 2, ',', ' ').' GB';
        }

        return number_format($bytes / (1024 * 1024), 1, ',', ' ').' MB';
    }
}
