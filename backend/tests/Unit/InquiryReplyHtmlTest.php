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
            ['title' => 'Fwd: RFQ', 'date' => '21.09.2026'],
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

    /**
     * Pozycja, dla której nie mamy wyrobu — tak, jak zwraca ją ClientInquiryService::offerRow.
     *
     * @return array<string, mixed>
     */
    private function unmatched(string $quote, string $qty, ?string $size = null): array
    {
        return [
            'head' => 'Poz. 2 — ilość: '.$qty,
            'quote' => $quote,
            'answer' => ['Pozycję potwierdzimy po weryfikacji dostępności i wrócimy z propozycją.'],
            'answer_roles' => ['note'],
            'facts' => ['name' => null, 'size' => $size, 'qty' => $qty, 'price' => null, 'total' => null, 'total_pln' => null],
        ];
    }

    public function test_every_position_has_its_own_tile_and_repeated_description_points_back(): void
    {
        $html = InquiryReplyHtml::render('Dzień dobry,', [
            $this->row('10', '96 par', '178,56 zł', 178.56),
            $this->row('9', '288 par', '535,68 zł', 535.68),
            $this->row('8', '96 par', '178,56 zł', 178.56),
        ], null, ['Pozdrawiam']);

        // każda pozycja klienta stoi osobno, z własnym numerem i wartością
        foreach (['Pozycja 1', 'Pozycja 2', 'Pozycja 3'] as $head) {
            $this->assertStringContainsString($head, $html);
        }
        $this->assertSame(3, substr_count($html, 'ULTRANE 549 VM'));
        $this->assertSame(3, substr_count($html, 'Państwa zapytanie'));
        $this->assertSame(3, substr_count($html, 'Nasza propozycja'));
        // ten sam akapit opisu raz — dalsze pozycje odsyłają do pierwszej
        $this->assertSame(1, substr_count($html, 'Lekkie rękawice do prac precyzyjnych.'));
        $this->assertSame(2, substr_count($html, 'Opis jak w poz. 1.'));
        $this->assertStringContainsString('netto / para', $html);
        $this->assertStringContainsString('535,68 zł', $html);
        // suma wszystkich pozycji i suma par tej samej jednostki
        $this->assertStringContainsString('892,80 zł', $html);
        $this->assertStringContainsString('480 par', $html);
        // rozmiar jest z zapytania klienta, nie potwierdzony w karcie — stoi w bloku klienta,
        // nigdy w naszej propozycji (karta „rozmiar S” z rozmiarem „M-XL” czytała się jak potwierdzenie)
        $this->assertSame(3, substr_count($html, 'Rozmiar: '));
        foreach (array_slice(explode('Nasza propozycja', $html), 1) as $ours) {
            $this->assertStringNotContainsString('Rozmiar', strstr($ours, 'Państwa zapytanie', true) ?: $ours);
        }
    }

    public function test_following_positions_alternate_background_like_a_zebra(): void
    {
        $rows = [
            $this->row('10', '96 par', '178,56 zł', 178.56),
            $this->row('9', '288 par', '535,68 zł', 535.68),
            $this->row('8', '96 par', '178,56 zł', 178.56),
            $this->row('7', '96 par', '178,56 zł', 178.56),
        ];
        $html = InquiryReplyHtml::render('Dzień dobry,', $rows, null, ['Pozdrawiam']);

        preg_match_all('/background:(#ffffff|#ebe7df);color:#1c1917;border:1px solid #e7e5e4;border-radius:14px/', $html, $m);
        $this->assertSame(['#ffffff', '#ebe7df', '#ffffff', '#ebe7df'], $m[1]);
    }

    public function test_different_description_keeps_its_own_text(): void
    {
        $other = $this->row('8', '96 par', '200,00 zł', 200.0, 'Inny opis tej samej nazwy.');
        $other['facts']['price'] = '2,08 zł';

        $html = InquiryReplyHtml::render('Dzień dobry,', [
            $this->row('10', '96 par', '178,56 zł', 178.56),
            $other,
        ], null, ['Pozdrawiam']);

        // inny opis = żadne zdanie nie ginie za odesłaniem do innej pozycji
        $this->assertStringContainsString('Lekkie rękawice do prac precyzyjnych.', $html);
        $this->assertStringContainsString('Inny opis tej samej nazwy.', $html);
        $this->assertStringNotContainsString('Opis jak w poz.', $html);
        $this->assertStringContainsString('2,08 zł', $html);
    }

    public function test_mixed_units_are_not_summed(): void
    {
        $html = InquiryReplyHtml::render('Dzień dobry,', [
            $this->row('10', '96 par', '178,56 zł', 178.56),
            $this->row('9', '2 kartony', '535,68 zł', 535.68),
        ], null, ['Pozdrawiam']);

        // cena w jednostce każdej pozycji, nie „za parę” dla kartonów
        $this->assertStringContainsString('netto / para', $html);
        $this->assertStringContainsString('netto / karton', $html);
        // par i kartonów nie dodajemy
        $this->assertStringContainsString('714,24 zł', $html);
        $this->assertStringNotContainsString('98 ', $html);
    }

    public function test_position_without_our_product_keeps_the_same_tile_and_is_named_in_the_total(): void
    {
        $html = InquiryReplyHtml::render('Dzień dobry,', [
            $this->row('10', '96 par', '178,56 zł', 178.56),
            $this->unmatched('Rękawiczki nitrylowe MedaSept EASYGRIP PURPLE, rozmiar M', '50 opak.', 'M'),
        ], null, ['Pozdrawiam']);

        $this->assertStringContainsString('Pozycja 2', $html);
        $this->assertStringContainsString('Rękawiczki nitrylowe MedaSept EASYGRIP PURPLE', $html);
        $this->assertStringContainsString('Pozycję potwierdzimy po weryfikacji dostępności', $html);
        $this->assertStringContainsString('border:1px dashed', $html);
        $this->assertStringContainsString('cena po weryfikacji', $html);
        // suma mówi, których pozycji nie obejmuje
        $this->assertStringContainsString('178,56 zł', $html);
        $this->assertStringContainsString('bez poz. 2 – jej cenę podamy po weryfikacji', $html);
        // klient nie napisał ilości w cytacie — dopisujemy ją pod jego słowami
        $this->assertStringContainsString('Ilość: 50 opak.', $html);
        // rozmiar jest w cytacie, więc nie stoi drugi raz
        $this->assertStringNotContainsString('Rozmiar: M', $html);
    }

    public function test_letter_without_prices_does_not_promise_a_price_later(): void
    {
        $priced = $this->row('10', '96 par', '178,56 zł', 178.56);
        $priced['facts']['price'] = null;
        $priced['facts']['total'] = null;
        $priced['facts']['total_pln'] = null;

        $html = InquiryReplyHtml::render('Dzień dobry,', [
            $priced,
            $this->unmatched('Rękawice drelichowe EN 388', '200 par'),
        ], null, ['Pozdrawiam']);

        $this->assertStringNotContainsString('cena po weryfikacji', $html);
        $this->assertStringNotContainsString('Razem netto', $html);
    }

    public function test_quantity_from_the_quote_is_not_repeated_and_size_is_not_offered_as_ours(): void
    {
        $row = $this->row('M-XL', '1 szt.', '181,72 zł', 181.72);
        $row['quote'] = 'Szelki bezpieczeństwa P-50mX rozmiar M-XL AB15021 - 1 szt';
        $row['facts']['name'] = 'P-50mX - Szelki bezpieczeństwa - rozmiar S';
        $row['facts']['total'] = '1 395,36 zł';

        $html = InquiryReplyHtml::render('Dzień dobry,', [$row], null, ['Pozdrawiam']);

        // ilość raz — pod naszą ceną; klient napisał ją już w swoim zdaniu
        $this->assertSame(1, substr_count($html, 'Ilość: '));
        // rozmiar tylko w słowach klienta: nasza karta go nie potwierdza
        $this->assertSame(1, substr_count($html, 'M-XL'));
        // kwota nie łamie się w połowie liczby
        $this->assertMatchesRegularExpression('/white-space:nowrap">1 395,36 zł</', $html);
    }
}
