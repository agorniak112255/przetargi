<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\Client;
use App\Models\ErpSaleDocument;
use App\Support\ClarionDate;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Faktury i paragony klientów z zakładki Klienci (clients.xl_gid) z Comarch ERP XL do erp_sale_documents — nagłówki
 * FS, PA, FSE i korekty FS/PA (wartość ze znakiem), zatwierdzone, z okna `months` pełnych miesięcy wstecz (od 1. dnia
 * miesiąca). Faktura do WZ (bez własnych pozycji w XL) ma wartość z pozycji swoich WZ; WZ bez zatwierdzonej faktury
 * jest osobnym dokumentem z datą WZ, dopóki faktura nie powstanie (ErpXlGateway::customerDocuments). Jedno źródło
 * sprzedaży dla karty klienta, celów handlowców i podpowiedzi zamówień.
 *
 * Pamięć (CLI 128 MB): kontrahenci paczkami po 500 do XL, dokumenty strumieniem (kursor), zapis paczkami po 1000
 * (upsert po typie i numerze dokumentu XL), bez dziennika zapytań. W pamięci zostaje tylko mapa numer XL → klient
 * i suma bieżącego roku na klienta (kontrola jakości).
 *
 * Nieaktualne wiersze (dokument anulowany albo cofnięty do bufora, klient bez numeru XL, dokument spoza okna) kasujemy
 * dopiero po udanym przebiegu — przerwany odczyt XL nie zostawia karty bez faktur.
 *
 * Kontrola jakości: suma dokumentów bieżącego roku na klienta porównana z clients.sales_net (erp:clients, ta sama
 * reguła XL) — różnica u kogokolwiek trafia do wyniku polecenia, nic nie jest poprawiane. Druga kontrola: WZ i korekty
 * z okna, których reguła WZ nie bierze (ErpXlGateway::deliveryCheck) — podejrzane trafiają też do dziennika.
 */
final class ErpClientDocumentSync
{
    /** Kiedy ostatnio udał się odczyt dokumentów (ISO) — „stan na” i komunikat „faktury pojawią się po nocnym odczycie”. */
    public const SYNCED_AT_CACHE_KEY = 'erp.client_documents.synced_at';

    /** Najkrótsze okno: karta klienta pokazuje 24 miesiące (krótsze skasowałoby historię widoczną na karcie). */
    public const MIN_MONTHS = 24;

    public const MAX_MONTHS = 120;

    private const CUSTOMER_CHUNK = 500;

    private const UPSERT_CHUNK = 1000;

    /** Tolerancja kontroli jakości (zaokrąglenia groszy przy sumowaniu pozycji). */
    private const QUALITY_TOLERANCE = 0.05;

    /** Ile przykładów różnic pokazać w wyniku polecenia. */
    private const QUALITY_EXAMPLES = 5;

    public function __construct(private readonly ErpXlGateway $gateway) {}

