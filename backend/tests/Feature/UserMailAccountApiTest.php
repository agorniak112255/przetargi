<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\SmtpHostGuard;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\RecordingCampaignSender;
use Tests\TestCase;

/** „Moja poczta”: skrzynka SMTP użytkownika — hasło nigdy w odpowiedzi, puste hasło = bez zmiany. */
final class UserMailAccountApiTest extends TestCase
{
    use RefreshDatabase;

    private RecordingCampaignSender $sender;

    /** @var array<string, list<string>> nazwa serwera → adresy IP (DNS w testach bez sieci) */
    private array $dns = ['smtp.supon.pl' => ['212.77.98.9'], 'smtp2.supon.pl' => ['212.77.98.10']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->sender = new RecordingCampaignSender;
        $this->sender->accountResult = ['ok' => false, 'message' => 'Serwer odrzucił logowanie.'];
        $this->app->instance(CampaignSender::class, $this->sender);
        $this->app->instance(SmtpHostGuard::class, new SmtpHostGuard(fn (string $host): array => $this->dns[$host] ?? []));
    }

    public function test_show_empty_then_save_and_password_never_returned(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/me/mail-account')->assertForbidden();

        Sanctum::actingAs($user);
        $this->getJson('/api/me/mail-account')->assertOk()
            ->assertJsonPath('configured', false)
            ->assertJsonPath('has_password', false)
            ->assertJsonPath('port', 587)
            ->assertJsonPath('rate_per_hour', 150);

        // nowa skrzynka bez hasła
        $this->putJson('/api/me/mail-account', [...$this->input(), 'password' => ''])->assertUnprocessable()->assertJsonValidationErrors('password');

        $res = $this->putJson('/api/me/mail-account', $this->input())->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('has_password', true)
            ->assertJsonPath('from_address', 'jan@supon.pl')
            ->assertJsonPath('scheme', 'smtp')
            ->assertJsonPath('signature', "Jan Nowak\ntel. 600 000 000");
        $this->assertArrayNotHasKey('password', $res->json());
        $this->assertStringNotContainsString('Tajne!haslo', $res->getContent());
        $account = UserMailAccount::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Tajne!haslo', $account->password);
        $this->assertNotSame('Tajne!haslo', $account->getRawOriginal('password'));

        // potwierdzona skrzynka: zmiana podpisu nie kasuje potwierdzenia, puste hasło zostawia stare
        $account->forceFill(['verified_at' => now()])->save();
        $this->putJson('/api/me/mail-account', [...$this->input(), 'password' => null, 'signature' => 'Pozdrawiam'])->assertOk()
            ->assertJsonPath('signature', 'Pozdrawiam');
        $account->refresh();
        $this->assertSame('Tajne!haslo', $account->password);
        $this->assertNotNull($account->verified_at);

        // zmiana serwera wymaga ponownego testu
        $this->putJson('/api/me/mail-account', [...$this->input(), 'password' => '', 'host' => 'smtp2.supon.pl'])->assertOk()
            ->assertJsonPath('verified_at', null);
        $this->assertSame('Tajne!haslo', $account->refresh()->password);
    }

    public function test_validation(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->putJson('/api/me/mail-account', [...$this->input(), 'port' => 22])->assertUnprocessable()->assertJsonValidationErrors('port');
        $this->putJson('/api/me/mail-account', [...$this->input(), 'scheme' => 'tls'])->assertUnprocessable()->assertJsonValidationErrors('scheme');
        $this->putJson('/api/me/mail-account', [...$this->input(), 'rate_per_hour' => 0])->assertUnprocessable()->assertJsonValidationErrors('rate_per_hour');
        $this->putJson('/api/me/mail-account', [...$this->input(), 'rate_per_hour' => 2001])->assertUnprocessable()->assertJsonValidationErrors('rate_per_hour');
        $this->putJson('/api/me/mail-account', [...$this->input(), 'from_address' => 'nie-mail'])->assertUnprocessable()->assertJsonValidationErrors('from_address');
        $this->putJson('/api/me/mail-account', [...$this->input(), 'from_name' => "Jan\r\nBcc: x@y.pl"])->assertUnprocessable()->assertJsonValidationErrors('from_name');
        $this->putJson('/api/me/mail-account', [...$this->input(), 'host' => 'smtp.supon.pl/x y'])->assertUnprocessable()->assertJsonValidationErrors('host');
        $this->putJson('/api/me/mail-account', [...$this->input(), 'port' => 465, 'scheme' => 'smtps'])->assertOk()->assertJsonPath('port', 465);
        $this->putJson('/api/me/mail-account', [...$this->input(), 'scheme' => null])->assertOk()->assertJsonPath('scheme', null);
    }

