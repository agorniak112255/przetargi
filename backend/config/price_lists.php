<?php

declare(strict_types=1);

return [
    /*
    | Cenniki wielomarkowe z pliku: dystrybutor podpisuje plik swoją nazwą, a sprzedaje też wyroby innych marek
    | („Respirator 3M 9914” w cenniku Canis). Wiersz z marką towaru w nazwie dostaje tę markę zamiast producenta pliku
    | (App\Services\PriceListGoodsBrand). Klucze jak PriceList::manufacturerKey — małe litery, bez znaków specjalnych.
    | Decyzja właściciela z 26.09.2026: na początek tylko Canis.
    */
    'brand_from_name' => ['canis'],
];
