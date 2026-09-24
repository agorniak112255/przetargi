<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ClientInquiry;
use App\Services\ClientInquiryService;
use App\Support\OfferPricing;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            $error = OfferPricing::marginInputError($value);
            if ($error !== null) {
                $fail($error);
            }
        };
    }

    /**
     * Cena ręczna pozycji: puste pole kasuje ją, a wszystko inne musi być kwotą.
     * Nieczytelna kwota nie może po cichu cofnąć listu do ceny wyliczonej.
     */
    private function manualPriceRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (trim((string) $value) === '') {
                return;
            }
            $error = OfferPricing::plnInputError($value);
            if ($error !== null) {
                $fail($error);
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $manualPrices = [];
        $answers = $this->input('answers');
        foreach (is_array($answers) ? array_keys($answers) : [] as $key) {
            if (is_string($key) && str_starts_with($key, ClientInquiryService::MANUAL_PRICE_PREFIX)) {
                $manualPrices['answers.'.$key.'.custom'] = ['nullable', 'string', $this->manualPriceRule()];
            }
        }

        return $manualPrices + [
            // Zmiana szablonu przepisuje list — brak pola zostawia zapisany szablon.
            'tone' => ['nullable', Rule::in(ClientInquiry::TONES)],
            'answers' => ['present', 'array'],
            'answers.*.option_id' => ['required', 'string', 'max:80'],
            'answers.*.custom' => ['nullable', 'string', 'max:400'],
            'answers.price.custom' => ['nullable', 'string', $this->marginRule()],
            'extra_note' => ['nullable', 'string', 'max:1000'],
            // Warunki oferty wpisane przez handlowca; brak klucza zostawia zapisane.
            'terms' => ['sometimes', 'array'],
            'terms.lead_time' => ['nullable', 'string', 'max:200'],
            'terms.delivery' => ['nullable', 'string', 'max:200'],
            'terms.payment' => ['nullable', 'string', 'max:200'],
            'terms.validity' => ['nullable', 'string', 'max:200'],
        ];
    }
}
