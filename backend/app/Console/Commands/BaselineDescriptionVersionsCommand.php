<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductSourceDocument;
use App\Services\B2b\B2bDescriptionSource;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\ManufacturerProfiles;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\SourceDocumentStore;
use App\Services\Enrichment\SourceIdentity;
use App\Services\PriceListCards;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Wersja bazowa (published, origin legacy_baseline) dla kart z opisem sprzed wersji opisu (etap 1, 08.10.2026). Bez
 * bazy nowy przebieg zapisuje opis zawsze — z bazą gorszy opis zostaje propozycją (DescriptionVersionStore::decide).
 *
 * Werdykt tożsamości bazy liczy się ze strony źródła opisu pobranej teraz (ten sam pobieracz i ta sama reguła co
 * products:source-identity-probe); nieudane pobranie i --no-fetch = werdykt nieznany (baza nie chroni przed
 * słabszą tożsamością, tylko przed mniejszą liczbą dowodów, której stare opisy nie mają). Pomija karty z opisem
 * ze sklepu B2B (to nie opis z pobierania) i opis będący samą nazwą. Tabeli products nie zmienia; domyślnie podgląd,
 * zapis z --apply. Cofnięcie: usunięcie wersji origin legacy_baseline (karta bez innych wersji).
 */
final class BaselineDescriptionVersionsCommand extends Command
{
    private const CHUNK = 100;

    private const PREVIEW_ROWS = 20;

    protected $signature = 'products:baseline-versions
                            {--price-list= : Tylko karty tego cennika (numer cennika)}
                            {--manufacturer= : Tylko karty tego producenta (bez rozróżniania wielkości liter)}
                            {--limit=0 : Najwyżej tyle kart (0 = wszystkie)}
                            {--no-fetch : Bez pobierania stron źródła — werdykt tożsamości nieznany}
                            {--delay=3 : Sekundy odstępu między stronami tej samej witryny (cederroth.com po kilku zapytaniach odpowiada 429)}
                            {--apply : Zapisz wersje bazowe (bez tej flagi tylko podgląd)}';

    protected $description = 'Wersja bazowa opisu (legacy_baseline) dla kart z opisem bez wersji — z werdyktem tożsamości strony źródła; podgląd bez --apply';

    public function handle(
        PriceListCards $cards,
        DescriptionVersionStore $versions,
        B2bDescriptionSource $b2bDescriptions,
    ): int {
        $query = $this->scopedQuery($cards);
        if ($query === null) {
            return self::FAILURE;
        }
        $limit = max(0, (int) $this->option('limit'));
        $fetch = ! $this->option('no-fetch');
        $apply = (bool) $this->option('apply');

        $counts = ['hard' => 0, 'soft' => 0, 'none' => 0, 'unknown' => 0];
        $skipped = ['b2b' => 0, 'no_text' => 0, 'versioned' => 0];
        $written = 0;
        $seen = 0;
        $preview = [];

        $query->chunkById(self::CHUNK, function ($products) use (
            $versions, $b2bDescriptions, $limit, $fetch, $apply, &$counts, &$skipped, &$written, &$seen, &$preview
        ): bool {
            $fromB2b = $b2bDescriptions->productIds($products->pluck('id')->map(static fn ($id): int => (int) $id)->all());
            foreach ($products as $product) {
                /** @var Product $product */
                if ($limit > 0 && $seen >= $limit) {
                    return false;
                }
                if (isset($fromB2b[(int) $product->id])) {
                    $skipped['b2b']++;

                    continue;
                }
                if (! $product->hasDescriptionText()) {
                    $skipped['no_text']++;

                    continue;
                }
                $seen++;
                [$url, $kind] = $this->source($product);
                $identity = $fetch
                    ? $this->judge($product, $url, $kind)
                    : ['verdict' => null, 'reason' => 'bez pobrania strony (--no-fetch)'];
                $verdict = is_string($identity['verdict'] ?? null) ? $identity['verdict'] : null;
                $counts[$verdict ?? 'unknown']++;
                if (count($preview) < self::PREVIEW_ROWS) {
                    $preview[] = [
                        (int) $product->id,
                        (string) $product->sku,
                        mb_substr((string) $product->name, 0, 50),
                        $verdict ?? '—',
                        mb_substr((string) ($identity['reason'] ?? ''), 0, 60),
                        mb_substr((string) ($url ?? ''), 0, 70),
                    ];
                }
                if (! $apply) {
                    continue;
                }
                $saved = DB::transaction(function () use ($versions, $product, $url, $verdict, $identity): bool {
                    // ten sam warunek co przy wyborze — karta, którą w międzyczasie zapisał przebieg, ma już wersję
                    $locked = Product::query()->lockForUpdate()->find($product->id);
                    if ($locked === null || $locked->description !== $product->description
                        || ProductDescriptionVersion::query()->where('product_id', $product->id)->exists()) {
                        return false;
                    }
                    $version = $versions->record($locked, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_LEGACY_BASELINE, [
                        'description' => (string) $locked->description,
                        'enrichment_payload' => is_array($locked->enrichment_payload) ? $locked->enrichment_payload : null,
                        'enrichment_trace' => is_array($locked->enrichment_trace) ? $locked->enrichment_trace : null,
                        'packaging' => $locked->packaging,
                        'primary_source_url' => $url,
                        'identity_verdict' => $verdict,
                        'identity_reason' => (string) ($identity['reason'] ?? ''),
                        'reason' => 'wersja bazowa opisu sprzed wersji (products:baseline-versions)',
                    ]);
                    $page = $identity['page'] ?? null;
                    if (is_array($page) && trim((string) ($page['text'] ?? '')) !== '') {
                        app(SourceDocumentStore::class)->record($locked, [[
                            'url' => (string) ($page['url'] ?? $url),
                            'final_url' => $page['final_url'] ?? null,
                            'text' => (string) $page['text'],
                            'roles' => [ProductSourceDocument::ROLE_DESCRIPTION],
                            'identity' => $identity,
                            'markup_codes' => is_array($page['markup_codes'] ?? null) ? $page['markup_codes'] : [],
                        ]], (int) $version->id);
                    }

                    return true;
                });
                $saved ? $written++ : $skipped['versioned']++;
            }

            return true;
        });

        $this->info(sprintf(
            'Kart z opisem bez wersji: %d — hard %d, soft %d, none %d, nieznany %d. Pominięte: opis z B2B %d, bez opisu (sama nazwa) %d.',
            $seen, $counts['hard'], $counts['soft'], $counts['none'], $counts['unknown'], $skipped['b2b'], $skipped['no_text'],
        ));
        if ($preview !== []) {
            $this->table(['Karta', 'SKU', 'Nazwa', 'Werdykt', 'Powód', 'Źródło'], $preview);
        }
        if (! $apply) {
            $this->line('Podgląd — nic nie zapisano. Zapis: dodaj --apply.');

            return self::SUCCESS;
        }
        $this->info("Zapisano wersji bazowych: {$written}. Pominięte (karta zmieniona w trakcie albo ma już wersję): {$skipped['versioned']}.");

        return self::SUCCESS;
    }

