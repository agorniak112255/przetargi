<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ManufacturerPart;
use App\Models\Product;
use App\Services\Enrichment\ManufacturerProfile;
use App\Services\Enrichment\PartsTable\CobaPartsTable;
use Tests\TestCase;

/**
 * Tabela części coba.com i przypięcie kart cennika Coby (09.10.2026): parser na przyciętych kopiach prawdziwych stron
 * (tests/Fixtures/pages/coba-parts, pobrane 09.10.2026) i reguły z measure.py / resolve_short.py — dokładny kod, skrót
 * cennika po rozmiarze, strona kanoniczna duplikatów, różne tabele, zdjęcie stylu w kolorze albo zdjęcie modelu.
 */
final class CobaPartsTableTest extends TestCase
{
    private const PREFIX = 'https://www.coba.com/pl/produkt/';

    private const UPLOADS = 'https://www.coba.com/pl/wp-content/uploads/sites/6/';

    public function test_parses_parts_table_rows_title_and_style_in_row_colour(): void
    {
        $page = $this->parser()->parse($this->fixture('orthomat'), self::PREFIX.'orthomat');

        $this->assertSame('Orthomat® Standard', $page['title']);
        $this->assertTrue($page['has_styles']);
        $this->assertCount(18, $page['rows']);
        $rows = array_column($page['rows'], null, 'part');
        $this->assertSame([
            'part' => 'AF010706',
            'label' => 'AF010706',
            'size' => '1,2 m x 18,3 m',
            'colour' => 'Czarny/Żółty',
            'weight_kg' => 70.0,
            'model_image' => self::UPLOADS.'2020/02/af-orthomat-standard-workplace-matting-black-1.jpg',
            'style_image' => self::UPLOADS.'2020/02/af-orthomat-standard-workplace-matting-style-safety-2-750x750.jpg',
        ], $rows['AF010706']);
        $this->assertSame(self::UPLOADS.'2020/02/af-orthomat-standard-workplace-matting-style-grey-3-750x750.jpg', $rows['AF060001']['style_image']);
        $this->assertSame('1,2 m x metr bieżący', $rows['AF060005C']['size']);
        $this->assertSame(58.55, $rows['AF060005']['weight_kg']);
    }

    public function test_parses_pages_without_styles_and_with_lowercase_colours(): void
    {
        $hygimat2 = $this->parser()->parse($this->fixture('hygimat-2'), self::PREFIX.'hygimat-2');
        $this->assertFalse($hygimat2['has_styles']);
        $this->assertSame(['HYS010003', 'HYS010002', 'HYS010001'], array_column($hygimat2['rows'], 'part'));
        $this->assertSame(1.85, $hygimat2['rows'][2]['weight_kg']);
        $this->assertNull($hygimat2['rows'][2]['style_image']);

        $edges = $this->parser()->parse($this->fixture('fatigue-step-edges-b1'), self::PREFIX.'fatigue-step-edges-b1');
        $this->assertSame('Fatigue-Step Krawędź B1', $edges['title']);
        $this->assertSame(['SS010002B1F', 'SS010002B1M', 'SS070002B1F', 'SS070002B1M'], array_column($edges['rows'], 'part'));
        $this->assertSame('75 mm x 1 m', $edges['rows'][0]['size']);
        $this->assertSame('żółty', $edges['rows'][3]['colour']);

        $deckstep = $this->parser()->parse($this->fixture('deckstep'), self::PREFIX.'deckstep');
        $this->assertCount(30, $deckstep['rows']);
        $this->assertTrue($deckstep['has_styles']);
    }

    /** Sekcja stylów bez stylu w kolorze wiersza („Czarny”) i z dwoma stylami „czarny/żółty” (GP1, GP2) — zdjęcie modelu. */
    public function test_row_without_exactly_one_matching_style_keeps_model_image(): void
    {
        $page = $this->parser()->parse($this->fixture('cablepro-gp'), self::PREFIX.'cablepro-gp');

        $this->assertTrue($page['has_styles']);
        $rows = array_column($page['rows'], null, 'part');
        $this->assertNull($rows['CP010006']['style_image'], 'brak stylu „czarny”');
        $this->assertNull($rows['CP010702']['style_image'], 'dwa style czarny/żółty (GP1, GP2) — niejednoznaczne');
        $this->assertSame(self::UPLOADS.'2022/10/af-cablepro-gp1-floor-level-safety-accessories-1.jpg', $rows['CP010702']['model_image']);
    }

