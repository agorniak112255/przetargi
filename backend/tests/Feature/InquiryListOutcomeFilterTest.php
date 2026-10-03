<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientInquiry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** GET /api/inquiries: wynik i ważność oferty w wierszu, filtr outcome (missing = wysłane bez wpisanego wyniku). */
final class InquiryListOutcomeFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00:00', 'Europe/Warsaw'));
    }

    public function test_rows_carry_outcome_and_validity_and_filter_by_outcome(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $ordered = $this->inquiry($user, ['outcome' => 'ordered', 'replied_at' => '2026-09-14 16:00', 'offer_terms' => ['validity' => '14 dni']]);
        $partial = $this->inquiry($user, ['outcome' => 'partial', 'replied_at' => '2026-09-15 10:00']);
        $notOrdered = $this->inquiry($user, ['outcome' => 'not_ordered', 'replied_at' => '2026-09-16 10:00']);
        $unknown = $this->inquiry($user, ['outcome' => 'unknown', 'replied_at' => '2026-09-17 10:00']);
        $missing = $this->inquiry($user, ['replied_at' => '2026-09-18 10:00', 'offer_terms' => ['validity' => 'do odwołania']]);
        $waiting = $this->inquiry($user, []);
        Sanctum::actingAs($user);

        $rows = collect($this->getJson('/api/inquiries')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(['ordered', '2026-09-28'], [$rows[$ordered->id]['outcome'], $rows[$ordered->id]['offer_valid_until']]);
        $this->assertSame([null, null], [$rows[$missing->id]['outcome'], $rows[$missing->id]['offer_valid_until']], 'Tekst „do odwołania” nie daje daty.');
        $this->assertNull($rows[$waiting->id]['offer_valid_until']);

        $ids = fn (string $outcome): array => array_column($this->getJson('/api/inquiries?outcome='.$outcome)->assertOk()->json('data'), 'id');
        $this->assertSame([$ordered->id], $ids('ordered'));
        $this->assertSame([$partial->id], $ids('partial'));
        $this->assertSame([$notOrdered->id], $ids('not_ordered'));
        $this->assertSame([$unknown->id], $ids('unknown'));
        // bez odpowiedzi nie ma czego wpisywać — „missing” to tylko wysłane
        $this->assertSame([$missing->id], $ids('missing'));
        $this->getJson('/api/inquiries?outcome=won')->assertUnprocessable()->assertJsonValidationErrors('outcome');
        $this->getJson('/api/inquiries?outcome=missing')->assertJsonPath('meta.total', 1);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function inquiry(User $user, array $attrs): ClientInquiry
    {
        if (isset($attrs['replied_at'])) {
            $attrs['replied_at'] = CarbonImmutable::parse((string) $attrs['replied_at'], 'Europe/Warsaw')->utc();
        }
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id, 'tone' => 'formal', 'source_channel' => 'web', 'source_subject' => 'Zapytanie',
            'source_body' => 'Proszę o ofertę.', 'analysis' => [],
        ]);
        $inquiry->forceFill($attrs)->save();

        return $inquiry;
    }
}
