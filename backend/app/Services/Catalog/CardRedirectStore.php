<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\B2bProductLink;
use App\Models\CardMatchCandidate;
use App\Models\CardRedirect;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductSourcePrice;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Zapis mapy połączeń (card_redirects): decyzja człowieka „kody karty źródła trafiają na kartę docelową” i przepinanie
 * wierszy przy scaleniach kart. Czytanie mapy przez synchronizację B2B i import pliku to kolejne kroki planu.
 */
final class CardRedirectStore
{
    /**
     * Zapis decyzji „kody karty $source trafiają na $target”: po jednym wierszu na każde powiązanie B2B karty źródła
     * (wszystkie konta; pozycja = remote_id, kod dostawcy = remote_sku, etykieta = variant_label identyfikatora tej
     * pozycji, jeśli jest) i na każdą pozycję identyfikatorów plikowych karty źródła (pozycja = position_key).
     * Wołać PRZED scaleniem, dopóki powiązania i identyfikatory są na karcie źródła. Wiersz o tej samej parze
     * (source_key, position_key) nadpisuje nowa decyzja — z autorem i czasem tej decyzji.
     *
     * @return int liczba zapisanych wierszy
     */
    public function recordMerge(Product $source, Product $target, string $reason, ?CardMatchCandidate $candidate, ?User $user): int
    {
        if (! in_array($reason, CardRedirect::REASONS, true)) {
            throw new InvalidArgumentException('Nieznany powód wpisu mapy połączeń: „'.$reason.'”.');
        }

        /** @var array<string, array{source_key: string, position_key: string, b2b_account_id: int|null, price_list_id: int|null, remote_sku: string|null, position_label: string|null}> $positions */
        $positions = [];
        $links = B2bProductLink::query()->where('product_id', $source->id)->orderBy('id')->get();
        foreach ($links as $link) {
            $sourceKey = ProductSourcePrice::b2bKey((int) $link->b2b_account_id);
            $position = (string) $link->remote_id;
            $positions[self::key($sourceKey, $position)] ??= [
                'source_key' => $sourceKey,
                'position_key' => $position,
                'b2b_account_id' => (int) $link->b2b_account_id,
                'price_list_id' => null,
                'remote_sku' => self::cut($link->remote_sku, 255),
                'position_label' => null,
            ];
        }
        // etykieta rozmiaru/koloru pozycji B2B z jej identyfikatorów (idą za pozycją, więc bez warunku karty)
        if ($links->isNotEmpty()) {
            $labels = ProductIdentifier::query()
                ->whereIn('source_key', $links->map(static fn (B2bProductLink $l): string => ProductSourcePrice::b2bKey((int) $l->b2b_account_id))->unique()->values()->all())
                ->whereIn('position_key', $links->pluck('remote_id')->map(static fn ($id): string => (string) $id)->unique()->values()->all())
                ->whereNotNull('variant_label')
                ->orderBy('id')
                ->get(['source_key', 'position_key', 'variant_label']);
            foreach ($labels as $row) {
                $key = self::key((string) $row->source_key, (string) $row->position_key);
                if (isset($positions[$key]) && $positions[$key]['position_label'] === null) {
                    $positions[$key]['position_label'] = self::cut($row->variant_label, 120);
                }
            }
        }

        $fileRows = ProductIdentifier::query()
            ->where('product_id', $source->id)
            ->where('source_key', 'like', 'file:%')
            ->orderBy('id')
            ->get(['source_key', 'position_key', 'price_list_id', 'variant_label']);
        foreach ($fileRows as $row) {
            $key = self::key((string) $row->source_key, (string) $row->position_key);
            if (! isset($positions[$key])) {
                $positions[$key] = [
                    'source_key' => (string) $row->source_key,
                    'position_key' => (string) $row->position_key,
                    'b2b_account_id' => null,
                    'price_list_id' => $row->price_list_id !== null ? (int) $row->price_list_id : null,
                    'remote_sku' => null,
                    'position_label' => null,
                ];
            }
            if ($positions[$key]['position_label'] === null) {
                $positions[$key]['position_label'] = self::cut($row->variant_label, 120);
            }
        }

        $snapshot = self::snapshot($target);
        $now = now();
        foreach ($positions as $position) {
            $row = CardRedirect::query()->firstOrNew([
                'source_key' => $position['source_key'],
                'position_key' => $position['position_key'],
            ]);
            // nowa decyzja zastępuje starą w całości — także autora, czas i pozycję wiodącą
            $row->forceFill([
                ...$position,
                'product_id' => (int) $target->id,
                'reason' => $reason,
                'is_anchor' => false,
                'target_snapshot' => $snapshot,
                'card_match_candidate_id' => $candidate?->id,
                'created_by' => $user?->id,
                'created_at' => $now,
            ])->save();
        }

        return count($positions);
    }

    /**
     * Scalenie kart: wiersze wskazujące karty $fromIds przechodzą na $toId. target_snapshot bez zmian — to ślad decyzji.
     *
     * @param  list<int>  $fromIds
     * @return int liczba przepiętych wierszy
     */
    public function repoint(array $fromIds, int $toId): int
    {
        $fromIds = array_values(array_diff(array_map('intval', $fromIds), [$toId]));
        if ($fromIds === []) {
            return 0;
        }

        return CardRedirect::query()->whereIn('product_id', $fromIds)->update(['product_id' => $toId]);
    }

    /**
     * Karta docelowa w chwili decyzji.
     *
     * @return array{id: int, sku: string, name: string, manufacturer: string}
     */
    public static function snapshot(Product $product): array
    {
        return [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'name' => (string) $product->name,
            'manufacturer' => (string) $product->manufacturer,
        ];
    }

    /**
     * Klucz pary źródło + pozycja jak porównanie w UNIQUE na produkcji (utf8mb4_unicode_ci: bez wielkości liter
     * i akcentów) — dwie takie pozycje w jednym zapisie trafiłyby w ten sam wiersz.
     */
    public static function key(string $sourceKey, string $position): string
    {
        return $sourceKey."\n".mb_strtolower(Str::ascii($position));
    }

    private static function cut(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
