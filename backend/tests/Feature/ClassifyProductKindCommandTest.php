<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Product;
use App\Services\Catalog\ProductKindClassifier;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Rodzaj produktu rozpoznany modelem to podgląd do szukania luk w regułach rodziny PPE — nic nie zapisuje, a podstawa
 * „dane” wymaga cytatu obecnego w karcie. Zapisana rodzina działa jak bramka dopasowania (compatibleProduct,
 * narrowSkuPool), więc rodzina z samej wiedzy modelu nie może trafić do bazy.
 */
final class ClassifyProductKindCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);
    }

    public function test_classifier_confirms_evidence_in_card_text_and_never_gives_family_outside_ppe(): void
    {
        [$activ, $thumb, $heat, $tape, $glue] = $this->cardsWithoutFamily();
        $this->fakeModel([
            ['id' => $activ->id, 'ppe' => 'tak', 'family' => 'gloves', 'type' => 'rękaw spawalniczy', 'basis' => 'wiedza_modelu', 'evidence' => null],
            ['id' => $thumb->id, 'ppe' => 'tak', 'family' => 'gloves', 'type' => 'rękaw ochronny', 'basis' => 'dane', 'evidence' => 'ściągacz z otworem na kciuk'],
            ['id' => $heat->id, 'ppe' => 'nie', 'family' => 'gloves', 'type' => 'rękaw termokurczliwy', 'basis' => 'dane', 'evidence' => 'Rękaw termokurczliwy'],
            ['id' => $tape->id, 'ppe' => 'tak', 'family' => 'banan', 'type' => 'taśma', 'basis' => 'dane', 'evidence' => 'zarękawek antyprzecięciowy'],
        ]);

        $results = app(ProductKindClassifier::class)->classify(collect([$activ, $thumb, $heat, $tape, $glue]));

        $this->assertSame(['gloves', 'wiedza_modelu', null, false], [
            $results[$activ->id]['family'], $results[$activ->id]['basis'], $results[$activ->id]['evidence'], $results[$activ->id]['evidence_confirmed'],
        ], 'rozpoznanie z wiedzy modelu jest oznaczone jako takie');
        $this->assertSame(['gloves', 'dane', true, false], [
            $results[$thumb->id]['family'], $results[$thumb->id]['basis'], $results[$thumb->id]['evidence_confirmed'], $results[$thumb->id]['evidence_matches_rule'],
        ], 'cytat z opisu karty potwierdza podstawę „dane”; nie pasuje do wzorca rodziny — to luka w regułach');
        $this->assertSame([null, 'nie'], [$results[$heat->id]['family'], $results[$heat->id]['ppe']], 'poza ŚOI nie ma rodziny');
        $this->assertSame([null, 'wiedza_modelu', false], [
            $results[$tape->id]['family'], $results[$tape->id]['basis'], $results[$tape->id]['evidence_confirmed'],
        ], 'rodzina spoza listy odpada, cytat spoza karty nie jest dowodem');
        $this->assertArrayNotHasKey($glue->id, $results, 'karta bez odpowiedzi modelu nie ma wyniku');
        $this->assertCount(1, $this->sent, 'pięć kart w jednej paczce');
    }

    public function test_command_previews_cards_without_family_and_changes_nothing(): void
    {
        [$activ, $thumb, $heat, $tape] = $this->cardsWithoutFamily();
        $gloves = Product::query()->create($this->base() + ['sku' => 'NIT-1', 'name' => 'Rękawice nitrylowe ProGlove', 'manufacturer' => 'PROS']);
        $this->assertSame(PpeAssortment::FAMILY_GLOVES, $gloves->fresh()->ppe_family);
        $this->fakeModel([
            ['id' => $activ->id, 'ppe' => 'tak', 'family' => 'gloves', 'type' => 'rękaw spawalniczy', 'basis' => 'wiedza_modelu', 'evidence' => null],
            ['id' => $thumb->id, 'ppe' => 'tak', 'family' => 'gloves', 'type' => 'rękaw ochronny', 'basis' => 'dane', 'evidence' => 'ściągacz z otworem na kciuk'],
            ['id' => $heat->id, 'ppe' => 'nie', 'family' => null, 'type' => 'rękaw termokurczliwy', 'basis' => 'dane', 'evidence' => 'Rękaw termokurczliwy'],
            ['id' => $tape->id, 'ppe' => 'nieznane', 'family' => null, 'type' => null, 'basis' => 'wiedza_modelu', 'evidence' => null],
        ]);
        $before = Product::query()->orderBy('id')->get(['id', 'ppe_family', 'updated_at'])->toArray();

        $this->artisan('products:classify-kind')
            ->expectsOutputToContain('Sprawdzam 5 kart modelem')
            ->expectsOutputToContain('Podsumowanie: zgodne 0 · różne 0 · nowa z danych 1 · nowa z wiedzy modelu 1 · poza ŚOI 1 · nieznane 1 · bez odpowiedzi 1')
            ->assertSuccessful();

        $this->assertStringNotContainsString('Rękawice nitrylowe ProGlove', implode("\n", $this->sent), 'domyślnie tylko karty bez rodziny');
        $this->assertSame($before, Product::query()->orderBy('id')->get(['id', 'ppe_family', 'updated_at'])->toArray(), 'podgląd niczego nie zapisuje');
    }

    /** @return list<Product> */
    private function cardsWithoutFamily(): array
    {
        $cards = [
            Product::query()->create($this->base() + ['sku' => '59416260', 'name' => 'ActivArmr 59416 Size 26,0', 'manufacturer' => 'Ansell']),
            Product::query()->create($this->base() + [
                'sku' => '11281180-W',
                'name' => 'HYFLEX 11281 SIZE 18,0 THUMBSLOT WIDE',
                'manufacturer' => 'Ansell',
                'description' => 'Dostępne długości: 12" (30 cm), 16" (40 cm). Budowa: dzianina. Mankiet: ściągacz z otworem na kciuk.',
            ]),
            Product::query()->create($this->base() + ['sku' => '7000032369', 'name' => 'Rękaw termokurczliwy 3M™ HDCW, 55/15-500 mm', 'manufacturer' => '3M']),
            Product::query()->create($this->base() + ['sku' => '515739', 'name' => 'Taśma winylowa 3M™ 471, czarna, 25 mm x 33 m', 'manufacturer' => '3M']),
            Product::query()->create($this->base() + ['sku' => '7100318596', 'name' => 'Klej epoksydowy 3M™ Scotch-Weld™ DP420, czarny, 400 ml', 'manufacturer' => '3M']),
        ];
        foreach ($cards as $card) {
            $this->assertNull($card->fresh()->ppe_family, $card->sku.' nie ma rodziny z reguł');
        }

        return $cards;
    }

    /** @return array<string, mixed> */
    private function base(): array
    {
        return ['catalog_price_net' => 10, 'purchase_price' => 8, 'stock' => 1];
    }

    /** @param list<array<string, mixed>> $items */
    private function fakeModel(array $items): void
    {
        $this->sent = [];
        Http::fake(function (Request $request) use ($items) {
            $this->sent[] = (string) ($request['messages'][1]['content'] ?? '');

            return Http::response([
                'choices' => [['message' => ['content' => json_encode(['items' => $items], JSON_UNESCAPED_UNICODE)], 'finish_reason' => 'stop']],
            ]);
        });
    }
}
