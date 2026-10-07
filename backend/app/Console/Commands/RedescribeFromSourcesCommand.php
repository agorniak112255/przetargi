<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductSourceDocument;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\SourceDoc;
use App\Services\Enrichment\SourceDocumentStore;
use App\Services\Enrichment\StoredSourcesDescriptionRejected;
use App\Services\PriceListCards;
use App\Support\NormCode;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Opis z zapisanych źródeł w trybie cienia (etap 1 opisów z cenników, 08.10.2026): dla kart z zapisanymi źródłami
 * opisu (SourceDocumentStore) model pisze opis wyłącznie z nich (ProductEnrichmentService::describeFromStoredSources),
 * a komenda zestawia go z obecnym opisem karty — werdykt tożsamości, dowody ze źródła, długość, różnice norm.
 *
 * Karty po kolei, jedno zapytanie do modelu naraz. Tabeli products nie zmienia nigdy; z --apply zapisuje wyłącznie
 * wersje shadow (origin stored_sources) — do porównania przed przełączeniem marki na opis ze źródeł (etap 2/5).
 */
final class RedescribeFromSourcesCommand extends Command
{
    protected $signature = 'products:redescribe-from-sources
                            {--price-list= : Tylko karty tego cennika (numer cennika)}
                            {--product=* : Tylko te karty (numery kart, można podać kilka)}
                            {--limit=20 : Najwyżej tyle kart (0 = wszystkie)}
                            {--apply : Zapisz opisy jako wersje shadow (bez tej flagi tylko porównanie)}';

    protected $description = 'Tryb cienia: opis z zapisanych źródeł karty obok obecnego opisu (werdykt, dowody, długość, normy); z --apply tylko wersje shadow';

    public function handle(
        ProductEnrichmentService $enrichment,
        SourceDocumentStore $sources,
        DescriptionVersionStore $versions,
        PriceListCards $cards,
    ): int {
        $query = $this->scopedQuery($cards);
        if ($query === null) {
            return self::FAILURE;
        }
        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $query->limit($limit);
        }
        $apply = (bool) $this->option('apply');

        $rows = [];
        $counts = ['described' => 0, 'rejected' => 0, 'failed' => 0, 'shadow' => 0];
        foreach ($query->get() as $product) {
            /** @var Product $product */
            $docs = $this->orderedDocs($product, $sources->forProduct($product, ProductSourceDocument::ROLE_DESCRIPTION));
            $current = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
            $before = [
                (int) $product->id,
                (string) $product->sku,
                $this->verdictLabel($current['identity']['verdict'] ?? null),
            ];
            try {
                $result = $enrichment->describeFromStoredSources($product, $docs);
            } catch (StoredSourcesDescriptionRejected $e) {
                $counts['rejected']++;
                $rows[] = [...$before, '—', $this->evidenceLabel($current, null), $this->lengthLabel($product, null), '—', 'odrzucony: '.mb_substr($e->getMessage(), 0, 80)];

                continue;
            } catch (Throwable $e) {
                // awaria modelu przy jednej karcie nie przerywa porównania pozostałych
                $counts['failed']++;
                $rows[] = [...$before, '—', $this->evidenceLabel($current, null), $this->lengthLabel($product, null), '—', 'błąd: '.mb_substr($e->getMessage(), 0, 80)];

                continue;
            }
            $counts['described']++;
            $payload = $result['payload'];
            $note = 'opis';
            if ($apply) {
                $version = $versions->record($product, ProductDescriptionVersion::STATUS_SHADOW, ProductDescriptionVersion::ORIGIN_STORED_SOURCES, [
                    'description' => $result['description'],
                    'enrichment_payload' => $payload,
                    'packaging' => $result['packaging'],
                    'primary_source_url' => $payload['primary_source_url'] ?? null,
                    'reason' => 'tryb cienia: opis z zapisanych źródeł (products:redescribe-from-sources)',
                ]);
                $counts['shadow']++;
                $note = 'wersja shadow #'.$version->id;
            }
            $rows[] = [
                ...$before,
                $this->verdictLabel($payload['identity']['verdict'] ?? null),
                $this->evidenceLabel($current, $payload),
                $this->lengthLabel($product, $result['description']),
                $this->normsDiff($current['norms'] ?? [], $payload['norms'] ?? []),
                $note,
            ];
        }

