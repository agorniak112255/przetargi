<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientInquiry;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductInquirySearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/** Analiza zapytania w tle zapisana → autor dostaje powiadomienie; nieudana analiza — nie. */
final class InquiryAnalysisReadyNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = "Dzień dobry,\nproszę o ofertę:\n1. Rękawice robocze R1 - 10 par\n2. Rękawice robocze R2 - 20 par";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        $this->mock(ProductInquirySearch::class, function ($mock): void {
            $mock->shouldReceive('findMany')->andReturnUsing(
                static fn (array $queries): array => array_map(static fn (string $q): array => ['query' => $q, 'products' => []], $queries),
            );
        });
    }

    public function test_author_is_notified_once_per_finished_analysis(): void
    {
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->andReturn([
                'subject' => 'Rękawice',
                'questions' => [],
                'product_queries' => [],
                'line_items' => [
                    ['id' => 'item_1', 'quote' => 'Rękawice robocze R1 - 10 par', 'qty' => '10', 'unit' => 'par', 'query' => 'rękawice robocze R1', 'size' => null],
                    ['id' => 'item_2', 'quote' => 'Rękawice robocze R2 - 20 par', 'qty' => '20', 'unit' => 'par', 'query' => 'rękawice robocze R2', 'size' => null],
                ],
                'cards' => [],
            ]);
        });
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);

        // kolejka w testach jest synchroniczna — analiza kończy się w tym samym żądaniu
        $id = (int) $this->postJson('/api/inquiries', ['body' => self::BODY, 'subject' => 'Rękawice', 'tone' => 'handlowy'])
            ->assertCreated()
            ->assertJsonPath('analysis_status', 'done')
            ->json('id');

        $rows = $user->notifications()->get();
        $this->assertCount(1, $rows);
        $this->assertSame('inquiry_analysis_ready', $rows[0]->data['type']);
        $this->assertSame('Analiza zapytania jest gotowa', $rows[0]->data['title']);
        $this->assertSame('/inquiries/'.$id, $rows[0]->data['url']);
        $this->assertSame($id, $rows[0]->data['inquiry_id']);
        $this->assertSame('Rękawice · pozycji w zapytaniu: 2', $rows[0]->data['body']);
        // domyślnie tylko w dzwonku
        Mail::assertNothingSent();
        $this->assertDatabaseHas('notification_dispatches', [
            'user_id' => $user->id,
            'event' => 'inquiry_analysis_ready',
            'subject_key' => 'inquiry:'.$id,
            'period_key' => 'run:'.ClientInquiry::query()->findOrFail($id)->analysis_run_id,
        ]);
    }

    public function test_notification_is_sent_after_analysis_timings_are_measured(): void
    {
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->andReturn([
                'subject' => 'Rękawice', 'questions' => [], 'product_queries' => [], 'cards' => [],
                'line_items' => [['id' => 'item_1', 'quote' => 'Rękawice robocze R1 - 10 par', 'qty' => '10', 'unit' => 'par', 'query' => 'rękawice robocze R1', 'size' => null]],
            ]);
        });
        $user = User::factory()->withRole('handlowiec')->create();
        // e-mail (SMTP) nie może wliczać się do czasu analizy w logu client-inquiry.timings
        $notificationsWhenTimed = null;
        Event::listen(MessageLogged::class, static function (MessageLogged $e) use ($user, &$notificationsWhenTimed): void {
            if ($e->message === 'client-inquiry.timings') {
                $notificationsWhenTimed = $user->notifications()->count();
            }
        });
        Sanctum::actingAs($user);

        $this->postJson('/api/inquiries', ['body' => self::BODY, 'tone' => 'handlowy'])
            ->assertCreated()
            ->assertJsonPath('analysis_status', 'done');

        $this->assertSame(0, $notificationsWhenTimed, 'Czasy zapisane przed wysyłką powiadomienia.');
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_failed_analysis_does_not_notify(): void
    {
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->andThrow(new RuntimeException('Model nie odpowiedział w wyznaczonym czasie.'));
        });
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/inquiries', ['body' => self::BODY, 'tone' => 'handlowy'])
            ->assertCreated()
            ->assertJsonPath('analysis_status', 'failed');

        $this->assertSame(0, $user->notifications()->count());
    }
}