    /** „Czarny/Żółty” w wierszu = „czarny i żółty” w stylu (ten sam zbiór kolorów), „Szary” ≠ „Szary/Czarny”. */
    public function test_style_matches_by_colour_set_when_label_differs(): void
    {
        $html = '<html><body><h1>Mata</h1><div class="mt-16 mb-4"><h3>Dostępne style</h3></div><div class="gallery">'
            .'<div><a href="https://www.coba.com/s/yb.jpg"><img src="x"></a><span class="small-text">czarny i żółty</span></div>'
            .'<div><a href="https://www.coba.com/s/gb.jpg"><img src="x"></a><span class="small-text">szary/czarny</span></div>'
            .'</div><table id="parts-table">'
            .'<tr><td data-label="Numer części">AB0107</td><td data-label="Rozmiar">0,6 m x 0,9 m</td><td data-label="Kolor">Czarny/Żółty</td>'
            .'<td data-label="Waga (kg)">1,5</td><td data-label="Zapytaj"><a productimage="https://www.coba.com/m/model.jpg">?</a></td></tr>'
            .'<tr><td data-label="Numer części">AB0106</td><td data-label="Rozmiar">0,6 m x 0,9 m</td><td data-label="Kolor">Szary</td>'
            .'<td data-label="Waga (kg)">1.5</td><td data-label="Zapytaj"><a productimage="https://www.coba.com/m/model.jpg">?</a></td></tr>'
            .'</table></body></html>';

        $page = $this->parser()->parse($html, self::PREFIX.'mata');

        $this->assertSame('https://www.coba.com/s/yb.jpg', $page['rows'][0]['style_image']);
        $this->assertSame(1.5, $page['rows'][0]['weight_kg']);
        $this->assertNull($page['rows'][1]['style_image']);
        $this->assertSame('https://www.coba.com/m/model.jpg', $page['rows'][1]['model_image']);
    }

    public function test_exact_code_pins_row_with_style_image_and_spec_lines(): void
    {
        $result = $this->parser()->pinFor($this->card('AF010706C', 'Orthomat Standard Czarny/Żółte krawędzie 1.2m x mb. (9.5mm)'), $this->profile(), $this->rows('orthomat'));

        $this->assertTrue($result->resolved());
        $pin = $result->pin;
        $this->assertSame('coba', $pin->brandKey);
        $this->assertSame(self::PREFIX.'orthomat', $pin->pageUrl);
        $this->assertSame('orthomat', $pin->pageKey);
        $this->assertSame('AF010706C', $pin->part);
        $this->assertFalse($pin->viaShortCode);
        $this->assertSame('style', $pin->imageReason);
        $this->assertSame(self::UPLOADS.'2020/02/af-orthomat-standard-workplace-matting-style-safety-2-750x750.jpg', $pin->imageUrl);
        $this->assertSame(['Numer części: AF010706C', 'Rozmiar: 1,2 m x metr bieżący', 'Kolor: Czarny/Żółty', 'Waga: 2,1 kg'], $pin->specLines());
        $this->assertSame(['verdict' => 'hard', 'reason' => 'tabela części coba.com: AF010706C', 'key_type' => 'manufacturer_code', 'key' => 'AF010706C', 'where' => 'text'], $pin->identity());
        $this->assertSame('orthomat', $pin->payload()['page_key']);
        $this->assertStringContainsString(self::PREFIX.'orthomat', $pin->promptNote());
    }

    public function test_short_price_list_code_resolves_by_size_from_card_name(): void
    {
        $rows = $this->rows('orthomat', 'deckstep', 'hygimat');

        $edge = $this->parser()->pinFor($this->card('AF0107', 'Orthomat Standard Czarny/Żółte krawędzie 1.2m x 18.3m (9.5mm)'), $this->profile(), $rows);
        $this->assertTrue($edge->resolved());
        $this->assertSame('AF010706', $edge->pin->part);
        $this->assertTrue($edge->pin->viaShortCode);
        $this->assertSame('Waga: 70 kg', $edge->pin->specLines()[3]);
        $this->assertSame('tabela części coba.com: AF010706 (skrót cennika AF0107)', $edge->pin->identity()['reason']);

        // „00” na końcu: bez krawędzi żółtych (07) — AF010705 nie istnieje, AF010706 odpada mimo rozmiaru 1,2 x 18,3
        $plain = $this->parser()->pinFor($this->card('AF0100', 'Orthomat Standard Czarny 1.2m x 18.3m (9.5mm)'), $this->profile(), $rows);
        $this->assertSame('AF010005', $plain->pin?->part);
    }

