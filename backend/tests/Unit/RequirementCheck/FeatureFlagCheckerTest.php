<?php

declare(strict_types=1);

namespace Tests\Unit\RequirementCheck;

use App\Models\Product;
use App\Support\PpeAssortment;
use App\Support\RequirementCheck\CardSource;
use App\Support\RequirementCheck\CardSources;
use App\Support\RequirementCheck\CheckRow;
use App\Support\RequirementCheck\FeatureFlagChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

final class FeatureFlagCheckerTest extends TestCase
{
    /** Opis karty 6003805 z produkcji (sonda S01, 25.09.2026) — bez słowa o antystatyce. */
    private const PHYNOMIC_AIRLITE_DESCRIPTION = 'uvex phynomic airLite - to najlżejsze rękawice ochronne w swojej klasie gwarantujące '
        .'wysoki komfort noszenia, bardzo dobrą manualność, lekkość oraz niezwykłą oddychalność. Są idealne do precyzyjnej '
        .'pracy, wymagającej również obsługi ekranów dotykowych.';

    public function test_poz_1_karta_11202000_cechy_wprost_ok_a_bezpieczny_dla_zywnosci_do_sprawdzenia(): void
    {
        $rows = $this->rows(Opisowy15Fixture::requirement(1), CardSources::fromProduct(Opisowy15Fixture::product('11202000')));

        // FDA: angielski opis karty mówi dosłownie „Food handling compliant under FDA regulations”.
        $this->assertSame([
            'antistatic' => 'ok',
            'latex_free' => 'ok',
            'silicone_free' => 'ok',
            'food_contact' => 'unclear',
            'fda' => 'ok',
            'seamless' => 'ok',
            'hook_and_loop' => 'ok',
        ], $this->statuses($rows));
        $this->assertSame('antistatic', $rows['antistatic']->gate);
        $this->assertStringContainsString('bez lateksu', $rows['latex_free']->required['quote']);
        // „Bez lateksu i silikonu” to jedno wyliczenie, które spełnia obie cechy
        $this->assertSame('Bez lateksu i silikonu', $rows['silicone_free']->card[0]['text']);
        $this->assertSame(['unclear', 'unclear'], array_column($rows['food_contact']->card, 'verdict'));
    }

    public function test_poz_1_karta_produkcyjna_cieta_i_szyta_przeczy_bezszwowosci(): void
    {
        $rows = $this->rows(Opisowy15Fixture::requirement(1), $this->productionSleeveCard());

        $this->assertSame([
            'antistatic' => 'ok',
            'latex_free' => 'ok',
            'silicone_free' => 'missing',
            'food_contact' => 'missing',
            'fda' => 'missing',
            'seamless' => 'fail',
            'hook_and_loop' => 'ok',
        ], $this->statuses($rows));
        $this->assertSame(
            ['text' => 'cięta i szyta', 'source' => 'description', 'verdict' => 'fail'],
            array_intersect_key($rows['seamless']->card[0], array_flip(['text', 'source', 'verdict'])),
        );
    }

    public function test_poz_15_powloka_z_lateksu_w_wymaganiu_nie_tworzy_wiersza_bez_lateksu(): void
    {
        $rows = $this->rows(Opisowy15Fixture::requirement(15), CardSources::fromProduct(Opisowy15Fixture::product('87320100-BULK')));

        // wymaganie poz. 15 nie żąda antystatyczności ani braku silikonu — tych wierszy nie ma
        $this->assertSame(['food_contact' => 'ok'], $this->statuses($rows));
    }

    public function test_tabela_etykieta_nie_na_karcie_87320100_to_zaprzeczenie(): void
    {
        $rows = $this->rows(Opisowy15Fixture::requirement(1), CardSources::fromProduct(Opisowy15Fixture::product('87320100-BULK')));

        $this->assertSame('fail', $rows['antistatic']->status->value);
        $this->assertSame('ANTYELEKTROSTATYCZNE Nie', $rows['antistatic']->card[0]['text']);
        // „BEZ SILIKONU Nie” nie może dać też ok ze wzorca „bez silikonu”
        $this->assertSame(['BEZ SILIKONU Nie'], array_column($rows['silicone_free']->card, 'text'));
        $this->assertSame('fail', $rows['silicone_free']->status->value);
        $this->assertSame('fail', $rows['latex_free']->status->value);
        $this->assertSame('ok', $rows['food_contact']->status->value);
        $this->assertSame('missing', $rows['fda']->status->value);
    }

