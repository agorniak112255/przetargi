<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CampaignAsset;
use App\Models\User;
use App\Services\Campaigns\CampaignAssetStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Obrazki do maili kampanii: wgranie (campaigns.use) i publiczny odczyt z linku w mailu. Ścieżka pliku zawsze z bazy,
 * nigdy z żądania.
 */
class CampaignAssetController extends Controller
{
    public function __construct(private readonly CampaignAssetStore $store) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,gif,webp'],
        ], [
            'file.required' => 'Wybierz obrazek.',
            'file.file' => CampaignAssetStore::NOT_IMAGE,
            'file.mimes' => CampaignAssetStore::NOT_IMAGE,
            'file.max' => 'Obrazek może mieć najwyżej 5 MB.',
        ]);
        /** @var User $user */
        $user = $request->user();
        $asset = $this->store->store($request->file('file'), $user);

        return response()->json([
            'uuid' => $asset->uuid,
            'url' => CampaignAssetStore::url($asset->uuid),
            'width' => $asset->width,
            'height' => $asset->height,
        ], 201);
    }

    public function show(string $uuid): Response
    {
        $asset = CampaignAsset::query()->where('uuid', $uuid)->first();
        $disk = Storage::disk('local');
        if ($asset === null || ! $disk->exists($asset->path)) {
            abort(404);
        }

        return response((string) $disk->get($asset->path), 200, [
            'Content-Type' => $asset->mime,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Content-Disposition' => 'inline',
        ]);
    }
}
