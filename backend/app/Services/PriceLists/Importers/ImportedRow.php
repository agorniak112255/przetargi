<?php

declare(strict_types=1);

namespace App\Services\PriceLists\Importers;

use App\Models\ProductIdentifier;

/**
 * Jeden wiersz cennika odczytany przez importer — wartości dosłownie z pliku (bez dopisywania cech).
 * toPayload() daje pozycję w kształcie, jaki przyjmuje PriceListImportService::persistImport
 * (jak normalizacja w importFromProducts), bez scalania rozmiarów.
 */
final class ImportedRow
{
    /**
     * @param  array<string, string>  $attributes  parametry wyrobu wypisane w cenniku (price_list_attributes)
     * @param  list<array{type: string, value: string, field: string, label: ?string}>  $codes  dodatkowe identyfikatory
     *                                                                                          z pliku (ProductIdentifier::TYPE_*: manufacturer_code, model_code, alt_code, pack_ean); kod
     *                                                                                          pozycji (source_code) i EAN dopisuje toPayload()
     */
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly float $catalogPriceNet,
        /** 'Arkusz!12' albo 'Strona 3, wiersz 7' — skąd w pliku */
        public readonly string $ref,
        public readonly string $currency = 'PLN',
        public readonly ?float $purchasePrice = null,
        public readonly ?float $discountPercent = null,
        public readonly ?string $ean = null,
        public readonly ?string $modelName = null,
        public readonly ?string $category = null,
        public readonly ?int $packQty = null,
        public readonly ?string $packaging = null,
        public readonly array $attributes = [],
        public readonly array $codes = [],
        /** marka towaru w cenniku wielomarkowym — null = producent cennika */
        public readonly ?string $manufacturer = null,
    ) {}

    /**
     * Pozycja dla persistImport. Zakup z pliku → `_purchase_from_file` (rabat wspólny go nie nadpisze);
     * bez zakupu i bez rabatu zakup liczy applyGroupOptions z rabatu wspólnego cennika.
     *
     * @return array<string, mixed>
     */
    public function toPayload(string $listManufacturer): array
    {
        $discount = $this->discountPercent;
        $purchase = $this->purchasePrice;
        if ($purchase === null && $discount !== null) {
            $purchase = round($this->catalogPriceNet * (1 - $discount / 100), 2);
        }

        $payload = [
            'sku' => $this->sku,
            'name' => $this->name,
            'manufacturer' => $this->manufacturer ?? $listManufacturer,
            'ean' => $this->ean,
            'category' => $this->category,
            'category_evidence' => null,
            'norms' => null,
            'price_list_attributes' => $this->attributes !== [] ? $this->attributes : null,
            'catalog_price_net' => $this->catalogPriceNet,
            'discount_percent' => $discount ?? 0.0,
            'purchase_price' => $purchase ?? $this->catalogPriceNet,
            'currency' => $this->currency,
            'stock' => 0,
            'pack_qty' => $this->packQty,
            'packaging' => $this->packaging,
            '_identifiers' => $this->identifiers(),
        ];
        if ($this->purchasePrice !== null || $this->discountPercent !== null) {
            $payload['_purchase_from_file'] = true;
        }

        return $payload;
    }

    /**
     * Identyfikatory w kształcie PriceListImportService::rowIdentifiers (position = kod pozycji w pliku).
     *
     * @return list<array{position: string, type: string, value: string, field: string, label: ?string}>
     */
    public function identifiers(): array
    {
        $label = $this->packaging !== null && trim($this->packaging) !== '' ? trim($this->packaging) : null;
        $out = [[
            'position' => $this->sku,
            'type' => ProductIdentifier::TYPE_SOURCE_CODE,
            'value' => $this->sku,
            'field' => 'sku',
            'label' => $label,
        ]];
        if ($this->ean !== null && trim($this->ean) !== '') {
            $out[] = ['position' => $this->sku, 'type' => ProductIdentifier::TYPE_EAN, 'value' => trim($this->ean), 'field' => 'ean', 'label' => $label];
        }
        foreach ($this->codes as $code) {
            $value = trim((string) ($code['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $out[] = [
                'position' => $this->sku,
                'type' => (string) $code['type'],
                'value' => $value,
                'field' => (string) ($code['field'] ?? $code['type']),
                'label' => $code['label'] ?? $label,
            ];
        }

        return $out;
    }
}
