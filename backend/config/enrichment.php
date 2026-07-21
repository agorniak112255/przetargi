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
    ],
];
