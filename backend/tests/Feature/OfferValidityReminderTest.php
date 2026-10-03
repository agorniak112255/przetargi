<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientInquiry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * crm:remind › offer_validity_ending: do autora od ostatniego dnia roboczego przed końcem ważności oferty do jej końca,
 * od 7:00 czasu polskiego, raz na ofertę, tylko bez wpisanego wyniku.
 */
final class OfferValidityReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        $this->author = User::factory()->withRole('handlowiec')->create();
    }

    public function test_reminder_goes_once_from_last_business_day_after_seven(): void
    {
        // odpowiedź wtorek 22.09, „14 dni” → ważna do wtorku 6.10; ostatni dzień roboczy przed = poniedziałek 5.10
        $inquiry = $this->offer('2026-09-22 15:00', '14 dni', ['source_subject' => 'Półmaski i fartuchy', 'contact' => ['company' => 'Szpital Miejski nr 3']]);

        $this->at('2026-10-02 12:00');                                 // piątek — za wcześnie
        $this->assertSame(0, $this->author->notifications()->count());
        $this->at('2026-10-05 06:45');                                 // poniedziałek przed 7:00
        $this->assertSame(0, $this->author->notifications()->count());

        $this->at('2026-10-05 07:00');
        $this->assertSame(1, $this->author->notifications()->count());
        $data = $this->author->notifications()->sole()->data;
        $this->assertSame('offer_validity_ending', $data['type'] ?? $data['event'] ?? null);
        $this->assertSame('/inquiries/'.$inquiry->id, $data['url']);
        $this->assertStringContainsString('6.10.2026', (string) $data['title']);
        $this->assertStringContainsString('Szpital Miejski nr 3', (string) ($data['body'] ?? ''));

        // kolejne przebiegi tego i następnego dnia — bez powtórki (raz na ofertę)
        $this->at('2026-10-05 07:15');
        $this->at('2026-10-06 09:00');
        $this->assertSame(1, $this->author->notifications()->count());
        // po końcu ważności nic
        $this->at('2026-10-07 09:00');
        $this->assertSame(1, $this->author->notifications()->count());
    }

    public function test_no_reminder_after_outcome_for_old_replies_or_unreadable_validity(): void
    {
        $this->offer('2026-09-22 15:00', '14 dni', ['outcome' => 'unknown']);
        $this->offer('2026-09-22 15:00', 'do wyczerpania zapasów');
        // odpowiedź sprzed 120 dni, choć „rok” ważności by pasował
        $this->offer('2026-05-01 10:00', '158 dni');

        $this->at('2026-10-05 08:00');
        $this->assertSame(0, $this->author->notifications()->count());
    }

    public function test_outcome_entered_before_the_window_stops_the_reminder(): void
    {
        $inquiry = $this->offer('2026-09-22 15:00', '14 dni');
        $inquiry->forceFill(['outcome' => 'ordered'])->save();

        $this->at('2026-10-05 08:00');
        $this->assertSame(0, $this->author->notifications()->count());
    }

    private function at(string $polish): void
    {
        $this->travelTo(CarbonImmutable::parse($polish, 'Europe/Warsaw'));
        Artisan::call('crm:remind');
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function offer(string $repliedAt, string $validity, array $attrs = []): ClientInquiry
    {
        $inquiry = ClientInquiry::query()->create(['user_id' => $this->author->id, 'source_body' => 'Prośba o ofertę', 'source_channel' => 'web']);
        $inquiry->forceFill([
            'replied_at' => CarbonImmutable::parse($repliedAt, 'Europe/Warsaw')->utc(),
            'offer_terms' => ['validity' => $validity],
            ...$attrs,
        ])->save();

        return $inquiry;
    }
}
