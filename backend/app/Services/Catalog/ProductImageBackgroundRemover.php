<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\ProductImage;
use GdImage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Wycinanie tła ze zdjęcia karty przez rembg (config/image_background.php) — ręcznie zlecane w panelu (decyzja
 * właściciela 09.10.2026: karta w edycji albo zaznaczone na liście; „tylko bez tła”, oryginał do przywrócenia).
 *
 * Wynik zastępuje plik zdjęcia (`path` → nowy PNG), oryginał zostaje na dysku pod `original_path`; `checksum` się
 * nie zmienia — to tożsamość pobranego pliku (dedup synchronizacji, odrzucenia zdjęć). Zdjęcie przezroczyste już
 * na brzegach (PNG producenta) pomijamy. Wynik bez produktu (prawie pusta maska) albo bez wyciętego tła (maska na
 * cały kadr) nie zastępuje oryginału — status „failed” z powodem.
 *
 * Model zostawia lustrzane odbicie pod produktem (zdjęcia VM na czarnym szkle) — sprawdzone 08.10.2026 na czterech
 * modelach; o wycięciu takiej karty decyduje człowiek, oryginał wraca przyciskiem „Przywróć oryginał”.
 */
final class ProductImageBackgroundRemover
{
    /** Zapytania do rembg po jednym naraz w całej aplikacji — dwa równoległe przekraczały pamięć kontenera. */
    private const LOCK = 'image-background:rembg';

    /** Ile czekamy na blokadę, zanim zadanie wróci do kolejki (przy jednym workerze to tylko bezpiecznik). */
    private const LOCK_WAIT_SECONDS = 5;

    /** Tyle pikseli najwyżej dekodujemy w GD (~5 B na piksel; 6000×4000 to ok. 120 MB). */
    private const MAX_PIXELS = 25_000_000;

    /** Punkty brzegu zdjęcia przezroczyste w tylu z ośmiu = zdjęcie już bez tła. */
    private const TRANSPARENT_EDGE_POINTS = 3;

