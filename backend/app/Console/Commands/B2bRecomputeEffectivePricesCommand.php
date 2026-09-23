<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Services\Pricing\ProductEffectivePrice;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Wdrożenie pierwszeństwa cennika producenta (decyzja użytkownika 23.09.2026): cennik producenta (jego konto B2B
 * albo plik) wygrywa z dystrybutorem wielu marek bez względu na datę, a konto może mieć wyłączoną cenę producenta.
 * Ceny kart liczone po staremu (najświeższe B2B przed plikiem) zmieniają się dopiero przy kolejnym zapisie slotu —
 * to polecenie pokazuje, które karty mają dziś inną cenę niż wynika z reguł, i na żądanie (--apply) je przelicza.
 * Tylko karty z co najmniej dwoma slotami: przy jednym slocie kolejność źródeł nic nie zmienia.
 */
final class B2bRecomputeEffectivePricesCommand extends Command
{
    protected $signature = 'b2b:recompute-effective-prices
        {--apply : Przelicz ceny kart (bez tego tylko raport)}';

    protected $description = 'Porównuje cenę kart z ceną obowiązującą wg pierwszeństwa cennika producenta i na żądanie ją przelicza';

    private const CHUNK = 500;

    private const LIST_LIMIT = 50;

    /** Ta sama tolerancja co ProductEffectivePrice::refreshLocked. */
    private const PRICE_TOLERANCE = 0.005;

    public function handle(ProductEffectivePrice $prices): int
    {
        $apply = (bool) $this->option('apply');
        $this->line($apply ? 'Tryb: zapis (--apply).' : 'Tryb: raport bez zapisu.');

        $multiSlot = ProductSourcePrice::query()
            ->select('product_id')
            ->groupBy('product_id')
            ->havingRaw('COUNT(*) >= 2');

        $checked = 0;
        $toChange = 0;
        $changed = 0;
        $rows = [];
        Product::query()
            ->whereIn('id', $multiSlot)
            ->chunkById(self::CHUNK, function (Collection $products) use ($prices, $apply, &$checked, &$toChange, &$changed, &$rows): void {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $checked++;
                    $resolved = $prices->resolve($product);
                    if ($resolved === null || ! $this->differs($product, $resolved)) {
                        continue;
                    }
                    $toChange++;
                    if (count($rows) < self::LIST_LIMIT) {
                        $rows[] = [
                            $product->id,
                            $product->sku,
                            (string) $product->manufacturer,
                            $this->money($product->purchase_price).' '.$product->currency,
                            $this->money($resolved['purchase_price']).' '.$resolved['currency'],
                            $resolved['source_key'],
                        ];
                    }
                    // refresh() liczy od nowa pod blokadą karty — między raportem a zapisem mógł dojść nowy slot
                    if ($apply && $prices->refresh($product) !== []) {
                        $changed++;
                    }
                }
            });

        $this->line(sprintf('Karty z co najmniej dwoma źródłami ceny: %d', $checked));
        $this->line(sprintf('Karty z ceną inną niż wynika z reguł: %d', $toChange));
        if ($rows !== []) {
            $this->table(['id', 'sku', 'producent', 'cena zakupu teraz', 'cena zakupu wg reguł', 'źródło nowej ceny'], $rows);
            if ($toChange > count($rows)) {
                $this->line(sprintf('… i %d więcej', $toChange - count($rows)));
            }
        }
        if ($apply) {
            $this->info(sprintf('Przeliczono ceny kart: %d', $changed));
        } elseif ($toChange > 0) {
            $this->warn('Nic nie zapisano. Przeliczenie: php artisan b2b:recompute-effective-prices --apply');
        }

        return self::SUCCESS;
    }

    /**
     * Te same porównania co ProductEffectivePrice::refreshLocked: waluta bez wielkości liter, liczby z tolerancją.
     *
     * @param  array<string, mixed>  $resolved
     */
    private function differs(Product $product, array $resolved): bool
    {
        foreach (ProductEffectivePrice::PRICE_FIELDS as $field) {
            $old = $product->getAttribute($field);
            $new = $resolved[$field];
            $same = $field === 'currency'
                ? strtoupper((string) $old) === strtoupper((string) $new)
                : abs((float) $old - (float) $new) < self::PRICE_TOLERANCE;
            if (! $same) {
                return true;
            }
        }

        return false;
    }

    private function money(mixed $value): string
    {
        return $value === null ? 'brak' : number_format((float) $value, 2, '.', '');
    }
}
