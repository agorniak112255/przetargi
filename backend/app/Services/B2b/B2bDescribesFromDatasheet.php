<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik, którego sklep ma krótki opis, a pełną treść wyrobu w karcie katalogowej PDF (Tegro: 1–2 zdania na stronie,
 * poziomy norm, powłoka, wkładka i branże tylko w PDF). Decyzja użytkownika 21.09.2026: opis takiej karty pisze model
 * wyłącznie z dwóch źródeł — opisu ze sklepu i karty katalogowej zapisanej przy karcie — bez niczego z internetu
 * (App\Jobs\DescribeB2bProductFromDatasheetJob, zlecany przez B2bCatalogSync po zapisie plików karty).
 */
interface B2bDescribesFromDatasheet {}
