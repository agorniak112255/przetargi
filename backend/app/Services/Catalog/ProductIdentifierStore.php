<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductSourcePrice;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Support\BrandKey;
use App\Support\ProductIdentifierCode;
use Illuminate\Support\Str;

/**
 * Zapis identyfikatorów wyrobu ze źródeł cen do product_identifiers (decyzja użytkownika 23.09.2026). Niczego nie
 * nadpisuje ani nie kasuje: identyfikator, którego źródło już nie podaje, dostaje removed_at, a gdy wróci — znacznik
 * znika. Wiersze należą do pozycji źródła (remote_id powiązania), więc przepięcie pozycji na inną kartę przenosi je.
 */
final class ProductIdentifierStore
{
    private const VALUE_LIMIT = 64;

    /**
     * Identyfikatory pozycji karty z przebiegu konta B2B. Wołane w transakcji zapisu karty i powiązań.
     *
     * @param  string  $cardRemoteId  remoteId karty — pozycja identyfikatorów bez własnej pozycji
     * @param  list<string>  $positionIds  pozycje karty w tym przebiegu (remote_id powiązań)
     * @param  list<B2bRemoteIdentifier>|null  $identifiers  null = łącznik ich nie podaje: zapisane zostają, tylko idą za pozycją
     * @return list<string> ostrzeżenia do dziennika przebiegu
     */
    public function recordB2b(
        Product $product,
        B2bAccount $account,
        string $cardRemoteId,
        array $positionIds,
        ?array $identifiers,
        string $manufacturer,
        ?int $runId,
    ): array {
        $sourceKey = ProductSourcePrice::b2bKey((int) $account->id);
        $positionIds = array_values(array_unique(array_map('strval', $positionIds)));
        if ($positionIds === []) {
            return [];
        }

        // pozycja przepięta na inną kartę (np. rozmiar w nowej cenie) — jej identyfikatory idą za nią
        ProductIdentifier::query()
            ->where('source_key', $sourceKey)
            ->whereIn('position_key', $positionIds)
            ->where('product_id', '!=', $product->id)
            ->update(['product_id' => $product->id]);

        if ($identifiers === null) {
            return [];
        }

        $warnings = [];
        $wanted = $this->wantedRows($identifiers, $cardRemoteId, $positionIds, $manufacturer, $warnings);
        $now = now();

        $matched = [];
        $seenIds = [];
        $goneIds = [];
        $rows = ProductIdentifier::query()
            ->where('source_key', $sourceKey)
            ->whereIn('position_key', $positionIds)
            ->get();
        foreach ($rows as $row) {
            $key = self::rowKey((string) $row->position_key, (string) $row->type, (string) $row->value);
            if (! isset($wanted[$key]) || isset($matched[$key])) {
                if ($row->removed_at === null) {
                    $goneIds[] = (int) $row->id;
                }

                continue;
            }
            $matched[$key] = true;
            $seenIds[] = (int) $row->id;
            // opis wiersza za źródłem (etykieta rozmiaru, pole, producent, zapis wielkości liter)
            $row->fill(array_diff_key($wanted[$key], ['position_key' => true, 'type' => true]));
            if ($row->isDirty()) {
                $row->save();
            }
        }
        // widziane — bez ruszania updated_at: to odczyt, nie zmiana identyfikatora
        if ($seenIds !== []) {
            ProductIdentifier::query()->toBase()->whereIn('id', $seenIds)
                ->update(['last_seen_at' => $now, 'b2b_sync_run_id' => $runId, 'removed_at' => null]);
        }
        if ($goneIds !== []) {
            ProductIdentifier::query()->toBase()->whereIn('id', $goneIds)->update(['removed_at' => $now]);
        }

        $inserts = [];
        foreach ($wanted as $key => $row) {
            if (isset($matched[$key])) {
                continue;
            }
            $inserts[] = [
                ...$row,
                'product_id' => $product->id,
                'source_key' => $sourceKey,
                'b2b_account_id' => $account->id,
                'price_list_id' => null,
                'b2b_sync_run_id' => $runId,
                'price_list_import_id' => null,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'removed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($inserts !== []) {
            ProductIdentifier::query()->insert($inserts);
        }

        return $warnings;
    }

    /**
     * Wiersze do zapisu po kluczu pozycja + typ + wartość bez wielkości liter i akcentów (rowKey) — MySQL porównuje
     * w UNIQUE bez nich, SQLite w testach z nimi; deduplikacja tutaj daje obu bazom to samo.
     *
     * @param  list<B2bRemoteIdentifier>  $identifiers
     * @param  list<string>  $positionIds
     * @param  list<string>  $warnings
     * @return array<string, array<string, string|null>>
     */
    private function wantedRows(array $identifiers, string $cardRemoteId, array $positionIds, string $manufacturer, array &$warnings): array
    {
        $positions = array_flip($positionIds);
        $manufacturer = trim($manufacturer);
        $brandKey = BrandKey::of($manufacturer);
        $wanted = [];
        foreach ($identifiers as $identifier) {
            $value = trim(preg_replace('/\s+/u', ' ', $identifier->value) ?? '');
            if ($value === '') {
                continue;
            }
            $position = (string) ($identifier->remoteId ?? $cardRemoteId);
            if (! isset($positions[$position])) {
                $warnings[] = 'identyfikator '.$value.' wskazuje pozycję '.$position.' spoza karty — pominięty';

                continue;
            }
            if (! in_array($identifier->type, ProductIdentifier::TYPES, true)) {
                $warnings[] = 'identyfikator '.$value.': nieznany rodzaj „'.$identifier->type.'” — pominięty';

                continue;
            }
            // dłuższej wartości nie przycinamy — ucięty kod byłby innym kodem
            if (mb_strlen($value) > self::VALUE_LIMIT) {
                $warnings[] = 'identyfikator '.mb_substr($value, 0, 40).'… dłuższy niż '.self::VALUE_LIMIT.' znaki — pominięty';

                continue;
            }
            $wanted[self::rowKey($position, $identifier->type, $value)] ??= [
                'position_key' => $position,
                'type' => $identifier->type,
                'value' => $value,
                'normalized' => ProductIdentifierCode::normalize($identifier->type, $value),
                'source_field' => self::cut($identifier->field, 100),
                'variant_label' => self::cut($identifier->label, 120),
                'manufacturer' => self::cut($manufacturer, 100),
                'brand_key' => self::cut($brandKey, 100),
            ];
        }

        return $wanted;
    }

    /**
     * Klucz jak porównanie w UNIQUE na produkcji (utf8mb4_unicode_ci: bez wielkości liter i akcentów, „Żółty” =
     * „zolty”) — dwie takie wartości w jednym zapisie złamałyby UNIQUE i wycofały zapis całej karty.
     */
    private static function rowKey(string $position, string $type, string $value): string
    {
        return mb_strtolower(Str::ascii($position))."\n".$type."\n".mb_strtolower(Str::ascii($value));
    }

    private static function cut(?string $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
