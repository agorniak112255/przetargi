<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenderDocument extends Model
{
    protected $fillable = [
        'tender_id',
        'uploaded_by',
        'original_name',
        'disk_path',
        'mime',
        'extension',
        'size_bytes',
        'mode',
        'targets',
        'extracted_text',
        'analysis_json',
    ];

    protected function casts(): array
    {
        return [
            'targets' => 'array',
            'analysis_json' => 'array',
            'size_bytes' => 'integer',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(TenderCondition::class);
    }
}
