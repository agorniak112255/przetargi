<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Zespół pracowników (Administracja → Role): kierownik zespołu (pivot is_leader) widzi w raporcie „Wynik kampanii”
 * kampanie członków swoich zespołów.
 */
class UserTeam extends Model
{
    protected $fillable = ['name'];

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_team_members', 'team_id', 'user_id')
            ->withPivot('is_leader')
            ->withTimestamps();
    }
}
