<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductAccessory;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Models\ProductPriceHistory;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\ProductSubstitute;
use App\Models\TenderItem;
use App\Services\B2b\B2bCatalogSync;
use App\Services\Catalog\CardOwnership;
use App\Services\Catalog\CardRedirectStore;
use App\Services\Pricing\ProductEffectivePrice;
use App\Services\Vector\ProductEmbeddingIndexer;
use App\Support\ProductSizeVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

final class ProductSizeMergeService
{
    public function __construct(
        private readonly ProductSizeVariant $sizes,
        private readonly ProductEffectivePrice $effectivePrices,
        private readonly CardRedirectStore $redirects,
        private readonly CardOwnership $ownership,
        private readonly ProductEmbeddingIndexer $embeddings,
    ) {}

    /**
     * Koszyk ceny do łączenia rozmiarów: z ceny z pliku (slot „file”) — rozmiary z jednego cennika mają tę samą cenę
     * z pliku, a cena obowiązująca bywa ceną B2B, która rozdzieliłaby rozmiary. Karta bez slotu pliku (sprzed slotów,
     * tylko B2B, ręczna) — z ceny karty, jak dotąd.
     */
    public function filePriceBucket(Product $product, ?ProductSourcePrice $fileSlot): string
    {
        if ($fileSlot === null) {
            return $this->sizes->priceBucket($product->catalog_price_net, $product->purchase_price);
        }

        return $this->sizes->priceBucket(
            $fileSlot->catalog_price_net ?? $fileSlot->purchase_price,
            $fileSlot->purchase_price ?? $fileSlot->catalog_price_net,
        );
    }

    /**
     * @return array{
     *     groups: int,
     *     deleted: int,
     *     dry_run: bool,
     *     examples: list<array{keep: string, drop: list<string>}>,
     *     errors: list<string>
     * }
     */
    public function merge(?string $manufacturer = null, bool $dryRun = false): array
    {
        // Karta z wersjami B2B (znak w formatach × podłożach) to już jedna karta z cenami w wersjach — cena karty 0
        // skleiłaby różne znaki w jedną grupę, a usunięcie karty skasowałoby jej wersje i historię cen.
        $query = Product::query()->withCount('images')->whereDoesntHave('variants')->orderBy('id');
        if ($manufacturer !== null && trim($manufacturer) !== '') {
            $query->where('manufacturer', trim($manufacturer));
        }

        $knownStems = [];
        $rows = [];
        foreach ((clone $query)->cursor() as $product) {
            $stem = $this->sizes->skuTailStem((string) $product->sku);
            if ($stem !== null) {
                $knownStems[mb_strtolower($stem)] = $stem;
            }
            $rows[] = [
                'id' => (int) $product->id,
                'manufacturer' => (string) $product->manufacturer,
                'name' => (string) $product->name,
                'sku' => (string) $product->sku,
            ];
        }
        // Litera rozmiaru w środku kodu — rozpoznawana z rodzeństwa, więc przed grupowaniem.
        $midGroups = $this->sizes->midCodeSizeVariantGroups($rows);

        /** @var array<int, ProductSourcePrice> $fileSlots */
        $fileSlots = ProductSourcePrice::query()
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->whereIn('product_id', (clone $query)->reorder()->select('products.id'))
            ->get()
            ->keyBy('product_id')
            ->all();

        /** @var array<string, list<Product>> $groups */
        $groups = [];
        // Karty modelu z wcześniejszego łączenia: nazwa i SKU bez rozmiaru, więc poza grupami. Kod ze scalonej listy
        // (merged_size_skus) → karta; kod na dwóch listach to niejednoznaczny dowód — odpada.
        /** @var array<int, Product> $modelCards */
        $modelCards = [];
        /** @var array<string, list<int>> $modelBySku */
        $modelBySku = [];
        foreach ($query->cursor() as $product) {
            $key = $this->mergeGroupKey($product, $knownStems, $midGroups, $fileSlots[(int) $product->id] ?? null);
            if ($key === null) {
                $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
                foreach (is_array($payload['merged_size_skus'] ?? null) ? $payload['merged_size_skus'] : [] as $sku) {
                    $modelCards[(int) $product->id] = $product;
                    $modelBySku[$this->modelSkuKey($product, (string) $sku)][] = (int) $product->id;
                }

                continue;
            }
            $groups[$key][] = $product;
        }
        $modelBySku = array_filter($modelBySku, static fn (array $ids): bool => count(array_unique($ids)) === 1);

        $mergedGroups = 0;
        $deleted = 0;
        $examples = [];
        $errors = [];
        foreach ($groups as $key => $items) {
            $model = null;
            if (str_starts_with((string) $key, 'name:')) {
                $modelIds = [];
                foreach ($items as $product) {
                    $ids = $modelBySku[$this->modelSkuKey($product, (string) $product->sku)] ?? [];
                    if ($ids !== []) {
                        $modelIds[$ids[0]] = true;
                    }
                }
                if (count($modelIds) > 1) {
                    $errors[] = $items[0]->sku.': kody grupy scalono wcześniej do kilku kart (#'
                        .implode(', #', array_keys($modelIds)).') — grupa pominięta';

                    continue;
                }
                $candidate = $modelIds !== [] ? $modelCards[(int) array_key_first($modelIds)] : null;
                if ($candidate !== null
                    && $this->sizes->namesCompatibleForMerge([(string) $candidate->name, (string) $items[0]->name])) {
                    $model = $candidate;
                    $items = [$model, ...$items];
                }
            }
            if (count($items) < 2) {
                continue;
            }
            if (str_starts_with((string) $key, 'stem:') && ! $this->stemNamesAllowMerge($items, $knownStems)) {
                continue;
            }
            // karta modelu zostaje: to samo id, jej powiązania innych kont, pozycje przetargów, nazwa i SKU
            $winner = $model ?? $this->preferredKeeper($items);
            $losers = array_values(array_filter(
                $items,
                static fn (Product $p): bool => (int) $p->id !== (int) $winner->id
            ));
            if ($losers === []) {
                continue;
            }
            $mergedGroups++;
            $deleted += count($losers);
            if (count($examples) < 20) {
                $examples[] = [
                    'keep' => (string) $winner->sku,
                    'drop' => array_map(static fn (Product $p): string => (string) $p->sku, $losers),
                ];
            }
            if (! $dryRun) {
                try {
                    $this->absorb(
                        $winner,
                        $losers,
                        str_starts_with((string) $key, 'mid:') ? $this->midSizesBySku($items, $midGroups) : null,
                        $model !== null,
                    );
                } catch (Throwable $e) {
                    $mergedGroups--;
                    $deleted -= count($losers);
                    if (count($examples) > 0) {
                        array_pop($examples);
                    }
                    $errors[] = $winner->sku.': '.$e->getMessage();
                }
            }
        }

        return [
            'groups' => $mergedGroups,
            'deleted' => $deleted,
            'dry_run' => $dryRun,
            'examples' => $examples,
            'errors' => $errors,
        ];
    }

