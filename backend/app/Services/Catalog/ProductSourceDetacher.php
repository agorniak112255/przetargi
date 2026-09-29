<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\B2bAccount;
use App\Models\B2bDescriptionSupplementAttempt;
use App\Models\B2bProductLink;
use App\Models\CardMatchCandidate;
use App\Models\CardRedirect;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\B2b\B2bCatalogSync;
use App\Services\Pricing\ProductEffectivePrice;
use App\Services\Pricing\SourcePriceComparison;
use DomainException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

/**
 * Usunięcie karty z listy kart konta dostawcy (decyzja użytkownika 29.09.2026): karta połączona z innym źródłem
 * (np. 3M B2B + P4S) traci tylko pozycje tego konta, a karta i pozostałe źródła zostają. Cel: pozbyć się
 * z cenników dystrybutorów producentów, od których mamy własne cenniki.
 *
 * Kasowane z karty dla konta: powiązania, identyfikatory pozycji, wpisy mapy połączeń, slot ceny, tabelka sklepu,
 * wiersze wersji/rozmiarów konta, próby uzupełnienia opisu, nieaktualne propozycje łączenia, adres sklepu karty
 * prowadzący do sklepu konta i wpis karty w stałym cenniku konta. Zostają: historia cen, zdjęcia i dokumenty (ten
 * sam wyrób — pojedyncze zdjęcie usuwa się „×” na karcie) oraz nazwa, opis i SKU karty. Odpinane wiersze trafiają
 * do kopii w storage/app/repair-backups.
 *
 * Każda karta w osobnym punkcie zapisu (savepoint): odmowa jednej karty (konto jest jej jedynym producentem, zmiana
 * ceny karty w przetargu) cofa tylko ją. Wołane w transakcji ProductDeletionService po B2bAccountSyncRunner::lockIdle.
 */
final class ProductSourceDetacher
{
    public function __construct(
        private readonly ProductEffectivePrice $effectivePrices,
        private readonly CardOwnership $ownership,
        private readonly ProductImportExclusions $exclusions,
        private readonly SourcePriceComparison $labels,
    ) {}

    /**
     * Czy karta ma poza kontem inne żywe źródło: powiązanie innego konta albo cennik z pliku (slot „file” lub
     * aktywny identyfikator wiersza pliku). Slot konta bez powiązania (sierota) się nie liczy. Karta tylko z tego
     * konta jest usuwana w całości.
     */
    public function hasOtherSource(int $productId, int $accountId): bool
    {
        if (B2bProductLink::query()->where('product_id', $productId)->where('b2b_account_id', '!=', $accountId)->exists()) {
            return true;
        }
        if (ProductSourcePrice::query()->where('product_id', $productId)->where('source_key', ProductSourcePrice::SOURCE_FILE)->exists()) {
            return true;
        }

        return ProductIdentifier::query()
            ->where('product_id', $productId)
            ->where('source_key', 'like', 'file:%')
            ->whereNull('removed_at')
            ->exists();
    }

    /**
     * @param  list<int>  $productIds  karty z powiązaniem konta i innym źródłem
     * @return array{
     *     detached: list<int>,
     *     refused: list<array{id: int, sku: string, reason: string}>,
     *     positions_excluded: int,
     *     backup_path: string|null
     * }
     *
     * @throws JsonException
     */
    public function detach(array $productIds, int $accountId, User $actor, bool $skipOnImport): array
    {
        $result = ['detached' => [], 'refused' => [], 'positions_excluded' => 0, 'backup_path' => null];
        if ($productIds === []) {
            return $result;
        }
        $account = B2bAccount::query()->find($accountId);
        $label = $this->labels->accountLabel($account);
        $sourceKey = ProductSourcePrice::b2bKey($accountId);
        $backup = [];

        $cards = Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
        foreach ($cards as $card) {
            try {
                $row = DB::transaction(function () use ($card, $accountId, $account, $label, $sourceKey, $actor, $skipOnImport): array {
                    return $this->detachOne($card, $accountId, $account, $label, $sourceKey, $actor, $skipOnImport);
                });
            } catch (DomainException $e) {
                $result['refused'][] = ['id' => (int) $card->id, 'sku' => (string) $card->sku, 'reason' => $e->getMessage()];

                continue;
            }
            $result['detached'][] = (int) $card->id;
            $result['positions_excluded'] += $row['positions_excluded'];
            $backup[] = $row['backup'];
        }
        if ($result['detached'] === []) {
            return $result;
        }

        $this->removeFromAccountPriceList($result['detached'], $account);
        $result['backup_path'] = $this->writeBackup($accountId, $actor, $backup);

        // tabelka sklepu konta zniknęła z karty — wektor od nowa; błąd kolejki nie cofa odpięcia
        $detached = $result['detached'];
        DB::afterCommit(static function () use ($detached): void {
            foreach ($detached as $id) {
                try {
                    ReindexProductEmbeddingJob::dispatch($id, true);
                } catch (Throwable) {
                    // kolejka embeddingów nie blokuje odpięcia
                }
            }
        });

        return $result;
    }

