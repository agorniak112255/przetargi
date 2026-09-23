<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductPriceHistory;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRemoteShopField;
use App\Services\B2b\JspB2bClient;
use App\Services\B2b\JspB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik www.jspsafety.com na atrapie sklepu (Http::fake, bez prawdziwego logowania). Sesja konta = ciasteczko
 * .ASPXAUTH ustawione przy logowaniu i wysyłane przez CookieJar klienta; strony produktów mają „Logout Now”
 * tylko dla ważnej sesji. Kod spoza katalogu konta przekierowuje na stronę główną (jak w sklepie).
 */
final class JspConnectorTest extends TestCase
{
    use RefreshDatabase;

    /** 1×1 PNG */
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82";

    private const LOGGED_IN_BAR = '<div class="TopLinks"><a class="logout">Logout Now</a><div class="CreditBar">Overall Credit: (kwota usunięta)</div></div>';

    private const FAR_OG = 'https://www.jspsafety.com/netalogue/photos/far0701-main.jpg';

    private const FAR_URL = 'https://www.jspsafety.com/products/kw/a/FAR0701_2m-Webbing-Self-Retractable-lifeline-Vertical-Horizontal-FF2';

    /** Mapa strony z jednym produktem (adres prawdziwy, z sitemap.xml sklepu). */
    private const ASA_SITEMAP = '<?xml version="1.0" encoding="utf-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        .'<url><loc>https://www.jspsafety.com/products/PPE/Eye-Face-Protection/Over-Spectacles/ASA940-061-300_Stealth-Coverlite-lightweight-Overspecs-Clear-Anti-scratch-lenses-Black-Frames</loc></url>'
        .'</urlset>';

    /**
     * SYNTETYCZNE zakładki Features & Benefits i Delivered With (strona ASA940 ich nie ma; znaczniki jak w innych
     * zakładkach bez klasy Tab_*). „Pantoskopowe ramiona…” powtarza nagłówek z Overview — ma nie wejść drugi raz.
     */
    private const EXTRA_TABS = '<h3 class="TabbedData_TabBodyAccordionTitle" data-index="7"><span> Features &amp; Benefits </span></h3>'
        .'<div class="TabbedData_TabBodyContainer" data-index="7"><div class="TabbedData_SectionContainer"><div class="TabbedData_TextContentContainer">'
        .'<ul class="description-overview"><li> Pantoskopowe ramiona z miękkim uchwytem </li><li> Soczewka 1 klasy optycznej </li><li> Soczewka 1 klasy optycznej </li></ul>'
        .'</div></div></div>'
        .'<h3 class="TabbedData_TabBodyAccordionTitle" data-index="8"><span> Delivered With </span></h3>'
        .'<div class="TabbedData_TabBodyContainer" data-index="8"><div class="TabbedData_SectionContainer"><div class="TabbedData_TextContentContainer">'
        .'<ul><li> Etui z mikrofibry </li><li> Linka do okularów </li></ul>'
        .'</div></div></div>';

    private const ASA_OVERVIEW = "Stealth™ Coverlite™ z powłoką nierysującą K — przezroczyste\n"
        ."Spełnia wymagania normy EN 166\n"
        ."Stylowe\n"
        ."Stylowa i przestronna konstrukcja Stealth™ Coverlite™ oznacza, że mogą one wygodnie zmieścić się na okulary korekcyjne, zachowując nowoczesny wygląd.\n"
        ."Powłoka nierysująca K\n"
        ."Soczewka Stealth™ CoverLite™ jest pokryta z obu stron powłoką PremierShield™ K o właściwościach nierysujących.\n"
        ."Optycznie doskonały\n"
        ."Wytrzymała, niska podstawa, minimalna krzywizna, soczewka 1 klasy optycznej zapewnia optymalne widzenie.\n"
        ."Pantoskopowe ramiona z miękkim uchwytem\n"
        ."Ramiona Stealth™ CoverLite™ są pantoskopowe i mają miękkie nakładki.\n"
        ."Elastyczna oprawka\n"
        ."Oprawka Stealth™ Coverlite™ jest elastyczna i sprężysta.\n"
        ."Bardzo lekka waga\n"
        .'Stealth™ Coverlite™ waży zaledwie 34 g.';

    private const ASA_SHORT = "- Stylowy Overspec\n- Powłoka soczewki K\n- Optycznie doskonały\n- Pantoskopowe ramiona z miękkim uchwytem\n- Elastyczna oprawka\n- Ultralekkie";

    private const ASA_WEIGHTS = "Wagi i wymiary:\n"
        ."INNER PACK – Pack quantity: 10\nINNER PACK – Height: 12CM\nINNER PACK – Width: 17.5CM\nINNER PACK – Length: 27.5CM\nINNER PACK – Weight: 0.58KG\n"
        .'OUTER PACK – Pack quantity: 120'."\nOUTER PACK – Height: 30CM\nOUTER PACK – Width: 50CM\nOUTER PACK – Length: 54CM\nOUTER PACK – Weight: 5.082KG";