    /** @return Builder<Product>|null */
    private function scopedQuery(PriceListCards $cards): ?Builder
    {
        $query = Product::query()
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->whereNotExists(static fn ($q) => $q->selectRaw('1')
                ->from('product_description_versions')
                ->whereColumn('product_description_versions.product_id', 'products.id'));

        $priceListId = (int) $this->option('price-list');
        if ($priceListId > 0) {
            $priceList = PriceList::query()->find($priceListId);
            if ($priceList === null) {
                $this->error("Nie ma cennika {$priceListId}.");

                return null;
            }
            $query->whereIntegerInRaw('id', $cards->ids($priceList));
        }
        $manufacturer = trim((string) $this->option('manufacturer'));
        if ($manufacturer !== '') {
            $query->whereRaw('LOWER(TRIM(manufacturer)) = ?', [mb_strtolower($manufacturer)]);
        }

        return $query;
    }

    /**
     * Adres i rodzaj źródła opisu: primary_source_url, zapasowo pierwszy z source_urls (opisy sprzed 13.09.2026) —
     * jak products:source-identity-probe.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function source(Product $product): array
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $url = is_string($payload['primary_source_url'] ?? null) && trim($payload['primary_source_url']) !== ''
            ? trim($payload['primary_source_url'])
            : (is_string(($payload['source_urls'] ?? [])[0] ?? null) ? trim($payload['source_urls'][0]) : null);
        $kind = is_string($payload['primary_source_kind'] ?? null) ? $payload['primary_source_kind'] : null;

        return [$url !== '' ? $url : null, $kind];
    }

    /** @var array<string, float> host => czas ostatniego pobrania (microtime) */
    private array $lastFetchAt = [];

    /**
     * Odstęp między stronami tej samej witryny. Bez niego cederroth.com po pierwszej stronie odpowiadał 429, strona
     * przychodziła z czytnika bez mikrodanych i karta dostawała fałszywe „niepotwierdzone” (podgląd 07.10.2026).
     */
    private function paceHost(string $url): void
    {
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $delay = max(0.0, (float) $this->option('delay'));
        // w testach strony są z atrapy (Http::fake) — czekanie tylko wydłużałoby pakiet
        if ($host === '' || $delay <= 0.0 || app()->runningUnitTests()) {
            return;
        }
        $wait = ($this->lastFetchAt[$host] ?? 0.0) + $delay - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastFetchAt[$host] = microtime(true);
    }

    /**
     * Werdykt karty jak w products:source-identity-probe: strona źródła tym samym pobieraczem co wzbogacanie (pamięć
     * stron 24 h); gdy bramka pobierania ją odrzuci, drugi odczyt bez kodu karty — liczy się werdykt, nie bramka.
     *
     * @return array{verdict: ?string, reason: string, key_type?: ?string, key?: ?string, page?: array<string, mixed>|null}
     */
    private function judge(Product $product, ?string $url, ?string $kind): array
    {
        $pages = app(ProductPageFetcher::class);
        $profiles = app(ManufacturerProfiles::class);
        $found = [];
        if ($url !== null && $kind !== 'manual' && $kind !== 'catalog') {
            $this->paceHost($url);
            $raw = $pages->fetchRaw($url);
            $row = ['url' => $url, 'title' => $raw !== null ? ($pages->pageTitles($raw['html'])[0] ?? '') : '', 'snippet' => ''];
            $found = $pages->fetch([$row], (string) $product->sku, 1, [], null)['pages'];
            if ($found === []) {
                $found = $pages->fetch([$row], '', 1, [], null)['pages'];
            }
        }
        $result = app(SourceIdentity::class)->judgeCard($product, $found, $url, $kind, $profiles->for($product)?->catalogs ?? []);

        return [
            'verdict' => $result['verdict'] ?? null,
            'reason' => (string) ($result['reason'] ?? ''),
            'key_type' => $result['key_type'] ?? null,
            'key' => $result['key'] ?? null,
            // pobrana strona źródła — przy --apply jej tekst zostaje przy wersji bazowej (SourceDocumentStore)
            'page' => is_array($found[0] ?? null) ? $found[0] : null,
        ];
    }
}
