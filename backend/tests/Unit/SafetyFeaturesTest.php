<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\SafetyFeatures;
use Tests\TestCase;

/**
 * Cechy bezpieczeństwa z nazwy karty i źródła (etap 3, §1.5) — przypadki z audytu SECURA 08.10.2026.
 */
final class SafetyFeaturesTest extends TestCase
{
    public function test_30_kv_card_conflicts_with_20_kv_shop_page(): void
    {
        // karta 182 opisana ze strony mistralbhp półbutów 20 kV (T5912100)
        $card = SafetyFeatures::ofCard(new Product(['name' => 'Półbuty elektroizolacyjne 30 kV - ANTYAMPER']));
        $page = SafetyFeatures::inUrl('https://mistralbhp.pl/Polbuty-elektroizolacyjne-20-KV-ANTYAMPER-T5912100/472');

        $this->assertSame(['30'], $card['kv']);
        $this->assertSame(['20'], $page['kv']);
        $this->assertSame('30 kV ≠ 20 kV', SafetyFeatures::conflict($card, $page));
        $this->assertNull(SafetyFeatures::conflict($card, SafetyFeatures::inUrl('https://icd.pl/polbuty-elektroizolacyjne-30-kv-antyamper.html')));
        // zdjęcie wariantu 20 kV
        $this->assertNotNull(SafetyFeatures::conflict($card, SafetyFeatures::inUrl('https://sklep.example/img/polbuty-elektroizolacyjne-20kv.jpg')));
    }

    public function test_elsec_5_kv_conflicts_with_2_5_kv_but_not_with_family_page(): void
    {
        $card = SafetyFeatures::ofCard(new Product(['name' => 'Zestaw ELSEC 5 kV']));

        $this->assertSame(['2.5'], SafetyFeatures::in('Zestaw ELSEC 2,5 kV')['kv']);
        $this->assertSame('5 kV ≠ 2.5 kV', SafetyFeatures::conflict($card, SafetyFeatures::in('Zestaw ELSEC 2,5 kV')));
        // przecinek w adresie staje się myślnikiem: „2-5-kv” = 2,5 kV, nie 2 i 5
        $this->assertSame(['2.5'], SafetyFeatures::inUrl('https://centrumelektronarzedzi.pl/zestaw-elsec-2-5-kv/48431')['kv']);
        $this->assertNotNull(SafetyFeatures::conflict($card, SafetyFeatures::inUrl('https://centrumelektronarzedzi.pl/zestaw-elsec-2-5-kv/48431')));
        // strona rodziny i lista napięć to nie sprzeczność
        $this->assertSame(['2.5', '5', '10'], SafetyFeatures::in('Zestawy ELSEC 2,5/5/10 kV')['kv']);
        $this->assertNull(SafetyFeatures::conflict($card, SafetyFeatures::in('Zestawy ELSEC 2,5/5/10 kV')));
        $this->assertSame(['5', '10'], SafetyFeatures::in('Rękawice 5 kV / 10 kV')['kv']);
        $this->assertNull(SafetyFeatures::conflict(SafetyFeatures::in('Zestaw ELSEC 10 kV'), SafetyFeatures::in('Rękawice 5 kV / 10 kV')));
        // brak cechy w źródle to nie sprzeczność
        $this->assertNull(SafetyFeatures::conflict($card, SafetyFeatures::in('Zestaw ELSEC')));
    }