    /**
     * @return array{positions_excluded: int, backup: array<string, mixed>}
     *
     * @throws DomainException odmowa dla tej karty (punkt zapisu wycofany)
     */
    private function detachOne(Product $card, int $accountId, ?B2bAccount $account, string $label, string $sourceKey, User $actor, bool $skipOnImport): array
    {
        $id = (int) $card->id;
        $owners = $this->ownership->ownerSourceKeys($card);
        if (in_array($sourceKey, $owners, true) && array_values(array_diff($owners, [$sourceKey])) === []) {
            throw new DomainException($label.' jest jedynym producentem karty — po odpięciu dystrybutor nadpisałby nazwę i producenta.');
        }

        $backup = [
            'product' => $card->only(['id', 'sku', 'name', 'manufacturer', 'purchase_price', 'catalog_price_net', 'currency', 'shop_source_url']),
            'b2b_product_links' => self::rows(B2bProductLink::query()->where('product_id', $id)->where('b2b_account_id', $accountId)),
            'product_identifiers' => self::rows(ProductIdentifier::query()->where('product_id', $id)->where('source_key', $sourceKey)),
            'card_redirects' => self::rows(CardRedirect::query()->where('product_id', $id)->where('source_key', $sourceKey)),
            'product_source_prices' => self::rows(ProductSourcePrice::query()->where('product_id', $id)->where('source_key', $sourceKey)),
            'product_shop_cards' => self::rows(ProductShopCard::query()->where('product_id', $id)->where('b2b_account_id', $accountId)),
            'product_variants' => self::rows(ProductVariant::query()->where('product_id', $id)->where('b2b_account_id', $accountId)),
            'b2b_description_supplement_attempts' => self::rows(B2bDescriptionSupplementAttempt::query()->where('product_id', $id)->where('b2b_account_id', $accountId)),
        ];

        // blokady przed kasowaniem — ProductImportExclusions czyta powiązania i mapę pozycji karty
        $excluded = $skipOnImport ? $this->exclusions->record([$id], $actor, $sourceKey, cardKept: true) : 0;

        B2bProductLink::query()->where('product_id', $id)->where('b2b_account_id', $accountId)->delete();
        ProductIdentifier::query()->where('product_id', $id)->where('source_key', $sourceKey)->delete();
        CardRedirect::query()->where('product_id', $id)->where('source_key', $sourceKey)->delete();
        ProductSourcePrice::query()->where('product_id', $id)->where('source_key', $sourceKey)->delete();
        ProductShopCard::query()->where('product_id', $id)->where('b2b_account_id', $accountId)->delete();
        // historia cen wersji kasowana kaskadą; pozycja przetargu zachowuje kopię etykiety i kodu wersji
        ProductVariant::query()->where('product_id', $id)->where('b2b_account_id', $accountId)->delete();
        B2bDescriptionSupplementAttempt::query()->where('product_id', $id)->where('b2b_account_id', $accountId)->delete();
        // propozycje łączenia liczone z pozycjami konta — nieaktualne; odświeżenie propozycji policzy je od nowa
        $backup['card_match_candidates'] = self::rows(self::staleCandidates($id, $sourceKey));
        self::staleCandidates($id, $sourceKey)->delete();

        if ($this->isAccountShopUrl((string) $card->shop_source_url, $account, $backup['product_shop_cards'])) {
            $card->forceFill(['shop_source_url' => null])->save();
        }

        $changes = $this->effectivePrices->refresh($card);
        if ($changes !== []) {
            $this->refuseTenderPriceChange($card, $changes, $label);
        }
        B2bCatalogSync::refreshShopFieldsSummary($card->refresh());
        $backup['price_changes'] = $changes;

        return ['positions_excluded' => $excluded, 'backup' => $backup];
    }

