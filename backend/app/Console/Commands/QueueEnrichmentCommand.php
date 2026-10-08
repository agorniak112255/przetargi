<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDatasheetOnlyDescription;
use App\Services\Enrichment\ModelGroupPlanner;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\PriceListCards;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Pobieranie opisów dla wybranej części katalogu: producent i kategoria z cennika. Naprawa nazw cennika 3M 2026 (14.09)
 * wyczyściła 2849 kart opisanych pod cudzą nazwą; ponad połowa to ścierniwa i taśmy, a do przetargów potrzebne są środki
 * ochrony indywidualnej. Pomija karty z pobranym i ręcznie wpisanym opisem. Bez --apply tylko podgląd.
 *
 * --done-without-description: karty ze statusem „done” i pustym opisem (audyt 22.09.2026: 52 karty ręcznych cenników).
 * Stary kod kończył tak przebieg, w którym zapisało się samo zdjęcie; poprawka 9784d9c ustawia dziś „manual”, ale
 * istniejących kart nie cofnęła, a „done” nie wraca do kolejki. Te karty idą ponownie z force (bez pamięci SKU).
 * Pomija karty powiązane z kontem łącznika B2bDatasheetOnlyDescription (ARTRA): ich opis pisze model wyłącznie
 * z karty katalogowej PDF (DescribeB2bProductFromDatasheetJob), a zwykłe wzbogacanie wzięłoby go z internetu.
 *
 * --enriched-before=DATA (04.10.2026, opisy Ansella i Coby sprzed poprawek jakości z 16.09): ponowne pobranie z force
 * kart opisanych przed datą albo bez daty, w każdym statusie poza kolejką; najstarsze pierwsze, więc --limit daje próbkę
 * z najgorszych. Force omija pamięć SKU — bez niego karta „failed” dostałaby z pamięci ten sam stary opis.
 * --price-list= bierze karty cennika jak „Pobierz opisy” w Cennikach (PriceListCards).
 *
 * --force (etap 2 opisów z cenników, pilotaż modeli): ponowne pobranie z force wskazanych kart (--id=) niezależnie od
 * statusu i daty opisu — karty w kolejce i w toku pomijane; kolejka tnie partię całymi modelami i zleca tylko liderów.
 * Porcje partii także całymi modelami (ModelGroupPlanner): cięcie po kartach rozdzielało model między partie
 * (--price-list= Coby: Orthomat, 11 kart, w dwóch partiach = dwaj liderzy i dwa opisy).
 */
final class QueueEnrichmentCommand extends Command
{
    protected $signature = 'products:queue-enrichment
        {--manufacturer= : Tylko ten producent}
        {--category= : Tylko ta kategoria z cennika, np. „Środki ochrony indywidualnej”}
        {--limit=0 : Najwyżej tyle kart (0 = wszystkie)}
        {--done-without-description : Tylko karty „done” z pustym opisem — ponowne pobranie (filtr producenta/kategorii opcjonalny)}
        {--price-list= : Tylko karty tego cennika (numer; ostatnie wgranie i karty z ceną z tego pliku)}
        {--enriched-before= : Ponowne pobranie (force) kart opisanych przed tą datą albo bez daty, każdy status poza kolejką — od najstarszych}
        {--id=* : Tylko te karty}
        {--force : Ponowne pobranie (force) także kart z gotowym i ręcznym opisem — pilotaż modeli po --id=; karty w kolejce i w toku pomijane}
        {--user= : E-mail użytkownika, na którego idą partie (domyślnie pierwszy administrator)}
        {--apply : Zleć pobieranie (bez tej flagi tylko podgląd)}';

    protected $description = 'Zleca pobranie opisów kartom wybranego producenta i kategorii, które jeszcze nie mają opisu (podgląd bez --apply)';

