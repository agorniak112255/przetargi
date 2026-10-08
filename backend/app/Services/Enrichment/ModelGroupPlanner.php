<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductEnrichmentBatchItem;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Plan partii po modelach (etap 2 opisów z cenników): karty o tym samym kluczu modelu (ProductModelKey) idą razem,
 * zadanie dostaje tylko lider, członkowie mają pozycje partii `queued` bez zadań i dostają opis lidera
 * (ApplyModelDescriptionJob). Lider = ręczny adres > SKU w postaci bazowej (bez „C” po cyfrze, bez „-N”) > karta
 * z twardą bazą (opublikowana wersja z twardym werdyktem tożsamości) > najniższy id; karta z ręcznym adresem innym
 * niż lidera to osobna grupa — jej opis musi przyjść z jej adresu. Karta marki bez grupowania = grupa jednoelementowa
 * bez klucza. Klucz i lider zamrażają się w pozycjach partii (model_key, model_leader_id) — stąd czytają je
 * contextFor/membersOf/nextLeader, nie z ponownego liczenia (nazwa karty mogła się zmienić w trakcie).
 */
final class ModelGroupPlanner
{
    private const CHUNK = 500;

    /** @var list<string> */
    private const COLUMNS = ['id', 'sku', 'name', 'manufacturer', 'shop_source_url'];

    public function __construct(
        private readonly ProductModelKey $keys,
        private readonly ManufacturerProfiles $profiles,
    ) {}

    /**
     * Grupy w kolejności listy wejściowej (pozycja pierwszej karty grupy); karty spoza bazy są pomijane.
     *
     * @param  list<int>  $productIds
     * @return list<ModelGroup>
     */
    public function groups(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if ($ids === []) {
            return [];
        }
        $position = array_flip($ids);
        $hard = $this->hardBaseIds($ids);
        /** @var array<string, list<array{id: int, rank: array{int, int, int, int}, url: ?string, stem: string}>> $byKey */
        $byKey = [];
        /** @var array<int, ModelGroup> $groups pozycja pierwszej karty => grupa */
        $groups = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach (Product::query()->whereIntegerInRaw('id', $chunk)->get(self::COLUMNS) as $product) {
                $id = (int) $product->id;
                $key = $this->keys->for($product);
                if ($key === null) {
                    $groups[$position[$id]] = new ModelGroup('', '', $id, [$id]);

                    continue;
                }
                $byKey[$key->key][] = $this->entry($product, $hard, $key->stem);
            }
        }
        foreach ($byKey as $key => $entries) {
            usort($entries, static fn (array $a, array $b): int => $position[$a['id']] <=> $position[$b['id']]);
            foreach ($this->splitByManualUrl($entries) as $members) {
                $first = min(array_map(static fn (array $e): int => $position[$e['id']], $members));
                $groups[$first] = new ModelGroup(
                    (string) $key,
                    $members[0]['stem'],
                    $members[0]['id'],
                    array_map(static fn (array $e): int => $e['id'], $members),
                );
            }
        }
        ksort($groups);

