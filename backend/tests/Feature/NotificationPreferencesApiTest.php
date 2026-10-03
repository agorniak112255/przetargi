<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Moje konto › Powiadomienia: wartości domyślne z konfiguracji, zapis tylko nadpisań, walidacja. */
final class NotificationPreferencesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_defaults_come_from_config_and_system_alert_only_with_permission(): void
    {
        $user = User::factory()->withRole('handlowiec')->create(['email' => 'jan@supon.example.pl']);
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/me/notification-preferences')->assertOk();

        $events = collect($res->json('events'))->keyBy('key');
        $this->assertSame(
            [
                'tender_deadline', 'tender_result_needed', 'tender_mention', 'tender_invitation', 'inquiry_analysis_ready', 'campaign_reply',
                'client_note_reminder', 'offer_validity_ending',
            ],
            $events->keys()->all(),
        );
        $this->assertTrue($events['tender_deadline']['bell']);
        $this->assertTrue($events['tender_deadline']['mail']);
        $this->assertFalse($events['tender_result_needed']['mail']);
        $this->assertSame('Zbliża się termin składania oferty', $events['tender_deadline']['label']);
        $this->assertSame(['7d', '3d', 'last_workday'], $res->json('deadline_offsets'));
        $this->assertSame(['7d', '3d', 'last_workday', '3h'], array_column($res->json('deadline_offset_options'), 'key'));
        $this->assertTrue($res->json('deadline_offset_options.3.needs_time'));
        $this->assertSame('jan@supon.example.pl', $res->json('email'));
        // w testach poczta to „array” — dla ludzi brak skonfigurowanej poczty
        $this->assertFalse($res->json('mail_configured'));

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $adminKeys = array_column($this->getJson('/api/me/notification-preferences')->assertOk()->json('events'), 'key');
        $this->assertContains('system_alert', $adminKeys);
    }

    public function test_only_differences_from_defaults_are_stored(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);

        $res = $this->putJson('/api/me/notification-preferences', [
            'events' => [
                'tender_mention' => ['bell' => true, 'mail' => false],
                // równe domyślnym — nie zapisujemy
                'tender_deadline' => ['bell' => true, 'mail' => true],
                'inquiry_analysis_ready' => ['mail' => true],
            ],
            'deadline_offsets' => ['3h', '7d'],
        ])->assertOk();

        $events = collect($res->json('events'))->keyBy('key');
        $this->assertFalse($events['tender_mention']['mail']);
        $this->assertTrue($events['tender_mention']['bell']);
        $this->assertTrue($events['inquiry_analysis_ready']['mail']);
        $this->assertTrue($events['inquiry_analysis_ready']['bell']);
        $this->assertSame(['7d', '3h'], $res->json('deadline_offsets'));
        $this->assertSame([
            'events' => [
                'tender_mention' => ['mail' => false],
                'inquiry_analysis_ready' => ['mail' => true],
            ],
            'deadline_offsets' => ['7d', '3h'],
        ], $user->fresh()->notification_preferences);

        // zdarzenie pominięte w zapisie zostaje bez zmian; powrót do domyślnych czyści nadpisania
        $this->putJson('/api/me/notification-preferences', [
            'events' => ['inquiry_analysis_ready' => ['mail' => false]],
        ])->assertOk()->assertJsonPath('deadline_offsets', ['7d', '3h']);
        $this->assertSame(['events' => ['tender_mention' => ['mail' => false]], 'deadline_offsets' => ['7d', '3h']], $user->fresh()->notification_preferences);

        $this->putJson('/api/me/notification-preferences', [
            'events' => ['tender_mention' => ['mail' => true]],
            'deadline_offsets' => ['last_workday', '3d', '7d'],
        ])->assertOk();
        $this->assertNull($user->fresh()->notification_preferences);

        // wszystkie momenty odznaczone = brak przypomnień o terminie (to też nadpisanie)
        $this->putJson('/api/me/notification-preferences', ['deadline_offsets' => []])
            ->assertOk()
            ->assertJsonPath('deadline_offsets', []);
        $this->assertSame(['deadline_offsets' => []], $user->fresh()->notification_preferences);
    }

    public function test_validation_and_events_without_permission_are_ignored(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/me/notification-preferences', ['deadline_offsets' => ['1d']])
            ->assertStatus(422)->assertJsonValidationErrors('deadline_offsets.0');
        $this->putJson('/api/me/notification-preferences', ['events' => ['price_up' => ['bell' => true]]])
            ->assertStatus(422)->assertJsonValidationErrors('events');
        $this->putJson('/api/me/notification-preferences', ['events' => ['tender_mention' => ['bell' => 'tak']]])
            ->assertStatus(422);

        $this->putJson('/api/me/notification-preferences', ['events' => ['system_alert' => ['mail' => false]]])->assertOk();
        $this->assertNull($user->fresh()->notification_preferences);
    }

    public function test_requires_login(): void
    {
        $this->getJson('/api/me/notification-preferences')->assertUnauthorized();
        $this->putJson('/api/me/notification-preferences', [])->assertUnauthorized();
    }
}
