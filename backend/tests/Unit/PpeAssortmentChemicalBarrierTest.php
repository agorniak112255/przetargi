<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\PpeAssortment;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kombinezon „chemoodporny” bez dowodu bariery dla cieczy (typ 1–4, EN 14605, EN 943, Tychem) — decyzja właściciela
 * z 25.09.2026: typ 5/6 (pyły, ograniczone rozpryski) zostaje na liście, ale pod progiem zapisu przetargu.
 */
final class PpeAssortmentChemicalBarrierTest extends TestCase
{
    private const CHEMICAL = 'Kombinezon chemoodporny antyelektrostatyczny, w szczególności na kwas siarkowy 96%';

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function barrierEvidence(): array
    {
        return [
            'typ 4/5/6' => ['Kombinezon ochronny typ 4/5/6, EN 1149-5', true],
            'typów ochrony 3/4/5' => ['Kombinezon spełnia wymagania typów ochrony 3/4/5', true],
            'Typ 3-B, 4-B' => ['Kombinezon Typ 3-B, 4-B, EN 1149-5', true],
            'type 3' => ['Chemical protective coverall, type 3 and 4', true],
            'EN 14605' => ['Kombinezon zgodny z EN 14605:2005+A1:2009', true],
            'EN 943' => ['Kombinezon gazoszczelny EN 943-1', true],
            'Tychem' => ['Kombinezon DuPont Tychem 2000 C', true],
            'typ 5/6' => ['Kombinezon ochronny typ 5/6, EN ISO 13982-1, EN 13034', false],
            'Typ 5 i 6' => ['Kombinezon Typ 5 i 6, antystatyczny EN 1149-5', false],
            'typ 6 PB' => ['Fartuch typ 6 [PB], EN 13034', false],
            'kategoria III bez typu' => ['Kombinezon kategorii III, EN 1149-5', false],
        ];
    }

    #[DataProvider('barrierEvidence')]
    public function test_barrier_evidence_on_card(string $description, bool $proven): void
    {
        $product = $this->coverall('Kombinezon ochronny', $description);

        $this->assertSame(! $proven, (new PpeAssortment)->missingChemicalBarrierEvidence(self::CHEMICAL, $product));
    }

    /** Przegląd 25.09.2026: typ ochrony wpisany ręcznie w parametrach karty to najpewniejszy cytat — też jest dowodem. */
    public function test_barrier_from_manual_specs_counts(): void
    {
        $product = $this->coverall('Kombinezon ochronny', 'Kombinezon z laminatu.');
        $product->forceFill(['manual_specs' => [['label' => 'Typ ochrony', 'value' => '3B, 4B']]]);

        $this->assertFalse((new PpeAssortment)->missingChemicalBarrierEvidence(self::CHEMICAL, $product));
    }

    public function test_barrier_from_enrichment_norms_counts(): void
    {
        $product = $this->coverall('Kombinezon ochronny', 'Kombinezon z laminatu.', ['norms' => ['EN 14605 typ 3']]);

        $this->assertFalse((new PpeAssortment)->missingChemicalBarrierEvidence(self::CHEMICAL, $product));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function requirementsWithoutChemicalBarrier(): array
    {
        return [
            'wodoochronny z kaloszami' => ['Kombinezon wodoochronny z wgrzanymi kaloszami'],
            'ochronny typ 5/6' => ['Kombinezon ochronny typ 5/6'],
            'chemoodporny, ale wprost typ 5/6' => ['Kombinezon chemoodporny jednorazowy typ 5/6, EN 13034'],
            'fartuch chemoodporny' => ['Fartuch chemoodporny kwasoodporny'],
            'rękawice chemoodporne' => ['Rękawice chemoodporne na kwas siarkowy'],
            'kombinezon spawalniczy' => ['Kombinezon spawalniczy trudnopalny'],
        ];
    }

    #[DataProvider('requirementsWithoutChemicalBarrier')]
    public function test_requirement_without_chemical_barrier_is_not_checked(string $requirement): void
    {
        $product = $this->coverall('Kombinezon ochronny', 'Kombinezon ochronny typ 5/6, EN ISO 13982-1, EN 13034');

        $this->assertFalse((new PpeAssortment)->missingChemicalBarrierEvidence($requirement, $product));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function chemicalBarrierRequirements(): array
    {
        return [
            'chemoodporny' => ['Kombinezon chemoodporny'],
            'przeciwchemiczny' => ['Kombinezon przeciwchemiczny z kapturem'],
            'kwasoodporny' => ['Kombinezon kwasoodporny'],
            'kwas siarkowy' => ['Kombinezon ochronny do pracy z kwasem siarkowym'],
            'kwasy i ługi' => ['Kombinezon ochronny odporny na kwasy i ługi'],
        ];
    }

    #[DataProvider('chemicalBarrierRequirements')]
    public function test_chemical_requirement_caps_type_5_6_coverall(string $requirement): void
    {
        $product = $this->coverall('Kombinezon ochronny', 'Kombinezon ochronny typ 5/6, EN ISO 13982-1, EN 13034');

        $this->assertTrue((new PpeAssortment)->missingChemicalBarrierEvidence($requirement, $product));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function coverall(string $name, string $description, array $payload = []): Product
    {
        $product = new Product;
        $product->forceFill([
            'sku' => 'K-1',
            'name' => $name,
            'description' => $description,
            'enrichment_payload' => $payload,
        ]);

        return $product;
    }
}
