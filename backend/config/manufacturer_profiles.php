<?php

declare(strict_types=1);

/*
| Profile producentów — warstwa nad enrichment.manufacturer_domains / manufacturer_only_sources / manufacturer_catalogs
| (te zostają, czyta je wiele miejsc). Tu tylko to, czego tam nie ma (App\Services\Enrichment\ManufacturerProfiles):
| - identity_in: gdzie kod wyrobu daje twardy werdykt tożsamości strony — url (ścieżka adresu), title, markup
|   (mikrodane i JSON-LD głównego wyrobu strony), text (tekst strony i numery części data-part; tylko marki z tabelą
|   wariantów na karcie rodziny, gdzie kod stoi jako osobny token, i tylko na hostach producenta z manufacturer_domains
|   — tekst sklepu niesie kafelki „podobne / klienci kupili”);
| - code: zapis kodu (normalize — na razie tylko upper_alnum), min_length — krótszy kod nie jest kluczem,
|   model_regex — grupa modelu z kodu (opis per model, etap 2); dash_suffix_pad — końcówka „-5” kodu z cennika to na
|   stronie cyfry doklejone do długości („FF0100-5” = „FF010005”); variant_suffixes — litera po kodzie, która znaczy ten
|   sam wyrób w innej postaci sprzedaży („CD010610C” na metry = rolka „CD010610”) — wtedy kod bez niej też jest kluczem;
| - model_alias_is_key: nazwa modelu z karty („VITAL 115”) też jest kluczem — MAPA nie pisze kodu na stronie;
| - resolver: klasa PHP dla reguł, których nie da się opisać danymi (na razie żadna).
| brand_keys — klucze marki jak w manufacturer_domains (małe litery, myślniki).
*/
return [
    'default' => [
        'identity_in' => ['url', 'title', 'markup'],
        'code' => ['normalize' => 'upper_alnum', 'min_length' => 4],
        'model_alias_is_key' => false,
        'resolver' => null,
    ],
    'profiles' => [
        'coba' => [
            'brand_keys' => ['coba', 'coba-europe'],
            'identity_in' => ['url', 'title', 'markup', 'text'],
            // pomiar 07.10.2026 na 182 poprawnie opisanych kartach: bez „-5” i „C” (oraz data-part w mikrodanych)
            // twardy werdykt miało 84,6%, z nimi 94,5% — reszta to strony bez kodu karty albo innego wariantu
            'code' => ['normalize' => 'upper_alnum', 'model_regex' => '/^([A-Z]+)\d/', 'dash_suffix_pad' => 2, 'variant_suffixes' => ['C']],
        ],
        'cederroth' => [
            'brand_keys' => ['cederroth'],
            'identity_in' => ['url', 'title', 'markup', 'text'],
        ],
        'mapa' => [
            'brand_keys' => ['mapa'],
            'identity_in' => ['url', 'title', 'markup', 'text'],
            'model_alias_is_key' => true,
        ],
    ],
];
