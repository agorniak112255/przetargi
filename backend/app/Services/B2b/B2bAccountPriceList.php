<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Jeden stały wpis w Cennikach na konto B2B (b2b_accounts.last_price_list_id). Każde pobranie aktualizuje ten
 * sam wiersz: produkty konta, liczniki i szczegóły ostatniego przebiegu. Historia cen zostaje na kartach
 * (product_price_history, źródło „b2b:{łącznik}”), dziennik przebiegów w b2b_sync_runs.
 */
final class B2bAccountPriceList
{
    public const PRICE_CHANGES_LIMIT = 100;

    public const UPDATED_PRODUCTS_LIMIT = 100;

    public const SKIPPED_DETAILS_LIMIT = 100;

    public const ERRORS_LIMIT = 50;

    public function __construct(private readonly B2bConnectorRegistry $connectors) {}

    public static function filename(string $host): string
    {
        return $host.' (API)';
    }

    /**
     * Wpis konta na początku przebiegu: zapisany na koncie; inaczej przejęty najnowszy wpis „{host} (API)”, którego
     * nie ma inne konto (dawny wpis jednego przebiegu); inaczej nowy. Nazwę i plik ustawia łącznik.
     *
     * @return array{0: PriceList, 1: bool} wpis i czy został założony w tym przebiegu
     */
    public function resolve(B2bAccount $account, B2bConnector $connector): array
    {
        $manufacturer = mb_substr($connector::label(), 0, 100);
        $list = $this->current($account);
        $created = false;
        // Jeden wpis na producenta, niezależnie od źródła: przebieg B2B dopisuje się do tego samego
        // cennika, do którego trafiają importy z pliku. Wpis „{host} (API)” z czasów, gdy konto miało
        // własny wiersz, jest przejmowany, a nie zakładany od nowa.
        $list ??= PriceList::query()
            ->where('manufacturer_key', PriceList::manufacturerKey($manufacturer))
            ->first();
        $list ??= $this->adoptionCandidate($connector::host(), $this->referencedIds($account->id));
        if ($list === null) {
            $list = new PriceList(['version' => $this->version()]);
            $created = true;
        }

        $list->fill([
            'original_filename' => self::filename($connector::host()),
            'imported_by' => $account->updated_by ?? $account->created_by,
        ]);
        // Nazwy istniejącego wpisu przebieg nie nadpisuje: człowiek mógł ją poprawić w panelu, a wpis
        // i tak odnajduje się po wskaźniku konta. Uzupełniamy tylko to, czego brakuje.
        if (trim((string) $list->manufacturer) === '') {
            $list->manufacturer = $manufacturer;
        }
        if (trim((string) $list->manufacturer_key) === '') {
            $list->manufacturer_key = PriceList::manufacturerKey((string) $list->manufacturer);
        }
        $list->save();

        if ((int) $account->last_price_list_id !== (int) $list->id) {
            $account->forceFill(['last_price_list_id' => $list->id])->save();
        }

        return [$list, $created];
    }

