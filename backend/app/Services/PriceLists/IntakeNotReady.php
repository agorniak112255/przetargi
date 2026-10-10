<?php

declare(strict_types=1);

namespace App\Services\PriceLists;

use RuntimeException;

/** Cennik nie jest gotowy do podglądu/importu nowym sposobem: brak importera albo klasy importera nie ma we wdrożeniu. */
final class IntakeNotReady extends RuntimeException {}
