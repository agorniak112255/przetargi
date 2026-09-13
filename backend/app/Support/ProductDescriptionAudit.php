<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;

/**
 * Czy opis karty należy do tego produktu. Enrichment bywał trafiony w obcą stronę
 * (opis JOKI na karcie CERRO, opis hełmu na systemie asekuracyjnym) — wspólna
 * logika raportu `products:audit-descriptions` i czyszczenia `products:reset-foreign-descriptions`.
 */
final class ProductDescriptionAudit
{
    public const REASON_FAMILY = 'family';

    public const REASON_GARMENT = 'garment';

    public const REASON_UNRELATED = 'unrelated';

    /** Zrzut strony (tabela części, cookies) albo opis nie po polsku — batch #298, Coba. */
    public const REASON_PAGE_DUMP = 'page_dump';

    private const STOP_TOKENS = [
        'robocze', 'ochronne', 'ochronna', 'ochronny', 'meskie', 'damskie', 'czarne', 'czarny',
        'granatowe', 'granatowy', 'niebieskie', 'zielone', 'szare', 'biale', 'guma', 'skora',
        'rozmiar', 'komplet', 'zestaw', 'para', 'sztuka', 'linia', 'seria', 'model',
    ];

    public function __construct(private readonly PpeAssortment $ppe) {}

    /** @return array{id: int, sku: string, name: string, reason: string, detail: string}|null */
    public function inspect(Product $product): ?array
    {
        $description = (string) $product->description;
        if (trim($description) === '') {
            return null;
        }
        // przed rodziną: zrzut strony producenta nazywa model i markę, więc reszta kontroli go przepuszcza
        if (ProductDescriptionText::looksLikeForeignOrPartsTableDump($description)) {
            return $this->finding($product, self::REASON_PAGE_DUMP, 'zrzut strony albo opis nie po polsku');
        }

        $productText = trim($product->name.' '.$product->sku.' '.(string) $product->category);

        $nameFamily = $this->ppe->family($productText);
        $descFamily = $this->ppe->family($description);

        if ($nameFamily !== null && $descFamily !== null && $nameFamily !== $descFamily) {
            return $this->finding($product, self::REASON_FAMILY, "nazwa={$nameFamily} opis={$descFamily}");
        }

        if ($nameFamily === PpeAssortment::FAMILY_APPAREL && $descFamily === PpeAssortment::FAMILY_APPAREL) {
            $nameGarment = $this->ppe->garment($productText);
            $descGarment = $this->ppe->garment($description);
            if ($nameGarment !== null && $descGarment !== null && $nameGarment !== $descGarment) {
                return $this->finding($product, self::REASON_GARMENT, "nazwa={$nameGarment} opis={$descGarment}");
            }
        }

        if (! $this->descriptionSharesToken($product, $description)) {
            return $this->finding($product, self::REASON_UNRELATED, 'opis nie zawiera SKU, modelu ani marki');
        }

        return null;
    }

    /** @return array{id: int, sku: string, name: string, reason: string, detail: string} */
    private function finding(Product $product, string $reason, string $detail): array
    {
        return [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'name' => mb_substr((string) $product->name, 0, 60),
            'reason' => $reason,
            'detail' => $detail,
        ];
    }

    /** Opis powinien nazwać po imieniu model, kod albo markę — inaczej to cudza karta. */
    private function descriptionSharesToken(Product $product, string $description): bool
    {
        $hay = mb_strtolower($description);

        foreach ([(string) $product->sku, (string) $product->manufacturer] as $value) {
            $value = mb_strtolower(trim($value));
            if ($value !== '' && str_contains($hay, $value)) {
                return true;
            }
        }

        foreach (preg_split('/[\s\-®™\/_,.]+/u', mb_strtolower((string) $product->name)) ?: [] as $token) {
            $token = trim($token);
            if (mb_strlen($token) < 4 || in_array($token, self::STOP_TOKENS, true)) {
                continue;
            }
            if (str_contains($hay, $token)) {
                return true;
            }
        }

        return false;
    }
}
