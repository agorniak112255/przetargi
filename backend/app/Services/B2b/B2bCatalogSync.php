<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Jobs\TranslateB2bProductTextJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductVariant;
use App\Models\ProductVariantPriceHistory;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\PriceListImportService;
use App\Support\ProductSearchBlob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wspólne zasady zapisu produktów z B2B do katalogu (dla każdego łącznika):
 * - karta dopasowana po powiązaniu z poprzedniego przebiegu, inaczej po dokładnym kodzie; kod karty
 *   innego producenta jest pomijany (wspólny import cenników skleja warianty po rdzeniu kodu i cenie);
 * - cena zawsze ze źródła; historia cen karty (źródło „b2b:{łącznik}”, przebieg, stały wpis konta w Cennikach)
 *   tylko przy nowej karcie lub zmianie ceny — zmiany widać na karcie produktu z datą; wpis konta w Cennikach
 *   (jeden na konto, B2bAccountPriceList) pokazuje wynik ostatniego przebiegu;
 * - opis ze źródła, gdy karta go nie ma albo ma opis zapisany wcześniej przez synchronizację i
 *   niezmieniony od tamtej pory — opisu poprawionego ręcznie nie nadpisujemy;
 * - kategoria, link i zdjęcie tylko gdy puste; produktów znikniętych z B2B nie kasujemy;
 * - łącznik B2bKeepsExistingNames nie zmienia nazwy istniejącej karty (nazwa ze źródła tylko na nowej);
 *   b2b_product_links.remote_name zawsze trzyma nazwę ze źródła z ostatniego przebiegu;
 * - łącznik B2bForeignLanguageSource (decyzja użytkownika 15.09.2026): po zapisie opisu ze źródła (i przy nowej
 *   karcie łącznika B2bKeepsExistingNames — także nazwy) zlecamy TranslateB2bProductTextJob; nigdy w dry-run.
 *   Niezmiennik hashy powiązania: description_hash = sha1 opisu na karcie zapisanego przez synchronizację;
 *   source_description_hash niepusty tylko wtedy, gdy ten opis jest tłumaczeniem — wtedy to sha1 tekstu źródła.
 *   Źródło bez zmian i tłumaczenie na karcie nietknięte → import nie przywraca oryginału i nie zleca ponownie;
 *   zapis tekstu źródła zeruje source_description_hash. Oryginalnego opisu nie przechowujemy.
 *
 * Łącznik z wersjami (B2bVariantConnector): karta ma cenę 0 („brak ceny”), ceny konta i ich historia są w
 * product_variants / product_variant_price_history; jedna transakcja na produkt; wersje zniknięte z pełnej
 * listy dostawcy dostają removed_at; formaty/podłoża trafiają do products.variant_summary (wyszukiwanie).
 * Tłumaczenia obsługuje tylko ścieżka bez wersji — żaden łącznik z wersjami nie ma obcojęzycznego źródła
 * (15.09.2026), znacznik B2bForeignLanguageSource na takim łączniku nic nie zleca.
 */
final class B2bCatalogSync
{
    private const VARIANT_SUMMARY_LIMIT = 1500;

    /** Bezpiecznik: taki udział wersji z ceną zmienił cenę tym samym współczynnikiem… */
    private const FUSE_SHARE = 0.8;

    private const FUSE_TOLERANCE = 0.02;

    /** …a współczynnik jest co najmniej taki (albo co najwyżej FUSE_DOWN). */
    private const FUSE_UP = 1.5;

    private const FUSE_DOWN = 0.67;

    /** Tyle podejrzanych produktów z rzędu kończy przebieg (B2bFatalException). */
    private const FUSE_STREAK = 3;

    private const FUSE_REASON = 'podejrzana zmiana wszystkich cen — możliwa utrata ceny konta';

    private const REMOVAL_CHUNK = 2000;

    public function __construct(
        private readonly PriceListImportService $priceLists,
        private readonly ProductImageDownloader $images,
    ) {}

