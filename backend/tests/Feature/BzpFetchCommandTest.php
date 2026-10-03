<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProcurementNotice;
use App\Models\Tender;
use App\Models\TenderLot;
use App\Services\Bzp\BzpNoticeParser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * bzp:fetch — pobieranie z API Biuletynu (atrapa Http::fake z prawdziwymi próbkami), stronicowanie, brak
 * duplikatów (to samo ogłoszenie pod kilkoma kodami CPV i przy ponownym przebiegu), łączenie z przetargami.
 * Teraz = sobota 03.10.2026 10:00 w Warszawie.
 */
final class BzpFetchCommandTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://bzp.test/notice';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00:00', 'Europe/Warsaw'));
        Sleep::fake();
        config([
            'bzp.base_url' => self::URL,
            'bzp.notice_types' => ['ContractNotice', 'TenderResultNotice'],
            'bzp.cpv_codes' => ['18141000-9', '18830000-6'],
            'bzp.page_size' => 2,
            'bzp.pause_ms' => 0,
            'bzp.retries' => 1,
        ]);
    }

    public function test_fetch_pages_saves_each_notice_once_and_links_tenders(): void
    {
        $tender = $this->tender('2026/BZP 00439099/01', '2026-09-24');
        $this->fakeApi();

        $this->artisan('bzp:fetch')
            ->expectsOutputToContain('zapytań 6, ogłoszeń w odpowiedziach 6, zapisanych 5, bez zmian 1, nieczytelnych 0')
            ->expectsOutputToContain('Przetargi z numerem ogłoszenia Biuletynu: 1, z odnalezionym ogłoszeniem: 1, uzupełnione części: 1')
            ->assertSuccessful();

        // 2 strony × ContractNotice/18141000-9, 1 × ContractNotice/18830000-6, 2 × wynik/18141000-9, 1 × wynik/18830000-6
        Http::assertSentCount(6);
        Http::assertSent(static fn (Request $request): bool => $request['NoticeType'] === 'ContractNotice'
            && $request['CpvCode'] === '18141000-9'
            && $request['PageSize'] === 2
            && $request['PageNumber'] === 2
            // --days=7 z dzisiejszym, w czasie polskim
            && $request['PublicationDateFrom'] === '2026-09-27'
            && $request['PublicationDateTo'] === '2026-10-03');

        $this->assertSame([
            '2026/BZP 00416394/01',
            '2026/BZP 00439099/01',
            '2026/BZP 00449679/01',
            '2026/BZP 00453849/01',
            '2026/BZP 00466799/01',
        ], ProcurementNotice::query()->orderBy('notice_number')->pluck('notice_number')->all());
        $notice = ProcurementNotice::query()->where('notice_number', '2026/BZP 00466799/01')->firstOrFail();
        $this->assertSame('2026/BZP 00439099', $notice->preceding_bzp_number);
        $this->assertSame(BzpNoticeParser::VERSION, $notice->parser_version);
        $this->assertNotEmpty($notice->getRawOriginal('html_body'));
        $this->assertNotNull($notice->fetched_at);

        // przetarg z numerem ogłoszenia o zamówieniu → ogłoszenie o wyniku (unieważnienie całości)
        $tender->refresh();
        $this->assertSame($notice->id, $tender->result_notice_id);
        $this->assertNotNull($tender->contract_notice_id);
        $this->assertSame('cancelled', $tender->result_status);
        // godzina z ogłoszenia o zamówieniu: 07:00 UTC = 09:00 w Polsce, ten sam dzień co termin w przetargu
        $this->assertSame('09:00', $tender->deadline_time);
        $this->assertSame(['cancelled'], TenderLot::query()->where('tender_id', $tender->id)->pluck('outcome')->all());
    }

    public function test_second_run_skips_notices_already_saved(): void
    {
        $this->fakeApi();
        $this->artisan('bzp:fetch')->assertSuccessful();
        $updatedAt = ProcurementNotice::query()->pluck('updated_at', 'notice_number')->map(static fn ($d): string => (string) $d)->all();

        $this->travel(1)->hours();
        $this->artisan('bzp:fetch')
            ->expectsOutputToContain('zapisanych 0, bez zmian 6')
            ->assertSuccessful();

        $this->assertSame(5, ProcurementNotice::query()->count());
        $this->assertSame($updatedAt, ProcurementNotice::query()->pluck('updated_at', 'notice_number')->map(static fn ($d): string => (string) $d)->all());
    }

    public function test_failed_request_does_not_stop_other_codes_and_ends_with_failure(): void
    {
        Http::fake(static function (Request $request) {
            if ($request['CpvCode'] === '18141000-9') {
                return Http::response('błąd', 503);
            }

            return Http::response($request['NoticeType'] === 'TenderResultNotice' ? [self::fixture('result-single-awarded.json')] : []);
        });

        $this->artisan('bzp:fetch')
            ->expectsOutputToContain('zapisanych 1')
            ->expectsOutputToContain('Nieudane zapytania: 2')
            ->assertFailed();

        $this->assertSame(['2026/BZP 00453849/01'], ProcurementNotice::query()->pluck('notice_number')->all());
    }

    /**
     * Produkcja 03.10.2026: przy serii zapytań Biuletyn chwilowo odpowiadał 403, a to samo zapytanie chwilę później
     * przechodziło. Chwilowe ograniczenie (403/429) jest ponawiane po dłuższej przerwie, a nie liczone jako błąd.
     */
    public function test_temporary_throttling_is_retried_after_a_longer_pause(): void
    {
        $calls = [];
        Http::fake(static function (Request $request) use (&$calls) {
            $key = $request['NoticeType'].' '.$request['CpvCode'];
            $calls[$key] = ($calls[$key] ?? 0) + 1;
            if ($request['CpvCode'] === '18141000-9' && $request['NoticeType'] === 'TenderResultNotice' && $calls[$key] === 1) {
                return Http::response('', 403);
            }

            return Http::response($request['NoticeType'] === 'TenderResultNotice' && $request['CpvCode'] === '18141000-9'
                ? [self::fixture('result-single-awarded.json')]
                : []);
        });

        $this->artisan('bzp:fetch')
            ->expectsOutputToContain('zapisanych 1')
            ->assertSuccessful();

        $this->assertSame(2, $calls['TenderResultNotice 18141000-9']);
        Sleep::assertSlept(static fn ($duration): bool => (int) $duration->totalMilliseconds === 20000, 1);
        Http::assertSent(static fn (Request $request): bool => str_starts_with($request->header('User-Agent')[0] ?? '', 'PrzetargiSupon/'));
    }

    public function test_throttling_that_does_not_stop_ends_as_a_failed_request(): void
    {
        Http::fake(static function (Request $request) {
            if ($request['CpvCode'] === '18141000-9') {
                return Http::response('', 429);
            }

            return Http::response([]);
        });

        $this->artisan('bzp:fetch')
            ->expectsOutputToContain('Nieudane zapytania: 2')
            ->assertFailed();
    }

    public function test_date_options(): void
    {
        Http::fake(static fn () => Http::response([]));

        $this->artisan('bzp:fetch', ['--from' => '2026-09-01', '--to' => '2026-09-15'])->assertSuccessful();
        Http::assertSent(static fn (Request $request): bool => $request['PublicationDateFrom'] === '2026-09-01'
            && $request['PublicationDateTo'] === '2026-09-15');

        $this->artisan('bzp:fetch', ['--from' => '2026-13-01'])->assertExitCode(2);
        $this->artisan('bzp:fetch', ['--from' => '2026-09-20', '--to' => '2026-09-10'])->assertExitCode(2);
        $this->artisan('bzp:fetch', ['--days' => '0'])->assertExitCode(2);
    }

    public function test_reparse_reads_stored_notices_without_requests(): void
    {
        $this->fakeApi();
        $this->artisan('bzp:fetch')->assertSuccessful();
        ProcurementNotice::query()->update(['parser_version' => 0, 'parsed' => null, 'preceding_bzp_number' => null]);
        ProcurementNotice::query()->where('notice_number', '2026/BZP 00416394/01')->update(['html_body' => null]);
        Http::fake();

        $this->artisan('bzp:fetch', ['--reparse' => true])
            ->expectsOutputToContain('Odczytano od nowa: 4, błędy: 0, bez pełnej treści (nie da się odczytać): 1.')
            ->assertSuccessful();

        Http::assertNothingSent();
        $notice = ProcurementNotice::query()->where('notice_number', '2026/BZP 00466799/01')->firstOrFail();
        $this->assertSame(BzpNoticeParser::VERSION, $notice->parser_version);
        $this->assertSame('2026/BZP 00439099', $notice->preceding_bzp_number);
        $this->assertSame('cancelled', $notice->parsed['lots'][0]['result']);
        $this->assertSame(0, ProcurementNotice::query()->where('notice_number', '2026/BZP 00416394/01')->value('parser_version'));
    }

    public function test_linking_error_ends_with_failure_after_other_tenders(): void
    {
        $this->fakeApi();
        $this->artisan('bzp:fetch')->assertSuccessful();
        Exceptions::fake();
        $broken = $this->tender('2026/BZP 00439099/01', '2026-09-24');
        $fine = $this->tender('2026/BZP 00376786', '2026-08-20');
        TenderLot::saving(static function (TenderLot $lot) use ($broken): void {
            if ((int) $lot->tender_id === (int) $broken->id) {
                throw new RuntimeException('Błąd zapisu części');
            }
        });

        $this->artisan('bzp:fetch', ['--reparse' => true])
            ->expectsOutputToContain('Nie udało się połączyć z ogłoszeniami przetargów: 1')
            ->assertFailed();

        Exceptions::assertReported(RuntimeException::class);
        $this->assertSame(1, TenderLot::query()->where('tender_id', $fine->id)->count());
    }

    /**
     * Atrapa API: ogłoszenia o zamówieniu pod dwoma kodami (contract-single pod oboma — duplikat), wyniki
     * na dwóch stronach.
     */
    private function fakeApi(): void
    {
        $pages = [
            'ContractNotice|18141000-9|1' => ['contract-lots.json', 'contract-single.json'],
            'ContractNotice|18141000-9|2' => [],
            'ContractNotice|18830000-6|1' => ['contract-single.json'],
            'TenderResultNotice|18141000-9|1' => ['result-lots-cancelled-and-awarded.json', 'result-single-cancelled.json'],
            'TenderResultNotice|18141000-9|2' => ['result-single-awarded.json'],
            'TenderResultNotice|18830000-6|1' => [],
        ];
        Http::fake(static function (Request $request) use ($pages) {
            $key = $request['NoticeType'].'|'.$request['CpvCode'].'|'.$request['PageNumber'];

            return Http::response(array_map(static fn (string $file): array => self::fixture($file), $pages[$key] ?? []));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/bzp/'.$name)), true);
    }

    private function tender(string $notice, string $deadline): Tender
    {
        return Tender::query()->create([
            'number' => 'PRZ/2026/'.random_int(1000, 9999),
            'title' => 'Środki ochrony indywidualnej',
            'client_id' => Client::query()->create(['name' => 'Starostwo'])->id,
            'status' => 'exported',
            'ai_percent' => 0,
            'deadline' => $deadline,
            'notice_number' => $notice,
        ]);
    }
}
