<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\B2b\MmmDocumentGate;
use PHPUnit\Framework\TestCase;

/**
 * Przypadki z pomiaru na plikach konta 3M (produkcja 08.10.2026, 2958 dołączeń): tytuły i nazwy plików dosłownie.
 */
final class MmmDocumentGateTest extends TestCase
{
    private const MEDIA = 'https://multimedia.3m.com/mws/media/';

    private MmmDocumentGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $codes = [];
        foreach ([
            ['AC205', 'Lina 3-splotkowa 3M™ Protecta® Cobra™, 5 m, AC205'],
            ['AC225', 'Lina asekuracyjna 3M™ Protecta® z karabinkiem, poliamid 14 mm, kolor biały, 25 m, AC225'],
            ['AC405', 'Lina statyczna w oplocie 3M™ Protecta® Viper™2, 5 m, AC405'],
            ['WPAF1', 'Osłona twarzy chroniąca przed łukiem elektrycznym 3M™, WPAF1'],
            ['WP96', '3M™ Osłona twarzy serii WP96, poliwęglanowa, przezroczysta'],
            ['1861+', 'Maska medyczna do cząstek stałych 3M™ Aura™, FFP1, typ IIR, 1861+'],
            ['1862+', 'Maska medyczna do cząstek stałych 3M™ Aura™, FFP2, typ IIR, 1862+'],
            ['9152E-BULK', 'Półmaska filtrująca 3M™ VFlex™, FFP2, bez zaworu, 9152E'],
            ['9162E', 'Półmaska filtrująca 3M™ VFlex™, FFP2, z zaworem, 9162E'],
            ['1100', '3M™ Wkładki przeciwhałasowe 1100'],
            ['1100R', '3M™ Wkładki przeciwhałasowe, w torbie uzupełniającej do dozownika, bez sznurka, 1100R'],
            ['G3000CUV-VI', 'Hełm ochronny 3M™, wskaźnik Uvicator, Pinlock, wentylowany, biały, G3000CUV-VI'],
            ['AURA 9322+GEN3', '3M™ Aura™ półmaska filtrująca, FFP2, z zaworem, 9322+'],
            ['MT9-02', '3M™ PELTOR™ Laryngofon, MT9-02'],
        ] as [$catalog, $name]) {
            array_push($codes, ...MmmDocumentGate::accountCodes($catalog, $name));
        }
        $this->gate = new MmmDocumentGate($codes);
    }

    public function test_family_datasheet_of_another_product_line_is_rejected_and_kept_on_its_own_line(): void
    {
        $title = 'Protecta-Cobra-AC2XX-3-Strand-Rope-Datasheet.pdf';
        $url = self::MEDIA.'1696143O/3m-protecta-cobra-3-strand-rope-ac230-30-m.pdf';

        $this->assertStringContainsString('AC2XX', (string) $this->rejection('AC405', 'Lina statyczna w oplocie 3M™ Protecta® Viper™2, 5 m, AC405', $title, $url));
        $this->assertNull($this->rejection('AC205', 'Lina 3-splotkowa 3M™ Protecta® Cobra™, 5 m, AC205', $title, $url));
    }

    public function test_series_name_without_digits_points_at_other_positions(): void
    {
        $url = self::MEDIA.'2499999O/3m-electric-arc-protective-faceshield-wpaf-series-pl-pl.pdf';
        $title = 'Osłona twarzy chroniąca przed łukiem elektrycznym 3M™ serii WPAF';

        $this->assertStringContainsString('seria WPAF', (string) $this->rejection('WP96', '3M™ Osłona twarzy serii WP96, poliwęglanowa, przezroczysta', $title, $url));
        $this->assertNull($this->rejection('WPAF1', 'Osłona twarzy chroniąca przed łukiem elektrycznym 3M™, WPAF1', $title, $url));
        // numer katalogowy ze spacją („AURA 9322+GEN3”) nie robi z nazwy linii Aura cudzej serii
        $this->assertNull($this->rejection('1861+', 'Maska medyczna 3M™ Aura™, FFP1, 1861+', '3M™ Aura™ Półmaski filtrujące serii 9300+ – karta danych', self::MEDIA.'1/aura.pdf'));
    }

    public function test_wildcard_and_number_roots(): void
    {
        $sheet = 'Jednorazowa maska oddechowa 3M 186X+ Karta techniczna';
        $this->assertNull($this->rejection('1861+', 'Maska medyczna 3M™ Aura™, FFP1, typ IIR, 1861+', $sheet, self::MEDIA.'1/186x.pdf'));

        $vflex = self::MEDIA.'2/technical-data-sheet-vflex-9152-pl.pdf';
        $this->assertStringContainsString('9152', (string) $this->rejection('9162E', 'Półmaska filtrująca 3M™ VFlex™, FFP2, z zaworem, 9162E', 'Karta Danych Technicznych Vflex 9152', $vflex));
        $this->assertNull($this->rejection('9152E-BULK', 'Półmaska filtrująca 3M™ VFlex™, FFP2, bez zaworu, 9152E', 'Karta Danych Technicznych Vflex 9152', $vflex));

        // karta 1100 opisuje też wersję z dozownika 1100R (rdzeń własnego kodu)
        $this->assertNull($this->rejection('1100R', '3M™ Wkładki przeciwhałasowe, bez sznurka, 1100R', 'Karta danych technicznych Wkładki przeciwhałasowe 3M 1100', self::MEDIA.'3/1100.pdf'));
        // seria z literami (G3000) nie jest „rdzeniem” — karta techniczna serii zostaje przy G3001
        $this->assertNull($this->rejection('1105549', 'Hełm ochronny 3M™, bez wentylacji, 1000 V, G3001DUV1000V-VI', 'SG_PSD_22_Technical-Datasheets-G3000_V3-FV_ForOfficeUse.pdf', self::MEDIA.'4/3m-hard-hats-g3000-series-technical-datasheet-english-online-version.pdf'));
    }

    public function test_code_in_the_file_name_and_marketing(): void
    {
        $this->assertStringContainsString('MT9-02', (string) $this->rejection('7100', '3M™ PELTOR™ LiteCom Plus, MT73H7A4D10EU', 'tds-peltor-throat-microphone-mt9-02-en.pdf', self::MEDIA.'5/3m-peltor-throat-microphone-mt9-02.pdf'));
        foreach ([
            ['Peltor Comtac IX Facebook banner', 'banner.pdf'],
            ['Infografika 3M: Testowanie słuchu i kompatybilność', 'x.pdf'],
            ['cross-sell guipl e-commerce assets pairing 11 pl', 'x.pdf'],
            ['psd-fall-protection-dbi-sala-xe-volume-buy-PL.pdf', 'x.pdf'],
            ['pl-lr.pdf', '3m-technical-bulletin-en-3522020-further-explained-polish.pdf'],
            ['Ulotka promocyjna 3M DBI-SALA ExoFit XE200', 'x.pdf'],
        ] as [$title, $file]) {
            $this->assertNotNull(MmmDocumentGate::marketingReason($title, self::MEDIA.'6/'.$file), $title);
        }
        foreach (['Hełm ochronny 3M™ SecureFit™ Broszura', 'Ulotka półmasek.pdf', '3M 4000 Plus Fit Poster - Polski'] as $title) {
            $this->assertNull(MmmDocumentGate::marketingReason($title, self::MEDIA.'7/x.pdf'), $title);
        }
        // plik rodziny bez żadnego kodu zostaje
        $this->assertNull($this->rejection('AC405', 'Lina statyczna w oplocie 3M™ Protecta® Viper™2, 5 m, AC405', 'Polish_LR.pdf', self::MEDIA.'8/3m-securefit-protective-eyewear-family-brochure-polish-online-version.pdf'));
    }

    public function test_card_codes_skip_measures_and_norm_years(): void
    {
        $this->assertSame(
            ['1105549', 'G3001DUV1000V-VI'],
            MmmDocumentGate::cardCodes(['1105549'], ['Hełm ochronny 3M™, wytrzymałość dielektryczna 1000 V, EN 397:2012, 10 m, biały, G3001DUV1000V-VI']),
        );
        $this->assertSame(
            ['2003397/21.042.00', '2003397', '21.042.00', 'CYL-FLITE-10', '2031536'],
            MmmDocumentGate::cardCodes([], ['3M™ Scott™ Y-Piece 2003397/21.042.00', 'Butla Flite COV, CYL-FLITE-10 (COV), 2 l, 200 barów, 10 min, 2031536']),
        );
    }

    private function rejection(string $catalog, string $name, string $title, string $url): ?string
    {
        return $this->gate->rejection(MmmDocumentGate::cardCodes([$catalog], [$name]), $title, $url);
    }
}