    /**
     * @param  (callable(string): void)|null  $onProduct
     * @param  int|null  $priceListId  stały wpis konta w Cennikach — zapisywany przy historii cen kart
     * @return array{
     *     total_remote: int,
     *     seen: int,
     *     created: int,
     *     updated: int,
     *     unchanged: int,
     *     skipped: int,
     *     descriptions: int,
     *     images: int,
     *     prices_changed: int,
     *     errors: list<string>,
     *     cancelled: bool,
     *     partial: bool,
     *     progress_unit: string,
     *     processed: int,
     *     progress_total: int,
     *     variants_removed: int,
     *     translations_queued: int
     * }
     */
    public function run(
        B2bAccount $account,
        B2bConnector $connector,
        ?int $limit = null,
        bool $dryRun = false,
        bool $withImages = true,
        ?callable $onProduct = null,
        ?B2bSyncProgress $progress = null,
        ?int $priceListId = null,
    ): array {
        $variantConnector = $connector instanceof B2bVariantConnector ? $connector : null;
        $unit = $variantConnector !== null ? B2bSyncRun::UNIT_VARIANTS : B2bSyncRun::UNIT_PRODUCTS;
        $progress?->setUnit($unit);
        $progress?->log('info', 'Logowanie…');
        $progress?->flush();
        $connector->login();

        $stats = [
            'total_remote' => 0, 'seen' => 0, 'created' => 0, 'updated' => 0,
            'unchanged' => 0, 'skipped' => 0, 'descriptions' => 0, 'images' => 0,
            'translations_queued' => 0,
        ];
        $errors = [];
        $pricesChanged = 0;
        $cancelled = false;
        $partial = false;
        $variantsProcessed = 0;
        $variantsRemoved = 0;
        $fuseStreak = 0;
        $budgetMinutes = $variantConnector?->runBudgetMinutes();
        $startedAt = CarbonImmutable::now();
        $expected = static fn (int $total): int => $limit !== null ? min($limit, $total) : $total;
        // Przy wersjach postęp liczony w wersjach; próbka (--limit = produkty) nie zna z góry liczby wersji swoich produktów.
        $progressTotal = static function () use ($variantConnector, $limit, $expected, &$stats, &$variantsProcessed): int {
            if ($variantConnector === null) {
                return $expected($stats['total_remote']);
            }

            return $limit === null ? $variantConnector->totalVariants() : $variantsProcessed;
        };
        // W trakcie próbki z wersjami łączna liczba jest nieznana — null (panel: pasek bez procentu i bez „pozostało”),
        // a nie processed, które dawało stałe 100%. Na końcu przebiegu setTotal($progressTotal()).
        $liveTotal = static fn (): ?int => $variantConnector !== null && $limit !== null ? null : $progressTotal();
        $runId = $progress?->run()->id;

        foreach ($connector->products() as $remote) {
            if ($limit !== null && $stats['seen'] >= $limit) {
                break;
            }
            $stats['seen']++;
            $stats['total_remote'] = $connector->totalProducts();
            $label = $remote->sku !== '' ? $remote->sku : 'ID '.$remote->remoteId;
            if ($stats['seen'] === 1 && $progress !== null) {
                $progress->setTotal($liveTotal());
                $progress->log('info', $this->totalLine($stats['total_remote'], $limit, $variantConnector));
            }

            try {
                $outcome = $variantConnector !== null
                    ? $this->syncVariantProduct($account, $variantConnector, $remote, $dryRun, $withImages, $runId, $priceListId)
                    : $this->syncProduct($account, $connector, $remote, $dryRun, $withImages, $runId, $priceListId);
            } catch (B2bFatalException $e) {
                // utrata sesji / blokada — kolejne produkty zapisałyby złe ceny; przebieg kończy się jako „failed”
                throw $e;
            } catch (Throwable $e) {
                $outcome = ['status' => 'skipped', 'reason' => $e->getMessage()];
            }

            if ($variantConnector !== null) {
                $variantsProcessed += (int) ($outcome['variants'] ?? $this->listedVersionCount($remote));
            }

            if ($outcome['status'] === 'skipped') {
                $stats['skipped']++;
                $errors[] = $label.': '.$outcome['reason'];
                $progress?->error($label.': '.$outcome['reason']);
                $progress?->skipped([
                    'reason' => (string) $outcome['reason'],
                    'row' => $stats['seen'],
                    'sheet' => null,
                    'sku' => $remote->sku !== '' ? $remote->sku : null,
                    'name' => $remote->name !== '' ? $remote->name : null,
                ]);
            } else {
                $stats[$outcome['status']]++;
                foreach ($this->priceChangesOf($outcome) as $change) {
                    $pricesChanged++;
                    $progress?->priceChange([...$change, 'at' => now()->toIso8601String()]);
                }
                if (($outcome['update_summary'] ?? null) !== null) {
                    $progress?->updatedProduct($outcome['update_summary']);
                }
                if ($outcome['description'] ?? false) {
                    $stats['descriptions']++;
                }
                if ($outcome['image'] ?? false) {
                    $stats['images']++;
                }
                if ($outcome['translation_queued'] ?? false) {
                    $stats['translations_queued']++;
                }
                if (($outcome['image_error'] ?? null) !== null) {
                    $errors[] = $label.': zdjęcie — '.$outcome['image_error'];
                    $progress?->error($label.': zdjęcie — '.$outcome['image_error']);
                }
            }

            $statusText = match ($outcome['status']) {
                'created' => 'nowy',
                'updated' => 'zaktualizowany',
                'unchanged' => 'bez zmian',
                default => 'pominięty: '.$outcome['reason'],
            };
            if ($variantConnector !== null && $outcome['status'] !== 'skipped') {
                $statusText .= ' · wersji: '.(int) ($outcome['variants'] ?? 0);
            }
            $line = sprintf('[%d/%d] %s — %s', $stats['seen'], $expected($stats['total_remote']), $label, $statusText);
            if ($onProduct !== null) {
                $onProduct($line);
            }

            if ($progress !== null) {
                // „bez zmian” tylko w licznikach — przy pełnym cenniku to tysiące wierszy szumu
                if ($outcome['status'] !== 'unchanged') {
                    $progress->log($outcome['status'] === 'skipped' ? 'warn' : 'info', $line);
                }
                foreach ($outcome['warnings'] ?? [] as $warning) {
                    $progress->log('warn', $label.': '.$warning);
                }
                if (($outcome['image_error'] ?? null) !== null) {
                    $progress->log('warn', $label.': zdjęcie — '.$outcome['image_error']);
                }
                $progress->setTotal($liveTotal());
                $progress->advance($label, [
                    'processed' => $variantConnector !== null ? $variantsProcessed : $stats['seen'],
                    'created' => $stats['created'],
                    'updated' => $stats['updated'],
                    'unchanged' => $stats['unchanged'],
                    'skipped' => $stats['skipped'],
                    'prices_changed' => $pricesChanged,
                    'descriptions' => $stats['descriptions'],
                    'images' => $stats['images'],
                ]);
            }

            if ($variantConnector !== null) {
                if ($outcome['suspicious'] ?? false) {
                    $fuseStreak++;
                    if ($fuseStreak >= self::FUSE_STREAK) {
                        throw new B2bFatalException(sprintf(
                            'Przerwano: %d produkty z rzędu — %s (ostatni: %s).',
                            $fuseStreak,
                            self::FUSE_REASON,
                            $label,
                        ));
                    }
                } elseif ($outcome['compared'] ?? false) {
                    $fuseStreak = 0;
                }
            }

            if ($progress?->cancelRequested()) {
                $cancelled = true;
                break;
            }
            if ($budgetMinutes !== null
                && $startedAt->diffInSeconds(CarbonImmutable::now(), true) >= $budgetMinutes * 60
                && $stats['seen'] < $connector->totalProducts()) {
                $partial = true;
                break;
            }
        }
        $stats['total_remote'] = $connector->totalProducts();

        if ($variantConnector !== null && ! $cancelled && ! $dryRun) {
            $listed = $variantConnector->listedVariantIds();
            if ($listed !== null) {
                $variantsRemoved = $this->markRemovedVariants($account, $variantConnector, $listed, $startedAt);
                if ($variantsRemoved > 0) {
                    $progress?->log('info', 'Wersje wycofane (nie ma ich już na liście dostawcy): '.$variantsRemoved);
                }
            } else {
                $progress?->log('warn', 'Lista wersji u dostawcy niepełna — wycofanych wersji nie oznaczono.');
            }
        }

        if ($stats['translations_queued'] > 0) {
            $progress?->log('info', 'Opisy zlecone do tłumaczenia na polski: '.$stats['translations_queued']);
        }

        if ($progress !== null) {
            $progress->setTotal($progressTotal());
            if ($stats['seen'] === 0) {
                $progress->log('info', $this->totalLine($stats['total_remote'], $limit, $variantConnector));
            }
        }

        return [
            ...$stats,
            'prices_changed' => $pricesChanged,
            'errors' => $errors,
            'cancelled' => $cancelled,
            'partial' => $partial,
            'progress_unit' => $unit,
            'processed' => $variantConnector !== null ? $variantsProcessed : $stats['seen'],
            'progress_total' => $progressTotal(),
            'variants_removed' => $variantsRemoved,
        ];
    }