    public function test_short_code_with_several_candidates_or_wrong_size_or_no_family_stays_unpinned(): void
    {
        $rows = $this->rows('orthomat', 'deckstep');

        $several = $this->parser()->pinFor($this->card('DS0106', 'DeckStep Matting Czarny ~0.59m/0.6m x mb (11.5mm)'), $this->profile(), $rows);
        $this->assertFalse($several->resolved());
        $this->assertSame(CobaPartsTable::REASON_SEVERAL, $several->unresolvedReason);
        $this->assertSame(['DS010610', 'DS010610C'], $several->candidates);

        // na coba.com DS010610 to „0,59 m x metr bieżący”, cennik ma rolkę 10 m
        $size = $this->parser()->pinFor($this->card('DS0106', 'DeckStep Matting Czarny ~0.59m/0.6m x 10m (11.5mm)'), $this->profile(), $rows);
        $this->assertSame(CobaPartsTable::REASON_SIZE, $size->unresolvedReason);
        $this->assertSame(['DS010610', 'DS010610C'], $size->candidates);

        $family = $this->parser()->pinFor($this->card('HR0100', 'HR Matting Czarny 0.9m x 1.5m'), $this->profile(), $rows);
        $this->assertSame(CobaPartsTable::REASON_NO_FAMILY, $family->unresolvedReason);

        $pattern = $this->parser()->pinFor($this->card('PROM', 'Stojak promocyjny'), $this->profile(), $rows);
        $this->assertSame(CobaPartsTable::REASON_NO_PATTERN, $pattern->unresolvedReason);
    }

