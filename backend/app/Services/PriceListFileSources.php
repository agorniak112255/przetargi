<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CatalogPage;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductSourcePrice;
use App\Services\B2b\B2bAccountPriceList;
use App\Services\Enrichment\CatalogSearchHostService;
use App\Services\Enrichment\PriceListDescriptionSources;
use App\Services\Enrichment\PriceListSourceSettings;
use Carbon\CarbonImmutable;

/**
 * Cenniki → „Z pliku”: cenniki z kartami z ceną z pliku (sloty product_source_prices „file”), ich strony z opisami
 * i skąd karty mają dziś opis — żeby przy każdym cenniku było widać, czy wpisane strony coś dają.
 */
final class PriceListFileSources
{
    public function __construct(
        private readonly PriceListCards $cards,
        private readonly PriceListDescriptionSources $sources,
        private readonly B2bAccountPriceList $b2bLists,
        private readonly CatalogSearchHostService $searchHosts,
    ) {}

    /**
     * @return list<array<string, mixed>> kształt z kontraktu GET /price-lists/files
     */
    public function lists(): array
    {
        $listIds = ProductSourcePrice::query()
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->whereNotNull('price_list_id')
            ->distinct()
            ->pluck('price_list_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        if ($listIds === []) {
            return [];
        }
        $lists = PriceList::query()
            ->whereIn('id', $listIds)
            // bez dużych JSON-ów ostatniego importu (product_ids, updated_products…) — lista odświeża się w trakcie
            // pobierania; original_filename potrzebuje B2bAccountPriceList::owners
            ->get(['id', 'manufacturer', 'version', 'original_filename', 'enrichment_sites', 'enrichment_sites_mode', 'enrichment_sites_updated_at'])
            ->sort(static fn (PriceList $a, PriceList $b): int => [mb_strtolower((string) $a->manufacturer), (int) $a->id]
                <=> [mb_strtolower((string) $b->manufacturer), (int) $b->id])
            ->values();

        // tylko karty ze slotem pliku — do nich stosują się strony cennika (product_ids bywa listą kart konta B2B)
        $cardsByList = $this->cards->fileSlotIdsByList($lists->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $owners = $this->b2bLists->owners($lists);
        $batches = $this->openBatches($lists->pluck('id')->map(static fn ($id): int => (int) $id)->all());

        $allHosts = [];
        foreach ($lists as $list) {
            foreach ($list->enrichmentHosts() as $host) {
                $allHosts[$host] = true;
            }
        }
        $allHosts = array_keys($allHosts);
        $listed = $allHosts === [] ? [] : $this->searchHosts->listedHosts($allHosts);
        $indexed = $this->indexedPages($allHosts);

        $out = [];
        foreach ($lists as $list) {
            $out[] = $this->row(
                $list,
                $cardsByList[(int) $list->id] ?? [],
                isset($owners[(int) $list->id]),
                $batches[(int) $list->id] ?? null,
                $listed,
                $indexed,
            );
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, true>  $listed
     * @param  array<string, int>  $indexed
     * @return array<string, mixed>
     */
    private function row(
        PriceList $list,
        array $ids,
        bool $hasB2bAccount,
        ?ProductEnrichmentBatch $batch,
        array $listed,
        array $indexed,
    ): array {
        $hosts = $list->enrichmentHosts();
        $settings = PriceListSourceSettings::fromList($list);
        $cards = $this->sources->cards($ids, $settings);
        $updatedAt = $list->enrichment_sites_updated_at !== null
            ? CarbonImmutable::instance($list->enrichment_sites_updated_at)
            : null;

        $currentSha1 = $list->enrichmentHostsSha1();
        $sources = array_fill_keys(PriceListDescriptionSources::SOURCES, 0);
        $byPosition = [];
        $stale = 0;
        $statuses = [
            Product::ENRICHMENT_QUEUED => 0,
            Product::ENRICHMENT_RUNNING => 0,
            Product::ENRICHMENT_FAILED => 0,
            Product::ENRICHMENT_MANUAL => 0,
        ];
        foreach ($cards as $card) {
            $sources[$card['source']]++;
            if (isset($statuses[$card['status']])) {
                $statuses[$card['status']]++;
            }
            if ($card['source'] === PriceListDescriptionSources::SOURCE_NONE) {
                continue;
            }
            if ($card['host_position'] !== null) {
                $byPosition[$card['host_position']] = ($byPosition[$card['host_position']] ?? 0) + 1;
            }
            // Opis sprzed zmiany stron; opis z B2B i od producenta strony cennika nie zmieniają (karta producenta
            // ma pierwszeństwo, opisu ze sklepu dostawcy zbiorcze AI nie nadpisuje). Opis z zapisanym odciskiem stron
            // porównujemy z bieżącym (przebieg trwający w chwili zmiany stron pisze według starych), bez odcisku —
            // datą opisu.
            if (in_array($card['source'], [PriceListDescriptionSources::SOURCE_B2B, PriceListDescriptionSources::SOURCE_MANUFACTURER], true)) {
                continue;
            }
            if ($card['hosts_sha1'] !== null
                ? $card['hosts_sha1'] !== $currentSha1
                : $updatedAt !== null && $card['enriched_at'] !== null && $card['enriched_at'] < $updatedAt->getTimestamp()) {
                $stale++;
            }
        }

        $hostRows = [];
        foreach ($hosts as $position => $host) {
            $hostRows[] = [
                'host' => $host,
                'position' => $position,
                'on_search_sites' => isset($listed[$host]),
                'indexed_pages' => $indexed[$host] ?? 0,
                'described_cards' => $byPosition[$position] ?? 0,
            ];
        }

        return [
            'id' => (int) $list->id,
            'manufacturer' => (string) $list->manufacturer,
            'version' => $list->version,
            'enrichment_sites' => $hosts,
            'enrichment_sites_mode' => $list->enrichmentSitesMode(),
            'enrichment_sites_updated_at' => $updatedAt?->toIso8601String(),
            'has_b2b_account' => $hasB2bAccount,
            // istniejące karty — product_ids ostatniego importu bywa starszy niż usunięcia kart
            'cards' => count($cards),
            'described' => count($cards) - $sources[PriceListDescriptionSources::SOURCE_NONE],
            'sources' => $sources,
            'stale' => $stale,
            'queued' => $statuses[Product::ENRICHMENT_QUEUED],
            'running' => $statuses[Product::ENRICHMENT_RUNNING],
            'failed' => $statuses[Product::ENRICHMENT_FAILED],
            'manual' => $statuses[Product::ENRICHMENT_MANUAL],
            'batch' => $batch === null ? null : [
                'id' => (int) $batch->id,
                'status' => (string) $batch->status,
                'total' => (int) $batch->total,
                'done' => (int) $batch->done,
                'failed' => (int) $batch->failed,
            ],
            'hosts' => $hostRows,
        ];
    }

    /**
     * Partia cennika w toku (najnowsza w kolejce albo w trakcie) — postęp pokazuje wiersz cennika.
     *
     * @param  list<int>  $listIds
     * @return array<int, ProductEnrichmentBatch>
     */
    private function openBatches(array $listIds): array
    {
        $out = [];
        $rows = ProductEnrichmentBatch::query()
            ->where('scope', ProductEnrichmentBatch::SCOPE_PRICE_LIST)
            ->whereIn('scope_id', $listIds)
            ->whereIn('status', [ProductEnrichmentBatch::STATUS_QUEUED, ProductEnrichmentBatch::STATUS_RUNNING])
            ->orderByDesc('id')
            ->get(['id', 'scope_id', 'status', 'total', 'done', 'failed']);
        foreach ($rows as $batch) {
            $out[(int) $batch->scope_id] ??= $batch;
        }

        return $out;
    }

    /**
     * Strony w indeksie lokalnym (catalog_pages) dla hosta i www.hosta — jedno zapytanie dla hostów wszystkich cenników.
     *
     * @param  list<string>  $hosts
     * @return array<string, int> host bez www => liczba stron
     */
    private function indexedPages(array $hosts): array
    {
        if ($hosts === []) {
            return [];
        }
        $aliases = [];
        foreach ($hosts as $host) {
            $aliases[] = $host;
            $aliases[] = 'www.'.$host;
        }
        $out = [];
        foreach (array_chunk($aliases, 1000) as $chunk) {
            $rows = CatalogPage::query()
                ->whereIn('host', $chunk)
                ->groupBy('host')
                ->selectRaw('host, COUNT(*) as pages')
                ->toBase()
                ->get();
            foreach ($rows as $row) {
                $host = (string) preg_replace('/^www\./', '', mb_strtolower((string) $row->host));
                $out[$host] = ($out[$host] ?? 0) + (int) $row->pages;
            }
        }

        return $out;
    }
}
