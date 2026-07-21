<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\BhpAttributeNormalizer;
use Illuminate\Console\Command;

final class BackfillBhpAttributesCommand extends Command
{
    protected $signature = 'products:backfill-bhp-attributes
                            {--force : Nadpisz istniejące attributes}
                            {--limit=0 : Maksymalna liczba aktualizacji (0 = bez limitu)}
                            {--report : Tylko raport pokrycia, bez zapisu}';

    protected $description = 'Uzupełnia enrichment_payload.attributes z opisu/nazwy/norm (bez ponownego AI)';

    public function handle(BhpAttributeNormalizer $normalizer): int
    {
        $force = (bool) $this->option('force');
        $reportOnly = (bool) $this->option('report');
        $limit = max(0, (int) $this->option('limit'));
        $updated = 0;
        $skipped = 0;
        $total = 0;
        $withUseful = 0;

        Product::query()
            ->orderBy('id')
            ->chunkById(100, function ($products) use (
                $normalizer,
                $force,
                $reportOnly,
                $limit,
                &$updated,
                &$skipped,
                &$total,
                &$withUseful,
            ): bool {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $total++;
                    $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
                    $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : null;
                    $hasAttrs = $this->hasUsefulAttributes($attrs);

                    if ($reportOnly) {
                        if ($hasAttrs) {
                            $withUseful++;
                        }

                        continue;
                    }

                    if ($hasAttrs && ! $force) {
                        $skipped++;

                        continue;
                    }

                    if ($limit > 0 && $updated >= $limit) {
                        return false;
                    }

                    $payload['attributes'] = $normalizer->forProduct($product);
                    $product->enrichment_payload = $payload;
                    $product->saveQuietly();
                    $updated++;
                }

                return true;
            });

        if ($reportOnly) {
            $this->info("Produkty: {$total}, z użytecznymi attributes: {$withUseful}, brak: ".($total - $withUseful));

            return self::SUCCESS;
        }

        $this->info("Zaktualizowano attributes: {$updated} (pominięto z atrybutami: {$skipped})");

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>|null  $attrs */
    private function hasUsefulAttributes(?array $attrs): bool
    {
        if ($attrs === null) {
            return false;
        }

        return ($attrs['material'] ?? null) !== null
            || ($attrs['kategoria_bhp'] ?? null) !== null
            || (is_array($attrs['normy_en'] ?? null) && $attrs['normy_en'] !== []);
    }
}
