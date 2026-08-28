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
}
