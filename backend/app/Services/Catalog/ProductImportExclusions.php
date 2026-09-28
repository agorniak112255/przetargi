<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\B2bProductLink;
use App\Models\CardRedirect;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductImportExclusion;
use App\Models\ProductSourcePrice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * „Usuń i pomijaj przy imporcie” (decyzja użytkownika 28.09.2026): pozycje źródeł usuwanej karty trafiają do
 * product_import_exclusions, a synchronizacja B2B i import cennika z pliku ich nie zakładają od nowa, dopóki człowiek
 * nie przywróci pozycji (Cenniki → Usunięte z pominięciem). Przywrócona pozycja wraca przy najbliższym przebiegu jako
 * nowa karta — usunięcie jest twarde, zdjęć ani historii nie odtwarzamy.
 */
final class ProductImportExclusions
{
    private const POSITION_LIMIT = 64;

    /**
     * Zapis blokad pozycji kart $productIds — PRZED usunięciem kart (kaskada kasuje powiązania i identyfikatory).
     * Jedno usunięcie karty = jeden deletion_id. Pozycja zablokowana wcześniej (także przywrócona) dostaje nowy zapis.
     *
     * @param  list<int>  $productIds
     * @return int liczba zablokowanych pozycji
     */
    public function record(array $productIds, User $actor): int
    {
        $positions = $this->positionsOf($productIds);
        if ($positions === []) {
            return 0;
        }
        $cards = Product::query()->whereIn('id', array_keys($positions))->get()->keyBy('id');
        $now = now();
        $count = 0;
        foreach ($positions as $productId => $cardPositions) {
            $card = $cards->get($productId);
            if (! $card instanceof Product) {
                continue;
            }
            $deletionId = (string) Str::uuid();
            $snapshot = [
                'id' => (int) $card->id,
                'sku' => (string) $card->sku,
                'name' => (string) $card->name,
                'manufacturer' => (string) $card->manufacturer,
                'purchase_price' => $card->purchase_price,
                'catalog_price_net' => $card->catalog_price_net,
                'currency' => $card->currency,
            ];
            foreach ($cardPositions as $position) {
                $row = ProductImportExclusion::query()->firstOrNew(['match_key' => $position['match_key']]);
                $row->forceFill([
                    ...$position,
                    'deletion_id' => $deletionId,
                    'product_id' => (int) $card->id,
                    'product_sku' => mb_substr((string) $card->sku, 0, 255),
                    'product_name' => mb_substr((string) $card->name, 0, 1000),
                    'product_manufacturer' => self::cut((string) $card->manufacturer, 100),
                    'product_snapshot' => $snapshot,
                    'deleted_by' => $actor->id,
                    'restored_at' => null,
                    'restored_by' => null,
                    'hits' => 0,
                    'last_hit_at' => null,
                    'created_at' => $now,
                ])->save();
                $count++;
            }
        }

        return $count;
    }

