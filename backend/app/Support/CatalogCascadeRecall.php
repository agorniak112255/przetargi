<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Rodzaj → cecha → marka (i model, gdy jest). Puste sitko zdejmuje warstwy od końca.
 */
final class CatalogCascadeRecall
{
    public const LEVEL_FAMILY_FEATURE_BRAND_MODEL = 'family_feature_brand_model';

    public const LEVEL_FAMILY_FEATURE_BRAND = 'family_feature_brand';

    public const LEVEL_FAMILY_FEATURE = 'family_feature';

    public const LEVEL_FAMILY = 'family';

    public function __construct(
        private readonly PpeAssortment $assortment,
        private readonly CatalogSlangDictionary $slang,
        private readonly CatalogManufacturerContext $manufacturers,
        private readonly ProductModelFuzzy $modelFuzzy,
        private readonly PpeFilterType $filterType,
    ) {}

    /**
     * @param  array<string, mixed>  $intent
     * @return array{
     *     family: ?string,
     *     family_nouns: list<string>,
     *     features: list<string>,
     *     manufacturer: ?string,
     *     model_needles: list<string>
     * }
     */
    public function layers(string $query, array $intent): array
    {
        $needed = trim((string) ($intent['needed'] ?? ''));
        $scope = trim($needed.' '.$query);
        $family = $this->assortment->family($query)
            ?? $this->assortment->family($needed)
            ?? $this->slang->familyFor($query);
        $features = $this->featureTokens($query, $intent, $scope, $family);
        $manufacturer = null;
        if (empty($intent['manufacturer_absent_in_catalog'])) {
            $guess = trim((string) ($intent['manufacturer'] ?? ''));
            if ($guess !== '' && $this->manufacturers->hasProductsForManufacturer($guess)) {
                $manufacturer = $guess;
            }
        }
        $modelNeedles = [];
        $modelQuery = trim((string) ($intent['model_name'] ?? '').' '.$query);
        if ($this->modelFuzzy->usesModelAnchoredCatalogSearch($modelQuery)) {
            $modelNeedles = $this->modelFuzzy->catalogModelNeedles($modelQuery);
        }

        return [
            'family' => $family,
            'family_nouns' => $this->familyNouns($family),
            'features' => $features,
            'manufacturer' => $manufacturer,
            'model_needles' => $modelNeedles,
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array{products: Collection<int, Product>, level: ?string}
     */
    public function retrieve(string $query, array $intent, string $requirement, int $limit): array
    {
        $layers = $this->layers($query, $intent);
        $steps = $this->intentSteps($intent, $layers);
        if ($steps !== []) {
            return $this->retrieveBySteps($query, $layers, $steps, $requirement, $limit);
        }
        if ($layers['family'] === null && $layers['features'] === [] && $layers['manufacturer'] === null) {
            return ['products' => collect(), 'level' => null];
        }

        foreach ($this->attempts($layers) as $attempt) {
            $rows = $this->query($layers, $attempt, max(8, $limit))
                ->filter(fn (Product $p): bool => $this->assortment->compatibleProduct($requirement, $p)
                    || $this->modelFuzzy->matches($requirement, $p))
                ->values();
            if ($attempt['feature'] && $this->slang->searchRewrite($query) !== null) {
                $rows = $rows
                    ->filter(fn (Product $p): bool => $this->matchesFeatureEvidence($query, $p))
                    ->values();
            }
            $rows = $rows
                ->filter(fn (Product $p): bool => ! $this->slang->rejectsProduct(
                    $query,
                    implode(' ', [
                        (string) $p->name,
                        (string) $p->sku,
                        (string) ($p->category ?? ''),
                        (string) ($p->description ?? ''),
                        (string) ($p->search_blob ?? ''),
                    ])
                ))
                ->values();
            if ($rows->isNotEmpty()) {
                return ['products' => $rows, 'level' => $attempt['name']];
            }
        }

        return ['products' => collect(), 'level' => null];
    }

    /**
     * @param  array<string, mixed>  $intent
     * @param  array{family: ?string, family_nouns: list<string>}  $layers
     * @return list<array{label: string, kind: 'text'|'brand', tokens: list<string>}>
     */
    private function intentSteps(array $intent, array $layers): array
    {
        $raw = is_array($intent['search_steps'] ?? null) ? $intent['search_steps'] : [];
        $brand = trim((string) ($intent['manufacturer'] ?? ''));
        $requested = trim((string) ($intent['manufacturer_requested'] ?? ''));
        $out = [];
        foreach ($raw as $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }
            if ($this->isBrandLabel($label, $brand, $requested)) {
                if ($brand !== '' && empty($intent['manufacturer_absent_in_catalog'])) {
                    $out[] = ['label' => $label, 'kind' => 'brand', 'tokens' => []];
                }

                continue;
            }
            $out[] = [
                'label' => $label,
                'kind' => 'text',
                'tokens' => $this->stepTokens($label, $layers['family'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @param  array{family: ?string, family_nouns: list<string>}  $layers
     * @param  list<array{label: string, kind: 'text'|'brand', tokens: list<string>}>  $steps
     * @return array{products: Collection<int, Product>, level: ?string}
     */
    private function retrieveBySteps(
        string $query,
        array $layers,
        array $steps,
        string $requirement,
        int $limit,
    ): array {
        $firstHasFeature = ($steps[0]['kind'] ?? '') === 'text' && ($steps[0]['tokens'] ?? []) !== [];
        $minKeep = $firstHasFeature ? 1 : min(2, count($steps));
        for ($n = count($steps); $n >= $minKeep; $n--) {
            $used = array_slice($steps, 0, $n);
            $rows = $this->querySteps($layers, $used, max(8, $limit))
                ->filter(fn (Product $p): bool => $this->assortment->compatibleProduct($requirement, $p)
                    || $this->modelFuzzy->matches($requirement, $p))
                ->filter(fn (Product $p): bool => ! $this->slang->rejectsProduct(
                    $query,
                    implode(' ', [
                        (string) $p->name,
                        (string) $p->sku,
                        (string) ($p->category ?? ''),
                        (string) ($p->description ?? ''),
                        (string) ($p->search_blob ?? ''),
                    ])
                ))
                ->values();
            if ($rows->isNotEmpty()) {
                return ['products' => $rows, 'level' => 'steps_'.$n];
            }
        }

        return ['products' => collect(), 'level' => null];
    }

    /**
     * @param  array{family: ?string, family_nouns: list<string>}  $layers
     * @param  list<array{label: string, kind: 'text'|'brand', tokens: list<string>}>  $steps
     * @return Collection<int, Product>
     */
    private function querySteps(array $layers, array $steps, int $limit): Collection
    {
        $builder = Product::query();
        $this->applyFamilyScope($builder, $layers);
        foreach ($steps as $step) {
            if ($step['kind'] === 'brand') {
                $brand = $step['label'];
                $like = '%'.addcslashes($brand, '%_\\').'%';
                $builder->where(function (Builder $outer) use ($brand, $like): void {
                    $outer->where('manufacturer', $brand)
                        ->orWhere('manufacturer', 'like', $like);
                });

                continue;
            }
            foreach ($step['tokens'] as $token) {
                $this->applyStepToken($builder, $token);
            }
        }

        return $builder
            ->orderByRaw("CASE WHEN enrichment_status = 'done' THEN 0 ELSE 1 END")
            ->orderByDesc('enriched_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->values();
    }

    private function applyStepToken(Builder $builder, string $token): void
    {
        $token = trim($token);
        if ($token === '') {
            return;
        }
        $needles = $this->slang->searchAliases($token);
        if ($needles === []) {
            $needles = [$token];
        }
        $builder->where(function (Builder $outer) use ($needles): void {
            foreach ($needles as $needle) {
                $like = '%'.addcslashes($needle, '%_\\').'%';
                $outer->orWhere('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('search_blob', 'like', $like);
            }
        });
    }

    /** @return list<string> */
    private function stepTokens(string $step, ?string $family): array
    {
        $out = [];
        foreach ($this->assortment->catalogNounLikes($step) as $like) {
            $fold = $this->fold($like);
            if ($fold !== '' && mb_strlen($fold) >= 3) {
                $out[] = $fold;
            }
        }
        $familyNouns = $this->familyNouns($family);
        $stop = ['ochrona', 'przed', 'ciecza', 'olej', 'plyn', 'proste', 'uniwersaln', 'oraz', 'dla', 'lekki', 'cienki'];
        foreach ($this->tokenize($step) as $token) {
            if (in_array($token, $stop, true) || preg_match('/^(ciecza|olej|plyn|ochrona|przed|lekki|cienki)/u', $token) === 1) {
                continue;
            }
            $skip = false;
            foreach ($familyNouns as $noun) {
                if (str_starts_with($token, $noun) || str_starts_with($noun, $token)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            $out[] = mb_strlen($token) > 5 ? mb_substr($token, 0, 5) : $token;
        }

        return array_values(array_unique(array_filter(
            $out,
            static fn (string $t): bool => $t !== '' && mb_strlen($t) >= 3
        )));
    }

    private function isBrandLabel(string $label, string $brand, string $requested): bool
    {
        $fold = $this->fold($label);
        if ($fold === '') {
            return false;
        }
        foreach ([$brand, $requested] as $name) {
            $n = $this->fold($name);
            if ($n !== '' && (str_contains($fold, $n) || str_contains($n, $fold))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{
     *     family: ?string,
     *     family_nouns: list<string>,
     *     features: list<string>,
     *     manufacturer: ?string,
     *     model_needles: list<string>
     * }  $layers
     * @return list<array{name: string, feature: bool, brand: bool, model: bool}>
     */
    private function attempts(array $layers): array
    {
        $hasFeature = $layers['features'] !== [];
        $hasBrand = $layers['manufacturer'] !== null;
        $hasModel = $layers['model_needles'] !== [];
        $out = [];
        if ($hasFeature && $hasBrand && $hasModel) {
            $out[] = ['name' => self::LEVEL_FAMILY_FEATURE_BRAND_MODEL, 'feature' => true, 'brand' => true, 'model' => true];
        }
        if ($hasFeature && $hasBrand) {
            $out[] = ['name' => self::LEVEL_FAMILY_FEATURE_BRAND, 'feature' => true, 'brand' => true, 'model' => false];
        }
        if ($hasFeature) {
            $out[] = ['name' => self::LEVEL_FAMILY_FEATURE, 'feature' => true, 'brand' => false, 'model' => false];
        }
        if ($layers['family'] !== null || $layers['family_nouns'] !== []) {
            $out[] = ['name' => self::LEVEL_FAMILY, 'feature' => false, 'brand' => false, 'model' => false];
        }

        return $out;
    }

    /**
     * @param  array{
     *     family: ?string,
     *     family_nouns: list<string>,
     *     features: list<string>,
     *     manufacturer: ?string,
     *     model_needles: list<string>
     * }  $layers
     * @param  array{name: string, feature: bool, brand: bool, model: bool}  $attempt
     * @return Collection<int, Product>
     */
    private function query(array $layers, array $attempt, int $limit): Collection
    {
        $builder = Product::query();
        $this->applyFamilyScope($builder, $layers);
        if ($attempt['feature']) {
            $this->applyTokenScope($builder, $layers['features']);
        }
        if ($attempt['brand'] && $layers['manufacturer'] !== null) {
            $brand = $layers['manufacturer'];
            $like = '%'.addcslashes($brand, '%_\\').'%';
            $builder->where(function (Builder $outer) use ($brand, $like): void {
                $outer->where('manufacturer', $brand)
                    ->orWhere('manufacturer', 'like', $like);
            });
        }
        if ($attempt['model'] && $layers['model_needles'] !== []) {
            $this->applyTokenScope($builder, $layers['model_needles']);
        }

        return $builder
            ->orderByRaw("CASE WHEN enrichment_status = 'done' THEN 0 ELSE 1 END")
            ->orderByDesc('enriched_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->values();
    }

    /**
     * @param  array{family: ?string, family_nouns: list<string>}  $layers
     */
    private function applyFamilyScope(Builder $builder, array $layers): void
    {
        $family = $layers['family'];
        $nouns = $layers['family_nouns'];
        if ($family === null && $nouns === []) {
            return;
        }
        $builder->where(function (Builder $outer) use ($family, $nouns): void {
            if ($family !== null) {
                $outer->where('ppe_family', $family);
            }
            foreach ($nouns as $noun) {
                $like = '%'.addcslashes($noun, '%_\\').'%';
                $outer->orWhere('name', 'like', $like)
                    ->orWhere('category', 'like', $like);
            }
        });
    }

    /** @param  list<string>  $tokens */
    private function applyTokenScope(Builder $builder, array $tokens): void
    {
        if ($tokens === []) {
            return;
        }
        $builder->where(function (Builder $outer) use ($tokens): void {
            foreach ($tokens as $token) {
                $like = '%'.addcslashes($token, '%_\\').'%';
                $outer->orWhere('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('search_blob', 'like', $like);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return list<string>
     */
    private function featureTokens(string $query, array $intent, string $scope, ?string $family): array
    {
        $out = [];
        foreach ($this->assortment->catalogNounLikes($scope) as $like) {
            $out[] = $this->fold($like);
        }
        foreach ($this->slang->evidenceNeedles($query) as $needle) {
            $out[] = $this->fold($needle);
        }
        foreach (is_array($intent['search_phrases'] ?? null) ? $intent['search_phrases'] : [] as $phrase) {
            foreach ($this->tokenize((string) $phrase) as $token) {
                $out[] = $token;
            }
        }
        foreach (is_array($intent['constraints'] ?? null) ? $intent['constraints'] : [] as $phrase) {
            foreach ($this->tokenize((string) $phrase) as $token) {
                $out[] = $token;
            }
        }
        foreach ($this->filterType->compactCodes($query) as $code) {
            $fold = $this->fold($code);
            if (mb_strlen($fold) >= 3) {
                $out[] = $fold;
            }
        }

        $brand = $this->fold((string) ($intent['manufacturer'] ?? ''));
        $requested = $this->fold((string) ($intent['manufacturer_requested'] ?? ''));
        $familyNouns = $this->familyNouns($family);
        $stop = ['ochrona', 'przed', 'ciecza', 'olej', 'plyn', 'proste', 'uniwersaln', 'lekki', 'cienki'];
        $clean = [];
        foreach ($out as $token) {
            $token = trim($token);
            if ($token === '' || mb_strlen($token) < 3 || in_array($token, $stop, true)) {
                continue;
            }
            if ($brand !== '' && str_contains($token, $brand)) {
                continue;
            }
            if ($requested !== '' && str_contains($token, $requested)) {
                continue;
            }
            $skip = false;
            foreach ($familyNouns as $noun) {
                if (str_starts_with($token, $noun) || str_starts_with($noun, $token)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            $clean[] = $token;
        }

        return array_values(array_unique($clean));
    }

    /** @return list<string> */
    private function tokenize(string $phrase): array
    {
        $out = [];
        foreach (preg_split('/[\s,;\/|+]+/u', $phrase) ?: [] as $raw) {
            $token = $this->fold($raw);
            if ($token !== '' && (mb_strlen($token) >= 4 || $this->slang->isIndexedTerm($token))) {
                $out[] = $token;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function familyNouns(?string $family): array
    {
        return match ($family) {
            PpeAssortment::FAMILY_GLOVES => ['rekawic', 'glove', 'rukavic'],
            PpeAssortment::FAMILY_FOOTWEAR => ['obuwie', 'buty', 'trzewik', 'kalosz', 'polbut'],
            PpeAssortment::FAMILY_APPAREL => ['kurtk', 'spodn', 'kombinezon', 'kamizelk', 'bluz'],
            PpeAssortment::FAMILY_HEAD => ['czapk', 'czepek', 'kominiark', 'helm', 'kask'],
            PpeAssortment::FAMILY_FACE => ['przylbic', 'oslona'],
            PpeAssortment::FAMILY_EYES => ['okular', 'gogl'],
            PpeAssortment::FAMILY_HEARING => ['nausznik', 'ochronnik', 'sluch'],
            PpeAssortment::FAMILY_RESPIRATORY => ['polmask', 'maska', 'pochlaniacz'],
            PpeAssortment::FAMILY_FALL => ['szelk', 'lonza'],
            PpeAssortment::FAMILY_KNEE => ['nakolann'],
            default => [],
        };
    }

    private function matchesFeatureEvidence(string $query, Product $product): bool
    {
        return $this->slang->matchesEvidence($query, implode(' ', [
            (string) $product->name,
            (string) $product->sku,
            (string) ($product->category ?? ''),
            (string) ($product->description ?? ''),
            (string) ($product->search_blob ?? ''),
        ]));
    }

    private function fold(string $text): string
    {
        $t = mb_strtolower($text);
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

        return trim((string) preg_replace('/[^a-z0-9]+/u', '', strtr($t, $map)));
    }
}
