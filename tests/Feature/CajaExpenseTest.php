<?php

namespace Tests\Feature;

use App\Models\DailyCashSummary;
use App\Models\EstadoCaja;
use App\Models\Inventario;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CajaExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_register_cash_expense(): void
    {
        $admin = Usuario::create([
            'email' => 'admin@example.com',
            'nombre' => 'Admin Test',
            'password' => bcrypt('secret123'),
            'puesto' => 1,
            'priv1' => 1,
            'priv2' => 1,
            'priv3' => 1,
            'priv4' => 1,
        ]);

        Sanctum::actingAs($admin, ['role:admin']);

        $today = Carbon::today();
        Carbon::setTestNow($today->copy()->setTime(9, 0));

        $this->postJson('/api/caja/open', [
            'fecha' => $today->format('d/m/y'),
            'saldoinicial' => 500,
        ])->assertCreated();

        $response = $this->postJson('/api/cashier/expenses', [
            'fecha' => $today->toDateString(),
            'descripcion' => 'Compra de insumos',
            'monto' => 150.75,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('egresos', [
            'descripcion' => 'Compra de insumos',
            'monto' => 150.75,
        ]);

        $status = $this->getJson('/api/caja/status');
        $status->assertOk();
        $summary = $status->json('cash_summary');
        $this->assertEquals(150.75, $summary['egresos']);
    }

    public function test_checkout_updates_daily_summary_and_close_sets_saldo_cierre(): void
    {
        $admin = Usuario::create([
            'email' => 'admin@example.com',
            'nombre' => 'Admin Test',
            'password' => bcrypt('secret123'),
            'puesto' => 1,
            'priv1' => 1,
            'priv2' => 1,
            'priv3' => 1,
            'priv4' => 1,
        ]);

        Sanctum::actingAs($admin, ['role:admin']);

        $today = Carbon::today();
        $fechaIso = $today->toDateString();
        $fechaDisplay = $today->format('d/m/y');

        $this->postJson('/api/caja/open', [
            'fecha' => $fechaDisplay,
            'saldoinicial' => 500,
        ])->assertCreated();

        $proveedor = Proveedor::create([
            'ident' => 9001,
            'nombre' => 'Proveedor Demo',
            'tipo' => 'normal',
            'fecha' => $fechaIso,
            'tel' => '5550001111',
            'email' => 'proveedor@example.com',
            'ciudad' => 'CDMX',
            'bancaria' => '0000000000',
            'sucursal' => 'Banco Demo',
            'importe' => 0,
        ]);

        $producto = Producto::create([
            'ident' => 8001,
            'nombre' => 'Producto Demo',
            'descripcion' => 'Demo',
            'precio' => 120,
            'precio_proveedor' => 80,
            'fecha' => $fechaIso,
            'proveedorid' => $proveedor->ident,
            'usuario' => $admin->id,
        ]);

        Inventario::create([
            'ident' => $producto->ident,
            'existencia' => 5,
            'importe' => 0,
            'provee' => $proveedor->ident,
            'precio_individual' => $producto->precio,
        ]);

        $this->postJson('/api/cashier/checkout', [
            'metodo' => 'efectivo',
            'recibo' => 120,
            'cambio' => 0,
            'vendedor' => $admin->nombre,
            'concepto' => 'VENTA',
            'lineas' => [[
                'idProd' => $producto->ident,
                'nombre' => $producto->nombre,
                'proveedor' => $proveedor->ident,
                'pUni' => 120,
                'cant' => 1,
            ]],
        ])->assertCreated();

        $summary = DailyCashSummary::whereDate('fecha', $fechaIso)->first();
        $this->assertNotNull($summary);
        $this->assertEquals(500.00, (float) $summary->saldo_inicial);
        $this->assertEquals(120.00, (float) $summary->efectivo);
        $this->assertEquals(0.00, (float) $summary->tarjeta);

        $this->postJson('/api/cashier/expenses', [
            'fecha' => $fechaIso,
            'descripcion' => 'Compra de papel',
            'monto' => 20,
        ])->assertCreated();

        $this->postJson('/api/caja/close', [
            'fecha' => $fechaDisplay,
            'saldofinal' => 600,
        ])->assertOk();

        $summary->refresh();
        $this->assertEquals(600.00, (float) $summary->saldo_cierre);

        $estado = EstadoCaja::where('fecha', $fechaIso)->first();
        $this->assertEquals(600.00, (float) $estado->saldo_cierre);
        $this->assertEquals(600.00, (float) $estado->saldosistema);
    }
}
