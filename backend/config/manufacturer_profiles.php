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
|   combination_suffix — końcówka kodu kombinacji (kolor, rozmiar) w mikrodanych strony producenta, zdejmowana przed
|   porównaniem („103-00033-48/XS” = 103);
| - model_alias_is_key: nazwa modelu z karty („VITAL 115”) też jest kluczem — MAPA nie pisze kodu na stronie;
| - labelled_short_codes: krótki kod (także krótszy niż min_length, od 3 znaków) jest kluczem z etykietą w adresie albo
|   tytule („model-103”, „REF: 6036”) i w mikrodanych strony producenta — tylko marki z krótkimi numerami modeli
|   (u innych „nr 100 szt”, „art. 103 kodeksu pracy” dawałyby fałszywe potwierdzenia);
| - model: opis wspólny dla modelu (etap 2 opisów z cenników). group — null: karta jest modelem (bez zmian);
|   name_stem: klucz modelu = marka | rodzina z model_regex | rdzeń nazwy bez rozmiarów, wymiarów i słów koloru
|   (App\Services\Enrichment\ProductModelKey) — zadanie idzie do lidera grupy, członkowie dostają jego opis
|   (ApplyModelDescriptionJob). min_members — od ilu kart modelu w partii lider dostaje notę modelu w poleceniu;
| - resolver: klasa PHP dla reguł, których nie da się opisać danymi (na razie żadna).
| brand_keys — klucze marki jak w manufacturer_domains (małe litery, myślniki).
*/
return [
    'default' => [
        'identity_in' => ['url', 'title', 'markup'],
        'code' => ['normalize' => 'upper_alnum', 'min_length' => 4],
        'model_alias_is_key' => false,
        'model' => ['group' => null, 'min_members' => 2],
        'resolver' => null,
    ],
    'profiles' => [
        'coba' => [
            'brand_keys' => ['coba', 'coba-europe'],
            'identity_in' => ['url', 'title', 'markup', 'text'],
            // pomiar 07.10.2026 na 182 poprawnie opisanych kartach: bez „-5” i „C” (oraz data-part w mikrodanych)
            // twardy werdykt miało 84,6%, z nimi 94,5% — reszta to strony bez kodu karty albo innego wariantu
            'code' => ['normalize' => 'upper_alnum', 'model_regex' => '/^([A-Z]+)\d/', 'dash_suffix_pad' => 2, 'variant_suffixes' => ['C']],
            // 869 kart cennika to ~258 modeli (08.10.2026): każdy wymiar i kolor osobną kartą, strona coba.com jedna na model
            'model' => ['group' => 'name_stem', 'min_members' => 2],
        ],
        'cederroth' => [
            'brand_keys' => ['cederroth'],
            'identity_in' => ['url', 'title', 'markup', 'text'],
            // numery REF 4-cyfrowe („REF: 1882”) — z etykietą tylko w adresie i tytule (SourceIdentity::shortCodeVerdict)
            'labelled_short_codes' => true,
        ],
        'mapa' => [
            'brand_keys' => ['mapa'],
            'identity_in' => ['url', 'title', 'markup', 'text'],
            'model_alias_is_key' => true,
        ],
        // pros.pl / sportpros.pl / bemoregreen.eu: kod to krótki numer modelu („103”), na stronie „model 103” w adresie
        // i tytule, w mikrodanych głównego wyrobu sam numer albo z nazwą witryny („BEMOREGREEN-901”) — reguła krótkiego
        // kodu z etykietą i mikrodanych na hoście producenta (SourceIdentity::shortCodeVerdict)
        'aj-group' => [
            'brand_keys' => ['aj-group', 'ajgroup', 'pros'],
            'labelled_short_codes' => true,
            // kod kombinacji PrestaShop w mikrodanych: model, numer koloru i rozmiar („SB01 STRONG-00113-39”,
            // „741-00005”) — bez końcówki to kod modelu
            'code' => ['combination_suffix' => '/-\d{5}(?:-[^-]*)?$/'],
        ],
    ],
];
