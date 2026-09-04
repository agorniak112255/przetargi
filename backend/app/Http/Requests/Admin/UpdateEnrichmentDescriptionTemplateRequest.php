<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\EnrichmentDescriptionTemplates;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEnrichmentDescriptionTemplateRequest extends FormRequest
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
            'instructions' => [
                'required',
                'string',
                'min:'.EnrichmentDescriptionTemplates::MIN_INSTRUCTIONS_LEN,
                'max:'.EnrichmentDescriptionTemplates::MAX_INSTRUCTIONS_LEN,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'instructions.required' => 'Wpisz instrukcje szablonu.',
            'instructions.min' => 'Instrukcje są za krótkie.',
        ];
    }
}
