<?php

declare(strict_types=1);

use App\Models\ProductAccessory;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $ids = ProductAccessory::query()
            ->where('source', ProductAccessory::SOURCE_ENRICHMENT)
            ->whereIn('method', ['fuzzy_model', 'pending'])
            ->get(['id', 'related_sku'])
            ->filter(static function (ProductAccessory $row): bool {
                $sku = trim((string) $row->related_sku);

                return $sku === '' || preg_match('/\d/', $sku) !== 1;
            })
            ->pluck('id')
            ->all();
        if ($ids !== []) {
            ProductAccessory::query()->whereIn('id', $ids)->delete();
        }
    }

    public function down(): void
    {
    }
};
