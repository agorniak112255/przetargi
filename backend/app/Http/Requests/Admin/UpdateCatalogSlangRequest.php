<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\CatalogSlangDictionary;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCatalogSlangRequest extends FormRequest
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
            'catalog_slang' => ['required', 'array', 'max:'.CatalogSlangDictionary::MAX_ENTRIES],
            'catalog_slang.*.category' => ['nullable', 'string', 'in:'.implode(',', CatalogSlangDictionary::CATEGORIES)],
            'catalog_slang.*.terms' => ['required', 'array', 'min:1', 'max:'.CatalogSlangDictionary::MAX_TERMS],
            'catalog_slang.*.terms.*' => ['string', 'max:'.CatalogSlangDictionary::MAX_TERM_LEN],
            'catalog_slang.*.phrases' => ['required', 'array', 'min:1', 'max:'.CatalogSlangDictionary::MAX_PHRASES],
            'catalog_slang.*.phrases.*' => ['string', 'max:'.CatalogSlangDictionary::MAX_PHRASE_LEN],
            'catalog_slang.*.note' => ['nullable', 'string', 'max:'.CatalogSlangDictionary::MAX_NOTE_LEN],
            'catalog_slang.*.jargon' => ['sometimes', 'boolean'],
            'catalog_slang.*.keywords' => ['sometimes', 'array', 'max:'.CatalogSlangDictionary::MAX_KEYWORDS],
            'catalog_slang.*.keywords.*' => ['string', 'max:'.CatalogSlangDictionary::MAX_PHRASE_LEN],
            'catalog_slang.*.tags' => ['sometimes', 'array', 'max:'.CatalogSlangDictionary::MAX_TAGS],
            'catalog_slang.*.tags.*' => ['string', 'max:'.CatalogSlangDictionary::MAX_TERM_LEN],
        ];
    }
}
