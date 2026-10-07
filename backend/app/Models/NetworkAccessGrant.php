<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Konto może pracować spoza sieci lokalnej z adresu `ip` (IPv4 albo sieć IPv6 /64) do `expires_at` —
 * po kodzie z e-maila (NetworkAccessCodeService). Zob. NetworkAccessPolicy.
 */
class NetworkAccessGrant extends Model
{
    protected $fillable = [
        'user_id',
        'ip',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
