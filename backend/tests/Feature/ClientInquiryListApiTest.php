<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientInquiry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lista zapytań mailowych: stronicowanie, filtry i zakres widoczności.
 *
 * Sprawdzamy, że filtrowanie odbywa się po stronie bazy (liczniki w `meta`
 * muszą zgadzać się z filtrem, a nie z całą tabelą).
 */
final class ClientInquiryListApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeInquiry(User $user, array $attributes = [], ?string $createdAt = null): ClientInquiry
    {
        $inquiry = ClientInquiry::query()->create(array_merge([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_channel' => 'web',
            'source_subject' => 'Zapytanie o rękawice',
            'source_body' => 'Proszę o ofertę na rękawice nitrylowe.',
            'analysis' => [],
        ], $attributes));

        if ($createdAt !== null) {
            $inquiry->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        }

        return $inquiry->refresh();
    }

    public function test_list_is_paginated_with_meta(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        for ($i = 1; $i <= 30; $i++) {
            $this->makeInquiry($user, ['source_subject' => 'Zapytanie '.$i]);
        }

        Sanctum::actingAs($user);

        $first = $this->getJson('/api/inquiries?per_page=25')->assertOk();
        $first->assertJsonCount(25, 'data');
        $first->assertJsonPath('meta.page', 1);
        $first->assertJsonPath('meta.per_page', 25);
        $first->assertJsonPath('meta.total', 30);
        $first->assertJsonPath('meta.last_page', 2);
        // handlowcy też widzą zespół — ten sam mail trafia do kilku osób
        $first->assertJsonPath('meta.can_view_all', true);

        $second = $this->getJson('/api/inquiries?per_page=25&page=2')->assertOk();
        $second->assertJsonCount(5, 'data');
        $second->assertJsonPath('meta.page', 2);
        $second->assertJsonPath('meta.total', 30);

        // strony nie mogą się zazębiać
        $firstIds = array_column($first->json('data'), 'id');
        $secondIds = array_column($second->json('data'), 'id');
        $this->assertSame([], array_intersect($firstIds, $secondIds));
        $this->assertCount(30, array_unique([...$firstIds, ...$secondIds]));
    }

    public function test_status_filter_splits_waiting_and_replied(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $waiting = $this->makeInquiry($user, ['source_subject' => 'Czeka']);
        $replied = $this->makeInquiry($user, ['source_subject' => 'Odpowiedziane', 'replied_at' => now()]);

        Sanctum::actingAs($user);

        $this->getJson('/api/inquiries?status=waiting')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $waiting->id)
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/inquiries?status=replied')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $replied->id)
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/inquiries')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_channel_filter_returns_only_thunderbird(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $this->makeInquiry($user, ['source_channel' => 'web', 'source_subject' => 'Z aplikacji']);
        $mail = $this->makeInquiry($user, ['source_channel' => 'thunderbird', 'source_subject' => 'Z poczty']);

        Sanctum::actingAs($user);

        $this->getJson('/api/inquiries?channel=thunderbird')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mail->id)
            ->assertJsonPath('data.0.source_channel', 'thunderbird')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_search_matches_subject_and_sender_email(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $bySubject = $this->makeInquiry($user, ['source_subject' => 'Kalosze ochronne na magazyn']);
        $byEmail = $this->makeInquiry($user, [
            'source_subject' => 'Pilne zamówienie',
            'source_from_name' => 'Wojciech Dzierżak',
            'source_from_email' => 'marketing@supon.rzeszow.pl',
        ]);
        $this->makeInquiry($user, ['source_subject' => 'Coś zupełnie innego', 'source_body' => 'Nic tu nie ma.']);

        Sanctum::actingAs($user);

        $this->getJson('/api/inquiries?q=kalosze')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $bySubject->id);

        $this->getJson('/api/inquiries?q=supon.rzeszow.pl')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $byEmail->id)
            ->assertJsonPath('data.0.source_from_email', 'marketing@supon.rzeszow.pl');
    }

    public function test_default_scope_hides_other_users_inquiries(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $other = User::factory()->withRole('handlowiec')->create();
        $mine = $this->makeInquiry($user, ['source_subject' => 'Moje']);
        $this->makeInquiry($other, ['source_subject' => 'Cudze']);

        Sanctum::actingAs($user);

        $this->getJson('/api/inquiries')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.user.id', $user->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_scope_all_is_forbidden_without_permission(): void
    {
        // rola z odebranym uprawnieniem — tak wygląda konto okrojone w panelu
        $user = User::factory()->withRole('handlowiec')->create();
        $user->roles->first()?->revokePermissionTo('inquiries.view_all');
        $user->forgetCachedPermissions();
        $this->makeInquiry($user);

        Sanctum::actingAs($user);

        $this->getJson('/api/inquiries')
            ->assertOk()
            ->assertJsonPath('meta.can_view_all', false);
        $this->getJson('/api/inquiries?scope=all')->assertForbidden();
    }

    public function test_kierownik_sees_other_users_with_scope_all(): void
    {
        $kierownik = User::factory()->withRole('kierownik')->create();
        $handlowiec = User::factory()->withRole('handlowiec')->create();
        $own = $this->makeInquiry($kierownik, ['source_subject' => 'Kierownika']);
        $foreign = $this->makeInquiry($handlowiec, ['source_subject' => 'Handlowca']);

        Sanctum::actingAs($kierownik);

        $all = $this->getJson('/api/inquiries?scope=all')->assertOk();
        $all->assertJsonPath('meta.total', 2);
        $all->assertJsonPath('meta.can_view_all', true);
        $ids = array_column($all->json('data'), 'id');
        sort($ids);
        $expected = [$own->id, $foreign->id];
        sort($expected);
        $this->assertSame($expected, $ids);

        // zawężenie do jednego pracownika działa tylko przy scope=all
        $this->getJson('/api/inquiries?scope=all&user_id='.$handlowiec->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $foreign->id)
            ->assertJsonPath('data.0.user.name', $handlowiec->name);

        // bez scope=all kierownik widzi tylko swoje
        $this->getJson('/api/inquiries')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
    }

    public function test_date_range_filters_by_created_at_inclusive(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $old = $this->makeInquiry($user, ['source_subject' => 'Stare'], '2026-08-01 10:00:00');
        $edge = $this->makeInquiry($user, ['source_subject' => 'Brzeg'], '2026-09-01 23:30:00');
        $fresh = $this->makeInquiry($user, ['source_subject' => 'Nowe'], '2026-09-10 08:00:00');

        Sanctum::actingAs($user);

        // granice włącznie: 2026-09-01 mieści się mimo godziny 23:30
        $range = $this->getJson('/api/inquiries?from=2026-09-01&to=2026-09-01')->assertOk();
        $range->assertJsonCount(1, 'data');
        $range->assertJsonPath('data.0.id', $edge->id);

        $from = $this->getJson('/api/inquiries?from=2026-09-01')->assertOk();
        $from->assertJsonPath('meta.total', 2);
        $this->assertSame([$fresh->id, $edge->id], array_column($from->json('data'), 'id'));

        $to = $this->getJson('/api/inquiries?to=2026-08-31')->assertOk();
        $to->assertJsonCount(1, 'data');
        $to->assertJsonPath('data.0.id', $old->id);
    }

    public function test_invalid_parameters_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->getJson('/api/inquiries?status=cokolwiek')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->getJson('/api/inquiries?channel=gmail')->assertUnprocessable();
        $this->getJson('/api/inquiries?per_page=500')->assertUnprocessable();
        $this->getJson('/api/inquiries?from=01.09.2026')->assertUnprocessable();
        $this->getJson('/api/inquiries?from=2026-09-10&to=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);
    }
}
