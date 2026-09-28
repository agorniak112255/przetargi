<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\ProductVariantPriceHistory;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDocumentSource;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bListProgressAware;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRunSummaryAware;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\B2bSizePriceSource;
use App\Services\B2b\MaviboB2bClient;
use App\Services\B2b\MaviboB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik mavibo.pl na atrapie sklepu (Http::fake, bez prawdziwego logowania). Znaczniki odwzorowują sklep
 * PrestaShop 1.7 z 23.09.2026 (sprawdzone na stronach konta i gościa): JSON „prestashop” z polem klienta
 * „is_logged”, formularz #login-form, lista kategorii głównej jako JSON (products + pagination), strona wyrobu
 * z JSON-em wyrobu w data-product (#product-details) i tabelą kombinacji modułu tablecombz — nagłówki
 * Zdjęcie/Indeks/Kolor/Rozmiar/Cena netto/Cena brutto/Dostępny/Kup, klucz kombinacji w oknie podglądu
 * (data-target="#tablecombz-image-modal-{id}") i w polu ilości qty[{wyrób}_{kombinacja}] (tylko kombinacje
 * dostępne), cena promocyjna z kwotą przekreśloną (<s>), legenda dostępności pod tabelą i galeria kombinacji
 * w oknie podglądu (data-image-large-src). Kwoty jak w sklepie: przecinek i twarda spacja przed „zł”.
 *
 * Wszystkie dane (indeksy, ceny, opisy) są SYNTETYCZNE.
 */
final class MaviboConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN = 'konto@example.test';

    private const PASSWORD = 'dobre-haslo';

    private const BASE = 'https://mavibo.pl';

    /** @var array<string, array<string, mixed>> wyroby atrapy w kolejności listy, wg numeru wyrobu */
    private array $products = [];

    /** @var list<string> */
    private array $validSessions = [];

    private int $logins = 0;

    private int $pageSize = 100;

    private bool $dropSessionOnProduct = false;

    private bool $productsAlwaysAnonymous = false;

    private bool $guestPagesAreAccountPages = false;

    private bool $listChangesOnce = false;

    /** @var list<string> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_posts_the_prestashop_form_and_confirms_the_account_session(): void
    {
        $this->fakeShop();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $post = Http::recorded(fn (Request $r): bool => $r->method() === 'POST')->first()[0];
        $this->assertSame(
            ['back' => 'my-account', 'email' => self::LOGIN, 'password' => self::PASSWORD, 'submitLogin' => '1'],
            $post->data(),
        );
        $this->assertSame(['gość:/logowanie', 'gość:/logowanie'], $this->requests);
    }

    public function test_wrong_password_fails_without_being_fatal(): void
    {
        $this->fakeShop();
        $client = new MaviboB2bClient(self::LOGIN, 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sprawdź e-mail i hasło', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_price_is_read_only_with_the_currency(): void
    {
        $this->assertSame(5425, MaviboB2bConnector::priceCents("54,25\u{00A0}zł"));
        $this->assertSame(123405, MaviboB2bConnector::priceCents(" 1\u{00A0}234,05 zł "));
        $this->assertNull(MaviboB2bConnector::priceCents('54,25 EUR'));
        $this->assertNull(MaviboB2bConnector::priceCents('54,25'));
        $this->assertNull(MaviboB2bConnector::priceCents('77.50 zł netto'));
        $this->assertNull(MaviboB2bConnector::priceCents(''));
    }

    public function test_color_code_is_the_reference_without_the_size(): void
    {
        $this->assertSame('51005_21', MaviboB2bConnector::colorCode([
            ['reference' => '51005_21_XS', 'size' => 'XS'],
            ['reference' => '51005_21_XXXL', 'size' => 'XXXL'],
        ]));
        // sklep miesza spację i podkreślnik w jednym kolorze — to ten sam indeks, zostaje zapis pierwszego rozmiaru
        $this->assertSame('21172 20', MaviboB2bConnector::colorCode([
            ['reference' => '21172 20 XS', 'size' => 'XS'],
            ['reference' => '21172_20_S', 'size' => 'S'],
        ]));
        $this->assertSame('42323_20/22', MaviboB2bConnector::colorCode([
            ['reference' => '42323_20/22_S/M', 'size' => 'S/M'],
        ]));

        // indeks bez rozmiaru na końcu, inny rdzeń albo brak indeksu — nie zgadujemy
        $this->assertNull(MaviboB2bConnector::colorCode([['reference' => '93100', 'size' => 'S']]));
        $this->assertNull(MaviboB2bConnector::colorCode([
            ['reference' => '21172 20 XS', 'size' => 'XS'],
            ['reference' => '211172 20 S', 'size' => 'S'],
        ]));
        $this->assertNull(MaviboB2bConnector::colorCode([['reference' => '', 'size' => 'S']]));
    }

    /**
     * Model, który ma cenę konta tylko w jednym kolorze (czerń bez ceny konta, ale na stronie — jej plik jest plikiem
     * koloru), to karta koloru jak przed łączeniem kolorów: nazwa z kolorem, SKU z indeksu koloru, etykieta = rozmiar.
     */
    public function test_sizes_of_one_color_in_one_price_are_one_card_with_the_color_code(): void
    {
        $this->addProduct(self::withoutAccountPrice(self::jacket(), '26'));
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $card = $products[0];
        $this->assertNull($card->cardName);
        $this->assertSame('51005_21', $card->sku);
        $this->assertSame('368_6076', $card->remoteId);
        $this->assertSame('PROMOSTARS NIMBO 51005, kolor 21', $card->name);
        $this->assertSame('Kurtki', $card->category);
        $this->assertSame(self::BASE.'/kurtki/368-nimbo-51005.html', $card->sourceUrl);
        $this->assertSame(
            [
                ['368_6076', '51005_21_S', 'PROMOSTARS NIMBO 51005, kolor 21 S', 'S', 'Produkt dostępny', 54.25, 77.5, 30.0, 'PLN'],
                ['368_6078', '51005_21_M', 'PROMOSTARS NIMBO 51005, kolor 21 M', 'M', 'Produkt z wydłużonym czasem dostawy', 54.25, 77.5, 30.0, 'PLN'],
            ],
            self::memberRows($card),
        );
        $this->assertSame('Produkt dostępny: S; Produkt z wydłużonym czasem dostawy: M', $card->availability);
        $this->assertSame('Rozmiary: S (51005_21_S); M (51005_21_M)', $card->variantSummary);
        // model z data-product na całą kartę, indeks każdej kombinacji na jej pozycji; indeks koloru (SKU) składamy
        // sami — nie jest identyfikatorem
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_MODEL_CODE, '51005', null, null, 'reference'],
                [ProductIdentifier::TYPE_SOURCE_CODE, '51005_21_S', '368_6076', '21 S', 'Indeks'],
                [ProductIdentifier::TYPE_SOURCE_CODE, '51005_21_M', '368_6078', '21 M', 'Indeks'],
            ],
            self::identifiers($card),
        );

        $price = $connector->price($card);
        $this->assertSame(54.25, $price?->net);
        $this->assertSame(77.5, $price?->base);
        $this->assertSame(30.0, $price?->discountPercent);
        $this->assertSame('PROMOSTARS', $connector->manufacturer($card));
        $this->assertSame(
            "GRAMATURA: 115 g/m2\nSKŁAD: 100% Poliester\n\n- kurtka przeciwdeszczowa\nWYMIARY: S M\nDługość 75 77\n- górne szwy klejone",
            $connector->description($card),
        );

        $fields = array_map(static fn ($f): array => [$f->section, $f->name, $f->value], $connector->shopFields($card));
        $this->assertSame([
            ['Oznaczenia', 'Model', '51005'],
            ['Oznaczenia', 'Indeks', 'S: 51005_21_S; M: 51005_21_M'],
            ['Oznaczenia', 'Kolor', '21'],
            ['Oznaczenia', 'Próbka koloru', '#112c69'],
            ['Informacje handlowe', 'Kategoria w sklepie', 'Kurtki'],
            ['Informacje handlowe', 'VAT', '23,0%'],
            ['Informacje handlowe', 'Dostępność', 'S: Produkt dostępny; M: Produkt z wydłużonym czasem dostawy'],
        ], $fields);

        // plik z numerem koloru w nazwie pliku (nazwa w sklepie mówi „22”) trafia na kolor 21; plik bez koloru — na każdy
        $this->assertSame(
            [
                ['KARTA PRODUKTU 51005 22', self::BASE.'/index.php?controller=attachment&id_attachment=842', ProductDocument::KIND_DATASHEET],
                ['TABELA ROZMIARÓW', self::BASE.'/index.php?controller=attachment&id_attachment=900', ProductDocument::KIND_SIZE_CHART],
            ],
            array_map(static fn ($d): array => [$d->title, $d->sourceUrl, $d->kind], $connector->documents($card)),
        );
        $this->assertSame(
            [self::BASE.'/3991-large_default/nimbo.jpg', self::BASE.'/3992-large_default/nimbo.jpg'],
            $connector->imageUrls($card),
        );
        $this->assertStringContainsString('Kombinacje bez ceny konta (poza kartami): 2, np. 51005_26_S, 51005_26_M', implode("\n", $connector->runSummary()));
        $this->assertStringNotContainsString('Modele w kilku kolorach', implode("\n", $connector->runSummary()));
    }

    /**
     * Kolory jednego modelu to jedna karta (decyzja użytkownika 28.09.2026 wieczorem): wiersze kolor × rozmiar, każdy
     * ze swoją ceną, indeksem i dostępnością; SKU = model, nazwa nowej karty bez koloru; tabelka bez koloru jednej
     * kombinacji; pliki i zdjęcia wszystkich kolorów. Dawna karta koloru (synchronizacja podaje jej tylko jej pozycje)
     * dostaje zdjęcia i pliki tylko swojego koloru.
     */
    public function test_colours_of_one_model_are_one_card_with_colour_and_size_rows(): void
    {
        $this->addProduct(self::jacket());
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $card = $products[0];
        $this->assertSame('51005', $card->sku);
        $this->assertSame('368_6076', $card->remoteId);
        // nazwa ze źródła — pierwszego koloru, jak dotąd; nowa karta dostaje nazwę bez koloru
        $this->assertSame('PROMOSTARS NIMBO 51005, kolor 21', $card->name);
        $this->assertSame('PROMOSTARS NIMBO 51005', $card->cardName);
        $this->assertSame(
            [
                ['368_6076', '51005_21_S', 'PROMOSTARS NIMBO 51005, kolor 21 S', 'kolor 21 / S', 'Produkt dostępny', 54.25, 77.5, 30.0, 'PLN'],
                ['368_6078', '51005_21_M', 'PROMOSTARS NIMBO 51005, kolor 21 M', 'kolor 21 / M', 'Produkt z wydłużonym czasem dostawy', 54.25, 77.5, 30.0, 'PLN'],
                ['368_6077', '51005_26_S', 'PROMOSTARS NIMBO 51005, kolor 26 S', 'kolor 26 / S', 'Produkt niedostępny', 54.25, 77.5, 30.0, 'PLN'],
                ['368_6079', '51005_26_M', 'PROMOSTARS NIMBO 51005, kolor 26 M', 'kolor 26 / M', 'Produkt niedostępny', 54.25, 77.5, 30.0, 'PLN'],
            ],
            self::memberRows($card),
        );
        $this->assertSame(
            'Produkt dostępny: kolor 21 / S; Produkt z wydłużonym czasem dostawy: kolor 21 / M; Produkt niedostępny: kolor 26 / S, kolor 26 / M',
            $card->availability,
        );
        $this->assertSame('Kolory: 21 (51005_21), 26 (51005_26); rozmiary: S, M', $card->variantSummary);
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_MODEL_CODE, '51005', null, null, 'reference'],
                [ProductIdentifier::TYPE_SOURCE_CODE, '51005_21_S', '368_6076', 'kolor 21 / S', 'Indeks'],
                [ProductIdentifier::TYPE_SOURCE_CODE, '51005_21_M', '368_6078', 'kolor 21 / M', 'Indeks'],
                [ProductIdentifier::TYPE_SOURCE_CODE, '51005_26_S', '368_6077', 'kolor 26 / S', 'Indeks'],
                [ProductIdentifier::TYPE_SOURCE_CODE, '51005_26_M', '368_6079', 'kolor 26 / M', 'Indeks'],
            ],
            self::identifiers($card),
        );
        $this->assertSame(54.25, $connector->price($card)?->net);
        $this->assertSame(
            [
                ['Oznaczenia', 'Model', '51005'],
                ['Oznaczenia', 'Indeks', 'kolor 21 / S: 51005_21_S; kolor 21 / M: 51005_21_M; kolor 26 / S: 51005_26_S; kolor 26 / M: 51005_26_M'],
                ['Informacje handlowe', 'Kategoria w sklepie', 'Kurtki'],
                ['Informacje handlowe', 'VAT', '23,0%'],
                ['Informacje handlowe', 'Dostępność', 'kolor 21 / S: Produkt dostępny; kolor 21 / M: Produkt z wydłużonym czasem dostawy; kolor 26 / S: Produkt niedostępny; kolor 26 / M: Produkt niedostępny'],
            ],
            array_map(static fn ($f): array => [$f->section, $f->name, $f->value], $connector->shopFields($card)),
        );
        $this->assertSame(
            ['KARTA PRODUKTU 51005 22', 'KARTA PRODUKTU 51005 26', 'TABELA ROZMIARÓW'],
            array_map(static fn ($d): string => $d->title, $connector->documents($card)),
        );
        // galeria pierwszego koloru, potem pierwsze zdjęcie następnego
        $this->assertSame(
            [self::BASE.'/3991-large_default/nimbo.jpg', self::BASE.'/3992-large_default/nimbo.jpg', self::BASE.'/3990-large_default/nimbo.jpg'],
            $connector->imageUrls($card),
        );

        // dawna karta czerni: synchronizacja podaje jej produkt z jej pozycjami (B2bCatalogSync::syncMembersByCard)
        $black = new B2bRemoteProduct(
            remoteId: '368_6077', sku: '51005_26', name: $card->name, raw: $card->raw,
            members: array_values(array_filter($card->members, static fn (array $m): bool => in_array($m['remote_id'], ['368_6077', '368_6079'], true))),
        );
        $this->assertSame(
            ['KARTA PRODUKTU 51005 26', 'TABELA ROZMIARÓW'],
            array_map(static fn ($d): string => $d->title, $connector->documents($black)),
        );
        $this->assertSame([self::BASE.'/3990-large_default/nimbo.jpg'], $connector->imageUrls($black));

        $this->assertSame(1, $connector->totalProducts());
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Karty: 1 (0 kolorów z rozmiarami w różnych cenach', $summary);
        $this->assertStringContainsString('Modele w kilku kolorach — jedna karta z tabelą kolor × rozmiar: 1 (2 kolorów)', $summary);
    }

    /**
     * Do 28.09.2026 (decyzja 15.09.2026) rozmiary jednego koloru w dwóch cenach były dwiema kartami („51005_26_S”
     * i „51005_26_3XL”, nazwy z rozmiarami). Od decyzji użytkownika 28.09.2026 kolor to jedna karta z indeksem koloru
     * i nazwą bez rozmiarów, a cena każdej kombinacji (konta i katalogowa) jedzie przy jej pozycji; cena karty
     * (price()) = najniższa cena rozmiaru. Tu czerń jako jedyny kolor z ceną konta (kolory razem — test kart z kolorami
     * i synchronizacji).
     */
    public function test_sizes_in_two_prices_are_one_card_with_size_prices_and_the_lowest_card_price(): void
    {
        $this->addProduct(self::withoutAccountPrice(self::jacketWithDearerSize(), '21'));
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(
            [['51005_26', 'PROMOSTARS NIMBO 51005, kolor 26', 2]],
            array_map(static fn (B2bRemoteProduct $p): array => [$p->sku, $p->name, count($p->members)], $products),
        );
        $black = $products[0];
        $this->assertSame('368_6077', $black->remoteId);
        $this->assertSame(
            [['S', 54.25, 77.5], ['3XL', 59.5, 85.0]],
            array_map(static fn (array $m): array => [$m['size'], $m['price']->net, $m['price']->base], $black->members),
        );
        $this->assertSame(54.25, $connector->price($black)?->net);
        $this->assertSame(77.5, $connector->price($black)?->base);
        $this->assertSame('Rozmiary: S (51005_26_S); 3XL (51005_26_3XL)', $black->variantSummary);
        // indeksy wszystkich rozmiarów koloru na jednej karcie
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_MODEL_CODE, '51005', null, null, 'reference'],
                [ProductIdentifier::TYPE_SOURCE_CODE, '51005_26_S', '368_6077', '26 S', 'Indeks'],
                [ProductIdentifier::TYPE_SOURCE_CODE, '51005_26_3XL', '368_6091', '26 3XL', 'Indeks'],
            ],
            self::identifiers($black),
        );
        $this->assertSame(1, $connector->totalProducts());
        $this->assertStringContainsString('Karty: 1 (1 kolorów z rozmiarami w różnych cenach', implode("\n", $connector->runSummary()));
    }

    /**
     * Cena katalogowa i promocja należą do kombinacji: rozmiar bez ceny katalogowej (gość podał niższą od ceny konta)
     * nie dostaje ceny innego rozmiaru; cena karty to najtańsza kombinacja z jej ceną katalogową, także gdy nie jest
     * pierwsza; promocja tylko części rozmiarów jest wypisana przy nich, a nie przypisana całej karcie.
     */
    public function test_each_combination_keeps_its_own_catalog_price_and_promotion(): void
    {
        $product = self::polo();
        $product['combinations'][] = [
            'id' => '2275', 'ref' => '42290_22/20_M', 'color' => '22/20', 'hex' => '', 'size' => 'M', 'account' => 4008, 'guest' => 3000,
            'stock' => 'available', 'images' => ['/1556-large_default/shot.jpg'],
        ];
        $product['combinations'][] = [
            'id' => '2276', 'ref' => '42290_22/20_3XL', 'color' => '22/20', 'hex' => '', 'size' => '3XL', 'account' => 2900, 'guest' => 6000,
            'stock' => 'back-order', 'images' => ['/1556-large_default/shot.jpg'],
        ];
        $this->addProduct($product);
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $products);
        $card = $products[0];
        $this->assertSame('42290_22/20', $card->sku);
        $this->assertSame('123_2274', $card->remoteId);
        $this->assertSame(
            [['S', 30.06, 57.25], ['M', 40.08, null], ['3XL', 29.0, 60.0]],
            array_map(static fn (array $m): array => [$m['size'], $m['price']->net, $m['price']->base], $card->members),
        );
        $price = $connector->price($card);
        $this->assertSame([29.0, 60.0, 51.67], [$price?->net, $price?->base, $price?->discountPercent]);
        $fields = array_map(static fn ($f): array => [$f->name, $f->value], $connector->shopFields($card));
        $this->assertContains(['Promocja w sklepie', 'S: – 25% (cena konta przed promocją 40,08 zł netto); M, 3XL: bez promocji'], $fields);
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('Bez ceny katalogowej', $summary);
        $this->assertStringContainsString('42290_22/20_M', $summary);
    }

    public function test_promotion_takes_the_current_price_and_the_guest_price_before_the_promotion(): void
    {
        $this->addProduct(self::polo());
        $this->fakeShop();
        $connector = $this->connector();

        $card = iterator_to_array($connector->products(), false)[0];

        $price = $connector->price($card);
        $this->assertSame(30.06, $price?->net);
        $this->assertSame(57.25, $price?->base);
        $fields = array_map(static fn ($f): array => [$f->name, $f->value], $connector->shopFields($card));
        $this->assertContains(['Promocja w sklepie', '– 25% (cena konta przed promocją 40,08 zł netto)'], $fields);
        $this->assertSame('42290_22/20', $card->sku);
    }

    public function test_combination_without_a_quantity_field_is_keyed_by_its_preview_window(): void
    {
        $this->addProduct(self::jacket());
        $this->fakeShop();
        $connector = $this->connector();

        $card = iterator_to_array($connector->products(), false)[0];

        // kombinacje czerni są niedostępne i nie mają pola ilości — klucz z okna podglądu zdjęcia
        $this->assertSame(['368_6076', '368_6078', '368_6077', '368_6079'], array_column($card->members, 'remote_id'));
    }

    public function test_quantity_field_contradicting_the_preview_window_skips_the_model(): void
    {
        $product = self::jacket();
        $product['combinations'][0]['qty_id'] = '9999';
        $this->addProduct($product);
        $this->addProduct(self::polo());
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame('skipped', $products[0]->raw['status']);
        $this->assertStringContainsString('niezgodnym kluczem', $products[0]->raw['reason']);
        $this->assertSame('ok', $products[1]->raw['status']);
    }

    public function test_product_without_combinations_is_skipped_and_missing_brand_falls_back(): void
    {
        $this->addProduct(self::withoutCombinations());
        $this->addProduct(self::withoutReferences());
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertCount(2, $products);
        $this->assertSame('skipped', $products[0]->raw['status']);
        $this->assertStringContainsString('bez tabeli kombinacji z ceną', $products[0]->raw['reason']);
        $this->assertSame('model_367', $products[0]->remoteId);
        // pominięty model nie podaje identyfikatorów (zapisane zostają)
        $this->assertNull($products[0]->identifiers);

        // kombinacje bez indeksu: SKU z klucza kombinacji; brak marki — producent przyjęty jako MAVIBO
        $card = $products[1];
        // indeks „—” i pusty model to brak kodu — podane, ale żadnego nie ma
        $this->assertSame([], $card->identifiers);
        $this->assertSame('MAVIBO 365_5835', $card->sku);
        $this->assertSame(['365_5835', '365_5841'], array_column($card->members, 'remote_id'));
        $this->assertSame(['365_5835', '365_5841'], array_column($card->members, 'sku'));
        $this->assertSame('MAVIBO', $connector->manufacturer($card));
        $this->assertSame('SLIM LADIES 21603 WYPRZEDAŻ, kolor 34', $card->name);
        $summary = implode("\n", $connector->runSummary());
        $this->assertStringContainsString('producent przyjęty jako MAVIBO: 1', $summary);
        $this->assertStringContainsString('1 modeli pominiętych', $summary);
    }

    /**
     * Ten sam model na dwóch stronach sklepu (MAVIBO: „CZAPKA 22001” i „CZAPKA 22001 - NA ZAMÓWIENIE”) — druga karta
     * z kolorami dostaje „MAVIBO {id wyrobu}”, bo dwie karty przebiegu z tym samym SKU złamałyby UNIQUE products.sku.
     */
    public function test_same_model_code_on_two_pages_gets_a_unique_sku(): void
    {
        $this->addProduct(self::shirtWithModelReferences());
        $onOrder = self::shirtWithModelReferences();
        $onOrder['id'] = '64';
        $onOrder['path'] = '/koszule/64-river-93100-na-zamowienie.html';
        $onOrder['name'] = 'RIVER 93100 - NA ZAMÓWIENIE';
        $onOrder['combinations'][0]['id'] = '2088';
        $onOrder['combinations'][1]['id'] = '2089';
        $this->addProduct($onOrder);
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(['93100', 'MAVIBO 64'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $this->assertSame(['PROMOSTARS RIVER 93100', 'PROMOSTARS RIVER 93100 - NA ZAMÓWIENIE'], array_map(static fn (B2bRemoteProduct $p): ?string => $p->cardName, $products));
        // indeks równy numerowi modelu zostaje indeksem kombinacji — dosłownie, obok modelu; kolor bez indeksu koloru
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_MODEL_CODE, '93100', null, null, 'reference'],
                [ProductIdentifier::TYPE_SOURCE_CODE, '93100', '63_1088', 'kolor 20 / S', 'Indeks'],
                [ProductIdentifier::TYPE_SOURCE_CODE, '93100', '63_1089', 'kolor 26 / S', 'Indeks'],
            ],
            self::identifiers($products[0]),
        );
        $this->assertSame('Kolory: 20, 26; rozmiary: S', $products[0]->variantSummary);
    }

    /**
     * Kombinacja bez koloru obok kolorów — sklep nie mówi, że to ten sam wyrób w innym kolorze: karty kolorów jak
     * przed łączeniem kolorów (z unikalnym SKU przy tym samym indeksie) i powód w podsumowaniu.
     */
    public function test_combination_without_a_colour_keeps_the_model_as_colour_cards(): void
    {
        $shirt = self::shirtWithModelReferences();
        $shirt['combinations'][] = ['id' => '1090', 'ref' => '93100', 'color' => '', 'hex' => '', 'size' => 'M', 'account' => 6467, 'guest' => 9239, 'stock' => 'available', 'images' => []];
        $this->addProduct($shirt);
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(
            [
                ['93100', 'PROMOSTARS RIVER 93100, kolor 20', null, ['S']],
                ['MAVIBO 63_1089', 'PROMOSTARS RIVER 93100, kolor 26', null, ['S']],
                ['MAVIBO 63_1090', 'PROMOSTARS RIVER 93100', null, ['M']],
            ],
            array_map(static fn (B2bRemoteProduct $p): array => [$p->sku, $p->name, $p->cardName, array_column($p->members, 'size')], $products),
        );
        $this->assertSame(
            [[ProductIdentifier::TYPE_MODEL_CODE, '93100', null, null, 'reference'], [ProductIdentifier::TYPE_SOURCE_CODE, '93100', '63_1089', '26 S', 'Indeks']],
            self::identifiers($products[1]),
        );
        $this->assertStringContainsString(
            'Modele w kilku kolorach zostawione jako karty kolorów: 1, np. RIVER 93100 (kombinacja 93100 ma cechy inne niż kolor i rozmiar albo nie ma koloru)',
            implode("\n", $connector->runSummary()),
        );
    }

    /**
     * Model w 10 kolorach × 8 rozmiarach: wykazy wierszy (indeksy, dostępność, promocja) nie mieszczą się w polu
     * tabelki (ProductShopCard::MAX_VALUE_CHARS) — są jawnie ucięte albo zliczone, nigdy przycięte po cichu przez zapis.
     */
    public function test_long_row_lists_of_a_colour_card_are_cut_explicitly(): void
    {
        $product = self::jacket();
        $product['attachments'] = [];
        $product['combinations'] = [];
        $id = 7000;
        foreach (['20', '21', '22', '24', '26', '27', '28', '30', '32', '36'] as $c => $color) {
            foreach (['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL'] as $size) {
                $promo = $c < 3 ? ['account_regular' => 5000, 'guest_regular' => 7000] : [];
                $product['combinations'][] = [
                    'id' => (string) $id++, 'ref' => '51005_'.$color.'_'.$size, 'color' => $color, 'hex' => '', 'size' => $size,
                    'account' => 4000, 'guest' => 5000, 'stock' => 'available', 'images' => [],
                ] + $promo;
            }
        }
        $this->addProduct($product);
        $this->fakeShop();
        $connector = $this->connector();

        $card = iterator_to_array($connector->products(), false)[0];
        $this->assertCount(80, $card->members);
        $fields = [];
        foreach ($connector->shopFields($card) as $field) {
            $fields[$field->name] = $field->value;
        }

        foreach (['Indeks', 'Dostępność', 'Promocja w sklepie'] as $name) {
            $this->assertLessThanOrEqual(ProductShopCard::MAX_VALUE_CHARS, mb_strlen($fields[$name]), $name);
        }
        $this->assertStringStartsWith('kolor 20 / XS: 51005_20_XS; kolor 20 / S: 51005_20_S; ', $fields['Indeks']);
        $this->assertMatchesRegularExpression('/; … \(\+\d+, pełna lista w tabeli wariantów\)$/u', $fields['Indeks']);
        $this->assertMatchesRegularExpression('/; … \(\+\d+, pełna lista w tabeli wariantów\)$/u', $fields['Dostępność']);
        // pełne pozycje przed dopiskiem, liczba pominiętych się zgadza
        preg_match('/\(\+(\d+),/u', $fields['Indeks'], $m);
        $this->assertSame(80, substr_count($fields['Indeks'], ': 51005_') + (int) $m[1]);
        $this->assertSame(
            '– 25% (cena konta przed promocją 50,00 zł netto): 24 z 80 kombinacji; bez promocji: 56 z 80 kombinacji'
            .' (wykaz kombinacji za długi na tabelkę — które są w promocji, pokazuje sklep)',
            $fields['Promocja w sklepie'],
        );
    }

    /** Ta sama para kolor–rozmiar w dwóch wierszach — nie wiadomo, który jest którym: karty kolorów jak dotąd. */
    public function test_repeated_colour_and_size_keeps_the_model_as_colour_cards(): void
    {
        $shirt = self::shirtWithModelReferences();
        $shirt['combinations'][] = ['id' => '1091', 'ref' => '93100', 'color' => '20', 'hex' => '#ffffff', 'size' => 'S', 'account' => 7000, 'guest' => 9900, 'stock' => 'available', 'images' => []];
        $this->addProduct($shirt);
        $this->fakeShop();
        $connector = $this->connector();

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame(
            [['PROMOSTARS RIVER 93100, kolor 20', ['63_1088', '63_1091']], ['PROMOSTARS RIVER 93100, kolor 26', ['63_1089']]],
            array_map(static fn (B2bRemoteProduct $p): array => [$p->name, array_column($p->members, 'remote_id')], $products),
        );
        // karty kolorów z tym samym indeksem — SKU unikalne jak dotąd (indeks, potem klucz kombinacji), bez nazwy nowej karty
        $this->assertSame(['93100', 'MAVIBO 63_1089'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $this->assertSame([null, null], array_map(static fn (B2bRemoteProduct $p): ?string => $p->cardName, $products));
        $this->assertSame(['S', 'S'], array_column($products[0]->members, 'size'));
        $this->assertStringContainsString('(powtórzony kolor 20 w rozmiarze S)', implode("\n", $connector->runSummary()));
    }

    public function test_guest_page_that_is_an_account_page_leaves_the_card_without_the_catalog_price(): void
    {
        $this->addProduct(self::jacket());
        $this->fakeShop();
        $this->guestPagesAreAccountPages = true;
        $connector = $this->connector();

        $card = iterator_to_array($connector->products(), false)[0];

        $this->assertSame(54.25, $connector->price($card)?->net);
        $this->assertNull($connector->price($card)?->base);
        $this->assertStringContainsString('Ceny katalogowe bez części stron gościa', implode("\n", $connector->runSummary()));
    }

    public function test_list_changed_during_reading_is_read_again_from_the_start(): void
    {
        $this->addProduct(self::jacket());
        $this->addProduct(self::polo());
        $this->fakeShop();
        $this->pageSize = 1;
        $this->listChangesOnce = true;
        $connector = $this->connector();
        $progress = [];
        $connector->onListProgress(static function (string $m) use (&$progress): void {
            $progress[] = $m;
        });

        $products = iterator_to_array($connector->products(), false);

        // kurtka (jedna karta z kolorami) i polo — wyrób, który doszedł przy pierwszym pobraniu, już go nie ma
        $this->assertSame(['51005', '42290_22/20'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $this->assertStringContainsString('pobieram od nowa', implode("\n", $progress));
    }

    public function test_session_lost_on_a_product_page_logs_in_again_once(): void
    {
        $this->addProduct(self::jacket());
        $this->fakeShop();
        $connector = $this->connector();
        $this->dropSessionOnProduct = true;

        $products = iterator_to_array($connector->products(), false);

        $this->assertSame('ok', $products[0]->raw['status']);
        $this->assertSame(2, $this->logins);
    }

    public function test_product_pages_never_signed_in_are_fatal(): void
    {
        $this->addProduct(self::jacket());
        $this->fakeShop();
        $connector = $this->connector();
        $this->productsAlwaysAnonymous = true;

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('Utracono sesję konta mavibo.pl');

        iterator_to_array($connector->products(), false);
    }

    public function test_sync_creates_cards_with_prices_files_images_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addProduct(self::jacketWithDearerSize());
        $this->addProduct(self::withoutCombinations());
        $this->fakeShop();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        // dwa kolory kurtki = jedna karta z wierszami kolor × rozmiar (decyzja użytkownika 28.09.2026 wieczorem);
        // czerń z rozmiarami w dwóch cenach — cena karty od najniższej
        $this->assertSame(1, $result['created'], implode(' | ', $result['errors']));
        $this->assertStringContainsString('bez tabeli kombinacji z ceną', implode(' | ', $result['errors']));
        $card = Product::query()->where('sku', '51005')->sole();
        $this->assertSame('PROMOSTARS NIMBO 51005', $card->name);
        $this->assertSame('Kolory: 21 (51005_21), 26 (51005_26); rozmiary: S, M, 3XL', $card->variant_summary);
        $this->assertSame('PROMOSTARS', $card->manufacturer);
        $this->assertSame(
            ['368_6076', '368_6077', '368_6078', '368_6091'],
            B2bProductLink::query()->where('product_id', $card->id)->orderBy('remote_id')->pluck('remote_id')->all(),
        );
        // powiązanie każdej kombinacji z jej nazwą (z kolorem) i indeksem
        $this->assertSame(
            'PROMOSTARS NIMBO 51005, kolor 26 3XL',
            B2bProductLink::query()->where('remote_id', '368_6091')->value('remote_name'),
        );
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->sole();
        // cena karty = najtańsza kombinacja z jej ceną katalogową, najwyższa cena kombinacji przy slocie
        $this->assertSame('54.25', (string) $slot->purchase_price);
        $this->assertSame('77.50', (string) $slot->catalog_price_net);
        $this->assertSame('59.50', (string) $slot->size_price_max);
        $this->assertStringContainsString('kurtka przeciwdeszczowa', (string) $card->description);
        $this->assertTrue(ProductShopCard::query()->where('product_id', $card->id)->exists());
        $this->assertSame(
            [self::BASE.'/3991-large_default/nimbo.jpg', self::BASE.'/3992-large_default/nimbo.jpg', self::BASE.'/3990-large_default/nimbo.jpg'],
            ProductImage::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('source_url')->all(),
        );
        $this->assertSame(
            [
                self::BASE.'/index.php?controller=attachment&id_attachment=842',
                self::BASE.'/index.php?controller=attachment&id_attachment=843',
                self::BASE.'/index.php?controller=attachment&id_attachment=900',
            ],
            ProductDocument::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('source_url')->all(),
        );
        $this->assertSame(
            [
                ['kolor 21 / S', '51005_21_S', '54.25', '77.50'],
                ['kolor 21 / M', '51005_21_M', '54.25', '77.50'],
                ['kolor 26 / S', '51005_26_S', '54.25', '77.50'],
                ['kolor 26 / 3XL', '51005_26_3XL', '59.50', '85.00'],
            ],
            ProductVariant::query()->where('product_id', $card->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('sort_order')->get()
                ->map(static fn (ProductVariant $v): array => [$v->label, $v->sku, (string) $v->purchase_price, (string) $v->list_price_net])->all(),
        );
        $this->assertSame('59.50', (string) B2bProductLink::query()->where('remote_id', '368_6091')->value('last_purchase_price'));

        // identyfikatory: model pod pozycją karty (pierwsza kombinacja), indeks pod każdą kombinacją z etykietą wiersza
        $this->assertSame(
            [
                ['368_6076', ProductIdentifier::TYPE_MODEL_CODE, '51005', null, 'reference', 'PROMOSTARS'],
                ['368_6076', ProductIdentifier::TYPE_SOURCE_CODE, '51005_21_S', 'kolor 21 / S', 'Indeks', 'PROMOSTARS'],
                ['368_6077', ProductIdentifier::TYPE_SOURCE_CODE, '51005_26_S', 'kolor 26 / S', 'Indeks', 'PROMOSTARS'],
                ['368_6078', ProductIdentifier::TYPE_SOURCE_CODE, '51005_21_M', 'kolor 21 / M', 'Indeks', 'PROMOSTARS'],
                ['368_6091', ProductIdentifier::TYPE_SOURCE_CODE, '51005_26_3XL', 'kolor 26 / 3XL', 'Indeks', 'PROMOSTARS'],
            ],
            ProductIdentifier::query()->where('product_id', $card->id)->orderBy('position_key')->orderBy('type')->get()
                ->map(static fn (ProductIdentifier $i): array => [$i->position_key, $i->type, $i->value, $i->variant_label, $i->source_field, $i->manufacturer])
                ->all(),
        );
        // karta × model + 4 kombinacje; pominięty model (DRYON 51003) nie ma karty ani identyfikatorów
        $this->assertSame(5, ProductIdentifier::query()->count());
        $this->assertFalse(ProductIdentifier::query()->where('value', '51003')->exists());
        $identifiers = ProductIdentifier::query()->orderBy('id')->get(['id', 'product_id', 'position_key', 'type', 'value'])->toArray();

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(1, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
        // drugi przebieg identyfikatorów nie dubluje ani nie oznacza jako zniknięte
        $this->assertSame($identifiers, ProductIdentifier::query()->orderBy('id')->get(['id', 'product_id', 'position_key', 'type', 'value'])->toArray());
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());
        // żaden identyfikator nie wskazał pozycji spoza swojej karty
        $this->assertSame(2, B2bSyncRun::query()->count());
        foreach (B2bSyncRun::query()->get() as $run) {
            $this->assertSame([], preg_grep('/identyfikator /', array_column((array) $run->log, 'text')));
        }
    }

    /**
     * Karty sprzed 28.09.2026 (granat — karta koloru, czerń rozbita według ceny na „51005_26_S” i „51005_26_3XL”) —
     * dwa przebiegi po zmianie łącznika (model = jedna karta z kolorami): bez nowej karty, bez przepinania powiązań,
     * bez zmian cen, kodów i nazw; każda karta dostaje swoje wiersze, a model trafia do size_spread przebiegu (do
     * scalenia „Scal rozmiary”) z nazwą bez koloru i listą kolorów od łącznika.
     */
    public function test_legacy_price_split_cards_stay_and_get_their_own_sizes_over_two_runs(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addProduct(self::jacketWithDearerSize());
        $this->fakeShop();
        $account = $this->account();
        $description = "GRAMATURA: 115 g/m2\nSKŁAD: 100% Poliester\n\n- kurtka przeciwdeszczowa\nWYMIARY: S M\nDługość 75 77\n- górne szwy klejone";
        $navy = $this->legacyCard($account, '51005_21', 'PROMOSTARS NIMBO 51005, kolor 21', $description, 54.25, 77.5, ['368_6076' => '51005_21_S', '368_6078' => '51005_21_M']);
        $small = $this->legacyCard($account, '51005_26_S', 'PROMOSTARS NIMBO 51005, kolor 26 (rozm. S)', $description, 54.25, 77.5, ['368_6077' => '51005_26_S']);
        $large = $this->legacyCard($account, '51005_26_3XL', 'PROMOSTARS NIMBO 51005, kolor 26 (rozm. 3XL)', $description, 59.5, 85.0, ['368_6091' => '51005_26_3XL']);
        $cards = fn (): array => Product::query()->whereKey([$navy->id, $small->id, $large->id])->orderBy('id')->get()->map(fn (Product $p): array => [
            $p->sku, $p->name, $p->description, (string) $p->purchase_price,
            B2bProductLink::query()->where('product_id', $p->id)->orderBy('remote_id')->pluck('remote_id')->all(),
            (string) ProductSourcePrice::query()->where('product_id', $p->id)->value('purchase_price'),
            (string) ProductSourcePrice::query()->where('product_id', $p->id)->value('catalog_price_net'),
            ProductSourcePrice::query()->where('product_id', $p->id)->value('size_price_max'),
        ])->all();
        $before = $cards();

        $first = app(B2bAccountSyncRunner::class)->run($account, delayMs: 0, withImages: false);

        $this->assertSame(0, $first['created'], implode(' | ', $first['errors']));
        $this->assertSame(['51005_21', '51005_26_3XL', '51005_26_S'], Product::query()->orderBy('sku')->pluck('sku')->all());
        $this->assertSame(0, $first['skipped'], implode(' | ', $first['errors']));
        $this->assertSame(0, $first['prices_changed']);
        $this->assertSame($before, $cards());
        $sizes = static fn (Product $card): array => ProductVariant::query()->where('product_id', $card->id)->orderBy('sort_order')->get()
            ->map(static fn (ProductVariant $v): array => [$v->label, (string) $v->purchase_price, (string) $v->list_price_net])->all();
        $this->assertSame([['kolor 21 / S', '54.25', '77.50'], ['kolor 21 / M', '54.25', '77.50']], $sizes($navy));
        $this->assertSame([['kolor 26 / S', '54.25', '77.50']], $sizes($small));
        $this->assertSame([['kolor 26 / 3XL', '59.50', '85.00']], $sizes($large));
        $spread = B2bSyncRun::query()->findOrFail($first['sync_run_id'])->size_spread;
        $this->assertSame(1, $spread['total']);
        $this->assertSame([$navy->id, $small->id, $large->id], $spread['groups'][0]['cards']);
        $this->assertSame('51005', $spread['groups'][0]['sku']);
        $this->assertSame('PROMOSTARS NIMBO 51005', $spread['groups'][0]['name']);
        $this->assertSame('Kolory: 21 (51005_21), 26 (51005_26); rozmiary: S, M, 3XL', $spread['groups'][0]['variant_summary']);

        $second = app(B2bAccountSyncRunner::class)->run($account, delayMs: 0, withImages: false);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        // wynik liczy produkty łącznika — model to jeden produkt, choć leży na trzech kartach
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame($before, $cards());
        $this->assertSame(0, ProductVariant::query()->whereNotNull('removed_at')->count());
    }

    /**
     * Karty kolorów z łącznika sprzed łączenia kolorów (produkcja: karta na kolor modelu) — przebieg zostawia je
     * (każda ze swoimi wierszami, zdjęciami i plikami tylko swojego koloru), a „Scal rozmiary” łączy je w kartę modelu
     * z nazwą bez koloru, listą kolorów od łącznika i SKU modelu.
     */
    public function test_legacy_colour_cards_keep_their_colour_until_merged_into_the_model_card(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addProduct(self::jacket());
        $this->fakeShop();
        $account = $this->account();
        $description = "GRAMATURA: 115 g/m2\nSKŁAD: 100% Poliester\n\n- kurtka przeciwdeszczowa\nWYMIARY: S M\nDługość 75 77\n- górne szwy klejone";
        $navy = $this->legacyCard($account, '51005_21', 'PROMOSTARS NIMBO 51005, kolor 21', $description, 54.25, 77.5, ['368_6076' => '51005_21_S', '368_6078' => '51005_21_M']);
        $black = $this->legacyCard($account, '51005_26', 'PROMOSTARS NIMBO 51005, kolor 26', $description, 54.25, 77.5, ['368_6077' => '51005_26_S', '368_6079' => '51005_26_M']);

        $first = app(B2bAccountSyncRunner::class)->run($account, delayMs: 0, withImages: true);

        $this->assertSame(0, $first['created'], implode(' | ', $first['errors']));
        $this->assertSame(0, $first['skipped'], implode(' | ', $first['errors']));
        $this->assertSame(['PROMOSTARS NIMBO 51005, kolor 21', 'PROMOSTARS NIMBO 51005, kolor 26'], [$navy->fresh()->name, $black->fresh()->name]);
        $images = static fn (Product $card): array => ProductImage::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('source_url')->all();
        $documents = static fn (Product $card): array => ProductDocument::query()->where('product_id', $card->id)->orderBy('sort_order')->pluck('title')->all();
        $this->assertSame([self::BASE.'/3991-large_default/nimbo.jpg', self::BASE.'/3992-large_default/nimbo.jpg'], $images($navy));
        $this->assertSame([self::BASE.'/3990-large_default/nimbo.jpg'], $images($black));
        $this->assertSame(['KARTA PRODUKTU 51005 22', 'TABELA ROZMIARÓW'], $documents($navy));
        $this->assertSame(['KARTA PRODUKTU 51005 26', 'TABELA ROZMIARÓW'], $documents($black));
        $this->assertSame(1, B2bSyncRun::query()->findOrFail($first['sync_run_id'])->size_spread['total']);
        $this->travel(5)->minutes();

        $this->artisan('b2b:merge-size-prices', ['account' => $account->id, '--apply' => true])
            ->expectsOutputToContain('Scalono wyrobów: 1')
            ->assertSuccessful();
        foreach (glob(storage_path('app/repair-backups/size-prices-'.$account->id.'-*.jsonl')) ?: [] as $file) {
            @unlink($file);
        }

        $keep = $navy->fresh();
        $this->assertNull(Product::query()->find($black->id));
        $this->assertSame('51005', $keep->sku);
        $this->assertSame('PROMOSTARS NIMBO 51005', $keep->name);
        $this->assertSame('Kolory: 21 (51005_21), 26 (51005_26); rozmiary: S, M', $keep->variant_summary);
        $this->assertSame(
            ['368_6076', '368_6077', '368_6078', '368_6079'],
            B2bProductLink::query()->where('product_id', $keep->id)->orderBy('remote_id')->pluck('remote_id')->all(),
        );
        $rows = static fn (): array => ProductVariant::query()->where('product_id', $keep->id)->where('kind', ProductVariant::KIND_SIZE)
            ->whereNull('removed_at')->orderBy('sort_order')->pluck('label')->all();
        $this->assertEqualsCanonicalizing(['kolor 21 / S', 'kolor 21 / M', 'kolor 26 / S', 'kolor 26 / M'], $rows());

        // następny przebieg trafia w kartę modelu zwykłą drogą, bez nowej karty i bez zmiany ceny
        $this->travel(5)->minutes();
        $next = app(B2bAccountSyncRunner::class)->run($account, delayMs: 0, withImages: true);
        $this->assertSame(0, $next['created'], implode(' | ', $next['errors']));
        $this->assertSame(0, $next['prices_changed'], implode(' | ', $next['errors']));
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(0, B2bSyncRun::query()->findOrFail($next['sync_run_id'])->size_spread['total'] ?? 0);
        $this->assertSame(['kolor 21 / S', 'kolor 21 / M', 'kolor 26 / S', 'kolor 26 / M'], $rows());
        // karta modelu: zdjęcia i pliki wszystkich kolorów
        $this->assertEqualsCanonicalizing(
            [self::BASE.'/3990-large_default/nimbo.jpg', self::BASE.'/3991-large_default/nimbo.jpg', self::BASE.'/3992-large_default/nimbo.jpg'],
            $images($keep),
        );
        $this->assertEqualsCanonicalizing(['KARTA PRODUKTU 51005 22', 'KARTA PRODUKTU 51005 26', 'TABELA ROZMIARÓW'], $documents($keep));
    }

    public function test_registry_detects_mavibo_by_host_as_a_distributor(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('mavibo', $registry->keyForSites(['https://mavibo.pl/']));
        $this->assertSame('MAVIBO', $registry->label('mavibo'));
        $this->assertTrue($registry->requiresPassword('mavibo'));
        $this->assertFalse($registry->requiresLoginCode('mavibo'));
        $this->assertNull($registry->discountRulesMode('mavibo'));

        $account = B2bAccount::query()->create([
            'username' => self::LOGIN, 'password' => 'sekret', 'sites' => ['https://mavibo.pl/'],
        ]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(MaviboB2bConnector::class, $connector);
        // dystrybutor, nie producent — opis stąd nie nadpisuje opisu z innego źródła
        $this->assertNotInstanceOf(B2bManufacturerSite::class, $connector);
        foreach ([
            B2bShopFieldSource::class, B2bRunSummaryAware::class, B2bListProgressAware::class,
            B2bDocumentSource::class, B2bImageGallery::class, B2bSizePriceSource::class,
        ] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
        // ceny rozmiarów — konto ma w panelu „Scal rozmiary”
        $this->assertTrue($registry->sendsSizePrices('mavibo'));
    }

    // ---- pomocnicze ----

    /**
     * Pozycje karty: remote_id, sku, nazwa, rozmiar, dostępność, cena konta, katalogowa, rabat i waluta kombinacji.
     *
     * @return list<list<mixed>>
     */
    private static function memberRows(B2bRemoteProduct $product): array
    {
        return array_map(static fn (array $m): array => [
            $m['remote_id'], $m['sku'], $m['name'], $m['size'] ?? null, $m['availability'] ?? null,
            $m['price']?->net, $m['price']?->base, $m['price']?->discountPercent, $m['price']?->currency,
        ], $product->members);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string|null, 3: string|null, 4: string|null}>
     */
    private static function identifiers(B2bRemoteProduct $product): array
    {
        return array_map(
            static fn ($i): array => [$i->type, $i->value, $i->remoteId, $i->label, $i->field],
            $product->identifiers ?? [],
        );
    }

    private function client(): MaviboB2bClient
    {
        return new MaviboB2bClient(self::LOGIN, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): MaviboB2bConnector
    {
        $connector = new MaviboB2bConnector($this->client());
        $connector->login();

        return $connector;
    }

    /**
     * Karta zapisana przez dawny podział według ceny: opis ze źródła z odciskiem w powiązaniach, slot konta, kod
     * i nazwa z rozmiarami.
     *
     * @param  array<string, string>  $combinations  klucz kombinacji => indeks
     */
    private function legacyCard(B2bAccount $account, string $sku, string $name, string $description, float $price, float $catalog, array $combinations): Product
    {
        $discount = round((1 - $price / $catalog) * 100, 2);
        $card = Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => 'PROMOSTARS', 'description' => $description,
            'catalog_price_net' => $catalog, 'discount_percent' => $discount, 'purchase_price' => $price, 'currency' => 'PLN',
        ]);
        foreach ($combinations as $key => $reference) {
            B2bProductLink::query()->create([
                'b2b_account_id' => $account->id, 'remote_id' => (string) $key, 'product_id' => $card->id,
                'remote_sku' => $reference, 'remote_name' => $name, 'manufacturer' => 'PROMOSTARS',
                'description_hash' => sha1($description), 'last_purchase_price' => $price, 'last_currency' => 'PLN',
            ]);
        }
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::b2bKey((int) $account->id), 'b2b_account_id' => $account->id,
            'catalog_price_net' => $catalog, 'purchase_price' => $price, 'discount_percent' => $discount, 'currency' => 'PLN',
            'availability' => 'Produkt dostępny', 'checked_at' => now()->subDay(),
        ]);

        return $card;
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::LOGIN],
            ['password' => self::PASSWORD, 'sites' => ['https://mavibo.pl/'], 'connector' => 'mavibo', 'sync_images' => true],
        )->fresh();
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function addProduct(array $product): void
    {
        $this->products[$product['id']] = $product;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return Product::query()->orderBy('sku')->get()->mapWithKeys(fn (Product $p): array => [$p->sku => [
            'name' => $p->name,
            'description' => $p->description,
            'variant_summary' => $p->variant_summary,
            'links' => B2bProductLink::query()->where('product_id', $p->id)->orderBy('remote_id')->pluck('remote_id')->all(),
            'shop_card' => ProductShopCard::query()->where('product_id', $p->id)->value('fields'),
            'images' => ProductImage::query()->where('product_id', $p->id)->orderBy('sort_order')->pluck('source_url')->all(),
            'documents' => ProductDocument::query()->where('product_id', $p->id)->orderBy('sort_order')->pluck('source_url')->all(),
            'price' => ProductSourcePrice::query()->where('product_id', $p->id)->get(['purchase_price', 'catalog_price_net', 'size_price_max', 'availability'])->toArray(),
            'sizes' => ProductVariant::query()->where('product_id', $p->id)->orderBy('id')
                ->get(['remote_id', 'label', 'purchase_price', 'list_price_net', 'availability', 'removed_at', 'updated_at'])->toArray(),
            'size_history' => ProductVariantPriceHistory::query()->count(),
        ]])->all();
    }

    // ---- wyroby atrapy ----

    /**
     * Kurtka w dwóch kolorach: granat (21) w dostępnych rozmiarach i czerń (26), której rozmiary są niedostępne
     * (bez pola ilości). Pliki: „KARTA PRODUKTU 51005 22” z plikiem „…_21_…” (sklep pomylił numer w nazwie),
     * karta czerni i tabela rozmiarów bez koloru.
     *
     * @return array<string, mixed>
     */
    private static function jacket(): array
    {
        return [
            'id' => '368',
            'path' => '/kurtki/368-nimbo-51005.html',
            'name' => 'NIMBO 51005',
            'reference' => '51005',
            'brand' => 'PROMOSTARS',
            'category' => 'Kurtki',
            'short' => '<p><strong>GRAMATURA</strong>: 115 g/m2</p>'."\n".'<p><strong>SKŁAD:</strong> 100% Poliester</p>'
                ."\n".'<p><img src="https://mavibo.pl/img/cms/PROMOSTARS%20LOGO.jpg" alt="" width="200"></p>',
            'description' => '<ul style="list-style:square;"><li>kurtka przeciwdeszczowa'."\n"
                .'<table style="float:right;"><caption></caption>'."\n".'<tbody>'."\n"
                .'<tr>'."\n".'<td><strong>WYMIARY:</strong></td>'."\n".'<td><strong>S</strong></td>'."\n".'<td><strong>M</strong></td>'."\n".'</tr>'."\n"
                .'<tr>'."\n".'<td>Długość</td>'."\n".'<td>75</td>'."\n".'<td>77</td>'."\n".'</tr>'."\n"
                .'</tbody>'."\n".'</table>'."\n".'</li>'."\n".'<li>górne szwy klejone</li>'."\n".'</ul>',
            'attachments' => [
                ['id' => '842', 'name' => 'KARTA PRODUKTU 51005 22', 'file' => 'p_51005_21_wynik.jpg', 'mime' => 'image/jpeg'],
                ['id' => '843', 'name' => 'KARTA PRODUKTU 51005 26', 'file' => 'p_51005_26_wynik.pdf', 'mime' => 'application/pdf'],
                ['id' => '900', 'name' => 'TABELA ROZMIARÓW', 'file' => 'tabela.pdf', 'mime' => 'application/pdf'],
            ],
            'combinations' => [
                ['id' => '6076', 'ref' => '51005_21_S', 'color' => '21', 'hex' => '#112c69', 'size' => 'S', 'account' => 5425, 'guest' => 7750, 'stock' => 'available',
                    'images' => ['/3991-large_default/nimbo.jpg', '/3992-large_default/nimbo.jpg']],
                ['id' => '6077', 'ref' => '51005_26_S', 'color' => '26', 'hex' => '#000000', 'size' => 'S', 'account' => 5425, 'guest' => 7750, 'stock' => 'unavailable',
                    'images' => ['/3990-large_default/nimbo.jpg']],
                ['id' => '6078', 'ref' => '51005_21_M', 'color' => '21', 'hex' => '#112c69', 'size' => 'M', 'account' => 5425, 'guest' => 7750, 'stock' => 'back-order',
                    'images' => ['/3991-large_default/nimbo.jpg', '/3992-large_default/nimbo.jpg']],
                ['id' => '6079', 'ref' => '51005_26_M', 'color' => '26', 'hex' => '#000000', 'size' => 'M', 'account' => 5425, 'guest' => 7750, 'stock' => 'unavailable',
                    'images' => ['/3990-large_default/nimbo.jpg']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function jacketWithDearerSize(): array
    {
        $product = self::jacket();
        $product['combinations'] = array_values(array_filter(
            $product['combinations'],
            static fn (array $c): bool => $c['id'] !== '6079',
        ));
        $product['combinations'][] = [
            'id' => '6091', 'ref' => '51005_26_3XL', 'color' => '26', 'hex' => '#000000', 'size' => '3XL', 'account' => 5950, 'guest' => 8500,
            'stock' => 'available', 'images' => ['/3990-large_default/nimbo.jpg'],
        ];

        return $product;
    }

    /**
     * Wyrób, którego kombinacje danego koloru konto widzi bez ceny („0,00 zł”) — kolor zostaje na stronie (i w plikach).
     *
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private static function withoutAccountPrice(array $product, string $color): array
    {
        foreach ($product['combinations'] as $i => $combination) {
            if ($combination['color'] === $color) {
                $product['combinations'][$i]['account'] = 0;
            }
        }

        return $product;
    }

    /**
     * Polo w promocji sklepu: konto widzi „<s>40,08 zł</s> – 25% 30,06 zł”, gość „<s>57,25 zł</s> – 25% 42,94 zł”.
     *
     * @return array<string, mixed>
     */
    private static function polo(): array
    {
        return [
            'id' => '123',
            'path' => '/POLO/123-shot-42290.html',
            'name' => 'SHOT 42290',
            'reference' => '42290',
            'brand' => 'CRIMSON CUT',
            'category' => 'Polo',
            'short' => '<p>GRAMATURA: 200 g/m2</p>',
            'description' => '<p>męska koszulka polo</p>',
            'attachments' => [],
            'combinations' => [
                ['id' => '2274', 'ref' => '42290_22/20_S', 'color' => '22/20', 'hex' => '', 'size' => 'S', 'account' => 3006, 'guest' => 4294,
                    'account_regular' => 4008, 'guest_regular' => 5725, 'stock' => 'available', 'images' => ['/1556-large_default/shot.jpg']],
            ],
        ];
    }

    /**
     * Kurtka bez kombinacji i bez ceny (DRYON) — tabela ma tylko zdjęcie i indeks.
     *
     * @return array<string, mixed>
     */
    private static function withoutCombinations(): array
    {
        return [
            'id' => '367',
            'path' => '/kurtki/367-dryon-51003.html',
            'name' => 'DRYON 51003',
            'reference' => '51003',
            'brand' => null,
            'category' => 'Kurtki',
            'short' => '',
            'description' => '<p>lekka kurtka przeciwwiatrowa</p>',
            'attachments' => [],
            'combinations' => [],
        ];
    }

    /**
     * Wyprzedaż bez indeksów („—”) i bez marki w sklepie.
     *
     * @return array<string, mixed>
     */
    private static function withoutReferences(): array
    {
        return [
            'id' => '365',
            'path' => '/wyprzedaz/365-slim-ladies-21603.html',
            'name' => 'SLIM LADIES 21603 WYPRZEDAŻ',
            'reference' => '',
            'brand' => null,
            'category' => 'Wyprzedaż',
            'short' => '',
            'description' => '<p>t-shirt damski</p>',
            'attachments' => [],
            'combinations' => [
                ['id' => '5835', 'ref' => '—', 'color' => '34', 'hex' => '', 'size' => 'XS', 'account' => 569, 'guest' => 813, 'stock' => 'available', 'images' => []],
                ['id' => '5841', 'ref' => '—', 'color' => '34', 'hex' => '', 'size' => 'S', 'account' => 569, 'guest' => 813, 'stock' => 'available', 'images' => []],
            ],
        ];
    }

    /**
     * Koszula, której indeksem każdej kombinacji jest sam numer modelu („93100”) — w dwóch kolorach.
     *
     * @return array<string, mixed>
     */
    private static function shirtWithModelReferences(): array
    {
        return [
            'id' => '63',
            'path' => '/koszule/63-river-93100.html',
            'name' => 'RIVER 93100',
            'reference' => '93100',
            'brand' => 'PROMOSTARS',
            'category' => 'Koszule',
            'short' => '',
            'description' => '<p>koszula męska</p>',
            'attachments' => [],
            'combinations' => [
                ['id' => '1088', 'ref' => '93100', 'color' => '20', 'hex' => '#ffffff', 'size' => 'S', 'account' => 6467, 'guest' => 9239, 'stock' => 'available', 'images' => []],
                ['id' => '1089', 'ref' => '93100', 'color' => '26', 'hex' => '#000000', 'size' => 'S', 'account' => 6467, 'guest' => 9239, 'stock' => 'available', 'images' => []],
            ],
        ];
    }

    // ---- atrapa sklepu ----

    private function fakeShop(): void
    {
        $listCalls = 0;
        Http::fake(function (Request $request) use (&$listCalls) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            preg_match('/PrestaShop-test=(\w+)/', $request->header('Cookie')[0] ?? '', $m);
            $session = $m[1] ?? '';
            $signedIn = in_array($session, $this->validSessions, true);
            $this->requests[] = ($signedIn ? '' : 'gość:').$path;
            $html = ['Content-Type' => 'text/html; charset=utf-8'];

            if ($path === '/logowanie') {
                if ($request->method() === 'GET') {
                    $this->logins++;

                    return Http::response(self::loginPage(), 200, [
                        ...$html,
                        'Set-Cookie' => 'PrestaShop-test=s'.$this->logins.'; path=/; secure; HttpOnly',
                    ]);
                }
                $data = $request->data();
                if (($data['email'] ?? null) !== self::LOGIN || ($data['password'] ?? null) !== self::PASSWORD) {
                    return Http::response(self::loginPage(), 200, $html);
                }
                $this->validSessions[] = $session;

                return Http::response(self::page('<div>Twoje konto</div>', account: true), 200, $html);
            }

            if ($path === '/moje-konto') {
                return Http::response($signedIn ? self::page('<div>Twoje konto</div>', account: true) : self::loginPage(), 200, $html);
            }

            if ($path === '/2-strona-glowna') {
                if (($request->header('X-Requested-With')[0] ?? '') !== 'XMLHttpRequest') {
                    return Http::response(self::page('<div>lista</div>', account: $signedIn), 200, $html);
                }
                $listCalls++;
                $products = array_values($this->products);
                if ($this->listChangesOnce && $listCalls === 2) {
                    // w trakcie pobierania doszedł wyrób — druga strona podaje inną liczbę
                    $products[] = self::shirtWithModelReferences();
                }
                $page = max(1, (int) ($query['page'] ?? 1));
                $items = array_slice($products, ($page - 1) * $this->pageSize, $this->pageSize);

                return Http::response([
                    'products' => array_map(static fn (array $p): array => [
                        'id_product' => $p['id'],
                        'url' => self::BASE.$p['path'].'#/1-rozmiar-s',
                        'name' => $p['name'],
                    ], $items),
                    'pagination' => [
                        'total_items' => count($products),
                        'pages_count' => (int) ceil(count($products) / $this->pageSize),
                        'current_page' => $page,
                    ],
                ], 200);
            }

            if ($path === '/index.php' && ($query['controller'] ?? '') === 'attachment') {
                return in_array($query['id_attachment'] ?? '', ['843', '900'], true)
                    // treść różna dla każdego pliku — jednakowe bajty zapis plików uznałby za ten sam plik
                    ? Http::response('%PDF-1.4 atrapa '.$query['id_attachment'], 200, ['Content-Type' => 'application/pdf'])
                    : Http::response(self::jpeg($url), 200, ['Content-Type' => 'image/jpeg']);
            }

            if (preg_match('#^/\d+-large_default/#', $path) === 1) {
                return Http::response(self::jpeg($path), 200, ['Content-Type' => 'image/jpeg']);
            }

            $product = $this->productAt($path);
            if ($product === null) {
                return Http::response('Nie znaleziono '.$url, 404, ['Content-Type' => 'text/html']);
            }
            if ($this->dropSessionOnProduct) {
                $this->dropSessionOnProduct = false;
                $this->validSessions = [];
                $signedIn = false;
            }
            if ($this->productsAlwaysAnonymous) {
                $signedIn = false;
            }
            $account = $signedIn || $this->guestPagesAreAccountPages;

            return Http::response(self::productPage($product, $account), 200, $html);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function productAt(string $path): ?array
    {
        foreach ($this->products as $product) {
            if ($path === $product['path']) {
                return $product;
            }
        }

        return null;
    }

    private static function loginPage(): string
    {
        return self::page(
            '<form action="https://mavibo.pl/logowanie?back=my-account" id="login-form" method="post">'
            .'<input type="hidden" name="back" value="my-account"><input type="email" name="email"><input type="password" name="password">'
            .'<input type="hidden" name="submitLogin" value="1"><button type="submit">Zaloguj się</button></form>',
            account: false,
        );
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private static function productPage(array $product, bool $account): string
    {
        $data = [
            'id_product' => (int) $product['id'],
            'id' => (int) $product['id'],
            'name' => $product['name'],
            'reference' => $product['reference'],
            'manufacturer_name' => $product['brand'],
            'category_name' => $product['category'],
            'description' => $product['description'],
            'description_short' => $product['short'],
            'features' => [],
            'attachments' => array_map(static fn (array $a): array => [
                'id_product' => $product['id'],
                'id_attachment' => $a['id'],
                'file' => sha1($a['id']),
                'file_name' => $a['file'],
                'mime' => $a['mime'],
                'name' => $a['name'],
                'description' => '',
            ], $product['attachments']),
        ];
        $body = '<section id="main"><h1>'.htmlspecialchars($product['name']).'</h1>'
            .'<div class="product-prices"><p class="product-without-taxes">77.50 zł netto</p></div>'
            .'<div id="product-details" data-product="'.htmlspecialchars((string) json_encode($data), ENT_QUOTES).'"></div>';

        $nbsp = "\u{00A0}";
        $money = static fn (int $cents): string => number_format($cents / 100, 2, ',', ' ').$nbsp.'zł';
        $table = '<div id="tablecombz-wrapper"><h4 class="tablecombz-filter">Wybierz atrybuty:</h4>'
            .'<form action="#tablecombz" method="post" name="tablecombz"><table class="tablecombz-table"><tbody>';
        $modals = '';
        if ($product['combinations'] === []) {
            $table .= '<tr><th class="first_item">Zdjęcie</th><th class="item">Indeks</th></tr>'
                .'<tr><td class="tablecombz-img"><div data-toggle="modal" data-target="#tablecombz-image-modal-0"><img src="https://mavibo.pl/1-small_default/x.jpg"></div></td>'
                .'<td class="tablecombz-reference"> '.htmlspecialchars($product['reference']).' </td></tr>';
        } else {
            $table .= '<tr><th class="first_item">Zdjęcie</th><th class="item">Indeks</th><th class="item">Kolor</th><th class="item">Rozmiar</th>'
                .'<th class="item">Cena netto</th><th class="item">Cena brutto</th><th class="item">Dostępny</th><th class="last_item">Kup</th></tr>';
            foreach ($product['combinations'] as $c) {
                $cents = $account ? $c['account'] : $c['guest'];
                $regular = $account ? ($c['account_regular'] ?? null) : ($c['guest_regular'] ?? null);
                $net = $regular !== null
                    ? '<div class="price price-lowered"><div><s>'.$money($regular).'</s></div><div>– 25%</div> '.$money($cents).' </div>'
                    : '<div class="price "> '.$money($cents).' </div>';
                $gross = '<div class="price "> '.$money((int) round($cents * 1.23)).' </div>';
                $orderable = $c['stock'] !== 'unavailable';
                $qtyId = $c['qty_id'] ?? $c['id'];
                $table .= '<tr>'
                    .'<td class="tablecombz-img"><div data-toggle="modal" data-target="#tablecombz-image-modal-'.$c['id'].'"><img src="https://mavibo.pl/1-small_default/x.jpg"></div></td>'
                    .'<td class="tablecombz-reference"> '.htmlspecialchars($c['ref']).' </td>'
                    .'<td class="tablecombz-attr-color"><a class="attr-color-picker" style="width: 20px;height: 20px;background: '.($c['hex'] !== '' ? $c['hex'] : 'url(/img/co/85.jpg)').';cursor: inherit;" title="'.$c['color'].'"> </a> <span>'.$c['color'].'</span></td>'
                    .'<td class="tablecombz-attr-color-label"> '.$c['size'].' </td>'
                    .'<td class="tablecombz-price-net tablecombz-discount"> '.$net.' </td>'
                    .'<td class="tablecombz-price tablecombz-discount"> '.$gross.' </td>'
                    .'<td class="tablecombz-avail"><img title="" class="tablecombz-avail-tooltip" src="/modules/tablecombz/views/img/'.$c['stock'].'.gif"></td>'
                    .'<td class="tablecombz-quantity-wanted">'.($orderable
                        ? '<div class="qty"><input class="input-group form-control input-quantity-wanted" type="text" name="qty['.$product['id'].'_'.$qtyId.']" value="0"></div>'
                        : ' — ').'</td>'
                    .'</tr>';
                $images = array_map(static fn (string $path): string => self::BASE.$path, $c['images']);
                $modals .= '<div class="modal fade tablecombz-image-modal" id="tablecombz-image-modal-'.$c['id'].'"><div class="modal-body">'
                    .($images !== [] ? '<figure><img class="js-modal-product-cover" width="1000" src="'.$images[0].'"></figure>' : '')
                    .'<ul class="product-images">'
                    .implode('', array_map(
                        static fn (string $u): string => '<li class="thumb-container"><img data-image-large-src="'.$u.'" src="'.str_replace('large_default', 'medium_default', $u).'"></li>',
                        $images,
                    ))
                    .'</ul></div></div>';
            }
            $table .= '<tr><td colspan="8" class="total"><u>Razem</u>: <span>0,0</span><div class="note"> Ceny brutto <br> VAT: 23,0%. </div></td></tr>';
        }
        $table .= '</tbody></table></form>'
            .'<div class="avail_descr"><img src="/modules/tablecombz/views/img/available.gif">&nbsp;Produkt dostępny<br>'
            .'<img src="/modules/tablecombz/views/img/unavailable.gif">&nbsp;Produkt niedostępny<br>'
            .'<img src="/modules/tablecombz/views/img/back-order.gif">&nbsp;Produkt z wydłużonym czasem dostawy </div></div>';

        return self::page($body.$table.$modals.'</section>', $account);
    }

    private static function page(string $body, bool $account): string
    {
        $prestashop = json_encode([
            'currency' => ['iso_code' => 'PLN'],
            'customer' => ['lastname' => $account ? 'Test' : null, 'is_logged' => $account],
        ]);

        return '<!doctype html><html lang="pl"><head><script type="text/javascript">var prestashop = '.$prestashop.';</script></head>'
            .'<body id="product">'.$body.'</body></html>';
    }

    private static function jpeg(string $path): string
    {
        $image = imagecreatetruecolor(1, 1);
        imagesetpixel($image, 0, 0, crc32($path) & 0xFFFFFF);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
