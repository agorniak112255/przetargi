<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('PRESTA_ENABLED', false),
    'host' => env('PRESTA_DB_HOST', ''),
    'port' => (int) env('PRESTA_DB_PORT', 3306),
    'database' => env('PRESTA_DB_DATABASE', ''),
    'username' => env('PRESTA_DB_USERNAME', ''),
    'password' => env('PRESTA_DB_PASSWORD', ''),
    'prefix' => env('PRESTA_TABLE_PREFIX', 'ps_'),
    'id_lang' => (int) env('PRESTA_ID_LANG', 1),
    'shop_url' => env('PRESTA_SHOP_URL', 'https://supon.rzeszow.pl'),
    'webservice_key' => env('PRESTA_WEBSERVICE_KEY', ''),
    'id_category_default' => (int) env('PRESTA_ID_CATEGORY_DEFAULT', 2),
    'delivery_label' => env('PRESTA_DELIVERY_LABEL', 'Na zamówienie'),
];
