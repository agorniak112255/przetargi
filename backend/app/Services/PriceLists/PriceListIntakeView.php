<?php

declare(strict_types=1);

namespace App\Services\PriceLists;

use App\Jobs\MapPriceListSourcesJob;
use App\Models\AssortmentGroup;
use App\Models\ManufacturerSite;
use App\Models\PriceList;
use App\Models\PriceListFile;
use App\Models\Product;
use App\Models\ProductSourcePin;
use App\Services\Enrichment\ManufacturerDomainResolver;
use App\Services\PriceLists\Importers\PriceListImporterRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * IntakeView i FileView z kontraktu „cenniki z plików jak B2B” (SUPON_AI_Plan_Cenniki_Importer_2026-10-10.md, sekcja 5).
 * many() liczy widoki wielu cenników stałą liczbą zapytań (zakładka „Z pliku” odświeża się w trakcie pobierania opisów).
 */
final class PriceListIntakeView
{
    /** Źródła stron producenta pokazywane w formularzu (bez „discovered” — wykrytych automatem). */
    public const SITE_SOURCES = ['manual', 'config'];

    public function __construct(
        private readonly PriceListImporterRegistry $registry,
        private readonly ManufacturerDomainResolver $manufacturers,
    ) {}

    /** @return array<string, mixed> */
    public function one(PriceList $list): array
    {
        return $this->many([$list])[(int) $list->id];
    }

    /**
     * @param  iterable<PriceList>  $lists
     * @return array<int, array<string, mixed>> price_list_id => IntakeView
     */
    public function many(iterable $lists): array
    {
        $lists = Collection::make($lists)->values();
        if ($lists->isEmpty()) {
            return [];
        }
        $ids = $lists->map(static fn (PriceList $l): int => (int) $l->id)->all();

        $filesByList = PriceListFile::query()
            ->whereIn('price_list_id', $ids)
            ->with('uploader:id,name')
            ->orderByDesc('id')
            ->get()
            ->groupBy('price_list_id');

        $pins = [];
        $pinRows = ProductSourcePin::query()
            ->whereIn('price_list_id', $ids)
            ->groupBy('price_list_id')
            ->selectRaw("price_list_id, COUNT(*) as total, SUM(CASE WHEN url IS NOT NULL AND url <> '' THEN 1 ELSE 0 END) as pinned")
            ->toBase()
            ->get();
        foreach ($pinRows as $row) {
            $pins[(int) $row->price_list_id] = ['total' => (int) $row->total, 'pinned' => (int) $row->pinned];
        }
        $humanUrl = $this->humanUrlProductIds($ids);

        // „Upust na cały cennik” = grupa (cały asortyment) producenta (PriceListDiscountService::apply)
        $discounts = [];
        $manufacturerNames = $lists->map(static fn (PriceList $l): string => (string) $l->manufacturer)->unique()->values()->all();
        foreach (AssortmentGroup::query()
            ->where('name', AssortmentGroup::GLOBAL_NAME)
            ->whereIn('manufacturer', $manufacturerNames)
            ->get(['manufacturer', 'discount_percent']) as $group) {
            $discounts[mb_strtolower((string) $group->manufacturer)] = (float) $group->discount_percent;
        }

        // strony wskazane przez człowieka i z configu — domeny wykryte automatem (discovered) nie są decyzją dla formularza
        $sites = ManufacturerSite::query()
            ->whereIn('source', self::SITE_SOURCES)
            ->orderBy('brand_key')
            ->orderBy('host')
            ->get(['brand_key', 'manufacturer', 'host']);

        $out = [];
        foreach ($lists as $list) {
            $files = $filesByList->get((int) $list->id, Collection::make());
            // intakeStatus() czyta relację, gdy jest wczytana — bez osobnego zapytania na cennik
            $list->setRelation('files', $files);
            /** @var PriceListFile|null $latest */
            $latest = $files->first();
            $listPins = $pins[(int) $list->id] ?? ['total' => 0, 'pinned' => 0];
            $importerKey = trim((string) $list->importer_key);
            $importerClass = $importerKey !== '' ? $this->registry->classFor($importerKey) : null;

            $out[(int) $list->id] = [
                'id' => (int) $list->id,
                'manufacturer' => (string) $list->manufacturer,
                'manufacturer_key' => (string) $list->manufacturer_key,
                'version' => (string) $list->version,
                'source_policy' => $list->source_policy,
                'importer_key' => $importerKey !== '' ? $importerKey : null,
                'importer_label' => $importerClass !== null ? $importerClass::label() : null,
                'importer_notes' => $list->importer_notes,
                'status' => $list->intakeStatus(),
                'manufacturer_hosts' => $this->manufacturerHosts($list, $sites),
                'enrichment_sites' => $list->enrichmentHosts(),
                'enrichment_sites_mode' => $list->enrichmentSitesMode(),
                'suggested_prices' => (bool) $list->suggested_prices,
                'discount_percent' => $discounts[mb_strtolower((string) $list->manufacturer)] ?? null,
                // rabat z formularza zapisuje tylko grupę „cały asortyment” — ceny kart liczy następny import
                'discount_applies_on_next_import' => true,
                'latest_file' => $latest !== null ? $this->file($latest) : null,
                // pinned + unresolved + human_url = total; human_url = bez strony z mapy, ale z adresem od człowieka
                'pins' => [
                    'pinned' => $listPins['pinned'],
                    'unresolved' => $listPins['total'] - $listPins['pinned'] - count($humanUrl[(int) $list->id] ?? []),
                    'human_url' => count($humanUrl[(int) $list->id] ?? []),
                    'total' => $listPins['total'],
                ],
                // przypisywanie stron po imporcie (MapPriceListSourcesJob) — null, gdy nie trwa
                'mapping' => $list->usesIntake() ? $this->mapping((int) $list->id) : null,
            ];
        }

        return $out;
    }

