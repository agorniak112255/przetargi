<?php

declare(strict_types=1);

/*
 * Normy producenta ze stron WWW (plan norm z 23.09.2026, etap 3 — polecenie norms:from-manufacturer-pages).
 *
 * `site_search`: wyszukiwarka na witrynie producenta, gdy adresu karty wyrobu nie ma przy karcie ani w indeksie map
 * stron. Klucz = BrandKey::of(producent z karty). `links` = układ listy wyników (magento: odnośniki z klasą
 * product-item-link / product-item-photo). Strona z wyników przechodzi potem tę samą bramkę tożsamości co każda inna.
 */
return [
    'site_search' => [
        // Canis/CXS (23.09.2026): indeks map stron ma tylko kategorie, a wyszukiwarka zwraca kartę po kodzie „3210-012”.
        'canis' => ['host' => 'cxs.net.pl', 'template' => 'https://cxs.net.pl/catalogsearch/result/?q={q}', 'links' => 'magento'],
        'cxs' => ['host' => 'cxs.net.pl', 'template' => 'https://cxs.net.pl/catalogsearch/result/?q={q}', 'links' => 'magento'],
    ],

    // Witryny za zaporą (Ansell: Incapsula) — gdy zwykłe pobranie trafi na zaporę, strona idzie przez reader jako
    // markdown (BlockedPageReader::fetchMarkdown, wspólna kolejka readera z wzbogacaniem).
    'reader_hosts' => ['ansell.com'],

    // Ile stron z wyników wyszukiwarki producenta sprawdzić na jedną kartę.
    'max_pages_per_card' => 4,

    // Odstęp między zapytaniami do tego samego hosta (ms) — polecenie chodzi po witrynach producentów z serwera.
    'host_delay_ms' => 1500,
];
