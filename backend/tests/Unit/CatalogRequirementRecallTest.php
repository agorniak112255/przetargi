<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CatalogRequirementRecall;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CatalogRequirementRecallTest extends TestCase
{
    private CatalogRequirementRecall $recall;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recall = app(CatalogRequirementRecall::class);
    }

    #[Test]
    public function antistatic_gloves_query_builds_profile(): void
    {
        $this->assertTrue($this->recall->shouldBackfillCatalog('Rękawice antyelektrostatyczne'));
        $this->assertTrue($this->recall->shouldRecallToCandidatePool('Okulary ochronne UV'));
    }
}
