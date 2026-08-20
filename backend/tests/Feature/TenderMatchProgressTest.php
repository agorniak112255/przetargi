<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\ProductMatchService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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
}
