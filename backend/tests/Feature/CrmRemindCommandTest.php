<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Notifications\AppNotificationMessage;
use App\Services\Notifications\ClientNoteReminderPlanner;
use App\Services\Notifications\OfferValidityPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** crm:remind: oba planery, jedna wysyłka na okres, błąd jednego planera nie zatrzymuje drugiego. */
final class CrmRemindCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_stub_planners_send_nothing(): void
    {
        $this->assertSame(0, Artisan::call('crm:remind'));
        $this->assertStringContainsString('Wysłane przypomnienia: 0, nieudane e-maile: 0, błędy: 0.', Artisan::output());
    }

    public function test_reminders_go_once_per_period_and_planner_hears_about_sending(): void
    {
        $user = User::factory()->create();
        // zdarzenie client_note_reminder dotyczy tylko osób z clients.view (config/notifications.php)
        $user->givePermissionTo(Permission::findOrCreate('clients.view', 'web'));
        $planner = new class($user) extends ClientNoteReminderPlanner
        {
            /** @var list<array<int, array{bell: bool, mail: ?bool}>> */
            public array $sentResults = [];

            public function __construct(private readonly User $user) {}

            public function due(CarbonImmutable $now): iterable
            {
                yield [
                    'user' => $this->user,
                    'message' => new AppNotificationMessage(
                        event: 'client_note_reminder',
                        subjectKey: 'client_note:7',
                        title: 'Przypomnienie: ACME',
                        body: 'Zadzwonić w sprawie rękawic.',
                        url: '/clients/3',
                    ),
                    'period' => '2026-10-05',
                ];
            }

            public function sent(array $reminder, array $result): void
            {
                $this->sentResults[] = $result;
            }
        };
        $this->app->instance(ClientNoteReminderPlanner::class, $planner);
        // drugi planer pada — pierwszy i tak wysyła, przebieg kończy się błędem (alert w „Stanie systemu”)
        $this->app->instance(OfferValidityPlanner::class, new class extends OfferValidityPlanner
        {
            public function due(CarbonImmutable $now): iterable
            {
                throw new RuntimeException('błąd zapytania');
            }
        });
        $user->forceFill(['notification_preferences' => ['events' => ['client_note_reminder' => ['bell' => true, 'mail' => false]]]])->save();

        $this->assertSame(1, Artisan::call('crm:remind'));
        $this->assertStringContainsString('Wysłane przypomnienia: 1, nieudane e-maile: 0, błędy: 1.', Artisan::output());
        $this->assertSame(1, $user->notifications()->count());
        $this->assertCount(1, $planner->sentResults);

        // ten sam okres w kolejnym przebiegu — bez powtórki w dzwonku
        Artisan::call('crm:remind');
        $this->assertSame(1, $user->notifications()->count());
    }
}
