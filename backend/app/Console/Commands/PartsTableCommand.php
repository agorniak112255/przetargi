<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CatalogPage;
use App\Models\ManufacturerPart;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\ManufacturerDomainResolver;
use App\Services\Enrichment\ModelGroupPlanner;
use App\Services\Enrichment\PartsTable\PartsTableImages;
use App\Services\Enrichment\PartsTable\PartsTablePin;
use App\Services\Enrichment\PartsTable\PartsTableResolver;
use App\Services\Enrichment\PartsTable\PartsTables;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\PriceListCards;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Przypięcie kart marki do tabeli części na stronach producenta (Coba, decyzja właściciela 09.10.2026).
 *
 * --refresh pobiera strony z catalog_pages (adresy z parts_table.page_prefix profilu) po jednej, z pominięciem pamięci
 * podręcznej i z odstępem jak norms:from-manufacturer-pages, i zapisuje WYŁĄCZNIE manufacturer_parts: strona bez
 * odpowiedzi zachowuje stare wiersze, strona bez tabeli traci swoje, strony, których nie ma już w catalog_pages — też;
 * ponad 20% stron bez odpowiedzi albo bez tabeli przerywa odświeżenie bez zapisu (zmiana szablonu witryny, zapora).
 *
 * Domyślnie podgląd bez zapisu w bazie: CSV w storage/app/parts-table/<marka>-<data>.csv (przypięcie, wiersz tabeli,
 * zdjęcie, obecne źródło opisu, zdjęcia do usunięcia) i osobno …-nierozwiazane.csv. --apply ustawia zdjęcia
 * przypiętych kart (PartsTableImages) z kopią wierszy zdjęć w storage/app/repair-backups/parts-table-images-<data>.json;
 * --queue (tylko z --apply) zleca ponowne pobranie opisu (force) wyłącznie kartom przypiętym. Karty nierozwiązane
 * zostają bez zmian.
 */
final class PartsTableCommand extends Command
{
    protected $signature = 'products:parts-table
        {--brand= : Klucz marki z config/manufacturer_profiles.php z resolverem tabeli części, np. coba}
        {--refresh : Pobierz strony z tabelą części i zapisz wiersze manufacturer_parts}
        {--price-list= : Tylko karty tego cennika (numer)}
        {--id=* : Tylko te karty}
        {--limit=0 : Najwyżej tyle kart (0 = wszystkie)}
        {--apply : Ustaw zdjęcia przypiętych kart (bez tej flagi tylko podgląd)}
        {--queue : Z --apply: zleć ponowne pobranie opisu (force) przypiętym kartom}
        {--user= : E-mail użytkownika, na którego idą partie (domyślnie pierwszy administrator)}';

    protected $description = 'Przypięcie kart do tabeli części na stronach producenta (podgląd + CSV; --refresh, --apply, --queue)';

    /** Najwyżej tyle stron bez odpowiedzi albo bez tabeli przy --refresh (ułamek wszystkich). */
    private const MAX_BAD_PAGES = 0.2;

    private const CHUNK = 100;

