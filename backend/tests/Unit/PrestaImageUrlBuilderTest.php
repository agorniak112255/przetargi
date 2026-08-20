<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Presta\PrestaImageUrlBuilder;
use Tests\TestCase;

final class PrestaImageUrlBuilderTest extends TestCase
{
    public function test_splits_image_id_into_presta_filesystem_dir(): void
    {
        $this->assertSame('9/3/2/9', PrestaImageUrlBuilder::filesystemDir(9329));
        $this->assertSame('5', PrestaImageUrlBuilder::filesystemDir(5));
    }

    public function test_builds_filesystem_url_before_pretty_rewrite(): void
    {
        $urls = PrestaImageUrlBuilder::urls(
            'https://www.supon.rzeszow.pl',
            9329,
            'rekawice-do-kwasow-i-detergentow-mapa-alto-258'
        );

        $this->assertSame('https://www.supon.rzeszow.pl/img/p/9/3/2/9/9329.jpg', $urls[0]);
        $this->assertContains(
            'https://www.supon.rzeszow.pl/9329-large_default/rekawice-do-kwasow-i-detergentow-mapa-alto-258.jpg',
            $urls
        );
    }
}
