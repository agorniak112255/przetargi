<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardMatchCandidate;
use App\Models\CardRedirect;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductSourcePrice;
use App\Services\Catalog\CardOwnership;
use App\Services\Catalog\CardRedirectStore;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Uzupełnienie wstecz mapy połączeń (card_redirects) z połączeń zrobionych, zanim mapa istniała. Dwa źródła:
 *
 * 1. Propozycje „Łączenie kart” ze statusem merged i kopią zapasową (CardMatchMerger::writeBackup): powiązania B2B
 *    i identyfikatory plikowe karty dystrybutora z kopii → wiersze „merge” z numerem propozycji, autorem i czasem
 *    decyzji. Karta docelowa = target_product_id (usunięta → „decyzja bez karty”), target_snapshot z kopii.
 * 2. Ręczne łączenia z konsoli (products:merge-duplicate, np. 11 par ANRO↔P4S 23.09.2026): karta z listą
 *    merged_duplicate_skus i jej powiązania ze znacznikiem merged_at kont, które nie są właścicielem karty
 *    (CardOwnership::isOwnerAccount). Sam merged_at nie wystarcza — stawia go też automatyczne łączenie rozmiarów,
 *    które bywało błędne. Karta z merged_duplicate_skus i zarazem merged_size_skus: nie wiadomo, które scalenie
 *    przeniosło kod — pozycja pominięta z ostrzeżeniem (chyba że potwierdza ją propozycja z punktu 1).
 *
 * Istniejących wierszy mapy nie nadpisuje. Pozycja wskazana przez źródła na dwie różne karty — pominięta
 * z ostrzeżeniem. Domyślnie podgląd; --apply zapisuje.
 */
final class CardRedirectsBackfillCommand extends Command
{
    protected $signature = 'card-redirects:backfill
                            {--apply : Zapisz wiersze mapy (bez tej flagi tylko podgląd)}';

    protected $description = 'Uzupełnia mapę połączeń (card_redirects) z wcześniejszych połączeń kart (podgląd bez --apply)';

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<int, string> */
    private array $accountLabels = [];

    public function handle(CardOwnership $ownership): int
    {
        $this->warnings = [];
        $this->accountLabels = B2bAccount::query()->get()
            ->mapWithKeys(static fn (B2bAccount $a): array => [(int) $a->id => (string) ($a->connector ?: $a->username)])
            ->all();

        $proposals = [...$this->fromCandidates(), ...$this->fromConsoleMerges($ownership)];
        $existing = [];
        foreach (CardRedirect::query()->get(['source_key', 'position_key']) as $row) {
            $existing[CardRedirectStore::key((string) $row->source_key, (string) $row->position_key)] = true;
        }

        $groups = [];
        foreach ($proposals as $proposal) {
            $groups[CardRedirectStore::key($proposal['source_key'], $proposal['position_key'])][] = $proposal;
        }

        $ready = [];
        $already = 0;
        $ambiguous = 0;
        foreach ($groups as $key => $group) {
            if (isset($existing[$key])) {
                $already++;

                continue;
            }
            $chosen = $this->resolve($group);
            if ($chosen === null) {
                $ambiguous++;

                continue;
            }
            $ready[] = $chosen;
        }

        if ($ready !== []) {
            $this->table(
                ['Źródło', 'Pozycja', 'Karta', 'Powód', 'Skąd wiadomo', 'Uwaga'],
                array_map(fn (array $p): array => [
                    $this->sourceLabel($p),
                    $p['position_key']
                        .($p['remote_sku'] !== null && $p['remote_sku'] !== $p['position_key'] ? ' ['.$p['remote_sku'].']' : '')
                        .($p['position_label'] !== null ? ' ('.$p['position_label'].')' : ''),
                    $p['product_id'] !== null
                        ? '#'.$p['product_id'].' '.($p['target_snapshot']['sku'] ?? '')
                        : 'usunięta (#'.($p['target_snapshot']['id'] ?? '—').' '.($p['target_snapshot']['sku'] ?? '').')',
                    CardRedirect::REASON_MERGE,
                    $p['origin'],
                    $this->note($p),
                ], $ready),
            );
        }
        foreach ($this->warnings as $warning) {
            $this->warn($warning);
        }
        $fromCandidates = count(array_filter($ready, static fn (array $p): bool => $p['card_match_candidate_id'] !== null));
        $this->info('Do zapisu: '.count($ready).' (z propozycji: '.$fromCandidates.', z łączeń z konsoli: '
            .(count($ready) - $fromCandidates).'); już w mapie: '.$already.'; pominięte niejednoznaczne: '.$ambiguous.'.');

        if (! (bool) $this->option('apply')) {
            $this->info('Podgląd — nic nie zapisano. Zapis: --apply');

            return self::SUCCESS;
        }

        $written = 0;
        DB::transaction(function () use ($ready, &$written): void {
            foreach ($ready as $p) {
                // drugi przebieg albo zapis w międzyczasie (ekran „Łączenie kart”) — istniejący wiersz zostaje
                if (CardRedirect::query()->where('source_key', $p['source_key'])->where('position_key', $p['position_key'])->exists()) {
                    continue;
                }
                $row = new CardRedirect;
                $row->forceFill([
                    'source_key' => $p['source_key'],
                    'position_key' => $p['position_key'],
                    'b2b_account_id' => $p['b2b_account_id'],
                    'price_list_id' => $p['price_list_id'],
                    'product_id' => $p['product_id'],
                    'reason' => CardRedirect::REASON_MERGE,
                    'is_anchor' => false,
                    'position_label' => $p['position_label'],
                    'remote_sku' => $p['remote_sku'],
                    'target_snapshot' => $p['target_snapshot'],
                    'card_match_candidate_id' => $p['card_match_candidate_id'],
                    'created_by' => $p['created_by'],
                ]);
                if ($p['created_at'] instanceof CarbonInterface) {
                    $row->created_at = $p['created_at'];
                }
                $row->save();
                $written++;
            }
        });
        $this->info("Zapisano {$written} wierszy mapy połączeń.");

        return self::SUCCESS;
    }

