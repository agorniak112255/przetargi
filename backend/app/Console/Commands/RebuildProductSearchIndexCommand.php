<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\ProductSearchBlob;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RebuildProductSearchIndexCommand extends Command
{
    protected $signature = 'products:rebuild-search-index
        {--force : Przelicz też karty, których blob się nie zmienił}';

    protected $description = 'Przelicza kolumnę search_blob i rodzinę PPE dla wyszukiwania pełnotekstowego';

    public function handle(ProductSearchBlob $builder): int
    {
        $total = Product::query()->count();
        if ($total === 0) {
            $this->info('Brak produktów — nic do przeliczenia.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $bar = $this->output->createProgressBar($total);
        $bar->start();
        $updated = 0;

        Product::query()
            ->orderBy('id')
            ->chunkById(500, function (Collection $chunk) use ($builder, $force, $bar, &$updated): void {
                DB::transaction(function () use ($chunk, $builder, $force, $bar, &$updated): void {
                    foreach ($chunk as $product) {
                        $bar->advance();
                        if (! $product instanceof Product) {
                            continue;
                        }
                        $fresh = $builder->build($product);
                        if (! $force && $product->search_blob_hash === $fresh['search_blob_hash']) {
                            continue;
                        }
                        // Pomijamy zdarzenia modelu — blob jest już policzony, a zapis
                        // przez save() uruchomiłby budowanie drugi raz.
                        DB::table('products')->where('id', $product->id)->update($fresh);
                        $updated++;
                    }
                });
            });

        $bar->finish();
        $this->newLine(2);
        $this->info("Przeliczono {$updated} z {$total} kart.");

        return self::SUCCESS;
    }
}
