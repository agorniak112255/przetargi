<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Enrichment\ProductImageRetry;
use Illuminate\Console\Command;

/**
 * Ponawia zdjęcia, których źródło chwilowo odmówiło przy przebiegu opisu (zapora ansell.com) — zob. ProductImageRetry.
 * Z harmonogramu co 3 h; --from-trace raz ręcznie dla kart sprzed zmiany.
 */
final class RetryProductImagesCommand extends Command
{
    protected $signature = 'products:retry-images
                            {--from-trace : Najpierw dopisz do kolejki karty sprzed zmiany (adresy ze śladu przebiegu)}
                            {--product=* : Tylko te karty (id)}
                            {--limit=40 : Ile kart w jednym przebiegu}
                            {--dry-run : Tylko pokaż, co byłoby ponawiane — bez zapisu i bez pobierania}';

    protected $description = 'Ponawia pobranie zdjęć, które źródło chwilowo zablokowało (np. zapora ansell.com)';

    /** Odstęp między kartami — ta sama zapora liczy tempo zapytań z naszego adresu. */
    private const PAUSE_MICROS = 3_000_000;

    public function handle(ProductImageRetry $retry): int
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('product'))));
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        if ($this->option('from-trace')) {
            $scheduled = 0;
            $query = $retry->legacyCandidates();
            if ($ids !== []) {
                $query->whereIn('id', $ids);
            }
            foreach ($query->lazyById(200) as $product) {
                $urls = $retry->urlsFromTrace($product);
                if ($urls === []) {
                    continue;
                }
                if (! $dryRun && ! $retry->schedule($product, $urls)) {
                    continue;
                }
                $scheduled++;
                $this->line("  #{$product->id} {$product->sku}: ".count($urls).' adres(y) ze śladu');
            }
            $this->info(($dryRun ? 'Do dopisania' : 'Dopisano').' ze śladu: '.$scheduled.' kart.');
        }

        $query = $retry->pending()->reorder('updated_at')->orderBy('id');
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }
        $waiting = (clone $query)->count();
        // same numery: kartę czytamy dopiero przed jej próbą — przebieg trwa do kilkudziesięciu minut
        $productIds = $query->limit($limit)->pluck('id')->all();
        if ($dryRun) {
            foreach (Product::query()->whereKey($productIds)->orderBy('id')->get() as $product) {
                $state = $product->enrichment_payload[ProductImageRetry::PAYLOAD_KEY] ?? [];
                $this->line("  #{$product->id} {$product->sku}: próba ".((int) ($state['attempts'] ?? 0) + 1));
            }
            $this->info("Czeka na ponowienie: {$waiting} kart (bez pobierania — --dry-run).");

            return self::SUCCESS;
        }

        $counts = ['saved' => 0, 'waiting' => 0, 'gave_up' => 0, 'skipped' => 0];
        foreach ($productIds as $i => $id) {
            if ($i > 0 && ! app()->environment('testing')) {
                usleep(self::PAUSE_MICROS);
            }
            $product = Product::query()->find($id);
            if ($product === null) {
                continue;
            }
            $result = $retry->retry($product);
            $counts[$result]++;
            $this->line("  #{$product->id} {$product->sku}: ".match ($result) {
                'saved' => 'zdjęcie pobrane',
                'waiting' => 'nadal zablokowane, ponowimy',
                'gave_up' => 'koniec ponawiania',
                'skipped' => 'pominięte (karta ma już zdjęcie, zmieniła się w trakcie albo brak adresów)',
            });
        }

        $this->info(sprintf(
            'Czekało %d kart, sprawdzono %d: pobrane %d, ponowimy %d, koniec %d, pominięte %d.',
            $waiting,
            count($productIds),
            $counts['saved'],
            $counts['waiting'],
            $counts['gave_up'],
            $counts['skipped'],
        ));

        return self::SUCCESS;
    }
}
