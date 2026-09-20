<?php

declare(strict_types=1);

namespace App\Models;

use App\Jobs\ReindexProductEmbeddingJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductDocument extends Model
{
    public const KIND_CERTIFICATE = 'certificate';

    public const KIND_DATASHEET = 'datasheet';

    /** Instrukcja obsługi / użytkowania — w przetargach BHP wymagana obok deklaracji zgodności. */
    public const KIND_MANUAL = 'manual';

    public const KIND_WARRANTY = 'warranty';

    public const KIND_SIZE_CHART = 'size_chart';

    public const KIND_OTHER = 'other';

    protected $fillable = [
        'product_id',
        'b2b_account_id',
        'path',
        'source_url',
        'title',
        'text',
        'kind',
        'sort_order',
        'checksum',
        'size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'b2b_account_id' => 'integer',
            'sort_order' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * Tekst karty technicznej wchodzi do dokumentu embeddingu wyrobu (ProductEmbeddingIndexer), a plik trafia
     * przy karcie po jej zapisie — hak na produkcie już nie zadziała, bo w samej karcie nic się nie zmieniło.
     * Stąd zlecenie stąd: bez niego wektor poznałby kartę techniczną dopiero przy najbliższej edycji wyrobu.
     */
    protected static function booted(): void
    {
        static::saved(function (self $document): void {
            if ($document->wasChanged('text') || ($document->wasRecentlyCreated && $document->text !== null)) {
                ReindexProductEmbeddingJob::dispatch((int) $document->product_id);
            }
        });

        static::deleted(function (self $document): void {
            if ($document->text !== null) {
                ReindexProductEmbeddingJob::dispatch((int) $document->product_id);
            }
        });
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