    /**
     * Duplikat tego samego wyrobu (np. karta dystrybutora założona obok karty producenta): powiązania B2B, sloty cen,
     * tabelki, media, historia, identyfikatory i odwołania (przetargi, zamienniki, akcesoria innych kart, Presta,
     * cenniki) przechodzą na $keep, $drop znika. Nazwa, SKU i lista rozmiarów $keep zostają; kod duplikatu trafia do
     * merged_duplicate_skus (nie do listy rozmiarów, z której korzysta łączenie rozmiarów). Warunki (ten sam producent,
     * brak wersji itp.) sprawdza wywołujący. Wektor $drop znika z Qdrant po commit (deleteVectorsAfterCommit).
     */
    public function mergeDuplicate(Product $keep, Product $drop): void
    {
        $this->absorb($keep, [$drop], null, true, 'merged_duplicate_skus');
    }

    /**
     * Łączenie rozmiarów z decyzji człowieka (ekran „Łączenie kart”, zakładka „Łączenie rozmiarów”, plan łączenia kart,
     * krok 6): karty rozmiarów $drops wchodzą w kartę modelu $keep. Przykład z produkcji: 3M ma kartę na rozmiar
     * (6100 S #40819, 6200 M #40815, 6300 L #40814) — zostaje #40819 z nazwą „6X00 Półmaska 3M 6000” i listą rozmiarów.
     *
     * Inaczej niż absorb (łączenie automatyczne): tożsamość $keep (id, SKU, packaging, opis, producent) zostaje, nazwa
     * i lista rozmiarów pochodzą z decyzji człowieka, a przy tym samym źródle wygrywa zawsze $keep (slot ceny, tabelka
     * sklepu właściciela, zdjęcie główne) — to jego pozycja jest wiodąca w mapie połączeń (card_redirects.is_anchor)
     * i to z niej synchronizacja dalej odświeża cenę, opis i zdjęcia. Historia cen łączonych kart znika (jest w kopii
     * zapasowej wywołującego): wiersze trzech rozmiarów na jednej karcie wyglądałyby jak skoki ceny jednego wyrobu.
     *
     * Wołać w transakcji wywołującego PO CardRedirectStore::recordSizeMerge (powiązania i identyfikatory są wtedy
     * jeszcze na kartach źródłowych). Warunki (ta sama marka, brak wersji, przetargów itp.) sprawdza wywołujący.
     *
     * @param  list<Product>  $drops  karty łączone (bez $keep, co najmniej jedna)
     * @return array{image_ids_keep: list<int>, image_ids_drops: list<int>} zdjęcia $keep sprzed łączenia (główne
     *                                                                      pierwsze) i przeniesione z $drops — do orderSizeMergeImages
     */
    public function mergeSizeCards(Product $keep, array $drops, string $name, string $variantSummary): array
    {
        $drops = array_values(array_filter($drops, static fn (Product $p): bool => (int) $p->id !== (int) $keep->id));
        if ($drops === []) {
            throw new InvalidArgumentException('Łączenie rozmiarów wymaga co najmniej jednej karty poza kartą, która zostaje.');
        }
        $name = mb_substr(trim($name), 0, 1000);
        if ($name === '') {
            throw new InvalidArgumentException('Nazwa karty modelu nie może być pusta.');
        }
        $keepId = (int) $keep->id;
        $dropIds = array_map(static fn (Product $p): int => (int) $p->id, $drops);
        $map = array_fill_keys($dropIds, $keepId);

        return DB::transaction(function () use ($keep, $drops, $name, $variantSummary, $keepId, $dropIds, $map): array {
            // właściciele przed przeniesieniem powiązań — potem karty łączone nie mają już żadnego
            $ownerAccounts = [];
            foreach ([$keep, ...$drops] as $card) {
                foreach ($this->ownership->ownerSourceKeys($card) as $key) {
                    if (str_starts_with($key, 'b2b:')) {
                        $ownerAccounts[(int) substr($key, 4)] = true;
                    }
                }
            }

            // 1) odwołania
            $this->remapTenderItems($map);
            $this->remapSubstitutes($keepId, $dropIds);
            $this->remapAccessories($keepId, $dropIds);
            $this->remapPresta($keepId, $dropIds);
            $this->remapPriceLists($map);

            // 2) zdjęcia: główne i kolejność $keep zostają, przeniesione na koniec (od 1000)
            $keepImageIds = $this->imageIds([$keepId]);
            $dropImageIds = $this->imageIds($dropIds);
            $this->moveMedia($keep, $dropIds);
            $moved = ProductImage::query()
                ->where('product_id', $keepId)
                ->whereIn('id', $dropImageIds === [] ? [0] : $dropImageIds)
                ->get()
                ->keyBy('id');
            $movedIds = [];
            foreach ($dropImageIds as $id) {
                $image = $moved->get($id);
                if ($image === null) {
                    continue; // duplikat (ta sama suma) — usunięty w moveMedia
                }
                $position = 1000 + count($movedIds);
                if ((bool) $image->is_primary || (int) $image->sort_order !== $position) {
                    $image->forceFill(['is_primary' => false, 'sort_order' => $position])->save();
                }
                $movedIds[] = $id;
            }

            // 3) historia cen: tylko $keep (łączonych — w kopii zapasowej wywołującego)
            if (Schema::hasTable('product_price_history')) {
                ProductPriceHistory::query()->whereIn('product_id', $dropIds)->delete();
            }

            // 4–6) sloty, powiązania, tabelki
            $this->moveSourcePricesKeeperFirst($keepId, $dropIds);
            $this->moveB2bLinks($keepId, $dropIds);
            $this->moveShopCardsKeeperFirst($keepId, $dropIds, array_keys($ownerAccounts));

            // 7) odrzucone zdjęcia, identyfikatory, mapa połączeń
            $this->moveImageRejections($keepId, $dropIds);
            ProductIdentifier::query()->whereIn('product_id', $dropIds)->update(['product_id' => $keepId]);
            $this->redirects->repoint($dropIds, $keepId);

            // 8) karta modelu: nazwa i lista rozmiarów z decyzji; SKU, packaging, opis i producent bez zmian
            $payload = is_array($keep->enrichment_payload) ? $keep->enrichment_payload : [];
            $mergedSkus = is_array($payload['merged_size_skus'] ?? null) ? $payload['merged_size_skus'] : [];
            $stock = (int) $keep->stock;
            $category = trim((string) $keep->category);
            $sizes = [['product_id' => $keepId, 'sku' => (string) $keep->sku]];
            foreach ($drops as $drop) {
                // łączona karta modelu z wcześniejszego łączenia — jej scalone kody też zostają na liście
                $dropPayload = is_array($drop->enrichment_payload) ? $drop->enrichment_payload : [];
                array_push($mergedSkus, ...(is_array($dropPayload['merged_size_skus'] ?? null) ? array_values($dropPayload['merged_size_skus']) : []));
                $mergedSkus[] = (string) $drop->sku;
                $stock += (int) $drop->stock;
                if ($category === '' && trim((string) $drop->category) !== '') {
                    $category = trim((string) $drop->category);
                }
                $sizes[] = ['product_id' => (int) $drop->id, 'sku' => (string) $drop->sku];
            }
            $payload['merged_size_skus'] = array_values(array_unique(array_filter(array_map('strval', $mergedSkus))));
            $payload['size_merge'] = ['at' => now()->toIso8601String(), 'sizes' => $sizes];
            $summary = trim($variantSummary);
            $keep->forceFill([
                'name' => $name,
                'variant_summary' => $summary === '' ? null : mb_substr($summary, 0, B2bCatalogSync::VARIANT_SUMMARY_LIMIT),
                'stock' => $stock,
                'category' => $category === '' ? $keep->category : $category,
                'enrichment_payload' => $payload,
            ]);
            // zwykły save(): hak modelu przelicza indeks tekstowy (nazwa i lista rozmiarów to kolumny wyszukiwania)
            $keep->save();

            // 9) karty łączone znikają (ich wiersze są już przeniesione albo w kopii zapasowej), wektory po commit
            Product::query()->whereIn('id', $dropIds)->delete();
            $this->deleteVectorsAfterCommit($dropIds);
            B2bCatalogSync::refreshShopFieldsSummary($keep);
            $this->effectivePrices->refresh($keep);
            DB::afterCommit(static function () use ($keepId): void {
                try {
                    ReindexProductEmbeddingJob::dispatch($keepId, true);
                } catch (Throwable) {
                    // kolejka embeddingów nie blokuje łączenia
                }
            });

            return ['image_ids_keep' => $keepImageIds, 'image_ids_drops' => $movedIds];
        });
    }

