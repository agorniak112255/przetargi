<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ProductCatalogHealthService;
use Illuminate\Console\Command;

final class BackfillProductSizesCommand extends Command
{
    protected $signature = 'products:backfill-sizes
                            {--manufacturer= : Tylko ten producent}';

    protected $description = 'Uzupełnia packaging z już zapisanych opisów (bez AI i sieci)';

    public function handle(ProductCatalogHealthService $health): int
    {
        $manufacturer = $this->option('manufacturer');
        $manufacturer = is_string($manufacturer) && trim($manufacturer) !== '' ? trim($manufacturer) : null;
        $result = $health->backfillSizesFromDescriptions($manufacturer);
        $this->info(
            "Z opisów uzupełniono {$result['updated']} produktów"
            ." (skan {$result['scanned']}, bez zmian {$result['skipped']})."
        );

        return self::SUCCESS;
    }
}