    public function test_securabc_writes_2_5_kv_without_comma_in_address(): void
    {
        // securabc.com: „ELSEC 2,5 kV” pod „25-elsec-25-kv.html”, zdjęcie „46-large_default/elsec-25-kv.jpg” — dziś
        // 25 kV ≠ 2,5 kV i W5 usuwał jedyne zdjęcia producenta
        $set = SafetyFeatures::ofCard(new Product(['name' => 'Zestaw ELSEC 2,5 kV']));
        $image = SafetyFeatures::inUrl('https://www.securabc.com/46-large_default/elsec-25-kv.jpg');
        $page = SafetyFeatures::inUrl('https://www.securabc.com/pl/rekawice-elektroizolacyjneelsec/25-elsec-25-kv.html');

        $this->assertSame(['25', '2.5'], $image['kv']);
        $this->assertNull(SafetyFeatures::conflict($set, $image));
        $this->assertNull(SafetyFeatures::conflict($set, $page));
        // strony innych napięć tej rodziny dalej przeczą
        $this->assertNotNull(SafetyFeatures::conflict($set, SafetyFeatures::inUrl('https://www.securabc.com/pl/rekawice-elektroizolacyjneelsec/27-elsec-10-kv.html')));
        $this->assertNotNull(SafetyFeatures::conflict($set, SafetyFeatures::inUrl('https://www.securabc.com/pl/rekawice-elektroizolacyjneelsec/26-elsec-5-kv.html')));
        // „10”, „20”, „30” bez odczytu z przecinkiem — „10-kv” to nie 1 kV klasy 0
        $this->assertSame(['10'], SafetyFeatures::inUrl('https://www.securabc.com/pl/rekawice-elektroizolacyjneelsec/27-elsec-10-kv.html')['kv']);
        $this->assertNotNull(SafetyFeatures::conflict(SafetyFeatures::in('Rękawice ELSEC klasa 0 do 1 kV'), SafetyFeatures::inUrl('https://sklep.example/elsec-10-kv.html')));
    }

    public function test_several_numbers_before_kv_in_address_are_uncertain(): void
    {
        // lista albo zapis z przecinkiem — nie wiadomo; wcześniej liczyła się ostatnia liczba (10 kV)
        $this->assertSame([], SafetyFeatures::inUrl('https://sklep.example/zestaw-elsec-5-10-kv.html')['kv']);
        $this->assertSame([], SafetyFeatures::inUrl('https://sklep.example/zestaw-elsec-2-5-5-10-kv.html')['kv']);
        $this->assertNull(SafetyFeatures::conflict(SafetyFeatures::in('Zestaw ELSEC 2,5 kV'), SafetyFeatures::inUrl('https://sklep.example/zestaw-elsec-5-10-kv.html')));
        // dwie liczby, druga jednocyfrowa — 2,5 kV jak dotąd
        $this->assertSame(['2.5'], SafetyFeatures::inUrl('https://sklep.example/zestaw-elsec-2-5-kv.html')['kv']);
        $this->assertSame(['0.5'], SafetyFeatures::inUrl('https://sklep.example/rekawice-0-5-kv.html')['kv']);
        // „klasa 0 do 1 kV” bez przecinka to nie 0,1 kV
        $this->assertSame([], SafetyFeatures::inUrl('https://sklep.example/rekawice-dielektryczne-klasa-0-1-kv.html')['kv']);
        $this->assertNull(SafetyFeatures::conflict(SafetyFeatures::in('Rękawice klasa 0 do 1 kV'), SafetyFeatures::inUrl('https://sklep.example/rekawice-dielektryczne-klasa-0-1-kv.html')));
        // inny człon przed napięciem nie psuje odczytu
        $this->assertSame(['20'], SafetyFeatures::inUrl('https://mistralbhp.pl/Polbuty-elektroizolacyjne-T5912100-20-KV/472')['kv']);
    }

