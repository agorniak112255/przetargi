<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Jedna ścieżka recall katalogu: rodzina PPE + typ artykułu + twarde cechy (ESD, °C, klasa obuwia, filtr).
 */
final class CatalogRequirementRecall
{
    public function __construct(
        private readonly PpeAssortment $assortment,
        private readonly BhpAttributeNormalizer $bhpAttributes,
        private readonly PpeFilterType $filterType,
        private readonly ProductModelFuzzy $modelFuzzy,
    ) {}

    /** Czy po rankingu / fallbacku uzupełniać wyniki z pełnego dopasowania katalogowego. */
    /**
     * @param  array<string, mixed>  $intent
     */
    public function shouldBackfillCatalog(string $query, array $intent = []): bool
    {
        if ($this->modelFuzzy->usesModelAnchoredCatalogSearch($query)) {
            return false;
        }
        $profile = $this->profile($query);
        if ($profile === null) {
            return false;
        }

        return $profile['antistatic']
            || $profile['min_heat'] !== null
            || $profile['foot_class'] !== null
            || $profile['head_liner']
            || $profile['filter_likes'] !== [];
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    public function shouldRecallToCandidatePool(string $query, array $intent = []): bool
    {
        if ($this->modelFuzzy->usesModelAnchoredCatalogSearch($query)) {
            return false;
        }

        return $this->profile($query) !== null;
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function resolveCatalogQuery(string $query, array $intent): string
    {
        if (! empty($intent['manufacturer_absent_in_catalog'])) {
            $chunks = array_filter([trim((string) ($intent['needed'] ?? ''))]);
            foreach ($intent['constraints'] ?? [] as $constraint) {
                $constraint = trim((string) $constraint);
                if ($constraint !== '') {
                    $chunks[] = $constraint;
                }
            }
            $merged = trim(implode(' ', array_unique($chunks)));
            if ($merged !== '') {
                return $merged;
            }
        }

        return $query;
    }

    /**
     * @param  callable(): Builder  $baseQuery
     * @param  callable(Product): string  $haystack
     * @param  callable(string, Product): int  $score
     * @param  callable(Product): (?int)  $heatCelsius
     * @return Collection<int, Product>
     */
    public function retrieve(
        callable $baseQuery,
        string $query,
        int $limit,
        callable $haystack,
        callable $score,
        callable $heatCelsius,
    ): Collection {
        $profile = $this->profile($query);
        if ($profile === null) {
            return collect();
        }

        $nouns = $profile['nouns'];
        if ($nouns === [] && ! $profile['head_liner'] && $profile['filter_likes'] === []) {
            return collect();
        }

        $builder = $baseQuery();
        if ($profile['family'] !== null) {
            $family = $profile['family'];
            $builder->where(function (Builder $outer) use ($family): void {
                $outer->where('ppe_family', $family)->orWhereNull('ppe_family');
            });
        }

        if ($profile['head_liner']) {
            $this->applyHeadLinerScope($builder);
        } elseif ($nouns !== []) {
            $family = $profile['family'];
            $builder->where(function (Builder $outer) use ($nouns, $family): void {
                foreach ($nouns as $like) {
                    $esc = '%'.$like.'%';
                    $outer->orWhere('name', 'like', $esc)
                        ->orWhere('sku', 'like', $esc)
                        ->orWhere('category', 'like', $esc)
                        ->orWhere('description', 'like', $esc)
                        ->orWhere('search_blob', 'like', $esc);
                }
                if ($family !== null) {
                    $outer->orWhere('ppe_family', $family);
                }
            });
        } elseif ($profile['filter_likes'] !== []) {
            $builder->where(function (Builder $outer) use ($profile): void {
                foreach ($profile['filter_likes'] as $like) {
                    $esc = '%'.$like.'%';
                    $outer->orWhere('name', 'like', $esc)
                        ->orWhere('sku', 'like', $esc)
                        ->orWhere('description', 'like', $esc)
                        ->orWhere('norms', 'like', $esc)
                        ->orWhere('search_blob', 'like', $esc);
                }
            });
        }

        if ($profile['evidence_likes'] !== []) {
            $builder->where(function (Builder $outer) use ($profile): void {
                foreach ($profile['evidence_likes'] as $like) {
                    $outer->orWhere('name', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('norms', 'like', $like)
                        ->orWhere('search_blob', 'like', $like);
                }
            });
        }

        if ($profile['foot_class'] !== null && $profile['family'] === PpeAssortment::FAMILY_FOOTWEAR) {
            $class = $profile['foot_class'];
            $token = $this->bhpAttributes->footwearClassToken($class);
            $likeClass = '%'.addcslashes($class, '%_\\').'%';
            $likeToken = '%'.addcslashes($token, '%_\\').'%';
            $builder->where(function (Builder $outer) use ($likeClass, $likeToken): void {
                $outer->where('name', 'like', $likeClass)
                    ->orWhere('sku', 'like', $likeClass)
                    ->orWhere('search_blob', 'like', $likeToken)
                    ->orWhere('search_blob', 'like', $likeClass);
            });
        }

        if ($profile['min_heat'] !== null) {
            $builder->where(function (Builder $outer): void {
                foreach (['%°C%', '%° C%', '%stopni%', '%st. C%', '%st.C%', '% C', '% C.', '% C,'] as $like) {
                    $outer->orWhere('name', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('search_blob', 'like', $like)
                        ->orWhere('norms', 'like', $like);
                }
            });
        }

        $rows = $builder->limit(500)->get()->filter(function (Product $product) use (
            $query,
            $profile,
            $haystack,
            $heatCelsius,
        ): bool {
            if (! $this->assortment->compatibleProduct($query, $product)) {
                return false;
            }
            $hay = $haystack($product);
            if ($profile['antistatic'] && ! $this->assortment->productMeetsAntistaticRequirement($query, $product)) {
                return false;
            }
            if ($profile['foot_class'] !== null) {
                $identity = trim($product->name.' '.$product->sku.' '.(string) ($product->category ?? ''));
                if ($this->bhpAttributes->footwearClass($identity) !== $profile['foot_class']) {
                    return false;
                }
                $reqType = $this->assortment->articleType($query, PpeAssortment::FAMILY_FOOTWEAR);
                $prodType = $this->assortment->articleType($product->name, PpeAssortment::FAMILY_FOOTWEAR);
                if ($reqType !== null && $prodType !== null && $reqType !== $prodType) {
                    return false;
                }
            }
            if ($profile['min_heat'] !== null) {
                $maxC = $heatCelsius($product);
                if ($maxC === null || $maxC < $profile['min_heat']) {
                    return false;
                }
            }
            if ($profile['filter_likes'] !== []) {
                if (! $this->filterType->covers($query, $hay)) {
                    return false;
                }
            }

            return true;
        });

        $sorted = $rows->sortByDesc(fn (Product $p): int => $score($query, $p))->values();

        return $sorted->take($this->takeLimit($profile, $limit))->values();
    }

    public function catalogMatchReason(string $query): string
    {
        if ($this->assortment->requiresAntistatic($query)) {
            return 'Produkty z potwierdzeniem ESD / antyelektrostatyczności w katalogu.';
        }
        if ($this->bhpAttributes->requiredCelsius($query) !== null) {
            return 'Produkty spełniające wymaganą odporność termiczną w katalogu.';
        }
        if ($this->bhpAttributes->footwearClass($query) !== null) {
            return 'Obuwie w wymaganej klasie ochrony w katalogu.';
        }
        if ($this->filterType->sqlLikes($query) !== []) {
            return 'Filtry / pochłaniacze zgodne z oznaczeniem w katalogu.';
        }

        return 'Ten sam rodzaj i cechy w katalogu (dopasowanie do wymagania).';
    }

    /**
     * @return array{
     *     family: ?string,
     *     nouns: list<string>,
     *     evidence_likes: list<string>,
     *     antistatic: bool,
     *     foot_class: ?string,
     *     min_heat: ?int,
     *     head_liner: bool,
     *     filter_likes: list<string>
     * }|null
     */
    private function profile(string $query): ?array
    {
        if ($this->modelFuzzy->hasNamedModel($query)) {
            return null;
        }

        $family = $this->assortment->family($query);
        $nouns = $this->assortment->catalogNounLikes($query);
        $antistatic = $this->assortment->requiresAntistatic($query);
        $footClass = $family === PpeAssortment::FAMILY_FOOTWEAR
            ? $this->bhpAttributes->footwearClass($query)
            : null;
        $minHeat = $this->bhpAttributes->requiredCelsius($query);
        $headLiner = $this->assortment->isUnderHelmetLiner($query);
        $filterLikes = $this->filterType->sqlLikes($query);

        if ($nouns === [] && $family !== null) {
            $nouns = $this->defaultNounLikes($family, $antistatic);
        }
        if ($antistatic && $family === PpeAssortment::FAMILY_FOOTWEAR && $nouns !== []) {
            $nouns = array_values(array_unique(array_merge(
                $nouns,
                $this->defaultNounLikes(PpeAssortment::FAMILY_FOOTWEAR, true),
            )));
        }

        $evidence = [];
        if ($antistatic) {
            foreach (['%esd%', '%antyelektro%', '%antystat%', '%1149%', '%61340%'] as $like) {
                $evidence[] = $like;
            }
        }

        $hasSignal = $family !== null
            || $nouns !== []
            || $antistatic
            || $footClass !== null
            || $minHeat !== null
            || $headLiner
            || $filterLikes !== [];

        if (! $hasSignal) {
            return null;
        }

        return [
            'family' => $family,
            'nouns' => $nouns,
            'evidence_likes' => $evidence,
            'antistatic' => $antistatic,
            'foot_class' => $footClass,
            'min_heat' => $minHeat,
            'head_liner' => $headLiner,
            'filter_likes' => $filterLikes,
        ];
    }

    /**
     * @return list<string>
     */
    private function defaultNounLikes(?string $family, bool $antistatic): array
    {
        if ($family === PpeAssortment::FAMILY_FOOTWEAR && $antistatic) {
            return ['kalosz', 'wellington', 'gumowc', 'gumiak', 'gumow', 'guma', 'obuwie', 'buty', 'trzewik', 'polbut'];
        }

        return match ($family) {
            PpeAssortment::FAMILY_GLOVES => ['rekawic', 'glove', 'rękawic'],
            PpeAssortment::FAMILY_EYES => ['okular', 'gogl'],
            PpeAssortment::FAMILY_HEARING => ['nausznik', 'ochronnik', 'sluch', 'czasze'],
            PpeAssortment::FAMILY_HEAD => ['czapk', 'czepek', 'kominiark', 'helm', 'kask'],
            PpeAssortment::FAMILY_FOOTWEAR => ['obuwie', 'buty', 'trzewik', 'kalosz'],
            PpeAssortment::FAMILY_APPAREL => ['kurtk', 'spodn', 'kombinezon', 'kamizelk', 'bluz'],
            PpeAssortment::FAMILY_RESPIRATORY => ['polmask', 'maska', 'filtr', 'pochlaniacz'],
            default => [],
        };
    }

    /**
     * @param  array{
     *     family: ?string,
     *     nouns: list<string>,
     *     evidence_likes: list<string>,
     *     antistatic: bool,
     *     foot_class: ?string,
     *     min_heat: ?int,
     *     head_liner: bool,
     *     filter_likes: list<string>
     * }  $profile
     */
    private function takeLimit(array $profile, int $limit): int
    {
        if ($profile['antistatic'] || $profile['min_heat'] !== null || $profile['foot_class'] !== null) {
            return max(40, $limit);
        }

        return max(8, $limit);
    }

    private function applyHeadLinerScope(Builder $builder): void
    {
        $builder->where(function (Builder $outer): void {
            $outer->where('name', 'like', '%czepek%')
                ->orWhere('name', 'like', '%czepk%')
                ->orWhere('name', 'like', '%kominiark%')
                ->orWhere('name', 'like', '%balaclava%')
                ->orWhere('category', 'like', '%czepek%')
                ->orWhere('category', 'like', '%kominiark%')
                ->orWhere(function (Builder $inner): void {
                    $inner->where(function (Builder $w): void {
                        $w->where('name', 'like', '%wkładk%')
                            ->orWhere('name', 'like', '%wkladk%')
                            ->orWhere('search_blob', 'like', '%wkladk%');
                    })->where(function (Builder $h): void {
                        $h->where('name', 'like', '%hełm%')
                            ->orWhere('name', 'like', '%helm%')
                            ->orWhere('name', 'like', '%kask%')
                            ->orWhere('description', 'like', '%hełm%')
                            ->orWhere('description', 'like', '%helm%')
                            ->orWhere('search_blob', 'like', '%helm%');
                    });
                })
                ->orWhere(function (Builder $cap): void {
                    $cap->where(function (Builder $n): void {
                        $n->where('name', 'like', '%czapk%')
                            ->orWhere('category', 'like', '%czapk%');
                    })->where(function (Builder $ctx): void {
                        $ctx->where('name', 'like', '%ociepl%')
                            ->orWhere('name', 'like', '%hełm%')
                            ->orWhere('name', 'like', '%helm%')
                            ->orWhere('name', 'like', '%kask%')
                            ->orWhere('name', 'like', '%esd%')
                            ->orWhere('description', 'like', '%hełm%');
                    });
                });
        });
    }
}
