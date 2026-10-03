<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProcurementNotice;
use App\Models\ProcurementNoticeSkip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST/DELETE /api/notices/{notice}/skip — decyzja „pominięte” wspólna dla zespołu (kto i kiedy), przywracanie,
 * uprawnienia, tylko ogłoszenia o zamówieniu.
 */
final class NoticeSkipApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00:00', 'Europe/Warsaw'));
    }

    public function test_skip_is_shared_by_the_team_and_can_be_restored(): void
    {
        $notice = $this->notice();
        $anna = User::factory()->withRole('handlowiec')->create(['name' => 'Anna']);
        $piotr = User::factory()->withRole('handlowiec')->create(['name' => 'Piotr']);

        Sanctum::actingAs($anna);
        $this->postJson("/api/notices/{$notice->id}/skip")
            ->assertOk()
            ->assertJsonPath('id', $notice->id)
            ->assertJsonPath('skipped.by', ['id' => $anna->id, 'name' => 'Anna'])
            ->assertJsonPath('skipped.at', '2026-10-03T08:00:00.000000Z')
            ->assertJsonPath('tender', null);

        // druga osoba widzi decyzję; ponowne „Pomiń” nie zmienia autora
        Sanctum::actingAs($piotr);
        $this->travel(5)->minutes();
        $this->postJson("/api/notices/{$notice->id}/skip")
            ->assertOk()
            ->assertJsonPath('skipped.by.name', 'Anna')
            ->assertJsonPath('skipped.at', '2026-10-03T08:00:00.000000Z');
        $this->assertSame(1, ProcurementNoticeSkip::query()->count());
        $this->assertSame(['new' => 0, 'created' => 0, 'skipped' => 1], $this->getJson('/api/notices')->json('counts'));

        $this->deleteJson("/api/notices/{$notice->id}/skip")
            ->assertOk()
            ->assertJsonPath('skipped', null);
        $this->assertSame(0, ProcurementNoticeSkip::query()->count());
        $this->assertSame([$notice->id], array_column($this->getJson('/api/notices')->json('data'), 'id'));

        // przywrócenie bez decyzji — bez błędu
        $this->deleteJson("/api/notices/{$notice->id}/skip")->assertOk();
    }

    /**
     * Lista pokazuje najnowszą wersję ogłoszenia postępowania — decyzja „pominięte” musi dotyczyć postępowania,
     * inaczej po nowej wersji (…/02) postępowanie wracało do „Nowe”.
     */
    public function test_skip_covers_new_versions_of_the_procedure(): void
    {
        $first = $this->notice();
        $anna = User::factory()->withRole('handlowiec')->create(['name' => 'Anna']);
        Sanctum::actingAs($anna);
        $this->postJson("/api/notices/{$first->id}/skip")->assertOk();

        // nocne pobranie dopisuje nową wersję ogłoszenia tego samego postępowania
        $second = $this->notice(['notice_number' => '2026/BZP 00400001/02', 'submitting_offers_at' => '2026-10-20 08:00:00']);

        $list = $this->getJson('/api/notices')->assertOk();
        $this->assertSame([], $list->json('data'));
        $this->assertSame(['new' => 0, 'created' => 0, 'skipped' => 1], $list->json('counts'));
        $this->getJson('/api/notices?tab=skipped')
            ->assertOk()
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.0.skipped.by.name', 'Anna');

        // ponowne „Pomiń” na nowej wersji nie dubluje decyzji
        $this->postJson("/api/notices/{$second->id}/skip")->assertOk()->assertJsonPath('skipped.by.name', 'Anna');
        $this->assertSame(1, ProcurementNoticeSkip::query()->count());

        // „Przywróć” z nowej wersji usuwa decyzję postępowania (podjętą przy starszej wersji)
        $this->deleteJson("/api/notices/{$second->id}/skip")->assertOk()->assertJsonPath('skipped', null);
        $this->assertSame(0, ProcurementNoticeSkip::query()->count());
        $this->assertSame([$second->id], array_column($this->getJson('/api/notices')->json('data'), 'id'));
    }

    public function test_deleted_account_leaves_skip_without_author(): void
    {
        $notice = $this->notice();
        $user = User::factory()->withRole('handlowiec')->create();
        ProcurementNoticeSkip::query()->create(['bzp_number' => $notice->bzp_number, 'procurement_notice_id' => $notice->id, 'user_id' => $user->id]);
        $user->delete();

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson('/api/notices?tab=skipped')
            ->assertOk()
            ->assertJsonPath('data.0.skipped.by', null)
            ->assertJsonPath('data.0.skipped.at', '2026-10-03T08:00:00.000000Z');
    }

    public function test_permissions_and_result_notices(): void
    {
        $notice = $this->notice();
        $result = $this->notice(['notice_type' => ProcurementNotice::TYPE_RESULT, 'notice_number' => '2026/BZP 00400002/01', 'bzp_number' => '2026/BZP 00400002']);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/notices/{$notice->id}/skip")->assertForbidden();
        $this->deleteJson("/api/notices/{$notice->id}/skip")->assertForbidden();

        // dyrektor (tenders.view_all bez tenders.create) też może pominąć
        Sanctum::actingAs(User::factory()->withRole('dyrektor')->create());
        $this->postJson("/api/notices/{$notice->id}/skip")->assertOk();
        $this->postJson("/api/notices/{$result->id}/skip")->assertNotFound();
        $this->postJson('/api/notices/999999/skip')->assertNotFound();
        $this->assertSame(1, ProcurementNoticeSkip::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function notice(array $overrides = []): ProcurementNotice
    {
        return ProcurementNotice::query()->create(array_merge([
            'source' => 'bzp',
            'notice_type' => ProcurementNotice::TYPE_CONTRACT,
            'notice_number' => '2026/BZP 00400001/01',
            'bzp_number' => '2026/BZP 00400001',
            'published_at' => '2026-10-01 08:00:00',
            'submitting_offers_at' => '2026-10-13 08:00:00',
            'order_object' => 'Rękawice',
            'cpv_codes' => [['code' => '18141000-9', 'name' => 'Rękawice robocze']],
            'organization_name' => 'Szpital',
            'parsed' => ['has_lots' => false, 'lots' => []],
            'parser_version' => 1,
            'fetched_at' => '2026-10-03 04:30:00',
        ], $overrides));
    }
}
