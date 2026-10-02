<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tender;
use App\Models\TenderCondition;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\TenderDocumentAiAnalyzer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Lista kontrolna warunków: opiekun zaznacza, czy firma spełnia warunek; zapis mówi kto i kiedy.
 */
final class TenderConditionStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_owner_marks_condition_and_tender_shows_who_did_it(): void
    {
        [$user, $tender, $condition] = $this->tenderWithCondition('wycena');
        Sanctum::actingAs($user);

        $this->patchJson("/api/tenders/{$tender->id}/conditions/{$condition->id}", ['status' => 'spelniamy'])
            ->assertOk()
            ->assertJsonPath('status', 'spelniamy')
            ->assertJsonPath('status_user.name', $user->name);

        $condition->refresh();
        $this->assertSame('spelniamy', $condition->status);
        $this->assertSame($user->id, $condition->status_user_id);
        $this->assertNotNull($condition->status_at);

        $this->getJson("/api/tenders/{$tender->id}")
            ->assertOk()
            ->assertJsonPath('tender.conditions.0.status', 'spelniamy')
            ->assertJsonPath('tender.conditions.0.status_user.name', $user->name);
    }

    public function test_back_to_unchecked_clears_who_and_when(): void
    {
        [$user, $tender, $condition] = $this->tenderWithCondition('draft');
        $condition->update(['status' => 'nie_spelniamy', 'status_user_id' => $user->id, 'status_at' => now()]);
        Sanctum::actingAs($user);

        $this->patchJson("/api/tenders/{$tender->id}/conditions/{$condition->id}", ['status' => null])
            ->assertOk()
            ->assertJsonPath('status', null);

        $condition->refresh();
        $this->assertNull($condition->status);
        $this->assertNull($condition->status_user_id);
        $this->assertNull($condition->status_at);
    }

    public function test_unknown_status_is_rejected(): void
    {
        [$user, $tender, $condition] = $this->tenderWithCondition('wycena');
        Sanctum::actingAs($user);

        $this->patchJson("/api/tenders/{$tender->id}/conditions/{$condition->id}", ['status' => 'chyba'])
            ->assertStatus(422);

        $this->assertNull($condition->refresh()->status);
    }

    public function test_status_cannot_change_after_pricing_left_the_team(): void
    {
        [$user, $tender, $condition] = $this->tenderWithCondition('akceptacja_km');
        Sanctum::actingAs($user);

        $this->patchJson("/api/tenders/{$tender->id}/conditions/{$condition->id}", ['status' => 'spelniamy'])
            ->assertStatus(422);

        $this->assertNull($condition->refresh()->status);
    }

    public function test_schema_example_echoed_by_the_model_is_dropped(): void
    {
        // 02.10.2026 na produkcji: model przepisał przykład ze schematu jako jedyny „warunek” przetargu
        $this->mock(OpenAiCompatibleClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('chat')->once()->andReturn([
                'content' => json_encode([
                    'items' => [],
                    'conditions' => [
                        ['category' => 'termin|dostawa|gwarancja|certyfikat|it|inne', 'content' => 'treść warunku'],
                        ['category' => 'dostawa', 'content' => 'Dostawa w ciągu 5 dni roboczych od zamówienia'],
                        ['category' => 'termin|dostawa', 'content' => 'Gwarancja 12 miesięcy'],
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'model' => 'test',
            ]);
        });

        $result = app(TenderDocumentAiAnalyzer::class)->analyze('Treść specyfikacji', ['conditions']);

        $this->assertSame([
            ['category' => 'dostawa', 'content' => 'Dostawa w ciągu 5 dni roboczych od zamówienia'],
            // kategoria wklejona z listy wariantów nic nie mówi — warunek zostaje, kategoria nie
            ['category' => null, 'content' => 'Gwarancja 12 miesięcy'],
        ], $result['conditions']);
    }

    /**
     * @return array{0: User, 1: Tender, 2: TenderCondition}
     */
    private function tenderWithCondition(string $status): array
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $client = Client::query()->create(['name' => 'Zamawiający testowy']);
        $tender = Tender::query()->create([
            'number' => 'PRZ/TEST/1',
            'title' => 'Rękawice',
            'client_id' => $client->id,
            'owner_id' => $user->id,
            'status' => $status,
        ]);
        $condition = TenderCondition::query()->create([
            'tender_id' => $tender->id,
            'sort_order' => 1,
            'category' => 'dostawa',
            'content' => 'Dostawa w ciągu 5 dni roboczych',
            'source' => 'document',
        ]);

        return [$user, $tender, $condition];
    }
}
