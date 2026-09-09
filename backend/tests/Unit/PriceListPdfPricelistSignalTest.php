<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PriceListPdfTextExtractor;
use PHPUnit\Framework\TestCase;

final class PriceListPdfPricelistSignalTest extends TestCase
{
    public function test_cover_letter_is_not_a_pricelist(): void
    {
        $text = <<<'TXT'
dot. DuPont Personal Protection - cennik 2018
Szanowni Państwo!
Jak Państwo widzą w załączonym cenniku, ceny wielu produktów pozostają bez zmian.
Od 18.12.2017 zamówienia będą fakturowane zgodnie z cennikiem.
Z poważaniem Andrzej Palka
TXT;
        $this->assertFalse((new PriceListPdfTextExtractor)->looksLikePricelist($text));
    }

    public function test_dupont_table_text_looks_like_pricelist(): void
    {
        $text = <<<'TXT'
Reference Article Number Size Price(€/pc.)
TD 0125 S WH 00 D14681379 S 2.68
TD 0125 S WH 00 D14681380 M 2.68
TD 0127 S WH 00 D14681302 S 3.36
TY CCF5 S WH 00 D13395579 S 3.63
TS CHF5 S WH DE D14886039 S 2.24
TY CHF5 S WH 00 D13395300 M 4.10
TF CHF5 T WH 00 D14001111 L 5.20
TXT;
        $this->assertTrue((new PriceListPdfTextExtractor)->looksLikePricelist($text));
    }

    public function test_renex_table_without_prices_in_text_is_pricelist(): void
    {
        $text = <<<'TXT'
Cennik dla dystrybutorów (PL)
Nazwa Kod Cena
1. FARTUCH STANDARD CE-FARTU.065
2. KASAK CE-KASAK.065
3. CZAPKA CE-CZAPKA.065
4. CE-KOSZU.065
5. CE-KOSZU.065-LS
TKANINA 065
TXT;
        $this->assertTrue((new PriceListPdfTextExtractor)->looksLikePricelist($text));
    }

    public function test_sungboo_cards_without_decimal_prices_are_pricelist(): void
    {
        $text = <<<'TXT'
SUNGBOO CENNIK DLA ODBIORCÓW HURTOWYCH
Cena netto Rozmiar EN388 Ilo par/opakowanie
SPECIAL CUT 926 4X42B 6/120 Rkawice antyprzeciciowe
Cena netto 1054 EXTRA CUT
Cena netto 1628 PREMIUM CUT
Cena netto 354 11N-N08
TXT;
        $this->assertTrue((new PriceListPdfTextExtractor)->looksLikePricelist($text));
    }

    public function test_rand_glued_to_price_looks_like_pricelist(): void
    {
        $text = <<<'TXT'
LEMAITRE safety footwear Price list End User
Pastel Brand Codes Description ExcL VAT Incl VAT
805001 LEMAITRE 8050 Apollo Shoe 446.00R 508.44R
803301 LEMAITRE 8033 Chainsaw Boot 398.00R 453.72R
800701 LEMAITRE 8007 Clog Slip-on 443.00R 505.02R
800801 LEMAITRE 8008 Clog Slip-on 443.00R 505.02R
802301 LEMAITRE 8023 Cyclone Shoe 462.00R 526.68R
804702 LEMAITRE 8047 Condor Sandal 518.00R 590.52R
802501 LEMAITRE 8025 Eagle Boot 601.00R 685.14R
803501 LEMAITRE 8035 Eagle Boot SMS 713.00R 812.82R
TXT;
        $this->assertTrue((new PriceListPdfTextExtractor)->looksLikePricelist($text));
    }

    public function test_chunks_whole_list_by_price_budget(): void
    {
        $lines = [];
        for ($i = 1; $i <= 159; $i++) {
            $lines[] = sprintf('HW%05d Pozycja %d 12,50', $i, $i);
        }
        $text = implode("\n", $lines);
        $chunks = (new PriceListPdfTextExtractor)->chunkByPriceBudget($text, 45, 7000);
        $this->assertGreaterThan(1, count($chunks));
        $joined = implode("\n", $chunks);
        $this->assertSame(159, preg_match_all('/\b12,50\b/u', $joined));
        $this->assertStringContainsString('HW00159', $joined);
    }

    public function test_splits_single_long_line_into_many_chunks(): void
    {
        $parts = [];
        for ($i = 1; $i <= 159; $i++) {
            $parts[] = sprintf('HW%05d Pozycja %d 12,50', $i, $i);
        }
        $text = implode(' ', $parts);
        $chunks = (new PriceListPdfTextExtractor)->chunkByPriceBudget($text, 45, 7000);
        $this->assertGreaterThan(2, count($chunks));
        $this->assertSame(159, preg_match_all('/\b12,50\b/u', implode("\n", $chunks)));
    }
}
