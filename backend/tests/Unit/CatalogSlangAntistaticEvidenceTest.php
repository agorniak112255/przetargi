<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CatalogSlangDictionary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dowód żargonu a antystatyka (K1, 25.09.2026). Grupa [monta, antystaty, odzie] odrzucała karty oznaczone tylko
 * „ESD” (uvex phynomic airLite A ESD, 6003805), bo słownik nie buduje 3-znakowej igły „esd”. Grupę z igłą
 * antystatyki rozstrzyga teraz ta sama reguła co bramka antystatyki (PpeAssortment::productShowsAntistatic).
 *
 * Grupy dowodu z produkcyjnego słownika żargonu (sondy S01 i S03 z 25.09) są tu odtworzone ze słownika w config —
 * pierwsza asercja każdego testu pilnuje, że wpis jest równoważny produkcyjnemu.
 */
final class CatalogSlangAntistaticEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private const UVEX = 'Rękawice montażowe powlekane uvex phynomic z funkcją ESD';

    private const COVERALL = 'Kombinezon chemoodporny (w szczególności na kwas siarkowy 96%) antyelektrostatyczny, rozmiar uniwersalny';

    public function test_esd_marked_glove_proves_antistatic_group(): void
    {
        $this->assertSame([['monta', 'antystaty', 'odzie']], $this->dict()->evidenceGroups(self::UVEX), 'grupy jak na produkcji (S01)');

        // 6003805 z produkcji (S01): nazwa + SKU + opis, category = null, ESD tylko w nazwie.
        $this->assertTrue($this->dict()->matchesEvidence(self::UVEX, 'Rękawice Phynomic airLite A ESD 6003805 '
            .'uvex phynomic airLite - to najlżejsze rękawice ochronne w swojej klasie gwarantujące wysoki komfort noszenia, '
            .'bardzo dobrą manualność, lekkość oraz niezwykłą oddychalność. Są idealne do precyzyjnej pracy, wymagającej '
            .'również obsługi ekranów dotykowych.'));
        // 6004806 z produkcji (S01).
        $this->assertTrue($this->dict()->matchesEvidence(self::UVEX, 'Rękawice uvex phynomic C XG ESD 6004806 '
            .'Rękawice uvex phynomic chroniące przed przecięciem powstały w Niemczech, przy użyciu technik produkcji '
            .'neutralnych pod względem emisji dwutlenku węgla. Powłoka Xtra-Grip zapewnia doskonałą przyczepność '
            .'w zaolejonych miejscach i wysoką trwałość rękawicy.'));
        // „antyelektrostatyczne” nie zawiera igły „antystaty” — ta sama reguła co bramka.
        $this->assertTrue($this->dict()->matchesEvidence(self::UVEX, 'Rękawice antyelektrostatyczne z włóknem węglowym'));
    }

    public function test_glove_without_antistatic_evidence_still_fails_the_group(): void
    {
        // 6004005 z produkcji (S01): bez ESD, bez norm, bez „montaż”.
        $this->assertFalse($this->dict()->matchesEvidence(self::UVEX, 'Rękawice Phynomic lite 6004005 '
            .'Uvex phynomic lite to najlżejsze rękawice ochronne w swojej klasie - redukujące uczucie zmęczenia. '
            .'Wysoka odporność na ścieranie dzięki cienkiej, ale bardzo wytrzymałej, impregnacji hydropolimerowej. '
            .'Zapewniają doskonały chwyt w suchych i lekko wilgotnych obszarach, wysoką oddychalność dzięki porowatej '
            .'powłoce redukującą pocenie oraz wysoki poziom czucia przy pracy z małymi częściami.'));
    }

    public function test_clothing_norm_en_1149_proves_antistatic_group(): void
    {
        $this->assertSame([['odzie', 'antystaty']], $this->dict()->evidenceGroups(self::COVERALL), 'grupy jak na produkcji (S03)');

        $this->assertTrue($this->dict()->matchesEvidence(self::COVERALL, '4000-OR CVRL HOOD 130.5XL OR40T-00130-09 EN 14605, EN 1149-5'));
        $this->assertTrue($this->dict()->matchesEvidence(self::COVERALL, 'Kombinezon ochronny typ 5/6 EN1149-5'));
        $this->assertFalse($this->dict()->matchesEvidence(self::COVERALL, '4000-OR CVRL HOOD 130.5XL OR40T-00130-09 EN 14605'));
        $this->assertFalse($this->dict()->matchesEvidence(self::COVERALL, 'Kombinezon ochronny typ 5/6 z kapturem'));
        // Zakazana w golden ścierka Canis (S03) nie wchodzi — nie pokazuje antystatyki.
        $this->assertFalse($this->dict()->matchesEvidence(self::COVERALL, 'Handy cloth, twill material, 100% cotton. 1150-008-100-00'));
    }

    /** Ratunek dotyczy tylko grupy z igłą antystatyki — warunek „podnosek” dalej obowiązuje. */
    public function test_antistatic_card_does_not_skip_other_condition_groups(): void
    {
        $sandals = 'Sandały ochronne (obuwie bezpieczne z odkrytą cholewką) kategorii S1 P wg EN ISO 20345. '
            .'Wymagane: zabudowana pięta; podnosek ochronny; właściwości antyelektrostatyczne (ESD).';
        $this->assertContains(['podnos'], $this->dict()->evidenceGroups($sandals));

        $this->assertFalse($this->dict()->matchesEvidence($sandals, 'Trzewiki robocze S1 P ESD EN IEC 61340-4-3'));
        $this->assertTrue($this->dict()->matchesEvidence($sandals, 'Trzewiki robocze S1 P ESD, podnosek kompozytowy'));
    }

    private function dict(): CatalogSlangDictionary
    {
        return $this->app->make(CatalogSlangDictionary::class);
    }
}
