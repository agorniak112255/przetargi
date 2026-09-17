<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bDiscountRule;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductShopCard;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bRemoteShopField;
use App\Services\B2b\ProtektB2bClient;
use App\Services\B2b\ProtektB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Łącznik protekt.pl na atrapie witryny (Http::fake). Witryna jest publiczna — nie ma logowania,
 * a cena zakupu powstaje z ceny katalogowej i rabatu z konfiguracji konta.
 *
 * Znaczniki stron odwzorowują prawdziwą kartę Protektu odczytaną 16.09.2026 (mikrodane schema.org:
 * itemprop price / priceCurrency / gtin / gtin13 / sku, nazwa w h1.product-desc__name, stan magazynowy
 * w span.stan_*).
 */
final class ProtektConnectorTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> ścieżka karty => HTML; brak wpisu = 404 (martwy wpis w mapie) */
    private array $pages = [];

    /** @var list<string> ścieżki kart w mapach strony */
    private array $sitemapPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_karta_z_cena_dostaje_rabat_z_reguly(): void
    {
        $this->rule(1, 'Amortyzatory ABM+BW', B2bDiscountRule::TYPE_PREFIX, 'BW', 45.0);
        $this->page('/amortyzator~p21524~c5341', $this->card(
            name: 'BW140 - Amortyzator bezpieczeństwa bez zatrzaśnika',
            catalogNo: 'BW140',
            price: '76,00',
            ean: '5906800652997',
            stock: 'Na wyczerpaniu',
        ));
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(1, $result['created']);
        $product = Product::query()->where('sku', 'BW140')->firstOrFail();
        $this->assertSame('76.00', (string) $product->catalog_price_net);
        // 76,00 − 45% = 41,80
        $this->assertSame('41.80', (string) $product->purchase_price);
        $this->assertSame('45.00', (string) $product->discount_percent);
        // EAN jest odczytywany z karty (raw['ean']), ale synchronizacja B2B nie zapisuje EAN-u dla żadnego
        // łącznika — B2bRemoteProduct nie ma takiego pola. Do ustalenia osobno, poza tą zmianą.
        $this->assertNull($product->ean);
    }

    public function test_karta_bez_pasujacej_reguly_jest_pomijana_a_nie_zapisywana_w_cenie_katalogowej(): void
    {
        $this->rule(1, 'Amortyzatory', B2bDiscountRule::TYPE_PREFIX, 'BW', 45.0);
        $this->page('/helm~p1443~c5536', $this->card(
            name: 'Montana - Hełm przemysłowy',
            catalogNo: 'HA00801',
            price: '119,00',
        ));
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(0, $result['created']);
        $this->assertFalse(Product::query()->where('sku', 'HA00801')->exists());
        $this->assertTrue(
            collect($result['errors'])->contains(fn (string $e): bool => str_contains($e, 'brak reguły rabatowej')),
            'Powód pominięcia ma wprost mówić o braku reguły: '.implode(' | ', $result['errors']),
        );
    }

    public function test_karta_bez_ceny_jest_pomijana_bez_przerywania_przebiegu(): void
    {
        $this->rule(1, 'Wszystko', B2bDiscountRule::TYPE_ANY, '', 20.0);
        $this->page('/tasma~p100~c5341', $this->card(name: 'AF 610 - Taśma', catalogNo: 'AF610', price: null));
        $this->page('/amortyzator~p101~c5341', $this->card(name: 'BW140 - Amortyzator', catalogNo: 'BW140', price: '76,00'));
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(1, $result['created']);
        $this->assertContains('AF610: brak ceny w B2B', $result['errors'], implode(' | ', $result['errors']));
        $this->assertSame('ok', $this->account()->last_sync_status);
    }

    public function test_martwe_wpisy_w_mapie_nie_przerywaja_przebiegu(): void
    {
        // Licznik awarii klienta przerywa przebieg po 20 błędach pod rząd. Martwych wpisów w mapach
        // Protektu bywa więcej niż 20 z rzędu — nie mogą być liczone jako awaria witryny.
        $this->rule(1, 'Wszystko', B2bDiscountRule::TYPE_ANY, '', 20.0);
        for ($i = 1; $i <= 25; $i++) {
            $this->sitemapPaths[] = '/martwy-'.$i.'~p'.(900 + $i).'~c5341';
        }
        $this->page('/amortyzator~p101~c5341', $this->card(name: 'BW140 - Amortyzator', catalogNo: 'BW140', price: '76,00'));
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame('ok', $this->account()->last_sync_status);
        $this->assertSame(1, $result['created']);
        $this->assertSame(25, $result['skipped']);
    }

    public function test_karta_bez_numeru_katalogowego_jest_pomijana_z_powodem(): void
    {
        // Seria BW100SCF nie ma na stronie „Nr kat.” — bez numeru nie ma czym powiązać karty z katalogiem.
        $this->rule(1, 'Wszystko', B2bDiscountRule::TYPE_ANY, '', 20.0);
        $this->page('/amortyzator-scf~p200~c5342', $this->card(
            name: 'BW100SCF-T - Amortyzator bezpieczeństwa',
            catalogNo: null,
            price: '299,00',
        ));
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(0, $result['created']);
        $this->assertTrue(
            collect($result['errors'])->contains(fn (string $e): bool => str_contains($e, 'bez numeru katalogowego')),
            'Oczekiwano powodu o braku numeru katalogowego: '.implode(' | ', $result['errors']),
        );
    }

    public function test_reguly_sprawdzane_po_kolei_w_jednej_kategorii(): void
    {
        // Prawdziwy układ cennika: ROLEX 30%, CR200 10%, CR300 20% w kategorii „urządzenia samohamowne”.
        $this->rule(1, 'CR200', B2bDiscountRule::TYPE_PREFIX, 'CR200', 10.0);
        $this->rule(2, 'CR300', B2bDiscountRule::TYPE_PREFIX, 'CR300', 20.0);
        $this->rule(3, 'Samohamowne', B2bDiscountRule::TYPE_ANY, '', 30.0);
        $this->page('/cr200~p1~c5356', $this->card(name: 'CR 200', catalogNo: 'CR20010', price: '100,00'));
        $this->page('/cr300~p2~c5356', $this->card(name: 'CR 300', catalogNo: 'CR30018', price: '100,00'));
        $this->page('/rolex~p3~c5356', $this->card(name: 'ROLEX', catalogNo: 'RX10020', price: '100,00'));
        $this->fakeSite();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame('90.00', (string) Product::query()->where('sku', 'CR20010')->firstOrFail()->purchase_price);
        $this->assertSame('80.00', (string) Product::query()->where('sku', 'CR30018')->firstOrFail()->purchase_price);
        $this->assertSame('70.00', (string) Product::query()->where('sku', 'RX10020')->firstOrFail()->purchase_price);
    }

    public function test_kolory_trafiaja_na_jedna_karte_jako_lista_wersji(): void
    {
        // Protekt daje każdemu kolorowi osobny adres, ale ten sam numer katalogowy i tę samą cenę.
        // Ma z tego powstać jedna karta z listą kolorów, a nie pięć kart ani karta z losowym kolorem.
        $this->rule(1, 'Amortyzatory', B2bDiscountRule::TYPE_PREFIX, 'BW', 45.0);
        foreach (['czarny', 'czerwony', 'niebieski'] as $i => $colour) {
            $this->page('/amortyzator-'.$i.'~p'.(100 + $i).'~c5341', $this->card(
                name: 'BW140 - Amortyzator bezpieczeństwa',
                catalogNo: 'BW140',
                price: '76,00',
                colours: ['czarny', 'czerwony', 'niebieski'],
            ));
        }
        $this->fakeSite();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(1, Product::query()->where('sku', 'BW140')->count());
        $this->assertSame('czarny, czerwony, niebieski', Product::query()->where('sku', 'BW140')->value('variant_summary'));
        // Specyfikacja jest w tabelce karty wyrobu u dostawcy, nie w opisie — a wiersz „Kolor” wymienia
        // wszystkie kolory, bo karta obejmuje je wszystkie, nie kolor jednego adresu.
        $product = Product::query()->where('sku', 'BW140')->sole();
        $this->assertStringNotContainsString('Kolor: czarny, czerwony, niebieski', (string) $product->description);
        $this->assertContains(
            ['name' => 'Kolor', 'value' => 'czarny, czerwony, niebieski'],
            self::sectionRows(ProductShopCard::query()->where('product_id', $product->id)->sole(), 'Specyfikacja techniczna'),
        );
    }

    public function test_ten_sam_numer_z_rozna_cena_nie_jest_przemilczany(): void
    {
        // Gdyby Protekt kiedyś zróżnicował ceny kolorami, karta może mieć tylko jedną cenę.
        // Zapisujemy pierwszą i mówimy o tym w dzienniku, zamiast po cichu brać ostatnią.
        $this->rule(1, 'Amortyzatory', B2bDiscountRule::TYPE_PREFIX, 'BW', 45.0);
        $this->page('/amortyzator-a~p100~c5341', $this->card(name: 'BW140', catalogNo: 'BW140', price: '76,00'));
        $this->page('/amortyzator-b~p101~c5341', $this->card(name: 'BW140', catalogNo: 'BW140', price: '99,00'));
        $this->page('/amortyzator-c~p102~c5341', $this->card(name: 'BW140', catalogNo: 'BW140', price: '99,00'));
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame('76.00', (string) Product::query()->where('sku', 'BW140')->value('catalog_price_net'));
        $log = collect(B2bSyncRun::query()->findOrFail($result['sync_run_id'])->log ?? [])
            ->pluck('text')
            ->implode(' | ');
        $this->assertStringContainsString('różnymi cenami', $log, $log);
        $this->assertStringContainsString('BW140 (76,00 vs 99,00)', $log, $log);
        // Jeden wpis na numer katalogowy, choćby adresów z rozbieżną ceną było kilka.
        $this->assertSame(1, substr_count($log, 'BW140 (76,00'), $log);
    }

    public function test_ten_sam_numer_z_rozna_cena_i_kolorem_daje_osobne_karty(): void
    {
        // BW200/LB101HV: wersja bez koloru 102 zł, odblaskowe 138 zł. Karta ma jedną cenę, więc wersja
        // droższa musi być osobną kartą — inaczej wycena na odblaskowej byłaby zaniżona o ponad jedną trzecią.
        $this->rule(1, 'Amortyzatory', B2bDiscountRule::TYPE_PREFIX, 'BW', 45.0);
        $this->page('/hv-zwykly~p433~c5341', $this->card(name: 'ABM/LB101HV', catalogNo: 'BW200/LB101HV', price: '102,00'));
        $this->page('/hv-pomaranczowy~p434~c5341', $this->card(
            name: 'ABM/LB101HV', catalogNo: 'BW200/LB101HV', price: '138,00', colours: ['jaskrawy pomarańczowy'],
        ));
        $this->fakeSite();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame('102.00', (string) Product::query()->where('sku', 'BW200/LB101HV')->value('catalog_price_net'));
        $droga = Product::query()->where('sku', 'BW200/LB101HV / jaskrawy pomarańczowy')->first();
        $this->assertNotNull($droga, 'Wersja o innej cenie ma być osobną kartą z kolorem w kodzie.');
        $this->assertSame('138.00', (string) $droga->catalog_price_net);
        $this->assertSame('75.90', (string) $droga->purchase_price);
    }

    public function test_produkt_wycofany_trafia_do_katalogu_z_ostrzezeniem(): void
    {
        $this->rule(1, 'Amortyzatory', B2bDiscountRule::TYPE_PREFIX, 'BW', 45.0);
        $this->page('/wycofany~p370~c5341', $this->card(
            name: 'ABM/2LE111', catalogNo: 'BW200/2LE111', price: '259,00', withdrawn: 'BW100/2LE111',
        ));
        $this->fakeSite();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $product = Product::query()->where('sku', 'BW200/2LE111')->sole();
        $description = (string) $product->description;
        $this->assertStringContainsString('produkt wycofany przez producenta', $description);
        $this->assertStringContainsString('zastąpiony przez BW100/2LE111', $description);
        // Proza zostaje w opisie, pary „nazwa → wartość” tylko w tabelce karty wyrobu u dostawcy.
        $this->assertStringContainsString('Cechy szczególne:', $description);
        $this->assertStringNotContainsString('Normy: EN 355', $description);
        $this->assertStringNotContainsString('Specyfikacja techniczna:', $description);
        $this->assertStringNotContainsString('Materiał: poliester/poliamid', $description);

        $card = ProductShopCard::query()->where('product_id', $product->id)->sole();
        $this->assertSame([['name' => 'Norma', 'value' => 'EN 355']], self::sectionRows($card, 'Normy'));
        $this->assertSame([
            ['name' => 'Materiał', 'value' => 'poliester/poliamid'],
            ['name' => 'Waga', 'value' => '300 g'],
        ], self::sectionRows($card, 'Specyfikacja techniczna'));
    }

    public function test_pliki_do_pobrania_trafiaja_przy_karte(): void
    {
        // Deklaracje zgodności to dokument, którego żądają specyfikacje przetargowe — musi być przy karcie
        // razem z adresem źródła, żeby dało się go sprawdzić u producenta.
        Storage::fake('public');
        $this->rule(1, 'Amortyzatory', B2bDiscountRule::TYPE_PREFIX, 'BW', 45.0);
        $this->page('/z-plikami~p370~c5341', $this->card(
            name: 'ABM/2LE111', catalogNo: 'BW200/2LE111', price: '259,00', documents: true,
        ));
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $product = Product::query()->where('sku', 'BW200/2LE111')->firstOrFail();
        $documents = ProductDocument::query()->where('product_id', $product->id)->get();
        $log = collect(B2bSyncRun::query()->findOrFail($result['sync_run_id'])->log ?? [])->pluck('text')->implode(' | ');
        $this->assertNotEmpty($documents, 'Brak plików. Dziennik: '.$log.' || Błędy: '.implode(' | ', $result['errors']));

        $this->assertSame(
            ['Deklaracje zgodności - PL', 'Instrukcja użytkownika - PL', 'Karta produktowa'],
            $documents->pluck('title')->sort()->values()->all(),
            $log,
        );
        $this->assertSame(
            ProductDocument::KIND_CERTIFICATE,
            $documents->firstWhere('title', 'Deklaracje zgodności - PL')?->kind,
        );
        $this->assertSame(
            ProductDocument::KIND_DATASHEET,
            $documents->firstWhere('title', 'Karta produktowa')?->kind,
        );
    }

    public function test_liczniki_trafien_regul_trafiaja_do_konfiguracji(): void
    {
        $matched = $this->rule(1, 'Amortyzatory', B2bDiscountRule::TYPE_PREFIX, 'BW', 45.0);
        $empty = $this->rule(2, 'Hełmy', B2bDiscountRule::TYPE_PREFIX, 'HA008', 15.0);
        $this->page('/amortyzator~p101~c5341', $this->card(name: 'BW140 - Amortyzator', catalogNo: 'BW140', price: '76,00'));
        $this->fakeSite();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(1, $matched->refresh()->last_matched_count);
        $this->assertSame(0, $empty->refresh()->last_matched_count);
    }

    public function test_karta_wyrobu_u_dostawcy_ma_dane_handlowe_normy_i_specyfikacje_z_podzespolami(): void
    {
        $this->page('/lonza~p21524~c5341', $this->card(
            name: 'ABM/2LE111 - Lonża bezpieczeństwa',
            catalogNo: 'BW200/2LE111',
            price: '259,00',
            ean: '5906800652997',
            stock: 'Na wyczerpaniu',
            index: 'AX 011',
            subassemblies: true,
        ));
        $this->fakeSite();

        $connector = ProtektB2bConnector::forAccount($this->account(), 0);
        $remote = iterator_to_array($connector->products(), false);

        $this->assertSame([
            ['Informacje handlowe', 'Nr katalogowy', 'BW200/2LE111'],
            ['Informacje handlowe', 'Indeks producenta', 'AX 011'],
            ['Informacje handlowe', 'EAN', '5906800652997'],
            ['Informacje handlowe', 'Dostępność', 'Na wyczerpaniu'],
            ['Normy', 'Norma', 'EN 355'],
            ['Specyfikacja techniczna', 'Materiał', 'poliester/poliamid'],
            ['Specyfikacja techniczna', 'Waga', '300 g'],
            // Powtarzalna etykieta pod dwoma nagłówkami podzespołów: dwa wiersze w dwóch sekcjach.
            ['Lonża', 'Materiał', 'taśma poliestrowa'],
            ['Zatrzaśnik', 'Materiał', 'stal'],
        ], self::rows($connector->shopFields($remote[0])));
    }

    public function test_karta_wyrobu_podaje_numer_katalogowy_ze_strony_a_nie_kod_z_dopiskiem_koloru(): void
    {
        // Wersja o innej cenie dostaje kod z dopiskiem koloru (nasz), ale w tabelce ma stać numer
        // katalogowy dosłownie ze strony Protektu. Karta pominięta (martwy wpis) nie ma wierszy.
        $this->page('/hv-zwykly~p433~c5341', $this->card(name: 'ABM/LB101HV', catalogNo: 'BW200/LB101HV', price: '102,00'));
        $this->page('/hv-pomaranczowy~p434~c5341', $this->card(
            name: 'ABM/LB101HV', catalogNo: 'BW200/LB101HV', price: '138,00', colours: ['jaskrawy pomarańczowy'],
        ));
        $this->sitemapPaths[] = '/martwy~p999~c5341';
        $this->fakeSite();

        $connector = ProtektB2bConnector::forAccount($this->account(), 0);
        $remote = iterator_to_array($connector->products(), false);

        $this->assertSame('BW200/LB101HV / jaskrawy pomarańczowy', $remote[1]->sku);
        $this->assertContains(
            ['Informacje handlowe', 'Nr katalogowy', 'BW200/LB101HV'],
            self::rows($connector->shopFields($remote[1])),
        );
        $this->assertSame([], $connector->shopFields($remote[2]));
    }

    /**
     * Wiersze karty wyrobu jako proste trójki — czytelniej porównać niż obiekty.
     *
     * @param  list<B2bRemoteShopField>  $fields
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function rows(array $fields): array
    {
        return array_map(
            static fn (B2bRemoteShopField $field): array => [$field->section, $field->name, $field->value],
            $fields,
        );
    }

    /**
     * Wiersze jednej sekcji zapisanej karty wyrobu u dostawcy.
     *
     * @return list<array{name: string, value: string}>
     */
    private static function sectionRows(ProductShopCard $card, string $section): array
    {
        foreach ((array) $card->fields as $entry) {
            if (($entry['section'] ?? null) === $section) {
                return $entry['rows'];
            }
        }

        return [];
    }

    /**
     * @param  list<string>  $colours  wersje kolorystyczne karty (każda ma u Protektu osobny adres,
     *                                 ten sam numer katalogowy i tę samą cenę)
     */
    private function card(string $name, ?string $catalogNo, ?string $price, ?string $ean = null, ?string $stock = null, array $colours = [], ?string $withdrawn = null, bool $documents = false, ?string $index = null, bool $subassemblies = false): string
    {
        $html = '<!DOCTYPE html><html><body>'
            .($withdrawn !== null
                ? '<div class="single-product-label">Wycofany</div>'
                .'<div class="replaces-box replaces-box_detail">zastąpiony przez <a href="/x">'.$withdrawn.'</a></div>'
                : '')
            .'<div class="single-products-content__dsc"><div class="product-desc">'
            .'<h1 itemprop="name" class="product-desc__name">'.$name.'</h1>'
            .'<div class="product-desc__norms"><p>Normy</p><p class="product-desc__norms--bold">EN 355</p></div>'
            .'<div class="product-desc__cost"><div itemprop="offers" itemscope itemtype="https://schema.org/Offer">';

        if ($price !== null) {
            $html .= '<p class="product-desc__cost--netto">Cena netto:</p>'
                .'<p class="product-desc__cost--pln"><span class="product-price" itemprop="price">'.$price.'</span>'
                .'<span itemprop="priceCurrency">PLN</span><span>/szt. </span></p>';
        } else {
            $html .= '<p class="product-desc__cost--netto">Cena netto:</p>';
        }
        $html .= '</div></div>';

        if ($catalogNo !== null) {
            $html .= '<div class="product-desc__cat product-desc__catnum left-contain"><p>Nr kat.:</p>'
                .'<p itemprop="gtin">'.$catalogNo.'</p></div>';
        }
        if ($ean !== null) {
            $html .= '<div class="product-desc__cat product-desc__catnum right-contain"><p>EAN:</p>'
                .'<p itemprop="gtin13">'.$ean.'</p></div>';
        }
        if ($index !== null) {
            $html .= '<div class="product-desc__cat product-desc__catnum left-contain"><p>Indeks:</p>'
                .'<p itemprop="sku">'.$index.'</p></div>';
        }
        if ($stock !== null) {
            $html .= '<div class="container-data-codes inventory"><div class="product-desc__cat left-contain">'
                .'<p>Stan magazynowy:</p><p><span class="stan_niski">'.$stock.'</span></p></div></div>';
        }

        if ($colours !== []) {
            $html .= '<div class="product-desc__sizes"><div class="row"><h4>Kolor </h4><div class="col-data">'
                .'<ul class="variants-grid variants-grid-color">';
            foreach ($colours as $colour) {
                $html .= '<li class="variant color"><a href="/x~p1~c1">'
                    .'<div class="color" style="background-color: #000000"></div>'
                    .'<p class="color--warn">'.$colour.'</p></a></li>';
            }
            $html .= '</ul></div></div></div>';
        }

        $html .= '</div></div>'
            .'<div class="product-spec"><div class="spec-tech"><div class="spec-tech__info"><div class="spec-col">'
            .'<div class="spec-col__row"><p class="spec-col__type">Materiał:</p><p class="spec-col__type_val">poliester/poliamid</p></div>'
            .'<div class="spec-col__row"><p class="spec-col__type">Waga:</p><p class="spec-col__type_val">300 g</p></div>'
            .($colours !== [] ? '<div class="spec-col__row"><p class="spec-col__type">Kolor:</p><p class="spec-col__type_val">'.$colours[0].'</p></div>' : '')
            // Karty zestawów mają specyfikację rozbitą na podzespoły — te same etykiety powtarzają się
            // pod różnymi nagłówkami („Materiał” lonży i zatrzaśnika to dwie różne cechy).
            .($subassemblies
                ? '<div class="spec-col__row"><p class="spec-col__product">Lonża</p></div>'
                .'<div class="spec-col__row"><p class="spec-col__type">Materiał:</p><p class="spec-col__type_val">taśma poliestrowa</p></div>'
                .'<div class="spec-col__row"><p class="spec-col__product">Zatrzaśnik</p></div>'
                .'<div class="spec-col__row"><p class="spec-col__type">Materiał:</p><p class="spec-col__type_val">stal</p></div>'
                : '')
            .'</div></div></div></div>'
            .'<div class="product-desc__specific"><p class="product-desc__specific--warn">Dopuszczone do prac w strefach zagrożonych wybuchem</p></div>'
            .($documents
                ? '<div class="spec-tech__docs"><h4>Do pobrania</h4>'
                .'<a href="/robokat/datasheet/product?id=354&amp;pdf=true&amp;lang=pl">Karta produktowa</a>'
                .'<a href="/instrukcje/ABM/ABM_Instrukcja_PL.pdf">Instrukcja użytkownika - PL</a>'
                .'<a href="/deklaracje/PL/ABM_2LE111_Deklaracja_PL.pdf">Deklaracje zgodności - PL</a>'
                .'</div>'
                : '')
            .'</body></html>';

        return $html;
    }

    private function page(string $path, string $html): void
    {
        $this->pages[$path] = $html;
        $this->sitemapPaths[] = $path;
    }

    private function fakeSite(): void
    {
        $paths = $this->sitemapPaths;
        $pages = $this->pages;

        Http::fake(function (Request $request) use ($paths, $pages) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);

            if (str_contains($path, '/sitemap/pl/sitemap.categories_')) {
                return Http::response(self::urlset([
                    ProtektB2bClient::BASE.'/amortyzatory-bezpieczenstwa-z-linka~c5341',
                    ProtektB2bClient::BASE.'/urzadzenia-samohamowne~c5356',
                    ProtektB2bClient::BASE.'/helmy-przemyslowe~c5536',
                ]));
            }

            if (str_contains($path, '/sitemap/pl/sitemap.products_')) {
                // Cała lista w pierwszej mapie działu; pozostałe działy puste, jak przy wąskim asortymencie.
                $first = str_contains($path, 'indywidualny-sprzet-ochrony-osobistej');

                return Http::response(self::urlset($first
                    ? array_map(static fn (string $p): string => ProtektB2bClient::BASE.$p, $paths)
                    : []));
            }

            if (isset($pages[$path])) {
                return Http::response($pages[$path]);
            }

            // pliki z sekcji „Do pobrania”
            if (str_ends_with($path, '.pdf') || str_contains($path, '/robokat/datasheet/')) {
                // każdy plik musi mieć inną treść — zapis rozpoznaje identyczne pliki jako ten sam
                return Http::response('%PDF-1.4 '.$path, 200, ['Content-Type' => 'application/pdf']);
            }

            return Http::response('Nie znaleziono', 404);
        });
    }

    /**
     * @param  list<string>  $urls
     */
    private static function urlset(array $urls): string
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            $body .= '<url><loc>'.htmlspecialchars($url, ENT_XML1).'</loc></url>';
        }

        return $body.'</urlset>';
    }

    private function rule(int $position, string $name, string $type, string $pattern, float $discount): B2bDiscountRule
    {
        return B2bDiscountRule::query()->create([
            'b2b_account_id' => $this->account()->id,
            'position' => $position,
            'name' => $name,
            'match_field' => B2bDiscountRule::FIELD_CATALOG_NO,
            'match_type' => $type,
            'pattern' => $pattern,
            'discount_percent' => $discount,
        ]);
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => 'PROTEKT'],
            ['sites' => ['protekt.pl'], 'connector' => 'protekt'],
        )->fresh();
    }
}
