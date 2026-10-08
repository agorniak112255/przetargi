<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProductDescriptionText;
use Tests\TestCase;

final class ProductDescriptionTextTest extends TestCase
{
    /** Batch #312: tytuł strony sklepu powtórzony jako opis to zrzut strony, nie opis produktu. */
    public function test_shop_page_title_repeated_as_description_is_a_page_dump(): void
    {
        $this->assertTrue(ProductDescriptionText::looksLikeForeignOrPartsTableDump(
            "Kurtka polar CANIS CXS 4ENVI SOLIS szaro-czarna - BLUZY\nKurtka polar CANIS CXS 4ENVI SOLIS szaro-czarna\nKurtka polar CANIS CXS 4ENVI SOLIS szaro-czarna"
        ));
        $this->assertTrue(ProductDescriptionText::looksLikeForeignOrPartsTableDump(
            "Spodnie CANIS STRETCH do pasa ciemnoniebiesko-czarne\nSpodnie CANIS STRETCH do pasa ciemnoniebiesko-czarne - Sklep Market BHP\nSpodnie CANIS STRETCH do pasa [1020-027-441-00]"
        ));
        $this->assertTrue(ProductDescriptionText::looksLikeForeignOrPartsTableDump(
            "Spodnie Pas Canis Cxs Orion Teodor 1020 004 710 00 Ocieplana BHP\nSPODNIE PAS CANIS CXS ORION TEODOR 1020 004 710 00 OCIEPLANA BHP"
        ));
        $this->assertTrue(ProductDescriptionText::looksLikeForeignOrPartsTableDump(
            "Centrum Elektronarzedzi - elektronarzędzia, narzędzia ręczne, spawalnicze, pneumatyczne, metalowe, BHP, śruby - Milwaukee, DeWALT, Makita...\n\nPodaj e-mail"
        ));

        $this->assertFalse(ProductDescriptionText::looksLikeForeignOrPartsTableDump(
            "Kurtka robocza CXS SOLIS FLEX\nKurtka robocza CXS SOLIS FLEX to lekka kurtka z tkaniny softshell 65% poliester, 35% bawełna, z kieszeniami na zamek i odblaskowymi lamówkami.\n\nZastosowanie: budownictwo, magazyny, prace na zewnątrz."
        ), 'nagłówek z nazwą nad akapitem to zwykły opis');
        $this->assertFalse(ProductDescriptionText::looksLikeForeignOrPartsTableDump(
            "Półmaska filtrująca 3M 9322+ FFP2 z zaworem wydechowym - chroni przed pyłami i aerozolami.\nNorma EN 149:2001+A1:2009."
        ), 'myślnik w pierwszym zdaniu to nie tytuł strony');
        $this->assertFalse(ProductDescriptionText::looksLikeForeignOrPartsTableDump(
            'Kurtka robocza CXS SOLIS FLEX
Kurtka robocza CXS SOLIS FLEX - lekka kurtka softshell z odpinanym kapturem i odblaskami.
Materiał: 94% poliester, 6% elastan.'
        ), 'nagłówek i zdanie „Nazwa - opis” to zwykły opis');
        $this->assertTrue(ProductDescriptionText::looksLikeForeignOrPartsTableDump(
            'Mata gumowa Bubblemat Czarny 0.9m x 1.2m (14mm) COBA (BF010702) - b2b.rkmpro.tools
Mata gumowa Bubblemat Czarny 0.9m x 1.2m (14mm) COBA (BF010702)'
        ), 'tytuł z domeną sklepu to też zrzut strony');
    }

    public function test_strips_html_and_css_wall(): void
    {
        $raw = '<div class="product-description" style="white-space:nowrap;width:2400px">'
            .'<p>Rękawice nitrylowe do montażu.</p>'
            .'<style>.x{display:block}</style>'
            .'<ul><li>SKU: 1024</li></ul>'
            .'</div>';

        $plain = ProductDescriptionText::plain($raw);

        $this->assertStringContainsString('Rękawice nitrylowe do montażu.', $plain);
        $this->assertStringContainsString('SKU: 1024', $plain);
        $this->assertStringNotContainsString('<div', $plain);
        $this->assertStringNotContainsString('white-space:nowrap', $plain);
    }