    /**
     * Karty z nierozwiązaną mapą, którym człowiek wskazał adres („Wskaż adres”, Product::trustedShopUrl — adres
     * z łącznika B2B się nie liczy). Takiej karty nie ma już na liście „Karty bez strony”. Filtr adresu w PHP (host
     * łącznika B2B nie da się sprawdzić w SQL); kandydatów zawęża SQL do kart z niepustym shop_source_url.
     *
     * @param  list<int>  $listIds
     * @return array<int, list<int>> price_list_id => product_id
     */
    public function humanUrlProductIds(array $listIds): array
    {
        if ($listIds === []) {
            return [];
        }
        $rows = ProductSourcePin::query()
            ->join('products', 'products.id', '=', 'product_source_pins.product_id')
            ->whereIn('product_source_pins.price_list_id', $listIds)
            ->where(static fn ($q) => $q->whereNull('product_source_pins.url')->orWhere('product_source_pins.url', ''))
            ->whereNotNull('products.shop_source_url')
            ->where('products.shop_source_url', '<>', '')
            ->toBase()
            ->get(['product_source_pins.price_list_id', 'products.id as product_id', 'products.shop_source_url']);

        $out = [];
        foreach ($rows as $row) {
            $probe = (new Product)->forceFill(['shop_source_url' => (string) $row->shop_source_url]);
            if ($probe->trustedShopUrl() !== null) {
                $out[(int) $row->price_list_id][] = (int) $row->product_id;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> FileView */
    public function file(PriceListFile $file): array
    {
        return [
            'id' => (int) $file->id,
            'original_name' => (string) $file->original_name,
            'sha256' => (string) $file->sha256,
            'size' => (int) $file->size,
            'status' => (string) $file->status,
            'error' => $file->error,
            'importer_key' => $file->importer_key,
            'importer_version' => $file->importer_version,
            'imported_at' => $file->imported_at?->toIso8601String(),
            'created_at' => $file->created_at?->toIso8601String(),
            'uploaded_by_name' => $file->uploader?->name,
        ];
    }

    /**
     * Strony producenta cennika: marka producenta (brand_key z nazwy, jak CatalogSearchHostService::assignManufacturer)
     * i marki zapisane formularzem cennika pod tym producentem (manufacturer_sites.manufacturer = producent cennika).
     *
     * @param  Collection<int, ManufacturerSite>  $sites
     * @return array<string, list<string>>
     */
    private function manufacturerHosts(PriceList $list, Collection $sites): array
    {
        $ownKey = $this->manufacturers->brandKey((string) $list->manufacturer);
        $listKey = (string) $list->manufacturer_key;
        $out = [];
        foreach ($sites as $site) {
            $brandKey = (string) $site->brand_key;
            $mine = ($ownKey !== '' && $brandKey === $ownKey)
                || ($listKey !== '' && PriceList::manufacturerKey((string) $site->manufacturer) === $listKey);
            if ($mine) {
                $out[$brandKey][] = (string) $site->host;
            }
        }

        return array_map(static fn (array $hosts): array => array_values(array_unique($hosts)), $out);
    }

    /** @return array{done: int, total: int}|null */
    private function mapping(int $listId): ?array
    {
        $progress = Cache::get(MapPriceListSourcesJob::progressKey($listId));
        if (! is_array($progress) || (int) ($progress['total'] ?? 0) <= 0) {
            return null;
        }

        return ['done' => (int) ($progress['done'] ?? 0), 'total' => (int) $progress['total']];
    }
}
