<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardMatchCandidate;
use App\Models\CardRedirect;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Pricing\SourcePriceComparison;
use DomainException;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Zapis mapy połączeń (card_redirects): decyzja człowieka „kody karty źródła trafiają na kartę docelową” i przepinanie
 * wierszy przy scaleniach kart. Czytanie mapy przez synchronizację B2B i import pliku to kolejne kroki planu.
 */
final class CardRedirectStore
{
    public function __construct(
        private readonly CardOwnership $ownership = new CardOwnership,
    ) {}

    /**
     * Zapis decyzji „kody karty $source trafiają na $target”: po jednym wierszu na każde powiązanie B2B karty źródła
     * (wszystkie konta; pozycja = remote_id, kod dostawcy = remote_sku, etykieta = variant_label identyfikatora tej
     * pozycji, jeśli jest) i na każdą pozycję identyfikatorów plikowych karty źródła (pozycja = position_key).
     * Wołać PRZED scaleniem, dopóki powiązania i identyfikatory są na karcie źródła. Wiersz o tej samej parze
     * (source_key, position_key) nadpisuje nowa decyzja — z autorem i czasem tej decyzji.
     *
     * @return int liczba zapisanych wierszy
     */
    public function recordMerge(Product $source, Product $target, string $reason, ?CardMatchCandidate $candidate, ?User $user): int
    {
        if (! in_array($reason, CardRedirect::REASONS, true)) {
            throw new InvalidArgumentException('Nieznany powód wpisu mapy połączeń: „'.$reason.'”.');
        }

        $positions = $this->positions($source);

        $snapshot = self::snapshot($target);
        $now = now();
        foreach ($positions as $position) {
            $row = CardRedirect::query()->firstOrNew([
                'source_key' => $position['source_key'],
                'position_key' => $position['position_key'],
            ]);
            // nowa decyzja zastępuje starą w całości — także autora, czas i pozycję wiodącą
            $row->forceFill([
                ...$position,
                'product_id' => (int) $target->id,
                'reason' => $reason,
                'is_anchor' => false,
                'target_snapshot' => $snapshot,
                'card_match_candidate_id' => $candidate?->id,
                'created_by' => $user?->id,
                'created_at' => $now,
            ])->save();
        }

        return count($positions);
    }

