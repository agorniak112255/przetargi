<?php

declare(strict_types=1);

namespace Tests\Unit\RequirementCheck;

use App\Support\RequirementCheck\CardSource;
use App\Support\RequirementCheck\CardSources;
use App\Support\RequirementCheck\CheckRow;
use App\Support\RequirementCheck\FeatureFlagChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

final class FeatureFlagCheckerTest extends TestCase
{
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

    public function test_esd_nie_jest_antystatycznoscia_i_odwrotnie(): void
    {
        $rows = $this->rows('Obuwie antystatyczne S3', [new CardSource(CardSource::NAME, 'Półbuty S1 ESD')]);
        $this->assertSame('unclear', $rows['antistatic']->status->value);
        $this->assertSame('ESD to nie to samo co antystatyczny — sprawdź', $rows['antistatic']->note);
        $this->assertArrayNotHasKey('esd', $rows);

        $rows = $this->rows('Obuwie ESD zgodne z EN 61340-5-1', [new CardSource(CardSource::NORMS, 'EN 1149-5')]);
        $this->assertSame('unclear', $rows['esd']->status->value);

        // przy wprost antystatycznym ESD obok nie obniża wyniku
        $this->assertSame('ok', $this->statusFor('antistatic', 'Obuwie antystatyczne', ['Obuwie antystatyczne, ESD']));
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
        $this->assertSame('fail', $this->statusFor('esd', 'Obuwie ESD', ['Nie jest to obuwie ESD.']));
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
            'nie jest to obuwie esd' => ['esd', 'Obuwie ESD', 'Nie jest to obuwie ESD.'],
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
