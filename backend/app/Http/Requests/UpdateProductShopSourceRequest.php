<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductShopSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('price_lists.import') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('shop_source_url');
        if (! is_string($raw)) {
            $this->merge(['shop_source_url' => null]);

            return;
        }
        $url = trim($raw);
        if ($url === '') {
            $this->merge(['shop_source_url' => null]);

            return;
        }
        if (preg_match('#^https?://#i', $url) !== 1) {
            $url = 'https://'.$url;
        }

        $this->merge(['shop_source_url' => mb_substr($url, 0, 2000)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shop_source_url' => ['nullable', 'string', 'max:2000', 'url:http,https'],
        ];
    }
}

