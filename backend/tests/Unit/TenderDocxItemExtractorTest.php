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

    public function test_attaches_opis_wyrobu_to_product_names(): void
    {
        $path = $this->makeOpzStyleDocx();
        $llm = (new ReflectionClass(OpenAiCompatibleClient::class))->newInstanceWithoutConstructor();
        $extractor = new TenderDocxItemExtractor(
            new TenderSpreadsheetItemExtractor($llm, new JsonResponseParser, new CurrencyDetector)
        );

        $result = $extractor->extract($path, false);
        @unlink($path);

        $this->assertNotNull($result);
        $this->assertCount(2, $result['items']);
        $this->assertSame('1375-141-00020-0', $result['items'][0]['sku']);
        $this->assertSame('KALOSZE BEZPIECZNE Z WKŁADKĄ OCIEPLANĄ', $result['items'][0]['name']);
        $this->assertNotNull($result['items'][0]['description']);
        $this->assertStringContainsString('Terminator S5', (string) $result['items'][0]['description']);
        $this->assertStringContainsString('Terminator S5', $result['items'][0]['requirement']);
        $this->assertStringContainsString('PODESZWA', (string) $result['items'][1]['description']);
        $this->assertStringContainsString('SRC', (string) $result['items'][1]['description']);
    }

    private function makeOfferDocx(): string
    {
        return $this->writeDocx([
            [
                ['L.p.', 'Przedmiot zamówienia', 'J.m.', 'Ilość', 'Cena jednostkowa netto (PLN)', 'Łączna wartość netto (PLN)'],
                ['1', '2', '3', '4', '5', '6 (4 x 5)'],
                ['1', 'Rękawice 5-palcowe wzmacniane DRAGON RDR', 'par', '7 000', '4,10', '28 700,00'],
                ['2', 'Rękawice ocieplane DRAGON WINTER RWD', 'par', '360', '11,00', '3 960,00'],
                ['', 'SUMA NETTO', '', '', '', '32 660,00'],
            ],
        ]);
    }

    private function makeOpzStyleDocx(): string
    {
        return $this->writeDocx([
            [
                ['LP', 'INDEKS', 'NAZWA PRODUKTU'],
                ['1', '1375-141-00020-0', 'KALOSZE BEZPIECZNE Z WKŁADKĄ OCIEPLANĄ'],
                ['2', '2221-196-00010-0', 'TRZEWIKI DLA KIEROWCÓW'],
            ],
            [
                ['Lp.', 'Opis wyrobu'],
                ['1', "KALOSZE BEZPIECZNE Z WKŁADKĄ OCIEPLANĄ (INDEKS: 1375-141-00020-0)\nKalosz bezpieczny Terminator S5 ATF z wkładem ocieplającym."],
                ['2', "TRZEWIKI DLA KIEROWCÓW (S1P/S3) (INDEKS: 2221-196-00010-0)\nPODESZWA:\nnieprzemakalna od podłoża, antypoślizgowa - klasa SRC."],
            ],
        ]);
    }

    /**
     * @param  list<list<list<string>>>  $tables
     */
    private function writeDocx(array $tables): string
    {
        $body = '';
        foreach ($tables as $rows) {
            $tbl = '';
            foreach ($rows as $row) {
                $tbl .= '<w:tr>';
                foreach ($row as $cell) {
                    $tbl .= '<w:tc>';
                    foreach (preg_split('/\R/u', $cell) ?: [$cell] as $line) {
                        $safe = htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                        $tbl .= '<w:p><w:r><w:t>'.$safe.'</w:t></w:r></w:p>';
                    }
                    $tbl .= '</w:tc>';
                }
                $tbl .= '</w:tr>';
            }
            $body .= '<w:tbl>'.$tbl.'</w:tbl>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'.$body.'<w:sectPr/></w:body></w:document>';

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
