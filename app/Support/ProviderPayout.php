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

            $unit = round((float) ($item['unit_price'] ?? $producto->precio ?? 0), 2);
            $totalQty = (int) ($item['qty'] ?? 0);
            $paidQty = max(0, (int) ($item['paid_quantity'] ?? $totalQty));
            $promotionDiscount = round((float) ($item['promotion_discount'] ?? 0), 2);
            $manualDiscount = round((float) ($item['manual_discount'] ?? 0), 2);
            $publicBase = round($unit * $paidQty, 2);

            $grossSubtotal += $publicBase;
            $discountTotal += ($promotionDiscount + $manualDiscount);

            $providerIdent = $proveedor?->ident ?? (int) ($producto->proveedorid ?? 0);
            $providerUnitCost = (float) ($item['provider_unit_cost'] ?? $producto->precio_proveedor ?? $unit);
            $providerType = $item['provider_type'] ?? ($proveedor->tipo ?? 'normal');
            $providerPct = $item['provider_pct'] ?? ($proveedor->porcentaje_comision ?? null);

            $providerCost = self::calculateProviderBruto($providerType, $publicBase, $paidQty, $providerUnitCost, $providerPct);
            $providerPercentageDiscount = $providerType === 'porcentaje'
                ? round(max(0, $publicBase - $providerCost), 2)
                : 0.0;
            $consignaDiscount = $providerType === 'consigna'
                ? round(max(0, ($unit - $providerUnitCost) * $paidQty), 2)
                : 0.0;

            $providerManualDiscount = min($providerCost, $manualDiscount);
            $providerPostManual = max(0, round($providerCost - $providerManualDiscount, 2));

            $lines[] = [
                'producto' => $producto,
                'proveedor' => $proveedor,
                'provider_id' => $providerIdent,
                'unit' => $unit,
                'qty' => $totalQty,
                'paid_qty' => $paidQty,
                'public_total' => $publicBase,
                'promotion_discount' => $promotionDiscount,
                'manual_discount' => $manualDiscount,
                'provider_cost' => $providerCost,
                'provider_manual_discount' => $providerManualDiscount,
                'provider_post_manual' => $providerPostManual,
                'credit_card_discount' => 0.0,
                'provider_percentage_discount' => $providerPercentageDiscount,
                'consigna_discount' => $consignaDiscount,
            ];

            $providerLines[$providerIdent][] = $index;
            if ($providerIdent > 0) {
                $providerPublicTotals[$providerIdent] = ($providerPublicTotals[$providerIdent] ?? 0) + $publicBase;
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

                    $lines[$lineIdx]['credit_card_discount'] = round(($lines[$lineIdx]['credit_card_discount'] ?? 0) + $lineCharge, 2);
                    $remainingCharge -= $lineCharge;
                }
            }
        }

        $afterDiscount = max(0, $grossSubtotal - $discountTotal);
        $total = round($afterDiscount, 2);

        $costoTotal = 0.0;
        $gananciaTotal = 0.0;

        foreach ($lines as &$line) {
            $providerCard = $line['credit_card_discount'];
            $providerNet = max(0, round($line['provider_post_manual'] - $providerCard, 2));
            $line['provider_net'] = $providerNet;
            $line['credit_card_discount'] = $providerCard;

            $adminEarnings = round(max(0, $line['public_total'] - $line['provider_cost']), 2);
            $line['admin_earnings'] = $adminEarnings;

            $costoTotal += $providerNet;
            $gananciaTotal += $adminEarnings;
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
