<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogSearchSite;
use App\Models\CatalogSearchSiteExclusion;
use App\Models\User;
use App\Support\CatalogSlangDictionary;
use Database\Seeders\FreshSystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class FreshSystemSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_system_loads_admin_and_search_hosts(): void
    {
        $this->seed(FreshSystemSeeder::class);

        $user = User::query()->where('email', 'artur@supon.rzeszow.pl')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->hasRole('admin'));

        $this->assertGreaterThan(40, CatalogSearchSite::query()->count());
        $this->assertTrue(CatalogSearchSite::hasHost('optimumbhp.pl'));
        $this->assertTrue(CatalogSearchSiteExclusion::hasHost('kaufland.pl'));
        $this->assertFalse(CatalogSearchSite::hasHost('kaufland.pl'));
        $this->assertNotSame([], CatalogSlangDictionary::defaults());
    }
}