    /**
     * Pending i konflikty, w których karta jest kartą dystrybutora, albo kartą producenta dopasowaną kluczem konta.
     */
    private static function staleCandidates(int $productId, string $sourceKey): EloquentBuilder
    {
        return CardMatchCandidate::query()
            ->whereIn('status', [CardMatchCandidate::STATUS_PENDING, CardMatchCandidate::STATUS_CONFLICT])
            ->where(static function ($q) use ($productId, $sourceKey): void {
                $q->where('source_product_id', $productId)
                    ->orWhere(static fn ($inner) => $inner->where('target_product_id', $productId)->where('matched_source_key', $sourceKey));
            });
    }

    /**
     * Adres sklepu karty z konta (wpisany przez przebieg, gdy karta go nie miała): adres tabelki sklepu konta albo
     * host jednej ze stron konta.
     *
     * @param  list<array<string, mixed>>  $shopCards
     */
    private function isAccountShopUrl(string $url, ?B2bAccount $account, array $shopCards): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        $normalized = Product::normalizeShopUrl($url);
        foreach ($shopCards as $shopCard) {
            $source = trim((string) ($shopCard['source_url'] ?? ''));
            if ($source !== '' && Product::normalizeShopUrl($source) === $normalized) {
                return true;
            }
        }
        $host = self::host($url);
        if ($host === '' || $account === null) {
            return false;
        }
        foreach ((array) ($account->sites ?? []) as $site) {
            $siteHost = self::host((string) $site);
            if ($siteHost !== '' && ($host === $siteHost || str_ends_with($host, '.'.$siteHost))) {
                return true;
            }
        }

        return false;
    }

    private static function host(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $host = parse_url(str_contains($value, '://') ? $value : 'https://'.$value, PHP_URL_HOST);
        $host = mb_strtolower(trim((string) $host));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * Przetarg liczy z ceny karty — gdy slot konta był ceną karty w przetargu, odpięcie zmieniłoby ofertę: odmowa
     * (jak CardMatchSplitter::refuseTenderPriceChange).
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    private function refuseTenderPriceChange(Product $card, array $changes, string $label): void
    {
        $tenders = DB::table('tender_items')
            ->where(static fn (Builder $q) => $q->where('main_product_id', $card->id)->orWhere('companion_product_id', $card->id))
            ->count();
        if ($tenders === 0) {
            return;
        }
        $before = $changes['purchase_price'][0] ?? null;
        $after = $changes['purchase_price'][1] ?? null;
        $prices = $before !== null || $after !== null
            ? ' ('.self::money($before).' → '.self::money($after).')'
            : '';

        throw new DomainException('jest w pozycjach przetargów ('.$tenders.'), a odpięcie '.$label.' zmieniłoby jej cenę'.$prices.'.');
    }

    private static function money(mixed $value): string
    {
        return $value === null ? 'brak' : number_format((float) $value, 2, ',', ' ').' zł';
    }

    /**
     * Stały wpis konta w Cennikach (b2b_accounts.last_price_list_id) bez odpiętych kart; inne cenniki bez zmian.
     *
     * @param  list<int>  $productIds
     */
    private function removeFromAccountPriceList(array $productIds, ?B2bAccount $account): void
    {
        $list = $account?->last_price_list_id !== null ? PriceList::query()->find((int) $account->last_price_list_id) : null;
        if ($list === null || ! is_array($list->product_ids)) {
            return;
        }
        $drop = array_fill_keys($productIds, true);
        $current = array_map('intval', $list->product_ids);
        $next = array_values(array_filter($current, static fn (int $id): bool => ! isset($drop[$id])));
        if (count($next) !== count($current)) {
            $list->update(['product_ids' => $next]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(EloquentBuilder $query): array
    {
        return $query->orderBy('id')->get()->map(static fn ($row): array => $row->getAttributes())->all();
    }

    /**
     * Kopia odpiętych wierszy — usuwana przez wywołującego, gdy transakcja się wycofa.
     *
     * @param  list<array<string, mixed>>  $cards
     *
     * @throws JsonException
     */
    private function writeBackup(int $accountId, User $actor, array $cards): string
    {
        $dir = storage_path('app/repair-backups');
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new DomainException('Kopia zapasowa nie powstała: brak katalogu '.$dir.' — nic nie odpięto.');
        }
        $path = $dir.DIRECTORY_SEPARATOR.'source-detach-b2b'.$accountId.'-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
        $json = json_encode([
            'kind' => 'source-detach',
            'created_at' => now()->toIso8601String(),
            'user' => ['id' => (int) $actor->id, 'name' => (string) $actor->name],
            'b2b_account_id' => $accountId,
            'cards' => $cards,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (@file_put_contents($path, $json) === false) {
            throw new DomainException('Kopia zapasowa nie powstała: zapis '.$path.' się nie udał — nic nie odpięto.');
        }

        return $path;
    }
}
