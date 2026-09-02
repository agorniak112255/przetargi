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

    public function test_rejects_truncated_thought_monologue(): void
    {
        $parser = new JsonResponseParser;
        $raw = '{"thought": "The user wants a complete, closed JSON object. The product is a MICROGARD 1500 coverall. I need to extract all facts and format them into the specified JSO';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API AI nie zwróciło poprawnego JSON');
        $parser->parse($raw);
    }

    public function test_keeps_payload_when_thought_is_only_an_extra_key(): void
    {
        $parser = new JsonResponseParser;
        $json = $parser->parse('{"thought":"ok","description":"Kombinezon AlphaTec 1500","features":["kaptur"]}');
        $this->assertSame('Kombinezon AlphaTec 1500', $json['description']);
    }

    public function test_recovers_truncated_rank_matches_json(): void
    {
        $parser = new JsonResponseParser;
        $raw = '{"matches": [ {"id": 23935, "sku": "MEDIBUT-PRIMA-CLOG-SRC-ESD-MIETOWY",'
            .' "name": "PRIMA CLOG SRC ESD - MIETOWY", "category": "OBUWIE EVA",'
            .' "manufacturer": "MEDIBUT", "norms": "SRC (odpornosc na poslizgi)",'
            .' "heat_celsius": null, "specs": ["Kod produktu: MEDIBUT-PRIMA-CLOG-SRC-ES"';

        $json = $parser->parse($raw);
        $this->assertSame(23935, (int) ($json['matches'][0]['id'] ?? 0));
    }

    public function test_looks_complete_accepts_closed_object(): void
    {
        $parser = new JsonResponseParser;
        $this->assertTrue($parser->looksComplete('{"description":"ok","features":["a"]}'));
        $this->assertFalse($parser->looksComplete('{"description":"buty ochronne S3 z podnoskiem'));
    }
}
