<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\JsonResponseParser;
use PHPUnit\Framework\TestCase;

final class JsonResponseParserTest extends TestCase
{
    public function test_parses_markdown_fenced_json(): void
    {
        $parser = new JsonResponseParser;
        $raw = "Oto wynik:\n```json\n{\"products\":[{\"sku\":\"A\"}]}\n```\n";
        $json = $parser->parse($raw);
        $this->assertSame('A', $json['products'][0]['sku']);
    }

    public function test_parses_trailing_comma(): void
    {
        $parser = new JsonResponseParser;
        $json = $parser->parse('{"notes":"ok","products":[],}');
        $this->assertSame('ok', $json['notes']);
    }

    public function test_recovers_truncated_products_json(): void
    {
        $parser = new JsonResponseParser;
        $raw = '{"manufacturer_detected":"JS GLOVES","currency":"PLN","notes":"test","products":['
            .'{"sku":"ROC5","name":"Rekawice","catalog_price":11.00,"discount":24,"purchase":8.36},'
            .'{"sku":"ROC5V","name":"Rekawice PVC","catalog_price":11.70,"discount":24,';

        $json = $parser->parse($raw);
        $this->assertSame('JS GLOVES', $json['manufacturer_detected']);
        $this->assertGreaterThanOrEqual(1, count($json['products']));
        $this->assertSame('ROC5', $json['products'][0]['sku']);
    }
}