    public function test_tabela_tak_daje_ok_a_nie_dotyczy_do_sprawdzenia(): void
    {
        $this->assertSame('ok', $this->statusFor('silicone_free', 'Rękawice bez silikonu', ["* BEZ SILIKONU Tak\n* CHLOROWANE Tak"]));
        $this->assertSame('unclear', $this->statusFor('antistatic', 'Obuwie antystatyczne', ['Antystatyczne: nie dotyczy']));
    }

    public function test_lateks_naturalny_przy_wymaganym_bez_lateksu_to_fail(): void
    {
        $this->assertSame('fail', $this->statusFor('latex_free', 'Rękawice bez lateksu', ['Materiał: lateks naturalny']));
        $this->assertSame('fail', $this->statusFor('latex_free', 'Rękawice bez lateksu', ['Rękawice z powłoką lateksową']));
    }

    public function test_zaprzeczenie_przed_sprzecznoscia_nie_daje_fail(): void
    {
        // „bez powłoki lateksowej” nie mówi, że wyrób ma lateks — ale nie jest też wzorcem wprost
        $this->assertSame('missing', $this->statusFor('latex_free', 'Rękawice bez lateksu', ['Wykonane bez powłoki lateksowej']));
        // „bez lateksu nie powodują” to zdanie, nie komórka tabeli „ETYKIETA Nie”
        $this->assertSame('ok', $this->statusFor('latex_free', 'Rękawice bez lateksu', ['Rękawice bez lateksu nie powodują uczuleń']));
    }

    public function test_szwy_zgrzewane_nie_przecza_bezszwowosci(): void
    {
        $this->assertSame('missing', $this->statusFor('seamless', 'Kaptur bezszwowy', ['Szwy zgrzewane taśmą']));
    }

    /**
     * Decyzja właściciela z 25.09.2026 (C, D7a): ESD i normy antystatyki są dowodem antystatyki, nie osobną cechą —
     * jeden wiersz „Antystatyczny”, bez wiersza „ESD” i bez notki „ESD to nie to samo co antystatyczny”.
     */
    public function test_esd_to_dowod_antystatyki_a_nie_osobna_cecha(): void
    {
        $rows = $this->rows('Obuwie antystatyczne S3', [new CardSource(CardSource::NAME, 'Półbuty S1 ESD')]);
        $this->assertSame('ok', $rows['antistatic']->status->value);
        $this->assertSame('ESD', $rows['antistatic']->card[0]['text']);
        $this->assertNull($rows['antistatic']->note);
        $this->assertArrayNotHasKey('esd', $rows);

        // EN 1149 to norma odzieży — na bucie nie jest dowodem ESD, którego żąda wymaganie
        $rows = $this->rows('Obuwie ESD zgodne z EN 61340-5-1', [new CardSource(CardSource::NORMS, 'EN 1149-5')]);
        $this->assertArrayNotHasKey('esd', $rows);
        $this->assertSame('unclear', $rows['antistatic']->status->value);
        $this->assertSame('Karta podaje EN 1149, a to nie jest dowód ESD dla tego rodzaju wyrobu — propozycja do sprawdzenia', $rows['antistatic']->note);

        // przy wprost antystatycznym ESD obok nie obniża wyniku
        $this->assertSame('ok', $this->statusFor('antistatic', 'Obuwie antystatyczne', ['Obuwie antystatyczne, ESD']));
    }

    /** Zgłoszenie z recenzji: karta 6003805 z produkcji — ESD tylko w nazwie, opis bez słowa o antystatyce. */
    public function test_rekawice_antystatyczne_i_karta_6003805_z_esd_w_nazwie_spelniaja(): void
    {
        $card = (new Product)->forceFill([
            'sku' => '6003805',
            'name' => 'Rękawice Phynomic airLite A ESD',
            'description' => self::PHYNOMIC_AIRLITE_DESCRIPTION,
        ]);

        $rows = $this->rows('Rękawice ochronne antystatyczne', CardSources::fromProduct($card));

        $this->assertSame('ok', $rows['antistatic']->status->value);
        $this->assertSame(
            ['text' => 'ESD', 'source' => 'name', 'verdict' => 'ok'],
            array_intersect_key($rows['antistatic']->card[0], array_flip(['text', 'source', 'verdict'])),
        );
        $this->assertNull($rows['antistatic']->note);
        // ta sama karta przy wymaganiu, które samo żąda ESD albo normy rękawic
        $this->assertSame('ok', $this->rows('Rękawice antyelektrostatyczne wg EN 16350', CardSources::fromProduct($card))['antistatic']->status->value);
    }

