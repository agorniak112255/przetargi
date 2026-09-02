<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Presta\PrestaCatalogGateway;
use App\Services\Presta\PrestaSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PrestaShopSettingsController extends Controller
{
    public function __construct(
        private readonly PrestaSettingsService $settings,
        private readonly PrestaCatalogGateway $catalog,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->settings->publicView());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'database' => ['nullable', 'string', 'max:128'],
            'username' => ['nullable', 'string', 'max:128'],
            'password' => ['nullable', 'string', 'max:500'],
            'prefix' => ['nullable', 'string', 'max:16'],
            'id_lang' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'shop_url' => ['nullable', 'url', 'max:255'],
            'webservice_key' => ['nullable', 'string', 'max:128'],
            'id_category_default' => ['sometimes', 'integer', 'min:1', 'max:999999'],
            'delivery_label' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $this->settings->update($data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->settings->publicView());
    }

    public function test(): JsonResponse
    {
        $ping = $this->catalog->ping();

        return response()->json($ping, $ping['ok'] ? 200 : 422);
    }
}
