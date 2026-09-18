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
        'b2b_account_id',
        'path',
        'source_url',
        'is_primary',
        'sort_order',
        'checksum',
    ];

    /**
     * Ustala kolejność zdjęć karty i wskazuje główne. Jedna reguła pierwszeństwa dla wszystkich
     * źródeł, bo zapisują je trzy niezależne miejsca (synchronizacja B2B, wzbogacanie z sieci,
     * przeniesienie z PrestaShopu) i każde liczyło `sort_order` po swojemu — potrafiły powstać dwa
     * zdjęcia główne i dwa o tym samym numerze.
     *
     * Pierwszeństwo: witryna producenta wyrobu, potem pozostali dostawcy, na końcu zdjęcie wyłowione
     * z internetu. Packshot ze sklepu producenta przedstawia ten konkretny wariant wyrobu; dystrybutor
     * pokazuje przy karcie zdjęcie poglądowe (bywa nim inny wariant tej serii), a zdjęcie znalezione
     * przez model przy cudzej karcie bywa innym kolorem albo innym modelem.
     *
     * $manufacturerAccountId to konto B2B witryny producenta tej karty — podaje je synchronizacja,
     * bo tylko ona wie, czy marka konta zgadza się z marką wyrobu (sklep producenta bywa też sklepem
     * cudzych marek). Bez niego kolejność jest ta sama co dotąd: dostawcy przed siecią.
     *
     * Zapisuje tylko wiersze, które faktycznie zmieniają miejsce — wołanie tego po każdym zapisie
     * zdjęcia nie może przestawiać karty w kółko.
     */
    public static function resequence(int $productId, ?int $manufacturerAccountId = null): void
    {
        $images = self::query()
            ->where('product_id', $productId)
            // 0 = producent, 1 = inny dostawca, 2 = z sieci. Bez konta producenta żaden wiersz nie
            // trafia do 0 (identyfikatory kont są dodatnie), więc zostaje podział dostawca/sieć.
            ->orderByRaw(
                'CASE WHEN b2b_account_id = ? THEN 0 WHEN b2b_account_id IS NULL THEN 2 ELSE 1 END',
                [$manufacturerAccountId ?? 0],
            )
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $position = 0;
        foreach ($images as $image) {
            $primary = $position === 0;
            if ((int) $image->sort_order !== $position || (bool) $image->is_primary !== $primary) {
                $image->forceFill(['sort_order' => $position, 'is_primary' => $primary])->save();
            }
            $position++;
        }
    }

    protected $appends = [
        'url',
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
        return $this->publicUrl();
    }

    public function getUrlAttribute(): string
    {
        return $this->publicUrl();
    }

    public function thumbUrl(): string
    {
        return route('product-images.thumb', $this);
    }

    private function publicUrl(): string
    {
        $path = (string) $this->path;
        if ($path === '' || $path === 'remote'
            || str_starts_with($path, 'http://')
            || str_starts_with($path, 'https://')) {
            return (string) ($this->source_url ?: $path);
        }

        if (! Storage::disk('public')->exists($path)) {
            $source = (string) ($this->source_url ?? '');
            if (str_starts_with($source, 'http://') || str_starts_with($source, 'https://')) {
                return $source;
            }
        }

        $basePath = rtrim((string) (parse_url((string) config('app.url'), PHP_URL_PATH) ?: ''), '/');
        if ($basePath === '' || $basePath === '/') {
            return Storage::disk('public')->url($this->path);
        }

        return $basePath.'/storage/'.ltrim($this->path, '/');
    }
}