    /**
     * Źródło 1: połączone propozycje z kopią zapasową.
     *
     * @return list<array<string, mixed>>
     */
    private function fromCandidates(): array
    {
        $proposals = [];
        $candidates = CardMatchCandidate::query()
            ->where('status', CardMatchCandidate::STATUS_MERGED)
            // łączenie rozmiarów i rozdzielanie zapisują mapę same przy decyzji (recordSizeMerge, recordSplit), a ich
            // kopie mają inny kształt — każda dawała ostrzeżenie „kopia nie pasuje do propozycji”
            ->where('kind', CardMatchCandidate::KIND_MERGE)
            ->whereNotNull('backup_path')
            ->orderBy('decided_at')
            ->orderBy('id')
            ->get();
        foreach ($candidates as $candidate) {
            $label = 'propozycja #'.$candidate->id;
            $path = (string) $candidate->backup_path;
            if (! is_file($path)) {
                $this->warnings[] = $label.': brak kopii zapasowej '.$path.' — pominięta.';

                continue;
            }
            try {
                $backup = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $this->warnings[] = $label.': kopia zapasowa nieczytelna ('.$e->getMessage().') — pominięta.';

                continue;
            }
            if (! is_array($backup) || ($backup['kind'] ?? null) !== 'card-match-merge'
                || (int) ($backup['drop_product_id'] ?? 0) !== (int) $candidate->source_product_id) {
                $this->warnings[] = $label.': kopia zapasowa nie pasuje do propozycji ('.$path.') — pominięta.';

                continue;
            }
            $rows = is_array($backup['cards']['source']['rows'] ?? null) ? $backup['cards']['source']['rows'] : [];
            $links = is_array($rows['b2b_product_links'] ?? null) ? $rows['b2b_product_links'] : [];
            $identifiers = is_array($rows['product_identifiers'] ?? null) ? $rows['product_identifiers'] : [];

            $targetProduct = is_array($backup['cards']['target']['product'] ?? null) ? $backup['cards']['target']['product'] : [];
            $snapshot = [
                'id' => (int) ($targetProduct['id'] ?? $backup['keep_product_id'] ?? $candidate->target_product_id ?? 0),
                'sku' => (string) ($targetProduct['sku'] ?? ''),
                'name' => (string) ($targetProduct['name'] ?? ''),
                'manufacturer' => (string) ($targetProduct['manufacturer'] ?? ''),
                'backfill' => 'kopia zapasowa propozycji #'.$candidate->id,
            ];
            $common = [
                // klucze obce z nullOnDelete: usunięta karta producenta albo autor to już null
                'product_id' => $candidate->target_product_id !== null ? (int) $candidate->target_product_id : null,
                'target_snapshot' => $snapshot,
                'card_match_candidate_id' => (int) $candidate->id,
                'created_by' => $candidate->decided_by !== null ? (int) $candidate->decided_by : null,
                'created_at' => $candidate->decided_at,
                'origin' => $label.' (kopia zapasowa)',
                'certain' => true,
            ];

            /** @var array<string, array<string, mixed>> $mine pozycje tej propozycji */
            $mine = [];
            $labels = [];
            foreach ($identifiers as $identifier) {
                $sourceKey = (string) ($identifier['source_key'] ?? '');
                $position = (string) ($identifier['position_key'] ?? '');
                $variant = self::cut($identifier['variant_label'] ?? null, 120);
                if ($sourceKey === '' || $position === '') {
                    continue;
                }
                $key = CardRedirectStore::key($sourceKey, $position);
                if ($variant !== null) {
                    $labels[$key] ??= $variant;
                }
                if (! str_starts_with($sourceKey, 'file:')) {
                    continue;
                }
                $priceListId = isset($identifier['price_list_id']) ? (int) $identifier['price_list_id'] : null;
                if ($priceListId === null || ! PriceList::query()->whereKey($priceListId)->exists()) {
                    $this->warnings[] = $label.': pozycja '.$position.' cennika '.$sourceKey.' — cennika już nie ma, pominięta.';

                    continue;
                }
                $mine[$key] ??= [
                    ...$common,
                    'source_key' => $sourceKey,
                    'position_key' => $position,
                    'b2b_account_id' => null,
                    'price_list_id' => $priceListId,
                    'remote_sku' => null,
                    'position_label' => null,
                ];
            }
            foreach ($links as $link) {
                $accountId = (int) ($link['b2b_account_id'] ?? 0);
                $position = (string) ($link['remote_id'] ?? '');
                if ($accountId === 0 || $position === '') {
                    continue;
                }
                if (! isset($this->accountLabels[$accountId])) {
                    $this->warnings[] = $label.': pozycja '.$position.' konta #'.$accountId.' — konta już nie ma, pominięta.';

                    continue;
                }
                $sourceKey = ProductSourcePrice::b2bKey($accountId);
                $mine[CardRedirectStore::key($sourceKey, $position)] ??= [
                    ...$common,
                    'source_key' => $sourceKey,
                    'position_key' => $position,
                    'b2b_account_id' => $accountId,
                    'price_list_id' => null,
                    'remote_sku' => self::cut($link['remote_sku'] ?? null, 255),
                    'position_label' => null,
                ];
            }
            foreach ($mine as $key => $proposal) {
                $proposal['position_label'] = $labels[$key] ?? null;
                // różne propozycje tej samej pozycji rozstrzyga resolve()
                $proposals[] = $proposal;
            }
        }

        return $proposals;
    }

