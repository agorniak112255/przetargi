<?php

declare(strict_types=1);

namespace App\Services\Erp;

/**
 * Część towaru w magazynach usługowych (słownik erp_warehouses) z rozbicia stanu na magazyny; handlowe = całość − usługowe.
 * Liczone przy odczycie stanów i przeliczane z zapisanego rozbicia po zmianie słownika — bez odczytu z XL.
 */
final class WarehouseSplit
{
    /**
     * @param  list<array{code?: string, quantity?: float|int, value?: float|null, oldest_lot?: string|null}>  $warehouses
     * @param  list<string>  $serviceCodes
     * @param  string|null  $itemOldest  najstarsza dostawa towaru (wszystkie magazyny) — zapas dla starego rozbicia bez dat
     * @return array{stock_service: float, stock_service_value: float|null, oldest_lot_trade_at: string|null, oldest_lot_service_at: string|null}
     */
    public static function compute(array $warehouses, array $serviceCodes, ?string $itemOldest): array
    {
        $service = array_flip($serviceCodes);
        $qty = ['trade' => 0.0, 'service' => 0.0];
        $serviceValue = 0.0;
        $serviceValueKnown = true;
        $oldest = ['trade' => null, 'service' => null];
        $datesKnown = true;
        foreach ($warehouses as $w) {
            $scope = isset($service[(string) ($w['code'] ?? '')]) ? 'service' : 'trade';
            $quantity = (float) ($w['quantity'] ?? 0);
            $qty[$scope] += $quantity;
            if ($scope === 'service') {
                if (($w['value'] ?? null) === null) {
                    $serviceValueKnown = false;
                } else {
                    $serviceValue += (float) $w['value'];
                }
            }
            if (! array_key_exists('oldest_lot', $w)) {
                $datesKnown = false;
            } elseif ($w['oldest_lot'] !== null && $quantity > 0 && ($oldest[$scope] === null || $w['oldest_lot'] < $oldest[$scope])) {
                $oldest[$scope] = $w['oldest_lot'];
            }
        }
        if (! $datesKnown) {
            // rozbicie sprzed zapisu dat na magazyn: data towaru pasuje tylko, gdy cały stan jest po jednej stronie
            $oldest = [
                'trade' => $qty['service'] <= 0 && $qty['trade'] > 0 ? $itemOldest : null,
                'service' => $qty['trade'] <= 0 && $qty['service'] > 0 ? $itemOldest : null,
            ];
        }

        return [
            'stock_service' => round($qty['service'], 4),
            'stock_service_value' => $qty['service'] > 0 || $serviceValue !== 0.0 ? ($serviceValueKnown ? round($serviceValue, 2) : null) : 0.0,
            'oldest_lot_trade_at' => $oldest['trade'],
            'oldest_lot_service_at' => $oldest['service'],
        ];
    }
}
