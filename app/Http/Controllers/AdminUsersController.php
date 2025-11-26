<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAdminUserRequest;
use App\Http\Requests\UpdateAdminUserRequest;
use App\Models\StaffRole;
use App\Models\Usuario;
use App\Support\StaffModules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class AdminUsersController extends Controller
{
    // GET /api/admin/users?search=&per_page=
    public function index(Request $request)
    {
        // Only admins should see this; simple gate:
        if ($request->user() instanceof \App\Models\Proveedor) {
            return response()->json(['message'=>'Solo administrador'], 403);
        }

        $perPage = (int)$request->get('per_page', 20);
        $q = Usuario::with('staffRole');

        if ($s = $request->get('search')) {
            $like = '%' . Str::lower($s) . '%';
            $q->where(function($qq) use ($like) {
                $qq->whereRaw('LOWER(nombre) LIKE ?', [$like])
                   ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                   ->orWhereRaw('LOWER(puesto) LIKE ?', [$like]);
            });
        }

        return $q->orderBy('nombre')->paginate($perPage);
    }

    // POST /api/admin/users
    public function store(StoreAdminUserRequest $request)
    {
        if ($request->user() instanceof \App\Models\Proveedor) {
            return response()->json(['message'=>'Solo administrador'], 403);
        }

        $data = $request->validated();
        $role = $data['role'] ?? 'admin';
        $data['role'] = $role;
        $staffRole = $this->resolveStaffRole($data['staff_role_id'] ?? null, $role, allowNull: true);
        $data['staff_role_id'] = $staffRole?->id;
        $data['modules'] = $staffRole ? null : $this->normalizeModules($data['modules'] ?? null, $role);
        $data['password'] = Hash::make($data['password']);
        $user = Usuario::create($data);

        return $user->load('staffRole');
    }

    // GET /api/admin/users/{usuario}
    public function show(Request $request, Usuario $usuario)
    {
        if ($request->user() instanceof \App\Models\Proveedor) {
            return response()->json(['message'=>'Solo administrador'], 403);
        }
        return $usuario->load('staffRole');
    }

    // PATCH /api/admin/users/{usuario}
    public function update(UpdateAdminUserRequest $request, Usuario $usuario)
    {
        if ($request->user() instanceof \App\Models\Proveedor) {
            return response()->json(['message'=>'Solo administrador'], 403);
        }

        $data = $request->validated();
        $role = $data['role'] ?? $usuario->role ?? 'admin';
        $data['role'] = $role;

        $staffRoleId = $data['staff_role_id'] ?? $usuario->staff_role_id;
        $staffRole = $this->resolveStaffRole($staffRoleId, $role, allowNull: true);
        $data['staff_role_id'] = $staffRole?->id;

        if ($staffRole) {
            $data['modules'] = null;
        } elseif (array_key_exists('modules', $data) || $usuario->modules !== null) {
            $modulesInput = array_key_exists('modules', $data) ? $data['modules'] : $usuario->modules;
            $data['modules'] = $this->normalizeModules($modulesInput, $role);
        }

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        $usuario->update($data);
        return $usuario->load('staffRole');
    }

    // DELETE /api/admin/users/{usuario}
    public function destroy(Request $request, Usuario $usuario)
    {
        if ($request->user() instanceof \App\Models\Proveedor) {
            return response()->json(['message'=>'Solo administrador'], 403);
        }
        $usuario->delete();
        return response()->noContent();
    }

    // GET /api/admin/users/backup
    public function backup(Request $request)
    {
        if (!$this->canPerformBackup($request->user())) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $token = $request->header('X-Backup-Token') ?? $request->query('token');
        $expectedToken = config('services.backup.token')
            ?? config('app.backup_token')
            ?? env('BACKUP_ACCESS_TOKEN');

        if (empty($expectedToken)) {
            Log::warning('Intento de respaldo sin token configurado');
            return response()->json(['message' => 'Respaldo no configurado correctamente'], 503);
        }

        if (!$token || !hash_equals($expectedToken, (string) $token)) {
            Log::warning('Token inválido en intento de respaldo', [
                'user_id' => $request->user()->id ?? null,
                'ip' => $request->ip(),
            ]);
            return response()->json(['message' => 'Token inválido'], 403);
        }

        $rateLimiterKey = sprintf('backup:%s', $request->user()->id ?? 'guest');
        if (!RateLimiter::attempt($rateLimiterKey, 1, function () {
        }, 300)) { // limit to once every 5 minutes
            return response()->json(['message' => 'Intenta nuevamente más tarde'], 429);
        }

        $connectionName = config('database.default');
        $connection = config("database.connections.{$connectionName}");

        if (!$connection) {
            return response()->json(['message' => 'Configuración de base de datos no encontrada'], 500);
        }

        $driver = $connection['driver'] ?? null;
        $host = $connection['host'] ?? '127.0.0.1';
        $port = $connection['port'] ?? null;
        $database = $connection['database'] ?? null;
        $username = $connection['username'] ?? null;
        $password = $connection['password'] ?? null;

        if (!$driver || !$database || !$username) {
            return response()->json(['message' => 'Configuración de conexión incompleta'], 500);
        }

        $timestamp = now()->format('Ymd_His');
        $fileName = "{$database}_backup_{$timestamp}.sql";
        $filePath = storage_path("app/{$fileName}");

        $process = null;
        $env = [];

        if ($driver === 'mysql') {
            $command = [
                'mysqldump',
                '--single-transaction',
                '--quick',
                '--lock-tables=false',
                '--result-file=' . $filePath,
            ];

            if ($host) {
                $command[] = '-h';
                $command[] = $host;
            }

            if ($port) {
                $command[] = '-P';
                $command[] = (string) $port;
            }

            $command[] = '-u';
            $command[] = $username;
            $command[] = $database;

            $env['MYSQL_PWD'] = $password ?? '';

            $process = new Process($command, null, $env);
        } elseif (in_array($driver, ['pgsql', 'postgres'])) {
            $command = [
                'pg_dump',
                '--no-owner',
                '--no-privileges',
            ];

            if ($host) {
                $command[] = '-h';
                $command[] = $host;
            }

            if ($port) {
                $command[] = '-p';
                $command[] = (string) $port;
            }

            $command[] = '-U';
            $command[] = $username;
            $command[] = '-f';
            $command[] = $filePath;
            $command[] = $database;

            $env['PGPASSWORD'] = $password ?? '';

            $process = new Process($command, null, $env);
        } else {
            return response()->json(['message' => "Driver {$driver} no soportado para respaldo"], 500);
        }

        try {
            $process->setTimeout(300);
            $process->run();
        } catch (\Throwable $e) {
            Log::error('Fallo al generar respaldo', [
                'driver' => $driver,
                'database' => $database,
                'error' => $e->getMessage(),
            ]);

            if (file_exists($filePath)) {
                @unlink($filePath);
            }

            return response()->json(['message' => 'No se pudo generar el respaldo'], 500);
        }

        if (!$process->isSuccessful() || !file_exists($filePath)) {
            Log::error('Fallo al ejecutar comando de respaldo', [
                'driver' => $driver,
                'database' => $database,
                'exit_code' => $process->getExitCode(),
                'output' => $process->getErrorOutput(),
            ]);

            if (file_exists($filePath)) {
                @unlink($filePath);
            }

            return response()->json(['message' => 'No se pudo generar el respaldo'], 500);
        }

        $headers = [
            'Content-Type' => 'application/sql',
        ];

        Log::info('Respaldo generado', [
            'user_id' => $request->user()->id ?? null,
            'database' => $database,
            'filename' => $fileName,
            'ip' => $request->ip(),
        ]);

        return response()->download($filePath, $fileName, $headers)->deleteFileAfterSend(true);
    }

    protected function canPerformBackup($user): bool
    {
        if (!$user instanceof Usuario) {
            return false;
        }

        $hasPrivileges = (int) ($user->priv1 ?? 0) === 1
            && (int) ($user->priv2 ?? 0) === 1
            && (int) ($user->priv3 ?? 0) === 1
            && (int) ($user->priv4 ?? 0) === 1;

        $isAdminRole = in_array(Str::lower((string) $user->puesto), ['admin', 'superadmin', 'owner'], true);

        return $hasPrivileges && $isAdminRole;
    }

    protected function normalizeModules($modules, string $role): array
    {
        $modulesArray = is_array($modules)
            ? array_values(array_filter(array_map(static fn ($value) => is_string($value) ? $value : (string) $value, $modules)))
            : [];

        $allowed = array_values(array_intersect($modulesArray, StaffModules::list()));

        if (!empty($allowed)) {
            return array_values(array_unique($allowed));
        }

        return $role === 'admin' ? StaffModules::list() : ['caja'];
    }

    protected function resolveStaffRole($staffRoleId, string $role, bool $allowNull = false): ?StaffRole
    {
        if (empty($staffRoleId)) {
            if ($allowNull) {
                return null;
            }
            throw ValidationException::withMessages([
                'staff_role_id' => 'Debes seleccionar un perfil válido.',
            ]);
        }

        $staffRole = StaffRole::find($staffRoleId);
        if (!$staffRole) {
            throw ValidationException::withMessages([
                'staff_role_id' => 'El perfil seleccionado no existe.',
            ]);
        }

        if ($staffRole->base_role !== $role) {
            throw ValidationException::withMessages([
                'staff_role_id' => 'El perfil seleccionado no coincide con el tipo de usuario.',
            ]);
        }

        return $staffRole;
    }
}
