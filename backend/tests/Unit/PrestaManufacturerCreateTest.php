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

final class PrestaManufacturerCreateTest extends TestCase
{
    private string $sqlitePath;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->sqlitePath = $dir.DIRECTORY_SEPARATOR.'presta-manufacturer.sqlite';
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
        $schema->create('ps_manufacturer', function (Blueprint $table): void {
            $table->increments('id_manufacturer');
            $table->string('name', 64);
            $table->dateTime('date_add');
            $table->dateTime('date_upd');
            $table->tinyInteger('active');
        });
        $schema->create('ps_manufacturer_lang', function (Blueprint $table): void {
            $table->unsignedInteger('id_manufacturer');
            $table->unsignedInteger('id_lang');
            $table->text('description')->nullable();
            $table->text('short_description')->nullable();
            $table->primary(['id_manufacturer', 'id_lang']);
        });
        $schema->create('ps_manufacturer_shop', function (Blueprint $table): void {
            $table->unsignedInteger('id_manufacturer');
            $table->unsignedInteger('id_shop');
            $table->primary(['id_manufacturer', 'id_shop']);
        });
        $schema->create('ps_lang', function (Blueprint $table): void {
            $table->unsignedInteger('id_lang')->primary();
            $table->tinyInteger('active')->default(1);
        });
        $schema->create('ps_shop', function (Blueprint $table): void {
            $table->unsignedInteger('id_shop')->primary();
            $table->tinyInteger('active')->default(1);
        });
        DB::connection('prestashop')->table('ps_lang')->insert(['id_lang' => 1, 'active' => 1]);
        DB::connection('prestashop')->table('ps_shop')->insert(['id_shop' => 1, 'active' => 1]);
    }

    protected function tearDown(): void
    {
        DB::purge('prestashop');
        if (isset($this->sqlitePath) && is_file($this->sqlitePath)) {
            @unlink($this->sqlitePath);
        }
        parent::tearDown();
    }

    public function test_creates_manufacturer_in_db_when_api_hits_htmlpurifier(): void
    {
        Http::fake(function ($request) {
            if (! str_contains($request->url(), '/api/manufacturers')) {
                return Http::response('unexpected '.$request->url(), 404);
            }

            return Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<prestashop><errors><error><code><![CDATA[15]]></code>'
                .'<message><![CDATA[[PHP Unknown error #8192] Creation of dynamic property HTMLPurifier_Language::$error]]></message>'
                .'</error></errors></prestashop>',
                500
            );
        });
        $id = $this->client()->resolveManufacturerId('Nowy Brand');
        $this->assertSame(1, $id);
        $this->assertSame('Nowy Brand', (string) DB::connection('prestashop')->table('ps_manufacturer')->where('id_manufacturer', 1)->value('name'));
        $this->assertTrue(DB::connection('prestashop')->table('ps_manufacturer_shop')->where('id_manufacturer', 1)->where('id_shop', 1)->exists());
    }

    public function test_reuses_manufacturer_from_shop_db(): void
    {
        Http::fake();
        DB::connection('prestashop')->table('ps_manufacturer')->insert([
            'name' => 'Ansell',
            'date_add' => '2026-01-01 00:00:00',
            'date_upd' => '2026-01-01 00:00:00',
            'active' => 1,
        ]);
        $this->assertSame(1, $this->client()->resolveManufacturerId('ansell'));
        Http::assertNothingSent();
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
