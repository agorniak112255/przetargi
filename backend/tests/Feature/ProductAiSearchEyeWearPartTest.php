<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Zapytania #91 i #93 (06.10.2026): „Gogle Bolle Rush+ 2.0 XP RUSXMN10E - bezbarwne z paskiem”. Karta z tym
 * samym SKU nazywa się „Hybrydowe okulary ochronne” i nie ma słowa „gogle”, więc odpadała na dowodzie żargonu
 * („gogle” → karta musi mówić „gogle”). Przechodził tylko „Zestaw pianki i paska” — część zamienna, której opis
 * mówi o przekształceniu okularów w gogle — i dostawał 96% jako „marka i model z wymagania”.
 */
final class ProductAiSearchEyeWearPartTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Gogle Bolle Rush+ 2.0 XP RUSXMN10E - bezbarwne z paskiem';

    /** @var list<array<string, mixed>> */
    private array $lastRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_card_with_the_code_written_by_the_client_beats_the_spare_part(): void
    {
        [$glasses, $part] = $this->rushCards();
        $this->fakeModel([]);

        $skus = $this->search();

        $this->assertSame('RUSXMN10E', $skus[0] ?? null, 'karta z kodem z zapytania nie stoi na pierwszym miejscu');
        $this->assertNotContains('RUSXMN70E', $skus, 'część zamienna weszła pod zapytanie o gogle');
        $this->assertSame('Kod z zapytania klienta.', $this->lastRows[0]['ai_match_reason'] ?? null);
        $this->assertNotSame($glasses->id, $part->id);
    }

    /** Zapytania klientów idą przez searchMany (ClientInquiryService::matchProducts) — ten sam wynik. */
    public function test_inquiry_batch_search_gives_the_same_card(): void
    {
        $this->rushCards();
        $this->fakeModel([]);

        $rows = $this->app->make(ProductAiSearchService::class)->searchMany([self::QUERY], 5);
        $skus = array_values(array_column($rows[0]['products'] ?? [], 'sku'));

        $this->assertSame('RUSXMN10E', $skus[0] ?? null);
        $this->assertNotContains('RUSXMN70E', $skus);
    }

    /**
     * Bez słowa „gogle” nie ma rodziny ani reguły części — część odcina dopiero to, że inna cyfra w kodzie
     * („rusxmn70e” wobec „rusxmn10e”) nie jest literówką nazwy modelu.
     */
    public function test_other_digit_in_the_code_is_not_a_typo(): void
    {
        $this->rushCards();
        $this->fakeModel([]);

        $skus = $this->search('Bolle RUSXMN10E');

        $this->assertContains('RUSXMN10E', $skus);
        $this->assertNotContains('RUSXMN70E', $skus);
    }

    /**
     * Nazwa linii („Bolle COMBAT”) pasuje też do zestawu pianki i paska tej linii — dopasowanie nazwy modelu nie może
     * uratować części pod wymaganiem o gogle.
     */
    public function test_line_name_does_not_rescue_the_spare_part(): void
    {
        $this->make('COMBPSI', 'COMBAT – Gogle ochronne bezbarwne', 'TACTICAL › GOGGLES', 'Gogle ochronne COMBAT, bezbarwna szybka, EN 166.');
        $this->make('KITFSCOMB', 'COMBAT FOAM STRAP – Zestaw pianki i paska', 'TACTICAL › ACCESSORIES › Spare parts', 'Zestaw pianki i paska do gogli COMBAT — zamienne części, gogle trzymają się szczelnie.');
        $this->make('COMBFIXS', 'COMBAT STRAP – elastyczne mocowanie i rozciąganie', 'TACTICAL › ACCESSORIES › Spare parts', 'Elastyczny pasek zamienny, gogle COMBAT trzymają się na kasku.');
        $this->fakeModel([]);

        $skus = $this->search('Gogle Bolle COMBAT bezbarwne');

        $this->assertContains('COMBPSI', $skus);
        $this->assertNotContains('KITFSCOMB', $skus);
        $this->assertNotContains('COMBFIXS', $skus);
    }

    /** Bez nazwanego modelu decyduje ocena modelu — część z wysoką oceną i tak nie przechodzi bramki zgodności. */
    public function test_spare_part_rated_by_the_model_without_named_model_is_dropped(): void
    {
        [, $part] = $this->rushCards();
        $goggles = $this->make(
            'GOG-200',
            'Gogle ochronne pośrednio wentylowane, bezbarwne, z gumowym paskiem',
            'Ochrona oczu › Gogle',
            'Gogle ochronne z wentylacją pośrednią, bezbarwna szybka, elastyczny pasek, EN 166.',
        );
        $this->fakeModel([
            ['id' => $part->id, 'score' => 96, 'reason' => 'pasek do gogli'],
            ['id' => $goggles->id, 'score' => 90, 'reason' => 'gogle bezbarwne z paskiem'],
        ]);

        $skus = $this->search('Gogle ochronne bezbarwne z paskiem');

        $this->assertContains('GOG-200', $skus);
        $this->assertNotContains('RUSXMN70E', $skus);
    }

    public function test_spare_part_rated_by_the_model_is_still_dropped(): void
    {
        [$glasses, $part] = $this->rushCards();
        $this->fakeModel([
            ['id' => $part->id, 'score' => 96, 'reason' => 'Rush+ 2.0 XP z paskiem'],
            ['id' => $glasses->id, 'score' => 95, 'reason' => 'Rush+ 2.0 XP bezbarwne'],
        ]);

        $skus = $this->search();

        $this->assertContains('RUSXMN10E', $skus);
        $this->assertNotContains('RUSXMN70E', $skus);
    }

    public function test_client_asking_for_the_part_still_gets_it(): void
    {
        $this->rushCards();
        $this->fakeModel([]);

        $skus = $this->search('Zestaw pianki i paska do gogli Bolle Rush+ 2.0 XP RUSXMN70E');

        $this->assertSame('RUSXMN70E', $skus[0] ?? null);
    }

    /** @return array{0: Product, 1: Product} */
    private function rushCards(): array
    {
        $glasses = $this->make(
            'RUSXMN10E',
            'RUSH+ 2.0 XP - rozmiar M/L – Hybrydowe okulary ochronne bezbarwne',
            'INDUSTRIAL › GLASSES › RUSH+2.0',
            'Bezramkowe okulary ochronne RUSH+ 2.0 XP w rozmiarze M/L, bezbarwna soczewka z powłoką przeciwparującą, EN 166.',
        );
        $part = $this->make(
            'RUSXMN70E',
            'RUSH+ 2.0 XP – Zestaw pianki i paska',
            'INDUSTRIAL › ACCESSORIES › Spare parts',
            'Zestaw pianki i paska do okularów ochronnych Bolle Safety RUSH+ 2.0. Pianka uszczelnia przestrzeń między twarzą a ramką, co pozwala przekształcić okulary w gogle.',
        );
        $this->make(
            'RUSXMN20E',
            'RUSH+ 2.0 XP - rozmiar M/L – Hybrydowe okulary ochronne z przyciemnianą soczewką',
            'INDUSTRIAL › GLASSES › RUSH+2.0',
            'Okulary ochronne RUSH+ 2.0 XP z soczewką przyciemnianą, EN 166, EN 172.',
        );

        return [$glasses, $part];
    }

    /** @param  list<array{id: int, score: int, reason: string}>  $matches */
    private function fakeModel(array $matches): void
    {
        $payload = [
            'needed' => 'gogle ochronne Bolle Rush+ 2.0 XP bezbarwne',
            'search_phrases' => ['gogle', 'bolle', 'rush', 'rusxmn10e'],
            'matches' => $matches,
        ];
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn($payload);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(
            static fn (array $messages): array => array_fill(0, count($messages), $payload)
        );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    /** @return list<string> */
    private function search(string $query = self::QUERY): array
    {
        $response = $this->postJson('/api/products/ai-search', ['query' => $query, 'limit' => 5])->assertOk();
        $this->lastRows = $response->json('products') ?? [];

        return array_values(array_column($this->lastRows, 'sku'));
    }

    private function make(string $sku, string $name, string $category, string $description): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'Bolle',
            'category' => $category,
            'description' => $description,
            'catalog_price_net' => 40,
            'purchase_price' => 25,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }
}
