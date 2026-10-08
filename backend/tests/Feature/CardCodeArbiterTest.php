<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Enrichment\CardCodeArbiter;
use App\Services\Enrichment\ManufacturerProfiles;
use App\Services\Enrichment\SourceIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Najdłuższy kod z katalogu marki decyduje (etap 3, §1.2) — przypadki z audytów AJ GROUP, CEDERROTH, SECURA i Bolle
 * z 08.10.2026.
 */
final class CardCodeArbiterTest extends TestCase
{
    use RefreshDatabase;

    private const AJ_CATALOG = [
        '1011', '1011 R', '104/1', '104/1 OC', 'SB04 AIR', 'SB04 AIR CARP', '105', '105 S/PRYZ', '108', '108/WZ',
        '102', '1102', '1101/1011', '1101 R / 1011 R', '1044', '1044/W', '103', '110',
    ];

    public function test_aj_group_longer_variant_code_makes_page_foreign(): void
    {
        $this->catalog('AJ GROUP', self::AJ_CATALOG);

        // 1011 ↔ 1011 R
        $variantR = $this->page('https://pros.pl/pl/odziez-ostrzegawcza/105-spodnie-ogrodniczki-model-1011-r.html', 'Spodnie ogrodniczki model 1011 R');
        $standard = $this->page('https://pros.pl/pl/odziez-ostrzegawcza/104-spodnie-ogrodniczki-model-1011.html', 'Spodnie ogrodniczki model 1011');
        $this->assertSame(['foreign', '1011 R'], $this->relation('1011', $variantR));
        $this->assertSame(['own', '1011'], $this->relation('1011', $standard));
        $this->assertSame('own', $this->relation('1011 R', $variantR)[0]);
        $this->assertSame(['foreign', '1011'], $this->relation('1011 R', $standard));

        // 104/1 ↔ 104/1 OC
        $this->assertSame('foreign', $this->relation('104/1', $this->page('https://pros.pl/pl/kombinezony/241-kombinezon-ocieplany-model-1041-oc.html', 'Kombinezon ocieplany model 104/1 OC'))[0]);
        $this->assertSame('own', $this->relation('104/1', $this->page('https://pros.pl/pl/kombinezony/240-kombinezon-model-1041.html', 'Kombinezon model 104/1'))[0]);

        // SB04 AIR ↔ SB04 AIR CARP
        $this->assertSame(['foreign', 'SB04 AIR CARP'], $this->relation('SB04 AIR', $this->page('https://pros.pl/pl/spodniobuty/261-spodniobuty-oddychajace-sb04-air-carp.html', 'Spodniobuty oddychające SB04 AIR CARP')));
        $this->assertSame('own', $this->relation('SB04 AIR', $this->page('https://pros.pl/pl/spodniobuty/242-spodniobuty-oddychajace-sb04-air.html', 'Spodniobuty oddychające SB04 AIR'))[0]);

        // 1044 ↔ 1044/W w obie strony
        $this->assertSame('foreign', $this->relation('1044', $this->page('https://pros.pl/pl/pros-extreme/166-kurtka-kangurka-model-1044w.html', 'Kurtka kangurka model 1044/W'))[0]);
        $this->assertSame('foreign', $this->relation('1044/W', $this->page('https://pros.pl/pl/pros-extreme/165-kurtka-kangurka-model-1044.html', 'Kurtka kangurka model 1044'))[0]);
    }

