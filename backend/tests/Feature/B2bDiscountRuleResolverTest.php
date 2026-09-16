<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bDiscountRule;
use App\Services\B2b\B2bDiscountRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rabaty konta B2B dla witryn z samą ceną katalogową (protekt.pl). Reguły sprawdzane po kolei,
 * pierwsza pasująca wygrywa; karta bez reguły nie dostaje rabatu — to ma zostać pominięciem
 * z powodem, a nie cichym 0%, bo cena katalogowa zapisana jako zakupu zawyża każdą wycenę.
 */
final class B2bDiscountRuleResolverTest extends TestCase
{
    use RefreshDatabase;

    private B2bAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = B2bAccount::query()->create([
            'username' => 'PROTEKT',
            'password' => 'x',
            'sites' => ['protekt.pl'],
            'connector' => 'protekt',
        ]);
    }

    public function test_pierwsza_pasujaca_regula_wygrywa(): void
    {
        // Prawdziwy przypadek z cennika: w kategorii „urządzenia samohamowne” leżą razem
        // ROLEX (30%), CR200 (10%) i CR300 (20%) — sama kategoria rabatu nie rozstrzyga.
        $this->rule(1, 'CR200', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'CR200', 10.0);
        $this->rule(2, 'CR300', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'CR300', 20.0);
        $this->rule(3, 'Samohamowne', B2bDiscountRule::FIELD_CATEGORY, B2bDiscountRule::TYPE_EQUALS, 'urzadzenia-samohamowne', 30.0);

        $resolver = new B2bDiscountRuleResolver($this->account->id);

        $this->assertSame(10.0, $resolver->resolve('CR20010', 'urzadzenia-samohamowne', 'CR 200')?->discountPercent);
        $this->assertSame(20.0, $resolver->resolve('CR30018', 'urzadzenia-samohamowne', 'CR 300')?->discountPercent);
        $this->assertSame(30.0, $resolver->resolve('RX10020', 'urzadzenia-samohamowne', 'ROLEX')?->discountPercent);
    }

    public function test_brak_dopasowania_daje_null_a_nie_zero(): void
    {
        $this->rule(1, 'CR200', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'CR200', 10.0);

        $resolver = new B2bDiscountRuleResolver($this->account->id);

        $this->assertNull($resolver->resolve('XX999', 'inna-kategoria', 'Coś nowego'));
        $this->assertSame(1, $resolver->missedCount());
        $this->assertSame(0, $resolver->matchedCount());
    }

    public function test_dopasowanie_ignoruje_wielkosc_liter_i_spacje(): void
    {
        $this->rule(1, 'Zawiesia WS', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, '  ws ', 25.0);

        $resolver = new B2bDiscountRuleResolver($this->account->id);

        $this->assertSame(25.0, $resolver->resolve('WS 020 03', 'zawiesia', 'WS Zawiesia taśmowe')?->discountPercent);
    }

    public function test_lapanka_na_koncu_listy_lapie_reszte(): void
    {
        $this->rule(1, 'CR200', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'CR200', 10.0);
        $this->rule(99, 'Pozostałe', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_ANY, '', 20.0);

        $resolver = new B2bDiscountRuleResolver($this->account->id);

        $this->assertSame(10.0, $resolver->resolve('CR20010', null, 'CR 200')?->discountPercent);
        $this->assertSame(20.0, $resolver->resolve('XX999', null, 'Coś nowego')?->discountPercent);
        $this->assertSame(0, $resolver->missedCount());
    }

    public function test_pusty_wzorzec_nie_lapie_wszystkiego(): void
    {
        // Wzorzec wyczyszczony w panelu nie może po cichu zamienić się w regułę „na wszystko”.
        $this->rule(1, 'Pusta', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, '', 45.0);

        $resolver = new B2bDiscountRuleResolver($this->account->id);

        $this->assertNull($resolver->resolve('CR20010', 'urzadzenia-samohamowne', 'CR 200'));
    }

    public function test_liczniki_trafien_trafiaja_do_regul(): void
    {
        $matched = $this->rule(1, 'CR200', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'CR200', 10.0);
        $empty = $this->rule(2, 'CR300', B2bDiscountRule::FIELD_CATALOG_NO, B2bDiscountRule::TYPE_PREFIX, 'CR300', 20.0);

        $resolver = new B2bDiscountRuleResolver($this->account->id);
        $resolver->resolve('CR20010', null, 'CR 200');
        $resolver->resolve('CR20018', null, 'CR 200');
        $resolver->flushCounters();

        $this->assertSame(2, $matched->refresh()->last_matched_count);
        $this->assertSame(0, $empty->refresh()->last_matched_count);
        $this->assertNotNull($empty->last_matched_at);
    }

    private function rule(int $position, string $name, string $field, string $type, string $pattern, float $discount): B2bDiscountRule
    {
        return B2bDiscountRule::query()->create([
            'b2b_account_id' => $this->account->id,
            'position' => $position,
            'name' => $name,
            'match_field' => $field,
            'match_type' => $type,
            'pattern' => $pattern,
            'discount_percent' => $discount,
        ]);
    }
}
