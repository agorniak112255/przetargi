<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductSearchIdentity;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Audyt ręcznych cenników 22.09.2026 (A1–A3): karty Canis dostawały opisy innych modeli tej samej
 * grupy towarowej (ROXY 3420-007 z karty MERU 3420-115), bandaże CEDERROTH strony „nr-51011010”,
 * a SPIRO P3 dane ze stron spiro-p1/p2. Adresy i tytuły poniżej to prawdziwe źródła z produkcji.
 */
final class ProductSearchIdentityGroupedCodeTest extends TestCase
{
    private const MERU = 'https://centrumelektronarzedzi.pl/pl/p/Rekawice-Canis-CXS-MERU-powlekane-w-polowie-lateks-3420-115/28015';

    private const SPIRO_BASE = 'https://canis.cz/pl/akcesoria-ochronne_c88493506174732/ochrona-drog-oddechowych_c88493506175613/respiratory-i-jednorazowe-zaslony_c3011742737104976/';

    public function test_grouped_canis_sku_core_is_group_with_model_not_the_group_alone(): void
    {
        $id = new ProductSearchIdentity;
        $roxy = $this->canis('3420-007-157-07', 'Rukavice ROXY, s blistrem, máčené v latexu,žluto - zelená, vel.');

        $this->assertSame('3420-007', $id->groupedNumericModelCode($roxy));
        $this->assertSame('3420-007', $id->gloveCodeCore($roxy));
        $this->assertNotContains('3420', $id->productCodes($roxy));
        $this->assertSame('4410-007', $id->groupedNumericModelCode($this->canis('4410-007-000-00', 'E.A.R. CAP earplugs')));
        $this->assertSame('3100-011', $id->groupedNumericModelCode($this->canis('3100-011-000', 'Rukavice BONO')));

        // dwuczłonowe kody to pełny model — bez zmian
        $threeM = new Product(['sku' => '8906-200', 'name' => 'Nauszniki 3M 8906-200', 'manufacturer' => '3M']);
        $tegera = new Product(['sku' => '8805-9', 'name' => 'Rękawice Tegera 8805 rozmiar 9', 'manufacturer' => 'Ejendals']);
        $this->assertNull($id->groupedNumericModelCode($threeM));
        $this->assertNull($id->groupedNumericModelCode($tegera));
        $this->assertSame('8906', $id->gloveCodeCore($threeM));
        $this->assertSame('8805', $id->gloveCodeCore($tegera));
    }

    public function test_page_of_another_canis_model_from_the_same_group_is_rejected(): void
    {
        $id = new ProductSearchIdentity;
        $cases = [
            // 12596 ROXY ← MERU
            [$this->canis('3420-007-157-07', 'Rukavice ROXY, s blistrem, máčené v latexu,žluto - zelená, vel.'),
                self::MERU, 'Rękawice Canis CXS MERU powlekane w połowie lateks 3420-115'],
            // 12546 KALA z blistrem ← BONO SKÓRZANE 3100-006: ta sama grupa, ale inny model
            [$this->canis('3100-027-000-10', 'Rukavice KALA štíp. kůže, s blistrem, vel.10'),
                'https://centrumelektronarzedzi.pl/pl/p/Rekawice-Canis-BONO-SKORZANE-3100-006/27960',
                'Rękawice Canis BONO SKÓRZANE 3100-006'],
            // 11352 1090-027 ← 1020-027
            [$this->canis('1090-027-440-00', 'Men´s pants 3/4 CXS STRETCH,bright blue-black, 98% cotton 2% elastane, 250g/m2'),
                'https://marketbhp.pl/produkt/spodnie-canis-stretch-do-pasa-1020-027-440-48-niebiesko-czarne-48.html',
                'Spodnie Canis STRETCH do pasa 1020-027-440-48'],
            // 12101 klapki TREND ← trzewiki STONE MARBLE 2118-005
            [$this->canis('2250-023-821-00', 'TREND slipper, unisex, black marble, 36-48 sizes'),
                'https://centrumelektronarzedzi.pl/pl/p/Trzewiki-Canis-CXS-STONE-MARBLE-S3-czarne-2118-005/27899',
                'Trzewiki Canis CXS STONE MARBLE S3 czarne 2118-005'],
        ];
        foreach ($cases as [$product, $url, $title]) {
            $this->assertTrue($id->pageClaimsAnotherCode($url, $title, $product), $url);
            $this->assertTrue($id->pageClaimsAnotherCode($url, '', $product), $url);
            $this->assertFalse($id->isConfirmedProductCard($url, $title, $title, $product), $url);
            $this->assertFalse($id->isConfirmedProductCard($url, '', '', $product), $url);
        }
    }