    public function test_aj_group_shop_entry_number_is_not_our_code(): void
    {
        $this->catalog('AJ GROUP', self::AJ_CATALOG);

        // 102 ↔ 1102: „102-” to numer wpisu PrestaShop
        $coat = $this->page('https://pros.pl/pl/odziez-wodoochronna-ostrzegawcza/102-plaszcz-model-1102.html', 'Płaszcz model 1102');
        $kangaroo = $this->page('https://pros.pl/pl/odziez-wodoochronna-standard/61-kurtka-kangurka-model-102.html', 'Kurtka kangurka model 102');
        $this->assertSame(['foreign', '1102'], $this->relation('102', $coat));
        $this->assertSame('own', $this->relation('102', $kangaroo)[0]);
        $this->assertSame(['foreign', '102'], $this->relation('1102', $kangaroo));

        // krótki numer innej karty bez etykiety (rozmiar, wymiar) to nie jej kod
        $this->assertSame('own', $this->relation('102', $this->page('https://pros.pl/pl/x/61-kurtka-kangurka-model-102.html', 'Kurtka kangurka model 102, długość 110 cm'))[0]);
    }

    public function test_aj_group_images_of_other_variants_are_foreign(): void
    {
        $this->catalog('AJ GROUP', self::AJ_CATALOG);

        $this->assertSame('foreign', $this->fileRelation('1011', 'https://pros.pl/7328-thickbox_default/spodnie-ogrodniczki-model-1011-r.jpg'));
        $this->assertSame('own', $this->fileRelation('1011', 'https://pros.pl/7312-thickbox_default/spodnie-ogrodniczki-model-1011.jpg'));
        $this->assertSame('foreign', $this->fileRelation('105', 'https://pros.pl/1264-thickbox_default/peleryna-straz-model-105-spryz.jpg'));
        $this->assertSame('own', $this->fileRelation('105', 'https://pros.pl/264-thickbox_default/peleryna-model-105.jpg'));
        $this->assertSame('foreign', $this->fileRelation('108', 'https://pros.pl/3355-thickbox_default/fartuch-z-wzmocnieniem-model-108wz.jpg'));
        $this->assertSame('foreign', $this->fileRelation('102', 'https://pros.pl/3879-thickbox_default/plaszcz-model-1102.jpg'));
        $this->assertSame('foreign', $this->fileRelation('1101/1011', 'https://pros.pl/6192-thickbox_default/ubranie-model-1101r1011r.jpg'));
        $this->assertSame('own', $this->fileRelation('1101/1011', 'https://pros.pl/5458-thickbox_default/ubranie-model-11011011.jpg'));
        $this->assertSame('foreign', $this->fileRelation('1101 R / 1011 R', 'https://pros.pl/5458-thickbox_default/ubranie-model-11011011.jpg'));
        // strona zestawu standard: człony „1101” i „1011” bez „R” to nie kod „1101 R / 1011 R”
        $this->assertSame('own', $this->relation('1101/1011', $this->page('https://pros.pl/pl/odziez-ostrzegawcza/110-ubranie-model-11011011.html', 'Ubranie model 1101/1011'))[0]);
        $this->assertSame('foreign', $this->relation('1011', $this->page('https://pros.pl/pl/odziez-ostrzegawcza/110-ubranie-model-11011011.html', 'Ubranie model 1101/1011'))[0]);
        // plik bez kodu w adresie (pdf.php?id_product=…) rozstrzyga numer wpisu, nie arbiter
        $this->assertSame('none', $this->fileRelation('104/1', 'https://pros.pl/modules/x13producttopdf/pdf.php?id_product=241 Pobierz kartę produktu w pliku PDF'));
    }

    public function test_aj_group_combination_code_in_manufacturer_markup_is_own(): void
    {
        $this->catalog('AJ GROUP', self::AJ_CATALOG);

        $page = $this->page('https://pros.pl/pl/odziez/62-kurtka.html', 'Kurtka PROS', [['type' => 'sku', 'value' => '103-00033-48/XS']]);
        $this->assertSame(['own', '103'], $this->relation('103', $page));
        // pole sku równe numerowi wpisu strony to nie kod („226-zacisk…”, sku 226)
        $this->catalog('AJ GROUP', ['226']);
        $entry = $this->page('https://pros.pl/pl/fartuchy/226-zacisk-do-rekawic-model-048tpu.html', 'Zacisk do rękawic model 048/TPU', [['type' => 'sku', 'value' => '226']]);
        $this->assertSame('own', $this->relation('048/TPU', $entry)[0]);
        $plain = $this->page('https://pros.pl/pl/fartuchy/226-zacisk-do-rekawic.html', 'Zacisk do rękawic', [['type' => 'sku', 'value' => '226']]);
        $this->assertSame('none', $this->relation('103', $plain)[0], '226 w adresie i mikrodanych to numer wpisu, nie karta 226');
        // etykieta modelu innego niż nasz na stronie producenta — strona innego wyrobu
        $this->assertSame(['foreign', '048/TPU'], $this->relation('103', $entry));
    }

