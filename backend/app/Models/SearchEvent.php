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