    /**
     * Produkcja 10.10: strona senso-dial nie odpowiedziała przy --refresh, a pełny kod SN060002 (Senso Dial 10 mm)
     * dopasował się jak skrót po samym rozmiarze do Senso Runner (3 mm). Pełny kod (6 cyfr) bez wiersza w tabelach nie
     * idzie regułą skrótów — rodzina i rozmiar się zgadzają, a karta i tak zostaje bez przypięcia.
     */
    public function test_full_code_missing_from_tables_is_not_resolved_by_size(): void
    {
        $rows = $this->rows('orthomat');

        // AF060099: rodzina AF06 i rozmiar 0,6 x 0,9 są w tabeli (AF060001), ale to pełny kod, nie skrót
        $full = $this->parser()->pinFor($this->card('AF060099', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)'), $this->profile(), $rows);
        $this->assertFalse($full->resolved());
        $this->assertSame(CobaPartsTable::REASON_FULL_CODE_MISSING, $full->unresolvedReason);

        // skrót z 4 cyframi dalej rozwiązuje się po rozmiarze
        $short = $this->parser()->pinFor($this->card('AF0600', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)'), $this->profile(), $rows);
        $this->assertTrue($short->resolved());
        $this->assertSame('AF060001', $short->pin?->part);
    }

    public function test_size_key_matches_python_rules(): void
    {
        $this->assertSame([0.6, 0.9], CobaPartsTable::sizeKey('Orthomat Standard Szary 0.6m x 0.9m (9.5mm)'));
        $this->assertSame([1.2, 'mb'], CobaPartsTable::sizeKey('1,2 m x metr bieżący'));
        $this->assertSame([0.6, 'mb'], CobaPartsTable::sizeKey('DeckStep Czarny ~0.59m/0.6m x mb'));
        $this->assertSame([0.07, 1.0], CobaPartsTable::sizeKey('Krawędź 75mm x 1m'), 'jak Python round(0.075, 2)');
        $this->assertSame([0.91, 0.91], CobaPartsTable::sizeKey('Fatigue-Step 91cm x 91cm'));
        $this->assertNull(CobaPartsTable::sizeKey('CablePro GP1 Czarny - 9 m'));
        $this->assertTrue(CobaPartsTable::sameSize([0.9, 18.3], [0.91, 18.2]));
        $this->assertFalse(CobaPartsTable::sameSize([0.9, 18.3], [0.9, 'mb']));
        $this->assertFalse(CobaPartsTable::sameSize([0.9, 1.5], [0.6, 1.5]));
    }

    public function test_code_on_duplicate_pages_with_same_table_pins_canonical_page(): void
    {
        // kolejność wierszy bez znaczenia: hygimat ma style, hygimat-2 nie
        foreach ([['hygimat-2', 'hygimat'], ['hygimat', 'hygimat-2']] as $order) {
            $result = $this->parser()->pinFor($this->card('HYS010001', 'Hygimat 0.6m x 0.9m (17mm) Czarny'), $this->profile(), $this->rows(...$order));
            $this->assertSame(self::PREFIX.'hygimat', $result->pin?->pageUrl);
            $this->assertSame('model', $result->pin->imageReason, 'styl „pełna” to nie kolor wiersza');
            $this->assertSame(self::UPLOADS.'2022/10/HYS0_Hygimat-Solid_Corner-scaled.jpg', $result->pin->imageUrl);
        }

        // bez stylów na obu stronach: slug bez „-N”
        $rows = array_map(static fn (ManufacturerPart $r): ManufacturerPart => $r->forceFill(['has_styles' => false]), $this->rows('hygimat-2', 'hygimat'));
        $this->assertSame('hygimat', $this->parser()->pinFor($this->card('HYS010002', 'Hygimat'), $this->profile(), $rows)->pin?->pageKey);
    }

    public function test_code_on_pages_with_different_tables_needs_override(): void
    {
        $rows = $this->rows('fatigue-step-krawedz-b1', 'fatigue-step-edges-b1');
        $card = $this->card('SS070002B1M', "Krawędź/narożnik 'męski' Żółty (100% Nitryl) 75mm x 1m");

        $result = $this->parser()->pinFor($card, $this->profile(), $rows);
        $this->assertFalse($result->resolved());
        $this->assertSame(CobaPartsTable::REASON_PAGES_DIFFER, $result->unresolvedReason);
        $this->assertEqualsCanonicalizing(['fatigue-step-edges-b1', 'fatigue-step-krawedz-b1'], $result->candidates);

        $override = $this->parser()->pinFor($card, $this->profile(['SS070002B1M' => 'fatigue-step-edges-b1']), $rows);
        $this->assertSame('fatigue-step-edges-b1', $override->pin?->pageKey);
        $this->assertSame('żółty', $override->pin->colour);
        $this->assertSame(1.3, $override->pin->weightKg);
    }

    public function test_card_with_link_chosen_by_a_person_is_not_pinned(): void
    {
        $card = $this->card('AF010706', 'Orthomat Standard Czarny/Żółte krawędzie 1.2m x 18.3m');
        $card->shop_source_url = 'https://sklep.example.pl/orthomat-czarny-zolty';

        $result = $this->parser()->pinFor($card, $this->profile(), $this->rows('orthomat'));

        $this->assertFalse($result->resolved());
        $this->assertSame(CobaPartsTable::REASON_MANUAL_URL, $result->unresolvedReason);
    }

    private function parser(): CobaPartsTable
    {
        return app(CobaPartsTable::class);
    }

    private function fixture(string $slug): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/pages/coba-parts/'.$slug.'.html'));
    }

    /** @return list<ManufacturerPart> wiersze stron jak po --refresh (bez bazy) */
    private function rows(string ...$slugs): array
    {
        $out = [];
        foreach ($slugs as $slug) {
            $url = self::PREFIX.$slug;
            $page = $this->parser()->parse($this->fixture($slug), $url);
            foreach ($page['rows'] as $row) {
                $out[] = (new ManufacturerPart)->forceFill([
                    'id' => count($out) + 1,
                    'brand_key' => 'coba',
                    'page_url' => $url,
                    'page_url_hash' => ManufacturerPart::hashFor($url),
                    'page_title' => $page['title'],
                    'part_code' => ManufacturerPart::codeKey($row['part']),
                    'part_label' => $row['label'],
                    'size_label' => $row['size'],
                    'colour_label' => $row['colour'],
                    'weight_kg' => $row['weight_kg'],
                    'model_image_url' => $row['model_image'],
                    'style_image_url' => $row['style_image'],
                    'has_styles' => $page['has_styles'],
                    'page_sha' => str_repeat('a', 40),
                ]);
            }
        }

        return $out;
    }

    private function card(string $sku, string $name): Product
    {
        return (new Product)->forceFill(['sku' => $sku, 'name' => $name, 'manufacturer' => 'Coba']);
    }

    /** @param  array<string, string>  $overrides */
    private function profile(array $overrides = []): ManufacturerProfile
    {
        return new ManufacturerProfile(
            brandKey: 'coba',
            hosts: ['coba.com', 'www.coba.com'],
            onlyManufacturer: true,
            catalogs: [],
            identityIn: ['url', 'title', 'markup', 'text'],
            codeNormalize: 'upper_alnum',
            minLength: 4,
            modelRegex: '/^([A-Z]+)\d/',
            modelAliasIsKey: false,
            resolver: CobaPartsTable::class,
            profileKey: 'coba',
            partsTable: ['page_prefix' => self::PREFIX, 'page_overrides' => $overrides],
        );
    }
}
