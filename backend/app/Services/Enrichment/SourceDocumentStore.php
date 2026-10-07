<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Models\ProductSourceDocument;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Zapisane źródła opisu karty (etap 1 opisów z cenników). Tekst strony / PDF-u na dysku „sources” jako
 * {sha256}.txt.gz — jedna kopia na wszystkie karty (strona rodziny Coba obsługuje kilka kart), w bazie wiersz
 * product_source_documents na kartę, adres i wersję tekstu, z werdyktem tożsamości.
 *
 * Retencja na (karta, adres): najnowszy wiersz oraz wiersze przypięte do opublikowanej wersji opisu
 * (product_description_versions.status = published) — żeby dało się sprawdzić, z czego powstał opis na karcie.
 * Pliki na dysku zostają: ten sam tekst bywa źródłem innych kart (sprzątanie osieroconych plików — osobno).
 *
 * Zapis (record) tylko przy enrichment.store_sources — w testach wyłączony (phpunit.xml).
 */
final class SourceDocumentStore
{
    public const DISK = 'sources';

    private ?bool $versionsTable = null;

    /** Zapisuje tekst (raz na treść) i oddaje jego sha256. */
    public function put(string $text): string
    {
        $sha256 = hash('sha256', $text);
        $path = $this->path($sha256);
        if (! $this->disk()->exists($path)) {
            $this->disk()->put($path, (string) gzencode($text, 6));
        }

        return $sha256;
    }

