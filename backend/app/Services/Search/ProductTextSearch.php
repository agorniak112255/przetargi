<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Product;
use App\Support\ProductFeatureMatch;
use App\Support\ProductSearchBlob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Wyszukiwanie tekstowe po znormalizowanej kolumnie `search_blob`. Na MySQL robi to
 * FULLTEXT, który zwraca od razu ranking trafności; SQLite z testów nie ma FULLTEXT,
 * więc tam ten sam zakres wyników daje LIKE z punktacją w PHP.
 *
 * Rodzina PPE jest warunkiem WHERE, a nie filtrem po stronie aplikacji — dzięki temu
 * „pokaż cały zgodny asortyment” nie oznacza już wczytania całego katalogu do PHP.
 */
final class ProductTextSearch
{
    /** Domyślny `innodb_ft_min_token_size`; krótsze tokeny i tak nie wejdą do indeksu. */
    private const MIN_TOKEN_LENGTH = 3;

    private const MAX_TOKENS = 24;

    public function __construct(
        private readonly ProductFeatureMatch $features,
        private readonly ProductSearchBlob $blob,
    ) {}

    /**
     * @param  list<string>  $phrases
     * @param  string|null  $family  gdy podana, wynik obejmuje całą rodzinę — także karty
     *                               bez trafienia we frazę, uszeregowane niżej
     * @return list<int> id produktów w kolejności trafności
     */
    public function search(array $phrases, ?string $family, int $limit): array
    {
        $tokens = $this->tokens($phrases);
        if ($tokens === [] && $family === null) {
            return [];
        }

        $limit = max(1, $limit);

        return DB::connection()->getDriverName() === 'mysql'
            ? $this->fullText($tokens, $family, $limit)
            : $this->likeFallback($tokens, $family, $limit);
    }

    /**
     * @param  list<string>  $phrases
     * @return list<string>
     */
    public function tokens(array $phrases): array
    {
        $text = trim(implode(' ', array_filter(
            $phrases,
            static fn (string $phrase): bool => trim($phrase) !== ''
        )));
        if ($text === '') {
            return [];
        }

        // Zapytanie przechodzi tę samą kanonizację co indeks, inaczej „250 g/m²”
        // z SIWZ nie spotkałoby się z „250gsm” zapisanym w blobie.
        $normalized = $this->features->normalize($text).' '.$this->blob->canonicalFeatures($text);
        if (preg_match_all('/[a-z0-9]{'.self::MIN_TOKEN_LENGTH.',}/u', $normalized, $matches) === false) {
            return [];
        }

        return array_slice(array_values(array_unique($matches[0] ?? [])), 0, self::MAX_TOKENS);
    }

    /**
     * Budowa bez wykonania — testy chodzą na SQLite, więc składnię wariantu MySQL
     * da się sprawdzić tylko na gotowym zapytaniu.
     *
     * @param  list<string>  $tokens
     * @return Builder<Product>
     */
    public function fullTextQuery(array $tokens, ?string $family, int $limit): Builder
    {
        $query = Product::query()->select('id');

        if ($tokens !== []) {
            $against = implode(' ', array_map(static fn (string $t): string => $t.'*', $tokens));
            $query->selectRaw('MATCH(search_blob) AGAINST (? IN BOOLEAN MODE) AS relevance', [$against])
                ->orderByDesc('relevance');

            // Bez rozpoznanej rodziny nie ma czego pokazać poza trafieniami, więc
            // to samo wyrażenie wraca jako warunek.
            if ($family === null) {
                $query->whereRaw('MATCH(search_blob) AGAINST (? IN BOOLEAN MODE)', [$against]);
            }
        }

        if ($family !== null) {
            $query->where('ppe_family', $family);
        }

        return $this->finish($query, $limit);
    }

    /**
     * @param  list<string>  $tokens
     * @return list<int>
     */
    private function fullText(array $tokens, ?string $family, int $limit): array
    {
        return $this->fullTextQuery($tokens, $family, $limit)
            ->get()
            ->pluck('id')
            ->map(intval(...))
            ->all();
    }

    /**
     * @param  list<string>  $tokens
     * @return list<int>
     */
    private function likeFallback(array $tokens, ?string $family, int $limit): array
    {
        $query = Product::query()->select(['id', 'search_blob', 'enrichment_status']);

        if ($family !== null) {
            $query->where('ppe_family', $family);
        } elseif ($tokens !== []) {
            $query->where(function (Builder $outer) use ($tokens): void {
                foreach ($tokens as $token) {
                    $outer->orWhere('search_blob', 'like', '%'.addcslashes($token, '%_\\').'%');
                }
            });
        }

        $rows = $this->finish($query, max($limit * 4, 400))->get()->all();
        $count = count($tokens);

        $scored = [];
        foreach ($rows as $index => $row) {
            $blob = (string) ($row->search_blob ?? '');
            $score = 0;
            foreach ($tokens as $position => $token) {
                if (str_contains($blob, $token)) {
                    $score += $count - $position;
                }
            }
            // Kolejność z SQL jest już sensownym rozstrzygnięciem remisów.
            $scored[] = ['id' => (int) $row->id, 'score' => $score, 'order' => $index];
        }

        usort($scored, static fn (array $a, array $b): int => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);

        return array_slice(array_column($scored, 'id'), 0, $limit);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function finish(Builder $query, int $limit): Builder
    {
        return $query
            ->orderByRaw("CASE WHEN enrichment_status = 'done' THEN 0 ELSE 1 END")
            ->orderByDesc('enriched_at')
            ->orderBy('id')
            ->limit($limit);
    }
}
