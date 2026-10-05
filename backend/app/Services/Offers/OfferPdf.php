<?php

declare(strict_types=1);

namespace App\Services\Offers;

use App\Models\Offer;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\ProductImageThumbService;
use Barryvdh\DomPDF\Facade\Pdf;
use DOMDocument;
use DOMElement;

/**
 * Oferta jako PDF (forma „pdf” i „both”): ten sam mail co w treści (OfferRenderer — baner, kafelki, układ produktów)
 * z podpisem handlowca, przygotowany pod dompdf. Dompdf nie pobiera niczego przez HTTP (enable_remote wyłączone,
 * a lokalny artisan serve jest jednowątkowy) — baner idzie z pliku w public/, zdjęcia produktów z
 * ProductImageThumbService::squareJpeg jako data URI; obrazek, którego nie da się tak podać, znika. Czcionka DejaVu Sans,
 * bo standardowe czcionki PDF (Arial/Helvetica) nie mają ą, ę, ś. Nie final — testy podmieniają zależności.
 */
class OfferPdf
{
    /** Domyślny baner maila (CampaignRenderer::DEFAULT_BANNER_PATH) — plik w public/. */
    private const BANNER_PATH = 'campaign/supon-header.png';

    private const FONT = "'DejaVu Sans', sans-serif";

    public function __construct(
        private readonly OfferRenderer $renderer,
        private readonly ProductImageThumbService $thumbs,
    ) {}

    /** Bajty PDF bieżącej oferty — pełny mail z produktami niezależnie od zapisanej formy. */
    public function render(Offer $offer, User $viewer): string
    {
        $mail = $this->renderer->render($offer, $viewer, null, true, 'body');

        return Pdf::loadHTML($this->forDompdf($mail['html']))
            ->setPaper('a4', 'portrait')
            // tylko użyte znaki czcionki — cała DejaVu Sans to ok. 0,8 MB w każdym pliku
            ->setOption('isFontSubsettingEnabled', true)
            ->output();
    }

    /** „Oferta-OF-0001.pdf” — bez znaków, które psują nazwę pliku albo nagłówek Content-Disposition. */
    public static function filename(Offer $offer): string
    {
        $code = (string) preg_replace('/[^A-Za-z0-9-]+/', '', (string) $offer->code);

        return 'Oferta-'.($code !== '' ? $code : (string) $offer->id).'.pdf';
    }

    /**
     * HTML maila pod dompdf: obrazki jako data URI, DejaVu Sans, biała strona A4 zamiast szarego tła klienta poczty,
     * baner na całą szerokość strony i układ bez zagnieżdżonych tabel (flatten).
     */
    private function forDompdf(string $html): string
    {
        $html = (string) preg_replace_callback('/<img\b[^>]*>/i', fn (array $m): string => $this->image($m[0]), $html);
        // style inline mają Arial — nadpisanie w <style> by nie wygrało, więc podmiana w atrybutach style (nie w treści)
        $html = (string) preg_replace_callback(
            '/\sstyle="[^"]*"/i',
            static fn (array $m): string => (string) preg_replace('/font-family:\s*[^;"]+/i', 'font-family:'.self::FONT, $m[0]),
            $html,
        );
        // szare tło wokół karty maila na papierze tylko marnuje tusz
        $html = str_replace('background:#eef1f3;', 'background:#ffffff;', $html);
        // baner ma w mailu najwyżej 640 px, a karta w PDF jest szersza (plik ma 1280 px — zostaje ostry)
        $html = str_replace('width:100%;max-width:640px;height:auto;', 'width:100%;height:auto;', $html);

        $style = '<style>'
            .'@page { margin: 10mm 8mm; }'
            .'html, body { margin: 0; padding: 0; background: #ffffff; font-family: '.self::FONT.'; }'
            // rząd kafelków (zdjęcie, opis, przyciski) i wiersz cennika nie rozcinają się między stronami
            .'tr { page-break-inside: avoid; }'
            .'</style>';
        $html = str_contains($html, '</head>') ? str_replace('</head>', $style.'</head>', $html) : $style.$html;

        return $this->flatten($html);
    }

    /**
     * Dompdf nie dzieli na strony komórki tabeli wyższej niż strona, w której jest kolejna tabela — po drugiej stronie
     * reszta oferty znika (sprawdzone 05.10.2026: z 40 pozycji cennika PDF pokazał 21, bez podpisu). Mail to tabela
     * w tabeli (otoczka 100% → karta 640 px → wiersz z tabelą produktów), więc w PDF: otoczka znika, a wiersze karty
     * stają się zwykłymi blokami — tabela produktów leży wtedy w zwykłym przepływie i dzieli się między wierszami.
     * W siatce trzy wiersze jednego rzędu kafelków (zdjęcie, opis, przyciski) trzymają się razem.
     */
    private function flatten(string $html): string
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // znaki spoza ASCII jako encje — loadHTML bez deklaracji kodowania czytałby UTF-8 jako Latin-1
        $loaded = $dom->loadHTML(mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'), LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $loaded ? $dom->getElementsByTagName('body')->item(0) : null;
        if (! $body instanceof DOMElement) {
            return $html;
        }

        // otoczka: body > table > tr > td — jej zawartość (informacja o kopii, karta maila) idzie wprost do body
        $wrapper = $this->children($body, 'table')[0] ?? null;
        $cell = $wrapper !== null ? ($this->cells($wrapper)[0] ?? null) : null;
        if ($wrapper !== null && $cell !== null) {
            while ($cell->firstChild !== null) {
                $body->insertBefore($cell->firstChild, $wrapper);
            }
            $body->removeChild($wrapper);
        }
        foreach ($this->children($body, 'table') as $table) {
            $this->tableToBlocks($dom, $table);
        }

        foreach (iterator_to_array($dom->getElementsByTagName('table')) as $table) {
            if (str_contains($table->getAttribute('style'), 'table-layout:fixed')) {
                $this->keepGridRowsTogether($table);
            }
        }

        $out = $dom->saveHTML();

        return is_string($out) && $out !== '' ? $out : $html;
    }

