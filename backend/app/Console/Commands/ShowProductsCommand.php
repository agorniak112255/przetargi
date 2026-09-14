<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Karta produktu w terminalu, tylko do odczytu. Przetarg 1 (pomiar 14.09): na szczycie oceny modelu pojawiły się karty,
 * których nie było w kopii katalogu z dnia wcześniej (Canis 2136-025-808-00, 4510-016-000-00) — daty utworzenia, pobrania
 * opisu i zmiany rozstrzygają, czy pogorszenie pomiaru to zmiana katalogu, czy kodu.
 */
final class ShowProductsCommand extends Command
{
    protected $signature = 'products:show {sku* : SKU jednej lub kilku kart}';

    protected $description = 'Pokazuje karty produktów: nazwa, producent, cena, normy, początek opisu i daty (tylko odczyt)';

    public function handle(): int
    {
        $skus = array_values(array_unique(array_map('strval', (array) $this->argument('sku'))));
        $products = Product::query()->whereIn('sku', $skus)->get()->keyBy('sku');
        foreach ($skus as $sku) {
            $product = $products->get($sku);
            if (! $product instanceof Product) {
                $this->warn("{$sku}: brak karty w katalogu");

                continue;
            }
            $description = trim((string) ($product->description ?? ''));
            $this->line(sprintf('== %s · %s · %s', $product->sku, (string) $product->name, (string) ($product->manufacturer ?? '')));
            $this->line(sprintf(
                '   kategoria: %s · rodzina: %s · cena zakupu: %s %s',
                (string) ($product->category ?? ''),
                (string) ($product->ppe_family ?? ''),
                (string) ($product->purchase_price ?? ''),
                (string) ($product->currency ?? ''),
            ));
            $this->line(sprintf(
                '   utworzona: %s · opis pobrany: %s (%s) · zmieniona: %s',
                (string) $product->created_at,
                (string) ($product->enriched_at ?? 'nigdy'),
                (string) ($product->enrichment_status ?? ''),
                (string) $product->updated_at,
            ));
            $this->line('   normy: '.mb_substr(trim((string) ($product->norms ?? '')), 0, 200));
            $this->line(sprintf('   opis (%d znaków): %s', mb_strlen($description), mb_substr(preg_replace('/\s+/u', ' ', $description) ?? $description, 0, 400)));
        }

        return self::SUCCESS;
    }
}
