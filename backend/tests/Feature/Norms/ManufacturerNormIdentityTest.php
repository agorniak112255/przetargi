<?php

declare(strict_types=1);

namespace Tests\Feature\Norms;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Services\Norms\ManufacturerNormIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Bramka tożsamości norm ze strony producenta (plan norm, etap 3a): strona musi nieść dokładny kod naszego wyrobu.
 * Strony to zapisane prawdziwe karty: cxs.net.pl (Canis „Rękawice CXS Tale”, SKU 3210-012-000-00 tylko w mikrodanych
 * i w wierszu SKU) i portwest.com (A110 — kod w adresie i w nagłówku h2, <title> to samo „PORTWEST”).
 */
final class ManufacturerNormIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const CXS_URL = 'https://cxs.net.pl/rekawice-cxs-tale.html';

    private const VALID_EAN = '5901234123457';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_codes_come_from_identifiers_of_the_card_brand_and_valid_eans(): void
    {
        $card = $this->card('CANIS', 'CX-99');
        $this->identifier($card, 'ean', self::VALID_EAN, '0'.self::VALID_EAN);
        // zła suma kontrolna — normalized null, jak zapisuje ProductIdentifierStore
        $this->identifier($card, 'ean', '5901234123458', null);
        $this->identifier($card, 'manufacturer_code', '3210-012-000-00', '3210012000000', 'canis');
        // kod producenta innej marki (dystrybutor nazwał tak kod Ansell) — nie potwierdza strony Canis
        $this->identifier($card, 'manufacturer_code', '11-800', '11800', 'ansell');
        $this->identifier($card, 'manufacturer_code', '3210-010-251-00', '3210010251000', 'canis', removed: true);
        $this->identifier($card, 'source_code', 'RNIT-7', 'RNIT7', 'canis');

        $codes = $this->identity()->codesFor($card);

        $this->assertSame([
            ['code' => self::VALID_EAN, 'kind' => 'ean', 'short' => false],
            ['code' => '3210-012-000-00', 'kind' => 'manufacturer_code', 'short' => false],
        ], $codes, 'bez SKU — karta nie ma ceny od samej marki');
    }

    public function test_card_ean_needs_a_valid_checksum_and_is_not_repeated(): void
    {
        $valid = $this->card('CANIS', 'CX-1', self::VALID_EAN);
        $this->identifier($valid, 'ean', '0'.self::VALID_EAN, '0'.self::VALID_EAN);
        $invalid = $this->card('CANIS', 'CX-2', '5901234123458');
        $short = $this->card('CANIS', 'CX-3', '1234567');

        $this->assertSame([['code' => '0'.self::VALID_EAN, 'kind' => 'ean', 'short' => false]], $this->identity()->codesFor($valid));
        $this->assertSame([], $this->identity()->codesFor($invalid));
        $this->assertSame([], $this->identity()->codesFor($short));
    }

    public function test_sku_counts_only_when_the_price_comes_from_the_brand_file_price_list(): void
    {
        $own = $this->card('Portwest', 'A110-XL');
        $other = $this->card('Portwest', 'PW-555');
        PriceList::query()->create(['manufacturer' => 'PORTWEST', 'version' => '2026', 'product_ids' => [$own->id]]);
        PriceList::query()->create(['manufacturer' => 'Hurtownia Mix', 'version' => '2026', 'product_ids' => [$other->id]]);

        $this->assertSame([
            ['code' => 'A110-XL', 'kind' => 'sku', 'short' => false],
            // kod bez końcówki rozmiaru — dodatkowy kandydat, krótki
            ['code' => 'A110', 'kind' => 'sku', 'short' => true],
        ], $this->identity()->codesFor($own));
        $this->assertSame([], $this->identity()->codesFor($other), 'cennik pliku innej marki to nie cennik producenta');
    }

    public function test_sku_counts_for_own_manufacturer_b2b_account_but_not_for_a_distributor(): void
    {
        $uvex = $this->card('UVEX', '6049908');
        $reis = $this->card('REIS', 'RNIT-B-9');
        $uvexAccount = B2bAccount::query()->create(['username' => 'u', 'password' => 'x', 'connector' => 'uvex', 'sites' => ['uvex.pl']]);
        $rawpol = B2bAccount::query()->create(['username' => 'r', 'password' => 'x', 'connector' => 'rawpol', 'sites' => ['rawpol.pl']]);
        B2bProductLink::query()->create(['b2b_account_id' => $uvexAccount->id, 'remote_id' => 'U1', 'product_id' => $uvex->id, 'manufacturer' => 'uvex']);
        B2bProductLink::query()->create(['b2b_account_id' => $rawpol->id, 'remote_id' => 'R1', 'product_id' => $reis->id, 'manufacturer' => 'REIS']);
        // wpis konta dystrybutora w Cennikach — marka „Raw-Pol”, nie Reis
        PriceList::query()->create(['manufacturer' => 'Raw-Pol', 'version' => '2026', 'product_ids' => [$reis->id]]);

        $this->assertSame([['code' => '6049908', 'kind' => 'sku', 'short' => false]], $this->identity()->codesFor($uvex));
        $this->assertSame([], $this->identity()->codesFor($reis));
    }

    public function test_short_codes(): void
    {
        $card = $this->card('ANSELL', 'X');
        foreach (['2039', 'A110', '11-800', '11-8000', 'AB-12345'] as $i => $code) {
            $this->identifier($card, 'manufacturer_code', $code, preg_replace('/\W/', '', $code), 'ansell', position: 'P'.$i);
        }

        $short = array_column($this->identity()->codesFor($card), 'short', 'code');

        $this->assertSame(['2039' => true, 'A110' => true, '11-800' => true, '11-8000' => false, 'AB-12345' => false], $short);
    }

    public function test_cxs_page_confirms_its_sku_from_markup_and_not_another_code(): void
    {
        $html = $this->fixture('cxs-tale-3210-012.html');
        $card = $this->card('CANIS', '3210-012-000-00');

        $this->assertSame(
            ['by' => 'sku', 'value' => '3210-012-000-00', 'where' => 'markup'],
            $this->identity()->confirm($card, self::CXS_URL, $html, [$this->code('3210-012-000-00', 'sku')]),
        );
        $this->assertNull($this->identity()->confirm($card, self::CXS_URL, $html, [$this->code('3210-010-251-00', 'sku')]));
    }

    public function test_long_code_in_page_text_confirms_but_not_inside_related_products_or_longer_codes(): void
    {
        // bez mikrodanych i klasy Magento zostaje wiersz „SKU 3210-012-000-00” w treści
        $html = str_replace(['itemprop="sku"', 'catalog_product_view_sku_', 'data-product-sku'], ['data-x', 'x_', 'data-y'], $this->fixture('cxs-tale-3210-012.html'));
        $card = $this->card('CANIS', '3210-012-000-00');

        $this->assertSame(
            ['by' => 'manufacturer_code', 'value' => '3210 012 000 00', 'where' => 'text'],
            $this->identity()->confirm($card, self::CXS_URL, $html, [$this->code('3210 012 000 00', 'manufacturer_code')]),
            'separatory kodu są wymienne',
        );

        // kod w kafelku innego wyrobu (zagnieżdżony blok „upsell”) i w strzałce następnego wyrobu — nie nasza strona
        $foreign = str_replace(
            ['Rękawice mechaniczne CSX Yema</a>', '<h3 class="product-name">Rękawice CXS Hivi</h3>'],
            ['Rękawice mechaniczne CSX Yema 3210-010-251-00</a>', '<h3 class="product-name">Rękawice CXS Hivi 3210-077-000-00</h3>'],
            $html,
        );
        $this->assertStringContainsString('CSX Yema 3210-010-251-00', $foreign);
        $this->assertNull($this->identity()->confirm($card, self::CXS_URL, $foreign, [$this->code('3210-010-251-00', 'sku')]));
        $this->assertNull($this->identity()->confirm($card, self::CXS_URL, $foreign, [$this->code('3210-077-000-00', 'sku')]));

        // „3210-012-000-00” nie jest osobnym tokenem w „3210-012-000-00-09”, a „3210-012” to tylko początek kodu
        $page = '<html><body><h1>Rękawice</h1><p>Kod wariantu: 3210-012-000-00-09</p></body></html>';
        $this->assertNull($this->identity()->confirm($card, 'https://cxs.net.pl/x.html', $page, [$this->code('3210-012-000-00', 'sku')]));
        $this->assertNull($this->identity()->confirm($card, 'https://cxs.net.pl/x.html', $page, [$this->code('3210-012', 'sku')]));
        $glued = '<html><body><p>Kod: 3210012000 00</p></body></html>';
        $this->assertNull($this->identity()->confirm($card, 'https://cxs.net.pl/x.html', $glued, [$this->code('3210-012-000-00', 'sku')]));
    }

    public function test_section_after_more_products_heading_is_not_our_page_but_the_title_block_stays(): void
    {
        $card = $this->card('CANIS', 'X');
        $url = 'https://x.pl/karta.html';
        $foreign = '<html><body><h1>Rękawice X</h1><p>Opis rękawicy.</p>'
            .'<h2>Więcej rękawic</h2><div><p>Rękawice Y 3210-099-000-00</p></div></body></html>';
        $this->assertNull($this->identity()->confirm($card, $url, $foreign, [$this->code('3210-099-000-00', 'sku')]));

        // klasa z „recommend”, ale w bloku stoi tytuł karty — to nasz wyrób
        $own = '<html><body><div class="product-recommendation-layout"><h1>Rękawice X</h1>'
            .'<p>Kod: 3210-012-000-00</p></div></body></html>';
        $this->assertSame(
            ['by' => 'sku', 'value' => '3210-012-000-00', 'where' => 'text'],
            $this->identity()->confirm($card, $url, $own, [$this->code('3210-012-000-00', 'sku')]),
        );
    }

    public function test_short_code_only_via_url_or_title(): void
    {
        $html = str_replace(
            '<strong>rozmiary: </strong>7,8,9,10,11',
            '<strong>rozmiary: </strong>7,8,9,10,11 art. 2039',
            $this->fixture('cxs-tale-3210-012.html'),
        );
        $card = $this->card('CANIS', '2039');
        $codes = [$this->code('2039', 'sku')];

        $this->assertNull($this->identity()->confirm($card, self::CXS_URL, $html, $codes), 'krótki kod w samej treści to za mało');
        $this->assertSame(
            ['by' => 'sku', 'value' => '2039', 'where' => 'url'],
            $this->identity()->confirm($card, 'https://cxs.net.pl/rekawice-2039.html', $html, $codes),
        );
        $titled = str_replace('<title>Rękawice CXS Tale</title>', '<title>Rękawice CXS Tale 2039</title>', $html);
        $this->assertSame(
            ['by' => 'sku', 'value' => '2039', 'where' => 'title'],
            $this->identity()->confirm($card, self::CXS_URL, $titled, $codes),
        );
        // „12-2039” to inny numer
        $this->assertNull($this->identity()->confirm($card, 'https://cxs.net.pl/rekawice-12-2039.html', $html, $codes));
    }

    public function test_portwest_short_code_in_url_confirms_but_body_text_alone_does_not(): void
    {
        $html = $this->fixture('portwest-a110.html');
        $card = $this->card('Portwest', 'A110');
        $codes = [$this->code('A110', 'sku')];

        $this->assertSame(
            ['by' => 'sku', 'value' => 'A110', 'where' => 'url'],
            $this->identity()->confirm($card, 'https://www.portwest.com/products/view/A110/BKR', $html, $codes),
        );
        // „A110 -” stoi w nagłówku h2 i w treści, ale <title> to „PORTWEST”, a h1 brak
        $this->assertNull($this->identity()->confirm($card, 'https://www.portwest.com/products/view/12345', $html, $codes));
    }

    public function test_ean_matches_only_as_a_whole_digit_token(): void
    {
        $card = $this->card('CANIS', 'X');
        $codes = [$this->code('0'.self::VALID_EAN, 'ean')];
        $page = static fn (string $text): string => '<html><body><h1>Rękawice</h1><p>'.$text.'</p></body></html>';

        $this->assertSame(
            ['by' => 'ean', 'value' => '0'.self::VALID_EAN, 'where' => 'text'],
            $this->identity()->confirm($card, 'https://x.pl/a.html', $page('EAN: '.self::VALID_EAN), $codes),
            'GTIN-14 z zerem to ten sam EAN-13',
        );
        $this->assertNull($this->identity()->confirm($card, 'https://x.pl/a.html', $page('Nr '.self::VALID_EAN.'1'), $codes));
        $this->assertNull($this->identity()->confirm($card, 'https://x.pl/a.html', $page('kod X'.self::VALID_EAN), $codes));
    }

    public function test_family_conflict_needs_two_codes_of_a_comparable_edition(): void
    {
        $identity = $this->identity();

        $this->assertFalse($identity->familyConflict('EN 388:2003 (4542), EN 388:2016 (4X42C)'), 'dwa wydania to dwie wartości wyrobu');
        $this->assertTrue($identity->familyConflict('EN 388:2016 2122X; EN 388:2016 2132X'));
        $this->assertTrue($identity->familyConflict("EN 388 2122X\nEN 388 2132X"), 'wydanie niepodane — porównywalne');
        $this->assertFalse($identity->familyConflict('EN 388:2016 4X42C'));
        $this->assertFalse($identity->familyConflict('EN 388:2016 4X42C; EN 388:2016 4X42C'), 'ten sam kod dwa razy');
        $this->assertFalse($identity->familyConflict('EN 388: odporność na przetarcie - 2, odporność na przecięcie - 1'));
    }

    private function identity(): ManufacturerNormIdentity
    {
        return app(ManufacturerNormIdentity::class);
    }

    private function card(string $brand, string $sku, ?string $ean = null): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => 'Rękawice '.$sku, 'manufacturer' => $brand, 'ean' => $ean,
            'catalog_price_net' => 10, 'purchase_price' => 8, 'stock' => 0,
        ]);
    }

    private function identifier(
        Product $card,
        string $type,
        string $value,
        ?string $normalized,
        ?string $brand = null,
        bool $removed = false,
        string $position = 'P',
    ): void {
        ProductIdentifier::query()->create([
            'product_id' => $card->id,
            'source_key' => 'file:1',
            'position_key' => $position.$type.$value,
            'type' => $type,
            'value' => $value,
            'normalized' => $normalized,
            'brand_key' => $brand,
            'last_seen_at' => now(),
            'removed_at' => $removed ? now() : null,
        ]);
    }

    /** @return array{code: string, kind: string, short: bool} */
    private function code(string $code, string $kind): array
    {
        $compact = (string) preg_replace('/[^A-Z0-9]/i', '', $code);
        $short = $kind !== 'ean' && (strlen($compact) <= 4 || (ctype_digit($compact) && strlen($compact) <= 5));

        return ['code' => $code, 'kind' => $kind, 'short' => $short];
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/norms/'.$name));
    }
}
