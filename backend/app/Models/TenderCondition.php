<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderCondition extends Model
{
    protected $fillable = [
        'tender_id',
        'tender_document_id',
        'sort_order',
        'category',
        'content',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(TenderDocument::class, 'tender_document_id');
    }
}
