<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProveedorRequest;
use App\Http\Requests\UpdateProveedorRequest;
use App\Http\Resources\ProveedorResource;
use App\Models\Proveedor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProveedoresController extends Controller
{
    // GET /api/proveedores?search=...&per_page=...
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 20);

        $q = Proveedor::query();

        if ($s = $request->get('search')) {
            $like = '%' . Str::lower($s) . '%';
            $q->where(function ($qq) use ($like) {
                $qq->whereRaw('LOWER(nombre) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(ciudad) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(sucursal) LIKE ?', [$like]);
            });
        }

        $q->with('recommendedImporte')->orderBy('nombre');

        return ProveedorResource::collection($q->paginate($perPage));
    }

    // POST /api/proveedores
    public function store(StoreProveedorRequest $request)
    {
        $data = $request->validated();
        $data['tipo'] = $data['tipo'] ?? 'normal';
        if ($data['tipo'] !== 'normal') {
            $data['importe'] = null;
        }
        if ($data['tipo'] !== 'porcentaje') {
            $data['porcentaje_comision'] = null;
        }

        $proveedor = Proveedor::create($data);
        return new ProveedorResource($proveedor->load('recommendedImporte'));
    }

    // GET /api/proveedores/{proveedor}
    public function show(Proveedor $proveedore) // route model binding (singular key is 'proveedore' by default)
    {
        return new ProveedorResource($proveedore->load('recommendedImporte'));
    }

    // PUT/PATCH /api/proveedores/{proveedor}
    public function update(UpdateProveedorRequest $request, Proveedor $proveedor)
    {
        $changes = $request->validated();
        if (!array_key_exists('email', $changes) || $changes['email'] === null || $changes['email'] === '') {
            $changes['email'] = $proveedor->email ?? '-';
        }
        if (!array_key_exists('bancaria', $changes) || $changes['bancaria'] === null || $changes['bancaria'] === '') {
            $changes['bancaria'] = $proveedor->bancaria ?? '-';
        }
        if (!array_key_exists('ciudad', $changes) || $changes['ciudad'] === null || $changes['ciudad'] === '') {
            $changes['ciudad'] = $proveedor->ciudad ?? '-';
        }
        if (!array_key_exists('sucursal', $changes) || $changes['sucursal'] === null || $changes['sucursal'] === '') {
            $changes['sucursal'] = $proveedor->sucursal ?? '-';
        }
        if (array_key_exists('tipo', $changes)) {
            if ($changes['tipo'] !== 'normal') {
                $changes['importe'] = null;
            }
            if ($changes['tipo'] !== 'porcentaje') {
                $changes['porcentaje_comision'] = null;
            }
        }

        $proveedor->fill($changes);
        if ($proveedor->isDirty()) {
            $proveedor->save();
        }
        return new ProveedorResource($proveedor->fresh()->load('recommendedImporte'));
    }

    // DELETE /api/proveedores/{proveedor}
    public function destroy(Proveedor $proveedore)
    {
        $proveedore->delete();
        return response()->noContent();
    }

    // POST /api/proveedores/import
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'delimiter' => ['nullable', 'string', 'max:1'],
            'update_existing' => ['nullable', 'boolean'],
        ]);

        $delimiter = $request->input('delimiter', ',');
        if ($delimiter === '\\t') {
            $delimiter = "\t";
        } elseif ($delimiter === '') {
            $delimiter = ',';
        }

        $updateExisting = $request->boolean('update_existing', true);
        $handle = fopen($request->file('file')->getRealPath(), 'r');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => 'No se pudo leer el archivo CSV proporcionado.',
            ]);
        }

        $headerRow = fgetcsv($handle, 0, $delimiter);
        if ($headerRow === false) {
            fclose($handle);
            throw ValidationException::withMessages([
                'file' => 'El archivo CSV está vacío.',
            ]);
        }

        $columnMap = [
            'ident'        => 'ident',
            'identificador'=> 'ident',
            'nombre'       => 'nombre',
            'razon_social' => 'nombre',
            'fecha'        => 'fecha',
            'fecha_alta'   => 'fecha',
            'tel'          => 'tel',
            'telefono'     => 'tel',
            'telefono_contacto' => 'tel',
            'email'        => 'email',
            'correo'       => 'email',
            'calle'        => 'calle',
            'direccion'    => 'calle',
            'bancaria'     => 'bancaria',
            'cuenta_bancaria' => 'bancaria',
            'clabe'        => 'bancaria',
            'ciudad'       => 'ciudad',
            'municipio'    => 'ciudad',
            'importe'      => 'importe',
            'monto_mensual'=> 'importe',
            'sucursal'     => 'sucursal',
            'banco'        => 'sucursal',
            'tipo'         => 'tipo',
            'tipo_proveedor' => 'tipo',
            'porcentaje'   => 'porcentaje_comision',
            'porcentaje_comision' => 'porcentaje_comision',
        ];

        $mappedHeader = [];
        foreach ($headerRow as $column) {
            $normalized = Str::of($column)->lower()->replaceMatches('/[^a-z0-9]+/i', '_')->trim('_')->__toString();
            $mappedHeader[] = $columnMap[$normalized] ?? null;
        }

        $requiredColumns = ['ident', 'nombre', 'fecha'];
        foreach ($requiredColumns as $required) {
            if (!in_array($required, $mappedHeader, true)) {
                fclose($handle);
                throw ValidationException::withMessages([
                    'file' => "La columna requerida '{$required}' no se encuentra en el encabezado del CSV.",
                ]);
            }
        }

        $rules = (new StoreProveedorRequest())->rules();

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $lineNumber = 1; // header

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lineNumber++;

            if ($row === [null] || count(array_filter($row, fn ($value) => trim((string) ($value ?? '')) !== '')) === 0) {
                $skipped++;
                continue;
        }

        $payload = [];
        foreach ($mappedHeader as $index => $field) {
            if ($field === null) {
                    continue;
                }
                $value = $row[$index] ?? null;
                if (is_string($value)) {
                    $value = trim($value);
                }
                $payload[$field] = ($value === '') ? null : $value;
            }

            // Ensure required keys exist
            if (!array_key_exists('ident', $payload) || $payload['ident'] === null) {
                $errors[] = [
                    'line' => $lineNumber,
                    'message' => 'La columna "ident" no puede estar vacía.',
                ];
                $skipped++;
                continue;
            }
            if (!array_key_exists('nombre', $payload) || !$payload['nombre']) {
                $errors[] = [
                    'line' => $lineNumber,
                    'message' => 'La columna "nombre" no puede estar vacía.',
                ];
                $skipped++;
                continue;
            }
            if (!array_key_exists('fecha', $payload) || !$payload['fecha']) {
                $errors[] = [
                    'line' => $lineNumber,
                    'message' => 'La columna "fecha" no puede estar vacía.',
                ];
                $skipped++;
                continue;
            }

            // Cast and normalize values.
            $payload['ident'] = (int) $payload['ident'];

            if (isset($payload['importe']) && $payload['importe'] !== null) {
                $importeRaw = preg_replace('/[^\d\.\-]/', '', (string) $payload['importe']);
                $payload['importe'] = $importeRaw === '' ? null : (float) $importeRaw;
            }

            $payload['tipo'] = $payload['tipo'] ?? 'normal';
            if ($payload['tipo'] === 'porcentaje') {
                $pct = isset($payload['porcentaje_comision']) ? (int) $payload['porcentaje_comision'] : null;
                if (!in_array($pct, [20, 30], true)) {
                    $errors[] = [
                        'line' => $lineNumber,
                        'message' => 'Los proveedores por porcentaje deben indicar 20 o 30 en la columna porcentaje.',
                    ];
                    $skipped++;
                    continue;
                }
                $payload['porcentaje_comision'] = $pct;
            } else {
                $payload['porcentaje_comision'] = null;
            }
            if ($payload['tipo'] !== 'normal') {
                $payload['importe'] = null;
            }

            if (isset($payload['tel']) && $payload['tel'] !== null) {
                $payload['tel'] = preg_replace('/\D+/', '', (string) $payload['tel']);
                if ($payload['tel'] === '') {
                    $payload['tel'] = null;
                }
            }

            if (!empty($payload['fecha'])) {
                $fecha = $payload['fecha'];
                try {
                    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $fecha)) {
                        $payload['fecha'] = Carbon::createFromFormat('d/m/Y', $fecha)->format('Y-m-d');
                    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
                        // already in ISO format
                        $payload['fecha'] = $fecha;
                    } else {
                        $payload['fecha'] = Carbon::parse($fecha)->format('Y-m-d');
                    }
                } catch (\Exception $e) {
                    $errors[] = [
                        'line' => $lineNumber,
                        'message' => 'Formato de fecha inválido, se esperaba YYYY-MM-DD o DD/MM/YYYY.',
                    ];
                    $skipped++;
                    continue;
                }
            }

            $validator = Validator::make($payload, $rules);

            try {
                $validated = $validator->validate();
            } catch (ValidationException $e) {
                $errors[] = [
                    'line' => $lineNumber,
                    'message' => implode(' ', $e->validator->errors()->all()),
                ];
                $skipped++;
                continue;
            }

            $existing = Proveedor::where('ident', $validated['ident'])->first();
            if (!$existing) {
                $existing = Proveedor::whereRaw('LOWER(nombre) = ?', [Str::lower($validated['nombre'])])->first();
            }

            // Align numeric casting & nullables
            $attributes = $validated;
            if (array_key_exists('importe', $attributes) && $attributes['importe'] !== null) {
                $attributes['importe'] = (float) $attributes['importe'];
            }

            if ($existing) {
                if (!$updateExisting) {
                    $skipped++;
                    continue;
                }
                $existing->fill($attributes);
                if ($existing->isDirty()) {
                    $existing->save();
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                Proveedor::create($attributes);
                $created++;
            }
        }

        fclose($handle);

        return response()->json([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
    }

    public function bulkUpdateTipo(Request $request)
    {
        $rules = [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:proveedores,id'],
            'items.*.tipo' => ['required', Rule::in(['normal', 'consigna', 'porcentaje'])],
            'items.*.importe' => ['nullable', 'numeric', 'min:0'],
            'items.*.porcentaje' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];

        $validator = Validator::make($request->all(), $rules, [], [
            'items.*.id' => 'proveedor',
        ]);

        $validator->after(function ($validator) use ($request) {
            $items = $request->input('items', []);
            foreach ($items as $index => $item) {
                $tipo = $item['tipo'] ?? 'normal';
                if ($tipo === 'normal') {
                    $importe = $item['importe'] ?? null;
                    if ($importe === null || !is_numeric($importe) || (float) $importe <= 0) {
                        $validator->errors()->add("items.{$index}.importe", 'El importe es obligatorio para proveedores normales.');
                    }
                }
                if ($tipo === 'porcentaje') {
                    $pct = $item['porcentaje'] ?? null;
                    if ($pct === null || !is_numeric($pct) || (float) $pct <= 0) {
                        $validator->errors()->add("items.{$index}.porcentaje", 'Define el porcentaje para proveedores por porcentaje.');
                    }
                }
            }
        });

        $validated = $validator->validate();
        $items = collect($validated['items']);

        $ids = $items->pluck('id')->all();
        $providers = Proveedor::whereIn('id', $ids)->get()->keyBy('id');

        $updated = 0;
        foreach ($items as $item) {
            $provider = $providers->get($item['id']);
            if (!$provider) {
                continue;
            }
            $tipo = $item['tipo'];
            $importe = $tipo === 'normal' ? (float) $item['importe'] : null;
            $porcentaje = $tipo === 'porcentaje' ? (float) $item['porcentaje'] : null;

            $provider->tipo = $tipo;
            $provider->importe = $importe;
            $provider->porcentaje_comision = $porcentaje;

            if ($provider->isDirty(['tipo', 'importe', 'porcentaje_comision'])) {
                $provider->save();
                $updated++;
            }
        }

        $refreshed = Proveedor::whereIn('id', $ids)->get();

        return response()->json([
            'updated' => $updated,
            'items' => ProveedorResource::collection($refreshed),
        ]);
    }

    public function updateSelf(Request $request)
    {
        $provider = $request->user();
        if (!$provider instanceof Proveedor) {
            abort(403, 'Solo los proveedores pueden actualizar su perfil.');
        }

        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:150'],
            'tel' => ['nullable', 'string', 'max:40'],
        ]);

        $provider->fill($data);
        if ($provider->isDirty()) {
            $provider->save();
        }

        return new ProveedorResource($provider->fresh()->load('recommendedImporte'));
    }
}