        if ($rows === []) {
            $this->info('Brak kart z zapisanymi źródłami opisu w tym zakresie.');

            return self::SUCCESS;
        }
        $this->table(['Karta', 'SKU', 'Werdykt teraz', 'Werdykt ze źródeł', 'Dowody (teraz → źródła)', 'Długość (teraz → źródła)', 'Normy (+ dochodzą, − znikają)', 'Wynik'], $rows);
        $this->info(sprintf(
            'Kart: %d — opis ze źródeł %d, odrzucony %d, błąd %d.',
            count($rows), $counts['described'], $counts['rejected'], $counts['failed'],
        ));
        $this->line($apply
            ? "Zapisano wersji shadow: {$counts['shadow']}. Karty bez zmian."
            : 'Porównanie — nic nie zapisano. Wersje shadow: dodaj --apply.');

        return self::SUCCESS;
    }

    /** @return Builder<Product>|null */
    private function scopedQuery(PriceListCards $cards): ?Builder
    {
        $query = Product::query()
            ->whereExists(static fn ($q) => $q->selectRaw('1')
                ->from('product_source_documents')
                ->whereColumn('product_source_documents.product_id', 'products.id'))
            ->orderBy('id');

        $ids = array_values(array_filter(array_map('intval', (array) $this->option('product')), static fn (int $id): bool => $id > 0));
        if ($ids !== []) {
            $query->whereIntegerInRaw('id', $ids);
        }
        $priceListId = (int) $this->option('price-list');
        if ($priceListId > 0) {
            $priceList = PriceList::query()->find($priceListId);
            if ($priceList === null) {
                $this->error("Nie ma cennika {$priceListId}.");

                return null;
            }
            $query->whereIntegerInRaw('id', $cards->ids($priceList));
        }

        return $query;
    }

    /**
     * Źródło obecnego opisu karty (primary_source_url) pierwsze — model czyta źródła w kolejności i ma limit stron.
     *
     * @param  list<SourceDoc>  $docs
     * @return list<SourceDoc>
     */
    private function orderedDocs(Product $product, array $docs): array
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $primary = is_string($payload['primary_source_url'] ?? null) && trim($payload['primary_source_url']) !== ''
            ? Product::normalizeShopUrl($payload['primary_source_url'])
            : null;
        if ($primary === null) {
            return $docs;
        }
        usort($docs, static fn (SourceDoc $a, SourceDoc $b): int => (Product::normalizeShopUrl($b->url) === $primary) <=> (Product::normalizeShopUrl($a->url) === $primary));

        return $docs;
    }

    private function verdictLabel(mixed $verdict): string
    {
        return is_string($verdict) && $verdict !== '' ? $verdict : 'nieznany';
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $new
     */
    private function evidenceLabel(array $current, ?array $new): string
    {
        $count = static fn (array $payload): string => is_numeric($payload['evidence_summary']['explicit'] ?? null)
            ? (string) (int) $payload['evidence_summary']['explicit']
            : '—';

        return $count($current).' → '.($new !== null ? $count($new) : '—');
    }

    private function lengthLabel(Product $product, ?string $new): string
    {
        return mb_strlen(trim((string) $product->description)).' → '.($new !== null ? (string) mb_strlen($new) : '—');
    }

    /**
     * Normy po kluczu tożsamości normy (NormCode::key, bez roku i poziomów), zapis nierozpoznany — dosłownie.
     */
    private function normsDiff(mixed $before, mixed $after): string
    {
        $index = static function (mixed $list): array {
            $out = [];
            foreach (is_array($list) ? $list : [] as $norm) {
                if (is_string($norm) && trim($norm) !== '') {
                    $key = NormCode::key($norm);
                    $out[$key !== '' ? $key : mb_strtoupper(trim($norm))] ??= trim($norm);
                }
            }

            return $out;
        };
        $old = $index($before);
        $new = $index($after);
        $added = array_values(array_diff_key($new, $old));
        $removed = array_values(array_diff_key($old, $new));
        if ($added === [] && $removed === []) {
            return 'bez zmian';
        }
        $parts = [];
        if ($added !== []) {
            $parts[] = '+ '.implode(', ', $added);
        }
        if ($removed !== []) {
            $parts[] = '− '.implode(', ', $removed);
        }

        return mb_substr(implode('; ', $parts), 0, 120);
    }
}
