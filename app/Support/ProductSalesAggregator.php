<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductSalesAggregator
{
    /**
     * Aggregate sales by provider/product between the given dates using current and historic tables.
     */
    public static function aggregate(string $startDate, string $endDate, array $productIdents = [], array $providerIdents = []): Collection
    {
        $start = $startDate;
        $end = $endDate;
        $productFilter = array_values(array_filter(array_map('strval', $productIdents)));
        $providerFilter = array_values(array_filter(array_map('strval', $providerIdents)));

        $current = DB::table('ventadesg as vd');
        $ventasExists = Schema::hasTable('ventas');
        $productColumn = Schema::hasColumn('ventadesg', 'producto_id') ? 'vd.producto_id' : 'vd.idprod';
        $providerColumn = Schema::hasColumn('ventadesg', 'proveedor_id') ? 'vd.proveedor_id' : 'vd.proveedor';
        $quantityColumn = Schema::hasColumn('ventadesg', 'quantity') ? 'vd.quantity' : (Schema::hasColumn('ventadesg', 'cant') ? 'vd.cant' : 'vd.quantity');
        $fechaExpr = $ventasExists ? 'COALESCE(vd.fecha, v.fecha)' : 'vd.fecha';
        $fechaNormalized = self::normalizeDateExpression($fechaExpr);
        if ($ventasExists) {
            $current->leftJoin('ventas as v', 'v.idventa', '=', 'vd.idventa');
        }

        $providerIdentExpr = self::castToString($providerColumn);
        $productIdentExpr = self::castToString($productColumn);

        $current->selectRaw('COALESCE(' . $providerIdentExpr . ', \'\') as provider_ident')
            ->selectRaw($productIdentExpr . ' as producto_ident')
            ->selectRaw($fechaNormalized . ' as fecha')
            ->selectRaw($quantityColumn . ' as unidades');

        if (!empty($productFilter)) {
            $current->whereIn($productColumn, $productFilter);
        }
        if (!empty($providerFilter)) {
            $current->whereIn($providerColumn, $providerFilter);
        }
        $current->whereBetween(DB::raw($fechaNormalized), [$start, $end]);

        $queries = [$current];

        if (Schema::hasTable('historic_ventadesg')) {
            $historic = DB::table('historic_ventadesg as hvd');
            $historicFechaExpr = 'hvd.fecha';
            if (Schema::hasTable('historic_ventas')) {
                $historic->leftJoin('historic_ventas as hv', 'hv.legacy_id', '=', 'hvd.venta_legacy_id');
                $historicFechaExpr = 'COALESCE(hvd.fecha, hv.fecha)';
            }
            $historicFechaNormalized = self::normalizeDateExpression($historicFechaExpr);

            $historic->selectRaw('COALESCE(hvd.proveedor_ident, \'\') as provider_ident')
                ->selectRaw('hvd.producto_ident as producto_ident')
                ->selectRaw($historicFechaNormalized . ' as fecha')
                ->selectRaw('hvd.cantidad as unidades');

            if (!empty($productFilter)) {
                $historic->whereIn('hvd.producto_ident', $productFilter);
            }
            if (!empty($providerFilter)) {
                $historic->whereIn('hvd.proveedor_ident', $providerFilter);
            }
            $historic->whereBetween(DB::raw($historicFechaNormalized), [$start, $end]);

            $queries[] = $historic;
        }

        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        return DB::query()
            ->fromSub($union, 'sales')
            ->selectRaw('provider_ident, producto_ident, SUM(unidades) as unidades, COUNT(DISTINCT fecha) as dias_con_venta')
            ->groupBy('provider_ident', 'producto_ident')
            ->get();
    }

    private static function normalizeDateExpression(string $expression): string
    {
        $wrapped = '(' . $expression . ')';
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            return "COALESCE(
                TO_DATE($wrapped::text, 'YYYY-MM-DD'),
                TO_DATE($wrapped::text, 'YYYY/MM/DD'),
                TO_DATE($wrapped::text, 'DD/MM/YYYY'),
                TO_DATE($wrapped::text, 'DD/MM/YY'),
                NULLIF($wrapped::text, '')::date
            )";
        }

        return "COALESCE(
            STR_TO_DATE($wrapped, '%Y-%m-%d'),
            STR_TO_DATE($wrapped, '%Y/%m/%d'),
            STR_TO_DATE($wrapped, '%d/%m/%Y'),
            STR_TO_DATE($wrapped, '%d/%m/%y'),
            $wrapped
        )";
    }

    private static function castToString(string $column): string
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            return "($column)::text";
        }

        return "CAST($column AS CHAR(64))";
    }
}