    public function handle(ProductEnrichmentService $enrichment, AiSettingsService $settings, B2bConnectorRegistry $registry, PriceListCards $cards, ModelGroupPlanner $planner): int
    {
        $manufacturer = trim((string) $this->option('manufacturer'));
        $category = trim((string) $this->option('category'));
        $doneWithoutDescription = (bool) $this->option('done-without-description');
        $onlyIds = array_values(array_filter(array_map('intval', (array) $this->option('id')), static fn (int $id): bool => $id > 0));
        $priceListOption = trim((string) $this->option('price-list'));
        $listIds = null;
        if ($priceListOption !== '') {
            $priceList = PriceList::query()->find((int) $priceListOption);
            if (! $priceList instanceof PriceList) {
                $this->error("Nie ma cennika #{$priceListOption}.");

                return self::FAILURE;
            }
            $listIds = $cards->ids($priceList);
        }
        // Stare opisy (sprzed poprawek jakości) — ponowne pobranie z force, także kart „done” i „manual”; najstarsze
        // pierwsze, żeby --limit wziął próbkę z najgorszych. Karty w kolejce i w toku pomijamy.
        $enrichedBefore = null;
        $enrichedBeforeOption = trim((string) $this->option('enriched-before'));
        if ($enrichedBeforeOption !== '') {
            try {
                $enrichedBefore = CarbonImmutable::parse($enrichedBeforeOption);
            } catch (Throwable) {
                $this->error("Nie rozumiem daty --enriched-before={$enrichedBeforeOption} (np. 2026-09-16).");

                return self::FAILURE;
            }
        }
        $refresh = $enrichedBefore !== null;
        // --force (etap 2, pilotaż modeli po --id=): jak --enriched-before bez daty — każdy status poza kolejką, z force
        $force = (bool) $this->option('force');
        if ($manufacturer === '' && $category === '' && ! $doneWithoutDescription && $listIds === null && $onlyIds === []) {
            $this->error('Podaj --manufacturer= albo --category= (albo --price-list=, --id=, --done-without-description) — bez filtra polecenie objęłoby cały katalog.');

            return self::FAILURE;
        }
        $limit = max(0, (int) $this->option('limit'));
        $datasheetOnlyAccounts = $doneWithoutDescription || $refresh || $force ? $this->datasheetOnlyAccountIds($registry) : [];
        $ids = Product::query()
            ->when(
                $refresh || $force,
                static fn ($q) => $q
                    ->whereNotIn('enrichment_status', [Product::ENRICHMENT_QUEUED, Product::ENRICHMENT_RUNNING])
                    ->when($refresh, static fn ($q) => $q->where(static fn ($w) => $w->whereNull('enriched_at')->orWhere('enriched_at', '<', $enrichedBefore))),
                fn ($q) => $q->when(
                    $doneWithoutDescription,
                    static fn ($q) => $q->where('enrichment_status', Product::ENRICHMENT_DONE)
                        // TRIM w MySQL i SQLite zdejmuje same spacje — znaki nowej linii i tabulatory usuwamy osobno
                        ->where(static fn ($w) => $w->whereNull('description')->orWhereRaw(
                            "TRIM(REPLACE(REPLACE(REPLACE(description, CHAR(10), ''), CHAR(13), ''), CHAR(9), '')) = ''"
                        )),
                    static fn ($q) => $q->whereNotIn('enrichment_status', [Product::ENRICHMENT_DONE, Product::ENRICHMENT_MANUAL]),
                ),
            )
            ->when($datasheetOnlyAccounts !== [], static fn ($q) => $q->whereNotIn(
                'id',
                B2bProductLink::query()->select('product_id')->whereNotNull('product_id')->whereIn('b2b_account_id', $datasheetOnlyAccounts)
            ))
            ->when($manufacturer !== '', static fn ($q) => $q->where('manufacturer', $manufacturer))
            ->when($category !== '', static fn ($q) => $q->where('category', $category))
            ->when($listIds !== null, static fn ($q) => $q->whereIntegerInRaw('id', $listIds === [] ? [0] : $listIds))
            ->when($onlyIds !== [], static fn ($q) => $q->whereIntegerInRaw('id', $onlyIds))
            // NULL przed datami w MySQL i SQLite przy rosnącym porządku
            ->when($refresh, static fn ($q) => $q->orderBy('enriched_at'))
            ->orderBy('id')
            ->when($limit > 0, static fn ($q) => $q->limit($limit))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        if ($ids === []) {
            $this->info('Brak kart do pobrania opisu dla tego filtra.');

            return self::SUCCESS;
        }

        $batchSize = $settings->enrichmentBatchLimit();
        $chunks = $this->chunksByModel($planner, $ids, $batchSize);
        $batches = count($chunks);
        $this->table(
            ['SKU', 'Producent', 'Nazwa', 'Kategoria', 'Status'],
            Product::query()->whereIn('id', array_slice($ids, 0, 15))->orderBy('id')->get(['sku', 'manufacturer', 'name', 'category', 'enrichment_status'])
                ->map(static fn (Product $p): array => [
                    (string) $p->sku,
                    (string) $p->manufacturer,
                    mb_substr((string) $p->name, 0, 60),
                    (string) $p->category,
                    (string) $p->enrichment_status,
                ])
                ->all(),
        );
        $this->info(sprintf(
            'Do pobrania opisu: %d kart%s — %d partii po %d (limit z Ustawień AI).',
            count($ids),
            match (true) {
                $refresh => ' opisanych przed '.$enrichedBefore->format('Y-m-d').' albo bez daty, ponowne pobranie (force)',
                $force => ' — ponowne pobranie (force), także z gotowym opisem',
                $doneWithoutDescription => ' „done” bez opisu, ponowne pobranie',
                default => '',
            },
            $batches,
            $batchSize
        ));
        if (! $this->option('apply')) {
            $this->line('Podgląd — uruchom z --apply, żeby zlecić pobieranie.');

            return self::SUCCESS;
        }

        $user = $this->resolveUser();
        if (! $user instanceof User) {
            return self::FAILURE;
        }
        $queued = 0;
        $batchIds = [];
        foreach ($chunks as $chunk) {
            try {
                // „done” pomija enqueueProductIds bez force — przy kartach bez opisu i starych opisach to właśnie cel;
                // force omija też pamięć SKU, która oddałaby stary opis bez nowego pobrania
                $result = $enrichment->enqueueProductIds($chunk, $user, force: $doneWithoutDescription || $refresh || $force);
            } catch (RuntimeException $e) {
                $this->warn($e->getMessage());

                continue;
            }
            $queued += count($result['product_ids']);
            $batchIds[] = (int) $result['batch']->id;
        }
        $this->info(sprintf('Zlecono pobranie opisu: %d kart w %d partiach (#%s).', $queued, count($batchIds), implode(', #', $batchIds)));

        return self::SUCCESS;
    }

