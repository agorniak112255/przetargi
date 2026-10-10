<?php

declare(strict_types=1);

namespace App\Services\PriceLists;

use App\Jobs\MapPriceListSourcesJob;
use App\Models\AssortmentGroup;
use App\Models\PriceList;
use App\Models\PriceListFile;
use App\Models\PriceListImport;
use App\Models\Product;
use App\Models\User;
use App\Services\B2b\B2bDescriptionSource;
use App\Services\PriceListImportService;
use App\Services\PriceLists\Importers\DefaultMapContext;
use App\Services\PriceLists\Importers\ImportedRow;
use App\Services\PriceLists\Importers\PriceListFormatChanged;
use App\Services\PriceLists\Importers\PriceListImporter;
use App\Services\PriceLists\Importers\PriceListImporterRegistry;
use App\Services\PriceLists\Importers\ReadContext;
use App\Services\PriceLists\Importers\ReadResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * Cennik z importerem (10.10.2026): podgląd importu bez zapisu i import pliku cennika. Podgląd = odczyt pliku
 * importerem, plan importu (PriceListImportService::planImport) i mapa kart z limitem, wszystko w ReadOnlyGuard.
 * Import = odczyt, zapis kart (importCollected), status pliku, zadanie mapy kart (MapPriceListSourcesJob).
 */
final class PriceListIntakeRunner
{
    /** Powód decyzji importera, gdy w podglądzie (bez pobierania stron) nie dało się rozstrzygnąć — liczony osobno. */
    public const NOT_CHECKED_REASON = 'nie sprawdzono w podglądzie';

    /** Ile przykładowych kart z mapą pokazuje podgląd. */
    private const SAMPLES = 30;

    /** Blokada importu cennika (jeden import naraz). */
    /** Blokada importu cennika — trzyma ją też przywracanie starszego pliku (PriceListFileStore). */
    public const LOCK_PREFIX = 'price-list-intake:';

    private const LOCK_SECONDS = 3600;

    /** Ile kart bez strony wypisuje podgląd. */
    private const UNRESOLVED_LIST = 100;

    public function __construct(
        private readonly PriceListImporterRegistry $registry,
        private readonly PriceListImportService $imports,
        private readonly ReadOnlyGuard $guard,
    ) {}

    /**
     * PreviewView (plan sekcja 5) — zero zapisów.
     *
     * @return array<string, mixed>
     *
     * @throws PriceListFormatChanged
     * @throws IntakeNotReady gdy brak importera / klasa nie istnieje
     */
    public function preview(PriceList $list, PriceListFile $file, int $limit = 200): array
    {
        return $this->previewWith($list, $file, $limit, false);
    }

