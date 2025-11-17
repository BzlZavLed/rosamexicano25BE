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
        $this->assertEqualsWithDelta(600, (float) $venta->totalventa, 0.01);
        $this->assertEqualsWithDelta(600, (float) $venta->total_recibido, 0.01);
        $this->assertSame('tarjeta', $venta->metodo);
        $this->assertEqualsWithDelta(0, (float) $venta->cambio, 0.01);
        $this->assertEquals($today, $venta->fecha->format('Y-m-d'));
        $this->assertNotEmpty($venta->hora);

        $lineas = VentaDesg::where('idventa', $ventaId)->get()->keyBy('nombre');
        $this->assertCount(3, $lineas);

        $expectedLines = [
            'Normal Prod' => [
                'proveedor' => $normal->ident,
                'unit_price' => 100,
                'quantity' => 1,
                'public_total' => 100,
                'promotion_discount_amount' => 0,
                'credit_card_discount' => 4.5,
                'provider_percentage_discount' => 0,
                'consigna_discount' => 0,
                'provider_cost' => 100,
                'provider_payment' => 95.5,
                'admin_earnings' => 0,
            ],
            'Consigna Prod' => [
                'proveedor' => $consigna->ident,
                'unit_price' => 200,
                'quantity' => 1,
                'public_total' => 200,
                'promotion_discount_amount' => 0,
                'credit_card_discount' => 9.0,
                'provider_percentage_discount' => 0,
                'consigna_discount' => 80,
                'provider_cost' => 120,
                'provider_payment' => 111.0,
                'admin_earnings' => 80,
            ],
            'Porcentaje Prod' => [
                'proveedor' => $porcentaje->ident,
                'unit_price' => 300,
                'quantity' => 1,
                'public_total' => 300,
                'promotion_discount_amount' => 0,
                'credit_card_discount' => 13.5,
                'provider_percentage_discount' => 90,
                'consigna_discount' => 0,
                'provider_cost' => 210,
                'provider_payment' => 196.5,
                'admin_earnings' => 90,
            ],
        ];

        foreach ($expectedLines as $nombre => $expected) {
            $line = $lineas[$nombre];
            $this->assertEquals($expected['proveedor'], (int) $line->proveedor_id, "{$nombre}: proveedor incorrecto");
            $this->assertEqualsWithDelta($expected['unit_price'], (float) $line->unit_price, 0.01, "{$nombre}: precio unitario incorrecto");
            $this->assertEquals($expected['quantity'], (int) $line->quantity, "{$nombre}: cantidad incorrecta");
            $this->assertEqualsWithDelta($expected['public_total'], (float) $line->public_total, 0.01, "{$nombre}: total público incorrecto");
            $this->assertEqualsWithDelta($expected['promotion_discount_amount'], (float) $line->promotion_discount_amount, 0.01, "{$nombre}: promo incorrecta");
            $this->assertEqualsWithDelta($expected['credit_card_discount'], (float) $line->credit_card_discount, 0.01, "{$nombre}: cargo tarjeta incorrecto");
            $this->assertEqualsWithDelta($expected['provider_percentage_discount'], (float) $line->provider_percentage_discount, 0.01, "{$nombre}: descuento porcentaje incorrecto");
            $this->assertEqualsWithDelta($expected['consigna_discount'], (float) $line->consigna_discount, 0.01, "{$nombre}: descuento consigna incorrecto");
            $this->assertEqualsWithDelta($expected['provider_cost'], (float) $line->provider_cost, 0.01, "{$nombre}: costo proveedor incorrecto");
            $this->assertEqualsWithDelta($expected['provider_payment'], (float) $line->provider_payment, 0.01, "{$nombre}: pago proveedor incorrecto");
            $this->assertEqualsWithDelta($expected['admin_earnings'], (float) $line->admin_earnings, 0.01, "{$nombre}: ganancia admin incorrecta");
        }

        $expectedSurcharge = round((100 + 200 + 300) * 0.045, 2);
        $actualSurcharge = round($lineas->sum(fn ($line) => (float) $line->credit_card_discount), 2);
        $this->assertEqualsWithDelta($expectedSurcharge, $actualSurcharge, 0.01, 'El recargo por tarjeta debe prorratearse.');
    }
}
