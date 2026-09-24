<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OfferProductText;
use Tests\TestCase;

/**
 * Tekst pozycji w liście do klienta. Trzy szablony pokazują ten sam wyrób
 * inaczej, a cały tekst musi pochodzić z karty — nic zmyślonego, nic
 * ujawnionego wbrew szablonowi.
 */
final class OfferProductTextTest extends TestCase
{
    private const VITAL = 'Rękawice ochronne VITAL 175 marki MAPA przeznaczone są do prac wymagających '
        .'precyzji i elastyczności w środowiskach o małej agresywności chemicznej. Wykonano je '
        .'z naturalnego lateksu posiadającego certyfikat FSC™. Długość rękawic wynosi 31 cm.';

    public function test_prose_stops_at_a_block_copied_from_the_supplier_page(): void
    {
        $description = "Rękawice nitrylowe do prac montażowych, grubość 0,11 mm.\n\n"
            ."NORMY I CERTYFIKATY:\n\nEN 388 0010X\nEN 374-5\n10 par/worek";

        $this->assertSame(
            'Rękawice nitrylowe do prac montażowych, grubość 0,11 mm.',
            OfferProductText::prose($description),
        );
    }

    public function test_paragraph_keeps_the_whole_description_and_cuts_on_a_sentence(): void
    {
        $this->assertSame(self::VITAL, OfferProductText::paragraph(self::VITAL));

        // Limit powyżej pierwszego zdania: cięcie wypada na kropce.
        $cutOnSentence = OfferProductText::paragraph(self::VITAL, 200);
        $this->assertNotNull($cutOnSentence);
        $this->assertStringEndsWith('chemicznej.', $cutOnSentence);

        // Limit krótszy niż pierwsze zdanie: nie ma gdzie ciąć na kropce,
        // więc urywamy na granicy wyrazu i mówimy o tym wielokropkiem.
        $cutOnWord = OfferProductText::paragraph(self::VITAL, 120);
        $this->assertNotNull($cutOnWord);
        $this->assertStringEndsWith('…', $cutOnWord);
        $this->assertLessThanOrEqual(120, mb_strlen($cutOnWord));
        $this->assertStringStartsWith('Rękawice ochronne VITAL 175', $cutOnWord);
    }

    public function test_paragraph_does_not_cut_inside_a_product_code(): void
    {
        // Kropka w kodzie wyrobu nie kończy zdania: list do klienta urywał się
        // na „(8543.8.” i tak szedł do wysyłki.
        $description = 'Półbut ochronny uvex 1 z materiałów syntetycznych, odpowiedni dla osób '
            .'uczulonych na chrom. Wyciągana wkładka antystatyczna odprowadza wilgoć. '
            .'Z karty technicznej (8543.8. wersja perforowana) wynika, że cholewka jest bezszwowa.';

        $cut = OfferProductText::paragraph($description, 190);

        $this->assertNotNull($cut);
        $this->assertStringEndsWith('odprowadza wilgoć.', $cut);
        $this->assertStringNotContainsString('8543.8.', $cut);
    }

    public function test_withdrawal_note_is_not_part_of_the_letter(): void
    {
        // Zapytanie #67 (24.09.2026): dopisek łącznika PROTEKT poszedł do klienta dosłownie,
        // z „Wycofany” dwa razy i kodem następcy sklejonym ze słowem. To stan karty dla
        // handlowca (panel zapytania), nie opis wyrobu.
        $description = "UWAGA: produkt wycofany przez producenta — Wycofany — zastąpiony przezBW100.\n\n"
            ."Cechy szczególne:\n- Dopuszczone do prac w strefach zagrożonych wybuchem";

        $paragraph = OfferProductText::paragraph($description);
        $this->assertNotNull($paragraph);
        $this->assertStringNotContainsString('wycofany', mb_strtolower($paragraph));
        $this->assertStringNotContainsString('BW100', $paragraph);
        $this->assertStringContainsString('Dopuszczone do prac w strefach zagrożonych wybuchem', $paragraph);

        // Szablon bez SKU brał pierwsze zdanie — czyli sam dopisek z kodem następcy.
        $lead = OfferProductText::genericLead($description, 'PROTEKT', null, 'ABM - Amortyzator bezpieczeństwa');
        $this->assertNotNull($lead);
        $this->assertStringNotContainsString('BW100', $lead);
        $this->assertStringNotContainsString('wycofany', mb_strtolower($lead));

        // Karta, której opis to tylko dopisek, nie ma opisu do listu.
        $this->assertNull(OfferProductText::paragraph('UWAGA: produkt wycofany przez producenta — Wycofany.'));
    }