    public function test_voltages_are_compared_by_insulation_class(): void
    {
        // EN 60903 / EN 50321: napięcie użytkowania i próby tej samej klasy to ten sam wyrób
        $this->assertNull(SafetyFeatures::conflict(SafetyFeatures::in('Kalosze elektroizolacyjne klasa 0 do 1 kV'), SafetyFeatures::in('Kalosze ANTYAMPER kl. 0, 5 kV')));
        $this->assertNull(SafetyFeatures::conflict(SafetyFeatures::in('Półbuty elektroizolacyjne 30 kV'), SafetyFeatures::in('Półbuty ANTYAMPER-HV do 26,5 kV AC')));
        $this->assertNull(SafetyFeatures::conflict(SafetyFeatures::in('Chodnik elektroizolacyjny 20 KV'), SafetyFeatures::in('Chodnik 17 kV')));
        $this->assertNull(SafetyFeatures::conflict(SafetyFeatures::in('Rękawice 0,5 kV'), SafetyFeatures::in('Rękawice ELSEC 2,5 kV')));
        // różne klasy dalej przeczą
        $this->assertSame('30 kV ≠ 20 kV', SafetyFeatures::conflict(SafetyFeatures::in('Półbuty 30 kV'), SafetyFeatures::in('Półbuty 20 kV')));
        $this->assertNotNull(SafetyFeatures::conflict(SafetyFeatures::in('Zestaw ELSEC 10 kV'), SafetyFeatures::in('Zestaw ELSEC 5 kV')));
        $this->assertNotNull(SafetyFeatures::conflict(SafetyFeatures::in('Rękawice 40 kV'), SafetyFeatures::in('Rękawice 30 kV')));
        // spoza tabeli — wprost
        $this->assertNotNull(SafetyFeatures::conflict(SafetyFeatures::in('Wskaźnik napięcia 12 kV'), SafetyFeatures::in('Wskaźnik napięcia 15 kV')));
        $this->assertNull(SafetyFeatures::conflict(SafetyFeatures::in('Wskaźnik napięcia 12 kV'), SafetyFeatures::in('Wskaźnik 12 kV / 24 kV')));
    }

    public function test_gas_group_e2_conflicts_with_a2_page(): void
    {
        $card = SafetyFeatures::ofCard(new Product(['name' => 'Pochłaniacz 3033 E2']));
        $page = SafetyFeatures::inUrl('https://domtechniczny24.pl/poch%C5%82aniacz-a2-3031-2-szt-secura.html');

        $this->assertSame(['E2'], $card['gas']);
        $this->assertSame(['A2'], $page['gas']);
        $this->assertSame('E2 ≠ A2', SafetyFeatures::conflict($card, $page));
        $this->assertNull(SafetyFeatures::conflict($card, SafetyFeatures::inUrl('https://www.securabc.com/pl/filtry/40-pochlaniacz-e2.html')));
    }

    public function test_particle_class_p2_conflicts_with_p1_image(): void
    {
        $card = SafetyFeatures::ofCard(new Product(['name' => 'Filtr dwustronny SECAIR 3000.02 klasy P2']));
        $image = SafetyFeatures::inUrl('https://icd.pl/media/catalog/product/f/i/filtr-secura-secair-3000-01-p1-1_1.jpg');

        $this->assertSame(['P2'], $card['particle']);
        $this->assertSame(['P1'], $image['particle']);
        $this->assertSame('P2 ≠ P1', SafetyFeatures::conflict($card, $image));
        $this->assertNull(SafetyFeatures::conflict($card, SafetyFeatures::inUrl('https://www.securabc.com/88-large_default/filtr-przeciwpylowy-p2.jpg')));
    }

    public function test_combined_filter_a2p3_matches_its_page(): void
    {
        $card = SafetyFeatures::ofCard(new Product(['name' => 'Zestaw 3071 A2P3 R (pochłaniacz 3031 A2 + filtr SECAIR 3000.03 P3 R)']));
        $page = SafetyFeatures::inUrl('https://www.securabc.com/pl/filtry/47-pochlaniacz-a2-filtr-przeciwpylowy-p3.html');

        $this->assertSame(['A2'], $card['gas']);
        $this->assertSame(['P3'], $card['particle']);
        $this->assertSame(['A2'], $page['gas']);
        $this->assertSame(['P3'], $page['particle']);
        $this->assertNull(SafetyFeatures::conflict($card, $page));
        // ABEK1 z filtrem P2 i zdjęcie samego pochłaniacza ABEK1 — bez sprzeczności
        $abek = SafetyFeatures::ofCard(new Product(['name' => 'Zestaw 3045 ABEK1 P2 R (pochłaniacz 3025 ABEK1 + filtr SECAIR 3000.02 P2)']));
        $this->assertSame(['ABEK1'], $abek['gas']);
        $this->assertNull(SafetyFeatures::conflict($abek, SafetyFeatures::inUrl('https://icd.pl/pochlaniacz-secura-abek1-3025.jpg')));
        $this->assertNull(SafetyFeatures::conflict($abek, SafetyFeatures::in('Pochłaniacz A1B1E1K1')));
        $this->assertNotNull(SafetyFeatures::conflict($abek, SafetyFeatures::in('Pochłaniacz AX')));
    }

