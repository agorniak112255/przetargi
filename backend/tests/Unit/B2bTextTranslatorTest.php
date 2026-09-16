<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\B2b\B2bTextTranslator;
use App\Services\B2b\B2bTranslationRejected;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Tłumaczenie opisów i nazw kart z importu B2B (Bollé, źródło po angielsku): segmenty w jednym wywołaniu,
 * sekcja „Parametry:” dosłownie, walidacja wyniku względem źródła. Model zastąpiony stubem — bez sieci.
 */
final class B2bTextTranslatorTest extends TestCase
{
    private const DESCRIPTION = "Prescription safety glasses\n\n"
        ."The clear frame and sideshields allow both visual comfort and unparalleled protection. Integrated nose pads ensures a great level of comfort. Available in three sizes. This is perfect for wide fitting larger faces.\n\n"
        ."Built-in side shields\nAdjustable reinforced temples\nNose bridge with non-slip pads (L and XL)\n3 sizes: S,L and XL\n\n"
        ."Parametry:\n- Materiał oprawki: Nylon Metal\n- Technologia oprawki: N/A";

    private const NAME = 'B809 - Extra Large – Prescription safety glasses';

    private const SEGMENT_TITLE = 'Okulary ochronne korekcyjne';

    private const SEGMENT_BODY = 'Bezbarwna oprawka i osłony boczne zapewniają zarówno komfort widzenia, jak i niezrównaną ochronę. Zintegrowane noski zapewniają wysoki poziom komfortu. Dostępne w trzech rozmiarach. Idealne dla szerokich, większych twarzy.';

    private const SEGMENT_LIST = "Wbudowane osłony boczne\nRegulowane, wzmocnione zauszniki\nMostek z antypoślizgowymi noskami (L i XL)\n3 rozmiary: S, L i XL";

    /** @var list<array{messages: array, temperature: ?float, max_tokens: ?int}> */
    private array $calls = [];

    #[Test]
    public function translates_segments_and_keeps_parameters_section_verbatim(): void
    {
        $translator = $this->translatorReturning([
            'name' => 'B809 - Extra Large – Okulary ochronne korekcyjne',
            'segments' => [self::SEGMENT_TITLE, self::SEGMENT_BODY, self::SEGMENT_LIST],
        ]);

        $result = $translator->translate(self::DESCRIPTION, self::NAME);

        $this->assertSame(
            self::SEGMENT_TITLE."\n\n".self::SEGMENT_BODY."\n\n".self::SEGMENT_LIST
                ."\n\nParametry:\n- Materiał oprawki: Nylon Metal\n- Technologia oprawki: N/A",
            $result['description']
        );
        $this->assertSame('B809 - Extra Large – Okulary ochronne korekcyjne', $result['name']);

        $this->assertCount(1, $this->calls);
        $call = $this->calls[0];
        $userContent = (string) $call['messages'][1]['content'];
        $payload = json_decode($userContent, true);
        $this->assertSame(self::NAME, $payload['name']);
        $this->assertSame([
            'Prescription safety glasses',
            'The clear frame and sideshields allow both visual comfort and unparalleled protection. Integrated nose pads ensures a great level of comfort. Available in three sizes. This is perfect for wide fitting larger faces.',
            "Built-in side shields\nAdjustable reinforced temples\nNose bridge with non-slip pads (L and XL)\n3 sizes: S,L and XL",
        ], $payload['segments']);
        $this->assertStringNotContainsString('Parametry', $userContent);
        $this->assertNotNull($call['temperature']);
        $this->assertLessThanOrEqual(0.1, $call['temperature']);
        $this->assertSame(1200, $call['max_tokens']);
    }

