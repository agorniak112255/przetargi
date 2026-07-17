<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\JsonResponseParser;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\CurrencyDetector;
use App\Services\TenderSpreadsheetItemExtractor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TenderSpreadsheetItemExtractorTest extends TestCase
{
    public function test_extracts_article_name_and_special_price_from_csv(): void
    {
        $path = sys_get_temp_dir().'/tender_siwz_'.uniqid('', true).'.csv';
        $csv = implode("\n", [
            'Client;Name of project;Article;Name of Article;Current special price;Price increase;Special price from 1st of June',
            'PHT SUPON;LOT AMS;34115188;VITAL 115 FSC;€ 0,69;0%;€ 0,69',
            'PHT SUPON;PKP;34117178;VITAL 117 FSC;€ 2,50;5%;€ 2,63',
        ]);
        file_put_contents($path, $csv);

        $llm = (new ReflectionClass(OpenAiCompatibleClient::class))->newInstanceWithoutConstructor();
        $extractor = new TenderSpreadsheetItemExtractor($llm, new JsonResponseParser, new CurrencyDetector);
        $result = $extractor->extract($path, false);

        @unlink($path);

        $this->assertNotNull($result);
        $this->assertGreaterThanOrEqual(2, count($result['items']));
        $this->assertSame('34115188', $result['items'][0]['sku']);
        $this->assertSame('VITAL 115 FSC', $result['items'][0]['name']);
        $this->assertEqualsWithDelta(0.69, (float) $result['items'][0]['offer_price'], 0.001);
        $this->assertSame('34117178', $result['items'][1]['sku']);
        $this->assertEqualsWithDelta(2.63, (float) $result['items'][1]['offer_price'], 0.001);
    }
}
