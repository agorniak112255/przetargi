<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\B2bProductLink;
use App\Models\CardMatchCandidate;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductSourcePrice;
use App\Models\User;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;

/**
 * Pełna kopia zapasowa przed decyzją z ekranu „Łączenie kart”, po której karty znikają — łączenie rozmiarów (krok 6,
 * CardMatchSizeMerger) i rozdzielanie (krok 7, CardMatchSplitter): wszystkie karty decyzji z wierszami, które decyzja
 * przenosi albo kasuje (CardMatchMerger::BACKUP_TABLES), wpisy mapy połączeń tych kart i ich pozycji (nowa decyzja
 * nadpisuje wiersz pozycji), propozycje z tymi kartami, zamienniki, akcesoria, pozycje przetargów z kartą jako
 * produktem dodatkowym i cenniki z tymi kartami na liście. Plik w storage/app/repair-backups; usunięcie pliku, gdy
 * transakcja decyzji się wycofa, należy do wywołującego.
 */
final class CardMatchBackup
{
    /**
     * @param  string  $kind  rodzaj kopii w pliku (np. „card-match-split”)
     * @param  string  $filePrefix  początek nazwy pliku (np. „card-match-split”)
     * @param  string  $nothingDone  koniec komunikatu odmowy (np. „nic nie połączono”)
     * @param  list<array{role: string, product: Product}>  $cards  karty w kolejności kopii, z rolą w decyzji
     * @param  array<string, mixed>  $head  pola decyzji zapisane przed kartami (np. keep_product_id)
     * @return string ścieżka zapisanego pliku
     *
     * @throws JsonException
     */
    public function write(string $kind, string $filePrefix, string $nothingDone, CardMatchCandidate $candidate, User $user, array $cards, array $head): string
    {
        $ids = array_values(array_unique(array_map(static fn (array $card): int => (int) $card['product']->id, $cards)));

        $rowsOfCards = [];
        foreach ($cards as $card) {
            $product = $card['product'];
            $rows = [];
            foreach (CardMatchMerger::BACKUP_TABLES as $table => $column) {
                if (Schema::hasTable($table)) {
                    $rows[$table] = self::rows(DB::table($table)->where($column, $product->id));
                }
            }
            $rowsOfCards[] = [
                'role' => $card['role'],
                'product' => (array) DB::table('products')->where('id', $product->id)->first(),
                'rows' => $rows,
            ];
        }

        // pozycje kart (powiązania B2B i pozycje z pliku) — wpis mapy tej pozycji mógł wskazywać inną kartę
        $pairs = [];
        foreach (B2bProductLink::query()->toBase()->whereIn('product_id', $ids)->get(['b2b_account_id', 'remote_id']) as $link) {
            $pairs[] = [ProductSourcePrice::b2bKey((int) $link->b2b_account_id), (string) $link->remote_id];
        }
        foreach (ProductIdentifier::query()->toBase()->whereIn('product_id', $ids)->where('source_key', 'like', 'file:%')
            ->distinct()->get(['source_key', 'position_key']) as $row) {
            $pairs[] = [(string) $row->source_key, (string) $row->position_key];
        }
        $redirects = self::rows(DB::table('card_redirects')->where(static function (Builder $q) use ($ids, $pairs): void {
            $q->whereIn('product_id', $ids);
            foreach ($pairs as [$sourceKey, $positionKey]) {
                $q->orWhere(static fn (Builder $w) => $w->where('source_key', $sourceKey)->where('position_key', $positionKey));
            }
        }));

        $candidates = self::rows(DB::table('card_match_candidates')->where(static function (Builder $q) use ($ids): void {
            $q->whereIn('source_product_id', $ids)->orWhereIn('target_product_id', $ids);
            CardMatchCandidate::whereTargetsKeyContains($q, $ids);
        }));
        $substitutes = Schema::hasTable('product_substitutes')
            ? self::rows(DB::table('product_substitutes')->where(static fn (Builder $q) => $q->whereIn('main_product_id', $ids)->orWhereIn('substitute_product_id', $ids)))
            : [];
        $accessories = Schema::hasTable('product_accessories')
            ? self::rows(DB::table('product_accessories')->where(static fn (Builder $q) => $q->whereIn('product_id', $ids)->orWhereIn('related_product_id', $ids)))
            : [];
        $companions = self::rows(DB::table('tender_items')->whereIn('companion_product_id', $ids));
        $priceLists = [];
        foreach (PriceList::query()->whereNotNull('product_ids')->cursor() as $list) {
            $productIds = is_array($list->product_ids) ? array_map('intval', $list->product_ids) : [];
            if (array_intersect($productIds, $ids) !== []) {
                $priceLists[] = ['id' => (int) $list->id, 'product_ids' => $list->product_ids];
            }
        }

        $payload = [
            'kind' => $kind,
            'created_at' => now()->toIso8601String(),
            'user' => ['id' => (int) $user->id, 'name' => (string) $user->name],
            'candidate' => $candidate->getAttributes(),
            ...$head,
            'cards' => $rowsOfCards,
            'card_redirects' => $redirects,
            'card_match_candidates' => $candidates,
            'product_substitutes' => $substitutes,
            'product_accessories' => $accessories,
            'tender_items_companion' => $companions,
            'price_lists' => $priceLists,
        ];

        $dir = storage_path('app/repair-backups');
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new DomainException('Kopia zapasowa nie powstała: brak katalogu '.$dir.' — '.$nothingDone.'.');
        }
        $path = $dir.DIRECTORY_SEPARATOR.$filePrefix.'-'.$candidate->id.'-'.now()->format('Ymd-His').'.json';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (@file_put_contents($path, $json) === false) {
            throw new DomainException('Kopia zapasowa nie powstała: zapis '.$path.' się nie udał — '.$nothingDone.'.');
        }

        return $path;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(Builder $query): array
    {
        return $query->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
    }
}
