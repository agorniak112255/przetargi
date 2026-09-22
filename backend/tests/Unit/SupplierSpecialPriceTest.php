<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductSourcePrice;
use App\Support\SupplierSpecialPrice;
use Tests\TestCase;

/**
 * Cena specjalna dostawcy to wniosek z porównania ceny konta z cennikiem bazowym × (1 − rabat standardowy).
 * Brak danych ma dawać brak oceny, nigdy „standard” — inaczej karta bez reguły rabatu wyglądałaby na sprawdzoną.
 */
final class SupplierSpecialPriceTest extends TestCase
{
    /** Heckel z cennika UVEX: cena bazowa przeliczona z euro, cena konta o 26 gr niżej = zaokrąglenie, nie cena specjalna. */
    public function test_roznica_w_tolerancji_to_cena_standardowa(): void
    {
        $result = SupplierSpecialPrice::evaluate(216.75, 255.31, 15.0);

        $this->assertNotNull($result);
        $this->assertSame(SupplierSpecialPrice::STANDARD, $result['status']);
        $this->assertSame(217.01, $result['standard_price']);
        $this->assertSame(0.26, $result['saving_net']);
        $this->assertSame(15.1, $result['actual_discount_percent']);
    }

    public function test_cena_konta_ponizej_standardowej_to_cena_specjalna(): void
    {
        $result = SupplierSpecialPrice::evaluate(200.0, 255.31, 15.0);

        $this->assertNotNull($result);
        $this->assertSame(SupplierSpecialPrice::SPECIAL, $result['status']);
        $this->assertSame(217.01, $result['standard_price']);
        $this->assertSame(17.01, $result['saving_net']);
        $this->assertSame(21.66, $result['actual_discount_percent']);
    }

    public function test_cena_konta_powyzej_standardowej_to_gorzej_niz_standard(): void
    {
        $result = SupplierSpecialPrice::evaluate(230.0, 255.31, 15.0);

        $this->assertNotNull($result);
        $this->assertSame(SupplierSpecialPrice::WORSE_THAN_STANDARD, $result['status']);
        $this->assertSame(-12.99, $result['saving_net']);
    }

    /** Tolerancja 0,5% ceny standardowej (217,01 → 1,085 zł): 1,08 zł mieści się, 1,09 zł już nie. */
    public function test_granica_tolerancji_procentowej(): void
    {
        $this->assertSame(SupplierSpecialPrice::STANDARD, SupplierSpecialPrice::evaluate(215.93, 255.31, 15.0)['status'] ?? null);
        $this->assertSame(SupplierSpecialPrice::SPECIAL, SupplierSpecialPrice::evaluate(215.92, 255.31, 15.0)['status'] ?? null);
        $this->assertSame(SupplierSpecialPrice::STANDARD, SupplierSpecialPrice::evaluate(218.09, 255.31, 15.0)['status'] ?? null);
        $this->assertSame(SupplierSpecialPrice::WORSE_THAN_STANDARD, SupplierSpecialPrice::evaluate(218.10, 255.31, 15.0)['status'] ?? null);
    }

    /** Tani wyrób: 0,5% z 10 zł to 5 gr — obowiązuje minimum 5 gr, więc 4 gr różnicy to zaokrąglenie, 6 gr już nie. */
    public function test_minimum_tolerancji_piec_groszy(): void
    {
        $this->assertSame(SupplierSpecialPrice::STANDARD, SupplierSpecialPrice::evaluate(9.96, 10.0, 0.0)['status'] ?? null);
        $this->assertSame(SupplierSpecialPrice::SPECIAL, SupplierSpecialPrice::evaluate(9.94, 10.0, 0.0)['status'] ?? null);
        $this->assertSame(SupplierSpecialPrice::WORSE_THAN_STANDARD, SupplierSpecialPrice::evaluate(10.06, 10.0, 0.0)['status'] ?? null);
    }

    public function test_brak_danych_to_brak_oceny(): void
    {
        $this->assertNull(SupplierSpecialPrice::evaluate(null, 255.31, 15.0));
        $this->assertNull(SupplierSpecialPrice::evaluate(200.0, null, 15.0));
        $this->assertNull(SupplierSpecialPrice::evaluate(200.0, 255.31, null));
        $this->assertNull(SupplierSpecialPrice::evaluate(0.0, 255.31, 15.0));
        $this->assertNull(SupplierSpecialPrice::evaluate(200.0, 0.0, 15.0));
    }

    public function test_for_slot_czyta_pola_slotu(): void
    {
        $slot = new ProductSourcePrice([
            'purchase_price' => 200,
            'base_price_net' => 255.31,
            'standard_discount_percent' => 15,
        ]);
        $this->assertSame(SupplierSpecialPrice::SPECIAL, SupplierSpecialPrice::forSlot($slot)['status'] ?? null);

        $bezRegul = new ProductSourcePrice(['purchase_price' => 200, 'base_price_net' => 255.31]);
        $this->assertNull(SupplierSpecialPrice::forSlot($bezRegul));
    }
}