    public function test_cederroth_labelled_ref_of_other_product_is_foreign_even_outside_catalog(): void
    {
        $this->catalog('CEDERROTH', ['310366', '51011013', '51011003', '2023']);

        $sensitive = $this->page('https://www.cederroth.com/pl/products/plastry-sensitive/', 'Plastry Salvequick Sensitive REF 6943');
        $this->assertSame(['foreign', '6943'], $this->relation('310366', $sensitive, 'CEDERROTH'));
        // ta sama etykieta na stronie sklepu nie wystarcza (tylko kody kart katalogu)
        $this->assertSame('none', $this->relation('310366', $this->page('https://sklep.example/plastry-sensitive', 'Plastry REF 6943'), 'CEDERROTH')[0]);

        $this->assertSame('foreign', $this->fileRelation('51011013', 'https://www.cederroth.com/wp-content/uploads/2023/01/51011003-v03.pdf Specyfikacja', 'CEDERROTH'));
        $this->assertSame('own', $this->fileRelation('51011013', 'https://www.cederroth.com/wp-content/uploads/2023/01/51011013-v02.pdf', 'CEDERROTH'), 'rok w katalogu uploads to nie karta 2023');
        // kompres 1893 i strona dozownika 51011006 z wpisem sklepu „-p-1893”
        $this->catalog('CEDERROTH', ['1893', '51011006']);
        $this->assertSame(['foreign', '51011006'], $this->relation('1893', $this->page('https://robartbhp.pl/dozownik-cederroth-wound-care-dispenser-51011006-p-1893.html', 'Dozownik Cederroth'), 'CEDERROTH'));
        // numer z katalogu na początku nazwy zdjęcia
        $this->catalog('CEDERROTH', ['6943']);
        $this->assertSame('foreign', $this->fileRelation('310366', 'https://www.cederroth.com/wp-content/uploads/2021/05/6943-sensitive-plasters-plasterrefill.jpg', 'CEDERROTH'));
    }

    public function test_secura_index_field_of_other_product_is_foreign(): void
    {
        // S565A202 (Pochłaniacz 3031 A2) to karta cennika SECURA na produkcji — kod z katalogu marki
        $this->catalog('SECURA', ['S565E202', 'S565A202', 'T5912200']);

        $a2 = $this->page('https://www.securabc.com/pl/filtry/38-pochlaniacz-a2.html', 'Pochłaniacz A2', [], "Pochłaniacz A2\nIndeks: S565A202\nOchrona przed parami organicznymi");
        $e2 = $this->page('https://www.securabc.com/pl/filtry/40-pochlaniacz-e2.html', 'Pochłaniacz E2', [], "Pochłaniacz E2\nIndeks: S565E202");
        $this->assertSame(['foreign', 'S565A202'], $this->relation('S565E202', $a2, 'SECURA'));
        $this->assertSame(['own', 'S565E202'], $this->relation('S565E202', $e2, 'SECURA'));
        // dalsze pola „Indeks” w tekście nie liczą się — tylko pierwsze
        $this->assertSame('foreign', $this->relation('S565E202', $this->page('https://www.securabc.com/pl/filtry/38-pochlaniacz-a2.html', 'Pochłaniacz', [], "Indeks: S565A202\nIndeks: S565E202"), 'SECURA')[0]);
    }

