<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\SmtpTestMail;
use App\Models\MailSetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class MailSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
    }

    public function test_admin_can_update_and_test_mail_settings(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson('/api/admin/mail-settings', [
            'mailer' => 'smtp',
            'host' => 'mail.example.test',
            'port' => 587,
            'username' => 'noreply@example.test',
            'password' => 'secret-pass',
            'from_address' => 'noreply@example.test',
            'from_name' => 'Przetargi Test',
            'verify_peer' => false,
        ])
            ->assertOk()
            ->assertJsonPath('host', 'mail.example.test')
            ->assertJsonPath('has_password', true)
            ->assertJsonMissingPath('password');

        $this->assertNotNull(MailSetting::query()->first()?->password);

        $this->postJson('/api/admin/mail-settings/test', [
            'to' => 'tester@example.test',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Wysłano e-mail testowy do tester@example.test');

        Mail::assertSent(SmtpTestMail::class, function (SmtpTestMail $mail): bool {
            return $mail->hasTo('tester@example.test');
        });
    }

    public function test_handlowiec_cannot_access_mail_settings(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->getJson('/api/admin/mail-settings')->assertForbidden();
    }
}