    /**
     * Łączenie rozmiarów z decyzji człowieka (krok 6): pozycje właścicieli wszystkich kart — $keep i $drops — trafiają
     * na $keep z powodem size_merge. Właściciele każdej karty wg CardOwnership: powiązania kont B2B producenta
     * (b2b:{id}) i pozycje identyfikatorów cennika slotu „file” (file:{cennik}). Pozycje nie-właścicieli (np. P4S
     * dołączony wcześniej do karty rozmiaru) zostają — ich wiersze przepina scalenie (repoint).
     *
     * Pozycja wiodąca (is_anchor): dla każdego konta-właściciela którejkolwiek karty $keep musi mieć dokładnie jedno
     * powiązanie tego konta — z niego synchronizacja odświeża cenę, opis i zdjęcia karty modelu (3M: 6100 S #40819
     * zostaje, pozycja 7000146845 wiodąca, 6200 M i 6300 L tylko powiązania). Cennik z pliku nie ma pozycji wiodącej.
     * Wszystko sprawdzone przed zapisem — przy odmowie mapa bez zmian. Wołać PRZED scaleniem kart.
     *
     * @param  list<Product>  $drops
     * @return array{rows: int, anchors: list<array{source_key: string, position_key: string}>}
     *
     * @throws DomainException karta, która zostaje, nie ma pozycji konta-właściciela albo ma ich kilka
     */
    public function recordSizeMerge(Product $keep, array $drops, string $approvedName, CardMatchCandidate $candidate, User $user): array
    {
        $cards = [$keep];
        foreach ($drops as $drop) {
            if ((int) $drop->id !== (int) $keep->id) {
                $cards[] = $drop;
            }
        }

        $positions = [];
        $ownerAccounts = [];
        foreach ($cards as $card) {
            $accountIds = [];
            $fileKeys = [];
            foreach ($this->ownership->ownerSourceKeys($card) as $key) {
                if (str_starts_with($key, 'b2b:')) {
                    $accountIds[] = (int) substr($key, 4);
                    $ownerAccounts[(int) substr($key, 4)] = true;
                } elseif ($key === ProductSourcePrice::SOURCE_FILE) {
                    $listId = ProductSourcePrice::query()
                        ->where('product_id', $card->id)
                        ->where('source_key', ProductSourcePrice::SOURCE_FILE)
                        ->value('price_list_id');
                    if ($listId !== null) {
                        $fileKeys[] = ProductIdentifierStore::fileKey((int) $listId);
                    }
                }
            }
            if ($accountIds !== [] || $fileKeys !== []) {
                $positions += $this->positions($card, $accountIds, $fileKeys);
            }
        }

        ksort($ownerAccounts);
        $anchors = [];
        $keepLinks = B2bProductLink::query()->where('product_id', $keep->id)->orderBy('id')->get(['b2b_account_id', 'remote_id']);
        foreach (array_keys($ownerAccounts) as $accountId) {
            $own = $keepLinks->filter(static fn (B2bProductLink $l): bool => (int) $l->b2b_account_id === $accountId)->values();
            if ($own->count() !== 1) {
                $label = app(SourcePriceComparison::class)->accountLabel(B2bAccount::query()->find($accountId));

                throw new DomainException($own->isEmpty()
                    ? 'Karta '.$keep->sku.', która zostaje, nie ma pozycji konta '.$label.' — wybierz kartę z pozycją tego konta.'
                    : 'Karta '.$keep->sku.', która zostaje, ma kilka pozycji konta '.$label.' — nie wiadomo, która jest wiodąca.');
            }
            $sourceKey = ProductSourcePrice::b2bKey($accountId);
            $position = (string) $own->first()->remote_id;
            $anchors[self::key($sourceKey, $position)] = ['source_key' => $sourceKey, 'position_key' => $position];
            // konto jest właścicielem innej karty, a karty, która zostaje, nie (inna marka) — pozycja wiodąca i tak w mapie
            $positions += $this->positions($keep, [$accountId], []);
        }

        $snapshot = [...self::snapshot($keep), 'name' => mb_substr(trim($approvedName), 0, 1000)];
        $now = now();
        foreach ($positions as $key => $position) {
            $row = CardRedirect::query()->firstOrNew([
                'source_key' => $position['source_key'],
                'position_key' => $position['position_key'],
            ]);
            // nowa decyzja zastępuje starą w całości (także wiersz „merge” tej pozycji sprzed łączenia rozmiarów)
            $row->forceFill([
                ...$position,
                'product_id' => (int) $keep->id,
                'reason' => CardRedirect::REASON_SIZE_MERGE,
                'is_anchor' => isset($anchors[$key]),
                'target_snapshot' => $snapshot,
                'card_match_candidate_id' => $candidate->id,
                'created_by' => $user->id,
                'created_at' => $now,
            ])->save();
        }

        return ['rows' => count($positions), 'anchors' => array_values($anchors)];
    }

    /**
     * Scalenie kart: wiersze wskazujące karty $fromIds przechodzą na $toId. target_snapshot bez zmian — to ślad decyzji.
     *
     * @param  list<int>  $fromIds
     * @return int liczba przepiętych wierszy
     */
    public function repoint(array $fromIds, int $toId): int
    {
        $fromIds = array_values(array_diff(array_map('intval', $fromIds), [$toId]));
        if ($fromIds === []) {
            return 0;
        }

        return CardRedirect::query()->whereIn('product_id', $fromIds)->update(['product_id' => $toId]);
    }

    /**
     * Karta docelowa w chwili decyzji.
     *
     * @return array{id: int, sku: string, name: string, manufacturer: string}
     */
    public static function snapshot(Product $product): array
    {
        return [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'name' => (string) $product->name,
            'manufacturer' => (string) $product->manufacturer,
        ];
    }