    /**
     * Porcje partii całymi modelami (etap 2): kolejne grupy planera w kolejności listy (z --enriched-before najstarsze
     * pierwsze), każda porcja do limitu kart — grupa, która się nie mieści, zaczyna następną porcję, a pierwsza grupa
     * większa niż limit wchodzi cała (ModelGroupPlanner::sliceByLimit, bez przestawiania grup — reszta to dalsze
     * grupy listy). Karta marki bez grupowania to grupa jednoelementowa, więc dla niej porcje są jak dotąd po kartach.
     *
     * @param  list<int>  $ids
     * @return list<list<int>>
     */
    private function chunksByModel(ModelGroupPlanner $planner, array $ids, int $batchSize): array
    {
        $chunks = [];
        $groups = $planner->groups($ids);
        while ($groups !== []) {
            $slice = $planner->sliceByLimit($groups, $batchSize, oldestFirst: false);
            if ($slice['product_ids'] === []) {
                break;
            }
            $chunks[] = $slice['product_ids'];
            $groups = array_slice($groups, count($slice['groups']));
        }

        return $chunks;
    }

    /**
     * Konta, których łącznik pisze opis wyłącznie z karty katalogowej PDF (B2bDatasheetOnlyDescription — ARTRA).
     * Konto bez działającego łącznika do tej grupy nie należy.
     *
     * @return list<int>
     */
    private function datasheetOnlyAccountIds(B2bConnectorRegistry $registry): array
    {
        $ids = [];
        foreach (B2bAccount::query()->orderBy('id')->get() as $account) {
            try {
                if ($registry->make($account, 0) instanceof B2bDatasheetOnlyDescription) {
                    $ids[] = (int) $account->id;
                }
            } catch (RuntimeException) {
                continue;
            }
        }

        return $ids;
    }

    private function resolveUser(): ?User
    {
        $email = trim((string) $this->option('user'));
        try {
            $user = $email !== ''
                ? User::query()->where('email', $email)->first()
                : User::role('admin')->orderBy('id')->first();
        } catch (Throwable) {
            $user = null;
        }
        if (! $user instanceof User) {
            $this->error($email !== '' ? "Nie ma użytkownika {$email}." : 'Nie ma administratora, na którego można zlecić partie — podaj --user=.');
        }

        return $user instanceof User ? $user : null;
    }
}
