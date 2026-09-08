<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Presta\PrestaSettingsService;
use App\Services\Presta\PrestaShopExportClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PrestaCombinationSyncTest extends TestCase
{
    private string $sqlitePath;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->sqlitePath = $dir.DIRECTORY_SEPARATOR.'presta-combinations.sqlite';
        if (is_file($this->sqlitePath)) {
            unlink($this->sqlitePath);
        }
        touch($this->sqlitePath);
        config([
            'database.connections.prestashop' => [
                'driver' => 'sqlite',
                'database' => $this->sqlitePath,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('prestashop');
        $schema = Schema::connection('prestashop');
        $schema->create('ps_product_attribute', function (Blueprint $table): void {
            $table->increments('id_product_attribute');
            $table->unsignedInteger('id_product');
        });
        $schema->create('ps_product_attribute_combination', function (Blueprint $table): void {
            $table->unsignedInteger('id_product_attribute');
            $table->unsignedInteger('id_attribute');
            $table->primary(['id_product_attribute', 'id_attribute']);
        });
        DB::connection('prestashop')->table('ps_product_attribute')->insert([
            'id_product_attribute' => 55,
            'id_product' => 5738,
        ]);
        DB::connection('prestashop')->table('ps_product_attribute_combination')->insert([
            'id_product_attribute' => 55,
            'id_attribute' => 9,
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('prestashop');
        if (isset($this->sqlitePath) && is_file($this->sqlitePath)) {
            @unlink($this->sqlitePath);
        }
        parent::tearDown();
    }

    public function test_empty_sizes_delete_leftover_combinations(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'DELETE' && str_contains($request->url(), '/api/combinations/55')) {
                return Http::response('', 200);
            }

            return Http::response('unexpected '.$request->method().' '.$request->url(), 404);
        });

        $this->client()->ensureCombinations(5738, []);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/api/combinations/55');
        });
        Http::assertSentCount(1);
    }

    public function test_keeps_wanted_size_and_deletes_extra(): void
    {
        DB::connection('prestashop')->table('ps_product_attribute')->insert([
            'id_product_attribute' => 56,
            'id_product' => 5738,
        ]);
        DB::connection('prestashop')->table('ps_product_attribute_combination')->insert([
            'id_product_attribute' => 56,
            'id_attribute' => 72,
        ]);

        Http::fake(function ($request) {
            if ($request->method() === 'DELETE' && str_contains($request->url(), '/api/combinations/55')) {
                return Http::response('', 200);
            }

            return Http::response('unexpected '.$request->method().' '.$request->url(), 404);
        });

        $this->client()->ensureCombinations(5738, [
            ['size' => '8', 'attribute_id' => 72, 'reference' => 'SKU-8'],
        ]);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/api/combinations/55');
        });
        Http::assertSentCount(1);
    }

    private function client(): PrestaShopExportClient
    {
        config([
            'prestashop.enabled' => true,
            'prestashop.host' => '127.0.0.1',
            'prestashop.port' => 3306,
            'prestashop.database' => $this->sqlitePath,
            'prestashop.username' => 'x',
            'prestashop.password' => 'y',
            'prestashop.webservice_key' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345',
            'prestashop.prefix' => 'ps_',
            'prestashop.id_lang' => 1,
            'prestashop.shop_url' => 'https://shop.test',
        ]);

        return new PrestaShopExportClient(app(PrestaSettingsService::class));
    }
}