    public function test_page_with_our_group_and_model_stays(): void
    {
        $id = new ProductSearchIdentity;
        // 12414: sklep pisze pełny kod, a w treści/tytule bywa sam rdzeń „4410-007”
        $plugs = $this->canis('4410-007-000-00', 'E.A.R. CAP earplugs');
        $url = 'https://eshop.rec21.cz/zatkove-chranice-sluchu-3m-e-a-r-caps-4410-007-000-00-68421cz441/';

        $this->assertFalse($id->textNamesAnotherGroupedCode('Zátkové chrániče sluchu E.A.R. CAPS 4410-007', $plugs));
        $this->assertFalse($id->pageClaimsAnotherCode($url, 'Zátkové chrániče sluchu 3M E.A.R. CAPS 4410-007', $plugs));
        $this->assertTrue($id->hayHasProductCode('zátkové chrániče e.a.r. caps 4410-007', $plugs));
        $this->assertTrue($id->isConfirmedProductCard($url, 'Zátkové chrániče sluchu 3M E.A.R. CAPS 4410-007-000-00', '', $plugs));

        // inny kolor/rozmiar tego samego modelu i strona z naszym kodem obok cudzego
        $roxy = $this->canis('3420-007-157-07', 'Rukavice ROXY');
        $this->assertFalse($id->textNamesAnotherGroupedCode('Rękawice Canis ROXY 3420-007-160-09', $roxy));
        $this->assertFalse($id->textNamesAnotherGroupedCode('ROXY 3420-007 (zastępuje 3420-115)', $roxy));
        // sklejone dziesięć cyfr
        $this->assertTrue($id->textNamesAnotherGroupedCode('rekawice-canis-3420115157', $roxy));
        $this->assertFalse($id->textNamesAnotherGroupedCode('rekawice-canis-3420007157', $roxy));
        // dziesięć cyfr z inną grupą to identyfikator sklepu/producenta (3M przy wkładkach E.A.R. Soft 4410-008)
        $refill = $this->canis('4410-008-000-00', 'E.A.R. SOFT ear plugs refil, box PD-01-010');
        $this->assertFalse($id->textNamesAnotherGroupedCode(
            'https://www.3m.com/3M/en_US/p/d/ear-plugs/3me-a-r-soft-yellow-neons-top-up-pd01010-for-one-touch-dispenser/ass-1325184410',
            $refill,
        ));
        // wymiar, EAN i norma to nie kod
        $this->assertFalse($id->textNamesAnotherGroupedCode('Mata 1200-300 mm, EAN 8595564412345, EN 1149-5', $roxy));
    }

