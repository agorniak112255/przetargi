<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PriceList;
use App\Models\PriceListFile;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductIdentifier;
use App\Models\ProductSourcePin;
use App\Models\User;
use App\Services\B2b\B2bDescriptionSource;
use App\Services\Catalog\ProductIdentifierStore;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\Sources\MappedSourcePin;
use App\Services\Enrichment\Sources\SourcePin;
use App\Services\Enrichment\Sources\SourcePins;
use App\Services\PriceListCards;
use App\Services\PriceLists\Importers\DefaultMapContext;
use App\Services\PriceLists\Importers\ImportedRow;
use App\Services\PriceLists\Importers\PriceListImporter;
use App\Services\PriceLists\IntakeNotReady;
use App\Services\PriceLists\PriceListIntakeRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Mapa kart cennika z importerem (10.10.2026): dla kart ze slotem ceny z pliku tego cennika importer ustala stronę
 * źródłową (mapSource, z pobieraniem stron) i zapisuje ją w product_source_pins — tylko pin, który się zmienił
 * (pinChanged). Karta bez strony dostaje powód przeglądu source_unmapped, karta z pinem go traci. describe = kolejka
 * opisów (force, bez limitu partii) dla kart z rozwiązanym pinem, bez adresu człowieka, bez opisu albo z opisem nie z tego
 * pinu (needsDescription — stan karty, nie różnica przebiegu); karty z opisem z B2B pomijane (B2bDescriptionSource).
 *
 * Wiersze karty: najnowszy zaimportowany plik czytany ponownie importerem (pełne dane wiersza: kody, EAN, cechy,
 * cena) i dobierany po kodzie pozycji z product_identifiers (source_key file:{cennik}, bez removed_at). Gdy pliku nie
 * da się odczytać (brak na dysku, zmiana formatu) — wiersze minimalne z identyfikatorów (kod, EAN, nazwa karty).
 *
 * Zadanie pracuje porcjami po CHUNK kart w budżecie czasu BUDGET_SECONDS (worker kolejki default ma --timeout=180);
 * po budżecie zleca dalszy ciąg z pozostałymi kartami. price-lists:map liczy to samo synchronicznie (run()).
 */
class MapPriceListSourcesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE = 'default';

    public const CHUNK = 200;

    /** Budżet jednego przebiegu zadania (sekundy) — potem dalszy ciąg w nowym zadaniu. */
    public const BUDGET_SECONDS = 120.0;

    /** Ile zmian pinów zwraca run() do raportu polecenia. */
    private const CHANGES_LIMIT = 500;

    /** Postęp przypisywania stron dla panelu (PriceListIntakeView: „mapping”) — wygasa sam, gdy zadanie padnie. */
    private const PROGRESS_TTL = 900;

    /** Co ile kart zapisać postęp (cache w bazie — nie przy każdej karcie). */
    private const PROGRESS_EVERY = 5;

    public int $tries = 2;

    public int $timeout = 170;

    /**
     * @param  list<int>|null  $productIds  null = wszystkie karty ze slotem pliku tego cennika
     * @param  int|null  $userId  na kogo idzie partia opisów (null: importujący cennik, potem pierwszy administrator)
     * @param  list<int>  $pendingDescribe  karty do opisu z wcześniejszych porcji tego samego przebiegu — opisy zleca
     *                                      dopiero ostatnia porcja, jedną partią
     */
    public function __construct(
        public readonly int $priceListId,
        public readonly bool $describe,
        public readonly ?array $productIds = null,
        public readonly ?int $userId = null,
        public readonly array $pendingDescribe = [],
    ) {
        $this->onQueue(self::QUEUE);
    }

    public static function progressKey(int $priceListId): string
    {
        return 'price-list-intake:mapping:'.$priceListId;
    }

    /**
     * Zlecenie mapy po imporcie: postęp „0 z N” od razu (panel pokazuje go, zanim worker weźmie zadanie) + zadanie.
     */
    public static function start(PriceList $list, bool $describe, ?int $userId): void
    {
        Cache::put(self::progressKey((int) $list->id), [
            'done' => 0,
            'total' => count(app(PriceListCards::class)->fileSlotIds($list)),
        ], self::PROGRESS_TTL);
        self::dispatch((int) $list->id, $describe, null, $userId);
    }

    public function handle(): void
    {
        $key = self::progressKey($this->priceListId);
        $progress = Cache::get($key);
        $total = is_array($progress) ? (int) ($progress['total'] ?? 0) : 0;
        // dalszy ciąg przebiegu: karty wcześniejszych porcji są już policzone
        $offset = $total > 0 && $this->productIds !== null ? max(0, $total - count($this->productIds)) : 0;
        $onCard = $total > 0
            ? static function (int $cards) use ($key, $offset, $total): void {
                if ($cards % self::PROGRESS_EVERY === 0) {
                    Cache::put($key, ['done' => min($total, $offset + $cards), 'total' => $total], self::PROGRESS_TTL);
                }
            }
        : null;

        $result = $this->run(true, self::BUDGET_SECONDS, $onCard);
        if ($result['remaining'] !== []) {
            if ($total > 0) {
                Cache::put($key, ['done' => min($total, $offset + $result['cards']), 'total' => $total], self::PROGRESS_TTL);
            }
            self::dispatch($this->priceListId, $this->describe, $result['remaining'], $this->userId, $result['to_describe']);
        } else {
            Cache::forget($key);
        }
        Log::info('Mapa kart cennika', ['price_list_id' => $this->priceListId] + array_diff_key($result, ['changes' => true, 'remaining' => true, 'to_describe' => true]) + ['remaining' => count($result['remaining'])]);
    }

    /** Zadanie padło na dobre — panel nie może dalej pokazywać „przypisuję strony”. */
    public function failed(?Throwable $e = null): void
    {
        Cache::forget(self::progressKey($this->priceListId));
    }

    /**
     * Mapa kart: $apply=false liczy bez zapisu (price-lists:map bez --apply, w ReadOnlyGuard), $apply=true zapisuje
     * zmienione piny i (z describe) zleca opisy. $budgetSeconds null = wszystkie karty w jednym przebiegu.
     *
     * @return array{
     *     cards: int,
     *     pinned: int,
     *     unresolved: int,
     *     changed: int,
     *     unchanged: int,
     *     written: int,
     *     described: int,
     *     review_marked: int,
     *     review_cleared: int,
     *     remaining: list<int>,
     *     changes: list<array{product_id: int, sku: string, old_url: ?string, new_url: ?string, reason: ?string}>,
     *     to_describe: list<int>
     * }
     */
    public function run(bool $apply, ?float $budgetSeconds = null, ?callable $onCard = null): array
    {
        $result = ['cards' => 0, 'pinned' => 0, 'unresolved' => 0, 'changed' => 0, 'unchanged' => 0, 'written' => 0, 'described' => 0, 'review_marked' => 0, 'review_cleared' => 0, 'remaining' => [], 'changes' => [], 'to_describe' => []];
        $list = PriceList::query()->find($this->priceListId);
        if ($list === null) {
            return $result;
        }
        $runner = app(PriceListIntakeRunner::class);
        try {
            $importer = $runner->importerFor($list);
        } catch (IntakeNotReady $e) {
            Log::warning('Mapa kart cennika: '.$e->getMessage(), ['price_list_id' => $list->id]);

            return $result;
        }

        $ids = app(PriceListCards::class)->fileSlotIds($list);
        if ($this->productIds !== null) {
            $wanted = array_flip(array_map('intval', $this->productIds));
            $ids = array_values(array_filter($ids, static fn (int $id): bool => isset($wanted[$id])));
        }
        if ($ids === []) {
            return $result;
        }

        $rowsByPosition = $this->fileRows($list, $importer, $runner);
        $ctx = new DefaultMapContext($list, true);
        $started = microtime(true);
        /** @var list<int> $toDescribe */
        $toDescribe = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunkIndex => $chunk) {
            $cards = Product::query()->whereIn('id', $chunk)->get()->keyBy('id');
            $pins = ProductSourcePin::query()->whereIn('product_id', $chunk)->get()->keyBy('product_id');
            $identifiers = $this->identifiersByCard($list, $chunk);
            foreach ($chunk as $position => $productId) {
                if ($budgetSeconds !== null && microtime(true) - $started > $budgetSeconds) {
                    $result['remaining'] = array_values(array_merge(
                        array_slice($chunk, $position),
                        ...array_slice(array_chunk($ids, self::CHUNK), $chunkIndex + 1),
                    ));
                    break 2;
                }
                /** @var Product|null $card */
                $card = $cards->get($productId);
                if ($card === null) {
                    continue;
                }
                $result['cards']++;
                $decision = $importer->mapSource($card, $this->rowsForCard($card, $identifiers[$productId] ?? [], $rowsByPosition), $ctx);
                $decision->isPinned() ? $result['pinned']++ : $result['unresolved']++;
                $attributes = [
                    ...$decision->toPinAttributes(),
                    'price_list_id' => (int) $list->id,
                    'importer_key' => $importer::key(),
                    'importer_version' => $importer::version(),
                ];
                /** @var ProductSourcePin|null $pin */
                $pin = $pins->get($productId);
                if ($pin !== null && ! $this->pinChanged($pin, $attributes)) {
                    $result['unchanged']++;
                } else {
                    $result['changed']++;
                    if (count($result['changes']) < self::CHANGES_LIMIT) {
                        $result['changes'][] = [
                            'product_id' => $productId,
                            'sku' => (string) $card->sku,
                            'old_url' => $pin?->url,
                            'new_url' => $decision->url,
                            'reason' => $decision->unresolvedReason,
                        ];
                    }
                    if ($apply) {
                        ProductSourcePin::query()->updateOrCreate(['product_id' => $productId], [...$attributes, 'checked_at' => now()]);
                        $result['written']++;
                    }
                }
                if ($apply) {
                    // stan karty po zapisie pinu tak, jak zobaczy go pobieranie opisu (SourcePins): adres człowieka,
                    // tabela części, odrzucony w przeglądzie adres mapy, brak przypięcia
                    $resolved = app(SourcePins::class)->resolve($card);
                    $this->syncReviewReason($card, $resolved['reason'] !== null, $result);
                    // znacznik trwały (stan karty wobec pinu), nie różnica z tego przebiegu — ponowienie zadania po
                    // przerwaniu w połowie nie gubi kart
                    if ($this->needsDescription($card, $resolved['pin'])) {
                        $toDescribe[] = $productId;
                    }
                }
                if ($onCard !== null) {
                    $onCard($result['cards']);
                }
            }
        }

        // porcje jednego przebiegu (budżet czasu) przekazują sobie karty do opisu — jedna partia na końcu, nie po partii
        // na porcję
        $toDescribe = array_values(array_unique([...array_map('intval', $this->pendingDescribe), ...$toDescribe]));
        if ($result['remaining'] !== []) {
            $result['to_describe'] = $toDescribe;
        } elseif ($apply && $this->describe && $toDescribe !== []) {
            $result['described'] = $this->enqueueDescriptions($list, $toDescribe);
        }

        return $result;
    }

    /**
     * Zmiana pinu, która wymaga zapisu: adres, zdjęcie, tytuł strony, rodzaj źródła i dopasowania, klucz dopasowania,
     * dane cennika (spec), powód braku, wersja i klucz importera, cennik. Dowody, kandydaci i data sprawdzenia same nie
     * zapisują pinu.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function pinChanged(ProductSourcePin $pin, array $attributes): bool
    {
        foreach (['url', 'image_url', 'page_title', 'source_kind', 'match_kind', 'match_key', 'unresolved_reason', 'importer_key'] as $field) {
            if ((string) ($pin->getAttribute($field) ?? '') !== (string) ($attributes[$field] ?? '')) {
                return true;
            }
        }
        if (array_values((array) ($pin->spec ?? [])) !== array_values((array) ($attributes['spec'] ?? []))) {
            return true;
        }

        return (int) $pin->importer_version !== (int) $attributes['importer_version']
            || (int) $pin->price_list_id !== (int) $attributes['price_list_id'];
    }

    /**
     * Karta wymaga opisu z mapy: pobieranie opisu czyta stronę z mapy importera (SourcePins → MappedSourcePin; adres
     * człowieka, tabela części, adres odrzucony w przeglądzie i brak przypięcia — nie), a karta nie ma opisu albo jej
     * opis nie pochodzi z tego pinu (enrichment_payload.source_map: adres, wersja importera, dane cennika — spec
     * porównywany, gdy zapis opisu go zapamiętał). Opis wybrany przez człowieka (zatwierdzony, przywrócony, z adresu
     * podanego ręcznie) zostaje.
     */
    private function needsDescription(Product $card, ?SourcePin $pin): bool
    {
        if (! $pin instanceof MappedSourcePin) {
            return false;
        }
        if (! $card->hasDescriptionText()) {
            return true;
        }
        if (app(DescriptionVersionStore::class)->currentIsHumanChoice($card)) {
            return false;
        }
        $payload = is_array($card->enrichment_payload) ? ($card->enrichment_payload['source_map'] ?? null) : null;
        if (! is_array($payload)) {
            return true;
        }
        if (trim((string) ($payload['url'] ?? '')) !== $pin->url()
            || (int) ($payload['importer_version'] ?? 0) !== $pin->importerVersion) {
            return true;
        }

        return array_key_exists('spec', $payload)
            && array_values((array) ($payload['spec'] ?? [])) !== $pin->specLines();
    }

    /**
     * Powód przeglądu z mapy: karta zablokowana bez strony (SourcePins::resolve — brak przypięcia, adres mapy odrzucony
     * w przeglądzie) → source_unmapped, gdy nie ma innego powodu; karta ze stroną (pin, tabela części, adres człowieka)
     * traci source_unmapped. Opisu nie rusza.
     *
     * @param  array<string, mixed>  $result
     */
    private function syncReviewReason(Product $card, bool $unmapped, array &$result): void
    {
        if ($unmapped && $card->review_reason === null) {
            $card->forceFill(['review_reason' => Product::REVIEW_SOURCE_UNMAPPED, 'review_since' => now()])->saveQuietly();
            $result['review_marked']++;
        } elseif (! $unmapped && $card->review_reason === Product::REVIEW_SOURCE_UNMAPPED) {
            $card->forceFill(['review_reason' => null, 'review_since' => null])->saveQuietly();
            $result['review_cleared']++;
        }
    }

    /**
     * Wiersze najnowszego zaimportowanego pliku po kodzie pozycji (jak position_key: przycięty, najwyżej 64 znaki).
     * Pusta tablica, gdy pliku nie ma albo nie da się go odczytać — wtedy wiersze minimalne z identyfikatorów.
     *
     * @return array<string, list<ImportedRow>>
     */
    private function fileRows(PriceList $list, PriceListImporter $importer, PriceListIntakeRunner $runner): array
    {
        $file = PriceListFile::query()
            ->where('price_list_id', $list->id)
            ->where('status', PriceListFile::STATUS_IMPORTED)
            ->orderByDesc('imported_at')
            ->orderByDesc('id')
            ->first();
        if ($file === null) {
            return [];
        }
        try {
            $read = $runner->read($list, $file, $importer);
        } catch (Throwable $e) {
            Log::warning('Mapa kart cennika: plik nieczytelny, wiersze z identyfikatorów', ['price_list_id' => $list->id, 'file_id' => $file->id, 'error' => $e->getMessage()]);

            return [];
        }
        $out = [];
        foreach ($read->rows as $row) {
            $out[mb_substr(trim($row->sku), 0, 64)][] = $row;
        }

        return $out;
    }

    /**
     * Identyfikatory wierszy tego cennika przy kartach porcji (bez zniknięte z pliku).
     *
     * @param  list<int>  $productIds
     * @return array<int, Collection<int, ProductIdentifier>>
     */
    private function identifiersByCard(PriceList $list, array $productIds): array
    {
        return ProductIdentifier::query()
            ->where('source_key', ProductIdentifierStore::fileKey((int) $list->id))
            ->whereNull('removed_at')
            ->whereIn('product_id', $productIds)
            ->orderBy('id')
            ->get(['id', 'product_id', 'position_key', 'type', 'value'])
            ->groupBy('product_id')
            ->all();
    }

    /**
     * Wiersze pliku karty: po kodach pozycji jej identyfikatorów. Bez wiersza w pliku — wiersz minimalny z pozycji
     * (kod, EAN z identyfikatora, nazwa i cena karty); karta bez identyfikatorów — z kodu karty.
     *
     * @param  Collection<int, ProductIdentifier>|array<int, ProductIdentifier>  $identifiers
     * @param  array<string, list<ImportedRow>>  $rowsByPosition
     * @return list<ImportedRow>
     */
    private function rowsForCard(Product $card, Collection|array $identifiers, array $rowsByPosition): array
    {
        $positions = [];
        foreach ($identifiers as $identifier) {
            $position = (string) $identifier->position_key;
            $positions[$position] ??= null;
            if ($identifier->type === ProductIdentifier::TYPE_EAN) {
                $positions[$position] ??= (string) $identifier->value;
            }
        }
        if ($positions === []) {
            $positions[(string) $card->sku] = $card->ean !== null ? (string) $card->ean : null;
        }
        $rows = [];
        foreach ($positions as $position => $ean) {
            $fromFile = $rowsByPosition[$position] ?? [];
            if ($fromFile !== []) {
                array_push($rows, ...$fromFile);

                continue;
            }
            $rows[] = new ImportedRow(
                sku: (string) $position,
                name: (string) $card->name,
                catalogPriceNet: (float) $card->catalog_price_net,
                ref: 'identyfikatory karty (bez wiersza w pliku)',
                ean: $ean,
            );
        }

        return $rows;
    }

    /**
     * Kolejka opisów (force, partia cennika) — bez kart z opisem z B2B (zbiorczo AI ich nie nadpisuje).
     *
     * @param  list<int>  $ids
     */
    private function enqueueDescriptions(PriceList $list, array $ids): int
    {
        $ids = array_values(array_unique($ids));
        $fromB2b = app(B2bDescriptionSource::class)->productIds($ids);
        $ids = array_values(array_filter($ids, static fn (int $id): bool => ! isset($fromB2b[$id])));
        if ($ids === []) {
            return 0;
        }
        // świeży odczyt: karta już w kolejce albo w trakcie opisu (np. poprzedni przebieg zadania) nie dostaje drugiej
        // pozycji partii (jak queue-enrichment --force)
        $ids = Product::query()
            ->whereIn('id', $ids)
            ->whereNotIn('enrichment_status', [Product::ENRICHMENT_QUEUED, Product::ENRICHMENT_RUNNING])
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        if ($ids === []) {
            return 0;
        }
        $user = $this->describeUser($list);
        if ($user === null) {
            Log::warning('Mapa kart cennika: brak użytkownika dla partii opisów', ['price_list_id' => $list->id]);

            return 0;
        }
        try {
            $queued = app(ProductEnrichmentService::class)->enqueueProductIds(
                $ids,
                $user,
                true,
                ProductEnrichmentBatch::SCOPE_PRICE_LIST,
                (int) $list->id,
                // wszystkie karty wymagające opisu — bez limitu partii z Ustawień AI
                limit: PHP_INT_MAX,
            );
        } catch (RuntimeException $e) {
            Log::info('Mapa kart cennika: opisy nie zlecone — '.$e->getMessage(), ['price_list_id' => $list->id]);

            return 0;
        }

        return count($queued['product_ids']);
    }

    private function describeUser(PriceList $list): ?User
    {
        foreach ([$this->userId, $list->imported_by] as $id) {
            if ($id !== null && ($user = User::query()->find((int) $id)) !== null) {
                return $user;
            }
        }
        try {
            $admin = User::role('admin')->orderBy('id')->first();
        } catch (Throwable) {
            $admin = null;
        }

        return $admin instanceof User ? $admin : null;
    }
}
