<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LocalNetwork;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\NetworkAccessPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dostęp z sieci: konto albo grupa „tylko z sieci lokalnej”, konto ma pierwszeństwo przed grupą.
 */
final class NetworkAccessTest extends TestCase
{
    use RefreshDatabase;

    private const OFFICE = '91.189.223.20';

    private const HOME = '83.31.130.233';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function office(): void
    {
        LocalNetwork::query()->create(['address' => self::OFFICE, 'label' => 'Biuro']);
        app(NetworkAccessPolicy::class)->forget();
    }

    private function loginFrom(string $ip, User $user): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'password']);
    }

    private function meWithToken(string $ip, string $token): TestResponse
    {
        // strażnik Sanctum pamięta użytkownika między żądaniami w jednym teście
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withToken($token)
            ->getJson('/api/me');
    }

    private function setRoleAccess(string $role, string $mode): void
    {
        Role::query()->where('name', $role)->update(['network_access' => $mode]);
    }

    public function test_default_account_logs_in_from_anywhere(): void
    {
        $this->office();
        $user = User::factory()->withRole('handlowiec')->create();

        $this->loginFrom(self::HOME, $user)->assertOk()->assertJsonStructure(['token']);
        $this->assertSame(
            ['mode' => 'any', 'source' => 'role', 'role' => 'handlowiec'],
            app(NetworkAccessPolicy::class)->effective($user->fresh()),
        );
    }

    public function test_local_only_account_is_refused_outside_and_gets_no_token(): void
    {
        $this->office();
        $user = User::factory()->withRole('handlowiec')->create(['network_access' => 'local']);

        $this->loginFrom(self::HOME, $user)
            ->assertStatus(403)
            ->assertJsonPath('reason', 'network')
            ->assertJsonPath('message', NetworkAccessPolicy::DENIED_MESSAGE);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'action' => 'login_blocked_network']);

        $this->loginFrom(self::OFFICE, $user)->assertOk();
    }

    public function test_wrong_password_from_outside_does_not_reveal_network_rule(): void
    {
        $this->office();
        $user = User::factory()->withRole('handlowiec')->create(['network_access' => 'local']);

        $this->withServerVariables(['REMOTE_ADDR' => self::HOME])
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'zle-haslo'])
            ->assertStatus(422)
            ->assertJsonMissingPath('reason');
    }

    public function test_group_setting_applies_and_account_setting_wins(): void
    {
        $this->office();
        $this->setRoleAccess('handlowiec', 'local');
        $inherits = User::factory()->withRole('handlowiec')->create();
        $anywhere = User::factory()->withRole('handlowiec')->create(['network_access' => 'any']);

        $this->loginFrom(self::HOME, $inherits)->assertStatus(403);
        $this->loginFrom(self::HOME, $anywhere)->assertOk();

        $this->setRoleAccess('handlowiec', 'any');
        $localOwn = User::factory()->withRole('handlowiec')->create(['network_access' => 'local']);
        $this->loginFrom(self::HOME, $localOwn)->assertStatus(403);
    }

    public function test_any_strict_group_restricts_account_with_several_groups(): void
    {
        $this->office();
        $this->setRoleAccess('admin', 'local');
        $user = User::factory()->withRole('handlowiec')->create();
        $user->assignRole('admin');

        $effective = app(NetworkAccessPolicy::class)->effective($user->fresh());
        $this->assertSame(['mode' => 'local', 'source' => 'role', 'role' => 'admin'], $effective);
        $this->loginFrom(self::HOME, $user)->assertStatus(403);
    }

    public function test_existing_token_stops_working_outside_once_account_is_restricted(): void
    {
        $this->office();
        $user = User::factory()->withRole('handlowiec')->create();
        $token = $user->createToken('spa')->plainTextToken;

        $this->meWithToken(self::HOME, $token)->assertOk();

        $user->forceFill(['network_access' => 'local'])->save();

        $this->meWithToken(self::HOME, $token)
            ->assertStatus(401)
            ->assertJsonPath('reason', 'network');
        $this->meWithToken(self::OFFICE, $token)->assertOk();
    }

    public function test_plain_401_without_token_has_no_network_reason(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::HOME])
            ->getJson('/api/me')
            ->assertStatus(401)
            ->assertJsonMissingPath('reason');
    }

    public function test_broadcasting_auth_is_blocked_outside(): void
    {
        $this->office();
        $user = User::factory()->withRole('handlowiec')->create(['network_access' => 'local']);
        $token = $user->createToken('spa')->plainTextToken;

        $this->withServerVariables(['REMOTE_ADDR' => self::HOME])
            ->withToken($token)
            ->postJson('/api/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-chat.user.'.$user->id])
            ->assertStatus(401);
    }

    public function test_kill_switch_lets_everyone_in(): void
    {
        $this->office();
        config(['auth.network_access_enforce' => false]);
        $user = User::factory()->withRole('handlowiec')->create(['network_access' => 'local']);

        $this->loginFrom(self::HOME, $user)->assertOk();
    }

    public function test_ranges_ipv6_and_mapped_ipv4_match(): void
    {
        LocalNetwork::query()->create(['address' => '87.204.165.0/24']);
        LocalNetwork::query()->create(['address' => '2a01:110:8012:1010::/64']);
        $policy = app(NetworkAccessPolicy::class);
        $policy->forget();

        $this->assertTrue($policy->isLocal('87.204.165.178'));
        $this->assertTrue($policy->isLocal('::ffff:87.204.165.178'));
        $this->assertFalse($policy->isLocal('87.204.166.1'));
        $this->assertTrue($policy->isLocal('2a01:110:8012:1010::abcd'));
        $this->assertFalse($policy->isLocal('2a01:110:8012:1011::1'));
        $this->assertFalse($policy->isLocal(null));
        $this->assertFalse($policy->isLocal('nie-adres'));
    }

    public function test_address_normalization_rejects_garbage_and_whole_internet(): void
    {
        $this->assertSame('91.189.223.20', NetworkAccessPolicy::normalizeAddress(' 91.189.223.20 '));
        $this->assertSame('91.189.223.0/24', NetworkAccessPolicy::normalizeAddress('91.189.223.0/24'));
        $this->assertSame('2a01:110::/32', NetworkAccessPolicy::normalizeAddress('2A01:0110:0000::/32'));
        $this->assertSame('1.2.3.4', NetworkAccessPolicy::normalizeAddress('::ffff:1.2.3.4'));
        $this->assertNull(NetworkAccessPolicy::normalizeAddress('0.0.0.0/0'));
        $this->assertNull(NetworkAccessPolicy::normalizeAddress('::/0'));
        $this->assertNull(NetworkAccessPolicy::normalizeAddress('1.2.3.4/33'));
        $this->assertNull(NetworkAccessPolicy::normalizeAddress('1.2.3.4/abc'));
        $this->assertNull(NetworkAccessPolicy::normalizeAddress('1.2.3.4/24/8'));
        $this->assertNull(NetworkAccessPolicy::normalizeAddress('biuro'));
        $this->assertNull(NetworkAccessPolicy::normalizeAddress(''));
    }

    public function test_admin_saves_address_list_and_new_list_takes_effect_at_once(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($admin);
        $user = User::factory()->withRole('handlowiec')->create();
        $token = $user->createToken('spa')->plainTextToken;

        $this->putJson('/api/admin/local-networks', ['networks' => [
            ['address' => ' 91.189.223.20 ', 'label' => 'Biuro Rzeszów'],
            ['address' => '87.204.165.0/24', 'label' => ''],
        ]])->assertOk()
            ->assertJsonPath('networks.0.address', '91.189.223.20')
            ->assertJsonPath('networks.1.label', null)
            ->assertJsonPath('your_ip', '127.0.0.1')
            ->assertJsonPath('your_ip_is_local', false);

        $user->forceFill(['network_access' => 'local'])->save();
        $this->meWithToken('87.204.165.178', $token)->assertOk();

        // adres usunięty z listy przestaje wpuszczać od następnego żądania (pamięć podręczna wyczyszczona)
        Sanctum::actingAs($admin);
        $this->putJson('/api/admin/local-networks', ['networks' => [['address' => self::OFFICE]]])->assertOk();
        $this->meWithToken('87.204.165.178', $token)->assertStatus(401);
    }

    public function test_address_list_validation(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson('/api/admin/local-networks', ['networks' => [['address' => 'biuro']]])
            ->assertStatus(422)->assertJsonValidationErrors('networks.0.address');
        $this->putJson('/api/admin/local-networks', ['networks' => [['address' => '0.0.0.0/0']]])
            ->assertStatus(422)->assertJsonValidationErrors('networks.0.address');
        $this->putJson('/api/admin/local-networks', ['networks' => [['address' => '1.2.3.4'], ['address' => '1.2.3.4 ']]])
            ->assertStatus(422)->assertJsonValidationErrors('networks.1.address');
        $this->assertSame(0, LocalNetwork::query()->count());
    }

    public function test_list_cannot_be_emptied_while_someone_is_local_only(): void
    {
        $this->office();
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        User::factory()->withRole('handlowiec')->create(['network_access' => 'local']);

        $this->putJson('/api/admin/local-networks', ['networks' => []])
            ->assertStatus(422)->assertJsonValidationErrors('networks');
        $this->assertSame(1, LocalNetwork::query()->count());
    }

    public function test_admin_cannot_remove_own_address_when_restricted(): void
    {
        LocalNetwork::query()->create(['address' => '127.0.0.1']);
        $admin = User::factory()->withRole('admin')->create(['network_access' => 'local']);
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/local-networks', ['networks' => [['address' => self::OFFICE]]])
            ->assertStatus(422)->assertJsonValidationErrors('networks');
        $this->assertSame(['127.0.0.1'], LocalNetwork::query()->pluck('address')->all());
        // cofnięta transakcja nie zostawia w pamięci podręcznej listy, której nie ma w bazie
        $this->assertSame(['127.0.0.1'], app(NetworkAccessPolicy::class)->addresses());
    }

    public function test_admin_sets_account_access_and_sees_effective_source(): void
    {
        $this->office();
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->setRoleAccess('handlowiec', 'local');
        $user = User::factory()->withRole('handlowiec')->create();

        $this->getJson('/api/admin/users')->assertOk();
        $row = collect($this->getJson('/api/admin/users')->json())->firstWhere('id', $user->id);
        $this->assertNull($row['network_access']);
        $this->assertSame(['mode' => 'local', 'source' => 'role', 'role' => 'handlowiec'], $row['network_access_effective']);

        $this->patchJson("/api/admin/users/{$user->id}", ['network_access' => 'any'])
            ->assertOk()
            ->assertJsonPath('network_access', 'any')
            ->assertJsonPath('network_access_effective.source', 'user');

        $this->patchJson("/api/admin/users/{$user->id}", ['network_access' => null])
            ->assertOk()
            ->assertJsonPath('network_access', null)
            ->assertJsonPath('network_access_effective.mode', 'local');

        $this->patchJson("/api/admin/users/{$user->id}", ['network_access' => 'wszędzie'])
            ->assertStatus(422)->assertJsonValidationErrors('network_access');
    }

    public function test_admin_cannot_restrict_own_account_from_outside_address(): void
    {
        $this->office();
        $admin = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$admin->id}", ['network_access' => 'local'])
            ->assertStatus(422)->assertJsonValidationErrors('network_access');
        $this->assertNull($admin->fresh()->network_access);
    }

    public function test_admin_cannot_move_self_into_local_only_group_from_outside(): void
    {
        $this->office();
        $this->setRoleAccess('handlowiec', 'local');
        $admin = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$admin->id}", ['role' => 'handlowiec'])
            ->assertStatus(422)->assertJsonValidationErrors('network_access');
        $this->assertSame(['admin'], $admin->fresh()->getRoleNames()->all());
    }

    public function test_account_cannot_be_set_local_with_empty_list(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $user = User::factory()->withRole('handlowiec')->create();

        $this->patchJson("/api/admin/users/{$user->id}", ['network_access' => 'local'])
            ->assertStatus(422)->assertJsonValidationErrors('network_access');
        $this->assertNull($user->fresh()->network_access);
    }

    public function test_group_access_is_set_on_its_own_endpoint_with_lockout_guard(): void
    {
        $this->office();
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->patchJson('/api/admin/roles/handlowiec/network-access', ['network_access' => 'local'])
            ->assertOk()
            ->assertJsonPath('network_access', 'local');
        $this->assertSame('local', Role::query()->where('name', 'handlowiec')->value('network_access'));

        $this->getJson('/api/admin/roles')->assertOk()->assertJsonFragment(['name' => 'handlowiec', 'network_access' => 'local']);

        $this->patchJson('/api/admin/roles/admin/network-access', ['network_access' => 'local'])
            ->assertStatus(422)->assertJsonValidationErrors('network_access');
        $this->assertSame('any', Role::query()->where('name', 'admin')->value('network_access'));

        $this->patchJson('/api/admin/roles/nie-ma/network-access', ['network_access' => 'any'])->assertNotFound();
    }

    public function test_recovery_command_sets_account_to_any(): void
    {
        $this->office();
        $user = User::factory()->withRole('admin')->create(['email' => 'szef@example.com', 'network_access' => 'local']);

        $this->artisan('users:network-access', ['email' => 'szef@example.com', 'mode' => 'any'])->assertSuccessful();
        $this->assertSame('local', $user->fresh()->network_access);

        $this->artisan('users:network-access', ['email' => 'szef@example.com', 'mode' => 'any', '--apply' => true])->assertSuccessful();
        $this->assertSame('any', $user->fresh()->network_access);

        $this->artisan('users:network-access', ['email' => 'szef@example.com', 'mode' => 'group', '--apply' => true])->assertSuccessful();
        $this->assertNull($user->fresh()->network_access);

        $this->artisan('users:network-access', ['email' => 'nikt@example.com'])->assertFailed();
    }
}