    /**
     * Decyzja 22.09.2026: karta blistrowa to ten sam wyrób co rękawica bez blistra, choć Canis
     * daje jej inny numer modelu. Strona i opis modelu bez blistra są nasze, jeśli są z tej samej
     * grupy towarowej, a etykieta cudzego kodu ma dokładnie ten sam zbiór słów modelu co nazwa.
     */
    public function test_blister_card_accepts_page_and_description_of_the_same_model_without_blister(): void
    {
        $id = new ProductSearchIdentity;
        $service = app(ProductEnrichmentService::class);
        $cases = [
            // 12549 BONO BLISTR 3100-041 ← BONO 3100-006
            [$this->canis('3100-041-000-08', 'Rukavice BONO, kožené, BLISTR, vel. 08'),
                'https://www.e-canis.cz/rukavice-bono-3100-006',
                'Rękawice Canis BONO 3100-006 to skórzane rękawice ochronne z koziej skóry licowej.'],
            // 12545 BONO z blistrem 3100-011 ← BONO 3100-006
            [$this->canis('3100-011-000-08', 'Rukavice BONO, s blistrem, kožené, vel.'),
                'https://www.e-canis.cz/rukavice-bono-3100-006',
                'Rękawice Canis BONO (3100-006) to skórzane rękawice ochronne.'],
            // 12555 TECHNIK ECO 3210-033 ← TECHNIK ECO 3210-010
            [$this->canis('3210-033-801-08', 'Rukavice TECHNIK ECO, s blistrem, kombinované, vel.8'),
                'https://www.e-canis.cz/rukavice-technik-eco-3210-010',
                'Rękawice CXS TECHNIK ECO 3210-010 to rękawice ochronne ze skóry licowej koziej i dzianiny bawełnianej.'],
            // 12566 FAWA 3310-010 ← FAWA 3310-002
            [$this->canis('3310-010-100-08', 'Rukavice FAWA, s blistrem, textilní , vel. 8'),
                'https://centrumelektronarzedzi.pl/pl/p/Rekawice-Canis-FAWA-3310-002/27973',
                'Rękawice FAWA 3310-002 to lekkie rękawice ochronne z bielonej dzianiny bawełnianej, marki Canis.'],
        ];
        foreach ($cases as [$product, $url, $description]) {
            $this->assertFalse($id->pageClaimsAnotherCode($url, '', $product), $url);
            $this->assertTrue($id->isConfirmedProductCard($url, '', '', $product), $url);
            $this->assertFalse($id->textNamesAnotherGroupedCode($description, $product), $description);
            $this->assertTrue($this->invoke($service, 'descriptionMentionsProduct', [$description, $product]), $description);
        }
        $bono = $this->canis('3100-041-000-08', 'Rukavice BONO, kožené, BLISTR, vel. 08');
        $this->assertFalse($id->pageClaimsAnotherCode('https://www.e-canis.cz/rukavice-bono-3100-006', 'Rukavice BONO 3100-006', $bono));
        // przyimek w adresie to nie człon modelu (MAWA z blistrem 3310-024 ← MAWA z PVC 3310-002)
        $mawa = $this->canis('3310-024-800-08', 'Rukavice MAWA, s blistrem, bavl.černé, s terčíky, vel. 8');
        $this->assertFalse($id->pageClaimsAnotherCode('https://centrumelektronarzedzi.pl/pl/p/Rekawice-Canis-MAWA-z-PVC-3310-002/27983', '', $mawa));
    }

