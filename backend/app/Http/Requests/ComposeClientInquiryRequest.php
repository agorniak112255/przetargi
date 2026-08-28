<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ComposeClientInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inquiries.use') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answers' => ['present', 'array'],
            'answers.*.option_id' => ['required', 'string', 'max:80'],
            'answers.*.custom' => ['nullable', 'string', 'max:400'],
            'extra_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
