<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\AccountCredentialsMail;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

final class AdminUserCredentialsMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_sends_generated_credentials_and_password_starts_working(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        Mail::fake();

        $user = User::factory()->withRole('handlowiec')->create([
            'email' => 'handlowiec@test.local',
            'password' => Hash::make('stare-haslo-123'),
        ]);

        $this->postJson("/api/admin/users/{$user->id}/send-credentials")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('password_generated', true);

        $sent = null;
        Mail::assertSent(AccountCredentialsMail::class, function (AccountCredentialsMail $mail) use ($user, &$sent): bool {
            $sent = $mail;

            return $mail->hasTo($user->email) && $mail->account->is($user);
        });

        $this->assertNotNull($sent);
        $this->assertSame('Handlowiec', $sent->roleLabel);
        $this->assertSame($user->email, $sent->account->email);

        $user->refresh();
        $this->assertTrue(Hash::check($sent->plainPassword, (string) $user->password));
        $this->assertFalse(Hash::check('stare-haslo-123', (string) $user->password));
    }

    public function test_message_shows_login_password_and_application_address(): void
    {
        config(['app.frontend_url' => 'https://przetargi.example.test/']);
        $user = User::factory()->withRole('handlowiec')->create(['email' => 'adresat@test.local']);

        $mail = new AccountCredentialsMail($user, 'haslo-do-podgladu', 'https://przetargi.example.test', 'Handlowiec');

        $mail->assertSeeInHtml('adresat@test.local');
        $mail->assertSeeInHtml('haslo-do-podgladu');
        $mail->assertSeeInHtml('https://przetargi.example.test');
        $mail->assertSeeInText('adresat@test.local');
        $mail->assertSeeInText('haslo-do-podgladu');
    }

    public function test_message_attaches_the_thunderbird_addon(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();

        $mail = new AccountCredentialsMail($user, 'haslo-do-podgladu', 'https://przetargi.example.test', 'Handlowiec');

        $this->assertFileExists($mail->addonPath);
        $this->assertTrue($mail->hasAttachment(
            Attachment::fromPath(public_path('dodatek/supon-przetargi.xpi'))
                ->as('supon-przetargi.xpi')
                ->withMime('application/x-xpinstall')
        ));
        $mail->assertSeeInHtml('Dodatek do Thunderbirda w załączniku');
        $mail->assertSeeInHtml('https://przetargi.example.test/help');
        $mail->assertSeeInText('Zainstaluj dodatek z pliku');

        // załącznik naprawdę ląduje w wysłanej wiadomości, pod nazwą .xpi
        Mail::to($user->email)->send($mail);
        $sent = collect(app('mailer')->getSymfonyTransport()->messages())->last()->getOriginalMessage();
        $names = array_map(static fn ($part) => $part->getFilename(), $sent->getAttachments());
        $this->assertSame(['supon-przetargi.xpi'], $names);
    }

    public function test_message_without_addon_file_goes_out_without_attachment(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();

        $mail = new AccountCredentialsMail($user, 'haslo-do-podgladu', 'https://przetargi.example.test', 'Handlowiec', base_path('nie-ma-takiego-pliku.xpi'));

        $this->assertSame([], $mail->attachments());
        $mail->assertDontSeeInHtml('Dodatek do Thunderbirda w załączniku');
        $mail->assertDontSeeInText('Zainstaluj dodatek z pliku');
        $mail->assertSeeInHtml('haslo-do-podgladu');
    }

    public function test_admin_sends_password_typed_in_the_panel(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        Mail::fake();

        $user = User::factory()->withRole('handlowiec')->create();

        $this->postJson("/api/admin/users/{$user->id}/send-credentials", ['password' => 'wpisane-haslo-1'])
            ->assertOk()
            ->assertJsonPath('password_generated', false);

        Mail::assertSent(AccountCredentialsMail::class, static fn (AccountCredentialsMail $mail): bool => $mail->plainPassword === 'wpisane-haslo-1');

        $this->assertTrue(Hash::check('wpisane-haslo-1', (string) $user->refresh()->password));
    }

    public function test_failed_delivery_leaves_the_old_password_in_place(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $user = User::factory()->withRole('handlowiec')->create([
            'password' => Hash::make('stare-haslo-123'),
        ]);

        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP nie odpowiada'));

        $this->postJson("/api/admin/users/{$user->id}/send-credentials")
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertTrue(Hash::check('stare-haslo-123', (string) $user->refresh()->password));
    }

    public function test_short_password_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        Mail::fake();

        $user = User::factory()->withRole('handlowiec')->create();

        $this->postJson("/api/admin/users/{$user->id}/send-credentials", ['password' => 'krotkie'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        Mail::assertNothingSent();
    }

    public function test_user_without_permission_cannot_send_credentials(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        Mail::fake();

        $target = User::factory()->withRole('handlowiec')->create();

        $this->postJson("/api/admin/users/{$target->id}/send-credentials")->assertForbidden();

        Mail::assertNothingSent();
    }
}