    /**
     * Drugi przegląd 22.09.2026: jedno wspólne słowo modelu przepuszczało inny wariant (TECHNIK PLUS
     * ze strony TECHNIK ECO, DOUBLE ROXY WINTER ze strony ROXY WINTER). Zbiór słów modelu etykiety
     * musi być równy naszemu; dopisek w etykiecie to wątpliwość, więc odrzucenie.
     */
    public function test_blister_card_rejects_another_variant_of_the_model(): void
    {
        $id = new ProductSearchIdentity;
        $cases = [
            ['3210-032-801-07', 'Rukavice TECHNIK PLUS, s blistrem, kombinované, vel.',
                'https://www.e-canis.cz/rukavice-technik-eco-3210-010', 'Rukavice TECHNIK ECO 3210-010'],
            ['3210-044-801-08', 'Rukavice TECHNIK PLUS BLISTR, vel. 10',
                'https://www.e-canis.cz/rukavice-technik-hv-3210-099', 'Rukavice TECHNIK HV 3210-099'],
            ['3700-043-000-10', 'Rukavice DOUBLE ROXY WINTER, s blistrem, zimní, máčené v nitrilu, vel. 10',
                'https://www.e-canis.cz/rukavice-roxy-winter-3700-034', 'Rukavice ROXY WINTER 3700-034'],
            ['3700-071-400-10', 'Rukavice ROXY BLUE WINTER, zimní, máčené v latexu, černo-modré,blistr,  vel. 10',
                'https://www.e-canis.cz/rukavice-roxy-winter-3700-034', 'Rukavice ROXY WINTER 3700-034'],
            ['3630-088-700-07', 'Rukavice CITA II, protipořezové, šedé, BLISTR, vel.',
                'https://www.e-canis.cz/rukavice-cita-3630-024', 'Rukavice CITA 3630-024'],
            ['3210-090-000-11', 'Rukavice DINGO A, kombinované, blistr, vel. 11',
                'https://www.e-canis.cz/rukavice-dingo-3210-025', 'Rukavice DINGO 3210-025'],
            ['3610-058-250-10', 'Rukavice CXS PATON RED, svářecí, červené, s blistrem, vel.',
                'https://www.e-canis.cz/rukavice-paton-3610-010', 'Rukavice PATON 3610-010'],
            ['3440-057-400-07', 'Rukavice BRITA DOTS, máčené v PU a PVC terčíky BLISTR, vel. 6-7',
                'https://www.e-canis.cz/rukavice-brita-touch-3440-049', 'Gloves BRITA TOUCH 3440-049'],
            ['3420-007-157-07', 'Rukavice ROXY, s blistrem, máčené v latexu,žluto - zelená, vel.',
                'https://www.e-canis.cz/rukavice-cxs-meru-3420-115', 'Rukavice CXS MERU 3420-115'],
            ['3420-008-101-10', 'Rukavice DETA, s blistrem, máčené v latexu, vel. 10',
                'https://www.e-canis.cz/rukavice-meru-3420-115-000', 'MERU 3420-115'],
            ['3420-014-102-10', 'Rukavice BLANCHE, s blistrem, máčené v latexu, vel. 10',
                'https://www.e-canis.cz/meru/3420-115', 'Rukavice MERU'],
            ['3100-027-000-10', 'Rukavice KALA štíp. kůže, s blistrem, vel.10',
                'https://www.e-canis.cz/rukavice-bono-3100-006', 'Rukavice BONO 3100-006'],
        ];
        foreach ($cases as [$sku, $name, $url, $title]) {
            $product = $this->canis($sku, $name);
            $this->assertTrue($id->pageClaimsAnotherCode($url, $title, $product), $name);
            $this->assertTrue($id->pageClaimsAnotherCode($url, '', $product), $name);
        }

        // opis: etykietą jest okno słów tuż przed kodem
        $plus = $this->canis('3210-032-801-07', 'Rukavice TECHNIK PLUS, s blistrem, kombinované, vel.');
        $this->assertTrue($id->textNamesAnotherGroupedCode('Rukavice TECHNIK ECO (3210-010), kombinované.', $plus));

        // przymiotnik opisowy sklepu („SKORZANE”, „powlekane”) to nie człon modelu — ta sama BONO zostaje;
        // wątpliwość to dopisek w oknie opisu („robocze … marki”) albo brak dopisku wersji (CXS TECHNIK przy
        // TECHNIK ECO) — sklep nie potwierdza, że to ten sam wariant
        $bono = $this->canis('3100-041-000-08', 'Rukavice BONO, kožené, BLISTR, vel. 08');
        $technikEco = $this->canis('3210-033-801-08', 'Rukavice TECHNIK ECO, s blistrem, kombinované, vel.8');
        $this->assertFalse($id->pageClaimsAnotherCode(
            'https://centrumelektronarzedzi.pl/pl/p/Rekawice-Canis-BONO-SKORZANE-3100-006/27960', '', $bono
        ));
        $this->assertTrue($id->pageClaimsAnotherCode(
            'https://centrumelektronarzedzi.pl/pl/p/Rekawice-Canis-CXS-TECHNIK-wzmacniane-3210-010/27967', '', $technikEco
        ));
        $this->assertTrue($id->textNamesAnotherGroupedCode('Rękawice robocze BONO marki Canis (3100-006) to skórzane rękawice.', $bono));
        // tytuł innego wariantu przy dobrym adresie też odrzuca
        $this->assertTrue($id->pageClaimsAnotherCode('https://www.e-canis.cz/rukavice-bono-3100-006', 'Rukavice BONO WINTER 3100-006', $bono));
    }

    public function test_blister_card_still_rejects_another_model_word_or_group(): void
    {
        $id = new ProductSearchIdentity;
        $roxy = $this->canis('3420-007-157-07', 'Rukavice ROXY, s blistrem, máčené v latexu,žluto - zelená, vel.');
        $technik = $this->canis('3210-033-801-08', 'Rukavice TECHNIK ECO, s blistrem, kombinované, vel.8');
        $winter = $this->canis('3700-024-801-08', 'Rukavice TECHNIK WINTER, s blistrem, zimní, vel.');

        // ROXY ← MERU 3420-115: inne słowo modelu
        $this->assertTrue($id->pageClaimsAnotherCode(self::MERU, '', $roxy));
        $this->assertTrue($id->textNamesAnotherGroupedCode('Rękawice robocze Canis CXS MERU (3420-115) to bezszwowe rękawice', $roxy));
        // TECHNIK ECO ← DINGO 3210-001: ta sama grupa, ale DINGO to nie TECHNIK
        $this->assertFalse($id->isConfirmedProductCard(
            'https://centrumelektronarzedzi.pl/pl/p/Rekawice-Canis-DINGO-kombinowane-3210-001/27956', '', '', $technik
        ));
        // TECHNIK WINTER 3700 ← TECHNIK 3210-010: słowo się zgadza, grupa nie (rękawice zimowe)
        $this->assertTrue($id->textNamesAnotherGroupedCode('Rękawice CXS TECHNIK 3210-010 ze skóry licowej', $winter));
        // karta bez blistra: słowo modelu nie wystarcza (inny numer modelu = inny wyrób)
        $plain = $this->canis('3100-011-000-08', 'Rukavice BONO, kožené, vel. 08');
        $this->assertTrue($id->textNamesAnotherGroupedCode('Rękawice Canis BONO 3100-006', $plain));
    }

