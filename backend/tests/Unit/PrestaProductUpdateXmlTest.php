<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Presta\PrestaSettingsService;
use App\Services\Presta\PrestaShopExportClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PrestaProductUpdateXmlTest extends TestCase
{
    public function test_update_strips_readonly_manufacturer_name(): void
    {
        $putBody = null;
        Http::fake(function ($request) use (&$putBody) {
            if (strtoupper($request->method()) === 'GET') {
                return Http::response($this->productGetXml(), 200);
            }
            $putBody = (string) $request->body();

            return Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><prestashop><product><id>5682</id></product></prestashop>',
                200
            );
        });

        $this->client()->updateProduct(5682, [
            'name' => 'HyFlex',
            'description' => 'opis',
            'description_short' => 'krotki',
            'delivery_label' => 'Na zamówienie',
            'id_manufacturer' => 12,
            'link_rewrite' => 'hyflex',
        ]);

        $this->assertNotNull($putBody);
        $this->assertStringNotContainsString('manufacturer_name', (string) $putBody);
        $this->assertStringNotContainsString('<quantity>', (string) $putBody);
        $this->assertStringContainsString('<id_manufacturer>12</id_manufacturer>', (string) $putBody);
        $this->assertStringContainsString('<![CDATA[opis]]>', (string) $putBody);
        $this->assertMatchesRegularExpression('/<description>.*id="1".*id="2"/s', (string) $putBody);
    }

    private function client(): PrestaShopExportClient
    {
        config([
            'prestashop.enabled' => true,
            'prestashop.shop_url' => 'https://shop.test',
            'prestashop.webservice_key' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345',
            'prestashop.prefix' => 'ps_',
            'prestashop.id_lang' => 1,
        ]);

        return new PrestaShopExportClient(app(PrestaSettingsService::class));
    }

    private function productGetXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<prestashop xmlns:xlink="http://www.w3.org/1999/xlink"><product>'
            .'<id>5682</id>'
            .'<id_manufacturer>1</id_manufacturer>'
            .'<manufacturer_name><![CDATA[Ansell]]></manufacturer_name>'
            .'<quantity>0</quantity>'
            .'<name><language id="1"><![CDATA[Stary]]></language><language id="2"><![CDATA[Old]]></language></name>'
            .'<description><language id="1"><![CDATA[]]></language><language id="2"><![CDATA[]]></language></description>'
            .'<description_short><language id="1"><![CDATA[]]></language></description_short>'
            .'<available_now><language id="1"><![CDATA[]]></language></available_now>'
            .'<available_later><language id="1"><![CDATA[]]></language></available_later>'
            .'<delivery_in_stock><language id="1"><![CDATA[]]></language></delivery_in_stock>'
            .'<delivery_out_stock><language id="1"><![CDATA[]]></language></delivery_out_stock>'
            .'</product></prestashop>';
    }
}
