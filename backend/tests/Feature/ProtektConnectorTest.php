<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bDiscountRule;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\ProductVariantPriceHistory;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRemoteShopField;
use App\Services\B2b\B2bSizePriceSource;
use App\Services\B2b\ProtektB2bClient;
use App\Services\B2b\ProtektB2bConnector;
use App\Support\ManufacturerNormFacts;
use App\Support\WithdrawnProductNote;
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

    /**
     * Strona P-50mX odczytana 24.09.2026: każdy rozmiar pod osobnym adresem, numer AB 150 21 wspólny. Karta z nazwą
     * „… - rozmiar S” obejmowała wszystkie rozmiary, a zapytanie „rozmiar M-XL” dostawało ostrzeżenie. Od 28.09.2026
     * (rozmiary jako pozycje karty) każdy rozmiar ma swoje powiązanie z nazwą swojej podstrony dosłownie; pozycja
     * wiodąca (pierwszy rozmiar) ma kod karty — jak powiązanie sprzed zmiany.
     */
    public function test_rozmiary_jednego_numeru_daja_karte_z_nazwa_bez_rozmiaru(): void
    {
        $this->rule(1, 'Szelki', B2bDiscountRule::TYPE_PREFIX, 'AB', 40.0);
        $sizes = ['S' => '/szelki-bezpieczenstwa-0~p8553~c40', 'M - XL' => '/szelki-bezpieczenstwa-1~p8554~c40', 'XXL' => '/szelki-bezpieczenstwa-2~p8555~c40'];
        foreach ($sizes as $size => $path) {
            $this->page($path, $this->card(
                name: 'P-50mX - Szelki bezpieczeństwa - rozmiar '.$size,
                catalogNo: 'AB 150 21',
                price: '300,00',
                variants: ['Rozmiar' => $sizes],
            ));
        }
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(1, $result['created'], implode(' | ', $result['errors']));
        $product = Product::query()->where('sku', 'AB 150 21')->sole();
        $this->assertSame('P-50mX - Szelki bezpieczeństwa', $product->name);
        // nazwa ze źródła zostaje dosłownie w powiązaniu — każdy rozmiar ze swojej podstrony
        $this->assertSame(
            [
                'AB 150 21' => 'P-50mX - Szelki bezpieczeństwa - rozmiar S',
                'AB 150 21 ~p8554' => 'P-50mX - Szelki bezpieczeństwa - rozmiar M - XL',
                'AB 150 21 ~p8555' => 'P-50mX - Szelki bezpieczeństwa - rozmiar XXL',
            ],
            B2bProductLink::query()->where('product_id', $product->id)->orderBy('remote_id')->pluck('remote_name', 'remote_id')->all(),
        );
        $this->assertSame('Rozmiary: S; M - XL; XXL', $product->variant_summary);

        // istniejącej karty synchronizacja nie przemianowuje (decyzja użytkownika 15.09.2026)
        $product->update(['name' => 'Szelki P-50mX (nazwa handlowca)']);
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);
        $this->assertSame('Szelki P-50mX (nazwa handlowca)', $product->fresh()->name);
    }

    public function test_nazwa_karty_bez_rozmiaru_z_kazdego_zapisu_na_stronie(): void
    {
        $cases = [
            'P-50mX - Szelki bezpieczeństwa - rozmiar S' => 'P-50mX - Szelki bezpieczeństwa',
            'P-01 - Szelki bezpieczeństwa - rozmiar M - XL' => 'P-01 - Szelki bezpieczeństwa',
            'P-04 - Kamizelka szelkowa - kolor , rozmiar M' => 'P-04 - Kamizelka szelkowa - kolor',
            'VS 020 - Kamizelka do szelek bezpieczeństwa - rozmiar M - XL, kolor' => 'VS 020 - Kamizelka do szelek bezpieczeństwa, kolor',
            'PB 31 - Pas do pracy w podparciu - roz. M-XL' => 'PB 31 - Pas do pracy w podparciu',
            'LIFTER - Zestaw do prac głębokościowych - rozmiar szelek M-XL' => 'LIFTER - Zestaw do prac głębokościowych',
            'P-600 - Szelki bezpieczeństwa z pasem do pracy w podparciu - rozmiar XXXL' => 'P-600 - Szelki bezpieczeństwa z pasem do pracy w podparciu',
            'P-51E - Szelki bezpieczeństwa z taśmami elastycznymi - rozmiar' => 'P-51E - Szelki bezpieczeństwa z taśmami elastycznymi',
            'PB 66 - Pas bojowy strażacki z linką bezpieczeństwa - roz.' => 'PB 66 - Pas bojowy strażacki z linką bezpieczeństwa',
        ];
        foreach ($cases as $name => $expected) {
            $this->assertSame($expected, ProtektB2bConnector::cardNameWithoutSize($name), $name);
        }
        // bez rozmiaru literowego nazwa zostaje: „szelek” to nie rozmiar S, liczba to cecha wyrobu
        $this->assertNull(ProtektB2bConnector::cardNameWithoutSize('BW140 - Amortyzator bezpieczeństwa'));
        $this->assertNull(ProtektB2bConnector::cardNameWithoutSize('Kamizelka do szelek - rozmiary uniwersalne'));
        $this->assertNull(ProtektB2bConnector::cardNameWithoutSize('KASK - Hełm - rozmiar 52-63'));
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

    /**
     * Decyzja użytkownika 28.09.2026: rozmiary w różnych cenach to jedna karta. Szelki P-61C (strona 28.09.2026):
     * M - XL 289 zł, XXL 320 zł pod jednym numerem AB16103 — do 28.09.2026 karta brała pierwszą cenę, a droższy rozmiar
     * znikał w dzienniku („zapisano pierwszą”). Teraz: jedna karta, pozycje = rozmiary z ceną każdego (cena katalogowa
     * ze strony rozmiaru, rabat z reguły), cena karty = najniższa, wiersze rozmiarów na karcie; drugi przebieg bez zmian.
     */
    public function test_rozmiary_w_roznych_cenach_to_jedna_karta_z_cena_najtanszego_rozmiaru(): void
    {
        $this->rule(1, 'Szelki', B2bDiscountRule::TYPE_PREFIX, 'AB', 40.0);
        $sizes = ['M - XL' => '/szelki-bezpieczenstwa~p845~c40', 'XXL' => '/szelki-bezpieczenstwa~p846~c40', 'S' => '/szelki-bezpieczenstwa~p847~c40', 'XXXL' => '/szelki-bezpieczenstwa~p848~c40'];
        // XXXL bez ceny („zapytaj o dostępność”) — poza kartą, w podsumowaniu; jego EAN zostaje przy pozycji karty
        foreach (['M - XL' => '289,00', 'XXL' => '320,00', 'S' => '320,00', 'XXXL' => null] as $size => $price) {
            $this->page($sizes[$size], $this->card(
                name: 'P-61C - Szelki bezpieczeństwa - rozmiar '.$size,
                catalogNo: 'AB16103',
                price: $price,
                ean: '59068006'.['M - XL' => '10001', 'XXL' => '10002', 'S' => '10003', 'XXXL' => '10004'][$size],
                stock: $size === 'XXL' ? 'Niski' : 'Wysoki',
                variants: ['Rozmiar' => $sizes],
                unit: '',
            ));
        }
        $this->fakeSite();

        $connector = ProtektB2bConnector::forAccount($this->account(), 0);
        $this->assertInstanceOf(B2bSizePriceSource::class, $connector);
        $remote = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $remote);
        $this->assertSame(['AB16103', 'AB16103', 'P-61C - Szelki bezpieczeństwa'], [$remote[0]->remoteId, $remote[0]->sku, $remote[0]->cardName]);
        $this->assertSame(
            [
                ['AB16103', 'AB16103', 'M - XL', 'Wysoki', 173.4, 289.0, 'PLN'],
                ['AB16103 ~p846', 'AB16103', 'XXL', 'Niski', 192.0, 320.0, 'PLN'],
                ['AB16103 ~p847', 'AB16103', 'S', 'Wysoki', 192.0, 320.0, 'PLN'],
            ],
            array_map(static fn (array $m): array => [
                $m['remote_id'], $m['sku'], $m['size'], $m['availability'], $m['price']->net, $m['price']->base, $m['price']->currency,
            ], $remote[0]->members),
        );
        // price() = najtańszy rozmiar (net i jego cena katalogowa)
        $price = $connector->price($remote[0]);
        $this->assertSame([173.4, 289.0, 40.0], [$price?->net, $price?->base, $price?->discountPercent]);
        $this->assertSame('Wysoki: M - XL, S; Niski: XXL', $remote[0]->availability);
        $this->assertSame('Rozmiary: M - XL; XXL; S', $remote[0]->variantSummary);
        // EAN każdego rozmiaru na pozycji swojego rozmiaru
        $this->assertSame([
            ['manufacturer_code', 'AB16103', 'AB16103', null, 'Nr katalogowy'],
            ['ean', '5906800610001', 'AB16103', 'M - XL', 'EAN'],
            ['ean', '5906800610002', 'AB16103 ~p846', 'XXL', 'EAN'],
            ['ean', '5906800610003', 'AB16103 ~p847', 'S', 'EAN'],
            ['ean', '5906800610004', 'AB16103', 'XXXL', 'EAN'],
        ], self::identifierRows($remote[0]));
        $this->assertSame(1, $connector->totalProducts());
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('1 wyrobów z rozmiarami w różnych cenach — jedna karta, cena karty = najniższa', $summary);
        $this->assertStringContainsString('Rozmiary bez ceny (poza kartą): 1, np. AB16103 XXXL', $summary);
        $this->assertStringNotContainsString('różnymi cenami na różnych adresach', $summary);

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(1, $result['created'], implode(' | ', $result['errors']));
        $card = Product::query()->where('sku', 'AB16103')->sole();
        $this->assertSame('P-61C - Szelki bezpieczeństwa', $card->name);
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        $this->assertSame(['173.40', '289.00', '192.00'], [(string) $slot->purchase_price, (string) $slot->catalog_price_net, (string) $slot->size_price_max]);
        $sizeRows = static fn (): array => ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)
            ->orderBy('sort_order')->get()
            ->map(static fn (ProductVariant $v): array => [$v->remote_id, $v->label, (string) $v->purchase_price, (string) $v->list_price_net, $v->availability])
            ->all();
        $this->assertSame([
            ['AB16103', 'M - XL', '173.40', '289.00', 'Wysoki'],
            ['AB16103 ~p846', 'XXL', '192.00', '320.00', 'Niski'],
            ['AB16103 ~p847', 'S', '192.00', '320.00', 'Wysoki'],
        ], $sizeRows());
        $this->assertSame(
            ['AB16103' => '173.40', 'AB16103 ~p846' => '192.00', 'AB16103 ~p847' => '192.00'],
            B2bProductLink::query()->where('product_id', $card->id)->orderBy('remote_id')->pluck('last_purchase_price', 'remote_id')
                ->map(static fn ($p): string => (string) $p)->all(),
        );

        $history = ProductVariantPriceHistory::query()->count();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame([0, 0, 1], [$second['created'], $second['updated'], $second['unchanged']], implode(' | ', $second['errors']));
        $this->assertSame($history, ProductVariantPriceHistory::query()->count());
        $this->assertCount(3, $sizeRows());
    }

    /**
     * Linki LB 101 (strona 28.09.2026): długości 1.4 / 1.6 / 2 m pod jednym numerem LB101/2AZ002 za 72 / 73 / 85 zł.
     * Do 28.09.2026 karta „… - dł. 1.4 m” obejmowała wszystkie długości w cenie najkrótszej, a druga cena tworzyła kartę
     * „LB101/2AZ002 / biały” (jedyny kolor linki). Długość z siatki „Długość [m]” to rozmiar karty: jedna karta
     * z nazwą bez długości, rozmiary „Długość [m]: 1.4” dosłownie ze strony; bez karty „numer / kolor”.
     */
    public function test_dlugosci_linki_to_rozmiary_jednej_karty_z_nazwa_bez_dlugosci(): void
    {
        $this->rule(1, 'Linki', B2bDiscountRule::TYPE_PREFIX, 'LB', 45.0);
        $lengths = ['1.4' => '/linka~p4277~c5343', '1.6' => '/linka~p4278~c5343', '2' => '/linka~p4280~c5343'];
        foreach (['1.4' => '72,00', '1.6' => '73,00', '2' => '85,00'] as $length => $price) {
            $this->page($lengths[$length], $this->card(
                name: 'LB 101 - Linka bezpieczeństwa z zatrzaśnikami AZ002, AZ002 - dł. '.$length.' m',
                catalogNo: 'LB101/2AZ002',
                price: $price,
                colours: ['biały'],
                variants: ['Długość [m]' => $lengths],
                specRows: ['Długość' => str_replace('.', ',', (string) $length).' m'],
            ));
        }
        $this->fakeSite();

        $connector = ProtektB2bConnector::forAccount($this->account(), 0);
        $remote = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $remote);
        $this->assertSame('LB 101 - Linka bezpieczeństwa z zatrzaśnikami AZ002, AZ002', $remote[0]->cardName);
        $this->assertSame(
            [['LB101/2AZ002', 'Długość [m]: 1.4', 39.6], ['LB101/2AZ002 ~p4278', 'Długość [m]: 1.6', 40.15], ['LB101/2AZ002 ~p4280', 'Długość [m]: 2', 46.75]],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['size'], $m['price']->net], $remote[0]->members),
        );
        $this->assertSame('biały; Rozmiary: Długość [m]: 1.4; Długość [m]: 1.6; Długość [m]: 2', $remote[0]->variantSummary);
        // tabelka karty obejmującej wszystkie długości podaje wszystkie długości, nie długość podstrony wiodącej
        $this->assertSame([
            ['Specyfikacja techniczna', 'Materiał', 'poliester/poliamid'],
            ['Specyfikacja techniczna', 'Waga', '300 g'],
            ['Specyfikacja techniczna', 'Długość', '1,4 m; 1,6 m; 2 m'],
            ['Specyfikacja techniczna', 'Kolor', 'biały'],
        ], array_values(array_filter(self::rows($connector->shopFields($remote[0])), static fn (array $r): bool => $r[0] === 'Specyfikacja techniczna')));
        $this->assertStringNotContainsString('różnymi cenami', implode("\n", $connector->runSummary()));

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(1, $result['created'], implode(' | ', $result['errors']));
        $this->assertSame(['LB101/2AZ002'], Product::query()->pluck('sku')->all());
        $this->assertSame('39.60', (string) Product::query()->sole()->purchase_price);
    }

    /**
     * Protekt bywa, że daje jeden numer dwóm wyrobom (AF 600 z zatrzaśnikami za 155 zł i bez za 145 zł — siatka
     * „Wersja”; AB 159 21 z dwoma modelami szelek). Wyrób w innej wersji nie jest rozmiarem karty: bez koloru nie ma
     * osobnej karty, zostaje przy cenie karty jak dotąd, a rozbieżność idzie do podsumowania.
     */
    public function test_inna_wersja_pod_tym_samym_numerem_nie_jest_rozmiarem_karty(): void
    {
        $this->rule(1, 'Wszystko', B2bDiscountRule::TYPE_ANY, '', 20.0);
        $with = '/tasma~p7268~c5341';
        $without = '/tasma~p7269~c5341';
        $withoutLong = '/tasma~p7271~c5341';
        $this->page($with, $this->card(name: 'AF 600 - Taśma - dł. 2.5 m', catalogNo: 'AF600', price: '155,00', variants: [
            'Wersja' => ['z zatrzaśnikami' => $with, 'bez zatrzaśników' => $without],
            'Długość [m]' => ['2.5' => $with, '3' => '/tasma~p7270~c5341'],
        ]));
        $this->page($without, $this->card(name: 'AF 600 - Taśma - dł. 2.5 m', catalogNo: 'AF600', price: '145,00', variants: [
            'Wersja' => ['z zatrzaśnikami' => $with, 'bez zatrzaśników' => $without],
            'Długość [m]' => ['2.5' => $without, '3' => $withoutLong],
        ]));
        $this->page($withoutLong, $this->card(name: 'AF 600 - Taśma - dł. 3 m', catalogNo: 'AF600', price: '40,00', variants: [
            'Wersja' => ['z zatrzaśnikami' => '/tasma~p7270~c5341', 'bez zatrzaśników' => $withoutLong],
            'Długość [m]' => ['2.5' => $without, '3' => $withoutLong],
        ]));
        $this->fakeSite();

        $connector = ProtektB2bConnector::forAccount($this->account(), 0);
        $remote = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $remote);
        $this->assertSame([], $remote[0]->members);
        $this->assertSame(124.0, $connector->price($remote[0])?->net);
        $this->assertStringContainsString('AF600 (155,00 vs 145,00)', implode("\n", $connector->runSummary()));
    }

    /**
     * Waluta zostaje w kluczu wyrobu (rozmiary jednej karty mają jedną walutę), jednostka nie: Protekt pisze ją
     * niekonsekwentnie na podstronach tego samego wyrobu w tej samej cenie (CR 255V 28.09.2026: czarny „/szt.”,
     * niebieski „/komplet”). Podstrona w innej walucie nie wchodzi do karty i trafia do podsumowania.
     */
    public function test_waluta_rozdziela_podstrony_a_jednostka_nie(): void
    {
        $this->rule(1, 'Wszystko', B2bDiscountRule::TYPE_ANY, '', 10.0);
        $this->page('/cr255v~p5592~c5356', $this->card(name: 'CR 255V - kolor czarny', catalogNo: 'CR255V06', price: '720,00', colours: ['czarny', 'niebieski'], ownColour: 'czarny', unit: '/szt. '));
        $this->page('/cr255v~p5595~c5356', $this->card(name: 'CR 255V - kolor niebieski', catalogNo: 'CR255V06', price: '720,00', colours: ['czarny', 'niebieski'], ownColour: 'niebieski', unit: '/komplet '));
        $this->page('/cr255v~p5596~c5356', $this->card(name: 'CR 255V - kolor szary', catalogNo: 'CR255V06', price: '170,00', colours: ['czarny', 'niebieski'], ownColour: 'szary', currency: 'EUR'));
        $this->fakeSite();

        $connector = ProtektB2bConnector::forAccount($this->account(), 0);
        $remote = iterator_to_array($connector->products(), false);

        $this->assertSame([['CR255V06', 'czarny, niebieski', 'PLN']], array_map(
            static fn (B2bRemoteProduct $p): array => [$p->sku, $p->variantSummary, $p->raw['currency']],
            $remote,
        ));
        $this->assertStringContainsString('Podstrony numeru w innej walucie niż karta (pominięte): 1, np. CR255V06 (EUR)', implode("\n", $connector->runSummary()));
    }

    /**
     * Karty zapisane przed 28.09.2026: karta numeru (powiązanie remote_id = numer katalogowy, opis ze strony z odciskiem)
     * i karta „LB101/2AZ002 / biały”, którą dawny podział zakładał dla każdej innej ceny (długości 1.6 i 2 m, nie kolor).
     * Pierwszy i drugi przebieg po zmianie: bez nowej karty i bez przepinania; karta numeru znaleziona po swoim
     * powiązaniu, nazwa i opis bez zmian; długości z dawnej karty „/ biały” trafiają na nią po legacy_remote_id
     * (B2bCatalogSync::cardGroups), więc każda karta dostaje swoje rozmiary, a wyrób idzie do size_spread (scalenie
     * „Scal rozmiary” to decyzja człowieka).
     */
    public function test_drugi_przebieg_na_kartach_sprzed_zmiany_nie_zaklada_nowej_karty_i_nie_rusza_opisu(): void
    {
        Storage::fake('public');
        $this->rule(1, 'Linki', B2bDiscountRule::TYPE_PREFIX, 'LB', 45.0);
        $lengths = ['1.4' => '/linka~p4277~c5343', '1.6' => '/linka~p4278~c5343', '2' => '/linka~p4280~c5343'];
        foreach (['1.4' => '72,00', '1.6' => '73,00', '2' => '85,00'] as $length => $price) {
            $this->page($lengths[$length], $this->card(
                name: 'LB 101 - Linka bezpieczeństwa z zatrzaśnikami AZ002, AZ002 - dł. '.$length.' m',
                catalogNo: 'LB101/2AZ002',
                price: $price,
                colours: ['biały'],
                variants: ['Długość [m]' => $lengths],
            ));
        }
        $this->fakeSite();
        $account = $this->account();
        $description = "Cechy szczególne:\n- Dopuszczone do prac w strefach zagrożonych wybuchem";
        $main = $this->legacyCard($account, 'LB101/2AZ002', 'LB 101 - Linka bezpieczeństwa z zatrzaśnikami AZ002, AZ002 - dł. 1.4 m', $description, 72.0, 45.0);
        $split = $this->legacyCard($account, 'LB101/2AZ002 / biały', 'LB 101 - Linka bezpieczeństwa z zatrzaśnikami AZ002, AZ002 - dł. 1.6 m', $description, 73.0, 45.0);
        $cards = fn (): array => Product::query()->orderBy('id')->get()->map(static fn (Product $p): array => [
            $p->sku, $p->name, $p->description,
            B2bProductLink::query()->where('product_id', $p->id)->orderBy('remote_id')->pluck('remote_id')->all(),
            ProductVariant::query()->where('product_id', $p->id)->orderBy('sort_order')->get()
                ->map(static fn (ProductVariant $v): array => [$v->label, (string) $v->purchase_price])->all(),
            (string) ProductSourcePrice::query()->where('product_id', $p->id)->value('purchase_price'),
        ])->all();

        $first = app(B2bAccountSyncRunner::class)->run($account, delayMs: 0, withImages: false);

        $this->assertSame(0, $first['created'], implode(' | ', $first['errors']));
        $this->assertSame(0, $first['skipped'], implode(' | ', $first['errors']));
        $after = [
            [
                'LB101/2AZ002', 'LB 101 - Linka bezpieczeństwa z zatrzaśnikami AZ002, AZ002 - dł. 1.4 m', $description,
                ['LB101/2AZ002'],
                [['Długość [m]: 1.4', '39.60']],
                '39.60',
            ],
            [
                'LB101/2AZ002 / biały', 'LB 101 - Linka bezpieczeństwa z zatrzaśnikami AZ002, AZ002 - dł. 1.6 m', $description,
                // dawne powiązanie zostaje (do scalenia), rozmiary dostają swoje
                ['LB101/2AZ002 / biały', 'LB101/2AZ002 ~p4278', 'LB101/2AZ002 ~p4280'],
                [['Długość [m]: 1.6', '40.15'], ['Długość [m]: 2', '46.75']],
                '40.15',
            ],
        ];
        $this->assertSame($after, $cards());
        $spread = B2bSyncRun::query()->findOrFail($first['sync_run_id'])->size_spread;
        $this->assertSame(1, $spread['total']);
        $this->assertSame([$main->id, $split->id], $spread['groups'][0]['cards']);

        $second = app(B2bAccountSyncRunner::class)->run($account, delayMs: 0, withImages: false);

        $this->assertSame([0, 0], [$second['created'], $second['updated']], implode(' | ', $second['errors']));
        $this->assertSame($after, $cards());
        $this->assertSame(0, ProductVariant::query()->whereNotNull('removed_at')->count());
    }

    /**
     * Dawny łącznik (do 28.09.2026) zapisywał linkę LB 100 (1.4 / 1.6 / 1.9 / 2 m za 53 / 54 / 65 / 66 zł, jedyny kolor
     * „biały”) na dwóch kartach: pierwsza cena — „LB100”, każda inna — „LB100 / biały”. Rozmiar, który leżał na karcie
     * „/ biały”, niesie legacy_remote_id = ten dawny kod, żeby silnik znalazł tę kartę (size_spread, „Scal rozmiary”).
     * Pozycja wiodąca ma swój dawny kod jako remote_id, a rozmiar z dawnym kodem = kod karty nie potrzebuje wpisu.
     * Prawdziwy podział koloru w innej cenie zostaje kartą „numer / kolor” bez legacy_remote_id.
     */
    public function test_rozmiar_z_dawnej_karty_numer_kolor_niesie_jej_kod(): void
    {
        $this->rule(1, 'Linki', B2bDiscountRule::TYPE_PREFIX, 'LB', 45.0);
        $lengths = ['1.4' => '/linka~p4260~c5343', '1.6' => '/linka~p4261~c5343', '1.9' => '/linka~p4262~c5343', '2' => '/linka~p4263~c5343'];
        foreach (['1.4' => '53,00', '1.6' => '53,00', '1.9' => '65,00', '2' => '66,00'] as $length => $price) {
            $this->page($lengths[$length], $this->card(
                name: 'LB 100 - Linka bezpieczeństwa regulowana bez zatrzaśników - dł. '.$length.' m',
                catalogNo: 'LB100',
                price: $price,
                colours: ['biały'],
                variants: ['Długość [m]' => $lengths],
            ));
        }
        // kolor naprawdę w innej cenie (DS 242) — karta „numer / kolor” jak dotąd
        $this->page('/drabina~p1217~c5341', $this->card(name: 'DS 242 - Drabina', catalogNo: 'DS242', price: '649,00', colours: ['szary', 'czarny'], ownColour: 'szary'));
        $this->page('/drabina~p1218~c5341', $this->card(name: 'DS 242 - Drabina', catalogNo: 'DS242', price: '659,00', colours: ['szary', 'czarny'], ownColour: 'czarny'));
        $this->fakeSite();
        $account = $this->account();
        $this->legacyCard($account, 'LB100', 'LB 100 - Linka bezpieczeństwa regulowana bez zatrzaśników - dł. 1.4 m', '', 53.0, 45.0);
        $this->legacyCard($account, 'LB100 / biały', 'LB 100 - Linka bezpieczeństwa regulowana bez zatrzaśników - dł. 1.9 m', '', 65.0, 45.0);

        $remote = iterator_to_array(ProtektB2bConnector::forAccount($account, 0)->products(), false);

        $this->assertSame(['LB100', 'DS242', 'DS242 / czarny'], array_map(static fn (B2bRemoteProduct $p): string => $p->remoteId, $remote));
        $this->assertSame(
            [
                ['LB100', 'Długość [m]: 1.4', null],
                ['LB100 ~p4261', 'Długość [m]: 1.6', null],
                ['LB100 ~p4262', 'Długość [m]: 1.9', 'LB100 / biały'],
                ['LB100 ~p4263', 'Długość [m]: 2', 'LB100 / biały'],
            ],
            array_map(static fn (array $m): array => [$m['remote_id'], $m['size'], $m['legacy_remote_id'] ?? null], $remote[0]->members),
        );
        $this->assertSame([[], []], [$remote[1]->members, $remote[2]->members]);
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
        // Panel zapytania czyta wycofanie z tego opisu — łącznik i czytnik muszą mówić jednym formatem.
        $this->assertSame(['successor' => 'BW100/2LE111'], WithdrawnProductNote::parse($description));
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

    public function test_norma_z_tabelki_karty_trafia_do_norm_producenta(): void
    {
        // plan norm z 23.09.2026, etap 2: wiersz „Normy / Norma” to norma od samego producenta
        $this->rule(1, 'Amortyzatory', B2bDiscountRule::TYPE_PREFIX, 'BW', 45.0);
        $this->page('/wycofany~p370~c5341', $this->card(name: 'ABM/2LE111', catalogNo: 'BW200/2LE111', price: '259,00'));
        $this->fakeSite();

        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $column = Product::query()->where('sku', 'BW200/2LE111')->sole()->manufacturer_norms;
        $this->assertSame('protekt', $column['source']['connector'] ?? null);
        $this->assertSame([['label' => 'EN 355', 'value' => '']], ManufacturerNormFacts::rows($column));
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
        // numer katalogowy (itemprop gtin — to nie GTIN) i indeks jako kody producenta, EAN z gtin13
        $this->assertSame([
            ['manufacturer_code', 'BW200/2LE111', 'BW200/2LE111', null, 'Nr katalogowy'],
            ['manufacturer_code', 'AX 011', 'BW200/2LE111', null, 'Indeks producenta'],
            ['ean', '5906800652997', 'BW200/2LE111', null, 'EAN'],
        ], self::identifierRows($remote[0]));
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
        // identyfikator to numer ze strony, bez naszego dopisku koloru — na pozycji karty wersji droższej
        $this->assertSame(
            [['manufacturer_code', 'BW200/LB101HV', 'BW200/LB101HV', null, 'Nr katalogowy']],
            self::identifierRows($remote[0]),
        );
        $this->assertSame(
            [['manufacturer_code', 'BW200/LB101HV', 'BW200/LB101HV / jaskrawy pomarańczowy', null, 'Nr katalogowy']],
            self::identifierRows($remote[1]),
        );
        $this->assertNull($remote[2]->identifiers);
    }

    /**
     * Kolory jednego numeru to osobne adresy z własnym EAN i indeksem, a karta i jej pozycja są jedne; zniknięte
     * oznacza dopiero koniec pełnego przebiegu. Od 28.09.2026 łącznik czyta całą mapę przed pierwszym produktem, więc
     * kolory przychodzą jednym produktem z identyfikatorami wszystkich adresów (etykieta = kolor adresu).
     */
    public function test_identyfikatory_wszystkich_kolorow_trafiaja_na_jedna_karte_a_drugi_przebieg_ich_nie_dubluje(): void
    {
        $this->rule(1, 'Amortyzatory', B2bDiscountRule::TYPE_PREFIX, 'BW', 45.0);
        $colours = ['czarny', 'czerwony'];
        foreach ($colours as $i => $colour) {
            $this->page('/amortyzator-'.$i.'~p'.(100 + $i).'~c5341', $this->card(
                name: 'BW140 - Amortyzator bezpieczeństwa',
                catalogNo: 'BW140',
                price: '76,00',
                ean: '590680065299'.$i,
                index: 'AX 01'.$i,
                colours: $colours,
                ownColour: $colour,
            ));
        }
        $this->fakeSite();

        $remote = iterator_to_array(ProtektB2bConnector::forAccount($this->account(), 0)->products(), false);

        $this->assertCount(1, $remote);
        $this->assertSame([], $remote[0]->members);
        $this->assertSame([
            ['manufacturer_code', 'BW140', 'BW140', null, 'Nr katalogowy'],
            ['manufacturer_code', 'AX 010', 'BW140', 'czarny', 'Indeks producenta'],
            ['ean', '5906800652990', 'BW140', 'czarny', 'EAN'],
            ['manufacturer_code', 'AX 011', 'BW140', 'czerwony', 'Indeks producenta'],
            ['ean', '5906800652991', 'BW140', 'czerwony', 'EAN'],
        ], self::identifierRows($remote[0]));

        $expected = [
            ['BW140', 'ean', '5906800652990', 'czarny', 'EAN', 'PROTEKT'],
            ['BW140', 'ean', '5906800652991', 'czerwony', 'EAN', 'PROTEKT'],
            ['BW140', 'manufacturer_code', 'AX 010', 'czarny', 'Indeks producenta', 'PROTEKT'],
            ['BW140', 'manufacturer_code', 'AX 011', 'czerwony', 'Indeks producenta', 'PROTEKT'],
            ['BW140', 'manufacturer_code', 'BW140', null, 'Nr katalogowy', 'PROTEKT'],
        ];
        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(1, $result['created'], implode(' | ', $result['errors']));
        $product = Product::query()->where('sku', 'BW140')->sole();
        $this->assertSame($expected, self::storedIdentifiers($product->id));
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());

        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame($expected, self::storedIdentifiers($product->id));
        $this->assertSame(5, ProductIdentifier::query()->count());
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());
    }

    /**
     * Karta zapisana przez łącznik sprzed 28.09.2026: jedno powiązanie z remote_id = kod karty, opis ze strony z odciskiem
     * w powiązaniu, slot konta.
     */
    private function legacyCard(B2bAccount $account, string $sku, string $name, string $description, float $catalogPrice, float $discount): Product
    {
        $purchase = round($catalogPrice * (1 - $discount / 100), 2);
        $card = Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => 'PROTEKT', 'description' => $description,
            'catalog_price_net' => $catalogPrice, 'discount_percent' => $discount, 'purchase_price' => $purchase, 'currency' => 'PLN',
        ]);
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id, 'remote_id' => $sku, 'product_id' => $card->id,
            'remote_sku' => $sku, 'remote_name' => $name, 'manufacturer' => 'PROTEKT',
            'description_hash' => sha1($description), 'last_purchase_price' => $purchase, 'last_currency' => 'PLN',
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::b2bKey((int) $account->id), 'b2b_account_id' => $account->id,
            'catalog_price_net' => $catalogPrice, 'purchase_price' => $purchase, 'discount_percent' => $discount, 'currency' => 'PLN',
            'checked_at' => now()->subDay(),
        ]);

        return $card;
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

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string|null, 4: string|null, 5: string|null}>
     */
    private static function storedIdentifiers(int $productId): array
    {
        return ProductIdentifier::query()
            ->where('product_id', $productId)
            ->orderBy('position_key')->orderBy('type')->orderBy('value')
            ->get()
            ->map(static fn (ProductIdentifier $i): array => [
                $i->position_key, $i->type, $i->value, $i->variant_label, $i->source_field, $i->manufacturer,
            ])
            ->all();
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
     * @param  string|null  $ownColour  kolor tego adresu w wierszu „Kolor” specyfikacji; null = pierwszy z $colours
     * @param  array<string, array<string, string>>  $variants  siatki „Dostępne warianty” jak na stronie: nagłówek =>
     *                                                          [etykieta => adres podstrony]; wybór podstrony to pozycja
     *                                                          z jej własnym adresem (strona 28.09.2026: „Rozmiar”,
     *                                                          „Długość [m]”, „Wersja”)
     * @param  array<string, string>  $specRows  dodatkowe wiersze specyfikacji podstrony (etykieta => wartość)
     */
    private function card(string $name, ?string $catalogNo, ?string $price, ?string $ean = null, ?string $stock = null, array $colours = [], ?string $withdrawn = null, bool $documents = false, ?string $index = null, bool $subassemblies = false, ?string $ownColour = null, array $variants = [], string $currency = 'PLN', string $unit = '/szt. ', array $specRows = []): string
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
                .'<span itemprop="priceCurrency">'.$currency.'</span>'.($unit !== '' ? '<span>'.$unit.'</span>' : '').'</p>';
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

        foreach ($variants as $heading => $options) {
            // etykiety z twardymi spacjami jak na stronie („M&nbsp;-&nbsp;XL”)
            $html .= '<div class="product-desc__sizes"><div class="row"><h4>'.$heading.' </h4><div class="col-data">'
                .'<ul class="variants-grid ">';
            foreach ($options as $label => $path) {
                $html .= '<li class="variant"><a href="'.$path.'">'.str_replace(' ', '&nbsp;', (string) $label).'</a></li>';
            }
            $html .= '</ul></div></div></div>';
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
            .implode('', array_map(
                static fn (string $label, string $value): string => '<div class="spec-col__row"><p class="spec-col__type">'.$label.':</p><p class="spec-col__type_val">'.$value.'</p></div>',
                array_keys($specRows),
                $specRows,
            ))
            .($colours !== [] ? '<div class="spec-col__row"><p class="spec-col__type">Kolor:</p><p class="spec-col__type_val">'.($ownColour ?? $colours[0]).'</p></div>' : '')
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
