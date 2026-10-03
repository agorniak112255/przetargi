<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notatka handlowca na karcie klienta. remind_on — dzień (czas polski), w którym crm:remind przypomina autorowi;
 * reminded_at — kiedy przypomnienie wyszło (zmiana remind_on je zeruje).
 *
 * @property int $client_id
 * @property int|null $user_id
 * @property string $body
 */
class ClientNote extends Model
{
    protected $fillable = [
        'client_id',
        'user_id',
        'body',
        'remind_on',
        'reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'client_id' => 'integer',
            'user_id' => 'integer',
            'remind_on' => 'date:Y-m-d',
            'reminded_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
