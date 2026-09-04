<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Ai\AiSettingsService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiTuningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.access') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'catalog_search_limit' => [
                'required',
                'integer',
                'min:1',
                'max:'.AiSettingsService::CATALOG_SEARCH_LIMIT_MAX,
            ],
        ];
    }
}
