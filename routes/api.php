<?php

use Illuminate\Support\Facades\Route; // ← add this
use App\Http\Controllers\ProveedoresController;
use App\Http\Controllers\ProductosController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\UnifiedAuthController;
use App\Http\Controllers\AdminUsersController;
use App\Http\Controllers\CashierController;
use App\Http\Controllers\CashierLegacyController;
use App\Models\Usuario;

Route::post('/auth/login', [UnifiedAuthController::class, 'login']);


Route::post('/setup/admin', function (\Illuminate\Http\Request $request) {
    // Require the one-time header
    abort_unless($request->header('X-Setup-Token') === config('app.admin_setup_token'), 403);

    // Prevent creating multiple if one already exists
    if (Usuario::count() > 0) {
        return response()->json(['message' => 'Admin already exists'], 409);
    }

    $data = $request->validate([
        'nombre'   => ['required','string','max:65'],
        'email'    => ['required','email','max:65','unique:usuarios,email'],
        'password' => ['required','string','min:6'],
    ]);

    $u = new Usuario();
    $u->nombre   = $data['nombre'];
    $u->email    = $data['email'];
    $u->password = Hash::make($data['password']); // hash!
    $u->puesto   = 'admin';
    $u->priv1 = $u->priv2 = $u->priv3 = $u->priv4 = 1;
    $u->save();

    return response()->json(['id'=>$u->id,'email'=>$u->email], 201);
});





Route::middleware('auth:sanctum')->group(function () {
    // Auth (session helpers)
    Route::get('/auth/me', [UnifiedAuthController::class, 'me']);
    Route::post('/auth/logout', [UnifiedAuthController::class, 'logout']);

    // Caja
    Route::get('/caja/status', [CashierController::class, 'status']);
    Route::post('/caja/open',   [CashierController::class, 'open']);
    Route::post('/caja/close',  [CashierController::class, 'close']);

    // Cashier: search + checkout
    Route::get('/cashier/find-product', [CashierController::class, 'findProduct']);
    Route::post('/cashier/checkout',    [CashierController::class, 'checkout']);

    // Proveedores
    Route::apiResource('proveedores', ProveedoresController::class)
        ->parameters(['proveedores' => 'proveedor']); // nicer param name
    Route::get('/proveedores/{proveedor}/productos', [ProductosController::class, 'byProveedor'])
        ->whereNumber('proveedor');
    Route::get('/proveedores/{proveedor}/inventario', [InventarioController::class, 'byProveedor'])
        ->whereNumber('proveedor');

    // Productos
    Route::apiResource('productos', ProductosController::class)
        ->parameters(['productos' => 'producto']);

    // Inventario
    Route::get('/inventario', [InventarioController::class, 'index']);
    Route::post('/inventario/set-stock',    [InventarioController::class, 'setStock']);     // absolute set
    Route::post('/inventario/adjust-stock', [InventarioController::class, 'adjustStock']);  // relative delta

    // Admin users (admins only — enforced in controller)
    Route::apiResource('admin/users', AdminUsersController::class)
        ->parameters(['users' => 'usuario']);
    //Promociones
    Route::apiResource('promociones', \App\Http\Controllers\PromocionesController::class)
    ->parameters(['promociones' => 'promocion']);

    // Caja
    Route::get('/caja/status', [CashierLegacyController::class, 'status']);
    Route::post('/caja/open',   [CashierLegacyController::class, 'open']);
    Route::post('/caja/close',  [CashierLegacyController::class, 'close']);

    // Buscar / escanear productos
    Route::get('/cashier/find-product', [CashierLegacyController::class, 'findProduct']);

    // Checkout / vender
    Route::post('/cashier/checkout', [CashierLegacyController::class, 'checkout']);
});
