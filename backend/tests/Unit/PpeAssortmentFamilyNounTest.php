<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CatalogSlangDictionary;
use App\Support\PpeAssortment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Rzeczownik rodzaju z nagłówka wymagania — pierwszy chip w „Weryfikacji karty”. Rodzina liczona
 * z pełnego tekstu (jak bramka dopasowania), rzeczownik tylko z nagłówka, w brzmieniu oryginału.
 */
final class PpeAssortmentFamilyNounTest extends TestCase
{
    private PpeAssortment $assortment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assortment = new PpeAssortment;
    }

    #[Test]
    #[DataProvider('opisowy15Cases')]
    public function family_noun_comes_from_requirement_head(int $line, ?string $label, ?string $stem): void
    {
        $noun = $this->nounForLine($line);

        $this->assertSame(
            $label === null ? null : ['label' => $label, 'stem' => (string) $stem],
            $noun,
            "poz. {$line}: rzeczownik rodzaju z nagłówka",
        );
    }

    /**
     * @return array<string, array{0: int, 1: string|null, 2: string|null}>
     */
    public static function opisowy15Cases(): array
    {
        return [
            // rodzina gloves tylko z EN 388 w dalszej treści — „rękaw” w nagłówku to nie „rękawice”
            'poz. 1 ochraniacz przedramienia → brak' => [1, null, null],
            'poz. 2 rękawice' => [2, 'Rękawice', 'Rękawic'],
            // „obuwie” w nawiasie i „S1” dalej to ta sama rodzina — wygrywa pierwsze słowo
            'poz. 3 sandały' => [3, 'Sandały', 'Sandał'],
            'poz. 4 fartuch' => [4, 'Fartuch', 'Fartuch'],
            // „kaloszami” dalej w nagłówku to już obuwie, ale rodzina liczona z pełnego tekstu to apparel
            'poz. 5 spodniobuty' => [5, 'Spodniobuty', 'Spodniobut'],
            'poz. 6 okulary' => [6, 'Okulary', 'Okular'],
            'poz. 7 rękawice' => [7, 'Rękawice', 'Rękawic'],
            'poz. 8 półmaska' => [8, 'Półmaska', 'Półmask'],
            'poz. 9 gogle' => [9, 'Gogle', 'Gogl'],
            'poz. 10 apteczka → brak rodziny' => [10, null, null],
            'poz. 11 płukanka → brak rodziny' => [11, null, null],
            'poz. 12 półbuty' => [12, 'Półbuty', 'Półbut'],
            'poz. 13 półmaska wielorazowa' => [13, 'Półmaska', 'Półmask'],
            // grupa wzorca to całe „pochlaniacz”, więc rdzeń = słowo
            'poz. 14 pochłaniacz' => [14, 'Pochłaniacz', 'Pochłaniacz'],
            'poz. 15 rękawice lateksowe' => [15, 'Rękawice', 'Rękawic'],
        ];
    }

    #[Test]
    public function welding_goggles_noun_is_not_taken_from_compatible_half_masks(): void
    {
        // Pełny opis poz. 9 mówi dalej „łącznie z półmaskami” i „na okularach korekcyjnych”.
        $requirement = Opisowy15Fixture::requirement(9);
        $head = CatalogSlangDictionary::requirementHead($requirement);

        $noun = $this->assortment->familyNoun($head, PpeAssortment::FAMILY_EYES);
        $this->assertNotNull($noun);
        $this->assertStringNotContainsStringIgnoringCase('półmask', $noun['label']);
        $this->assertStringNotContainsStringIgnoringCase('okular', $noun['label']);
        // Nawet pełny tekst jako nagłówek: pierwsze trafienie wygrywa.
        $this->assertSame('Gogle', $this->assortment->familyNoun($requirement, PpeAssortment::FAMILY_EYES)['label'] ?? null);
    }

    #[Test]
    public function two_word_face_shield_keeps_whole_phrase_as_stem(): void
    {
        $this->assertSame(
            ['label' => 'Osłona twarzy', 'stem' => 'Osłona twarzy'],
            $this->assortment->familyNoun('Osłona twarzy ochronna z poliwęglanu', PpeAssortment::FAMILY_FACE),
        );
    }

    #[Test]
    public function czech_glove_name_from_canis_price_list(): void
    {
        $this->assertSame(
            ['label' => 'Rukavice', 'stem' => 'Rukavic'],
            $this->assortment->familyNoun('Rukavice CERRO, máčené v nitrilu BLISTR', PpeAssortment::FAMILY_GLOVES),
        );
    }

    #[Test]
    public function odd_characters_do_not_throw(): void
    {
        // „½” normalizuje się do „1 2”, „´” do spacji — dopasowanie po słowach, nie po offsetach.
        $this->assertSame(
            ['label' => 'Rękawice', 'stem' => 'Rękawic'],
            $this->assortment->familyNoun('½ Rękawice ½ palca', PpeAssortment::FAMILY_GLOVES),
        );
        $this->assertSame(
            ['label' => 'jacket', 'stem' => 'jacket'],
            $this->assortment->familyNoun('Men´s jacket CXS SOLIS FLEX', PpeAssortment::FAMILY_APPAREL),
        );
        $this->assertNull($this->assortment->familyNoun('😀 ½ ß', PpeAssortment::FAMILY_GLOVES));
    }

    #[Test]
    public function word_with_unequal_normalized_length_uses_whole_word_as_stem(): void
    {
        // „ß” → „ss” (przypadek sztuczny): prefiks o długości grupy wzorca mógłby wskazać złe litery oryginału.
        $noun = $this->assortment->familyNoun('Rękawiceß robocze', PpeAssortment::FAMILY_GLOVES);

        $this->assertSame(['label' => 'Rękawiceß', 'stem' => 'Rękawiceß'], $noun);
    }

    #[Test]
    public function compound_word_stem_does_not_shrink_to_earlier_alternative(): void
    {
        // Wzorzec odzieży ma „spodn” przed „spodniobut” — rdzeń „Spodn” liczyłby „spodnie” w opisie spodniobutów.
        $this->assertSame(
            ['label' => 'Spodniobuty', 'stem' => 'Spodniobut'],
            $this->assortment->familyNoun('Spodniobuty wodoochronne', PpeAssortment::FAMILY_APPAREL),
        );
        // Zwykła końcówka po grupie zostaje przy rdzeniu z wzorca.
        $this->assertSame(
            ['label' => 'Spodnie', 'stem' => 'Spodn'],
            $this->assortment->familyNoun('Spodnie robocze do pasa', PpeAssortment::FAMILY_APPAREL),
        );
    }

    #[Test]
    public function unknown_family_gives_no_noun(): void
    {
        $this->assertNull($this->assortment->familyNoun('Rękawice ochronne', 'first_aid'));
    }

    /**
     * Tak jak wywołujący: rodzina z pełnego tekstu, bez rodziny nie pyta o rzeczownik.
     *
     * @return array{label: string, stem: string}|null
     */
    private function nounForLine(int $line): ?array
    {
        $requirement = Opisowy15Fixture::requirement($line);
        $family = $this->assortment->family($requirement);
        if ($family === null) {
            return null;
        }

        return $this->assortment->familyNoun(CatalogSlangDictionary::requirementHead($requirement), $family);
    }
}
