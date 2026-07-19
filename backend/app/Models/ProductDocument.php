<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductDocument extends Model
{
    public const KIND_CERTIFICATE = 'certificate';

    public const KIND_DATASHEET = 'datasheet';

    public const KIND_OTHER = 'other';

    protected $fillable = [
        'product_id',
        'path',
        'source_url',
        'title',
        'kind',
        'sort_order',
        'checksum',
        'size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function url(): string
    {
        $basePath = rtrim((string) (parse_url((string) config('app.url'), PHP_URL_PATH) ?: ''), '/');
        if ($basePath === '' || $basePath === '/') {
            return Storage::disk('public')->url($this->path);
        }

        return $basePath.'/storage/'.ltrim($this->path, '/');
    }
}