    public function handle(PartsTables $tables, PartsTableImages $images, PriceListCards $cards, ManufacturerDomainResolver $domains): int
    {
        $brand = mb_strtolower(trim((string) $this->option('brand')), 'UTF-8');
        if ($brand === '') {
            $this->error('Podaj --brand= (klucz marki z config/manufacturer_profiles.php, np. coba).');

            return self::FAILURE;
        }
        $profile = $this->profileEntry($brand);
        if ($profile === null) {
            $this->error("Marka „{$brand}” nie ma w config/manufacturer_profiles.php resolvera tabeli części z parts_table.page_prefix.");

            return self::FAILURE;
        }
        [$brandKey, $entry, $resolver] = $profile;
        if ((bool) $this->option('queue') && ! (bool) $this->option('apply')) {
            $this->error('--queue działa tylko z --apply.');

            return self::FAILURE;
        }

        if ((bool) $this->option('refresh')) {
            if (! $this->refresh($brandKey, (string) $entry['parts_table']['page_prefix'], $resolver)) {
                return self::FAILURE;
            }
            $tables->flush();
        }
        if (! ManufacturerPart::query()->where('brand_key', $brandKey)->exists()) {
            $this->error("Brak wierszy tabeli części marki {$brandKey} — uruchom najpierw z --refresh.");

            return self::FAILURE;
        }

        $ids = $this->selectedIds($cards);
        if ($ids === false) {
            return self::FAILURE;
        }
        $brandKeys = array_values(array_unique([$brandKey, ...array_map('strval', (array) ($entry['brand_keys'] ?? []))]));
        $manufacturers = $this->manufacturersOf($brandKeys, $domains);
        if ($manufacturers === []) {
            $this->info("Brak kart marki {$brandKey}.");

            return self::SUCCESS;
        }

        $stamp = now()->format('Ymd-His');
        $csvPath = storage_path("app/parts-table/{$brandKey}-{$stamp}.csv");
        $unresolvedPath = storage_path("app/parts-table/{$brandKey}-{$stamp}-nierozwiazane.csv");
        $csv = $this->openCsv($csvPath, [
            'id', 'sku', 'nazwa', 'status', 'strona', 'część', 'rozmiar', 'kolor', 'waga_kg', 'zdjęcie', 'powód zdjęcia',
            'obecne primary_source_url', 'zmiana źródła', 'zdjęcia web łącznie', 'web spoza producenta', 'banery',
            'do usunięcia', 'zdjęcia B2B',
        ]);
        $unresolvedCsv = $this->openCsv($unresolvedPath, ['id', 'sku', 'nazwa', 'powód', 'kandydaci']);
        if ($csv === null || $unresolvedCsv === null) {
            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        $counts = [];
        /** @var array<int, PartsTablePin> $pins */
        $pins = [];
        $seen = 0;
        Product::query()
            ->select(['id', 'sku', 'name', 'manufacturer', 'shop_source_url', 'enrichment_payload', 'enrichment_status'])
            ->whereIn('manufacturer', $manufacturers)
            ->when($ids !== null, static fn ($q) => $q->whereIntegerInRaw('id', $ids === [] ? [0] : $ids))
            ->chunkById(self::CHUNK, function (Collection $products) use ($tables, $images, $csv, $unresolvedCsv, $limit, &$counts, &$pins, &$seen): bool {
                foreach ($products as $product) {
                    /** @var Product $product */
                    if ($limit > 0 && $seen >= $limit) {
                        return false;
                    }
                    $seen++;
                    $result = $tables->pinFor($product);
                    $base = [(int) $product->id, (string) $product->sku, (string) $product->name];
                    if ($result === null || ! $result->resolved()) {
                        $reason = $result?->unresolvedReason ?? 'bez tabeli części dla tej karty';
                        $counts['nierozwiązany: '.$reason] = ($counts['nierozwiązany: '.$reason] ?? 0) + 1;
                        fputcsv($csv, [...$base, 'nierozwiązany: '.$reason], ';');
                        fputcsv($unresolvedCsv, [...$base, $reason, implode(', ', $result->candidates ?? [])], ';');

                        continue;
                    }
                    $pin = $result->pin;
                    $pins[(int) $product->id] = $pin;
                    $status = $pin->viaShortCode ? 'skrót→'.$pin->part : 'dokładny';
                    $counts[$pin->viaShortCode ? 'skrót' : 'dokładny'] = ($counts[$pin->viaShortCode ? 'skrót' : 'dokładny'] ?? 0) + 1;
                    fputcsv($csv, [...$base, $status, ...$this->pinColumns($product, $pin, $images)], ';');
                }

                return true;
            });
        fclose($csv);
        fclose($unresolvedCsv);

        ksort($counts);
        $this->table(['wynik', 'kart'], array_map(static fn (string $k, int $v): array => [$k, $v], array_keys($counts), $counts));
        $this->info(sprintf('Przypięte: %d z %d kart. Podgląd: %s', count($pins), $seen, $csvPath));
        $this->line("Nierozwiązane: {$unresolvedPath}");
        if (! (bool) $this->option('apply')) {
            $this->line('Podgląd — bez zmian w bazie. Zdjęcia: --apply; opisy: --apply --queue.');

            return self::SUCCESS;
        }
        if ($pins === []) {
            $this->info('Brak przypiętych kart — nic do zapisania.');

            return self::SUCCESS;
        }

        $backupPath = storage_path('app/repair-backups/parts-table-images-'.$stamp.'.json');
        $error = $this->writeBackup($backupPath, $brandKey, array_keys($pins));
        if ($error !== null) {
            $this->error("Nie zapisano kopii zapasowej ({$error}) — nic nie zmieniono.");

            return self::FAILURE;
        }
        $downloaded = 0;
        $removed = 0;
        $withoutImage = 0;
        foreach ($pins as $id => $pin) {
            $product = Product::query()->find($id);
            if ($product === null) {
                continue;
            }
            $result = $images->apply($product, $pin);
            $result['downloaded'] !== null ? $downloaded++ : $withoutImage++;
            $removed += count($result['removed']);
        }
        $this->info(sprintf(
            'Zdjęcia: %d kart ze zdjęciem z tabeli jako głównym, %d bez niego (nie pobrano albo wiersz bez zdjęcia), usunięto %d zdjęć. Kopia: %s',
            $downloaded,
            $withoutImage,
            $removed,
            $backupPath,
        ));

        return (bool) $this->option('queue') ? $this->queue(array_keys($pins)) : self::SUCCESS;
    }

    /**
     * Wpis profilu marki z resolverem tabeli części: [klucz wiersza manufacturer_parts, wpis, resolver] albo null.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: PartsTableResolver}|null
     */
    private function profileEntry(string $brand): ?array
    {
        foreach ((array) config('manufacturer_profiles.profiles', []) as $key => $entry) {
            if (! is_array($entry) || ($key !== $brand && ! in_array($brand, (array) ($entry['brand_keys'] ?? []), true))) {
                continue;
            }
            $class = $entry['resolver'] ?? null;
            $prefix = $entry['parts_table']['page_prefix'] ?? null;
            if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, PartsTableResolver::class)
                || ! is_string($prefix) || trim($prefix) === '') {
                return null;
            }

            return [(string) $key, $entry, app($class)];
        }