    public function test_description_naming_another_canis_model_is_rejected(): void
    {
        $service = app(ProductEnrichmentService::class);
        $roxy = $this->canis('3420-007-157-07', 'Rukavice ROXY, s blistrem, máčené v latexu,žluto - zelená, vel.');

        $this->assertFalse($this->invoke($service, 'descriptionMentionsProduct', [
            'Rękawice Canis CXS MERU 3420-115 powlekane w połowie lateksem, żółto-zielone. '
            .'Rękawice robocze Canis do prac ogólnych.',
            $roxy,
        ]));
        $this->assertTrue($this->invoke($service, 'descriptionMentionsProduct', [
            'Rękawice Canis ROXY 3420-007 powlekane lateksem, żółto-zielone. Rękawice robocze do prac ogólnych.',
            $roxy,
        ]));
    }

    public function test_labeled_foreign_article_number_beats_a_word_from_the_name(): void
    {
        $id = new ProductSearchIdentity;
        $url = 'https://www.supon.rzeszow.pl/opatrunki-i-zele/3910-cederroth-bandaz-z-pianki-soft-foam-bandage-blue-6x450cm-nr-51011010.html';
        $title = 'Cederroth bandaż z pianki Soft Foam Bandage Blue 6x450cm nr 51011010';
        $beige = new Product([
            'sku' => '51011019',
            'name' => 'Bandaż z pianki Cederroth Soft Foam Bandage Beige, 6 cmx200cm',
            'manufacturer' => 'CEDERROTH',
        ]);
        $blue = new Product([
            'sku' => '51011010',
            'name' => 'Bandaż z pianki Cederroth Soft Foam Bandage Blue, 6 cmx450cm',
            'manufacturer' => 'CEDERROTH',
        ]);

        $this->assertTrue($id->pageClaimsAnotherCode($url, $title, $beige));
        $this->assertFalse($id->isConfirmedProductCard($url, $title, $title, $beige));
        $this->assertFalse($id->pageClaimsAnotherCode($url, $title, $blue));

        // apteczka Large ← strona X-Large „nr-390103”
        $large = new Product(['sku' => '390102', 'name' => 'Apteczka Cederroth First Aid Kit Large', 'manufacturer' => 'CEDERROTH']);
        $this->assertTrue($id->pageClaimsAnotherCode(
            'https://www.supon.rzeszow.pl/apteczki-przenosne/3902-cederroth-apteczka-first-aid-kit-x-large-nr-390103.html',
            '',
            $large
        ));
        $this->assertFalse($id->pageClaimsAnotherCode(
            'https://www.sklep.aio.com.pl/pierwsza-pomoc/apteczki/apteczka-pierwszej-pomocy-cederroth-first-aid-kit-large-art-390102',
            '',
            $large
        ));

        // numer zgodności innej długości („dla art. 390103, 390104”) nie jest cudzym numerem artykułu
        $holder = new Product(['sku' => '51000008', 'name' => 'Uchwyt ścienny Cederroth, dla art. 390103, 390104, 51011007', 'manufacturer' => 'CEDERROTH']);
        $this->assertFalse($id->pageClaimsAnotherCode(
            'https://www.supon.rzeszow.pl/apteczki-przenosne/3904-cederroth-uchwyt-scienny-dla-art-390103-390104-nr-51000008.html',
            '',
            $holder
        ));
        // bez przedrostka numer długości SKU nie rozstrzyga (SKU z samych cyfr u 3M/UVEX)
        $this->assertFalse($this->invoke($id, 'urlOrTitleHasLabeledForeignCode', [
            '/opatrunki-i-zele/3910-cederroth-bandaz-soft-foam-bandage-beige-51011010.html',
            '',
            $beige,
        ]));
        $this->assertTrue($this->invoke($id, 'urlOrTitleHasLabeledForeignCode', ['', 'Bandaż Cederroth ref. 51011010', $beige]));
    }

