<?php

declare(strict_types=1);

namespace App\Services\Enrichment\Sources;

use RuntimeException;

/**
 * Karta cennika przyjmowanego nowym sposobem (source_policy = map_only) bez strony z mapy importera i bez adresu
 * wskazanego przez człowieka (decyzja właściciela 10.10.2026): opisu z internetu nie pobieramy — bez wyszukiwarki,
 * bez modelu, bez pamięci SKU. Karta trafia do „Do przeglądu” (Product::REVIEW_SOURCE_UNMAPPED), istniejący opis,
 * zdjęcia i pliki zostają (nic nie jest cofane ani kasowane). Celowo nie dziedziczy po ProductSourcesNotFoundException
 * ani ManufacturerPageMissingException — tamte przy pełnym pobraniu cofają albo czyszczą opis.
 */
final class SourceUnmappedException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Brak strony z importera cennika: '.$reason.' — opis z internetu nie jest pobierany, istniejący zostaje. Wskaż adres w „Do przeglądu”.');
    }
}