    /**
     * Kolejność zdjęć karty modelu po łączeniu rozmiarów i dołączeniu karty dystrybutora: zdjęcia $keep (jak były,
     * pierwsze główne), potem pozostałe (np. przeniesione z karty dystrybutora), na końcu zdjęcia kart łączonych od
     * 1000 — ProductImage::resequence z kontem producenta (3M) stawia jego zdjęcia przed dystrybutorem po sort_order,
     * więc zdjęcie główne dalej pochodzi z pozycji wiodącej. Zapis tylko wierszy, które się zmieniają.
     *
     * @param  list<int>  $keepImageIds  image_ids_keep z mergeSizeCards
     * @param  list<int>  $dropImageIds  image_ids_drops z mergeSizeCards
     */
    public function orderSizeMergeImages(Product $keep, array $keepImageIds, array $dropImageIds): void
    {
        $images = ProductImage::query()
            ->where('product_id', $keep->id)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->keyBy('id');
        $head = [];
        foreach ($keepImageIds as $id) {
            if ($images->has($id)) {
                $head[] = $images->get($id);
                $images->forget($id);
            }
        }
        $tail = [];
        foreach ($dropImageIds as $id) {
            if ($images->has($id)) {
                $tail[] = $images->get($id);
                $images->forget($id);
            }
        }
        $ordered = [];
        foreach ([...$head, ...$images->values()->all()] as $position => $image) {
            $ordered[] = [$image, $position];
        }
        foreach ($tail as $i => $image) {
            $ordered[] = [$image, 1000 + $i];
        }

        foreach ($ordered as $index => [$image, $position]) {
            $primary = $index === 0;
            if ((int) $image->sort_order !== $position || (bool) $image->is_primary !== $primary) {
                $image->forceFill(['sort_order' => $position, 'is_primary' => $primary])->save();
            }
        }
    }

