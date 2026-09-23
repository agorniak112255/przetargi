<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Services\B2b\B2bConnectorRegistry;
use App\Support\CanonicalBrand;

/**
 * Właściciel karty — z danych, które już są, bez nowej kolumny. Właścicielem jest źródło producenta marki karty:
 * - konto B2B powiązane z kartą (b2b_product_links), którego łącznik jest cennikiem producenta tej marki
 *   (B2bConnectorRegistry::brandsForKey — ta sama lista marek co w ProductEffectivePrice);
 * - cennik z pliku, którego slot „file” jest na karcie, a price_lists.manufacturer to marka karty — także cennik
 *   sugerowany (ceny wybiera ProductEffectivePrice osobno; tu chodzi o to, czyje są nazwa i producent).
 * Marki porównywane kanonicznie (CanonicalBrand: słownik marek, potem pierwsze słowo).
 *
 * Po co: dystrybutor wielu marek (P4S, Raw-Pol, Ardon, Procera) dopięty do karty producenta przez
 * products:merge-duplicate nadpisywał przy każdym przebiegu producenta karty, listę rozmiarów, dowód kategorii
 * i kolejność zdjęć — a od producenta karty zależy wybór ceny (plik producenta) i szukanie karty przy imporcie.
 * Karta z właścicielem jest chroniona przed kontami, które nie są jej właścicielem (B2bCatalogSync).
 */
final class CardOwnership
{
    public function __construct(
        private readonly B2bConnectorRegistry $connectors = new B2bConnectorRegistry,
    ) {}

    /**
     * Źródła-właściciele karty: 'b2b:{id}' kont producenta marki karty, które mają z nią powiązanie, i 'file'.
     *
     * @return list<string>
     */
    public function ownerSourceKeys(Product $product): array
    {
        $brand = CanonicalBrand::key($product->manufacturer);
        if ($brand === '' || ! $product->exists) {
            return [];
        }

        $keys = [];
        $accountIds = B2bProductLink::query()
            ->where('product_id', $product->id)
            ->distinct()
            ->pluck('b2b_account_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        if ($accountIds !== []) {
            foreach (B2bAccount::query()->whereIn('id', $accountIds)->orderBy('id')->get() as $account) {
                if ($this->accountHasBrand($account, $brand)) {
                    $keys[] = ProductSourcePrice::b2bKey((int) $account->id);
                }
            }
        }

        $fileSlot = ProductSourcePrice::query()
            ->where('product_id', $product->id)
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->with('priceList')
            ->first();
        if ($fileSlot !== null && CanonicalBrand::key($fileSlot->priceList?->manufacturer) === $brand) {
            $keys[] = ProductSourcePrice::SOURCE_FILE;
        }

        return $keys;
    }

    /**
     * Czy konto jest cennikiem producenta marki tej karty. Bez wymogu powiązania — konto producenta, które pierwszy
     * raz trafia w swoją kartę (po kodzie), też jest u siebie.
     */
    public function isOwnerAccount(Product $product, B2bAccount $account): bool
    {
        $brand = CanonicalBrand::key($product->manufacturer);

        return $brand !== '' && $this->accountHasBrand($account, $brand);
    }

    /** Karta ma co najmniej jednego właściciela. */
    public function isProtected(Product $product): bool
    {
        return $this->ownerSourceKeys($product) !== [];
    }

    private function accountHasBrand(B2bAccount $account, string $brand): bool
    {
        foreach ($this->connectors->brandsForKey($this->connectors->keyForAccount($account)) as $accountBrand) {
            if (CanonicalBrand::key($accountBrand) === $brand) {
                return true;
            }
        }

        return false;
    }
}
