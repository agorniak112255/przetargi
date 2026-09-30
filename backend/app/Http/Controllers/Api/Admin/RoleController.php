<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RenameRoleRequest;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRolePermissionsRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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
                ];
            })
            ->values();

        return response()->json([
            'roles' => $roles,
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
        ]);
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
