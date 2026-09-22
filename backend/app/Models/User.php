<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\OfferPricing;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /** Dozwolone tryby wyglądu zapisywane w ui_preferences.mode. */
    public const UI_MODES = ['light', 'dark', 'system'];

    protected string $guard_name = 'web';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'ui_preferences' => 'array',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function ownedTenders(): HasMany
    {
        return $this->hasMany(Tender::class, 'owner_id');
    }

    public function tenderInvitations(): HasMany
    {
        return $this->hasMany(TenderInvitation::class, 'user_id');
    }

    /**
     * @return array{id: int, name: string, email: string, role: string, roles: list<string>, permissions: list<string>, ui_preferences: array{template: ?string, mode: ?string}, default_margin_percent: float}
     */
    public function toAuthArray(): array
    {
        $roles = $this->getRoleNames()->values()->all();
        $primaryRole = $roles[0] ?? $this->role ?? 'handlowiec';

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $primaryRole,
            'roles' => $roles,
            'permissions' => $this->getAllPermissions()->pluck('name')->values()->all(),
            'ui_preferences' => $this->normalizedUiPreferences(),
            'default_margin_percent' => $this->defaultMarginPercent(),
        ];
    }

    /**
     * Marża, z którą startuje nowa odpowiedź na zapytanie. Wartość spoza dozwolonego
     * zakresu (np. wpisana ręcznie w bazie) jest przycinana do granic, a jej brak
     * oznacza marżę z konfiguracji.
     */
    public function defaultMarginPercent(): float
    {
        $raw = $this->getAttribute('default_margin_percent');
        if (! is_numeric($raw)) {
            return OfferPricing::markupPercent();
        }

        return max(0.0, min((float) $raw, OfferPricing::marginMax()));
    }

    /**
     * Zwraca zawsze oba klucze; wartość spoza dozwolonego formatu (np. ręcznie wpisana w bazie) staje się null.
     *
     * @return array{template: ?string, mode: ?string}
     */
    private function normalizedUiPreferences(): array
    {
        $stored = is_array($this->ui_preferences) ? $this->ui_preferences : [];
        $template = $stored['template'] ?? null;
        $mode = $stored['mode'] ?? null;

        return [
            'template' => is_string($template) && preg_match('/^[a-z0-9-]{1,40}$/', $template) === 1 ? $template : null,
            'mode' => in_array($mode, self::UI_MODES, true) ? $mode : null,
        ];
    }

    public function syncPrimaryRole(string $roleName): void
    {
        $this->syncRoles([$roleName]);
        $this->forceFill(['role' => $roleName])->save();
    }
}
