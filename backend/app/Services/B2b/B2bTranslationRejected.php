<?php

declare(strict_types=1);

namespace App\Services\B2b;

use RuntimeException;

/**
 * Tłumaczenie odrzucone przez walidację (np. zgubiona albo dopisana liczba, norma, kod; inna liczba segmentów).
 * Karta zostaje z tekstem źródła — nie jest to błąd połączenia z modelem.
 */
final class B2bTranslationRejected extends RuntimeException {}