    /**
     * Klucz pary źródło + pozycja jak porównanie w UNIQUE na produkcji (utf8mb4_unicode_ci: bez wielkości liter
     * i akcentów) — dwie takie pozycje w jednym zapisie trafiłyby w ten sam wiersz.
     */
    public static function key(string $sourceKey, string $position): string
    {
        return $sourceKey."\n".mb_strtolower(Str::ascii($position));
    }

    /**
     * Pozycje karty do mapy połączeń: powiązania B2B (pozycja = remote_id, kod dostawcy = remote_sku, etykieta =
     * variant_label identyfikatora tej pozycji) i pozycje identyfikatorów z pliku. null = wszystkie konta / cenniki.
     *
     * @param  list<int>|null  $accountIds
     * @param  list<string>|null  $fileSourceKeys  „file:{cennik}”
     * @return array<string, array{source_key: string, position_key: string, b2b_account_id: int|null, price_list_id: int|null, remote_sku: string|null, position_label: string|null}>
     */
    private function positions(Product $source, ?array $accountIds = null, ?array $fileSourceKeys = null): array
    {
        /** @var array<string, array{source_key: string, position_key: string, b2b_account_id: int|null, price_list_id: int|null, remote_sku: string|null, position_label: string|null}> $positions */
        $positions = [];
        $links = B2bProductLink::query()
            ->where('product_id', $source->id)
            ->when($accountIds !== null, static fn ($q) => $q->whereIn('b2b_account_id', $accountIds === [] ? [0] : $accountIds))
            ->orderBy('id')
            ->get();
        foreach ($links as $link) {
            $sourceKey = ProductSourcePrice::b2bKey((int) $link->b2b_account_id);
            $position = (string) $link->remote_id;
            $positions[self::key($sourceKey, $position)] ??= [
                'source_key' => $sourceKey,
                'position_key' => $position,
                'b2b_account_id' => (int) $link->b2b_account_id,
                'price_list_id' => null,
                'remote_sku' => self::cut($link->remote_sku, 255),
                'position_label' => null,
            ];
        }
        // etykieta rozmiaru/koloru pozycji B2B z jej identyfikatorów (idą za pozycją, więc bez warunku karty)
        if ($links->isNotEmpty()) {
            $labels = ProductIdentifier::query()
                ->whereIn('source_key', $links->map(static fn (B2bProductLink $l): string => ProductSourcePrice::b2bKey((int) $l->b2b_account_id))->unique()->values()->all())
                ->whereIn('position_key', $links->pluck('remote_id')->map(static fn ($id): string => (string) $id)->unique()->values()->all())
                ->whereNotNull('variant_label')
                ->orderBy('id')
                ->get(['source_key', 'position_key', 'variant_label']);
            foreach ($labels as $row) {
                $key = self::key((string) $row->source_key, (string) $row->position_key);
                if (isset($positions[$key]) && $positions[$key]['position_label'] === null) {
                    $positions[$key]['position_label'] = self::cut($row->variant_label, 120);
                }
            }
        }

        $fileRows = ProductIdentifier::query()
            ->where('product_id', $source->id)
            ->where('source_key', 'like', 'file:%')
            ->when($fileSourceKeys !== null, static fn ($q) => $q->whereIn('source_key', $fileSourceKeys === [] ? [''] : $fileSourceKeys))
            ->orderBy('id')
            ->get(['source_key', 'position_key', 'price_list_id', 'variant_label']);
        foreach ($fileRows as $row) {
            $key = self::key((string) $row->source_key, (string) $row->position_key);
            if (! isset($positions[$key])) {
                $positions[$key] = [
                    'source_key' => (string) $row->source_key,
                    'position_key' => (string) $row->position_key,
                    'b2b_account_id' => null,
                    'price_list_id' => $row->price_list_id !== null ? (int) $row->price_list_id : null,
                    'remote_sku' => null,
                    'position_label' => null,
                ];
            }
            if ($positions[$key]['position_label'] === null) {
                $positions[$key]['position_label'] = self::cut($row->variant_label, 120);
            }
        }

        return $positions;
    }

    private static function cut(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