    public function test_keeps_specification_and_cuts_pictogram_legend(): void
    {
        $plain = ProductDescriptionText::plain(
            "Rękawice Camapren 720 z polichloroprenu.\n\nSpecyfikacja:\n- ukryte w opisie\n\n"
            ."Piktogramy\nP / HRO — nie dotyczy tego modelu"
        );

        $this->assertStringContainsString('Specyfikacja', $plain);
        $this->assertStringContainsString('ukryte w opisie', $plain);
        $this->assertStringNotContainsString('Piktogramy', $plain);
        $this->assertStringNotContainsString('HRO', $plain);
    }

    public function test_keeps_argon_card_and_strips_shop_chrome(): void
    {
        $plain = ProductDescriptionText::plain(
            'ARTRABiałe półbuty robocze S2. zoom_out_map chevron_left −20% '
            ."Czas wysyłki od 5 do 8 dni roboczych. Indywidualna wycena dla firm.\n\n"
            ."PÓŁBUTY ROBOCZE ARGON 8229 1010 S2 ARTRA to obuwie bezpieczne klasy S2.\n\n"
            ."Specyfikacja:\n"
            ."- stalowy podnosek LIBERYUM\n"
            ."- BRAK WKŁADKI ANTYPRZEBICIOWEJ\n\n"
            ."Cechy produktu:\n- Cholewka PURYA SKINYUM\n\n"
            ."Piktogramy\nP HRO — legenda wszystkich klas\n"
            .'BUTY ROBOCZE PÓŁBUTY ARICA 6207 1010 S2 227,19 zł'
        );

        $this->assertStringContainsString('ARTRA Białe', $plain);
        $this->assertStringContainsString('ARGON 8229', $plain);
        $this->assertStringContainsString('LIBERYUM', $plain);
        $this->assertStringContainsString('BRAK WKŁADKI ANTYPRZEBICIOWEJ', $plain);
        $this->assertStringContainsString('SKINYUM', $plain);
        $this->assertStringNotContainsString('zoom_out_map', $plain);
        $this->assertStringNotContainsString('Piktogramy', $plain);
        $this->assertStringNotContainsString('ARICA', $plain);
    }

    public function test_strips_shopify_size_price_dump_and_keeps_bhp_facts(): void
    {
        $raw = "ARMEN 9003 6660 S1 ESD\n"
            .'EU 35 - 309 złEU 36 - 309 złEU 37 - 309 złEU 38 - 309 złEU 39 - 309 zł'
            ."EU 40 - 309 złEU 41 - 309 złEU 42 - 309 zł Wariant\n"
            .'Konstrukcja obuwia ARELAX zapewnia przestrzeń dla palców. '
            ."Podeszwa LYFTOR PU.2D z podnoskiem LIBERYUM.\n"
            ."Rozmiar EU Długość stopy --- --- **35** 21,8 **36** 22,4\n"
            ."### Jak dobrać rozmiar?\n"
            ."Jeśli obuwie nie będzie Państwu odpowiadać, mogą je Państwo zwrócić w ciągu 30 dni od otrzymania.\n"
            .'Natychmiast do wysyłki • Darmowa dostawa';

        $plain = ProductDescriptionText::plain($raw);

        $this->assertStringContainsString('ARELAX', $plain);
        $this->assertStringContainsString('LIBERYUM', $plain);
        $this->assertStringNotContainsString('309 zł', $plain);
        $this->assertStringNotContainsString('Wariant', $plain);
        $this->assertStringNotContainsString('Jak dobrać rozmiar', $plain);
        $this->assertStringNotContainsString('30 dni', $plain);
        $this->assertStringNotContainsString('Natychmiast do wysyłki', $plain);
    }