        return null;
    }

    /**
     * Strony z catalog_pages pod page_prefix, po jednej, bez pamięci podręcznej; zapis wierszy w jednej transakcji.
     */
    private function refresh(string $brandKey, string $prefix, PartsTableResolver $resolver): bool
    {
        $urls = $this->pageUrls($prefix);
        if ($urls === []) {
            $this->error("Brak stron w catalog_pages z adresem {$prefix}… — najpierw indeks witryny producenta.");

            return false;
        }
        $fetcher = app(ProductPageFetcher::class)->bypassCache();
        $delayUs = max(0, (int) config('norms.host_delay_ms', 1500)) * 1000;
        $parsed = [];
        $noAnswer = [];
        $noTable = [];
        foreach (array_values($urls) as $i => $url) {
            if ($i > 0 && $delayUs > 0) {
                usleep($delayUs);
            }
            try {
                $raw = $fetcher->fetchRaw($url);
            } catch (Throwable $e) {
                $this->warn("{$url}: {$e->getMessage()}");
                $raw = null;
            }
            if ($raw === null) {
                $noAnswer[] = $url;

                continue;
            }
            $page = $resolver->parse($raw['html'], $url);
            if ($page['rows'] === []) {
                $noTable[] = $url;
            }
            $parsed[$url] = $page + ['sha' => sha1($raw['html'])];
        }
        $bad = count($noAnswer) + count($noTable);
        $this->line(sprintf('Strony: %d, bez odpowiedzi %d, bez tabeli części %d.', count($urls), count($noAnswer), count($noTable)));
        foreach ([...$noAnswer, ...$noTable] as $url) {
            $this->line('  '.$url.(in_array($url, $noAnswer, true) ? ' — bez odpowiedzi' : ' — bez tabeli części'));
        }
        if ($bad > self::MAX_BAD_PAGES * count($urls)) {
            $this->error(sprintf(
                'Ponad %d%% stron bez odpowiedzi albo bez tabeli części (%d z %d) — przerwane bez zapisu (szablon witryny albo zapora?).',
                (int) (self::MAX_BAD_PAGES * 100),
                $bad,
                count($urls),
            ));

            return false;
        }

        $now = now();
        $rowsWritten = 0;
        $keepHashes = array_map(static fn (string $url): string => ManufacturerPart::hashFor($url), array_values($urls));
        DB::transaction(function () use ($brandKey, $parsed, $now, $keepHashes, &$rowsWritten): void {
            foreach ($parsed as $url => $page) {
                $hash = ManufacturerPart::hashFor($url);
                ManufacturerPart::query()->where('brand_key', $brandKey)->where('page_url_hash', $hash)->delete();
                $insert = [];
                foreach ($page['rows'] as $row) {
                    $insert[] = [
                        'brand_key' => $brandKey,
                        'page_url' => mb_substr($url, 0, 500, 'UTF-8'),
                        'page_url_hash' => $hash,
                        'page_title' => self::cut($page['title'], 300),
                        'part_code' => ManufacturerPart::codeKey($row['part']),
                        'part_label' => (string) self::cut($row['label'], 64),
                        'size_label' => self::cut($row['size'], 120),
                        'colour_label' => self::cut($row['colour'], 120),
                        'weight_kg' => $row['weight_kg'] !== null && $row['weight_kg'] < 100000 ? $row['weight_kg'] : null,
                        'model_image_url' => self::cut($row['model_image'], 2000),
                        'style_image_url' => self::cut($row['style_image'], 2000),
                        'has_styles' => $page['has_styles'],
                        'page_sha' => $page['sha'],
                        'fetched_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                foreach (array_chunk($insert, 200) as $chunk) {
                    ManufacturerPart::query()->insert($chunk);
                }
                $rowsWritten += count($insert);
            }
            // strony, których nie ma już w catalog_pages — stare wiersze nie przypinają kart do wycofanego wyrobu
            ManufacturerPart::query()->where('brand_key', $brandKey)->whereNotIn('page_url_hash', $keepHashes)->delete();
        });
        $this->info(sprintf('Zapisano %d wierszy tabeli części z %d stron (marka %s).', $rowsWritten, count($parsed), $brandKey));

        return true;
    }

    /**
     * Adresy stron spod prefiksu: prefiks + pierwszy człon ścieżki (slug), bez ukośnika na końcu, bez powtórzeń.
     *
     * @return array<string, string> klucz małymi literami => adres
     */
    private function pageUrls(string $prefix): array
    {
        $prefix = rtrim(trim($prefix), '/').'/';
        $urls = [];
        foreach (CatalogPage::query()->select(['id', 'url'])->where('url', 'like', $prefix.'%')->orderBy('id')->cursor() as $page) {
            $url = trim((string) $page->url);
            if (! str_starts_with(mb_strtolower($url, 'UTF-8'), mb_strtolower($prefix, 'UTF-8'))) {
                continue;
            }
            $rest = (string) preg_replace('/[?#].*$/', '', substr($url, strlen($prefix)));
            $slug = explode('/', trim($rest, '/'))[0] ?? '';
            if ($slug === '') {
                continue;
            }
            $canonical = $prefix.$slug;
            $urls[mb_strtolower($canonical, 'UTF-8')] ??= $canonical;
        }

        return $urls;
    }

    /**
     * Kolumny przypiętej karty: wiersz tabeli, zdjęcie, obecne źródło opisu i plan zdjęć (PartsTableImages, dryRun).
     *
     * @return list<string|int|float|null>
     */
    private function pinColumns(Product $product, PartsTablePin $pin, PartsTableImages $images): array
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $current = trim((string) ($payload['primary_source_url'] ?? ''));
        $change = $current === '' ? 'brak źródła'
            : (DescriptionVersionStore::sourceUrlKey($current) === DescriptionVersionStore::sourceUrlKey($pin->pageUrl) ? 'nie' : 'tak');
        $plan = $images->apply($product, $pin, dryRun: true);
        $reasons = array_count_values(array_column($plan['removed'], 'reason'));
        $gallery = ProductImage::query()->where('product_id', $product->id)->get(['id', 'b2b_account_id', 'source_url']);
        $web = $gallery->filter(static fn (ProductImage $i): bool => $i->b2b_account_id === null && trim((string) $i->source_url) !== '')->count();
        $b2b = $gallery->filter(static fn (ProductImage $i): bool => $i->b2b_account_id !== null)->count();

        return [
            $pin->pageUrl, $pin->part, $pin->size, $pin->colour,
            $pin->weightKg !== null ? str_replace('.', ',', (string) $pin->weightKg) : null,
            $pin->imageUrl, $pin->imageReason, $current, $change, $web,
            $reasons[PartsTableImages::REASON_FOREIGN] ?? 0,
            $reasons[PartsTableImages::REASON_BANNER] ?? 0,
            count($plan['removed']),
            $b2b,
        ];
    }

    /**
     * Karty z --price-list i --id (część wspólna); null = bez filtra, false = błąd.
     *
     * @return list<int>|null|false
     */
    private function selectedIds(PriceListCards $cards): array|null|false
    {
        $ids = null;
        $option = trim((string) $this->option('price-list'));
        if ($option !== '') {
            $list = PriceList::query()->find((int) $option);
            if (! $list instanceof PriceList) {
                $this->error("Nie ma cennika #{$option}.");

                return false;
            }
            $ids = $cards->ids($list);
        }
        $only = array_values(array_filter(array_map('intval', (array) $this->option('id')), static fn (int $id): bool => $id > 0));
        if ($only !== []) {
            $ids = $ids === null ? $only : array_values(array_intersect($ids, $only));
        }

        return $ids;
    }

    /**
     * Wartości kolumny manufacturer kart, których marka (ManufacturerDomainResolver::brandKey) należy do profilu.
     *
     * @param  list<string>  $brandKeys
     * @return list<string>
     */
    private function manufacturersOf(array $brandKeys, ManufacturerDomainResolver $domains): array
    {
        $out = [];
        foreach (Product::query()->whereNotNull('manufacturer')->distinct()->pluck('manufacturer') as $name) {
            if (in_array($domains->brandKey((string) $name), $brandKeys, true)) {
                $out[] = (string) $name;
            }
        }

        return $out;
    }

    /**
     * Wiersze zdjęć przypiętych kart przed zmianą — pliki zostają na dysku, więc z kopii da się odtworzyć wiersze.
     *
     * @param  list<int>  $productIds
     */
    private function writeBackup(string $path, string $brandKey, array $productIds): ?string
    {
        $images = [];
        foreach (array_chunk($productIds, 500) as $chunk) {
            foreach (ProductImage::query()->whereIntegerInRaw('product_id', $chunk)->orderBy('product_id')->orderBy('sort_order')->get() as $image) {
                $images[(string) $image->product_id][] = [
                    'id' => (int) $image->id,
                    'b2b_account_id' => $image->b2b_account_id !== null ? (int) $image->b2b_account_id : null,
                    'path' => $image->path,
                    'original_path' => $image->original_path,
                    'source_url' => $image->source_url,
                    'is_primary' => (bool) $image->is_primary,
                    'sort_order' => (int) $image->sort_order,
                    'checksum' => $image->checksum,
                ];
            }
        }
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return "nie można utworzyć katalogu {$dir}";
        }
        try {
            $json = json_encode(
                ['created_at' => now()->toIso8601String(), 'brand' => $brandKey, 'product_ids' => $productIds, 'images' => $images],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            return $e->getMessage();
        }

        return file_put_contents($path, $json) === false ? "nie można zapisać {$path}" : null;
    }

    /**
     * Ponowne pobranie opisu (force) przypiętych kart całymi modelami (ModelGroupPlanner), bez kart w kolejce i w toku.
     *
     * @param  list<int>  $ids
     */
    private function queue(array $ids): int
    {
        $ids = Product::query()->whereIntegerInRaw('id', $ids)
            ->whereNotIn('enrichment_status', [Product::ENRICHMENT_QUEUED, Product::ENRICHMENT_RUNNING])
            ->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        if ($ids === []) {
            $this->info('Brak kart do zlecenia (wszystkie w kolejce albo w toku).');

            return self::SUCCESS;
        }
        $user = $this->resolveUser();
        if ($user === null) {
            return self::FAILURE;
        }
        $planner = app(ModelGroupPlanner::class);
        $enrichment = app(ProductEnrichmentService::class);
        $batchSize = app(AiSettingsService::class)->enrichmentBatchLimit();
        $groups = $planner->groups($ids);
        $queued = 0;
        $batches = [];
        while ($groups !== []) {
            $slice = $planner->sliceByLimit($groups, $batchSize, oldestFirst: false);
            if ($slice['product_ids'] === []) {
                break;
            }
            $groups = array_slice($groups, count($slice['groups']));
            try {
                $result = $enrichment->enqueueProductIds($slice['product_ids'], $user, force: true);
            } catch (RuntimeException $e) {
                $this->warn($e->getMessage());

                continue;
            }
            $queued += count($result['product_ids']);
            $batches[] = (int) $result['batch']->id;
        }
        $this->info(sprintf('Zlecono pobranie opisu: %d kart w %d partiach (#%s).', $queued, count($batches), implode(', #', $batches)));

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $email = trim((string) $this->option('user'));
        try {
            $user = $email !== ''
                ? User::query()->where('email', $email)->first()
                : User::role('admin')->orderBy('id')->first();
        } catch (Throwable) {
            $user = null;
        }
        if (! $user instanceof User) {
            $this->error($email !== '' ? "Nie ma użytkownika {$email}." : 'Nie ma administratora, na którego można zlecić partie — podaj --user=.');

            return null;
        }

        return $user;
    }

    /**
     * @param  list<string>  $header
     * @return resource|null
     */
    private function openCsv(string $path, array $header)
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Nie da się utworzyć katalogu {$dir}");

            return null;
        }
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            $this->error("Nie da się zapisać pliku: {$path}");

            return null;
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $header, ';');

        return $handle;
    }

    private static function cut(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $length, 'UTF-8');
    }
}
