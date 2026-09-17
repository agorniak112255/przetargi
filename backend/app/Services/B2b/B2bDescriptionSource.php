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
 *
 * Opisem jest tekst spełniający miarę karty (Product::isDescriptionText) — sama jednostka sprzedaży ze sklepu
 * („Jednostka: szt.”, 396 kart UVEX 17.09.2026) opisem nie jest: karta nadal czeka na opis, więc liczniki muszą
 * ją pokazywać jako niegotową, a zbiorcze AI ma jej nie pomijać.
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
            $linked = B2bProductLink::query()
                ->whereIn('product_id', $chunk)
                ->whereNotNull('description_hash')
                ->pluck('product_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            if ($linked === []) {
                continue;
            }
            $descriptions = [];
            foreach (Product::query()->whereIn('id', $linked)->get(['id', 'description']) as $product) {
                $descriptions[(int) $product->id] = (string) $product->description;
            }
            $out += $this->filterByDescription($descriptions);
        }

        return $out;
    }

    /**
     * Ta sama reguła co productIds(), ale na już wczytanych opisach — dla miejsc, które i tak przechodzą po kartach
     * (raport jakości katalogu) i nie muszą czytać opisów drugi raz.
     *
     * @param  array<int, string>  $descriptions  id karty => jej obecny opis
     * @return array<int, true> id kart z opisem z B2B
     */
    public function filterByDescription(array $descriptions): array
    {
        $out = [];
        foreach (array_chunk($descriptions, 1000, true) as $chunk) {
            $links = B2bProductLink::query()
                ->whereIn('product_id', array_keys($chunk))
                ->whereNotNull('description_hash')
                ->get(['product_id', 'description_hash']);
            foreach ($links as $link) {
                $id = (int) $link->product_id;
                $description = (string) ($chunk[$id] ?? '');
                // sam tekst musi być opisem: część kart UVEX ma ze sklepu tylko jednostkę sprzedaży
                // („Jednostka: szt.”), a taka karta nadal czeka na opis — i AI ma wolno jej go dopisać
                if (Product::isDescriptionText($description) && sha1($description) === (string) $link->description_hash) {
                    $out[$id] = true;
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