    public function test_strips_size_prices_without_eu_prefix_and_lowercase_wariant(): void
    {
        $plain = ProductDescriptionText::plain(
            '35 - 309 zł36 - 309 zł37 - 309 zł38 - 309 zł wariant '
            .'Półbuty S1 z podnoskiem LIBERYUM. 50 - 129 eur51 - 129 €'
        );

        $this->assertStringContainsString('LIBERYUM', $plain);
        $this->assertStringNotContainsString('309 zł', $plain);
        $this->assertStringNotContainsString('wariant', mb_strtolower($plain));
        $this->assertStringNotContainsString('129 eur', mb_strtolower($plain));
        $this->assertStringNotContainsString('129 €', $plain);
    }

    public function test_splits_long_blob_into_paragraphs(): void
    {
        $blob = 'Pierwsze zdanie opisuje przeznaczenie rękawic do montażu w suchych warunkach. '
            .'Drugie zdanie mówi o wkładce z poliestru i powłoce nitrylowej na dłoni. '
            .'Trzecie zdanie wyjaśnia, że mankiet ze ściągaczem utrzymuje rękawicę na miejscu. '
            .'Czwarte zdanie podaje zgodność z EN 388 przy codziennej pracy. '
            .'Piąte zdanie podkreśla chwyt i odporność na ścieranie w zakładzie. '
            .'Szóste zdanie wskazuje zastosowanie przy kompletacji i pracach precyzyjnych.';

        $paras = ProductDescriptionText::paragraphs($blob);

        $this->assertGreaterThanOrEqual(2, count($paras));
        $this->assertStringContainsString('przeznaczenie', $paras[0]);
    }

    public function test_drops_spec_sentences_copied_from_description(): void
    {
        $prose = 'Rękawice nitrylowe Ansell do montażu w warunkach suchych. Trwała powłoka zwiększa chwyt.';
        $items = ProductDescriptionText::dropDuplicatedListItems([
            'SKU: 1024',
            'Rękawice nitrylowe Ansell do montażu w warunkach suchych.',
        ], $prose);

        $this->assertSame(['SKU: 1024'], $items);
    }

    public function test_cuts_glued_spec_table_dump_and_keeps_prose(): void
    {
        $plain = ProductDescriptionText::plain(
            "Adhesion Strength (Imperial)22 oz/in\n"
            ."Adhesion Strength (metric)24 N/100mm\n"
            ."Overall Width (Imperial)4 in, 12 in, 24 in, 36 in\n"
            ."Total Tape Thickness without Liner (Metric)424.2 mm\n\n"
            .'Suitable for both indoor and outdoor use, our thick backing and specially formulated '
            .'rubber adhesive provide a strong bond and clean removal to safeguard various surfaces.'
        );

        $this->assertStringNotContainsString('Adhesion Strength', $plain);
        $this->assertStringNotContainsString('oz/in', $plain);
        $this->assertStringNotContainsString('424.2 mm', $plain);
        $this->assertStringStartsWith('Suitable for both indoor', $plain);
    }

    public function test_cuts_flattened_comparison_table_of_other_products(): void
    {
        $plain = ProductDescriptionText::plain(
            "Taśma ochronna do wymagających zastosowań.\n\n"
            .'--- --- --- --- Overall Width (Metric) 609.6 mm 76.2 mm, 50.8 mm, 25.4 mm '
            ."Product Color Tan Transparent\n"
        );

        $this->assertStringContainsString('Taśma ochronna', $plain);
        $this->assertStringNotContainsString('609.6 mm', $plain);
        $this->assertStringNotContainsString('Transparent', $plain);
    }

