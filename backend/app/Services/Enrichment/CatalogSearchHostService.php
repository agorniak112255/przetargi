<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Jobs\IndexCatalogHostJob;
use App\Models\CatalogHost;
use App\Models\CatalogPage;
use App\Models\CatalogSearchSite;
use App\Models\CatalogSearchSiteExclusion;
use App\Models\CatalogSkipOverride;
use App\Models\ManufacturerSite;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CatalogSearchHostService
{
    public function __construct(
        private readonly CatalogIndexProgress $indexProgress,
    ) {}

    /**
     * @return list<array{
     *     host: string,
     *     links: int,
     *     sources: list<string>,
     *     source_label: string,
     *     last_seen_at: string|null,
     *     last_attempt_at: string|null,
     *     empty_reason: string|null,
     *     added_at: string|null,
     *     is_config_skip_listed: bool,
     *     skip_overridden: bool
     * }>
     */
    public function list(): array
    {
        $counts = $this->pageStats();
        $attempts = $this->attemptStats();
        $hosts = [];

        foreach ($this->configRetailerHosts() as $host) {
            $hosts[$host]['config'] = true;
        }
        foreach ($this->configManufacturerHosts() as $host) {
            $hosts[$host]['config'] = true;
        }
        foreach ($this->preferredHosts() as $host) {
            $hosts[$host]['config'] = true;
        }
        foreach (ManufacturerSite::allHosts() as $host) {
            $host = $this->normalizeHost((string) $host);
            if ($host !== '') {
                $hosts[$host]['producent'] = true;
            }
        }
        foreach (CatalogSearchSite::allHosts() as $host) {
            $host = $this->normalizeHost($host);
            if ($host !== '') {
                $hosts[$host]['manual'] = true;
            }
        }
        foreach (array_keys($counts) as $host) {
            $hosts[$host]['indeks'] = true;
        }
        foreach (array_keys($attempts) as $host) {
            $hosts[$host]['indeks'] = $hosts[$host]['indeks'] ?? false;
        }

        $manualDates = $this->manualAddedAt();
        $blocked = array_fill_keys(
            array_map(fn (string $host): string => $this->normalizeHost($host), CatalogSearchSiteExclusion::allHosts()),
            true
        );
        $out = [];
        foreach ($hosts as $host => $flags) {
            if (isset($blocked[$host]) || $host === '') {
                continue;
            }
            $sources = [];
            if (! empty($flags['manual'])) {
                $sources[] = 'manual';
            }
            if (! empty($flags['config'])) {
                $sources[] = 'config';
            }
            if (! empty($flags['producent'])) {
                $sources[] = 'producent';
            }
            if ($sources === [] && ! empty($flags['indeks'])) {
                $sources[] = 'indeks';
            }
            $stat = $counts[$host] ?? ['links' => 0, 'last_seen_at' => null];
            $attempt = $attempts[$host] ?? ['last_attempt_at' => null, 'last_error' => null];
            $links = (int) $stat['links'];
            $configSkipListed = $this->isConfigSkipListed($host);
            $skipOverridden = $configSkipListed && CatalogSkipOverride::hasHost($host);
            $out[] = [
                'host' => $host,
                'links' => $links,
                'sources' => $sources,
                'source_label' => $this->sourceLabel($sources),
                'last_seen_at' => $stat['last_seen_at'],
                'last_attempt_at' => $attempt['last_attempt_at'],
                'empty_reason' => $this->emptyReason($host, $links, $attempt['last_attempt_at'], $attempt['last_error']),
                'added_at' => $manualDates[$host] ?? null,
                'is_config_skip_listed' => $configSkipListed,
                'skip_overridden' => $skipOverridden,
            ];
        }

        usort($out, static fn (array $a, array $b): int => $a['host'] <=> $b['host']);

        return $out;
    }

    /**
     * @return array{host: string, links: int, sources: list<string>, source_label: string, already: bool}
     */
    public function add(string $raw): array
    {
        $host = $this->normalizeHost($raw);
        if ($host === '' || ! $this->looksLikeHost($host)) {
            throw ValidationException::withMessages([
                'url' => 'Podaj poprawny adres strony lub domenę (np. sklepbhp.pl).',
            ]);
        }

        $existing = $this->find($host);
        if ($existing !== null) {
            throw ValidationException::withMessages([
                'url' => 'Strona '.$host.' jest już dodana ('.$existing['links'].' linków).',
            ]);
        }

        CatalogSearchSiteExclusion::forget($host);
        CatalogSearchSite::query()->create([
            'host' => $host,
            'source' => 'manual',
        ]);
        $this->indexProgress->start($host, 'Dodano '.$host.' — w kolejce.');
        IndexCatalogHostJob::dispatch($host);

        return [
            'host' => $host,
            'links' => 0,
            'sources' => ['manual'],
            'source_label' => $this->sourceLabel(['manual']),
            'empty_reason' => 'Jeszcze nie sprawdzana.',
            'already' => false,
        ];
    }

    /**
     * @return array{
     *     host: string,
     *     links: int,
     *     data: list<array{id: int, url: string, title: string|null, manufacturer: string|null, last_seen_at: string|null}>,
     *     meta: array{current_page: int, last_page: int, per_page: int, total: int}
     * }
     */
    public function pages(string $host, string $q = '', int $page = 1, int $perPage = 40): array
    {
        $row = $this->requireHost($host);
        $host = $row['host'];
        $perPage = max(10, min(100, $perPage));
        $page = max(1, $page);

        $query = CatalogPage::query()
            ->whereIn('host', $this->hostAliases($host))
            ->orderByDesc('last_seen_at')
            ->orderBy('id');

        $needle = trim($q);
        if ($needle !== '') {
            $like = '%'.addcslashes($needle, '%_\\').'%';
            $query->where(function ($builder) use ($like): void {
                $builder
                    ->where('url', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('manufacturer', 'like', $like);
            });
        }

        $paginator = $query->paginate($perPage, ['id', 'url', 'title', 'manufacturer', 'last_seen_at'], 'page', $page);

        return [
            'host' => $host,
            'links' => $row['links'],
            'data' => collect($paginator->items())->map(static function (CatalogPage $item): array {
                return [
                    'id' => (int) $item->id,
                    'url' => (string) $item->url,
                    'title' => $item->title,
                    'manufacturer' => $item->manufacturer,
                    'last_seen_at' => $item->last_seen_at?->toIso8601String(),
                ];
            })->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array{host: string, deleted: bool, pages: int, message: string}
     */
    public function remove(string $host): array
    {
        $row = $this->requireHost($host);
        $host = $row['host'];
        $aliases = $this->hostAliases($host);
        $pages = CatalogPage::query()->whereIn('host', $aliases)->count();

        CatalogPage::query()->whereIn('host', $aliases)->delete();
        CatalogHost::query()->whereIn('host', $aliases)->delete();
        CatalogSearchSite::query()->whereIn('host', $aliases)->delete();
        if (Schema::hasTable('manufacturer_sites')) {
            ManufacturerSite::query()->whereIn('host', $aliases)->delete();
        }
        CatalogSearchSiteExclusion::remember($host);

        return [
            'host' => $host,
            'deleted' => true,
            'pages' => $pages,
            'message' => 'Usunięto '.$host.($pages > 0 ? ' ('.$pages.' kart).' : '.'),
        ];
    }

    /**
     * @return array{host: string, queued: bool, message: string}
     */
    public function reindex(string $host): array
    {
        $row = $this->requireHost($host);
        $this->indexProgress->start($row['host'], 'Zlecono sprawdzenie '.$row['host'].'.');
        IndexCatalogHostJob::dispatch($row['host']);

        return [
            'host' => $row['host'],
            'queued' => true,
            'message' => 'Sprawdzanie '.$row['host'].' w tle.',
        ];
    }

    /**
     * Odblokowuje domenę z config('enrichment.catalog_skip_hosts') bez zmiany kodu —
     * wpis w bazie nadpisuje pominięcie i od razu zleca indeksowanie.
     *
     * @return array{host: string, queued: bool, message: string}
     */
    public function unskip(string $host): array
    {
        $host = $this->normalizeHost($host);
        if ($host === '' || ! $this->isConfigSkipListed($host)) {
            throw ValidationException::withMessages([
                'host' => 'Domena '.$host.' nie jest na liście pomijanych (catalog_skip_hosts).',
            ]);
        }

        CatalogSkipOverride::remember($host);
        $this->indexProgress->start($host, 'Odblokowano '.$host.' — w kolejce.');
        IndexCatalogHostJob::dispatch($host);

        return [
            'host' => $host,
            'queued' => true,
            'message' => 'Odblokowano '.$host.' — indeksowanie w tle.',
        ];
    }

    /**
     * Cofa ręczne odblokowanie — domena wraca do pomijania przy pełnym skanie.
     * Nie kasuje już zebranych kart, tylko wyłącza ją z przyszłych automatycznych indeksowań.
     *
     * @return array{host: string, message: string}
     */
    public function reskip(string $host): array
    {
        $host = $this->normalizeHost($host);
        if ($host === '' || ! $this->isConfigSkipListed($host)) {
            throw ValidationException::withMessages([
                'host' => 'Domena '.$host.' nie jest na liście pomijanych (catalog_skip_hosts).',
            ]);
        }

        CatalogSkipOverride::forget($host);

        return [
            'host' => $host,
            'message' => $host.' wraca do pomijanych przy pełnym skanie.',
        ];
    }

    /**
     * @return array{
     *     host: string,
     *     status: 'idle'|'queued'|'running'|'done'|'failed',
     *     started_at: string|null,
     *     finished_at: string|null,
     *     lines: list<array{at: string, text: string}>
     * }
     */
    public function progress(string $host): array
    {
        return $this->indexProgress->snapshot($this->normalizeHost($host));
    }

    public function normalizeHost(string $domain): string
    {
        return ManufacturerSite::normalizeHost($domain);
    }

    /**
     * @return array{host: string, links: int}|null
     */
    public function find(string $host): ?array
    {
        $host = $this->normalizeHost($host);
        if ($host === '') {
            return null;
        }
        foreach ($this->list() as $row) {
            if ($row['host'] === $host) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array{host: string, links: int, sources: list<string>, source_label: string, last_seen_at: string|null, last_attempt_at: string|null, empty_reason: string|null, added_at: string|null}
     */
    private function requireHost(string $host): array
    {
        $row = $this->find($host);
        if ($row === null) {
            throw ValidationException::withMessages([
                'host' => 'Nieznana domena '.$this->normalizeHost($host).'.',
            ]);
        }

        return $row;
    }

    /**
     * @return list<string>
     */
    private function hostAliases(string $host): array
    {
        $host = $this->normalizeHost($host);
        if ($host === '') {
            return [];
        }

        return array_values(array_unique([$host, 'www.'.$host]));
    }

    /**
     * @return array<string, array{links: int, last_seen_at: string|null}>
     */
    private function pageStats(): array
    {
        if (! $this->hasTable('catalog_pages')) {
            return [];
        }

        $rows = CatalogPage::query()
            ->selectRaw('host, COUNT(*) as links, MAX(last_seen_at) as last_seen_at')
            ->groupBy('host')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $host = $this->normalizeHost((string) $row->host);
            if ($host === '') {
                continue;
            }
            $out[$host] = [
                'links' => (int) ($out[$host]['links'] ?? 0) + (int) $row->links,
                'last_seen_at' => $this->laterIso($out[$host]['last_seen_at'] ?? null, $row->last_seen_at),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, array{last_attempt_at: string|null, last_error: string|null}>
     */
    private function attemptStats(): array
    {
        if (! $this->hasTable('catalog_hosts')) {
            return [];
        }

        $out = [];
        $hasError = $this->hasColumn('catalog_hosts', 'last_error');
        $columns = $hasError ? ['host', 'last_attempt_at', 'last_error'] : ['host', 'last_attempt_at'];
        foreach (CatalogHost::query()->get($columns) as $row) {
            $host = $this->normalizeHost((string) $row->host);
            if ($host === '') {
                continue;
            }
            $error = $hasError && is_string($row->last_error) && $row->last_error !== ''
                ? $row->last_error
                : null;
            $out[$host] = [
                'last_attempt_at' => $this->laterIso($out[$host]['last_attempt_at'] ?? null, $row->last_attempt_at),
                'last_error' => $error ?? ($out[$host]['last_error'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function manualAddedAt(): array
    {
        if (! $this->hasTable('catalog_search_sites')) {
            return [];
        }

        $out = [];
        foreach (CatalogSearchSite::query()->get(['host', 'created_at']) as $row) {
            $host = $this->normalizeHost((string) $row->host);
            if ($host !== '' && $row->created_at !== null) {
                $out[$host] = $row->created_at->toIso8601String();
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function configRetailerHosts(): array
    {
        return $this->normalizeList(config('enrichment.retailer_domains', []));
    }

    /**
     * @return list<string>
     */
    private function preferredHosts(): array
    {
        return $this->normalizeList(config('enrichment.preferred_domains', []));
    }

    /**
     * @return list<string>
     */
    private function configManufacturerHosts(): array
    {
        $out = [];
        foreach ((array) config('enrichment.manufacturer_domains', []) as $domains) {
            foreach ($this->normalizeList($domains) as $host) {
                $out[] = $host;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function normalizeList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $domain) {
            if (! is_string($domain)) {
                continue;
            }
            $host = $this->normalizeHost($domain);
            if ($host !== '') {
                $out[$host] = $host;
            }
        }

        return array_values($out);
    }

    /**
     * @param  list<string>  $sources
     */
    private function sourceLabel(array $sources): string
    {
        $labels = [
            'manual' => 'Ręcznie',
            'config' => 'Konfiguracja',
            'producent' => 'Producent',
            'indeks' => 'Indeks',
        ];
        $parts = [];
        foreach ($sources as $source) {
            $parts[] = $labels[$source] ?? $source;
        }

        return implode(', ', $parts);
    }

    private function looksLikeHost(string $host): bool
    {
        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $host) === 1;
    }

    private function emptyReason(string $host, int $links, ?string $lastAttempt, ?string $lastError): ?string
    {
        if ($links > 0) {
            return null;
        }
        if ($this->isCdnHost($host)) {
            return 'CDN — przy pełnym skanie pomijany.';
        }
        if ($this->isConfigSkipListed($host) && ! CatalogSkipOverride::hasHost($host)) {
            return 'Pominięta na liście catalog_skip_hosts.';
        }
        if (is_string($lastError) && $lastError !== '') {
            return $lastError;
        }
        if ($lastAttempt === null) {
            return 'Jeszcze nie sprawdzana.';
        }

        return 'Sprawdzona — 0 kart (brak sitemapy, WAF albo nie-sklep).';
    }

    /** Host figuruje w config('enrichment.catalog_skip_hosts') — niezależnie od odblokowania. */
    private function isConfigSkipListed(string $host): bool
    {
        foreach ((array) config('enrichment.catalog_skip_hosts', []) as $domain) {
            if (! is_string($domain)) {
                continue;
            }
            if ($this->normalizeHost($domain) === $host) {
                return true;
            }
        }

        return false;
    }

    private function isCdnHost(string $host): bool
    {
        return str_contains($host, 'cloudfront.net');
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (Throwable) {
            return false;
        }
    }

    private function laterIso(mixed $current, mixed $candidate): ?string
    {
        $a = $this->toIso($current);
        $b = $this->toIso($candidate);
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return strcmp($a, $b) >= 0 ? $a : $b;
    }

    private function toIso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($raw))->format(DATE_ATOM);
        } catch (Throwable) {
            return $raw;
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