    /**
     * Łącznik z wersjami: tylko fakty — liczba wersji z listy dostawcy i limit próbki (liczba produktów takiego
     * łącznika to szacunek, więc jej nie pokazujemy).
     */
    private function totalLine(int $total, ?int $limit, ?B2bVariantConnector $variants): string
    {
        if ($variants === null) {
            return 'Produktów w B2B: '.$total.($limit !== null ? ' · próbka: '.min($limit, $total) : '');
        }

        return 'Wersji w B2B: '.$variants->totalVariants().($limit !== null ? ' · próbka: '.$limit.' znaków' : '');
    }

    /**
     * @param  array<string, mixed>  $outcome
     * @return list<array<string, mixed>>
     */
    private function priceChangesOf(array $outcome): array
    {
        if (isset($outcome['price_changes'])) {
            return $outcome['price_changes'];
        }
        if (($outcome['price_change'] ?? null) === null) {
            return [];
        }

        return [['product_id' => $outcome['product_id'], ...$outcome['price_change']]];
    }

    private function listedVersionCount(B2bRemoteProduct $remote): int
    {
        $versions = $remote->raw['versions'] ?? null;

        return is_array($versions) ? count($versions) : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function syncProduct(
        B2bAccount $account,
        B2bConnector $connector,
        B2bRemoteProduct $remote,
        bool $dryRun,
        bool $withImages,
        ?int $runId,
        ?int $priceListId,
    ): array {
        if ($remote->remoteId === '' || $remote->sku === '' || $remote->name === '') {
            return ['status' => 'skipped', 'reason' => 'brak kodu lub nazwy'];
        }

        $link = B2bProductLink::query()
            ->where('b2b_account_id', $account->id)
            ->where('remote_id', $remote->remoteId)
            ->with('product')
            ->first();
        $linked = $link?->product;
        $existing = $linked ?? Product::query()->where('sku', $remote->sku)->first();
        $manufacturer = mb_substr(trim($connector->manufacturer($remote)), 0, 100);

        if ($existing !== null && $linked === null && $this->foreignManufacturer($existing, $manufacturer)) {
            return ['status' => 'skipped', 'reason' => 'kod należy do karty producenta '.$existing->manufacturer];
        }

        $price = $connector->price($remote);
        if ($price === null) {
            return ['status' => 'skipped', 'reason' => 'brak ceny w B2B'];
        }

        $payload = [
            'name' => mb_substr($remote->name, 0, 1000),
            'manufacturer' => $manufacturer,
            // cena konta (po rabacie) = zakup; cena bazowa dostawcy = katalogowa
            'catalog_price_net' => $price->base ?? $price->net,
            'purchase_price' => $price->net,
            'discount_percent' => $price->discountPercent,
            'currency' => $price->currency,
        ];
        // łącznik ze znacznikiem: nazwa ze źródła tylko na nową kartę — przed detectPriceChange/summarizeUpdate,
        // żeby zachowana nazwa nie liczyła się jako zmiana
        if ($existing !== null && $connector instanceof B2bKeepsExistingNames) {
            unset($payload['name']);
        }
        [$descriptionHash, $sourceTextTaken] = $this->applyCardDetails($payload, $existing, $link, $connector, $remote);

        $priceChange = null;
        $updateSummary = null;
        $dirty = true;
        if ($existing !== null) {
            $priceChange = $this->priceLists->detectPriceChange($existing, $payload, $remote->sku);
            // przed fill — porównuje zapisane wartości karty z nowymi
            $updateSummary = $this->priceLists->summarizeUpdate($existing, $payload, $remote->sku, $priceChange !== null);
            $existing->fill($payload);
            $dirty = $existing->isDirty();
        }
        $status = $existing === null ? 'created' : ($dirty ? 'updated' : 'unchanged');

        if ($dryRun) {
            return ['status' => $status, 'description' => isset($payload['description'])];
        }

        if ($existing !== null) {
            if ($dirty) {
                $existing->save();
            }
            $product = $existing;
        } else {
            $product = Product::query()->create(['sku' => $remote->sku, ...$payload]);
        }

        if ($existing === null || $priceChange !== null) {
            ProductPriceHistory::query()->create([
                'product_id' => $product->id,
                'price_list_id' => $priceListId,
                'b2b_sync_run_id' => $runId,
                'catalog_price_net' => $product->catalog_price_net,
                'purchase_price' => $product->purchase_price,
                'source' => 'b2b:'.$connector::key(),
            ]);
        }

        $linkValues = [
            'product_id' => $product->id,
            'remote_sku' => mb_substr($remote->sku, 0, 255),
            'remote_name' => mb_substr($remote->name, 0, 1000),
            'description_hash' => $descriptionHash,
            'last_seen_at' => now(),
        ];
        // karta dostała (albo już ma) tekst źródła, więc nie jest tłumaczeniem — jedyne miejsce zerowania
        if ($sourceTextTaken) {
            $linkValues['source_description_hash'] = null;
        }
        B2bProductLink::query()->updateOrCreate(
            ['b2b_account_id' => $account->id, 'remote_id' => $remote->remoteId],
            $linkValues,
        );

        // po zapisie powiązania — job czyta z niego hashe i nazwę ze źródła
        $created = $existing === null;
        $translationQueued = false;
        if ($connector instanceof B2bForeignLanguageSource
            && (isset($payload['description']) || ($created && $connector instanceof B2bKeepsExistingNames))) {
            TranslateB2bProductTextJob::dispatch(
                (int) $product->id,
                (int) $account->id,
                $created && $connector instanceof B2bKeepsExistingNames,
            );
            $translationQueued = true;
        }

        [$image, $imageError] = $withImages ? $this->storeImage($connector, $remote, $product) : [false, null];

        return [
            'status' => $status,
            'product_id' => (int) $product->id,
            'price_change' => $priceChange,
            'update_summary' => $status === 'updated' ? $updateSummary : null,
            'description' => isset($payload['description']),
            'image' => $image,
            'image_error' => $imageError,
            'translation_queued' => $translationQueued,
        ];
    }

    /**
     * Produkt z wersjami: karta z ceną 0, wersje z cenami konta i historią w jednej transakcji.
     * Zwraca także „variants” (liczba wersji do postępu), „compared” / „suspicious” (bezpiecznik).
     *
     * @return array<string, mixed>
     */
    private function syncVariantProduct(
        B2bAccount $account,
        B2bVariantConnector $connector,
        B2bRemoteProduct $remote,
        bool $dryRun,
        bool $withImages,
        ?int $runId,
        ?int $priceListId,
    ): array {
        if ($remote->remoteId === '' || $remote->sku === '' || $remote->name === '') {
            return ['status' => 'skipped', 'reason' => 'brak kodu lub nazwy'];
        }
        $source = 'b2b:'.$connector::key();

        $remoteVariants = $this->uniqueVariants($connector->variants($remote));
        $count = count($remoteVariants);
        if ($remoteVariants === []) {
            return ['status' => 'skipped', 'reason' => 'brak wersji w B2B'];
        }
        $remoteIds = array_map(static fn (B2bRemoteVariant $v): string => $v->remoteId, $remoteVariants);

        // (1) karta, do której należą znane wersje tego produktu
        $knownIds = $remoteIds;
        foreach ((array) ($remote->raw['versions'] ?? []) as $version) {
            if (is_array($version) && isset($version['id']) && trim((string) $version['id']) !== '') {
                $knownIds[] = trim((string) $version['id']);
            }
        }
        $cardIds = ProductVariant::query()
            ->where('source', $source)
            ->whereIn('remote_id', array_values(array_unique($knownIds)))
            ->distinct()
            ->pluck('product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
        if (count($cardIds) > 1) {
            return [
                'status' => 'skipped',
                'reason' => 'wersje należą do kilku kart (#'.implode(', #', $cardIds).') — pominięty bez scalania',
                'variants' => $count,
            ];
        }

        // (2) powiązanie z poprzedniego przebiegu (remote_id = kod), (3) dokładny kod z regułą producenta
        $link = B2bProductLink::query()
            ->where('b2b_account_id', $account->id)
            ->where('remote_id', $remote->remoteId)
            ->first();
        if ($cardIds !== [] && $link !== null && (int) $link->product_id !== $cardIds[0]) {
            return [
                'status' => 'skipped',
                'reason' => 'kod powiązany z kartą #'.$link->product_id.', a wersje z kartą #'.$cardIds[0].' — pominięty bez scalania',
                'variants' => $count,
            ];
        }
        $linked = $cardIds !== []
            ? Product::query()->find($cardIds[0])
            : ($link !== null ? Product::query()->find($link->product_id) : null);
        $existing = $linked ?? Product::query()->where('sku', $remote->sku)->first();
        $manufacturer = mb_substr(trim($connector->manufacturer($remote)), 0, 100);

        if ($existing !== null && $linked === null && $this->foreignManufacturer($existing, $manufacturer)) {
            return ['status' => 'skipped', 'reason' => 'kod należy do karty producenta '.$existing->manufacturer, 'variants' => $count];
        }

        // Żadne ID tej grupy nie jest znane, a karta kodu ma już aktywne wersje tego źródła — to inna grupa wersji
        // o tym samym kodzie (inny znak). Nie scalamy dwóch znaków w jedną kartę. Gdy dostawca nadał wersjom nowe ID,
        // stare dostaną removed_at przy pełnej liście i kolejny przebieg zapisze nowe.
        if ($existing !== null && $cardIds === []) {
            $otherVariants = ProductVariant::query()
                ->where('product_id', $existing->id)
                ->where('source', $source)
                ->whereNull('removed_at')
                ->count();
            if ($otherVariants > 0) {
                return [
                    'status' => 'skipped',
                    'reason' => 'karta #'.$existing->id.' tego kodu ma już inne wersje ('.$otherVariants.') — inna grupa wersji o tym samym kodzie, pominięty bez scalania',
                    'variants' => $count,
                ];
            }
        }

        $priced = array_values(array_filter(
            $remoteVariants,
            static fn (B2bRemoteVariant $v): bool => $v->price !== null && $v->priceError === null,
        ));
        if ($priced === []) {
            $firstError = null;
            foreach ($remoteVariants as $v) {
                $firstError ??= $v->priceError;
            }

            return [
                'status' => 'skipped',
                'reason' => 'brak ceny w B2B'.($firstError !== null ? ' ('.$firstError.')' : ''),
                'variants' => $count,
            ];
        }
        $currencies = array_values(array_unique(array_map(
            static fn (B2bRemoteVariant $v): string => strtoupper(trim((string) $v->price?->currency)),
            $priced,
        )));
        if (count($currencies) !== 1 || $currencies[0] === '') {
            return ['status' => 'skipped', 'reason' => 'różne lub brak walut wersji: '.implode(', ', $currencies), 'variants' => $count];
        }

        /** @var Collection<string, ProductVariant> $stored */
        $stored = ProductVariant::query()
            ->where('source', $source)
            ->whereIn('remote_id', $remoteIds)
            ->get()
            ->keyBy(static fn (ProductVariant $v): string => (string) $v->remote_id);

        $factors = [];
        foreach ($priced as $v) {
            $old = $stored->get($v->remoteId)?->purchase_price;
            if ($old !== null && (float) $old > 0 && $v->price !== null) {
                $factors[] = $v->price->net / (float) $old;
            }
        }
        $compared = $factors !== [];
        if ($compared && $this->suspiciousPriceShift($factors)) {
            return ['status' => 'skipped', 'reason' => self::FUSE_REASON, 'variants' => $count, 'suspicious' => true];
        }

        $hadCardPrice = $existing !== null
            && ((float) $existing->purchase_price > 0 || (float) $existing->catalog_price_net > 0);
        $oldCardPurchase = $existing !== null ? (float) $existing->purchase_price : 0.0;
        $oldCardCatalog = $existing !== null ? (float) $existing->catalog_price_net : 0.0;

        // Plan wersji w pamięci — przy --dry-run nic nie zapisujemy, ale status karty uwzględnia zmiany wersji.
        $rows = [];
        $variantChanged = false;
        foreach ($remoteVariants as $v) {
            $model = $stored->get($v->remoteId) ?? new ProductVariant(['source' => $source, 'remote_id' => mb_substr($v->remoteId, 0, 64)]);
            $isNew = ! $model->exists;
            $priceOk = $v->price !== null && $v->priceError === null;
            $oldPurchase = $model->purchase_price;
            $oldList = $model->list_price_net;

            $model->fill([
                'b2b_account_id' => $account->id,
                'label' => mb_substr($v->label, 0, 255),
                'attributes' => $v->attributes,
                'sort_order' => max(0, $v->sortOrder),
                'removed_at' => null,
            ]);
            if ($existing !== null) {
                $model->product_id = $existing->id;
            }
            if ($v->sourceUrl !== null) {
                $model->source_url = mb_substr($v->sourceUrl, 0, 2000);
            }
            // VAT i jednostka zwykle przychodzą z tym samym zapytaniem co cena — błąd ceny nie kasuje zapisanych
            if ($priceOk || $v->vatRate !== null) {
                $model->vat_rate = $v->vatRate;
            }
            if ($priceOk || $v->unit !== null) {
                $model->unit = $v->unit !== null ? mb_substr($v->unit, 0, 20) : null;
            }
            if ($priceOk && $v->price !== null) {
                $model->fill([
                    'purchase_price' => round($v->price->net, 2),
                    // cena katalogowa tylko gdy źródło podaje ją wprost
                    'list_price_net' => $v->price->base !== null ? round($v->price->base, 2) : null,
                    'currency' => mb_substr(strtoupper(trim($v->price->currency)), 0, 3),
                ]);
            }

            $newPurchase = $model->purchase_price;
            $newList = $model->list_price_net;
            $purchaseChanged = $priceOk && ($oldPurchase === null || abs((float) $oldPurchase - (float) $newPurchase) >= 0.005);
            $listChanged = $priceOk && ! $isNew && (($oldList === null) !== ($newList === null)
                || ($oldList !== null && $newList !== null && abs((float) $oldList - (float) $newList) >= 0.005));
            $dirty = $isNew || $model->isDirty();
            $variantChanged = $variantChanged || $dirty;

            $change = null;
            if (! $isNew && $oldPurchase !== null && ($purchaseChanged || $listChanged)) {
                $old = round((float) $oldPurchase, 2);
                $new = round((float) $newPurchase, 2);
                $change = [
                    'sku' => $remote->sku,
                    'name' => mb_substr($remote->name, 0, 255),
                    'variant_label' => (string) $model->label,
                    'purchase_old' => $old,
                    'purchase_new' => $new,
                    'catalog_old' => null,
                    'catalog_new' => null,
                    'catalog_pct' => null,
                    'discount_old' => null,
                    'discount_new' => null,
                    'direction' => $new - $old >= 0.005 ? 'up' : ($old - $new >= 0.005 ? 'down' : 'flat'),
                ];
            }

            $rows[] = [
                'variant' => $model,
                'dirty' => $dirty,
                'price_ok' => $priceOk,
                'history' => $purchaseChanged || $listChanged,
                'change' => $change,
            ];
        }

        $payload = [
            'name' => mb_substr($remote->name, 0, 1000),
            'manufacturer' => $manufacturer,
            // 0 = „brak ceny” (oferta, dopasowanie, wycena) — ceny są tylko w wersjach
            'catalog_price_net' => 0,
            'purchase_price' => 0,
            'discount_percent' => 0,
            'currency' => $currencies[0],
            'variant_summary' => $this->variantSummary($this->summaryItems($rows, $existing, $stored)),
        ];
        // błąd pobrania opisu nie wstrzymuje cen: opis zostaje bez zmian, ostrzeżenie w dzienniku przebiegu
        $warnings = [];
        [$descriptionHash] = $this->applyCardDetails($payload, $existing, $link, $connector, $remote, $warnings);

        // przed fill — porównuje zapisane wartości karty z nowymi; zmiany wersji dopisane po zapisie
        $updateSummary = $existing !== null
            ? $this->priceLists->summarizeUpdate($existing, $payload, $remote->sku, false)
            : null;
        $existing?->fill($payload);
        $cardDirty = $existing === null || $existing->isDirty();
        $status = $existing === null ? 'created' : (($cardDirty || $variantChanged) ? 'updated' : 'unchanged');

        if ($dryRun) {
            return [
                'status' => $status,
                'description' => isset($payload['description']),
                'variants' => $count,
                'compared' => $compared,
            ];
        }

        $changes = [];
        $product = DB::transaction(function () use (
            $account, $remote, $existing, $payload, $rows, $runId, $priceListId, $descriptionHash, $source,
            $hadCardPrice, $oldCardPurchase, $oldCardCatalog, &$warnings, &$changes,
        ): Product {
            $now = now();
            if ($existing !== null) {
                if ($existing->isDirty()) {
                    $existing->save();
                }
                $product = $existing;
            } else {
                $product = Product::query()->create(['sku' => $remote->sku, ...$payload]);
            }

            if ($hadCardPrice) {
                ProductPriceHistory::query()->create([
                    'product_id' => $product->id,
                    'price_list_id' => $priceListId,
                    'b2b_sync_run_id' => $runId,
                    'catalog_price_net' => 0,
                    'purchase_price' => 0,
                    'source' => $source,
                ]);
                $warning = sprintf(
                    'cena karty (zakup %.2f, katalog %.2f) zmieniona na 0 — ceny są teraz w wersjach',
                    $oldCardPurchase,
                    $oldCardCatalog,
                );
                $warnings[] = $warning;
                Log::warning('B2B: karta z wersjami — cena karty zmieniona na 0', [
                    'product_id' => $product->id,
                    'sku' => $remote->sku,
                    'purchase_old' => $oldCardPurchase,
                    'catalog_old' => $oldCardCatalog,
                    'b2b_sync_run_id' => $runId,
                ]);
            }

            $touchPriced = [];
            $touchSeen = [];
            foreach ($rows as $row) {
                /** @var ProductVariant $variant */
                $variant = $row['variant'];
                if ($row['dirty']) {
                    $variant->product_id = $product->id;
                    $variant->last_seen_at = $now;
                    if ($row['price_ok']) {
                        $variant->price_checked_at = $now;
                    }
                    $variant->save();
                } elseif ($row['price_ok']) {
                    $touchPriced[] = (int) $variant->id;
                } else {
                    $touchSeen[] = (int) $variant->id;
                }

                if ($row['history']) {
                    ProductVariantPriceHistory::query()->create([
                        'product_variant_id' => $variant->id,
                        'b2b_sync_run_id' => $runId,
                        'purchase_price' => $variant->purchase_price,
                        'list_price_net' => $variant->list_price_net,
                        'currency' => $variant->currency,
                        'source' => $source,
                    ]);
                }
                if ($row['change'] !== null) {
                    $changes[] = ['product_id' => (int) $product->id, 'variant_id' => (int) $variant->id, ...$row['change']];
                }
            }
            // wersje bez zmian: jeden UPDATE sygnału widoczności (bez updated_at — wiersz się nie zmienił)
            if ($touchPriced !== []) {
                ProductVariant::query()->toBase()->whereIn('id', $touchPriced)->update(['last_seen_at' => $now, 'price_checked_at' => $now]);
            }
            if ($touchSeen !== []) {
                ProductVariant::query()->toBase()->whereIn('id', $touchSeen)->update(['last_seen_at' => $now]);
            }

            B2bProductLink::query()->updateOrCreate(
                ['b2b_account_id' => $account->id, 'remote_id' => $remote->remoteId],
                [
                    'product_id' => $product->id,
                    'remote_sku' => mb_substr($remote->sku, 0, 255),
                    'remote_name' => mb_substr($remote->name, 0, 1000),
                    'description_hash' => $descriptionHash,
                    'last_seen_at' => $now,
                ],
            );

            return $product;
        });

        // Hak modelu wysyła reindeks jeszcze w transakcji (kolejka bez after_commit) — worker mógłby przeczytać
        // kartę sprzed zapisu. Ponowne zlecenie po commit; ShouldBeUnique pomija je, gdy pierwsze jeszcze czeka.
        if ($product->wasRecentlyCreated || $product->wasChanged(ProductSearchBlob::SOURCE_COLUMNS)) {
            ReindexProductEmbeddingJob::dispatch((int) $product->id);
        }

        if ($updateSummary !== null && $status === 'updated') {
            $fields = array_values(array_diff($updateSummary['fields'], ['bez zmian wartości']));
            if ($variantChanged) {
                $fields[] = 'wersje';
            }
            $updateSummary['fields'] = $fields !== [] ? $fields : ['bez zmian wartości'];
            $updateSummary['price_changed'] = $changes !== [];
        }

        [$image, $imageError] = $withImages ? $this->storeImage($connector, $remote, $product) : [false, null];

        return [
            'status' => $status,
            'product_id' => (int) $product->id,
            'price_changes' => $changes,
            'update_summary' => $status === 'updated' ? $updateSummary : null,
            'description' => isset($payload['description']),
            'image' => $image,
            'image_error' => $imageError,
            'variants' => $count,
            'compared' => $compared,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<B2bRemoteVariant>  $variants
     * @return list<B2bRemoteVariant>
     */
    private function uniqueVariants(array $variants): array
    {
        $seen = [];
        $out = [];
        foreach ($variants as $variant) {
            $id = trim($variant->remoteId);
            if ($id === '' || isset($seen['#'.$id])) {
                continue;
            }
            $seen['#'.$id] = true;
            $out[] = $variant;
        }

        return $out;
    }

    /**
     * Czy ponad FUSE_SHARE wersji zmieniło cenę tym samym (±FUSE_TOLERANCE) dużym współczynnikiem —
     * tak wygląda cena anonimowa zamiast ceny konta, nie zwykła zmiana cennika.
     *
     * @param  list<float>  $factors  nowa cena / zapisana cena
     */
    private function suspiciousPriceShift(array $factors): bool
    {
        $total = count($factors);
        foreach ($factors as $pivot) {
            if ($pivot < self::FUSE_UP && $pivot > self::FUSE_DOWN) {
                continue;
            }
            $same = 0;
            foreach ($factors as $factor) {
                $close = $pivot == 0.0 ? $factor == 0.0 : abs($factor / $pivot - 1) <= self::FUSE_TOLERANCE;
                if ($close) {
                    $same++;
                }
            }
            if ($same > self::FUSE_SHARE * $total) {
                return true;
            }
        }

        return false;
    }

    /**
     * Aktywne wersje karty po zapisie: wersje tego produktu + pozostałe aktywne wersje karty spoza listy.
     *
     * @param  list<array{variant: ProductVariant}>  $rows
     * @param  Collection<string, ProductVariant>  $stored
     * @return list<array{label: string, attributes: mixed, sort_order: int, id: int|null}>
     */
    private function summaryItems(array $rows, ?Product $existing, $stored): array
    {
        $items = array_map(static fn (array $row): array => [
            'label' => (string) $row['variant']->label,
            'attributes' => $row['variant']->attributes,
            'sort_order' => (int) $row['variant']->sort_order,
            'id' => $row['variant']->exists ? (int) $row['variant']->id : null,
        ], $rows);

        if ($existing !== null) {
            $others = ProductVariant::query()
                ->where('product_id', $existing->id)
                ->whereNull('removed_at')
                ->whereNotIn('id', $stored->pluck('id')->all())
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'label', 'attributes', 'sort_order']);
            foreach ($others as $other) {
                $items[] = [
                    'label' => (string) $other->label,
                    'attributes' => $other->attributes,
                    'sort_order' => (int) $other->sort_order,
                    'id' => (int) $other->id,
                ];
            }
            usort($items, static fn (array $a, array $b): int => [$a['sort_order'], $a['id'] ?? PHP_INT_MAX] <=> [$b['sort_order'], $b['id'] ?? PHP_INT_MAX]);
        }

        return $items;
    }

    /**
     * „Format: 10 x 14,8 cm; 20 x 29,6 cm | Podłoże: FN - folia samoprzylepna” — wartości dosłownie, bez powtórzeń,
     * w kolejności źródła; gdy któraś wersja nie ma atrybutów — unikalne etykiety wersji.
     *
     * @param  list<array{label: string, attributes: mixed}>  $items
     */
    private function variantSummary(array $items): ?string
    {
        if ($items === []) {
            return null;
        }

        $dimensions = [];
        $structured = true;
        foreach ($items as $item) {
            $attributes = is_array($item['attributes']) ? $item['attributes'] : [];
            if ($attributes === []) {
                $structured = false;
                break;
            }
            foreach ($attributes as $name => $value) {
                $name = trim((string) $name);
                $value = trim((string) $value);
                if ($name !== '' && $value !== '') {
                    $dimensions['#'.$name]['#'.$value] = true;
                }
            }
        }

        $strip = static fn (string $key): string => substr($key, 1);
        if ($structured && $dimensions !== []) {
            $parts = [];
            foreach ($dimensions as $name => $values) {
                $parts[] = $strip($name).': '.implode('; ', array_map($strip, array_keys($values)));
            }
            $text = implode(' | ', $parts);
        } else {
            $labels = [];
            foreach ($items as $item) {
                $label = trim($item['label']);
                if ($label !== '') {
                    $labels['#'.$label] = true;
                }
            }
            $text = implode('; ', array_map($strip, array_keys($labels)));
        }

        $text = trim($text);

        return $text === '' ? null : mb_substr($text, 0, self::VARIANT_SUMMARY_LIMIT);
    }

    /**
     * Wersje tego źródła i konta, których nie ma na pełnej liście dostawcy → removed_at. Skan po zakresach id
     * (same id/remote_id), bez ładowania modeli; potem nowe variant_summary dotkniętych kart.
     * Wersja jest „na liście”, gdy lista ma jej pełne remote_id albo ID bazowe sprzed „:” (wersja wielokluczowa
     * „{id}:{klucz}” — mapa strony zna tylko ID bazowe). Wersji widzianej w tym przebiegu nie wycofujemy nigdy
     * (sklep zwraca też wersje, których nie ma w mapie strony).
     *
     * @param  list<string>  $listed
     */
    private function markRemovedVariants(
        B2bAccount $account,
        B2bVariantConnector $connector,
        array $listed,
        CarbonImmutable $runStartedAt,
    ): int {
        $listedSet = [];
        foreach ($listed as $id) {
            $listedSet['#'.trim((string) $id)] = true;
        }
        $now = now();
        $removed = 0;
        $productIds = [];

        ProductVariant::query()
            ->toBase()
            ->select(['id', 'remote_id', 'product_id'])
            ->where('source', 'b2b:'.$connector::key())
            ->where('b2b_account_id', $account->id)
            ->whereNull('removed_at')
            ->where(static function ($query) use ($runStartedAt): void {
                $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $runStartedAt);
            })
            ->chunkById(self::REMOVAL_CHUNK, function ($chunk) use ($listedSet, $now, &$removed, &$productIds): void {
                $ids = [];
                foreach ($chunk as $row) {
                    $remoteId = (string) $row->remote_id;
                    $base = strstr($remoteId, ':', true);
                    $isListed = isset($listedSet['#'.$remoteId]) || ($base !== false && isset($listedSet['#'.$base]));
                    if (! $isListed) {
                        $ids[] = (int) $row->id;
                        $productIds[(int) $row->product_id] = true;
                    }
                }
                if ($ids !== []) {
                    $removed += DB::table('product_variants')->whereIn('id', $ids)->update(['removed_at' => $now, 'updated_at' => $now]);
                }
            });

        foreach (array_keys($productIds) as $productId) {
            $product = Product::query()->find($productId);
            if ($product === null) {
                continue;
            }
            $items = ProductVariant::query()
                ->where('product_id', $productId)
                ->whereNull('removed_at')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['label', 'attributes'])
                ->map(static fn (ProductVariant $v): array => ['label' => (string) $v->label, 'attributes' => $v->attributes])
                ->all();
            $product->variant_summary = $this->variantSummary($items);
            if ($product->isDirty('variant_summary')) {
                $product->save();
            }
        }

        return $removed;
    }