    public function test_cuts_size_table_with_unit_in_the_label(): void
    {
        $plain = ProductDescriptionText::plain(
            "Rękawice antyprzecięciowe do prac montażowych w suchym środowisku.\n"
            ."Obwód dłoni (mm)152 178 203 229 254 279 304\n"
            ."Długość dłoni (mm)160 171 182 192 204 215 226\n"
        );

        $this->assertStringContainsString('Rękawice antyprzecięciowe', $plain);
        $this->assertStringNotContainsString('Obwód dłoni', $plain);
        $this->assertStringNotContainsString('229', $plain);
    }

    public function test_keeps_standard_designations(): void
    {
        $plain = ProductDescriptionText::plain(
            "Odzież ostrzegawcza dla służb drogowych.\nEN ISO 13688\nEN ISO 20471\nISO 13997\n"
        );

        $this->assertStringContainsString('EN ISO 13688', $plain);
        $this->assertStringContainsString('EN ISO 20471', $plain);
        $this->assertStringContainsString('ISO 13997', $plain);
    }

    public function test_keeps_feature_bullets_that_are_not_table_rows(): void
    {
        $plain = ProductDescriptionText::plain(
            "Extremely cut-resistant gloves with grippy micro-cup nitrile coating\n"
            ."Ideal for high-risk jobs and handling sharp materials in dry conditions\n"
            ."Touchscreen compatibility for convenience\n"
            ."Kieszeń na telefon\n"
            ."Ochrona podbródka zwiększa komfort\n"
        );

        $this->assertStringContainsString('Extremely cut-resistant gloves', $plain);
        $this->assertStringContainsString('Touchscreen compatibility', $plain);
        $this->assertStringContainsString('Kieszeń na telefon', $plain);
        $this->assertStringContainsString('Ochrona podbródka', $plain);
    }

    public function test_keeps_sentence_that_merely_carries_a_unit(): void
    {
        $plain = ProductDescriptionText::plain(
            "Pielęgnacja można prać w pralce przemysłowej do 40 °C\n"
            ."Care washable in industrial machine up to 40 °C\n"
        );

        $this->assertStringContainsString('pralce przemysłowej do 40 °C', $plain);
        $this->assertStringContainsString('industrial machine up to 40 °C', $plain);
    }

    /** Etap 3 (§1.4): „<” przed liczbą to wartość — strip_tags zjadał resztę opisu (6× COBAtape, Exonit 852). */
    public function test_less_than_sign_before_a_value_is_text_not_a_tag(): void
    {
        $this->assertSame(
            'Grubość powłoki: 15 µm. Pozostałość rozpuszczalnika: <0,5%. Przechowywanie w temperaturze 20-30°C.',
            ProductDescriptionText::plain('Grubość powłoki: 15 µm. Pozostałość rozpuszczalnika: <0,5%. Przechowywanie w temperaturze 20-30°C.')
        );
        $this->assertSame(
            'Ochraniacze rozpraszają energię (średnia siła uderzenia <9 kN) i chronią grzbiet dłoni.',
            ProductDescriptionText::plain('Ochraniacze rozpraszają energię (średnia siła uderzenia <9 kN) i chronią grzbiet dłoni.')
        );
        $this->assertSame('Opór pary wodnej < 15 m²Pa/W, AQL <= 0,65.', ProductDescriptionText::plain('Opór pary wodnej < 15 m²Pa/W, AQL <= 0,65.'));
    }

    public function test_real_html_tags_and_comments_are_still_stripped(): void
    {
        $plain = ProductDescriptionText::plain(
            '<p>Rękawice nitrylowe <strong>do montażu</strong>.</p><!-- ukryte --><p>Odporność chemiczna: <span>&lt;10 min</span>.</p>'
            .'<?xml version="1.0"?><br/>Koniec <b>opisu</b>.'
        );

        $this->assertSame("Rękawice nitrylowe do montażu.\nOdporność chemiczna: <10 min.\n\nKoniec opisu.", $plain);
    }

