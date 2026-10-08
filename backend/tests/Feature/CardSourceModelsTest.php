<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\ProductIdentifierCode;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Modele połączone w jednej karcie na liście produktów i na karcie (zgłoszenie 06.10.2026: ELTEN pokazuje 5 modeli
 * MAVERICK, u nas 4 karty — red i black w jednej).
 */
final class CardSourceModelsTest extends TestCase
{
    use RefreshDatabase;

    private B2bAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::forget('nbp.table_a.rates');
        Http::fake(['api.nbp.pl/*' => Http::response([['effectiveDate' => '2026-10-06', 'rates' => [['code' => 'EUR', 'mid' => 4.0]]]])]);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->account = B2bAccount::query()->create(['username' => '28788', 'password' => 'x', 'sites' => ['https://b2b.elten.com/']]);
    }

    public function test_merged_colours_are_listed_with_supplier_name_number_and_sizes(): void
    {
        $card = $this->card('0723341-0', 'ELTEN MAVERICK Low ESD S3S');
        $this->article($card, '0723341-0', 'red', 'MAVERICK red Low ESD S3S', ['38', '39', '40']);
        $this->article($card, '0723381-0', 'black', 'MAVERICK black Low ESD S3S', ['38', '39', '40']);
        $single = $this->card('0723391-0', 'ELTEN MAVERICK black-red Low ESD S3S');
        $this->article($single, '0723391-0', null, 'MAVERICK black-red Low ESD S3S', ['38', '39']);

        $expected = [
            ['number' => '0723341-0', 'name' => 'MAVERICK red Low ESD S3S', 'sizes' => ['38', '39', '40']],
            ['number' => '0723381-0', 'name' => 'MAVERICK black Low ESD S3S', 'sizes' => ['38', '39', '40']],
        ];
        $rows = collect($this->getJson('/api/products?sort=sku')->assertOk()->json('data'))->keyBy('sku');
        $this->assertSame($expected, $rows['0723341-0']['source_models']);
        // jeden model — bez listy
        $this->assertSame([], $rows['0723391-0']['source_models']);
        $this->assertSame($expected, $this->getJson('/api/products/'.$card->id)->assertOk()->json('source_models'));
    }

    public function test_same_model_under_another_number_differs_by_sizes(): void
    {
        $card = $this->card('1768502-0', 'ELTEN MATTHEW Pro BOA® GTX® Mid ESD S3S WR Typ 2');
        $this->article($card, '1768502-0', null, 'MATTHEW Pro BOA® GTX® Mid ESD S3S WR Typ 2', ['39', '40', '41', '47']);
        $this->article($card, '7685502-0', null, 'MATTHEW Pro BOA® GTX® Mid ESD S3S WR Typ 2', ['48']);

        $models = $this->getJson('/api/products/'.$card->id)->assertOk()->json('source_models');

        $this->assertSame(['39', '40', '41', '47'], $models[0]['sizes']);
        $this->assertSame(['48'], $models[1]['sizes']);
    }

    public function test_size_after_a_slash_is_cut_from_the_name(): void
    {
        // Atlas: tęgości jednego modelu pod numerami 45700 S1 / 45712 S1, nazwa pozycji z „/ rozmiar” na końcu
        $card = $this->card('45700 S1', 'Flash 4000 | ESD');
        $this->article($card, '45700 S1', 'tęgość 10', 'Flash 4000 | ESD, tęgość 10', ['36', '37'], sizeSeparator: '/', nameSeparator: ' / ');
        $this->article($card, '45712 S1', 'tęgość 12', 'Flash 4000 | ESD, tęgość 12', ['36'], sizeSeparator: '/', nameSeparator: ' / ');

        $models = $this->getJson('/api/products/'.$card->id)->assertOk()->json('source_models');

        $this->assertSame(['Flash 4000 | ESD, tęgość 10', 'Flash 4000 | ESD, tęgość 12'], array_column($models, 'name'));
    }

    public function test_size_codes_and_removed_numbers_are_not_models(): void
    {
        // Portwest: numer każdego rozmiaru = kod wiersza rozmiaru — to rozmiary, nie modele
        $portwest = $this->card('S503', 'Kurtka Bomber Calais');
        foreach (['S503NVRM' => 'Navy / M', 'S503NVRL' => 'Navy / L'] as $code => $label) {
            $this->identifier($portwest, $code, $code);
            $this->sizeRow($portwest, $code, $code, $label);
        }
        // drugi kolor zdjęty ze źródła — zostaje jeden model, bez listy
        $elten = $this->card('0723341-0', 'ELTEN MAVERICK Low ESD S3S');
        $this->article($elten, '0723341-0', 'red', 'MAVERICK red Low ESD S3S', ['38']);
        $this->article($elten, '0723381-0', 'black', 'MAVERICK black Low ESD S3S', ['38'], removed: true);

        $this->assertSame([], $this->getJson('/api/products/'.$portwest->id)->assertOk()->json('source_models'));
        $this->assertSame([], $this->getJson('/api/products/'.$elten->id)->assertOk()->json('source_models'));
    }

    public function test_colours_without_model_numbers_are_grouped_from_size_rows(): void
    {
        // Portwest (prośba 08.10.2026: lista jak u ELTEN dla wszystkich cenników): kolor tylko w etykiecie i kodzie
        // każdego rozmiaru, rozmiary od dostawcy alfabetycznie
        $card = $this->card('S843', 'Fartuch z kieszenią Portwest S843');
        foreach (['S843BGRL/XL' => 'Bottle Green (BGR) / L/XL', 'S843BGRS/M' => 'Bottle Green (BGR) / S/M', 'S843BGRXXL' => 'Bottle Green (BGR) / XXL',
            'S843BKRL/XL' => 'Black (BKR) / L/XL', 'S843BKRS/M' => 'Black (BKR) / S/M'] as $code => $label) {
            $this->sizeRow($card, (string) $code, (string) $code, $label);
        }

        $this->assertSame([
            ['number' => 'S843BGR', 'name' => 'Bottle Green (BGR)', 'sizes' => ['S/M', 'L/XL', 'XXL']],
            ['number' => 'S843BKR', 'name' => 'Black (BKR)', 'sizes' => ['S/M', 'L/XL']],
        ], $this->getJson('/api/products/'.$card->id)->assertOk()->json('source_models'));
        $rows = collect($this->getJson('/api/products?sort=sku')->assertOk()->json('data'))->keyBy('sku');
        $this->assertCount(2, $rows['S843']['source_models']);
    }

    public function test_group_number_is_the_common_code_part(): void
    {
        // Canis: rozmiar zakodowany liczbą („-92” = S) — wspólny początek do granicy członu
        $canis = $this->card('1610-001-000-00', 'Koszulka CXS DANIEL');
        foreach (['1610-001-100-92' => 'kolor biały / S', '1610-001-100-93' => 'kolor biały / M',
            '1610-001-400-92' => 'kolor granatowy / S', '1610-001-400-93' => 'kolor granatowy / M'] as $code => $label) {
            $this->sizeRow($canis, (string) $code, (string) $code, $label);
        }
        // Hultafors: kod bez członów, kolor „0404” z etykiety kończy numer; Delta Plus bez kodu w etykiecie — „…”
        $hultafors = $this->card('1100', 'AllroundWork, Kurtka ocieplana 1100');
        foreach (['11000404004' => '0404 - Black\Black / S', '11000404005' => '0404 - Black\Black / M',
            '11009504004' => '9504 - Navy\Black / S', '11009504012' => '9504 - Navy\Black / XXXXXL'] as $code => $label) {
            $this->sizeRow($hultafors, (string) $code, (string) $code, $label);
        }
        $delta = $this->card('M5PA3TSTR', 'SPODNIE ROBOCZE RIPSTOP M5PA3TSTR');
        foreach (['M5PA3TSTRNOGT' => 'Czarny L', 'M5PA3TSTRNOTM' => 'Czarny M', 'M5PA3TSTRBM3X' => 'Granatowy 3XL'] as $code => $label) {
            $this->sizeRow($delta, (string) $code, (string) $code, $label);
        }

        // Raw-Pol: kolor przyklejony do rozmiaru („KOS-5P” + „XXL”), etykieta „2xl” — bez cofania do „KOS”
        $rawpol = $this->card('KOS-5', 'Koszulka KOS-5');
        foreach (['KOS-5PM' => 'pomarańczowy m', 'KOS-5PXXL' => 'pomarańczowy 2xl', 'KOS-5SEM' => 'żółty m', 'KOS-5SEL' => 'żółty l'] as $code => $label) {
            $this->sizeRow($rawpol, (string) $code, (string) $code, $label);
        }

        // Raw-Pol: kolor = jeden rozmiar, kod bez koloru („RNYDO8”) — po odcięciu rozmiaru wszędzie „RNYDO”, więc cały kod
        $rnydo = $this->card('RNYDO', 'Rękawice RNYDO');
        foreach (['RNYDO7' => 'biało-zielony 7', 'RNYDO8' => 'biało-czerwony 8'] as $code => $label) {
            $this->sizeRow($rnydo, (string) $code, (string) $code, $label);
        }

        $numbers = fn (Product $card): array => array_column($this->getJson('/api/products/'.$card->id)->assertOk()->json('source_models'), 'number');
        $this->assertSame(['KOS-5P', 'KOS-5SE'], $numbers($rawpol));
        $this->assertSame(['RNYDO7', 'RNYDO8'], $numbers($rnydo));
        $this->assertSame(['1610-001-100', '1610-001-400'], $numbers($canis));
        $this->assertSame(['11000404', '11009504'], $numbers($hultafors));
        $this->assertSame(['M5PA3TSTRNO…', 'M5PA3TSTRBM3X'], $numbers($delta));
        $this->assertSame(['M', 'L'], $this->getJson('/api/products/'.$delta->id)->json('source_models.0.sizes'));
    }

    public function test_colour_only_rows_are_models_and_code_label_is_not_a_size(): void
    {
        // 3M: wiersz = kolor bez rozmiaru; ELTEN sznurówki: bez rozmiaru łącznik wpisuje do etykiety kod pozycji
        $helmet = $this->card('G3000NUV', 'Hełm ochronny 3M G3000NUV');
        $this->sizeRow($helmet, '7100001960', 'G3000NUV-VI', 'biały');
        $this->sizeRow($helmet, '7000009701', 'G3000NUV-GU', 'żółty');
        $laces = $this->card('0260090-0', 'ELTEN Laces');
        $this->sizeRow($laces, '0260090-0', '0260090-0', 'black / 0260090-0');
        $this->sizeRow($laces, '0260092-0', '0260092-0', 'beige / 0260092-0');
        // MAVIBO „kolor 20” bez rozmiaru — 20 to kolor, nie rozmiar
        $cap = $this->card('31000', 'Czapka 31000');
        $this->sizeRow($cap, '31000 20', '31000 20', 'kolor 20');
        $this->sizeRow($cap, '31000 22', '31000 22', 'kolor 22');

        $this->assertSame([
            ['number' => 'G3000NUV-VI', 'name' => 'biały', 'sizes' => []],
            ['number' => 'G3000NUV-GU', 'name' => 'żółty', 'sizes' => []],
        ], $this->getJson('/api/products/'.$helmet->id)->assertOk()->json('source_models'));
        $this->assertSame([
            ['number' => '0260090-0', 'name' => 'black', 'sizes' => []],
            ['number' => '0260092-0', 'name' => 'beige', 'sizes' => []],
        ], $this->getJson('/api/products/'.$laces->id)->assertOk()->json('source_models'));
        $this->assertSame(['kolor 20', 'kolor 22'], array_column($this->getJson('/api/products/'.$cap->id)->json('source_models'), 'name'));
    }

    public function test_size_only_labels_are_not_models(): void
    {
        // etykiety z produkcji (08.10.2026), które bez rozpoznania rozmiaru dawały „modele” będące rozmiarami
        $cases = [
            'Hultafors' => ['XS Regular*', 'S Regular', 'M Long*', 'XXXXXL Short*'],
            'Ejendals' => ['S=35-38', 'M=37-40', 'XL=45-48+'],
            'Safety Jogger' => ['S (34-38)', 'M (39-43)', 'W42L30', 'W44L34'],
            'Mascot' => ['82C42', '82C44', 'XS ONE', '2XLONE', '35/383PC', '39/433PC'],
            'Profix' => ['S (48)', '2L (54)', '35/36'],
            'Sara' => ['XXLA', 'XXXLA', 'LS', '1SIZE'],
            'JHK' => ['100x50', '140X70'],
            'Protekt' => ['mały', 'duży'],
            'SIR' => ['6-', '7-', '8'],
            'Canis' => ['48; / 50', '52; / 54'],
            'P4S' => ['rozmiar 22,5/35,0', 'rozmiar 23,0/36,0'],
            'Hultafors one size' => ['One-size', 'uni'],
            'Raw-Pol' => ['czarny 40', 'czarny 42', 'czarny uni'],
        ];
        foreach ($cases as $supplier => $labels) {
            $card = $this->card($supplier, $supplier);
            foreach ($labels as $i => $label) {
                $this->sizeRow($card, $supplier.$i, $supplier.'-'.$i, $label);
            }
            $this->assertSame([], $this->getJson('/api/products/'.$card->id)->assertOk()->json('source_models'), $supplier);
        }
    }

    private function card(string $sku, string $name): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => 'ELTEN',
            'catalog_price_net' => 10, 'purchase_price' => 5, 'stock' => 0,
        ]);
    }

    /**
     * Artykuł jak z łącznika ELTEN: numer na pozycji pierwszego rozmiaru, powiązanie i wiersz każdego rozmiaru.
     *
     * @param  list<string>  $sizes
     */
    private function article(
        Product $card,
        string $number,
        ?string $colour,
        string $name,
        array $sizes,
        bool $removed = false,
        string $sizeSeparator = ' ',
        string $nameSeparator = ' ',
    ): void {
        $this->identifier($card, $number, $number.'/'.$sizes[0], $colour, $removed);
        foreach ($sizes as $size) {
            $position = $number.'/'.$size;
            B2bProductLink::query()->create([
                'b2b_account_id' => $this->account->id, 'remote_id' => $position, 'product_id' => $card->id,
                'remote_sku' => $number.' '.$size, 'remote_name' => $name.$nameSeparator.$size,
            ]);
            // artykuł zdjęty ze źródła traci w pełnym przebiegu i identyfikator, i wiersze rozmiarów (sweepSizeRows)
            $this->sizeRow($card, $position, $number.$sizeSeparator.$size, ($colour !== null ? $colour.' / ' : '').$size, $removed);
        }
    }

    private function identifier(Product $card, string $value, string $position, ?string $label = null, bool $removed = false): void
    {
        ProductIdentifier::query()->create([
            'product_id' => $card->id, 'source_key' => 'b2b:'.$this->account->id, 'b2b_account_id' => $this->account->id,
            'position_key' => $position, 'type' => ProductIdentifier::TYPE_SOURCE_CODE, 'value' => $value,
            'normalized' => ProductIdentifierCode::code($value), 'variant_label' => $label,
            'removed_at' => $removed ? now() : null,
        ]);
    }

    private function sizeRow(Product $card, string $remoteId, string $sku, string $label, bool $removed = false): void
    {
        // wiersz rozmiaru = pozycja B2B powiązana z kartą (synchronizacja zapisuje oba)
        B2bProductLink::query()->firstOrCreate(
            ['b2b_account_id' => $this->account->id, 'remote_id' => $remoteId],
            ['product_id' => $card->id, 'remote_sku' => $sku],
        );
        ProductVariant::query()->create([
            'product_id' => $card->id, 'kind' => ProductVariant::KIND_SIZE, 'source' => 'b2b:'.$this->account->id,
            'b2b_account_id' => $this->account->id, 'remote_id' => $remoteId, 'sku' => $sku, 'label' => $label,
            'removed_at' => $removed ? now() : null,
        ]);
    }
}
