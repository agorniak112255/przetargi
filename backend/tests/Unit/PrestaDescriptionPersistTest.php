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

final class PrestaDescriptionPersistTest extends TestCase
{
    private string $sqlitePath;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->sqlitePath = $dir.DIRECTORY_SEPARATOR.'presta-description.sqlite';
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
        $schema->create('ps_product_lang', function (Blueprint $table): void {
            $table->unsignedInteger('id_product');
            $table->unsignedInteger('id_lang');
            $table->unsignedInteger('id_shop')->default(1);
            $table->text('description')->nullable();
            $table->text('description_short')->nullable();
        });
        DB::connection('prestashop')->table('ps_product_lang')->insert([
            'id_product' => 5682,
            'id_lang' => 1,
            'id_shop' => 1,
            'description' => '<p>bez tla</p>',
            'description_short' => 'stary',
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

    public function test_update_writes_highlight_html_into_product_lang(): void
    {
        Http::fake(function ($request) {
            if (strtoupper($request->method()) === 'GET') {
                return Http::response($this->productGetXml(), 200);
            }

            return Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><prestashop><product><id>5682</id></product></prestashop>',
                200
            );
        });

        $html = '<table bgcolor="#fef3c7"><tr><td bgcolor="#fef3c7">Cechy</td></tr></table>';
        $this->client()->updateProduct(5682, [
            'name' => 'HyFlex',
            'description' => $html,
            'description_short' => 'krotki',
            'delivery_label' => 'Na zamówienie',
            'link_rewrite' => 'hyflex',
        ]);

        $stored = (string) DB::connection('prestashop')->table('ps_product_lang')
            ->where('id_product', 5682)
            ->value('description');
        $this->assertStringContainsString('bgcolor="#fef3c7"', $stored);
        $this->assertSame('krotki', (string) DB::connection('prestashop')->table('ps_product_lang')
            ->where('id_product', 5682)
            ->value('description_short'));
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

    private function productGetXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<prestashop xmlns:xlink="http://www.w3.org/1999/xlink"><product>'
            .'<id>5682</id>'
            .'<name><language id="1"><![CDATA[Stary]]></language></name>'
            .'<description><language id="1"><![CDATA[]]></language></description>'
            .'<description_short><language id="1"><![CDATA[]]></language></description_short>'
            .'<available_now><language id="1"><![CDATA[]]></language></available_now>'
            .'<available_later><language id="1"><![CDATA[]]></language></available_later>'
            .'<delivery_in_stock><language id="1"><![CDATA[]]></language></delivery_in_stock>'
            .'<delivery_out_stock><language id="1"><![CDATA[]]></language></delivery_out_stock>'
            .'</product></prestashop>';
    }
}
