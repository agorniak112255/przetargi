<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RequirementUnderstanding;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Services\Search\RequirementUnderstandingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

/**
 * Pomiar 21.09.2026: to samo wymaganie rozumiane 5 razy dawało 5 różnych list warunków, a od nich zależy, które
 * karty trafią do oceny. Przetarg 1 poz. 15: z 54 różnych kart tylko 4 wspólne dla 5 przebiegów, karta właściwa
 * docierała do modelu w 3–4 przebiegach z 5; poz. 04 — w 1 z 5. „Model pomija właściwą kartę” znaczyło najczęściej,
 * że jej nie dostał. Wymaganie rozumiemy więc raz i zapisujemy.
 */
final class RequirementUnderstandingStoreTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIREMENT = 'Rękawice ochronne z lateksu naturalnego, flokowane, długość 300 mm, grubość 0,45 mm, AQL 1,5, do kontaktu z żywnością';

    public function test_same_requirement_is_understood_once_even_when_the_model_would_answer_differently(): void
    {
        $calls = $this->modelAnswering([
            ['needed' => 'rękawice lateksowe flokowane', 'search_phrases' => ['rękawice lateksowe'], 'constraints' => ['AQL 1,5', 'długość 300 mm']],
            ['needed' => 'rękawice gospodarcze', 'search_phrases' => ['rękawice gumowe'], 'constraints' => ['kontakt z żywnością']],
        ]);
        $service = app(ProductAiSearchService::class);

        $first = $service->understandRequirement(self::REQUIREMENT);
        $second = $service->understandRequirement("  rękawice OCHRONNE z lateksu naturalnego,   flokowane, długość 300 mm, grubość 0,45 mm, AQL 1,5, do kontaktu z żywnością\n");

        $this->assertSame(1, $calls->count(), 'drugi raz bez pytania modelu — także przy innych odstępach i wielkości liter');
        $this->assertSame($first['needed'], $second['needed']);
        $this->assertSame($first['constraints'], $second['constraints']);
        $this->assertSame(1, RequirementUnderstanding::query()->count());
        $this->assertSame(ProductAiSearchService::UNDERSTAND_PROMPT_VERSION, RequirementUnderstanding::query()->value('prompt_version'));
    }

    public function test_whole_tender_batch_reuses_what_single_search_understood(): void
    {
        $this->card();
        app(RequirementUnderstandingStore::class)->put(
            self::REQUIREMENT,
            ProductAiSearchService::UNDERSTAND_PROMPT_VERSION,
            ['needed' => 'rękawice lateksowe flokowane', 'search_phrases' => ['rękawice lateksowe flokowane'], 'constraints' => ['AQL 1,5']],
        );
        $kinds = new \ArrayObject;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static function (array $sets) use ($kinds): array {
            foreach ($sets as $messages) {
                $kinds->append(FakeSearchLlm::kind($messages));
            }

            return array_fill(0, count($sets), []);
        });
        $llm->shouldReceive('chatJson')->andReturn([]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        app(ProductAiSearchService::class)->searchMany([self::REQUIREMENT], 5, false, AiTask::ProductSearch);

        $this->assertNotContains(FakeSearchLlm::KIND_UNDERSTAND, $kinds->getArrayCopy(), 'zapisane zrozumienie — fala nie pyta o nie modelu');
    }

    public function test_empty_answer_and_model_failure_are_never_stored(): void
    {
        $this->modelAnswering([[], new RuntimeException('limit zapytań')]);
        $service = app(ProductAiSearchService::class);

        $service->understandRequirement(self::REQUIREMENT);
        $service->understandRequirement(self::REQUIREMENT.' — wersja druga');

        $this->assertSame(0, RequirementUnderstanding::query()->count(), 'awaria i pusta odpowiedź nie zostają „zrozumieniem” na stałe');
    }

    public function test_new_prompt_version_does_not_read_the_old_understanding(): void
    {
        $store = app(RequirementUnderstandingStore::class);
        $store->put(self::REQUIREMENT, 'understand-stara', ['needed' => 'rękawice', 'constraints' => []]);

        $this->assertNull($store->get(self::REQUIREMENT, ProductAiSearchService::UNDERSTAND_PROMPT_VERSION));
        $this->assertSame('rękawice', $store->get(self::REQUIREMENT, 'understand-stara')['needed'] ?? null);
    }

    /**
     * Model, który na kolejne pytania o zrozumienie odpowiada kolejnymi pozycjami listy (wyjątek = awaria).
     *
     * @param  list<array<string, mixed>|\Throwable>  $answers
     */
    private function modelAnswering(array $answers): \ArrayObject
    {
        $calls = new \ArrayObject;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static function () use (&$answers, $calls): array {
            $calls->append(1);
            $next = array_shift($answers) ?? [];
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        return $calls;
    }

    private function card(): Product
    {
        return Product::query()->create([
            'sku' => '87320100-PAIR',
            'name' => 'AlphaTec 87320 rękawice lateksowe flokowane',
            'manufacturer' => 'Ansell',
            'description' => 'Rękawice z lateksu naturalnego, flokowane, długość 300 mm, AQL 1,5, do kontaktu z żywnością.',
            'catalog_price_net' => 10,
            'purchase_price' => 6,
            'stock' => 1,
            'ppe_family' => 'gloves',
        ]);
    }
}
