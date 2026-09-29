<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Karta katalogu przy towarze XL na listach Zapasów: tylko pewne i potwierdzone powiązania (propozycja nie jest
 * dowodem), potwierdzona przed automatyczną, z miniaturą głównego zdjęcia — jedno zapytanie o zdjęcia na stronę.
 */
final class ErpItemCards
{
    public const LINKED = [ErpItemLink::STATUS_AUTO, ErpItemLink::STATUS_CONFIRMED];

    /**
     * @param  Builder<ErpItemLink>|HasMany<ErpItemLink, ErpItem>  $q
     * @return Builder<ErpItemLink>|HasMany<ErpItemLink, ErpItem>
     */
    public static function linked($q)
    {
        return $q->whereIn('status', self::LINKED)->whereNotNull('product_id');
    }

    /**
     * Do ->with(): powiązania z kartą (id, SKU, nazwa, producent).
     *
     * @return array<string, \Closure>
     */
    public static function eagerLinks(): array
    {
        return ['links' => static fn ($q) => self::linked($q)->with('product:id,sku,name,manufacturer')];
    }

    /**
     * Id towaru XL → karta w wierszu i liczba powiązanych kart.
     *
     * @param  iterable<ErpItem>  $items  z załadowanymi powiązaniami (eagerLinks)
     * @return array<int, array{card: array{id: int, sku: string, name: string, manufacturer: string|null, thumb_url: string|null, link_status: string}|null, cards_count: int}>
     */
    public function forItems(iterable $items): array
    {
        $main = [];
        foreach ($items as $item) {
            $main[(int) $item->id] = $this->mainLink($item);
        }
        $thumbs = $this->thumbs(array_values(array_filter(array_map(
            static fn (?ErpItemLink $l): ?int => $l?->product_id !== null ? (int) $l->product_id : null,
            $main,
        ))));

        $out = [];
        foreach ($items as $item) {
            $link = $main[(int) $item->id];
            $out[(int) $item->id] = [
                'card' => $link === null ? null : [
                    'id' => (int) $link->product->id,
                    'sku' => (string) $link->product->sku,
                    'name' => (string) $link->product->name,
                    'manufacturer' => $link->product->manufacturer !== '' ? $link->product->manufacturer : null,
                    'thumb_url' => $thumbs[(int) $link->product->id] ?? null,
                    'link_status' => $link->status,
                ],
                'cards_count' => $item->links->filter(fn (ErpItemLink $l): bool => $l->product !== null)->pluck('product_id')->unique()->count(),
            ];
        }

        return $out;
    }

    /** Karta w wierszu: potwierdzona przed automatyczną, potem najstarsze powiązanie. */
    private function mainLink(ErpItem $item): ?ErpItemLink
    {
        return $item->links
            ->filter(fn (ErpItemLink $l): bool => $l->product !== null)
            ->sortBy(fn (ErpItemLink $l): string => ($l->status === ErpItemLink::STATUS_CONFIRMED ? '0' : '1').'-'.str_pad((string) $l->id, 10, '0', STR_PAD_LEFT))
            ->first();
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, string>
     */
    private function thumbs(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $out = [];
        foreach (Product::query()->whereIn('id', array_unique($productIds))->with([
            'images' => static fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id'),
        ])->get(['id']) as $product) {
            $image = $product->images->first();
            if ($image !== null) {
                $out[(int) $product->id] = $image->thumbUrl();
            }
        }

        return $out;
    }
}
