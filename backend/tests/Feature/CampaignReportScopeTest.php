<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use App\Models\UserTeam;
use App\Services\Campaigns\CampaignReportScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Zakres raportu „Wynik kampanii” (CampaignReportScope) i zespoły w Administracji → Role (/api/admin/teams).
 */
final class CampaignReportScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_sees_all(): void
    {
        $admin = User::factory()->withRole('admin')->create();

        $this->assertSame(['mode' => 'all', 'user_ids' => null], $this->scope()->for($admin));
    }

    public function test_salesperson_with_campaigns_use_sees_own(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();

        $this->assertSame(['mode' => 'own', 'user_ids' => [$user->id]], $this->scope()->for($user));
    }

    public function test_user_without_campaigns_use_but_with_started_campaign_sees_own(): void
    {
        $user = User::factory()->create();
        Campaign::query()->create(['user_id' => $user->id, 'name' => 'Szkic', 'status' => Campaign::STATUS_DRAFT]);
        $this->assertNull($this->scope()->for($user), 'sam szkic nie daje dostępu');

        Campaign::query()->create([
            'user_id' => $user->id,
            'name' => 'Wysłana',
            'status' => Campaign::STATUS_SENT,
            'sending_started_at' => now()->subDays(3),
        ]);

        $this->assertSame(['mode' => 'own', 'user_ids' => [$user->id]], $this->scope()->for($user->fresh()));
    }

    public function test_campaigns_view_does_not_widen_scope(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('campaigns.view');

        $this->assertNull($this->scope()->for($viewer));

        $salesperson = User::factory()->withRole('handlowiec')->create();
        $salesperson->givePermissionTo('campaigns.view');

        $this->assertSame(['mode' => 'own', 'user_ids' => [$salesperson->id]], $this->scope()->for($salesperson->fresh()));
    }

    public function test_team_leader_sees_members_of_all_led_teams_and_self(): void
    {
        $leader = User::factory()->withRole('kierownik')->create();
        $a = User::factory()->withRole('handlowiec')->create();
        $b = User::factory()->withRole('handlowiec')->create();
        $c = User::factory()->withRole('handlowiec')->create();
        $outsider = User::factory()->withRole('handlowiec')->create();

        $north = UserTeam::query()->create(['name' => 'Północ']);
        $north->members()->sync([$leader->id => ['is_leader' => true], $b->id => ['is_leader' => false], $a->id => ['is_leader' => false]]);
        $south = UserTeam::query()->create(['name' => 'Południe']);
        $south->members()->sync([$leader->id => ['is_leader' => true], $a->id => ['is_leader' => false], $c->id => ['is_leader' => false]]);
        // zespół, w którym kierownik jest tylko członkiem — nie poszerza zakresu
        $other = UserTeam::query()->create(['name' => 'Inny']);
        $other->members()->sync([$outsider->id => ['is_leader' => true], $leader->id => ['is_leader' => false]]);

        $expected = [$leader->id, $a->id, $b->id, $c->id];
        sort($expected);
        $this->assertSame(['mode' => 'team', 'user_ids' => $expected], $this->scope()->for($leader));

        // zwykły członek zespołu widzi tylko siebie
        $this->assertSame(['mode' => 'own', 'user_ids' => [$a->id]], $this->scope()->for($a));

        $this->assertTrue($this->scope()->allows($leader, $c->id));
        $this->assertTrue($this->scope()->allows($leader, $leader->id));
        $this->assertFalse($this->scope()->allows($leader, $outsider->id));
        $this->assertFalse($this->scope()->allows($a, $b->id));
    }

    public function test_team_leader_without_campaigns_use_still_sees_team(): void
    {
        $leader = User::factory()->create();
        $member = User::factory()->withRole('handlowiec')->create();
        $team = UserTeam::query()->create(['name' => 'Zarząd sprzedaży']);
        $team->members()->sync([$leader->id => ['is_leader' => true], $member->id => ['is_leader' => false]]);

        $expected = [$leader->id, $member->id];
        sort($expected);
        $this->assertSame(['mode' => 'team', 'user_ids' => $expected], $this->scope()->for($leader));
    }

    public function test_all_scope_allows_only_existing_users_and_no_scope_allows_nobody(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $other = User::factory()->create();

        $this->assertTrue($this->scope()->allows($admin, $other->id));
        $this->assertFalse($this->scope()->allows($admin, $other->id + 1000));

        $nobody = User::factory()->create();
        $this->assertFalse($this->scope()->allows($nobody, $nobody->id));
    }

    public function test_me_contains_campaign_report_scope(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson('/api/me')->assertOk()->assertJsonPath('campaign_report_scope', 'own');

        Sanctum::actingAs(User::factory()->create());
        $response = $this->getJson('/api/me')->assertOk();
        $this->assertArrayHasKey('campaign_report_scope', $response->json());
        $this->assertNull($response->json('campaign_report_scope'));

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->getJson('/api/me')->assertOk()->assertJsonPath('campaign_report_scope', 'all');
    }

    public function test_admin_can_create_list_update_and_delete_team(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $zofia = User::factory()->withRole('kierownik')->create(['name' => 'Zofia Nowak']);
        $adam = User::factory()->withRole('handlowiec')->create(['name' => 'Adam Kowal']);
        $basia = User::factory()->withRole('handlowiec')->create(['name' => 'Barbara Wiśniewska']);

        $created = $this->postJson('/api/admin/teams', [
            'name' => 'Handel Kraków',
            'members' => [
                ['user_id' => $adam->id, 'is_leader' => false],
                ['user_id' => $zofia->id, 'is_leader' => true],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Handel Kraków')
            // kierownicy na początku listy
            ->assertJsonPath('data.members.0', ['user_id' => $zofia->id, 'name' => 'Zofia Nowak', 'is_leader' => true])
            ->assertJsonPath('data.members.1', ['user_id' => $adam->id, 'name' => 'Adam Kowal', 'is_leader' => false]);
        $teamId = (int) $created->json('data.id');

        $expected = [$zofia->id, $adam->id];
        sort($expected);
        $this->assertSame(['mode' => 'team', 'user_ids' => $expected], $this->scope()->for($zofia->fresh()));

        $this->getJson('/api/admin/teams')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $teamId)
            ->assertJsonPath('data.0.members.0.user_id', $zofia->id)
            ->assertJsonFragment(['id' => $basia->id, 'name' => 'Barbara Wiśniewska', 'role' => 'handlowiec']);

        // lista członków zastępowana w całości
        $this->putJson("/api/admin/teams/{$teamId}", [
            'name' => 'Handel Małopolska',
            'members' => [
                ['user_id' => $basia->id, 'is_leader' => true],
                ['user_id' => $zofia->id, 'is_leader' => false],
            ],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Handel Małopolska')
            ->assertJsonCount(2, 'data.members')
            ->assertJsonPath('data.members.0', ['user_id' => $basia->id, 'name' => 'Barbara Wiśniewska', 'is_leader' => true])
            ->assertJsonPath('data.members.1.is_leader', false);

        $this->assertDatabaseMissing('user_team_members', ['team_id' => $teamId, 'user_id' => $adam->id]);
        $this->assertSame('own', $this->scope()->for($zofia->fresh())['mode'] ?? null);

        // ta sama nazwa przy zapisie tego samego zespołu jest dozwolona
        $this->putJson("/api/admin/teams/{$teamId}", ['name' => 'Handel Małopolska', 'members' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.members');

        $this->deleteJson("/api/admin/teams/{$teamId}")->assertOk()->assertJsonStructure(['message']);
        $this->assertDatabaseMissing('user_teams', ['id' => $teamId]);
        $this->assertDatabaseCount('user_team_members', 0);
    }

    public function test_team_validation(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $user = User::factory()->create();
        UserTeam::query()->create(['name' => 'Istniejący']);

        $this->postJson('/api/admin/teams', ['name' => '   ', 'members' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/admin/teams', ['name' => str_repeat('a', 101), 'members' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/admin/teams', ['name' => 'Istniejący', 'members' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/admin/teams', ['name' => 'Nowy'])
            ->assertUnprocessable()->assertJsonValidationErrors('members');
        $this->postJson('/api/admin/teams', ['name' => 'Nowy', 'members' => [['user_id' => $user->id + 1000, 'is_leader' => false]]])
            ->assertUnprocessable()->assertJsonValidationErrors('members.0.user_id');
        $this->postJson('/api/admin/teams', ['name' => 'Nowy', 'members' => [
            ['user_id' => $user->id, 'is_leader' => false],
            ['user_id' => $user->id, 'is_leader' => true],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['members.0.user_id', 'members.1.user_id']);
        $this->postJson('/api/admin/teams', ['name' => 'Nowy', 'members' => [['user_id' => $user->id, 'is_leader' => 'może']]])
            ->assertUnprocessable()->assertJsonValidationErrors('members.0.is_leader');

        $this->assertDatabaseCount('user_teams', 1);
        $this->assertDatabaseCount('user_team_members', 0);
    }

    public function test_teams_require_admin_roles_manage(): void
    {
        $team = UserTeam::query()->create(['name' => 'Zespół']);
        // kierownik ma campaigns.use i reports.view, ale nie zarządza rolami
        Sanctum::actingAs(User::factory()->withRole('kierownik')->create());

        $this->getJson('/api/admin/teams')->assertForbidden();
        $this->postJson('/api/admin/teams', ['name' => 'X', 'members' => []])->assertForbidden();
        $this->putJson("/api/admin/teams/{$team->id}", ['name' => 'X', 'members' => []])->assertForbidden();
        $this->deleteJson("/api/admin/teams/{$team->id}")->assertForbidden();

        // sam admin.access bez admin.roles.manage też nie wystarcza
        $adminAccessOnly = User::factory()->create();
        $adminAccessOnly->givePermissionTo('admin.access');
        Sanctum::actingAs($adminAccessOnly);
        $this->getJson('/api/admin/teams')->assertForbidden();

        $this->assertDatabaseHas('user_teams', ['id' => $team->id, 'name' => 'Zespół']);
    }

    private function scope(): CampaignReportScope
    {
        return app(CampaignReportScope::class);
    }
}