    /**
     * Hipoteza H2 (CEDERROTH 26589): model złamał zdanie po nazwie z wymiarem — wiersz „nazwa + wymiar” z następną
     * linią od małej litery to początek zdania, nie wiersz tabeli. Wycięcie zostawiało w opisie samo „o symbolu…”.
     */
    public function test_name_with_dimensions_followed_by_lowercase_continuation_is_kept(): void
    {
        $plain = ProductDescriptionText::plain(
            "Bandaż pasuje do stacji pierwszej pomocy Cederroth First Aid Station.\n"
            ."Soft Foam Bandage Blue 6 cm x 200 cm\n"
            .'o symbolu 51011011 ma wymiary 6 cm szerokości i 200 cm (2 m) długości.'
        );

        $this->assertStringContainsString("Soft Foam Bandage Blue 6 cm x 200 cm\no symbolu 51011011", $plain);
    }

    public function test_glued_spec_row_is_dropped_even_before_a_lowercase_line(): void
    {
        $plain = ProductDescriptionText::plain(
            "Taśma ochronna do zabezpieczania powierzchni.\nOverall Length (Metric)54.9 m\noraz klej kauczukowy."
        );

        $this->assertStringNotContainsString('Overall Length', $plain);
        $this->assertStringNotContainsString('54.9 m', $plain);
        $this->assertStringContainsString('oraz klej kauczukowy.', $plain);
    }

    public function test_spaced_spec_rows_before_an_uppercase_paragraph_are_still_dropped(): void
    {
        $plain = ProductDescriptionText::plain(
            "Elongation at Break 4 %\nTensile Strength 22 N/cm\n\nTaśma do znakowania podłóg w halach."
        );

        $this->assertSame('Taśma do znakowania podłóg w halach.', $plain);
    }

    /** COBAtape TP010002 — opis z produkcji (08.10.2026) urwany na „<0,5%” zjedzonym przez strip_tags. */
    public function test_unfinished_tail_after_colon_is_cut_to_last_sentence(): void
    {
        $text = 'COBAtape to samoprzylepna taśma podłogowa z PVC, przeznaczona do szybkiego i skutecznego oznaczania linii na '
            .'podłogach. Numer części: TP010002. Rozmiar: 50 mm x 33 m. Kolor: czarny. Waga: 0,35 kg. Materiał: PVC z klejem '
            .'na bazie gumy. Wytrzymałość na rozciąganie: 22 Ncm. Wydłużenie: 180%. Grubość powłoki: 15 µm. Pozostałości rozpuszczalnika:';

        $result = ProductDescriptionText::withoutUnfinishedTail($text);

        $this->assertSame('Pozostałości rozpuszczalnika:', $result['cut']);
        $this->assertStringEndsWith('Grubość powłoki: 15 µm.', $result['text']);
    }

    /** Exonit 852 (MAPA 34852019) — opis z produkcji urwany w nawiasie. */
    public function test_unfinished_tail_with_open_parenthesis_is_cut(): void
    {
        $text = 'Rękawice ochronne EXONIT 852 producenta MAPA (SKU 34852019) zostały zaprojektowane z myślą o ochronie przed '
            .'uderzeniami w ciężkich warunkach pracy, gdzie dłonie są narażone na siły uderzeniowe i zgniatające. Kluczowym '
            .'elementem konstrukcji jest jednoczęściowa wkładka z elastomeru termoplastycznego (TPR) naszyta na materiał '
            .'tekstylny na grzbiecie dłoni i palcach. Ochraniacze te łagodzą uderzenia, rozpraszają energię (średnia siła uderzenia';

        $result = ProductDescriptionText::withoutUnfinishedTail($text);

        $this->assertSame('Ochraniacze te łagodzą uderzenia, rozpraszają energię (średnia siła uderzenia', $result['cut']);
        $this->assertStringEndsWith('na grzbiecie dłoni i palcach.', $result['text']);
    }

