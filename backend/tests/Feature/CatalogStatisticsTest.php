<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Enrichment\CatalogSitemapIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CatalogStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_statistics_refresh_is_skipped_outside_mysql(): void
    {
        // ANALYZE TABLE to składnia MySQL — na sqlite ma się nie wysypać
        app(CatalogSitemapIndexer::class)->refreshTableStatistics();

        $this->assertTrue(true);
    }

    public function test_index_command_finishes_with_statistics_refresh(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->artisan('catalog:index', ['host' => 'nieznany-sklep.pl', '--seconds' => 30])
            ->expectsOutputToContain('Zaindeksowano');
    }
}