    /** Rękawice, odzież i pozostałe: „ESD”, EN 1149 albo EN 16350; EN 61340 decyzja wymienia tylko przy obuwiu. */
    public function test_dowod_antystatyki_rekawic_i_odziezy(): void
    {
        $gloves = 'Rękawice montażowe powlekane z funkcją ESD';
        $this->assertSame('ok', $this->statusFor('antistatic', $gloves, ['Rękawice Phynomic airLite A ESD']));
        $this->assertSame('ok', $this->statusFor('antistatic', $gloves, ['EN 388:2016, EN 1149-5:2018']));
        $this->assertSame('ok', $this->statusFor('antistatic', $gloves, ['EN 388:2016 4131X, EN 16350:2014']));
        $this->assertSame('ok', $this->statusFor('antistatic', $gloves, ['Rękawice powlekane nitrylem, EN ISO 16350']));
        $rows = $this->rows($gloves, [new CardSource(CardSource::NORMS, 'IEC 61340-5-1')]);
        $this->assertSame('unclear', $rows['antistatic']->status->value);
        $this->assertSame('IEC 61340-5-1', $rows['antistatic']->card[0]['text']);
        $this->assertSame('Karta podaje EN 61340, a to nie jest dowód ESD dla tego rodzaju wyrobu — propozycja do sprawdzenia', $rows['antistatic']->note);

        $coverall = 'Kombinezon ochronny typ 5/6, EN 1149-5';
        $this->assertSame('ok', $this->statusFor('antistatic', $coverall, ['EN 14605, EN 1149-5']));
        $this->assertSame('unclear', $this->statusFor('antistatic', $coverall, ['Kombinezon antyelektrostatyczny typ 5/6']));
    }

    /** Obuwie: „ESD” albo EN 61340; EN 1149 i EN 16350 (normy odzieży i rękawic) to przy nim propozycja do sprawdzenia. */
    public function test_dowod_antystatyki_obuwia(): void
    {
        $sandals = 'Sandały ochronne S1 P wg EN ISO 20345, właściwości antyelektrostatyczne (ESD)';
        $this->assertSame('ok', $this->statusFor('antistatic', $sandals, ['ARSO 701 616560 S1 P ESD']));
        $this->assertSame('ok', $this->statusFor('antistatic', $sandals, ['Spełnia normę EN ISO 20347:2012 oraz wymagania EN IEC 61340-4-3:2018.']));
        // sklejony zapis normy też jest normą
        $this->assertSame('ok', $this->statusFor('antistatic', $sandals, ['Sandały S1 P, EN61340-4-3']));
        $this->assertSame('unclear', $this->statusFor('antistatic', $sandals, ['Trzewiki S3 EN 1149-5']));
        $this->assertSame('unclear', $this->statusFor('antistatic', $sandals, ['Półbuty S3, EN 16350:2014']));

        // karta poz. 3 z produkcji: ESD w specyfikacji, cechach i opisie
        $rows = $this->rows(Opisowy15Fixture::requirement(3), CardSources::fromProduct(Opisowy15Fixture::product('ARMEN 9007 6660 S1 P')));
        $this->assertSame('ok', $rows['antistatic']->status->value);
        $this->assertArrayNotHasKey('esd', $rows);
    }

    /**
     * Gdy wymaganie żąda ESD albo normy, samo słowo na karcie to propozycja do sprawdzenia (jak limit oceny w
     * wyszukiwarce), a obok dowodu wprost słowo nie obniża wyniku i nie jest pokazywane jako „do sprawdzenia”.
     */
    public function test_samo_slowo_przy_zadaniu_esd_albo_normy_to_propozycja(): void
    {
        $rows = $this->rows('Rękawice antyelektrostatyczne wg EN 16350', [new CardSource(CardSource::FEATURES, 'Rękawice antystatyczne powlekane poliuretanem')]);
        $this->assertSame('unclear', $rows['antistatic']->status->value);
        $this->assertSame(['antystatyczne'], array_column($rows['antistatic']->card, 'text'));
        $this->assertSame('Karta podaje antystatykę tylko słownie, bez oznaczenia ESD ani normy — propozycja do sprawdzenia', $rows['antistatic']->note);

        $rows = $this->rows('Rękawice antyelektrostatyczne wg EN 16350', [
            new CardSource(CardSource::FEATURES, 'Rękawice antystatyczne powlekane poliuretanem'),
            new CardSource(CardSource::NORMS, 'EN 388:2016, EN 16350:2014'),
        ]);
        $this->assertSame('ok', $rows['antistatic']->status->value);
        $this->assertSame(['EN 16350'], array_column($rows['antistatic']->card, 'text'));
        $this->assertNull($rows['antistatic']->note);

        // rękaw HyFlex 11-202 (samo „antistatic”) przy sandałach z poz. 3, które żądają ESD
        $rows = $this->rows(Opisowy15Fixture::requirement(3), CardSources::fromProduct(Opisowy15Fixture::product('11202000')));
        $this->assertSame('unclear', $rows['antistatic']->status->value);
        $this->assertSame('Karta podaje antystatykę tylko słownie, bez oznaczenia ESD ani normy — propozycja do sprawdzenia', $rows['antistatic']->note);
    }

