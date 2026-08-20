<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
        'path',
        'source_url',
        'is_primary',
        'sort_order',
        'checksum',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function url(): string
    {
        $path = (string) $this->path;
        if ($path === '' || $path === 'remote'
            || str_starts_with($path, 'http://')
            || str_starts_with($path, 'https://')) {
            return (string) ($this->source_url ?: $path);
        }

        // Ścieżka względna do Alias /Przetargi (nie zależ od hosta localhost vs 127.0.0.1)
        $basePath = rtrim((string) (parse_url((string) config('app.url'), PHP_URL_PATH) ?: ''), '/');
        if ($basePath === '' || $basePath === '/') {
            return Storage::disk('public')->url($this->path);
        }

        return $basePath.'/storage/'.ltrim($this->path, '/');
    }
}
