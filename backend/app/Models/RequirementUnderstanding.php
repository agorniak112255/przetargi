<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Surowa odpowiedź modelu z kroku „zrozum wymaganie”, zapisana raz na treść wymagania i wersję instrukcji.
 *
 * @property string $requirement_hash
 * @property string $prompt_version
 * @property string $requirement
 * @property array<string, mixed> $answer
 * @property string|null $model model, który odpowiedział (null: wpis sprzed 25.09.2026 albo nieznane)
 * @property string|null $provider dostawca OpenRoutera
 * @property string|null $profile profil modelu z Ustawień AI
 */
class RequirementUnderstanding extends Model
{
    protected $fillable = ['requirement_hash', 'prompt_version', 'requirement', 'answer', 'model', 'provider', 'profile'];

    protected function casts(): array
    {
        return ['answer' => 'array'];
    }
}
