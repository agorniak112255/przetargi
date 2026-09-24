<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\UnderstandingAudit;
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

    public function test_stem_inside_a_longer_word_matches(): void
    {
        $answer = ['needed' => 'narękawnik ochronny', 'search_steps' => ['narękawnik'], 'constraints' => []];

        $this->assertSame(['dropped' => [], 'added' => []], (new UnderstandingAudit)->check('Rękaw ochronny', $answer));
    }
}
