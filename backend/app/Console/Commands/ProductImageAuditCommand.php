<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Services\Catalog\CardOwnership;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductSearchIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

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
 * - zdjęcie wyłowione z sieci (bez konta dostawcy), które nie przechodzi dzisiejszej bramki tożsamości —
 *   także gdy nazwa pliku podaje inną klasę obuwia (23.09.2026: „ARDOR 330 Air 619060 S1 PL ESD” ze zdjęciem
 *   „ARDOR_330_619060_S3L_ESD.png” z pierwszego pobierania). Takie zdjęcie dostaje ślad odrzucenia
 *   (ProductImageRejection), żeby wzbogacanie nie dołożyło go z powrotem.
 * - z `--web-with-manufacturer`: każde zdjęcie wyłowione z sieci na karcie, która ma już zdjęcie od konta B2B
 *   producenta swojej marki (CardOwnership). Decyzja użytkownika 28.09.2026: zdjęcia od producenta, ze sklepów
 *   tylko wtedy, gdy producent ich nie ma — 207 kart Bolle z importu pliku miało zdjęcia ze specshop.pl,
 *   e-militaria.eu i innych, często innego wariantu. Ten sam ślad odrzucenia.
 *
 * - zdjęcie z sieci z innym modelem rękawicy Ansella w nazwie pliku („08-354….jpg” na AlphaTec 08352) albo logo
 *   i ikona witryny WordPress („cropped-…”, „site-icon”) — 05.10.2026, zdjęcia ze sklepów za zablokowany plik ansell.com.
 *
 * Osobna lista „do przejrzenia” (nigdy nie kasowana przez --apply — reguła nie wie, która karta ma rację):
 * - ten sam plik z sieci na kartach różnych modeli (Ringers R169SD i R840VP z jednym obrazkiem),
 * - zdjęcie z sieci bez kodu wyrobu w adresie na karcie bez opisu („wpisz ręcznie” / błąd): KleenGuard A10 z obrazkiem
 *   gry „A10” z agamecdn.com, A40 z szybką do przyłbicy ESAB.
 *
 * Zdjęcia dostawców nie są ruszane nigdy: to, co dostawca pokazuje przy swojej karcie, jest jego decyzją.
 * Pliki na dysku zostają — sprząta je `products:media-report --apply`, które liczy też miejsce.
 */
final class ProductImageAuditCommand extends Command
{
    protected $signature = 'products:images-audit
                            {--apply : Skasuj wskazane wiersze (bez tej flagi tylko raport)}
                            {--manufacturer= : Tylko karty tego producenta}
                            {--web-with-manufacturer : Także zdjęcia z sieci na kartach, które mają zdjęcie od konta B2B producenta}
                            {--show=20 : Ile przykładów wypisać}';

    protected $description = 'Pokazuje powtórzone i obce zdjęcia w galeriach kart (kasuje tylko z --apply)';

    /** @var Collection<int, B2bAccount>|null konta B2B po id — do rozpoznania zdjęć producenta */
    private ?Collection $accounts = null;

    /** @var array<string, list<int>> klucz pliku (sameFileKey) → karty, na których to zdjęcie z sieci stoi */
    private array $webOwners = [];

    /** @var array<int, Product> */
    private array $ownerCards = [];

