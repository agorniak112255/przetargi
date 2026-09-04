<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\EnrichmentDescriptionLayouts;
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
        $isDefault = $this->kategoria() === EnrichmentDescriptionLayouts::DEFAULT_KEY;

        return [
            'instructions' => $isDefault
                ? ['nullable', 'string', 'max:'.EnrichmentDescriptionTemplates::MAX_INSTRUCTIONS_LEN]
                : [
                    'required_without:layout',
                    'nullable',
                    'string',
                    'min:'.EnrichmentDescriptionTemplates::MIN_INSTRUCTIONS_LEN,
                    'max:'.EnrichmentDescriptionTemplates::MAX_INSTRUCTIONS_LEN,
                ],
            'layout' => [$isDefault ? 'required_without:instructions' : 'nullable', 'array'],
            'layout.inherit_card' => ['sometimes', 'boolean'],
            'layout.inherit_export' => ['sometimes', 'boolean'],
            'layout.card' => ['sometimes', 'nullable', 'array'],
            'layout.export' => ['sometimes', 'nullable', 'array'],
            'layout.card.*.id' => ['required_with:layout.card', 'string', 'max:40'],
            'layout.card.*.visible' => ['sometimes', 'boolean'],
            'layout.card.*.emphasis' => ['sometimes', 'string', 'in:'.implode(',', EnrichmentDescriptionLayouts::EMPHASIS)],
            'layout.export.*.id' => ['required_with:layout.export', 'string', 'max:40'],
            'layout.export.*.visible' => ['sometimes', 'boolean'],
            'layout.export.*.emphasis' => ['sometimes', 'string', 'in:'.implode(',', EnrichmentDescriptionLayouts::EMPHASIS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'instructions.required_without' => 'Wpisz instrukcje szablonu albo układ bloków.',
            'instructions.min' => 'Instrukcje są za krótkie.',
        ];
    }

    private function kategoria(): string
    {
        $raw = $this->route('kategoria');

        return is_string($raw) ? $raw : '';
    }
}
