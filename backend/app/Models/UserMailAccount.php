<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Skrzynka SMTP użytkownika dla kampanii. Hasło zaszyfrowane (APP_KEY) i nigdy nie wraca w odpowiedzi API. */
class UserMailAccount extends Model
{
    public const SCHEMES = ['smtp', 'smtps'];

    protected $fillable = [
        'user_id',
        'from_name',
        'from_address',
        'host',
        'port',
        'scheme',
        'username',
        'password',
        'verify_peer',
        'rate_per_hour',
        'copy_to_self',
        'imap_enabled',
        'imap_host',
        'imap_port',
        'imap_folders',
        'imap_checked_at',
        'imap_error',
        'signature',
        'verified_at',
        'last_error',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
            'verify_peer' => 'boolean',
            'rate_per_hour' => 'integer',
            'copy_to_self' => 'boolean',
            'imap_enabled' => 'boolean',
            'imap_port' => 'integer',
            // pozycja odczytu każdego folderu: nazwa → {v: UIDVALIDITY, u: ostatni UID}
            'imap_folders' => 'array',
            'imap_checked_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
