<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductSearchIdentity;
use Illuminate\Console\Command;

/**
 * Zdjęcia, których dzisiejsze reguły nie wpuściłyby do galerii karty.
 *
 * Powód: testujący zgłosił bałagan w zdjęciach — przy jednej karcie kilka ujęć, część z innego modelu tej
 * samej serii („ARAL 927 4260 S3” ze zdjęciem „…aral-927-6160-o2-fo.jpg”), a bywa i ten sam plik dwa razy.
 * Reguły, które te przypadki odsiewają, powstały później niż same wiersze: bramka tożsamości
 * (ProductSearchIdentity) odrzuca dziś obcy wariant przy wzbogacaniu, a jeden klucz pliku
 * (ProductImageDownloader::sameFileKey) nie pozwala zapisać tego samego obrazu Shopify pod dwoma adresami.
 * Zastane wiersze zostały w bazie i to one są widoczne w panelu.
 *
 * Domyślnie polecenie **tylko liczy i wypisuje**. Kasuje wiersze wyłącznie po jawnym `--apply`, i tylko te
 * dwa rodzaje:
 * - powtórzony plik w obrębie jednej karty — zostaje wiersz dostawcy (a przy remisie wcześniejszy),
 * - zdjęcie wyłowione z sieci (bez konta dostawcy), które nie przechodzi dzisiejszej bramki tożsamości.
 *
 * Zdjęcia dostawców nie są ruszane nigdy: to, co dostawca pokazuje przy swojej karcie, jest jego decyzją.
 * Pliki na dysku zostają — sprząta je `products:media-report --apply`, które liczy też miejsce.
 */
final class ProductImageAuditCommand extends Command
{
    protected $signature = 'products:images-audit
                            {--apply : Skasuj wskazane wiersze (bez tej flagi tylko raport)}
                            {--manufacturer= : Tylko karty tego producenta}
                            {--show=20 : Ile przykładów wypisać}';

    protected $description = 'Pokazuje powtórzone i obce zdjęcia w galeriach kart (kasuje tylko z --apply)';

    public function __construct(private readonly ProductSearchIdentity $identity)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $manufacturer = trim((string) $this->option('manufacturer'));
        $show = max(0, (int) $this->option('show'));

        $products = Product::query()
            ->when($manufacturer !== '', static fn ($q) => $q->where('manufacturer', $manufacturer))
            ->whereHas('images')
            ->orderBy('id');

        $duplicates = [];
        $foreign = [];
        $cards = 0;

        $this->line('Czytam galerie kart…');
        $products->with('images')->chunk(200, function ($chunk) use (&$duplicates, &$foreign, &$cards): void {
            foreach ($chunk as $product) {
                $cards++;
                $this->collect($product, $duplicates, $foreign);
            }
        });

        $this->newLine();
        $this->line('Sprawdzone karty:        '.$cards);
        $this->line('Powtórzony plik:         '.count($duplicates).' wierszy');
        $this->line('Obce zdjęcie z sieci:    '.count($foreign).' wierszy');

        $this->examples('Powtórzony plik w karcie', $duplicates, $show);
        $this->examples('Zdjęcie, które dziś nie przeszłoby bramki', $foreign, $show);

        $rows = array_merge($duplicates, $foreign);
        if ($rows === []) {
            $this->newLine();
            $this->info('Nie ma czego czyścić.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('Raport — nic nie skasowano. Żeby usunąć te wiersze: php artisan products:images-audit --apply');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Kasuję wiersze…');
        $touched = [];
        foreach ($rows as $row) {
            ProductImage::query()->whereKey($row['id'])->delete();
            $touched[$row['product_id']] = true;
        }
        foreach (array_keys($touched) as $productId) {
            ProductImage::resequence((int) $productId);
        }

        $this->info('Skasowano '.count($rows).' wierszy w '.count($touched).' kartach. '
            .'Pliki na dysku zostały — miejsce odzyskuje products:media-report --apply.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array{id: int, product_id: int, sku: string, file: string}>  $duplicates
     * @param  list<array{id: int, product_id: int, sku: string, file: string}>  $foreign
     */
    private function collect(Product $product, array &$duplicates, array &$foreign): void
    {
        // wiersz dostawcy zostaje, więc przy tym samym pliku przegrywa zdjęcie bez konta; przy remisie
        // wcześniejszy wiersz (niższy identyfikator) — to on ma już swoje miejsce w galerii
        $images = $product->images
            ->sortBy([
                static fn (ProductImage $a, ProductImage $b): int => ($b->b2b_account_id !== null ? 1 : 0) <=> ($a->b2b_account_id !== null ? 1 : 0),
                static fn (ProductImage $a, ProductImage $b): int => (int) $a->id <=> (int) $b->id,
            ])
            ->values();

        $seen = [];
        foreach ($images as $image) {
            $url = (string) $image->source_url;
            $row = [
                'id' => (int) $image->id,
                'product_id' => (int) $product->id,
                'sku' => (string) $product->sku,
                'file' => basename((string) (parse_url($url, PHP_URL_PATH) ?: $url)),
            ];

            $key = ProductImageDownloader::sameFileKey($url);
            if (isset($seen[$key])) {
                $duplicates[] = $row;

                continue;
            }
            $seen[$key] = true;

            if ($image->b2b_account_id !== null || ! str_starts_with($url, 'http')) {
                continue;
            }
            if ($this->identity->imageUrlMentionsForeignBrand($url, $product)
                || $this->identity->imageUrlHasForeignVariantCode($url, $product)
                || $this->identity->imageUrlHasForeignType($url, $product)) {
                $foreign[] = $row;
            }
        }
    }

    /**
     * @param  list<array{id: int, product_id: int, sku: string, file: string}>  $rows
     */
    private function examples(string $title, array $rows, int $show): void
    {
        if ($rows === [] || $show === 0) {
            return;
        }

        $this->newLine();
        $this->line($title.':');
        foreach (array_slice($rows, 0, $show) as $row) {
            $this->line('  #'.$row['product_id'].'  '.$row['sku'].'  ←  '.$row['file']);
        }
        if (count($rows) > $show) {
            $this->line('  … i '.(count($rows) - $show).' więcej');
        }
    }
}
