<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use RuntimeException;

/** Opis z opisu sklepu i karty PDF odrzucony — karta zostaje z tekstem ze sklepu; powód w komunikacie. */
final class B2bSourcesDescriptionRejected extends RuntimeException {}