    /**
     * Źródło 2: ręczne łączenia z konsoli (merged_duplicate_skus + merged_at powiązań kont spoza właścicieli).
     *
     * @return list<array<string, mixed>>
     */
    private function fromConsoleMerges(CardOwnership $ownership): array
    {
        $proposals = [];
        $cards = Product::query()->whereNotNull('enrichment_payload->merged_duplicate_skus')->orderBy('id')->cursor();
        foreach ($cards as $card) {
            $payload = is_array($card->enrichment_payload) ? $card->enrichment_payload : [];
            $duplicates = is_array($payload['merged_duplicate_skus'] ?? null) ? array_filter($payload['merged_duplicate_skus']) : [];
            if ($duplicates === []) {
                continue;
            }
            $hasSizeMerge = is_array($payload['merged_size_skus'] ?? null) && array_filter($payload['merged_size_skus']) !== [];
            $links = B2bProductLink::query()
                ->where('product_id', $card->id)
                ->whereNotNull('merged_at')
                ->with('account')
                ->orderBy('id')
                ->get();
            foreach ($links as $link) {
                $account = $link->account;
                if (! $account instanceof B2bAccount || $ownership->isOwnerAccount($card, $account)) {
                    continue;
                }
                $sourceKey = ProductSourcePrice::b2bKey((int) $account->id);
                $position = (string) $link->remote_id;
                $proposals[] = [
                    'source_key' => $sourceKey,
                    'position_key' => $position,
                    'b2b_account_id' => (int) $account->id,
                    'price_list_id' => null,
                    'remote_sku' => self::cut($link->remote_sku, 255),
                    'position_label' => self::cut(ProductIdentifier::query()
                        ->where('source_key', $sourceKey)
                        ->where('position_key', $position)
                        ->whereNotNull('variant_label')
                        ->orderBy('id')
                        ->value('variant_label'), 120),
                    'product_id' => (int) $card->id,
                    // karta z chwili uzupełnienia — stanu z chwili łączenia z konsoli nie zapisano
                    'target_snapshot' => [
                        ...CardRedirectStore::snapshot($card),
                        'backfill' => 'merged_duplicate_skus karty + merged_at powiązania (stan z '.now()->toDateString().')',
                    ],
                    'card_match_candidate_id' => null,
                    'created_by' => null,
                    'created_at' => $link->merged_at,
                    'origin' => 'łączenie z konsoli (merged_duplicate_skus: '.mb_substr(implode(', ', $duplicates), 0, 60).')',
                    'certain' => ! $hasSizeMerge,
                ];
            }
        }

        return $proposals;
    }

