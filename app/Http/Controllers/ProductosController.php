<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Inventario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use App\Http\Requests\StoreProductoRequest;
use App\Http\Requests\UpdateProductoRequest;
use App\Http\Requests\ProductoBulkUploadRequest;
use App\Http\Resources\ProductoResource;
use Throwable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductosController extends Controller
{
    // GET /api/productos?search=&barcode=&proveedor_id=&per_page=
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 20);
        if ($perPage <= 0) {
            $perPage = 20;
        }

        $page = (int) $request->get('page', 1);
        if ($page <= 0) {
            $page = 1;
        }

        $query = $this->buildProductosQuery($request);

        return ProductoResource::collection($query->paginate($perPage, ['*'], 'page', $page));
    }

    // GET /api/productos/export
    public function export(Request $request)
    {
        $query = $this->buildProductosQuery($request);
        $filename = 'productos-' . date('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
        ];

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['ident', 'nombre', 'descripcion', 'proveedor', 'existencia', 'precio']);

            foreach ($query->get() as $producto) {
                $inventario = $producto->inventario;
                $proveedor = $producto->proveedor;

                fputcsv($handle, [
                    $producto->ident,
                    $producto->nombre,
                    $producto->descripcion,
                    $proveedor ? $proveedor->nombre : '',
                    $inventario ? $inventario->existencia : '',
                    $producto->precio !== null ? $producto->precio : '',
                ]);
            }

            fclose($handle);
        }, $filename, $headers);
    }

    protected function buildProductosQuery(Request $request): Builder
    {
        $sort = Str::lower((string) $request->get('sort', 'nombre'));
        $direction = Str::lower((string) $request->get('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $allowedSorts = ['nombre', 'proveedor', 'existencia'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'nombre';
        }

        $query = Producto::with(['proveedor', 'inventario']);

        if ($barcode = $request->get('barcode')) {
            $query->where('ident', (int) $barcode);
        }

        if ($prov = $request->get('proveedor_id')) {
            $query->where('proveedorid', (int) $prov);
        }

        if ($s = $request->get('search')) {
            $normalized = Str::lower($s);
            $like = '%' . $normalized . '%';
            $query->where(function ($qq) use ($normalized, $like) {
                $qq->whereRaw('LOWER(nombre) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(descripcion) LIKE ?', [$like])
                    ->orWhere('ident', 'LIKE', '%' . $normalized . '%')
                    ->orWhereHas('proveedor', function ($qp) use ($like, $normalized) {
                        $qp->whereRaw('LOWER(nombre) LIKE ?', [$like])
                            ->orWhere('ident', 'LIKE', '%' . $normalized . '%');
                    });
            });
        }

        $hasInventory = Str::lower((string) $request->get('has_inventory'));
        if ($hasInventory === 'with') {
            $query->whereHas('inventario', function ($qi) {
                $qi->where('existencia', '>', 0);
            });
        } elseif ($hasInventory === 'without') {
            $query->where(function ($qq) {
                $qq->whereDoesntHave('inventario')
                    ->orWhereHas('inventario', function ($qi) {
                        $qi->where('existencia', '<=', 0);
                    });
            });
        }

        switch ($sort) {
            case 'proveedor':
                $query->orderBy(
                    Proveedor::select('nombre')
                        ->whereColumn('proveedores.ident', 'producto.proveedorid')
                        ->limit(1),
                    $direction
                );
                break;
            case 'existencia':
                $query->orderBy(
                    Inventario::select('existencia')
                        ->whereColumn('inventario.ident', 'producto.ident')
                        ->limit(1),
                    $direction
                );
                break;
            default:
                $query->orderBy('nombre', $direction);
        }

        return $query;
    }

    // GET /api/proveedores/{proveedor}/productos
    public function byProveedor(Proveedor $proveedor, Request $request)
    {
        $perPage = (int) $request->get('per_page', 20);

        $q = $proveedor->productos()->with('proveedor');

        if ($s = $request->get('search')) {
            $normalized = Str::lower($s);
            $like = '%' . $normalized . '%';
            $q->where(function ($qq) use ($normalized, $like) {
                $qq->whereRaw('LOWER(nombre) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(descripcion) LIKE ?', [$like])
                    ->orWhere('ident', 'LIKE', '%'.$normalized.'%')
                    ->orWhereHas('proveedor', function ($qp) use ($normalized, $like) {
                        $qp->whereRaw('LOWER(nombre) LIKE ?', [$like])
                            ->orWhere('ident', 'LIKE', '%'.$normalized.'%');
                    });
            });
        }

        if ($barcode = $request->get('barcode')) {
            $q->where('ident', (int) $barcode);
        }

        return ProductoResource::collection($q->orderBy('nombre')->paginate($perPage));
    }

    // POST /api/productos
    public function store(StoreProductoRequest $request)
    {
        try {
            $data = $request->validated();

            $data['usuario'] = Auth::id(); // or: $request->user()->id

            if (!$data['usuario']) {
                return response()->json(['message' => 'No autenticado.'], 401);
            }

            $data = $this->normalizeProductPricing($data);
            $producto = Producto::create($data);

            return new ProductoResource($producto->load('proveedor'));

        } catch (Throwable $e) {
            Log::error('Producto store failed', [
                'ex' => $e,
                'payload' => $request->all(),
                'user_id' => Auth::id(),
            ]);
            return response()->json(['message' => 'No se pudo crear el producto.'], 500);
        }
    }

    // GET /api/productos/{producto}
    public function show(Producto $producto)
    {
        return new ProductoResource($producto->load('proveedor'));
    }

    // PUT/PATCH /api/productos/{producto}
    public function update(UpdateProductoRequest $request, Producto $producto)
    {
        try {
            $changes = $request->validated();
            $changes = $this->normalizeProductPricing($changes, $producto);
            $producto->fill($changes);
            if ($producto->isDirty()) {
                $producto->save();
            }
            return new ProductoResource($producto->load('proveedor'));
        } catch (Throwable $e) {
            Log::error('Producto update failed', [
                'ex' => $e,
                'payload' => $request->all(),
                'user_id' => Auth::id(),
            ]);
            if ($e instanceof ValidationException) {
                throw $e;
            }
            return response()->json(['message' => 'No se pudo actualizar el producto.'], 500);
        }
    }

    // DELETE /api/productos/{producto}
    public function destroy(Producto $producto)
    {
        $producto->delete();
        return response()->noContent();
    }

    // GET /api/productos/bulk-template
    public function bulkTemplate()
    {
        $path = resource_path('templates/productos_bulk_template.csv');
        if (!file_exists($path)) {
            return response()->json(['message' => 'Plantilla no disponible.'], 404);
        }

        $filename = 'productos-bulk-template.csv';
        return response()->download($path, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    // POST /api/productos/bulk-upload
    public function bulkUpload(ProductoBulkUploadRequest $request)
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $file = $request->file('file');
        $delimiter = $request->input('delimiter');
        $updateExisting = $request->boolean('update_existing', true);

        if (!$file->isValid()) {
            return response()->json(['message' => 'Archivo inválido.'], 422);
        }

        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return response()->json(['message' => 'No se pudo leer el archivo.'], 400);
        }

        if ($delimiter === null || $delimiter === '') {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                fclose($handle);
                return response()->json(['message' => 'El archivo está vacío.'], 422);
            }

            $possibleDelimiters = [',', ';', '|'];
            $detectedDelimiter = ',';
            $highestCount = -1;
            foreach ($possibleDelimiters as $candidate) {
                $count = substr_count($firstLine, $candidate);
                if ($count > $highestCount) {
                    $highestCount = $count;
                    $detectedDelimiter = $candidate;
                }
            }
            $delimiter = $detectedDelimiter;
            rewind($handle);
        } else {
            $delimiter = substr($delimiter, 0, 1);
            rewind($handle);
        }

        $header = null;
        $lineNumber = 0;
        $rows = [];
        $errors = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lineNumber++;
            if ($lineNumber === 1) {
                $header = array_map(function ($value) {
                    $clean = trim($value);
                    $clean = ltrim($clean, "\xEF\xBB\xBF"); // strip BOM if present
                    return Str::lower($clean);
                }, $row);

                if (count($header) === 0) {
                    $errors[] = ['line' => $lineNumber, 'message' => 'Encabezado vacío en el CSV.'];
                    break;
                }

                continue;
            }

            if ($header === null) {
                $errors[] = ['line' => $lineNumber, 'message' => 'No se encontró encabezado en el CSV.'];
                break;
            }

            if ($row === [null] || count(array_filter($row, fn ($val) => $val !== null && trim($val) !== '') ) === 0) {
                continue; // skip blank lines
            }

            $assoc = [];
            foreach ($header as $idx => $column) {
                $assoc[$column] = isset($row[$idx]) ? trim($row[$idx]) : null;
            }

            $rows[] = ['line' => $lineNumber, 'data' => $assoc];
        }

        fclose($handle);

        if (empty($rows)) {
            return response()->json([
                'message' => 'El archivo no contiene registros para importar.',
                'errors'  => $errors,
            ], 422);
        }

        $requiredColumns = ['ident', 'nombre', 'precio'];
        foreach ($requiredColumns as $col) {
            if (!in_array($col, $header, true)) {
                return response()->json([
                    'message' => 'El archivo CSV debe incluir las columnas requeridas.',
                    'errors'  => [['line' => 1, 'message' => "Columna requerida faltante: {$col}"]],
                ], 422);
            }
        }

        $hasProveedorIdent = in_array('proveedor_ident', $header, true);
        $hasProveedorIdLegacy = in_array('proveedorid', $header, true);
        if (!$hasProveedorIdent && !$hasProveedorIdLegacy) {
            return response()->json([
                'message' => 'El archivo CSV debe incluir la columna proveedor_ident (o proveedorid).',
                'errors'  => [['line' => 1, 'message' => 'Debe incluir proveedor_ident o proveedorid.']],
            ], 422);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        DB::beginTransaction();

        try {
            foreach ($rows as $entry) {
                $line = $entry['line'];
                $data = $entry['data'];

                $identRaw = $data['ident'] ?? null;
                if ($identRaw === null || $identRaw === '') {
                    $errors[] = ['line' => $line, 'message' => 'Campo ident vacío.'];
                    continue;
                }

                if (!is_numeric($identRaw)) {
                    $errors[] = ['line' => $line, 'message' => 'Campo ident debe ser numérico.'];
                    continue;
                }
                $ident = (int) $identRaw;

                $nombre = $data['nombre'] ?? '';
                if ($nombre === '') {
                    $errors[] = ['line' => $line, 'message' => 'Campo nombre vacío.'];
                    continue;
                }
                $nombre = Str::limit($nombre, 100, '');

                $descripcion = $data['descripcion'] ?? null;
                if ($descripcion !== null && $descripcion !== '') {
                    $descripcion = Str::limit($descripcion, 100, '');
                } else {
                    $descripcion = null;
                }

                $fecha = $data['fecha'] ?? date('d/m/y');
                $fecha = Str::limit($fecha, 10, '');

                $rawProveedor = $data['proveedor_ident'] ?? ($data['proveedorid'] ?? null);
                if ($rawProveedor === null || $rawProveedor === '') {
                    $errors[] = ['line' => $line, 'message' => 'Campo proveedor_ident vacío.'];
                    continue;
                }

                if (!is_numeric($rawProveedor)) {
                    $errors[] = ['line' => $line, 'message' => 'Campo proveedor_ident debe ser numérico.'];
                    continue;
                }
                $proveedorIdent = (int) $rawProveedor;

                $rawPrecio = $data['precio'] ?? null;
                if ($rawPrecio === null || $rawPrecio === '') {
                    $errors[] = ['line' => $line, 'message' => 'Campo precio vacío.'];
                    continue;
                }
                $normalizedPrecio = preg_replace('/[^0-9,.\-]/', '', $rawPrecio);
                if (Str::contains($normalizedPrecio, ',') && !Str::contains($normalizedPrecio, '.')) {
                    $normalizedPrecio = str_replace(',', '.', $normalizedPrecio);
                } else {
                    $normalizedPrecio = str_replace(',', '', $normalizedPrecio);
                }

                if (!is_numeric($normalizedPrecio)) {
                    $errors[] = ['line' => $line, 'message' => "Precio inválido: {$rawPrecio}"];
                    continue;
                }
                $precio = round((float) $normalizedPrecio, 2);

                $rawPrecioProveedor = $data['precio_proveedor'] ?? null;
                $precioProveedor = null;
                if ($rawPrecioProveedor !== null && $rawPrecioProveedor !== '') {
                    $normalizedCosto = preg_replace('/[^0-9,.\-]/', '', $rawPrecioProveedor);
                    if (Str::contains($normalizedCosto, ',') && !Str::contains($normalizedCosto, '.')) {
                        $normalizedCosto = str_replace(',', '.', $normalizedCosto);
                    } else {
                        $normalizedCosto = str_replace(',', '', $normalizedCosto);
                    }
                    if (is_numeric($normalizedCosto)) {
                        $precioProveedor = round((float) $normalizedCosto, 2);
                    }
                }

                $payload = [
                    'ident'       => $ident,
                    'nombre'      => $nombre,
                    'descripcion' => $descripcion,
                    'fecha'       => $fecha,
                    'proveedorid' => $proveedorIdent,
                    'usuario'     => (string) $userId,
                    'precio'      => $precio,
                    'precio_proveedor' => $precioProveedor,
                ];

                $producto = Producto::where('ident', $ident)->first();

                try {
                    $payload = $this->normalizeProductPricing($payload, $producto);
                } catch (ValidationException $e) {
                    $errors[] = [
                        'line' => $line,
                        'message' => implode(' ', $e->validator->errors()->all()),
                    ];
                    $skipped++;
                    continue;
                }

                if ($producto) {
                    if (!$updateExisting) {
                        $skipped++;
                        continue;
                    }
                    $producto->fill($payload);
                    $producto->save();
                    $updated++;
                } else {
                    Producto::create($payload);
                    $created++;
                }
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Bulk upload productos failed', [
                'ex' => $e,
                'user_id' => $userId,
                'file' => $file->getClientOriginalName(),
            ]);

            return response()->json([
                'message' => 'No se pudo completar la carga masiva.',
            ], 500);
        }

        $status = 200;
        if ($created === 0 && $updated === 0) {
            $status = 422;
        }

        return response()->json([
            'message' => 'Carga procesada.',
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors'  => $errors,
        ], $status);
    }

    private function normalizeProductPricing(array $data, ?Producto $current = null): array
    {
        $providerIdent = $data['proveedorid'] ?? $current?->proveedorid;
        if (!$providerIdent) {
            throw ValidationException::withMessages([
                'proveedorid' => 'Debes seleccionar un proveedor.',
            ]);
        }

        $proveedor = Proveedor::where('ident', $providerIdent)->first();
        if (!$proveedor) {
            throw ValidationException::withMessages([
                'proveedorid' => 'El proveedor seleccionado no existe.',
            ]);
        }

        $publicPrice = array_key_exists('precio', $data)
            ? $data['precio']
            : ($current?->precio);

        if ($publicPrice === null) {
            throw ValidationException::withMessages([
                'precio' => 'Debes capturar el precio público.',
            ]);
        }

        $providerPrice = array_key_exists('precio_proveedor', $data)
            ? $data['precio_proveedor']
            : ($current?->precio_proveedor);

        $tipo = $proveedor->tipo ?? 'normal';
        if ($tipo === 'porcentaje') {
            $pct = (int) ($proveedor->porcentaje_comision ?? 0);
            if ($pct <= 0) {
                throw ValidationException::withMessages([
                    'proveedorid' => 'El proveedor porcentaje no tiene comisión configurada (20/30%).',
                ]);
            }
            if ($providerPrice === null) {
                $providerPrice = round($publicPrice * (1 - ($pct / 100)), 2);
            }
        } elseif ($tipo === 'consigna') {
            if ($providerPrice === null) {
                throw ValidationException::withMessages([
                    'precio_proveedor' => 'Debes capturar el costo base para proveedores en consigna.',
                ]);
            }
        } else {
            $providerPrice = $providerPrice ?? $publicPrice;
        }

        $data['proveedorid'] = $providerIdent;
        $data['precio'] = $publicPrice;
        $data['precio_proveedor'] = $providerPrice;

        return $data;
    }
}
