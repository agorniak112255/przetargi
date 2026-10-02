<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\AnalyzeClientInquiryJob;
use App\Models\ClientInquiry;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ClientInquiryService;
use App\Services\ProductInquirySearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Analiza zapytania w tle (29.09.2026): zapytanie powstaje od razu, AnalyzeClientInquiryJob liczy pozycje, szukanie
 * i szkic listu. Przy 50 pozycjach to kilka minut — serwer ucina tak długie żądania.
 */
final class ClientInquiryAsyncAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = "Dzień dobry,\nproszę o ofertę:\n1. Rękawice robocze R1 - 10 par\n2. Rękawice robocze R2 - 20 par";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_inquiry_is_created_at_once_and_analysis_goes_to_the_queue(): void
    {
        Queue::fake();
        $this->mock(OpenAiCompatibleClient::class, fn ($mock) => $mock->shouldNotReceive('chatJson'));
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/inquiries', ['body' => self::BODY, 'subject' => 'Rękawice', 'tone' => 'handlowy'])
            ->assertCreated()
            ->assertJsonPath('analysis_status', 'queued')
            ->assertJsonPath('items', [])
            ->assertJsonPath('reply_body', null)
            ->assertJsonPath('source_subject', 'Rękawice');

        $inquiry = ClientInquiry::query()->findOrFail($res->json('id'));
        Queue::assertPushed(AnalyzeClientInquiryJob::class, fn (AnalyzeClientInquiryJob $job): bool => $job->inquiryId === $inquiry->id
            && $job->runId === $inquiry->analysis_run_id
            && $job->queue === AnalyzeClientInquiryJob::QUEUE);
        // lista pokazuje zapytanie od razu, z właściwym stanem
        $row = collect($this->getJson('/api/inquiries')->assertOk()->json('data'))->firstWhere('id', $inquiry->id);
        $this->assertSame('queued', $row['analysis_status']);
    }

    public function test_changes_wait_for_the_analysis_but_marking_sent_and_deleting_do_not(): void
    {
        Queue::fake();
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);
        $id = (int) $this->postJson('/api/inquiries', ['body' => self::BODY, 'tone' => 'handlowy'])->assertCreated()->json('id');
        $product = Product::query()->create(['sku' => 'R1', 'name' => 'Rękawice robocze R1', 'manufacturer' => 'Supon']);
        $waiting = 'Analiza zapytania jeszcze trwa — poczekaj na wynik.';

        $this->postJson("/api/inquiries/{$id}/compose", ['answers' => []])->assertStatus(409)->assertJsonPath('message', $waiting);
        $this->patchJson("/api/inquiries/{$id}", ['reply_body' => 'Mój list'])->assertStatus(409);
        $this->postJson("/api/inquiries/{$id}/pick-product", ['item_id' => 'item_1', 'product_id' => $product->id])->assertStatus(409);
        $this->postJson("/api/inquiries/{$id}/queue-reply", ['queued' => true])->assertStatus(409);
        $this->assertNull(ClientInquiry::query()->findOrFail($id)->reply_body);

        $this->postJson("/api/inquiries/{$id}/replied", ['replied' => true])->assertOk();
        $this->deleteJson("/api/inquiries/{$id}")->assertSuccessful();
    }

    public function test_failed_analysis_is_shown_and_can_be_restarted_once(): void
    {
        $calls = 0;
        $this->mock(OpenAiCompatibleClient::class, function ($mock) use (&$calls): void {
            $mock->shouldReceive('chatJson')->andReturnUsing(function () use (&$calls): array {
                if (++$calls === 1) {
                    throw new RuntimeException('Model nie odpowiedział w wyznaczonym czasie.');
                }

                return [
                    'subject' => 'Rękawice',
                    'questions' => [],
                    'product_queries' => [],
                    'line_items' => [
                        ['id' => 'item_1', 'quote' => 'Rękawice robocze R1 - 10 par', 'qty' => '10', 'unit' => 'par', 'query' => 'rękawice robocze R1', 'size' => null],
                    ],
                    'cards' => [],
                ];
            });
        });
        $this->emptySearch();
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/inquiries', ['body' => self::BODY, 'tone' => 'handlowy'])
            ->assertCreated()
            ->assertJsonPath('analysis_status', 'failed')
            ->assertJsonPath('analysis_error', 'Nie udało się przeanalizować zapytania: Model nie odpowiedział w wyznaczonym czasie.')
            ->assertJsonPath('can_retry_analysis', true);
        $id = (int) $res->json('id');
        $this->postJson("/api/inquiries/{$id}/compose", ['answers' => []])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Analiza zapytania nie powiodła się — uruchom ją ponownie.');

        $this->postJson("/api/inquiries/{$id}/retry-analysis")
            ->assertOk()
            ->assertJsonPath('analysis_status', 'done')
            ->assertJsonPath('analysis_error', null)
            ->assertJsonPath('items.0.quote', '1. Rękawice robocze R1 - 10 par');
        $this->assertNotSame('', (string) ClientInquiry::query()->findOrFail($id)->reply_body);
        // gotowej analizy nie liczymy drugi raz
        $this->postJson("/api/inquiries/{$id}/retry-analysis")->assertStatus(409);
        $this->assertSame(2, $calls);
    }

    public function test_only_the_author_restarts_the_analysis(): void
    {
        $author = User::factory()->withRole('handlowiec')->create();
        $inquiry = $this->pendingInquiry($author, ClientInquiry::ANALYSIS_FAILED);
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->postJson("/api/inquiries/{$inquiry->id}/retry-analysis")->assertForbidden();
    }

    /**
     * inquiries.reanalyze (02.10.2026, zapytanie #91 bez pozycji po złym cięciu maila): admin uruchamia od nowa
     * gotową analizę cudzego zapytania. Nowy wynik zastępuje pozycje i list, autor się nie zmienia.
     */
    public function test_admin_reanalyzes_done_inquiry_of_another_user(): void
    {
        $round = 0;
        $this->mock(OpenAiCompatibleClient::class, function ($mock) use (&$round): void {
            $mock->shouldReceive('chatJson')->andReturnUsing(function () use (&$round): array {
                $round++;

                return [
                    'subject' => 'Rękawice',
                    // drugi przebieg poznajemy po pytaniu klienta — pochodzi wyłącznie z odpowiedzi modelu
                    'questions' => $round > 1 ? ['Jaki termin dostawy?'] : [],
                    'product_queries' => [],
                    'line_items' => [
                        ['id' => 'item_1', 'quote' => 'Rękawice robocze R1 - 10 par', 'qty' => '10', 'unit' => 'par', 'query' => 'rękawice robocze R1', 'size' => null],
                    ],
                    'cards' => [],
                ];
            });
        });
        $this->emptySearch();
        $author = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($author);
        $id = (int) $this->postJson('/api/inquiries', ['body' => self::BODY, 'tone' => 'handlowy'])
            ->assertCreated()
            ->assertJsonPath('analysis_status', 'done')
            // autor bez uprawnienia nie liczy gotowej analizy od nowa
            ->assertJsonPath('can_reanalyze', false)
            ->assertJsonPath('questions', [])
            ->json('id');
        $this->postJson("/api/inquiries/{$id}/retry-analysis")->assertStatus(409);

        $admin = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($admin);
        $this->getJson("/api/inquiries/{$id}")
            ->assertOk()
            ->assertJsonPath('can_reanalyze', true)
            ->assertJsonPath('reanalyze_blocked', null);
        $this->postJson("/api/inquiries/{$id}/retry-analysis")
            ->assertOk()
            ->assertJsonPath('analysis_status', 'done')
            ->assertJsonPath('questions', ['Jaki termin dostawy?'])
            ->assertJsonPath('user.id', $author->id);
        $this->assertSame($author->id, (int) ClientInquiry::query()->findOrFail($id)->user_id);
        $this->assertSame(2, $round);
    }

    public function test_reanalysis_is_blocked_after_the_reply_was_sent_or_queued(): void
    {
        Queue::fake();
        $inquiry = $this->pendingInquiry(User::factory()->withRole('handlowiec')->create(), ClientInquiry::ANALYSIS_DONE);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $inquiry->forceFill(['send_requested_at' => now()])->save();
        $this->getJson("/api/inquiries/{$inquiry->id}")
            ->assertOk()
            ->assertJsonPath('reanalyze_blocked', 'List czeka na wysłanie w Thunderbirdzie.');
        $this->postJson("/api/inquiries/{$inquiry->id}/retry-analysis")
            ->assertStatus(409)
            ->assertJsonPath('message', 'List czeka na wysłanie w Thunderbirdzie.');

        $inquiry->forceFill(['send_requested_at' => null, 'replied_at' => now()])->save();
        $this->postJson("/api/inquiries/{$inquiry->id}/retry-analysis")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Odpowiedź na to zapytanie już wysłano.');

        // zapytanie sprzed analizy w tle (bez statusu) liczy się jako gotowe
        $legacy = $this->pendingInquiry(User::factory()->withRole('handlowiec')->create(), ClientInquiry::ANALYSIS_DONE);
        $legacy->forceFill(['analysis_status' => null])->save();
        // „długo czeka w kolejce” liczy się od ponowienia, nie od założenia zapytania
        $this->travelTo(now()->addHour()->startOfSecond());
        $this->postJson("/api/inquiries/{$legacy->id}/retry-analysis")
            ->assertOk()
            ->assertJsonPath('analysis_status', 'queued')
            ->assertJsonPath('analysis_queued_at', now()->toIso8601String());
        Queue::assertPushed(AnalyzeClientInquiryJob::class, 1);
    }

    /** Kierownik otwiera cudze zapytania, ale bez inquiries.reanalyze ich nie przelicza — ani gotowych, ani nieudanych. */
    public function test_viewing_others_does_not_allow_reanalysis(): void
    {
        Queue::fake();
        $author = User::factory()->withRole('handlowiec')->create();
        $done = $this->pendingInquiry($author, ClientInquiry::ANALYSIS_DONE);
        $failed = $this->pendingInquiry($author, ClientInquiry::ANALYSIS_FAILED);
        Sanctum::actingAs(User::factory()->withRole('kierownik')->create());

        $this->getJson("/api/inquiries/{$done->id}")->assertOk()->assertJsonPath('can_reanalyze', false);
        $this->getJson("/api/inquiries/{$failed->id}")->assertOk()->assertJsonPath('can_retry_analysis', false);
        $this->postJson("/api/inquiries/{$done->id}/retry-analysis")->assertForbidden();
        $this->postJson("/api/inquiries/{$failed->id}/retry-analysis")->assertForbidden();
        Queue::assertNothingPushed();
    }

    /**
     * Oglądający cudze zapytanie widzi list w innym szablonie, ale nic się nie zapisuje — autor i Thunderbird
     * dalej dostają list w szablonie autora (02.10.2026).
     */
    public function test_viewer_previews_letter_in_another_template_without_saving(): void
    {
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->andReturn([
                'subject' => 'Rękawice',
                'questions' => [],
                'product_queries' => [],
                'line_items' => [
                    ['id' => 'item_1', 'quote' => 'Rękawice robocze R1 - 10 par', 'qty' => '10', 'unit' => 'par', 'query' => 'rękawice robocze R1', 'size' => null],
                ],
                'cards' => [],
            ]);
        });
        $this->emptySearch();
        $author = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($author);
        $id = (int) $this->postJson('/api/inquiries', ['body' => self::BODY, 'tone' => 'handlowy'])->assertCreated()->json('id');
        $before = ClientInquiry::query()->findOrFail($id);
        $this->assertStringContainsString('przesyłamy ofertę do zapytania', (string) $before->reply_body);

        Sanctum::actingAs(User::factory()->withRole('kierownik')->create());
        $this->getJson("/api/inquiries/{$id}/reply-preview?tone=formal")
            ->assertOk()
            ->assertJsonPath('tone', 'formal')
            ->assertJsonPath('body', fn (string $body): bool => str_contains($body, 'w odpowiedzi na przesłane zapytanie przedstawiamy ofertę'));
        $this->getJson("/api/inquiries/{$id}/reply-preview?tone=nieznany")->assertStatus(422);

        $after = ClientInquiry::query()->findOrFail($id);
        $this->assertSame('handlowy', $after->tone);
        $this->assertSame($before->reply_body, $after->reply_body);
        $this->assertSame($before->reply_html, $after->reply_html);

        // bez otwierania cudzych — ani podglądu
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson("/api/inquiries/{$id}/reply-preview?tone=formal")->assertForbidden();
    }

    /** Worker zabity w trakcie: „running” bez końca po 25 min to przebieg przerwany — do ponowienia. */
    public function test_running_analysis_without_end_after_25_minutes_is_shown_as_interrupted(): void
    {
        Queue::fake();
        $user = User::factory()->withRole('handlowiec')->create();
        $inquiry = $this->pendingInquiry($user, ClientInquiry::ANALYSIS_RUNNING, now()->subMinutes(26));
        Sanctum::actingAs($user);

        $this->getJson("/api/inquiries/{$inquiry->id}")
            ->assertOk()
            ->assertJsonPath('analysis_status', 'failed')
            ->assertJsonPath('can_retry_analysis', true);
        $this->postJson("/api/inquiries/{$inquiry->id}/retry-analysis")->assertOk()->assertJsonPath('analysis_status', 'queued');
        Queue::assertPushed(AnalyzeClientInquiryJob::class, 1);

        $fresh = $this->pendingInquiry($user, ClientInquiry::ANALYSIS_RUNNING, now()->subMinutes(5));
        $this->getJson("/api/inquiries/{$fresh->id}")->assertOk()->assertJsonPath('analysis_status', 'running');
        $this->postJson("/api/inquiries/{$fresh->id}/retry-analysis")->assertStatus(409);
    }

    /** Spóźniony przebieg (zadanie dostarczone po ponowieniu) nie nadpisuje nowszego — ani wynikiem, ani błędem. */
    public function test_stale_run_does_not_touch_the_current_one(): void
    {
        $this->mock(OpenAiCompatibleClient::class, fn ($mock) => $mock->shouldNotReceive('chatJson'));
        $user = User::factory()->withRole('handlowiec')->create();
        $inquiry = $this->pendingInquiry($user, ClientInquiry::ANALYSIS_QUEUED);
        $service = app(ClientInquiryService::class);

        $service->runQueuedAnalysis((int) $inquiry->id, 'stary-przebieg');
        (new AnalyzeClientInquiryJob((int) $inquiry->id, 'stary-przebieg'))->failed(new RuntimeException('timeout'));

        $inquiry->refresh();
        $this->assertSame(ClientInquiry::ANALYSIS_QUEUED, $inquiry->analysis_status);
        $this->assertNull($inquiry->analysis_error);

        // zapytanie skasowane przed startem zadania — przebieg po cichu nic nie robi
        $inquiry->delete();
        $service->runQueuedAnalysis((int) $inquiry->id, (string) $inquiry->analysis_run_id);
        $this->assertNull(ClientInquiry::query()->find($inquiry->id));
    }

    public function test_job_timeout_marks_the_current_run_as_failed(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $inquiry = $this->pendingInquiry($user, ClientInquiry::ANALYSIS_RUNNING, now()->subMinutes(3));

        (new AnalyzeClientInquiryJob((int) $inquiry->id, (string) $inquiry->analysis_run_id))
            ->failed(new RuntimeException('has timed out'));

        $inquiry->refresh();
        $this->assertSame(ClientInquiry::ANALYSIS_FAILED, $inquiry->analysis_status);
        $this->assertStringContainsString('przerwana', (string) $inquiry->analysis_error);
    }

    /** Postęp szukania trafia do zapytania w trakcie przebiegu (etap, gotowe, wszystkie, liczba pozycji). */
    public function test_search_progress_is_written_while_the_analysis_runs(): void
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
        $seen = null;
        $this->mock(ProductInquirySearch::class, function ($mock) use (&$seen): void {
            $mock->shouldReceive('findMany')->andReturnUsing(function (array $queries, int $limit, ?callable $onProgress) use (&$seen): array {
                $this->assertNotNull($onProgress);
                $onProgress('rank', 1, 2);
                $seen = ClientInquiry::query()->latest('id')->firstOrFail()->analysis_progress;

                return array_map(static fn (string $q): array => ['query' => $q, 'products' => []], $queries);
            });
        });
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->postJson('/api/inquiries', ['body' => self::BODY, 'tone' => 'handlowy'])
            ->assertCreated()
            ->assertJsonPath('analysis_status', 'done')
            ->assertJsonPath('analysis_progress', null);
        $this->assertSame(['stage' => 'rank', 'done' => 1, 'total' => 2, 'items' => 2], $seen);
    }

    public function test_rematch_skips_inquiry_still_being_analyzed(): void
    {
        $inquiry = $this->pendingInquiry(User::factory()->withRole('handlowiec')->create(), ClientInquiry::ANALYSIS_RUNNING, now());
        $inquiry->forceFill(['analysis' => ['product_queries' => ['rękawice'], 'line_items' => []]])->save();

        $report = app(ClientInquiryService::class)->rematch($inquiry, true);

        $this->assertSame('analiza zapytania jeszcze trwa albo się nie powiodła', $report['skipped']);
    }

    public function test_admin_sets_the_line_item_limit_within_range(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->getJson('/api/admin/ai-tuning')
            ->assertOk()
            ->assertJsonPath('inquiry_max_items', 50)
            ->assertJsonPath('inquiry_max_items_default', 50)
            ->assertJsonPath('inquiry_max_items_max', 60);
        $limit = $this->getJson('/api/admin/ai-tuning')->json('catalog_search_limit');
        $this->putJson('/api/admin/ai-tuning', ['catalog_search_limit' => $limit, 'inquiry_max_items' => 61])->assertStatus(422);
        $this->putJson('/api/admin/ai-tuning', ['catalog_search_limit' => $limit, 'inquiry_max_items' => 0])->assertStatus(422);
        $this->putJson('/api/admin/ai-tuning', ['catalog_search_limit' => $limit, 'inquiry_max_items' => 30])
            ->assertOk()
            ->assertJsonPath('inquiry_max_items', 30);
        // pominięte pole zostaje bez zmian
        $this->putJson('/api/admin/ai-tuning', ['catalog_search_limit' => $limit])->assertOk()->assertJsonPath('inquiry_max_items', 30);
    }

    private function pendingInquiry(User $user, string $status, mixed $startedAt = null): ClientInquiry
    {
        return ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'handlowy',
            'source_channel' => 'web',
            'source_body' => self::BODY,
            'analysis_status' => $status,
            'analysis_run_id' => (string) Str::ulid(),
            'analysis_started_at' => $startedAt,
        ]);
    }

    private function emptySearch(): void
    {
        $this->mock(ProductInquirySearch::class, function ($mock): void {
            $mock->shouldReceive('findMany')->andReturnUsing(
                static fn (array $queries): array => array_map(static fn (string $q): array => ['query' => $q, 'products' => []], $queries),
            );
        });
    }
}
