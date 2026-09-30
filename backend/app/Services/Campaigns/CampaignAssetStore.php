<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\CampaignAsset;
use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Obrazki do maili kampanii (logo, grafika): zawsze przekodowane przez GD — z pliku zostają same piksele (bez
 * metadanych i doklejonych treści). Szerokość najwyżej MAX_WIDTH; z przezroczystością → PNG, reszta → JPEG na białym
 * tle. Oryginał nie jest zapisywany; plików nie kasujemy (wysłane maile je wskazują).
 */
class CampaignAssetStore
{
    public const MAX_WIDTH = 1200;

    /** Dłuższy bok wgrywanego pliku — większe to raczej pułapka na pamięć niż grafika do maila. */
    public const MAX_SOURCE_SIDE = 6000;

    public const NOT_IMAGE = 'To nie jest obrazek JPG, PNG, GIF ani WEBP.';

    private const JPEG_QUALITY = 85;

    /** Adres obrazka w mailu: publiczny adres aplikacji, a bez niego (lokalnie) adres z trasy. */
    public static function url(string $uuid): string
    {
        $base = rtrim((string) config('campaigns.public_url'), '/');

        return $base !== '' ? $base.'/api/campaign-assets/'.$uuid : route('campaign-assets.show', ['uuid' => $uuid]);
    }

    public function store(UploadedFile $file, User $user): CampaignAsset
    {
        $path = (string) $file->getRealPath();
        $info = $path !== '' ? @getimagesize($path) : false;
        if ($info === false
            || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)
            || $info[0] < 1 || $info[1] < 1
            || max($info[0], $info[1]) > self::MAX_SOURCE_SIDE) {
            throw ValidationException::withMessages(['file' => [self::NOT_IMAGE]]);
        }
        if (! $this->fitsInMemory($info[0], $info[1])) {
            throw ValidationException::withMessages(['file' => ['Obrazek ma za dużo pikseli ('.$info[0].'×'.$info[1].') — zmniejsz go przed wgraniem.']]);
        }
        $bytes = (string) file_get_contents($path);
        $source = @imagecreatefromstring($bytes);
        if (! $source instanceof GdImage) {
            throw ValidationException::withMessages(['file' => [self::NOT_IMAGE]]);
        }

        $alpha = $this->hasAlpha($info[2], $bytes, $source);
        $width = imagesx($source);
        $height = imagesy($source);
        $outWidth = min($width, self::MAX_WIDTH);
        $outHeight = max(1, (int) round($height * $outWidth / $width));

        $canvas = imagecreatetruecolor($outWidth, $outHeight);
        if ($alpha) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        } else {
            // spłaszczenie na białe tło (JPEG nie ma przezroczystości)
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagealphablending($canvas, true);
        }
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $outWidth, $outHeight, $width, $height);
        imagedestroy($source);

        ob_start();
        $alpha ? imagepng($canvas, null, 9) : imagejpeg($canvas, null, self::JPEG_QUALITY);
        $encoded = (string) ob_get_clean();
        imagedestroy($canvas);

        $uuid = (string) Str::uuid();
        $stored = 'campaign-assets/'.$uuid.($alpha ? '.png' : '.jpg');
        Storage::disk('local')->put($stored, $encoded);

        return CampaignAsset::query()->create([
            'uuid' => $uuid,
            'user_id' => $user->id,
            'path' => $stored,
            'mime' => $alpha ? 'image/png' : 'image/jpeg',
            'width' => $outWidth,
            'height' => $outHeight,
            'size' => strlen($encoded),
        ]);
    }

    /**
     * GD trzyma cały obrazek w pamięci (~5 B na piksel): 6000×6000 to ~180 MB. Przy niskim memory_limit odmowa 422
     * zamiast błędu 500 w połowie dekodowania.
     */
    private function fitsInMemory(int $width, int $height): bool
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return true;
        }
        $bytes = (int) $limit;
        $bytes *= match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return memory_get_usage() + $width * $height * 5 + 16 * 1024 * 1024 <= $bytes;
    }

    /**
     * Czy obrazek ma przezroczystość: PNG z kanałem alfa albo tRNS, GIF z kolorem przezroczystym, WEBP z flagą alfy
     * (VP8X albo bezstratny VP8L). JPEG nigdy.
     */
    private function hasAlpha(int $type, string $bytes, GdImage $image): bool
    {
        if ($type === IMAGETYPE_PNG) {
            $colorType = strlen($bytes) > 25 ? ord($bytes[25]) : 0;

            return $colorType === 4 || $colorType === 6 || str_contains(substr($bytes, 0, (int) strpos($bytes.'IDAT', 'IDAT')), 'tRNS');
        }
        if ($type === IMAGETYPE_GIF) {
            return imagecolortransparent($image) >= 0;
        }
        if ($type === IMAGETYPE_WEBP && strlen($bytes) > 25) {
            $chunk = substr($bytes, 12, 4);
            if ($chunk === 'VP8X') {
                return (ord($bytes[20]) & 0x10) !== 0;
            }
            if ($chunk === 'VP8L') {
                // po sygnaturze 0x2f: 14 bitów szerokości, 14 wysokości, 1 bit „alfa użyta”
                $bits = unpack('V', substr($bytes, 21, 4));

                return is_array($bits) && ((int) $bits[1] >> 28 & 1) === 1;
            }
        }

        return false;
    }
}