    /**
     * Jedna pozycja z kilku źródeł: wszystkie muszą wskazywać tę samą kartę. Pierwszeństwo ma propozycja (najnowsza
     * decyzja — kolejność decided_at), potem łączenie z konsoli. Niepewna pozycja (karta z łączeniem rozmiarów) bez
     * potwierdzenia pewnym źródłem — pominięta.
     *
     * @param  list<array<string, mixed>>  $group
     * @return array<string, mixed>|null
     */
    private function resolve(array $group): ?array
    {
        $first = $group[0];
        $where = $first['source_key'].' / '.$first['position_key'];
        $targets = [];
        foreach ($group as $proposal) {
            $identity = $proposal['product_id'] !== null
                ? '#'.$proposal['product_id']
                : 'usunięta #'.($proposal['target_snapshot']['id'] ?? '—');
            $targets[$identity][] = $proposal['origin'];
        }
        if (count($targets) > 1) {
            $parts = [];
            foreach ($targets as $identity => $origins) {
                $parts[] = $identity.' ('.implode('; ', array_unique($origins)).')';
            }
            $this->warnings[] = 'Pozycja '.$where.' wskazana na różne karty: '.implode(' / ', $parts).' — pominięta.';

            return null;
        }

        $certain = array_values(array_filter($group, static fn (array $p): bool => $p['certain']));
        if ($certain === []) {
            $this->warnings[] = 'Pozycja '.$where.' na karcie #'.$first['product_id'].': karta ma też scalone rozmiary '
                .'(merged_size_skus) — nie wiadomo, które scalenie przeniosło kod; pominięta.';

            return null;
        }
        $fromCandidates = array_values(array_filter($certain, static fn (array $p): bool => $p['card_match_candidate_id'] !== null));

        return $fromCandidates !== [] ? $fromCandidates[count($fromCandidates) - 1] : $certain[0];
    }

    /** Gdzie pozycja jest dziś — rozjazd z decyzją naprawi synchronizacja czytająca mapę (kolejny krok planu). */
    private function note(array $p): string
    {
        $notes = [];
        if ($p['product_id'] === null) {
            $notes[] = 'decyzja bez karty';
        }
        if ($p['b2b_account_id'] !== null) {
            $current = B2bProductLink::query()
                ->where('b2b_account_id', $p['b2b_account_id'])
                ->where('remote_id', $p['position_key'])
                ->value('product_id');
            if ($current === null) {
                $notes[] = 'powiązania już nie ma';
            } elseif ($p['product_id'] !== null && (int) $current !== (int) $p['product_id']) {
                $notes[] = 'powiązanie teraz na karcie #'.$current;
            }
        } else {
            $current = ProductIdentifier::query()
                ->where('source_key', $p['source_key'])
                ->where('position_key', $p['position_key'])
                ->distinct()
                ->pluck('product_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            if ($current === []) {
                $notes[] = 'pozycji cennika już nie ma';
            } elseif ($p['product_id'] !== null && $current !== [(int) $p['product_id']]) {
                $notes[] = 'pozycja cennika teraz na karcie #'.implode(', #', $current);
            }
        }

        return implode('; ', $notes);
    }

    private function sourceLabel(array $p): string
    {
        if ($p['b2b_account_id'] !== null) {
            return $p['source_key'].' ('.($this->accountLabels[(int) $p['b2b_account_id']] ?? '?').')';
        }
        $list = PriceList::query()->find($p['price_list_id']);

        return $p['source_key'].($list !== null ? ' ('.$list->manufacturer.' '.$list->version.')' : '');
    }

    private static function cut(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
