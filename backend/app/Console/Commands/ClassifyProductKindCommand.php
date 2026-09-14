<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Catalog\ProductKindClassifier;
use App\Services\Enrichment\ProductEnrichmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Podgląd rodzaju produktu rozpoznanego modelem obok rodziny z reguł (tylko odczyt). Służy do znajdowania luk w regułach
 * rodziny PPE: brakujące rzeczowniki i normy trafiają do PpeAssortment z testami, potem products:rebuild-search-index.
 * Rodzina z modelu nie jest zapisywana — zapisana rodzina działa jak bramka dopasowania, a zła ukryłaby kartę.
 */
final class ClassifyProductKindCommand extends Command
{
    protected $signature = 'products:classify-kind
        {--manufacturer= : Tylko ten producent}
        {--sku=* : Tylko te karty (SKU)}
        {--all : Także karty, którym reguły już dały rodzinę (porównanie reguł z modelem)}
        {--limit=40 : Najwyżej tyle kart}
        {--enrich : Zleć pobranie opisu kartom ŚOI rozpoznanym tylko z wiedzy modelu (rodzina nie jest zapisywana)}
        {--user= : E-mail użytkownika, na którego idzie partia pobierania opisów (domyślnie pierwszy administrator)}';

    protected $description = 'Podgląd: rodzaj produktu rozpoznany modelem AI obok rodziny z reguł, z cytatem z karty (nic nie zapisuje)';

    public function handle(ProductKindClassifier $classifier, AiSettingsService $settings): int
    {
        if (! $settings->isReady()) {
            $this->error('AI nie jest skonfigurowane — podgląd wymaga modelu.');

            return self::FAILURE;
        }

        $limit = max(1, min(2000, (int) $this->option('limit')));
        $skus = array_values(array_filter(array_map('strval', (array) $this->option('sku')), static fn (string $sku): bool => trim($sku) !== ''));
        $manufacturer = trim((string) $this->option('manufacturer'));
        $products = Product::query()
            ->when($skus !== [], static fn ($q) => $q->whereIn('sku', $skus))
            ->when($skus === [] && ! $this->option('all'), static fn ($q) => $q->whereNull('ppe_family'))
            ->when($manufacturer !== '', static fn ($q) => $q->where('manufacturer', $manufacturer))
            ->orderBy('id')
            ->limit($limit)
            ->get();
        if ($products->isEmpty()) {
            $this->info('Brak kart do sprawdzenia.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Sprawdzam %d kart modelem (paczki po %d, nic nie zapisuję)…', $products->count(), ProductKindClassifier::BATCH));
        $results = $classifier->classify($products);

        $counts = array_fill_keys(['zgodne', 'różne', 'nowa z danych', 'nowa z wiedzy modelu', 'poza ŚOI', 'nieznane', 'bez odpowiedzi'], 0);
        $rows = [];
        foreach ($products as $product) {
            $rule = is_string($product->ppe_family) && $product->ppe_family !== '' ? $product->ppe_family : null;
            $result = $results[(int) $product->id] ?? null;
            $status = $this->status($rule, $result);
            $counts[$status]++;
            $rows[] = [
                (string) $product->sku,
                mb_substr((string) $product->name, 0, 40),
                $rule ?? '—',
                $result['family'] ?? '—',
                mb_substr((string) ($result['type'] ?? ''), 0, 30),
                $this->basisLabel($result),
                mb_substr((string) ($result['evidence'] ?? ''), 0, 50),
                $result === null ? '' : ($result['evidence_matches_rule'] ? 'tak' : 'nie'),
                $status,
            ];
        }
        $this->table(['SKU', 'Nazwa', 'Reguły', 'Model', 'Typ', 'Podstawa', 'Cytat', 'Cytat = wzorzec', 'Wynik'], $rows);
        $this->line('Podsumowanie: '.implode(' · ', array_map(
            static fn (string $label, int $count): string => $label.' '.$count,
            array_keys($counts),
            $counts,
        )));
        if ($this->option('enrich')) {
            return $this->queueEnrichment($products, $results);
        }

        return self::SUCCESS;
    }

    /**
     * Karty ŚOI rozpoznane tylko z wiedzy modelu (nazwa to kod, brak dowodu w danych) idą do pobrania opisu; reguły rodziny
     * rozpoznają je potem z opisu, z dowodem. Rodzina z wiedzy modelu nadal nie jest zapisywana.
     *
     * @param  Collection<int, Product>  $products
     * @param  array<int, array{family: ?string, ppe: string, basis: string}>  $results
     */
    private function queueEnrichment(Collection $products, array $results): int
    {
        $ids = $products
            ->filter(static function (Product $product) use ($results): bool {
                $result = $results[(int) $product->id] ?? null;

                return $result !== null
                    && $result['ppe'] === ProductKindClassifier::PPE_YES
                    && $result['family'] !== null
                    && $result['basis'] === ProductKindClassifier::BASIS_MODEL
                    && ! in_array($product->enrichment_status, [Product::ENRICHMENT_DONE, Product::ENRICHMENT_MANUAL], true);
            })
            ->map(static fn (Product $product): int => (int) $product->id)
            ->values()
            ->all();
        if ($ids === []) {
            $this->info('Nie ma kart do pobrania opisu (ŚOI rozpoznane tylko z wiedzy modelu i jeszcze bez opisu).');

            return self::SUCCESS;
        }

        $email = trim((string) $this->option('user'));
        try {
            $user = $email !== ''
                ? User::query()->where('email', $email)->first()
                : User::role('admin')->orderBy('id')->first();
        } catch (Throwable) {
            $user = null;
        }
        if (! $user instanceof User) {
            $this->error($email !== '' ? "Nie ma użytkownika {$email}." : 'Nie ma administratora, na którego można zlecić partię — podaj --user=.');

            return self::FAILURE;
        }

        try {
            $queued = app(ProductEnrichmentService::class)->enqueueProductIds($ids, $user);
        } catch (RuntimeException $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS;
        }
        $this->info(sprintf('Zlecono pobranie opisu: %d kart (partia #%d).', count($queued['product_ids']), (int) $queued['batch']->id));

        return self::SUCCESS;
    }

    /** @param array{family: ?string, ppe: string, basis: string}|null $result */
    private function status(?string $rule, ?array $result): string
    {
        if ($result === null) {
            return 'bez odpowiedzi';
        }
        if ($result['ppe'] === ProductKindClassifier::PPE_NO) {
            return 'poza ŚOI';
        }
        if ($result['family'] === null) {
            return 'nieznane';
        }
        if ($rule === null) {
            return $result['basis'] === ProductKindClassifier::BASIS_DATA ? 'nowa z danych' : 'nowa z wiedzy modelu';
        }

        return $rule === $result['family'] ? 'zgodne' : 'różne';
    }

    /** @param array{basis: string, evidence: ?string, evidence_confirmed: bool}|null $result */
    private function basisLabel(?array $result): string
    {
        if ($result === null) {
            return '';
        }
        if ($result['evidence'] !== null && ! $result['evidence_confirmed']) {
            return 'cytat spoza karty';
        }

        return $result['basis'] === ProductKindClassifier::BASIS_DATA ? 'dane' : 'wiedza modelu';
    }
}
