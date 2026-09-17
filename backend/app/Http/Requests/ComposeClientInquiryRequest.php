<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\OfferPricing;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class ComposeClientInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inquiries.use') ?? false;
    }

    /**
     * Marza wchodzi wprost do ceny w liscie. Wpisane „200” bylo po cichu przycinane
     * do 99%, a literowka („18%%”) cofala cene do marzy domyslnej — w obu razach
     * handlowiec widzialby w liscie cene, ktorej nie ustawil.
     */
    private function marginRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (trim((string) $value) === '') {
                return;
            }
            $percent = OfferPricing::percentFromInput($value);
            if ($percent === null) {
                $fail('Marża musi być liczbą, np. 18 albo 12,5.');

                return;
            }
            $max = OfferPricing::marginMax();
            if ($percent < 0 || $percent > $max) {
                $fail('Marża musi mieścić się w zakresie 0–'.rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.').'%.');
            }
        };
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
            'answers.price.custom' => ['nullable', 'string', $this->marginRule()],
            'extra_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
