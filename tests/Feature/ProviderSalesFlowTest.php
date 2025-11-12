<?php

namespace Tests\Feature;

use App\Models\EstadoCaja;
use App\Models\Inventario;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Usuario;
use App\Models\Venta;
use App\Models\VentaDesg;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProviderSalesFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_records_provider_payouts_for_each_type(): void
    {
        $admin = Usuario::create([
            'email' => 'admin@example.com',
            'nombre' => 'Admin Test',
            'password' => 'secret123',
            'puesto' => 1,
            'priv1' => 1,
            'priv2' => 1,
            'priv3' => 1,
            'priv4' => 1,
        ]);

        $today = Carbon::today()->format('Y-m-d');
        EstadoCaja::create([
            'fecha' => $today,
            'estado' => 1,
            'saldoinicial' => 0,
            'saldofinal' => 0,
            'saldosistema' => 0,
            'usuario' => 'Admin Test',
        ]);

        $normal = Proveedor::create([
            'ident' => 111111,
            'nombre' => 'Proveedor Normal',
            'fecha' => $today,
            'tel' => '5551112222',
            'email' => 'normal@example.com',
            'ciudad' => 'CDMX',
            'bancaria' => '1234567890',
            'sucursal' => 'Banco 1',
            'importe' => 1500,
            'tipo' => 'normal',
        ]);

        $consigna = Proveedor::create([
            'ident' => 222222,
            'nombre' => 'Proveedor Consigna',
            'fecha' => $today,
            'tel' => '5553334444',
            'email' => 'consigna@example.com',
            'ciudad' => 'CDMX',
            'bancaria' => '0987654321',
            'sucursal' => 'Banco 2',
            'importe' => 0,
            'tipo' => 'consigna',
        ]);

        $porcentaje = Proveedor::create([
            'ident' => 333333,
            'nombre' => 'Proveedor Porcentaje',
            'fecha' => $today,
            'tel' => '5557778888',
            'email' => 'porcentaje@example.com',
            'ciudad' => 'CDMX',
            'bancaria' => '1122334455',
            'sucursal' => 'Banco 3',
            'importe' => 0,
            'tipo' => 'porcentaje',
            'porcentaje_comision' => 30,
        ]);

        $productos = [
            'normal' => Producto::create([
                'ident' => 500001,
                'nombre' => 'Normal Prod',
                'descripcion' => 'Prod normal',
                'fecha' => $today,
                'proveedorid' => $normal->ident,
                'precio' => 100,
                'precio_proveedor' => 100,
                'usuario' => $admin->id,
            ]),
            'consigna' => Producto::create([
                'ident' => 500002,
                'nombre' => 'Consigna Prod',
                'descripcion' => 'Prod consigna',
                'fecha' => $today,
                'proveedorid' => $consigna->ident,
                'precio' => 200,
                'precio_proveedor' => 120,
                'usuario' => $admin->id,
            ]),
            'porcentaje' => Producto::create([
                'ident' => 500003,
                'nombre' => 'Porcentaje Prod',
                'descripcion' => 'Prod porcentaje',
                'fecha' => $today,
                'proveedorid' => $porcentaje->ident,
                'precio' => 300,
                'precio_proveedor' => 210,
                'usuario' => $admin->id,
            ]),
        ];

        foreach ($productos as $producto) {
            Inventario::create([
                'ident' => $producto->ident,
                'existencia' => 10,
                'importe' => 0,
                'provee' => $producto->proveedorid,
                'precio_individual' => $producto->precio,
            ]);
        }

        Sanctum::actingAs($admin, ['role:admin']);

        $response = $this->postJson('/api/cashier/checkout', [
            'metodo' => 'tarjeta',
            'recibo' => 600,
            'cambio' => 0,
            'vendedor' => $admin->nombre,
            'concepto' => 'VENTA MOSTRADOR',
            'lineas' => [
                [
                    'idProd' => $productos['normal']->ident,
                    'nombre' => $productos['normal']->nombre,
                    'proveedor' => $normal->ident,
                    'pUni' => 100,
                    'cant' => 1,
                ],
                [
                    'idProd' => $productos['consigna']->ident,
                    'nombre' => $productos['consigna']->nombre,
                    'proveedor' => $consigna->ident,
                    'pUni' => 200,
                    'cant' => 1,
                ],
                [
                    'idProd' => $productos['porcentaje']->ident,
                    'nombre' => $productos['porcentaje']->nombre,
                    'proveedor' => $porcentaje->ident,
                    'pUni' => 300,
                    'cant' => 1,
                ],
            ],
        ]);

        $response->assertCreated();

        $ventaId = $response->json('data.venta.idventa');
        $this->assertNotNull($ventaId, 'La venta debe devolver un consecutivo.');

        $venta = Venta::where('idventa', $ventaId)->first();
        $this->assertNotNull($venta);
        $this->assertEqualsWithDelta(600, (float) $venta->subtotal, 0.01);
        $this->assertEqualsWithDelta(27, (float) $venta->tarjeta_cargo, 0.01);
        $this->assertEqualsWithDelta(600, (float) $venta->totalventa, 0.01);
        $this->assertEqualsWithDelta(600, (float) $venta->ingreso_real, 0.01);
        $this->assertEqualsWithDelta(403, (float) $venta->costo_total, 0.01);
        $this->assertEqualsWithDelta(170, (float) $venta->ganancia_total, 0.01);
        $this->assertSame('tarjeta', $venta->metodo);
        $this->assertEqualsWithDelta(600, (float) $venta->recibo, 0.01);
        $this->assertEqualsWithDelta(0, (float) $venta->cambio, 0.01);

        $lineas = VentaDesg::where('idventa', $ventaId)->get()->keyBy('nombre');
        $this->assertCount(3, $lineas);

        $expectedLines = [
            'Normal Prod' => [
                'proveedor' => $normal->ident,
                'puni' => 100,
                'cant' => 1,
                'total' => 100,
                'descuento_producto' => 0,
                'cargo_tarjeta_proveedor' => 4.5,
                'proveedor_bruto' => 100,
                'proveedor_descuento' => 4.5,
                'proveedor_neto' => 95.5,
                'admin_ganancia' => 0,
                'proveedor_porcentaje' => null,
            ],
            'Consigna Prod' => [
                'proveedor' => $consigna->ident,
                'puni' => 200,
                'cant' => 1,
                'total' => 200,
                'descuento_producto' => 0,
                'cargo_tarjeta_proveedor' => 9.0,
                'proveedor_bruto' => 120,
                'proveedor_descuento' => 9.0,
                'proveedor_neto' => 111.0,
                'admin_ganancia' => 80,
                'proveedor_porcentaje' => null,
            ],
            'Porcentaje Prod' => [
                'proveedor' => $porcentaje->ident,
                'puni' => 300,
                'cant' => 1,
                'total' => 300,
                'descuento_producto' => 0,
                'cargo_tarjeta_proveedor' => 13.5,
                'proveedor_bruto' => 210,
                'proveedor_descuento' => 13.5,
                'proveedor_neto' => 196.5,
                'admin_ganancia' => 90,
                'proveedor_porcentaje' => 30,
            ],
        ];

        foreach ($expectedLines as $nombre => $expected) {
            $line = $lineas[$nombre];
            $puniValue = (float) ($line->puni ?? $line->pUni ?? $line->p_uni ?? 0);
            $this->assertEquals($expected['proveedor'], (int) $line->proveedor, "{$nombre}: proveedor incorrecto");
            $this->assertEqualsWithDelta($expected['puni'], $puniValue, 0.01, "{$nombre}: precio unitario incorrecto");
            $this->assertEquals($expected['cant'], (int) $line->cant, "{$nombre}: cantidad incorrecta");
            $this->assertEqualsWithDelta($expected['total'], (float) $line->total, 0.01, "{$nombre}: total incorrecto");
            $this->assertEqualsWithDelta($expected['descuento_producto'], (float) ($line->descuento_producto ?? 0), 0.01, "{$nombre}: descuento producto incorrecto");
            $this->assertEqualsWithDelta($expected['cargo_tarjeta_proveedor'], (float) ($line->cargo_tarjeta_proveedor ?? 0), 0.01, "{$nombre}: cargo tarjeta proveedor incorrecto");
            $this->assertEqualsWithDelta($expected['proveedor_bruto'], (float) ($line->proveedor_bruto ?? 0), 0.01, "{$nombre}: proveedor bruto incorrecto");
            $this->assertEqualsWithDelta($expected['proveedor_descuento'], (float) ($line->proveedor_descuento ?? 0), 0.01, "{$nombre}: proveedor descuento incorrecto");
            $this->assertEqualsWithDelta($expected['proveedor_neto'], (float) ($line->proveedor_neto ?? 0), 0.01, "{$nombre}: proveedor neto incorrecto");
            $this->assertEqualsWithDelta($expected['admin_ganancia'], (float) ($line->admin_ganancia ?? 0), 0.01, "{$nombre}: admin ganancia incorrecta");
            if ($expected['proveedor_porcentaje'] === null) {
                $this->assertNull($line->proveedor_porcentaje, "{$nombre}: proveedor porcentaje debería ser null");
            } else {
                $this->assertEqualsWithDelta($expected['proveedor_porcentaje'], (float) $line->proveedor_porcentaje, 0.01, "{$nombre}: porcentaje incorrecto");
            }
        }

        $expectedSurcharge = round((100 + 200 + 300) * 0.045, 2);
        $actualSurcharge = round($lineas->sum(fn ($line) => (float) ($line->cargo_tarjeta_proveedor ?? 0)), 2);
        $this->assertEqualsWithDelta($expectedSurcharge, $actualSurcharge, 0.01, 'El recargo por tarjeta debe prorratearse.');
    }
}