    #[Test]
    public function max_tokens_grow_with_input_length(): void
    {
        $long = implode("\n", array_map(
            static fn (int $i): string => 'Feature line number '.$i.' with a longer explanation of the lens coating',
            range(1, 80)
        ));
        $translator = $this->translatorReturning(['name' => null, 'segments' => []]);

        try {
            $translator->translate($long);
            $this->fail('Oczekiwano odrzucenia (inna liczba segmentów).');
        } catch (B2bTranslationRejected) {
        }

        $payloadLength = mb_strlen((string) $this->calls[0]['messages'][1]['content']);
        $this->assertSame((int) ceil($payloadLength * 1.6 / 3), $this->calls[0]['max_tokens']);
        $this->assertGreaterThan(1200, $this->calls[0]['max_tokens']);
    }

    #[Test]
    public function keeps_translation_when_only_the_thousands_separator_changes(): void
    {
        // strona producenta UVEX pisze tysiące z przecinkiem („11,500nm”), po polsku piszemy je ze spacją
        $source = 'A broadband laser protection exists from 635nm to 11,500nm, especially at 1,030-1,400nm.';
        $translator = $this->translatorReturning([
            'segments' => ['Szerokopasmowa ochrona laserowa od 635 nm do 11 500 nm, zwłaszcza przy 1030-1400 nm.'],
        ]);

        $result = $translator->translate($source);

        $this->assertSame('Szerokopasmowa ochrona laserowa od 635 nm do 11 500 nm, zwłaszcza przy 1030-1400 nm.', $result['description']);
    }

    #[Test]
    public function keeps_product_code_followed_by_a_word(): void
    {
        // kod kończy się cyfrą, a zaraz po nim idzie słowo — to nadal ten sam token
        $source = 'The laser safety window P6P21 with ESD coating consists of a gold-colored plastic.';
        $translator = $this->translatorReturning([
            'segments' => ['Okno ochronne do laserow P6P21 z powloka ESD sklada sie ze zlotego tworzywa sztucznego.'],
        ]);

        $result = $translator->translate($source);

        $this->assertStringContainsString('P6P21', $result['description']);
    }

    #[Test]
    public function rejects_lost_number(): void
    {
        $this->assertRejected(
            ['name' => 'B809 - Extra Large – Okulary ochronne korekcyjne', 'segments' => [
                self::SEGMENT_TITLE,
                self::SEGMENT_BODY,
                "Wbudowane osłony boczne\nRegulowane, wzmocnione zauszniki\nMostek z antypoślizgowymi noskami (L i XL)\nRozmiary: S, L i XL",
            ]],
            'zgubiona liczba: 3'
        );
    }

    #[Test]
    public function rejects_added_number(): void
    {
        $this->assertRejected(
            ['name' => 'B809 - Extra Large – Okulary ochronne korekcyjne', 'segments' => [
                self::SEGMENT_TITLE,
                self::SEGMENT_BODY.' Gwarancja 2 lata.',
                self::SEGMENT_LIST,
            ]],
            'dopisana liczba: 2'
        );
    }

    #[Test]
    public function rejects_added_norm(): void
    {
        $this->assertRejected(
            ['name' => 'B809 - Extra Large – Okulary ochronne korekcyjne', 'segments' => [
                self::SEGMENT_TITLE,
                self::SEGMENT_BODY.' Zgodne z EN 170.',
                self::SEGMENT_LIST,
            ]],
            'dopisana norma: EN 170'
        );
    }

    #[Test]
    public function rejects_lost_uppercase_coating_name(): void
    {
        $translator = $this->translatorReturning([
            'name' => null,
            'segments' => ['Powłoka przeciwmgielna na obu stronach soczewki'],
        ]);

        $this->expectException(B2bTranslationRejected::class);
        $this->expectExceptionMessage('zgubiony token: PLATINUM');

        $translator->translate('PLATINUM anti-fog coating on both sides of the lens');
    }

    #[Test]
    public function rejects_lost_uppercase_model_name(): void
    {
        $translator = $this->translatorReturning([
            'name' => null,
            'segments' => ['Oprawka z miedzianą soczewką'],
        ]);

        $this->expectException(B2bTranslationRejected::class);
        $this->expectExceptionMessage('zgubiony token: TRYON');

        $translator->translate('TRYON frame with copper lens');
    }