    /** Wymaganie z samym słowem nie żąda normy — to samo słowo na karcie jest trafieniem (rękawice z zapytania 374). */
    public function test_samo_slowo_w_wymaganiu_i_na_karcie_spelnia(): void
    {
        $requirement = 'Rękawice chemoodporne, antyelektrostatyczne z normą EN ISO 374-1, rozmiar 10';
        $this->assertSame('ok', $this->statusFor('antistatic', $requirement, ['Rękawice antystatyczne']));
        $this->assertSame('ok', $this->statusFor('antistatic', $requirement, ['Anti-static PU coated gloves']));
    }

    /** Wymaganie wymienia normę, którą karta ma — spełnia je wprost, niezależnie od reguły rodzaju wyrobu. */
    public function test_norma_wymieniona_w_wymaganiu_spelnia_je_wprost(): void
    {
        $requirement = 'Trzewiki S3 antyelektrostatyczne EN 1149-5';
        $this->assertSame('ok', $this->statusFor('antistatic', $requirement, ['EN ISO 20345 S3, EN 1149-5']));
        $this->assertSame('unclear', $this->statusFor('antistatic', $requirement, ['Podeszwa antystatyczna']));
    }

    /**
     * „ESD: nie” przeczy dowodowi, nie antystatyczności: przy wymaganiu z samym słowem nie psuje „Antystatyczne: tak”
     * (i nie jest sprzecznością pól karty), a przy żądaniu ESD nie spełnia. Zaprzeczone ESD w wymaganiu go nie żąda.
     */
    public function test_zaprzeczenie_esd_liczy_sie_tylko_gdy_wymaganie_zada_esd(): void
    {
        $card = [new CardSource(CardSource::FEATURES, 'Antystatyczne: tak'), new CardSource(CardSource::FEATURES, 'ESD: nie')];

        $rows = $this->rows('Rękawice ochronne antystatyczne', $card);
        $this->assertSame('ok', $rows['antistatic']->status->value);
        $this->assertSame(['Antystatyczne'], array_column($rows['antistatic']->card, 'text'));

        $rows = $this->rows('Rękawice ochronne antystatyczne', [new CardSource(CardSource::FEATURES, 'ESD: nie')]);
        $this->assertSame('unclear', $rows['antistatic']->status->value);
        $this->assertSame('Wymaganie żąda samej antystatyczności, a karta mówi tylko o ESD albo normie — sprawdź', $rows['antistatic']->note);

        $rows = $this->rows('Obuwie ESD', $card);
        $this->assertSame('fail', $rows['antistatic']->status->value);
        $this->assertSame(['unclear', 'fail'], array_column($rows['antistatic']->card, 'verdict'));

        // Karta pokazuje antystatykę tylko normą i zaprzecza innej — sprzeczność do sprawdzenia, nie ✓ (szorty ARDON
        // REFIWAN 29058 z produkcji); przy żądaniu ESD od odzieży zaprzeczona EN 1149-5 nie spełnia.
        $shorts = [
            new CardSource(CardSource::DESCRIPTION, 'Szorty do stref EPA zgodne z EN 61340-5-1. Szorty nie spełniają wymagań norm EN 1149–5.'),
        ];
        $rows = $this->rows('Odzież ochronna antystatyczna', $shorts);
        $this->assertSame('unclear', $rows['antistatic']->status->value);
        $this->assertSame(['ok', 'fail'], array_column($rows['antistatic']->card, 'verdict'));
        $this->assertSame('fail', $this->rows('Odzież ochronna ESD', $shorts)['antistatic']->status->value);
        // mata ESD, której opis wspomina inną wersję bez ESD
        $this->assertSame('unclear', $this->statusFor('antistatic', 'Mata antystatyczna', [
            'Ochrona elektrostatyczna (ESD) zgodnie z IEC 61340-4-1',
            'Dostępna jest również wersja bez właściwości ESD.',
        ]));

        // tabela SIWZ „ESD: nie” nie żąda ESD, więc samo słowo na karcie wystarcza
        $this->assertSame('ok', $this->statusFor('antistatic', 'Obuwie antystatyczne S3, ESD: nie', ['Obuwie antystatyczne']));
        $this->assertSame('ok', $this->statusFor('antistatic', 'Obuwie antystatyczne S3, nie musi spełniać EN 61340-5-1', ['Obuwie antystatyczne']));
    }

