<?php

use App\Http\Controllers\AdminSalesToolController;
use App\Http\Controllers\AdminUsersController;
use App\Http\Controllers\CashierController;
use App\Http\Controllers\CashierLegacyController;
use App\Http\Controllers\ClientesController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\InventoryProposalController;
use App\Http\Controllers\MailerController;
use App\Http\Controllers\MailerTrackController;
use App\Http\Controllers\MensualidadController;
use App\Http\Controllers\ProductosController;
use App\Http\Controllers\PromocionesController;
use App\Http\Controllers\ProveedoresController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffRoleController;
use App\Http\Controllers\UnifiedAuthController;
use App\Http\Controllers\WidgetsController;
use App\Http\Controllers\AnalysisController;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [UnifiedAuthController::class, 'login']);
Route::post('/login', [UnifiedAuthController::class, 'login'])->name('api.login');

Route::get('/health-check', function () {
    return response()->json([
        'ok' => true,
        'app' => config('app.name'),
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::post('/setup/admin', function (Request $request) {
    abort_unless($request->header('X-Setup-Token') === config('app.admin_setup_token'), 403);

    if (Usuario::count() > 0) {
        return response()->json(['message' => 'Admin already exists'], 409);
    }

    $data = $request->validate([
        'nombre'   => ['required', 'string', 'max:65'],
        'email'    => ['required', 'email', 'max:65', 'unique:usuarios,email'],
        'password' => ['required', 'string', 'min:6'],
    ]);

    $u = new Usuario();
    $u->nombre = $data['nombre'];
    $u->email = $data['email'];
    $u->password = Hash::make($data['password']);
    $u->puesto = 1;
    $u->priv1 = $u->priv2 = $u->priv3 = $u->priv4 = 1;
    $u->save();

    return response()->json(['id' => $u->id, 'email' => $u->email], 201);
});

Route::middleware('auth:sanctum')->group(function () {
    // Auth helpers
    Route::get('/auth/me', [UnifiedAuthController::class, 'me']);
    Route::post('/auth/logout', [UnifiedAuthController::class, 'logout']);

    // Caja lifecycle
    Route::get('/caja/status', [CashierController::class, 'status']);
    Route::post('/caja/open', [CashierController::class, 'open']);
    Route::post('/caja/close', [CashierController::class, 'close']);

    // Cashier operations
    Route::get('/cashier/find-product', [CashierLegacyController::class, 'findProduct']);
    Route::post('/cashier/checkout', [CashierController::class, 'checkout']);

    // Proveedores
    Route::apiResource('proveedores', ProveedoresController::class)
        ->parameters(['proveedores' => 'proveedor']);
    Route::post('/proveedores/import', [ProveedoresController::class, 'import']);
    Route::post('/proveedores/bulk-tipo', [ProveedoresController::class, 'bulkUpdateTipo']);
    Route::put('/provider/profile', [ProveedoresController::class, 'updateSelf']);
    Route::get('/proveedores/{proveedor}/productos', [ProductosController::class, 'byProveedor'])
        ->whereNumber('proveedor');
    Route::get('/proveedores/{proveedor}/inventario', [InventarioController::class, 'byProveedor'])
        ->whereNumber('proveedor');
    Route::get('/reports/provider/trends', [ReportController::class, 'providerTrends']);

    // Productos
    Route::get('/productos/bulk-template', [ProductosController::class, 'bulkTemplate']);
    Route::post('/productos/bulk-upload', [ProductosController::class, 'bulkUpload']);
    Route::get('/productos/export', [ProductosController::class, 'export']);
    Route::apiResource('productos', ProductosController::class)
        ->parameters(['productos' => 'producto']);

    // Clientes
    Route::apiResource('clientes', ClientesController::class)
        ->parameters(['clientes' => 'cliente']);

    // Mailer
    Route::post('/mailer/resend', [MailerController::class, 'resend']);
    Route::apiResource('mailer', MailerController::class)
        ->parameters(['mailer' => 'mailer']);

    // Mensualidad
    Route::post('/mensualidad/{mensualidad}/send-mail', [MensualidadController::class, 'sendCharge']);
    Route::post('/mensualidad/{mensualidad}/pay', [MensualidadController::class, 'pay']);
    Route::post('/mensualidad/bulk', [MensualidadController::class, 'bulkCreate']);
    Route::apiResource('mensualidad', MensualidadController::class)
        ->parameters(['mensualidad' => 'mensualidad']);

    // Widgets
    Route::get('/widgets/cashier-summary', [WidgetsController::class, 'cashierSummary']);
    Route::get('/widgets/top-products', [WidgetsController::class, 'topProducts']);
    Route::get('/widgets/restock-alerts', [WidgetsController::class, 'restockAlerts']);

    // Reports
    Route::get('/reports/caja', [ReportController::class, 'caja']);
    Route::get('/reports/egresos-caja', [ReportController::class, 'egresosCaja']);
    Route::get('/reports/flujo-caja', [ReportController::class, 'flujoCaja']);
    Route::get('/reports/restock-forecast', [ReportController::class, 'restockForecast']);
    Route::post('/reports/restock-forecast/preference', [ReportController::class, 'updateRestockPreference']);
    Route::post('/reports/restock-forecast/notify', [ReportController::class, 'restockForecastNotify']);
    Route::get('/reports/inventory-proposals', [InventoryProposalController::class, 'index']);
    Route::get('/reports/inventory-proposals/{horizon}', [InventoryProposalController::class, 'show']);
    Route::post('/reports/inventory-proposals', [InventoryProposalController::class, 'store']);
    Route::post('/reports/inventory-proposals/notify', [InventoryProposalController::class, 'notify']);
    Route::get('/settings/general', [SettingsController::class, 'general']);
    Route::post('/settings/general', [SettingsController::class, 'updateGeneral']);
    Route::post('/settings/general/run-restock', [SettingsController::class, 'runRestock']);
    Route::post('/settings/general/run-cash-autoclose', [SettingsController::class, 'runCashAutoClose']);
    Route::post('/settings/general/run-card-rebalance', [SettingsController::class, 'runCardRebalance']);
    Route::get('/analysis/summary', [AnalysisController::class, 'summary']);
    Route::post('/analysis/import', [AnalysisController::class, 'import']);
    Route::get('/analysis/top-sellers', [AnalysisController::class, 'topSellers']);
    Route::get('/analysis/top-products', [AnalysisController::class, 'topProducts']);
    Route::get('/analysis/month-details', [AnalysisController::class, 'monthDetails']);
    Route::get('/analysis/recommended-importes', [AnalysisController::class, 'recommendedImportes']);
    Route::post('/analysis/recommended-importes/recalculate', [AnalysisController::class, 'recalculateRecommendedImportes']);
    Route::post('/analysis/recommended-importes/apply', [AnalysisController::class, 'applyRecommendedImport']);
    Route::get('/analysis/transition-report', [AnalysisController::class, 'transitionReport']);
    Route::get('/analysis/transition-report/provider', [AnalysisController::class, 'transitionProviderDetails']);
    Route::get('/reports/mensualidad', [ReportController::class, 'mensualidad']);
    Route::get('/reports/productos', [ReportController::class, 'productos']);
    Route::get('/reports/inventario', [ReportController::class, 'inventario']);
    Route::get('/reports/entradas', [ReportController::class, 'entradas']);
    Route::get('/reports/caja-proveedores', [ReportController::class, 'cajaPorProveedor']);
    Route::get('/reports/cancelaciones', [ReportController::class, 'cancelaciones']);

    // Inventario
    Route::get('/inventario', [InventarioController::class, 'index']);
    Route::post('/inventario/set-stock', [InventarioController::class, 'setStock']);
    Route::post('/inventario/adjust-stock', [InventarioController::class, 'adjustStock']);

    // Admin users
    Route::get('/admin/users/backup', [AdminUsersController::class, 'backup']);
    Route::apiResource('admin/users', AdminUsersController::class)
        ->parameters(['users' => 'usuario']);
    Route::apiResource('admin/staff-roles', StaffRoleController::class)
        ->parameters(['staff-roles' => 'staffRole']);

    // Promociones
    Route::apiResource('promociones', PromocionesController::class)
        ->parameters(['promociones' => 'promocion']);

    // Legacy endpoints still waiting for a refactor
    Route::post('/cashier/expenses', [CashierController::class, 'registerExpense']);
    Route::post('/cashier/send-ticket', [CashierLegacyController::class, 'emailTicket']);

    // Mailer tracking
    Route::get('/mailer-track', [MailerTrackController::class, 'index']);

    // Admin sales tool
    Route::post('/admin/sales/list', [AdminSalesToolController::class, 'list']);
    Route::post('/admin/sales/{venta}/cancel', [AdminSalesToolController::class, 'cancel'])
        ->whereNumber('venta');
});
