<?php

declare(strict_types=1);

use App\Services\Enrichment\PartsTable\CobaPartsTable;

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
| - image_url_blocklist: wyrażenia regularne (cały adres) grafik witryny producenta, które nie są zdjęciem wyrobu —
|   obok wzorców ogólnych App\Support\ImageUrlBlocklist (logo, banner, placeholder…); adres odpada z listy zdjęć strony
|   i z pobierania;
| - code.alt_forms: inne zapisy kodu karty do drugiej próby na hostach producenta marki „tylko producent”
|   (App\Services\Enrichment\ManufacturerCodeForms: letter_suffix „SBA01B” → „SBA01”, dash_suffix „SB01-J” → „SB01”,
|   trailing_words „071 STRAŻ” → „071”, leading_zeros „0071” → „71”); strona znaleziona innym zapisem jest najwyżej
|   „soft” (etap 3 opisów z cenników);
| - code.longest_code_wins: najdłuższy kod z katalogu marki decyduje (App\Services\Enrichment\CardCodeArbiter) —
|   strona, zdjęcie albo plik z kodem innej karty marki, który wydłuża nasz („1011” → „1011 R”), albo bez naszego kodu
|   przy kodzie innej karty („102” przy „1102”) nie jest źródłem karty; na hoście producenta także etykietowany kod
|   („REF 6943” przy 310366, „Indeks: S565A202” przy S565E202);
| - code.index_label: etykieta pola kodu w treści strony producenta („Indeks: T5912200” na securabc.com) — pierwsze
|   takie pole równe kodowi karty daje twardy werdykt (SourceIdentity::judgePage, „field”);
| - code.size_letters: oznaczenia rozmiaru w kodzie wyrobu (ManufacturerProfile::sizeSibling) — kod innej karty albo
|   pole „Indeks” różniące się od naszego tylko rozmiarem w tym samym miejscu (S56T0SL0 ↔ S56T0SM0) to ten sam model:
|   nie „strona innego wyrobu”, tylko „soft” (strona modelu w innym rozmiarze);
| - resolver: klasa PHP dla reguł, których nie da się opisać danymi — dziś tabela części na stronie producenta
|   (App\Services\Enrichment\PartsTable\PartsTableResolver; coba: CobaPartsTable). Karta cennika przypięta do wiersza
|   tabeli (dokładny kod albo skrót cennika po kolorze i rozmiarze) bierze opis wyłącznie z tej strony, bez wyszukiwarki;
|   wiersze zapisuje products:parts-table --brand=<marka> --refresh (tabela manufacturer_parts);
| - parts_table: page_prefix — początek adresu stron z tabelą części (strony z catalog_pages), page_overrides — kod
|   części => slug strony, gdy kod stoi na kilku stronach z różnymi tabelami i człowiek wskazał właściwą.
| brand_keys — klucze marki jak w manufacturer_domains (małe litery, myślniki).
*/
return [
    'default' => [
        'identity_in' => ['url', 'title', 'markup'],
        'code' => ['normalize' => 'upper_alnum', 'min_length' => 4, 'alt_forms' => [], 'longest_code_wins' => false, 'index_label' => null],
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
            // grafiki reklamowe coba.com na kartach po audycie 08.10.2026 („Stand up for health”, słoń z okna modalnego)
            'image_url_blocklist' => ['/StandUpforHealth/i', '/Modal_Elephant/i'],
            // przypięcie po tabeli części coba.com (decyzja właściciela 09.10.2026): pomiar na 869 kartach cennika 14 —
            // 680 kodów dokładnie w tabeli (z duplikatami stron jak hygimat / hygimat-2), 70 skrótów po kolorze i rozmiarze;
            // 10 kodów stoi na stronach z różnymi tabelami (Fatigue-Step krawędź B1, Ringmat) — przypięcie dopiero przez
            // page_overrides; 109 bez strony zostaje bez zmian
            'resolver' => CobaPartsTable::class,
            'parts_table' => ['page_prefix' => 'https://www.coba.com/pl/produkt/', 'page_overrides' => []],
        ],
        'cederroth' => [
            'brand_keys' => ['cederroth'],
            'identity_in' => ['url', 'title', 'markup', 'text'],
            // numery REF 4-cyfrowe („REF: 1882”) — z etykietą tylko w adresie i tytule (SourceIdentity::shortCodeVerdict)
            'labelled_short_codes' => true,
            // audyt 08.10.2026: strona REF 6943 przy 310366, PDF 51011003 przy 51011013, zdjęcie 7200 przy 510110414
            'code' => ['longest_code_wins' => true],
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
            'code' => [
                'combination_suffix' => '/-\d{5}(?:-[^-]*)?$/',
                // audyt 08.10.2026: SBA01B, WRA02B, SB01-J, 071 STRAŻ — pros.pl ma stronę modelu w innym zapisie kodu
                'alt_forms' => ['letter_suffix', 'dash_suffix', 'trailing_words'],
                // 1011 ↔ 1011 R, 104/1 ↔ 104/1 OC, SB04 AIR ↔ SB04 AIR CARP, 102 ↔ 1102 (audyt 08.10.2026)
                'longest_code_wins' => true,
            ],
        ],
        // securabc.com: kod wyrobu tylko w polu „Indeks: T5912200” treści strony (w mikrodanych „sku” to numer wpisu)
        'secura' => [
            'brand_keys' => ['secura'],
            'identity_in' => ['url', 'title', 'markup'],
            // półmaska SECURA 3000 S/M/L = S56T0SS0/SM0/SL0, a strona securabc.com jedna („20-41-secura-3000.html”,
            // rozmiary S, M, L, „Indeks S56T0SM0”) — audyt SECURA 08.10.2026
            'code' => ['longest_code_wins' => true, 'index_label' => 'Indeks', 'size_letters' => ['XS', 'S', 'M', 'L', 'XL']],
        ],
        // specshop/bolle-safety: filtr B9V opisany ze strony przyłbicy FLASHV (audyt Bolle 08.10.2026)
        'bolle' => [
            'brand_keys' => ['bolle', 'bolle-safety'],
            'code' => ['longest_code_wins' => true],
        ],
    ],
];
