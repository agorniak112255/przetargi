<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\BhpAttributeNormalizer;
use Illuminate\Console\Command;

final class BackfillBhpAttributesCommand extends Command
{
    protected $signature = 'products:backfill-bhp-attributes {--force : Nadpisz istniejące attributes}';

    protected $description = 'Uzupełnia enrichment_payload.attributes z list enrichment (bez ponownego AI)';

    public function handle(BhpAttributeNormalizer $normalizer): int
    {
        $force = (bool) $this->option('force');
        $updated = 0;

        Product::query()
            ->whereNotNull('enrichment_payload')
            ->orderBy('id')
            ->chunkById(100, function ($products) use ($normalizer, $force, &$updated): void {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
                    $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : null;
                    $hasAttrs = $attrs !== null && (
                        ($attrs['material'] ?? null) !== null
                        || ($attrs['kategoria_bhp'] ?? null) !== null
                        || (is_array($attrs['normy_en'] ?? null) && $attrs['normy_en'] !== [])
                    );

                    if ($hasAttrs && ! $force) {
                        continue;
                    }

                    $payload['attributes'] = $normalizer->forProduct($product);
                    $product->enrichment_payload = $payload;
                    $product->saveQuietly();
                    $updated++;
                }
            });

        $this->info("Zaktualizowano attributes: {$updated}");

        return self::SUCCESS;
    }
}
