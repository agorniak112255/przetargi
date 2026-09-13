<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Client;
use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Services\ProductMatchService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

final class TenderMatchProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_match_progress_starts_idle_and_finishes_done(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $tender = Tender::query()->create([
            'number' => 'PRZ/PROG/1',
            'title' => 'Postęp dopasowania',
            'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'KAMIZELKA ODBLASKOWA żółta SIATKOWA EN 20471',
            'quantity' => 1,
            'status' => 'brak',
        ]);
        TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 2,
            'requirement' => 'Rękawice nitrylowe ze ściągaczem',
            'quantity' => 1,
            'status' => 'brak',
        ]);

        $this->getJson("/api/tenders/{$tender->id}/match/progress")
            ->assertOk()
            ->assertJsonPath('status', 'idle')
            ->assertJsonPath('done', 0)
            ->assertJsonPath('total', 0);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => true])
            ->assertOk()
            ->assertJsonPath('processed', 2);

        $this->getJson("/api/tenders/{$tender->id}/match/progress")
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('done', 2)
            ->assertJsonPath('total', 2);

        $cached = ProductMatchService::readMatchProgress((int) $tender->id);
        $this->assertSame('done', $cached['status']);
        $this->assertSame(2, $cached['done']);
    }

    /**
     * Przetarg 1: okno stało minutami na „0 / 15”, a potem skakało do „15 / 15” — ranking całej
     * paczki w modelu nie zostawiał śladu w postępie. W czasie oceny modelu postęp ma pokazywać
     * etap „rank” z liczbą pozycji i liczyć odpowiedzi po podpaczkach.
     */
    public function test_progress_shows_model_ranking_stage_while_model_works(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        Http::fake();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);
        Product::query()->create([
            'sku' => 'RNITZ-M',
            'name' => 'Rękawice nitrylowe ze ściągaczem',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze nitrylowe ze ściągaczem, dzianina bawełniana, do prac montażowych.',
            'catalog_price_net' => 3,
            'purchase_price' => 2,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $tender = Tender::query()->create([
            'number' => 'PRZ/PROG/2',
            'title' => 'Etapy postępu',
            'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Rękawice robocze nitrylowe ze ściągaczem, dzianina bawełniana, do prac montażowych',
            'quantity' => 10,
            'status' => 'brak',
        ]);

        $tenderId = (int) $tender->id;
        $seen = [];
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static function (array $messageSets, ...$rest) use ($tenderId, &$seen): array {
            $isRank = $messageSets !== [] && FakeSearchLlm::kind($messageSets[0]) === FakeSearchLlm::KIND_RANK;
            if ($isRank) {
                $seen['before'] = ProductMatchService::readMatchProgress($tenderId);
                $onChunkDone = $rest[3] ?? null;
                if (is_callable($onChunkDone)) {
                    $onChunkDone(count($messageSets), count($messageSets));
                    $seen['after_chunk'] = ProductMatchService::readMatchProgress($tenderId);
                }
            }

            return array_map(static fn (): array => $isRank ? ['matches' => []] : [], $messageSets);
        });
        $llm->shouldReceive('chatJson')->andThrow(new RuntimeException('model niedostępny'));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson("/api/tenders/{$tenderId}/match", ['only_empty' => true])->assertOk();

        $this->assertArrayHasKey('before', $seen, 'model musi dostać ranking pozycji opisowej');
        $this->assertSame('running', $seen['before']['status']);
        $this->assertSame(ProductAiSearchService::PROGRESS_STAGE_RANK, $seen['before']['stage']);
        $this->assertSame(0, $seen['before']['done'], 'pozycje nie są jeszcze zapisane');
        $this->assertSame(1, $seen['before']['stage_total']);
        $this->assertSame(0, $seen['before']['stage_done']);
        $this->assertArrayHasKey('after_chunk', $seen, 'wyszukiwarka przekazuje licznik podpaczek do klienta modelu');
        $this->assertSame(1, $seen['after_chunk']['stage_done']);

        $final = ProductMatchService::readMatchProgress($tenderId);
        $this->assertSame('done', $final['status']);
        $this->assertSame(1, $final['done']);
    }
}
