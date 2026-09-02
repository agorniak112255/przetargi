<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Jobs\IndexCatalogHostJob;
use App\Models\CatalogHost;
use App\Models\CatalogPage;
use App\Models\CatalogSearchSite;
use App\Models\ManufacturerSite;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CatalogSearchHostService
{
    /**
     * @return list<array{
     *     host: string,
     *     links: int,
     *     sources: list<string>,
     *     source_label: string,
     *     last_seen_at: string|null,
     *     last_attempt_at: string|null,
     *     added_at: string|null
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
        $out = [];
        foreach ($hosts as $host => $flags) {
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
            $attempt = $attempts[$host] ?? ['last_attempt_at' => null];
            $out[] = [
                'host' => $host,
                'links' => (int) $stat['links'],
                'sources' => $sources,
                'source_label' => $this->sourceLabel($sources),
                'last_seen_at' => $stat['last_seen_at'],
                'last_attempt_at' => $attempt['last_attempt_at'],
                'added_at' => $manualDates[$host] ?? null,
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

        CatalogSearchSite::query()->create([
            'host' => $host,
            'source' => 'manual',
        ]);
        IndexCatalogHostJob::dispatch($host);

        return [
            'host' => $host,
            'links' => 0,
            'sources' => ['manual'],
            'source_label' => $this->sourceLabel(['manual']),
            'already' => false,
        ];
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
     * @return array<string, array{last_attempt_at: string|null}>
     */
    private function attemptStats(): array
    {
        if (! $this->hasTable('catalog_hosts')) {
            return [];
        }

        $out = [];
        foreach (CatalogHost::query()->get(['host', 'last_attempt_at']) as $row) {
            $host = $this->normalizeHost((string) $row->host);
            if ($host === '') {
                continue;
            }
            $out[$host] = [
                'last_attempt_at' => $this->laterIso($out[$host]['last_attempt_at'] ?? null, $row->last_attempt_at),
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