    public function test_secura_size_of_the_same_half_mask_is_not_foreign(): void
    {
        // securabc.com: jedna strona półmaski SECURA 3000 na rozmiary S, M, L z polem „Indeks S56T0SM0” — karty
        // S56T0SS0/SM0/SL0 (produkcja 149–151); dziś S56T0SL0 → foreign S56T0SM0 i cofnięcie opisu przy marce ścisłej
        $this->catalog('SECURA', ['S56T0SS0', 'S56T0SM0', 'S56T0SL0', 'S56T1SM0']);
        $mask = $this->page('https://www.securabc.com/pl/polmaska-wielokrotnego-uzytku-secura/20-41-secura-3000.html', 'SECURA 3000', [['type' => 'sku', 'value' => '20']],
            "Półmaska wielokrotnego użytku SECURA 3000\nRozmiar S M L\nIndeks S56T0SM0\nPN-EN 140");

        $this->assertSame(['none', null], $this->relation('S56T0SL0', $mask, 'SECURA'));
        $this->assertSame(['none', null], $this->relation('S56T0SS0', $mask, 'SECURA'));
        $this->assertSame(['own', 'S56T0SM0'], $this->relation('S56T0SM0', $mask, 'SECURA'));
        // inna cyfra (SECURA 3100) to inny model
        $this->assertSame(['foreign', 'S56T0SM0'], $this->relation('S56T1SM0', $mask, 'SECURA'));
        // „Indeks” innego rozmiaru nie zagłusza obcej etykiety „model X” w tytule — spoza katalogu i z katalogu marki
        $text = "Półmaska SECURA 3000\nIndeks S56T0SM0";
        $outside = $this->page('https://www.securabc.com/pl/x/22-zestaw.html', 'Zestaw ADR model S5799000', [], $text);
        $this->assertSame(['foreign', 'S5799000'], $this->relation('S56T0SL0', $outside, 'SECURA'));
        $inCatalog = $this->page('https://www.securabc.com/pl/x/23-polmaska-3100.html', 'Półmaska model S56T1SM0', [], $text);
        $this->assertSame(['foreign', 'S56T1SM0'], $this->relation('S56T0SL0', $inCatalog, 'SECURA'));
        // marka bez rozmiarów w profilu — bez zmian
        $this->catalog('AJ GROUP', ['SB01S0', 'SB01M0']);
        $this->assertSame('foreign', $this->relation('SB01S0', $this->page('https://pros.pl/pl/x/1-wyrob-sb01m0.html', 'Wyrób SB01M0'))[0]);
    }

    public function test_secura_index_field_outside_brand_catalog_is_not_foreign(): void
    {
        // securabc.com „25-elsec-25-kv.html”: „Indeks S5911000” (rękawice ELSEC 2,5 kV), karta to zestaw S5921000 —
        // kodu S5911000 nie ma w cenniku SECURA
        $this->catalog('SECURA', ['S5921000', 'S5922000', 'S5923000', 'S565A202']);
        $gloves = $this->page('https://www.securabc.com/pl/rekawice-elektroizolacyjneelsec/25-elsec-25-kv.html', 'ELSEC 2,5 kV', [['type' => 'sku', 'value' => '25']],
            "Rękawice elektroizolacyjne ELSEC 2,5 kV\nIndeks S5911000\nKlasa 00");

        $this->assertSame(['none', null], $this->relation('S5921000', $gloves, 'SECURA'));
        // ten sam kod spoza katalogu w tytule nazywa wyrób strony — dalej foreign
        $this->assertSame(['foreign', 'S5911000'], $this->relation('S5921000', $this->page('https://www.securabc.com/pl/x/25-elsec.html', 'ELSEC 2,5 kV Indeks S5911000'), 'SECURA'));
        // pole z kodem karty z katalogu marki — foreign jak dotąd
        $this->assertSame(['foreign', 'S565A202'], $this->relation('S5921000', $this->page('https://www.securabc.com/pl/x/38-pochlaniacz.html', 'Pochłaniacz', [], 'Indeks: S565A202'), 'SECURA'));
    }

