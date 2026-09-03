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
        $this->assertFalse($svc->isSpecificRequirement('Czapka drelichowa'));
        $this->assertFalse($svc->isSpecificRequirement('Nauszniki'));
        $this->assertFalse($svc->isSpecificRequirement('Ochronniki słuchu'));
        $this->assertFalse($svc->isSpecificRequirement(
            'Ochronniki słuchu na hełm MSA - niski poziom tłumienia'
        ));
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
        $this->assertTrue($svc->isSpecificRequirement('Buty robocze z metalowymi noskami'));
    }

    public function test_msa_earmuff_query_does_not_invent_hard_constraints_from_soft_words(): void
    {
        $svc = $this->service();
        $ref = new \ReflectionMethod(ProductAiSearchService::class, 'fallbackConstraints');
        $constraints = $ref->invoke(
            $svc,
            'Ochronniki słuchu na hełm MSA - niski poziom tłumienia'
        );

        $this->assertSame([], $constraints);
    }
}