    /**
     * Przy żądaniu ESD wątpliwość co do samego słowa (wątpliwy szyk, „nie dotyczy”) nie przeważa nad jawnym ESD —
     * taśma 3M z katalogu: „bez typowych wad taśm antystatycznych” obok ESD. Wprost zaprzeczone słowo dalej przeczy.
     */
    public function test_watpliwe_slowo_nie_przewaza_nad_jawnym_esd(): void
    {
        $card = [
            new CardSource(CardSource::NAME, 'Taśma poliimidowa o właściwościach antystatycznych'),
            new CardSource(CardSource::DESCRIPTION, 'Taśma chroni elementy wrażliwe na ESD, bez typowych wad taśm antystatycznych.'),
        ];
        $rows = $this->rows('Taśma ESD', $card);
        $this->assertSame('ok', $rows['antistatic']->status->value);
        $this->assertSame(['ESD'], array_column($rows['antistatic']->card, 'text'));
        // przy wymaganiu z samym słowem wątpliwość dotyczy tego, czego żąda wymaganie — jak dotąd „do sprawdzenia”
        $this->assertSame('unclear', $this->rows('Taśma antystatyczna', $card)['antistatic']->status->value);

        $rows = $this->rows('Obuwie ESD', [new CardSource(CardSource::FEATURES, 'Antystatyczne: nie dotyczy')]);
        $this->assertSame('unclear', $rows['antistatic']->status->value);
        $this->assertNull($rows['antistatic']->note);

        $rows = $this->rows('Obuwie ESD', [new CardSource(CardSource::NAME, 'Półbuty S1 ESD'), new CardSource(CardSource::FEATURES, 'Nie jest antystatyczne')]);
        $this->assertSame('unclear', $rows['antistatic']->status->value);
        $this->assertSame(['ok', 'fail'], array_column($rows['antistatic']->card, 'verdict'));
    }

    /** Liczba z cyfrą obok to kod wyrobu, nie norma — w wymaganiu i na karcie, jak w bramce dopasowania. */
    public function test_kod_wyrobu_to_nie_norma_antystatyki(): void
    {
        $this->assertSame('missing', $this->statusFor('antistatic', 'Rękawice ochronne antystatyczne', ['Rękawice powlekane 11495']));
        $this->assertSame('missing', $this->statusFor('antistatic', 'Rękawice ochronne antystatyczne', ['Rękawice nitrylowe 16350 szt.']));
        $this->assertArrayNotHasKey('antistatic', $this->rows('Linka bezpieczeństwa z amortyzatorem DBI-SALA 6134006', [new CardSource(CardSource::NAME, 'Linka DBI-SALA 6134006')]));
        $this->assertArrayNotHasKey('antistatic', $this->rows('Rękawice powlekane 11495, rozmiar 9', [new CardSource(CardSource::NAME, 'Rękawice powlekane 11495')]));
    }