    /** Zastępcza mapa strony (null = tests/Fixtures/jsp/sitemap.xml). */
    private ?string $sitemap = null;

    /** @var array<string, string> kod bez myślników → HTML strony produktu */
    private array $pages = [];

    /** @var list<string> */
    private array $validSessions = [];

    private int $logins = 0;

    /** Pierwsze pobranie strony produktu unieważnia sesję (np. wygasła w nocy). */
    private bool $dropSessionOnce = false;

    /** Strony produktów nigdy nie mają „Logout Now”, choć logowanie się udaje. */
    private bool $pagesWithoutMarker = false;

    /** @var list<int> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $far = $this->fixture('product_far0701.html');
        $this->pages = [
            'FAR0701' => $far,
            '1LEOCARB23S' => $this->fixture('product_1leocarb23s.html'),
            // strona grupy wariantów: tytuł jest, kodu produktu i ceny brak
            'VARAKS270000100' => str_replace(
                '<div id="ctl00_ContentPlaceHolder1_ctl16_dvPartNo" class="ProductTitleBar_PartNo"> FAR0701 </div>', '', $far
            ),
            // AJF030000100 nie ma strony dla konta → przekierowanie na stronę główną
            // w mapie strony tylko przy $this->sitemap = ASA_SITEMAP
            'ASA940061300' => $this->fixture('product_asa940061300.html'),
        ];
    }

    public function test_login_echoes_hidden_form_fields_and_sends_session_cookies_with_later_requests(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();
        $page = $client->productPage('FAR0701');

        $this->assertTrue($client->isLoggedIn());
        $this->assertSame('ok', $page['status']);
        $post = Http::recorded(fn (Request $r): bool => $r->method() === 'POST')->first()[0];
        $this->assertSame('https://www.jspsafety.com/login.aspx', $post->url());
        $data = $post->data();
        $this->assertSame('VS-SYNTETYCZNY', $data['__VIEWSTATE']);
        $this->assertSame('C2EE9ABB', $data['__VIEWSTATEGENERATOR']);
        $this->assertSame('EV-SYNTETYCZNY', $data['__EVENTVALIDATION']);
        $this->assertSame('true', $data['ctl00$ContentPlaceHolder1$hdnDefaultLoginButtonEnabled']);
        $this->assertSame('jan', $data['ctl00$ContentPlaceHolder1$tbusername']);
        $this->assertSame('dobre-haslo', $data['ctl00$ContentPlaceHolder1$tbpassword']);
        $this->assertSame('Login to distributor account', $data['ctl00$ContentPlaceHolder1$blogin']);
        // inne przyciski formularza i pola z szablonów w skryptach nie są wysyłane
        $this->assertArrayNotHasKey('ctl00$ContentPlaceHolder1$bGoogleLogin', $data);
        $this->assertArrayNotHasKey('zeSkryptu', $data);
        $this->assertStringContainsString('ASP.NET_SessionId=anon1', $post->header('Cookie')[0] ?? '');

        $product = Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/products/kw/a/FAR0701_'))->first()[0];
        $cookie = $product->header('Cookie')[0] ?? '';
        $this->assertStringContainsString('ASP.NET_SessionId=anon1', $cookie);
        $this->assertStringContainsString('__AntiXsrfToken=xsrf1', $cookie);
        $this->assertStringContainsString('.ASPXAUTH=auth1', $cookie);
    }

    public function test_login_without_logout_now_fails_with_clear_message(): void
    {
        $this->fakeSite();
        $client = new JspB2bClient('jan', 'zle-haslo', 0, fn (int $ms) => $this->sleeps[] = $ms);

        try {
            $client->login();
            $this->fail('Logowanie powinno się nie udać');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(B2bFatalException::class, $e);
            $this->assertStringStartsWith('Logowanie do jspsafety.com nieudane', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_sitemap_keeps_standard_codes_without_bespoke_and_duplicates_and_url_drops_dashes(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $codes = $client->sitemapProductCodes();

        $this->assertSame(['FAR0701', '1LEOCARB23S', 'AJF030-000-100', 'VAR-AKS270-000-100'], $codes);
        $this->assertSame('https://www.jspsafety.com/products/kw/a/AJF030000100_EVO3-Safety-Helmet', $client->productUrl('AJF030-000-100'));
        $this->assertSame(self::FAR_URL, $client->productUrl('FAR0701'));
    }

    public function test_price_is_account_price_in_euro_from_price_or_from_label_and_ignores_tiers(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsByCode($connector);

        $far = $connector->price($products['FAR0701']);
        $this->assertSame('From:', $products['FAR0701']->raw['price_prefix']);
        $this->assertSame(155.83, $far?->net);
        $this->assertNull($far?->base);
        $this->assertSame(0.0, $far?->discountPercent);
        $this->assertSame('EUR', $far?->currency);

        $leone = $connector->price($products['1LEOCARB23S']);
        $this->assertSame('Price:', $products['1LEOCARB23S']->raw['price_prefix']);
        $this->assertSame(7.74, $leone?->net);
        $this->assertNull($leone?->base);
        $this->assertSame('EUR', $leone?->currency);
        $this->assertSame(4, $connector->totalProducts());
    }

    public function test_non_empty_mrrp_becomes_catalog_price(): void
    {
        $this->pages['1LEOCARB23S'] = str_replace(
            '<div class="Prices_PriceMRRPText Prices_PriceMRRPText_NoStrike"></div>',
            '<div class="Prices_PriceMRRPText Prices_PriceMRRPText_NoStrike"> €9.99 </div>',
            $this->pages['1LEOCARB23S'],
        );
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();

        $price = $connector->price($this->productsByCode($connector)['1LEOCARB23S']);

        $this->assertSame(7.74, $price?->net);
        $this->assertSame(9.99, $price?->base);
    }

    public function test_code_redirected_to_home_page_is_unavailable_and_skipped_with_reason(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsByCode($connector);

        $unavailable = $products['AJF030-000-100'];
        $this->assertSame('skipped', $unavailable->raw['status']);
        Http::assertSent(static fn (Request $r): bool => $r->url() === 'https://www.jspsafety.com/products/kw/a/AJF030000100_EVO3-Safety-Helmet');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('niedostępny w katalogu konta (sklep przekierował na stronę główną)');
        $connector->price($unavailable);
    }

    public function test_client_reports_redirect_to_home_page_as_unavailable(): void
    {
        $this->fakeSite();
        $client = $this->client();
        $client->login();

        $this->assertSame('unavailable', $client->productPage('AJF030-000-100')['status']);
        $this->assertSame('ok', $client->productPage('FAR0701')['status']);
    }

    public function test_page_showing_other_code_or_no_code_is_skipped(): void
    {
        $this->pages['1LEOCARB23S'] = $this->pages['FAR0701'];
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsByCode($connector);

        $this->assertSame('kod na stronie (FAR0701) inny niż kod z mapy strony (1LEOCARB23S)', $products['1LEOCARB23S']->raw['reason']);
        $this->assertSame('brak kodu produktu na stronie (np. strona grupy wariantów)', $products['VAR-AKS270-000-100']->raw['reason']);
        // strona innego wyrobu nie przypisuje jego kodu tej pozycji
        $this->assertNull($products['1LEOCARB23S']->identifiers);
        $this->assertNull($products['VAR-AKS270-000-100']->identifiers);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('kod na stronie (FAR0701) inny niż kod z mapy strony (1LEOCARB23S)');
        $connector->price($products['1LEOCARB23S']);
    }

    public function test_logged_in_page_without_price_block_is_skipped_as_no_price_without_stopping_sync(): void
    {
        Storage::fake('public');
        $this->pages['1LEOCARB23S'] = (string) preg_replace('#<!-- cena -->.*?<!-- /cena -->#s', '', $this->pages['1LEOCARB23S']);
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(1, $result['created']);
        $this->assertContains('1LEOCARB23S: brak ceny w B2B', $result['errors']);
        $this->assertFalse(Product::query()->where('sku', '1LEOCARB23S')->exists());
        $this->assertSame('ok', $this->account()->last_sync_status);
    }

    public function test_product_page_without_logout_now_logs_in_again_and_continues(): void
    {
        $this->dropSessionOnce = true;
        $this->fakeSite();
        $client = $this->client();
        $client->login();

        $page = $client->productPage('FAR0701');

        $this->assertSame(2, $this->logins);
        $this->assertSame('ok', $page['status']);
        $this->assertTrue(JspB2bClient::hasLoggedInMarker($page['html'] ?? ''));
    }

    public function test_product_page_still_without_logout_now_after_relogin_is_fatal(): void
    {
        $this->pagesWithoutMarker = true;
        $this->fakeSite();
        $client = $this->client();
        $client->login();

        try {
            $client->productPage('FAR0701');
            $this->fail('Oczekiwano B2bFatalException');
        } catch (B2bFatalException $e) {
            $this->assertStringStartsWith('Utracono sesję konta jspsafety.com', $e->getMessage());
        }
        $this->assertSame(2, $this->logins);
    }

    public function test_description_is_polish_feature_list_without_duplicates_and_category_comes_from_breadcrumbs(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsByCode($connector);

        $far = $products['FAR0701'];
        $this->assertSame('FAR0701', $far->sku);
        $this->assertSame('FAR0701', $far->remoteId);
        $this->assertSame('Urządzenie samohamowne 2m - pionowe + poziome + FF2', $far->name);
        $this->assertSame('JSP', $connector->manufacturer($far));
        $this->assertSame(self::FAR_URL, $far->sourceUrl);
        // adres kw/a ma w okruszkach tylko „Search : a” — to nie kategoria
        $this->assertNull($far->category);
        $this->assertSame(implode("\n", [
            '- Wytrzymała i trwała obudowa z tworzywa sztucznego.',
            '- Taśma o szerokości 21 mm z amortyzatorem.',
            '- Zgodne z normą EN 360:2002, do pracy w poziomie (ostra krawędź CNB/P/11.060:2014) i przy współczynniku upadku 2 w granicy 100 kg.',
            '- 2 zatrzaśniki aluminiowe z blokadą ćwierćobrotową na obu końcach. Otwarcie zatrzaśnika: 21 mm. Zgodne z normą EN 362:2004 klasa B.',
        ]), $connector->description($far));
        // Jednostka sprzedaży jest wierszem tabelki karty wyrobu u dostawcy, nie linią opisu.
        $this->assertStringNotContainsString('Jednostka: ', $connector->description($far));
        $this->assertContains(
            ['Informacje handlowe', 'Jednostka sprzedaży', 'sztuka'],
            self::rows($connector->shopFields($far)),
        );

        // kod JSP ze strony (ProductTitleBar_PartNo) dosłownie — kod producenta pozycji karty
        $this->assertSame(
            [[ProductIdentifier::TYPE_MANUFACTURER_CODE, 'FAR0701', 'FAR0701', null, 'PartNo']],
            self::identifierRows($far),
        );

        $leone = $products['1LEOCARB23S'];
        $this->assertSame('1LEOCARB23S', $leone->sku);
        // mapa strony podaje „1lEOCARB23S” — wartość idzie ze strony produktu, nie z adresu
        $this->assertSame(
            [[ProductIdentifier::TYPE_MANUFACTURER_CODE, '1LEOCARB23S', '1LEOCARB23S', null, 'PartNo']],
            self::identifierRows($leone),
        );
        $this->assertSame('Okulary ochronne Leone™ Premium - soczewki przyciemniane K&N, oprawki w stylu karbonu', $leone->name);
        $this->assertSame('ŚOI › Ostatnia szansa na zakup › Ochrona oczu ostatniej szansy', $leone->category);
        // dokumenty (karty techniczne, deklaracje) nie są pobierane
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), 'showfile.aspx'));
    }

    public function test_description_is_full_overview_then_short_features_without_weights_unit_or_other_tabs(): void
    {
        $description = $this->asaDescription();

        $this->assertSame(implode("\n\n", [
            self::ASA_OVERVIEW,
            // polskie krótkie cechy nie są dosłownie nagłówkami opisu („Stylowy Overspec” ≠ „Stylowe”) — zostają
            "Cechy w skrócie:\n".self::ASA_SHORT,
        ]), $description);
        // Wagi i jednostka sprzedaży są wierszami tabelki karty wyrobu u dostawcy, nie liniami opisu.
        $this->assertStringNotContainsString(self::ASA_WEIGHTS, $description);
        foreach (['Wagi i wymiary:', 'INNER PACK', 'OUTER PACK', 'Jednostka: '] as $absent) {
            $this->assertStringNotContainsString($absent, $description);
        }
        foreach (['Karta techniczna', 'Download', 'przydymione', 'Add to basket', 'Brak opinii', 'Oceń ten produkt', 'Jak dobrać', 'Show More', 'Show Less', 'Szablon ze skryptu', '€'] as $chrome) {
            $this->assertStringNotContainsString($chrome, $description);
        }
    }

    public function test_short_features_are_dropped_when_each_is_already_a_line_of_overview(): void
    {
        $this->pages['ASA940061300'] = str_replace(
            ['Stylowy Overspec', 'Powłoka soczewki K', 'Ultralekkie', 'Elastyczna oprawka'],
            ['Stylowe', 'powłoka nierysująca K.', 'Bardzo lekka waga', 'Elastyczna oprawka'],
            $this->pages['ASA940061300'],
        );

        $this->assertSame(self::ASA_OVERVIEW, $this->asaDescription());
    }

    public function test_without_overview_tab_description_falls_back_to_short_features(): void
    {
        $this->pages['ASA940061300'] = (string) preg_replace('#<!-- overview -->.*?<!-- /overview -->#s', '', $this->pages['ASA940061300']);

        $this->assertSame(self::ASA_SHORT, $this->asaDescription());
    }

    public function test_features_and_delivered_with_tabs_add_only_lines_not_yet_in_description(): void
    {
        $this->pages['ASA940061300'] = str_replace('<!-- /weights -->', self::EXTRA_TABS, $this->pages['ASA940061300']);

        $this->assertSame(implode("\n\n", [
            self::ASA_OVERVIEW,
            "Cechy w skrócie:\n".self::ASA_SHORT,
            "Cechy i zalety:\n- Soczewka 1 klasy optycznej",
            "W zestawie:\n- Etui z mikrofibry\n- Linka do okularów",
        ]), $this->asaDescription());
    }

    public function test_weights_table_gives_only_label_value_rows_with_group_prefix(): void
    {
        $this->pages['ASA940061300'] = (string) preg_replace(
            '#<span class="field-value">.*?</span>#s',
            '<table><tr><th colspan="2">INNER PACK</th></tr><tr><td>Pack quantity</td><td>10</td></tr><tr><td>Uwagi</td><td> </td></tr>'
            .'<tr><td>Height</td><td>12CM</td></tr></table><table><tr><td>Weight</td><td>0.58KG</td></tr></table>',
            $this->pages['ASA940061300'],
        );

        $fields = self::rows($this->asaShopFields());

        $this->assertSame([
            ['Wagi i wymiary', 'INNER PACK – Pack quantity', '10'],
            ['Wagi i wymiary', 'INNER PACK – Height', '12CM'],
            ['Wagi i wymiary', 'INNER PACK – Weight', '0.58KG'],
        ], array_values(array_filter(
            $fields,
            static fn (array $row): bool => $row[0] === 'Wagi i wymiary',
        )));
        $this->assertContains(['Informacje handlowe', 'Jednostka sprzedaży', 'sztuka'], $fields);
    }

    public function test_overview_keeps_inline_bold_in_its_sentence_and_list_items_as_bullets(): void
    {
        // układ z opisów EVO®5 i Force®8 (strony anonimowe), teksty skrócone
        $this->pages['ASA940061300'] = (string) preg_replace(
            '#(<div class="TabbedData_TextContentContainer">)\s*<h2> Stealth.*?(<input)#s',
            '$1<h2> EVO®5 Dualswitch™ </h2>'."\n".'Hełm zgodny z normami EN 397 i EN 12492, z możliwością przełączania pomiędzy'."\n"
            .'2 normami.<br><br><strong> Praca na ziemi - przesuń w górę </strong><br><br><ul class="description-overview"><li> EN 397 </li><li> ANSI / ISEA Z89.1 </li></ul>'
            .'<strong> Praca na wysokości - przesuń w dół </strong><ul class="description-overview"><li> EN 12492 </li><li> ANSI / ISEA Z89.1 </li></ul><br><br>'
            .' <strong>Wkładka EPP </strong>- Nasza wytrzymała wyściółka.<br><br><strong> Bezpieczne dopasowanie -</strong> w pełni regulowany pasek.<br><br>'
            .'<strong> CR2</strong> - materiał odblaskowy.<br>Soczewka <strong>1 klasy</strong> optycznej.<br>'
            .'<strong>Features &amp; Benefits</strong><ul class="description-overview"><li> <b>Convenience</b> - half-mask always on hand </li></ul>'
            .'<img src="https://data.jsp.co.uk/images/dualswitch.png"><br><br>Zdjęcia tylko do celów poglądowych.$2',
            $this->pages['ASA940061300'],
        );

        $this->assertStringStartsWith(implode("\n", [
            'EVO®5 Dualswitch™',
            'Hełm zgodny z normami EN 397 i EN 12492, z możliwością przełączania pomiędzy 2 normami.',
            'Praca na ziemi - przesuń w górę',
            '- EN 397',
            '- ANSI / ISEA Z89.1',
            'Praca na wysokości - przesuń w dół',
            '- EN 12492',
            // ta sama norma pod innym nagłówkiem zostaje
            '- ANSI / ISEA Z89.1',
            'Wkładka EPP - Nasza wytrzymała wyściółka.',
            'Bezpieczne dopasowanie - w pełni regulowany pasek.',
            'CR2 - materiał odblaskowy.',
            'Soczewka 1 klasy optycznej.',
            'Features & Benefits',
            '- Convenience - half-mask always on hand',
            'Zdjęcia tylko do celów poglądowych.',
        ])."\n\nCechy w skrócie:", $this->asaDescription());
    }

    public function test_next_sync_replaces_description_written_by_sync_with_full_page_description(): void
    {
        Storage::fake('public');
        $this->sitemap = self::ASA_SITEMAP;
        $full = $this->pages['ASA940061300'];
        // strona w starym odczycie: bez zakładek zostają tylko krótkie cechy (jak w zgłoszeniu)
        $this->pages['ASA940061300'] = (string) preg_replace('#<!-- zakładki -->.*?<!-- /zakładki -->#s', '', $full);
        $this->fakeSite();

        $first = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);
        $product = Product::query()->where('sku', 'ASA940-061-300')->sole();
        $this->assertSame(1, $first['created']);
        $this->assertSame(self::ASA_SHORT, $product->description);

        $this->pages['ASA940061300'] = $full;
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $product->refresh();
        $this->assertSame(1, $second['descriptions']);
        $this->assertStringStartsWith(self::ASA_OVERVIEW."\n\nCechy w skrócie:", (string) $product->description);
        // Wagi zostają w tabelce karty wyrobu u dostawcy — opis ich nie przejmuje.
        $this->assertStringNotContainsString(self::ASA_WEIGHTS, (string) $product->description);
        $this->assertSame(sha1((string) $product->description), B2bProductLink::query()->where('remote_id', 'ASA940-061-300')->value('description_hash'));

        // kod producenta zapisany raz, drugi przebieg bez duplikatu, bez oznaczenia zniknięcia i bez ostrzeżeń
        $this->assertSame(
            [[$product->id, 'ASA940-061-300', ProductIdentifier::TYPE_MANUFACTURER_CODE, 'ASA940-061-300', 'PartNo', 'JSP']],
            ProductIdentifier::query()->orderBy('id')->get()
                ->map(static fn (ProductIdentifier $i): array => [$i->product_id, $i->position_key, $i->type, $i->value, $i->source_field, $i->manufacturer])
                ->all(),
        );
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());
        foreach ([$first, $second] as $result) {
            $texts = implode("\n", array_column(B2bSyncRun::query()->findOrFail($result['sync_run_id'])->log, 'text'));
            $this->assertStringNotContainsString('identyfikator', $texts);
        }
    }

    /**
     * Hierarchia źródeł opisu (decyzja właściciela z 18.09.2026): witryna producenta stoi
     * najwyżej i zastępuje opis zapisany na karcie — opisów nie redaguje się u nas ręcznie,
     * a ten z JSP pochodzi od autora wyrobu. Poprzedni tekst zostaje w karcie, żeby zmiana
     * była odwracalna. Dystrybutorzy opisu nadal nie ruszają (B2bManufacturerDescriptionTest).
     */
    public function test_next_sync_replaces_the_card_description_with_the_manufacturer_one(): void
    {
        Storage::fake('public');
        $this->sitemap = self::ASA_SITEMAP;
        $full = $this->pages['ASA940061300'];
        $this->pages['ASA940061300'] = (string) preg_replace('#<!-- zakładki -->.*?<!-- /zakładki -->#s', '', $full);
        $this->fakeSite();
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);
        $product = Product::query()->where('sku', 'ASA940-061-300')->sole();
        $product->update(['description' => 'Opis wpisany w katalogu przed przebiegiem.']);

