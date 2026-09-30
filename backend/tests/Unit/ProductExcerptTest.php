<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Campaigns\ProductExcerpt;
use PHPUnit\Framework\TestCase;

/** Krótki opis do maila = wycinek opisu karty (przypadki z opisów na produkcji 30.09.2026), nigdy tekst dopisany. */
final class ProductExcerptTest extends TestCase
{
    public function test_first_full_sentences_up_to_limit(): void
    {
        $description = 'Okulary ochronne 3M™ SecureFit™ serii 500 charakteryzują się stylizowanym kształtem soczewek oraz zausznikami, które automatycznie się dopasowują. Ważą zaledwie 22 g.'
            ."\n\nW celu korzystania z nowoczesnych produktów do ochrony oczu zaprojektowanych z myślą o wygodzie użyj okularów.";

        $this->assertSame(
            'Okulary ochronne 3M™ SecureFit™ serii 500 charakteryzują się stylizowanym kształtem soczewek oraz zausznikami, które automatycznie się dopasowują. Ważą zaledwie 22 g.',
            ProductExcerpt::fromDescription($description, 'SecureFit 500'),
        );
    }

    public function test_skips_source_header_caps_lines_and_repeated_name(): void
    {
        $name = 'Znak BHP Ostrzeżenie przed kruchym dachem 50x50mm P (W036)';
        $description = "Z karty technicznej (znak W036.pdf):\nUVEX SAFETY POLSKA\n{$name}\n{$name} to tablica ostrzegawcza stosowana w miejscach, gdzie istnieje ryzyko upadku z powodu kruchego dachu. Znak ma na celu zwrócenie uwagi.";

        $this->assertSame(
            $name.' to tablica ostrzegawcza stosowana w miejscach, gdzie istnieje ryzyko upadku z powodu kruchego dachu. Znak ma na celu zwrócenie uwagi.',
            ProductExcerpt::fromDescription($description, $name),
        );
    }

    public function test_bullet_only_description_gives_first_features(): void
    {
        $description = "stylowa damska bluza polarowa z wysokiej jakości materiału\nznakomicie wykończony fason z wiedeńskimi szwami, 280 g/m²\ndwie kieszenie dolne\nciekawe szczegóły - suwak\nkolor: biały";

        $this->assertSame(
            'Stylowa damska bluza polarowa z wysokiej jakości materiału · znakomicie wykończony fason z wiedeńskimi szwami, 280 g/m² · dwie kieszenie dolne',
            ProductExcerpt::fromDescription($description),
        );
    }

    public function test_lead_line_before_paragraph_becomes_first_sentence_and_short_fragments_are_skipped(): void
    {
        $description = "Rękawice mechaniczne idealne do wszechstronnych prac\n\nSprawność\nPowłoka poliuretanowa zapewnia dobrą przyczepność dzięki funkcji antypoślizgowej.";

        $this->assertSame(
            'Rękawice mechaniczne idealne do wszechstronnych prac. Powłoka poliuretanowa zapewnia dobrą przyczepność dzięki funkcji antypoślizgowej.',
            ProductExcerpt::fromDescription($description),
        );
    }

    public function test_too_long_first_sentence_is_cut_on_word_with_ellipsis(): void
    {
        $long = 'DeckStep to wielozadaniowa mata podłogowa '.str_repeat('z winylu o prążkowanej powierzchni ', 10).'koniec.';
        $out = (string) ProductExcerpt::fromDescription($long);

        $this->assertLessThanOrEqual(ProductExcerpt::MAX, mb_strlen($out));
        $this->assertStringEndsWith('…', $out);
        $this->assertStringStartsWith('DeckStep to wielozadaniowa mata podłogowa', $out);
        // bez uciętego słowa
        $this->assertMatchesRegularExpression('/(winylu|prążkowanej|powierzchni|o|z)…$/u', $out);
    }

    public function test_html_is_stripped_and_empty_or_useless_gives_null(): void
    {
        $this->assertSame('Kask ochronny z regulacją obwodu głowy i wentylacją, lekki i wygodny w pracy.', ProductExcerpt::fromDescription('<p>Kask ochronny z regulacją obwodu głowy i wentylacją, lekki i wygodny w pracy.</p>'));
        $this->assertNull(ProductExcerpt::fromDescription(null));
        $this->assertNull(ProductExcerpt::fromDescription("  \n "));
        $this->assertNull(ProductExcerpt::fromDescription("Cechy szczególne:\nWłaściwości\nprodukt"));
    }

    public function test_doubled_dots_from_source_are_merged_but_ellipsis_stays(): void
    {
        $this->assertSame(
            'Dohełmowa wkładka termiczna Surefit™ o wysokiej widoczności w kolorze pomarańczowym - M/L.',
            ProductExcerpt::fromDescription('Dohełmowa wkładka termiczna Surefit™ o wysokiej widoczności w kolorze pomarańczowym - M/L. .'),
        );
        $this->assertSame(
            'Lekka kurtka przeciwdeszczowa z kapturem i taśmami odblaskowymi... do pracy na zewnątrz.',
            ProductExcerpt::fromDescription('Lekka kurtka przeciwdeszczowa z kapturem i taśmami odblaskowymi... do pracy na zewnątrz.'),
        );
    }

    public function test_norms_list(): void
    {
        $this->assertSame(['DIN 51130', 'DIN 51097'], ProductExcerpt::norms('DIN 51130, DIN 51097'));
        $this->assertSame(['EN ISO 20345:2011 S3', 'SRC', 'EN 388', 'EN 407'], ProductExcerpt::norms("EN ISO 20345:2011 S3; SRC\nEN 388, EN 407, EN 511, SRC"));
        $this->assertSame([], ProductExcerpt::norms(null));
        $this->assertSame([], ProductExcerpt::norms(str_repeat('x', 41)));
    }
}
