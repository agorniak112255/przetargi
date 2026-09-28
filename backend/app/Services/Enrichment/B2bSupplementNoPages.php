<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

/**
 * Uzupełnianie krótkiego opisu B2B nie znalazło żadnej potwierdzonej strony wyrobu w sieci — karta zostaje z opisem
 * z B2B (próba ze statusem no_pages).
 */
class B2bSupplementNoPages extends B2bSourcesDescriptionRejected {}
