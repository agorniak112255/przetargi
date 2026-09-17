<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientInquiry extends Model
{
    protected $fillable = [
        'user_id',
        'client_id',
        'tone',
        'source_channel',
        'source_subject',
        'source_message_id',
        'source_body',
        'analysis',
        'answers',
        'extra_note',
        'reply_subject',
        'reply_body',
        'replied_at',
    ];

    protected function casts(): array
    {
        return [
            'analysis' => 'array',
            'answers' => 'array',
            'replied_at' => 'datetime',
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