    /**
     * Najnowszy wpis „{host} (API)” spoza podanych id (wpisów innych kont).
     *
     * @param  list<int>  $excludeIds
     */
    public function adoptionCandidate(string $host, array $excludeIds): ?PriceList
    {
        return PriceList::query()
            ->where('original_filename', self::filename($host))
            ->when($excludeIds !== [], static fn ($query) => $query->whereNotIn('id', $excludeIds))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Koniec przebiegu (ok, częściowy, zatrzymany, nieudany po zapisach): ten sam wiersz dostaje datę sprawdzenia,
     * wszystkie produkty konta i wynik ostatniego przebiegu.
     */
    public function record(PriceList $list, B2bAccount $account, B2bSyncProgress $progress): void
    {
        $run = $progress->run();
        $productIds = $this->productIds($account);

        $list->forceFill([
            'version' => $this->version(),
            'product_ids' => $productIds,
            'rows_total' => count($productIds),
            'products_created' => (int) ($run->created ?? 0),
            'products_updated' => (int) ($run->updated ?? 0),
            'prices_changed' => (int) ($run->prices_changed ?? 0),
            'rows_skipped' => (int) ($run->skipped ?? 0),
            'price_changes' => array_slice($progress->priceChanges(), 0, self::PRICE_CHANGES_LIMIT),
            'updated_products' => array_slice($progress->updatedProducts(), 0, self::UPDATED_PRODUCTS_LIMIT),
            'skipped_details' => array_slice($progress->skippedDetails(), 0, self::SKIPPED_DETAILS_LIMIT),
            'errors' => array_slice($progress->errors(), 0, self::ERRORS_LIMIT),
            'updated_at' => now(),
        ])->save();

        // Raport przebiegu obok raportów importów z pliku — w Cennikach widać jedną historię producenta,
        // bez względu na to, czym przyszła kolejna porcja danych.
        PriceListImport::query()->create([
            'price_list_id' => $list->id,
            'source' => PriceListImport::SOURCE_B2B,
            'version' => $this->version(),
            'original_filename' => $list->original_filename,
            'imported_by' => $account->updated_by ?? $account->created_by,
            'rows_total' => count($productIds),
            'products_created' => (int) ($run->created ?? 0),
            'products_updated' => (int) ($run->updated ?? 0),
            'prices_changed' => (int) ($run->prices_changed ?? 0),
            'rows_skipped' => (int) ($run->skipped ?? 0),
            'errors' => array_slice($progress->errors(), 0, self::ERRORS_LIMIT),
            'price_changes' => array_slice($progress->priceChanges(), 0, self::PRICE_CHANGES_LIMIT),
            'updated_products' => array_slice($progress->updatedProducts(), 0, self::UPDATED_PRODUCTS_LIMIT),
            'skipped_details' => array_slice($progress->skippedDetails(), 0, self::SKIPPED_DETAILS_LIMIT),
            'product_ids' => $productIds,
        ]);
    }

    /**
     * Nieudany przebieg bez zapisów (np. złe hasło) nie zostawia pustego wpisu założonego przed chwilą.
     */
    public function discardNew(PriceList $list, B2bAccount $account): void
    {
        DB::transaction(static function () use ($list, $account): void {
            if ((int) $account->last_price_list_id === (int) $list->id) {
                $account->forceFill(['last_price_list_id' => null])->save();
            }
            // wiersz bez produktów — bezpośrednio, bez usuwania produktów przez PriceListDeletionService
            PriceList::query()->whereKey($list->id)->delete();
        });
    }

    /**
     * Wszystkie karty konta: powiązania z B2B oraz karty wersji tego konta.
     *
     * @return list<int>
     */
    public function productIds(B2bAccount $account): array
    {
        $ids = B2bProductLink::query()
            ->where('b2b_account_id', $account->id)
            ->pluck('product_id')
            ->merge(ProductVariant::query()->where('b2b_account_id', $account->id)->distinct()->pluck('product_id'))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $ids;
    }

    /**
     * Konto B2B, do którego należy wpis — dopóki konto istnieje, wpisu nie można usunąć ani edytować w Cennikach.
     * Wpis zapisany na koncie; dla konta bez wpisu (przed pierwszym pobraniem po zmianie) także wpis
     * „{host} (API)” jego łącznika. Jedno zapytanie o konta niezależnie od liczby wpisów.
     *
     * @param  iterable<PriceList>  $lists
     * @return array<int, B2bAccount> price_list_id => konto
     */
    public function owners(iterable $lists): array
    {
        $accounts = B2bAccount::query()
            ->orderBy('id')
            ->get(['id', 'username', 'connector', 'sites', 'last_price_list_id']);
        if ($accounts->isEmpty()) {
            return [];
        }

        $keyByFilename = [];
        foreach ($this->connectors->options() as $option) {
            $keyByFilename[self::filename($option['host'])] = $option['key'];
        }

        $owners = [];
        foreach ($lists as $list) {
            $owner = $accounts->first(static fn (B2bAccount $a): bool => (int) $a->last_price_list_id === (int) $list->id);
            $key = $keyByFilename[(string) $list->original_filename] ?? null;
            if ($owner === null && $key !== null) {
                $owner = $accounts->first(fn (B2bAccount $a): bool => $a->last_price_list_id === null && $this->connectorKey($a) === $key);
            }
            if ($owner !== null) {
                $owners[(int) $list->id] = $owner;
            }
        }

        return $owners;
    }

    /**
     * @return array{id: int, username: string, connector_label: string|null}
     */
    public function ownerPayload(B2bAccount $account): array
    {
        return [
            'id' => (int) $account->id,
            'username' => (string) $account->username,
            'connector_label' => $this->connectors->label($this->connectorKey($account)),
        ];
    }

    public function connectorKey(B2bAccount $account): ?string
    {
        $key = trim((string) $account->connector);
        if ($key !== '') {
            return $key;
        }

        return $this->connectors->keyForSites($account->sites ?? []);
    }

    /**
     * @return list<int>
     */
    private function referencedIds(int $exceptAccountId): array
    {
        return B2bAccount::query()
            ->whereKeyNot($exceptAccountId)
            ->whereNotNull('last_price_list_id')
            ->pluck('last_price_list_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function current(B2bAccount $account): ?PriceList
    {
        return $account->last_price_list_id !== null ? PriceList::query()->find($account->last_price_list_id) : null;
    }

    private function version(): string
    {
        return 'B2B · aktualizacja '.now()->setTimezone(B2bAccount::SYNC_TIMEZONE)->format('Y-m-d H:i');
    }
}