    /**
     * Pozycje źródeł kart: powiązania B2B (remote_id), kody wierszy cenników z pliku (także zniknięte z pliku) i wpisy
     * mapy połączeń wskazujące kartę. Karta z ceną z pliku bez kodów wierszy tego cennika — wpis „sku” (SKU karty).
     * Pomijane: pozycja, którą mapa połączeń kieruje na istniejącą kartę spoza usuwanych, i pozycja pliku z aktywnym
     * identyfikatorem na innej karcie (zmieniony EAN, kod powtórzony w pliku) — należy do tamtej karty.
     *
     * @param  list<int>  $productIds
     * @return array<int, list<array<string, mixed>>> id karty => pozycje (pola wiersza product_import_exclusions)
     */
    public function positionsOf(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $idSet = array_fill_keys($ids, true);

        /** @var array<string, int> $elsewhere klucz źródło+pozycja => karta spoza usuwanych, na którą kieruje mapa */
        $elsewhere = [];
        /** @var list<CardRedirect> $ownRedirects */
        $ownRedirects = [];
        foreach (CardRedirect::query()->whereNotNull('product_id')->where(static function ($q) use ($ids): void {
            $q->whereIn('product_id', $ids)
                ->orWhereIn('position_key', B2bProductLink::query()->whereIn('product_id', $ids)->select('remote_id'))
                ->orWhereIn('position_key', ProductIdentifier::query()->whereIn('product_id', $ids)->where('source_key', 'like', 'file:%')->select('position_key'));
        })->orderBy('id')->get() as $redirect) {
            if (isset($idSet[(int) $redirect->product_id])) {
                $ownRedirects[] = $redirect;
            } else {
                $elsewhere[CardRedirectStore::key((string) $redirect->source_key, (string) $redirect->position_key)] = (int) $redirect->product_id;
            }
        }

        /** @var array<string, array{source_key: string, position_key: string, product_id: int, remote_sku: string|null, position_label: string|null}> $raw */
        $raw = [];
        $add = static function (string $sourceKey, string $position, int $productId, ?string $remoteSku, ?string $label) use (&$raw): void {
            $position = mb_substr(trim($position), 0, self::POSITION_LIMIT);
            if ($position === '') {
                return;
            }
            $key = CardRedirectStore::key($sourceKey, $position);
            $raw[$key] ??= [
                'source_key' => $sourceKey,
                'position_key' => $position,
                'product_id' => $productId,
                'remote_sku' => $remoteSku,
                'position_label' => $label,
            ];
            // etykieta z kolejnego wiersza tej samej pozycji (identyfikator z etykietą rozmiaru)
            $raw[$key]['position_label'] ??= $label;
        };

        foreach (B2bProductLink::query()->whereIn('product_id', $ids)->orderBy('id')->get() as $link) {
            $add(
                ProductSourcePrice::b2bKey((int) $link->b2b_account_id),
                (string) $link->remote_id,
                (int) $link->product_id,
                self::cut($link->remote_sku, 255),
                self::cut($link->remote_name, 255),
            );
        }

        $fileRows = ProductIdentifier::query()
            ->whereIn('product_id', $ids)
            ->where('source_key', 'like', 'file:%')
            ->orderBy('id')
            ->get(['product_id', 'source_key', 'position_key', 'variant_label']);
        /** @var array<string, true> $fileListsWithRows „{karta}:{source_key}” */
        $fileListsWithRows = [];
        foreach ($fileRows as $row) {
            $fileListsWithRows[(int) $row->product_id.':'.$row->source_key] = true;
            $add((string) $row->source_key, (string) $row->position_key, (int) $row->product_id, null, self::cut($row->variant_label, 255));
        }

        foreach ($ownRedirects as $redirect) {
            $add((string) $redirect->source_key, (string) $redirect->position_key, (int) $redirect->product_id, self::cut($redirect->remote_sku, 255), self::cut($redirect->position_label, 255));
        }

        // pozycje pliku z aktywnym identyfikatorem na karcie spoza usuwanych
        $filePositions = [];
        foreach ($raw as $entry) {
            if (str_starts_with($entry['source_key'], 'file:')) {
                $filePositions[$entry['source_key']][] = $entry['position_key'];
            }
        }
        $takenElsewhere = [];
        foreach ($filePositions as $sourceKey => $positions) {
            foreach (array_chunk(array_values(array_unique($positions)), 500) as $chunk) {
                $others = ProductIdentifier::query()
                    ->where('source_key', $sourceKey)
                    ->whereIn('position_key', $chunk)
                    ->whereNotIn('product_id', $ids)
                    ->whereNull('removed_at')
                    ->get(['source_key', 'position_key']);
                foreach ($others as $other) {
                    $takenElsewhere[CardRedirectStore::key((string) $other->source_key, (string) $other->position_key)] = true;
                }
            }
        }

        $listIds = [];
        foreach ($raw as $entry) {
            if (str_starts_with($entry['source_key'], 'file:')) {
                $listIds[(int) substr($entry['source_key'], 5)] = true;
            }
        }
        $fileSlots = ProductSourcePrice::query()
            ->whereIn('product_id', $ids)
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->whereNotNull('price_list_id')
            ->get(['product_id', 'price_list_id']);
        foreach ($fileSlots as $slot) {
            $listIds[(int) $slot->price_list_id] = true;
        }
        $manufacturerKeys = $listIds === [] ? [] : PriceList::query()
            ->whereIn('id', array_keys($listIds))
            ->pluck('manufacturer_key', 'id')
            ->map(static fn (mixed $key): string => (string) $key)
            ->all();

        $out = [];
        $seen = [];
        foreach ($raw as $key => $entry) {
            if (isset($elsewhere[$key]) || isset($takenElsewhere[$key])) {
                continue;
            }
            $position = $this->position($entry['source_key'], $entry['position_key'], ProductImportExclusion::KIND_POSITION, $manufacturerKeys);
            if ($position === null || isset($seen[$position['match_key']])) {
                continue;
            }
            $seen[$position['match_key']] = true;
            $out[$entry['product_id']][] = [
                ...$position,
                'remote_sku' => $entry['remote_sku'],
                'position_label' => $entry['position_label'],
            ];
        }

        // karta z ceną z pliku bez kodów wierszy tego cennika (import sprzed 23.09.2026) — dopasowanie po SKU karty
        if ($fileSlots->isNotEmpty()) {
            $skus = Product::query()->whereIn('id', $fileSlots->pluck('product_id')->all())->pluck('sku', 'id');
            foreach ($fileSlots as $slot) {
                $productId = (int) $slot->product_id;
                $sourceKey = ProductIdentifierStore::fileKey((int) $slot->price_list_id);
                if (isset($fileListsWithRows[$productId.':'.$sourceKey])) {
                    continue;
                }
                $sku = (string) ($skus[$productId] ?? '');
                $position = $this->position($sourceKey, $sku, ProductImportExclusion::KIND_SKU, $manufacturerKeys);
                if ($position === null || isset($seen[$position['match_key']])) {
                    continue;
                }
                $seen[$position['match_key']] = true;
                $out[$productId][] = [...$position, 'remote_sku' => null, 'position_label' => null];
            }
        }

        return $out;
    }

