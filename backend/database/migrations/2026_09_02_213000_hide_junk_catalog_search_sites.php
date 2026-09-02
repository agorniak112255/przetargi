<?php

declare(strict_types=1);

use App\Models\CatalogHost;
use App\Models\CatalogPage;
use App\Models\CatalogSearchSite;
use App\Models\CatalogSearchSiteExclusion;
use App\Models\ManufacturerSite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const HOSTS = [
        '3m.com',
        '3mpolska.pl',
        'honeywell.com',
        'sps.honeywell.com',
        'kaufland.pl',
        'd3nan4w00fsv2d.cloudfront.net',
        'd3rbxgeqn1ye9j.cloudfront.net',
        'agnes-ai.com',
        'app.agnes-ai.com',
        'sir.ezdrowie.gov.pl',
        'media.uvex.de',
        'whirlpool.com',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('catalog_search_site_exclusions')) {
            return;
        }

        foreach (self::HOSTS as $raw) {
            $host = ManufacturerSite::normalizeHost($raw);
            if ($host === '') {
                continue;
            }
            $aliases = array_values(array_unique([$host, 'www.'.$host]));
            if (Schema::hasTable('catalog_pages')) {
                CatalogPage::query()->whereIn('host', $aliases)->delete();
            }
            if (Schema::hasTable('catalog_hosts')) {
                CatalogHost::query()->whereIn('host', $aliases)->delete();
            }
            if (Schema::hasTable('catalog_search_sites')) {
                CatalogSearchSite::query()->whereIn('host', $aliases)->delete();
            }
            if (Schema::hasTable('manufacturer_sites')) {
                ManufacturerSite::query()->whereIn('host', $aliases)->delete();
            }
            CatalogSearchSiteExclusion::remember($host);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('catalog_search_site_exclusions')) {
            return;
        }

        $hosts = [];
        foreach (self::HOSTS as $raw) {
            $host = ManufacturerSite::normalizeHost($raw);
            if ($host !== '') {
                $hosts[] = $host;
            }
        }
        CatalogSearchSiteExclusion::query()->whereIn('host', $hosts)->delete();
    }
};