    /**
     * Coba PL010001: audyt z 08.10 zastał opis urwany na „…Ładowanie elektrostatyczne” (odtworzone na obecnym opisie
     * z produkcji); obecny opis jest pełny i zostaje bez zmian.
     */
    public function test_unfinished_tail_ending_on_a_plain_word_is_cut_and_full_text_is_left_alone(): void
    {
        $full = 'Precision Loop to mata podłogowa klasy premium o gładkiej, welurowej powierzchni i precyzyjnym wykończeniu, '
            .'przeznaczona do stref wejściowych o średnim i intensywnym natężeniu ruchu (wyłącznie do użytku wewnętrznego). '
            .'Produkt charakteryzuje się niskimi kosztami instalacji, wysoką odpornością na promieniowanie UV oraz plamy.'
            ."\n\nMata jest dostępna w wariantach kolorystycznych czarnym i antracytowym, w formacie rolki 2m x 23m lub na metry "
            .'bieżące. Opcjonalnie dostępna jest fabrycznie montowana krawędź Needlepunch Edge (kod P249-C63-C09).';

        $this->assertSame(['text' => $full, 'cut' => ''], ProductDescriptionText::withoutUnfinishedTail($full));

        $truncated = $full.' Mata ogranicza ślizganie i zapobiega gromadzeniu ładunków. Ładowanie elektrostatyczne';
        $result = ProductDescriptionText::withoutUnfinishedTail($truncated);

        $this->assertSame('Ładowanie elektrostatyczne', $result['cut']);
        $this->assertStringEndsWith('zapobiega gromadzeniu ładunków.', $result['text']);
    }

    public function test_full_content_without_final_period_is_not_cut(): void
    {
        foreach ([
            'code' => "Rękawice z powłoką nitrylową do prac montażowych.\n\nSpełniają wymagania normy EN 388 4131X",
            'acronym' => 'Półbuty ochronne do prac w magazynach. Zgodność z wymaganiami względem obuwia ESD',
            'unit' => 'Mata przemysłowa z gumy. Dostępne warianty: 1,2 m x 10 m, czerwony, 9 kg/m',
            'feature lines' => "Okulary ochronne z poliwęglanu.\n\nOchrona górna, dolna i boczna\nPowłoka odporna na zarysowania",
            'inline list' => 'Rękawice do prac precyzyjnych. Właściwości: - dobry chwyt - wysoka elastyczność - bardzo dobra manualność',
            'label value' => "Taśma do znakowania podłóg.\n\nKolor: czarny",
            'short heading' => "Taśma do znakowania podłóg.\n\nMade in Poland",
            'one sentence' => 'Samoprzylepna taśma podłogowa z PVC do oznaczania linii, stref i przejść w halach',
        ] as $case => $text) {
            $this->assertSame(['text' => $text, 'cut' => ''], ProductDescriptionText::withoutUnfinishedTail($text), $case);
        }
    }

    public function test_abbreviation_is_not_a_sentence_end_for_the_cut(): void
    {
        $result = ProductDescriptionText::withoutUnfinishedTail(
            'Rękawice do prac ogólnych. Dostępne rozmiary np. 8, 9 i 10 oraz wersja kat. II w opakowaniach po 12 par,'
        );

        $this->assertSame('Rękawice do prac ogólnych.', $result['text']);
        $this->assertSame('Dostępne rozmiary np. 8, 9 i 10 oraz wersja kat. II w opakowaniach po 12 par,', $result['cut']);
    }

