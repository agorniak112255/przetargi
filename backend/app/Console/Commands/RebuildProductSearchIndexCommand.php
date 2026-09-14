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
        /** @var list<array{0: string, 1: string, 2: string, 3: string}> $familyChanges */
        $familyChanges = [];

        Product::query()
            ->orderBy('id')
            ->chunkById(500, function (Collection $chunk) use ($builder, $force, $bar, &$updated, &$familyChanges): void {
                DB::transaction(function () use ($chunk, $builder, $force, $bar, &$updated, &$familyChanges): void {
                    foreach ($chunk as $product) {
                        $bar->advance();
                        if (! $product instanceof Product) {
                            continue;
                        }
                        $fresh = $builder->build($product);
                        // Rodzina liczy się z reguł, nie tylko z tekstu: nowa reguła (rękaw → rękawice) zmienia rodzinę
                        // przy tym samym blobie, a pominięcie po samym hashu zostawiało starą rodzinę do następnej edycji karty.
                        $familyChanged = $product->ppe_family !== $fresh['ppe_family'];
                        if (! $force && ! $familyChanged && $product->search_blob_hash === $fresh['search_blob_hash']) {
                            continue;
                        }
                        if ($familyChanged) {
                            $familyChanges[] = [
                                (string) $product->sku,
                                mb_substr((string) $product->name, 0, 60),
                                (string) ($product->ppe_family ?? '—'),
                                (string) ($fresh['ppe_family'] ?? '—'),
                            ];
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
        $this->info('Karty ze zmienioną rodziną: '.count($familyChanges));
        if ($familyChanges !== []) {
            $this->table(['SKU', 'Nazwa', 'Było', 'Jest'], array_slice($familyChanges, 0, 50));
        }

        return self::SUCCESS;
    }
}