    public function test_paragraph_is_null_when_the_card_has_no_description(): void
    {
        $this->assertNull(OfferProductText::paragraph(''));
        // „Jednostka: szt.” z cennika B2B nie jest opisem wyrobu
        $this->assertNull(OfferProductText::paragraph('Jednostka: szt.'));
    }

    public function test_generic_lead_drops_the_brand_and_the_model(): void
    {
        $lead = OfferProductText::genericLead(self::VITAL, 'MAPA', null, 'VITAL 175');

        $this->assertSame(
            'Rękawice ochronne przeznaczone są do prac wymagających precyzji i elastyczności '
                .'w środowiskach o małej agresywności chemicznej.',
            $lead,
        );
    }

    public function test_generic_lead_takes_only_the_first_sentence(): void
    {
        $lead = OfferProductText::genericLead(self::VITAL, 'MAPA', null, 'VITAL 175');

        $this->assertNotNull($lead);
        $this->assertStringNotContainsString('lateksu', $lead);
    }

    public function test_generic_lead_hides_the_model_from_a_separate_field(): void
    {
        $description = 'Gogle SUPERBLAST firmy Bolle chronią oczy przed odpryskami i pyłem '
            .'w pracach szlifierskich.';

        $lead = OfferProductText::genericLead($description, 'Bolle', 'SUPERBLAST', 'Gogle autoklawowalne');

        $this->assertSame(
            'Gogle chronią oczy przed odpryskami i pyłem w pracach szlifierskich.',
            $lead,
        );
    }

    public function test_generic_lead_keeps_a_descriptive_card_name(): void
    {
        $description = 'Gogle autoklawowalne wielokrotnego użytku z bezbarwnymi soczewkami '
            .'poliwęglanowymi do pracy w pomieszczeniach czystych.';

        $lead = OfferProductText::genericLead(
            $description,
            'Bolle',
            'ELITE',
            'Gogle autoklawowalne wielokrotnego użytku - bezbarwne soczewki PC',
        );

        // Długa nazwa karty jest opisem, nie oznaczeniem — nie ma jej po co wycinać.
        $this->assertSame($description, $lead);
    }

    public function test_generic_lead_is_null_when_nothing_sensible_is_left(): void
    {
        // Całe zdanie to marka i model — po wycięciu nie zostaje opis wyrobu.
        $this->assertNull(OfferProductText::genericLead('MAPA VITAL 175.', 'MAPA', 'VITAL 175', 'VITAL 175'));
        $this->assertNull(OfferProductText::genericLead('', 'MAPA', null, 'VITAL 175'));
    }

    public function test_generic_lead_cuts_a_brand_written_differently_than_in_the_card(): void
    {
        // W karcie producent to „3M Peltor X”, w opisie „3M Peltor” — całość się
        // nie dopasuje, więc wycinamy też pojedyncze wyrazy nazwy.
        $description = 'Nauszniki przeciwhałasowe 3M Peltor tłumią hałas o 31 dB w pracach budowlanych.';

        $lead = OfferProductText::genericLead($description, '3M Peltor X', null, 'H540A');

        $this->assertSame('Nauszniki przeciwhałasowe tłumią hałas o 31 dB w pracach budowlanych.', $lead);
    }

    public function test_generic_lead_keeps_legal_form_words_out_of_cutting(): void
    {
        // „Poland” z nazwy producenta nie jest nazwą do wycinania — inaczej
        // zniknęłoby z każdego zdania, które mówi o rynku polskim.
        $description = 'Obuwie robocze produkowane dla rynku Poland spełnia wymagania prac budowlanych.';

        $lead = OfferProductText::genericLead($description, 'Delta Poland', null, 'S3-42');

        $this->assertSame($description, $lead);
    }
}