    #[Test]
    public function rejects_changed_decimal_in_model_name(): void
    {
        $translator = $this->translatorReturning([
            'name' => null,
            'segments' => ['Okulary RUSH+ 2,0 z modulatorem'],
        ]);

        $this->expectException(B2bTranslationRejected::class);
        $this->expectExceptionMessage('zgubiony token: 2.0');

        $translator->translate('RUSH+ 2.0 glasses with modulator');
    }

    #[Test]
    public function rejects_changed_name_family(): void
    {
        $translator = $this->translatorReturning([
            'name' => 'RUSH 2.0 – Okulary ochronne Modulator',
            'segments' => [],
        ]);

        $this->expectException(B2bTranslationRejected::class);
        $this->expectExceptionMessage('zmieniony człon nazwy „RUSH+ 2.0”');

        $translator->translate('', 'RUSH+ 2.0 – Modulator safety glasses');
    }

    #[Test]
    public function translates_name_only_when_description_is_empty(): void
    {
        $translator = $this->translatorReturning([
            'name' => 'VOLT – Filtr elektrooptyczny',
            'segments' => [],
        ]);

        $result = $translator->translate('', 'VOLT – Electro-optical filter');

        $this->assertSame(['description' => '', 'name' => 'VOLT – Filtr elektrooptyczny'], $result);
        $payload = json_decode((string) $this->calls[0]['messages'][1]['content'], true);
        $this->assertSame(['product' => null, 'name' => 'VOLT – Electro-optical filter', 'segments' => []], $payload);
    }

    #[Test]
    public function rejects_different_segment_count(): void
    {
        $this->assertRejected(
            ['name' => 'B809 - Extra Large – Okulary ochronne korekcyjne', 'segments' => [
                self::SEGMENT_TITLE."\n\n".self::SEGMENT_BODY,
                self::SEGMENT_LIST,
            ]],
            'inna liczba segmentów: w źródle 3, w odpowiedzi 2'
        );
    }

    #[Test]
    public function rejects_different_line_count_in_segment(): void
    {
        $this->assertRejected(
            ['name' => 'B809 - Extra Large – Okulary ochronne korekcyjne', 'segments' => [
                self::SEGMENT_TITLE,
                self::SEGMENT_BODY,
                "Wbudowane osłony boczne, regulowane, wzmocnione zauszniki\nMostek z antypoślizgowymi noskami (L i XL)\n3 rozmiary: S, L i XL",
            ]],
            'segment 3: inna liczba linii — w źródle 4, w tłumaczeniu 3'
        );
    }

    #[Test]
    public function rejects_too_short_result_for_long_text(): void
    {
        $this->assertRejected(
            ['name' => 'B809 - Extra Large – Okulary', 'segments' => [
                'Okulary',
                'Wygodne okulary.',
                "Osłony\nZauszniki\nNoski (L, XL)\n3 rozmiary",
            ]],
            'za krótkie tłumaczenie'
        );
    }

    #[Test]
    public function keeps_uppercase_only_segment_verbatim_without_sending_it_to_model(): void
    {
        // Bollé featureddescription: ucięte znaczniki kategorii z nazwą modelu (15.09.2026 odrzucone: „zgubiony token: KIT, SAFETY, SPARE”)
        $translator = $this->translatorReturning([
            'name' => null,
            'segments' => ['Zestaw pianki i paska', 'Zestaw pianki i paska NESS+'],
        ]);
        $source = "Foam and strap kit\n\nNESS+ Foam and Strap Kit\n\nRUSH+ - KIT SAFETY SPARE\n\nParametry:\n- Materiał oprawki: HYTREL - SBR";

        $result = $translator->translate($source);

        $this->assertSame(
            "Zestaw pianki i paska\n\nZestaw pianki i paska NESS+\n\nRUSH+ - KIT SAFETY SPARE\n\nParametry:\n- Materiał oprawki: HYTREL - SBR",
            $result['description']
        );
        $payload = json_decode((string) $this->calls[0]['messages'][1]['content'], true);
        $this->assertSame(['Foam and strap kit', 'NESS+ Foam and Strap Kit'], $payload['segments']);
    }