    /** Tabela układu (jedna komórka w wierszu) → blok z jej stylem, a w nim blok na każdą komórkę. */
    private function tableToBlocks(DOMDocument $dom, DOMElement $table): void
    {
        $block = $dom->createElement('div');
        // szerokość z maila (640 / 100%) nie ma znaczenia — blok wypełnia stronę
        $style = (string) preg_replace('/(^|;)\s*(max-)?width:[^;]*/i', '$1', $table->getAttribute('style'));
        if (trim($style, '; ') !== '') {
            $block->setAttribute('style', $style);
        }
        foreach ($this->cells($table) as $cell) {
            $part = $dom->createElement('div');
            $cellStyle = $cell->getAttribute('style');
            $align = strtolower($cell->getAttribute('align'));
            if (in_array($align, ['left', 'center', 'right'], true)) {
                $cellStyle = 'text-align:'.$align.';'.$cellStyle;
            }
            if ($cellStyle !== '') {
                $part->setAttribute('style', $cellStyle);
            }
            while ($cell->firstChild !== null) {
                $part->appendChild($cell->firstChild);
            }
            $block->appendChild($part);
        }
        $table->parentNode?->replaceChild($block, $table);
    }

    /** Siatka: wiersze rzędu kafelków (co trzeci kończy rząd; odstęp między rzędami to wiersz z colspan). */
    private function keepGridRowsTogether(DOMElement $table): void
    {
        $inRow = 0;
        foreach ($this->rows($table) as $tr) {
            $first = $this->children($tr, 'td')[0] ?? null;
            if ($first !== null && $first->hasAttribute('colspan')) {
                // odstęp przed rzędem też trzyma się rzędu — strona łamie się przed nim, nie przed ramkami kafelków
                $inRow = 0;
                $tr->setAttribute('style', trim($tr->getAttribute('style').';page-break-after:avoid', ';'));

                continue;
            }
            $inRow++;
            if ($inRow % 3 !== 0) {
                $tr->setAttribute('style', trim($tr->getAttribute('style').';page-break-after:avoid', ';'));
            }
        }
    }

    /** @return list<DOMElement> wiersze tabeli (wprost albo w tbody/thead) */
    private function rows(DOMElement $table): array
    {
        $rows = [];
        foreach ($this->children($table) as $child) {
            if ($child->tagName === 'tr') {
                $rows[] = $child;
            } elseif (in_array($child->tagName, ['tbody', 'thead', 'tfoot'], true)) {
                array_push($rows, ...$this->children($child, 'tr'));
            }
        }

        return $rows;
    }

    /** @return list<DOMElement> komórki wszystkich wierszy po kolei */
    private function cells(DOMElement $table): array
    {
        $cells = [];
        foreach ($this->rows($table) as $tr) {
            array_push($cells, ...$this->children($tr, 'td'));
        }

        return $cells;
    }

    /** @return list<DOMElement> */
    private function children(DOMElement $parent, ?string $tag = null): array
    {
        $out = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && ($tag === null || $node->tagName === $tag)) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /** Znacznik <img> z adresem zamienionym na data URI albo '' (obrazek nieznany albo niedostępny). */
    private function image(string $tag): string
    {
        if (preg_match('/\ssrc="([^"]*)"/i', $tag, $m) !== 1) {
            return '';
        }
        $src = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        $data = $this->dataUri($src);

        return $data === null ? '' : str_replace($m[0], ' src="'.$data.'"', $tag);
    }

    private function dataUri(string $src): ?string
    {
        // renderer składa adresy z publicznego adresu aplikacji (może mieć podkatalog, np. http://localhost/Przetargi)
        $base = rtrim((string) config('campaigns.public_url'), '/');
        if ($base === '' || ! str_starts_with($src, $base.'/')) {
            return null;
        }
        $path = substr($src, strlen($base));
        if ($path === '/'.self::BANNER_PATH) {
            $file = public_path(self::BANNER_PATH);
            $bytes = is_file($file) ? file_get_contents($file) : false;

            return is_string($bytes) && $bytes !== '' ? 'data:image/png;base64,'.base64_encode($bytes) : null;
        }
        if (preg_match('#^/api/product-images/(\d+)/square$#', $path, $m) === 1) {
            $image = ProductImage::query()->find((int) $m[1]);
            $jpeg = $image !== null ? $this->thumbs->squareJpeg($image) : null;

            return $jpeg !== null ? 'data:image/jpeg;base64,'.base64_encode($jpeg) : null;
        }

        return null;
    }
}
