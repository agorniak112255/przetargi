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
            $mock->shouldReceive('find')->once()->andReturn([
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
            ]);
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

        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Rękawice nitrylowe RNITZ-100',
                'body' => "Dzień dobry,\n\nPotwierdzamy dostępność RNITZ-100.\n\nPozdrawiamy,\nZespół Supon",
            ]);
        });

        Sanctum::actingAs($user);

        $this->postJson("/api/inquiries/{$inquiry->id}/compose", [
            'answers' => [
                'price' => ['option_id' => 'none'],
            ],
            'extra_note' => 'Nie podawaj terminu',
        ])
            ->assertOk()
            ->assertJsonPath('reply_subject', 'Rękawice nitrylowe RNITZ-100')
            ->assertJsonPath('extra_note', 'Nie podawaj terminu');

        $this->assertStringContainsString('RNITZ-100', (string) $inquiry->fresh()->reply_body);
        $this->assertStringNotContainsString('1.10', (string) $inquiry->fresh()->reply_body);
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