    /**
     * @return array{
     *     clients: int,
     *     from: string,
     *     documents: int,
     *     skipped: int,
     *     removed: int,
     *     quality: array{year: int, checked: int, mismatched: int, examples: list<array{client_id: int, name: string, clients_net: float, documents_net: float}>},
     *     deliveries: null|array{invoice_with_lines: array{documents: int, net: float}, invoice_lines_mode: array{documents: int, net: float}, unknown_link: array{documents: int, net: float}, correction_of_lineless: array{documents: int, net: float}}
     * }
     */
    public function run(int $months = 36, bool $dryRun = false): array
    {
        if (! $this->gateway->configured()) {
            throw new RuntimeException('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*).');
        }
        if ($months < self::MIN_MONTHS || $months > self::MAX_MONTHS) {
            throw new RuntimeException(sprintf('Okno dokumentów musi mieć od %d do %d miesięcy.', self::MIN_MONTHS, self::MAX_MONTHS));
        }

        DB::disableQueryLog();
        // bez ułamków sekund — synced_at zapisany z dokładnością do sekundy nie może wyjść „starszy” niż start
        $startedAt = CarbonImmutable::now()->startOfSecond();
        $today = PolishTime::today();
        $from = $today->startOfMonth()->subMonthsNoOverflow($months);
        $fromDate = $from->toDateString();
        $year = (int) $today->year;

        /** @var array<int, int> $clientByGid numer kontrahenta XL → id klienta */
        $clientByGid = Client::query()->whereNotNull('xl_gid')->pluck('id', 'xl_gid')
            ->mapWithKeys(static fn (mixed $id, mixed $gid): array => [(int) $gid => (int) $id])
            ->all();
        $gids = array_keys($clientByGid);
        sort($gids);

        $documents = 0;
        $skipped = 0;
        /** @var array<int, float> $yearNet suma dokumentów bieżącego roku na klienta */
        $yearNet = [];
        $buffer = [];
        foreach (array_chunk($gids, self::CUSTOMER_CHUNK) as $chunk) {
            foreach ($this->gateway->customerDocuments($chunk, ClarionDate::fromDate($from)) as $row) {
                $issued = ClarionDate::toDate($row['date']);
                $kind = ErpSaleDocument::TYPE_KIND[$row['document_type']] ?? null;
                $clientId = $clientByGid[$row['customer_gid']] ?? null;
                if ($issued === null || $kind === null || $clientId === null || $issued->toDateString() < $fromDate) {
                    $skipped++;

                    continue;
                }
                $net = round((float) $row['net_value'], 2);
                if ((int) $issued->year === $year) {
                    $yearNet[$clientId] = ($yearNet[$clientId] ?? 0.0) + $net;
                }
                $documents++;
                if ($dryRun) {
                    continue;
                }
                $buffer[] = [
                    'document_type' => $row['document_type'],
                    'document_id' => $row['document_id'],
                    'document_number' => mb_substr($row['document_number'], 0, 40),
                    'kind' => $kind,
                    'issued_at' => $issued->toDateString(),
                    'customer_xl_gid' => $row['customer_gid'],
                    'client_id' => $clientId,
                    'net_value' => $net,
                    'synced_at' => $startedAt,
                    'created_at' => $startedAt,
                    'updated_at' => $startedAt,
                ];
                if (count($buffer) >= self::UPSERT_CHUNK) {
                    $this->flush($buffer);
                    $buffer = [];
                }
            }
        }
        $this->flush($buffer);
        unset($buffer);

        $removed = 0;
        if (! $dryRun) {
            // dopiero po pełnym odczycie: czego XL tym razem nie zwrócił (anulowany, w buforze, klient bez numeru XL)
            // i co wypadło z okna
            $removed = ErpSaleDocument::query()
                ->where(static function ($q) use ($startedAt, $fromDate): void {
                    $q->whereNull('synced_at')
                        ->orWhere('synced_at', '<', $startedAt)
                        ->orWhere('issued_at', '<', $fromDate);
                })
                ->delete();
            Cache::forever(self::SYNCED_AT_CACHE_KEY, $startedAt->toIso8601String());
        }

        // WZ i korekty, których reguła nie bierze — spinacz −2033 to tryb „faktura z pozycjami” (spodziewany). Do
        // dziennika tylko to, co psuje kwoty: WZ pod fakturą z własnymi pozycjami albo nieznany spinacz. Korekta
        // z pozycjami do dokumentu bez pozycji (06.10.2026: jedna FSK do FSK, −6 602,85 zł) psuje tylko powiązanie
        // korekty w kampanii — w wyniku polecenia, bez ostrzeżenia co noc przez całe okno
        // (sama kontrola; jej błąd nie cofa udanego odczytu — trafia do raportu błędów, wynik null)
        try {
            $deliveries = $this->gateway->deliveryCheck(ClarionDate::fromDate($from), ClarionDate::fromDate($today));
        } catch (Throwable $e) {
            report($e);
            $deliveries = null;
        }
        $suspicious = $deliveries === null ? [] : array_filter(
            array_intersect_key($deliveries, array_flip(['invoice_with_lines', 'unknown_link'])),
            static fn (array $c): bool => $c['documents'] > 0,
        );
        if ($suspicious !== []) {
            Log::warning('erp:client-documents: WZ albo korekty poza regułą sprzedaży z WZ', ['from' => $fromDate, ...$suspicious]);
        }

        return [
            'clients' => count($gids),
            'from' => $fromDate,
            'documents' => $documents,
            'skipped' => $skipped,
            'removed' => $removed,
            'quality' => $this->quality($year, $yearNet),
            'deliveries' => $deliveries,
        ];
    }

    /**
     * Kiedy ostatnio udał się odczyt (pamięć podręczna, a gdy ją wyczyszczono — najnowszy zapis w tabeli); null =
     * jeszcze nigdy (faktury pojawią się po nocnym odczycie).
     */
    public static function syncedAt(): ?CarbonImmutable
    {
        $cached = Cache::get(self::SYNCED_AT_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            try {
                return CarbonImmutable::parse($cached);
            } catch (Throwable) {
                // uszkodzony wpis — sprawdzamy tabelę
            }
        }
        $latest = ErpSaleDocument::query()->max('synced_at');

        return is_string($latest) && $latest !== '' ? CarbonImmutable::parse($latest, (string) config('app.timezone', 'UTC')) : null;
    }

    /**
     * Suma bieżącego roku z dokumentów vs clients.sales_net — tylko klienci, których erp:clients policzył za ten rok.
     *
     * @param  array<int, float>  $yearNet
     * @return array{year: int, checked: int, mismatched: int, examples: list<array{client_id: int, name: string, clients_net: float, documents_net: float}>}
     */
    private function quality(int $year, array $yearNet): array
    {
        $checked = 0;
        $mismatched = 0;
        $examples = [];
        $query = Client::query()->whereNotNull('xl_gid')->where('sales_year', $year)->select(['id', 'name', 'sales_net']);
        foreach ($query->lazyById(500) as $client) {
            /** @var Client $client */
            $checked++;
            $expected = round((float) $client->sales_net, 2);
            $actual = round($yearNet[(int) $client->id] ?? 0.0, 2);
            if (abs($expected - $actual) <= self::QUALITY_TOLERANCE) {
                continue;
            }
            $mismatched++;
            if (count($examples) < self::QUALITY_EXAMPLES) {
                $examples[] = ['client_id' => (int) $client->id, 'name' => (string) $client->name, 'clients_net' => $expected, 'documents_net' => $actual];
            }
        }

        return ['year' => $year, 'checked' => $checked, 'mismatched' => $mismatched, 'examples' => $examples];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function flush(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        DB::table('erp_sale_documents')->upsert($rows, ['document_type', 'document_id'], [
            'document_number', 'kind', 'issued_at', 'customer_xl_gid', 'client_id', 'net_value', 'synced_at', 'updated_at',
        ]);
    }
}
