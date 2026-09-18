<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientInquiry;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductInquirySearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Trzy szablony listu do klienta.
 *
 * Ten sam dobór produktów wygląda inaczej w zależności od szablonu, ale tekst
 * zawsze pochodzi z karty wyrobu albo z zapytania klienta:
 *
 *  - handlowy  — nazwa, SKU i producent (pełna specyfikacja),
 *  - oficjalny — nazwa i akapit opisu z karty, bez SKU,
 *  - bez SKU   — jedno zdanie opisu bez marki i modelu.
 */
final class ClientInquiryToneApiTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTION = 'Rękawice ochronne VITAL 175 marki MAPA przeznaczone są do prac '
        .'wymagających precyzji w środowiskach o małej agresywności chemicznej. Wykonano je '
        .'z naturalnego lateksu z certyfikatem FSC.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function product(array $extra = []): Product
    {
        return Product::query()->create(array_merge([
            'sku' => 'VIT-175',
            'name' => 'VITAL 175',
            'model_name' => 'VITAL 175',
            'manufacturer' => 'MAPA',
            'norms' => 'EN 388, EN 374',
            'description' => self::DESCRIPTION,
            'catalog_price_net' => 12.00,
            'purchase_price' => 5.00,
            'stock' => 40,
        ], $extra));
    }

    private function mockAnalysis(Product $product): void
    {
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->andReturn([
                'subject' => 'Rękawice',
                'questions' => [],
                'product_queries' => ['rękawice lateksowe'],
                'line_items' => [],
                'cards' => [],
            ]);
        });

        $row = [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'manufacturer' => $product->manufacturer,
            'norms' => (string) $product->norms,
            'catalog_price_net' => '12.00',
            'currency' => 'PLN',
            'stock' => 40,
            'ai_match_percent' => 88,
        ];
        $this->mock(ProductInquirySearch::class, function ($mock) use ($row): void {
            $mock->shouldReceive('findMany')->andReturnUsing(
                fn (array $queries): array => array_map(
                    fn (string $q): array => ['query' => $q, 'products' => [$row]],
                    $queries
                )
            );
        });
    }

    private function createInquiry(string $tone): array
    {
        $res = $this->postJson('/api/inquiries', [
            'body' => "Dzień dobry\n\n30szt Rękawice lateksowe rozmiar 9",
            'tone' => $tone,
        ])->assertCreated();

        return [$res->json(), (string) $res->json('reply_body')];
    }

    public function test_handlowy_template_lists_the_full_specification(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = $this->product();
        $this->mockAnalysis($product);
        Sanctum::actingAs($user);

        [, $body] = $this->createInquiry(ClientInquiry::TONE_HANDLOWY);

        $this->assertStringContainsString('Produkt: VITAL 175 (SKU VIT-175), MAPA', $body);
        $this->assertStringContainsString('Normy: EN 388, EN 374', $body);
        // pełna specyfikacja nie sili się na opis z karty
        $this->assertStringNotContainsString('agresywności chemicznej', $body);
    }

    public function test_formal_template_adds_the_card_description_and_hides_the_sku(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = $this->product();
        $this->mockAnalysis($product);
        Sanctum::actingAs($user);

        [, $body] = $this->createInquiry(ClientInquiry::TONE_FORMAL);

        $this->assertStringContainsString('Produkt: VITAL 175', $body);
        $this->assertStringContainsString('Rękawice ochronne VITAL 175 marki MAPA przeznaczone są', $body);
        $this->assertStringContainsString('Normy: EN 388, EN 374', $body);
        $this->assertStringNotContainsString('SKU', $body);
    }

    public function test_no_sku_template_hides_the_brand_the_model_and_the_code(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = $this->product();
        $this->mockAnalysis($product);
        Sanctum::actingAs($user);

        [, $body] = $this->createInquiry(ClientInquiry::TONE_NO_SKU);

        $this->assertStringContainsString(
            'Produkt: Rękawice ochronne przeznaczone są do prac wymagających precyzji',
            $body,
        );
        $this->assertStringContainsString('Normy: EN 388, EN 374', $body);
        $this->assertStringNotContainsString('SKU', $body);
        $this->assertStringNotContainsString('MAPA', $body);
        // „VITAL 175” jest w cytacie klienta tylko wtedy, gdy sam je napisał —
        // w tym zapytaniu nie napisał, więc model nie może wyjść z naszej karty.
        $this->assertStringNotContainsString('VITAL', $body);
    }

    public function test_no_sku_template_falls_back_to_the_words_of_the_client(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        // karta bez opisu — nie ma z czego zrobić zdania bez modelu
        $product = $this->product(['description' => null]);
        $this->mockAnalysis($product);
        Sanctum::actingAs($user);

        [, $body] = $this->createInquiry(ClientInquiry::TONE_NO_SKU);

        $this->assertStringContainsString('Produkt: 30szt Rękawice lateksowe rozmiar 9', $body);
        $this->assertStringNotContainsString('SKU', $body);
        $this->assertStringNotContainsString('MAPA', $body);
    }

    public function test_template_can_be_switched_on_the_reply_page(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = $this->product();
        $this->mockAnalysis($product);
        Sanctum::actingAs($user);

        [$payload] = $this->createInquiry(ClientInquiry::TONE_HANDLOWY);
        $id = (int) $payload['id'];

        $switched = $this->postJson("/api/inquiries/{$id}/compose", [
            'answers' => [],
            'tone' => ClientInquiry::TONE_NO_SKU,
        ])->assertOk();

        $this->assertSame(ClientInquiry::TONE_NO_SKU, $switched->json('tone'));
        $body = (string) $switched->json('reply_body');
        $this->assertStringNotContainsString('SKU VIT-175', $body);
        $this->assertStringContainsString('Rękawice ochronne przeznaczone są', $body);
        $this->assertDatabaseHas('client_inquiries', ['id' => $id, 'tone' => ClientInquiry::TONE_NO_SKU]);

        // Powrót do pełnej specyfikacji przepisuje list z powrotem.
        $back = $this->postJson("/api/inquiries/{$id}/compose", [
            'answers' => [],
            'tone' => ClientInquiry::TONE_HANDLOWY,
        ])->assertOk();
        $this->assertStringContainsString('SKU VIT-175', (string) $back->json('reply_body'));
    }

    public function test_compose_without_tone_keeps_the_saved_template(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = $this->product();
        $this->mockAnalysis($product);
        Sanctum::actingAs($user);

        [$payload] = $this->createInquiry(ClientInquiry::TONE_NO_SKU);
        $id = (int) $payload['id'];

        $again = $this->postJson("/api/inquiries/{$id}/compose", ['answers' => []])->assertOk();

        $this->assertSame(ClientInquiry::TONE_NO_SKU, $again->json('tone'));
        $this->assertStringNotContainsString('SKU', (string) $again->json('reply_body'));
    }

    public function test_letter_ends_without_our_own_signature(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = $this->product();
        $this->mockAnalysis($product);
        Sanctum::actingAs($user);

        [$payload, $body] = $this->createInquiry(ClientInquiry::TONE_HANDLOWY);

        // Każdy handlowiec ma własną stopkę w programie pocztowym — nasz podpis
        // dawał dwa podpisy pod jednym listem.
        $this->assertStringNotContainsString('Z poważaniem', $body);
        $this->assertStringNotContainsString('Zespół Supon', $body);
        $this->assertStringEndsWith('W razie pytań zapraszamy do kontaktu.', trim($body));

        $html = (string) $payload['reply_html'];
        $this->assertStringNotContainsString('Z poważaniem', $html);
        $this->assertStringNotContainsString('Zespół Supon', $html);
    }

    public function test_closing_sentence_appears_once_even_when_the_note_repeats_it(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = $this->product();
        $this->mockAnalysis($product);
        Sanctum::actingAs($user);

        [$payload, $body] = $this->createInquiry(ClientInquiry::TONE_HANDLOWY);
        $this->assertSame(1, mb_substr_count($body, 'W razie pytań zapraszamy do kontaktu.'));
        $this->assertSame(1, mb_substr_count((string) $payload['reply_html'], 'W razie pytań zapraszamy do kontaktu.'));

        // Handlowiec wpisał to samo zdanie w „Dopisku do listu” — klient dostawał
        // je wtedy dwa razy pod rząd.
        $id = (int) $payload['id'];
        $with = $this->postJson("/api/inquiries/{$id}/compose", [
            'answers' => [],
            'extra_note' => 'Towar dostępny od ręki. W razie pytań zapraszamy do kontaktu.',
        ])->assertOk();

        $this->assertSame(1, mb_substr_count((string) $with->json('reply_body'), 'W razie pytań zapraszamy do kontaktu.'));
        $this->assertSame(1, mb_substr_count((string) $with->json('reply_html'), 'W razie pytań zapraszamy do kontaktu.'));
        $this->assertStringContainsString('Towar dostępny od ręki.', (string) $with->json('reply_body'));
    }

    public function test_unknown_template_is_rejected(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/inquiries', [
            'body' => 'Dzień dobry, proszę o wycenę rękawic nitrylowych.',
            'tone' => 'poetycki',
        ])->assertStatus(422)->assertJsonValidationErrors('tone');
    }
}
