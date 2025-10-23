<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nombre', 200);
            $table->string('email', 200);
        });

        Schema::create('entradas', function (Blueprint $table) {
            $table->increments('id');
            $table->string('prodnombre', 50);
            $table->string('prodid', 10);
            $table->string('provid', 10);
            $table->integer('ingreal');
            $table->string('fecha', 10);
            $table->integer('accion');
            $table->string('usuario', 85);
        });

        Schema::create('estadocaja', function (Blueprint $table) {
            $table->increments('id');
            $table->string('fecha', 10);
            $table->integer('estado');
            $table->decimal('saldo', 11, 2);
            $table->decimal('saldosistema', 11, 2);
            $table->string('usuario', 65);
        });

        Schema::create('inventario', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('ident')->unique();
            $table->integer('existencia');
            $table->decimal('importe', 11, 2);
            $table->unsignedInteger('provee');
        });

        Schema::create('mailer', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email_to', 80);
            $table->string('name', 80);
            $table->string('subject', 150);
            $table->date('fecha');
            $table->binary('data');
        });

        Schema::create('mensualidad', function (Blueprint $table) {
            $table->increments('id');
            $table->string('marca', 10);
            $table->string('nombre_marca', 100);
            $table->string('mes_cobro', 10);
            $table->string('fecha', 10);
            $table->decimal('importe', 10, 2);
            $table->integer('email');
            $table->integer('pagado');
            $table->decimal('cantidad', 10, 2);
            $table->date('fechaPago');
        });

        Schema::create('producto', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('ident')->unique();
            $table->string('nombre', 100);
            $table->string('descripcion', 100);
            $table->string('fecha', 10);
            $table->unsignedInteger('proveedorid');
            $table->string('usuario', 80);
            $table->decimal('precio', 11, 2);
        });

        Schema::create('promociones', function (Blueprint $table) {
            $table->increments('id');
            $table->string('producto', 45)->nullable();
            $table->string('productoNombre', 45)->nullable();
            $table->string('proveedorid', 45)->nullable();
            $table->string('proveedorNombre', 45)->nullable();
            $table->string('tipoPromocion', 25)->nullable();
            $table->string('descuento', 3)->nullable();
            $table->string('minimoCompra', 2)->nullable();
            $table->string('cantidadGratis', 2)->nullable();
            $table->string('fechaVencimiento', 45)->nullable();
        });

        Schema::create('proveedores', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('ident');
            $table->string('nombre', 60)->unique();
            $table->date('fecha');
            $table->string('tel', 10);
            $table->string('email', 100);
            $table->string('calle', 100);
            $table->string('bancaria', 50);
            $table->string('ciudad', 100);
            $table->decimal('importe', 10, 2);
            $table->string('sucursal', 100);
        });

        Schema::create('registro', function (Blueprint $table) {
            $table->increments('id');
            $table->string('accion', 150);
            $table->string('user', 50);
            $table->string('fecha', 10);
        });

        Schema::create('usuarios', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email', 150);
            $table->string('password', 15);
            $table->integer('puesto');
            $table->string('nombre', 150);
            $table->string('priv1', 100);
            $table->string('priv2', 100);
            $table->string('priv3', 100);
            $table->string('priv4', 100);
        });

        Schema::create('ventadesg', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('idventa');
            $table->string('fecha', 10);
            $table->unsignedInteger('idProd');
            $table->string('nombre', 65);
            $table->unsignedInteger('proveedor');
            $table->decimal('pUni', 11, 2);
            $table->integer('cant');
            $table->decimal('total', 11, 2);
            $table->decimal('totdesc', 10, 2);
            $table->time('hora', 6);
        });

        Schema::create('ventas', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('idventa');
            $table->decimal('totalventa', 11, 2);
            $table->string('metodo', 50);
            $table->decimal('recibo', 11, 2);
            $table->decimal('cambio', 11, 2);
            $table->string('vendedor', 45);
            $table->string('fecha', 10);
            $table->integer('ie');
            $table->string('concepto', 60);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventas');
        Schema::dropIfExists('ventadesg');
        Schema::dropIfExists('usuarios');
        Schema::dropIfExists('registro');
        Schema::dropIfExists('proveedores');
        Schema::dropIfExists('promociones');
        Schema::dropIfExists('producto');
        Schema::dropIfExists('mensualidad');
        Schema::dropIfExists('mailer');
        Schema::dropIfExists('inventario');
        Schema::dropIfExists('estadocaja');
        Schema::dropIfExists('entradas');
        Schema::dropIfExists('clientes');
    }
};

