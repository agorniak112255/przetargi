<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Jobs\TranslateB2bProductTextJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Models\ProductPriceHistory;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\ProductVariantPriceHistory;
use App\Services\Enrichment\ProductDocumentDownloader;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\PriceListImportService;
use App\Services\Pricing\ProductEffectivePrice;
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
 * - cena ze źródła trafia do slotu konta w product_source_prices („b2b:{id konta}”), nie wprost na kartę
 *   (decyzja użytkownika 15.09.2026: cenniki z plików i B2B nie nadpisują sobie cen); cenę obowiązującą karty
 *   przelicza ProductEffectivePrice (slot B2B ma pierwszeństwo przed plikiem). Zmiana ceny i podsumowanie
 *   aktualizacji porównują z poprzednim slotem tego konta (bez slotu — z ceną karty). Historia cen karty (źródło
 *   „b2b:{łącznik}”, przebieg, stały wpis konta w Cennikach) z wartościami slotu, tylko przy nowej karcie lub
 *   zmianie ceny slotu — zmiany widać na karcie produktu z datą; wpis konta w Cennikach (jeden na konto,
 *   B2bAccountPriceList) pokazuje wynik ostatniego przebiegu;
 * - opis ze źródła, gdy karta go nie ma albo ma opis zapisany wcześniej przez synchronizację i
 *   niezmieniony od tamtej pory — opisu poprawionego ręcznie nie nadpisujemy;
 * - kategoria, link i zdjęcie tylko gdy puste; produktów znikniętych z B2B nie kasujemy;
 * - łącznik treści (B2bContentOnlySite, witryna producenta bez cen zakupu): brak ceny nie pomija pozycji,
 *   nic nie idzie do slotu ceny ani do historii cen, a pozycja bez karty w katalogu jest pomijana z powodem
 *   zamiast zakładać nową kartę; reszta (opis, tabelka, zdjęcia, pliki, rozmiary) idzie wspólną drogą;
 * - żaden łącznik nie zmienia nazwy istniejącej karty — nazwa ze źródła tylko na nowej (decyzja użytkownika
 *   15.09.2026; znacznik B2bKeepsExistingNames zostaje dla zgodności); b2b_product_links.remote_name zawsze
 *   trzyma nazwę ze źródła z ostatniego przebiegu;
 * - łącznik B2bForeignLanguageSource (decyzja użytkownika 15.09.2026): po zapisie opisu ze źródła (i przy nowej
 *   karcie — także nazwy) zlecamy TranslateB2bProductTextJob; nigdy w dry-run.
 *   Niezmiennik hashy powiązania: description_hash = sha1 opisu na karcie zapisanego przez synchronizację;
 *   source_description_hash niepusty tylko wtedy, gdy ten opis jest tłumaczeniem — wtedy to sha1 tekstu źródła.
 *   Źródło bez zmian i tłumaczenie na karcie nietknięte → import nie przywraca oryginału i nie zleca ponownie;
 *   zapis tekstu źródła zeruje source_description_hash. Oryginalnego opisu nie przechowujemy.
 *   Karta, która wciąż ma nieprzetłumaczony tekst źródła (TranslateB2bProductTextJob::pending), dostaje zlecenie
 *   przy każdym przebiegu — ponowne pobranie nadrabia tłumaczenia odrzucone, nieudane albo nadpisane.
 *
 * Grupa pozycji scalonych przez łącznik (B2bRemoteProduct::members, np. rozmiary o tej samej cenie — decyzja
 * użytkownika 15.09.2026): jedna karta, powiązanie dla każdej pozycji; kartę użytą w przebiegu przez inną grupę
 * pomijamy. Dostępność ze źródła tylko w slocie konta (product_source_prices.availability), dosłownie.
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

    /**
     * Największy plik dostawcy trafiający na kartę. Karty techniczne mają setki kB, ale wielojęzyczne instrukcje
     * bywają grubsze (16.09.2026 „instrukcja obsługi rękawic HexArmor.pdf” to 7,4 MB) — przy 5 MB odpadały.
     */
    private const DOCUMENT_MAX_BYTES = 12_000_000;

    /**
     * Rodzaje plików, z których wolno wziąć tekst opisu wyrobu. Po rozpoznaniu instrukcji, gwarancji
     * i tabeli rozmiarów część plików dotąd oznaczanych jako karta techniczna zmienia rodzaj — opis
     * ma dalej powstawać z karty produktu i z instrukcji obsługi, bo obie opisują sam wyrób.
     * Karta gwarancyjna i tabela rozmiarów mówią o warunkach i wymiarach, nie o wyrobie.
     *
     * @var list<string>
     */
    private const DESCRIPTION_SOURCE_KINDS = [ProductDocument::KIND_DATASHEET, ProductDocument::KIND_MANUAL];

    /**
     * Co ile dni odświeżamy kartę wyrobu ze sklepu (ProductShopCard). Pobranie pól bywa płatne dodatkowym
     * zapytaniem do sklepu (Anro pobiera parametry techniczne osobno), więc odświeżamy je nie częściej niż
     * raz na tydzień — tabelka u dostawcy zmienia się rzadziej niż cena.
     */
    public const SHOP_FIELDS_TTL_DAYS = 7;

    /**
     * Limit tekstu z kart sklepowych na karcie wyrobu — tyle samo co przy wersjach. Bez niego sama tabelka
     * „Protection Level” z UVEX (kilkadziesiąt wierszy) wypchnęłaby opis poza blob wyszukiwania (16000 znaków)
     * i poza dokument embeddingu (8000).
     */
    private const SHOP_FIELDS_SUMMARY_LIMIT = 1500;

    /** Tyle powodów pominięcia wraca w wyniku przebiegu (panel i CLI pokazują kilka pierwszych). */
    private const ERRORS_LIMIT = 200;

    /** Co tyle produktów zużycie pamięci trafia do laravel.log — po to, żeby przerwany przebieg dało się zbadać. */
    private const MEMORY_EVERY_PRODUCTS = 100;

    public function __construct(
        private readonly PriceListImportService $priceLists,
        private readonly ProductImageDownloader $images,
        private readonly ProductEffectivePrice $effectivePrices,
        private readonly ProductDocumentDownloader $documents = new ProductDocumentDownloader,
        private readonly B2bDocumentText $documentText = new B2bDocumentText,
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
     *     documents: int,
     *     shop_fields: int,
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
            'documents' => 0, 'shop_fields' => 0, 'translations_queued' => 0,
        ];
        $errors = [];
        $errorsOverLimit = 0;
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
        // pełny cennik potrafi mieć tysiące pominięć — w wyniku zostaje pierwsze ERRORS_LIMIT, reszta jako licznik
        $addError = static function (string $text) use (&$errors, &$errorsOverLimit): void {
            if (count($errors) < self::ERRORS_LIMIT) {
                $errors[] = $text;

                return;
            }
            $errorsOverLimit++;
        };
        $runId = $progress?->run()->id;
        // karty użyte w tym przebiegu (id → true, kod karty małymi literami → id; null = nowa karta w dry-run) —
        // grupa rozmiarów (members) nie trafia na kartę innej pozycji z tego samego przebiegu
        $claimed = ['products' => [], 'skus' => []];

        // długie pobieranie listy przed pierwszym produktem — komunikaty są sygnałem życia przebiegu,
        // a przy okazji jedynym miejscem, w którym widać prośbę o zatrzymanie (pętli produktów jeszcze nie ma)
        if ($connector instanceof B2bListProgressAware) {
            $connector->onListProgress(static function (string $message) use ($progress): void {
                $progress?->log('info', $message);
                $progress?->flush();
                if ($progress?->cancelRequested()) {
                    throw new B2bCancelledException('Zatrzymano w trakcie pobierania listy dostawcy');
                }
            });
        }

        foreach ($this->listedProducts($connector, $cancelled) as $remote) {
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
                    : $this->syncProduct($account, $connector, $remote, $dryRun, $withImages, $runId, $priceListId, $claimed);
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
                $addError($label.': '.$outcome['reason']);
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
                $stats['documents'] += (int) ($outcome['documents'] ?? 0);
                if ($outcome['shop_fields'] ?? false) {
                    $stats['shop_fields']++;
                }
                if ($outcome['translation_queued'] ?? false) {
                    $stats['translations_queued']++;
                }
                if (($outcome['image_error'] ?? null) !== null) {
                    $addError($label.': zdjęcie — '.$outcome['image_error']);
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

            if ($stats['seen'] % self::MEMORY_EVERY_PRODUCTS === 0) {
                Log::info('B2B: zużycie pamięci przebiegu', [
                    'b2b_account_id' => $account->id,
                    'sync_run_id' => $runId,
                    'seen' => $stats['seen'],
                    'total' => $stats['total_remote'],
                    'memory_mb' => (int) round(memory_get_usage(true) / 1024 / 1024),
                    'peak_mb' => (int) round(memory_get_peak_usage(true) / 1024 / 1024),
                    'limit' => ini_get('memory_limit'),
                ]);
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

        if ($errorsOverLimit > 0) {
            $progress?->log('warn', 'Dalszych powodów pominięcia nie wypisujemy: '.$errorsOverLimit);
        }

        if ($stats['translations_queued'] > 0) {
            $progress?->log('info', 'Opisy zlecone do tłumaczenia na polski: '.$stats['translations_queued']);
        }

        // Podsumowanie własne łącznika (np. trafienia reguł rabatowych) — po przejściu całej listy.
        if ($connector instanceof B2bRunSummaryAware) {
            foreach ($connector->runSummary() as $line) {
                $progress?->log('info', $line);
            }
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
     * Produkty z listy dostawcy; „Zatrzymaj” w trakcie pobierania samej listy kończy przebieg jako zatrzymany
     * (bez błędu) — pełna lista potrafi schodzić kilka minut, a pętli produktów jeszcze wtedy nie ma.
     *
     * @param  bool  $cancelled  przez referencję — ustawiane, gdy lista została przerwana
     * @return iterable<B2bRemoteProduct>
     */
    private function listedProducts(B2bConnector $connector, bool &$cancelled): iterable
    {
        try {
            yield from $connector->products();
        } catch (B2bCancelledException) {
            $cancelled = true;
        }
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
     * Pozycja pojedyncza (members = []) — karta po powiązaniu remoteId, inaczej po kodzie; jedno powiązanie.
     * Grupa pozycji scalonych przez łącznik (members, np. rozmiary o tej samej cenie — decyzja użytkownika 15.09.2026):
     * karta wg resolveGroupCard, powiązanie dla KAŻDEJ pozycji grupy (kod i nazwa pozycji dosłownie); karta, która
     * straciła wszystkie powiązania konta, dostaje ostrzeżenie — nic nie jest kasowane.
     * Dostępność ze źródła (niepusta wartość) tylko w slocie konta; variant_summary łącznika tylko na kartę bez
     * aktywnych wersji. Błąd pobrania opisu nie wstrzymuje ceny (ostrzeżenie w dzienniku, jak w ścieżce z wersjami).
     *
     * @param  array{products: array<int, true>, skus: array<string, int|null>}  $claimed  karty użyte w tym przebiegu
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
        array &$claimed,
    ): array {
        if ($remote->remoteId === '' || $remote->sku === '' || $remote->name === '') {
            return ['status' => 'skipped', 'reason' => 'brak kodu lub nazwy'];
        }

        $members = $remote->members !== [] ? $this->memberRows($remote) : [];
        $memberLinks = null;
        if ($members === []) {
            $link = B2bProductLink::query()
                ->where('b2b_account_id', $account->id)
                ->where('remote_id', $remote->remoteId)
                ->with('product')
                ->first();
            $linked = $link?->product;
            $existing = $linked ?? Product::query()->where('sku', $remote->sku)->first();
        } else {
            $group = $this->resolveGroupCard($account, $remote, $members, $claimed);
            if ($group['reason'] !== null) {
                return ['status' => 'skipped', 'reason' => $group['reason']];
            }
            $link = $group['link'];
            $linked = $group['linked'];
            $existing = $group['existing'];
            $memberLinks = $group['member_links'];
        }
        $manufacturer = mb_substr(trim($connector->manufacturer($remote)), 0, 100);

        if ($existing !== null && $linked === null && $this->foreignManufacturer($existing, $manufacturer)) {
            return ['status' => 'skipped', 'reason' => 'kod należy do karty producenta '.$existing->manufacturer];
        }

        // łącznik treści (B2bContentOnlySite, np. witryna producenta): brak ceny jest u niego normalny,
        // a katalog buduje cennik — pozycja bez karty nie zakłada nowej, tylko czeka na cennik
        $contentOnly = $connector instanceof B2bContentOnlySite;
        $price = $connector->price($remote);
        if ($price === null && ! $contentOnly) {
            return ['status' => 'skipped', 'reason' => 'brak ceny w B2B'];
        }
        if ($contentOnly && $existing === null) {
            return ['status' => 'skipped', 'reason' => 'brak karty w katalogu — cennik jej nie zawiera'];
        }

        // pola opisowe karty; ceny idą do slotu konta, nie do fill karty
        $payload = [
            'name' => mb_substr($remote->name, 0, 1000),
            'manufacturer' => $manufacturer,
        ];
        // każdy łącznik (decyzja użytkownika 15.09.2026): nazwa ze źródła tylko na nową kartę — przed
        // detectPriceChange/summarizeUpdate, żeby zachowana nazwa nie liczyła się jako zmiana
        if ($existing !== null) {
            unset($payload['name']);
        }
        // lista rozmiarów/kodów z łącznika ('' = wyczyść); karta z aktywnymi wersjami ma podsumowanie wersji — bez zmian
        if ($remote->variantSummary !== null && ($existing === null || ! $this->hasActiveVariants($existing))) {
            $summary = trim($remote->variantSummary);
            $payload['variant_summary'] = $summary === '' ? null : mb_substr($summary, 0, self::VARIANT_SUMMARY_LIMIT);
        }
        // łącznik treści nie wnosi ceny: pusta tablica nie dojdzie ani na kartę (nowych nie zakłada),
        // ani do slotu konta, ani do porównania cen — wszystkie te ścieżki są dla niego wyłączone
        $prices = $price === null ? [] : [
            // cena konta (po rabacie) = zakup; cena bazowa dostawcy = katalogowa
            'catalog_price_net' => $price->base ?? $price->net,
            'purchase_price' => $price->net,
            'discount_percent' => $price->discountPercent,
            'currency' => $price->currency,
        ];
        $slotKey = ProductSourcePrice::b2bKey((int) $account->id);
        // błąd pobrania opisu nie wstrzymuje ceny: opis zostaje bez zmian, ostrzeżenie w dzienniku przebiegu
        $warnings = [];
        $card = $this->cardDocuments($connector, $remote, $existing, $warnings);
        [$descriptionHash, $sourceTextTaken] = $this->applyCardDetails($payload, $existing, $link, $connector, $remote, $warnings, $card);

        $priceChange = null;
        $updateSummary = null;
        $dirty = true;
        $slotChanged = true;
        if ($contentOnly && $existing !== null) {
            // tryb „tylko treść”: nie ma ceny do porównania ani slotu do odświeżenia, więc o tym, czy karta się
            // zmieniła, decydują same pola opisowe. Podsumowania aktualizacji (kolumny cen) też nie budujemy.
            $existing->fill($payload);
            $dirty = $existing->isDirty();
            $slotChanged = false;
        } elseif ($existing !== null) {
            $slot = ProductSourcePrice::query()
                ->where('product_id', $existing->id)
                ->where('source_key', $slotKey)
                ->first();
            // porównanie z poprzednią ceną tego konta, nie z ceną obowiązującą (ta może być z pliku albo innego
            // konta); bez slotu (pierwszy przebieg konta) — z ceną karty. Przed fill — kopia ma zapisane wartości.
            $previous = $this->effectivePrices->cardWithSlotPrices($existing, $slot);
            $compared = [...$payload, ...$prices];
            $priceChange = $this->priceLists->detectPriceChange($previous, $compared, $remote->sku);
            $updateSummary = $this->priceLists->summarizeUpdate($previous, $compared, $remote->sku, $priceChange !== null);
            // dostępność tylko w slocie (poza $prices — te idą na nową kartę i do detectPriceChange); null = źródło
            // jej nie podaje, zapisana wartość zostaje
            $availabilityChanged = $remote->availability !== null && $slot?->availability !== $remote->availability;
            $slotChanged = $slot === null
                || $priceChange !== null
                || strtoupper((string) $slot->currency) !== strtoupper($price->currency)
                || $availabilityChanged;
            $existing->fill($payload);
            $dirty = $existing->isDirty();
            // summarizeUpdate nie zna tych pól — jak „wersje” w ścieżce z wersjami
            $extraFields = [
                ...($existing->isDirty('variant_summary') ? ['rozmiary'] : []),
                ...($availabilityChanged ? ['dostępność'] : []),
            ];
            if ($extraFields !== []) {
                $updateSummary['fields'] = [
                    ...array_values(array_diff($updateSummary['fields'], ['bez zmian wartości'])),
                    ...$extraFields,
                ];
            }
        }
        $status = $existing === null ? 'created' : (($dirty || $slotChanged) ? 'updated' : 'unchanged');

        if ($dryRun) {
            $this->claim($claimed, $existing !== null ? (int) $existing->id : null, $existing !== null ? (string) $existing->sku : $remote->sku);

            return ['status' => $status, 'description' => isset($payload['description'])];
        }

        // Karta i jej powiązanie w jednej transakcji (jak w ścieżce z wersjami): przerwany zapis zostawiłby kartę
        // z nowym opisem i powiązanie ze starym description_hash, a taka karta wygląda jak ręcznie zmieniona
        // — kolejne przebiegi nie ruszałyby już jej opisu (16.09.2026: 214 kart UVEX po błędzie pamięci podręcznej).
        [$product, $savedLink] = DB::transaction(function () use (
            $account, $connector, $remote, $existing, $payload, $prices, $slotKey, $priceChange, $priceListId,
            $runId, $dirty, $members, $memberLinks, $descriptionHash, $sourceTextTaken, $contentOnly, &$claimed, &$warnings,
        ): array {
            if ($existing !== null) {
                if ($dirty) {
                    $existing->save();
                }
                $product = $existing;
            } else {
                // kolumny cen karty są NOT NULL — nowa karta startuje z ceną konta, przeliczenie ze slotu jej nie zmieni
                $product = Product::query()->create(['sku' => $remote->sku, ...$payload, ...$prices]);
            }

            $this->claim($claimed, (int) $product->id, (string) $product->sku);

            // Łącznik treści nie ma ceny do zapisania: slot konta zostawiamy pusty, a historii cen nie dotykamy.
            // Slot z ceną detaliczną producenta wygrałby z ceną z cennika (ProductEffectivePrice) i zawyżył wycenę.
            if (! $contentOnly) {
                // zapis slotu także bez zmiany ceny — checked_at wyznacza najświeższe konto przy kilku kontach
                $slot = $this->effectivePrices->saveSlot($product, $slotKey, [
                    ...$prices,
                    'b2b_account_id' => $account->id,
                    // null = źródło nie podaje dostępności — zapisana wartość zostaje
                    ...($remote->availability !== null ? ['availability' => $remote->availability] : []),
                ])['slot'];

                if ($existing === null || $priceChange !== null) {
                    ProductPriceHistory::query()->create([
                        'product_id' => $product->id,
                        'price_list_id' => $priceListId,
                        'b2b_sync_run_id' => $runId,
                        'catalog_price_net' => $slot->catalog_price_net,
                        'purchase_price' => $slot->purchase_price,
                        'source' => 'b2b:'.$connector::key(),
                    ]);
                }
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
            if ($members === []) {
                $savedLink = B2bProductLink::query()->updateOrCreate(
                    ['b2b_account_id' => $account->id, 'remote_id' => $remote->remoteId],
                    $linkValues,
                );
            } else {
                // powiązanie dla każdej pozycji grupy — kod i nazwa pozycji dosłownie; memberRows zaczyna od remoteId
                $savedLink = null;
                foreach ($members as $member) {
                    $saved = B2bProductLink::query()->updateOrCreate(
                        ['b2b_account_id' => $account->id, 'remote_id' => $member['remote_id']],
                        [
                            ...$linkValues,
                            'remote_sku' => mb_substr($member['sku'], 0, 255),
                            'remote_name' => mb_substr($member['name'], 0, 1000),
                        ],
                    );
                    $savedLink ??= $saved;
                }
                $warnings = [...$warnings, ...$this->orphanedCardWarnings($account, $memberLinks, (int) $product->id)];
            }

            return [$product, $savedLink];
        });

        // Hak modelu wysyła reindeks jeszcze w transakcji (kolejka bez after_commit) — worker mógłby przeczytać
        // kartę sprzed zapisu. Ponowne zlecenie po commit; ShouldBeUnique pomija je, gdy pierwsze jeszcze czeka.
        if ($product->wasRecentlyCreated || $product->wasChanged(ProductSearchBlob::SOURCE_COLUMNS)) {
            ReindexProductEmbeddingJob::dispatch((int) $product->id);
        }

        // po zapisie powiązania — job czyta z niego hashe i nazwę ze źródła
        $created = $existing === null;
        $translationQueued = false;
        $foreignText = $connector instanceof B2bForeignLanguageSource
            || ($connector instanceof B2bForeignTextCards && $connector->hasForeignDescription($remote));
        if ($foreignText) {
            // Karta, która wciąż ma tekst źródła (tłumaczenie odrzucone, nieudane albo nadpisane — 15.09.2026 ponowne
            // pobranie niczego nie nadrabiało), dostaje zlecenie przy każdym przebiegu; czekający job nie jest
            // dublowany (ShouldBeUniqueUntilProcessing). Nazwa istniejącej karty tylko gdy to wciąż nazwa ze źródła
            // i łącznik zachowuje nazwy — jak b2b:translate.
            $pending = TranslateB2bProductTextJob::pending(
                $product,
                $savedLink,
                $created || $connector instanceof B2bKeepsExistingNames,
            );
            if (isset($payload['description']) || $created || $pending['description'] || $pending['name']) {
                TranslateB2bProductTextJob::dispatch((int) $product->id, (int) $account->id, $created || $pending['name']);
                $translationQueued = true;
            }
        }

        [$image, $imageError] = $withImages ? $this->storeImage($connector, $remote, $product, $account) : [false, null];
        $documents = $this->storeDocuments($account, $product, $connector, $card, $warnings);
        $shopFields = $this->storeShopFields($connector, $remote, $product, $account, $warnings);

        return [
            'status' => $status,
            'product_id' => (int) $product->id,
            'documents' => $documents,
            'shop_fields' => $shopFields,
            'price_change' => $priceChange,
            'update_summary' => $status === 'updated' ? $updateSummary : null,
            'description' => isset($payload['description']),
            'image' => $image,
            'image_error' => $imageError,
            'translation_queued' => $translationQueued,
            'warnings' => $warnings,
        ];
    }

    /**
     * Pozycje grupy bez pustych i powtórzonych ID; pozycja remoteId zawsze pierwsza (gdy łącznik jej nie podał —
     * z kodu i nazwy produktu). Kod i nazwa pozycji dosłownie.
     *
     * @return list<array{remote_id: string, sku: string, name: string}>
     */
    private function memberRows(B2bRemoteProduct $remote): array
    {
        $rows = ['#'.$remote->remoteId => ['remote_id' => $remote->remoteId, 'sku' => $remote->sku, 'name' => $remote->name]];
        foreach ($remote->members as $member) {
            $id = (string) ($member['remote_id'] ?? '');
            if (trim($id) === '') {
                continue;
            }
            $row = ['remote_id' => $id, 'sku' => (string) ($member['sku'] ?? ''), 'name' => (string) ($member['name'] ?? '')];
            if ($id === $remote->remoteId) {
                $rows['#'.$id] = [
                    'remote_id' => $id,
                    'sku' => $row['sku'] !== '' ? $row['sku'] : $remote->sku,
                    'name' => $row['name'] !== '' ? $row['name'] : $remote->name,
                ];

                continue;
            }
            $rows['#'.$id] ??= $row;
        }

        return array_values($rows);
    }

    /**
     * Karta grupy pozycji: (a) powiązanie remoteId → jego karta; (b) karta o kodzie remote->sku; (c) karta, na którą
     * wskazuje najwięcej powiązań pozostałych pozycji grupy (remis → najniższe id); (d) brak → nowa karta.
     * Karta użyta w tym przebiegu przez inną pozycję ($claimed) jest pomijana na każdym kroku. Gdy kod jest już SKU
     * takiej karty, nowa karta złamałaby UNIQUE products.sku — pozycja pominięta z powodem.
     * „linked” = karta, na którą wskazuje powiązanie pozycji grupy (bez reguły producenta); „link” = to powiązanie
     * (hashe opisu) albo null przy dopasowaniu po samym kodzie. member_links — powiązania pozycji sprzed zapisu.
     *
     * @param  list<array{remote_id: string, sku: string, name: string}>  $members
     * @param  array{products: array<int, true>, skus: array<string, int|null>}  $claimed
     * @return array{reason: string|null, link: B2bProductLink|null, linked: Product|null, existing: Product|null, member_links: Collection<int, B2bProductLink>}
     */
    private function resolveGroupCard(B2bAccount $account, B2bRemoteProduct $remote, array $members, array $claimed): array
    {
        /** @var Collection<int, B2bProductLink> $memberLinks */
        $memberLinks = B2bProductLink::query()
            ->where('b2b_account_id', $account->id)
            ->whereIn('remote_id', array_column($members, 'remote_id'))
            ->with('product')
            ->orderBy('id')
            ->get()
            ->values();
        $isClaimed = static fn (int $productId): bool => isset($claimed['products'][$productId]);
        $found = static fn (?B2bProductLink $link, ?Product $card, ?string $reason = null): array => [
            'reason' => $reason,
            'link' => $link,
            'linked' => $link !== null ? $card : null,
            'existing' => $card,
            'member_links' => $memberLinks,
        ];
        $conflict = static fn (?int $productId): string => $productId !== null
            ? 'kod '.$remote->sku.' jest już SKU karty #'.$productId.' użytej w tym przebiegu przez inną grupę rozmiarów'
            : 'kod '.$remote->sku.' jest już SKU nowej karty z tego przebiegu (inna grupa rozmiarów)';

        // (a)
        $own = $memberLinks->first(static fn (B2bProductLink $l): bool => (string) $l->remote_id === $remote->remoteId);
        if ($own?->product !== null && ! $isClaimed((int) $own->product_id)) {
            return $found($own, $own->product);
        }

        // (b)
        $skuKey = mb_strtolower($remote->sku);
        if (array_key_exists($skuKey, $claimed['skus'])) {
            return $found(null, null, $conflict($claimed['skus'][$skuKey]));
        }
        $bySku = Product::query()->where('sku', $remote->sku)->first();
        if ($bySku !== null) {
            if ($isClaimed((int) $bySku->id)) {
                return $found(null, null, $conflict((int) $bySku->id));
            }

            return $found(
                $memberLinks->first(static fn (B2bProductLink $l): bool => (int) $l->product_id === (int) $bySku->id),
                $bySku,
            );
        }

        // (c)
        $counts = [];
        foreach ($memberLinks as $memberLink) {
            if ($memberLink->product === null || $isClaimed((int) $memberLink->product_id)) {
                continue;
            }
            $counts[(int) $memberLink->product_id] = ($counts[(int) $memberLink->product_id] ?? 0) + 1;
        }
        if ($counts !== []) {
            ksort($counts);
            $best = (int) array_search(max($counts), $counts, true);
            $link = $memberLinks->first(static fn (B2bProductLink $l): bool => (int) $l->product_id === $best);

            return $found($link, $link?->product);
        }

        // (d)
        return $found(null, null);
    }

    /**
     * Karty, na które wskazywały powiązania pozycji grupy przed zapisem, a które po przepięciu nie mają już żadnego
     * powiązania tego konta. Niczego nie kasujemy — slot ceny konta zostaje do decyzji użytkownika.
     *
     * @param  Collection<int, B2bProductLink>|null  $memberLinks
     * @return list<string>
     */
    private function orphanedCardWarnings(B2bAccount $account, ?Collection $memberLinks, int $productId): array
    {
        if ($memberLinks === null) {
            return [];
        }
        $warnings = [];
        $seen = [];
        foreach ($memberLinks as $memberLink) {
            $cardId = (int) $memberLink->product_id;
            if ($cardId === $productId || isset($seen[$cardId])) {
                continue;
            }
            $seen[$cardId] = true;
            $left = B2bProductLink::query()
                ->where('b2b_account_id', $account->id)
                ->where('product_id', $cardId)
                ->exists();
            if (! $left) {
                $warnings[] = sprintf(
                    'karta #%d (%s) nie ma już kodów w B2B tego konta — jej cena z konta zostaje do decyzji',
                    $cardId,
                    (string) ($memberLink->product?->sku ?? '?'),
                );
            }
        }

        return $warnings;
    }

    /**
     * @param  array{products: array<int, true>, skus: array<string, int|null>}  $claimed
     */
    private function claim(array &$claimed, ?int $productId, string $sku): void
    {
        if ($productId !== null) {
            $claimed['products'][$productId] = true;
        }
        $claimed['skus'][mb_strtolower($sku)] ??= $productId;
    }

    private function hasActiveVariants(Product $product): bool
    {
        return ProductVariant::query()->where('product_id', $product->id)->whereNull('removed_at')->exists();
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
        // jak w ścieżce bez wersji: nazwa ze źródła tylko na nową kartę (decyzja użytkownika 15.09.2026)
        if ($existing !== null) {
            unset($payload['name']);
        }
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

        [$image, $imageError] = $withImages ? $this->storeImage($connector, $remote, $product, $account) : [false, null];
        $shopFields = $this->storeShopFields($connector, $remote, $product, $account, $warnings);

        return [
            'status' => $status,
            'product_id' => (int) $product->id,
            'shop_fields' => $shopFields,
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
     * Z $warnings błąd pobrania opisu nie przerywa produktu: opis i jego hash zostają bez zmian. Od 15.09.2026 obie
     * ścieżki (z wersjami i bez) przekazują $warnings — cena konta nie czeka na opis; bez $warnings wyjątek leci dalej.
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
        array $card = ['documents' => [], 'texts' => []],
    ): array {
        if ($remote->category !== null && trim((string) ($existing?->category ?? '')) === '') {
            $payload['category'] = mb_substr($remote->category, 0, 255);
        }
        if ($remote->sourceUrl !== null && trim((string) ($existing?->shop_source_url ?? '')) === '') {
            $payload['shop_source_url'] = $remote->sourceUrl;
        }

        $descriptionHash = $link?->description_hash;
        // Hierarchia źródeł opisu: najpierw producent. Witryna producenta tej marki zastępuje
        // opis już zapisany na karcie (z AI, z Presty, od dystrybutora) — opisów nie redaguje
        // się u nas ręcznie, więc nie ma czego bronić hashem.
        $fromManufacturer = $connector instanceof B2bManufacturerSite
            && $existing !== null
            && $this->sameBrand($connector::ownBrand(), (string) $existing->manufacturer);
        if ($this->mayWriteDescription($existing, $link, $fromManufacturer)) {
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
            $description = self::withDatasheet($description, $card);
            if ($description !== '') {
                if ($this->keepsTranslation($existing, $link, $description)) {
                    return [$descriptionHash, false];
                }
                $replaces = $existing !== null && $existing->hasDescriptionText()
                    && $description !== (string) $existing->description;
                // Etykieta („Jednostka: szt.”) nie zastępuje opisu, który już jest na karcie.
                if ($replaces && ! Product::isDescriptionText($description)) {
                    return [$descriptionHash, false];
                }
                $descriptionHash = sha1($description);
                if ($existing === null || $description !== (string) $existing->description) {
                    $payload['description'] = $description;
                }
                if ($replaces) {
                    // Zastąpiony tekst zostaje w karcie przebiegu i w payloadzie — nadpisanie
                    // ma być odwracalne, a nie ciche.
                    $payload['enrichment_payload'] = $this->withReplacedDescription($existing, $description);
                    if ($warnings !== null) {
                        $warnings[] = 'opis zastąpiony opisem producenta (poprzedni w enrichment_payload.replaced_description)';
                    }
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

    /** Ta sama marka po odsianiu wielkosci liter, znakow i dopisków („Bolle Safety” = „BOLLE”). */
    private function sameBrand(string $a, string $b): bool
    {
        $key = static function (string $v): string {
            $v = trim(explode('(', explode('/', $v)[0])[0]);
            $v = mb_strtolower($v);
            $v = preg_replace('/[^a-z0-9]+/u', ' ', $v) ?? $v;

            return trim(explode(' ', trim($v))[0]);
        };

        return $key($a) !== '' && $key($a) === $key($b);
    }

    private function foreignManufacturer(Product $existing, string $manufacturer): bool
    {
        return mb_strtolower(trim((string) $existing->manufacturer)) !== mb_strtolower($manufacturer);
    }

    /**
     * Opis karty ze sklepu dostawcy + dosłowny tekst z karty technicznej (PDF z zakładki „Pliki do pobrania”),
     * z nazwą pliku jako źródłem. Nic nie jest dopisywane od siebie: bez tekstu w pliku nie ma sekcji.
     *
     * @param  array{documents: list<B2bRemoteDocument>, texts: array<string, string>}  $card
     */
    private static function withDatasheet(string $description, array $card): string
    {
        $sections = $description !== '' ? [$description] : [];
        foreach ($card['documents'] as $document) {
            $text = B2bDocumentText::forCard($card['texts'][$document->sourceUrl] ?? '');
            if ($text === '') {
                continue;
            }
            $sections[] = 'Z karty technicznej ('.$document->title.'):'."\n".$text;
        }

        return implode("\n\n", $sections);
    }

    /**
     * Pliki karty u dostawcy i tekst karty technicznej. Tekst raz odczytany zostaje przy dokumencie
     * (product_documents.text), więc kolejne przebiegi nie pobierają PDF-ów ponownie — pobieramy tylko to,
     * czego karta jeszcze nie ma.
     *
     * @param  list<string>|null  $warnings
     * @return array{documents: list<B2bRemoteDocument>, texts: array<string, string>}
     */
    private function cardDocuments(
        B2bConnector $connector,
        B2bRemoteProduct $remote,
        ?Product $existing,
        ?array &$warnings = null,
    ): array {
        $empty = ['documents' => [], 'texts' => []];
        if (! $connector instanceof B2bDocumentSource) {
            return $empty;
        }

        try {
            $documents = $connector->documents($remote);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (Throwable $e) {
            $warnings[] = 'pliki produktu nie zostały odczytane ('.$e->getMessage().')';

            return $empty;
        }

        $texts = [];
        foreach ($documents as $document) {
            // do opisu bierzemy pierwszą kartę techniczną; reszta plików zostaje załącznikiem
            if (! in_array($document->kind, self::DESCRIPTION_SOURCE_KINDS, true)) {
                continue;
            }
            $stored = $existing === null ? null : ProductDocument::query()
                ->where('product_id', $existing->id)
                ->where('source_url', mb_substr($document->sourceUrl, 0, 2000))
                ->first();
            if ($stored !== null && $stored->text !== null) {
                $texts[$document->sourceUrl] = (string) $stored->text;

                break;
            }
            try {
                $file = $connector->documentBytes($document);
                $texts[$document->sourceUrl] = $this->documentText->fromFile($file['bytes'], $file['mime']);
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (Throwable $e) {
                $warnings[] = 'karta techniczna nie została pobrana ('.$e->getMessage().')';
            }

            break;
        }

        return ['documents' => $documents, 'texts' => $texts];
    }

    /**
     * Pliki dostawcy przy karcie (ProductDocument): pobieramy tylko te, których karta jeszcze nie ma pod tym
     * adresem. Tekst odczytany dla opisu zapisujemy razem z plikiem; z pozostałych PDF-ów czytamy go przy zapisie,
     * bo bajty i tak są w ręku.
     *
     * @param  array{documents: list<B2bRemoteDocument>, texts: array<string, string>}  $card
     * @param  list<string>|null  $warnings
     * @return int liczba plików zapisanych albo uzupełnionych przy karcie
     */
    private function storeDocuments(
        B2bAccount $account,
        Product $product,
        B2bConnector $connector,
        array $card,
        ?array &$warnings = null,
    ): int {
        if (! $connector instanceof B2bDocumentSource || $card['documents'] === []) {
            return 0;
        }

        $urls = array_map(
            static fn (B2bRemoteDocument $document): string => mb_substr($document->sourceUrl, 0, 2000),
            $card['documents'],
        );
        $stored = ProductDocument::query()
            ->where('product_id', $product->id)
            ->whereIn('source_url', $urls)
            ->get()
            ->keyBy('source_url');
        $sortOrder = (int) ProductDocument::query()->where('product_id', $product->id)->max('sort_order');

        $saved = 0;
        foreach ($card['documents'] as $document) {
            $url = mb_substr($document->sourceUrl, 0, 2000);
            $have = $stored->get($url);
            $text = $card['texts'][$document->sourceUrl] ?? null;
            if ($have !== null) {
                // plik już jest przy karcie — bajtów nie pobieramy ponownie, najwyżej uzupełniamy tekst
                if ($text !== null && $have->text === null) {
                    $have->forceFill(['text' => $text])->save();
                    $saved++;
                }

                continue;
            }
            try {
                $file = $connector->documentBytes($document);
                $text ??= in_array($document->kind, self::DESCRIPTION_SOURCE_KINDS, true)
                    ? $this->documentText->fromFile($file['bytes'], $file['mime'])
                    : null;
                $written = $this->documents->storeBytes(
                    $product,
                    $file['bytes'],
                    $file['mime'],
                    $document->sourceUrl,
                    $document->title,
                    $document->kind,
                    ++$sortOrder,
                    $text,
                    (int) $account->id,
                    self::DOCUMENT_MAX_BYTES,
                );
                if ($written !== null) {
                    $saved++;

                    continue;
                }
                $warnings[] = 'plik „'.$document->title.'” pominięty (typ albo rozmiar poza limitem)';
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (Throwable $e) {
                $warnings[] = 'plik „'.$document->title.'” nie został zapisany ('.$e->getMessage().')';
            }
        }

        return $saved;
    }

    /**
     * Karta wyrobu u dostawcy (ProductShopCard): wiersze nazwa→wartość ze sklepu, osobno od products.description
     * i osobno dla każdego konta B2B. Poza transakcją zapisu karty — błąd pól nie może cofnąć ceny ani karty.
     *
     * Brama kosztu: pola pobieramy tylko wtedy, gdy para (karta, konto) nie ma jeszcze rekordu albo jest on
     * starszy niż SHOP_FIELDS_TTL_DAYS — inaczej nie wysyłamy do sklepu niczego. Pusta odpowiedź (dostawca
     * przestał podawać tabelkę) kasuje rekord tej pary, zamiast zostawiać nieaktualne wiersze.
     *
     * Po każdej ścieżce (także tych, które kończą się bez zapisu) odświeżamy products.shop_fields_summary — tekst
     * dla wyszukiwania liczony ze stanu w bazie, więc obejmuje też karty pozostałych kont.
     *
     * @param  list<string>|null  $warnings
     * @return bool czy wiersze zostały zapisane albo odświeżone
     */
    private function storeShopFields(
        B2bConnector $connector,
        B2bRemoteProduct $remote,
        Product $product,
        B2bAccount $account,
        ?array &$warnings = null,
    ): bool {
        $saved = $this->syncShopFields($connector, $remote, $product, $account, $warnings);
        self::refreshShopFieldsSummary($product);

        return $saved;
    }

    /**
     * Samo pobranie i zapisanie wierszy karty sklepowej dla pary (karta, konto).
     *
     * @param  list<string>|null  $warnings
     * @return bool czy wiersze zostały zapisane albo odświeżone
     */
    private function syncShopFields(
        B2bConnector $connector,
        B2bRemoteProduct $remote,
        Product $product,
        B2bAccount $account,
        ?array &$warnings = null,
    ): bool {
        if (! $connector instanceof B2bShopFieldSource) {
            return false;
        }

        $stored = ProductShopCard::query()
            ->where('product_id', $product->id)
            ->where('b2b_account_id', $account->id)
            ->first();
        if ($stored !== null && $stored->synced_at !== null
            && $stored->synced_at->gt(CarbonImmutable::now()->subDays(self::SHOP_FIELDS_TTL_DAYS))) {
            return false;
        }

        try {
            $fields = $connector->shopFields($remote);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (Throwable $e) {
            $warnings[] = 'dane z karty w sklepie nie zostały odczytane ('.$e->getMessage().') — karta zapisana bez nich';

            return false;
        }

        $sections = ProductShopCard::sectionsFrom($fields);
        if ($sections === []) {
            // dostawca przestał podawać tabelkę — zapisane wiersze nie mają już źródła
            $stored?->delete();

            return false;
        }

        if ($stored !== null && self::isPoorerShopCard($sections, is_array($stored->fields) ? $stored->fields : [])) {
            // Zapisanej tabelki nie zastępujemy uboższą odpowiedzią — zostaje poprzednia, bez dotykania synced_at,
            // żeby następny przebieg spróbował pobrać ją jeszcze raz.
            $warnings[] = 'dane z karty w sklepie były uboższe od zapisanych — zostawiono poprzednie wiersze';

            return false;
        }

        ProductShopCard::query()->updateOrCreate(
            ['product_id' => $product->id, 'b2b_account_id' => $account->id],
            [
                'fields' => $sections,
                'source_url' => $remote->sourceUrl !== null ? mb_substr($remote->sourceUrl, 0, 2000) : null,
                'synced_at' => now(),
            ],
        );

        return true;
    }

    /**
     * Czy nowa odpowiedź to uboższa wersja zapisanej tabelki: ma mniej wierszy i nie wnosi żadnej nowej sekcji.
     *
     * Tak wygląda karta UVEX: wiersze „Dane techniczne” pochodzą ze strony producenta, a łącznik zagląda tam tylko
     * przy okazji opisu — karta z opisem ręcznym albo od AI dostaje po TTL same dwa wiersze handlowe. To luka
     * w pobraniu, nie zmiana u dostawcy, więc nadpisanie (updateOrCreate) kasowałoby dane bez powodu. Odpowiedź
     * z nową sekcją albo z tyloma samymi wierszami traktujemy normalnie — wtedy u dostawcy naprawdę coś się zmieniło.
     *
     * @param  array<int, array{section: string, rows: list<array{name: string, value: string}>}>  $fresh
     * @param  array<int, array{section: string, rows: list<array{name: string, value: string}>}>  $stored
     */
    private static function isPoorerShopCard(array $fresh, array $stored): bool
    {
        if ($stored === [] || self::shopCardRows($fresh) >= self::shopCardRows($stored)) {
            return false;
        }

        $known = [];
        foreach ($stored as $section) {
            $known['#'.trim((string) ($section['section'] ?? ''))] = true;
        }
        foreach ($fresh as $section) {
            if (! isset($known['#'.trim((string) ($section['section'] ?? ''))])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array{section: string, rows: list<array{name: string, value: string}>}>  $sections
     */
    private static function shopCardRows(array $sections): int
    {
        $rows = 0;
        foreach ($sections as $section) {
            $rows += is_array($section['rows'] ?? null) ? count($section['rows']) : 0;
        }

        return $rows;
    }

    /**
     * Tekst z kart wyrobu u dostawców (ProductShopCard) na kolumnie products.shop_fields_summary — dane ze sklepu
     * mają być widoczne dla wyszukiwania leksykalnego i wektorowego, mimo że nie są opisem wyrobu.
     *
     * Liczony ze stanu zapisanego w bazie (wszystkie konta naraz), nie z odpowiedzi łącznika, więc przebieg jednego
     * konta nie gubi wierszy pozostałych. Zapis zwykłym save(): hak `saving` przelicza search_blob, hak `updated`
     * zleca reindeks wektora — i tylko wtedy, gdy tekst faktycznie się zmienił.
     *
     * @return bool czy kolumna została zapisana
     */
    public static function refreshShopFieldsSummary(Product $product): bool
    {
        $product->shop_fields_summary = self::shopFieldsSummary($product);
        if (! $product->isDirty('shop_fields_summary')) {
            return false;
        }

        $product->save();

        return true;
    }

    /**
     * Układ (zamrożony): nazwa sekcji w osobnej linii, pod nią wiersze „nazwa: wartość” po jednym w linii; sekcja
     * bez nazwy nie ma nagłówka. Karty kolejnych kont idą po sobie w kolejności b2b_account_id — kolejność musi być
     * stała, bo inaczej ten sam stan bazy dawałby raz taki, raz inny tekst i każdy przebieg zlecałby reindeks
     * wektora bez powodu.
     */
    public static function shopFieldsSummary(Product $product): ?string
    {
        $lines = [];
        $cards = ProductShopCard::query()
            ->where('product_id', $product->id)
            ->orderBy('b2b_account_id')
            ->get();

        foreach ($cards as $card) {
            foreach (is_array($card->fields) ? $card->fields : [] as $section) {
                $name = trim((string) ($section['section'] ?? ''));
                if ($name !== '') {
                    $lines[] = $name;
                }
                foreach (is_array($section['rows'] ?? null) ? $section['rows'] : [] as $row) {
                    $label = trim((string) ($row['name'] ?? ''));
                    $value = trim((string) ($row['value'] ?? ''));
                    if ($label === '' || $value === '') {
                        continue;
                    }
                    $lines[] = $label.': '.$value;
                }
            }
        }

        $text = trim(implode("\n", $lines));

        return $text === '' ? null : mb_substr($text, 0, self::SHOP_FIELDS_SUMMARY_LIMIT);
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function storeImage(B2bConnector $connector, B2bRemoteProduct $remote, Product $product, B2bAccount $account): array
    {
        if ($connector instanceof B2bImageGallery) {
            return $this->storeGallery($connector, $remote, $product, $account);
        }
        if ($product->images()->exists()) {
            return [false, null];
        }
        try {
            $remoteImage = $connector->image($remote);
            if ($remoteImage === null) {
                return [false, null];
            }

            $stored = $this->images->storeBytes(
                $product,
                $remoteImage->bytes,
                $remoteImage->mime,
                $remoteImage->sourceUrl,
                0,
                (int) $account->id,
            );
            if ($stored !== null) {
                ProductImage::resequence((int) $product->id);
            }

            return [$stored !== null, null];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Wszystkie zdjęcia karty u dostawcy, w kolejności ze sklepu. Pobieramy tylko te, których karta jeszcze nie
     * ma pod tym adresem — kolejny przebieg nie ściąga niczego ponownie, ale dokłada ujęcia, które dostawca
     * dodał później. Karta ze zdjęciami z innego źródła zostaje przy swoim głównym: nowe idą na koniec.
     *
     * @return array{0: bool, 1: string|null}
     */
    private function storeGallery(B2bImageGallery $connector, B2bRemoteProduct $remote, Product $product, B2bAccount $account): array
    {
        try {
            $urls = $connector->imageUrls($remote);
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
        if ($urls === []) {
            return [false, null];
        }

        $have = ProductImage::query()->where('product_id', $product->id)->pluck('source_url')->all();
        $stored = array_flip(array_map(static fn ($url): string => (string) $url, $have));
        $sortOrder = $have === []
            ? 0
            : (int) ProductImage::query()->where('product_id', $product->id)->max('sort_order') + 1;

        $saved = false;
        $error = null;
        foreach ($urls as $url) {
            if (isset($stored[mb_substr($url, 0, 2000)])) {
                continue;
            }
            try {
                $image = $connector->imageAt($url);
                if ($image === null) {
                    continue;
                }
                if ($this->images->storeBytes($product, $image->bytes, $image->mime, $image->sourceUrl, $sortOrder, (int) $account->id) !== null) {
                    $saved = true;
                    $sortOrder++;
                }
            } catch (Throwable $e) {
                // pierwsze niepobrane zdjęcie idzie do dziennika przebiegu; pozostałych i tak próbujemy
                $error ??= $e->getMessage();
            }
        }

        if ($saved) {
            ProductImage::resequence((int) $product->id);
        }

        return [$saved, $error];
    }

    private function mayWriteDescription(?Product $existing, ?B2bProductLink $link, bool $fromManufacturer = false): bool
    {
        if ($existing === null || ! $existing->hasDescriptionText()) {
            return true;
        }
        if ($fromManufacturer) {
            return true;
        }

        return $link?->description_hash !== null
            && hash_equals($link->description_hash, sha1((string) $existing->description));
    }

    /**
     * Poprzedni opis karty zapisany obok wyniku wzbogacania — bez tego nadpisanie opisem
     * producenta byłoby nieodwracalne, bo nigdzie indziej starego tekstu nie trzymamy.
     *
     * @return array<string, mixed>
     */
    private function withReplacedDescription(Product $existing, string $description): array
    {
        $payload = is_array($existing->enrichment_payload) ? $existing->enrichment_payload : [];
        $payload['replaced_description'] = mb_substr((string) $existing->description, 0, 10000);
        $payload['replaced_description_at'] = now()->toIso8601String();
        $payload['replaced_description_hash'] = sha1($description);

        return $payload;
    }
}
