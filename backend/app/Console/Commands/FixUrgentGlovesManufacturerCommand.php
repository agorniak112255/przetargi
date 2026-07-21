<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Enrichment\ProductSearchIdentity;
use Illuminate\Console\Command;

/**
 * Cennik PROS często zawiera serię rękawic URGENT (kody 1000…) pod złą marką.
 */
final class FixUrgentGlovesManufacturerCommand extends Command
{
    protected $signature = 'products:fix-urgent-gloves
        {--dry-run : Tylko podgląd, bez zapisu}
        {--rename-sku : Zmień SKU PROS-1000 → URGENT-1000 gdy wolne}';

    protected $description = 'Ustaw manufacturer=URGENT dla rękawic błędnie zaimportowanych jako PROS';

    public function handle(ProductSearchIdentity $identity): int
    {
        $dry = (bool) $this->option('dry-run');
        $renameSku = (bool) $this->option('rename-sku');
        $updated = 0;

        Product::query()
            ->where(function ($q): void {
                $q->where('manufacturer', 'PROS')
                    ->orWhere('manufacturer', 'like', 'PROS%');
            })
            ->where(function ($q): void {
                $q->where('category', 'like', '%REKAW%')
                    ->orWhere('category', 'like', '%RĘKAW%')
                    ->orWhere('category', 'like', '%rekaw%');
            })
            ->orderBy('id')
            ->each(function (Product $product) use ($identity, $dry, $renameSku, &$updated): void {
                if (! $identity->looksLikeUrgentGloveSeries($product)) {
                    return;
                }
                $code = $identity->gloveCodeCore($product);
                $newSku = $renameSku && $code !== null ? 'URGENT-'.$code : null;
                if ($newSku !== null && Product::query()->where('sku', $newSku)->where('id', '!=', $product->id)->exists()) {
                    $newSku = null;
                }

                $this->line(sprintf(
                    '%s → URGENT%s',
                    $product->sku,
                    $newSku !== null ? " (sku {$newSku})" : ''
                ));

                if ($dry) {
                    $updated++;

                    return;
                }

                $payload = ['manufacturer' => 'URGENT'];
                if ($newSku !== null) {
                    $payload['sku'] = $newSku;
                }
                // wyczyść błędne enrichment spodniobutów itd.
                $payload['description'] = null;
                $payload['enrichment_status'] = Product::ENRICHMENT_NONE;
                $payload['enrichment_payload'] = null;
                $payload['enrichment_error'] = null;
                $payload['enriched_at'] = null;
                $payload['norms'] = null;
                $product->images()->delete();
                $product->documents()->delete();
                $product->update($payload);
                $updated++;
            });

        $this->info(($dry ? 'Do zmiany: ' : 'Zaktualizowano: ').$updated);

        return self::SUCCESS;
    }
}
