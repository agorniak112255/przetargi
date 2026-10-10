<?php

declare(strict_types=1);

namespace App\Services\PriceLists;

use RuntimeException;

/** Import tego cennika już trwa (blokada price-list-intake:{id}) — kontroler odpowiada 409. */
final class IntakeBusy extends RuntimeException {}