    /**
     * Zdjęcia kart w kolejności: karty jak na liście, w karcie główne pierwsze, potem sort_order i id.
     *
     * @param  list<int>  $productIds
     * @return list<int>
     */
    private function imageIds(array $productIds): array
    {
        if (! Schema::hasTable('product_images') || $productIds === []) {
            return [];
        }
        $rows = ProductImage::query()
            ->whereIn('product_id', $productIds)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'product_id']);
        $ids = [];
        foreach ($productIds as $productId) {
            foreach ($rows as $row) {
                if ((int) $row->product_id === $productId) {
                    $ids[] = (int) $row->id;
                }
            }
        }

        return $ids;
    }

    /** Klucz kodu w mapie kart modelu — z producentem, bo merge(null) obejmuje cały katalog. */
    private function modelSkuKey(Product $product, string $sku): string
    {
        return mb_strtolower(trim((string) $product->manufacturer)).'|'.mb_strtolower(trim($sku));
    }

    /**
     * @param  array<string, string>  $knownStems
     * @param  array<int, array{key: string, size: string}>  $midGroups
     */
    private function mergeGroupKey(
        Product $product,
        array $knownStems,
        array $midGroups,
        ?ProductSourcePrice $fileSlot,
    ): ?string {
        $price = $this->filePriceBucket($product, $fileSlot);
        $stem = $this->sizes->resolveMergeStem((string) $product->sku, $knownStems);
        if ($stem !== null) {
            return 'stem:'.mb_strtolower((string) $product->manufacturer).'|'.mb_strtolower($stem).'|'.$price;
        }

        $key = $this->sizes->groupKey(
            (string) $product->manufacturer,
            (string) $product->name,
            (string) $product->sku,
            $product->packaging !== null ? (string) $product->packaging : null,
        );
        if ($key !== null) {
            return $key.'|'.$price;
        }

        // Ostatnia szansa: rozmiar literą w środku kodu (ścieżki wyżej mają pierwszeństwo).
        $mid = $midGroups[(int) $product->id] ?? null;

        return $mid !== null ? $mid['key'].'|'.$price : null;
    }

    /**
     * Litery rozmiaru grupy „mid” po SKU — trafiają na kartę zwycięzcy jako lista rozmiarów.
     *
     * @param  list<Product>  $items
     * @param  array<int, array{key: string, size: string}>  $midGroups
     * @return array<string, string>|null null = grupa spoza ścieżki „litera w środku kodu”
     */
    private function midSizesBySku(array $items, array $midGroups): ?array
    {
        $sizes = [];
        foreach ($items as $product) {
            $mid = $midGroups[(int) $product->id] ?? null;
            if ($mid === null) {
                return null;
            }
            $sizes[(string) $product->sku] = $mid['size'];
        }

        return $sizes === [] ? null : $sizes;
    }

    /**
     * @param  list<Product>  $items
     * @param  array<string, string>  $knownStems
     */
    private function stemNamesAllowMerge(array $items, array $knownStems): bool
    {
        foreach ($items as $product) {
            if (isset($knownStems[mb_strtolower((string) $product->sku)])) {
                return true;
            }
        }
        $names = array_map(static fn (Product $p): string => (string) $p->name, $items);

        return $this->sizes->namesCompatibleForMerge($names);
    }

    /**
     * Karta, która zostaje przy łączeniu rozmiarów: rdzeń SKU innej karty, zdjęcie i opis, gotowy opis; remis —
     * pierwsza w kolejności podanej listy. Także podpowiedź „Zostanie karta” propozycji łączenia rozmiarów
     * (CardMatchFinder) — karty potrzebują sku, images_count, description, name i enrichment_status.
     *
     * @param  list<Product>  $variants
     */
    public function preferredKeeper(array $variants): Product
    {
        $knownStems = [];
        foreach ($variants as $product) {
            $stem = $this->sizes->skuTailStem((string) $product->sku);
            if ($stem !== null) {
                $knownStems[mb_strtolower($stem)] = $stem;
            }
        }

        $best = $variants[0];
        $bestScore = -1;
        foreach ($variants as $product) {
            $hasImage = ((int) ($product->images_count ?? 0)) > 0;
            $hasDesc = $product->hasUsableDescription();
            $score = 0;
            if (isset($knownStems[mb_strtolower((string) $product->sku)])) {
                $score += 80;
            }
            if ($hasImage && $hasDesc) {
                $score += 200;
            }
            if ($hasImage) {
                $score += 100;
            }
            if ($hasDesc) {
                $score += 50;
            }
            if ($product->enrichment_status === Product::ENRICHMENT_DONE) {
                $score += 20;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $product;
            }
        }

        return $best;
    }

    /**
     * @param  list<Product>  $losers
     * @param  array<string, string>|null  $midSizes  SKU => litera rozmiaru z kodu (tylko ścieżka „mid”)
     * @param  bool  $keepIdentity  karta modelu z wcześniejszego łączenia — nazwa, SKU i lista rozmiarów zostają
     *                              (stripSizeFromName nie jest idempotentne, a packaging mogło wypełnić wzbogacanie)
     * @param  string  $mergedKey  lista kodów scalonych kart w enrichment_payload (rozmiary albo duplikaty)
     */
    private function absorb(
        Product $winner,
        array $losers,
        ?array $midSizes = null,
        bool $keepIdentity = false,
        string $mergedKey = 'merged_size_skus',
    ): void {
        $loserIds = array_map(static fn (Product $p): int => (int) $p->id, $losers);
        $map = [];
        foreach ($loserIds as $id) {
            $map[$id] = (int) $winner->id;
        }

        DB::transaction(function () use ($winner, $losers, $loserIds, $map, $midSizes, $keepIdentity, $mergedKey): void {
            $this->remapTenderItems($map);
            $this->remapSubstitutes((int) $winner->id, $loserIds);
            $this->remapAccessories((int) $winner->id, $loserIds);
            $this->moveMedia($winner, $loserIds);
            $this->remapPriceHistory((int) $winner->id, $loserIds);
            $this->remapPresta((int) $winner->id, $loserIds);
            $this->remapPriceLists($map);

            $core = $this->sizes->skuCore((string) $winner->sku, (string) $winner->name);
            $stripped = $this->sizes->stripSizeFromName((string) $winner->name);
            $payload = is_array($winner->enrichment_payload) ? $winner->enrichment_payload : [];
            $mergedSkus = is_array($payload[$mergedKey] ?? null) ? $payload[$mergedKey] : [];
            foreach ($losers as $loser) {
                $mergedSkus[] = (string) $loser->sku;
            }
            $payload[$mergedKey] = array_values(array_unique(array_filter($mergedSkus)));

            // Rozmiar odczytany z kodu nie może zniknąć: lista na kartę, przypisanie SKU → rozmiar do payloadu.
            $sizeLabel = null;
            if ($midSizes !== null && $midSizes !== []) {
                $sizeLabel = $this->sizes->midCodeSizeLabel(array_values($midSizes));
                $knownSizes = is_array($payload['merged_size_variants'] ?? null) ? $payload['merged_size_variants'] : [];
                $payload['merged_size_variants'] = [...$knownSizes, ...$midSizes];
            }

            $stock = (int) $winner->stock;
            foreach ($losers as $loser) {
                $stock += (int) $loser->stock;
            }

            $updates = [
                'stock' => $stock,
                'enrichment_payload' => $payload,
            ];
            if (! $keepIdentity) {
                $updates['packaging'] = $sizeLabel;
            }
            if (! $keepIdentity && $stripped !== '' && $stripped !== (string) $winner->name) {
                $updates['name'] = $stripped;
            }
            $newSku = null;
            if (! $keepIdentity && $core !== null && $core !== (string) $winner->sku) {
                $taken = Product::query()
                    ->where('sku', $core)
                    ->where('id', '!=', $winner->id)
                    ->whereNotIn('id', $loserIds)
                    ->exists();
                if (! $taken) {
                    $newSku = $core;
                }
            }
            // przed usunięciem scalanych kart — ich sloty, powiązania B2B, tabelki ze sklepu i odrzucone zdjęcia
            // zniknęłyby kaskadą, a następna synchronizacja założyłaby dla kodu dostawcy osobną kartę
            $this->moveSourcePrices((int) $winner->id, $loserIds);
            $this->moveB2bLinks((int) $winner->id, $loserIds);
            // mapa połączeń: decyzje wskazujące scalane karty idą za ich kodami (usunięcie karty wyzerowałoby wskazanie)
            $this->redirects->repoint($loserIds, (int) $winner->id);
            $this->moveShopCards((int) $winner->id, $loserIds);
            $this->moveImageRejections((int) $winner->id, $loserIds);
            // identyfikatory ze źródeł cen (EAN, kody scalanych rozmiarów) — UNIQUE bez product_id, więc bez konfliktów
            ProductIdentifier::query()->whereIn('product_id', $loserIds)->update(['product_id' => $winner->id]);
            $winner->update($updates);
            Product::query()->whereIn('id', $loserIds)->delete();
            $this->deleteVectorsAfterCommit($loserIds);
            if ($newSku !== null) {
                $winner->update(['sku' => $newSku]);
            }
            B2bCatalogSync::refreshShopFieldsSummary($winner);
            $this->effectivePrices->refresh($winner);
        });

        try {
            ReindexProductEmbeddingJob::dispatch((int) $winner->id, true);
        } catch (Throwable) {
            // kolejka embeddingów nie blokuje scalenia
        }
    }

    /**
     * Wektory usuniętych kart w Qdrant — po commit, bo scalenie bywa częścią większej transakcji („Połącz”
     * i „Połącz rozmiary” na ekranie „Łączenie kart”): wycofana transakcja zostawia karty, więc i ich wektory.
     * Wektor bez karty nie trafia do wyników (wyszukiwanie pomija numery bez wiersza w bazie), ale zajmuje miejsce
     * w puli wektorowej i w fuzji rang, a duplikat tego samego wyrobu stoi w niej tuż przy karcie, która zostaje —
     * i wypycha z puli inną kartę. Błąd Qdrant tylko w logu (ProductEmbeddingIndexer::delete), scalenie zostaje.
     *
     * @param  list<int>  $productIds
     */
    private function deleteVectorsAfterCommit(array $productIds): void
    {
        DB::afterCommit(function () use ($productIds): void {
            foreach ($productIds as $id) {
                try {
                    $this->embeddings->delete($id);
                } catch (Throwable) {
                    // sprzątanie wektora nie blokuje scalenia
                }
            }
        });
    }

    /**
     * @param  array<int, int>  $map
     */
    private function remapTenderItems(array $map): void
    {
        if (! Schema::hasTable('tender_items') || $map === []) {
            return;
        }
        foreach ($map as $from => $to) {
            TenderItem::query()->where('main_product_id', $from)->update(['main_product_id' => $to]);
            // produkt dodatkowy pozycji (np. filtr do półmaski) — usunięcie karty wyzerowałoby go po cichu (nullOnDelete)
            TenderItem::query()->where('companion_product_id', $from)->update(['companion_product_id' => $to]);
        }
        // produkt dodatkowy równy głównemu po scaleniu (półmaska i jej duplikat jako „drugi”) to ta sama karta — ekran
        // przetargu nie dopuszcza takiej pary, więc dodatkowy znika zamiast wskazywać produkt główny
        TenderItem::query()
            ->whereIn('main_product_id', array_values(array_unique(array_map('intval', $map))))
            ->whereColumn('companion_product_id', 'main_product_id')
            ->update(['companion_product_id' => null]);
    }

    /**
     * @param  list<int>  $loserIds
     */
    private function remapSubstitutes(int $winnerId, array $loserIds): void
    {
        if (! Schema::hasTable('product_substitutes') || $loserIds === []) {
            return;
        }
        ProductSubstitute::query()
            ->whereIn('main_product_id', $loserIds)
            ->update(['main_product_id' => $winnerId]);
        ProductSubstitute::query()
            ->whereIn('substitute_product_id', $loserIds)
            ->update(['substitute_product_id' => $winnerId]);
        ProductSubstitute::query()
            ->whereColumn('main_product_id', 'substitute_product_id')
            ->delete();

        $seen = [];
        foreach (ProductSubstitute::query()
            ->where('main_product_id', $winnerId)
            ->orWhere('substitute_product_id', $winnerId)
            ->orderBy('id')
            ->get() as $row) {
            $pair = $row->main_product_id.'-'.$row->substitute_product_id;
            if (isset($seen[$pair])) {
                $row->delete();

                continue;
            }
            $seen[$pair] = true;
        }
    }

    /**
     * Akcesoria innych kart wskazujące scalane karty (related_product_id) przechodzą na kartę, która zostaje: scalana
     * karta to ten sam wyrób (klucz „Połącz”, rozmiar karty modelu), a usunięcie karty wyzerowałoby wskazanie po cichu
     * (nullOnDelete). link_key opisuje dowód ze źródła (numer Presty, EAN, kod ze strony) i zostaje — poza ręcznym
     * „m:{karta}” (ProductKitService::attach), który idzie za kartą. Znikają: akcesorium samej siebie (karta, która
     * zostaje, wskazywała scaloną — upsert i attach takich nie zapisują) i ręczne powtórzenie (karta ma już ręczne
     * akcesorium wskazujące kartę, która zostaje). Numer Presty akcesorium (presta_related_id) zostaje — to produkt,
     * który sklep ma już podpięty: eksport tylko dopisuje akcesoria (ensureAccessories), więc nowy numer dałby w sklepie
     * drugi odnośnik do tego samego wyrobu, a karta bez dopasowania do Presty zostałaby przy eksporcie założona albo
     * nadpisałaby produkt sklepu znaleziony po jej kodzie. Własne akcesoria scalanych kart (product_id) nie
     * przechodzą — „Połącz” i products:merge-duplicate odmawiają przy nich.
     *
     * @param  list<int>  $loserIds
     */
    private function remapAccessories(int $winnerId, array $loserIds): void
    {
        if (! Schema::hasTable('product_accessories') || $loserIds === []) {
            return;
        }
        // wiersze scalanych kart i tak znikają z nimi (kaskada)
        $rows = ProductAccessory::query()
            ->whereIn('related_product_id', $loserIds)
            ->whereNotIn('product_id', $loserIds)
            ->orderBy('id')
            ->get();
        foreach ($rows as $row) {
            if ((int) $row->product_id === $winnerId) {
                $row->delete();

                continue;
            }
            $updates = ['related_product_id' => $winnerId];
            if ($row->source === ProductAccessory::SOURCE_MANUAL && $row->link_key === 'm:'.$row->related_product_id) {
                $key = 'm:'.$winnerId;
                if (ProductAccessory::query()->where('product_id', $row->product_id)->where('link_key', $key)->exists()) {
                    $row->delete();

                    continue;
                }
                $updates['link_key'] = $key;
            }
            $row->forceFill($updates)->save();
        }
    }

    /**
     * @param  list<int>  $loserIds
     */
    private function moveMedia(Product $winner, array $loserIds): void
    {
        if ($loserIds === []) {
            return;
        }
        if (Schema::hasTable('product_images')) {
            $this->reassignUniqueChecksums(
                ProductImage::query()->whereIn('product_id', $loserIds)->get(),
                $winner->images()->pluck('checksum')->filter()->map(static fn ($c): string => (string) $c)->all(),
                (int) $winner->id,
            );
        }
        if (Schema::hasTable('product_documents')) {
            $this->reassignUniqueChecksums(
                ProductDocument::query()->whereIn('product_id', $loserIds)->get(),
                $winner->documents()->pluck('checksum')->filter()->map(static fn ($c): string => (string) $c)->all(),
                (int) $winner->id,
            );
        }
    }

    /**
     * Ten sam PDF/zdjęcie na wielu rozmiarach — przenosimy raz, resztę kasujemy.
     *
     * @param  Collection<int, ProductImage|ProductDocument>  $rows
     * @param  list<string>  $takenChecksums
     */
    private function reassignUniqueChecksums($rows, array $takenChecksums, int $winnerId): void
    {
        $seen = array_fill_keys($takenChecksums, true);
        foreach ($rows as $row) {
            $sum = trim((string) ($row->checksum ?? ''));
            if ($sum !== '' && isset($seen[$sum])) {
                $row->delete();

                continue;
            }
            if ($sum !== '') {
                $seen[$sum] = true;
            }
            $row->product_id = $winnerId;
            $row->save();
        }
    }

    /**
     * @param  list<int>  $loserIds
     */
    private function remapPriceHistory(int $winnerId, array $loserIds): void
    {
        if (! Schema::hasTable('product_price_history') || $loserIds === []) {
            return;
        }
        ProductPriceHistory::query()->whereIn('product_id', $loserIds)->update(['product_id' => $winnerId]);
    }

    /**
     * Sloty cen scalanych kart na kartę docelową. Ten sam source_key (UNIQUE) — zostaje slot z nowszym checked_at.
     *
     * @param  list<int>  $loserIds
     */
    private function moveSourcePrices(int $winnerId, array $loserIds): void
    {
        if (! Schema::hasTable('product_source_prices') || $loserIds === []) {
            return;
        }
        $kept = ProductSourcePrice::query()->where('product_id', $winnerId)->get()->keyBy('source_key')->all();
        foreach (ProductSourcePrice::query()->whereIn('product_id', $loserIds)->orderBy('id')->get() as $slot) {
            $key = (string) $slot->source_key;
            $current = $kept[$key] ?? null;
            if ($current !== null) {
                if (($slot->checked_at?->getTimestamp() ?? 0) <= ($current->checked_at?->getTimestamp() ?? 0)) {
                    $slot->delete();

                    continue;
                }
                $current->delete();
            }
            $slot->product_id = $winnerId;
            $slot->save();
            $kept[$key] = $slot;
        }
    }

    /**
     * Sloty cen przy łączeniu rozmiarów (mergeSizeCards): slot $keep o danym source_key zostaje zawsze — to cena
     * pozycji wiodącej, z której synchronizacja dalej go odświeża; sloty łączonych kart o tym źródle znikają (są
     * w kopii zapasowej). Źródło, którego $keep nie ma, przechodzi ze slotem o najnowszym checked_at.
     *
     * @param  list<int>  $dropIds
     */
    private function moveSourcePricesKeeperFirst(int $keepId, array $dropIds): void
    {
        if (! Schema::hasTable('product_source_prices') || $dropIds === []) {
            return;
        }
        $keepKeys = array_fill_keys(
            ProductSourcePrice::query()->where('product_id', $keepId)->pluck('source_key')->map(static fn ($k): string => (string) $k)->all(),
            true,
        );
        /** @var array<string, ProductSourcePrice> $best */
        $best = [];
        foreach (ProductSourcePrice::query()->whereIn('product_id', $dropIds)->orderBy('id')->get() as $slot) {
            $key = (string) $slot->source_key;
            if (isset($keepKeys[$key])) {
                $slot->delete();

                continue;
            }
            $current = $best[$key] ?? null;
            if ($current !== null) {
                if (($slot->checked_at?->getTimestamp() ?? 0) <= ($current->checked_at?->getTimestamp() ?? 0)) {
                    $slot->delete();

                    continue;
                }
                $current->delete();
            }
            $best[$key] = $slot;
        }
        foreach ($best as $slot) {
            $slot->product_id = $keepId;
            $slot->save();
        }
    }

    /**
     * Tabelki sklepu przy łączeniu rozmiarów: konto-właściciel którejkolwiek karty (3M) — tylko tabelka $keep, bo
     * pozycja wiodąca odświeża ją dalej, a tabelki pozostałych rozmiarów opisywałyby inny rozmiar (usunięte, są
     * w kopii zapasowej); inne konta — tabelka $keep, a gdy jej nie ma, najnowsza z łączonych kart.
     *
     * @param  list<int>  $dropIds
     * @param  list<int>  $ownerAccountIds
     */
    private function moveShopCardsKeeperFirst(int $keepId, array $dropIds, array $ownerAccountIds): void
    {
        if (! Schema::hasTable('product_shop_cards') || $dropIds === []) {
            return;
        }
        $owners = array_fill_keys($ownerAccountIds, true);
        $keepAccounts = array_fill_keys(
            ProductShopCard::query()->where('product_id', $keepId)->pluck('b2b_account_id')->map(static fn ($id): int => (int) $id)->all(),
            true,
        );
        /** @var array<int, ProductShopCard> $best */
        $best = [];
        foreach (ProductShopCard::query()->whereIn('product_id', $dropIds)->orderBy('id')->get() as $card) {
            $accountId = (int) $card->b2b_account_id;
            if (isset($owners[$accountId]) || isset($keepAccounts[$accountId])) {
                $card->delete();

                continue;
            }
            $current = $best[$accountId] ?? null;
            if ($current !== null) {
                if (($card->synced_at?->getTimestamp() ?? 0) <= ($current->synced_at?->getTimestamp() ?? 0)) {
                    $card->delete();

                    continue;
                }
                $current->delete();
            }
            $best[$accountId] = $card;
        }
        foreach ($best as $card) {
            $card->product_id = $keepId;
            $card->save();
        }
    }

    /**
     * Powiązania kodów dostawcy na kartę docelową. UNIQUE (b2b_account_id, remote_id) nie zależy od karty, a kilka
     * kodów jednego konta na jednej karcie to zwykły stan grupy rozmiarów w B2bCatalogSync. merged_at oznacza kartę
     * scaloną — synchronizacja nie oddaje jej kolejnych grup rozmiarów konta na nowe karty (resolveGroupCard).
     *
     * @param  list<int>  $loserIds
     */
    private function moveB2bLinks(int $winnerId, array $loserIds): void
    {
        if (! Schema::hasTable('b2b_product_links') || $loserIds === []) {
            return;
        }
        B2bProductLink::query()->whereIn('product_id', $loserIds)->update(['product_id' => $winnerId, 'merged_at' => now()]);
    }

    /**
     * Tabelki ze sklepu dostawcy na kartę docelową. Ta sama para (karta, konto) jest UNIQUE — zostaje tabelka
     * pobrana później (jak slot ceny w moveSourcePrices).
     *
     * @param  list<int>  $loserIds
     */
    private function moveShopCards(int $winnerId, array $loserIds): void
    {
        if (! Schema::hasTable('product_shop_cards') || $loserIds === []) {
            return;
        }
        $kept = ProductShopCard::query()->where('product_id', $winnerId)->get()->keyBy('b2b_account_id')->all();
        foreach (ProductShopCard::query()->whereIn('product_id', $loserIds)->orderBy('id')->get() as $card) {
            $accountId = (int) $card->b2b_account_id;
            $current = $kept[$accountId] ?? null;
            if ($current !== null) {
                if (($card->synced_at?->getTimestamp() ?? 0) <= ($current->synced_at?->getTimestamp() ?? 0)) {
                    $card->delete();

                    continue;
                }
                $current->delete();
            }
            $card->product_id = $winnerId;
            $card->save();
            $kept[$accountId] = $card;
        }
    }

    /**
     * Zdjęcie odrzucone na scalanej karcie nie może wrócić na kartę docelową z galerii przeniesionego powiązania B2B.
     * UNIQUE (product_id, file_key_hash) — odrzucenie już zapisane na karcie docelowej wystarcza.
     *
     * @param  list<int>  $loserIds
     */
    private function moveImageRejections(int $winnerId, array $loserIds): void
    {
        if (! Schema::hasTable('product_image_rejections') || $loserIds === []) {
            return;
        }
        $taken = array_fill_keys(
            ProductImageRejection::query()->where('product_id', $winnerId)->pluck('file_key_hash')->all(),
            true,
        );
        foreach (ProductImageRejection::query()->whereIn('product_id', $loserIds)->orderBy('id')->get() as $rejection) {
            $hash = (string) $rejection->file_key_hash;
            if (isset($taken[$hash])) {
                $rejection->delete();

                continue;
            }
            $taken[$hash] = true;
            $rejection->product_id = $winnerId;
            $rejection->save();
        }
    }

    /**
     * @param  list<int>  $loserIds
     */
    private function remapPresta(int $winnerId, array $loserIds): void
    {
        if (! Schema::hasTable('presta_product_matches') || $loserIds === []) {
            return;
        }
        foreach ($loserIds as $from) {
            try {
                DB::table('presta_product_matches')->where('product_id', $from)->update(['product_id' => $winnerId]);
            } catch (Throwable) {
                DB::table('presta_product_matches')->where('product_id', $from)->delete();
            }
        }
    }

    /**
     * @param  array<int, int>  $map
     */
    private function remapPriceLists(array $map): void
    {
        if (! Schema::hasTable('price_lists') || $map === []) {
            return;
        }
        foreach (PriceList::query()->whereNotNull('product_ids')->cursor() as $list) {
            $ids = is_array($list->product_ids) ? $list->product_ids : [];
            $next = [];
            $changed = false;
            foreach ($ids as $id) {
                $nid = $map[(int) $id] ?? (int) $id;
                if ($nid !== (int) $id) {
                    $changed = true;
                }
                if (! in_array($nid, $next, true)) {
                    $next[] = $nid;
                }
            }
            if ($changed) {
                $list->update(['product_ids' => $next]);
            }
        }
    }
}
