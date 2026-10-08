<?php

declare(strict_types=1);

/*
| Usuwanie tła ze zdjęć kart: rembg (MIT) w Dockerze na serwerze aplikacji, tylko 127.0.0.1 (wdrożone 08.10.2026:
| danielgatis/rembg:2.0.85, --cpus=8 --memory=16g). Model ZAWSZE jawnie — domyślny bria-rmbg wymaga płatnej
| licencji do użytku komercyjnego. birefnet-general-lite (MIT): ok. 20 s na zdjęcie; birefnet-general nie mieści się
| w 16 GB. Zapytania po jednym naraz (przy 8 GB kontener padał po każdym zdjęciu — proces ~8,3 GB).
*/
return [
    'url' => rtrim((string) env('IMAGE_BACKGROUND_URL', 'http://127.0.0.1:7000'), '/'),
    'model' => (string) env('IMAGE_BACKGROUND_MODEL', 'birefnet-general-lite'),
    'timeout_seconds' => (int) env('IMAGE_BACKGROUND_TIMEOUT', 300),
    // dłuższy bok wysyłanego zdjęcia — model liczy maskę w 1024 px, większe wejście zjada tylko pamięć kontenera
    'max_side' => (int) env('IMAGE_BACKGROUND_MAX_SIDE', 1500),
    // bramka wyniku: odsetek nieprzezroczystych pikseli; poza zakresem oryginał zostaje
    'min_coverage_percent' => 1.0,
    'max_coverage_percent' => 99.0,
];
