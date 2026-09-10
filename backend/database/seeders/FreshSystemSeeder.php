<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CatalogSearchSite;
use App\Models\CatalogSearchSiteExclusion;
use App\Models\ManufacturerSite;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

final class FreshSystemSeeder extends Seeder
{
    /** @var list<string> */
    private const JUNK_HOSTS = [
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

    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->seedAdmin();
        $this->seedSearchHosts();
        $this->seedJunkExclusions();
    }

    private function seedAdmin(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'artur@supon.rzeszow.pl'],
            [
                'name' => 'Artur',
                'password' => 'password',
                'role' => 'admin',
            ]
        );
        $user->syncPrimaryRole('admin');
    }

    private function seedSearchHosts(): void
    {
        if (! Schema::hasTable('catalog_search_sites')) {
            return;
        }

        foreach ($this->configHosts() as $host) {
            CatalogSearchSite::query()->firstOrCreate(
                ['host' => $host],
                ['source' => 'config']
            );
        }
    }

    private function seedJunkExclusions(): void
    {
        if (! Schema::hasTable('catalog_search_site_exclusions')) {
            return;
        }

        foreach (self::JUNK_HOSTS as $raw) {
            $host = ManufacturerSite::normalizeHost($raw);
            if ($host === '') {
                continue;
            }
            CatalogSearchSiteExclusion::remember($host);
            CatalogSearchSite::query()->whereIn('host', [$host, 'www.'.$host])->delete();
        }
    }

    /**
     * @return list<string>
     */
    private function configHosts(): array
    {
        $raw = [
            ...(array) config('enrichment.retailer_domains', []),
            ...(array) config('enrichment.preferred_domains', []),
        ];
        foreach ((array) config('enrichment.catalog_search_hosts', []) as $hosts) {
            if (is_array($hosts)) {
                $raw = [...$raw, ...$hosts];
            }
        }
        foreach ((array) config('enrichment.manufacturer_domains', []) as $hosts) {
            if (is_array($hosts)) {
                $raw = [...$raw, ...$hosts];
            }
        }

        $out = [];
        foreach ($raw as $domain) {
            if (! is_string($domain)) {
                continue;
            }
            $host = ManufacturerSite::normalizeHost($domain);
            if ($host !== '') {
                $out[$host] = $host;
            }
        }

        return array_values($out);
    }
}
