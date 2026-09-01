<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ProductAiSearchService;
use Tests\TestCase;

final class ProductAiSearchSpecificityTest extends TestCase
{
    private function service(): ProductAiSearchService
    {
        return $this->app->make(ProductAiSearchService::class);
    }

    public function test_bare_article_name_is_not_specific(): void
    {
        $svc = $this->service();

        $this->assertFalse($svc->isSpecificRequirement('kombinezon'));
        $this->assertFalse($svc->isSpecificRequirement('kalosze'));
        $this->assertFalse($svc->isSpecificRequirement('gogle'));
        $this->assertFalse($svc->isSpecificRequirement('CZAPKA KOMINIARKA Z POLARU czarna lub granatowa'));
        $this->assertFalse($svc->isSpecificRequirement('kombinezon roboczy'));
    }

    public function test_hazard_or_norm_makes_requirement_specific(): void
    {
        $svc = $this->service();

        $this->assertTrue($svc->isSpecificRequirement('Kombinezon chemoodporny na kwas siarkowy 96%'));
        $this->assertTrue($svc->isSpecificRequirement('kombinezon EN 13034'));
        $this->assertTrue($svc->isSpecificRequirement('kombinezon chemoodporny'));
        $this->assertTrue($svc->isSpecificRequirement('KOMINIARKA ANTYELEKTROSTATYCZNA'));
        $this->assertTrue($svc->isSpecificRequirement('gogle polaryzacyjne'));
        $this->assertTrue($svc->isSpecificRequirement('kombinezon na kwas'));
    }
}
