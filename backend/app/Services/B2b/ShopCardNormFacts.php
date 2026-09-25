<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductShopCard;
use App\Support\BrandKey;
use App\Support\ManufacturerNormFacts;
use App\Support\Utf8Trim;

/**
 * Normy producenta z tabelki karty wyrobu w jego własnym sklepie B2B (łącznik B2bShopFieldNormSource) — plan norm
 * z 23.09.2026, etap 2. Tabelkę zapisuje synchronizacja (ProductShopCard), więc odczyt nie wysyła nic do sklepu,
 * a ten sam kod służy przebiegowi synchronizacji i uzupełnieniu istniejących kart (norms:from-shop-cards).
 *
 * Wiersz „Norma” bywa jedną normą („EN 355”, „EN ISO 20345:2022 S3L FO SR”) albo listą („EN 166:2001, EN 14594 3B,
 * EN 12941:1998 TH3, Oznaczenie CE”). Każda pozycja listy to para: oznaczenie normy (z rokiem wydania, dosłownie)
 * i reszta zapisu jako wartość — bez przepisywania i bez zgadywania poziomów; co z wartości jest poziomem EN 388,
 * rozstrzyga ManufacturerNormFacts. Pozycja bez oznaczenia normy („Oznaczenie CE”) nie jest normą i odpada.
 */
final class ShopCardNormFacts
{
    public const SAVED = 'saved';

    public const SAME = 'same';

    public const NONE = 'none';

    public const OTHER_SOURCE = 'other_source';

    /** Oznaczenie normy na początku pozycji: „EN 388:2016 + A1:2018”, „EN ISO 20345:2022”, „EN143 : 2001”, „PN-EN 361”. */
    private const DESIGNATION = '/^(?:PN[\s\-]+)?EN(?:\s?ISO)?\s?\d{2,6}(?:-\d{1,3})*(?:\s?:\s?(?:19|20)\d{2}(?!\d))?(?:\s*\+\s*A\d{1,2}(?:\s?:\s?(?:19|20)\d{2}(?!\d))?)?/iu';

    /** Separator listy tylko przed kolejnym oznaczeniem — przecinek wewnątrz wartości („kat. II, III”) listy nie dzieli. */
    private const LIST_SPLIT = '/\s*[,;\n]\s*(?=(?:PN[\s\-]+)?EN\s?(?:ISO\s?)?\d)/iu';

    /** Dopisek o znaku CE na końcu listy 3M — to nie poziom normy stojącej przed nim. */
    private const CE_TAIL = '/\s*[,;]\s*(?:oznaczenie\s+)?CE\s*$/iu';

    /**
     * Pary z wierszy tabelki o podanych nazwach, w kolejności tabelki; powtórzone pary odpadają.
     *
     * @param  array<int, array{section?: string, rows?: list<array{name?: string, value?: string}>}>  $sections  ProductShopCard::$fields
     * @param  list<string>  $names  B2bShopFieldNormSource::normShopFieldNames()
     * @return list<array{label: string, value: string|null}>
     */
    public static function facts(array $sections, array $names): array
    {
        $wanted = array_map(static fn (string $name): string => mb_strtolower(trim($name)), $names);
        $out = [];
        foreach ($sections as $section) {
            foreach ((array) ($section['rows'] ?? []) as $row) {
                $name = mb_strtolower(trim((string) ($row['name'] ?? '')));
                if (! in_array($name, $wanted, true)) {
                    continue;
                }
                foreach (self::items((string) ($row['value'] ?? '')) as $fact) {
                    if (! in_array($fact, $out, true)) {
                        $out[] = $fact;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Zapis par z tabelki producenta w products.manufacturer_norms. Pary innego łącznika B2B zostają (każdy łącznik
     * producenta czyta swoją witrynę); pary ze strony WWW producenta ustępują — tabelka sklepu B2B producenta to dane
     * wprost od niego, a nie ramka odczytana z HTML.
     *
     * @param  list<string>  $names
     * @return array{status: string, column: array<string, mixed>|null}
     */
    public function resolve(Product $product, B2bAccount $account, string $connectorKey, string $brand, array $names): array
    {
        if (! BrandKey::same($brand, (string) $product->manufacturer)) {
            return ['status' => self::NONE, 'column' => null];
        }
        $card = ProductShopCard::query()
            ->where('product_id', $product->id)
            ->where('b2b_account_id', $account->id)
            ->first();
        $facts = self::facts(is_array($card?->fields) ? $card->fields : [], $names);
        if ($facts === []) {
            return ['status' => self::NONE, 'column' => null];
        }
        $column = ManufacturerNormFacts::build($facts, $connectorKey, $brand, (string) ($card?->source_url ?? ''));
        if ($column === null) {
            return ['status' => self::NONE, 'column' => null];
        }
        $stored = $product->manufacturer_norms;
        $storedConnector = is_array($stored) ? ($stored['source']['connector'] ?? null) : null;
        if (ManufacturerNormFacts::rows($stored) !== []
            && ! in_array($storedConnector, [$connectorKey, ManufacturerNormFacts::WEB_PAGE_CONNECTOR], true)) {
            return ['status' => self::OTHER_SOURCE, 'column' => $column];
        }
        if (ManufacturerNormFacts::sameFacts($stored, $column)) {
            return ['status' => self::SAME, 'column' => $column];
        }

        return ['status' => self::SAVED, 'column' => $column];
    }

    /**
     * @param  list<string>  $names
     * @return string status jak w resolve()
     */
    public function store(Product $product, B2bAccount $account, string $connectorKey, string $brand, array $names): string
    {
        $result = $this->resolve($product, $account, $connectorKey, $brand, $names);
        if ($result['status'] === self::SAVED) {
            $product->manufacturer_norms = $result['column'];
            $product->save();
        }

        return $result['status'];
    }

    /**
     * @return list<array{label: string, value: string|null}>
     */
    private static function items(string $value): array
    {
        $out = [];
        foreach (preg_split(self::LIST_SPLIT, trim($value)) ?: [] as $item) {
            $item = trim((string) preg_replace(self::CE_TAIL, '', trim($item)));
            if (preg_match(self::DESIGNATION, $item, $m) !== 1) {
                continue;
            }
            $label = trim($m[0]);
            $rest = Utf8Trim::trim(mb_substr($item, mb_strlen($m[0])), " \t:;,–—-");
            $out[] = ['label' => $label, 'value' => $rest === '' ? null : $rest];
        }

        return $out;
    }
}
