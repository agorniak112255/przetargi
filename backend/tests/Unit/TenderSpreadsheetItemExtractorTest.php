<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\JsonResponseParser;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\CurrencyDetector;
use App\Services\TenderSpreadsheetItemExtractor;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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

    public function test_merges_all_sheets_with_names_and_norms(): void
    {
        $path = sys_get_temp_dir().'/siwz_sheets_'.uniqid('', true).'.xlsx';
        $book = new Spreadsheet;
        $odziez = $book->getActiveSheet();
        $odziez->setTitle('Odzież');
        $odziez->fromArray([
            ['ZAŁĄCZNIK NR 1a - Odzież'],
            ['Lokalizacja Tarnów'],
            ['L.p.', 'Nazwa asortymentu', 'Normy', 'j.m.', 'Szacunkowe ilości roczne', 'Cena jednostkowa zł/j.m.'],
            ['1.', 'CZAPKA KOMINIARKA Z POLARU', 'EN ISO 13688', 'szt', '10', ''],
            ['2.', 'KURTKA OCHRONNA', 'EN 342', 'szt', '5', ''],
        ]);

        $obuwie = $book->createSheet();
        $obuwie->setTitle('Obuwie');
        $obuwie->fromArray([
            ['ZAŁĄCZNIK - Obuwie'],
            ['Lokalizacja Tarnów'],
            ['L.p.', 'Nazwa asortymentu', 'Normy', 'Szacunkowe ilości roczne', 'Cena jednostkowa zł/para'],
            ['1.', 'BUTY gumowe DAMSKIE', 'EN ISO 20347', '15', ''],
        ]);

        $sprzet = $book->createSheet();
        $sprzet->setTitle('Sprzęt');
        $sprzet->fromArray([
            ['ZAŁĄCZNIK - Sprzęt'],
            ['Lokalizacja Tarnów'],
            ['L.p.', 'Nazwa asortymentu', 'Szacunkowe ilości roczne', 'Cena jednostkowa zł/szt'],
            ['1.', 'Maska 3S BASIS PLUS MSA', '50', ''],
            ['2.', 'Gaśnica śniegowa 5kg', '12', ''],
        ]);

        (new Xlsx($book))->save($path);

        $llm = (new ReflectionClass(OpenAiCompatibleClient::class))->newInstanceWithoutConstructor();
        $extractor = new TenderSpreadsheetItemExtractor($llm, new JsonResponseParser, new CurrencyDetector);
        $result = $extractor->extract($path, false);
        @unlink($path);

        $this->assertNotNull($result);
        $this->assertCount(5, $result['items']);
        $this->assertStringContainsString('Scalono 3 arkusze', $result['notes']);

        $byName = [];
        foreach ($result['items'] as $item) {
            $byName[$item['name']] = $item;
        }
        $this->assertArrayHasKey('CZAPKA KOMINIARKA Z POLARU', $byName);
        $this->assertArrayHasKey('BUTY gumowe DAMSKIE', $byName);
        $this->assertArrayHasKey('Maska 3S BASIS PLUS MSA', $byName);
        $this->assertArrayHasKey('Gaśnica śniegowa 5kg', $byName);

        $cap = $byName['CZAPKA KOMINIARKA Z POLARU'];
        $this->assertNull($cap['sku']);
        $this->assertSame('EN ISO 13688', $cap['norms']);
        $this->assertSame(10, $cap['quantity']);
        $this->assertStringContainsString('EN ISO 13688', $cap['requirement']);

        $this->assertSame('EN ISO 20347', $byName['BUTY gumowe DAMSKIE']['norms']);
        $this->assertNull($byName['Maska 3S BASIS PLUS MSA']['norms']);
        $this->assertSame(50, $byName['Maska 3S BASIS PLUS MSA']['quantity']);
    }
}
