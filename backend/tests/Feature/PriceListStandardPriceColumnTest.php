<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AssortmentGroup;
use App\Models\CardRedirect;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Catalog\CardRedirectStore;
use App\Services\PriceListImportService;
use App\Support\SupplierSpecialPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Cennik SECURA (decyzja właściciela 10.10.2026): „40% s.dystryb.” = cena specjalna (zakup, nie każdy wiersz ją ma),
 * „21%” = cena normalna (rola standard_price), „CENA katalogowa netto” = katalogowa. Ocena ceny specjalnej jak w slotach
 * B2B UVEX: slot „file” dostaje base_price_net = katalogowa i standard_discount_percent z ceny normalnej.
 */
final class PriceListStandardPriceColumnTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create();
    }

    public function test_special_and_standard_prices_are_rated_in_file_slot(): void
    {
        $result = $this->import($this->securaRows(), $this->securaMapping());
        $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));
        $this->assertTrue($result['price_list']->fresh()->has_supplier_special, 'zapisany slot z oceną oznacza cennik');

        // trzy ceny: zakup = cena specjalna, rabat standardowy z ceny normalnej 22,78 / 28,84
        $special = $this->slot('RK-ALFA');
        $this->assertEqualsWithDelta(17.30, (float) $special->purchase_price, 0.001);
        $this->assertEqualsWithDelta(28.84, (float) $special->catalog_price_net, 0.001);
        $this->assertEqualsWithDelta(28.84, (float) $special->base_price_net, 0.001);
        $this->assertEqualsWithDelta(21.01, (float) $special->standard_discount_percent, 0.001);
        $this->assertSame(PriceListImportService::FILE_STANDARD_PRICE_SOURCE, $special->base_price_source);
        $this->assertNull($special->base_price_category);
        $this->assertNull($special->base_price_code);
        $this->assertSame(SupplierSpecialPrice::SPECIAL, SupplierSpecialPrice::forSlot($special)['status'] ?? null);

        // pusta komórka ceny specjalnej: zakup = cena normalna, ocena „standard”
        $standard = $this->slot('KU-BETA');
        $this->assertEqualsWithDelta(44.24, (float) $standard->purchase_price, 0.001);
        $this->assertEqualsWithDelta(56.00, (float) $standard->base_price_net, 0.001);
        $this->assertEqualsWithDelta(21.00, (float) $standard->standard_discount_percent, 0.001);
        $this->assertEqualsWithDelta(21.00, (float) $standard->discount_percent, 0.001);
        $this->assertSame(SupplierSpecialPrice::STANDARD, SupplierSpecialPrice::forSlot($standard)['status'] ?? null);

        // Cena specjalna bez ceny normalnej: właściciel — „40% traktuj jako ceny specjalne”. Pierwsza wersja zapisywała
        // tu zakup 30 bez oceny, czyli cenę specjalną jako zwykłą cenę widoczną dla wszystkich (wyciek). Teraz ocena
        // zachowawcza: cena standardowa = katalogowa (rabat 0), więc 30 jest ukryte jak cena specjalna, plus uwaga.
        $conservative = $this->slot('BU-GAMA');
        $this->assertEqualsWithDelta(30.00, (float) $conservative->purchase_price, 0.001);
        $this->assertEqualsWithDelta(50.00, (float) $conservative->base_price_net, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $conservative->standard_discount_percent, 0.001);
        $this->assertSame(SupplierSpecialPrice::SPECIAL, SupplierSpecialPrice::forSlot($conservative)['status'] ?? null);
        $this->assertNotEmpty(array_filter(
            $result['errors'],
            static fn (string $note): bool => str_contains($note, 'brak ceny normalnej') && str_contains($note, 'BU-GAMA'),
        ), implode('; ', $result['errors']));

        // bez ceny zakupu z pliku i z ceną normalną wyższą od katalogowej — nie ma czego ukrywać, bez oceny
        $invalid = $this->slot('CZ-DELTA');
        $this->assertNull($invalid->base_price_net);
        $this->assertNull($invalid->standard_discount_percent);
        $this->assertEqualsWithDelta(60.00, (float) $invalid->purchase_price, 0.001);

        // ocena tylko w slocie, karta ma zwykłą cenę
        $card = Product::query()->where('sku', 'KU-BETA')->sole();
        $this->assertArrayNotHasKey('base_price_net', $card->getAttributes());
        $this->assertArrayNotHasKey('standard_discount_percent', $card->getAttributes());
        $this->assertEqualsWithDelta(44.24, (float) $card->purchase_price, 0.001);
        $this->assertEqualsWithDelta(56.00, (float) $card->catalog_price_net, 0.001);
    }

    public function test_preview_marks_purchase_from_standard_price_as_from_file(): void
    {
        $path = $this->spreadsheet($this->securaRows());
        try {
            $preview = app(PriceListImportService::class)->previewFromMapping($path, $this->securaMapping(), 50);
            $row = collect($preview['products'])->firstWhere('sku', 'KU-BETA');

            $this->assertNotNull($row);
            $this->assertEqualsWithDelta(44.24, $row['purchase_price'], 0.001);
            $this->assertTrue($row['_purchase_from_file']);
            // uwaga o ocenie zachowawczej widoczna już w podglądzie
            $this->assertStringContainsString('BU-GAMA', implode(' ', $preview['errors']));
        } finally {
            @unlink($path);
        }
    }

    public function test_flagged_price_list_refuses_mapping_import_without_standard_price_column(): void
    {
        $this->import($this->securaRows(), $this->securaMapping());
        $list = PriceList::query()->sole();
        $groups = AssortmentGroup::query()->count();

        $mapping = $this->securaMapping();
        unset($mapping['sheets'][0]['columns']['standard_price']);
        $rows = $this->securaRows();
        $rows[1][2] = 15.00;
        $result = $this->import($rows, $mapping, '2026-11', ['default_discount' => 50]);

        $this->assertNull($result['price_list']);
        $this->assertSame([PriceListImportService::SUPPLIER_SPECIAL_MAPPING_REQUIRED], $result['errors']);
        // nic nie zapisane: ani cena, ani ocena, ani wersja cennika, ani grupa asortymentowa
        $this->assertEqualsWithDelta(17.30, (float) $this->slot('RK-ALFA')->purchase_price, 0.001);
        $this->assertEqualsWithDelta(21.01, (float) $this->slot('RK-ALFA')->standard_discount_percent, 0.001);
        $this->assertSame('2026-10', $list->fresh()->version);
        $this->assertSame($groups, AssortmentGroup::query()->count());
        $this->assertTrue($list->fresh()->has_supplier_special);

        // podgląd z producentem ostrzega tym samym tekstem
        $path = $this->spreadsheet($rows);
        try {
            $preview = app(PriceListImportService::class)->previewFromMapping($path, $mapping, 8, 'SECURA');
            $this->assertSame(PriceListImportService::SUPPLIER_SPECIAL_MAPPING_REQUIRED, $preview['errors'][0] ?? null);
        } finally {
            @unlink($path);
        }
    }

    public function test_flagged_price_list_refuses_ai_and_plain_imports(): void
    {
        $this->import($this->securaRows(), $this->securaMapping());
        $versions = PriceList::query()->pluck('version')->all();

        $path = $this->spreadsheet($this->securaRows());
        try {
            $service = app(PriceListImportService::class);
            $ai = $service->importFromProducts(
                new UploadedFile($path, 'secura.xlsx', null, null, true),
                'Secura',
                '2026-12',
                $this->user,
                [['sku' => 'RK-ALFA', 'name' => 'Rękawice robocze Alfa', 'catalog_price' => 28.84, 'purchase_price' => 12.00]],
                null,
                ['default_discount' => 30],
            );
            $this->assertNull($ai['price_list']);
            $this->assertSame([PriceListImportService::SUPPLIER_SPECIAL_MAPPING_REQUIRED], $ai['errors']);

            $plain = $service->import(new UploadedFile($path, 'secura.xlsx', null, null, true), 'SECURA', '2026-12', $this->user);
            $this->assertNull($plain['price_list']);
            $this->assertSame([PriceListImportService::SUPPLIER_SPECIAL_MAPPING_REQUIRED], $plain['errors']);
        } finally {
            @unlink($path);
        }

        $this->assertSame($versions, PriceList::query()->pluck('version')->all());
        $this->assertEqualsWithDelta(17.30, (float) $this->slot('RK-ALFA')->purchase_price, 0.001);
        $this->assertSame(0, AssortmentGroup::query()->count());
    }

    public function test_flag_stays_when_reimport_has_no_rated_rows(): void
    {
        $this->import($this->securaRows(), $this->securaMapping());

        // ta sama kolumna ceny normalnej, ale wiersze bez ceny specjalnej i bez ceny normalnej — ocena znika z wiersza,
        // znacznik cennika zostaje: żaden import ani polecenie go nie zdejmuje, zdjęcie wymaga ręcznej zmiany w bazie
        // na decyzję właściciela
        $rows = [
            ['Indeks', 'Nazwa', '40% s.dystryb.', '21%', 'CENA katalogowa netto'],
            ['RK-ALFA', 'Rękawice robocze Alfa', null, null, 28.84],
            ['KU-BETA', 'Kurtka ostrzegawcza Beta', null, null, 56.00],
        ];
        $result = $this->import($rows, $this->securaMapping(), '2026-11');
        $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));

        foreach (['RK-ALFA', 'KU-BETA'] as $sku) {
            $slot = $this->slot($sku);
            $this->assertNull($slot->base_price_net, $sku);
            $this->assertNull($slot->standard_discount_percent, $sku);
            $this->assertNull($slot->base_price_source, $sku);
        }
        $this->assertTrue(PriceList::query()->sole()->has_supplier_special);
    }

    /** Cena specjalna bez ceny katalogowej: nie ma od czego liczyć ceny standardowej — wiersz pominięty błędem. */
    public function test_special_price_without_catalog_price_is_rejected(): void
    {
        $this->import($this->securaRows(), $this->securaMapping());

        $rows = $this->securaRows();
        $rows[1] = ['RK-ALFA', 'Rękawice robocze Alfa', 15.00, 22.78, 0];
        $result = $this->import($rows, $this->securaMapping(), '2026-11');
        $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));

        $errors = implode(' | ', $result['errors']);
        $this->assertStringContainsString('(RK-ALFA): brak ceny katalogowej przy cenie specjalnej — wiersz pominięty, dotychczasowa cena karty (jeśli jest) bez zmian.', $errors);
        // bez fałszywej uwagi o ocenie zachowawczej dla tego wiersza
        $this->assertDoesNotMatchRegularExpression('/ocena zachowawcza[^|]*RK-ALFA|RK-ALFA[^|]*ocena zachowawcza/u', $errors);
        $slot = $this->slot('RK-ALFA');
        $this->assertEqualsWithDelta(17.30, (float) $slot->purchase_price, 0.001);
        $this->assertEqualsWithDelta(28.84, (float) $slot->catalog_price_net, 0.001);
        $this->assertEqualsWithDelta(21.01, (float) $slot->standard_discount_percent, 0.001);
    }

    /**
     * Upust przy kolumnie ceny normalnej: zakup z niezerowego upustu ma pierwszeństwo przed ceną normalną, dostaje
     * ocenę (z ceny normalnej albo zachowawczą) i rabat grupy go nie nadpisuje. Cena normalna jest zakupem tylko bez
     * ceny zakupu i bez upustu.
     */
    public function test_discount_takes_precedence_over_standard_price_and_survives_group_discount(): void
    {
        $rows = [
            ['Indeks', 'Nazwa', 'Upust', '21%', 'CENA katalogowa netto'],
            ['UP-1', 'Rękawice robocze Kappa', 40, 79.00, 100.00],
            ['UP-2', 'Rękawice robocze Lambda', 0.4, null, 100.00],
            ['UP-3', 'Rękawice robocze Mi', 0, 79.00, 100.00],
        ];
        $mapping = $this->securaMapping();
        $mapping['sheets'][0]['columns'] = ['sku' => 0, 'name' => 1, 'discount' => 2, 'standard_price' => 3, 'catalog_price' => 4];
        $mapping['sheets'][0]['locked_columns'] = array_keys($mapping['sheets'][0]['columns']);
        $result = $this->import($rows, $mapping, '2026-10', ['default_discount' => 50]);
        $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));

        $fromDiscount = $this->slot('UP-1');
        $this->assertEqualsWithDelta(60.00, (float) $fromDiscount->purchase_price, 0.001);
        $this->assertEqualsWithDelta(100.00, (float) $fromDiscount->base_price_net, 0.001);
        $this->assertEqualsWithDelta(21.00, (float) $fromDiscount->standard_discount_percent, 0.001);
        $this->assertSame(SupplierSpecialPrice::SPECIAL, SupplierSpecialPrice::forSlot($fromDiscount)['status'] ?? null);

        // upust bez ceny normalnej — zachowawczo, z uwagą
        $conservative = $this->slot('UP-2');
        $this->assertEqualsWithDelta(60.00, (float) $conservative->purchase_price, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $conservative->standard_discount_percent, 0.001);
        $this->assertStringContainsString('UP-2', implode(' ', $result['errors']));

        // bez upustu: zakup = cena normalna, rabat grupy 50% go nie nadpisuje
        $standard = $this->slot('UP-3');
        $this->assertEqualsWithDelta(79.00, (float) $standard->purchase_price, 0.001);
        $this->assertEqualsWithDelta(21.00, (float) $standard->standard_discount_percent, 0.001);
    }

    /**
     * Analiza zgadła kolumnę „21%” jako upust, a człowiek wskazał ją też jako cenę normalną: cena czytana jako procent
     * dałaby zmyślony zakup (56 × (1 − 44,24%) = 31,23). Upust na kolumnie ceny wypada — zakup = cena normalna.
     */
    public function test_discount_on_the_standard_price_column_is_ignored(): void
    {
        $result = $this->import($this->securaRows(), $this->securaMapping(['discount' => 3]));
        $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));

        $standard = $this->slot('KU-BETA');
        $this->assertEqualsWithDelta(44.24, (float) $standard->purchase_price, 0.001);
        $this->assertEqualsWithDelta(21.00, (float) $standard->standard_discount_percent, 0.001);
        $this->assertSame(SupplierSpecialPrice::STANDARD, SupplierSpecialPrice::forSlot($standard)['status'] ?? null);
        $this->assertEqualsWithDelta(17.30, (float) $this->slot('RK-ALFA')->purchase_price, 0.001);
    }

    /**
     * Cennik z cenami specjalnymi i mapowanie bez ceny zakupu i bez upustu (sama katalogowa): nie ma czego ujawnić —
     * import i podgląd przechodzą bez odmowy.
     */
    public function test_flagged_price_list_accepts_catalog_only_mapping(): void
    {
        $this->import($this->securaRows(), $this->securaMapping());
        $mapping = $this->securaMapping();
        $mapping['sheets'][0]['columns'] = ['sku' => 0, 'name' => 1, 'catalog_price' => 4];
        $mapping['sheets'][0]['locked_columns'] = ['sku', 'name', 'catalog_price'];
        $service = app(PriceListImportService::class);
        $this->assertTrue($service->mappingHasStandardPrice($mapping));
        // arkusz pominięty w mapowaniu nie wymaga roli
        $skipped = $this->securaMapping();
        unset($skipped['sheets'][0]['columns']['standard_price']);
        $skipped['sheets'][0]['include'] = false;
        $this->assertTrue($service->mappingHasStandardPrice($skipped));

        $path = $this->spreadsheet($this->securaRows());
        try {
            $preview = $service->previewFromMapping($path, $mapping, 8, 'SECURA');
            $this->assertNotContains(PriceListImportService::SUPPLIER_SPECIAL_MAPPING_REQUIRED, $preview['errors']);
        } finally {
            @unlink($path);
        }

        $result = $this->import($this->securaRows(), $mapping, '2026-11');
        $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));
        // zakup = katalogowa, nic nie jest ujawnione; znacznik zostaje
        $this->assertEqualsWithDelta(28.84, (float) $this->slot('RK-ALFA')->purchase_price, 0.001);
        $this->assertTrue(PriceList::query()->sole()->has_supplier_special);
    }

    public function test_group_discount_does_not_override_purchase_from_standard_price(): void
    {
        $result = $this->import($this->securaRows(), $this->securaMapping(), '2026-10', ['default_discount' => 50]);
        $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));

        $this->assertEqualsWithDelta(44.24, (float) $this->slot('KU-BETA')->purchase_price, 0.001);
        $this->assertEqualsWithDelta(17.30, (float) $this->slot('RK-ALFA')->purchase_price, 0.001);
        // wiersz bez żadnej ceny zakupu dostaje rabat cennika jak dotąd
        $this->assertEqualsWithDelta(30.00, (float) $this->slot('CZ-DELTA')->purchase_price, 0.001);
    }

    /**
     * Wiersz kartonowy (CAR): zakup z ceny za opakowanie, katalogowa przeliczona na opakowanie, a rabat standardowy
     * z surowych wartości pliku (cena normalna i katalogowa kartonu — stosunek nie zależy od przeliczenia).
     */
    public function test_carton_row_takes_standard_discount_from_raw_file_values(): void
    {
        $rows = [
            ['Indeks', 'Nazwa', '40% s.dystryb.', '21%', 'CENA katalogowa netto', 'Jednostka', 'Cena za opak.'],
            ['KAR-1', 'Rękawice nitrylowe Sigma karton', 60.00, 79.00, 100.00, 'CAR', 12.00],
            ['KAR-2', 'Rękawice lateksowe Tau karton', 60.00, null, 100.00, 'CAR', 12.00],
        ];
        $mapping = $this->securaMapping(['price_unit' => 5, 'pack_price' => 6]);
        $result = $this->import($rows, $mapping);
        $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));

        $slot = $this->slot('KAR-1');
        $this->assertEqualsWithDelta(12.00, (float) $slot->purchase_price, 0.001);
        $this->assertEqualsWithDelta(20.00, (float) $slot->catalog_price_net, 0.001);
        $this->assertEqualsWithDelta(20.00, (float) $slot->base_price_net, 0.001);
        $this->assertEqualsWithDelta(21.00, (float) $slot->standard_discount_percent, 0.001);
        $this->assertSame(SupplierSpecialPrice::SPECIAL, SupplierSpecialPrice::forSlot($slot)['status'] ?? null);

        // karton bez ceny normalnej — zachowawczo
        $conservative = $this->slot('KAR-2');
        $this->assertEqualsWithDelta(20.00, (float) $conservative->base_price_net, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $conservative->standard_discount_percent, 0.001);
    }

    /** Dopłata % w wierszu: cena normalna jej nie obejmuje — ani zakup z niej, ani rabat; ocena zachowawcza z uwagą. */
    public function test_surcharge_row_gets_conservative_rating(): void
    {
        $rows = [
            ['Indeks', 'Nazwa', '40% s.dystryb.', '21%', 'CENA katalogowa netto', 'Dopłata %'],
            ['DOP-1', 'Kask ochronny Epsilon', 30.00, 40.00, 50.00, 10],
        ];
        $result = $this->import($rows, $this->securaMapping(['surcharge' => 5]));
        $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));

        $slot = $this->slot('DOP-1');
        $this->assertEqualsWithDelta(55.00, (float) $slot->catalog_price_net, 0.001);
        $this->assertEqualsWithDelta(30.00, (float) $slot->purchase_price, 0.001);
        $this->assertEqualsWithDelta(55.00, (float) $slot->base_price_net, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $slot->standard_discount_percent, 0.001);
        $this->assertStringContainsString('dopłata', implode(' ', $result['errors']));
        $this->assertStringContainsString('DOP-1', implode(' ', $result['errors']));
    }

    /** Rozmiary zwinięte w jedną kartę (ta sama katalogowa i zakup), różne ceny normalne — ocena z najwyższą ceną standardową. */
    public function test_collapsed_sizes_take_rating_with_highest_standard_price(): void
    {
        $rows = [
            ['Indeks', 'Nazwa', '40% s.dystryb.', '21%', 'CENA katalogowa netto'],
            ['KOMBI-S', 'Kombinezon ochronny Omega rozmiar S', 60.00, 79.00, 100.00],
            ['KOMBI-M', 'Kombinezon ochronny Omega rozmiar M', 60.00, 85.00, 100.00],
        ];
        $result = $this->import($rows, $this->securaMapping());
        $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));

        $slots = ProductSourcePrice::query()->where('source_key', ProductSourcePrice::SOURCE_FILE)->get();
        $this->assertCount(1, $slots, 'rozmiary w tej samej cenie zwijają się w jedną kartę');
        $this->assertEqualsWithDelta(100.00, (float) $slots[0]->base_price_net, 0.001);
        $this->assertEqualsWithDelta(15.00, (float) $slots[0]->standard_discount_percent, 0.001);
    }

    public function test_redirect_card_takes_rating_with_highest_standard_price(): void
    {
        $rows = [
            ['Indeks', 'Nazwa', '40% s.dystryb.', '21%', 'CENA katalogowa netto'],
            ['Q-1', 'Kask ochronny biały', null, 79.00, 100.00],
            ['Z-2', 'Nauszniki przeciwhałasowe', null, 79.00, 100.00],
        ];
        $first = $this->import($rows, $this->securaMapping(), '2026-09');
        $card = Product::query()->create([
            'sku' => 'KOMPLET-1', 'name' => 'Zestaw kask z nausznikami', 'manufacturer' => 'SECURA',
            'catalog_price_net' => 90.00, 'purchase_price' => 80.00, 'currency' => 'PLN',
        ]);
        foreach (['Q-1', 'Z-2'] as $sku) {
            $source = Product::query()->where('sku', $sku)->sole();
            (new CardRedirectStore)->recordMerge($source, $card, CardRedirect::REASON_MERGE, null, $this->user);
            $source->delete();
        }

        $this->import($rows, $this->securaMapping(), '2026-10');
        $slot = $this->slotOf($card);
        $this->assertSame((int) $first['price_list']->id, (int) $slot->price_list_id);
        $this->assertEqualsWithDelta(79.00, (float) $slot->purchase_price, 0.001);
        $this->assertEqualsWithDelta(100.00, (float) $slot->base_price_net, 0.001);
        $this->assertEqualsWithDelta(21.00, (float) $slot->standard_discount_percent, 0.001);
        $this->assertArrayNotHasKey('base_price_net', $card->refresh()->getAttributes());

        // ten sam zakup, ale wyższa cena normalna jednego wiersza — karta dostaje ocenę z wyższą ceną standardową
        $rows[1] = ['Q-1', 'Kask ochronny biały', 79.00, 85.00, 100.00];
        $this->import($rows, $this->securaMapping(), '2026-11');
        $slot = $this->slotOf($card);
        $this->assertEqualsWithDelta(79.00, (float) $slot->purchase_price, 0.001);
        $this->assertEqualsWithDelta(100.00, (float) $slot->base_price_net, 0.001);
        $this->assertEqualsWithDelta(15.00, (float) $slot->standard_discount_percent, 0.001);
    }

    /** @return list<list<mixed>> */
    private function securaRows(): array
    {
        return [
            ['Indeks', 'Nazwa', '40% s.dystryb.', '21%', 'CENA katalogowa netto'],
            ['RK-ALFA', 'Rękawice robocze Alfa', 17.30, 22.78, 28.84],
            ['KU-BETA', 'Kurtka ostrzegawcza Beta', null, 44.24, 56.00],
            ['BU-GAMA', 'Buty gumowe Gama', 30.00, null, 50.00],
            ['CZ-DELTA', 'Czapka zimowa Delta', null, 70.00, 60.00],
        ];
    }

    /**
     * @param  array<string, int>  $extra  dodatkowe role kolumn
     * @return array<string, mixed>
     */
    private function securaMapping(array $extra = []): array
    {
        $columns = ['sku' => 0, 'name' => 1, 'purchase' => 2, 'standard_price' => 3, 'catalog_price' => 4] + $extra;

        return [
            'currency' => 'PLN',
            'sheets' => [[
                'sheet' => 'Cennik',
                'include' => true,
                'header_excel_row' => 1,
                'columns' => $columns,
                'locked_columns' => array_keys($columns),
                'repeating_headers' => false,
                'confidence' => 1.0,
            ]],
        ];
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @param  array<string, mixed>  $mapping
     * @param  array<string, mixed>|null  $groupOptions
     * @return array<string, mixed>
     */
    private function import(array $rows, array $mapping, string $version = '2026-10', ?array $groupOptions = null): array
    {
        $path = $this->spreadsheet($rows);
        try {
            return app(PriceListImportService::class)->importWithMapping(
                new UploadedFile($path, 'secura.xlsx', null, null, true),
                'SECURA',
                $version,
                $this->user,
                $mapping,
                null,
                $groupOptions,
            );
        } finally {
            @unlink($path);
        }
    }

    private function slot(string $sku): ProductSourcePrice
    {
        return $this->slotOf(Product::query()->where('sku', $sku)->sole());
    }

    private function slotOf(Product $card): ProductSourcePrice
    {
        return ProductSourcePrice::query()
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->where('product_id', $card->id)
            ->sole();
    }

    /** @param  list<list<mixed>>  $rows */
    private function spreadsheet(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cennik');
        $sheet->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'secura').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
