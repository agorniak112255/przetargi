<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Ai\AiSettingsService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiTuningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.access') ?? false;
    }

    /**
     * Progi są opcjonalne — panel wysyła je razem z limitem, ale starszy klient
     * może przysłać sam limit i wtedy zapisane progi zostają bez zmian.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $score = [
            'sometimes',
            'integer',
            'min:'.AiSettingsService::MATCH_SCORE_MIN,
            'max:'.AiSettingsService::MATCH_SCORE_MAX,
        ];

        return [
            'catalog_search_limit' => [
                'required',
                'integer',
                'min:1',
                'max:'.AiSettingsService::CATALOG_SEARCH_LIMIT_MAX,
            ],
            'match_apply_score' => $score,
            'match_substitute_score' => $score,
            'match_min_score' => $score,
            'match_allow_catalog_rows' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $settings = app(AiSettingsService::class);
            // Brakujące pole = wartość już zapisana; inaczej wysłanie samego progu
            // zamiennika porównywałoby go z domyślną, a nie z faktyczną konfiguracją.
            $apply = $this->has('match_apply_score')
                ? $this->integer('match_apply_score')
                : $settings->matchApplyScore();
            $substitute = $this->has('match_substitute_score')
                ? $this->integer('match_substitute_score')
                : $settings->matchSubstituteScore();

            if ($apply > $substitute) {
                $validator->errors()->add(
                    'match_substitute_score',
                    'Próg zamiennika nie może być niższy niż próg zapisu dopasowania.'
                );
            }
        });
    }
}
