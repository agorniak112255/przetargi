<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\NetworkAccessCodeMail;
use App\Models\ActivityLog;
use App\Models\LocalNetwork;
use App\Models\NetworkAccessChallenge;
use App\Models\NetworkAccessGrant;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\NetworkAccessPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Dostęp spoza sieci lokalnej z kodem e-mailem (tryb local_code): logowanie z kodem, dostęp konta z adresu na dobę,
 * limity, odebranie.
 */
final class NetworkAccessCodeTest extends TestCase
{
    use RefreshDatabase;

    private const OFFICE = '91.189.223.20';

    private const HOME = '83.31.130.233';

    private const CAFE = '5.173.10.20';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        LocalNetwork::query()->create(['address' => self::OFFICE, 'label' => 'Biuro']);
        app(NetworkAccessPolicy::class)->forget();
        Mail::fake();
    }

    private function codeUser(): User
    {
        return User::factory()->withRole('handlowiec')->create(['network_access' => 'local_code', 'email' => 'jan.kowalski@supon.pl']);
    }

    private function fromIp(string $ip): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function loginFrom(string $ip, User $user): TestResponse
    {
        return $this->fromIp($ip)->postJson('/api/login', ['email' => $user->email, 'password' => 'password']);
    }

    private function send(string $ip, string $challenge): TestResponse
    {
        return $this->fromIp($ip)->postJson('/api/login/network-code/send', ['challenge' => $challenge]);
    }

    private function verify(string $ip, string $challenge, string $code): TestResponse
    {
        return $this->fromIp($ip)->postJson('/api/login/network-code/verify', ['challenge' => $challenge, 'code' => $code]);
    }

    private function meWithToken(string $ip, string $token): TestResponse
    {
        return $this->fromIp($ip)->withToken($token)->getJson('/api/me');
    }

    /** Kod z ostatniego maila do tego konta. */
    private function sentCode(User $user): string
    {
        $code = null;
        Mail::assertSent(NetworkAccessCodeMail::class, function (NetworkAccessCodeMail $mail) use ($user, &$code): bool {
            if ($mail->account->is($user)) {
                $code = $mail->code;
            }

            return true;
        });
        $this->assertIsString($code);

        return $code;
    }

    /** Logowanie z kodem od hasła do klucza; zwraca klucz. */
    private function fullCodeLogin(string $ip, User $user): string
    {
        $challenge = (string) $this->loginFrom($ip, $user)->assertStatus(403)->json('challenge');
        $this->send($ip, $challenge)->assertOk();

        return (string) $this->verify($ip, $challenge, $this->sentCode($user))->assertOk()->json('token');
    }

    public function test_code_account_gets_challenge_outside_and_normal_login_in_office(): void
    {
        $user = $this->codeUser();

        $this->loginFrom(self::OFFICE, $user)->assertOk()->assertJsonStructure(['token']);

        $res = $this->loginFrom(self::HOME, $user)
            ->assertStatus(403)
            ->assertJsonPath('reason', 'network')
            ->assertJsonPath('code_login', true)
            ->assertJsonPath('email_hint', 'j***@supon.pl')
            ->assertJsonMissingPath('token');
        $this->assertSame(40, strlen((string) $res->json('challenge')));
        // w bazie tylko skrót klucza
        $this->assertNull(NetworkAccessChallenge::query()->where('token_hash', $res->json('challenge'))->first());
        Mail::assertNothingSent();
    }

    public function test_local_only_account_gets_no_challenge(): void
    {
        $user = User::factory()->withRole('handlowiec')->create(['network_access' => 'local']);

        $this->loginFrom(self::HOME, $user)
            ->assertStatus(403)
            ->assertJsonPath('code_login', false)
            ->assertJsonMissingPath('challenge');
        $this->assertSame(0, NetworkAccessChallenge::query()->count());
    }

    public function test_wrong_password_never_starts_code_login(): void
    {
        $user = $this->codeUser();

        $this->fromIp(self::HOME)->postJson('/api/login', ['email' => $user->email, 'password' => 'zle-haslo'])
            ->assertStatus(422)
            ->assertJsonMissingPath('challenge');
        $this->assertSame(0, NetworkAccessChallenge::query()->count());
    }

    public function test_full_code_login_grants_this_address_for_a_day(): void
    {
        $user = $this->codeUser();

        $token = $this->fullCodeLogin(self::HOME, $user);

        $this->meWithToken(self::HOME, $token)->assertOk()->assertJsonPath('id', $user->id);
        $grant = NetworkAccessGrant::query()->where('user_id', $user->id)->sole();
        $this->assertSame(self::HOME, $grant->ip);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $grant->expires_at->timestamp, 5);

        // z innego miejsca ten sam klucz nie działa, a odmowa mówi, że można potwierdzić kodem
        $this->meWithToken(self::CAFE, $token)
            ->assertStatus(401)
            ->assertJsonPath('reason', 'network')
            ->assertJsonPath('code_login', true);

        // z tego samego adresu zwykłe logowanie hasłem już przechodzi
        $this->loginFrom(self::HOME, $user)->assertOk()->assertJsonStructure(['token']);

        // po dobie klucz przestaje działać
        $this->travel(24)->hours();
        $this->travel(1)->minutes();
        $this->meWithToken(self::HOME, $token)->assertStatus(401)->assertJsonPath('code_login', true);
    }

    public function test_grant_unlocks_older_token_of_the_same_account_but_not_other_accounts(): void
    {
        $user = $this->codeUser();
        $other = User::factory()->withRole('handlowiec')->create(['network_access' => 'local_code']);
        // klucz dodatku Thunderbirda założony w biurze
        $addonToken = (string) $this->loginFrom(self::OFFICE, $user)->assertOk()->json('token');
        $otherToken = (string) $this->loginFrom(self::OFFICE, $other)->assertOk()->json('token');
        $this->meWithToken(self::HOME, $addonToken)->assertStatus(401);

        $this->fullCodeLogin(self::HOME, $user);

        $this->meWithToken(self::HOME, $addonToken)->assertOk();
        $this->meWithToken(self::HOME, $otherToken)->assertStatus(401);
    }

    public function test_send_rules_resend_gap_and_hourly_limit(): void
    {
        $user = $this->codeUser();
        $challenge = (string) $this->loginFrom(self::HOME, $user)->json('challenge');

        $this->send(self::HOME, $challenge)
            ->assertOk()
            ->assertJsonPath('email_hint', 'j***@supon.pl')
            ->assertJsonPath('resend_after', 60)
            ->assertJsonPath('expires_in', 600);
        Mail::assertSent(NetworkAccessCodeMail::class, fn (NetworkAccessCodeMail $m): bool => $m->hasTo('jan.kowalski@supon.pl') && $m->ip === self::HOME);

        $this->send(self::HOME, $challenge)->assertStatus(429)->assertJsonStructure(['message', 'retry_after']);
        Mail::assertSentCount(1);

        // kolejne kody po minucie — najwyżej 5 maili na godzinę na konto (także przy nowych logowaniach)
        for ($i = 0; $i < 4; $i++) {
            $this->travel(61)->seconds();
            $this->send(self::HOME, $challenge)->assertOk();
        }
        $this->travel(61)->seconds();
        $this->send(self::HOME, $challenge)->assertStatus(429);
        $fresh = (string) $this->loginFrom(self::HOME, $user)->json('challenge');
        $this->send(self::HOME, $fresh)->assertStatus(429);
        Mail::assertSentCount(5);
    }

    public function test_new_code_replaces_previous_one(): void
    {
        $user = $this->codeUser();
        $challenge = (string) $this->loginFrom(self::HOME, $user)->json('challenge');
        $this->send(self::HOME, $challenge)->assertOk();
        $first = $this->sentCode($user);
        $this->travel(61)->seconds();
        Mail::fake();
        $this->send(self::HOME, $challenge)->assertOk();
        $second = $this->sentCode($user);

        if ($first !== $second) {
            $this->verify(self::HOME, $challenge, $first)->assertStatus(422)->assertJsonValidationErrors('code');
        }
        $this->verify(self::HOME, $challenge, $second)->assertOk();
    }

    public function test_failed_mail_does_not_consume_resend_gap(): void
    {
        $user = $this->codeUser();
        $challenge = (string) $this->loginFrom(self::HOME, $user)->json('challenge');
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP nie działa'));

        $this->send(self::HOME, $challenge)
            ->assertStatus(422)
            ->assertJsonMissingPath('reason');
        $this->assertNull(NetworkAccessChallenge::query()->sole()->last_sent_at);
    }

    public function test_verify_errors_wrong_code_attempt_limit_and_burned_challenge(): void
    {
        $user = $this->codeUser();
        $challenge = (string) $this->loginFrom(self::HOME, $user)->json('challenge');

        // przed wysyłką kodu
        $this->verify(self::HOME, $challenge, '123456')->assertStatus(422)->assertJsonValidationErrors('code');

        $this->send(self::HOME, $challenge)->assertOk();
        $good = $this->sentCode($user);
        $wrong = $good === '000000' ? '111111' : '000000';

        for ($i = 1; $i <= 4; $i++) {
            $this->verify(self::HOME, $challenge, $wrong)->assertStatus(422)->assertJsonValidationErrors('code');
        }
        $this->verify(self::HOME, $challenge, $wrong)->assertStatus(422)->assertJsonPath('reason', 'challenge_invalid');
        // po pięciu błędach nawet dobry kod nie przejdzie
        $this->verify(self::HOME, $challenge, $good)->assertStatus(422)->assertJsonPath('reason', 'challenge_invalid');
        $this->assertSame(0, NetworkAccessGrant::query()->count());
        $this->assertSame(5, NetworkAccessChallenge::query()->sole()->attempts);
    }

    public function test_code_expires_after_ten_minutes_and_challenge_after_fifteen(): void
    {
        $user = $this->codeUser();
        $challenge = (string) $this->loginFrom(self::HOME, $user)->json('challenge');
        $this->send(self::HOME, $challenge)->assertOk();
        $code = $this->sentCode($user);

        $this->travel(11)->minutes();
        $this->verify(self::HOME, $challenge, $code)->assertStatus(422)->assertJsonValidationErrors('code');

        $this->travel(5)->minutes();
        $this->send(self::HOME, $challenge)->assertStatus(422)->assertJsonPath('reason', 'challenge_invalid');
    }

    public function test_challenge_is_bound_to_address_and_single_use(): void
    {
        $user = $this->codeUser();
        $challenge = (string) $this->loginFrom(self::HOME, $user)->json('challenge');

        $this->send(self::CAFE, $challenge)->assertStatus(422)->assertJsonPath('reason', 'challenge_invalid');
        Mail::assertNothingSent();

        $this->send(self::HOME, $challenge)->assertOk();
        $code = $this->sentCode($user);
        $this->verify(self::CAFE, $challenge, $code)->assertStatus(422)->assertJsonPath('reason', 'challenge_invalid');

        $this->verify(self::HOME, $challenge, $code)->assertOk();
        $this->verify(self::HOME, $challenge, $code)->assertStatus(422)->assertJsonPath('reason', 'challenge_invalid');
        $this->send(self::HOME, 'nieistniejacy-klucz')->assertStatus(422)->assertJsonPath('reason', 'challenge_invalid');
    }

    public function test_mode_change_during_code_login_invalidates_challenge(): void
    {
        $user = $this->codeUser();
        $challenge = (string) $this->loginFrom(self::HOME, $user)->json('challenge');
        $this->send(self::HOME, $challenge)->assertOk();
        $code = $this->sentCode($user);

        $user->forceFill(['network_access' => 'local'])->save();

        $this->verify(self::HOME, $challenge, $code)->assertStatus(422)->assertJsonPath('reason', 'challenge_invalid');
        $this->assertSame(0, NetworkAccessGrant::query()->count());
    }

    public function test_failed_codes_are_limited_per_account_across_logins(): void
    {
        $user = $this->codeUser();
        $wrongs = 0;
        for ($round = 0; $round < 3; $round++) {
            $challenge = (string) $this->loginFrom(self::HOME, $user)->json('challenge');
            $this->travel(61)->seconds();
            $this->send(self::HOME, $challenge)->assertOk();
            $good = $this->sentCode($user);
            $wrong = $good === '000000' ? '111111' : '000000';
            for ($i = 0; $i < 4 && $wrongs < 10; $i++, $wrongs++) {
                $this->verify(self::HOME, $challenge, $wrong)->assertStatus(422);
            }
            if ($wrongs >= 10) {
                $this->verify(self::HOME, $challenge, $good)->assertStatus(429)->assertJsonStructure(['retry_after']);

                return;
            }
        }
        $this->fail('Limit błędnych kodów na konto nie zadziałał.');
    }

    public function test_ipv6_grant_covers_the_whole_64_network(): void
    {
        $user = $this->codeUser();
        // adres prywatności zmienia się między hasłem a kodem — ta sama sieć /64 wystarcza
        $challenge = (string) $this->loginFrom('2a02:a311:4142:2b00:1111:2222:3333:4444', $user)->assertStatus(403)->json('challenge');
        $this->send('2a02:a311:4142:2b00:aaaa::1', $challenge)->assertOk();
        $this->send('2a02:a311:4142:2b01::1', $challenge)->assertStatus(422)->assertJsonPath('reason', 'challenge_invalid');
        $token = (string) $this->verify('2a02:a311:4142:2b00:bbbb::2', $challenge, $this->sentCode($user))->assertOk()->json('token');

        $this->assertSame('2a02:a311:4142:2b00::/64', NetworkAccessGrant::query()->sole()->ip);
        $this->meWithToken('2a02:a311:4142:2b00:9999:8888:7777:6666', $token)->assertOk();
        $this->meWithToken('2a02:a311:4142:2b01::1', $token)->assertStatus(401);
    }

    public function test_strictest_group_wins_with_code_mode(): void
    {
        Role::query()->where('name', 'handlowiec')->update(['network_access' => 'local_code']);
        Role::query()->where('name', 'kierownik')->update(['network_access' => 'local']);
        $policy = app(NetworkAccessPolicy::class);

        $sales = User::factory()->withRole('handlowiec')->create();
        $this->assertSame('local_code', $policy->effective($sales)['mode']);

        $both = User::factory()->withRole('handlowiec')->create();
        $both->assignRole('kierownik');
        $this->assertSame(['mode' => 'local', 'source' => 'role', 'role' => 'kierownik'], $policy->effective($both->fresh()));

        $own = User::factory()->withRole('kierownik')->create(['network_access' => 'local_code']);
        $this->assertSame('local_code', $policy->effective($own)['mode']);
    }

    public function test_password_change_revokes_grants_except_current_address(): void
    {
        $user = $this->codeUser();
        $this->fullCodeLogin(self::CAFE, $user);
        $homeToken = $this->fullCodeLogin(self::HOME, $user);
        $pending = (string) $this->loginFrom('7.7.7.7', $user)->json('challenge');

        $this->fromIp(self::HOME)->withToken($homeToken)->postJson('/api/me/password', [
            'current_password' => 'password',
            'password' => 'nowe-haslo-123',
            'password_confirmation' => 'nowe-haslo-123',
        ])->assertOk();

        $policy = app(NetworkAccessPolicy::class);
        $this->assertTrue($policy->hasGrant($user, self::HOME));
        $this->assertFalse($policy->hasGrant($user, self::CAFE));
        $this->send('7.7.7.7', $pending)->assertStatus(422)->assertJsonPath('reason', 'challenge_invalid');
    }

    public function test_admin_password_reset_revokes_all_grants(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $user = $this->codeUser();
        $this->fullCodeLogin(self::HOME, $user);
        $adminToken = (string) $this->loginFrom(self::OFFICE, $admin)->json('token');

        $this->fromIp(self::OFFICE)->withToken($adminToken)
            ->patchJson('/api/admin/users/'.$user->id, ['password' => 'inne-haslo-123'])
            ->assertOk();

        $this->assertFalse(app(NetworkAccessPolicy::class)->hasGrant($user, self::HOME));
    }

    public function test_admin_lists_and_revokes_grants(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $user = $this->codeUser();
        $userToken = $this->fullCodeLogin(self::HOME, $user);
        $adminToken = (string) $this->loginFrom(self::OFFICE, $admin)->json('token');

        $list = $this->fromIp(self::OFFICE)->withToken($adminToken)->getJson('/api/admin/network-access-grants')
            ->assertOk()
            ->assertJsonCount(1, 'grants')
            ->assertJsonPath('grants.0.user.email', 'jan.kowalski@supon.pl')
            ->assertJsonPath('grants.0.ip', self::HOME);

        $this->fromIp(self::OFFICE)->withToken($adminToken)
            ->deleteJson('/api/admin/network-access-grants/'.$list->json('grants.0.id'))
            ->assertOk();

        $this->meWithToken(self::HOME, $userToken)->assertStatus(401);
        $this->fromIp(self::OFFICE)->withToken($adminToken)->getJson('/api/admin/network-access-grants')
            ->assertOk()
            ->assertJsonCount(0, 'grants');
        $this->assertDatabaseHas('activity_logs', ['action' => 'network_grant_revoked', 'user_id' => $admin->id]);
    }

    public function test_grants_admin_needs_roles_permission(): void
    {
        $sales = User::factory()->withRole('handlowiec')->create();
        $token = (string) $this->loginFrom(self::OFFICE, $sales)->json('token');

        $this->fromIp(self::OFFICE)->withToken($token)->getJson('/api/admin/network-access-grants')->assertStatus(403);
    }

    public function test_admin_outside_setting_own_group_to_code_mode_keeps_access(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $token = (string) $this->loginFrom(self::HOME, $admin)->assertOk()->json('token');

        $this->fromIp(self::HOME)->withToken($token)
            ->patchJson('/api/admin/roles/admin/network-access', ['network_access' => 'local_code'])
            ->assertOk()
            ->assertJsonPath('network_access', 'local_code');

        $this->assertTrue(app(NetworkAccessPolicy::class)->hasGrant($admin, self::HOME));
        $this->meWithToken(self::HOME, $token)->assertOk();
    }

    public function test_code_mode_with_empty_address_list_is_refused(): void
    {
        LocalNetwork::query()->delete();
        app(NetworkAccessPolicy::class)->forget();
        $admin = User::factory()->withRole('admin')->create();
        $token = (string) $this->loginFrom(self::HOME, $admin)->json('token');

        $this->fromIp(self::HOME)->withToken($token)
            ->patchJson('/api/admin/roles/handlowiec/network-access', ['network_access' => 'local_code'])
            ->assertStatus(422);
        $this->assertSame('any', Role::query()->where('name', 'handlowiec')->value('network_access'));
    }

    public function test_code_is_never_written_to_activity_log(): void
    {
        $user = $this->codeUser();
        $this->fullCodeLogin(self::HOME, $user);
        $code = $this->sentCode($user);

        $this->assertDatabaseHas('activity_logs', ['action' => 'network_code_sent']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'network_code_verified']);
        $logs = ActivityLog::query()->get()->map(fn ($row): string => (string) json_encode($row->meta))->implode("\n");
        $this->assertStringNotContainsString($code, $logs);
    }

    public function test_login_is_rate_limited_per_email_and_address(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        for ($i = 0; $i < 10; $i++) {
            $this->fromIp(self::HOME)->postJson('/api/login', ['email' => $user->email, 'password' => 'zle'])->assertStatus(422);
        }
        $this->fromIp(self::HOME)->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(429);
        // inny adres (np. biuro) nie jest blokowany próbami z domu
        $this->loginFrom(self::OFFICE, $user)->assertOk();
    }

    public function test_recovery_command_accepts_code_mode(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();

        $this->artisan('users:network-access', ['email' => $user->email, 'mode' => 'local_code', '--apply' => true])
            ->assertSuccessful();
        $this->assertSame('local_code', $user->fresh()->network_access);
    }

    public function test_prune_removes_old_challenges_and_long_expired_grants(): void
    {
        $user = $this->codeUser();
        $this->loginFrom(self::HOME, $user)->assertStatus(403);
        NetworkAccessGrant::query()->create(['user_id' => $user->id, 'ip' => self::CAFE, 'expires_at' => now()->subDays(91)]);
        NetworkAccessGrant::query()->create(['user_id' => $user->id, 'ip' => self::HOME, 'expires_at' => now()->subDays(5)]);

        $this->travel(8)->days();
        $this->artisan('system:prune')->assertSuccessful();

        $this->assertSame(0, NetworkAccessChallenge::query()->count());
        $this->assertSame([self::HOME], NetworkAccessGrant::query()->pluck('ip')->all());
    }
}
