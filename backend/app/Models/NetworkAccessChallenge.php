<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jedno logowanie spoza sieci lokalnej po dobrym haśle (tryb `local_code`): klucz challenge (w bazie sha256),
 * kod z e-maila (HMAC), liczniki. Zob. NetworkAccessCodeService.
 */
class NetworkAccessChallenge extends Model
{
    protected $fillable = [
        'user_id',
        'token_hash',
        'ip',
        'code_hash',
        'last_sent_at',
        'attempts',
        'expires_at',
        'used_at',
    ];

    protected $hidden = [
        'token_hash',
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
