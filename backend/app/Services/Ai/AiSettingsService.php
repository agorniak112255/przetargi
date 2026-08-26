<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\Product;
use App\Services\Enrichment\TavilyQuotaGuard;
use App\Services\Enrichment\TavilySearchProfile;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class AiSettingsService
{
    public const ENRICHMENT_BATCH_MAX = 1000;

    public const CONCURRENCY_MAX = 100;

    public const SEARCH_ENGINE_TAVILY = 'tavily';

    public const SEARCH_ENGINE_DUCKDUCKGO = 'duckduckgo';

    public const SEARCH_ENGINE_SEARXNG = 'searxng';

    private const SEARCH_ENGINES = [
        self::SEARCH_ENGINE_TAVILY,
        self::SEARCH_ENGINE_DUCKDUCKGO,
        self::SEARCH_ENGINE_SEARXNG,
    ];

    public const EMBEDDING_LOCAL = 'local';

    public const EMBEDDING_OPENAI = 'openai';

    public const EMBEDDING_OPENROUTER = 'openrouter';

    public const EMBEDDING_PROVIDERS = [
        self::EMBEDDING_LOCAL,
        self::EMBEDDING_OPENAI,
        self::EMBEDDING_OPENROUTER,
    ];

    /** Adresy chmurowych dostawców embeddingów — nie do edycji z UI. */
    private const EMBEDDING_CLOUD_BASE_URLS = [
        self::EMBEDDING_OPENAI => 'https://api.openai.com/v1',
        self::EMBEDDING_OPENROUTER => 'https://openrouter.ai/api/v1',
    ];

    private const EMBEDDING_CLOUD_DEFAULT_MODELS = [
        self::EMBEDDING_OPENAI => 'text-embedding-3-small',
        self::EMBEDDING_OPENROUTER => 'openai/text-embedding-3-small',
    ];

    public const OPENAI_EMBEDDING_MODEL = 'text-embedding-3-small';

    /**
     * @return array{
     *     enabled: bool,
     *     provider: string,
     *     base_url: string,
     *     api_key: ?string,
     *     model: string,
     *     enrichment_model: ?string,
     *     enrichment_use_large_model: bool,
     *     timeout_seconds: int,
     *     temperature: float,
     *     web_search_enabled: bool,
     *     tavily_api_key: ?string,
     *     search_engine: string,
     *     searxng_url: ?string,
     *     search_fallback: string,
     *     tavily_search_mode: string,
     *     enrichment_batch_limit: int,
     *     match_concurrency: int,
     *     vector_enabled: bool,
     *     qdrant_url: ?string,
     *     qdrant_api_key: ?string,
     *     qdrant_collection: ?string,
     *     embedding_model: ?string,
     *     embedding_base_url: ?string,
     *     embedding_api_key: ?string,
     *     embedding_provider: string,
     *     embedding_cloud_model: ?string,
     *     embedding_cloud_api_key: ?string,
     *     has_api_key: bool,
     *     has_tavily_api_key: bool,
     *     has_qdrant_api_key: bool,
     *     has_embedding_api_key: bool,
     *     has_embedding_cloud_api_key: bool,
     *     model_profiles: list<array<string, mixed>>,
     *     source: string
     * }
     */
    public function resolve(): array
    {
        $row = AiSetting::query()->first();
        if ($row !== null) {
            $key = $this->safeEncrypted($row, 'api_key');
            $tavily = $this->safeEncrypted($row, 'tavily_api_key');
            $qdrantKey = $this->safeEncrypted($row, 'qdrant_api_key');
            $embKey = $this->safeEncrypted($row, 'embedding_api_key');
            $cloudEmbKey = $this->safeEncrypted($row, 'embedding_cloud_api_key');
            $hasVectorCols = Schema::hasColumn('ai_settings', 'vector_enabled');
            $hasProviderCol = Schema::hasColumn('ai_settings', 'embedding_provider');

            return [
                'enabled' => (bool) $row->enabled,
                'provider' => (string) $row->provider,
                'base_url' => rtrim((string) $row->base_url, '/'),
                'api_key' => $key !== null && $key !== '' ? (string) $key : null,
                'model' => (string) $row->model,
                'enrichment_model' => $this->nullableString($row->enrichment_model ?? null),
                'enrichment_use_large_model' => $this->boolColumn(
                    $row,
                    'enrichment_use_large_model',
                    false
                ),
                'timeout_seconds' => (int) $row->timeout_seconds,
                'temperature' => (float) $row->temperature,
                'web_search_enabled' => (bool) ($row->web_search_enabled ?? true),
                'tavily_api_key' => $tavily !== null && $tavily !== '' ? (string) $tavily : null,
                'search_engine' => $this->normalizeSearchEngine(
                    Schema::hasColumn('ai_settings', 'search_engine')
                        ? ($row->search_engine ?? null)
                        : null
                ),
                'searxng_url' => Schema::hasColumn('ai_settings', 'searxng_url')
                    ? $this->nullableUrl($row->searxng_url ?? null)
                    : null,
                'search_fallback' => (string) ($row->search_fallback ?: 'tavily'),
                'tavily_search_mode' => $this->normalizeTavilySearchMode(
                    Schema::hasColumn('ai_settings', 'tavily_search_mode')
                        ? ($row->tavily_search_mode ?? null)
                        : null
                ),
                'enrichment_batch_limit' => $this->normalizeEnrichmentBatchLimit(
                    Schema::hasColumn('ai_settings', 'enrichment_batch_limit')
                        ? ($row->enrichment_batch_limit ?? null)
                        : null
                ),
                'match_concurrency' => $this->normalizeMatchConcurrency(
                    Schema::hasColumn('ai_settings', 'match_concurrency')
                        ? ($row->match_concurrency ?? null)
                        : null
                ),
                'vector_enabled' => $hasVectorCols ? (bool) ($row->vector_enabled ?? false) : false,
                'qdrant_url' => $hasVectorCols ? $this->nullableString($row->qdrant_url ?? null) : null,
                'qdrant_api_key' => $qdrantKey !== null && $qdrantKey !== '' ? (string) $qdrantKey : null,
                'qdrant_collection' => $hasVectorCols
                    ? ($this->nullableString($row->qdrant_collection ?? null) ?? 'products')
                    : 'products',
                'embedding_model' => $hasVectorCols ? $this->nullableString($row->embedding_model ?? null) : null,
                'embedding_base_url' => $hasVectorCols ? $this->nullableUrl($row->embedding_base_url ?? null) : null,
                'embedding_api_key' => $embKey !== null && $embKey !== '' ? (string) $embKey : null,
                'embedding_provider' => $this->normalizeEmbeddingProvider(
                    $hasProviderCol ? ($row->embedding_provider ?? null) : null
                ),
                'embedding_cloud_model' => $hasProviderCol
                    ? $this->nullableString($row->embedding_cloud_model ?? null)
                    : null,
                'embedding_cloud_api_key' => $cloudEmbKey !== null && $cloudEmbKey !== ''
                    ? (string) $cloudEmbKey
                    : null,
                'has_api_key' => $key !== null && $key !== '',
                'has_tavily_api_key' => $tavily !== null && $tavily !== '',
                'has_qdrant_api_key' => $qdrantKey !== null && $qdrantKey !== '',
                'has_embedding_api_key' => $embKey !== null && $embKey !== '',
                'has_embedding_cloud_api_key' => $cloudEmbKey !== null && $cloudEmbKey !== '',
                'model_profiles' => $this->safeProfiles($row),
                'source' => 'database',
            ];
        }

        $key = config('ai.api_key');
        $key = is_string($key) && $key !== '' ? $key : null;
        $tavily = config('ai.tavily_api_key');
        $tavily = is_string($tavily) && $tavily !== '' ? $tavily : null;
        $qdrantKey = config('ai.qdrant_api_key');
        $qdrantKey = is_string($qdrantKey) && $qdrantKey !== '' ? $qdrantKey : null;
        $embKey = config('ai.embedding_api_key');
        $embKey = is_string($embKey) && $embKey !== '' ? $embKey : null;
        $cloudEmbKey = config('ai.embedding_cloud_api_key');
        $cloudEmbKey = is_string($cloudEmbKey) && $cloudEmbKey !== '' ? $cloudEmbKey : null;

        return [
            'enabled' => (bool) config('ai.enabled'),
            'provider' => (string) config('ai.provider'),
            'base_url' => rtrim((string) config('ai.base_url'), '/'),
            'api_key' => $key,
            'model' => (string) config('ai.model'),
            'enrichment_model' => $this->nullableString(config('ai.enrichment_model')),
            'enrichment_use_large_model' => (bool) config('ai.enrichment_use_large_model', false),
            'timeout_seconds' => (int) config('ai.timeout_seconds'),
            'temperature' => (float) config('ai.temperature'),
            'web_search_enabled' => (bool) config('ai.web_search_enabled', true),
            'tavily_api_key' => $tavily,
            'search_engine' => $this->normalizeSearchEngine(config('ai.search_engine')),
            'searxng_url' => $this->nullableUrl(config('ai.searxng_url')),
            'search_fallback' => (string) config('ai.search_fallback', 'tavily'),
            'tavily_search_mode' => $this->normalizeTavilySearchMode(config('ai.tavily_search_mode')),
            'enrichment_batch_limit' => $this->normalizeEnrichmentBatchLimit(config('ai.enrichment_batch_limit')),
            'match_concurrency' => $this->normalizeMatchConcurrency(config('ai.match_concurrency')),
            'vector_enabled' => (bool) config('ai.vector_enabled', false),
            'qdrant_url' => $this->nullableString(config('ai.qdrant_url')),
            'qdrant_api_key' => $qdrantKey,
            'qdrant_collection' => $this->nullableString(config('ai.qdrant_collection')) ?? 'products',
            'embedding_model' => $this->nullableString(config('ai.embedding_model')),
            'embedding_base_url' => $this->nullableUrl(config('ai.embedding_base_url')),
            'embedding_api_key' => $embKey,
            'embedding_provider' => $this->normalizeEmbeddingProvider(config('ai.embedding_provider')),
            'embedding_cloud_model' => $this->nullableString(config('ai.embedding_cloud_model')),
            'embedding_cloud_api_key' => $cloudEmbKey,
            'has_api_key' => $key !== null,
            'has_tavily_api_key' => $tavily !== null,
            'has_qdrant_api_key' => $qdrantKey !== null,
            'has_embedding_api_key' => $embKey !== null,
            'has_embedding_cloud_api_key' => $cloudEmbKey !== null,
            'model_profiles' => [],
            'source' => 'env',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicView(): array
    {
        $cfg = $this->resolve();

        return [
            'enabled' => $cfg['enabled'],
            'provider' => $cfg['provider'],
            'base_url' => $cfg['base_url'],
            'model' => $cfg['model'],
            'enrichment_model' => $cfg['enrichment_model'],
            'enrichment_use_large_model' => $cfg['enrichment_use_large_model'],
            'timeout_seconds' => $cfg['timeout_seconds'],
            'temperature' => $cfg['temperature'],
            'web_search_enabled' => $cfg['web_search_enabled'],
            'search_engine' => $cfg['search_engine'],
            'searxng_url' => $cfg['searxng_url'],
            'search_fallback' => $cfg['search_fallback'],
            'tavily_search_mode' => $cfg['tavily_search_mode'],
            'enrichment_batch_limit' => $cfg['enrichment_batch_limit'],
            'match_concurrency' => $cfg['match_concurrency'],
            'vector_enabled' => $cfg['vector_enabled'],
            'qdrant_url' => $cfg['qdrant_url'],
            'qdrant_collection' => $cfg['qdrant_collection'],
            'embedding_model' => $cfg['embedding_model'],
            'embedding_base_url' => $cfg['embedding_base_url'],
            'embedding_provider' => $cfg['embedding_provider'],
            'embedding_cloud_model' => $cfg['embedding_cloud_model'],
            'embedding_collection' => $this->embeddingCollection($cfg),
            'has_api_key' => $cfg['has_api_key'],
            'has_tavily_api_key' => $cfg['has_tavily_api_key'],
            'has_qdrant_api_key' => $cfg['has_qdrant_api_key'],
            'has_embedding_api_key' => $cfg['has_embedding_api_key'],
            'has_embedding_cloud_api_key' => $cfg['has_embedding_cloud_api_key'],
            'model_profiles' => AiModelProfiles::publicView($cfg['model_profiles'], $this->maskKey(...)),
            'ai_tasks' => AiTask::catalog(),
            'source' => $cfg['source'],
            'api_key_masked' => $this->maskKey($cfg['api_key']),
            'tavily_api_key_masked' => $this->maskKey($cfg['tavily_api_key']),
            'qdrant_api_key_masked' => $this->maskKey($cfg['qdrant_api_key']),
            'embedding_api_key_masked' => $this->maskKey($cfg['embedding_api_key']),
            'embedding_cloud_api_key_masked' => $this->maskKey($cfg['embedding_cloud_api_key']),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): AiSetting
    {
        $row = AiSetting::query()->first() ?? new AiSetting([
            'enabled' => false,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'enrichment_model' => null,
            'enrichment_use_large_model' => false,
            'timeout_seconds' => 240,
            'temperature' => 0.1,
            'web_search_enabled' => false,
            'search_engine' => self::SEARCH_ENGINE_TAVILY,
            'search_fallback' => 'tavily',
            'tavily_search_mode' => TavilySearchProfile::MODE_BALANCED,
            'enrichment_batch_limit' => 5,
            'match_concurrency' => 4,
            'vector_enabled' => false,
            'qdrant_url' => 'http://127.0.0.1:6333',
            'qdrant_collection' => 'products',
            'embedding_model' => self::OPENAI_EMBEDDING_MODEL,
            'embedding_provider' => self::EMBEDDING_LOCAL,
        ]);

        $embeddingTargetBefore = $this->embeddingTarget($row);

        foreach ([
            'enabled',
            'provider',
            'base_url',
            'model',
            'timeout_seconds',
            'temperature',
            'web_search_enabled',
            'search_fallback',
            'vector_enabled',
            'qdrant_url',
            'qdrant_collection',
            'embedding_model',
            'embedding_base_url',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $row->{$field} = $data[$field];
            }
        }

        if (array_key_exists('enrichment_model', $data)) {
            $row->enrichment_model = $this->nullableString($data['enrichment_model']);
        }

        if (array_key_exists('enrichment_use_large_model', $data)
            && Schema::hasColumn('ai_settings', 'enrichment_use_large_model')) {
            $row->enrichment_use_large_model = (bool) $data['enrichment_use_large_model'];
        }

        if (array_key_exists('search_engine', $data) && Schema::hasColumn('ai_settings', 'search_engine')) {
            $row->search_engine = $this->normalizeSearchEngine($data['search_engine']);
        }

        if (array_key_exists('searxng_url', $data) && Schema::hasColumn('ai_settings', 'searxng_url')) {
            $row->searxng_url = $this->nullableUrl($data['searxng_url']);
        }

        if (array_key_exists('tavily_search_mode', $data) && Schema::hasColumn('ai_settings', 'tavily_search_mode')) {
            $row->tavily_search_mode = $this->normalizeTavilySearchMode($data['tavily_search_mode']);
        }

        if (array_key_exists('enrichment_batch_limit', $data)
            && Schema::hasColumn('ai_settings', 'enrichment_batch_limit')) {
            $row->enrichment_batch_limit = $this->normalizeEnrichmentBatchLimit($data['enrichment_batch_limit']);
        }

        if (array_key_exists('match_concurrency', $data)
            && Schema::hasColumn('ai_settings', 'match_concurrency')) {
            $row->match_concurrency = $this->normalizeMatchConcurrency($data['match_concurrency']);
        }

        if (Schema::hasColumn('ai_settings', 'embedding_provider')) {
            if (array_key_exists('embedding_provider', $data)) {
                $row->embedding_provider = $this->normalizeEmbeddingProvider($data['embedding_provider']);
            }
            if (array_key_exists('embedding_cloud_model', $data)) {
                $row->embedding_cloud_model = $this->nullableString($data['embedding_cloud_model']);
            }
        }

        if (array_key_exists('model_profiles', $data) && Schema::hasColumn('ai_settings', 'model_profiles')) {
            $row->model_profiles = AiModelProfiles::normalize($data['model_profiles'], $this->safeProfiles($row));
        }

        $this->applySecret($row, 'api_key', $data);
        $this->applySecret($row, 'tavily_api_key', $data);
        $this->applySecret($row, 'qdrant_api_key', $data);
        $this->applySecret($row, 'embedding_api_key', $data);
        $this->applySecret($row, 'embedding_cloud_api_key', $data);

        if (array_key_exists('tavily_api_key', $data)
            && is_string($data['tavily_api_key'] ?? null)
            && trim((string) $data['tavily_api_key']) !== ''
            && ! str_contains((string) $data['tavily_api_key'], '*')) {
            TavilyQuotaGuard::clear();
        }

        $row->base_url = rtrim((string) $row->base_url, '/');
        if (is_string($row->embedding_base_url) && $row->embedding_base_url !== '') {
            $row->embedding_base_url = rtrim($row->embedding_base_url, '/');
        }
        if (is_string($row->qdrant_url) && $row->qdrant_url !== '') {
            $row->qdrant_url = rtrim($row->qdrant_url, '/');
        }
        $indexTargetChanged = $row->exists && $embeddingTargetBefore !== $this->embeddingTarget($row);
        $row->save();

        if ($indexTargetChanged) {
            $this->resetEmbeddingIndexState();
        }

        return $row;
    }

    /** Zmiana dostawcy albo modelu unieważnia wektory — inny wymiar i inna przestrzeń. */
    private function embeddingTarget(AiSetting $row): string
    {
        $provider = Schema::hasColumn('ai_settings', 'embedding_provider')
            ? $this->normalizeEmbeddingProvider($row->embedding_provider ?? null)
            : self::EMBEDDING_LOCAL;

        $model = $provider === self::EMBEDDING_LOCAL
            ? (string) ($row->embedding_model ?? '')
            : (string) ($row->embedding_cloud_model ?? '');

        return $provider.'|'.$model.'|'.(string) ($row->embedding_base_url ?? '');
    }

    /**
     * Po zmianie celu indeksowania hashe muszą zniknąć — inaczej reindeks
     * pominąłby produkty jako „już zsynchronizowane”, a kolekcja zostałaby pusta.
     */
    private function resetEmbeddingIndexState(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'embedding_hash')) {
            return;
        }

        Product::query()
            ->whereNotNull('embedding_hash')
            ->orWhereNotNull('embedding_synced_at')
            ->update(['embedding_hash' => null, 'embedding_synced_at' => null]);
    }

    /**
     * @return array{provider: string, base_url: string, api_key: ?string, model: string}
     */
    public function embeddingProfile(): array
    {
        $cfg = $this->resolve();
        $provider = $cfg['embedding_provider'];

        if (isset(self::EMBEDDING_CLOUD_BASE_URLS[$provider])) {
            // Klucz czatu ratuje sytuację tylko wtedy, gdy prowadzi do tego samego dostawcy.
            $sharedKey = str_starts_with($cfg['base_url'], self::EMBEDDING_CLOUD_BASE_URLS[$provider])
                ? $cfg['api_key']
                : null;

            return [
                'provider' => $provider,
                'base_url' => self::EMBEDDING_CLOUD_BASE_URLS[$provider],
                'api_key' => $cfg['embedding_cloud_api_key'] ?: $sharedKey,
                'model' => (string) ($cfg['embedding_cloud_model'] ?: self::EMBEDDING_CLOUD_DEFAULT_MODELS[$provider]),
            ];
        }

        return [
            'provider' => self::EMBEDDING_LOCAL,
            'base_url' => rtrim((string) ($cfg['embedding_base_url'] ?: $cfg['base_url']), '/'),
            'api_key' => $cfg['embedding_api_key'] ?: $cfg['api_key'],
            'model' => (string) ($cfg['embedding_model'] ?: self::OPENAI_EMBEDDING_MODEL),
        ];
    }

    /**
     * Nazwa kolekcji Qdrant dla aktywnego profilu embeddingów.
     *
     * @param  array<string, mixed>|null  $cfg
     */
    public function embeddingCollection(?array $cfg = null): string
    {
        $cfg ??= $this->resolve();
        $name = trim((string) ($cfg['qdrant_collection'] ?: 'products'));
        $name = $name !== '' ? $name : 'products';

        return $cfg['embedding_provider'] === self::EMBEDDING_LOCAL
            ? $name
            : $name.'_'.$cfg['embedding_provider'];
    }

    public function isReady(): bool
    {
        $cfg = $this->resolve();

        return $cfg['enabled']
            && $cfg['has_api_key']
            && $cfg['base_url'] !== ''
            && $cfg['model'] !== '';
    }

    public function isVectorReady(): bool
    {
        $cfg = $this->resolve();

        return (bool) $cfg['vector_enabled']
            && is_string($cfg['qdrant_url'] ?? null)
            && trim((string) $cfg['qdrant_url']) !== '';
    }

    /**
     * Profil obsługujący zadanie. Puste pola profilu schodzą do konfiguracji głównej,
     * więc „ten sam serwer, inny model” to profil z wypełnionym samym modelem.
     *
     * @return array{
     *     label: string,
     *     base_url: string,
     *     api_key: ?string,
     *     model: string,
     *     timeout_seconds: int,
     *     temperature: float,
     *     is_default: bool
     * }
     */
    public function profileForTask(?AiTask $task): array
    {
        $cfg = $this->resolve();
        $fallback = [
            'label' => 'konfiguracja główna',
            'base_url' => (string) $cfg['base_url'],
            'api_key' => $cfg['api_key'],
            'model' => (string) $cfg['model'],
            'timeout_seconds' => (int) $cfg['timeout_seconds'],
            'temperature' => (float) $cfg['temperature'],
            'is_default' => true,
        ];

        if ($task === null) {
            return $fallback;
        }

        $profile = AiModelProfiles::forTask($cfg['model_profiles'], $task);
        if ($profile === null) {
            return $fallback;
        }

        $baseUrl = $this->nullableUrl($profile['base_url'] ?? null) ?? $fallback['base_url'];
        $apiKey = $this->nullableString($profile['api_key'] ?? null);

        return [
            'label' => $this->nullableString($profile['name'] ?? null) ?? 'profil bez nazwy',
            'base_url' => $baseUrl,
            // Własny klucz obowiązuje tylko przy własnym adresie — inaczej wysłalibyśmy
            // klucz OpenRoutera na lokalny serwer albo odwrotnie.
            'api_key' => $apiKey ?? ($baseUrl === $fallback['base_url'] ? $fallback['api_key'] : null),
            'model' => $this->nullableString($profile['model'] ?? null) ?? $fallback['model'],
            'timeout_seconds' => is_numeric($profile['timeout_seconds'] ?? null)
                ? (int) $profile['timeout_seconds']
                : $fallback['timeout_seconds'],
            'temperature' => is_numeric($profile['temperature'] ?? null)
                ? (float) $profile['temperature']
                : $fallback['temperature'],
            'is_default' => false,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function safeProfiles(AiSetting $row): array
    {
        if (! array_key_exists('model_profiles', $row->getAttributes())) {
            return [];
        }

        try {
            $value = $row->model_profiles;
        } catch (DecryptException|Throwable) {
            return [];
        }

        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    /** Model do opisów produktów (tani); pusty enrichment_model → model główny. */
    public function enrichmentModel(): string
    {
        $cfg = $this->resolve();
        if ($this->enrichmentUsesLargeModelFrom($cfg)) {
            return (string) $cfg['model'];
        }
        $cheap = trim((string) ($cfg['enrichment_model'] ?? ''));

        return $cheap !== '' ? $cheap : (string) $cfg['model'];
    }

    /** Wyszukiwanie i opis produktów wyłącznie modelem głównym (AI web search). */
    public function enrichmentUsesLargeModel(): bool
    {
        return $this->enrichmentUsesLargeModelFrom($this->resolve());
    }

    public function searchEngine(): string
    {
        return $this->normalizeSearchEngine($this->resolve()['search_engine'] ?? null);
    }

    /** Darmowe szukanie po stronie PHP (SearXNG / publiczne wyszukiwarki) — bez kredytów Tavily. */
    public function usesFreeWebSearch(): bool
    {
        return in_array(
            $this->searchEngine(),
            [self::SEARCH_ENGINE_DUCKDUCKGO, self::SEARCH_ENGINE_SEARXNG],
            true
        );
    }

    public function usesTavilySearch(): bool
    {
        return $this->searchEngine() === self::SEARCH_ENGINE_TAVILY;
    }

    /** Adres własnej instancji SearXNG (z włączonym formatem json) albo null. */
    public function searxngUrl(): ?string
    {
        if ($this->searchEngine() !== self::SEARCH_ENGINE_SEARXNG) {
            return null;
        }

        return $this->nullableUrl($this->resolve()['searxng_url'] ?? null);
    }

    public function tavilySearchProfile(): TavilySearchProfile
    {
        $cfg = $this->resolve();

        return TavilySearchProfile::fromMode($cfg['tavily_search_mode'] ?? null);
    }

    public function enrichmentBatchLimit(): int
    {
        $cfg = $this->resolve();

        return $this->normalizeEnrichmentBatchLimit($cfg['enrichment_batch_limit'] ?? null);
    }

    public function matchConcurrency(): int
    {
        $cfg = $this->resolve();

        return $this->normalizeMatchConcurrency($cfg['match_concurrency'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function enrichmentUsesLargeModelFrom(array $cfg): bool
    {
        return (bool) ($cfg['enrichment_use_large_model'] ?? false);
    }

    private function boolColumn(AiSetting $row, string $column, bool $default): bool
    {
        if (! Schema::hasColumn('ai_settings', $column)) {
            return $default;
        }

        return (bool) ($row->{$column} ?? $default);
    }

    private function normalizeEmbeddingProvider(mixed $value): string
    {
        $provider = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($provider, self::EMBEDDING_PROVIDERS, true)
            ? $provider
            : self::EMBEDDING_LOCAL;
    }

    private function normalizeSearchEngine(mixed $value): string
    {
        $engine = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($engine, self::SEARCH_ENGINES, true)
            ? $engine
            : self::SEARCH_ENGINE_TAVILY;
    }

    private function normalizeTavilySearchMode(mixed $value): string
    {
        $mode = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($mode, TavilySearchProfile::MODES, true)
            ? $mode
            : TavilySearchProfile::MODE_BALANCED;
    }

    private function normalizeEnrichmentBatchLimit(mixed $value): int
    {
        $n = is_numeric($value) ? (int) $value : 5;

        return max(1, min(self::ENRICHMENT_BATCH_MAX, $n));
    }

    private function normalizeMatchConcurrency(mixed $value): int
    {
        $n = is_numeric($value) ? (int) $value : 4;

        return max(1, min(self::CONCURRENCY_MAX, $n));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $v = trim($value);

        return $v !== '' ? $v : null;
    }

    private function nullableUrl(mixed $value): ?string
    {
        $v = $this->nullableString($value);

        return $v !== null ? rtrim($v, '/') : null;
    }

    private function maskKey(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }
        $len = strlen($key);

        return $len <= 8
            ? str_repeat('*', $len)
            : substr($key, 0, 3).str_repeat('*', max(4, $len - 7)).substr($key, -4);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applySecret(AiSetting $row, string $field, array $data): void
    {
        if (! array_key_exists($field, $data)) {
            return;
        }

        $incoming = $data[$field];
        if (! is_string($incoming)) {
            return;
        }
        $incoming = trim($incoming);
        if ($incoming === '' || str_contains($incoming, '*')) {
            return;
        }

        $row->{$field} = $incoming;
    }

    /** Odszyfrowanie tajnych pól — przy złym APP_KEY (np. po db-push) nie wywala całego /ai-settings. */
    private function safeEncrypted(AiSetting $row, string $field): ?string
    {
        if (! array_key_exists($field, $row->getAttributes())) {
            return null;
        }

        try {
            $value = $row->{$field};
        } catch (DecryptException|Throwable) {
            return null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
