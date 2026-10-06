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
            $this->sizeRow($card, $position, $number.$sizeSeparator.$size, ($colour !== null ? $colour.' / ' : '').$size);
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

    private function sizeRow(Product $card, string $remoteId, string $sku, string $label): void
    {
        ProductVariant::query()->create([
            'product_id' => $card->id, 'kind' => ProductVariant::KIND_SIZE, 'source' => 'b2b:'.$this->account->id,
            'remote_id' => $remoteId, 'sku' => $sku, 'label' => $label,
        ]);
    }
}
