<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bProductLink;
use App\Models\Product;
use RuntimeException;

/**
 * Karta z opisem z cennika B2B (decyzja użytkownika 15.09.2026): opis zapisany przez synchronizację i niezmieniony od
 * tamtej pory — powiązanie konta ma description_hash = sha1 obecnego opisu (także polskie tłumaczenie zapisane przez
 * TranslateB2bProductTextJob). Uzupełnianie AI takiego opisu nie nadpisuje zbiorczo; pojedynczo tylko po potwierdzeniu
 * — AI zastąpiłoby tekst ze sklepu dostawcy opisem z internetu, a kolejna synchronizacja uzna zmianę za ręczną
 * i oryginału nie przywróci.
 */
final class B2bDescriptionSource
{
    public const OVERWRITE_MESSAGE = 'Karta ma opis z cennika B2B (ze sklepu dostawcy). Uzupełnianie AI zastąpiłoby go opisem z internetu, '
        .'a kolejne pobranie cennika go nie przywróci — potwierdź nadpisanie.';

    /**
     * @param  list<int>  $productIds
     * @return array<int, true> id kart z opisem z B2B
     */
    public function productIds(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $hashes = [];
            $links = B2bProductLink::query()
                ->whereIn('product_id', $chunk)
                ->whereNotNull('description_hash')
                ->get(['product_id', 'description_hash']);
            foreach ($links as $link) {
                $hashes[(int) $link->product_id][(string) $link->description_hash] = true;
            }
            if ($hashes === []) {
                continue;
            }
            foreach (Product::query()->whereIn('id', array_keys($hashes))->get(['id', 'description']) as $product) {
                $description = (string) $product->description;
                if (trim($description) !== '' && isset($hashes[(int) $product->id][sha1($description)])) {
                    $out[(int) $product->id] = true;
                }
            }
        }

        return $out;
    }

    public function has(Product $product): bool
    {
        return $this->productIds([(int) $product->id]) !== [];
    }

    /** Pojedyncze uzupełnianie AI karty z opisem z B2B tylko po potwierdzeniu użytkownika. */
    public function assertMayOverwrite(Product $product, bool $confirmed): void
    {
        if (! $confirmed && $this->has($product)) {
            throw new RuntimeException(self::OVERWRITE_MESSAGE);
        }
    }
}
