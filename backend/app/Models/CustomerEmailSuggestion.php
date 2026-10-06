<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adres e-mail klienta XL znaleziony poza ERP XL (strona firmy, katalog firm, rejestr) — propozycja z dowodem; do ofert
 * trafia dopiero zatwierdzony (status accepted). Zapis: App\Services\Inspections\CustomerEmailFinder.
 */
class CustomerEmailSuggestion extends Model
{
    public const SOURCE_WEBSITE = 'website';

    public const SOURCE_DIRECTORY = 'directory';

    public const EVIDENCE_NIP = 'nip';

    public const EVIDENCE_NAME = 'name';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'customer_xl_gid',
        'email',
        'source',
        'source_url',
        'source_host',
        'evidence',
        'status',
        'decided_by',
        'decided_at',
        'found_at',
    ];

    protected function casts(): array
    {
        return [
            'customer_xl_gid' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'found_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
