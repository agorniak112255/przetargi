<?php

declare(strict_types=1);

return [
    /*
    | Oficjalne domeny producentów — certyfikaty / deklaracje / datasheet PDF.
    | Klucz = znormalizowana nazwa marki (małe litery, myślniki).
    */
    'manufacturer_domains' => [
        'demar' => ['demar.com.pl', 'www.demar.com.pl'],
        'atg' => [
            'atggloves.com',
            'www.atggloves.com',
            'atg-glovesolutions.com',
            'www.atg-glovesolutions.com',
        ],
        // aliasy marek ATG w cennikach / nazwach
        'maxiflex' => [
            'atggloves.com',
            'www.atggloves.com',
            'atg-glovesolutions.com',
            'www.atg-glovesolutions.com',
        ],
        'maxicut' => [
            'atggloves.com',
            'www.atggloves.com',
            'atg-glovesolutions.com',
            'www.atg-glovesolutions.com',
        ],
        'maxidry' => [
            'atggloves.com',
            'www.atggloves.com',
            'atg-glovesolutions.com',
            'www.atg-glovesolutions.com',
        ],
        'ansell' => ['ansell.com', 'www.ansell.com'],
        'delta-plus' => ['delta-plus.com', 'www.delta-plus.com'],
        'delta' => ['delta-plus.com', 'www.delta-plus.com'],
        'uvex' => [
            'uvex-safety.com',
            'www.uvex-safety.com',
            'uvex.com',
            'www.uvex.com',
            'uvex-safety.de',
            'www.uvex-safety.de',
            'media.uvex.de',
            'uvex.de',
            // CDN kart katalogowych / datasheetów
            'd3nan4w00fsv2d.cloudfront.net',
            'd3rbxgeqn1ye9j.cloudfront.net',
        ],
        '3m' => ['3m.com', 'www.3m.com'],
        'honeywell' => ['honeywell.com', 'www.honeywell.com', 'sps.honeywell.com'],
        'msa' => [
            'pl.msasafety.com',
            'msasafety.com',
            'www.msasafety.com',
        ],
        'msa-safety' => [
            'pl.msasafety.com',
            'msasafety.com',
            'www.msasafety.com',
        ],
        'msa-auer' => [
            'pl.msasafety.com',
            'msasafety.com',
            'www.msasafety.com',
        ],
        'portwest' => ['portwest.com', 'www.portwest.com'],
        'coverguard' => ['coverguard.com', 'www.coverguard.com'],
        'singer' => ['singer.fr', 'www.singer.fr'],
        'pros' => ['pros.pl', 'www.pros.pl'],
        'urgent' => [
            'urgent.com.pl',
            'www.urgent.com.pl',
            'urgent.pl',
            'www.urgent.pl',
        ],
        'pilne' => [
            'urgent.com.pl',
            'www.urgent.com.pl',
            'urgent.pl',
            'www.urgent.pl',
        ],
        'kcl' => ['kcl.de', 'www.kcl.de'],
        'rostaing' => ['rostaing.com', 'www.rostaing.com'],
        'gvs' => ['gvs.com', 'www.gvs.com'],
        'weldas' => ['weldaseurope.com', 'www.weldaseurope.com', 'weldas.com', 'www.weldas.com'],
        'mapa' => ['mapa-pro.com', 'www.mapa-pro.com'],
        'cxs' => ['cxs.net.pl', 'www.cxs.net.pl', 'cxs.cz', 'www.cxs.cz', 'canis.cz', 'www.canis.cz'],
        'canis' => ['cxs.net.pl', 'www.cxs.net.pl', 'cxs.cz', 'www.cxs.cz', 'canis.cz', 'www.canis.cz'],
        'medibut' => ['medibut.pl', 'www.medibut.pl'],
        'panther' => ['panther-safety.com', 'www.panther-safety.com'],
        'ejendals' => [
            'ejendals.com',
            'www.ejendals.com',
            'jalas.com',
            'www.jalas.com',
        ],
        'jalas' => [
            'jalas.com',
            'www.jalas.com',
            'ejendals.com',
            'www.ejendals.com',
        ],
        'ardon' => ['ardon.pl', 'www.ardon.pl'],
        'ardon-safety' => ['ardon.pl', 'www.ardon.pl'],
    ],

    /*
    | Sklepy / dystrybutorzy — dobre źródła opisów (nie certyfikatów).
    */
    'retailer_domains' => [
        'icd.pl',
        'supon.rzeszow.pl',
        'sprzetbhp.pl',
        'gvarant.pl',
        'bhponline-24.pl',
        'fasterbhp.pl',
        'bhp-sklep.com.pl',
        'bogarobhp.pl',
        'demar24.pl',
        'roboczystyl.pl',
        'pros.pl',
        'www.pros.pl',
        'optimumbhp.pl',
        'www.optimumbhp.pl',
        // znalezione przez „catalog:index --discover” dla marek bez pokrycia
        'bpbhp.pl',
        'rywal.com.pl',
        'centrumspawalnicze.pl',
        'dobryspaw.pl',
        'centrumelektronarzedzi.pl',
        '3mpolska.pl',
        'glovex.com.pl',
        'rawpol.com',
        'pol-aura.pl',
        'gloves.co.uk',
        'thesafetysupplycompany.co.uk',
        // karty masek i sprzętu MASKPOL, których wyszukiwarki nie indeksują
        'bezpieczni112.pl',
        // sklepy z szerokim katalogiem MSA
        'strefa998.pl',
        'bhp.pl',
        'sklep.arpapol.pl',
        'tmbhp.pl',
        'bhpsupply.pl',
        'marketbhp.pl',
        'balticbhp.pl',
        'esklep.krisbhp.pl',
        'aitbhp.pl',
        'behapownia.pl',
        'kams.com.pl',
        'bhp-gabi.pl',
        'specto.com.pl',
        'kingbhp.pl',
        'filimar.pl',
        'elmar-bhp.pl',
        'sklep.prohaccp.pl',
        'natare.pl',
        'sklep.arsel-bhp.pl',
        'roboczebhp.pl',
    ],

    /*
    | Odstęp między zapytaniami do SearXNG (Google/Qwant liczą ruch z instancji).
    | To jest ochrona przed 429 — nie liczba workerów LLM.
    */
    'search_min_interval' => (float) env('ENRICHMENT_SEARCH_MIN_INTERVAL', 1.5),

    /*
    | Ile produktów naraz szuka stron (kolejka prefetch). Model ma osobną pulę
    | (kolejka enrich, limit z Ustawień AI). Nie podnoś tego do 16 — dostaniesz 429.
    */
    'prefetch_concurrency' => max(1, min(8, (int) env('ENRICHMENT_PREFETCH_CONCURRENCY', 5))),

    /*
    | Domeny pomijane przy „catalog:index” — globalne serwisy korporacyjne mają
    | sitemapy liczone w setkach MB i prawie nic po polsku. Nadal można je
    | zaindeksować, podając host wprost: „artisan catalog:index 3m.com”.
    */
    'catalog_skip_hosts' => [
        '3m.com',
        'honeywell.com',
        'sps.honeywell.com',
    ],

    /*
    | Preferowane domeny przy wyszukiwaniu kart produktu (producent + sklepy).
    */
    'preferred_domains' => [
        'icd.pl',
        'supon.rzeszow.pl',
        'sprzetbhp.pl',
        'gvarant.pl',
        'bhponline-24.pl',
        'fasterbhp.pl',
        'bhp-sklep.com.pl',
        'bogarobhp.pl',
        'atggloves.com',
        'www.atggloves.com',
        'atg-glovesolutions.com',
        'www.atg-glovesolutions.com',
        'ansell.com',
        'www.ansell.com',
        'delta-plus.com',
        'www.delta-plus.com',
        'demar.com.pl',
        'www.demar.com.pl',
        'demar24.pl',
        'roboczystyl.pl',
        'pros.pl',
        'www.pros.pl',
        'optimumbhp.pl',
        'www.optimumbhp.pl',
        'urgent.com.pl',
        'www.urgent.com.pl',
        'pl.msasafety.com',
        'msasafety.com',
        'www.msasafety.com',
        'strefa998.pl',
        'bhp.pl',
        'sklep.arpapol.pl',
        'tmbhp.pl',
        'glovex.com.pl',
        'bhpsupply.pl',
        'marketbhp.pl',
        'balticbhp.pl',
        'esklep.krisbhp.pl',
        'aitbhp.pl',
        'behapownia.pl',
        'kams.com.pl',
        'bhp-gabi.pl',
        'specto.com.pl',
        'kingbhp.pl',
        'filimar.pl',
        'elmar-bhp.pl',
        'sklep.prohaccp.pl',
        'natare.pl',
        'sklep.arsel-bhp.pl',
        'roboczebhp.pl',
        'ardon.pl',
        'www.ardon.pl',
        'ejendals.com',
        'www.ejendals.com',
        'jalas.com',
        'www.jalas.com',
    ],
];
