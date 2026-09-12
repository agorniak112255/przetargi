<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\ProductSizeVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Usuwa z nazw tylko etykietę Size/Rozmiar/Taille + numer — nie gołe XL/S/M.
 */
final class StripProductSizeLabelsCommand extends Command
{
    protected $signature = 'products:strip-size-labels
        {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}';

    protected $description = 'Usuwa „Size 10,0” / „Rozmiar 9” z nazw produktów';

    public function handle(ProductSizeVariant $sizes): int
    {
        $apply = (bool) $this->option('apply');
        $changes = [];

        Product::query()
            ->orderBy('id')
            ->each(function (Product $product) use ($sizes, &$changes): void {
                $old = trim((string) $product->name);
                $new = $sizes->stripSizeLabelFromName($old);
                if ($new === '' || $new === $old) {
                    return;
                }
                $changes[] = [
                    'id' => (int) $product->id,
                    'sku' => (string) $product->sku,
                    'old' => $old,
                    'new' => $new,
                ];
            });

        $this->info(($apply ? 'Do zapisu: ' : 'Podgląd: ').count($changes).' nazw');
        foreach (array_slice($changes, 0, 40) as $row) {
            $this->line($row['sku'].' | '.$row['old'].' → '.$row['new']);
        }
        if (count($changes) > 40) {
            $this->line('… i '.(count($changes) - 40).' kolejnych');
        }

        if (! $apply || $changes === []) {
            if (! $apply && $changes !== []) {
                $this->comment('Żeby zapisać: products:strip-size-labels --apply');
            }

            return self::SUCCESS;
        }

        DB::transaction(function () use ($changes): void {
            foreach ($changes as $row) {
                Product::query()->whereKey($row['id'])->update(['name' => $row['new']]);
            }
        });
        $this->info('Zapisano '.count($changes).' nazw.');

        return self::SUCCESS;
    }
}
