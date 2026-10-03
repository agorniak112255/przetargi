<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ScheduledTaskRun;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * system:prune — stare wpisy wysłanych powiadomień, przebiegi zadań i treść ogłoszeń niepowiązanych z przetargiem.
 */
class SystemPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-03 02:35:00', 'UTC'));
        config([
            'notifications.dispatches_retention_days' => 180,
            'system_health.runs_retention_days' => 60,
            'bzp.html_retention_days' => 30,
        ]);
    }

    public function test_old_dispatches_and_task_runs_are_deleted(): void
    {
        $user = User::factory()->create();
        $this->dispatch($user, 'a', now()->subDays(181));
        $this->dispatch($user, 'b', now()->subDays(179));
        $this->taskRun(now()->subDays(61));
        $this->taskRun(now()->subDays(59));

        $this->artisan('system:prune')
            ->expectsOutputToContain('Usunięte wpisy wysłanych powiadomień: 1. Usunięte przebiegi zadań: 1.')
            ->assertSuccessful();

        $this->assertSame(['b'], DB::table('notification_dispatches')->pluck('subject_key')->all());
        $this->assertSame(1, ScheduledTaskRun::query()->count());
    }

    public function test_notice_html_is_cleared_only_when_old_and_not_linked(): void
    {
        $old = $this->notice('2026/BZP 00000001/01', now()->subDays(31));
        $fresh = $this->notice('2026/BZP 00000002/01', now()->subDays(29));
        $contract = $this->notice('2026/BZP 00000003/01', now()->subDays(90));
        $result = $this->notice('2026/BZP 00000004/01', now()->subDays(90));
        $lotNotice = $this->notice('2026/BZP 00000005/01', now()->subDays(90));

        $tender = Tender::query()->create([
            'number' => 'PRZ/2026/0001', 'title' => 'Przetarg', 'client_id' => Client::query()->create(['name' => 'Gmina'])->id,
            'owner_id' => User::factory()->create()->id, 'status' => 'exported', 'ai_percent' => 0, 'last_activity_at' => now(),
        ]);
        $tender->forceFill(['contract_notice_id' => $contract, 'result_notice_id' => $result])->save();
        $other = Tender::query()->create([
            'number' => 'PRZ/2026/0002', 'title' => 'Przetarg 2', 'client_id' => Client::query()->create(['name' => 'Szpital'])->id,
            'owner_id' => User::factory()->create()->id, 'status' => 'exported', 'ai_percent' => 0, 'last_activity_at' => now(),
        ]);
        DB::table('tender_lots')->insert(['tender_id' => $other->id, 'lot_no' => 1, 'currency' => 'PLN', 'bzp_notice_id' => $lotNotice, 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('system:prune')->expectsOutputToContain('Wyczyszczona treść ogłoszeń: 1.')->assertSuccessful();

        $html = DB::table('procurement_notices')->pluck('html_body', 'id');
        $this->assertNull($html[$old]);
        $this->assertNotNull($html[$fresh]);
        $this->assertNotNull($html[$contract], 'Ogłoszenie o zamówieniu przetargu zostaje w całości.');
        $this->assertNotNull($html[$result], 'Ogłoszenie o wyniku przetargu zostaje w całości.');
        $this->assertNotNull($html[$lotNotice], 'Ogłoszenie, z którego wypełniono część, zostaje w całości.');
        $this->assertSame(5, DB::table('procurement_notices')->count(), 'Wiersze ogłoszeń zostają — znika tylko pełny HTML.');
        $this->assertNotNull(DB::table('procurement_notices')->where('id', $old)->value('parsed'));
    }

    private function dispatch(User $user, string $subject, \DateTimeInterface $at): void
    {
        DB::table('notification_dispatches')->insert([
            'user_id' => $user->id, 'event' => 'tender_deadline', 'subject_key' => $subject, 'period_key' => '2026-10-05:7d', 'created_at' => $at,
        ]);
    }

    private function taskRun(\DateTimeInterface $at): void
    {
        $run = ScheduledTaskRun::query()->create(['task' => 'system:prune', 'started_at' => $at, 'status' => 'ok']);
        $run->forceFill(['created_at' => $at])->save();
    }

    private function notice(string $number, \DateTimeInterface $fetchedAt): int
    {
        return (int) DB::table('procurement_notices')->insertGetId([
            'notice_type' => 'ContractNotice', 'notice_number' => $number, 'bzp_number' => substr($number, 0, 18),
            'published_at' => $fetchedAt, 'order_object' => 'Rękawice', 'cpv_codes' => json_encode(['18100000-0']),
            'organization_name' => 'Gmina', 'parsed' => json_encode(['lots' => []]), 'parser_version' => 1,
            'html_body' => '<html>'.str_repeat('a', 100).'</html>', 'fetched_at' => $fetchedAt, 'created_at' => $fetchedAt, 'updated_at' => $fetchedAt,
        ]);
    }
}
