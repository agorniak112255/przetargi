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
}
