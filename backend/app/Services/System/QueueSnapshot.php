<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Services\B2b\B2bConnectorRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * „Kolejki teraz” na ekranie Stan systemu (GET /admin/system-status/queues): co robią pracownicy kolejek i co czeka.
 *
 * 05.10.2026: pobieranie opisów cennika Ansella przez 10 minut nie skończyło ani jednej karty, bo wszystkie 16
 * pracowników kolejki „enrich” tłumaczyło karty SIR (530 zadań TranslateB2bProductTextJob) — w panelu nie było tego
 * widać. Tylko odczyt tabel kolejek (jobs, jobs_embeddings, jobs_inquiries) i failed_jobs; liczby z SQL, rodzaje
 * zadań i ich źródło (konto dostawcy, producent) z payloadu najstarszych SAMPLE wierszy każdej tabeli.
 */
class QueueSnapshot
{
    public function __construct(private readonly B2bConnectorRegistry $connectors) {}

    /** Tyle najstarszych zadań tabeli rozbieramy na rodzaje — reszta tylko w liczbach (np. przebudowa wektorów). */
    public const SAMPLE = 5000;

    /**
     * Kolejki, które obsługują pracownicy z deploy/ensure-enrichment-workers.sh — pokazywane także puste.
     *
     * @var array<string, array{table: string, queue: string, label: string}>
     */
    private const QUEUES = [
        'enrich' => ['table' => 'jobs', 'queue' => 'enrich', 'label' => 'Opisy i tłumaczenia kart'],
        'prefetch' => ['table' => 'jobs', 'queue' => 'prefetch', 'label' => 'Wyszukiwanie stron przed opisem'],
        'default' => ['table' => 'jobs', 'queue' => 'default', 'label' => 'Pozostałe zadania'],
        'embeddings' => ['table' => 'jobs_embeddings', 'queue' => 'embeddings', 'label' => 'Wektory wyszukiwania'],
        'inquiries' => ['table' => 'jobs_inquiries', 'queue' => 'inquiries', 'label' => 'Analizy zapytań klientów'],
    ];

    /** @var array<string, string> */
    private const JOB_LABELS = [
        'EnrichProductJob' => 'Pobieranie opisu',
        'PrefetchProductSourcesJob' => 'Wyszukiwanie stron przed opisem',
        'TranslateB2bProductTextJob' => 'Tłumaczenie karty dostawcy',
        'DescribeB2bProductFromDatasheetJob' => 'Opis z karty katalogowej dostawcy',
        'SupplementB2bDescriptionJob' => 'Uzupełnienie krótkiego opisu dostawcy',
        'MergeB2bSizePricesJob' => 'Łączenie cen rozmiarów',
        'ReindexProductEmbeddingJob' => 'Wektor wyszukiwania karty',
        'AnalyzeClientInquiryJob' => 'Analiza zapytania klienta',
        'AnalyzePriceListPdfChunkJob' => 'Odczyt cennika PDF',
        'ExportProductToPrestaJob' => 'Wysyłka do sklepu Presta',
        'IndexCatalogHostJob' => 'Indeksowanie sklepu',
        'RegisterManufacturerCatalogJob' => 'Katalog producenta',
        'RewriteProductCategoriesJob' => 'Kategorie kart',
    ];