    /**
     * Audyt 08.10.2026: „Taśma o szer. 75 mm i dł. 100 m, kolor biało-czerwony” — kropka po skrócie spoza listy
     * („dł.”) brana była za koniec zdania i zostawało „…i dł.”. Koniec zdania wymaga za sobą wielkiej litery,
     * cudzysłowu albo nawiasu otwierającego, nowego wiersza albo końca tekstu — także przy skrócie, którego nie ma na liście.
     */
    public function test_abbreviation_followed_by_number_or_lowercase_is_not_a_sentence_end(): void
    {
        $sentence = 'Taśma o szer. 75 mm i dł. 100 m, kolor biało-czerwony';
        $this->assertSame(
            ['text' => 'Taśma ostrzegawcza do oznaczania stref niebezpiecznych.', 'cut' => $sentence],
            ProductDescriptionText::withoutUnfinishedTail('Taśma ostrzegawcza do oznaczania stref niebezpiecznych. '.$sentence)
        );
        // przed urwanym końcem nie ma pełnego zdania — tekst bez zmian
        $this->assertSame(['text' => $sentence, 'cut' => ''], ProductDescriptionText::withoutUnfinishedTail($sentence));

        // skrót spoza listy („zakr.”) przed liczbą i „np.” przed wielką literą też nie kończą zdania
        $result = ProductDescriptionText::withoutUnfinishedTail(
            'Lina asekuracyjna z hakiem. Lina o zakr. 5 m, zgodna np. EN 354 i dopuszczona do pracy przy'
        );
        $this->assertSame('Lina asekuracyjna z hakiem.', $result['text']);
        $this->assertSame('Lina o zakr. 5 m, zgodna np. EN 354 i dopuszczona do pracy przy', $result['cut']);
    }

    /** „szt.”, „itp.” zwykle kończą zdanie — przed wielką literą kropka po nich to koniec zdania. */
    public function test_sentence_final_abbreviation_before_uppercase_ends_the_sentence(): void
    {
        $result = ProductDescriptionText::withoutUnfinishedTail(
            'Rękawice nitrylowe do prac w magazynie. Opakowanie: 12 szt. Rękawice chronią dłonie przed otarciami i'
        );

        $this->assertSame('Rękawice nitrylowe do prac w magazynie. Opakowanie: 12 szt.', $result['text']);
        $this->assertSame('Rękawice chronią dłonie przed otarciami i', $result['cut']);

        $result = ProductDescriptionText::withoutUnfinishedTail(
            'Okulary do prac przy szlifowaniu, z kurzem, opiłkami itp. Soczewki z poliwęglanu chronią oczy przed'
        );
        $this->assertSame('Okulary do prac przy szlifowaniu, z kurzem, opiłkami itp.', $result['text']);
        // „itp.” przed małą literą to środek zdania
        $result = ProductDescriptionText::withoutUnfinishedTail(
            'Okulary ochronne. Do prac z kurzem, opiłkami itp. oraz przy szlifowaniu i cięciu tarczą, soczewki chronią oczy przed'
        );
        $this->assertSame('Okulary ochronne.', $result['text']);
    }

    public function test_abbreviation_list_is_shared_and_matches_only_whole_abbreviations(): void
    {
        $this->assertTrue(ProductDescriptionText::endsWithAbbreviation('Taśma o szer'));
        $this->assertTrue(ProductDescriptionText::endsWithAbbreviation('Taśma o szer. 75 mm i dł'));
        $this->assertTrue(ProductDescriptionText::endsWithAbbreviation('(m.in'));
        $this->assertTrue(ProductDescriptionText::endsWithAbbreviation('WERSJA KAT'));
        $this->assertFalse(ProductDescriptionText::endsWithAbbreviation('Rękawice do prac ogólnych'));
        $this->assertFalse(ProductDescriptionText::endsWithAbbreviation('powłoka numer'));
        $this->assertFalse(ProductDescriptionText::endsWithAbbreviation('kompletnych'));
        foreach (ProductDescriptionText::SENTENCE_FINAL_ABBREVIATIONS as $abbreviation) {
            $this->assertContains($abbreviation, ProductDescriptionText::ABBREVIATIONS);
        }
    }

    public function test_keeps_product_name_whose_model_code_looks_like_a_unit(): void
    {
        $plain = ProductDescriptionText::plain(
            "ARTRA Trzewiki bezpieczne ARUBA 941 6060 S3\nKangurka morska 3011\nTitan 850\n"
        );

        $this->assertStringContainsString('ARUBA 941 6060 S3', $plain);
        $this->assertStringContainsString('Kangurka morska 3011', $plain);
        $this->assertStringContainsString('Titan 850', $plain);
    }
}
