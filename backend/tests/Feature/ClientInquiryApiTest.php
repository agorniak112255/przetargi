<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductInquirySearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ClientInquiryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_handlowiec_analyzes_inquiry_and_strips_purchase_price(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $client = Client::query()->create(['name' => 'Firma Test', 'owner_id' => $user->id]);
        $product = Product::query()->create([
            'sku' => 'RNITZ-100',
            'name' => 'Rękawice nitrylowe',
            'manufacturer' => 'Supon',
            'norms' => 'EN 374',
            'catalog_price_net' => 2.40,
            'purchase_price' => 1.10,
            'stock' => 80,
        ]);
        $other = Product::query()->create([
            'sku' => 'RNITZ-200',
            'name' => 'Rękawice nitrylowe grubsze',
            'manufacturer' => 'Supon',
            'catalog_price_net' => 3.10,
            'purchase_price' => 1.80,
            'stock' => 20,
        ]);

        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Rękawice nitrylowe',
                'questions' => ['Czy macie rękawice nitrylowe?'],
                'product_queries' => ['rękawice nitrylowe'],
                'cards' => [[
                    'id' => 'sizes',
                    'title' => 'Rozmiar',
                    'prompt' => 'Klient nie podał rozmiarów',
                    'options' => [
                        ['id' => 'ask', 'label' => 'Dopytaj'],
                        ['id' => 'skip', 'label' => 'Nie dopytuj'],
                    ],
                    'allow_custom' => false,
                ]],
            ]);
        });

        $this->mock(ProductInquirySearch::class, function ($mock) use ($product, $other): void {
            $mock->shouldReceive('findMany')->once()->andReturn([[
                'query' => 'rękawice nitrylowe',
                'products' => [
                    [
                        'id' => $product->id,
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'manufacturer' => $product->manufacturer,
                        'norms' => $product->norms,
                        'catalog_price_net' => '2.40',
                        'purchase_price' => '1.10',
                        'currency' => 'PLN',
                        'stock' => 80,
                        'ai_match_percent' => 91,
                    ],
                    [
                        'id' => $other->id,
                        'sku' => $other->sku,
                        'name' => $other->name,
                        'manufacturer' => $other->manufacturer,
                        'catalog_price_net' => '3.10',
                        'purchase_price' => '1.80',
                        'currency' => 'PLN',
                        'stock' => 20,
                        'ai_match_percent' => 70,
                    ],
                ],
            ]]);
        });

        Sanctum::actingAs($user);

        $res = $this->postJson('/api/inquiries', [
            'body' => 'Dzień dobry, proszę o ofertę na rękawice nitrylowe do laboratorium.',
            'tone' => 'formal',
            'client_id' => $client->id,
        ]);

        $res->assertCreated()
            ->assertJsonPath('client.name', 'Firma Test')
            ->assertJsonPath('cards.0.id', 'product');

        $json = $res->json();
        $this->assertStringNotContainsString('purchase_price', json_encode($json, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('1.10', json_encode($json, JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('client_inquiries', [
            'user_id' => $user->id,
            'client_id' => $client->id,
        ]);
    }

    public function test_analyze_keeps_product_and_substitutes_on_each_line(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = Product::query()->create([
            'sku' => 'G10',
            'name' => 'Rękawice chemoodporne',
            'manufacturer' => 'Supon',
            'catalog_price_net' => 12.00,
            'purchase_price' => 5.00,
            'stock' => 40,
        ]);

        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Oferta BHP',
                'questions' => [],
                'product_queries' => ['rękawice chemoodporne'],
                'line_items' => [],
                'cards' => [],
            ]);
        });

        $this->mock(ProductInquirySearch::class, function ($mock) use ($product): void {
            $row = [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'manufacturer' => $product->manufacturer,
                'norms' => '',
                'catalog_price_net' => '12.00',
                'currency' => 'PLN',
                'stock' => 40,
                'ai_match_percent' => 86,
            ];
            $mock->shouldReceive('findMany')->once()->andReturnUsing(
                fn (array $queries): array => array_map(
                    fn (string $q): array => ['query' => $q, 'products' => [$row]],
                    $queries
                )
            );
        });

        Sanctum::actingAs($user);

        $res = $this->postJson('/api/inquiries', [
            'body' => "Dzień dobry\n\n30szt Rękawice chemoodporne rozmiar 10\n\n30szt Rękawice chemoodporne rozmiar 9",
            'tone' => 'formal',
        ]);

        $res->assertCreated()
            ->assertJsonPath('cards.0.id', 'product:item_1')
            ->assertJsonPath('cards.1.id', 'substitutes:item_1')
            ->assertJsonPath('cards.2.id', 'product:item_2')
            ->assertJsonPath('cards.3.id', 'substitutes:item_2')
            ->assertJsonPath('cards.0.quote', '30szt Rękawice chemoodporne rozmiar 10')
            ->assertJsonPath('cards.2.quote', '30szt Rękawice chemoodporne rozmiar 9');
    }

    public function test_compose_saves_reply_from_answers(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = Product::query()->create([
            'sku' => 'RNITZ-100',
            'name' => 'Rękawice nitrylowe',
            'manufacturer' => 'Supon',
            'catalog_price_net' => 2.40,
            'purchase_price' => 1.10,
            'stock' => 80,
        ]);

        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_body' => 'Proszę o informację o rękawicach nitrylowych do laboratorium.',
            'analysis' => [
                'questions' => ['Czy macie rękawice?'],
                'matches' => [[
                    'query' => 'rękawice nitrylowe',
                    'products' => [[
                        'id' => $product->id,
                        'sku' => 'RNITZ-100',
                        'name' => 'Rękawice nitrylowe',
                        'manufacturer' => 'Supon',
                        'norms' => 'EN 374',
                        'catalog_price_net' => '2.40',
                        'currency' => 'PLN',
                        'stock' => 80,
                        'score' => 91,
                    ]],
                ]],
                'cards' => [[
                    'id' => 'price',
                    'title' => 'Ceny',
                    'prompt' => 'Czy podać cenę?',
                    'options' => [
                        ['id' => 'none', 'label' => 'Bez ceny'],
                        ['id' => 'catalog', 'label' => 'Cena katalogowa'],
                    ],
                    'allow_custom' => false,
                ]],
            ],
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/inquiries/{$inquiry->id}/compose", [
            'answers' => [
                'price' => ['option_id' => 'none'],
            ],
            'extra_note' => 'Nie podawaj terminu',
        ])
            ->assertOk()
            ->assertJsonPath('extra_note', 'Nie podawaj terminu');

        $body = (string) $inquiry->fresh()->reply_body;
        $this->assertStringContainsString('RNITZ-100', $body);
        $this->assertStringNotContainsString('1.10', $body);
        $this->assertStringNotContainsString('magazyn', mb_strtolower($body));
        $this->assertStringNotContainsString('Stan magazynowy', $body);
    }

    public function test_compose_letter_quotes_pln_offer_not_eur_catalog(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_subject' => 'Rękawice i kalosze',
            'source_body' => "30szt Rękawice chemoodporne rozmiar 10\n4szt Kalosze chemoodporne rozmiar 43",
            'analysis' => [
                'line_items' => [
                    [
                        'id' => 'item_1',
                        'quote' => '30szt Rękawice chemoodporne rozmiar 10',
                        'qty' => '30 szt.',
                        'size' => '10',
                        'query' => 'Rękawice chemoodporne',
                    ],
                    [
                        'id' => 'item_2',
                        'quote' => '4szt Kalosze chemoodporne rozmiar 43',
                        'qty' => '4 szt.',
                        'size' => '43',
                        'query' => 'Kalosze chemoodporne',
                    ],
                ],
                'matches' => [[
                    'query' => 'Rękawice chemoodporne',
                    'products' => [[
                        'id' => 11,
                        'sku' => '37900VP',
                        'name' => 'AlphaTec 37900VP',
                        'manufacturer' => 'Ansell',
                        'norms' => 'EN ISO 374-1',
                        'catalog_price_net' => '19.85',
                        'currency' => 'PLN',
                        'catalog_pln' => 19.85,
                        'offer_pln' => 22.15,
                        'stock' => 0,
                        'score' => 90,
                    ]],
                ], [
                    'query' => 'Kalosze chemoodporne',
                    'products' => [[
                        'id' => 22,
                        'sku' => 'FW94',
                        'name' => 'Kalosze S4',
                        'manufacturer' => 'Portwest',
                        'norms' => 'S4',
                        'catalog_price_net' => '55.46',
                        'currency' => 'PLN',
                        'catalog_pln' => 55.46,
                        'offer_pln' => 65.44,
                        'stock' => 0,
                        'score' => 88,
                    ]],
                ]],
                'cards' => [],
            ],
        ]);

        Sanctum::actingAs($user);
        $this->postJson("/api/inquiries/{$inquiry->id}/compose", [
            'answers' => [
                'price' => ['option_id' => 'catalog_margin'],
            ],
        ])->assertOk();

        $body = (string) $inquiry->fresh()->reply_body;
        $this->assertStringContainsString('30 szt., rozmiar 10', $body);
        $this->assertStringContainsString('SKU 37900VP', $body);
        $this->assertStringContainsString('22,15 zł netto / szt.', $body);
        $this->assertStringContainsString('65,44 zł netto / szt.', $body);
        $this->assertStringNotContainsString('EUR', $body);
        $this->assertStringNotContainsString('Stan magazynowy', $body);
        $this->assertStringNotContainsString('4.67', $body);
        $this->assertSame('Oferta — Rękawice i kalosze', $inquiry->fresh()->reply_subject);
    }

    public function test_other_user_cannot_open_inquiry(): void
    {
        $owner = User::factory()->withRole('handlowiec')->create();
        $other = User::factory()->withRole('handlowiec')->create();
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $owner->id,
            'tone' => 'formal',
            'source_body' => 'Proszę o informację o rękawicach nitrylowych XL.',
        ]);

        Sanctum::actingAs($other);

        $this->getJson("/api/inquiries/{$inquiry->id}")->assertForbidden();
        $this->postJson("/api/inquiries/{$inquiry->id}/compose", [
            'answers' => [],
        ])->assertForbidden();
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/inquiries', [
            'body' => 'Proszę o informację o rękawicach nitrylowych do laboratorium.',
            'tone' => 'formal',
        ])->assertForbidden();
    }

    public function test_ai_task_catalog_includes_client_inquiry(): void
    {
        $keys = \App\Services\Ai\AiTask::keys();
        $this->assertContains('client_inquiry', $keys);
    }
}
