<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\JsonResponseParser;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\CurrencyDetector;
use App\Services\TenderDocxItemExtractor;
use App\Services\TenderSpreadsheetItemExtractor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ZipArchive;

final class TenderDocxItemExtractorTest extends TestCase
{
    public function test_extracts_items_from_offer_form_table(): void
    {
        $path = $this->makeOfferDocx();
        $llm = (new ReflectionClass(OpenAiCompatibleClient::class))->newInstanceWithoutConstructor();
        $extractor = new TenderDocxItemExtractor(
            new TenderSpreadsheetItemExtractor($llm, new JsonResponseParser, new CurrencyDetector)
        );

        $result = $extractor->extract($path, false);
        @unlink($path);

        $this->assertNotNull($result);
        $this->assertGreaterThanOrEqual(2, count($result['items']));
        $this->assertStringContainsString('DRAGON', $result['items'][0]['name']);
        $this->assertSame(7000, $result['items'][0]['quantity']);
        $this->assertEqualsWithDelta(4.10, (float) $result['items'][0]['offer_price'], 0.001);
    }

    private function makeOfferDocx(): string
    {
        $rows = [
            ['L.p.', 'Przedmiot zamówienia', 'J.m.', 'Ilość', 'Cena jednostkowa netto (PLN)', 'Łączna wartość netto (PLN)'],
            ['1', '2', '3', '4', '5', '6 (4 x 5)'],
            ['1', 'Rękawice 5-palcowe wzmacniane DRAGON RDR', 'par', '7 000', '4,10', '28 700,00'],
            ['2', 'Rękawice ocieplane DRAGON WINTER RWD', 'par', '360', '11,00', '3 960,00'],
            ['', 'SUMA NETTO', '', '', '', '32 660,00'],
        ];
        $tbl = '';
        foreach ($rows as $row) {
            $tbl .= '<w:tr>';
            foreach ($row as $cell) {
                $safe = htmlspecialchars($cell, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $tbl .= '<w:tc><w:p><w:r><w:t>'.$safe.'</w:t></w:r></w:p></w:tc>';
            }
            $tbl .= '</w:tr>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body><w:tbl>'.$tbl.'</w:tbl><w:sectPr/></w:body></w:document>';

        $path = sys_get_temp_dir().'/offer_'.uniqid('', true).'.docx';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>');
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return $path;
    }
}
