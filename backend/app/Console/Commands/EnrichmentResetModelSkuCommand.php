<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductEnrichmentCache;
use App\Services\Enrichment\ProductSearchIdentity;
use Illuminate\Console\Command;

/**
 * Kody typu „COUPURE-IT11” trafiały wcześniej na dowolną kartę tej samej marki,
 * więc zapisane opisy potrafią dotyczyć innego produktu. Ta komenda je kasuje,
 * żeby dało się je pobrać ponownie po poprawce dopasowania.
 */
final class EnrichmentResetModelSkuCommand extends Command
{
    protected $signature = 'enrichment:reset-model-sku
        {--apply : Bez tej flagi komenda tylko liczy, niczego nie kasuje}';

    protected $description = 'Czyści opisy produktów, których kod niesie nazwę modelu (COUPURE-IT11)';

    public function handle(ProductSearchIdentity $identity): int
    {
        $apply = (bool) $this->option('apply');
        $matched = 0;
        $cleared = 0;

        Product::query()
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->chunkById(500, function ($products) use ($identity, $apply, &$matched, &$cleared): void {
                foreach ($products as $product) {
                    if ($identity->internalSkuCore($product) === '') {
                        continue;
                    }
                    $matched++;
                    if (! $apply) {
                        continue;
                    }

                    $key = ProductEnrichmentCache::normalizeKey(
                        (string) $product->manufacturer,
                        (string) $product->sku
                    );
                    ProductEnrichmentCache::query()
                        ->where('manufacturer', $key['manufacturer'])
                        ->where('sku', $key['sku'])
                        ->delete();

                    $product->update([
                        'description' => null,
                        'enrichment_payload' => null,
                        'enrichment_status' => Product::ENRICHMENT_NONE,
                        'enriched_at' => null,
                        'enrichment_error' => null,
                    ]);
                    $cleared++;
                }
            });

        $this->info($apply
            ? "Wyczyszczono {$cleared} produktów (z cache SKU włącznie)."
            : "Do wyczyszczenia: {$matched} produktów. Uruchom z --apply, żeby skasować opisy.");

        return self::SUCCESS;
    }
}
