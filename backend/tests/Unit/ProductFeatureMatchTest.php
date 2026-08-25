<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProductFeatureMatch;
use PHPUnit\Framework\TestCase;

final class ProductFeatureMatchTest extends TestCase
{
    private ProductFeatureMatch $features;

    protected function setUp(): void
    {
        parent::setUp();
        $this->features = new ProductFeatureMatch;
    }

    public function test_grammage_is_read_from_every_notation(): void
    {
        $this->assertSame([250], $this->features->grammages('spodnie o gramatrzurze 250gr'));
        $this->assertSame([250], $this->features->grammages('przy gramaturze 250 g/m².'));
        $this->assertSame([250], $this->features->grammages('tkanina 250 gsm'));
        $this->assertSame([250], $this->features->grammages('gramatura: 250'));
        $this->assertSame([250], $this->features->grammages('250g/m2'));
    }

    public function test_grammage_ignores_values_outside_textile_range(): void
    {
        $this->assertSame([], $this->features->grammages('opakowanie 5 g'));
        $this->assertSame([], $this->features->grammages('worek 5000 g'));
    }

    public function test_requirement_and_card_meet_despite_different_notation(): void
    {
        $overlap = $this->features->overlap(
            'spodnie o gramatrzurze 250gr',
            'Spodnie robocze CXS STRETCH, przy gramaturze 250 g/m², EN 13688.'
        );

        $this->assertSame(1, $overlap['grammage']);
    }

    public function test_different_grammage_does_not_count(): void
    {
        $overlap = $this->features->overlap(
            'spodnie 250 g/m2',
            'Spodnie robocze z poliestru, gramatura 300 g/m².'
        );

        $this->assertSame(0, $overlap['grammage']);
    }

    public function test_norms_match_regardless_of_iso_and_year(): void
    {
        $overlap = $this->features->overlap(
            'kamizelka EN ISO 20471 klasa 2',
            'Kamizelka ostrzegawcza zgodna z EN20471:2013.'
        );

        $this->assertSame(1, $overlap['norms']);
    }

    public function test_no_features_in_requirement_gives_zero_overlap(): void
    {
        $overlap = $this->features->overlap('spodnie robocze', 'Spodnie 250 g/m² EN 13688');

        $this->assertSame(['grammage' => 0, 'norms' => 0], $overlap);
    }
}
