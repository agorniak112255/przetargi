<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Obrazek wgrany do maila kampanii (logo, grafika): przekodowany JPEG/PNG na dysku local, publiczny pod
 * /api/campaign-assets/{uuid}. Plików nie kasujemy — wysłane maile wskazują je na zawsze.
 */
class CampaignAsset extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'path',
        'mime',
        'width',
        'height',
        'size',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'size' => 'integer',
        ];
    }
}
