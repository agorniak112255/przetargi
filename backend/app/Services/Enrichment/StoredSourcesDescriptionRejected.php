<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use RuntimeException;

/**
 * Opis z zapisanych źródeł karty (ProductEnrichmentService::describeFromStoredSources, etap 1 — tryb cienia)
 * odrzucony; powód w komunikacie. Karta zostaje bez zmian — ten opis i tak niczego nie zapisuje.
 */
class StoredSourcesDescriptionRejected extends RuntimeException {}