    /**
     * Okno pokazuje antystatykę na karcie dokładnie wtedy, gdy widzi ją bramka dopasowania
     * (PpeAssortment::productShowsAntistatic) — te same zapisy norm, te same kody wyrobów odrzucone.
     */
    #[DataProvider('gateTexts')]
    public function test_antystatyka_na_karcie_jak_w_bramce_dopasowania(string $text): void
    {
        $this->assertSame(
            (new PpeAssortment)->productShowsAntistatic($text),
            $this->statusFor('antistatic', 'Rękawice ochronne antystatyczne', [$text]) === 'ok',
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function gateTexts(): array
    {
        $texts = [
            'ARSO 701 616560 S1 P ESD',
            'EN IEC 61340-4-3:2018',
            'Rękawice, IEC 61340-5-1',
            'Sandały S1 P, EN61340-4-3',
            'Rękawice, IEC61340-5-1',
            'antyelektrostatyczna podeszwa',
            'Anti-static PU coated gloves',
            'Trzewiki S3 EN 1149-5',
            'Rękawice powlekane PU, EN1149-5',
            'Rękawice powlekane PU, 1149-5',
            'PN-EN 1149-5:2018',
            'Rękawice EN 16350:2014',
            'Rękawice powlekane nitrylem, EN ISO 16350',
            'Rękawice montażowe powlekane poliuretanem, EN 388 4131X',
            'Rękawice Phynomic lite',
            'Rękawice powlekane 11495',
            'Rękawice kod 11490',
            'Rękawice nitrylowe 16350 szt.',
            'Linka DBI-SALA 6134006',
        ];

        return array_combine($texts, array_map(static fn (string $text): array => [$text], $texts));
    }

    public function test_bezpieczny_dla_zywnosci_to_nie_deklaracja_kontaktu_z_zywnoscia(): void
    {
        $this->assertSame('unclear', $this->statusFor('food_contact', 'Rękawice do kontaktu z żywnością', ['Bezpieczne dla żywności', 'System HACCP']));
        // pokrewne sformułowanie nie psuje deklaracji wprost na tej samej karcie (karta 87320100)
        $this->assertSame('ok', $this->statusFor('food_contact', 'Rękawice do kontaktu z żywnością', ['Bezpieczne dla żywności', 'Zgodne z przepisami dotyczącymi kontaktu z żywnością']));
        $this->assertSame('fail', $this->statusFor('food_contact', 'Rękawice do kontaktu z żywnością', ['Nie nadają się do kontaktu z żywnością']));
    }

    public function test_granice_slow_i_zaprzeczenia_w_wymaganiu(): void
    {
        $this->assertSame('missing', $this->statusFor('fda', 'Zgodność z FDA', ['Seria FDAX-100']));
        $this->assertSame('missing', $this->statusFor('hook_and_loop', 'Zapięcie na rzep', ['Do prac przy zbiorze rzepaku']));
        $this->assertSame('fail', $this->statusFor('antistatic', 'Obuwie antystatyczne', ['Wersja nieantystatyczna']));
        // wymaganie z zaprzeczeniem nie żąda cechy
        $this->assertSame([], (new FeatureFlagChecker)->check('Rękawice nie są antystatyczne', []));
    }

    public function test_komorka_etykieta_nie_z_kropka_przecinkiem_i_kreska(): void
    {
        $this->assertSame('fail', $this->statusFor('latex_free', 'Rękawice bez lateksu', ['Bez lateksu: nie.']));
        $this->assertSame('fail', $this->statusFor('latex_free', 'Rękawice bez lateksu', ['Bez lateksu: nie, bez silikonu: tak']));
        $this->assertSame('ok', $this->statusFor('silicone_free', 'Rękawice bez silikonu', ['Bez lateksu: nie, bez silikonu: tak']));
        $this->assertSame('fail', $this->statusFor('latex_free', 'Rękawice bez lateksu', ['Bez lateksu | Nie']));
        $this->assertSame('fail', $this->statusFor('latex_free', 'Rękawice bez lateksu', ['Latex free: No.']));
        $this->assertSame('fail', $this->statusFor('antistatic', 'Obuwie antystatyczne', ['Antystatyczne: NIE.']));
        $this->assertSame('ok', $this->statusFor('antistatic', 'Obuwie antystatyczne', ['Antystatyczne: Tak.']));
        $this->assertSame('unclear', $this->statusFor('antistatic', 'Obuwie antystatyczne', ['Antystatyczne | nie dotyczy |']));
    }

    public function test_zaprzeczenie_przed_cecha_na_karcie(): void
    {
        $this->assertSame('fail', $this->statusFor('latex_free', 'Rękawice bez lateksu', ['Produkt nie jest wolny od lateksu.']));
        $rows = $this->rows('Rękawice bez lateksu', [new CardSource(CardSource::FEATURES, 'Te rękawice nie są bez lateksu.')]);
        $this->assertSame('fail', $rows['latex_free']->status->value);
        // znalezisko pokazuje zaprzeczenie, nie samo „bez lateksu”
        $this->assertSame('nie są bez lateksu', $rows['latex_free']->card[0]['text']);

        $this->assertSame('fail', $this->statusFor('antistatic', 'Obuwie antystatyczne', ['Obuwie bez właściwości antystatycznych']));
        $this->assertSame('fail', $this->statusFor('antistatic', 'Obuwie antystatyczne', ['Brak właściwości antystatycznych.']));
        $this->assertSame('fail', $this->statusFor('antistatic', 'Obuwie antystatyczne', ['Nie są to buty antystatyczne.']));
        $this->assertSame('fail', $this->statusFor('food_contact', 'Rękawice do kontaktu z żywnością', ['Nie należy stosować do kontaktu z żywnością.']));
        $this->assertSame('fail', $this->statusFor('food_contact', 'Rękawice do kontaktu z żywnością', ['Nieprzeznaczone do kontaktu z żywnością']));
        $this->assertSame('fail', $this->statusFor('food_contact', 'Rękawice do kontaktu z żywnością', ['Nie są odpowiednie do kontaktu z żywnością']));
        $this->assertSame('fail', $this->statusFor('food_contact', 'Rękawice do kontaktu z żywnością', ['Unsuitable for food contact']));
        $this->assertSame('fail', $this->statusFor('hook_and_loop', 'Buty z zapięciem na rzep', ['Zapięcie na sznurówki, bez rzepów']));
        $this->assertSame('fail', $this->statusFor('antistatic', 'Obuwie ESD', ['Nie jest to obuwie ESD.']));
        // zaprzeczenie w wątpliwym szyku — nie ok
        $this->assertSame('unclear', $this->statusFor('antistatic', 'Obuwie antystatyczne', ['Obuwie nie przemakające antystatyczne']));
    }

    public function test_zaprzeczenie_w_innym_zdaniu_lub_po_spojniku_nie_przeczy_cesze(): void
    {
        $this->assertSame('ok', $this->statusFor('antistatic', 'Obuwie antystatyczne', ['Nie przemaka. Obuwie antystatyczne']));
        $this->assertSame('ok', $this->statusFor('antistatic', 'Obuwie antystatyczne', ['Obuwie nie jest ciężkie i jest antystatyczne']));
        $this->assertSame('ok', $this->statusFor('antistatic', 'Obuwie antystatyczne', ['Nie przemaka, antystatyczne']));
        $this->assertSame('ok', $this->statusFor('antistatic', 'Obuwie antystatyczne', ['Nie tylko antystatyczne, ale też olejoodporne']));
        $this->assertSame('ok', $this->statusFor('antistatic', 'Obuwie antystatyczne', ['Obuwie bez metalowych elementów i antystatyczne']));
    }

    public function test_zaprzeczenie_w_wymaganiu_nie_tworzy_wiersza(): void
    {
        $card = [new CardSource(CardSource::FEATURES, 'Obuwie antystatyczne')];
        $this->assertArrayNotHasKey('antistatic', $this->rows('Obuwie nie musi być antystatyczne', $card));
        $this->assertArrayNotHasKey('antistatic', $this->rows('Nie wymaga się właściwości antystatycznych', $card));
        $this->assertArrayNotHasKey('antistatic', $this->rows('Właściwości antystatyczne nie są wymagane', $card));
        $this->assertArrayNotHasKey('hook_and_loop', $this->rows('Buty sznurowane, bez rzepów', [new CardSource(CardSource::FEATURES, 'Zapięcie na rzep')]));
        // zaprzeczenie przy innej cesze nie kasuje wiersza
        $this->assertSame('ok', $this->statusFor('antistatic', 'Obuwie nie musi być wodoodporne, antystatyczne', ['Obuwie antystatyczne']));
    }

    public function test_uczulenie_na_lateks_to_nie_sklad(): void
    {
        $rows = $this->rows('Rękawice bez lateksu', [new CardSource(CardSource::FEATURES, 'Odpowiednie dla osób uczulonych na lateks naturalny')]);
        $this->assertSame('unclear', $rows['latex_free']->status->value);
        $this->assertNotNull($rows['latex_free']->note);
        $this->assertSame('unclear', $this->statusFor('latex_free', 'Rękawice bez lateksu', ['Dla alergików na lateks']));
        $this->assertSame('unclear', $this->statusFor('latex_free', 'Rękawice bez lateksu', ['Suitable for users with latex allergy']));
        // skład wprost nadal przeczy
        $this->assertSame('fail', $this->statusFor('latex_free', 'Rękawice bez lateksu', ['Zawiera lateks naturalny, może powodować reakcje alergiczne']));
    }

    public function test_sklejona_komorka_tabeli_daje_czytelne_znalezisko(): void
    {
        $rows = $this->rows('Rękawice bez lateksu, antystatyczne', [new CardSource(CardSource::DESCRIPTION, "Bez lateksuNie\nAntystatyczneNIE")]);

        $this->assertSame('fail', $rows['latex_free']->status->value);
        $this->assertSame('Bez lateksu Nie', $rows['latex_free']->card[0]['text']);
        $this->assertSame('Bez lateksuNie', $rows['latex_free']->card[0]['find']);
        $this->assertSame('fail', $rows['antistatic']->status->value);
        $this->assertSame('Antystatyczne NIE', $rows['antistatic']->card[0]['text']);
    }

    public function test_powtorzone_trafienia_to_jedno_znalezisko(): void
    {
        $rows = $this->rows('Rękawice bez lateksu', [new CardSource(CardSource::DESCRIPTION, str_repeat('Rękawice lateksowe. ', 300))]);

        $this->assertCount(1, $rows['latex_free']->card);
        $this->assertSame('fail', $rows['latex_free']->status->value);
    }

    #[DataProvider('neverOk')]
    public function test_nigdy_ok(string $key, string $requirement, string $card): void
    {
        $rows = $this->rows($requirement, [new CardSource(CardSource::DESCRIPTION, $card)]);

        $this->assertNotSame('ok', ($rows[$key] ?? null)?->status->value, $key);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function neverOk(): array
    {
        return [
            'nie. po komórce' => ['latex_free', 'Rękawice bez lateksu', 'Bez lateksu: nie.'],
            'nie, po komórce' => ['latex_free', 'Rękawice bez lateksu', 'Bez lateksu: nie, bez silikonu: tak'],
            'kreska pionowa' => ['latex_free', 'Rękawice bez lateksu', 'Bez lateksu | Nie'],
            'latex free no z kropką' => ['latex_free', 'Rękawice bez lateksu', 'Latex free: No.'],
            'nie wielkimi z kropką' => ['antistatic', 'Obuwie antystatyczne', 'Antystatyczne: NIE.'],
            'nie jest wolny od' => ['latex_free', 'Rękawice bez lateksu', 'Produkt nie jest wolny od lateksu.'],
            'nie są bez' => ['latex_free', 'Rękawice bez lateksu', 'Te rękawice nie są bez lateksu.'],
            'bez właściwości' => ['antistatic', 'Obuwie antystatyczne', 'Obuwie bez właściwości antystatycznych'],
            'brak właściwości' => ['antistatic', 'Obuwie antystatyczne', 'Brak właściwości antystatycznych.'],
            'nie są to buty' => ['antistatic', 'Obuwie antystatyczne', 'Nie są to buty antystatyczne.'],
            'nie należy stosować' => ['food_contact', 'Rękawice do kontaktu z żywnością', 'Nie należy stosować do kontaktu z żywnością.'],
            'nieprzeznaczone' => ['food_contact', 'Rękawice do kontaktu z żywnością', 'Nieprzeznaczone do kontaktu z żywnością'],
            'nie są odpowiednie' => ['food_contact', 'Rękawice do kontaktu z żywnością', 'Nie są odpowiednie do kontaktu z żywnością'],
            'unsuitable' => ['food_contact', 'Rękawice do kontaktu z żywnością', 'Unsuitable for food contact'],
            'bez rzepów' => ['hook_and_loop', 'Buty z zapięciem na rzep', 'Zapięcie na sznurówki, bez rzepów'],
            'nie jest to obuwie esd' => ['antistatic', 'Obuwie ESD', 'Nie jest to obuwie ESD.'],
            'esd nie przy żądaniu esd' => ['antistatic', 'Obuwie ESD', "Antystatyczne: tak\nESD: nie"],
            'samo słowo przy żądaniu normy' => ['antistatic', 'Rękawice antyelektrostatyczne wg EN 16350', 'Rękawice antystatyczne'],
            'wymaganie nie musi być' => ['antistatic', 'Obuwie nie musi być antystatyczne', 'Obuwie antystatyczne'],
            'wymaganie nie wymaga się' => ['antistatic', 'Nie wymaga się właściwości antystatycznych', 'Obuwie antystatyczne'],
        ];
    }

    /**
     * Karta rękawa z bazy produkcyjnej — te same pola, co CardSources zbudowałby z produktu.
     *
     * @return list<CardSource>
     */
    private function productionSleeveCard(): array
    {
        return [
            new CardSource(CardSource::SPECS, 'Kolor: wysoka widoczność'),
            new CardSource(CardSource::FEATURES, 'Bez lateksu – odpowiednie dla alergików'),
            new CardSource(CardSource::FEATURES, 'Wysoka widoczność – kolor ostrzegawczy'),
            new CardSource(CardSource::FEATURES, 'Zapięcie VELCRO™ – pewne dopasowanie'),
            new CardSource(CardSource::DESCRIPTION, 'Rękaw w kolorze wysokiej widoczności wykonano z nylonu, poliestru i włókna szklanego, bez dodatku lateksu. Konstrukcja cięta i szyta zapewnia trwałość, a właściwości antyelektrostatyczne chronią elektronikę.'),
        ];
    }

    /**
     * @param  list<string>  $features
     */
    private function statusFor(string $key, string $requirement, array $features): string
    {
        $sources = array_map(static fn (string $text): CardSource => new CardSource(CardSource::FEATURES, $text), $features);
        $rows = $this->rows($requirement, $sources);
        $this->assertArrayHasKey($key, $rows, "wymaganie „{$requirement}” powinno dać wiersz {$key}");

        return $rows[$key]->status->value;
    }

    /**
     * @param  list<CardSource>  $sources
     * @return array<string, CheckRow>
     */
    private function rows(string $requirement, array $sources): array
    {
        $out = [];
        foreach ((new FeatureFlagChecker)->check($requirement, $sources) as $row) {
            $out[$row->key] = $row;
        }

        return $out;
    }

    /**
     * @param  array<string, CheckRow>  $rows
     * @return array<string, string>
     */
    private function statuses(array $rows): array
    {
        return array_map(static fn (CheckRow $row): string => $row->status->value, $rows);
    }
}
