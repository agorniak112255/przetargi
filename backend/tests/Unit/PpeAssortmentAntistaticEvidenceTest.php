<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\PpeAssortment;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Siła dowodu antystatyki (decyzja właściciela 25.09.2026, D7a): obuwie — „ESD” albo EN 61340; rękawice, odzież
 * i pozostałe — „ESD”, EN 1149 albo EN 16350. Samo słowo „antyelektrostatyczne / antystatyczne” to propozycja do
 * sprawdzenia („weak”), nie trafienie. Bramka (productShowsAntistatic) przepuszcza obie siły.
 */
final class PpeAssortmentAntistaticEvidenceTest extends TestCase
{
    private const FOOTWEAR = 'Sandały ochronne (obuwie bezpieczne z odkrytą cholewką) kategorii S1 P wg EN ISO 20345. '
        .'Wymagane: zabudowana pięta; podnosek ochronny; właściwości antyelektrostatyczne (ESD).';

    private const GLOVES = 'Rękawice montażowe powlekane uvex phynomic z funkcją ESD';

    private const COVERALL = 'Kombinezon chemoodporny (w szczególności na kwas siarkowy 96%) antyelektrostatyczny, rozmiar uniwersalny';

    private PpeAssortment $assortment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assortment = new PpeAssortment;
    }

    #[Test]
    public function footwear_strong_evidence_is_esd_or_en_61340(): void
    {
        $this->assertSame(PpeAssortment::FAMILY_FOOTWEAR, $this->assortment->family(self::FOOTWEAR));

        $this->assertSame('strong', $this->assortment->antistaticEvidence(self::FOOTWEAR, 'ARSO 701 616560 S1 P ESD'));
        $this->assertSame('strong', $this->assortment->antistaticEvidence(
            self::FOOTWEAR,
            'ART 702 Air 6660 OB A E FO Spełnia normę EN ISO 20347:2012 oraz wymagania EN IEC 61340-4-3:2018.'
        ));
        $this->assertSame('strong', $this->assortment->antistaticEvidence(self::FOOTWEAR, 'Sandały S1 P, norma: ESD według EN IEC 61340-4-3:2018'));
    }

    #[Test]
    public function footwear_word_only_or_clothing_norm_is_weak(): void
    {
        $this->assertSame('weak', $this->assortment->antistaticEvidence(self::FOOTWEAR, 'Sandały S1 P SRC, antyelektrostatyczna podeszwa PU'));
        $this->assertSame('weak', $this->assortment->antistaticEvidence(self::FOOTWEAR, 'Półbuty robocze S1 antystatyczne'));
        // EN 1149 to norma odzieży — na bucie nie jest dowodem ESD, ale bramka go przepuszcza jako propozycję.
        $this->assertSame('weak', $this->assortment->antistaticEvidence(self::FOOTWEAR, 'Trzewiki S3 EN 1149-5'));
        $this->assertNull($this->assortment->antistaticEvidence(self::FOOTWEAR, 'Sandały robocze S1 P z podnoskiem kompozytowym'));
    }

    #[Test]
    public function gloves_and_clothing_strong_evidence_is_esd_en_1149_or_en_16350(): void
    {
        $this->assertSame(PpeAssortment::FAMILY_GLOVES, $this->assortment->family(self::GLOVES));

        // Karta 6003805 z produkcji (S01): ESD tylko w nazwie.
        $this->assertSame('strong', $this->assortment->antistaticEvidence(self::GLOVES, 'Rękawice Phynomic airLite A ESD 6003805'));
        $this->assertSame('strong', $this->assortment->antistaticEvidence(self::GLOVES, 'Rękawice powlekane PU, EN 388:2016, EN 1149-5:2018'));
        $this->assertSame('strong', $this->assortment->antistaticEvidence(self::GLOVES, 'Rękawice powlekane PU, EN1149-5'));
        $this->assertSame('strong', $this->assortment->antistaticEvidence(self::GLOVES, 'Rękawice powlekane PU, 1149-5'));
        $this->assertSame('strong', $this->assortment->antistaticEvidence(self::GLOVES, 'Rękawice powlekane PU, EN 16350:2014'));
        $this->assertSame('strong', $this->assortment->antistaticEvidence(self::COVERALL, '4000-OR CVRL HOOD 130.5XL EN 14605, EN 1149-5'));
    }

    #[Test]
    public function gloves_word_only_evidence_is_weak(): void
    {
        $this->assertSame('weak', $this->assortment->antistaticEvidence(self::GLOVES, 'Rękawice antystatyczne powlekane poliuretanem'));
        $this->assertSame('weak', $this->assortment->antistaticEvidence(self::GLOVES, 'Rękawice antyelektrostatyczne z włóknem węglowym'));
        $this->assertSame('weak', $this->assortment->antistaticEvidence(self::GLOVES, 'Anti-static PU coated gloves'));
        // Decyzja C dosłownie: EN 61340 wymieniona tylko przy obuwiu — na rękawicy bez ESD / EN 1149 / EN 16350 to propozycja.
        $this->assertSame('weak', $this->assortment->antistaticEvidence(self::GLOVES, 'Rękawice, IEC 61340-5-1'));
        // Karta 6004005 z produkcji (S01): brak ESD, norm i słowa o antystatyce.
        $this->assertNull($this->assortment->antistaticEvidence(
            self::GLOVES,
            'Rękawice Phynomic lite 6004005 Uvex phynomic lite to najlżejsze rękawice ochronne w swojej klasie - redukujące uczucie zmęczenia.'
        ));
    }

    /** Wymaganie żąda normy albo ESD wprost — samo słowo nie (decyzja właściciela z 25.09.2026). */
    #[Test]
    public function strong_antistatic_is_required_only_by_esd_or_a_norm_number(): void
    {
        $this->assertTrue($this->assortment->requiresStrongAntistatic('Sandały S1 P, właściwości antyelektrostatyczne (ESD)'));
        $this->assertTrue($this->assortment->requiresStrongAntistatic('Rękawice antyelektrostatyczne wg EN 16350'));
        $this->assertTrue($this->assortment->requiresStrongAntistatic('Kombinezon EN 1149-5'));
        $this->assertTrue($this->assortment->requiresStrongAntistatic('Obuwie EN IEC 61340-4-3'));
        $this->assertFalse($this->assortment->requiresStrongAntistatic('Rękawice chemoodporne, antyelektrostatyczne z normą EN ISO 374-1, rozmiar 10'));
        $this->assertFalse($this->assortment->requiresStrongAntistatic('Ochraniacz przedramienia, wyrób antystatyczny, bez lateksu'));
        $this->assertFalse($this->assortment->requiresStrongAntistatic('Rękawice kod 11490'), 'liczba w kodzie to nie norma');
    }

    /** Numery norm antystatyki do uzasadnienia limitu i do dopasowania normy wymienionej w wymaganiu. */
    #[Test]
    public function antistatic_norm_numbers_are_read_from_text(): void
    {
        $this->assertSame(['1149'], $this->assortment->antistaticNormsIn('Trzewiki S3, EN 1149-5:2018'));
        $this->assertSame(['61340', '16350'], $this->assortment->antistaticNormsIn('IEC 61340-5-1; EN 16350:2014'));
        $this->assertSame([], $this->assortment->antistaticNormsIn('Rękawice kod 11490, EN 388 4131X'));
    }

    /**
     * Na karcie norma liczy się tylko w zapisie, który czyta bramka i siła dowodu (EN 1149, 1149-5, 61340, EN 16350) —
     * goła liczba bywa kodem wyrobu i dawała uzasadnienie „karta podaje EN 1149” przy rękawicy z samym słowem.
     */
    #[Test]
    public function card_norms_are_read_only_in_norm_notation(): void
    {
        $this->assertSame([], $this->assortment->productAntistaticNorms(
            $this->card('ATG 1149', 'Rękawice antystatyczne 1149', ['norms' => 'EN 388:2016 4131X'])
        ));
        $this->assertSame(['1149'], $this->assortment->productAntistaticNorms(
            $this->card('R-1', 'Rękawice powlekane', ['norms' => 'EN 388:2016, EN 1149-5:2018'])
        ));
        $this->assertSame(['1149'], $this->assortment->productAntistaticNorms($this->card('R-2', 'Rękawice powlekane, 1149-5')));
        $this->assertSame(['61340'], $this->assortment->productAntistaticNorms($this->card('R-3', 'Rękawice, IEC 61340-5-1')));
        $this->assertSame(['16350'], $this->assortment->productAntistaticNorms($this->card('R-4', 'Rękawice, EN ISO 16350')));
        $this->assertSame([], $this->assortment->productAntistaticNorms($this->card('R-5', 'Rękawice nitrylowe 16350 szt.')));
    }

    /**
     * Przegląd tury B: liczba z cyfrą obok to kod wyrobu, nie norma — sklejone „11495” czytało się jak EN 1149-5,
     * a SKU linki DBI-SALA 6134006 jak EN 61340 (także w wymaganiu, które wtedy włączało bramkę antystatyki).
     * Sklejony zapis normy (EN61340, IEC61340) jest normą — dotąd bramka go nie widziała.
     */
    #[Test]
    public function product_codes_are_not_antistatic_norms_but_glued_norm_notation_is(): void
    {
        $this->assertFalse($this->assortment->productShowsAntistatic('Rękawice powlekane 11495'));
        $this->assertFalse($this->assortment->productShowsAntistatic('Linka bezpieczeństwa DBI-SALA 6134006'));
        $this->assertFalse($this->assortment->requiresAntistatic('Linka bezpieczeństwa z amortyzatorem DBI-SALA 6134006'));
        $this->assertFalse($this->assortment->requiresAntistatic('Rękawice powlekane 11495, rozmiar 9'));
        $this->assertSame([], $this->assortment->productAntistaticNorms($this->card('R-11495', 'Rękawice antystatyczne 11495')));
        $this->assertFalse($this->assortment->productMeetsAntistaticRequirement(
            'Półbuty ochronne S1P ESD',
            $this->card('P-11495', 'Półbuty ochronne S1P 11495', ['norms' => 'EN ISO 20345:2011 S1P'])
        ));

        $this->assertTrue($this->assortment->productShowsAntistatic('Sandały S1 P, EN61340-4-3'));
        $this->assertTrue($this->assortment->productShowsAntistatic('Sandały S1 P, IEC61340-4-3'));
        $this->assertTrue($this->assortment->requiresAntistatic('Obuwie wg EN61340-4-3'));
        $this->assertSame('strong', $this->assortment->antistaticEvidence(self::FOOTWEAR, 'Sandały S1 P, EN61340-4-3'));
        $this->assertSame(['61340'], $this->assortment->productAntistaticNorms($this->card('S-1', 'Sandały S1 P, IEC61340-4-3')));
        $this->assertTrue($this->assortment->productMeetsAntistaticRequirement(
            'Półbuty ochronne S1P ESD',
            $this->card('P-61340', 'Półbuty ochronne S1P', ['norms' => 'EN ISO 20345:2011 S1P, EN61340-4-3'])
        ));
        // Zapis z myślnikiem i spacją dalej jest normą.
        $this->assertTrue($this->assortment->productShowsAntistatic('Rękawice powlekane, 1149-5'));
        $this->assertTrue($this->assortment->productShowsAntistatic('Rękawice powlekane, EN1149-5'));
    }

    /**
     * Wymaganie bez rodzaju wyrobu (sam model: „ARSO 701 616560 ESD”) — o regule dowodu decyduje rodzaj z karty. Dotąd
     * but z EN 61340 szedł regułą rękawic (ESD / EN 1149 / EN 16350) i wychodził „weak”, choć dla obuwia to dowód ESD.
     */
    #[Test]
    public function product_family_decides_evidence_rule_when_requirement_names_no_family(): void
    {
        $requirement = 'ARSO 701 616560 ESD';
        $this->assertNull($this->assortment->family($requirement), 'fixture: wymaganie bez rodzaju wyrobu');
        $sandal = $this->card('616560', 'Sandały ARSO 701 616560 S1 P', ['norms' => 'EN ISO 20345:2011 S1 P, EN IEC 61340-4-3:2018']);

        $this->assertSame(PpeAssortment::ANTISTATIC_STRONG, $this->assortment->productAntistaticEvidence($requirement, $sandal));
        // Rodzaj z wymagania nadal wygrywa z rodzajem karty.
        $this->assertSame(PpeAssortment::ANTISTATIC_WEAK, $this->assortment->productAntistaticEvidence(
            'Rękawice ochronne ESD',
            $this->card('R-61340', 'Rękawice powlekane', ['norms' => 'EN 388:2016, IEC 61340-5-1'])
        ));
    }

    /** Siła dowodu dzieli dokładnie to, co bramka przepuszcza — nic nie jest „strong” ani „weak” poza nią. */
    #[Test]
    public function evidence_is_present_exactly_when_gate_shows_antistatic(): void
    {
        $texts = [
            'ARSO 701 616560 S1 P ESD',
            'EN IEC 61340-4-3:2018',
            'antyelektrostatyczna podeszwa',
            'Trzewiki S3 EN 1149-5',
            'Rękawice EN 16350:2014',
            'Rękawice 1149-5',
            'Anti-static PU coated gloves',
            'Rękawice montażowe powlekane poliuretanem, EN 388 4131X',
            'Rękawice Phynomic lite',
            'Rękawice powlekane 11495',
            'Linka DBI-SALA 6134006',
            'Sandały S1 P, EN61340-4-3',
            'Rękawice, IEC61340-5-1',
        ];
        foreach ([self::FOOTWEAR, self::GLOVES, self::COVERALL] as $requirement) {
            foreach ($texts as $text) {
                $this->assertSame(
                    $this->assortment->productShowsAntistatic($text),
                    $this->assortment->antistaticEvidence($requirement, $text) !== null,
                    $requirement.' | '.$text
                );
            }
        }
    }

    /** EN 16350 (właściwości elektrostatyczne rękawic) w SIWZ wymaga antystatyki, a na karcie ją pokazuje. */
    #[Test]
    public function en_16350_requires_and_shows_antistatic(): void
    {
        $requirement = 'Rękawice ochronne powlekane nitrylem, zgodne z EN 388 oraz PN-EN 16350:2014';
        $this->assertTrue($this->assortment->requiresAntistatic($requirement));
        $this->assertTrue($this->assortment->productShowsAntistatic('Rękawice powlekane nitrylem, EN 388:2016, EN 16350:2014'));
        $this->assertTrue($this->assortment->productShowsAntistatic('Rękawice powlekane nitrylem, EN16350'));
        // Tak zapisują to atrybuty części kart na produkcji (zrzut przypadków 25.09: „EN ISO 16350”).
        $this->assertTrue($this->assortment->productShowsAntistatic('Rękawice powlekane nitrylem, EN ISO 16350'));
        $this->assertSame('strong', $this->assortment->antistaticEvidence(self::GLOVES, 'Rękawice powlekane nitrylem, EN ISO 16350'));
        $this->assertFalse($this->assortment->productShowsAntistatic('Rękawice powlekane nitrylem 16350 szt.'), 'goła liczba to nie norma');

        $this->assertTrue($this->assortment->productMeetsAntistaticRequirement($requirement, $this->card('R-16350', 'Rękawice powlekane nitrylem', [
            'norms' => 'EN 388:2016 (4131X), EN 16350:2014',
        ])));
        $this->assertFalse($this->assortment->productMeetsAntistaticRequirement($requirement, $this->card('R-388', 'Rękawice powlekane nitrylem', [
            'norms' => 'EN 388:2016 (4131X)',
        ])));
    }

    /** Reguła obuwia bez zmian: EN 16350 (norma rękawic) nie przepuszcza buta bez ESD / EN 61340. */
    #[Test]
    public function en_16350_does_not_pass_footwear_gate(): void
    {
        $this->assertFalse($this->assortment->productMeetsAntistaticRequirement(
            'Półbuty ochronne S3 ESD',
            $this->card('P-1', 'Półbuty ochronne S3', ['norms' => 'EN ISO 20345:2011 S3, EN 16350:2014'])
        ));
        $this->assertTrue($this->assortment->productMeetsAntistaticRequirement(
            'Półbuty ochronne S3 ESD',
            $this->card('P-2', 'Półbuty ochronne S3', ['norms' => 'EN ISO 20345:2011 S3, EN IEC 61340-4-3:2018'])
        ));
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function card(string $sku, string $name, array $attrs = []): Product
    {
        $product = new Product;
        $product->forceFill(array_merge(['sku' => $sku, 'name' => $name], $attrs));

        return $product;
    }
}
