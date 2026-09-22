<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\InquiryReplyHtml;
use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class InquiryReplyHtmlTest extends TestCase
{
    /**
     * Ciemny motyw Thunderbirda podmienia dziedziczony kolor tekstu na jasny,
     * a tła wpisane w znaczniki zostawia białe. Każdy tekst musi więc mieć kolor
     * wpisany w element, który go bezpośrednio zawiera.
     */
    public function test_every_text_has_its_own_color_for_dark_mail_themes(): void
    {
        $html = InquiryReplyHtml::render(
            'Dzień dobry,',
            [[
                'head' => 'Rozmiar 9 288 par',
                'quote' => null,
                'answer' => ['Rękawice FAWA', 'Zastosowanie: lekkie prace montażowe.', 'Zamiennik: Rękawice MAWA'],
                'answer_roles' => ['name', 'body', 'sub_name'],
                'facts' => [
                    'name' => 'Rękawice FAWA z bistorem, rozm. 9',
                    'code' => 'FAWA-9',
                    'norms' => 'EN ISO 21420',
                    'size' => '9',
                    'qty' => '288 par',
                    'price' => '1,86 zł',
                    'total' => '535,68 zł',
                    'total_pln' => 535.68,
                ],
            ]],
            'Dopisek handlowca',
            ['Pozdrawiam'],
            [['label' => 'Termin dostawy', 'value' => '7 dni']],
            ['title' => 'Fwd: RFQ', 'date' => '21.09.2026', 'lines' => ['1. Rozmiar 9 288 par']],
        );

        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NOERROR);

        $uncolored = [];
        foreach ((new DOMXPath($dom))->query('//body//text()[normalize-space()]') ?: [] as $node) {
            $parent = $node instanceof DOMText ? $node->parentNode : null;
            $style = $parent instanceof DOMElement ? $parent->getAttribute('style') : '';
            if (! preg_match('/(?:^|;)\s*color:/', $style)) {
                $uncolored[] = trim((string) $node->textContent);
            }
        }

        $this->assertSame([], $uncolored);
        $this->assertStringContainsString('Rękawice FAWA z bistorem', $html);
        // Szkic listu zajmuje 60% okna, nie całą szerokość.
        $this->assertMatchesRegularExpression('/^<div style="[^"]*width:60%/', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $size, string $qty, string $total, float $totalPln, string $body = 'Lekkie rękawice do prac precyzyjnych.'): array
    {
        return [
            'head' => 'Rozmiar '.$size.' '.$qty,
            'quote' => null,
            'answer' => ['ULTRANE 549 VM', $body],
            'answer_roles' => ['name', 'body'],
            'facts' => [
                'name' => 'ULTRANE 549 VM',
                'norms' => 'EN ISO 21420',
                'size' => $size,
                'qty' => $qty,
                'price' => '1,86 zł',
                'total' => $total,
                'total_pln' => $totalPln,
            ],
        ];
    }

    public function test_sizes_of_one_product_share_one_tile_with_the_description_once(): void
    {
        $html = InquiryReplyHtml::render('Dzień dobry,', [
            $this->row('10', '96 par', '178,56 zł', 178.56),
            $this->row('9', '288 par', '535,68 zł', 535.68),
            $this->row('8', '96 par', '178,56 zł', 178.56),
        ], null, ['Pozdrawiam']);

        $this->assertSame(1, substr_count($html, 'ULTRANE 549 VM'));
        $this->assertSame(1, substr_count($html, 'Lekkie rękawice do prac precyzyjnych.'));
        $this->assertStringContainsString('3 rozmiary · 480 par', $html);
        // cena za parę raz przy nazwie, bo jest ta sama dla wszystkich rozmiarów
        $this->assertSame(1, substr_count($html, '1,86 zł'));
        $this->assertStringContainsString('netto / para', $html);
        $this->assertStringContainsString('535,68 zł', $html);
        $this->assertStringContainsString('892,80 zł', $html);
    }

    public function test_different_description_or_price_keeps_its_own_information(): void
    {
        $other = $this->row('8', '96 par', '200,00 zł', 200.0, 'Inny opis tej samej nazwy.');
        $other['facts']['price'] = '2,08 zł';

        $html = InquiryReplyHtml::render('Dzień dobry,', [
            $this->row('10', '96 par', '178,56 zł', 178.56),
            $other,
        ], null, ['Pozdrawiam']);

        // inny opis = osobny kafel, żadne zdanie nie ginie
        $this->assertSame(2, substr_count($html, 'ULTRANE 549 VM'));
        $this->assertStringContainsString('Inny opis tej samej nazwy.', $html);
        $this->assertStringContainsString('2,08 zł', $html);
        $this->assertStringNotContainsString('rozmiary', $html);
    }

    public function test_mixed_units_are_not_summed(): void
    {
        $html = InquiryReplyHtml::render('Dzień dobry,', [
            $this->row('10', '96 par', '178,56 zł', 178.56),
            $this->row('9', '2 kartony', '535,68 zł', 535.68),
        ], null, ['Pozdrawiam']);

        $this->assertStringContainsString('2 rozmiary', $html);
        $this->assertStringNotContainsString('2 rozmiary ·', $html);
        // ta sama kwota, inna jednostka — cena przy każdym rozmiarze, nie „za parę” dla kartonów
        $this->assertStringNotContainsString('netto / para', $html);
        $this->assertStringContainsString('1,86 zł / para', $html);
        $this->assertStringContainsString('1,86 zł / karton', $html);
    }
}
