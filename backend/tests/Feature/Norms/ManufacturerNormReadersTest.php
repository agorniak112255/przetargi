<?php

declare(strict_types=1);

namespace Tests\Feature\Norms;

use App\Services\Norms\CxsNormPageReader;
use App\Services\Norms\GenericNormFrameReader;
use App\Services\Norms\ManufacturerNormReaders;
use Tests\TestCase;

/**
 * Czytniki norm na zapisanych prawdziwych stronach producentów. Pary mają być dosłowne: CXS podaje poziomy EN 388
 * słowami („odporność na przetarcie - 2, …”) i tak zostają — kodu „2112” czytnik nie składa.
 */
final class ManufacturerNormReadersTest extends TestCase
{
    private const CXS_URL = 'https://cxs.net.pl/rekawice-cxs-tale.html';

    private const LEVELS = 'odporność na przetarcie - 2, odporność na przecięcie - 1, odporność na rozerwanie - 1, odporność na przekłucie - 2';

    public function test_cxs_reader_takes_norms_line_and_worded_en388_levels_literally(): void
    {
        $reading = app(CxsNormPageReader::class)->read($this->fixture('cxs-tale-3210-012.html'), self::CXS_URL);

        $this->assertNotNull($reading);
        $this->assertSame([
            ['label' => 'EN 420', 'value' => null],
            ['label' => 'EN 388', 'value' => self::LEVELS],
        ], $reading->rows);
        $this->assertSame(
            "normy: EN 420, EN 388\n"
            ."Poziomy odporności dla normy EN388:\n"
            ."- odporność na przetarcie - 2\n"
            ."- odporność na przecięcie - 1\n"
            ."- odporność na rozerwanie - 1\n"
            .'- odporność na przekłucie - 2',
            $reading->block,
        );
        $this->assertSame('cxs', $reading->reader);
    }

    public function test_cxs_reader_ignores_other_products_on_the_page(): void
    {
        $html = str_replace(
            'Rękawice mechaniczne CSX Yema</a>',
            'Rękawice mechaniczne CSX Yema</a><p>normy: EN 511, EN 407</p><p>Poziomy odporności dla normy EN388:<br>- odporność na przetarcie - 4</p>',
            $this->fixture('cxs-tale-3210-012.html'),
        );
        $this->assertStringContainsString('normy: EN 511', $html);

        $reading = app(CxsNormPageReader::class)->read($html, self::CXS_URL);

        $this->assertSame([
            ['label' => 'EN 420', 'value' => null],
            ['label' => 'EN 388', 'value' => self::LEVELS],
        ], $reading?->rows);
    }

    public function test_cxs_reader_without_norms_in_description_gives_null(): void
    {
        $html = '<html><body><div class="product attribute overview"><div class="value"><p>dłoń: skóra</p></div></div></body></html>';

        $this->assertNull(app(CxsNormPageReader::class)->read($html, self::CXS_URL));
    }

    public function test_generic_reader_reads_a_norm_frame_and_nothing_on_portwest(): void
    {
        $generic = app(GenericNormFrameReader::class);

        // Portwest trzyma normy w zakładce <section id="content4"> bez klasy ramki norm
        $this->assertNull($generic->read($this->fixture('portwest-a110.html'), 'https://www.portwest.com/products/view/A110/BKR'));

        $frame = '<html><body><h1>Butoflex 650</h1><ul class="norms">'
            .'<li><div>EN 388</div><div>1121X</div></li><li><div>EN 374-5</div></li></ul></body></html>';
        $reading = $generic->read($frame, 'https://www.mapa-pro.pl/produkty/butoflex-650');

        $this->assertNotNull($reading);
        $this->assertSame([
            ['label' => 'EN 388', 'value' => '1121X'],
            ['label' => 'EN 374-5', 'value' => null],
        ], $reading->rows);
        $this->assertSame("EN 388 1121X\nEN 374-5", $reading->block);
        $this->assertSame('ramka-norm', $reading->reader);
    }

    public function test_site_readers_come_before_the_generic_one(): void
    {
        $readers = app(ManufacturerNormReaders::class);

        $this->assertSame(
            [CxsNormPageReader::class, GenericNormFrameReader::class],
            array_map(get_class(...), $readers->for(self::CXS_URL)),
        );
        $this->assertSame(
            [GenericNormFrameReader::class],
            array_map(get_class(...), $readers->for('https://www.portwest.com/products/view/A110/BKR')),
        );
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/norms/'.$name));
    }
}
