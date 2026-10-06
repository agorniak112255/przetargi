<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RenameRoleRequest;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRolePermissionsRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\NetworkAccessPolicy;
use App\Services\Offers\OfferVisibility;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $fallbackLabels = PermissionCatalog::roleLabels();

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get()
            ->map(static function (Role $role) use ($fallbackLabels): array {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'label' => $role->display_name ?: ($fallbackLabels[$role->name] ?? $role->name),
                    'is_system' => in_array($role->name, PermissionCatalog::ROLES, true),
                    'permissions' => $role->permissions->pluck('name')->values()->all(),
                    'users_count' => $role->users()->count(),
                    'network_access' => self::networkAccess($role),
                    'offer_visible_user_ids' => OfferVisibility::roleUserIds($role),
                ];
            })
            ->values();

        return response()->json([
            'roles' => $roles,
            // do wyboru osób, których oferty widzi rola z „Oferty — podgląd ofert wybranych osób”
            'users' => User::query()->orderBy('name')->get(['id', 'name'])
                ->map(static fn (User $u): array => ['id' => (int) $u->id, 'name' => (string) $u->name])->values(),
            'all_permissions' => PermissionCatalog::ALL,
            'permission_definitions' => PermissionCatalog::definitionsList(),
        ]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $data = $request->validated();

        $role = Role::query()->create([
            'name' => $data['name'],
            'display_name' => $data['display_name'],
            'guard_name' => 'web',
        ]);

        $permissions = $data['permissions'] ?? [];
        if ($permissions === [] && ! empty($data['copy_from'])) {
            $source = Role::findByName($data['copy_from'], 'web');
            $permissions = $source->permissions->pluck('name')->all();
        }

        if ($permissions !== []) {
            $role->syncPermissions($permissions);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json([
            'id' => $role->id,
            'name' => $role->name,
            'label' => $role->display_name,
            'is_system' => false,
            'permissions' => $role->permissions()->pluck('name')->values()->all(),
            'users_count' => 0,
            'network_access' => NetworkAccessPolicy::ANY,
            'offer_visible_user_ids' => [],
        ], 201);
    }

    public function update(UpdateRolePermissionsRequest $request, string $role): JsonResponse
    {
        $roleModel = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $role)
            ->first();

        if ($roleModel === null) {
            return response()->json(['message' => 'Nieznana rola.'], 404);
        }

        // Uprawnienia spoza listy znanej stronie zostają bez zmian (np. dodane wdrożeniem,
        // gdy panel był już otwarty) — inaczej zapis starej strony odbierałby je po cichu.
        $known = $request->validated('known');
        $kept = array_diff($roleModel->permissions->pluck('name')->all(), $known);
        $roleModel->syncPermissions(array_values(array_unique([...$kept, ...$request->validated('permissions')])));
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $this->present($roleModel);
    }

    public function rename(RenameRoleRequest $request, string $role): JsonResponse
    {
        $roleModel = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $role)
            ->first();

        if ($roleModel === null) {
            return response()->json(['message' => 'Nieznana rola.'], 404);
        }

        $data = $request->validated();

        DB::transaction(function () use ($roleModel, $data): void {
            if (isset($data['display_name'])) {
                $roleModel->display_name = $data['display_name'];
            }

            $oldCode = $roleModel->name;
            if (isset($data['name']) && $data['name'] !== $oldCode) {
                $roleModel->name = $data['name'];
                // Przypisania (model_has_roles) idą po id roli; users.role trzyma kod — przepisujemy.
                User::query()->where('role', $oldCode)->update(['role' => $data['name']]);
            }

            $roleModel->save();
        });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $this->present($roleModel);
    }

    private function present(Role $roleModel): JsonResponse
    {
        $fallbackLabels = PermissionCatalog::roleLabels();

        return response()->json([
            'id' => $roleModel->id,
            'name' => $roleModel->name,
            'label' => $roleModel->display_name ?: ($fallbackLabels[$roleModel->name] ?? $roleModel->name),
            'is_system' => in_array($roleModel->name, PermissionCatalog::ROLES, true),
            'permissions' => $roleModel->permissions()->pluck('name')->values()->all(),
            'users_count' => $roleModel->users()->count(),
            'network_access' => self::networkAccess($roleModel),
            'offer_visible_user_ids' => OfferVisibility::roleUserIds($roleModel),
        ]);
    }

    /** Osoby, których oferty widzi rola z offers.view_selected (lista bez uprawnienia nic nie daje). */
    public function updateOfferViewers(Request $request, string $role): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['present', 'array', 'max:500'],
            'user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
        ], [
            'user_ids.*.exists' => 'Część wybranych osób już nie istnieje — odśwież stronę.',
        ]);

        $roleModel = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $role)
            ->first();

        if ($roleModel === null) {
            return response()->json(['message' => 'Nieznana rola.'], 404);
        }

        $ids = array_values(array_unique(array_map('intval', $data['user_ids'])));
        sort($ids);
        $roleModel->forceFill(['offer_visible_user_ids' => $ids])->save();

        return $this->present($roleModel);
    }

    /**
     * Dostęp z sieci dla całej grupy. Konto z własnym ustawieniem (Użytkownicy) go nie dziedziczy.
     */
    public function updateNetworkAccess(Request $request, NetworkAccessPolicy $policy, string $role): JsonResponse
    {
        $data = $request->validate([
            'network_access' => ['required', Rule::in(NetworkAccessPolicy::MODES)],
        ]);

        $roleModel = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $role)
            ->first();

        if ($roleModel === null) {
            return response()->json(['message' => 'Nieznana rola.'], 404);
        }

        /** @var User $actor */
        $actor = $request->user();
        $policy->applyGuarded($actor, $request->ip(), 'network_access', function () use ($roleModel, $data): void {
            $roleModel->forceFill(['network_access' => $data['network_access']])->save();
        });
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $this->present($roleModel);
    }

    private static function networkAccess(Role $role): string
    {
        return $role->getAttribute('network_access') === NetworkAccessPolicy::LOCAL ? NetworkAccessPolicy::LOCAL : NetworkAccessPolicy::ANY;
    }

    public function destroy(string $role): JsonResponse
    {
        if (in_array($role, PermissionCatalog::ROLES, true)) {
            return response()->json(['message' => 'Nie można usunąć roli systemowej.'], 422);
        }

        $roleModel = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $role)
            ->first();

        if ($roleModel === null) {
            return response()->json(['message' => 'Nieznana rola.'], 404);
        }

        if ($roleModel->users()->count() > 0) {
            return response()->json([
                'message' => 'Najpierw przenieś użytkowników na inną rolę.',
            ], 422);
        }

        $roleModel->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['message' => 'OK']);
    }
}
