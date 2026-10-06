<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Odrzucona podpowiedź z wzorca: ta pozycja XL nie jest „taka jak” pozycja wzorcowa — nie proponować jej ponownie. */
class InspectionSuggestionRejection extends Model
{
    protected $fillable = ['pattern_position_id', 'xl_gid', 'user_id'];

    protected function casts(): array
    {
        return [
            'pattern_position_id' => 'integer',
            'xl_gid' => 'integer',
            'user_id' => 'integer',
        ];
    }
}