    public function __construct(
        private readonly ProductSearchIdentity $identity,
        private readonly CardOwnership $ownership,
    ) {
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
        $review = [];
        $cards = 0;

        $this->loadWebOwners($manufacturer);
        $this->line('Czytam galerie kart…');
        $products->with('images')->chunk(200, function ($chunk) use (&$duplicates, &$foreign, &$review, &$cards): void {
            foreach ($chunk as $product) {
                $cards++;
                $this->collect($product, $duplicates, $foreign, $review);
            }
        });

        $this->newLine();
        $this->line('Sprawdzone karty:        '.$cards);
        $this->line('Powtórzony plik:         '.count($duplicates).' wierszy');
        $this->line('Obce zdjęcie z sieci:    '.count($foreign).' wierszy');

        $this->examples('Powtórzony plik w karcie', $duplicates, $show);
        $this->examples('Zdjęcie, które dziś nie przeszłoby bramki', $foreign, $show);
        if ($review !== []) {
            $this->newLine();
            $this->line('Do przejrzenia (--apply ich nie usuwa): '.count($review).' wierszy');
            foreach (array_slice($review, 0, max($show, 50)) as $row) {
                $this->line('  #'.$row['product_id'].'  '.$row['sku'].'  ←  '.$row['file'].'  ('.$row['why'].')');
            }
            if (count($review) > max($show, 50)) {
                $this->line('  … i '.(count($review) - max($show, 50)).' więcej');
            }
        }

        // karta, w której wszystkie zdjęcia są do usunięcia, zostanie bez zdjęcia — człowiek ma to widzieć przed --apply
        $emptied = $this->cardsLeftWithoutImages(array_merge($duplicates, $foreign));
        if ($emptied !== []) {
            $this->newLine();
            $this->line('Po usunięciu zostaną bez zdjęcia ('.count($emptied).'): #'.implode(', #', $emptied));
        }

        $rows = array_merge($duplicates, $foreign);
        if ($rows === []) {
            $this->newLine();
            $this->info('Nie ma czego czyścić.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->newLine();
            // podpowiedź powtarza filtr producenta — bez niego --apply czyściłby galerie wszystkich marek
            $quoted = preg_match('/^[\w.-]+$/u', $manufacturer) === 1
                ? $manufacturer
                : '"'.str_replace('"', '\"', $manufacturer).'"';
            $filter = ($manufacturer !== '' ? ' --manufacturer='.$quoted : '')
                .($this->option('web-with-manufacturer') ? ' --web-with-manufacturer' : '');
            $this->warn('Raport — nic nie skasowano. Żeby usunąć te wiersze: php artisan products:images-audit'.$filter.' --apply');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Kasuję wiersze…');
        $touched = [];
        foreach ($duplicates as $row) {
            ProductImage::query()->whereKey($row['id'])->delete();
            $touched[$row['product_id']] = true;
        }
        foreach (array_keys($touched) as $productId) {
            ProductImage::resequence((int) $productId);
        }
        // powtórzony plik zostaje w karcie drugim wierszem, więc odrzucamy tylko obce zdjęcia
        foreach ($foreign as $row) {
            $image = ProductImage::query()->find($row['id']);
            if ($image !== null) {
                ProductImageRejection::rejectAndDelete($image, ProductImageRejection::REASON_AUDIT);
            }
            $touched[$row['product_id']] = true;
        }

        $this->info('Skasowano '.count($rows).' wierszy w '.count($touched).' kartach. '
            .'Pliki na dysku zostały — miejsce odzyskuje products:media-report --apply.');

        return self::SUCCESS;
    }

    /** Właściciele zdjęć z sieci po kluczu pliku — do listy „ten sam plik na kartach różnych modeli”. */
    private function loadWebOwners(string $manufacturer): void
    {
        $this->webOwners = [];
        ProductImage::query()
            ->whereNull('b2b_account_id')
            ->whereNotNull('source_url')
            ->when($manufacturer !== '', static fn ($q) => $q->whereIn(
                'product_id',
                Product::query()->select('id')->where('manufacturer', $manufacturer)
            ))
            ->select(['id', 'product_id', 'source_url'])
            ->chunkById(2000, function ($rows): void {
                foreach ($rows as $row) {
                    $key = ProductImageDownloader::sameFileKey((string) $row->source_url);
                    $this->webOwners[$key][] = (int) $row->product_id;
                }
            });
        $shared = [];
        foreach ($this->webOwners as $key => $ids) {
            $ids = array_values(array_unique($ids));
            if (count($ids) > 1) {
                $this->webOwners[$key] = $ids;
                foreach ($ids as $id) {
                    $shared[$id] = true;
                }
            } else {
                unset($this->webOwners[$key]);
            }
        }
        $this->ownerCards = $shared === [] ? [] : Product::query()->whereKey(array_keys($shared))->get()->keyBy('id')->all();
    }

    /** To zdjęcie z sieci stoi też na karcie innego modelu. */
    private function sharedWithAnotherModel(string $key, Product $product): bool
    {
        foreach ($this->webOwners[$key] ?? [] as $id) {
            $other = $this->ownerCards[$id] ?? null;
            if ($id !== (int) $product->id && $other !== null && ! $this->identity->sameGloveModel($product, $other)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{id: int, product_id: int, sku: string, file: string}>  $duplicates
     * @param  list<array{id: int, product_id: int, sku: string, file: string}>  $foreign
     * @param  list<array{id: int, product_id: int, sku: string, file: string, why: string}>  $review
     */
    private function collect(Product $product, array &$duplicates, array &$foreign, array &$review): void
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
        $replacedByManufacturer = (bool) $this->option('web-with-manufacturer') && $this->hasManufacturerImage($product);
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
            if ($replacedByManufacturer
                || $this->identity->imageUrlMentionsForeignBrand($url, $product)
                || $this->identity->imageUrlHasForeignVariantCode($url, $product)
                || $this->identity->imageUrlHasForeignType($url, $product)
                || $this->identity->imageUrlNamesAnotherFootwearVariant($url, $product)
                || $this->identity->imageUrlNamesForeignGloveModel($url, $product)
                || ProductImageDownloader::isSiteIdentityGraphicUrl($url)) {
                $foreign[] = $row;

                continue;
            }
            if ($this->sharedWithAnotherModel($key, $product)) {
                $review[] = $row + ['why' => 'ten sam plik na karcie innego modelu'];
            } elseif (in_array($product->enrichment_status, [Product::ENRICHMENT_MANUAL, Product::ENRICHMENT_FAILED], true)
                && ! $this->identity->imageUrlMentionsProduct($url, $product)) {
                $review[] = $row + ['why' => 'karta bez opisu, adres nie nazywa wyrobu'];
            }
        }
    }

    /** Karta ma zdjęcie od konta B2B, które jest cennikiem producenta jej marki. */
    private function hasManufacturerImage(Product $product): bool
    {
        $this->accounts ??= B2bAccount::query()->get()->keyBy('id');
        foreach ($product->images->pluck('b2b_account_id')->filter()->unique() as $accountId) {
            $account = $this->accounts->get((int) $accountId);
            if ($account !== null && $this->ownership->isOwnerAccount($product, $account)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{id: int, product_id: int, sku: string, file: string}>  $rows
     * @return list<int>
     */
    private function cardsLeftWithoutImages(array $rows): array
    {
        $removed = [];
        foreach ($rows as $row) {
            $removed[$row['product_id']] = ($removed[$row['product_id']] ?? 0) + 1;
        }
        if ($removed === []) {
            return [];
        }
        $totals = ProductImage::query()
            ->whereIn('product_id', array_keys($removed))
            ->selectRaw('product_id, count(*) as c')
            ->groupBy('product_id')
            ->pluck('c', 'product_id');

        $out = [];
        foreach ($removed as $productId => $count) {
            if ((int) ($totals[$productId] ?? 0) <= $count) {
                $out[] = (int) $productId;
            }
        }
        sort($out);

        return $out;
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