    public function test_cederroth_unit_card_with_underscore_suffix_owns_its_ref_page(): void
    {
        // produkcja: 3387_1 „Serwetki do dezynfekcji Cederroth, 1 szt.” i 3387 „…, 600 szt.”; strona „REF 3387” — dziś
        // foreign 3387 dla karty sztuki
        $this->catalog('CEDERROTH', ['3387', '3387_1', '901900', '901900_1', '310366']);
        $wipes = $this->page('https://www.cederroth.com/pl/products/serwetki-do-dezynfekcji/', 'Serwetki do dezynfekcji Salvequick REF 3387');

        $this->assertSame(['own', '3387'], $this->relation('3387_1', $wipes, 'CEDERROTH'));
        $this->assertSame('own', $this->relation('3387', $wipes, 'CEDERROTH')[0]);
        $this->assertSame('own', $this->relation('901900_1', $this->page('https://www.cederroth.com/pl/products/burn-gel-dressing/', 'Burn Gel Dressing REF 901900'), 'CEDERROTH')[0]);
        // inna karta marki dalej foreign
        $this->assertSame(['foreign', '3387'], $this->relation('310366', $wipes, 'CEDERROTH'));
    }

    public function test_bolle_accessory_page_naming_the_helmet_it_fits_is_not_foreign(): void
    {
        // produkcja: COVFLASHEXT „Zewnętrzny wizjer … - do przyłbicy FLASH” na stronie „Wizjer zewnętrzny do przyłbicy
        // FLASHV” — dziś foreign FLASHV
        $this->catalog('Bolle', ['B9V', 'FLASHV', 'COVFLASHEXT', 'COVFLASHINT']);
        $visor = $this->page('https://specshop.pl/wizjer-zewnetrzny-do-przylbicy-flashv', 'Wizjer zewnętrzny do przyłbicy FLASHV');
        $helmet = $this->page('https://specshop.pl/przylbica-spawalnicza-bolle-flash-flashv', 'FLASH – przyłbica spawalnicza FLASHV');
        $visorName = 'Zewnętrzny wizjer ochronny z filtrem antyrefleksyjnym - 118x136 mm - do przyłbicy FLASH';

        $this->assertSame(['none', null], $this->relation('COVFLASHEXT', $visor, 'Bolle', $visorName));
        $this->assertSame(['none', null], $this->relation('COVFLASHEXT', $this->page('https://www.bolle-safety.com/pl/outer-cover-lens', 'Outer cover lens compatible with FLASHV'), 'Bolle', $visorName));
        // strona samej przyłbicy — kod bez zwrotu „do …” — foreign; „do spawania” to nie nazwa naszego wizjera
        $this->assertSame(['foreign', 'FLASHV'], $this->relation('COVFLASHEXT', $helmet, 'Bolle', $visorName));
        $this->assertSame(['foreign', 'FLASHV'], $this->relation('COVFLASHEXT', $this->page('https://sklep.example/przylbica-flash', 'Przyłbica do spawania FLASHV'), 'Bolle', $visorName));
        // filtr B9V nie jest w nazwie akcesorium „do …” — strona wizjera i przyłbicy FLASHV dalej foreign
        $this->assertSame(['foreign', 'FLASHV'], $this->relation('B9V', $visor, 'Bolle', 'FLASH – filtr elektrooptyczny'));
        $this->assertSame(['foreign', 'FLASHV'], $this->relation('B9V', $helmet, 'Bolle', 'FLASH – filtr elektrooptyczny'));
        // „art.” nazywa wyrób strony, nie ten, do którego pasuje
        $this->assertSame(['foreign', 'FLASHV'], $this->relation('COVFLASHEXT', $this->page('https://sklep.example/przylbica', 'Przyłbica Bolle art. FLASHV'), 'Bolle', $visorName));
    }