    /**
     * @return string status zapisany przy zdjęciu (ProductImage::BACKGROUND_*), 'gone' = zdjęcie zniknęło w trakcie
     *
     * @throws LockTimeoutException inne zapytanie do rembg trwa — zadanie ma wrócić do kolejki
     */
    public function remove(ProductImage $image): string
    {
        if ($image->hasBackgroundRemoved()) {
            return $this->mark($image, ProductImage::BACKGROUND_DONE, null);
        }
        $path = (string) $image->path;
        if ($path === '' || $path === 'remote' || preg_match('#^https?://#i', $path) === 1) {
            return $this->mark($image, ProductImage::BACKGROUND_SKIPPED, 'zdjęcie bez pliku na serwerze (adres zewnętrzny)');
        }
        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return $this->mark($image, ProductImage::BACKGROUND_FAILED, 'brak pliku zdjęcia na serwerze');
        }
        $bytes = (string) $disk->get($path);
        $info = $bytes !== '' ? @getimagesizefromstring($bytes) : false;
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            return $this->mark($image, ProductImage::BACKGROUND_FAILED, 'plik nie jest zdjęciem JPG, PNG, GIF ani WEBP');
        }
        if ((int) $info[0] * (int) $info[1] > self::MAX_PIXELS) {
            return $this->mark($image, ProductImage::BACKGROUND_FAILED, 'zdjęcie za duże ('.$info[0].'×'.$info[1].' px)');
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source instanceof GdImage) {
            return $this->mark($image, ProductImage::BACKGROUND_FAILED, 'nie da się odczytać zdjęcia');
        }
        if (self::isTransparentAtEdges($source)) {
            return $this->mark($image, ProductImage::BACKGROUND_SKIPPED, 'zdjęcie już bez tła');
        }
        $send = $this->forService($source);

        $lock = Cache::lock(self::LOCK, (int) config('image_background.timeout_seconds') + 60);
        $lock->block(self::LOCK_WAIT_SECONDS);
        try {
            $response = Http::timeout((int) config('image_background.timeout_seconds'))
                ->attach('file', $send, 'zdjecie.jpg')
                // model ZAWSZE jawnie: domyślny bria-rmbg wymaga płatnej licencji do użytku komercyjnego
                ->post(config('image_background.url').'/api/remove', ['model' => (string) config('image_background.model')]);
        } catch (ConnectionException $e) {
            return $this->mark($image, ProductImage::BACKGROUND_FAILED, mb_substr('usługa usuwania tła niedostępna: '.$e->getMessage(), 0, 255));
        } finally {
            $lock->release();
        }
        if (! $response->successful()) {
            return $this->mark($image, ProductImage::BACKGROUND_FAILED, 'usługa usuwania tła odpowiedziała HTTP '.$response->status());
        }
        $png = $response->body();
        $cut = str_starts_with($png, "\x89PNG\r\n\x1A\n") ? @imagecreatefromstring($png) : false;
        if (! $cut instanceof GdImage) {
            return $this->mark($image, ProductImage::BACKGROUND_FAILED, 'usługa usuwania tła nie oddała obrazka PNG');
        }
        $coverage = self::opaquePercent($cut);
        if ($coverage < (float) config('image_background.min_coverage_percent')) {
            return $this->mark($image, ProductImage::BACKGROUND_FAILED, 'model nie znalazł produktu na zdjęciu');
        }
        if ($coverage > (float) config('image_background.max_coverage_percent')) {
            return $this->mark($image, ProductImage::BACKGROUND_FAILED, 'model nie wyciął tła (cały kadr uznał za produkt)');
        }

        $newPath = 'products/'.$image->product_id.'/'.Str::lower(Str::random(16)).'.png';
        $disk->put($newPath, $png);
        $saved = DB::transaction(static function () use ($image, $path, $newPath): bool {
            // 20 s modelu to dość, żeby zdjęcie zostało usunięte („×”, kasowanie karty) albo podmienione
            $fresh = ProductImage::query()->lockForUpdate()->find($image->id);
            if ($fresh === null || (string) $fresh->path !== $path || $fresh->hasBackgroundRemoved()) {
                return false;
            }
            $fresh->forceFill([
                'path' => $newPath,
                'original_path' => $path,
                'background_status' => ProductImage::BACKGROUND_DONE,
                'background_note' => null,
                'background_removed_at' => now(),
            ])->save();

            return true;
        });
        if (! $saved) {
            $disk->delete($newPath);

            return 'gone';
        }

        return ProductImage::BACKGROUND_DONE;
    }

    /**
     * Przywraca oryginał; plik wycięcia (nasz, pochodny) kasujemy. false = zdjęcie nie było wycięte.
     */
    public function restore(ProductImage $image): bool
    {
        if (! $image->hasBackgroundRemoved()) {
            return false;
        }
        $cut = (string) $image->path;
        $original = (string) $image->original_path;
        $image->forceFill([
            'path' => $original,
            'original_path' => null,
            'background_status' => null,
            'background_note' => null,
            'background_removed_at' => null,
        ])->save();
        if ($cut !== '' && $cut !== $original && str_starts_with($cut, 'products/')) {
            Storage::disk('public')->delete($cut);
        }

        return true;
    }

    /**
     * Przezroczyste brzegi: rogi i środki boków z alfą > 100 (GD: 0 = kryje, 127 = przezroczyste) w co najmniej
     * TRANSPARENT_EDGE_POINTS z ośmiu punktów. Nagłówek PNG nie wystarcza — packshot RGBA na białym, w pełni
     * kryjącym tle też ma kanał alfa.
     */
    public static function isTransparentAtEdges(GdImage $image): bool
    {
        $w = imagesx($image) - 1;
        $h = imagesy($image) - 1;
        $points = [[0, 0], [$w, 0], [0, $h], [$w, $h], [intdiv($w, 2), 0], [intdiv($w, 2), $h], [0, intdiv($h, 2)], [$w, intdiv($h, 2)]];
        $transparent = 0;
        foreach ($points as [$x, $y]) {
            if (imagecolorsforindex($image, imagecolorat($image, $x, $y))['alpha'] > 100) {
                $transparent++;
            }
        }

        return $transparent >= self::TRANSPARENT_EDGE_POINTS;
    }

    /** Odsetek kryjących pikseli wycięcia (alfa < 64), próbkowanie co 4 piksele. */
    public static function opaquePercent(GdImage $image): float
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $opaque = 0;
        $all = 0;
        for ($y = 0; $y < $h; $y += 4) {
            for ($x = 0; $x < $w; $x += 4) {
                $all++;
                if (imagecolorsforindex($image, imagecolorat($image, $x, $y))['alpha'] < 64) {
                    $opaque++;
                }
            }
        }

        return $all === 0 ? 0.0 : 100 * $opaque / $all;
    }

    /** JPEG do usługi, dłuższy bok najwyżej image_background.max_side (model liczy maskę w 1024 px). */
    private function forService(GdImage $source): string
    {
        $w = imagesx($source);
        $h = imagesy($source);
        $scale = min(1.0, (int) config('image_background.max_side') / max($w, $h));
        $outW = max(1, (int) round($w * $scale));
        $outH = max(1, (int) round($h * $scale));
        $canvas = imagecreatetruecolor($outW, $outH);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $outW, $outH, $w, $h);
        ob_start();
        imagejpeg($canvas, null, 92);

        return (string) ob_get_clean();
    }

    /** Status przy zdjęciu (o ile wiersz jeszcze istnieje); zwraca zapisany status albo 'gone'. */
    private function mark(ProductImage $image, string $status, ?string $note): string
    {
        $updated = ProductImage::query()->whereKey($image->id)->update([
            'background_status' => $status,
            'background_note' => $note,
            'updated_at' => now(),
        ]);
        $image->forceFill(['background_status' => $status, 'background_note' => $note]);

        return $updated > 0 ? $status : 'gone';
    }
}