    /**
     * Kategoria i link tylko gdy puste; opis wg mayWriteDescription. Zwraca [hash opisu do powiązania, czy
     * przyjęto niepusty tekst źródła] — przy true powiązanie zeruje source_description_hash (karta nie ma
     * tłumaczenia). Tłumaczenie na karcie (source_description_hash = sha1 niezmienionego źródła, description_hash =
     * sha1 opisu karty, czyli nietknięte ręcznie) zostaje: opis nie jest nadpisywany, hash bez zmian, false
     * (15.09.2026). Opis z łącznika pobierany jest raz.
     * Z $warnings (łącznik wersji) błąd pobrania opisu nie przerywa produktu: opis i jego hash zostają bez zmian.
     * Bez $warnings (dotychczasowe łączniki) wyjątek leci dalej jak wcześniej.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>|null  $warnings
     * @return array{0: string|null, 1: bool}
     */
    private function applyCardDetails(
        array &$payload,
        ?Product $existing,
        ?B2bProductLink $link,
        B2bConnector $connector,
        B2bRemoteProduct $remote,
        ?array &$warnings = null,
    ): array {
        if ($remote->category !== null && trim((string) ($existing?->category ?? '')) === '') {
            $payload['category'] = mb_substr($remote->category, 0, 255);
        }
        if ($remote->sourceUrl !== null && trim((string) ($existing?->shop_source_url ?? '')) === '') {
            $payload['shop_source_url'] = $remote->sourceUrl;
        }

        $descriptionHash = $link?->description_hash;
        if ($this->mayWriteDescription($existing, $link)) {
            try {
                $description = $connector->description($remote);
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (Throwable $e) {
                if ($warnings === null) {
                    throw $e;
                }
                $warnings[] = 'opis nie został pobrany ('.$e->getMessage().') — opis bez zmian, ceny zaktualizowane';
                $description = '';
            }
            if ($description !== '') {
                if ($this->keepsTranslation($existing, $link, $description)) {
                    return [$descriptionHash, false];
                }
                $descriptionHash = sha1($description);
                if ($existing === null || $description !== (string) $existing->description) {
                    $payload['description'] = $description;
                }

                return [$descriptionHash, true];
            }
        }

        return [$descriptionHash, false];
    }

    /**
     * Karta ma tłumaczenie tego samego tekstu źródła, nietknięte od zapisu przez job tłumaczenia.
     */
    private function keepsTranslation(?Product $existing, ?B2bProductLink $link, string $source): bool
    {
        return $existing !== null
            && $link?->source_description_hash !== null
            && $link->description_hash !== null
            && hash_equals($link->source_description_hash, sha1($source))
            && hash_equals($link->description_hash, sha1((string) $existing->description));
    }

    private function foreignManufacturer(Product $existing, string $manufacturer): bool
    {
        return mb_strtolower(trim((string) $existing->manufacturer)) !== mb_strtolower($manufacturer);
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function storeImage(B2bConnector $connector, B2bRemoteProduct $remote, Product $product): array
    {
        if ($product->images()->exists()) {
            return [false, null];
        }
        try {
            $remoteImage = $connector->image($remote);
            if ($remoteImage === null) {
                return [false, null];
            }

            return [$this->images->storeBytes($product, $remoteImage->bytes, $remoteImage->mime, $remoteImage->sourceUrl, 0) !== null, null];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    private function mayWriteDescription(?Product $existing, ?B2bProductLink $link): bool
    {
        if ($existing === null || ! $existing->hasDescriptionText()) {
            return true;
        }

        return $link?->description_hash !== null
            && hash_equals($link->description_hash, sha1((string) $existing->description));
    }
}
