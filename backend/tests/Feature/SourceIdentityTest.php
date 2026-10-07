<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Services\Enrichment\ManufacturerProfiles;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\SourceIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Werdykt tożsamości źródła opisu (etap 1 opisów z cenników). Przypadki z pomiaru Coby 07.10.2026
 * (SUPON_AI_Pomiar_Coba_przed_2026-10-07.csv) i MAPA (kod 8 cyfr, którego strona nie podaje).
 */
final class SourceIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const ORTHOMAT = 'https://www.coba.com/product/orthomat-standard';

    private const ORTHOMAT_TEXT = "Orthomat® Standard Anti Fatigue Mat\nPart Number Size Colour\n"
        ."AF010001 0.6 m x 0.9 m Black 1.31\nAF060003C 0.9 m x per linear metre Grey 2\n"
        ."AF060003 0.9 m x 18.3 m Grey 40\nAF060001 0.6 m x 0.9 m Grey 1.31";

    public function test_coba_code_in_family_parts_table_is_hard(): void
    {
        $verdict = $this->judge($this->coba('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)'), self::ORTHOMAT, 'Orthomat® Standard - COBA', self::ORTHOMAT_TEXT);

        $this->assertSame('hard', $verdict['verdict']);
        $this->assertSame(['sku', 'AF060001', 'text'], [$verdict['key_type'], $verdict['key'], $verdict['where']]);
    }

    public function test_longer_variant_code_does_not_confirm_shorter_one(): void
    {
        $text = "Part Number\nAF060003C 0.9 m x per linear metre Grey";
        $verdict = $this->judge($this->coba('AF060003', 'Orthomat Standard Szary 0.9m x 18.3m (9.5mm)'), 'https://shop.example/mata', 'Mata', $text);

        $this->assertNotSame('hard', $verdict['verdict'], 'AF060003C to inny kod niż AF060003');
    }

    public function test_foreign_coba_family_page_is_not_hard(): void
    {
        // CCLIP25 opisany ze strony Bubblemat, LCLIP-38 ze strony samej kraty COBAGRiP (pomiar „przed”, grupa 1)
        $bubblemat = $this->judge(
            $this->coba('CCLIP25', 'Akcesoria Krata GRP - Uchwyt typu C - 25mm'),
            'https://www.coba.com/pl/produkt/bubblemat',
            'Bubblemat - COBA',
            "Bubblemat\nBF010004 0.6 m x 0.9 m\nBF010003 0.9 m x 1.2 m",
            [['type' => 'sku', 'value' => 'BF010004']]
        );
        $grating = $this->judge(
            $this->coba('LCLIP-38', 'Akcesoria Krata GRP - Uchwyt typu L - 38mm'),
            'https://www.coba.com/pl/produkt/cobagrip-grating',
            'COBAGRiP Grating - COBA',
            "Kraty GRP\nGRP070004G 2000 mm x 1000 mm x 38 mm Żółty\nGRP070003G 3660 mm x 1220 mm x 25 mm",
        );

        $this->assertNotSame('hard', $bubblemat['verdict']);
        $this->assertNotSame('hard', $grating['verdict']);
    }

    public function test_coba_price_list_code_forms_match_page_codes(): void
    {
        // „FF0100-5” w cenniku = „FF010005” na stronie; „CD010610C” (na metry) = rolka „CD010610”
        $dash = $this->judge($this->coba('FF0100-5', 'Orthomat Premium Czarny 0.6m x 18.3m'), 'https://www.coba.com/pl/produkt/orthomat-premium', 'Orthomat Premium', "FF010004 0.9 m x 3.65 m\nFF010005 0.6 m x 18.3 m");
        $cut = $this->judge($this->coba('CD010610C', 'COBAmat Heavy 2210 Czarny 0.6m x mb.'), 'https://www.coba.com/product/cobamat-heavy', 'COBAmat Heavy', '', [['type' => 'sku', 'value' => 'CD010610']]);

        $this->assertSame(['hard', 'FF010005'], [$dash['verdict'], $dash['key']]);
        $this->assertSame(['hard', 'CD010610', 'markup'], [$cut['verdict'], $cut['key'], $cut['where']]);
    }

    public function test_coba_family_code_with_size_codes_in_part_numbers_is_hard(): void
    {
        // Cennik „LM0102”, karta producenta tylko „LM010201”, „LM010202” — i to w data-part, nie w tekście
        $verdict = $this->judge(
            $this->coba('LM0102', 'COBAwash Czarny/Niebieski 0.85m x 1.2m'),
            'https://www.coba.com/product/cobawash',
            'COBAwash - COBA',
            'COBAwash entrance mat',
            [['type' => 'part', 'value' => 'LM010201'], ['type' => 'part', 'value' => 'LM010202']]
        );

        $this->assertSame('hard', $verdict['verdict']);
        $this->assertSame('text', $verdict['where']);
    }

    public function test_coba_code_in_shop_text_is_not_hard_but_in_manufacturer_text_is(): void
    {
        // Tekst sklepu niesie kafelki „podobne / klienci kupili” — kod karty w nim nie potwierdza strony.
        $product = $this->coba('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)');
        $shop = $this->judge($product, 'https://sklep.example/mata-bubblemat.html', 'Mata Bubblemat', "Mata Bubblemat BF010004\nKlienci kupili też: Orthomat AF060001");
        $redirectedToShop = $this->identity()->judgePage($product, [
            'url' => self::ORTHOMAT, 'final_url' => 'https://sklep.example/orthomat', 'title' => 'Orthomat', 'text' => self::ORTHOMAT_TEXT, 'markup_codes' => [],
        ], $this->profiles()->for($product));
        $subdomain = $this->judge($product, 'https://shop.coba.com/orthomat', 'Orthomat', self::ORTHOMAT_TEXT);

        $this->assertNotSame('hard', $shop['verdict']);
        $this->assertNotSame('hard', $redirectedToShop['verdict'], 'tekst należy do adresu po przekierowaniu');
        $this->assertSame(['hard', 'text'], [$subdomain['verdict'], $subdomain['where']], 'poddomena producenta');
    }

    public function test_part_number_counts_only_on_manufacturer_host(): void
    {
        $product = $this->coba('CD010610', 'COBAmat Heavy 2210 Czarny 0.6m x 10m');
        $part = [['type' => 'part', 'value' => 'CD010610']];

        $shop = $this->judge($product, 'https://sklep.example/mata-inna', 'Mata gumowa', '', $part);
        $own = $this->judge($product, 'https://www.coba.com/product/cobamat-heavy', 'COBAmat Heavy', '', $part);
        $shopSku = $this->judge($product, 'https://sklep.example/mata-inna', 'Mata gumowa', '', [['type' => 'sku', 'value' => 'CD010610']]);

        $this->assertNotSame('hard', $shop['verdict'], 'przycisk z numerem części w karuzeli sklepu');
        $this->assertSame(['hard', 'markup'], [$own['verdict'], $own['where']]);
        $this->assertSame(['hard', 'markup'], [$shopSku['verdict'], $shopSku['where']], 'sku głównego wyrobu sklepu liczy się na każdym hoście');
    }

    public function test_shop_markup_code_with_brand_prefix_is_hard(): void
    {
        // icd.pl (Magento): sku i mpn głównego wyrobu to „COBA-PG010001”
        $product = $this->coba('PG010001', 'Premier Grip Czarny 29cm x 44cm');
        $url = 'https://icd.pl/mata-coba-premier-grip-29cm-x-44cmx12mm-czarny.html';

        $prefixed = $this->judge($product, $url, 'Wycieraczka', '', [['type' => 'sku', 'value' => 'COBA-PG010001']]);
        $glued = $this->judge($product, $url, 'Wycieraczka', '', [['type' => 'sku', 'value' => 'XCOBA-PG010001']]);

        $this->assertSame(['hard', 'markup', 'PG010001'], [$prefixed['verdict'], $prefixed['where'], $prefixed['key']]);
        $this->assertNotSame('hard', $glued['verdict'], 'tylko marka jako przedrostek');
    }

    public function test_our_code_only_in_shop_carousel_markup_is_not_hard(): void
    {
        // Sklep: główny wyrób to inna mata, nasz kod tylko w kafelku „podobne” (mikrodane i JSON-LD) i w data-part.
        $html = '<html><head><script type="application/ld+json">'
            .json_encode(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Bubblemat', 'sku' => 'BF010004',
                'isSimilarTo' => ['@type' => 'Product', 'sku' => 'AF060001']])
            .'</script><script type="application/ld+json">'
            .json_encode(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Orthomat', 'sku' => 'AF060001'])
            .'</script></head><body>'
            .'<div itemscope itemtype="https://schema.org/Product"><h1 itemprop="name">Bubblemat</h1><span itemprop="sku">BF010004</span></div>'
            .'<section class="related"><div itemscope itemtype="https://schema.org/Product"><span itemprop="sku">AF060001</span>'
            .'<a data-part="AF060001">Do koszyka</a></div></section></body></html>';
        $codes = (new ProductPageFetcher)->markupIdentifiers($html);
        $product = $this->coba('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)');

        $this->assertNotContains(['type' => 'sku', 'value' => 'AF060001'], $codes);
        $this->assertNotSame('hard', $this->judge($product, 'https://sklep.example/bubblemat', 'Bubblemat', '', $codes)['verdict']);
        $this->assertSame('hard', $this->judge($this->coba('BF010004', 'Bubblemat Czarny 0.6m x 0.9m'), 'https://sklep.example/bubblemat', 'Bubblemat', '', $codes)['verdict']);
    }

    public function test_short_numeric_sku_as_shop_entry_id_is_not_hard(): void
    {
        $product = new Product(['sku' => '4535', 'name' => 'Mata antyzmęczeniowa', 'manufacturer' => 'Coba']);
        $verdict = fn (string $url, string $title = 'Mata antyzmęczeniowa'): ?string => $this->judge($product, $url, $title, '')['verdict'];

        // PrestaShop: numer wpisu sklepu na początku segmentu
        $this->assertNotSame('hard', $verdict('https://sklep.example/maty/4535-mata-antyzmeczeniowa.html'));
        $this->assertNotSame('hard', $verdict('https://sklep.example/4535.html'));
        $this->assertNotSame('hard', $verdict('https://sklep.example/produkt/4535'));
        $this->assertSame('hard', $verdict('https://sklep.example/mata-coba-4535.html'));
        $this->assertSame('hard', $verdict('https://sklep.example/12-mata-coba-4535.html'));
        // tytuł: sama liczba to za mało, obok marki — tak
        $this->assertNotSame('hard', $verdict('https://sklep.example/mata', 'Mata 4535 zł — sklep'));
        $this->assertSame('hard', $verdict('https://sklep.example/mata', 'Mata Coba 4535 — sklep'));
        // kod z literą zostaje jak był
        $this->assertSame('hard', $this->judge($this->coba('VP01', 'Vyna-Plush'), 'https://sklep.example/vp01-mata.html', 'Mata', '')['verdict']);
    }

    public function test_mapa_model_alias_skips_size_norm_brand_and_unit_pairs(): void
    {
        $aliases = function (string $name, ?string $model = null): array {
            $product = new Product(['sku' => '34115038', 'name' => $name, 'manufacturer' => 'MAPA', 'model_name' => $model]);

            return array_column(array_filter(
                $this->identity()->keysFor($product, $this->profiles()->for($product)),
                static fn (array $key): bool => $key['type'] === 'model_alias'
            ), 'value');
        };

        $this->assertSame(['VITAL 115'], $aliases('Rękawice MAPA rozmiar 10 ISO 374 VITAL 115'));
        $this->assertSame(['VITAL 115'], $aliases('Rękawice EN 388 kat. 2 size 10 VITAL 115'));
        $this->assertSame([], $aliases('Krem ochronny MAPA 100 ml'));
        $this->assertSame([], $aliases('Rękawice MAPA 358'), 'marka to nie model');
        $this->assertSame(['TEMP DEX 720', 'DEX 720'], $aliases('TEMP DEX 720'));
        $this->assertSame(['ULTRANITRIL 358'], $aliases('ULTRANITRIL 358 - POLYBAG'));
        $this->assertSame(['VITAL 117'], $aliases('VITAL 117 L'), 'rozmiar L po numerze to nie litry');
        $this->assertSame(['Ultranitril 492'], $aliases('Rękawice rozmiar 9 TITAN 393', 'Ultranitril 492'), 'model_name ma pierwszeństwo');
    }

    public function test_text_counts_only_for_profiles_that_allow_it(): void
    {
        $product = new Product(['sku' => 'S56322S2', 'name' => 'Półmaska S56322S2', 'manufacturer' => 'Secura']);
        $page = ['url' => 'https://shop.example/polmaska', 'title' => 'Półmaska Secura', 'text' => 'Półmaska Secura S56322S2 z filtrami'];

        $verdict = $this->identity()->judgePage($product, $page, $this->profiles()->for($product));

        $this->assertSame('soft', $verdict['verdict'], 'domyślny profil: kod w treści to za mało na twardy werdykt');
        $this->assertSame('hard', $this->identity()->judgePage($product, ['url' => 'https://shop.example/polmaska-s56322s2'] + $page, $this->profiles()->for($product))['verdict']);
        $this->assertSame('hard', $this->identity()->judgePage($product, ['title' => 'Secura S56322S2'] + $page, $this->profiles()->for($product))['verdict']);
    }

    public function test_short_code_in_text_is_not_hard(): void
    {
        $verdict = $this->judge($this->coba('VP01', 'Vyna-Plush Czarny/Stalowy 0.9m x 1.2m'), 'https://shop.example/vyna', 'Entrance mat', 'Vyna-Plush VP01 entrance mat');

        $this->assertNotSame('hard', $verdict['verdict']);
    }

    public function test_ean_from_markup_and_manufacturer_code_of_card_brand_are_keys(): void
    {
        $product = Product::query()->create(['sku' => 'X-1', 'name' => 'Rękawice Tale', 'manufacturer' => 'Canis', 'ean' => '5901234123457']);
        $this->identifier($product, ProductIdentifier::TYPE_MANUFACTURER_CODE, '3210-012-000-00', 'canis');
        $this->identifier($product, ProductIdentifier::TYPE_MANUFACTURER_CODE, '1010-001-703-00', 'reis');

        $keys = $this->identity()->keysFor($product, $this->profiles()->for($product));
        $this->assertContains(['type' => 'ean', 'value' => '5901234123457'], $keys);
        $this->assertContains(['type' => 'manufacturer_code', 'value' => '3210-012-000-00'], $keys);
        $this->assertNotContains(['type' => 'manufacturer_code', 'value' => '1010-001-703-00'], $keys, 'kod innej marki');

        $byGtin = $this->identity()->judgePage($product, ['url' => 'https://cxs.net.pl/tale', 'title' => 'Tale', 'text' => '', 'markup_codes' => [['type' => 'gtin', 'value' => '05901234123457']]], $this->profiles()->for($product));
        $byCode = $this->identity()->judgePage($product, ['url' => 'https://cxs.net.pl/tale', 'title' => 'CXS Tale 3210-012-000-00', 'text' => ''], $this->profiles()->for($product));
        $this->assertSame(['hard', 'ean', 'markup'], [$byGtin['verdict'], $byGtin['key_type'], $byGtin['where']]);
        $this->assertSame(['hard', 'manufacturer_code', 'title'], [$byCode['verdict'], $byCode['key_type'], $byCode['where']]);
    }

    public function test_mapa_model_name_is_a_key_only_with_profile_flag(): void
    {
        $product = new Product(['sku' => '34115038', 'name' => 'VITAL 115', 'manufacturer' => 'MAPA']);
        $own = ['url' => 'https://www.mapa-pro.pl/produkty/wodoodporne/strona-produktu/vital-115', 'title' => 'Vital 115 - MAPA Professional', 'text' => 'Rękawice Vital 115'];
        $sibling = ['url' => 'https://www.mapa-pro.pl/produkty/wodoodporne/strona-produktu/vital-117', 'title' => 'Vital 117 - MAPA Professional', 'text' => 'Rękawice Vital 117'];

        $hit = $this->identity()->judgePage($product, $own, $this->profiles()->for($product));
        $this->assertSame(['hard', 'model_alias', 'VITAL 115'], [$hit['verdict'], $hit['key_type'], $hit['key']]);
        $this->assertNotSame('hard', $this->identity()->judgePage($product, $sibling, $this->profiles()->for($product))['verdict']);

        config()->set('manufacturer_profiles.profiles.mapa.model_alias_is_key', false);
        $this->assertNotSame('hard', $this->identity()->judgePage($product, $own, $this->profiles()->for($product))['verdict']);
    }

    public function test_card_verdict_follows_primary_source(): void
    {
        $product = $this->coba('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)');
        $page = ['url' => 'http://coba.com/product/orthomat-standard?x=1', 'final_url' => self::ORTHOMAT, 'title' => 'Orthomat', 'text' => self::ORTHOMAT_TEXT];

        $card = $this->identity()->judgeCard($product, [$page], self::ORTHOMAT.'/', 'manufacturer', []);
        $this->assertSame(['hard', self::ORTHOMAT.'/', 'coba'], [$card['verdict'], $card['source_url'], $card['profile']], 'dopasowanie po adresie po przekierowaniu');

        $this->assertNull($this->identity()->judgeCard($product, [$page], null, null, [])['verdict'], 'karta bez źródła');
        $this->assertNull($this->identity()->judgeCard($product, [$page], 'https://www.coba.com/product/inna', 'manufacturer', [])['verdict'], 'strona źródła niepobrana');
        $this->assertSame('hard', $this->identity()->judgeCard($product, [], 'https://sklep.example/x', 'manual', [])['verdict']);

        $catalog = 'https://www.securabc.com/img/cms/Katalog%202026%20PL_web.pdf';
        $this->assertSame(
            ['hard', 'catalog'],
            array_values(array_intersect_key($this->identity()->judgeCard($product, [], $catalog, 'shop', [$catalog]), array_flip(['verdict', 'where'])))
        );
    }

    public function test_trusted_shop_url_is_hard(): void
    {
        $product = $this->coba('TR0100', 'Toughrib Antracyt 0.8m x 1.2m');
        $product->shop_source_url = 'https://sklep.example/toughrib';

        $this->assertSame('hard', $this->identity()->judgePage($product, ['url' => 'https://sklep.example/toughrib/', 'text' => 'mata'], null)['verdict']);
    }

    /**
     * 07.10.2026 (podgląd baseline CEDERROTH): cederroth.com na stronach globalnych podaje numer artykułu tylko w nazwie
     * głównego zdjęcia; nasz sklep z kodem w adresie nie potwierdza niczego, nawet wskazany ręcznie.
     */
    public function test_code_in_manufacturer_og_image_is_hard_and_own_shop_is_never_a_source(): void
    {
        config(['prestashop.shop_url' => 'https://www.supon.rzeszow.pl']);
        $station = new Product(['sku' => '51011026', 'name' => 'Apteczka ścienna Cederroth First Aid Station', 'manufacturer' => 'CEDERROTH']);
        $og = [['type' => 'og_image', 'value' => 'https://www.cederroth.com/app/uploads/2020/09/51011026-cederroth-first-aid-station-f-low-scaled.jpg']];

        $this->assertSame('hard', $this->judge($station, 'https://www.cederroth.com/products/first-aid-station/', 'First Aid Station - Global', 'A First Aid Station.', $og)['verdict']);
        // to samo zdjęcie na stronie sklepu — nazwa pliku sklepu bywa dowolna
        $this->assertNotSame('hard', $this->judge($station, 'https://sklep.example/apteczka', 'Apteczka', 'Apteczka.', $og)['verdict']);
        // kod krótki w nazwie pliku nawet u producenta — za mało
        $short = new Product(['sku' => '6036', 'name' => 'Plastry Cederroth Salvequick', 'manufacturer' => 'CEDERROTH']);
        $this->assertNotSame('hard', $this->judge($short, 'https://www.cederroth.com/products/plaster/', 'Plaster', 'Plaster.', [['type' => 'og_image', 'value' => 'https://www.cederroth.com/app/uploads/6036-plaster.jpg']])['verdict']);

        // strona z czytnika (limit zapytań 429) — bez mikrodanych, ale ze zdjęciami wyrobu
        $cabinet = new Product(['sku' => '290900', 'name' => 'Apteczka ścienna Cederroth First Aid Cabinet', 'manufacturer' => 'CEDERROTH']);
        $this->assertSame('hard', $this->identity()->judgePage($cabinet, [
            'url' => 'https://www.cederroth.com/pl/products/duza-szafkowa-apteczka-cederroth-double-door/',
            'text' => 'Duża szafkowa apteczka.',
            'image_urls' => ['https://www.cederroth.com/app/uploads/2020/10/290900-fa-cabinet-double-door-f.jpg'],
        ], $this->profiles()->for($cabinet))['verdict']);

        $dispenser = new Product(['sku' => '490710', 'name' => 'Dozownik na plastry Cederroth', 'manufacturer' => 'CEDERROTH']);
        $ownShop = 'https://www.supon.rzeszow.pl/dozowniki/3893-cederroth-dozownik-na-plastry-490710.html';
        $this->assertSame('none', $this->judge($dispenser, $ownShop, 'Dozownik 490710', 'Dozownik 490710')['verdict']);
        $dispenser->shop_source_url = $ownShop;
        $this->assertSame('none', $this->identity()->judgePage($dispenser, ['url' => $ownShop, 'text' => 'Dozownik'], null)['verdict']);
    }

    public function test_aj_group_labelled_short_model_number_is_hard_only_with_exact_boundary(): void
    {
        // pros.pl zapisuje model w adresie i tytule jako „model 103”; liczba na początku segmentu to numer wpisu sklepu
        $own = $this->judge($this->ajGroup('103', 'Kurtka wodoochronna zapinana na zamek + stójka z polarem'),
            'https://pros.pl/pl/odziez-wodoochronna-standard/62-kurtka-z-zamkiem-model-103.html', 'Kurtka z zamkiem model 103, PROS', '');
        $this->assertSame(['hard', 'sku', '103', 'url'], [$own['verdict'], $own['key_type'], $own['key'], $own['where']]);

        // karta 102 „Kurtka wodoochronna kangurka” ze źródłem płaszcza 1102 — „102-” to numer wpisu, 102 ≠ 1102
        $coat = $this->judge($this->ajGroup('102', 'Kurtka wodoochronna kangurka'),
            'https://pros.pl/pl/odziez-wodoochronna-ostrzegawcza/102-plaszcz-model-1102.html', 'Płaszcz model 1102', 'Płaszcz model 1102',
            [['type' => 'sku', 'value' => '1102-00122-48/XS'], ['type' => 'sku', 'value' => '1102'], ['type' => 'mpn', 'value' => '1102']]);
        $this->assertNotSame('hard', $coat['verdict']);

        // dalszy numer albo przyrostek po ukośniku za kodem to inny model
        $this->assertNotSame('hard', $this->judge($this->ajGroup('104', 'Kombinezon wodoochronny Standard'),
            'https://pros.pl/pl/odziez/241-kombinezon-ocieplany.html', 'Kombinezon ocieplany model 104/1 OC', '')['verdict']);
        $this->assertNotSame('hard', $this->judge($this->ajGroup('333', 'Fartuch Rybacki Extreme'),
            'https://pros.pl/pl/pros-extreme/247-fartuch-rybacki.html', 'Fartuch rybacki model 333/WZ', '')['verdict']);
        // mikrodane głównego wyrobu podają tylko dłuższy kod z literą — etykieta w adresie nie wystarcza
        $this->assertNotSame('hard', $this->judge($this->ajGroup('001', 'Spodnie wodoochronne ogrodniczki Standard'),
            'https://pros.pl/pl/odziez/71-spodnie-ogrodniczki-wzmocnione-model-001-max.html', 'Spodnie ogrodniczki wzmocnione model 001 MAX', '',
            [['type' => 'sku', 'value' => '001 MAX'], ['type' => 'mpn', 'value' => '001 MAX']])['verdict']);
        // bez mikrodanych (pole sku to numer wpisu „71”): znacznik wariantu w tytule, w adresie kod nie zamyka nazwy
        $this->assertNotSame('hard', $this->judge($this->ajGroup('001', 'Spodnie wodoochronne ogrodniczki Standard'),
            'https://pros.pl/pl/odziez/71-spodnie-ogrodniczki-wzmocnione-model-001-max.html', 'Spodnie ogrodniczki wzmocnione model 001 MAX, PROS', '',
            [['type' => 'sku', 'value' => '71']])['verdict']);
        // marka za numerem to nie znacznik wariantu
        $this->assertSame('hard', $this->judge($this->ajGroup('616', 'Kurtka wodoochronna 3/4'),
            'https://pros.pl/en/waterproof-standard-clothing/219-jacket.html', 'Jacket model 616 PROS, waterproof', '')['verdict']);
    }

    public function test_labelled_short_code_on_foreign_host_needs_card_brand(): void
    {
        $product = $this->ajGroup('103', 'Kurtka wodoochronna zapinana na zamek');

        $this->assertNotSame('hard', $this->judge($product, 'https://sklep.example/kurtka-model-103.html', 'Kurtka model 103', 'PROS kurtka')['verdict']);
        $this->assertSame('hard', $this->judge($product, 'https://sklep.example/kurtka-pros-model-103.html', 'Kurtka model 103', '')['verdict']);
        // tekst sklepu nigdy, także z etykietą
        $this->assertNotSame('hard', $this->judge($product, 'https://sklep.example/kurtka-pros.html', 'Kurtka PROS', 'Kurtka PROS model 103')['verdict']);
    }

    public function test_short_code_in_manufacturer_markup_needs_it_in_address_or_title(): void
    {
        // bemoregreen.eu: mikrodane „BEMOREGREEN-901” (nazwa witryny + model), tytuł „KURTKA MĘSKA 901”, bez etykiety
        $jacket = $this->judge($this->ajGroup('901', 'KURTKA MĘSKA'), 'https://bemoregreen.eu/pl/kurtka/1-kurtka-meska-901.html',
            'Be More Green - KURTKA MĘSKA 901 - BeMoreGreen', '', [['type' => 'sku', 'value' => 'BEMOREGREEN-901-00001'], ['type' => 'mpn', 'value' => 'BEMOREGREEN-901']]);
        $this->assertSame(['hard', 'markup'], [$jacket['verdict'], $jacket['where']]);

        // pole sku bywa numerem wpisu (226 tylko na początku segmentu adresu) — to nie kod wyrobu
        $this->assertNotSame('hard', $this->judge($this->ajGroup('226', 'Zacisk do rękawic'),
            'https://pros.pl/pl/fartuchy-wodoochronne/226-zacisk-do-rekawic-model-048tpu.html', 'Zacisk do rękawic model 048/TPU', '',
            [['type' => 'sku', 'value' => '226'], ['type' => 'mpn', 'value' => '226']])['verdict']);
    }

    public function test_aj_group_combination_code_in_manufacturer_markup_is_the_model(): void
    {
        $product = $this->ajGroup('SB01 STRONG', 'Spodniobuty STRONG 1000 g/m2 w kolorze czerwonym');
        $markup = [['type' => 'sku', 'value' => 'SB01 STRONG-00113-39'], ['type' => 'mpn', 'value' => 'SB01 STRONG RED']];

        $own = $this->judge($product, 'https://pros.pl/pl/spodniobuty-i-wodery/222-spodniobuty-strong-red-sb01.html', 'Spodniobuty PROS STRONG RED', '', $markup);
        $this->assertSame(['hard', 'markup'], [$own['verdict'], $own['where']]);
        $this->assertNotSame('hard', $this->judge($product, 'https://sklep.example/spodniobuty-strong-red.html', 'Spodniobuty PROS STRONG RED', '', $markup)['verdict'],
            'końcówka kombinacji to zapis strony producenta, nie sklepu');
    }

    public function test_secura_brand_and_number_from_card_name_is_not_a_key(): void
    {
        // „marka numer” z nazwy karty nie jest kluczem: części do półmaski noszą tę samą parę („Zawór wydechowy SECURA
        // 3000”, „SECURA 3000 - nagłowie”, „Filtry do półmasek - SECURA 3100”) — SECURA potwierdza katalog PDF
        $maskPage = 'https://www.securabc.com/pl/polmaska-wielokrotnego-uzytku-secura/21-secura-3100.html';
        $markup = [['type' => 'sku', 'value' => '21'], ['type' => 'mpn', 'value' => '21']];
        $cards = [
            'S56T1SM0' => 'Półmaska SECURA 3100 (nagłowie trzyczęściowe)',
            'S5621210' => 'Zawór wydechowy SECURA 3100',
            'S5632300' => 'Filtr SECURA 3100 A2',
            'S5621300' => 'Nagłowie tekstylne kompletne do półmaski SECURA 3100',
        ];
        foreach ($cards as $sku => $name) {
            $verdict = $this->judge(new Product(['sku' => $sku, 'name' => $name, 'manufacturer' => 'SECURA']), $maskPage, 'SECURA 3100', '', $markup);
            $this->assertNotSame('hard', $verdict['verdict'], $name);
        }
    }

    public function test_labelled_short_codes_need_profile_flag_and_no_unit_price_or_law_after(): void
    {
        $reis = fn (string $sku, string $title): ?string => $this->judge(
            new Product(['sku' => $sku, 'name' => 'Wyrób REIS', 'manufacturer' => 'REIS']), 'https://sklep.example/reis-wyrob', $title, '')['verdict'];

        $this->assertNotSame('hard', $reis('100', 'Rękawice REIS opak. nr 100 szt'));
        $this->assertNotSame('hard', $reis('300', 'Ręcznik REIS nr 300 g/m2'));
        $this->assertNotSame('hard', $reis('103', 'Apteczka REIS zgodna z art. 103 kodeksu pracy'));
        // bez flagi profilu nawet czysta etykieta i 3-znakowy kod nie są kluczem
        $this->assertNotSame('hard', $reis('A12', 'Okulary REIS model A12'));
        $this->assertNotSame('hard', $this->judge(new Product(['sku' => '450', 'name' => 'Okulary', 'manufacturer' => 'UVEX']),
            'https://sklep.example/okulary-uvex-ref-450.html', 'Okulary UVEX ref 450', '')['verdict']);

        // przy fladze (AJ GROUP) jednostka, cena i przepis za liczbą też wykluczają
        $aj = fn (string $sku, string $title): ?string => $this->judge($this->ajGroup($sku, 'Wyrób'), 'https://sklep.example/pros-wyrob', $title, '')['verdict'];
        $this->assertNotSame('hard', $aj('100', 'Rękawice PROS opak. nr 100 szt'));
        $this->assertNotSame('hard', $aj('103', 'Apteczka PROS zgodna z art. 103 kodeksu pracy'));
        $this->assertNotSame('hard', $aj('103', 'Kurtka PROS model 103,50 zł'));
        // kod krótszy niż 3 znaki nigdy
        $this->assertNotSame('hard', $this->judge($this->ajGroup('08', 'Fartuch'), 'https://pros.pl/pl/fartuchy/203-fartuch-model-08.html', 'Fartuch model 08', '')['verdict']);
    }

    public function test_variant_marker_after_labelled_code_is_checked_on_multibyte_titles(): void
    {
        // 40 bajtów za kodem tnie „ż” w połowie — dotąd preg_match /u zwracał false i znacznik „MAX” przechodził
        $title = 'Spodnie PROS model 001 MAX '.str_repeat('ż', 30);

        $this->assertNotSame('hard', $this->judge($this->ajGroup('001', 'Spodnie wodoochronne ogrodniczki Standard'), 'https://pros.pl/pl/odziez/71-spodnie.html', $title, '')['verdict']);
    }

    public function test_manufacturer_markup_short_code_is_not_confirmed_by_price_in_title(): void
    {
        $verdict = $this->judge($this->ajGroup('103', 'Kurtka wodoochronna'), 'https://pros.pl/pl/odziez/62-kurtka.html', 'Kurtka PROS — teraz 103 zł', '',
            [['type' => 'sku', 'value' => '103'], ['type' => 'mpn', 'value' => '103']]);

        $this->assertNotSame('hard', $verdict['verdict']);
    }

    public function test_labelled_short_code_counts_in_title_but_not_in_manufacturer_text(): void
    {
        $product = new Product(['sku' => '6036', 'name' => 'Plaster Cederroth Salvequick', 'manufacturer' => 'Cederroth']);

        $this->assertSame('hard', $this->judge($product, 'https://www.cederroth.com/pl/products/plaster/', 'Plaster Salvequick REF: 6036', '')['verdict']);
        // strony zestawów i dozowników wymieniają w treści „REF: …” wkładów (pomiar CEDERROTH 07.10.2026)
        $this->assertNotSame('hard', $this->judge($product, 'https://www.cederroth.com/pl/products/automat-na-plastry-salvequick/', 'Automat na plastry',
            "Automat na plastry Salvequick\nWkłady: Plaster plastikowy REF: 6036")['verdict']);
    }

    /**
     * @param  list<array{type: string, value: string}>  $markup
     * @return array<string, mixed>
     */
    private function judge(Product $product, string $url, string $title, string $text, array $markup = []): array
    {
        return $this->identity()->judgePage(
            $product,
            ['url' => $url, 'final_url' => $url, 'title' => $title, 'text' => $text, 'markup_codes' => $markup],
            $this->profiles()->for($product)
        );
    }

    private function coba(string $sku, string $name): Product
    {
        return new Product(['sku' => $sku, 'name' => $name, 'manufacturer' => 'Coba']);
    }

    private function ajGroup(string $sku, string $name): Product
    {
        return new Product(['sku' => $sku, 'name' => $name, 'manufacturer' => 'AJ GROUP']);
    }

    private function identifier(Product $product, string $type, string $value, string $brand): void
    {
        ProductIdentifier::query()->create([
            'product_id' => $product->id,
            'source_key' => 'file',
            'position_key' => $value,
            'type' => $type,
            'value' => $value,
            'normalized' => preg_replace('/[^A-Z0-9]/', '', strtoupper($value)),
            'brand_key' => $brand,
            'manufacturer' => $brand,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    private function identity(): SourceIdentity
    {
        return app(SourceIdentity::class);
    }

    private function profiles(): ManufacturerProfiles
    {
        return app(ManufacturerProfiles::class);
    }
}