    public function test_bolle_filter_on_helmet_page_is_foreign(): void
    {
        $this->catalog('Bolle', ['B9V', 'FLASHV']);

        $helmet = $this->page('https://specshop.pl/przylbica-spawalnicza-bolle-flash-flashv', 'FLASH – przyłbica spawalnicza FLASHV');
        $this->assertSame(['foreign', 'FLASHV'], $this->relation('B9V', $helmet, 'Bolle'));
        $this->assertSame('own', $this->relation('FLASHV', $helmet, 'Bolle')[0]);
    }

    public function test_profiles_without_longest_code_rule_get_none(): void
    {
        $this->catalog('Coba', ['AF060003', 'AF060003C']);
        $this->catalog('MAPA', ['34115', '341151']);
        $page = $this->page('https://www.coba.com/product/orthomat', 'Orthomat AF060003C');

        $this->assertSame('none', $this->relation('AF060003', $page, 'Coba')[0]);
        $this->assertSame('none', $this->relation('34115', $this->page('https://www.mapa-pro.pl/x/341151', 'Vital 341151'), 'MAPA')[0]);
        // karta bez kodu od 3 znaków (AJ „CP”)
        $this->catalog('AJ GROUP', ['110']);
        $this->assertSame('none', $this->relation('CP', $this->page('https://icd.pl/fartuch-pros-model-110.html', 'Fartuch PROS model 110'))[0]);
    }

    public function test_forget_reloads_brand_catalog(): void
    {
        $this->catalog('AJ GROUP', ['1011']);
        $page = $this->page('https://pros.pl/pl/x/105-spodnie-ogrodniczki-model-1011-r.html', 'Spodnie ogrodniczki model 1011 R');
        $this->assertSame('own', $this->relation('1011', $page)[0]);

        Product::query()->create(['sku' => '1011 R', 'name' => 'Spodnie 1011 R', 'manufacturer' => 'AJ GROUP']);
        $this->assertSame('own', $this->relation('1011', $page)[0], 'katalog marki czytany raz w przebiegu');
        app(CardCodeArbiter::class)->forget();
        $this->assertSame('foreign', $this->relation('1011', $page)[0]);
    }

    /**
     * @param  list<string>  $skus
     */
    private function catalog(string $manufacturer, array $skus): void
    {
        foreach ($skus as $sku) {
            Product::query()->create(['sku' => $sku, 'name' => 'Wyrób '.$sku, 'manufacturer' => $manufacturer]);
        }
        app(CardCodeArbiter::class)->forget();
    }

    /**
     * @param  list<array{type: string, value: string}>  $markup
     * @return array<string, mixed>
     */
    private function page(string $url, string $title, array $markup = [], string $text = ''): array
    {
        return ['url' => $url, 'final_url' => $url, 'title' => $title, 'text' => $text, 'markup_codes' => $markup];
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array{0: string, 1: ?string}
     */
    private function relation(string $sku, array $page, string $manufacturer = 'AJ GROUP', ?string $name = null): array
    {
        $product = new Product(['sku' => $sku, 'name' => $name ?? 'Karta '.$sku, 'manufacturer' => $manufacturer]);
        $relation = app(SourceIdentity::class)->pageCodeRelation($product, $page, app(ManufacturerProfiles::class)->for($product));

        return [$relation['verdict'], $relation['code']];
    }

    private function fileRelation(string $sku, string $urlAndLabel, string $manufacturer = 'AJ GROUP'): string
    {
        $product = new Product(['sku' => $sku, 'name' => 'Karta '.$sku, 'manufacturer' => $manufacturer]);

        return app(SourceIdentity::class)->fileCodeRelation($product, $urlAndLabel, app(ManufacturerProfiles::class)->for($product))['verdict'];
    }
}
