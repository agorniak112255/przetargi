<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RegisterManufacturerCatalogJob;
use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductSourcePrice;
use App\Services\B2b\B2bAccountPriceList;
use App\Services\B2b\B2bDescriptionSource;
use App\Services\PriceListDeletionService;
use App\Services\PriceListDiscountService;
use App\Services\Pricing\ProductEffectivePrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class PriceListController extends Controller
{
    public function __construct(
        private readonly PriceListDeletionService $deletion,
        private readonly B2bAccountPriceList $b2bLists,
        private readonly B2bDescriptionSource $b2bDescriptions,
        private readonly PriceListDiscountService $discounts,
        private readonly ProductEffectivePrice $effectivePrices,
    ) {}

    public function index(): JsonResponse
    {
        $lists = PriceList::query()
            ->with('importer:id,name')
            ->withCount('imports')
            ->latest()
            ->get();
        // czym przyszła ostatnia porcja danych i czy producent ma oba źródła — lista pokazuje to w jednym wierszu
        $sources = PriceListImport::query()
            ->whereIn('price_list_id', $lists->pluck('id'))
            ->get(['price_list_id', 'source'])
            ->groupBy('price_list_id')
            ->map(static fn ($rows): array => $rows->pluck('source')->unique()->sort()->values()->all());
        $owners = $this->b2bLists->owners($lists);

        $allIds = [];
        foreach ($lists as $list) {
            foreach ($list->product_ids ?? [] as $id) {
                $allIds[] = (int) $id;
            }
        }
        $allIds = array_values(array_unique(array_filter($allIds)));

        $statusSets = [
            Product::ENRICHMENT_DONE => [],
            Product::ENRICHMENT_FAILED => [],
            Product::ENRICHMENT_QUEUED => [],
            Product::ENRICHMENT_RUNNING => [],
        ];
        if ($allIds !== []) {
            $statusRows = Product::query()
                ->whereIn('id', $allIds)
                ->whereIn('enrichment_status', array_keys($statusSets))
                ->get(['id', 'enrichment_status']);
            foreach ($statusRows as $product) {
                $status = (string) $product->enrichment_status;
                if (isset($statusSets[$status])) {
                    $statusSets[$status][(int) $product->id] = true;
                }
            }
        }

        // karty z opisem z cennika B2B nie idą do zbiorczego AI (ProductEnrichmentService::enqueueProductIds),
        // więc w pokryciu cennika liczą się jako gotowe — inaczej „zostało” nigdy nie zeszłoby do zera
        $fromB2b = [];
        $notDone = array_values(array_filter(
            $allIds,
            static fn (int $id): bool => ! isset($statusSets[Product::ENRICHMENT_DONE][$id]),
        ));
        if ($notDone !== []) {
            $fromB2b = $this->b2bDescriptions->productIds($notDone);
        }
        // karta z błędem AI, ale z opisem ze sklepu dostawcy jest gotowa i ponowienie jej nie weźmie
        $failedFromB2b = array_intersect_key($fromB2b, $statusSets[Product::ENRICHMENT_FAILED]);

        $latestBatchMsg = [];
        $batchRows = ProductEnrichmentBatch::query()
            ->where('scope', ProductEnrichmentBatch::SCOPE_PRICE_LIST)
            ->whereIn('scope_id', $lists->pluck('id')->all())
            ->orderByDesc('id')
            ->get(['id', 'scope_id', 'status', 'message', 'failed', 'done', 'total', 'current_sku']);
        foreach ($batchRows as $batch) {
            $sid = (int) $batch->scope_id;
            if (! isset($latestBatchMsg[$sid])) {
                $latestBatchMsg[$sid] = $batch;

                continue;
            }
            $open = [
                ProductEnrichmentBatch::STATUS_QUEUED,
                ProductEnrichmentBatch::STATUS_RUNNING,
            ];
            $current = $latestBatchMsg[$sid];
            if (! in_array((string) $current->status, $open, true)
                && in_array((string) $batch->status, $open, true)) {
                $latestBatchMsg[$sid] = $batch;
            }
        }

        $payload = $lists->map(function (PriceList $list) use (
            $statusSets,
            $fromB2b,
            $failedFromB2b,
            $latestBatchMsg,
            $owners,
            $sources,
        ): array {
            $ids = array_map('intval', $list->product_ids ?? []);
            $countStatus = static function (array $set) use ($ids): int {
                $n = 0;
                foreach ($ids as $id) {
                    if (isset($set[$id])) {
                        $n++;
                    }
                }

                return $n;
            };
            $row = $list->toArray();
            $row['enrichment_done'] = $countStatus($statusSets[Product::ENRICHMENT_DONE]);
            $row['enrichment_failed'] = $countStatus($statusSets[Product::ENRICHMENT_FAILED]);
            $row['enrichment_queued'] = $countStatus($statusSets[Product::ENRICHMENT_QUEUED]);
            $row['enrichment_running'] = $countStatus($statusSets[Product::ENRICHMENT_RUNNING]);
            $row['enrichment_from_b2b'] = $countStatus($fromB2b);
            $row['enrichment_failed_from_b2b'] = $countStatus($failedFromB2b);
            $row['enrichment_total'] = count($ids);
            $batch = $latestBatchMsg[$list->id] ?? null;
            $row['enrichment_batch_status'] = $batch?->status;
            $row['enrichment_current_sku'] = $batch?->current_sku;
            $row['enrichment_last_error'] = $batch && $batch->failed > 0
                ? mb_substr((string) ($batch->message ?? ''), 0, 240)
                : null;
            $row['b2b_account'] = isset($owners[$list->id]) ? $this->b2bLists->ownerPayload($owners[$list->id]) : null;
            $row['sources'] = $sources[$list->id] ?? [];

            return $row;
        })->values()->all();

        return response()->json($payload);
    }

    public function show(PriceList $priceList): JsonResponse
    {
        $owner = $this->b2bLists->owners([$priceList])[$priceList->id] ?? null;

        return response()->json([
            ...$priceList->load('importer:id,name')->toArray(),
            'b2b_account' => $owner !== null ? $this->b2bLists->ownerPayload($owner) : null,
            // historia aktualizacji tego producenta — wpis jest jeden, przebiegów wiele
            'imports' => $priceList->imports()->with('importer:id,name')->get()->map(
                static fn (PriceListImport $import): array => [
                    'id' => $import->id,
                    'source' => $import->source,
                    'version' => $import->version,
                    'original_filename' => $import->original_filename,
                    'created_at' => $import->created_at?->toIso8601String(),
                    'importer' => $import->importer?->name,
                    'rows_total' => $import->rows_total,
                    'products_created' => $import->products_created,
                    'products_updated' => $import->products_updated,
                    'prices_changed' => $import->prices_changed,
                    'rows_skipped' => $import->rows_skipped,
                    'products' => count($import->product_ids ?? []),
                ]
            )->values()->all(),
        ]);
    }

    /**
     * Cofa jedną aktualizację cennika. Łagodna operacja: znikają tylko karty, których nie przyniósł
     * żaden inny przebieg — w odróżnieniu od usunięcia całego cennika producenta.
     */
    public function destroyImport(Request $request, PriceList $priceList, PriceListImport $import): JsonResponse
    {
        if ((int) $import->price_list_id !== (int) $priceList->id) {
            return response()->json(['message' => 'Ta aktualizacja nie należy do tego cennika.'], 404);
        }

        try {
            $result = $this->deletion->undoImport($import, $request->user());
        } catch (Throwable $e) {
            return response()->json(['message' => 'Nie udało się cofnąć aktualizacji: '.$e->getMessage()], 422);
        }

        return response()->json([
            'message' => sprintf(
                'Cofnięto aktualizację %s. Kart usuniętych: %d%s.',
                $result['version'] !== '' ? $result['version'] : '(bez wersji)',
                $result['products_deleted'],
                $result['products_kept_shared'] > 0
                    ? ', zachowanych (są w innych aktualizacjach): '.$result['products_kept_shared']
                    : ''
            ),
            ...$result,
        ]);
    }

    /**
     * Wpis konta B2B: nazwę, wersję i produkty ustawia pobieranie z konta, a usunięcie wpisu skasowałoby katalog
     * dostawcy — dopóki konto istnieje, wpis jest tylko do odczytu.
     */
    private function b2bAccountBlock(PriceList $priceList, string $action): ?JsonResponse
    {
        $owner = $this->b2bLists->owners([$priceList])[$priceList->id] ?? null;
        if ($owner === null) {
            return null;
        }
        $label = $this->b2bLists->ownerPayload($owner)['connector_label'] ?? (string) $priceList->manufacturer;
        $account = $label.' · '.$owner->username;

        return response()->json([
            'message' => $action === 'delete'
                ? 'Nie można usunąć cennika konta B2B ('.$account.') — najpierw usuń konto w zakładce Cenniki → B2B.'
                : 'Nie można edytować cennika konta B2B ('.$account.') — nazwę i wersję ustawia pobieranie z konta. Najpierw usuń konto w zakładce Cenniki → B2B.',
        ], 422);
    }

    /**
     * Edycja metadanych cennika — zmiana producenta/wersji propaguje się na produkty z product_ids.
     */
    public function update(Request $request, PriceList $priceList): JsonResponse
    {
        $data = $request->validate([
            'manufacturer' => ['sometimes', 'string', 'min:1', 'max:255'],
            'version' => ['sometimes', 'string', 'min:1', 'max:120'],
            // ceny sugerowane bez cen zakupu (np. „ATG-sugerowany”) — decyzja użytkownika 23.09.2026
            'suggested_prices' => ['sometimes', 'boolean'],
        ]);

        if ($data === []) {
            return response()->json(['message' => 'Brak pól do aktualizacji.'], 422);
        }
        // Nazwę wolno poprawić także przy cenniku z kontem B2B: po zwinięciu Cenników do jednego wpisu
        // na producenta ten sam wiersz obsługuje import z pliku, więc blokada zabierałaby edycję czegoś,
        // co z kontem nie ma nic wspólnego. Przebieg B2B nie nadpisuje już nazwy istniejącego wpisu,
        // a odnajduje go po wskaźniku konta — zmiana zostaje. Usuwanie zostaje zablokowane.
        $oldManufacturer = (string) $priceList->manufacturer;
        $productIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): int => (int) $id,
            $priceList->product_ids ?? []
        ))));

        $productsUpdated = 0;
        $suggestedChanged = array_key_exists('suggested_prices', $data)
            && (bool) $data['suggested_prices'] !== (bool) $priceList->suggested_prices;
        DB::transaction(function () use ($priceList, $data, $productIds, &$productsUpdated): void {
            if (array_key_exists('manufacturer', $data)) {
                $priceList->manufacturer = trim($data['manufacturer']);
                // klucz idzie za nazwą — po nim kolejny import odnajduje cennik tego producenta
                $priceList->manufacturer_key = PriceList::manufacturerKey($priceList->manufacturer);
            }
            if (array_key_exists('version', $data)) {
                $priceList->version = trim($data['version']);
            }
            if (array_key_exists('suggested_prices', $data)) {
                $priceList->suggested_prices = (bool) $data['suggested_prices'];
            }
            $priceList->save();

            if (array_key_exists('manufacturer', $data) && $productIds !== []) {
                $productsUpdated = Product::query()
                    ->whereIn('id', $productIds)
                    ->update(['manufacturer' => $priceList->manufacturer]);
            }
        });

        // Znacznik cennika sugerowanego zmienia kolejność źródeł ceny — przeliczamy karty z ceną z tego pliku.
        // Poza transakcją: refresh() blokuje każdą kartę osobno.
        $pricesChanged = 0;
        if ($suggestedChanged) {
            ProductSourcePrice::query()
                ->where('source_key', ProductSourcePrice::SOURCE_FILE)
                ->where('price_list_id', $priceList->id)
                ->select('product_id')
                ->orderBy('product_id')
                ->chunk(500, function ($slots) use (&$pricesChanged): void {
                    foreach (Product::query()->whereIn('id', $slots->pluck('product_id'))->get() as $product) {
                        if ($this->effectivePrices->refresh($product) !== []) {
                            $pricesChanged++;
                        }
                    }
                });
        }

        $priceList->load('importer:id,name');

        if (array_key_exists('manufacturer', $data)
            && $oldManufacturer !== $priceList->manufacturer) {
            RegisterManufacturerCatalogJob::dispatch(
                (string) $priceList->manufacturer,
                $productIds[0] ?? 0
            );
        }

        return response()->json([
            'price_list' => $priceList,
            'products_updated' => $productsUpdated,
            'prices_changed' => $pricesChanged,
            'message' => sprintf(
                'Zapisano cennik%s%s%s.',
                array_key_exists('manufacturer', $data) && $oldManufacturer !== $priceList->manufacturer
                    ? sprintf(' (producent: „%s” → „%s”)', $oldManufacturer, $priceList->manufacturer)
                    : '',
                $productsUpdated > 0
                    ? sprintf(', zaktualizowano producent na %d produktach', $productsUpdated)
                    : '',
                $suggestedChanged
                    ? sprintf(
                        '%s cena obowiązująca zmieniła się na %d kartach',
                        $priceList->suggested_prices ? ' (cennik sugerowany — bez pierwszeństwa przed kontem B2B);' : ' (cennik zakupu producenta);',
                        $pricesChanged,
                    )
                    : ''
            ),
        ]);
    }

    /**
     * Rabaty cen z tego cennika (per grupa asortymentowa i wspólny dla reszty kart) — do edycji po imporcie.
     */
    public function discounts(PriceList $priceList): JsonResponse
    {
        return response()->json($this->discounts->summary($priceList));
    }

    public function updateDiscounts(Request $request, PriceList $priceList): JsonResponse
    {
        $data = $request->validate([
            'groups' => ['sometimes', 'array'],
            'groups.*.id' => ['required', 'integer'],
            'groups.*.discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'ungrouped_discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $groupDiscounts = [];
        foreach ($data['groups'] ?? [] as $row) {
            $groupDiscounts[(int) $row['id']] = round((float) $row['discount_percent'], 2);
        }
        $ungrouped = isset($data['ungrouped_discount']) ? round((float) $data['ungrouped_discount'], 2) : null;
        if ($groupDiscounts === [] && $ungrouped === null) {
            return response()->json(['message' => 'Brak rabatu do zapisania.'], 422);
        }

        $result = $this->discounts->apply($priceList, $groupDiscounts, $ungrouped);

        return response()->json([
            ...$result,
            'discounts' => $this->discounts->summary($priceList),
            'message' => sprintf(
                'Zapisano rabat cennika %s: nowa cena zakupu na %d kartach%s.',
                $priceList->manufacturer,
                $result['products_changed'],
                $result['b2b_priced'] > 0
                    ? sprintf(' (w tym %d z ceną z konta B2B, które ma pierwszeństwo — ich cena obowiązująca zostaje z tego konta)', $result['b2b_priced'])
                    : ''
            ),
        ]);
    }

    public function destroy(Request $request, PriceList $priceList): JsonResponse
    {
        if (($blocked = $this->b2bAccountBlock($priceList, 'delete')) !== null) {
            return $blocked;
        }

        try {
            $result = $this->deletion->delete($priceList, $request->user());
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Nie udało się usunąć cennika: '.$e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => sprintf(
                'Usunięto cennik %s / %s. Produktów usuniętych: %d%s.',
                $result['manufacturer'],
                $result['version'],
                $result['products_deleted'],
                $result['products_kept_shared'] > 0
                    ? ', zachowanych (w innych cennikach): '.$result['products_kept_shared']
                    : ''
            ),
            ...$result,
        ]);
    }
}
