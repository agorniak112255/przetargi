<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\WithdrawnProductNote;
use Tests\TestCase;

/**
 * Dopisek o wycofaniu w opisie karty (dziś PROTEKT). Formaty wzięte z produkcji 24.09.2026: 523 karty, zawsze
 * w pierwszej linii, z następcą albo bez; nazwa następcy sklejona ze słowem „przez”, bo tak podaje ją strona.
 */
final class WithdrawnProductNoteTest extends TestCase
{
    private const FEATURES = "Cechy szczególne:\n- Dopuszczone do prac w strefach zagrożonych wybuchem";

    public function test_successor_glued_to_the_word_is_read_without_touching_the_source(): void
    {
        $description = "UWAGA: produkt wycofany przez producenta — Wycofany — zastąpiony przezBW100.\n\n".self::FEATURES;

        $this->assertSame(['successor' => 'BW100'], WithdrawnProductNote::parse($description));
    }

    public function test_successor_with_spaces_and_slashes_stays_whole(): void
    {
        $this->assertSame(
            ['successor' => 'PROTON 100'],
            WithdrawnProductNote::parse('UWAGA: produkt wycofany przez producenta — Wycofany — zastąpiony przezPROTON 100.'),
        );
        $this->assertSame(
            ['successor' => 'BW100/LB101'],
            WithdrawnProductNote::parse('UWAGA: produkt wycofany przez producenta — Wycofany — zastąpiony przez BW100/LB101.'),
        );
    }

    public function test_withdrawn_without_successor(): void
    {
        $description = "UWAGA: produkt wycofany przez producenta — Wycofany.\n\n".self::FEATURES;

        $this->assertSame(['successor' => null], WithdrawnProductNote::parse($description));
    }

    public function test_description_without_the_note_is_not_withdrawn(): void
    {
        $this->assertNull(WithdrawnProductNote::parse(self::FEATURES));
        $this->assertNull(WithdrawnProductNote::parse(''));
        $this->assertNull(WithdrawnProductNote::parse(null));
        // Samo słowo w prozie to nie dopisek łącznika.
        $this->assertNull(WithdrawnProductNote::parse('Następca modelu wycofanego przez producenta w 2020 r.'));
    }

    public function test_writer_and_reader_share_one_format(): void
    {
        $line = WithdrawnProductNote::forDescription('Wycofany — zastąpiony przezBW100');

        $this->assertSame('UWAGA: produkt wycofany przez producenta — Wycofany — zastąpiony przezBW100.', $line);
        $this->assertSame(['successor' => 'BW100'], WithdrawnProductNote::parse($line));
    }

    public function test_strip_removes_only_the_note(): void
    {
        $description = "UWAGA: produkt wycofany przez producenta — Wycofany.\n\n".self::FEATURES;

        $this->assertSame(self::FEATURES, WithdrawnProductNote::strip($description));
        // Opis bez dopisku wraca bajt w bajt — łącznie ze złamaniami i spacjami na końcach.
        $untouched = "  Proza.\n\n".self::FEATURES."\n";
        $this->assertSame($untouched, WithdrawnProductNote::strip($untouched));
    }
}