        $this->pages['ASA940061300'] = $full;
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(1, $second['descriptions']);
        $fresh = $product->fresh();
        $this->assertNotSame('Opis wpisany w katalogu przed przebiegiem.', $fresh?->description);
        $this->assertStringStartsWith(self::ASA_OVERVIEW, (string) $fresh?->description);
        $this->assertSame(
            'Opis wpisany w katalogu przed przebiegiem.',
            $fresh?->enrichment_payload['replaced_description'] ?? null
        );
    }

    public function test_shop_card_rows_come_from_the_page_already_downloaded_in_products(): void
    {
        $this->sitemap = self::ASA_SITEMAP;
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $product = $this->productsByCode($connector)['ASA940-061-300'];

        $before = Http::recorded()->count();
        $fields = $connector->shopFields($product);

        $this->assertSame($before, Http::recorded()->count(), 'karta dostawcy nie dopytuje sklepu');
        $this->assertSame([
            ['Informacje handlowe', 'Kod', 'ASA940-061-300'],
            ['Informacje handlowe', 'Jednostka sprzedaży', 'sztuka'],
            ['Wagi i wymiary', 'INNER PACK – Pack quantity', '10'],
            ['Wagi i wymiary', 'INNER PACK – Height', '12CM'],
            ['Wagi i wymiary', 'INNER PACK – Width', '17.5CM'],
            ['Wagi i wymiary', 'INNER PACK – Length', '27.5CM'],
            ['Wagi i wymiary', 'INNER PACK – Weight', '0.58KG'],
            ['Wagi i wymiary', 'OUTER PACK – Pack quantity', '120'],
            ['Wagi i wymiary', 'OUTER PACK – Height', '30CM'],
            ['Wagi i wymiary', 'OUTER PACK – Width', '50CM'],
            ['Wagi i wymiary', 'OUTER PACK – Length', '54CM'],
            ['Wagi i wymiary', 'OUTER PACK – Weight', '5.082KG'],
        ], self::rows($fields));
    }

    public function test_shop_card_shows_the_category_from_breadcrumbs_and_skips_a_product_without_a_page(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $products = $this->productsByCode($connector);

        $this->assertContains(
            ['Informacje handlowe', 'Kategoria', 'ŚOI › Ostatnia szansa na zakup › Ochrona oczu ostatniej szansy'],
            self::rows($connector->shopFields($products['1LEOCARB23S'])),
        );
        // pozycja bez strony w katalogu konta nie ma czego pokazać
        $this->assertSame([], $connector->shopFields($products['AJF030-000-100']));
    }

    /**
     * @param  list<B2bRemoteShopField>  $fields
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function rows(array $fields): array
    {
        return array_map(
            static fn ($field): array => [$field->section, $field->name, $field->value],
            $fields,
        );
    }

    public function test_image_comes_from_og_image_as_public_url(): void
    {
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $far = $this->productsByCode($connector)['FAR0701'];

        $image = $connector->image($far);

        $this->assertNotNull($image);
        $this->assertSame(self::PNG, $image->bytes);
        $this->assertSame('image/png', $image->mime);
        $this->assertSame(self::FAR_OG, $image->sourceUrl);
        $this->assertNull($connector->image(new B2bRemoteProduct('X', 'X', 'X', raw: ['status' => 'ok'])));
    }

    public function test_rate_limit_honours_retry_after_then_backoff_and_succeeds(): void
    {
        Http::fake(['www.jspsafety.com/*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '7'])
            ->push('', 503)
            ->push($this->fixture('sitemap.xml'))]);

        $this->assertCount(4, $this->client()->sitemapProductCodes());
        $this->assertSame([7000, 10000], $this->sleeps);
    }

    public function test_twenty_consecutive_http_failures_are_fatal(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $client = $this->client();
        for ($i = 1; $i < 20; $i++) {
            try {
                $client->sitemapProductCodes();
                $this->fail('Oczekiwano błędu HTTP');
            } catch (RuntimeException $e) {
                $this->assertNotInstanceOf(B2bFatalException::class, $e);
            }
        }
        $this->expectException(B2bFatalException::class);
        $client->sitemapProductCodes();
    }

    public function test_registry_detects_jsp_by_host(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('jsp', $registry->keyForSites(['https://www.jspsafety.com/login.aspx']));
        $this->assertSame('JSP', $registry->label('jsp'));
        $account = B2bAccount::query()->create(['username' => 'jan', 'password' => 'sekret', 'sites' => ['www.jspsafety.com']]);
        $this->assertInstanceOf(JspB2bConnector::class, $registry->make($account, 0));
    }

    public function test_sync_through_runner_creates_jsp_card_with_account_price_description_link_image_and_history(): void
    {
        Storage::fake('public');
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);

        $this->assertSame(4, $result['total_remote']);
        $this->assertSame(4, $result['seen']);
        $this->assertSame(2, $result['created']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(2, $result['images']);
        $this->assertContains('AJF030-000-100: niedostępny w katalogu konta (sklep przekierował na stronę główną)', $result['errors']);
        $this->assertContains('VAR-AKS270-000-100: brak kodu produktu na stronie (np. strona grupy wariantów)', $result['errors']);
        $this->assertFalse(Product::query()->whereIn('sku', ['AJF030-000-100', 'VAR-AKS270-000-100'])->exists());

        $product = Product::query()->where('sku', 'FAR0701')->sole();
        $this->assertSame('JSP', $product->manufacturer);
        $this->assertSame('Urządzenie samohamowne 2m - pionowe + poziome + FF2', $product->name);
        $this->assertSame('EUR', $product->currency);
        $this->assertSame('155.83', $product->purchase_price);
        $this->assertSame('155.83', $product->catalog_price_net);
        $this->assertSame('0.00', $product->discount_percent);
        $this->assertSame(self::FAR_URL, $product->shop_source_url);
        $this->assertStringContainsString('- Zgodne z normą EN 360:2002', (string) $product->description);
        $this->assertSame(self::FAR_OG, $product->images()->firstOrFail()->source_url);
        $this->assertSame('ŚOI › Ostatnia szansa na zakup › Ochrona oczu ostatniej szansy', Product::query()->where('sku', '1LEOCARB23S')->value('category'));

        $link = B2bProductLink::query()->where('remote_id', 'FAR0701')->sole();
        $this->assertSame($product->id, $link->product_id);
        $this->assertSame(sha1((string) $product->description), $link->description_hash);
        $this->assertTrue(ProductPriceHistory::query()
            ->where('product_id', $product->id)
            ->where('price_list_id', $result['price_list_id'])
            ->where('b2b_sync_run_id', $result['sync_run_id'])
            ->where('source', 'b2b:jsp')
            ->exists());
        $this->assertSame(1, PriceList::query()->count());
        $this->assertStringStartsWith('W B2B: 4 · sprawdzone: 4 · nowe: 2', (string) $this->account()->last_sync_message);
        // kody producenta tylko zapisanych pozycji — pominięte (AJF030, VAR-AKS270) nie mają wierszy
        $this->assertSame(
            ['1LEOCARB23S' => '1LEOCARB23S', 'FAR0701' => 'FAR0701'],
            ProductIdentifier::query()->where('type', ProductIdentifier::TYPE_MANUFACTURER_CODE)
                ->orderBy('position_key')->pluck('value', 'position_key')->all(),
        );
        $this->assertSame(2, ProductIdentifier::query()->count());
    }

    public function test_sync_command_prints_summary_for_product_connector(): void
    {
        $this->fakeSite();
        $account = $this->account();

        $this->artisan('b2b:sync', ['account' => $account->id, '--delay' => 0, '--no-images' => true, '--dry-run' => true])
            ->expectsOutputToContain('W B2B: 4 · sprawdzone: 4 · nowe: 2')
            ->assertSuccessful();
    }

    private function client(): JspB2bClient
    {
        return new JspB2bClient('jan', 'dobre-haslo', 0, function (int $ms): void {
            $this->sleeps[] = $ms;
        });
    }

    private function connector(): JspB2bConnector
    {
        return new JspB2bConnector($this->client());
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => 'jan'],
            ['password' => 'dobre-haslo', 'sites' => ['www.jspsafety.com'], 'connector' => 'jsp'],
        )->fresh();
    }

    /**
     * @return array<string, B2bRemoteProduct>
     */
    private function productsByCode(JspB2bConnector $connector): array
    {
        $out = [];
        foreach ($connector->products() as $product) {
            $out[$product->remoteId] = $product;
        }

        return $out;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string|null, 3: string|null, 4: string|null}>
     */
    private static function identifierRows(B2bRemoteProduct $product): array
    {
        return array_map(
            static fn (B2bRemoteIdentifier $i): array => [$i->type, $i->value, $i->remoteId, $i->label, $i->field],
            $product->identifiers ?? [],
        );
    }

    /** Opis ASA940-061-300 z przebiegu łącznika po stronie z $this->pages (mapa strony z jednym produktem). */
    private function asaDescription(): string
    {
        [$connector, $product] = $this->asaProduct();

        return $connector->description($product);
    }

    /**
     * Wiersze karty wyrobu u dostawcy dla ASA940-061-300 (tam trafiły wagi i jednostka sprzedaży).
     *
     * @return list<B2bRemoteShopField>
     */
    private function asaShopFields(): array
    {
        [$connector, $product] = $this->asaProduct();

        return $connector->shopFields($product);
    }

    /**
     * @return array{0: JspB2bConnector, 1: B2bRemoteProduct}
     */
    private function asaProduct(): array
    {
        $this->sitemap = self::ASA_SITEMAP;
        $this->fakeSite();
        $connector = $this->connector();
        $connector->login();
        $product = $this->productsByCode($connector)['ASA940-061-300'];
        $this->assertSame('ok', $product->raw['status']);

        return [$connector, $product];
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/jsp/'.$name));
    }

    private function fakeSite(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            preg_match('/\.ASPXAUTH=([^;\s]+)/', $request->header('Cookie')[0] ?? '', $m);
            $loggedIn = isset($m[1]) && in_array($m[1], $this->validSessions, true);

            if ($path === '/login.aspx' && $request->method() === 'GET') {
                return Http::response($this->fixture('login.html'), 200, ['Set-Cookie' => [
                    'ASP.NET_SessionId=anon1; path=/; HttpOnly; SameSite=None; Secure',
                    '__AntiXsrfToken=xsrf1; path=/; HttpOnly; SameSite=None; Secure',
                ]]);
            }
            if ($path === '/login.aspx' && $request->method() === 'POST') {
                if (($request['ctl00$ContentPlaceHolder1$tbpassword'] ?? '') !== 'dobre-haslo'
                    || ($request['__VIEWSTATE'] ?? '') !== 'VS-SYNTETYCZNY'
                    || ($request['__EVENTVALIDATION'] ?? '') !== 'EV-SYNTETYCZNY') {
                    return Http::response($this->fixture('login.html'));
                }
                $this->logins++;
                $session = 'auth'.$this->logins;
                $this->validSessions[] = $session;

                return Http::response('', 302, [
                    'Location' => 'https://www.jspsafety.com/',
                    'Set-Cookie' => '.ASPXAUTH='.$session.'; path=/; HttpOnly; Secure',
                ]);
            }
            if ($path === '/') {
                return Http::response($this->withMarker($this->fixture('home.html'), $loggedIn));
            }
            if ($path === '/netalogue/sitemap.xml') {
                return Http::response($this->sitemap ?? $this->fixture('sitemap.xml'), 200, ['Content-Type' => 'text/xml']);
            }
            if (str_starts_with($path, '/products/kw/a/')) {
                if ($this->dropSessionOnce) {
                    $this->dropSessionOnce = false;
                    $this->validSessions = [];
                    $loggedIn = false;
                }
                $last = basename($path);
                $key = strtoupper(substr($last, 0, (int) strpos($last, '_')));
                if (! isset($this->pages[$key])) {
                    return Http::response('', 302, ['Location' => 'https://www.jspsafety.com/']);
                }

                return Http::response($this->withMarker($this->pages[$key], $loggedIn && ! $this->pagesWithoutMarker));
            }
            if (str_starts_with($path, '/netalogue/photos/')) {
                return Http::response(self::PNG, 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('nieznany adres w teście: '.$url, 404);
        });
    }

    private function withMarker(string $html, bool $loggedIn): string
    {
        return $loggedIn ? str_replace('<body>', '<body>'.self::LOGGED_IN_BAR, $html) : $html;
    }
}
