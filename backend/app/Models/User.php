<?php

declare(strict_types=1);

namespace App\Models;

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
     * @return array{id: int, name: string, email: string, role: string, roles: list<string>, permissions: list<string>}
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
        ];
    }

    public function syncPrimaryRole(string $roleName): void
    {
        $this->syncRoles([$roleName]);
        $this->forceFill(['role' => $roleName])->save();
    }
}
