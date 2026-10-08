<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

/**
 * Klucz modelu karty (etap 2 opisów z cenników): `marka | rodzina SKU | rdzeń nazwy`, liczony w locie
 * przez ProductModelKey i zamrażany w pozycji partii (model_key) oraz w enrichment_payload.model_group.
 */
final readonly class ModelKey
{
    public function __construct(
        /** klucz do porównań i do kolumny model_key (≤ 160 znaków) */
        public string $key,
        public string $brandKey,
        /**
         * rodzina z SKU: przechwyt model_regex profilu („AF” z „AF060001”) plus literowy przyrostek po cyfrach
         * („ST/B1” z „ST010001B1” — wersja nitrylowa to inny model niż „ST010001”; wariant „C” i „-N” nie liczą się);
         * '' gdy SKU nie pasuje
         */
        public string $family,
        /** nazwa karty bez rozmiarów, wymiarów i słów koloru, w oryginalnej pisowni */
        public string $stem,
        /** skąd klucz: na razie tylko rdzeń nazwy z profilu marki */
        public string $source = 'profile_name',
    ) {}
}
