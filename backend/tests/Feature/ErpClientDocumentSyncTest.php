<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ErpSaleDocument;
use App\Services\Erp\ErpClientDocumentSync;
use App\Services\Erp\ErpXlGateway;
use App\Support\ClarionDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\Support\FakeErpXlGateway;
use Tests\TestCase;

/**
 * erp:client-documents: nagłówki FS/PA/FSE i korekt (ze znakiem) klientów z numerem XL, okno pełnych miesięcy,
 * client_id po numerze XL, kasowanie nieaktualnych tylko po udanym przebiegu, zapis paczkami (bez zbierania całości),
 * kontrola jakości z zakładką Klienci, WZ bez faktury jako dokument i kontrola reguły WZ.
 */
final class ErpClientDocumentSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeErpXlGateway $xl;

    protected function setUp(): void
    {
        parent::setUp();
        // 2 października 2026 w Polsce — okno 36 miesięcy od 1 października 2023
        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Europe/Warsaw'));
        $this->xl = new FakeErpXlGateway;
        $this->app->instance(ErpXlGateway::class, $this->xl);
    }

    public function test_copies_documents_of_clients_with_xl_number_with_signed_corrections_and_window(): void
    {
        $acme = $this->client('ACME', 10, ['sales_year' => 2026, 'sales_net' => 900]);
        $beta = $this->client('BETA', 20, ['sales_year' => 2026, 'sales_net' => 50]);
        Client::query()->create(['name' => 'Ręczny bez XL']);

        $this->xl->documentRows = [
            FakeErpXlGateway::saleDocument(1, $this->d('2026-09-15'), 10, 1000.00),
            FakeErpXlGateway::saleDocument(2, $this->d('2026-05-01'), 10, 200.00, 2034),
            // korekta faktury — wartość ujemna pomniejsza zakupy
            FakeErpXlGateway::saleDocument(3, $this->d('2026-09-20'), 10, -300.00, 2041),
            FakeErpXlGateway::saleDocument(4, $this->d('2025-12-31'), 20, 80.00, 2037),
            // pierwszy dzień okna i dzień przed nim
            FakeErpXlGateway::saleDocument(5, $this->d('2023-10-01'), 20, 10.00),
            FakeErpXlGateway::saleDocument(6, $this->d('2023-09-30'), 20, 11.00),
            // rodzaj spoza listy (2036 — faktura wewnętrzna serii 01K, nie sprzedaż) — pomijany
            FakeErpXlGateway::saleDocument(7, $this->d('2026-09-01'), 20, 12.00, 2036),
        ];

        $this->artisan('erp:client-documents')
            ->expectsOutputToContain('Klienci z numerem XL: 2, dokumenty od 2023-10-01: 5, pominięte (nieznany rodzaj, data albo kontrahent): 1, usunięte nieaktualne: 0.')
            ->expectsOutputToContain('sprawdzonych klientów 2, różnica u 1 klientów')
            ->expectsOutputToContain('BETA (klient #'.$beta->id.'): zakładka Klienci 50,00 zł, dokumenty 0,00 zł.')
            ->assertSuccessful();

        // tylko klienci z numerem XL, okno od 1. dnia miesiąca 36 miesięcy wstecz
        $this->assertSame([['gids' => [10, 20], 'from' => $this->d('2023-10-01')]], $this->xl->documentCalls);

        $rows = ErpSaleDocument::query()->orderBy('document_id')->get()->keyBy('document_id');
        $this->assertSame([1, 2, 3, 4, 5], $rows->keys()->all());
        $this->assertSame(['invoice', 'receipt', 'invoice_correction', 'export_invoice', 'invoice'], $rows->pluck('kind')->values()->all());
        $this->assertSame('-300.00', $rows[3]->net_value);
        $this->assertSame('2026-09-20', $rows[3]->issued_at->format('Y-m-d'));
        $this->assertSame($acme->id, $rows[1]->client_id);
        $this->assertSame($beta->id, $rows[4]->client_id);
        $this->assertSame('FSK-01H/3/26/09', $rows[3]->document_number);
        $this->assertNotNull(Cache::get(ErpClientDocumentSync::SYNCED_AT_CACHE_KEY));
    }

    public function test_delivery_note_without_invoice_is_a_sale_until_the_invoice_replaces_it(): void
    {
        $acme = $this->client('ACME', 10);
        // WZ i WZK bez zatwierdzonej faktury — liczą się od dnia wydania (decyzja właściciela 06.10.2026)
        $this->xl->documentRows = [
            FakeErpXlGateway::saleDocument(31, $this->d('2026-09-28'), 10, 500.00, 2001),
            FakeErpXlGateway::saleDocument(32, $this->d('2026-09-29'), 10, -50.00, 2009),
        ];
        $this->artisan('erp:client-documents')->assertSuccessful();
        $rows = ErpSaleDocument::query()->orderBy('document_id')->get();
        $this->assertSame([['delivery_note', 'WZ-01H/31/26/09', '500.00'], ['delivery_correction', 'WZK-01H/32/26/09', '-50.00']], $rows->map(
            static fn (ErpSaleDocument $d): array => [$d->kind, $d->document_number, $d->net_value],
        )->all());
        $this->assertContains(ErpSaleDocument::KIND_DELIVERY_NOTE, ErpSaleDocument::SALE_KINDS);

        // następnej nocy jest faktura do tej WZ: XL zwraca fakturę z wartością WZ, WZ znika z kopii
        $this->travel(1)->days();
        $this->xl->documentRows = [FakeErpXlGateway::saleDocument(77, $this->d('2026-09-30'), 10, 450.00)];
        $this->artisan('erp:client-documents')->assertSuccessful();
        $this->assertSame([[77, 'invoice', '450.00', $acme->id]], ErpSaleDocument::query()->get()->map(
            static fn (ErpSaleDocument $d): array => [$d->document_id, $d->kind, $d->net_value, $d->client_id],
        )->all());
    }

    public function test_delivery_rule_check_is_reported_and_suspicious_cases_logged(): void
    {
        $this->client('ACME', 10);
        Log::spy();
        $this->xl->deliveryCheckResult = [
            'invoice_lines_mode' => ['documents' => 118, 'net' => 550111.17],
            'invoice_with_lines' => ['documents' => 2, 'net' => 1500.0],
            'correction_of_lineless' => ['documents' => 1, 'net' => -6602.85],
        ];

        $this->artisan('erp:client-documents')
            ->expectsOutputToContain('WZ z fakturą z własnymi pozycjami (spinacz −2033, liczone z faktury): 118 WZ, 550 111,17 zł.')
            ->expectsOutputToContain('Poza regułą WZ: faktura w spinaczu z pozycjami 2 (1 500,00 zł), inny spinacz 0 (0,00 zł), korekta z pozycjami do dokumentu bez pozycji 1 (-6 602,85 zł).')
            ->assertSuccessful();
        // okres kontroli = okno dokumentów do dziś
        $this->assertSame([[$this->d('2023-10-01'), $this->d('2026-10-02')]], $this->xl->deliveryCheckCalls);
        // do dziennika tylko to, co psuje kwoty (korekta do dokumentu bez pozycji psuje tylko powiązanie w kampanii)
        Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'poza regułą')
            && array_keys($context) === ['from', 'invoice_with_lines']);

        // tryb −2033 i sama korekta do dokumentu bez pozycji — bez nowego ostrzeżenia (szpieg ten sam, więc dalej jedno)
        $this->xl->deliveryCheckResult = [
            'invoice_lines_mode' => ['documents' => 5, 'net' => 10.0],
            'correction_of_lineless' => ['documents' => 1, 'net' => -6602.85],
        ];
        $this->artisan('erp:client-documents')
            ->expectsOutputToContain('korekta z pozycjami do dokumentu bez pozycji 1 (-6 602,85 zł)')
            ->assertSuccessful();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_failed_delivery_check_does_not_undo_a_successful_read(): void
    {
        $this->client('ACME', 10);
        $failing = Mockery::mock(ErpXlGateway::class);
        $failing->shouldReceive('configured')->andReturnTrue();
        $failing->shouldReceive('customerDocuments')->andReturn([FakeErpXlGateway::saleDocument(1, $this->d('2026-09-15'), 10, 100.00)]);
        $failing->shouldReceive('deliveryCheck')->andThrow(new RuntimeException('Timeout'));
        $this->app->instance(ErpXlGateway::class, $failing);

        $this->artisan('erp:client-documents')
            ->expectsOutputToContain('Kontrola reguły WZ nieudana (szczegóły w dzienniku błędów) — dokumenty zapisane.')
            ->assertSuccessful();
        $this->assertSame(1, ErpSaleDocument::query()->count());
        $this->assertNotNull(Cache::get(ErpClientDocumentSync::SYNCED_AT_CACHE_KEY));
    }

    public function test_stale_rows_are_removed_only_after_a_successful_run(): void
    {
        $this->client('ACME', 10);
        $this->xl->documentRows = [
            FakeErpXlGateway::saleDocument(1, $this->d('2026-09-15'), 10, 100.00),
            FakeErpXlGateway::saleDocument(2, $this->d('2026-09-16'), 10, 200.00),
        ];
        $this->artisan('erp:client-documents')->assertSuccessful();
        // wiersz sprzed okna (np. po zmianie okna) — też znika po udanym przebiegu
        DB::table('erp_sale_documents')->insert([
            'document_type' => 2033, 'document_id' => 99, 'document_number' => 'FS-STARY', 'kind' => 'invoice',
            'issued_at' => '2020-01-10', 'customer_xl_gid' => 10, 'client_id' => null, 'net_value' => 5,
            'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // dokument 2 anulowany w XL, ale odczyt pada w połowie — nic nie znika
        $this->xl->documentRows = [FakeErpXlGateway::saleDocument(1, $this->d('2026-09-15'), 10, 100.00)];
        $rows = $this->xl->documentRows;
        $failing = Mockery::mock(ErpXlGateway::class);
        $failing->shouldReceive('configured')->andReturnTrue();
        $failing->shouldReceive('customerDocuments')->andReturnUsing(static function () use ($rows): iterable {
            yield from $rows;
            throw new RuntimeException('Utracono połączenie z ERP XL');
        });
        $this->app->instance(ErpXlGateway::class, $failing);
        $this->travel(1)->days();

        $this->artisan('erp:client-documents')
            ->expectsOutputToContain('Odczyt faktur klientów z ERP XL przerwany: Utracono połączenie z ERP XL')
            ->assertFailed();
        $this->assertSame([1, 2, 99], ErpSaleDocument::query()->orderBy('document_id')->pluck('document_id')->all());

        // kolejny, udany przebieg kasuje anulowany i ten sprzed okna
        $this->app->instance(ErpXlGateway::class, $this->xl);
        $this->artisan('erp:client-documents')
            ->expectsOutputToContain('usunięte nieaktualne: 2.')
            ->assertSuccessful();
        $this->assertSame([1], ErpSaleDocument::query()->pluck('document_id')->all());
    }

    public function test_client_follows_xl_number_and_client_without_number_loses_documents(): void
    {
        $old = $this->client('STARY', 10);
        $this->xl->documentRows = [FakeErpXlGateway::saleDocument(1, $this->d('2026-09-15'), 10, 100.00)];
        $this->artisan('erp:client-documents')->assertSuccessful();
        $this->assertSame($old->id, ErpSaleDocument::query()->firstOrFail()->client_id);

        // numer XL przeszedł na innego klienta (np. scalenie) — dokument idzie za numerem
        $old->forceFill(['xl_gid' => null])->save();
        $new = $this->client('NOWY', 10);
        $this->travel(1)->minutes();
        $this->artisan('erp:client-documents')->assertSuccessful();
        $this->assertSame($new->id, ErpSaleDocument::query()->firstOrFail()->client_id);

        // klient bez numeru XL — jego dokumentów XL już nie odczytujemy, kopia znika
        $new->forceFill(['xl_gid' => null])->save();
        $this->travel(1)->minutes();
        $this->artisan('erp:client-documents')->expectsOutputToContain('Klienci z numerem XL: 0')->assertSuccessful();
        $this->assertSame(0, ErpSaleDocument::query()->count());
        // bez klientów z numerem XL nie ma o co pytać XL
        $this->assertCount(2, $this->xl->documentCalls);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->client('ACME', 10);
        $this->xl->documentRows = [FakeErpXlGateway::saleDocument(1, $this->d('2026-09-15'), 10, 100.00)];

        $this->artisan('erp:client-documents', ['--dry-run' => true])
            ->expectsOutputToContain('[bez zapisu] Klienci z numerem XL: 1, dokumenty od 2023-10-01: 1')
            ->assertSuccessful();
        $this->assertSame(0, ErpSaleDocument::query()->count());
        $this->assertNull(Cache::get(ErpClientDocumentSync::SYNCED_AT_CACHE_KEY));
    }

    public function test_xl_asked_in_batches_of_500_customers_and_rows_saved_while_streaming(): void
    {
        $rows = [];
        for ($gid = 1; $gid <= 501; $gid++) {
            $rows[] = ['name' => 'Klient '.$gid, 'source' => Client::SOURCE_ERP_XL, 'xl_gid' => $gid, 'xl_archived' => false, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('clients')->insert($rows);

        // 2500 dokumentów jednego klienta generowanych w locie; przy 1500. sprawdzamy, ile już jest w bazie
        $date = $this->d('2026-09-15');
        $calls = [];
        $savedWhileStreaming = null;
        $streaming = Mockery::mock(ErpXlGateway::class);
        $streaming->shouldReceive('configured')->andReturnTrue();
        $streaming->shouldReceive('customerDocuments')->andReturnUsing(static function (array $gids) use ($date, &$calls, &$savedWhileStreaming): iterable {
            $calls[] = count($gids);
            if (! in_array(1, $gids, true)) {
                return;
            }
            for ($i = 1; $i <= 2500; $i++) {
                if ($i === 1500) {
                    $savedWhileStreaming = DB::table('erp_sale_documents')->count();
                }
                yield FakeErpXlGateway::saleDocument($i, $date, 1, 10.00);
            }
        });
        $this->app->instance(ErpXlGateway::class, $streaming);

        $this->artisan('erp:client-documents')->assertSuccessful();

        $this->assertSame([500, 1], $calls);
        // paczka po 1000 zapisana, zanim XL skończył oddawać dokumenty — całość nie leży w pamięci
        $this->assertSame(1000, $savedWhileStreaming);
        $this->assertSame(2500, ErpSaleDocument::query()->count());
    }

    public function test_skips_without_xl_connection_and_refuses_too_short_window(): void
    {
        $this->xl->isConfigured = false;
        $this->artisan('erp:client-documents')->expectsOutputToContain('pomijam')->assertSuccessful();
        $this->assertSame([], $this->xl->documentCalls);

        $this->xl->isConfigured = true;
        // krótsze okno skasowałoby historię widoczną na karcie klienta (24 miesiące)
        $this->artisan('erp:client-documents', ['--months' => 12])->assertExitCode(2);
        $this->artisan('erp:client-documents', ['--months' => 'abc'])->assertExitCode(2);
        $this->assertSame([], $this->xl->documentCalls);
    }

    /** @param  array<string, mixed>  $extra */
    private function client(string $name, int $gid, array $extra = []): Client
    {
        return Client::query()->create(['name' => $name, 'source' => Client::SOURCE_ERP_XL, 'xl_gid' => $gid, ...$extra]);
    }

    private function d(string $date): int
    {
        return ClarionDate::fromDate(CarbonImmutable::parse($date));
    }
}
