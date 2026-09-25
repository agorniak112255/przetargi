<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jedno wykonanie wyszukiwania AI: co poszło do retrievalu, co zobaczył model,
 * co wróciło do użytkownika. Razem z `search_event_actions` (klik / wstawienie
 * do oferty) to jedyne źródło danych o jakości wyszukiwarki — z niego rośnie
 * golden set do `search:eval`.
 */
class SearchEvent extends Model
{
    public const RETENTION_DAYS = 180;

    public const TASK_PRODUCT_SEARCH = 'product_search';

    public const TASK_PRODUCT_SEARCH_WEB = 'product_search_web';

    /** Pozycja z fali „Dopasuj wszystkie” (prefetch paczki pozycji przetargu). */
    public const TASK_TENDER_MATCH = 'tender_match';

    /** Pojedyncze wyszukiwanie pozycji przetargu („Dopasuj” jednej pozycji albo zapas po awarii fali). */
    public const TASK_TENDER_ITEM = 'tender_item';

    /** Pozycja zapytania z poczty (przy tworzeniu zapytania i przy inquiries:rematch). */
    public const TASK_INQUIRY = 'inquiry';

    /** Druga runda wyszukiwania pod zamienniki pozycji przetargu (BattlecardService). */
    public const TASK_BATTLECARD = 'battlecard';

    /** @var list<string> rodzaje pozycji, które wołają model — ekran „Statystyki AI” */
    public const AI_TASKS = [
        self::TASK_PRODUCT_SEARCH,
        self::TASK_TENDER_MATCH,
        self::TASK_TENDER_ITEM,
        self::TASK_INQUIRY,
        self::TASK_BATTLECARD,
    ];

    public const CONTEXT_TENDER = 'tender';

    public const CONTEXT_INQUIRY = 'inquiry';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'task',
        'prompt_version',
        'query',
        'needed',
        'intent',
        'candidate_ids',
        'rank_card_ids',
        'llm_matches',
        'returned',
        'result_count',
        'candidate_count',
        'passes',
        'duration_ms',
        'timings_ms',
        'ai_note',
        'prompt_tokens',
        'completion_tokens',
        'reasoning_tokens',
        'llm_calls',
        'rank_calls',
        'rank_card_count',
        'model_state',
        'model',
        'provider',
        'fallback',
        'usage',
        'run_id',
        'context_type',
        'context_id',
        'context_items',
    ];

    protected function casts(): array
    {
        return [
            'intent' => 'array',
            'candidate_ids' => 'array',
            'rank_card_ids' => 'array',
            'llm_matches' => 'array',
            'returned' => 'array',
            'timings_ms' => 'array',
            'result_count' => 'integer',
            'candidate_count' => 'integer',
            'passes' => 'integer',
            'duration_ms' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'llm_calls' => 'integer',
            'rank_calls' => 'integer',
            'rank_card_count' => 'integer',
            'fallback' => 'boolean',
            'usage' => 'array',
            'context_id' => 'integer',
            'context_items' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(SearchEventAction::class);
    }

    /** Pozycja produktu na oddanej liście (1 = pierwszy) albo null. */
    public function positionOf(int $productId): ?int
    {
        $returned = is_array($this->returned) ? $this->returned : [];
        foreach (array_values($returned) as $i => $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) === $productId) {
                return $i + 1;
            }
        }

        return null;
    }
}
