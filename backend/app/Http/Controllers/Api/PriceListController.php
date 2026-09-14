<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RegisterManufacturerCatalogJob;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Services\B2b\B2bAccountPriceList;
use App\Services\PriceListDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class PriceListController extends Controller
{
    public function __construct(
        private readonly PriceListDeletionService $deletion,
        private readonly B2bAccountPriceList $b2bLists,
    ) {}

    public function index(): JsonResponse
    {
        $lists = PriceList::query()
            ->with('importer:id,name')
            ->latest()
            ->get();
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

        $payload = $lists->map(function (PriceList $list) use ($statusSets, $latestBatchMsg, $owners): array {
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
            $row['enrichment_total'] = count($ids);
            $batch = $latestBatchMsg[$list->id] ?? null;
            $row['enrichment_batch_status'] = $batch?->status;
            $row['enrichment_current_sku'] = $batch?->current_sku;
            $row['enrichment_last_error'] = $batch && $batch->failed > 0
                ? mb_substr((string) ($batch->message ?? ''), 0, 240)
                : null;
            $row['b2b_account'] = isset($owners[$list->id]) ? $this->b2bLists->ownerPayload($owners[$list->id]) : null;

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
        ]);

        if ($data === []) {
            return response()->json(['message' => 'Brak pól do aktualizacji.'], 422);
        }
        if (($blocked = $this->b2bAccountBlock($priceList, 'update')) !== null) {
            return $blocked;
        }

        $oldManufacturer = (string) $priceList->manufacturer;
        $productIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): int => (int) $id,
            $priceList->product_ids ?? []
        ))));

        $productsUpdated = 0;
        DB::transaction(function () use ($priceList, $data, $productIds, &$productsUpdated): void {
            if (array_key_exists('manufacturer', $data)) {
                $priceList->manufacturer = trim($data['manufacturer']);
            }
            if (array_key_exists('version', $data)) {
                $priceList->version = trim($data['version']);
            }
            $priceList->save();

            if (array_key_exists('manufacturer', $data) && $productIds !== []) {
                $productsUpdated = Product::query()
                    ->whereIn('id', $productIds)
                    ->update(['manufacturer' => $priceList->manufacturer]);
            }
        });

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
            'message' => sprintf(
                'Zapisano cennik%s%s.',
                array_key_exists('manufacturer', $data) && $oldManufacturer !== $priceList->manufacturer
                    ? sprintf(' (producent: „%s” → „%s”)', $oldManufacturer, $priceList->manufacturer)
                    : '',
                $productsUpdated > 0
                    ? sprintf(', zaktualizowano producent na %d produktach', $productsUpdated)
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