    /** @return array<string, mixed> */
    public function build(): array
    {
        $now = time();
        $queues = [];
        foreach (self::QUEUES as $key => $def) {
            $queues[$key] = [
                'key' => $key,
                'label' => $def['label'],
                'running' => 0,
                'waiting' => 0,
                'delayed' => 0,
                'oldest_wait_seconds' => null,
                'longest_running_seconds' => null,
                'over_timeout' => 0,
                'jobs' => [],
                'sampled' => false,
            ];
        }

        foreach (array_unique(array_column(self::QUEUES, 'table')) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $this->readTable($table, $now, $queues);
        }

        return [
            'checked_at' => now()->toIso8601String(),
            'queues' => array_values(array_map(function (array $q): array {
                $q['jobs'] = array_values(array_map(static function (array $job): array {
                    arsort($job['sources']);
                    $job['sources'] = array_map(
                        static fn (string $label, int $count): array => ['label' => $label, 'count' => $count],
                        array_keys(array_slice($job['sources'], 0, 4, true)),
                        array_values(array_slice($job['sources'], 0, 4, true)),
                    );

                    return $job;
                }, $q['jobs']));
                usort($q['jobs'], static fn (array $a, array $b): int => ($b['running'] + $b['waiting']) <=> ($a['running'] + $a['waiting']));

                return $q;
            }, $queues)),
            'batches' => $this->activeBatches(),
            'failed_24h' => $this->failedLastDay(),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $queues
     */
    private function readTable(string $table, int $now, array &$queues): void
    {
        $totals = DB::table($table)
            ->selectRaw('queue, count(*) as cnt, sum(case when reserved_at is not null then 1 else 0 end) as running,'
                .' sum(case when reserved_at is null and available_at > ? then 1 else 0 end) as delayed_count,'
                .' min(case when reserved_at is null and available_at <= ? then available_at end) as oldest_wait,'
                .' min(reserved_at) as oldest_reserved', [$now, $now])
            ->groupBy('queue')
            ->get();
        foreach ($totals as $row) {
            $key = $this->queueKey($table, (string) $row->queue);
            $queues[$key] ??= [
                'key' => $key, 'label' => $key, 'running' => 0, 'waiting' => 0, 'delayed' => 0,
                'oldest_wait_seconds' => null, 'longest_running_seconds' => null, 'over_timeout' => 0, 'jobs' => [], 'sampled' => false,
            ];
            $running = (int) $row->running;
            $delayed = (int) $row->delayed_count;
            $queues[$key]['running'] = $running;
            $queues[$key]['delayed'] = $delayed;
            $queues[$key]['waiting'] = (int) $row->cnt - $running - $delayed;
            $queues[$key]['oldest_wait_seconds'] = $row->oldest_wait !== null ? max(0, $now - (int) $row->oldest_wait) : null;
            $queues[$key]['longest_running_seconds'] = $row->oldest_reserved !== null ? max(0, $now - (int) $row->oldest_reserved) : null;
            $queues[$key]['sampled'] = (int) $row->cnt > self::SAMPLE;
        }

        $rows = DB::table($table)->orderBy('id')->limit(self::SAMPLE)->get(['queue', 'payload', 'reserved_at']);
        $parsed = [];
        $productIds = [];
        $accountIds = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true);
            $payload = is_array($payload) ? $payload : [];
            $command = (string) ($payload['data']['command'] ?? '');
            $productId = preg_match('/"productId";i:(\d+);/', $command, $m) === 1 ? (int) $m[1] : null;
            $accountId = preg_match('/"b2bAccountId";i:(\d+);/', $command, $a) === 1 ? (int) $a[1] : null;
            $reservedFor = $row->reserved_at !== null ? $now - (int) $row->reserved_at : null;
            $timeout = isset($payload['timeout']) && is_numeric($payload['timeout']) ? (int) $payload['timeout'] : null;
            $parsed[] = [
                'queue' => $this->queueKey($table, (string) $row->queue),
                'type' => class_basename((string) ($payload['displayName'] ?? 'nieznane')),
                'running' => $row->reserved_at !== null,
                'over_timeout' => $reservedFor !== null && $timeout !== null && $timeout > 0 && $reservedFor > $timeout,
                'product' => $productId,
                'account' => $accountId,
            ];
            if ($accountId !== null) {
                $accountIds[$accountId] = true;
            } elseif ($productId !== null) {
                $productIds[$productId] = true;
            }
        }

        $accounts = [];
        foreach ($accountIds === [] ? [] : B2bAccount::query()->whereKey(array_keys($accountIds))->get(['id', 'connector', 'username']) as $account) {
            // jak „Konta dostawców” w SystemStatusService: nazwa łącznika, a bez niej login konta
            $accounts[(int) $account->id] = $this->connectors->label($account->connector) ?? (string) $account->username;
        }
        $manufacturers = [];
        foreach (array_chunk(array_keys($productIds), 1000) as $chunk) {
            $manufacturers += Product::query()->whereKey($chunk)->pluck('manufacturer', 'id')->all();
        }

        foreach ($parsed as $job) {
            $key = $job['queue'];
            if (! isset($queues[$key])) {
                continue;
            }
            $type = $job['type'];
            $queues[$key]['jobs'][$type] ??= [
                'type' => $type,
                'label' => self::JOB_LABELS[$type] ?? $type,
                'running' => 0,
                'waiting' => 0,
                'sources' => [],
            ];
            $queues[$key]['jobs'][$type][$job['running'] ? 'running' : 'waiting']++;
            if ($job['over_timeout']) {
                $queues[$key]['over_timeout']++;
            }
            $source = $job['account'] !== null
                ? (string) ($accounts[$job['account']] ?? 'konto #'.$job['account'])
                : ($job['product'] !== null ? trim((string) ($manufacturers[$job['product']] ?? '')) : '');
            if ($source !== '') {
                $queues[$key]['jobs'][$type]['sources'][$source] = ($queues[$key]['jobs'][$type]['sources'][$source] ?? 0) + 1;
            }
        }
    }

    private function queueKey(string $table, string $queue): string
    {
        foreach (self::QUEUES as $key => $def) {
            if ($def['table'] === $table && $def['queue'] === $queue) {
                return $key;
            }
        }

        return $table === 'jobs' ? $queue : $table.':'.$queue;
    }

    /** @return list<array<string, mixed>> */
    private function activeBatches(): array
    {
        return ProductEnrichmentBatch::query()
            ->whereIn('status', [ProductEnrichmentBatch::STATUS_QUEUED, ProductEnrichmentBatch::STATUS_RUNNING])
            ->orderBy('id')
            ->limit(20)
            ->get(['id', 'status', 'total', 'done', 'failed', 'message', 'current_sku', 'created_at'])
            ->map(static fn (ProductEnrichmentBatch $b): array => [
                'id' => (int) $b->id,
                'status' => (string) $b->status,
                'total' => (int) $b->total,
                'done' => (int) $b->done,
                'failed' => (int) $b->failed,
                'message' => $b->message,
                'current_sku' => $b->current_sku,
                'created_at' => $b->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{type: string, label: string, count: int, last_at: ?string, last_error: string}> */
    private function failedLastDay(): array
    {
        try {
            $rows = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->orderByDesc('id')
                ->limit(500)
                ->get(['payload', 'exception', 'failed_at']);
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true);
            $type = class_basename((string) (is_array($payload) ? ($payload['displayName'] ?? 'nieznane') : 'nieznane'));
            if (! isset($out[$type])) {
                $firstLine = trim((string) strtok((string) $row->exception, "\n"));
                $out[$type] = [
                    'type' => $type,
                    'label' => self::JOB_LABELS[$type] ?? $type,
                    'count' => 0,
                    'last_at' => $row->failed_at !== null ? Carbon::parse((string) $row->failed_at)->toIso8601String() : null,
                    'last_error' => mb_substr($firstLine, 0, 240),
                ];
            }
            $out[$type]['count']++;
        }

        return array_values($out);
    }
}
