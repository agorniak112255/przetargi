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
 */
class RequirementUnderstanding extends Model
{
    protected $fillable = ['requirement_hash', 'prompt_version', 'requirement', 'answer'];

    protected function casts(): array
    {
        return ['answer' => 'array'];
    }
}
