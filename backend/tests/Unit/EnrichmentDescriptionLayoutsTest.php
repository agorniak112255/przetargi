<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\EnrichmentDescriptionLayouts;
use Tests\TestCase;

final class EnrichmentDescriptionLayoutsTest extends TestCase
{
    public function test_normalize_keeps_order_and_fills_missing_blocks(): void
    {
        $layout = EnrichmentDescriptionLayouts::normalize([
            'inherit_card' => false,
            'card' => [
                ['id' => 'norms', 'visible' => true, 'emphasis' => 'highlight'],
                ['id' => 'description', 'visible' => false, 'emphasis' => 'nope'],
            ],
        ], false);

        $this->assertFalse($layout['inherit_card']);
        $this->assertTrue($layout['inherit_export']);
        $this->assertSame('norms', $layout['card'][0]['id']);
        $this->assertSame('highlight', $layout['card'][0]['emphasis']);
        $this->assertSame('description', $layout['card'][1]['id']);
        $this->assertFalse($layout['card'][1]['visible']);
        $this->assertSame('none', $layout['card'][1]['emphasis']);
        $this->assertContains('documents', array_column($layout['card'], 'id'));
        $this->assertNotContains('documents', array_column($layout['export'], 'id'));
    }

    public function test_visual_default_cannot_inherit(): void
    {
        $layout = EnrichmentDescriptionLayouts::normalize([
            'inherit_card' => true,
            'inherit_export' => true,
        ], true);

        $this->assertFalse($layout['inherit_card']);
        $this->assertFalse($layout['inherit_export']);
    }

    public function test_resolve_uses_fallback_when_inheriting(): void
    {
        $fallback = EnrichmentDescriptionLayouts::defaultStoredLayout();
        $fallback['card'][0]['emphasis'] = 'accent';
        $family = EnrichmentDescriptionLayouts::defaultLayout(true);
        $family['card'][0]['emphasis'] = 'highlight';

        $resolved = EnrichmentDescriptionLayouts::resolve($family, $fallback);
        $this->assertSame('accent', $resolved['card'][0]['emphasis']);
    }
}