        return array_values($groups);
    }

    /**
     * Partia w całych modelach: kolejne grupy, póki mieszczą się w limicie kart; grupa, która się nie mieści, kończy
     * partię — chyba że jest pierwsza (model większy niż limit wchodzi cały, inaczej nigdy by nie wszedł).
     * $oldestFirst (force): najpierw grupy, w których żadna karta nie ma enriched_at, potem od najstarszego
     * z NAJNOWSZYCH enriched_at w grupie — model tknięty ostatnio idzie na koniec (newestEnrichedAt); przy równej dacie
     * kolejność wejściowa.
     *
     * @param  list<ModelGroup>  $groups
     * @return array{groups: list<ModelGroup>, product_ids: list<int>}
     */
    public function sliceByLimit(array $groups, int $limit, bool $oldestFirst): array
    {
        $groups = array_values($groups);
        if ($oldestFirst && $groups !== []) {
            $newest = $this->newestEnrichedAt($groups);
            $order = array_keys($groups);
            usort($order, static fn (int $a, int $b): int => [$newest[$a], $a] <=> [$newest[$b], $b]);
            $groups = array_map(static fn (int $i): ModelGroup => $groups[$i], $order);
        }
        $limit = max(1, $limit);
        $taken = [];
        $ids = [];
        foreach ($groups as $group) {
            if ($taken !== [] && count($ids) + count($group->memberIds) > $limit) {
                break;
            }
            $taken[] = $group;
            array_push($ids, ...$group->memberIds);
        }

        return ['groups' => $taken, 'product_ids' => $ids];
    }

    /**
     * Lider spośród kart jednego modelu: ręczny adres > SKU bazowe > twarda baza > najniższy id.
     *
     * @param  list<Product>  $products  co najmniej jedna karta
     */
    public function chooseLeader(array $products): Product
    {
        $products = array_values($products);
        if ($products === []) {
            throw new InvalidArgumentException('Brak kart do wyboru lidera modelu.');
        }
        $hard = $this->hardBaseIds(array_map(static fn (Product $p): int => (int) $p->id, $products));
        $best = $products[0];
        $bestRank = $this->rank($best, $hard);
        foreach ($products as $product) {
            $rank = $this->rank($product, $hard);
            if ($rank < $bestRank) {
                $best = $product;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /**
     * Nota modelu dla lidera w partii: klucz z pozycji partii, rdzeń z nazwy i karty modelu w tej partii (z liderem).
     * null, gdy pozycja nie ma klucza (marka bez grupowania) albo kart modelu w partii jest mniej niż min_members
     * profilu — wtedy lider idzie jak dotąd, bez noty.
     *
     * @return array{key: string, stem: string, members: list<array{id: int, sku: string, name: string}>}|null
     */
    public function contextFor(Product $p, int $batchId): ?array
    {
        $item = ProductEnrichmentBatchItem::query()
            ->where('batch_id', $batchId)
            ->where('product_id', (int) $p->id)
            ->first(['model_key']);
        $key = trim((string) ($item?->model_key ?? ''));
        if ($key === '') {
            return null;
        }
        $profile = $this->profiles->for($p);
        if ($profile === null || $profile->modelGroup === null) {
            return null;
        }
        $members = [];
        foreach (ProductEnrichmentBatchItem::query()
            ->where('batch_id', $batchId)
            ->where('model_key', $key)
            ->orderBy('product_id')
            ->get(['product_id', 'sku', 'name']) as $row) {
            $members[] = ['id' => (int) $row->product_id, 'sku' => (string) $row->sku, 'name' => (string) $row->name];
        }
        if (count($members) < $profile->modelMinMembers) {
            return null;
        }
        $stem = $this->keys->for($p, $profile)?->stem ?? '';
        if ($stem === '') {
            // nazwa karty zmieniła się po zaplanowaniu partii — rdzeń z klucza (małymi literami)
            $parts = explode('|', $key);
            $stem = trim((string) end($parts));
        }

        return ['key' => $key, 'stem' => $stem, 'members' => $members];
    }

    /**
     * Członkowie modelu lidera w partii, którzy jeszcze czekają (`queued`, bez zadania) — bez samego lidera.
     *
     * @return list<int>
     */
    public function membersOf(int $batchId, int $leaderId): array
    {
        return $this->queuedMembers($batchId, $leaderId)
            ->orderBy('product_id')
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Sztafeta: lider bez wersji (brak źródeł, wyjątek, failed()) oddaje model kolejnemu członkowi — wybranemu tą samą
     * regułą co lider — i pozostali czekający członkowie dostają jego id w model_leader_id. null = nikt już nie czeka.
     *
     * Pozycja upadłego lidera (i wcześniejszych upadłych liderów tej sztafety) też przechodzi pod nowego lidera — mają
     * stan końcowy, więc nie są „czekającymi członkami”, ale gdy nowy lider da wersję, ApplyModelDescriptionJob opisze
     * także je (relayedLeaders). Pełne pobranie Coby #501 (08.10.2026): DP0106 i dwa pasy GRP zostały „ręcznie”, choć
     * ich model dostał opis od kolejnego lidera.
     */
    public function nextLeader(int $batchId, int $failedLeaderId): ?int
    {
        $ids = $this->membersOf($batchId, $failedLeaderId);
        if ($ids === []) {
            return null;
        }
        $products = Product::query()->whereIntegerInRaw('id', $ids)->get(self::COLUMNS)->all();
        if ($products === []) {
            return null;
        }
        $leaderId = (int) $this->chooseLeader($products)->id;
        // czekający członkowie i upadli liderzy tej sztafety (ręcznie / błąd); pozycje zakończone opisem zostają
        ProductEnrichmentBatchItem::query()
            ->where('batch_id', $batchId)
            ->where('model_leader_id', $failedLeaderId)
            ->whereIn('status', [ProductEnrichmentBatchItem::STATUS_QUEUED, ProductEnrichmentBatchItem::STATUS_MANUAL, ProductEnrichmentBatchItem::STATUS_FAILED])
            ->update(['model_leader_id' => $leaderId]);

        return $leaderId;
    }

    /**
     * Upadli liderzy sztafety, którzy przeszli pod tego lidera: pozycja w stanie końcowym bez opisu (ręcznie / błąd),
     * karta wciąż bez wyniku przebiegu — dostają opis modelu jak członkowie, gdy ten lider da wersję.
     *
     * @return list<int>
     */
    public function relayedLeaders(int $batchId, int $leaderId): array
    {
        return ProductEnrichmentBatchItem::query()
            ->where('batch_id', $batchId)
            ->where('model_leader_id', $leaderId)
            ->where('product_id', '!=', $leaderId)
            ->whereIn('status', [ProductEnrichmentBatchItem::STATUS_MANUAL, ProductEnrichmentBatchItem::STATUS_FAILED])
            // członek, który padł w zadaniu opisu członków, ma numer wersji lidera (handOverModel) — to nie upadły lider
            ->whereNull('model_leader_version_id')
            ->whereHas('product', static fn ($q) => $q->whereIn('enrichment_status', [Product::ENRICHMENT_MANUAL, Product::ENRICHMENT_FAILED]))
            ->orderBy('product_id')
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return Builder<ProductEnrichmentBatchItem>
     */
    private function queuedMembers(int $batchId, int $leaderId): Builder
    {
        return ProductEnrichmentBatchItem::query()
            ->where('batch_id', $batchId)
            ->where('model_leader_id', $leaderId)
            ->where('product_id', '!=', $leaderId)
            ->where('status', ProductEnrichmentBatchItem::STATUS_QUEUED);
    }

    /**
     * @param  array<int, true>  $hard
     * @return array{id: int, rank: array{int, int, int, int}, url: ?string, stem: string}
     */
    private function entry(Product $product, array $hard, string $stem): array
    {
        $manual = $product->trustedShopUrl();

        return [
            'id' => (int) $product->id,
            'rank' => $this->rank($product, $hard),
            'url' => $manual !== null ? Product::normalizeShopUrl($manual) : null,
            'stem' => $stem,
        ];
    }

    /**
     * Mniejsza krotka wygrywa: [ręczny adres, SKU bazowe, twarda baza, id].
     *
     * @param  array<int, true>  $hard
     * @return array{int, int, int, int}
     */
    private function rank(Product $product, array $hard): array
    {
        return [
            $product->trustedShopUrl() !== null ? 0 : 1,
            self::isBaseSku((string) $product->sku) ? 0 : 1,
            isset($hard[(int) $product->id]) ? 0 : 1,
            (int) $product->id,
        ];
    }

    /** SKU w postaci bazowej: bez „C” po cyfrze („AF060003C” to cięcie na metry) i bez końcówki „-N” („SD0107-6”). */
    private static function isBaseSku(string $sku): bool
    {
        $sku = trim($sku);

        return $sku !== '' && preg_match('/\dC$/i', $sku) !== 1 && preg_match('/-\d+$/', $sku) !== 1;
    }

    /**
     * Lider na pierwszym miejscu; karta z ręcznym adresem innym niż lidera (adresy porównane po normalizacji) idzie
     * do osobnej grupy razem z kartami o tym samym adresie, ze swoim liderem.
     *
     * @param  list<array{id: int, rank: array{int, int, int, int}, url: ?string, stem: string}>  $entries  w kolejności wejściowej
     * @return list<list<array{id: int, rank: array{int, int, int, int}, url: ?string, stem: string}>>
     */
    private function splitByManualUrl(array $entries): array
    {
        $leader = $this->best($entries);
        $main = [$leader];
        $byUrl = [];
        foreach ($entries as $entry) {
            if ($entry['id'] === $leader['id']) {
                continue;
            }
            if ($entry['url'] === null || $entry['url'] === $leader['url']) {
                $main[] = $entry;
            } else {
                $byUrl[$entry['url']][] = $entry;
            }
        }
        $out = [$main];
        foreach ($byUrl as $sub) {
            $subLeader = $this->best($sub);
            $out[] = [$subLeader, ...array_values(array_filter($sub, static fn (array $e): bool => $e['id'] !== $subLeader['id']))];
        }

        return $out;
    }

    /**
     * @param  non-empty-list<array{id: int, rank: array{int, int, int, int}, url: ?string, stem: string}>  $entries
     * @return array{id: int, rank: array{int, int, int, int}, url: ?string, stem: string}
     */
    private function best(array $entries): array
    {
        $best = $entries[0];
        foreach ($entries as $entry) {
            if ($entry['rank'] < $best['rank']) {
                $best = $entry;
            }
        }

        return $best;
    }

    /**
     * Karty z opublikowaną wersją opisu o twardym werdykcie tożsamości (baza etapu 1).
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function hardBaseIds(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (ProductDescriptionVersion::query()
                ->where('status', ProductDescriptionVersion::STATUS_PUBLISHED)
                ->where('identity_verdict', ProductDescriptionVersion::VERDICT_HARD)
                ->whereIntegerInRaw('product_id', $chunk)
                ->distinct()
                ->pluck('product_id') as $id) {
                $out[(int) $id] = true;
            }
        }

        return $out;
    }

    /**
     * Najnowszy enriched_at w grupie jako tekst do porównania; '' = żadna karta grupy nie ma daty opisu (idzie
     * pierwsza). Najnowszy, nie najstarszy: członek z propozycją albo błędem zachowuje stary enriched_at (albo nie ma
     * go wcale), więc po najstarszym cały model wracałby na początek każdej kolejnej partii --force — kolejne wywołanie
     * modelu językowego za ten sam model; data lidera z ostatniego przebiegu wysyła model na koniec.
     *
     * @param  list<ModelGroup>  $groups
     * @return array<int, string> indeks grupy => data
     */
    private function newestEnrichedAt(array $groups): array
    {
        $groupOf = [];
        foreach ($groups as $i => $group) {
            foreach ($group->memberIds as $id) {
                $groupOf[$id] = $i;
            }
        }
        $newest = array_fill(0, count($groups), '');
        foreach (array_chunk(array_keys($groupOf), 1000) as $chunk) {
            foreach (Product::query()->whereIntegerInRaw('id', $chunk)->toBase()->get(['id', 'enriched_at']) as $row) {
                $i = $groupOf[(int) $row->id];
                $at = $row->enriched_at !== null ? (string) $row->enriched_at : '';
                if ($at > $newest[$i]) {
                    $newest[$i] = $at;
                }
            }
        }

        return $newest;
    }
}
