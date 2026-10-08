<?php

declare(strict_types=1);

/*
| Znane zaślepki zapisane jako poprawny obraz — bramka typu MIME i wymiarów ich nie łapie (App\Services\Enrichment\
| ProductImageDownloader: downloadOne i storeBytes, także zdjęcia z łączników B2B). Suma kontrolna = sha256 bajtów
| pliku, ta sama co product_images.checksum. Każdy wpis z komentarzem: skąd plik i na których kartach był.
*/
return [
    'checksums' => [
        // „404 Not Found / nginx” jako PNG 1280×1280 z fachhandel.pl i wz-narzedzia.pl (imagecache 680x680) — produkcja
        // 08.10.2026: karty HR Matting 10799, 10801, 10804, 10805, 10806 (audyt Coby 08.10.2026)
        'c057d3c30474558a036ea52ac00cc1cb3814ec41dd40b40ed419f5403d5365b0',
    ],
];
