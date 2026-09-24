<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\UnderstandingAudit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnderstandingAuditTest extends TestCase
{
    public function test_flags_dropped_material_and_added_word_from_production_case(): void
    {
        // 24.09.2026: materiał tylko w search_phrases nie pomógł wyszukiwaniu, a „robocze” model dopisał sam
        $answer = [
            'needed' => 'spodnie robocze',
            'search_steps' => ['spodnie', 'robocze'],
            'manufacturer' => null,
            'model_name' => null,
            'size_note' => null,
            'search_phrases' => ['spodnie robocze', 'spodnie do pasa', 'spodnie polipropylenowe'],
            'constraints' => [],
        ];

        $this->assertSame(
            ['dropped' => ['polipropylenu'], 'added' => ['robocze']],
            (new UnderstandingAudit)->check('spodnie do pasa z polipropylenu', $answer),
        );
    }

    public function test_faithful_understanding_with_model_name_is_clean(): void
    {
        $answer = [
            'needed' => 'rękawice spawalnicze trudnopalne',
            'search_steps' => ['rękawice', 'spawalnicze', 'trudnopalne', 'RS SPLIT KEV'],
            'model_name' => 'RS SPLIT KEV',
            'constraints' => [],
        ];

        $this->assertSame(
            ['dropped' => [], 'added' => []],
            (new UnderstandingAudit)->check('rękawice spawalnicze trudnopalne RS SPLIT KEV', $answer),
        );
    }

    public function test_inflected_forms_and_diacritics_match(): void
    {
        $answer = [
            'needed' => 'kurtka polipropylenowa ocieplana',
            'search_steps' => ['kurtki', 'polipropylen', 'ocieplana'],
            'constraints' => ['wodoodporność'],
        ];

        $this->assertSame(
            ['dropped' => [], 'added' => []],
            (new UnderstandingAudit)->check('Kurtka z polipropylenu, ocieplana, WODOODPORNA', $answer),
        );
    }

    public function test_ignores_quantity_words_short_words_and_codes(): void
    {
        $answer = ['needed' => 'rękawice nitrylowe', 'search_steps' => ['rękawice', 'nitrylowe'], 'constraints' => []];

        $this->assertSame(
            ['dropped' => [], 'added' => []],
            (new UnderstandingAudit)->check(
                'Rękawice nitrylowe, opakowanie 100 sztuk, rozmiary S-XL, zgodnie z wymaganiami poniżej, EN374 (kod 12345)',
                $answer,
            ),
        );
    }

    /** Akapit SIWZ model ma streścić — zgubione słowa liczymy tylko dla krótkich wymagań; dopisane zawsze. */
    public function test_long_requirement_reports_only_added_words(): void
    {
        $answer = ['needed' => 'fartuch wodoochronny gumowy', 'search_steps' => ['fartuch', 'wodoochronny', 'gumowy'], 'constraints' => []];

        $this->assertSame(
            ['dropped' => [], 'added' => ['gumowy']],
            (new UnderstandingAudit)->check(
                'Fartuch wodoochronny przeznaczony dla przetwórstwa spożywczego, kolor zielony, materiał poliuretan '
                .'na podkładzie poliestrowym, długość 120 centymetrów, wiązany na szyi i w pasie',
                $answer,
            ),
        );
    }

    /**
     * Zapisy skasowane 24.09.2026 na serwerze po przeglądzie jako błędne — zawężona reguła ma je dalej wskazywać.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function wrongUnderstandings(): array
    {
        return [
            '98 zmyślony wyrób' => ['Sperian tudzież HONEYWELL - 10 130 47', 'zapalniczki'],
            '99 zgadnięty rodzaj' => ['Sperian HONEYWELL 10 130 47', 'okulary ochronne'],
            '104 wyrób spoza wiersza' => ['Zamów proszę - kolor czarny', 'rękawice'],
            '111 zgubiony materiał' => ['spodnie do pasa z polipropylenu', 'spodnie robocze'],
            '64 zmyślony materiał' => ['Rękawice MAxicut 44-3745 10 12 par', 'rękawice ochronne z włókna aramidowego'],
            '57 zmyślone cechy' => ['rękawice r. 11.40-50', 'rękawice robocze'],
            '80 nakrapiane to nie powlekane' => ['rękawice białe dziane nakrapiane EN ISO 21420', 'rękawice dzianinowe z powlekana dłonią'],
            '87 przekręcona marka' => ['Rękawiczki nitrylowe "MedaSept" EASYGRIP PURPLE - 50 opk', 'rękawice nitrylowe jednorazowe'],
            '89 przekręcona marka' => ['rękawiczki nitrylowe MedaSept EASYGRIP PURPLE', 'rękawice nitrylowe jednorazowe'],
            '20 zgubiona seria' => ['Rękawice Ultrane', 'rękawice'],
        ];
    }

    #[DataProvider('wrongUnderstandings')]
    public function test_understandings_found_wrong_on_production_stay_flagged(string $requirement, string $needed): void
    {
        $r = (new UnderstandingAudit)->check($requirement, ['needed' => $needed, 'search_steps' => [], 'constraints' => []]);

        $this->assertNotSame(['dropped' => [], 'added' => []], $r);
    }

    /** Żargon przełożony w krokach wyszukiwania to nie błąd — kroki nie są nazwą wyrobu. */
    public function test_translated_search_steps_are_not_flagged_as_added(): void
    {
        $answer = ['needed' => 'buty spawalnicze', 'search_steps' => ['buty', 'podeszwa żaroodporna'], 'constraints' => []];

        $this->assertSame(['dropped' => [], 'added' => []], (new UnderstandingAudit)->check('Buty spawalnicze HRO', $answer));
    }

    public function test_stem_inside_a_longer_word_matches(): void
    {
        $answer = ['needed' => 'narękawnik ochronny', 'search_steps' => ['narękawnik'], 'constraints' => []];

        $this->assertSame(['dropped' => [], 'added' => []], (new UnderstandingAudit)->check('Rękaw ochronny', $answer));
    }
}
