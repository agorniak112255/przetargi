<?php

declare(strict_types=1);

namespace App\Services\B2b;

use RuntimeException;

/**
 * Uzupełnianie opisu karty zatrzymane przyciskiem „Zatrzymaj” w trakcie pracy joba (B2bDescriptionSupplement::stop) —
 * job przerywa pracę na najbliższym etapie i niczego nie zapisuje.
 */
final class B2bSupplementCancelled extends RuntimeException {}
