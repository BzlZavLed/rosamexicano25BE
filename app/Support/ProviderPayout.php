<?php

namespace App\Support;

use App\Models\Producto;
use App\Models\Proveedor;

class ProviderPayout
{
    public static function calculate(array $lineItems, string $paymentMethod): array
    {
        $lines = [];
        $grossSubtotal = 0.0;
        $discountTotal = 0.0;

        $providerPublicTotals = [];
        $providerPostManualTotals = [];
        $providerLines = [];

        foreach ($lineItems as $index => $item) {
            /** @var Producto $producto */
            $producto = $item['producto'];
            $proveedor = $item['proveedor'] ?? null;

            if (!$proveedor && method_exists($producto, 'proveedor')) {
                $proveedor = $producto->relationLoaded('proveedor')
                    ? $producto->getRelation('proveedor')
                    : $producto->proveedor()->first();
            }

            $qty = (int) ($item['qty'] ?? 0);
            $unit = (float) ($item['unit_price'] ?? 0);
            $gross = round($unit * $qty, 2);
            $discount = min($gross, max(0, (float) ($item['discount_amount'] ?? 0)));
            $netBefore = $gross - $discount;

            $grossSubtotal += $gross;
            $discountTotal += $discount;

            $providerIdent = $proveedor?->ident ?? (int) ($producto->proveedorid ?? 0);
            $providerUnitCost = (float) ($producto->precio_proveedor ?? $unit);
            $providerType = $proveedor->tipo ?? 'normal';
            $providerPct = $proveedor->porcentaje_comision ?? null;

            $providerBruto = self::calculateProviderBruto($providerType, $gross, $qty, $providerUnitCost, $providerPct);
            $providerBruto = min($providerBruto, $gross);
            $providerManualDiscount = min($providerBruto, $discount);
            $providerPostManual = max(0, round($providerBruto - $providerManualDiscount, 2));
            $adminMarkup = max(0, round($gross - $providerBruto, 2));

            $lineData = [
                'producto' => $producto,
                'proveedor' => $proveedor,
                'provider_id' => $providerIdent,
                'qty' => $qty,
                'unit' => $unit,
                'gross' => $gross,
                'discount' => $discount,
                'net_before' => $netBefore,
                'provider_bruto' => $providerBruto,
                'provider_manual_discount' => $providerManualDiscount,
                'provider_post_manual' => $providerPostManual,
                'admin_markup' => $adminMarkup,
                'provider_charge' => 0.0,
                'public_total' => $gross,
            ];

            $lines[] = $lineData;
            $providerLines[$providerIdent][] = $index;
            if ($providerIdent > 0) {
                $providerPublicTotals[$providerIdent] = ($providerPublicTotals[$providerIdent] ?? 0) + $gross;
                $providerPostManualTotals[$providerIdent] = ($providerPostManualTotals[$providerIdent] ?? 0) + $providerPostManual;
            }
        }

        $totalPublic = array_sum($providerPublicTotals);
        $providerChargeTotal = 0.0;

        if (strtolower($paymentMethod) === 'tarjeta' && $totalPublic > 0) {
            $providerChargeTotal = round($totalPublic * 0.045, 2);
            $providerCharges = self::distributeSurcharge($providerPublicTotals, $totalPublic, $providerChargeTotal);

            foreach ($providerCharges as $providerId => $charge) {
                $indexes = $providerLines[$providerId] ?? [];
                if (empty($indexes) || $charge <= 0) {
                    continue;
                }

                $providerBase = max(0.01, $providerPostManualTotals[$providerId] ?? 0.01);
                $remainingCharge = $charge;
                $lineCount = count($indexes);

                foreach ($indexes as $pos => $lineIdx) {
                    $lineBase = max(0.01, $lines[$lineIdx]['provider_post_manual']);
                    if ($providerBase <= 0) {
                        $lineCharge = $pos === $lineCount - 1 ? round($remainingCharge, 2) : 0.0;
                    } elseif ($pos === $lineCount - 1) {
                        $lineCharge = round($remainingCharge, 2);
                    } else {
                        $weight = $lineBase / $providerBase;
                        $lineCharge = round($charge * $weight, 2);
                        $remainingCharge -= $lineCharge;
                    }

                    $lines[$lineIdx]['provider_charge'] = round(($lines[$lineIdx]['provider_charge'] ?? 0) + $lineCharge, 2);
                    $remainingCharge -= $lineCharge;
                }
            }
        }

        $afterDiscount = max(0, $grossSubtotal - $discountTotal);
        $total = round($afterDiscount, 2);

        $costoTotal = 0.0;
        $gananciaTotal = 0.0;

        foreach ($lines as &$line) {
            $providerManual = $line['provider_manual_discount'];
            $providerCard = $line['provider_charge'];
            $providerTotalDiscount = round($providerManual + $providerCard, 2);
            $providerNet = max(0, round($line['provider_bruto'] - $providerTotalDiscount, 2));
            $line['provider_total_discount'] = $providerTotalDiscount;
            $line['provider_net'] = $providerNet;
            $costoTotal += $providerNet;
            $gananciaTotal += $line['admin_markup'];
        }
        unset($line);

        return [
            'lines' => $lines,
            'gross_subtotal' => round($grossSubtotal, 2),
            'discount_total' => round($discountTotal, 2),
            'after_discount' => round($afterDiscount, 2),
            'provider_charge_total' => round($providerChargeTotal, 2),
            'total' => $total,
            'costo_total' => round($costoTotal, 2),
            'ganancia_total' => round($gananciaTotal, 2),
        ];
    }

    private static function calculateProviderBruto(string $type, float $gross, int $qty, float $providerUnitCost, ?int $percent): float
    {
        $gross = round($gross, 2);
        return match ($type) {
            'consigna' => round($providerUnitCost * $qty, 2),
            'porcentaje' => self::calculatePercentageBase($gross, $percent),
            default => $gross,
        };
    }

    private static function calculatePercentageBase(float $gross, ?int $percent): float
    {
        $percent = $percent ?? 0;
        $percent = max(0, min(100, $percent));
        $share = 1 - ($percent / 100);
        return round($gross * $share, 2);
    }

    private static function distributeSurcharge(array $providerTotals, float $base, float $surchargeTotal): array
    {
        if ($base <= 0 || $surchargeTotal <= 0) {
            return [];
        }

        $charges = [];
        $providerIds = array_keys($providerTotals);
        $remaining = $surchargeTotal;
        $count = count($providerIds);

        foreach ($providerIds as $index => $providerId) {
            $total = $providerTotals[$providerId];
            if ($total <= 0) {
                $charges[$providerId] = 0.0;
                continue;
            }

            if ($index === $count - 1) {
                $portion = round($remaining, 2);
                $charges[$providerId] = $portion;
                $remaining -= $portion;
            } else {
                $portion = round($surchargeTotal * ($total / $base), 2);
                $charges[$providerId] = $portion;
                $remaining -= $portion;
            }
        }

        if (!empty($charges) && abs($remaining) >= 0.01) {
            $lastProvider = end($providerIds);
            if ($lastProvider !== false) {
                $charges[$lastProvider] = round(($charges[$lastProvider] ?? 0) + $remaining, 2);
            }
        }

        return $charges;
    }
}