    public function test_norm_amendment_b2b_and_paper_size_are_not_gas_groups(): void
    {
        $this->assertSame([], SafetyFeatures::in('Rękawice filtr EN 388:2016+A1:2018, EN ISO 374-1:2016/A1:2018')['gas']);
        $this->assertSame([], SafetyFeatures::in('Filtr dla klientów B2B')['gas']);
        // bez kontekstu filtra „A4” to format kartki, „A2” też nic nie znaczy
        $this->assertSame([], SafetyFeatures::in('Instrukcja A4, segregator A2')['gas']);
        $this->assertSame([], SafetyFeatures::in('Pochłaniacz — ab, be, ak')['gas']);
        $this->assertSame(['FFP3'], SafetyFeatures::in('Półmaska NEOSEC 3000 FFP3 R D')['ffp']);
        $this->assertSame([], SafetyFeatures::in('Półmaska NEOSEC 3000 FFP3 R D')['particle']);
    }

    public function test_insulation_class_needs_label_and_electrical_context(): void
    {
        $this->assertSame(['00'], SafetyFeatures::in('Rękawice elektroizolacyjne klasa 00, 500 V')['insulation']);
        $this->assertSame('klasa 0 ≠ klasa 1', SafetyFeatures::conflict(
            SafetyFeatures::in('Rękawice dielektryczne klasa 0'),
            SafetyFeatures::in('Rękawice dielektryczne klasa 1, do 7,5 kV')
        ));
        // lista i zakres klas na stronie rodziny to nie sprzeczność
        $this->assertSame(['0', '1', '2'], SafetyFeatures::in('Rękawice elektroizolacyjne klasa 0, 1 i 2')['insulation']);
        $this->assertSame(['00', '0', '1', '2', '3', '4'], SafetyFeatures::in('Insulating matting Class 00-4, up to 36 kV')['insulation']);
        $this->assertNull(SafetyFeatures::conflict(SafetyFeatures::in('Mata elektroizolacyjna klasa 2'), SafetyFeatures::in('Insulating matting Class 0-4, up to 36 kV')));
        // zakres napięć nie wskazuje jednego
        $this->assertSame([], SafetyFeatures::in('Mata dielektryczna 1-36 kV')['kv']);
        $this->assertNull(SafetyFeatures::conflict(SafetyFeatures::in('Mata dielektryczna 17 kV'), SafetyFeatures::in('Maty dielektryczne 3,5–36 kV')));
        // klasa widzialności czy EN 343 bez kontekstu napięcia to nie klasa izolacji
        $this->assertSame([], SafetyFeatures::in('Kamizelka ostrzegawcza klasa 2')['insulation']);
        $this->assertSame([], SafetyFeatures::in('Filtr dwustronny SECAIR 3000.02 klasy P2')['insulation']);
    }

    public function test_merge_keeps_features_of_every_source(): void
    {
        $merged = SafetyFeatures::merge(SafetyFeatures::inUrl('https://sklep.example/polbuty.html'), SafetyFeatures::in('Półbuty 20 kV'));

        $this->assertSame(['20'], $merged['kv']);
        $this->assertNotNull(SafetyFeatures::conflict(SafetyFeatures::in('Półbuty 30 kV'), $merged));
    }
}