    public function test_account_test_uses_sender_and_is_per_user(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $other = User::factory()->withRole('handlowiec')->create();

        Sanctum::actingAs($user);
        $this->postJson('/api/me/mail-account/test')->assertUnprocessable();
        $this->assertSame([], $this->sender->calls);

        $this->putJson('/api/me/mail-account', $this->input())->assertOk();
        $this->postJson('/api/me/mail-account/test')->assertOk()->assertExactJson(['ok' => false, 'message' => 'Serwer odrzucił logowanie.']);
        $this->assertSame([['testAccount', [(int) UserMailAccount::query()->where('user_id', $user->id)->value('id')]]], $this->sender->calls);

        // inny użytkownik nie widzi cudzej skrzynki
        Sanctum::actingAs($other);
        $this->getJson('/api/me/mail-account')->assertOk()->assertJsonPath('configured', false);
    }

    public function test_mail_server_must_be_public(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);
        $this->dns += [
            'poczta.lan.supon.pl' => ['192.168.1.10'],
            'dziesiec.supon.pl' => ['10.20.30.40'],
            'metadane.supon.pl' => ['169.254.169.254'],
            'zero.supon.pl' => ['0.0.0.0'],
            'v6.supon.pl' => ['fd00::25'],
            'v6ll.supon.pl' => ['fe80::1'],
            'v6lo.supon.pl' => ['::1'],
            'mapped.supon.pl' => ['::ffff:127.0.0.1'],
            // jeden z adresów prywatny — też odpada (połączenie mogłoby trafić w ten)
            'mieszany.supon.pl' => ['212.77.98.9', '127.0.0.1'],
        ];

        $blocked = ['localhost', 'smtp.localhost', '127.0.0.1', '10.0.0.5', '2130706433', 'poczta.lan.supon.pl', 'dziesiec.supon.pl',
            'metadane.supon.pl', 'zero.supon.pl', 'v6.supon.pl', 'v6ll.supon.pl', 'v6lo.supon.pl', 'mapped.supon.pl', 'mieszany.supon.pl'];
        foreach ($blocked as $host) {
            $this->putJson('/api/me/mail-account', [...$this->input(), 'host' => $host])
                ->assertUnprocessable()
                ->assertJsonPath('errors.host.0', SmtpHostGuard::NOT_PUBLIC);
        }
        $this->putJson('/api/me/mail-account', [...$this->input(), 'host' => 'nie-ma-takiego.supon.pl'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.host.0', SmtpHostGuard::NOT_FOUND);
        $this->assertSame(0, UserMailAccount::query()->count());

        // własny serwer w sieci firmy tylko z listy administratora
        config(['campaigns.smtp_allowed_hosts' => ['Poczta.LAN.supon.pl']]);
        $this->putJson('/api/me/mail-account', [...$this->input(), 'host' => 'poczta.lan.supon.pl'])->assertOk();
        $this->putJson('/api/me/mail-account', [...$this->input(), 'host' => 'smtp.supon.pl'])->assertOk()->assertJsonPath('host', 'smtp.supon.pl');

        // nazwa, która po zapisie zaczęła wskazywać localhost — zmiana innych pól też odrzucona
        $this->dns['smtp.supon.pl'] = ['127.0.0.1'];
        $this->putJson('/api/me/mail-account', [...$this->input(), 'password' => '', 'signature' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('host');
    }

    public function test_new_password_replaces_unreadable_one(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);
        $this->putJson('/api/me/mail-account', $this->input())->assertOk();
        $account = UserMailAccount::query()->where('user_id', $user->id)->firstOrFail();
        $account->forceFill(['verified_at' => now()])->save();
        // hasło zaszyfrowane innym APP_KEY — nie da się go odczytać
        DB::table('user_mail_accounts')->where('id', $account->id)->update(['password' => 'eyJpdiI6ImFiYyIsInZhbHVlIjoieHl6IiwibWFjIjoiMTIzIn0=']);

        // bez nowego hasła zapis innych pól działa, a skrzynka dalej ma (nieczytelne) hasło
        $this->putJson('/api/me/mail-account', [...$this->input(), 'password' => '', 'signature' => 'Pozdrawiam'])
            ->assertOk()->assertJsonPath('has_password', true);

        $this->putJson('/api/me/mail-account', [...$this->input(), 'password' => 'Nowe!haslo'])
            ->assertOk()
            ->assertJsonPath('has_password', true)
            ->assertJsonPath('verified_at', null);
        $this->assertSame('Nowe!haslo', $account->fresh()->password);
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return [
            'from_name' => 'Jan Nowak — SUPON',
            'from_address' => 'jan@supon.pl',
            'host' => 'smtp.supon.pl',
            'port' => 587,
            'scheme' => 'smtp',
            'username' => 'jan@supon.pl',
            'password' => 'Tajne!haslo',
            'verify_peer' => true,
            'rate_per_hour' => 150,
            'copy_to_self' => true,
            'signature' => "Jan Nowak\ntel. 600 000 000",
        ];
    }
}