    /** Aktywne blokady konta B2B — raz na przebieg. */
    public function forAccount(int $accountId): ImportExclusionSet
    {
        return $this->setOf(self::b2bScope($accountId));
    }

    /** Aktywne blokady cennika z pliku — po producencie cennika, nie po numerze wpisu. */
    public function forPriceList(PriceList $priceList): ImportExclusionSet
    {
        $key = trim((string) $priceList->manufacturer_key);

        return $key === '' ? ImportExclusionSet::empty() : $this->setOf(self::fileScope($key));
    }

    /**
     * Blokada pozycji konta prosto z bazy — tuż przed założeniem karty (usunięcie z pominięciem w trakcie przebiegu,
     * po wczytaniu mapy).
     */
    public function activeB2bPosition(int $accountId, string $remoteId): ?ProductImportExclusion
    {
        if (trim($remoteId) === '') {
            return null;
        }

        return ProductImportExclusion::query()
            ->where('match_key', self::matchKey(self::b2bScope($accountId), ProductImportExclusion::KIND_POSITION, $remoteId))
            ->whereNull('restored_at')
            ->first();
    }

    /**
     * Licznik trafień: raz na koniec pełnego przebiegu (bez próbnych).
     *
     * @param  list<int>  $ids
     */
    public function registerHits(array $ids): void
    {
        $now = now();
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 500) as $chunk) {
            ProductImportExclusion::query()->toBase()->whereIn('id', $chunk)->update([
                'hits' => DB::raw('hits + 1'),
                'last_hit_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Przywrócenie pozycji: blokada zdjęta (wiersz zostaje z restored_at jako ślad). Karta wraca przy najbliższej
     * synchronizacji albo imporcie źródła.
     *
     * @param  list<int>  $ids
     * @return int liczba przywróconych pozycji
     */
    public function restore(array $ids, User $actor): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }
        $rows = ProductImportExclusion::query()->whereIn('id', $ids)->whereNull('restored_at')->get(['id', 'source_key', 'position_key', 'product_id', 'product_sku']);
        if ($rows->isEmpty()) {
            return 0;
        }
        ProductImportExclusion::query()->whereIn('id', $rows->pluck('id')->all())->update([
            'restored_at' => now(),
            'restored_by' => $actor->id,
        ]);
        Log::info('Import exclusions restored', [
            'actor_id' => $actor->id,
            'actor_email' => $actor->email,
            'restored' => $rows->count(),
            'positions' => $rows->map(static fn (ProductImportExclusion $row): string => $row->source_key.' '.$row->position_key.' (#'.$row->product_id.' '.$row->product_sku.')')->all(),
        ]);

        return $rows->count();
    }

    /** Pozycja w postaci porównania: przycięta do 64 znaków, bez wielkości liter i akcentów (jak UNIQUE w MySQL). */
    public static function normalize(string $position): string
    {
        return mb_strtolower(Str::ascii(mb_substr(trim($position), 0, self::POSITION_LIMIT)));
    }

    public static function b2bScope(int $accountId): string
    {
        return ProductSourcePrice::b2bKey($accountId);
    }

    public static function fileScope(string $manufacturerKey): string
    {
        return 'file:'.mb_substr(trim($manufacturerKey), 0, 100);
    }

    public static function matchKey(string $scope, string $kind, string $position): string
    {
        return sha1($scope."\n".$kind."\n".self::normalize($position));
    }

    private function setOf(string $scope): ImportExclusionSet
    {
        $byPosition = [];
        $bySku = [];
        foreach (ProductImportExclusion::query()->where('scope_key', $scope)->whereNull('restored_at')->orderBy('id')->get() as $row) {
            $key = self::normalize((string) $row->position_key);
            if ($row->match_kind === ProductImportExclusion::KIND_SKU) {
                $bySku[$key] ??= $row;
            } else {
                $byPosition[$key] ??= $row;
            }
        }

        return new ImportExclusionSet($byPosition, $bySku);
    }

    /**
     * @param  array<int, string>  $manufacturerKeys  id cennika => manufacturer_key
     * @return array<string, mixed>|null pola zakresu i klucza; null — źródło bez zakresu (cennik bez producenta)
     */
    private function position(string $sourceKey, string $position, string $kind, array $manufacturerKeys): ?array
    {
        $position = mb_substr(trim($position), 0, self::POSITION_LIMIT);
        if ($position === '') {
            return null;
        }
        if (str_starts_with($sourceKey, 'b2b:')) {
            $accountId = (int) substr($sourceKey, 4);
            if ($accountId <= 0) {
                return null;
            }
            $scope = self::b2bScope($accountId);

            return [
                'source_key' => $sourceKey,
                'scope_key' => $scope,
                'match_kind' => $kind,
                'position_key' => $position,
                'match_key' => self::matchKey($scope, $kind, $position),
                'b2b_account_id' => $accountId,
                'price_list_id' => null,
                'manufacturer_key' => null,
            ];
        }
        if (str_starts_with($sourceKey, 'file:')) {
            $listId = (int) substr($sourceKey, 5);
            $manufacturerKey = trim($manufacturerKeys[$listId] ?? '');
            if ($manufacturerKey === '') {
                return null;
            }
            $scope = self::fileScope($manufacturerKey);

            return [
                'source_key' => $sourceKey,
                'scope_key' => $scope,
                'match_kind' => $kind,
                'position_key' => $position,
                'match_key' => self::matchKey($scope, $kind, $position),
                'b2b_account_id' => null,
                'price_list_id' => $listId,
                'manufacturer_key' => mb_substr($manufacturerKey, 0, 100),
            ];
        }

        return null;
    }

    private static function cut(?string $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
