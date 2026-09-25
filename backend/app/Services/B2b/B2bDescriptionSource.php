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
     * Lista cenników pyta o cały katalog (25.09.2026: 43 tys. kart, 138 tys. powiązań, 29 MB opisów), stąd wiersze
     * bez modeli Eloquent, jedno zapytanie o powiązania na porcję i sha1 raz na kartę, nie na każde jej powiązanie.
     *
     * @param  list<int>  $productIds
     * @return array<int, true> id kart z opisem z B2B
     */
    public function productIds(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $hashes = $this->linkHashes($chunk);
            if ($hashes === []) {
                continue;
            }
            $rows = Product::query()->whereIn('id', array_keys($hashes))->toBase()->get(['id', 'description']);
            foreach ($rows as $row) {
                $id = (int) $row->id;
                if ($this->matchesLink((string) ($row->description ?? ''), $hashes[$id])) {
                    $out[$id] = true;
                }
            }
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
            foreach ($this->linkHashes(array_keys($chunk)) as $id => $hashes) {
                if ($this->matchesLink((string) ($chunk[$id] ?? ''), $hashes)) {
                    $out[$id] = true;
                }
            }
        }

        return $out;
    }

    /**
     * Skróty opisu zapisane przez synchronizację na powiązaniach kart — karta bywa powiązana z kilkoma kontami
     * i wersjami (średnio 3 powiązania na kartę).
     *
     * @param  list<int>  $productIds  najwyżej 1000
     * @return array<int, array<string, true>> id karty => skróty jej powiązań
     */
    private function linkHashes(array $productIds): array
    {
        $hashes = [];
        $rows = B2bProductLink::query()
            ->whereIn('product_id', $productIds)
            ->whereNotNull('description_hash')
            ->toBase()
            ->get(['product_id', 'description_hash']);
        foreach ($rows as $row) {
            $hashes[(int) $row->product_id][(string) $row->description_hash] = true;
        }

        return $hashes;
    }

    /**
     * Opis karty to wciąż tekst zapisany przez synchronizację któregoś z jej powiązań.
     *
     * @param  array<string, true>  $linkHashes
     */
    private function matchesLink(string $description, array $linkHashes): bool
    {
        // sam tekst musi być opisem: część kart UVEX ma ze sklepu tylko jednostkę sprzedaży
        // („Jednostka: szt.”), a taka karta nadal czeka na opis — i AI ma wolno jej go dopisać
        return Product::isDescriptionText($description) && isset($linkHashes[sha1($description)]);
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
