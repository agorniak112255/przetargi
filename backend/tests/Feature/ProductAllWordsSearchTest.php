<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\Product;
use App\Models\User;
use App\Services\Search\ProductListTextSearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Wyszukiwarka kart w oknie „Połącz towar XL z kartą”: /products?words=all (każde słowo zawęża listę)
 * i /products/word-counts (ile kart trafia każde słowo osobno).
 */
final class ProductAllWordsSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::forget('nbp.table_a.rates');
        Http::fake(['api.nbp.pl/*' => Http::response([['effectiveDate' => '2026-09-29', 'rates' => [['code' => 'EUR', 'mid' => 4.0]]]])]);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_every_word_narrows_the_list_where_the_whole_phrase_finds_nothing(): void
    {
        $this->card('RTEPO', 'Rękawice ochronne TEPO.', 'Reis');
        $this->card('RTEPO-DOTS', 'Rękawice ochronne TEPO-DOTS.', 'Reis');
        $this->card('RNIT', 'Rękawice robocze nitrylowe', 'Reis');
        $this->card('OK-1', 'Okulary RTEPO zapas', 'UVEX');

        // cała fraza jako jeden ciąg — żadna karta jej nie ma (dotychczasowe działanie listy bez zmian)
        $this->assertSame([], $this->skus('rękawice rtepo'));
        $this->assertSame(['RTEPO', 'RTEPO-DOTS'], $this->skus('rękawice rtepo', true));
        // kolejność słów i interpunkcja na brzegach bez znaczenia
        $this->assertSame(['RTEPO', 'RTEPO-DOTS'], $this->skus('RTEPO, rękawice.', true));
        $this->assertSame(['RTEPO-DOTS'], $this->skus('rękawice rtepo dots', true));
        // słowo, którego nie ma w żadnej karcie, daje pustą listę — nie jest pomijane
        $this->assertSame([], $this->skus('rękawice rtepo czarne', true));
        // jedno słowo — jak bez trybu
        $this->assertSame($this->skus('rtepo'), $this->skus('rtepo', true));
        // jedno słowo z interpunkcją albo ze znakiem obok — szukane samo słowo, nie cała fraza
        $this->assertSame([], $this->skus('a rtepo,'));
        // …i to słowo jest numerem do kolejności: dokładne SKU, potem SKU z tym ciągiem, potem reszta
        $this->assertSame(['RTEPO', 'RTEPO-DOTS', 'OK-1'], $this->skus('a rtepo,', true));
    }

    public function test_xl_code_counts_as_a_word_and_is_reported(): void
    {
        $pheos = $this->card('9198.014', 'Okulary Pheos CX2', 'UVEX');
        $this->card('9198.015', 'Gogle Pheos', 'UVEX');
        $item = ErpItem::query()->create([
            'xl_gid' => 1, 'code' => 'SOK9198014', 'name' => 'OKULARY PHEOS', 'unit' => 'szt', 'archived' => false,
            'stock_trade' => 0, 'stock_total' => 0, 'synced_at' => now(),
        ]);
        ErpItemLink::query()->create([
            'erp_item_id' => $item->id, 'product_id' => $pheos->id, 'status' => ErpItemLink::STATUS_AUTO,
            'method' => ErpItemLink::METHOD_NAME, 'matched_value' => $item->code, 'last_seen_at' => now(),
        ]);

        $rows = $this->getJson('/api/products?words=all&sort=sku&q='.rawurlencode('sok9198014 okulary'))->assertOk()->json('data');

        $this->assertSame(['9198.014'], array_column($rows, 'sku'));
        $this->assertSame(['SOK9198014'], $rows[0]['erp_codes']);
    }

    public function test_word_counts_count_each_word_alone(): void
    {
        $this->card('RTEPO', 'Rękawice ochronne TEPO.', 'Reis');
        $this->card('RTEPO-DOTS', 'Rękawice ochronne TEPO-DOTS.', 'Reis');
        $this->card('RNIT', 'Rękawice robocze nitrylowe', 'Reis');

        $words = $this->getJson('/api/products/word-counts?q='.rawurlencode('rękawice robocze RTEPO czarne rękawice'))
            ->assertOk()->json('words');

        $this->assertSame([
            ['word' => 'rękawice', 'count' => 3],
            ['word' => 'robocze', 'count' => 1],
            ['word' => 'RTEPO', 'count' => 2],
            ['word' => 'czarne', 'count' => 0],
        ], $words);
        $this->getJson('/api/products/word-counts')->assertStatus(422);
    }

    public function test_phrase_words_trim_punctuation_keep_codes_and_limit(): void
    {
        $this->assertSame(['OCHR', 'BPBOCH8540/8', '9198.014', 'x1'], ProductListTextSearch::phraseWords(' OCHR. BPBOCH8540/8 (9198.014) — a x1 ochr '));
        $this->assertSame(['a1', 'b2'], ProductListTextSearch::phraseWords('a1 b2 c3', 2));
    }

    /** @return list<string> */
    private function skus(string $q, bool $allWords = false): array
    {
        $url = '/api/products?sort=sku&q='.rawurlencode($q).($allWords ? '&words=all' : '');

        return array_column($this->getJson($url)->assertOk()->json('data'), 'sku');
    }

    private function card(string $sku, string $name, string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => $manufacturer,
            'catalog_price_net' => 10, 'purchase_price' => 5, 'stock' => 0,
        ]);
    }
}
