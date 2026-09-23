<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBrandDictionaryEntryRequest;
use App\Http\Requests\Admin\UpdateBrandDictionaryEntryRequest;
use App\Models\BrandDictionaryEntry;
use App\Services\BrandDictionaryAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class BrandDictionaryController extends Controller
{
    public function __construct(
        private readonly BrandDictionaryAdminService $dictionary,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->dictionary->overview());
    }

    public function store(StoreBrandDictionaryEntryRequest $request): JsonResponse
    {
        $entry = $this->dictionary->create($request->validated());

        return response()->json(['entry' => $this->dictionary->present($entry)], 201);
    }

    public function update(UpdateBrandDictionaryEntryRequest $request, BrandDictionaryEntry $entry): JsonResponse
    {
        $entry = $this->dictionary->update($entry, $request->validated());

        return response()->json(['entry' => $this->dictionary->present($entry)]);
    }

    public function destroy(BrandDictionaryEntry $entry): Response
    {
        $entry->delete();

        return response()->noContent();
    }
}
