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
