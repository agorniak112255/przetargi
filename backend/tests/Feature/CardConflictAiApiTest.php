<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * „Sprawdź modelem” w oknie „Weryfikacja karty”: model tylko proponuje sprzeczności, serwer
 * przepuszcza wyłącznie te, których każdy cytat występuje dosłownie we wskazanym polu karty.
 */
final class CardConflictAiApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_przyjmuje_sprzecznosci_z_dwoma_dosłownymi_cytatami(): void
    {
        $product = $this->card();
        $llm = $this->mockLlm();
        $llm->shouldReceive('chatJson')
            ->once()
            ->withArgs(function (array $messages, ?float $temperature, ?int $maxTokens, ?string $model, ?AiTask $task): bool {
                $user = (string) $messages[1]['content'];

                return $task === AiTask::CardConflicts
                    && str_contains($user, 'kat. II')
                    && str_contains($user, 'Kategoria: III')
                    && str_contains($user, 'Konstrukcja cięta i szyta');
            })
            ->andReturn(['conflicts' => [
                [
                    'parameter' => 'kategoria ŚOI',
                    'explanation' => 'Normy podają kategorię II, a specyfikacja kategorię III.',
                    'quotes' => [
                        ['field' => 'norms', 'text' => 'kat. II'],
                        ['field' => 'specs', 'text' => 'Kategoria: III'],
                    ],
                ],
                [
                    'parameter' => 'konstrukcja',
                    'explanation' => 'Cechy mówią o konstrukcji bezszwowej, opis o ciętej i szytej.',
                    'quotes' => [
                        // inna wielkość liter i podwójna spacja — zwracamy fragment z karty
                        ['field' => 'features', 'text' => 'bezszwowa  konstrukcja'],
                        ['field' => 'description', 'text' => 'konstrukcja cięta i szyta'],
                    ],
                ],
            ]]);

        $response = $this->postJson('/api/products/'.$product->id.'/conflicts/ai')
            ->assertOk()
            ->assertJsonPath('rejected', 0)
            ->assertJsonPath('cached', false)
            ->assertJsonPath('prompt_version', 'conflicts-2026-09-15')
            ->assertJsonCount(2, 'conflicts')
            ->assertJsonPath('conflicts.0.parameter', 'kategoria ŚOI')
            ->assertJsonPath('conflicts.0.quotes.0.source', 'norms')
            ->assertJsonPath('conflicts.0.quotes.0.text', 'kat. II')
            ->assertJsonPath('conflicts.0.quotes.0.find', 'kat. II')
            ->assertJsonPath('conflicts.0.quotes.0.verdict', 'unclear')
            ->assertJsonPath('conflicts.0.quotes.1.source', 'specs')
            ->assertJsonPath('conflicts.0.quotes.1.quote', 'Kategoria: III')
            ->assertJsonPath('conflicts.0.quotes.1.find', 'Kategoria: III')
            ->assertJsonPath('conflicts.1.quotes.0.text', 'Bezszwowa konstrukcja')
            ->assertJsonPath('conflicts.1.quotes.0.find', 'Bezszwowa konstrukcja')
            ->assertJsonPath('conflicts.1.quotes.1.source', 'description')
            ->assertJsonPath('conflicts.1.quotes.1.find', 'Konstrukcja cięta i szyta');

        $this->assertIsString($response->json('checked_at'));
    }

    public function test_odrzuca_sprzecznosci_bez_potwierdzonych_cytatow_a_pozostale_przyjmuje(): void
    {
        $product = $this->card();
        $llm = $this->mockLlm();
        $llm->shouldReceive('chatJson')->once()->andReturn(['conflicts' => [
            $this->conflict('zmyślony cytat', [['norms', 'kat. II'], ['specs', 'Kategoria: IV']]),
            $this->conflict('cytat z innego pola', [['norms', 'kat. II'], ['description', 'Kategoria: III']]),
            $this->conflict('jeden cytat', [['norms', 'kat. II']]),
            $this->conflict('dwa identyczne', [['norms', 'kat. II'], ['norms', 'KAT.  II']]),
            $this->conflict('nieznane pole', [['attributes', 'kat. II'], ['specs', 'Kategoria: III']]),
            $this->conflict('za krótki cytat', [['norms', 'I'], ['specs', 'Kategoria: III']]),
            'nie-obiekt',
            $this->conflict('kategoria ŚOI', [['norms', 'kat. II'], ['specs', 'Kategoria: III']]),
        ]]);

        $this->postJson('/api/products/'.$product->id.'/conflicts/ai')
            ->assertOk()
            ->assertJsonPath('rejected', 7)
            ->assertJsonCount(1, 'conflicts')
            ->assertJsonPath('conflicts.0.parameter', 'kategoria ŚOI');
    }

    public function test_przycina_parametr_i_wyjasnienie(): void
    {
        $product = $this->card();
        $llm = $this->mockLlm();
        $llm->shouldReceive('chatJson')->once()->andReturn(['conflicts' => [[
            'parameter' => str_repeat('p', 200),
            'explanation' => str_repeat('w', 900),
            'quotes' => [['field' => 'norms', 'text' => 'kat. II'], ['field' => 'specs', 'text' => 'Kategoria: III']],
        ]]]);

        $response = $this->postJson('/api/products/'.$product->id.'/conflicts/ai')->assertOk();

        $this->assertSame(80, mb_strlen((string) $response->json('conflicts.0.parameter')));
        $this->assertSame(300, mb_strlen((string) $response->json('conflicts.0.explanation')));
    }

    public function test_bledy_modelu_402_i_429_wracaja_jako_422_z_komunikatem(): void
    {
        $product = $this->card();
        $credits = 'API AI odmówiło (HTTP 402: Insufficient credits). Konto modelu AI nie ma środków.';
        $limit = 'Limit zapytań modelu AI (HTTP 429). To OpenRouter/dostawca modelu, nie Tavily. Poczekaj ok. minutę i ponów próbę.';
        $llm = $this->mockLlm();
        $llm->shouldReceive('chatJson')->twice()->andThrowExceptions([new RuntimeException($credits), new RuntimeException($limit)]);

        $this->postJson('/api/products/'.$product->id.'/conflicts/ai')
            ->assertStatus(422)
            ->assertJsonPath('message', $credits);
        $this->postJson('/api/products/'.$product->id.'/conflicts/ai')
            ->assertStatus(422)
            ->assertJsonPath('message', $limit);
    }

    public function test_odpowiedz_bez_listy_konczy_sie_bledem_i_nie_trafia_do_cache(): void
    {
        $product = $this->card();
        $llm = $this->mockLlm();
        $llm->shouldReceive('chatJson')->twice()->andReturn(['wynik' => 'brak'], ['conflicts' => []]);

        $this->postJson('/api/products/'.$product->id.'/conflicts/ai')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Model zwrócił odpowiedź bez listy sprzeczności. Ponów próbę.');
        $this->postJson('/api/products/'.$product->id.'/conflicts/ai')
            ->assertOk()
            ->assertJsonPath('cached', false)
            ->assertJsonCount(0, 'conflicts');
    }

    public function test_wylaczone_ai_zwraca_422_bez_zapytania_do_modelu(): void
    {
        AiSetting::query()->create([
            'enabled' => false,
            'provider' => 'openai_compatible',
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'openai/gpt-4o',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
        ]);
        $product = $this->card();

        $this->postJson('/api/products/'.$product->id.'/conflicts/ai')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Integracja AI jest wyłączona. Włącz ją w Ustawieniach AI.');
        Http::assertNothingSent();
    }

    public function test_cache_omija_model_do_zmiany_karty_albo_odswiezenia(): void
    {
        $product = $this->card();
        // Laravel trzyma instancję kontrolera na trasie przez cały test — jeden mock na wszystkie żądania:
        // 5 żądań, z czego model woła się tylko 3 razy (pierwsze, po zmianie karty, refresh).
        $llm = $this->mockLlm();
        $llm->shouldReceive('chatJson')->times(3)->andReturn(['conflicts' => []]);

        $url = '/api/products/'.$product->id.'/conflicts/ai';
        $first = $this->postJson($url)->assertOk()->assertJsonPath('cached', false);
        $this->postJson($url)
            ->assertOk()
            ->assertJsonPath('cached', true)
            ->assertJsonPath('checked_at', $first->json('checked_at'));

        // zmiana karty (updated_at) → nowy klucz cache
        DB::table('products')->where('id', $product->id)->update(['updated_at' => now()->addMinutes(5)]);
        $this->postJson($url)->assertOk()->assertJsonPath('cached', false);
        $this->postJson($url)->assertOk()->assertJsonPath('cached', true);

        // refresh omija cache i go nadpisuje
        $this->postJson($url, ['refresh' => true])->assertOk()->assertJsonPath('cached', false);
        $this->postJson($url)->assertOk()->assertJsonPath('cached', true);
    }

    public function test_karta_z_sama_nazwa_nie_wola_modelu(): void
    {
        $product = $this->product(['sku' => 'NAME-ONLY', 'name' => 'Rękawice robocze']);
        $llm = $this->mockLlm();
        $llm->shouldNotReceive('chatJson');

        $this->postJson('/api/products/'.$product->id.'/conflicts/ai')
            ->assertOk()
            ->assertJsonCount(0, 'conflicts')
            ->assertJsonPath('rejected', 0);
    }

    public function test_wymaga_uprawnienia_products_view(): void
    {
        $product = $this->card();
        $llm = $this->mockLlm();
        $llm->shouldNotReceive('chatJson');
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/products/'.$product->id.'/conflicts/ai')->assertForbidden();
    }

    public function test_zadanie_ma_wlasny_klucz_w_ustawieniach_ai(): void
    {
        $this->assertContains('card_conflicts', AiTask::keys());
        $this->assertSame('Sprzeczności w karcie', AiTask::CardConflicts->label());
    }

    private function mockLlm(): MockInterface
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        return $llm;
    }

    private function card(): Product
    {
        return $this->product([
            'sku' => 'KAT-1',
            'name' => 'Rękawice nitrylowe KAT-1',
            'norms' => 'EN 388:2016 4121X, EN ISO 374-1, kat. II',
            'enrichment_payload' => [
                'specs' => ['Kategoria: III', 'Materiał: nitryl'],
                'features' => ['Bezszwowa konstrukcja'],
                'materials' => ['nitryl'],
            ],
            'description' => '<p>Rękawica robocza do prac montażowych.</p><p>Konstrukcja cięta i szyta.</p>',
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $quotes
     * @return array<string, mixed>
     */
    private function conflict(string $parameter, array $quotes): array
    {
        return [
            'parameter' => $parameter,
            'explanation' => 'Pola karty sobie przeczą.',
            'quotes' => array_map(static fn (array $q): array => ['field' => $q[0], 'text' => $q[1]], $quotes),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function product(array $overrides): Product
    {
        return Product::query()->create(array_merge([
            'manufacturer' => 'Test',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ], $overrides));
    }
}
