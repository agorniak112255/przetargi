<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductShopCard;
use App\Services\B2b\B2bCatalogSync;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Przepisuje zapisane tabelki z kart wyrobu u dostawców (product_shop_cards) do products.shop_fields_summary,
 * czyli do tekstu, który widzi wyszukiwanie leksykalne (search_blob) i wektorowe.
 *
 * Potrzebne raz po wdrożeniu: pierwszy etap zapisał kilkanaście tysięcy kart sklepowych, a synchronizacja
 * odświeża je nie częściej niż raz na tydzień (B2bCatalogSync::SHOP_FIELDS_TTL_DAYS), więc bez tego polecenia
 * kolumna wypełniałaby się dopiero przy kolejnych przebiegach cenników.
 *
 * Tekst dla karty wyrobu zawsze powstaje ze wszystkich jej kart sklepowych naraz — opcja --account wybiera tylko
 * to, które karty wyrobu przeliczamy, nie z których kont bierzemy wiersze.
 */
final class RefreshShopFieldsSummariesCommand extends Command
{
    private const CHUNK = 500;

    protected $signature = 'products:refresh-shop-summaries
                            {--account= : Tylko karty wyrobu mające tabelkę z tego konta B2B (id)}';

    protected $description = 'Przepisuje tabelki z kart wyrobu u dostawców do products.shop_fields_summary (wyszukiwanie tekstowe i wektor)';

    public function handle(): int
    {
        $accountId = trim((string) $this->option('account'));
        if ($accountId !== '' && ! B2bAccount::query()->whereKey((int) $accountId)->exists()) {
            $this->error('Nie ma konta B2B o id '.$accountId.'.');

            return self::FAILURE;
        }

        $ids = ProductShopCard::query()
            ->when($accountId !== '', static fn ($query) => $query->where('b2b_account_id', (int) $accountId))
            ->distinct()
            ->orderBy('product_id')
            ->pluck('product_id')
            ->map(static fn (mixed $id): int => (int) $id);

        $total = $ids->count();
        if ($total === 0) {
            $this->info('Brak kart wyrobu z tabelką ze sklepu — nic do przeliczenia.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();
        $updated = 0;

        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            /** @var Collection<int, Product> $products */
            $products = Product::query()->whereIn('id', $chunk->all())->orderBy('id')->get();
            foreach ($products as $product) {
                $bar->advance();
                // Zwykły save() w B2bCatalogSync: hak modelu przelicza search_blob i zleca reindeks wektora.
                if (B2bCatalogSync::refreshShopFieldsSummary($product)) {
                    $updated++;
                }
            }
            // karty skasowane po odczytaniu listy id — pasek ma dobiec do końca
            $bar->advance($chunk->count() - $products->count());
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Zmieniono {$updated} z {$total} kart wyrobu.");

        return self::SUCCESS;
    }
}
