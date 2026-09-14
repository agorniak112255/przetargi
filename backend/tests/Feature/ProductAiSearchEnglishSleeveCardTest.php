<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductAiSearchService;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Przetarg 1 poz. 1 (raporty 14.09: 3/3 przebiegi puste, „żadna karta nie przeszła bramek”): oczekiwana karta Ansell
 * HyFlex 11-202 ma angielski opis ze zrzutu strony sklepu. Bramka rodziny rozpoznawała rękaw („arm protector”), ale dowód
 * żargonu szukał słowa „rękaw”, a antystatyka słowa „antystatyczn” — karta odpadała przed rankingiem, a diagnostyka
 * pokazywała tylko „w kandydatach=nie”.
 */
final class ProductAiSearchEnglishSleeveCardTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIREMENT = 'Ochraniacz przedramienia (rękaw) chroniący przed przecięciem, długość ok. 475 mm (19\'\'), w kolorze '
        .'fluorescencyjnym żółtym o wysokiej widzialności. Konstrukcja bezszwowa, dzianina z przędzy nylon/poliester/włókno szklane; '
        .'regulowane zapięcie na rzep; wyrób antystatyczny, bez lateksu i bez silikonu; dopuszczony do kontaktu z żywnością (zgodność '
        .'z wymaganiami FDA). Wymagane: ŚOI kategorii III; EN 420:2003+A1:2009; EN 388 z poziomami min. 2.X.4.2.C; EN 407 poziom 1.';

    public function test_english_arm_protector_card_passes_every_compatibility_gate(): void
    {
        $sleeve = $this->card('11202000', 'HyFlex 11202 SIZE 19\'\'/47,5 cm', [
            'norms' => 'EN 420:2003 + A1:2009, EN 388:2016 (2.X.4.2.C), EN 407 (X.1.X.X.X)',
            'description' => 'The new HyFlex® 11-202 HI-VIZ™ arm protector offers optimum wearing comfort thanks to its anatomical fit. '
                .'Its secure fit is ensured by the adjustable Velcro fastener. Ansell HyFlex 11-202 Hi-Vis Cut-Resistant Sleeve with '
                .'Velcro Fixing System. Standards: EN 420:2003 + A1:2009, Cat.III EN 407(X.1.X.X.X), EN388 (2.X.4.2.C). '
                .'Lining material: nylon, polyester, glass fibre. extra features: antistatic, latex-free',
        ]);
        $glove = $this->card('11724110', 'HYFLEX 11724', [
            'norms' => 'EN 388:2016, EN ISO 21420:2020',
            'description' => 'Rękawice Ansell HyFlex 11-724 powlekane poliuretanem PU, przędza HPPE, ścieg 13, odporność na przecięcie poziom B, antystatyczne.',
        ]);
        $service = app(ProductAiSearchService::class);

        $gates = $service->debugCompatibilityGates(self::REQUIREMENT, 'narękawniki ochronne przeciwprzecięciowe', $sleeve);
        $this->assertSame([], array_keys(array_filter($gates, static fn (bool $ok): bool => ! $ok)), 'angielski rękaw przechodzi wszystkie bramki');
        $this->assertFalse(
            $service->debugCompatibilityGates(self::REQUIREMENT, 'narękawniki ochronne przeciwprzecięciowe', $glove)['rodzina'],
            'rękawica dalej odpada na bramce rodziny (rękaw ≠ rękawica)'
        );

        $requirement = (new \ReflectionMethod($service, 'assortmentText'))->invoke($service, self::REQUIREMENT, 'narękawniki ochronne przeciwprzecięciowe');
        $kept = (new \ReflectionMethod($service, 'keepCompatible'))->invoke($service, $requirement, collect([$glove, $sleeve]));
        $this->assertSame(['11202000'], $kept->pluck('sku')->all());
    }

    /** @param array<string, mixed> $attrs */
    private function card(string $sku, string $name, array $attrs): Product
    {
        return Product::query()->create(array_merge([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'Ansell',
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'catalog_price_net' => 12,
            'purchase_price' => 10.74,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ], $attrs));
    }
}