    /**
     * Podgląd z wyborem pobierania stron (price-lists:preview --live-fetch). Pobrane strony trafiają tylko do pamięci
     * podręcznej w tablicy (ReadOnlyGuard).
     *
     * @return array<string, mixed>
     */
    public function previewWith(PriceList $list, PriceListFile $file, int $limit, bool $liveFetch): array
    {
        $importer = $this->importerFor($list);
        $this->assertNoAssortmentGroups($list);
        $this->assertNoSupplierSpecialPrices($list);

        return $this->guard->run(fn (): array => $this->buildPreview($list, $file, $importer, max(0, $limit), $liveFetch));
    }

    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<string>, price_changes: list<array<string, mixed>>, map_job: string}
     *
     * @throws PriceListFormatChanged
     * @throws IntakeNotReady gdy brak importera / klasa nie istnieje / cennik ma grupy asortymentowe
     * @throws IntakeBusy gdy import tego cennika już trwa (kontroler: 409)
     */
    public function import(PriceList $list, PriceListFile $file, User $user, bool $describe): array
    {
        $importer = $this->importerFor($list);
        $this->assertNoAssortmentGroups($list);
        $this->assertNoSupplierSpecialPrices($list);
        $lock = Cache::lock(self::LOCK_PREFIX.$list->id, self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new IntakeBusy('Import tego cennika już trwa.');
        }
        try {
            return $this->importLocked($list, $file, $user, $describe, $importer);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<string>, price_changes: list<array<string, mixed>>, map_job: string}
     */
    private function importLocked(PriceList $list, PriceListFile $file, User $user, bool $describe, PriceListImporter $importer): array
    {
        $meta = ['importer_key' => $importer::key(), 'importer_version' => $importer::version()];
        // wpis importu tego cennika zapisany po tej chwili = karty już w bazie (błąd po zatwierdzeniu transakcji)
        $lastImportId = (int) PriceListImport::query()->where('price_list_id', $list->id)->max('id');
        try {
            $read = $this->read($list, $file, $importer);
            $result = $this->imports->importCollected($list, $file, $user, $this->collected($list, $read), $this->groupOptions($list));
        } catch (Throwable $e) {
            $saved = PriceListImport::query()->where('price_list_id', $list->id)->where('id', '>', $lastImportId)->orderByDesc('id')->first();
            if ($saved !== null) {
                // karty i ceny zapisane — plik zaimportowany z adnotacją, mapa kart musi powstać jak po udanym imporcie
                $file->forceFill([
                    ...$meta,
                    'status' => PriceListFile::STATUS_IMPORTED,
                    'error' => mb_substr('import zapisany, błąd po zapisie: '.$e->getMessage(), 0, 2000),
                    'price_list_import_id' => $saved->id,
                    'imported_at' => now(),
                ])->save();
                MapPriceListSourcesJob::dispatch((int) $list->id, $describe, null, (int) $user->id);
            } else {
                $file->forceFill([
                    ...$meta,
                    'status' => PriceListFile::STATUS_FAILED,
                    'error' => mb_substr($e->getMessage(), 0, 2000),
                ])->save();
            }

            throw $e;
        }

        $import = $result['price_list_import'] ?? null;
        $file->forceFill([
            ...$meta,
            'status' => PriceListFile::STATUS_IMPORTED,
            'error' => null,
            'price_list_import_id' => $import?->id,
            'imported_at' => now(),
        ])->save();

        MapPriceListSourcesJob::dispatch((int) $list->id, $describe, null, (int) $user->id);

        return [
            'created' => (int) $result['created'],
            'updated' => (int) $result['updated'],
            'skipped' => (int) $result['skipped'],
            'errors' => array_values($result['errors']),
            'price_changes' => array_values($result['price_changes']),
            'map_job' => 'queued',
        ];
    }

    /**
     * Rabaty w grupach asortymentowych (assortment_groups producenta poza „cały asortyment”) — import przyjęcia liczy
     * tylko rabat wspólny i wyzerowałby rabaty grup na kartach.
     *
     * @throws IntakeNotReady
     */
    public function assertNoAssortmentGroups(PriceList $list): void
    {
        $reason = self::assortmentGroupsBlock((string) $list->manufacturer);
        if ($reason !== null) {
            throw new IntakeNotReady($reason);
        }
    }

    /**
     * Cennik z cenami specjalnymi dostawcy (price_lists.has_supplier_special, SECURA 10.10.2026): importer, który nie czyta
     * kolumny ceny normalnej, wpisałby cenę specjalną jako zwykły zakup — import odmawia zapisu (persistImport), więc
     * podgląd i import odmawiają od razu, czytelnie.
     */
    public function assertNoSupplierSpecialPrices(PriceList $list): void
    {
        if ($this->imports->supplierSpecialRefusalForImporter((string) $list->manufacturer) !== null) {
            throw new IntakeNotReady('Cennik ma ceny specjalne dostawcy — importer musi czytać kolumnę ceny normalnej, zgłoś programiście.');
        }
    }

    /**
     * Powód, dla którego cennik producenta nie może iść nowym sposobem: grupy asortymentowe inne niż „cały asortyment”
     * (importer ich nie obsługuje — import wyzerowałby ich rabaty). Statycznie, bo sprawdza to też formularz przy
     * włączaniu nowego sposobu (PriceListIntakeController), zanim cennik ma importer.
     */
    public static function assortmentGroupsBlock(string $manufacturer): ?string
    {
        $hasGroups = AssortmentGroup::query()
            ->where('manufacturer', $manufacturer)
            ->where('name', '!=', AssortmentGroup::GLOBAL_NAME)
            ->exists();

        return $hasGroups ? 'Cennik ma rabaty w grupach asortymentowych — importer ich nie obsługuje, zgłoś programiście.' : null;
    }

    /** Cennik z argumentu polecenia: numer (price_lists.id) albo manufacturer_key (też nazwa producenta). */
    public function findList(string $argument): ?PriceList
    {
        $argument = trim($argument);
        if ($argument === '') {
            return null;
        }
        if (ctype_digit($argument)) {
            $byId = PriceList::query()->find((int) $argument);
            if ($byId !== null) {
                return $byId;
            }
        }

        return PriceList::query()->where('manufacturer_key', $argument)->first()
            ?? PriceList::query()->where('manufacturer_key', PriceList::manufacturerKey($argument))->first();
    }

    /** Plik cennika: wskazany numerem albo najnowszy niezastąpiony (new / imported / failed). */
    public function findFile(PriceList $list, ?string $fileId): ?PriceListFile
    {
        $query = PriceListFile::query()->where('price_list_id', $list->id);
        if ($fileId !== null && trim($fileId) !== '') {
            return $query->whereKey((int) $fileId)->first();
        }

        return $query->where('status', '!=', PriceListFile::STATUS_SUPERSEDED)->orderByDesc('id')->first();
    }

    /** @throws IntakeNotReady */
    public function importerFor(PriceList $list): PriceListImporter
    {
        if (! $list->usesIntake()) {
            throw new IntakeNotReady('Cennik przyjmuje pliki dawnym sposobem — najpierw włącz nowy sposób w ustawieniach cennika.');
        }
        $key = trim((string) $list->importer_key);
        if ($key === '') {
            throw new IntakeNotReady('Cennik nie ma jeszcze importera — przygotuje go programista.');
        }
        if ($this->registry->classFor($key) === null) {
            throw new IntakeNotReady('Importera „'.$key.'” nie ma w tym wdrożeniu.');
        }

        return $this->registry->make($key);
    }

    /** Odczyt pliku cennika importerem (ścieżka z dysku pliku, rabat wspólny cennika w ReadContext). */
    public function read(PriceList $list, PriceListFile $file, PriceListImporter $importer): ReadResult
    {
        if ((int) $file->price_list_id !== (int) $list->id) {
            throw new InvalidArgumentException('Plik #'.$file->id.' należy do innego cennika.');
        }
        // brak pliku na dysku → czytelny wyjątek z nazwą pliku (PriceListFileStore::absolutePath)
        $path = app(PriceListFileStore::class)->absolutePath($file);

        return $importer->read($path, (string) $file->original_name, new ReadContext($list, $this->globalDiscount($list)));
    }

    /** „Upust na cały cennik” — grupa GLOBAL producenta (jak PriceListDiscountService); null = nie ustawiono. */
    public function globalDiscount(PriceList $list): ?float
    {
        $value = AssortmentGroup::query()
            ->where('manufacturer', (string) $list->manufacturer)
            ->where('name', AssortmentGroup::GLOBAL_NAME)
            ->value('discount_percent');

        return $value !== null && is_numeric($value) ? (float) $value : null;
    }

    /**
     * Wynik odczytu w kształcie $collected importu: pozycje (ImportedRow::toPayload), pominięte wiersze z powodem,
     * uwagi importera jako uwagi importu.
     *
     * @return array{products: list<array<string, mixed>>, skipped: int, errors: list<string>, rows_total: int, skipped_details: list<array<string, mixed>>}
     */
    public function collected(PriceList $list, ReadResult $read): array
    {
        $manufacturer = (string) $list->manufacturer;

        return [
            'products' => array_values(array_map(static fn (ImportedRow $row): array => $row->toPayload($manufacturer), $read->rows)),
            'skipped' => count($read->skipped),
            'errors' => array_values(array_map('strval', $read->notes)),
            'rows_total' => $read->rowsTotal,
            'skipped_details' => array_values(array_map(static fn (array $skip): array => [
                'reason' => trim((string) ($skip['ref'] ?? '')) !== '' ? $skip['ref'].': '.$skip['reason'] : (string) $skip['reason'],
                'row' => null,
                'sheet' => null,
                'sku' => $skip['sku'] ?? null,
                'name' => null,
            ], $read->skipped)),
        ];
    }

    /** @return array{default_discount: float}|null */
    public function groupOptions(PriceList $list): ?array
    {
        $discount = $this->globalDiscount($list);

        return $discount !== null ? ['default_discount' => $discount] : null;
    }

    /** @return array<string, mixed> */
    private function buildPreview(PriceList $list, PriceListFile $file, PriceListImporter $importer, int $limit, bool $liveFetch): array
    {
        $read = $this->read($list, $file, $importer);
        $plan = $this->imports->planImport($list, $this->collected($list, $read), $this->groupOptions($list));

        $counts = [
            PriceListImportService::ROW_CREATE => 0,
            PriceListImportService::ROW_UPDATE => 0,
            PriceListImportService::ROW_SKIP => 0,
            PriceListImportService::ROW_BLOCKED => 0,
        ];
        $skipped = array_map(static fn (array $skip): array => [
            'ref' => (string) ($skip['ref'] ?? ''),
            'sku' => $skip['sku'] ?? null,
            'reason' => (string) $skip['reason'],
        ], $read->skipped);
        /** @var array<string, array{card: Product, rows: list<ImportedRow>, action: string}> $cards */
        $cards = [];
        foreach ($plan['rows'] as $row) {
            $counts[$row['action']] = ($counts[$row['action']] ?? 0) + 1;
            $imported = $read->rows[$row['index']] ?? null;
            if ($row['action'] === PriceListImportService::ROW_SKIP) {
                $skipped[] = ['ref' => $imported?->ref ?? '', 'sku' => $row['sku'], 'reason' => (string) $row['reason']];

                continue;
            }
            if (! in_array($row['action'], [PriceListImportService::ROW_CREATE, PriceListImportService::ROW_UPDATE], true)
                || $imported === null || ! $row['card'] instanceof Product) {
                continue;
            }
            $key = $row['product_id'] !== null ? 'id:'.$row['product_id'] : 'new:'.spl_object_id($row['card']);
            if (! isset($cards[$key])) {
                $cards[$key] = ['card' => $this->cardForMap($row['card']), 'rows' => [], 'action' => $row['action']];
            }
            $cards[$key]['rows'][] = $imported;
        }

        $mapped = array_slice($cards, 0, $limit, true);
        $existingIds = [];
        foreach ($mapped as $entry) {
            if ($entry['card']->exists) {
                $existingIds[] = (int) $entry['card']->id;
            }
        }
        $fromB2b = $existingIds !== [] ? app(B2bDescriptionSource::class)->productIds($existingIds) : [];

        $ctx = new DefaultMapContext($list, $liveFetch);
        $sources = ['manufacturer' => 0, 'supplier' => 0, 'shop' => 0, 'unresolved' => 0, 'human_url' => 0, 'b2b_description' => 0, 'not_checked' => 0];
        $unresolved = [];
        $samples = [];
        foreach ($mapped as $entry) {
            $card = $entry['card'];
            // adres wskazany przez człowieka wygrywa z mapą; opisu z B2B zbiorcze opisy nie nadpisują
            if ($card->exists && $card->trustedShopUrl() !== null) {
                $sources['human_url']++;

                continue;
            }
            if ($card->exists && isset($fromB2b[(int) $card->id])) {
                $sources['b2b_description']++;

                continue;
            }
            $decision = $importer->mapSource($card, $entry['rows'], $ctx);
            if ($decision->isPinned()) {
                $kind = (string) $decision->sourceKind;
                $sources[$kind] = ($sources[$kind] ?? 0) + 1;
            } elseif ($decision->unresolvedReason === self::NOT_CHECKED_REASON) {
                $sources['not_checked']++;
            } else {
                $sources['unresolved']++;
                if (count($unresolved) < self::UNRESOLVED_LIST) {
                    $unresolved[] = [
                        'sku' => (string) $card->sku,
                        'name' => (string) $card->name,
                        'reason' => (string) $decision->unresolvedReason,
                        'candidates' => $decision->candidates,
                    ];
                }
            }
            if (count($samples) < self::SAMPLES) {
                $samples[] = [
                    'sku' => (string) $card->sku,
                    'name' => (string) $card->name,
                    'action' => $entry['action'],
                    'url' => $decision->url,
                    'source_kind' => $decision->sourceKind,
                    'match_kind' => $decision->matchKind,
                ];
            }
        }

        $notInPreview = [];
        if (count($cards) > count($mapped)) {
            $notInPreview[] = 'Mapa policzona dla '.count($mapped).' z '.count($cards).' kart — pozostałe przypnie zadanie mapy po imporcie.';
        }
        if (! $liveFetch) {
            $notInPreview[] = 'Strony nie były pobierane — karty, których importer nie rozstrzyga z indeksu stron, mają „'.self::NOT_CHECKED_REASON.'”.';
        }
        $notInPreview[] = 'Ceny specjalne z arkusza liczy dopiero import.';

        return [
            'importer' => ['key' => $importer::key(), 'version' => $importer::version()],
            'rows_total' => $read->rowsTotal,
            'rows' => [
                'create' => $counts[PriceListImportService::ROW_CREATE],
                'update' => $counts[PriceListImportService::ROW_UPDATE],
                'skip' => $counts[PriceListImportService::ROW_SKIP],
                'blocked' => $counts[PriceListImportService::ROW_BLOCKED],
            ],
            'skipped' => array_slice($skipped, 0, 200),
            'skipped_total' => count($skipped),
            'price_changes' => array_slice(array_map(static fn (array $change): array => [
                'sku' => (string) $change['sku'],
                'name' => (string) $change['name'],
                'old' => $change['catalog_old'],
                'new' => $change['catalog_new'],
            ], $plan['price_changes']), 0, 100),
            'price_changes_total' => count($plan['price_changes']),
            'sources' => $sources,
            'unresolved' => $unresolved,
            'unresolved_total' => $sources['unresolved'],
            'samples' => $samples,
            'notes' => array_values(array_unique($plan['notes'])),
            'not_in_preview' => $notInPreview,
        ];
    }

    /**
     * Karta do mapSource w podglądzie: istniejąca — stan po imporcie z planu; nowa — niezapisany Product z polami
     * wiersza, bez ujemnego id planu (importer nie może jej pomylić z kartą z bazy).
     */
    private function cardForMap(Product $card): Product
    {
        if ($card->exists) {
            return $card;
        }
        $fresh = new Product;
        $fresh->setRawAttributes(Arr::except($card->getAttributes(), ['id']));

        return $fresh;
    }
}
