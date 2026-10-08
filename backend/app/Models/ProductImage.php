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
        'original_path',
        'background_status',
        'background_note',
        'background_removed_at',
    ];

    /** Usuwanie tła zlecone, zdjęcie czeka w kolejce `images`. */
    public const BACKGROUND_QUEUED = 'queued';

    /** Tło wycięte: `path` = PNG bez tła, `original_path` = oryginał do przywrócenia. */
    public const BACKGROUND_DONE = 'done';

    /** Nie udało się (usługa, bramka wyniku) — oryginał bez zmian, powód w `background_note`. */
    public const BACKGROUND_FAILED = 'failed';

    /** Nie było czego wycinać (zdjęcie już przezroczyste, zdjęcie zdalne) — powód w `background_note`. */
    public const BACKGROUND_SKIPPED = 'skipped';

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
            'background_removed_at' => 'datetime',
        ];
    }

    /** Czy `path` wskazuje wycięcie (PNG bez tła), a oryginał leży pod `original_path`. */
    public function hasBackgroundRemoved(): bool
    {
        return ($this->getAttributes()['original_path'] ?? null) !== null;
    }

    /**
     * Zdjęcie w odpowiedziach panelu (lista produktów, karta, usuwanie zdjęć i tła) — jeden kształt.
     *
     * @return array{id: int, url: string, thumb_url: string, source_url: string|null, is_primary: bool, sort_order: int, background: array{status: string, note: string|null, removed_at: string|null}|null}
     */
    public function panelView(): array
    {
        return [
            'id' => (int) $this->id,
            'url' => $this->url(),
            'thumb_url' => $this->thumbUrl(),
            'source_url' => $this->source_url,
            'is_primary' => (bool) $this->is_primary,
            'sort_order' => (int) $this->sort_order,
            'background' => $this->backgroundView(),
        ];
    }

    /**
     * Stan usuwania tła dla panelu: null = nikt nie zlecał.
     *
     * @return array{status: string, note: string|null, removed_at: string|null}|null
     */
    public function backgroundView(): ?array
    {
        $status = $this->getAttributes()['background_status'] ?? null;
        if ($status === null) {
            return null;
        }

        return [
            'status' => (string) $status,
            'note' => $this->background_note,
            'removed_at' => $this->background_removed_at?->toIso8601String(),
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

    /**
     * Miniatura idzie z nagłówkiem cache na 30 dni pod stałym adresem — po wycięciu tła (inny plik pod tym samym id)
     * adres dostaje wersję z bieżącego pliku, inaczej przeglądarka pokazywałaby starą miniaturę z tłem. Wersja tylko
     * przy wyciętym tle: pozostałe adresy bez zmian. Zapytanie bez kolumn path/original_path daje adres bez wersji.
     */
    public function thumbUrl(): string
    {
        $attributes = $this->getAttributes();
        if (($attributes['original_path'] ?? null) !== null && isset($attributes['path'])) {
            return route('product-images.thumb', ['image' => $this, 'v' => substr(sha1((string) $attributes['path']), 0, 10)]);
        }

        return route('product-images.thumb', $this);
    }

    /**
     * Kwadrat na białym tle pod pełnym adresem aplikacji — do listu, który czyta klient bez logowania.
     * Adres publiczny jak miniatura; dodatek Thunderbirda osadza obrazek z tego adresu w mailu.
     */
    public function squareUrl(): string
    {
        return route('product-images.square', $this);
    }

    /**
     * Zdjęcie główne każdej karty (bez znacznika — pierwsze w kolejności karty), jednym zapytaniem.
     *
     * @param  list<int>  $productIds
     * @return array<int, self> klucz = id karty; karta bez zdjęcia nie ma wpisu
     */
    public static function primaryFor(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($productIds === []) {
            return [];
        }

        $out = [];
        foreach (self::query()
            ->whereIn('product_id', $productIds)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'product_id', 'path', 'original_path', 'source_url']) as $image) {
            $out[(int) $image->product_id] ??= $image;
        }

        return $out;
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