    public function get(string $sha256): ?string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            return null;
        }
        $raw = $this->disk()->get($this->path($sha256));
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $text = @gzdecode($raw);

        return is_string($text) ? $text : null;
    }

    /**
     * Zapis źródeł przebiegu: upsert po (karta, adres, sha256 tekstu surowego) i retencja na (karta, adres).
     *
     * @param  list<array{
     *     url: string,
     *     final_url?: string|null,
     *     text: string,
     *     filtered_text?: string|null,
     *     roles?: list<string>,
     *     identity?: array{verdict?: string|null, reason?: string|null, key_type?: string|null, key?: string|null}|null,
     *     markup_codes?: list<array{type: string, value: string}>,
     *     norm_facts?: list<array<string, mixed>>,
     *     fetched_at?: CarbonInterface|string|null
     * }>  $docs  text = tekst surowy strony (przed filtrem stron), filtered_text = tekst po filtrze
     */
    public function record(Product $p, array $docs, ?int $versionId): void
    {
        if (! config('enrichment.store_sources', true) || ! $p->exists) {
            return;
        }
        foreach ($docs as $doc) {
            $url = trim((string) ($doc['url'] ?? ''));
            $text = (string) ($doc['text'] ?? '');
            if ($url === '' || $text === '') {
                continue;
            }
            $sha256 = $this->put($text);
            $filtered = isset($doc['filtered_text']) && is_string($doc['filtered_text']) && $doc['filtered_text'] !== '' && $doc['filtered_text'] !== $text
                ? $this->put($doc['filtered_text'])
                : null;
            $finalUrl = trim((string) ($doc['final_url'] ?? ''));
            $host = mb_strtolower((string) (parse_url($finalUrl !== '' ? $finalUrl : $url, PHP_URL_HOST) ?? ''));
            $identity = is_array($doc['identity'] ?? null) ? $doc['identity'] : [];
            $keyType = trim((string) ($identity['key_type'] ?? ''));
            $key = trim((string) ($identity['key'] ?? ''));
            $roles = array_values(array_unique(array_filter(
                is_array($doc['roles'] ?? null) ? $doc['roles'] : [ProductSourceDocument::ROLE_DESCRIPTION],
                static fn (mixed $role): bool => is_string($role) && $role !== ''
            )));
            $verdict = $identity['verdict'] ?? null;
            $urlHash = ProductSourceDocument::urlHash($url);

            $row = ProductSourceDocument::query()->firstOrNew(
                ['product_id' => $p->id, 'url_hash' => $urlHash, 'sha256' => $sha256]
            );
            // Ten sam tekst w kolejnym przebiegu: przypięcie do opublikowanej wersji zostaje (inaczej propozycja
            // z tego samego tekstu odpięłaby źródło opisu z karty i retencja mogłaby je potem usunąć).
            $previousVersion = $row->exists && $row->description_version_id !== null ? (int) $row->description_version_id : null;
            $keepPrevious = $previousVersion !== null && ($versionId === null || $this->publishedVersionIds([$previousVersion]) !== []);
            $row->fill([
                'description_version_id' => $keepPrevious ? $previousVersion : $versionId,
                'url' => mb_substr($url, 0, 2000),
                'final_url' => $finalUrl !== '' ? mb_substr($finalUrl, 0, 2000) : null,
                'host' => mb_substr($host, 0, 255),
                'filtered_sha256' => $filtered,
                'chars' => mb_strlen($text),
                'fetched_at' => $this->fetchedAt($doc['fetched_at'] ?? null),
                'identity_verdict' => is_string($verdict) && $verdict !== '' ? $verdict : null,
                'identity_reason' => is_string($identity['reason'] ?? null) ? mb_substr($identity['reason'], 0, 255) : null,
                'identity_key' => $keyType !== '' && $key !== '' ? mb_substr($keyType.':'.$key, 0, 80) : null,
                'roles' => mb_substr(implode(',', $roles !== [] ? $roles : [ProductSourceDocument::ROLE_DESCRIPTION]), 0, 40),
                'markup_codes' => is_array($doc['markup_codes'] ?? null) && $doc['markup_codes'] !== [] ? array_values($doc['markup_codes']) : null,
                'norm_facts' => is_array($doc['norm_facts'] ?? null) && $doc['norm_facts'] !== [] ? array_values($doc['norm_facts']) : null,
            ]);
            $row->save();
            $this->prune($p, $urlHash);
        }
    }

    /**
     * Źródła wersji opisu przechodzą na nową wersję published z tą samą treścią (DescriptionVersionStore::publish —
     * zatwierdzona propozycja, przywrócona wersja). Bez tego retencja (prune) traktowałaby źródła opisu na karcie
     * jak źródła nieopublikowanej wersji i usunęła je przy kolejnym pobraniu tego adresu.
     *
     * @return int ile wierszy przepięto
     */
    public function repointVersion(Product $p, int $fromVersionId, int $toVersionId): int
    {
        if ($p->id === null || $fromVersionId <= 0 || $toVersionId <= 0 || $fromVersionId === $toVersionId) {
            return 0;
        }

        return ProductSourceDocument::query()
            ->where('product_id', $p->id)
            ->where('description_version_id', $fromVersionId)
            ->update(['description_version_id' => $toVersionId, 'updated_at' => now()]);
    }

    /**
     * Źródła karty w danej roli, najnowsza wersja tekstu na adres, od najnowszego pobrania. Bez tekstu (get()).
     *
     * @return list<SourceDoc>
     */
    public function forProduct(Product $p, string $role = ProductSourceDocument::ROLE_DESCRIPTION): array
    {
        $out = [];
        $seen = [];
        $rows = ProductSourceDocument::query()
            ->where('product_id', $p->id)
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->get();
        foreach ($rows as $row) {
            if (isset($seen[$row->url_hash]) || ! in_array($role, $row->roleList(), true)) {
                continue;
            }
            $seen[$row->url_hash] = true;
            $out[] = new SourceDoc(
                url: (string) $row->url,
                finalUrl: $row->final_url !== null ? (string) $row->final_url : null,
                host: (string) $row->host,
                sha256: (string) $row->sha256,
                filteredSha256: $row->filtered_sha256 !== null ? (string) $row->filtered_sha256 : null,
                verdict: $row->identity_verdict !== null ? (string) $row->identity_verdict : null,
                verdictReason: $row->identity_reason !== null ? (string) $row->identity_reason : null,
                roles: $row->roleList(),
                normFacts: is_array($row->norm_facts) ? $row->norm_facts : [],
                markupCodes: is_array($row->markup_codes) ? $row->markup_codes : [],
                fetchedAt: $row->fetched_at,
            );
        }

        return $out;
    }

    /** Na (karta, adres) zostaje najnowszy wiersz i wiersze opublikowanych wersji opisu. */
    private function prune(Product $p, string $urlHash): void
    {
        $rows = ProductSourceDocument::query()
            ->where('product_id', $p->id)
            ->where('url_hash', $urlHash)
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->get(['id', 'description_version_id']);
        if ($rows->count() < 2) {
            return;
        }
        $older = $rows->slice(1);
        $versionIds = $older->pluck('description_version_id')->filter()->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $kept = $this->publishedVersionIds($versionIds);
        $drop = $older
            ->filter(static fn (ProductSourceDocument $row): bool => $row->description_version_id === null
                || ! in_array((int) $row->description_version_id, $kept, true))
            ->pluck('id')
            ->all();
        if ($drop !== []) {
            ProductSourceDocument::query()->whereIn('id', $drop)->delete();
        }
    }

    /**
     * Które z wersji są opublikowane. Bez tabeli wersji nie wiadomo — wtedy każda liczy się jako opublikowana
     * (wiersz z wersją zostaje).
     *
     * @param  list<int>  $versionIds
     * @return list<int>
     */
    private function publishedVersionIds(array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }
        $this->versionsTable ??= Schema::hasTable('product_description_versions');
        if (! $this->versionsTable) {
            return $versionIds;
        }

        return DB::table('product_description_versions')
            ->whereIn('id', $versionIds)
            ->where('status', 'published')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function fetchedAt(mixed $value): CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return now();
    }

    private function path(string $sha256): string
    {
        return $sha256.'.txt.gz';
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
