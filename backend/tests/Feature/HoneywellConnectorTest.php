<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDocumentSource;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bForeignLanguageSource;
use App\Services\B2b\B2bGroupsSizes;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bListProgressAware;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRunSummaryAware;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\B2bSizePriceSource;
use App\Services\B2b\HoneywellB2bClient;
use App\Services\B2b\HoneywellB2bConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Łącznik automation.honeywell.com na atrapie sklepu (Http::fake). Kształt odpowiedzi odwzorowuje sklep z 30.09.2026:
 * publiczna wyszukiwarka katalogu (dokumenty Product z polami {raw: …}, assets/resources jako JSON w stringu), lista
 * pozycji rodziny {strona}.pdpsearchsearvlet (zalogowany: id „PRD010~kod|kod sklepu”), strona produktu w sklepie
 * z tabelą pozycji (układ wiersza jak na żywo) i /pricecall z parami ref/variants.
 *
 * Logowanie w atrapie to ZAŁOŻENIE (PingFederate: formularz e-mail „subject”, potem formularz hasła) — na żywo
 * widziany był tylko pierwszy formularz; pierwszy przebieg na serwerze potwierdzi resztę.
 *
 * Wszystkie dane (kody, EAN, ceny, opisy) są SYNTETYCZNE.
 */
