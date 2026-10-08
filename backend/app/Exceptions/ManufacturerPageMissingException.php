<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Marka „tylko od producenta” (enrichment.manufacturer_only_sources), a strony producenta nie znaleziono — ani
 * w pierwszej puli, ani w drugiej próbie na jego hostach z innymi zapisami kodu (etap 3 opisów z cenników, 08.10.2026).
 * Sklepy nie są źródłem, więc model nie jest wołany: karta „manual” z powodem przeglądu manufacturer_missing
 * (Product::REVIEW_MANUFACTURER_MISSING), handlowiec wskazuje adres strony producenta albo opisuje ręcznie.
 * Awaria wyszukiwarki to nie ten wyjątek — ta kończy się zwykłym ProductSourcesNotFoundException („failed”).
 */
final class ManufacturerPageMissingException extends ProductSourcesNotFoundException {}
