<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ProductSizeMergeService;
use Illuminate\Console\Command;

final class MergeProductSizeVariantsCommand extends Command
{
    protected $signature = 'products:merge-size-variants
        {--manufacturer= : Tylko ten producent}
        {--dry-run : Tylko podgląd, bez usuwania}';

    protected $description = 'Scala produkty różniące się tylko rozmiarem (zostawia kartę z opisem i zdjęciem)';

    public function handle(ProductSizeMergeService $merge): int
    {
        $manufacturer = $this->option('manufacturer');
        $dry = (bool) $this->option('dry-run');
        $result = $merge->merge(
            is_string($manufacturer) && $manufacturer !== '' ? $manufacturer : null,
            $dry,
        );

        $this->info(
            ($dry ? 'Podgląd: ' : '')
            .'grup '. $result['groups']
            .', do usunięcia '.$result['deleted'].' SKU.'
        );
        foreach ($result['examples'] as $row) {
            $this->line('  '.$row['keep'].' ← '.implode(', ', $row['drop']));
        }
        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        return self::SUCCESS;
    }
}