final class HoneywellConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'zakupy@example.com';

    private const PASSWORD = 'dobre-haslo';

    private const SCENE7 = 'https://honeywell.scene7.com/is/image/Honeywell65/';

    private const EDAM = 'https://prod-edam.honeywell.com/content/dam/honeywell-edam/sps/his/en-gb/docs/';

    /** @var array<string, array<string, mixed>> rodziny wg numeru */
    private array $families = [];

    /** @var array<string, list<array<string, string>>> pozycje konta wg numeru rodziny (kod, kod sklepu, opis, wiersz) */
    private array $skus = [];

    /** @var array<string, array{listPrice: string, netprice: string, discount: string, error: string|null}> wg ref */
    private array $prices = [];

    private bool $loggedIn = false;

    private int $logins = 0;

    private bool $dropSessionOnce = false;

    private bool $alwaysGuest = false;

    private bool $asksCode = false;

    private bool $noShopRows = false;

    private bool $pricesFail = false;

    private bool $shopGuestOnce = false;

    private bool $foreignRedirect = false;

    /** @var array<string, mixed>|null odpowiedź listy pozycji zamiast właściwej */
    private ?array $pdpAnswer = null;

    /** Powrót z logowania adresami „http://…” i „https://…:443” (tak odsyła Honeywell). */
    private bool $plainCallback = false;

    /** Numer rodziny, której lista pozycji odpowiada błędem serwera. */
    private string $pdpFailFor = '';

    /** Kod produktu w sklepie, którego strona (zalogowana) nie ma tabeli pozycji. */
    private string $tableMissingFor = '';

    /** Kod produktu w sklepie, którego /pricecall odpowiada stroną HTML (sesja ważna). */
    private string $pricesHtmlFor = '';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    // ---- logowanie ----

    public function test_login_posts_the_email_then_the_password_form_and_checks_the_session(): void
    {
        $this->fakeSite();
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $identifier = Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/resume/as/authorization.ping') && isset($r->data()['subject']))->first()[0];
        $this->assertSame(self::EMAIL, $identifier->data()['subject']);
        $this->assertSame('false', $identifier->data()['cancel.identifier.selection']);
        $password = Http::recorded(fn (Request $r): bool => isset($r->data()['pf.pass']))->first()[0];
        $this->assertEqualsCanonicalizing(['pf.username' => self::EMAIL, 'pf.pass' => self::PASSWORD, 'pf.ok' => 'clicked', 'pf.cancel' => ''], $password->data());
        $this->assertTrue(Http::recorded(fn (Request $r): bool => $r->url() === 'https://automation.honeywell.com/shop/honeywell/en/')->isNotEmpty());
    }

    public function test_wrong_password_fails_with_the_page_message_and_is_not_fatal(): void
    {
        $this->fakeSite();
        $client = new HoneywellB2bClient(self::EMAIL, 'zle-haslo', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (B2bFatalException $e) {
            $this->fail('złe hasło to nie błąd krytyczny: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('odrzucił hasło', $e->getMessage());
            $this->assertStringContainsString('We didn', $e->getMessage());
        }
        $this->assertFalse($client->isLoggedIn());
    }

    public function test_a_second_factor_page_stops_the_login_with_a_clear_message(): void
    {
        $this->fakeSite();
        $this->asksCode = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('drugi składnik');

        $this->client()->login();
    }

    public function test_login_never_follows_a_redirect_outside_honeywell(): void
    {
        $this->fakeSite();
        $this->foreignRedirect = true;

        try {
            $this->client()->login();
            $this->fail('przekierowanie na obcy host powinno przerwać logowanie');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('poza Honeywell', $e->getMessage());
        }
        $this->assertTrue(Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'evil.example.com'))->isEmpty());
    }

    // ---- odczyt strony sklepu ----

    public function test_shop_rows_read_code_ref_ean_size_price_unit_and_packaging_literally(): void
    {
        $rows = HoneywellB2bConnector::shopRows($this->shopPageHtml([
            self::row('T9999/7S', 'PRD010~T9999-07S', '1234567890123', 'SYNTH NITRILE FC S 7', '7S', '50.28', '1 BOX', 'box', '(12&nbsp;pair)'),
            self::row('T9999/8M', 'PRD010~T9999-08M', '', 'Synth nitrile glove.', '8M', '50.28', '1 BOX', 'box', '(12&nbsp;pair)'),
        ]));

        $this->assertSame(['T9999/7S', 'T9999/8M'], array_keys($rows));
        $row = $rows['T9999/7S'];
        $this->assertSame('PRD010~T9999-07S', $row['ref']);
        $this->assertSame('1234567890123', $row['ean']);
        $this->assertSame('SYNTH NITRILE FC S 7', $row['description']);
        $this->assertSame('7S', $row['size']);
        $this->assertSame('50.28', $row['list_price']);
        $this->assertSame('1 BOX', $row['price_unit']);
        $this->assertSame('Min 1 box (12 pair)', $row['min_text']);
        $this->assertSame('1', $row['min_qty']);
        $this->assertSame('India', $row['origin']);
        $this->assertSame('France', $row['ships_from']);
        $this->assertSame('FR50 - Honeywell Safety Products France', $row['entity']);
        $this->assertSame('', $rows['T9999/8M']['ean']);
        $this->assertStringNotContainsString('CSRF', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public function test_packaging_reads_the_price_unit_pack_contents_and_minimum_literally(): void
    {
        $this->assertSame(
            ['word' => 'box', 'pack' => 'box', 'min_packs' => 1.0, 'contents' => 12.0, 'inner' => 'pair', 'step' => 1.0],
            HoneywellB2bConnector::packaging('1 BOX', 'Min 1 box (12 pair)', '1', '1'),
        );
        $this->assertSame(
            ['word' => 'case', 'pack' => 'case', 'min_packs' => 1.0, 'contents' => 27.0, 'inner' => 'each', 'step' => null],
            HoneywellB2bConnector::packaging('1 CAS', 'Min 1 case (27 each)'),
        );
        $this->assertSame(
            ['word' => 'each', 'pack' => 'each', 'min_packs' => null, 'contents' => null, 'inner' => null, 'step' => null],
            HoneywellB2bConnector::packaging('1 EA', 'Min each'),
        );
        $this->assertNull(HoneywellB2bConnector::packaging('2 BOX', 'Min 1 box (12 pair)')['word']);
    }

    public function test_price_per_pair_keeps_the_shop_price_in_the_note_and_orders_whole_boxes(): void
    {
        $offer = HoneywellB2bConnector::offer(['net' => 25.68, 'list' => 50.28, 'discount' => 49.0], self::rowData('T9999/7S', '7S', '1 BOX', 'Min 1 box (12 pair)'));

        $this->assertSame('unit|pair', $offer['mode'] ?? null);
        $price = $offer['price'];
        $this->assertSame(2.14, $price->net);
        $this->assertSame(4.19, $price->base);
        $this->assertSame('EUR', $price->currency);
        $this->assertSame(49.0, $price->discountPercent);
        $this->assertSame(12.0, $price->order?->min);
        $this->assertSame(12.0, $price->order?->step);
        $this->assertSame('par', $price->order?->unit);
        $this->assertSame('Sklep Honeywell: 25,68 EUR za 1 BOX (Min 1 box (12 pair)); cena przeliczona na 1 pair', $price->condition?->note);
        $this->assertSame(12.0, $price->condition?->cartonQty);
    }

    public function test_pair_price_that_rounding_would_distort_stays_per_box_with_a_note(): void
    {
        // 33,20 € / 400 par = 0,083 → 0,08 × 400 = 32,00 (−3,6%) — cena zostaje za pudełko
        $offer = HoneywellB2bConnector::offer(['net' => 33.2, 'list' => 80.0, 'discount' => 58.5], self::rowData('9999203-AM', 'One Size', '1 BOX', 'Min 1 box (400 pair)'));

        $this->assertSame('pack|box', $offer['mode'] ?? null);
        $this->assertSame(33.2, $offer['price']->net);
        $this->assertSame(80.0, $offer['price']->base);
        $this->assertSame('op.', $offer['price']->order?->unit);
        $this->assertNull($offer['price']->condition?->cartonQty);
        $this->assertStringContainsString('zaokrąglenie do centa', (string) $offer['price']->condition?->note);

        // 22,00 € / 100 par = 0,22 dokładnie — przeliczona
        $exact = HoneywellB2bConnector::offer(['net' => 22.0, 'list' => 54.96, 'discount' => 60.0], self::rowData('3399130', 'One Size', '1 BOX', 'Min 1 box (100 pair)'));
        $this->assertSame('unit|pair', $exact['mode'] ?? null);
        $this->assertSame(0.22, $exact['price']->net);
    }

    public function test_box_without_contents_is_left_out_and_pair_or_piece_prices_stay_as_given(): void
    {
        $this->assertNull(HoneywellB2bConnector::offer(['net' => 30.0, 'list' => null, 'discount' => 0.0], self::rowData('X1', 'One Size', '1 BOX', 'Min 1 box')));
        $this->assertNull(HoneywellB2bConnector::offer(['net' => 30.0, 'list' => null, 'discount' => 0.0], self::rowData('X2', 'One Size', '1 CAS', 'Min 1 box (10 each)')));

        $pair = HoneywellB2bConnector::offer(['net' => 51.73, 'list' => 81.18, 'discount' => 36.5], self::rowData('6299850-42/7', '42', '1 PR', 'Min 1 pair'));
        $this->assertSame('unit|pair', $pair['mode'] ?? null);
        $this->assertSame(51.73, $pair['price']->net);
        $this->assertNull($pair['price']->condition?->note);
        $this->assertFalse($pair['price']->order?->restricts());

        // cena za sztukę, zamawia się pełne pudełka po 10
        $piece = HoneywellB2bConnector::offer(['net' => 3.5, 'list' => 7.0, 'discount' => 50.0], self::rowData('X3', 'One Size', '1 EA', 'Min 1 box (10 each)'));
        $this->assertSame(3.5, $piece['price']->net);
        $this->assertSame(10.0, $piece['price']->order?->min);
        $this->assertSame(10.0, $piece['price']->order?->step);
        $this->assertSame('szt.', $piece['price']->order?->unit);
    }

    public function test_size_groups_come_only_from_code_size_and_description(): void
    {
        $groups = HoneywellB2bConnector::sizeGroups([
            // A: rozmiar z kolumny Size jako segment kodu, różne opisy i różne warunki zamawiania
            self::rowData('T9999/6XS', '6XS', '1 BOX', 'Min 1 box (12 pair)', 'Synth glove'),
            self::rowData('T9999/7S', '7S', '1 BOX', 'Min 1 box (10 pair)', 'SYNTH FC S 7'),
            // A: rozmiar w środku kodu
            self::rowData('6299850-37/7', '37', '1 PR', 'Min 1 pair', 'Boot'), self::rowData('6299850-38/7', '38', '1 PR', 'Min 1 pair', 'Boot'),
            // A: ten sam przedrostek, inny szablon — osobna karta rozmiarów
            self::rowData('6299850-37/9', '37', '1 PR', 'Min 1 pair', 'Boot ESD'),
            // B: One Size, rozmiar rękawic jako ostatni segment, ten sam opis
            self::rowData('CS99-7518B-6', 'One Size', '1 BAG', 'Min 1 bag (10 pair)', 'cut glove, size 6'),
            self::rowData('CS99-7518B-7', 'One Size', '1 BAG', 'Min 1 bag (10 pair)', 'cut glove, size 6'),
            // wariant „-V-” to inny wyrób
            self::rowData('CS99-7518B-V-6', 'One Size', '1 BAG', 'Min 1 bag (10 pair)', 'cut glove, size 6'),
            // kod bez separatora, ostatni segment poza rozmiarami, litery nie-rozmiar — osobno
            self::rowData('3399130', 'One Size', '1 BOX', 'Min 1 box (100 pair)', 'Foam earplugs'),
            self::rowData('MXX-1', 'One Size', '1 BOX', 'Min 1 box (100 pair)', 'Uncorded'),
            self::rowData('MXX-30', 'One Size', '1 BOX', 'Min 1 box (100 pair)', 'Uncorded'),
            self::rowData('9999203-AM', 'One Size', '1 BOX', 'Min 1 box (400 pair)', 'Dispenser'),
        ]);

        $this->assertSame(
            [
                ['A', ['T9999/6XS', 'T9999/7S']],
                ['A', ['6299850-37/7', '6299850-38/7']],
                ['A', ['6299850-37/9']],
                ['B', ['CS99-7518B-6', 'CS99-7518B-7']],
                ['B', ['CS99-7518B-V-6']],
                ['single', ['3399130']],
                ['single', ['MXX-1']],
                ['single', ['MXX-30']],
                ['single', ['9999203-AM']],
            ],
            array_map(static fn (array $g): array => [$g['kind'], array_column($g['rows'], 'code')], $groups),
        );
    }

    public function test_the_same_size_twice_is_not_a_size_table(): void
    {
        $groups = HoneywellB2bConnector::sizeGroups([
            self::rowData('A1-8', '8', '1 PR', 'Min 1 pair'),
            self::rowData('A1-08', '8', '1 PR', 'Min 1 pair'),
        ]);

        $this->assertSame(['single', 'single'], array_column($groups, 'kind'));
    }

    // ---- przebieg łącznika ----

    public function test_families_become_cards_with_sizes_per_pair_prices_and_families_outside_the_account_are_left_out(): void
    {
        $this->addGloveFamily();
        $this->addGlassesFamily();
        $this->addFamily('3000003', 'Synth Outside', '/personal-protective-equipment/eye-protection/synth-outside', []);
        $this->addDetectorFamily();
        $this->fakeSite();

        $products = $this->products();

        $bySku = [];
        foreach ($products as $product) {
            $bySku[$product->sku] = $product;
        }
        // rodziny w kolejności numerów; SKU karty rozmiarów = pełny kod najmniejszej pozycji
        $this->assertSame(['T9999/7S', 'SY100-1', 'SY100-2', 'SYICON-B'], array_keys($bySku));

        $gloves = $bySku['T9999/7S'];
        $this->assertSame('T9999/7S', $gloves->remoteId);
        $this->assertSame('Synth Superlite T9999 – Nitrile glove on cotton base.', $gloves->name);
        $this->assertSame(['T9999/7S', 'T9999/8M'], array_column($gloves->members, 'remote_id'));
        $this->assertSame(['7S', '8M'], array_column($gloves->members, 'size'));
        // pozycja wiodąca nosi nazwę karty (tłumaczenie nazwy nowej karty)
        $this->assertSame($gloves->name, $gloves->members[0]['name']);
        $this->assertSame([2.14, 2.2], array_map(static fn (array $m): float => $m['price']->net, $gloves->members));
        $this->assertSame('Hand Protection › Gloves', $gloves->category);
        $this->assertSame('Rozmiary: 7S (T9999/7S, EAN 1234567890123); 8M (T9999/8M, EAN 1234567890124)', $gloves->variantSummary);
        $this->assertSame('https://automation.honeywell.com/gb/en/products/personal-protective-equipment/hand-protection/gloves/synth-superlite-t9999', $gloves->sourceUrl);

        $glasses = $bySku['SY100-1'];
        $this->assertSame('Synth A100 Series SY100-1 – A100 Clear Anti-scratch', $glasses->name);
        $this->assertSame([], $glasses->members);
        $this->assertSame('Kod: SY100-1; EAN: 1234567890201', $glasses->variantSummary);
        // opis pozycji wersalikami (skrót SAP) nie idzie do nazwy
        $this->assertSame('Synth BW Icon SYICON-B', $bySku['SYICON-B']->name);

        $connector = $this->connector();
        $price = $connector->price($gloves);
        $this->assertSame(2.14, $price?->net);
        $this->assertSame('EUR', $price?->currency);
        $this->assertSame('Honeywell', $connector->manufacturer($gloves));
        $this->assertStringContainsString('Nitrile glove on cotton base.', $connector->description($gloves));
        $this->assertStringContainsString('Features:', $connector->description($gloves));
        $this->assertSame([self::SCENE7.'synth-t9999-main', self::SCENE7.'synth-t9999-palm'], $connector->imageUrls($gloves));
        $docs = $connector->documents($gloves);
        $this->assertSame([ProductDocument::KIND_DATASHEET, ProductDocument::KIND_SIZE_CHART], array_map(static fn ($d): string => $d->kind, $docs));

        $summary = implode("\n", $this->lastConnector->runSummary());
        $this->assertStringContainsString('1 poza kontem', $summary);
        $this->assertStringContainsString('SY100-3', $summary); // pozycja bez ceny
    }

    public function test_shop_fields_list_codes_sizes_eans_and_packaging_from_the_shop(): void
    {
        $this->addGloveFamily();
        $this->fakeSite();

        $gloves = $this->products()[0];
        $fields = [];
        foreach ($this->lastConnector->shopFields($gloves) as $field) {
            $fields[$field->name] = $field->value;
        }

        $this->assertSame('T9999/7S; T9999/8M', $fields['Kody pozycji']);
        $this->assertSame('T9999/7S: 7S; T9999/8M: 8M', $fields['Rozmiar']);
        $this->assertSame('T9999/7S: 1234567890123; T9999/8M: 1234567890124', $fields['EAN']);
        $this->assertSame('1 BOX', $fields['Jednostka ceny w sklepie']);
        $this->assertSame('Min 1 box (12 pair)', $fields['Opakowanie / minimum zamówienia']);
        $this->assertSame('India', $fields['Kraj pochodzenia']);
    }

    public function test_session_lost_on_the_account_list_logs_in_again_once(): void
    {
        $this->addGloveFamily();
        $this->fakeSite();
        $this->dropSessionOnce = true;

        $products = $this->products();

        $this->assertCount(1, $products);
        $this->assertSame(2, $this->logins);
    }

    public function test_account_list_never_signed_in_is_fatal(): void
    {
        $this->addGloveFamily();
        $this->fakeSite();
        $this->alwaysGuest = true;

        $this->expectException(B2bFatalException::class);

        $this->products();
    }

    public function test_many_families_without_shop_rows_stop_the_run_before_cards_lose_positions(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $id = (string) (4000000 + $i);
            $this->addFamily($id, 'Synth '.$i, '/personal-protective-equipment/hand-protection/synth-'.$i, [
                ['code' => 'Z'.$i, 'shop' => 'Synth '.$i.'0000', 'row' => self::row('Z'.$i, 'PRD010~Z'.$i, '', 'Z', 'One Size', '1', '1 EA', 'each', '')],
            ]);
            $this->prices['PRD010~Z'.$i] = ['listPrice' => '2.00', 'netprice' => '1.00', 'discount' => '50', 'error' => null];
        }
        $this->fakeSite();
        $this->noShopRows = true;

        $this->expectException(B2bFatalException::class);
        $this->expectExceptionMessage('bez tabeli pozycji');

        $this->products();
    }

    public function test_sync_creates_cards_with_size_rows_prices_identifiers_and_a_second_run_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addGloveFamily();
        $this->addGlassesFamily();
        $this->fakeSite();

        $result = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(3, $result['created'], implode(' | ', $result['errors']));
        $gloves = Product::query()->where('sku', 'T9999/7S')->sole();
        $this->assertSame('Honeywell', $gloves->manufacturer);
        // nazwa karty = nazwa ze źródła na powiązaniu pozycji wiodącej — tłumaczenie nazwy nowej karty ją rozpozna
        $this->assertSame($gloves->name, B2bProductLink::query()->where('remote_id', 'T9999/7S')->value('remote_name'));
        $this->assertSame(['T9999/7S', 'T9999/8M'], B2bProductLink::query()->where('product_id', $gloves->id)->orderBy('remote_id')->pluck('remote_id')->all());
        $slot = ProductSourcePrice::query()->where('product_id', $gloves->id)->sole();
        $this->assertSame('EUR', $slot->currency);
        $this->assertSame('2.14', (string) $slot->purchase_price);
        $this->assertSame('2.20', (string) $slot->size_price_max);
        $this->assertSame(12.0, (float) $slot->order_step_qty);
        $this->assertSame('par', $slot->order_unit);
        $this->assertStringContainsString('1 BOX', (string) $slot->price_note);
        $this->assertSame(
            [['7S', '2.14'], ['8M', '2.20']],
            ProductVariant::query()->where('product_id', $gloves->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('sort_order')->get()
                ->map(static fn (ProductVariant $v): array => [$v->label, (string) $v->purchase_price])->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['1234567890123', '1234567890124', 'T9999/7S', 'T9999/8M'],
            ProductIdentifier::query()->where('product_id', $gloves->id)->pluck('value')->all(),
        );
        $this->assertTrue(ProductShopCard::query()->where('product_id', $gloves->id)->exists());
        $this->assertSame(
            [self::SCENE7.'synth-t9999-main', self::SCENE7.'synth-t9999-palm'],
            ProductImage::query()->where('product_id', $gloves->id)->orderBy('sort_order')->pluck('source_url')->all(),
        );

        $before = $this->snapshot();
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: true);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['updated'], implode(' | ', $second['errors']));
        $this->assertSame(3, $second['unchanged'], implode(' | ', $second['errors']));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_price_failure_of_a_family_skips_its_positions_without_touching_their_cards(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addGloveFamily();
        $this->fakeSite();
        $first = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);
        $this->assertSame(1, $first['created'], implode(' | ', $first['errors']));
        $before = $this->snapshot();

        $this->pricesFail = true;
        $broken = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(0, $broken['created']);
        $this->assertSame(0, $broken['updated']);
        $this->assertNotEmpty($broken['errors']);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());
        $this->assertSame(0, ProductVariant::query()->whereNotNull('removed_at')->count());
    }

    public function test_a_size_without_price_in_the_next_run_does_not_split_the_card(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->addGloveFamily();
        $this->fakeSite();
        $first = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);
        $this->assertSame(1, $first['created'], implode(' | ', $first['errors']));

        $this->prices['PRD010~T9999-08M']['error'] = 'No price';
        $second = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(1, Product::query()->count());
        $gloves = Product::query()->sole();
        $this->assertSame(['T9999/7S', 'T9999/8M'], B2bProductLink::query()->where('product_id', $gloves->id)->orderBy('remote_id')->pluck('remote_id')->all());
        $this->assertSame('2.14', (string) ProductSourcePrice::query()->where('product_id', $gloves->id)->value('purchase_price'));

        $this->prices['PRD010~T9999-08M']['error'] = null;
        $third = app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);
        $this->assertSame(0, $third['created'], implode(' | ', $third['errors']));
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(
            ['7S', '8M'],
            ProductVariant::query()->where('product_id', $gloves->id)->where('kind', ProductVariant::KIND_SIZE)->whereNull('removed_at')->orderBy('sort_order')->pluck('label')->all(),
        );
    }

    public function test_a_code_listed_in_two_families_becomes_one_card_from_the_first_family(): void
    {
        $this->addGlassesFamily();
        $this->addFamily('3000009', 'Synth A100 Accessories', '/personal-protective-equipment/eye-protection/synth-a100-acc', [
            ['code' => 'SY100-1', 'shop' => 'Synth A100 Accessories0000', 'row' => self::row('SY100-1', 'PRD010~SY100-1', '1234567890201', 'A100 Clear Anti-scratch', 'One Size', '9.00', '1 EA', 'each', '')],
        ]);
        $this->fakeSite();

        $products = $this->products();

        $this->assertSame(['SY100-1', 'SY100-2'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $this->assertStringContainsString('Pozycje w kilku rodzinach (karta z pierwszej): 1', implode("\n", $this->lastConnector->runSummary()));
    }

    public function test_account_list_error_of_one_family_does_not_stop_the_others(): void
    {
        $this->addGloveFamily();
        $this->addGlassesFamily();
        $this->fakeSite();
        $this->pdpFailFor = '3000001';

        $products = $this->products();

        $this->assertSame(['SY100-1', 'SY100-2'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, $products));
        $this->assertStringContainsString('Synth Superlite T9999 (lista pozycji', implode("\n", $this->lastConnector->runSummary()));
    }

    public function test_shop_page_without_the_table_logs_in_again_once(): void
    {
        $this->addGloveFamily();
        $this->fakeSite();
        $this->shopGuestOnce = true;

        $products = $this->products();

        $this->assertSame('T9999/7S', $products[0]->sku);
        $this->assertSame(2, $this->logins);
    }

    public function test_signed_in_shop_page_without_the_table_fails_only_that_family_without_logging_in_again(): void
    {
        $this->addGloveFamily();
        $this->addGlassesFamily();
        $this->fakeSite();
        $this->tableMissingFor = 'Synth Superlite T99990000';

        $products = $this->products();

        $this->assertSame(1, $this->logins);
        // każda pozycja rodziny osobno pominięta — różne wyroby nigdy w jednej grupie
        foreach ([0 => 'T9999/7S', 1 => 'T9999/8M'] as $index => $code) {
            $this->assertSame('skipped', $products[$index]->raw['status']);
            $this->assertSame($code, $products[$index]->remoteId);
            $this->assertSame([], $products[$index]->members);
        }
        $this->assertSame(['SY100-1', 'SY100-2'], array_map(static fn (B2bRemoteProduct $p): string => $p->sku, array_slice($products, 2)));
        // opis, pliki i tabelka pominiętej pozycji nie są „puste” — synchronizacja zostawia kartę bez zmian
        $this->expectException(RuntimeException::class);
        $this->lastConnector->description($products[0]);
    }

    public function test_login_follows_honeywell_redirects_written_as_http_or_with_a_port_over_https_only(): void
    {
        // pierwszy przebieg 30.09.2026: „przekierowanie poza Honeywell: automation.honeywell.com”
        $this->fakeSite();
        $this->plainCallback = true;
        $client = $this->client();

        $client->login();

        $this->assertTrue($client->isLoggedIn());
        $this->assertTrue(Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/pif/cwa/oauth/callback/'))->isNotEmpty());
        $this->assertTrue(Http::recorded(fn (Request $r): bool => ! str_starts_with($r->url(), 'https://') || str_contains($r->url(), ':443'))->isEmpty());
    }

    public function test_login_reads_the_account_like_the_page_and_sends_site_cookies_with_the_position_list(): void
    {
        $this->addGloveFamily();
        $this->fakeSite();

        $this->products();

        foreach (['/pif/api/soldto/favorite/v1/user', '/pif/api/account/v1/status'] as $path) {
            $this->assertTrue(Http::recorded(fn (Request $r): bool => str_contains($r->url(), $path))->isNotEmpty(), $path);
        }
        $list = Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '.pdpsearchsearvlet'))->first()[0];
        $this->assertStringContainsString('dtm=pl', $list->header('Cookie')[0] ?? '');
        $this->assertStringContainsString('b2bunit=synth-unit', $list->header('Cookie')[0] ?? '');
        $this->assertStringEndsWith('/synth-superlite-t9999?pdpPageTab=pills-sku-tab', $list->header('Referer')[0] ?? '');
    }

    public function test_unreadable_position_list_says_what_the_shop_answered(): void
    {
        $this->addGloveFamily();
        $this->addGlassesFamily();
        $this->fakeSite();
        $this->pdpAnswer = ['success' => false, 'message' => 'Sold-to not selected'];

        $this->products();

        $summary = implode("\n", $this->lastConnector->runSummary());
        $this->assertStringContainsString('nieczytelna odpowiedź (HTTP 200, application/json, JSON success=false, komunikat: Sold-to not selected)', $summary);
    }

    public function test_login_never_submits_ordinary_shop_forms_with_hidden_fields(): void
    {
        $this->fakeSite();

        $this->client()->login();

        $this->assertTrue(Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/_s/currency'))->isEmpty());
    }

    public function test_unreadable_price_answer_with_a_valid_session_fails_only_that_family(): void
    {
        $this->addGloveFamily();
        $this->addGlassesFamily();
        $this->fakeSite();
        $this->pricesHtmlFor = 'Synth Superlite T99990000';

        $products = $this->products();

        $this->assertSame(['skipped', 'skipped', 'ok', 'ok'], array_map(static fn (B2bRemoteProduct $p): string => $p->raw['status'], $products));
        $this->assertSame(1, $this->logins);
    }

    public function test_price_with_a_thousands_separator_is_read(): void
    {
        $this->addDetectorFamily();
        $this->prices['PRD010~SYICON-B']['listPrice'] = '1,250.00';
        $this->prices['PRD010~SYICON-B']['netprice'] = '1,062.50';
        $this->addGloveFamily();
        $this->fakeSite();

        $detector = array_values(array_filter($this->products(), static fn (B2bRemoteProduct $p): bool => $p->sku === 'SYICON-B'))[0];

        $price = $this->lastConnector->price($detector);
        $this->assertSame(1062.5, $price?->net);
        $this->assertSame(1250.0, $price?->base);
    }

    public function test_login_error_never_shows_the_password(): void
    {
        $this->fakeSite();
        $client = new HoneywellB2bClient(self::EMAIL, 'tajne-haslo-123', 0, static function (int $ms): void {});

        try {
            $client->login();
            $this->fail('logowanie złym hasłem powinno się nie udać');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('tajne-haslo-123', $e->getMessage());
        }
    }

    public function test_registry_detects_honeywell_by_host_as_the_manufacturer_site(): void
    {
        $registry = app(B2bConnectorRegistry::class);

        $this->assertSame('honeywell', $registry->keyForSites(['https://automation.honeywell.com/gb/en']));
        $this->assertSame('Honeywell', $registry->label('honeywell'));
        $this->assertTrue($registry->requiresPassword('honeywell'));
        $this->assertTrue($registry->isManufacturerSite('honeywell'));

        $account = B2bAccount::query()->create([
            'username' => self::EMAIL, 'password' => 'sekret', 'sites' => ['https://automation.honeywell.com/gb/en'],
        ]);
        $connector = $registry->make($account, 0);

        $this->assertInstanceOf(HoneywellB2bConnector::class, $connector);
        foreach ([B2bManufacturerSite::class, B2bForeignLanguageSource::class, B2bGroupsSizes::class, B2bSizePriceSource::class, B2bImageGallery::class, B2bDocumentSource::class, B2bShopFieldSource::class, B2bRunSummaryAware::class, B2bListProgressAware::class] as $interface) {
            $this->assertInstanceOf($interface, $connector);
        }
    }

    // ---- pomocnicze ----

    private ?HoneywellB2bConnector $lastConnector = null;

    private function client(): HoneywellB2bClient
    {
        return new HoneywellB2bClient(self::EMAIL, self::PASSWORD, 0, static function (int $ms): void {});
    }

    private function connector(): HoneywellB2bConnector
    {
        $this->lastConnector ??= new HoneywellB2bConnector($this->client(), 2);

        return $this->lastConnector;
    }

    /**
     * @return list<B2bRemoteProduct>
     */
    private function products(): array
    {
        $connector = $this->connector();
        $connector->login();

        return iterator_to_array($connector->products(), false);
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => self::EMAIL],
            ['password' => self::PASSWORD, 'sites' => ['https://automation.honeywell.com/gb/en'], 'connector' => 'honeywell', 'sync_images' => true],
        )->fresh();
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
            'price' => ProductSourcePrice::query()->where('product_id', $p->id)->get(['purchase_price', 'catalog_price_net', 'size_price_max', 'price_note', 'order_min_qty', 'order_step_qty'])->toArray(),
            'sizes' => ProductVariant::query()->where('product_id', $p->id)->orderBy('id')->get(['label', 'purchase_price'])->toArray(),
            'documents' => ProductDocument::query()->where('product_id', $p->id)->orderBy('id')->pluck('source_url')->all(),
        ]])->all();
    }

    private function addGloveFamily(): void
    {
        $this->addFamily('3000001', 'Synth Superlite T9999', '/personal-protective-equipment/hand-protection/gloves/synth-superlite-t9999', [
            ['code' => 'T9999/7S', 'shop' => 'Synth Superlite T99990000', 'row' => self::row('T9999/7S', 'PRD010~T9999-07S', '1234567890123', 'SYNTH NITRILE FC S 7', '7S', '50.28', '1 BOX', 'box', '(12&nbsp;pair)')],
            ['code' => 'T9999/8M', 'shop' => 'Synth Superlite T99990000', 'row' => self::row('T9999/8M', 'PRD010~T9999-08M', '1234567890124', 'Nitrile glove on cotton base.', '8M', '50.28', '1 BOX', 'box', '(12&nbsp;pair)')],
        ], [
            'short_description' => 'Nitrile glove on cotton base.',
            'long_description' => '<b>Features:</b><BR />Fully dipped.<BR />&nbsp;',
            'line_of_business' => 'Hand Protection',
            'product_family' => 'Gloves',
            'assets' => [
                ['type' => 'product-image-primary', 'url' => self::SCENE7.'synth-t9999-main', 'targetMarket' => ['gb']],
                ['type' => 'Additional_Images', 'url' => self::SCENE7.'synth-t9999-palm', 'targetMarket' => ['gb']],
                ['type' => 'product-image-primary', 'url' => self::SCENE7.'synth-t9999-us', 'targetMarket' => ['us']],
                ['type' => 'Video1', 'url' => self::SCENE7.'synth-t9999-video', 'targetMarket' => ['gb'], 'hasVideo' => true],
            ],
            'resources' => [
                ['name' => 'T9999 Data Sheet', 'url' => self::EDAM.'t9999-datasheet.pdf', 'category' => 'Data Sheet', 'format' => 'application/pdf', 'targetMarket' => ['gb']],
                ['name' => 'T9999 Glove Size Chart', 'url' => self::EDAM.'t9999-size-chart.pdf', 'category' => 'Manuals and Guides', 'format' => 'application/pdf', 'targetMarket' => ['gb']],
                ['name' => 'T9999 Datenblatt', 'url' => self::EDAM.'t9999-de.pdf', 'category' => 'Data Sheet', 'format' => 'application/pdf', 'targetMarket' => ['de']],
                ['name' => 'Firmware', 'url' => self::EDAM.'fw.pdf', 'category' => 'Firmware', 'format' => 'application/pdf', 'targetMarket' => ['gb']],
            ],
        ]);
        $this->prices['PRD010~T9999-07S'] = ['listPrice' => '50.28', 'netprice' => '25.68', 'discount' => '49', 'error' => null];
        $this->prices['PRD010~T9999-08M'] = ['listPrice' => '52.80', 'netprice' => '26.40', 'discount' => '50', 'error' => null];
    }

    private function addGlassesFamily(): void
    {
        $this->addFamily('3000002', 'Synth A100 Series', '/personal-protective-equipment/eye-protection/synth-a100', [
            ['code' => 'SY100-1', 'shop' => 'Synth A1000000', 'row' => self::row('SY100-1', 'PRD010~SY100-1', '1234567890201', 'A100 Clear Anti-scratch', 'One Size', '9.00', '1 EA', 'each', '')],
            ['code' => 'SY100-2', 'shop' => 'Synth A1000000', 'row' => self::row('SY100-2', 'PRD010~SY100-2', '1234567890202', 'A100 Yellow Anti-scratch', 'One Size', '9.00', '1 EA', 'each', '')],
            ['code' => 'SY100-3', 'shop' => 'Synth A1000000', 'row' => self::row('SY100-3', 'PRD010~SY100-3', '1234567890203', 'A100 Grey Anti-scratch', 'One Size', '9.00', '1 EA', 'each', '')],
        ], ['line_of_business' => 'Eye Protection', 'product_family' => 'Safety Eyewear']);
        $this->prices['PRD010~SY100-1'] = ['listPrice' => '9.00', 'netprice' => '4.50', 'discount' => '50', 'error' => null];
        $this->prices['PRD010~SY100-2'] = ['listPrice' => '9.00', 'netprice' => '4.60', 'discount' => '49', 'error' => null];
        $this->prices['PRD010~SY100-3'] = ['listPrice' => '9.00', 'netprice' => '0.00', 'discount' => '0', 'error' => 'No price'];
    }

    private function addDetectorFamily(): void
    {
        $this->addFamily('3000004', 'Synth BW Icon', '/sensing-solutions/gas-and-flame-detection/portables/multi-gas/synth-bw-icon', [
            ['code' => 'SYICON-B', 'shop' => 'Synth BW Icon0000', 'row' => self::row('SYICON-B', 'PRD010~SYICON-B', '', 'SYNTH ICON 4-GAS, BLACK', 'One Size', '600.00', '1 EA', 'each', '')],
        ], ['line_of_business' => 'Gas & Flame Detection', 'product_family' => 'Portables', 'sbu' => 'Sensing Solutions']);
        $this->prices['PRD010~SYICON-B'] = ['listPrice' => '600.00', 'netprice' => '420.00', 'discount' => '30', 'error' => null];
    }

    /**
     * @param  list<array{code: string, shop: string, row: string}>  $skus
     * @param  array<string, mixed>  $fields
     */
    private function addFamily(string $id, string $name, string $path, array $skus, array $fields = []): void
    {
        $this->families[$id] = $fields + [
            'id' => $id,
            'product_name' => $name,
            'product_id' => $name,
            'url' => $path,
            'sbu' => 'Personal Protective Equipment',
            'short_description' => '',
            'long_description' => '',
            'line_of_business' => '',
            'product_family' => '',
            'assets' => [],
            'resources' => [],
        ];
        $this->skus[$id] = $skus;
    }

    /**
     * Wiersz tabeli sklepu po odczycie (shopRows) — do testów grupowania i ceny.
     *
     * @return array<string, string>
     */
    private static function rowData(string $code, string $size, string $unit, string $min, string $description = 'X'): array
    {
        return [
            'code' => $code, 'ref' => 'PRD010~'.$code, 'ean' => '', 'description' => $description, 'size' => $size,
            'list_price' => '1', 'price_unit' => $unit, 'min_text' => $min, 'min_qty' => '1', 'multiple' => '1',
            'origin' => '', 'ships_from' => '', 'entity' => '',
        ];
    }

    private static function row(string $code, string $ref, string $ean, string $description, string $size, string $listPrice, string $unit, string $pack, string $contents): string
    {
        $eanHtml = $ean !== '' ? '<br> <br>EAN UPC<br>'.$ean : '';
        $min = $unit === '1 EA' ? 'each' : ($unit === '1 PR' ? 'pair' : $pack.'&nbsp;'.$contents);

        return '<tr class="responsive-table-item rowheight" data-wish="false">'
            .'<td class="responsive-table-cell sm-label hidden-sm hidden-md hidden-lg"><span class="ml10">Add to Wish List</span></td>'
            .'<td class="responsive-table-cell text-nowrap wish-sku"></td>'
            .'<td class="responsive-table-cell sm-label hidden-sm hidden-md hidden-lg">Part #</td>'
            .'<td class="responsive-table-cell text-nowrap"> <a class="variant-part-specification-redirect">'.$code.'</a>'.$eanHtml
            .'<input type="hidden" path="code" name="code" id="code" value="'.$ref.'"> <input type="hidden" name="variantname" class="variantname" value="'.$code.'">'
            .'<div><form id="configureForm" action="x/configuratorPage/" method="post"><div><input type="hidden" name="CSRFToken" value="syntetyczny-token"></div></form></div></td>'
            .'<td class="responsive-table-cell sm-label hidden-sm hidden-md hidden-lg">Description</td>'
            .'<td class="responsive-table-cell sku-desc"><p class="item__name">'.$description.'</p></td>'
            .'<td class="responsive-table-cell sm-label hidden-sm hidden-md hidden-lg">Size</td>'
            .'<td class="responsive-table-cell text-center"> '.$size.'</td>'
            .'<td class="responsive-table-cell sm-label hidden-sm hidden-md hidden-lg noPrint">List Price</td>'
            .'<td class="responsive-table-cell text-center noPrint"> '.$listPrice.'€<input type="hidden" name="jsListPrice" class="js-list-price" value="'.$listPrice.'"> <div class="text-center text-uppercase"> '.$unit.'</div> </td>'
            .'<td class="responsive-table-cell sm-label hidden-sm hidden-md hidden-lg">Order Qty </td>'
            .'<td class="responsive-table-cell"> <div class="addtocart-component" data-min-order-qty="1" data-variant-multiple="1"><div class="qty-selector"></div>'
            .'<div class="text-center"><span class="order-qty-message hide" data-min-order-quantity="1">Min&nbsp;1&nbsp;</span>'.$min.'</div> </div></td>'
            .'<td class="responsive-table-cell sm-label hidden-sm hidden-md hidden-lg">Availability</td>'
            .'<td class="responsive-table-cell text-center position-r"><div class="table-available"><p> Ships From<span>: France</span></p><p> Country of Origin<span>: India</span></p>'
            .'<input type="hidden" name="shipFrom" value="France"> <input type="hidden" name="origin" value="India">'
            .'<p class="text-gray-cls">Business Entity<span class="text-normal-cls">: FR50 - Honeywell Safety Products France&nbsp;</span></p></div></td>'
            .'</tr>';
    }

    /**
     * @param  list<string>  $rows
     */
    private function shopPageHtml(array $rows): string
    {
        return '<html><body><input type="hidden" class="js-accountId" name="accountId" value="0000099999" />'
            .'<div class="modal">Honeywell Business Entity</div><form method="POST" id="hWAddToCartForm" action="/shop/honeywell/en/cart/multipleAdd">'
            .'<table class="custom-table responsive-table hide"><thead><tr><th>Part #</th></tr></thead><tbody>'
            .implode('', $rows)
            .'</tbody></table></form></body></html>';
    }

    /**
     * @param  array<string, mixed>  $family
     * @return array<string, mixed>
     */
    private static function searchDoc(array $family): array
    {
        $doc = [];
        foreach ($family as $key => $value) {
            if ($key === 'id') {
                continue;
            }
            $doc[$key] = ['raw' => in_array($key, ['assets', 'resources'], true) ? json_encode($value, JSON_UNESCAPED_SLASHES) : $value];
        }
        $doc['id'] = ['raw' => 'joule-bt-sps-epim-product-prod|'.$family['id'].'-en'];
        $doc['document_type'] = ['raw' => 'Product'];

        return $doc;
    }

    private function fakeSite(): void
    {
        $base = 'https://automation.honeywell.com';
        $auth = 'https://authn.honeywell.com';
        // zakres detektorów nigdy nie jest pusty (pusty = zmiana wyszukiwarki) — rodzina spoza konta, gdy test nie dał własnej
        if (! in_array('Portables', array_column($this->families, 'product_family'), true)) {
            $this->addFamily('9000001', 'Synth Detector Outside', '/sensing-solutions/gas-and-flame-detection/portables/synth-outside', [], [
                'sbu' => 'Sensing Solutions', 'line_of_business' => 'Gas & Flame Detection', 'product_family' => 'Portables',
            ]);
        }

        Http::fake(function (Request $request) use ($base, $auth) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            $host = 'https://'.parse_url($url, PHP_URL_HOST);

            // --- logowanie (założenie: PingFederate) ---
            if ($host === $base && str_starts_with($path, '/pif/cwa/oauth/request/j_security_check')) {
                if ($this->foreignRedirect) {
                    return Http::response('', 302, ['Location' => 'https://evil.example.com/login']);
                }

                return Http::response('', 302, ['Location' => $auth.'/as/authorization.oauth2?response_type=code&client_id=Client_81']);
            }
            if ($host === $auth && $path === '/as/authorization.oauth2') {
                return Http::response('<html><form method="POST" action="/as/AbC1/resume/as/authorization.ping" autocomplete="off">'
                    .'<input id="identifierInput" name="subject" type="text"/><input type="hidden" name="clear.previous.selected.subject" value="" />'
                    .'<input type="hidden" name="cancel.identifier.selection" value="false" /></form></html>');
            }
            if ($host === $auth && $path === '/as/AbC1/resume/as/authorization.ping' && isset($request->data()['subject'])) {
                if ($this->asksCode) {
                    return Http::response('<html><p>Enter the verification code sent by PingID</p><form method="POST" action="/as/AbC1/resume/as/authorization.ping"><input type="text" name="otp"/></form></html>');
                }

                return Http::response($this->passwordPage(''));
            }
            if ($host === $auth && $path === '/as/AbC1/resume/as/authorization.ping' && isset($request->data()['pf.pass'])) {
                if ($request->data()['pf.pass'] !== self::PASSWORD || $request->data()['pf.username'] !== self::EMAIL) {
                    return Http::response($this->passwordPage('<div class="ping-error">We didn&#39;t recognize the username or password you entered.</div>'));
                }
                $this->loggedIn = true;
                $this->logins++;

                return Http::response('', 302, ['Location' => ($this->plainCallback ? 'http://automation.honeywell.com' : $base).'/pif/cwa/oauth/callback/j_security_check?code=synth']);
            }
            if ($host === $base && $path === '/pif/cwa/oauth/callback/j_security_check') {
                return Http::response('', 302, ['Location' => ($this->plainCallback ? 'https://automation.honeywell.com:443' : $base).'/gb/en']);
            }
            if ($host === $base && $path === '/gb/en') {
                return Http::response('<html>home</html>');
            }
            if ($host === $base && in_array($path, ['/pif/api/soldto/favorite/v1/user', '/pif/api/session/refresh', '/pif/api/account/v1/countries/country', '/pif/api/account/v1/status'], true)) {
                // serwer ustawia przy odczytach konta ciasteczko konta sold-to
                return Http::response(['honId' => 'synth'], 200, $path === '/pif/api/account/v1/status' ? ['Set-Cookie' => 'b2bunit=synth-unit; Path=/; Secure'] : []);
            }
            if ($host === $base && $path === '/pif/api/session/details') {
                return Http::response($this->loggedIn ? ['session_valid' => true, 'email' => strtoupper(self::EMAIL)] : ['session_valid' => false]);
            }
            if ($host === $base && $path === '/shop/honeywell/en/') {
                // strona sklepu ma zwykłe formularze z samymi ukrytymi polami (waluta, koszyk) — nie są przekazaniem logowania
                return Http::response('<html><form method="post" action="/shop/honeywell/en/_s/currency"><input type="hidden" name="code" value="EUR">'
                    .'<input type="hidden" name="CSRFToken" value="syntetyczny"></form><script>document.forms[0].submit()</script></html>');
            }

            // --- wyszukiwarka (publiczna) ---
            if ($host === $base && $path === '/pif/api/search/v1/joule-bt-sps-meta-prod/search') {
                $body = json_decode($request->body(), true);
                $filters = array_merge(...$body['filters']['all']);
                $matching = array_values(array_filter($this->families, static function (array $f) use ($filters): bool {
                    foreach (['sbu', 'line_of_business', 'product_family'] as $key) {
                        if (isset($filters[$key]) && ($f[$key] ?? '') !== $filters[$key]) {
                            return false;
                        }
                    }

                    return true;
                }));
                $size = $body['page']['size'];
                $page = $body['page']['current'];

                return Http::response([
                    'meta' => ['page' => ['current' => $page, 'total_results' => count($matching), 'size' => $size]],
                    'results' => array_map(self::searchDoc(...), array_slice($matching, ($page - 1) * $size, $size)),
                ]);
            }

            // --- pozycje rodziny ---
            if ($host === $base && str_ends_with($path, '.pdpsearchsearvlet')) {
                $id = $request->data()['dynamicProductId'] ?? '';
                if ($this->pdpAnswer !== null) {
                    return Http::response($this->pdpAnswer);
                }
                if ($id === $this->pdpFailFor) {
                    return Http::response('Internal Server Error', 500);
                }
                if ($this->dropSessionOnce) {
                    $this->dropSessionOnce = false;
                    $this->loggedIn = false;
                }
                $guest = ! $this->loggedIn || $this->alwaysGuest;
                $skus = $this->skus[$id] ?? [];

                return Http::response(['success' => true, 'search_results' => [[
                    'meta' => ['page' => ['current' => 1, 'total_results' => count($skus)]],
                    'results' => array_map(static fn (array $s): array => [
                        'id' => ['raw' => $guest ? 'joule-bt-sps-epim-sku-prod|99-en' : 'PRD010~'.$s['code'].'|'.$s['shop']],
                        'title' => ['raw' => $s['code']],
                        'sku_description' => ['raw' => 'x'],
                    ], $skus),
                ]]]);
            }

            // --- sklep ---
            if ($host === $base && str_starts_with($path, '/shop/honeywell/en/p/')) {
                $rest = rawurldecode(substr($path, strlen('/shop/honeywell/en/p/')));
                if (str_ends_with($rest, '/pricecall')) {
                    if ($this->pricesFail) {
                        return Http::response('Internal Server Error', 500);
                    }
                    $shop = substr($rest, 0, -strlen('/pricecall'));
                    if ($shop === $this->pricesHtmlFor) {
                        return Http::response('<html>Service temporarily unavailable</html>');
                    }
                    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                    $out = [];
                    foreach (explode(',', (string) ($query['displayedSkus'] ?? '')) as $code) {
                        $ref = $this->refFor($shop, $code);
                        if ($ref !== null && isset($this->prices[$ref])) {
                            $out[] = ['ref' => $ref];
                            $out[] = ['variants' => [['qty' => '1.000'] + $this->prices[$ref]]];
                        }
                    }

                    return Http::response(['sku' => $out]);
                }
                if ($rest === $this->tableMissingFor) {
                    return Http::response('<html><input type="hidden" class="js-accountId" name="accountId" value="0000099999" /><p>Configure this product</p></html>');
                }
                if ($this->shopGuestOnce) {
                    // sesja sklepu wygasła: strona logowania z kodem 200, bez tabeli pozycji
                    $this->shopGuestOnce = false;
                    $this->loggedIn = false;

                    return Http::response('<html><a href="/pif/cwa/oauth/request/j_security_check">Sign In</a></html>');
                }
                $rows = [];
                foreach ($this->skus as $skus) {
                    foreach ($skus as $s) {
                        if ($s['shop'] === $rest && ! $this->noShopRows) {
                            $rows[] = $s['row'];
                        }
                    }
                }

                return Http::response($this->shopPageHtml($rows));
            }

            // --- pliki ---
            if (str_starts_with($url, self::SCENE7)) {
                return Http::response('SYNTH-IMAGE-'.basename($path), 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_starts_with($url, self::EDAM)) {
                return Http::response('%PDF-1.4 synth '.basename($path), 200, ['Content-Type' => 'application/pdf']);
            }

            return Http::response('nieznany adres atrapy: '.$url, 404);
        });
    }

    private function refFor(string $shop, string $code): ?string
    {
        foreach ($this->skus as $skus) {
            foreach ($skus as $s) {
                if ($s['shop'] === $shop && $s['code'] === $code && preg_match('/value="(PRD010~[^"]+)"/', $s['row'], $m) === 1) {
                    return $m[1];
                }
            }
        }

        return null;
    }

    private function passwordPage(string $error): string
    {
        return '<html>'.$error.'<form method="POST" action="/as/AbC1/resume/as/authorization.ping">'
            .'<input type="text" name="pf.username" value="'.self::EMAIL.'"/><input type="password" name="pf.pass"/>'
            .'<input type="hidden" name="pf.ok" value=""/><input type="hidden" name="pf.cancel" value=""/></form></html>';
    }
}
