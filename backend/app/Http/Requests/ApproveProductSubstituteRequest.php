<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveProductSubstituteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('substitutes.approve') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'approval_status' => ['required', 'in:zatwierdzony,odrzucony,oczekuje'],
        ];
    }
}
