<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plik cennika zapisany na dysku (10.10.2026) — wejście importera per cennik. Ścieżka `price-list-files/{sha256}.{ext}`
 * na dysku `disk` (domyślnie local = storage/app/private albo storage/app — zależnie od konfiguracji dysku).
 */
class PriceListFile extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUSES = [self::STATUS_NEW, self::STATUS_IMPORTED, self::STATUS_FAILED, self::STATUS_SUPERSEDED];

    protected $fillable = [
        'price_list_id',
        'sha256',
        'disk',
        'path',
        'original_name',
        'size',
        'mime',
        'uploaded_by',
        'status',
        'error',
        'importer_key',
        'importer_version',
        'price_list_import_id',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'importer_version' => 'integer',
            'imported_at' => 'datetime',
        ];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function priceListImport(): BelongsTo
    {
        return $this->belongsTo(PriceListImport::class);
    }
}
