<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sygnał zwrotny do wyniku wyszukiwania: co użytkownik otworzył, co wybrał,
 * co trafiło do oferty. To on odróżnia „model coś zwrócił” od „model trafił”.
 */
class SearchEventAction extends Model
{
    /** Wejście w kartę produktu z listy wyników. */
    public const ACTION_OPEN = 'open';

    /** Wybór produktu w modalu dopasowania. */
    public const ACTION_PICK = 'pick';

    /** Wstawienie produktu do pozycji oferty — najmocniejszy sygnał. */
    public const ACTION_ADD_TO_OFFER = 'add_to_offer';

    /** @var list<string> */
    public const ACTIONS = [
        self::ACTION_OPEN,
        self::ACTION_PICK,
        self::ACTION_ADD_TO_OFFER,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'search_event_id',
        'product_id',
        'user_id',
        'action',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function searchEvent(): BelongsTo
    {
        return $this->belongsTo(SearchEvent::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
