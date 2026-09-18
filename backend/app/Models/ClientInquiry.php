<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientInquiry extends Model
{
    /** Pełna specyfikacja: nazwa z katalogu, SKU, producent, normy i cena. */
    public const TONE_HANDLOWY = 'handlowy';

    /** Oficjalny: nazwa, akapit opisu z karty, normy i cena — bez SKU. */
    public const TONE_FORMAL = 'formal';

    /** Bez SKU: jedno zdanie opisu bez marki i modelu, normy i cena. */
    public const TONE_NO_SKU = 'bez_sku';

    /** Szablony listu do klienta; wybór zapisuje się w kolumnie „tone”. */
    public const TONES = [self::TONE_HANDLOWY, self::TONE_FORMAL, self::TONE_NO_SKU];

    protected $fillable = [
        'user_id',
        'client_id',
        'tone',
        'source_channel',
        'source_subject',
        'source_message_id',
        'source_fingerprint',
        'source_fingerprint_tail',
        'duplicate_of_id',
        'source_from_name',
        'source_from_email',
        'source_sent_at',
        'contact',
        'source_body',
        'analysis',
        'answers',
        'extra_note',
        'reply_subject',
        'reply_body',
        'reply_html',
        'replied_at',
        'send_requested_at',
    ];

    protected function casts(): array
    {
        return [
            'analysis' => 'array',
            'answers' => 'array',
            'replied_at' => 'datetime',
            'send_requested_at' => 'datetime',
            'source_sent_at' => 'datetime',
            'contact' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
