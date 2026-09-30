<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\EmailSuppression;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Lista wypisanych: widzi każdy z kampaniami, dopisuje i zdejmuje tylko administrator. */
final class EmailSuppressionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_list_for_campaign_users_manage_only_for_admin(): void
    {
        $seller = User::factory()->withRole('handlowiec')->create();
        $admin = User::factory()->withRole('admin')->create();
        $campaign = Campaign::query()->create(['user_id' => $seller->id, 'name' => 'K']);
        $unsub = EmailSuppression::query()->create(['email' => 'klient@firma.pl', 'reason' => EmailSuppression::REASON_UNSUBSCRIBE, 'campaign_id' => $campaign->id]);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/email-suppressions')->assertForbidden();

        Sanctum::actingAs($seller);
        $this->getJson('/api/email-suppressions')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0', [
                'id' => $unsub->id, 'email' => 'klient@firma.pl', 'reason' => 'unsubscribe', 'note' => null,
                'campaign' => ['id' => $campaign->id, 'code' => $campaign->refresh()->code],
                'created_at' => $unsub->created_at->toIso8601String(),
            ]);
        $this->postJson('/api/email-suppressions', ['email' => 'x@y.pl'])->assertForbidden();
        $this->deleteJson("/api/email-suppressions/{$unsub->id}")->assertForbidden();
        $this->assertSame(1, EmailSuppression::query()->count());

        Sanctum::actingAs($admin);
        $this->postJson('/api/email-suppressions', ['email' => ' Biuro@Firma.PL ', 'note' => 'telefon 30.09'])->assertCreated()
            ->assertJsonPath('email', 'biuro@firma.pl')
            ->assertJsonPath('reason', 'manual')
            ->assertJsonPath('campaign', null);
        $this->assertSame($admin->id, (int) EmailSuppression::query()->where('email', 'biuro@firma.pl')->value('created_by'));
        $this->postJson('/api/email-suppressions', ['email' => 'BIURO@firma.pl'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/email-suppressions', ['email' => 'nie-mail'])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame(['biuro@firma.pl'], array_column($this->getJson('/api/email-suppressions?search=BIURO')->json('data'), 'email'));

        $this->deleteJson("/api/email-suppressions/{$unsub->id}")->assertOk();
        $this->assertNull(EmailSuppression::query()->find($unsub->id));
    }
}
