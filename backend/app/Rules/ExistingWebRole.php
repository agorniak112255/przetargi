<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

final class ExistingWebRole implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('Nieprawidłowa rola.');

            return;
        }

        $exists = DB::table('roles')
            ->where('guard_name', 'web')
            ->where('name', $value)
            ->exists();

        if (! $exists) {
            $fail('Wybrana rola nie istnieje.');
        }
    }
}
