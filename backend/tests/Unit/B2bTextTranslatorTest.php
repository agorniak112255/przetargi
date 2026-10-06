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
    public function typo_in_source_does_not_block_the_translation(): void
    {
        // strona producenta ma „from 940 to1055nm” bez spacji — to literówka, nie kod wyrobu
        $source = 'It offers OD10+ from 940 to1055nm and OD8+ from 880nm to 1075nm.';
        $translator = $this->translatorReturning([
            'segments' => ['Zapewnia OD10+ od 940 do 1055 nm oraz OD8+ od 880 nm do 1075 nm.'],
        ]);

        $result = $translator->translate($source);

        $this->assertSame('Zapewnia OD10+ od 940 do 1055 nm oraz OD8+ od 880 nm do 1075 nm.', $result['description']);
    }

    #[Test]
    public function uppercase_english_function_word_may_be_translated(): void
    {
        // Bollé FLASHV 23.09.2026: „zgubiony token: THE” — wersaliki dla podkreślenia, nie nazwa modelu
        $source = 'FLASH is THE reference in welding helmets.';
        $translator = $this->translatorReturning([
            'segments' => ['FLASH to wzorzec wśród przyłbic spawalniczych.'],
        ]);

        $this->assertSame('FLASH to wzorzec wśród przyłbic spawalniczych.', $translator->translate($source)['description']);
    }

    #[Test]
    public function uppercase_pack_and_size_words_in_the_name_may_be_translated(): void
    {
        // Bolle RUSPMN14E 28.09.2026: „zgubiony token: ECO, PACK, PIECES, SIZE” przy poprawnym tłumaczeniu nazwy
        $name = 'RUSH+ 2.0 - ECO PACK OF 20 PIECES - SIZE M – Clear safety glasses - Eco pack';
        $translated = 'RUSH+ 2.0 - opakowanie ekologiczne 20 szt. - rozmiar M – okulary ochronne bezbarwne - opakowanie ekologiczne';
        $translator = $this->translatorReturning(['name' => $translated, 'segments' => []]);

        $this->assertSame($translated, $translator->translate('', $name)['name']);
    }

    #[Test]
    public function uppercase_model_name_next_to_pack_words_stays_protected(): void
    {
        // UNIVERSAL to nazwa gogli Bolle — nie jest na liście słów do tłumaczenia
        $this->expectException(B2bTranslationRejected::class);
        $this->expectExceptionMessage('zgubiony token: UNIVERSAL');
        $translator = $this->translatorReturning(['name' => null, 'segments' => ['Gogle uniwersalne, opakowanie 10 szt.']]);

        $translator->translate('UNIVERSAL goggles, PACK of 10 PIECES');
    }

    #[Test]
    public function words_glued_by_punctuation_are_separate_tokens(): void
    {
        // Bollé KOVEMX10U-F 23.09.2026: „zgubiony token: D5),Overflow” — model poprawnie wstawił spację
        $source = 'Comfortable TPR gasket,Optimal protection (D3 D4 D5),Overflow chute for liquids,Sealed bi-material frame';
        $result = 'Wygodna uszczelka z TPR, optymalna ochrona (D3 D4 D5), rynienka odprowadzająca ciecze, szczelna oprawka dwumateriałowa';
        $translator = $this->translatorReturning(['segments' => [$result]]);

        $this->assertSame($result, $translator->translate($source)['description']);
    }

    #[Test]
    public function glued_model_code_is_still_protected(): void
    {
        // sklejone „(TRYON),Overflow” miało małe litery, więc nazwa modelu nie była chroniona wcale
        $this->expectException(B2bTranslationRejected::class);
        $this->expectExceptionMessage('zgubiony token: TRYON');

        $this->translatorReturning([
            'segments' => ['Wygodna uszczelka z TPR, rama jak w modelu, rynienka odprowadzająca ciecze'],
        ])->translate('Comfortable TPR gasket,Frame as in (TRYON),Overflow chute for liquids');
    }

    #[Test]
    public function rejects_english_segment_returned_without_translation(): void
    {
        // Bollé HUSTLN50E: „tłumaczenie” identyczne z angielskim źródłem zapisane jako gotowe
        $source = 'Experience unmatched protection and comfort with HUSTLER, now eco-designed.';

        try {
            $this->translatorReturning(['segments' => [$source]])->translate($source);
            $this->fail('Oczekiwano odrzucenia tekstu bez tłumaczenia');
        } catch (B2bTranslationRejected $e) {
            $this->assertStringContainsString('model zwrócił tekst źródła bez tłumaczenia', $e->getMessage());
            // odpowiedź modelu idzie z odrzuceniem do logu joba
            $this->assertSame(['segments' => [$source]], $e->modelResponse);
        }
    }

    #[Test]
    public function polish_segment_returned_unchanged_is_accepted(): void
    {
        $source = 'Zestaw pianki i paska do gogli NESS+';
        $translator = $this->translatorReturning(['segments' => [$source]]);

        $this->assertSame($source, $translator->translate($source)['description']);
    }

    #[Test]
    public function range_with_a_unit_may_be_written_the_polish_way(): void
    {
        $source = 'There is broad protection in the NIR range from 855nm-1090nm (OD5+).';
        $translator = $this->translatorReturning([
            'segments' => ['Szeroka ochrona w zakresie NIR od 855 nm do 1090 nm (OD5+).'],
        ]);

        $result = $translator->translate($source);

        $this->assertSame('Szeroka ochrona w zakresie NIR od 855 nm do 1090 nm (OD5+).', $result['description']);
    }

    #[Test]
    public function rejects_a_number_lost_from_a_range(): void
    {
        $translator = $this->translatorReturning([
            'segments' => ['Szeroka ochrona w zakresie NIR od 855 nm (OD5+).'],
        ]);

        $this->expectException(B2bTranslationRejected::class);
        $translator->translate('There is broad protection in the NIR range from 855nm-1090nm (OD5+).');
    }

    #[Test]
    public function quantity_multiplier_may_be_written_the_polish_way(): void
    {
        // „(5x eyelets / linear meter)” to krotność, nie kod wyrobu — po polsku „5 przelotek na metr bieżący”
        $source = 'Eyelet tape on upper edge (5x eyelets / linear meter).';
        $translator = $this->translatorReturning([
            'segments' => ['Taśma z przelotkami na górnej krawędzi (5 przelotek na metr bieżący).'],
        ]);

        $result = $translator->translate($source);

        $this->assertSame('Taśma z przelotkami na górnej krawędzi (5 przelotek na metr bieżący).', $result['description']);
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
    public function lines_wrapped_mid_sentence_are_joined_before_translation(): void
    {
        // SIR Safety System (SAP): tekst łamany co ~72 znaki; model sklejał linie i kontrola liczby linii odrzucała
        // tłumaczenie (01.10.2026 MB1636: „w źródle 18, w tłumaczeniu 17”)
        $source = "Low shoe with printed grain leather upper, with glass fibre toecap and\n"
            ."PS type composite puncture-proof with constant thickness.\n"
            ."Ankle pad in Oxford polyester.\n"
            ."The mid-sole area, with a groove, is designed to enhance grip on ladders\n"
            ."(LG requirement).\n"
            ."The insoles, made of multi-punched EVA material, have anti-shock\n"
            .'properties.';
        $translator = $this->translatorReturning(['segments' => [
            "Półbut z cholewką ze skóry licowej z nadrukiem, z podnoskiem z włókna szklanego i wkładką antyprzebiciową typu PS o stałej grubości.\n"
            ."Wyściółka kostki z poliestru Oxford.\n"
            ."Śródpodeszwa z rowkiem poprawia chwyt na drabinach (wymóg LG).\n"
            .'Wkładki z wielokrotnie perforowanego materiału EVA mają właściwości amortyzujące.',
        ]]);

        $result = $translator->translate($source);

        $payload = json_decode((string) $this->calls[0]['messages'][1]['content'], true);
        $this->assertSame([
            "Low shoe with printed grain leather upper, with glass fibre toecap and PS type composite puncture-proof with constant thickness.\n"
            ."Ankle pad in Oxford polyester.\n"
            ."The mid-sole area, with a groove, is designed to enhance grip on ladders (LG requirement).\n"
            .'The insoles, made of multi-punched EVA material, have anti-shock properties.',
        ], $payload['segments']);
        $this->assertSame(4, substr_count($result['description'], "\n") + 1);
    }

    #[Test]
    public function separate_lines_are_not_joined(): void
    {
        // wyliczenia bez znaczników, pozycje listy, etykiety pól i zdania zakończone kropką zostają osobnymi liniami
        $source = "Built-in side shields with anti-scratch coating on both sides\n"
            ."Adjustable reinforced temples\n"
            ."The product has been designed to comply with the regulation in force.\n"
            ."The lining is in synthetic material, polypropylene, non-woven fabric\n"
            ."- smooth profile outsole\n"
            ."Main features of the footwear and the materials used in production\n"
            ."UPPER: microfibre\n"
            .'Short line without full stop';
        $translator = $this->translatorReturning(['segments' => ['x']]);

        try {
            $translator->translate($source);
        } catch (B2bTranslationRejected) {
            // odpowiedź atrapy nie ma znaczenia — sprawdzamy, co poszło do modelu
        }

        $payload = json_decode((string) $this->calls[0]['messages'][1]['content'], true);
        $this->assertSame([$source], $payload['segments']);
    }

    #[Test]
    public function uppercase_field_labels_may_be_translated(): void
    {
        // SIR 01.10.2026: „zgubiony token: UPPER, LINING, TOECAP, PUNCTURE-PROOF, FOOTBED, SOLE”
        $source = "UPPER: Printed grain leather\nLINING : 3D-TEX in polyester\nPUNCTURE-PROOF: PS-type composite\nDEXTERITY: 5";
        $translator = $this->translatorReturning(['segments' => [
            "Cholewka: skóra licowa z nadrukiem\nPodszewka: 3D-TEX z poliestru\nWkładka antyprzebiciowa: kompozyt typu PS\nZręczność: 5",
        ]]);

        $this->assertSame(
            "Cholewka: skóra licowa z nadrukiem\nPodszewka: 3D-TEX z poliestru\nWkładka antyprzebiciowa: kompozyt typu PS\nZręczność: 5",
            $translator->translate($source)['description']
        );
    }

    #[Test]
    public function uppercase_name_outside_a_field_label_stays_protected(): void
    {
        // wartość etykiety i słowo wersalikami w środku linii to nadal nazwy (PLATINUM®: z ® też)
        $translator = $this->translatorReturning(['segments' => ["Powłoka: przeciwmgielna\nKlasa 2: ochrona"]]);

        $this->expectException(B2bTranslationRejected::class);
        $this->expectExceptionMessage('zgubiony token: PLATINUM, FFP2');

        $translator->translate("COATING: PLATINUM anti-fog\nFFP2: protection");
    }

    #[Test]
    public function metal_free_and_shouted_warning_may_be_translated(): void
    {
        // SIR 01.10.2026: „zgubiony token: METAL, FREE” i „CLASS, TROUSERS, SHALL, WORN, COMBINATION, CUT, PROTECTION, JACKET”
        $source = "Low shoe with composite toecap, METAL FREE.\nTHE TROUSERS SHALL BE WORN IN COMBINATION WITH THE CUT PROTECTION\nJACKET";
        $translator = $this->translatorReturning(['segments' => [
            "Półbut z podnoskiem kompozytowym, BEZ METALU.\nSPODNIE NALEŻY NOSIĆ W POŁĄCZENIU Z KURTKĄ CHRONIĄCĄ PRZED PRZECIĘCIEM",
        ]]);

        $result = $translator->translate($source);

        $this->assertStringContainsString('BEZ METALU', $result['description']);
        $payload = json_decode((string) $this->calls[0]['messages'][1]['content'], true);
        $this->assertSame(
            ["Low shoe with composite toecap, METAL FREE.\nTHE TROUSERS SHALL BE WORN IN COMBINATION WITH THE CUT PROTECTION JACKET"],
            $payload['segments'],
            'Zdanie wersalikami złamane na dwie linie idzie do modelu jako jedna linia'
        );
    }

    #[Test]
    public function model_name_in_shouted_line_stays_protected(): void
    {
        // „NEW FOBIA SERIES”: FOBIA to nazwa wyrobu (jest w nazwie), NEW i SERIES — zwykłe słowa
        $translator = $this->translatorReturning(['name' => null, 'segments' => ["NOWA SERIA\nPółbut"]]);

        $this->expectException(B2bTranslationRejected::class);
        $this->expectExceptionMessage('zgubiony token: FOBIA');

        // nazwa karty przetłumaczona wcześniej — słowa nazwy bierzemy też z niej
        $translator->translate("NEW FOBIA SERIES\nLow shoe", null, 'FOBIA półbut MB1316');
    }

    #[Test]
    public function shouted_line_with_digits_stays_protected(): void
    {
        // normy i oznaczenia z cyfrą w linii wersalikami („EN 61340 ESD”) to nie tekst wykrzyczany — ESD musi zostać
        $translator = $this->translatorReturning(['segments' => ["EN ISO 20345 S3S SR, EN 61340\nPółbut"]]);

        $this->expectException(B2bTranslationRejected::class);
        $this->expectExceptionMessage('zgubiony token: ESD');

        $translator->translate("EN ISO 20345 S3S SR, EN 61340 ESD\nLow shoe");
    }

    #[Test]
    public function uppercase_word_used_also_in_lowercase_may_be_translated(): void
    {
        // SIR MD12Z7/MA1115: „VISOR made of…” przy „the visor”, „Grain cowhide LEATHER” przy „Grain leather offers…”
        $source = "VISOR made of polycarbonate.\nThe visor protects the face.\nEVA/RUBBER sole, rubber outsole.";
        $translator = $this->translatorReturning(['segments' => [
            "WIZJER z poliwęglanu.\nWizjer chroni twarz.\nPodeszwa EVA/guma, podeszwa zewnętrzna z gumy.",
        ]]);

        $this->assertStringStartsWith('WIZJER', $translator->translate($source)['description']);
    }

    #[Test]
    public function uppercase_word_from_the_product_name_stays_protected_even_when_used_in_lowercase(): void
    {
        // FLASH to nazwa wyrobu, choć tekst mówi też o „flash” — nazwa nie może zniknąć
        $translator = $this->translatorReturning(['name' => null, 'segments' => ['Przyłbica chroni przed błyskiem.']]);

        $this->expectException(B2bTranslationRejected::class);
        $this->expectExceptionMessage('zgubiony token: FLASH');

        $translator->translate('FLASH protects against welding flash.', null, 'FLASH – przyłbica spawalnicza');
    }

    #[Test]
    public function type_class_and_index_next_to_a_norm_may_be_written_in_polish(): void
    {
        $translator = $this->translatorReturning(['segments' => ['EN 13982 TYP 5; EN ISO 11393-2 KLASA 1; EN 14116 INDEKS 1']]);

        $this->assertSame(
            'EN 13982 TYP 5; EN ISO 11393-2 KLASA 1; EN 14116 INDEKS 1',
            $translator->translate('EN 13982 TYPE 5; EN ISO 11393-2 CLASS 1; EN 14116 INDEX 1 for coverall')['description']
        );
    }

    #[Test]
    public function norm_level_code_after_norm_number_is_not_a_thousands_group(): void
    {
        // SIR MA1524 05.10.2026: „EN 388 234XX” było liczone jako 388234XX — kod uznany za zgubiony, choć model go zachował
        $translator = $this->translatorReturning(['segments' => ["EN 388 234XX\nEN 407 412X4X, rękawice"]]);

        $this->assertSame("EN 388 234XX\nEN 407 412X4X, rękawice", $translator->translate("EN 388 234XX\nEN 407 412X4X, gloves")['description']);
    }

    #[Test]
    public function words_glued_in_the_source_are_compared_separately(): void
    {
        // SIR: „(EU)2016/425”, „APV:63/14.1/3.4/17.8”, „YKK®zippers”, „The3D-TEX”, „FFP2NRD”
        $source = "Regulation (EU)2016/425 and amendments.\nFreq. APV:63/14.1/3.4/17.8;\nHeavy duty YKK®zippers.\nThe3D-TEX polyester lining.\nFoldable FFP2NRD respirator.";
        $translator = $this->translatorReturning(['segments' => [
            "Rozporządzenie (UE) 2016/425 i zmiany.\nCzęst. APV: 63/14.1/3.4/17.8;\nWytrzymałe zamki YKK®.\nPodszewka 3D-TEX z poliestru.\nSkładana półmaska FFP2 NR D.",
        ]]);

        $this->assertStringContainsString('FFP2 NR D', $translator->translate($source)['description']);
    }

    #[Test]
    public function polish_equivalents_and_plain_uppercase_words_are_accepted(): void
    {
        // TLV = NDS, SRN (literówka SNR), FOOD SAFE, NEVER, zwrot z IN/WITH/THE, rodzaje wyrobów w odsyłaczach, AQL 1,5
        $source = "Up to 12 times the TLV, SRN 35 dB.\nEN 388 3131X, FOOD SAFE\nClean and NEVER with alcohol.\n"
            ."EN ISO 20471 CLASS 3 (IN COMBINATION WITH THE JACKET)\nMC3521 - MISTRAL COLOR TROUSERS\nNitrile, AQL 1.5.";
        $translator = $this->translatorReturning(['segments' => [
            "Do 12-krotności NDS, SNR 35 dB.\nEN 388 3131X, DO KONTAKTU Z ŻYWNOŚCIĄ\nCzyścić i NIGDY alkoholem.\n"
            ."EN ISO 20471 KLASA 3 (W POŁĄCZENIU Z KURTKĄ)\nMC3521 - spodnie MISTRAL COLOR\nNitryl, AQL 1,5.",
        ]]);

        $this->assertStringContainsString('NDS', $translator->translate($source)['description']);
    }

    #[Test]
    public function model_name_in_a_cross_reference_stays_protected(): void
    {
        // rodzaj wyrobu wolno przetłumaczyć, nazwy modelu (MISTRAL, COLOR) nie
        $translator = $this->translatorReturning(['segments' => ['Łączyć z MC3521 - spodnie MISTRAL kolorowe']]);

        $this->expectException(B2bTranslationRejected::class);
        $this->expectExceptionMessage('zgubiony token: COLOR');

        $translator->translate('Combine with MC3521 - MISTRAL COLOR TROUSERS');
    }

    #[Test]
    public function decimal_after_a_code_on_the_previous_line_is_a_value(): void
    {
        // SIR MA2424: „…FOOD SAFE\nLENGTH 29.5 cm” — 29.5 nie należy do kodu z linii wyżej
        $translator = $this->translatorReturning(['segments' => ["EN 388 3121X\nDługość 29,5 cm"]]);

        $this->assertSame("EN 388 3121X\nDługość 29,5 cm", $translator->translate("EN 388 3121X\nLength 29.5 cm")['description']);
    }

    #[Test]
    public function changed_layout_is_retried_line_by_line(): void
    {
        // model skleił tytuł ze zdaniem („w źródle 2, w tłumaczeniu 1”) — drugie zapytanie: każda linia osobno
        $responses = [
            ['segments' => ['Kurtka z poliestru TPU, 145 g/m².']],
            ['segments' => ['Kurtka z poliestru', 'TPU, 145 g/m².']],
        ];
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->twice()
            ->andReturnUsing(function (array $messages) use (&$responses): array {
                $this->calls[] = ['messages' => $messages, 'temperature' => null, 'max_tokens' => null];

                return array_shift($responses);
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $result = $this->app->make(B2bTextTranslator::class)->translate("Jacket in polyester\nTPU, 145 g/m².");

        $this->assertSame("Kurtka z poliestru\nTPU, 145 g/m².", $result['description']);
        $second = json_decode((string) $this->calls[1]['messages'][1]['content'], true);
        $this->assertSame(['Jacket in polyester', 'TPU, 145 g/m².'], $second['segments']);
    }

    #[Test]
    public function content_rejection_is_not_retried_line_by_line(): void
    {
        $translator = $this->translatorReturning(['segments' => ["Kurtka\nPoliester"]]);

        try {
            $translator->translate("Jacket TRYON\nPolyester");
            $this->fail('Oczekiwano odrzucenia.');
        } catch (B2bTranslationRejected $e) {
            $this->assertSame('zgubiony token: TRYON', $e->getMessage());
        }
        $this->assertCount(1, $this->calls);
    }

    #[Test]
    public function hyphenated_word_and_degree_unit_are_joined_with_the_previous_line(): void
    {
        $translator = $this->translatorReturning(['segments' => ['x']]);

        try {
            $translator->translate("Fire boot with nitrile rubber sole resistant up to 250\n°C; equipped with Air-\nMesh/microfibre upper.");
        } catch (B2bTranslationRejected) {
            // sprawdzamy tylko wejście modelu
        }

        $payload = json_decode((string) $this->calls[0]['messages'][1]['content'], true);
        $this->assertSame(['Fire boot with nitrile rubber sole resistant up to 250 °C; equipped with Air-Mesh/microfibre upper.'], $payload['segments']);
    }

    #[Test]
    public function line_starting_with_a_comma_continues_the_previous_one(): void
    {
        $translator = $this->translatorReturning(['segments' => ['x']]);

        try {
            $translator->translate("Helmet with retractable shield with SHELL made of high-density\npolypropylene\n, equipped with stiffening ribs.");
        } catch (B2bTranslationRejected) {
            // sprawdzamy tylko wejście modelu
        }

        $payload = json_decode((string) $this->calls[0]['messages'][1]['content'], true);
        $this->assertSame(['Helmet with retractable shield with SHELL made of high-density polypropylene , equipped with stiffening ribs.'], $payload['segments']);
    }

    #[Test]
    public function pvc_may_be_written_as_polish_pcw_or_pcv(): void
    {
        // 23.09.2026: „oprawki BL150 z PCW” odrzucone jako „zgubiony token: PVC” — to ten sam materiał
        $source = 'Replacement PVC frame for BL150 goggles.';
        foreach (['Zapasowa oprawka z PCW do gogli BL150.', 'Zapasowa oprawka z PCV do gogli BL150.'] as $result) {
            $translator = $this->translatorReturning(['segments' => [$result]]);

            $this->assertSame($result, $translator->translate($source)['description']);
        }
    }

    #[Test]
    public function rejects_pc_written_instead_of_pvc(): void
    {
        // Bollé BL15APSI 23.09.2026: model napisał „oprawka z PC” — poliwęglan to inny materiał niż PVC
        $translator = $this->translatorReturning([
            'segments' => ['Zapasowa oprawka z PC do gogli BL150.'],
        ]);

        $this->expectException(B2bTranslationRejected::class);
        $this->expectExceptionMessage('zgubiony token: PVC');

        $translator->translate('Replacement PVC frame for BL150 goggles.');
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
    public function name_family_is_the_code_before_the_first_dash(): void
    {
        // Bollé BL150N10W 23.09.2026: „Pack of 10 pieces” to nie kod — wolno go przetłumaczyć
        $translator = $this->translatorReturning([
            'name' => 'BL150 - Zestaw 10 sztuk – Przezroczyste okulary ochronne',
            'segments' => [],
        ]);

        $this->assertSame(
            'BL150 - Zestaw 10 sztuk – Przezroczyste okulary ochronne',
            $translator->translate('', 'BL150 - Pack of 10 pieces – Clear safety goggle')['name'],
        );
    }

    #[Test]
    public function changed_code_before_the_first_dash_is_still_rejected(): void
    {
        $this->expectException(B2bTranslationRejected::class);
        $this->expectExceptionMessage('zmieniony człon nazwy „BL150”');

        $this->translatorReturning([
            'name' => 'BL 150 - Zestaw 10 sztuk – Przezroczyste okulary ochronne',
            'segments' => [],
        ])->translate('', 'BL150 - Pack of 10 pieces – Clear safety goggle');
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
