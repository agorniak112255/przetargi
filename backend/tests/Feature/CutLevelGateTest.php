<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductAiSearchService;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pomiar 20260914_191625, poz. 2 przebieg 3: MAPA ULTRANE 681 (EN 388 4X21A, przecięcie ISO poziom A — rękawica montażowa)
 * wygrała z oceną 95 modelu przy wymaganiu rękawic odpornych na przecięcie do szkła i skalpela. Decyzja użytkownika:
 * bez podanego poziomu — co najmniej B. Karta z niższym poziomem odpada w bramce, karta bez poziomu zostaje.
 */
final class CutLevelGateTest extends TestCase
{
    use RefreshDatabase;

    private const POZ2 = 'Rękawice ochronne odporne na przecięcie, przeznaczone do prac z narzędziami tnącymi, ostrymi elementami i szkłem '
        .'– m.in. w budownictwie, przemyśle szklarskim, chemicznym i motoryzacyjnym oraz przy pracach ze skalpelem lub nożem drukarskim. '
        .'Wymagane: ochrona dłoni przed przecięciem i ścieraniem potwierdzona oznakowaniem zgodnie z EN 388; konstrukcja zapewniająca '
        .'elastyczność, wygodę i precyzję pracy.';

    public function test_glove_with_cut_level_below_b_is_rejected_and_unknown_level_stays(): void
    {
        $ultrane = $this->glove('34681008', 'ULTRANE 681', 'MAPA',
            'EN 388 – odporność mechaniczna (ścieranie 4, przecięcie Coup X, rozerwanie 2, przekłucie 1, przecięcie ISO 13977 A), EN ISO 21420:2020',
            'Rękawice ochronne MAPA ULTRANE 681 to ultracienkie rękawice z powłoką nitrylową do lekkich i precyzyjnych prac montażowych.');
        $maxiflex = $this->glove('34-8743', 'Ściągacz, oblanie części chwytnej, EN388: 3', 'ATG',
            'EN 388:2016 – 4331B (ścieranie 4, przecięcie Coup Test 3, rozerwanie 3, przekłucie 1, przecięcie ISO 13977: B)',
            'Rękawice antyprzecięciowe ATG MaxiFlex Cut 34-8743 do precyzyjnych prac z ryzykiem przecięcia.');
        $noLevel = $this->glove('34837018', 'Rękawice antyprzecięciowe powlekane', 'ATG', 'EN 388',
            'Rękawice antyprzecięciowe z dzianiny HPPE powlekane nitrylem, do prac z ostrymi elementami.');
        $service = app(ProductAiSearchService::class);

        $requirement = (new \ReflectionMethod($service, 'assortmentText'))->invoke($service, self::POZ2, 'rękawice antyprzecięciowe');
        $kept = (new \ReflectionMethod($service, 'keepCompatible'))->invoke($service, $requirement, collect([$ultrane, $maxiflex, $noLevel]));

        $this->assertSame(['34-8743', '34837018'], $kept->pluck('sku')->all());
        $this->assertFalse($service->debugCompatibilityGates(self::POZ2, 'rękawice antyprzecięciowe', $ultrane)['poziom cięcia']);
        $this->assertTrue($service->debugCompatibilityGates(self::POZ2, 'rękawice antyprzecięciowe', $maxiflex)['poziom cięcia']);
    }

    /**
     * Uwagi eksperta 24.09 do przetargu 1, poz. 2: RCFB-2369 COVENT FOAM (EN 388 2131X — Coup Test 1, ISO nie badano)
     * przechodziło jako „bez danych”. Decyzja użytkownika: sam Coup Test 0–1 bez litery ISO przy wymaganym B odpada;
     * wyższa cyfra Coup Test bez litery zostaje (nie przeliczamy).
     */
    public function test_glove_with_only_coup_1_and_no_iso_letter_is_rejected(): void
    {
        $rcfb = $this->glove('RCFB-2369', 'RĘKAWICE COVENT FOAM Kat.2 NA BLISTRZE', 'Polstar',
            'EN 388:2016+A1:2018 – poziom 2131X, EN ISO 21420:2020',
            'Dziane rękawice poliestrowe z powłoką ze spienionego lateksu do prac wymagających ochrony przed uszkodzeniami mechanicznymi.');
        $coup3 = $this->glove('COUP-3', 'Rękawice powlekane', 'TEST', 'EN 388:2016 – 4343X',
            'Rękawice z dzianiny powlekane nitrylem.');
        $service = app(ProductAiSearchService::class);

        $this->assertFalse($service->debugCompatibilityGates(self::POZ2, 'rękawice antyprzecięciowe', $rcfb)['poziom cięcia']);
        $this->assertTrue($service->debugCompatibilityGates(self::POZ2, 'rękawice antyprzecięciowe', $coup3)['poziom cięcia']);
        // wymaganie bez odporności na przecięcie — Coup Test 1 nie przeszkadza
        $this->assertTrue($service->debugCompatibilityGates('Rękawice powlekane lateksem do prac montażowych, EN 388', null, $rcfb)['poziom cięcia']);
    }

    public function test_requirement_without_cut_protection_does_not_filter_by_cut_level(): void
    {
        $ultrane = $this->glove('34681008', 'ULTRANE 681', 'MAPA',
            'EN 388 – przecięcie ISO 13977 A, EN ISO 21420:2020',
            'Rękawice ochronne MAPA ULTRANE 681 z powłoką nitrylową do lekkich prac montażowych.');
        $service = app(ProductAiSearchService::class);

        $this->assertTrue($service->debugCompatibilityGates('Rękawice powlekane nitrylem do prac montażowych, EN 388', null, $ultrane)['poziom cięcia']);
    }

    private function glove(string $sku, string $name, string $manufacturer, string $norms, string $description): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'norms' => $norms,
            'description' => $description,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }
}