    #[Test]
    public function accepts_units_dimensions_and_number_words_written_in_polish(): void
    {
        $translator = $this->translatorReturning([
            'name' => null,
            'segments' => ["Regulacja opóźnienia: 0,05 ms\nEkran LED: 96 x 39 mm\nOdporność na uderzenia (typ B: 120 m/s)\nNagłowie 3-punktowe z pamięcią kształtu"],
        ]);

        $result = $translator->translate(
            "Delay adjustment: 0.05ms\nLED screen: 96x39mm\nHigh impact resistance (type B: 120m/s)\nShape-memory 3-points headgear"
        );

        $this->assertStringContainsString('Ekran LED: 96 x 39 mm', $result['description']);
    }

    #[Test]
    public function rejects_changed_number_inside_dimensions(): void
    {
        $translator = $this->translatorReturning([
            'name' => null,
            'segments' => ['Ekran LED: 96 x 38 mm'],
        ]);

        $this->expectException(B2bTranslationRejected::class);
        $this->expectExceptionMessage('zgubiona liczba: 39');

        $translator->translate('LED screen: 96x39mm');
    }

    #[Test]
    public function rejects_markdown_in_result(): void
    {
        $translator = $this->translatorReturning([
            'name' => null,
            'segments' => ['```json Soczewka bezbarwna'],
        ]);

        $this->expectException(B2bTranslationRejected::class);
        $this->expectExceptionMessage('znaczniki markdown/HTML');

        $translator->translate('Clear lens');
    }

    #[Test]
    public function accepts_decimal_comma_and_polish_unit(): void
    {
        $translator = $this->translatorReturning([
            'name' => null,
            'segments' => ['Długość linki 17,5 cm'],
        ]);

        $result = $translator->translate('Cord length 17.5CM');

        $this->assertSame(['description' => 'Długość linki 17,5 cm', 'name' => null], $result);
    }

    #[Test]
    public function short_segment_skips_length_check(): void
    {
        $translator = $this->translatorReturning([
            'name' => null,
            'segments' => ['Soczewka bezbarwna'],
        ]);

        $this->assertSame(
            ['description' => 'Soczewka bezbarwna', 'name' => null],
            $translator->translate('Clear lens')
        );
    }

    #[Test]
    public function nothing_to_translate_does_not_call_model(): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldNotReceive('chatJsonEnrichment');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
        $translator = $this->app->make(B2bTextTranslator::class);

        $this->assertSame(['description' => '', 'name' => null], $translator->translate('', null));

        $parametersOnly = "Parametry:\n- Kolor soczewki: Platinum";
        $this->assertSame(
            ['description' => $parametersOnly, 'name' => null],
            $translator->translate($parametersOnly)
        );
    }

    #[Test]
    public function model_error_passes_through_unchanged(): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->once()->andThrow(new RuntimeException('HTTP 429 limit zapytań'));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        try {
            $this->app->make(B2bTextTranslator::class)->translate('Clear lens');
            $this->fail('Oczekiwano wyjątku z klienta AI.');
        } catch (RuntimeException $e) {
            $this->assertSame(RuntimeException::class, $e::class);
            $this->assertSame('HTTP 429 limit zapytań', $e->getMessage());
        }
    }

    /** @param  array<string, mixed>  $response */
    private function assertRejected(array $response, string $message): void
    {
        $translator = $this->translatorReturning($response);

        try {
            $translator->translate(self::DESCRIPTION, self::NAME);
            $this->fail('Oczekiwano odrzucenia tłumaczenia: '.$message);
        } catch (B2bTranslationRejected $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    /** @param  array<string, mixed>  $response */
    private function translatorReturning(array $response): B2bTextTranslator
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')
            ->andReturnUsing(function (array $messages, ?float $temperature, ?int $maxTokens) use ($response): array {
                $this->calls[] = ['messages' => $messages, 'temperature' => $temperature, 'max_tokens' => $maxTokens];

                return $response;
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        return $this->app->make(B2bTextTranslator::class);
    }
}
