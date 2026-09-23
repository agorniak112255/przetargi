<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccountManufacturerRule;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;

/**
 * Znaczniki „cena” i „opis” producenta w cenniku konta B2B (decyzja użytkownika 23.09.2026). Dystrybutor wielu
 * marek nie musi ustalać ceny ani opisu każdej z nich — użytkownik wyłącza to w oknie „Producenci” przy koncie.
 * Brak reguły = oba znaczniki włączone. Klucz producenta: PriceList::manufacturerKey.
 */
final class B2bManufacturerRules
{
    public const ALLOW_ALL = ['price' => true, 'description' => true];

    public static function key(string $manufacturer): string
    {
        return PriceList::manufacturerKey($manufacturer);
    }

    /**
     * Wszystkie wyłączenia konta — synchronizacja czyta je raz na przebieg.
     *
     * @return array<string, array{price: bool, description: bool}> klucz producenta => znaczniki
     */
    public function forAccount(int $accountId): array
    {
        $out = [];
        foreach (B2bAccountManufacturerRule::query()->where('b2b_account_id', $accountId)->get() as $rule) {
            $out[(string) $rule->manufacturer_key] = [
                'price' => (bool) $rule->take_price,
                'description' => (bool) $rule->take_description,
            ];
        }

        return $out;
    }

    /**
     * @return array{price: bool, description: bool}
     */
    public function flagsFor(int $accountId, string $manufacturer): array
    {
        $rule = B2bAccountManufacturerRule::query()
            ->where('b2b_account_id', $accountId)
            ->where('manufacturer_key', self::key($manufacturer))
            ->first();

        return $rule === null ? self::ALLOW_ALL : [
            'price' => (bool) $rule->take_price,
            'description' => (bool) $rule->take_description,
        ];
    }

    /**
     * Czy konto powiązania może pisać opis tej karty — dla jobów opisu (tłumaczenie, opis z karty katalogowej),
     * zleconych zanim użytkownik wyłączył opis producenta. Producent w brzmieniu konta, inaczej z karty.
     */
    public function descriptionAllowed(B2bProductLink $link, Product $product): bool
    {
        $manufacturer = (string) ($link->manufacturer ?? $product->manufacturer ?? '');

        return $this->flagsFor((int) $link->b2b_account_id, $manufacturer)['description'];
    }

    /**
     * Konta z wyłączoną ceną, po kluczu producenta — jedno zapytanie dla wszystkich slotów karty.
     *
     * @param  list<int>  $accountIds
     * @return array<int, array<string, true>> id konta => [klucz producenta => true]
     */
    public function priceDisabled(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }
        $out = [];
        $rows = B2bAccountManufacturerRule::query()
            ->whereIn('b2b_account_id', $accountIds)
            ->where('take_price', false)
            ->get(['b2b_account_id', 'manufacturer_key']);
        foreach ($rows as $row) {
            $out[(int) $row->b2b_account_id][(string) $row->manufacturer_key] = true;
        }

        return $out;
    }
}
