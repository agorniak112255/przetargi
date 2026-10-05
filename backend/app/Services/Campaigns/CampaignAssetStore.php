<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\CampaignAsset;
use App\Models\User;
use App\Support\ImageReencoder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Obrazki do maili kampanii (logo, grafika): zawsze przekodowane przez GD (ImageReencoder) — z pliku zostają same
 * piksele (bez metadanych i doklejonych treści). Szerokość najwyżej MAX_WIDTH. Oryginał nie jest zapisywany; plików
 * nie kasujemy (wysłane maile je wskazują).
 */
class CampaignAssetStore
{
    public const MAX_WIDTH = 1200;

    /** Dłuższy bok wgrywanego pliku — większe to raczej pułapka na pamięć niż grafika do maila. */
    public const MAX_SOURCE_SIDE = 6000;

    public const NOT_IMAGE = ImageReencoder::NOT_IMAGE;

    /** Adres obrazka w mailu: publiczny adres aplikacji, a bez niego (lokalnie) adres z trasy. */
    public static function url(string $uuid): string
    {
        $base = rtrim((string) config('campaigns.public_url'), '/');

        return $base !== '' ? $base.'/api/campaign-assets/'.$uuid : route('campaign-assets.show', ['uuid' => $uuid]);
    }

    public function store(UploadedFile $file, User $user): CampaignAsset
    {
        $image = ImageReencoder::reencode($file, self::MAX_WIDTH, self::MAX_SOURCE_SIDE);

        $uuid = (string) Str::uuid();
        $stored = 'campaign-assets/'.$uuid.'.'.$image['extension'];
        Storage::disk('local')->put($stored, $image['bytes']);

        return CampaignAsset::query()->create([
            'uuid' => $uuid,
            'user_id' => $user->id,
            'path' => $stored,
            'mime' => $image['mime'],
            'width' => $image['width'],
            'height' => $image['height'],
            'size' => strlen($image['bytes']),
        ]);
    }
}
