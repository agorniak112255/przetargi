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

    public function test_visual_default_keeps_export_following_card(): void
    {
        $layout = EnrichmentDescriptionLayouts::normalize([
            'inherit_card' => true,
            'inherit_export' => true,
        ], true);

        $this->assertFalse($layout['inherit_card']);
        $this->assertTrue($layout['inherit_export']);
    }

    public function test_resolve_uses_fallback_when_inheriting(): void
    {
        $fallback = EnrichmentDescriptionLayouts::defaultStoredLayout();
        $fallback['card'][0]['emphasis'] = 'accent';
        $family = EnrichmentDescriptionLayouts::defaultLayout(true);
        $family['card'][0]['emphasis'] = 'highlight';

        $resolved = EnrichmentDescriptionLayouts::resolve($family, $fallback);
        $this->assertSame('accent', $resolved['card'][0]['emphasis']);
        $this->assertSame('accent', $resolved['export'][0]['emphasis']);
    }

    public function test_export_follows_custom_card_when_export_inherits(): void
    {
        $card = EnrichmentDescriptionLayouts::defaultBlocks('card');
        $card[1]['emphasis'] = 'highlight';
        $card[3]['emphasis'] = 'accent';
        $layout = [
            'inherit_card' => false,
            'inherit_export' => true,
            'card' => $card,
            'export' => EnrichmentDescriptionLayouts::defaultBlocks('export'),
        ];

        $resolved = EnrichmentDescriptionLayouts::resolve($layout, EnrichmentDescriptionLayouts::defaultStoredLayout());
        $attrs = collect($resolved['export'])->firstWhere('id', 'attributes');
        $features = collect($resolved['export'])->firstWhere('id', 'features');
        $this->assertSame('highlight', $attrs['emphasis'] ?? null);
        $this->assertSame('accent', $features['emphasis'] ?? null);
        $this->assertNotContains('documents', array_column($resolved['export'], 'id'));
    }

    public function test_stock_export_follows_custom_card_even_without_flag(): void
    {
        $card = EnrichmentDescriptionLayouts::defaultBlocks('card');
        $card[1]['emphasis'] = 'highlight';
        $layout = [
            'inherit_card' => false,
            'inherit_export' => false,
            'card' => $card,
            'export' => EnrichmentDescriptionLayouts::defaultBlocks('export'),
        ];

        $resolved = EnrichmentDescriptionLayouts::resolve($layout, EnrichmentDescriptionLayouts::defaultStoredLayout());
        $attrs = collect($resolved['export'])->firstWhere('id', 'attributes');
        $this->assertSame('highlight', $attrs['emphasis'] ?? null);
    }
}
