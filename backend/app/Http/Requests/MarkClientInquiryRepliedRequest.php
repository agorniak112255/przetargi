<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkClientInquiryRepliedRequest extends FormRequest
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
            'replied' => ['required', 'boolean'],
        ];
    }
}
