<?php

namespace App\Http\Controllers;

use App\Models\StaffRole;
use App\Models\Usuario;
use App\Support\StaffModules;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StaffRoleController extends Controller
{
    protected function ensureAdmin(Request $request)
    {
        if ($request->user() instanceof \App\Models\Proveedor) {
            abort(403, 'Solo administrador');
        }
    }

    public function index(Request $request)
    {
        $this->ensureAdmin($request);

        return StaffRole::orderBy('base_role')
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $this->validateData($request);
        $role = StaffRole::create($data);
        $this->syncDefaultFlag($role);

        return response()->json($role, 201);
    }

    public function show(Request $request, StaffRole $staffRole)
    {
        $this->ensureAdmin($request);
        return $staffRole;
    }

    public function update(Request $request, StaffRole $staffRole)
    {
        $this->ensureAdmin($request);

        $data = $this->validateData($request, $staffRole);
        $staffRole->update($data);
        $this->syncDefaultFlag($staffRole);

        return $staffRole;
    }

    public function destroy(Request $request, StaffRole $staffRole)
    {
        $this->ensureAdmin($request);

        $inUse = Usuario::where('staff_role_id', $staffRole->id)->exists();
        if ($inUse) {
            return response()->json(['message' => 'No se puede eliminar un perfil en uso.'], 409);
        }

        $staffRole->delete();
        return response()->noContent();
    }

    protected function validateData(Request $request, ?StaffRole $role = null): array
    {
        $id = $role?->id;
        $data = $request->validate([
            'name' => ['required','string','max:80'],
            'slug' => [
                'nullable',
                'string',
                'max:80',
                Rule::unique('staff_roles', 'slug')->ignore($id),
            ],
            'base_role' => ['required','in:admin,cashier'],
            'modules' => ['sometimes','array'],
            'modules.*' => ['string'],
            'is_default' => ['sometimes','boolean'],
        ]);

        $data['slug'] = $this->resolveSlug($data['slug'] ?? null, $data['name'], $id);
        $modules = $data['modules'] ?? ($role?->modules ?? []);
        $data['modules'] = $this->sanitizeModules($modules, $data['base_role']);
        $data['is_default'] = (bool) ($data['is_default'] ?? false);

        return $data;
    }

    protected function resolveSlug(?string $slug, string $name, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($slug ?: $name);
        if ($baseSlug === '') {
            $baseSlug = Str::slug($name . '-' . Str::random(4));
        }

        $candidate = $baseSlug;
        $suffix = 1;
        while (
            StaffRole::where('slug', $candidate)
                ->when($ignoreId, fn ($q) => $q->where('id', '<>', $ignoreId))
                ->exists()
        ) {
            $candidate = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    protected function sanitizeModules($modules, string $baseRole): array
    {
        $modulesArray = is_array($modules)
            ? array_values(array_filter(array_map(static fn ($value) => is_string($value) ? $value : (string) $value, $modules)))
            : [];

        $allowed = array_values(array_intersect($modulesArray, StaffModules::list()));

        if (!empty($allowed)) {
            return array_values(array_unique($allowed));
        }

        return $baseRole === 'admin' ? StaffModules::list() : ['caja'];
    }

    protected function syncDefaultFlag(StaffRole $role): void
    {
        if (!$role->is_default) {
            return;
        }

        StaffRole::where('base_role', $role->base_role)
            ->where('id', '<>', $role->id)
            ->update(['is_default' => false]);
    }
}