    public function test_respirator_page_of_another_protection_class_is_rejected(): void
    {
        $id = new ProductSearchIdentity;
        $p3 = $this->canis('4510-010-000-00', 'Respirator SPIRO, P3, with valve – formed');
        $p1 = $this->canis('4510-006-000-00', 'Respirator SPIRO, P1, with valve – formed');
        $spiroP1 = self::SPIRO_BASE.'profilowana-polmaska-filtrujaca-cxs-spiro-p1_p4129';
        $spiroP2 = self::SPIRO_BASE.'profilowana-polmaska-filtrujaca-cxs-spiro-p2_p5260';

        $this->assertTrue($id->pageNamesAnotherRespiratoryClass($spiroP1, '', $p3));
        $this->assertTrue($id->pageNamesAnotherRespiratoryClass($spiroP2, 'Profilowana półmaska filtrująca CXS SPIRO P2', $p3));
        $this->assertTrue($id->pageNamesAnotherRespiratoryClass($spiroP2, '', $p1));
        $this->assertFalse($id->pageNamesAnotherRespiratoryClass($spiroP1, '', $p1));
        $this->assertFalse($id->isConfirmedProductCard($spiroP1, 'Profilowana półmaska filtrująca CXS SPIRO P1', '', $p3));

        // strona zbiorcza wariantów z naszym wśród nich zostaje
        $this->assertFalse($id->pageNamesAnotherRespiratoryClass(
            self::SPIRO_BASE.'polmaski-filtrujace-cxs-spiro-p1-p2-p3',
            'Półmaski filtrujące CXS SPIRO P1 / P2 / P3',
            $p3
        ));
        // strona bez klasy niczego nie dowodzi
        $this->assertFalse($id->pageNamesAnotherRespiratoryClass(self::SPIRO_BASE.'polmaska-cxs-spiro', 'Półmaska CXS SPIRO', $p3));
    }

    public function test_respirator_sieve_leaves_3m_filters_and_non_respiratory_names(): void
    {
        $id = new ProductSearchIdentity;
        $filter = new Product(['sku' => '6035', 'name' => '3M 6035 filtr przeciwpyłowy P3 R', 'manufacturer' => '3M']);
        $this->assertFalse($id->pageNamesAnotherRespiratoryClass(
            'https://www.3m.com.pl/3M/pl_PL/p/d/b00039254/',
            '3M™ Filtr przeciwpyłowy 6035, P3 R',
            $filter
        ));
        $filterFfp = new Product(['sku' => '5935', 'name' => '3M 5935 filtr przeciwpyłowy P3 R', 'manufacturer' => '3M']);
        // FFP3 i P3 to ta sama klasa
        $this->assertFalse($id->pageNamesAnotherRespiratoryClass('https://sklep.pl/3m-5935-ffp3', 'Filtr 3M 5935 FFP3', $filterFfp));
        $this->assertTrue($id->pageNamesAnotherRespiratoryClass('https://sklep.pl/3m-5925-p2-r', 'Filtr 3M 5925 P2 R', $filterFfp));

        // dwie klasy w nazwie albo nazwa bez ochrony dróg oddechowych — sito milczy
        $two = new Product(['sku' => 'X-1', 'name' => 'Półmaska X filtry P2/P3', 'manufacturer' => 'X']);
        $this->assertFalse($id->pageNamesAnotherRespiratoryClass('https://sklep.pl/polmaska-x-p1', '', $two));
        $glove = new Product(['sku' => 'G-1', 'name' => 'Rękawice G P1', 'manufacturer' => 'X']);
        $this->assertFalse($id->pageNamesAnotherRespiratoryClass('https://sklep.pl/rekawice-g-p2', '', $glove));
    }

    private function canis(string $sku, string $name): Product
    {
        return new Product(['sku' => $sku, 'name' => $name, 'manufacturer' => 'CANIS']);
    }

    /**
     * @param  list<mixed>  $args
     */
    private function invoke(object $object, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        return $ref->invoke($object, ...$args);
    }
}
