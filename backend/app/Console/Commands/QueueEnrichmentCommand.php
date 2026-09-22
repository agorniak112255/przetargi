<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDatasheetOnlyDescription;
use App\Services\Enrichment\ProductEnrichmentService;
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
 */
final class QueueEnrichmentCommand extends Command
{
    protected $signature = 'products:queue-enrichment
        {--manufacturer= : Tylko ten producent}
        {--category= : Tylko ta kategoria z cennika, np. „Środki ochrony indywidualnej”}
        {--limit=0 : Najwyżej tyle kart (0 = wszystkie)}
        {--done-without-description : Tylko karty „done” z pustym opisem — ponowne pobranie (filtr producenta/kategorii opcjonalny)}
        {--user= : E-mail użytkownika, na którego idą partie (domyślnie pierwszy administrator)}
        {--apply : Zleć pobieranie (bez tej flagi tylko podgląd)}';

    protected $description = 'Zleca pobranie opisów kartom wybranego producenta i kategorii, które jeszcze nie mają opisu (podgląd bez --apply)';

    public function handle(ProductEnrichmentService $enrichment, AiSettingsService $settings, B2bConnectorRegistry $registry): int
    {
        $manufacturer = trim((string) $this->option('manufacturer'));
        $category = trim((string) $this->option('category'));
        $doneWithoutDescription = (bool) $this->option('done-without-description');
        if ($manufacturer === '' && $category === '' && ! $doneWithoutDescription) {
            $this->error('Podaj --manufacturer= albo --category= (albo --done-without-description) — bez filtra polecenie objęłoby cały katalog.');

            return self::FAILURE;
        }
        $limit = max(0, (int) $this->option('limit'));
        $datasheetOnlyAccounts = $doneWithoutDescription ? $this->datasheetOnlyAccountIds($registry) : [];
        $ids = Product::query()
            ->when(
                $doneWithoutDescription,
                static fn ($q) => $q->where('enrichment_status', Product::ENRICHMENT_DONE)
                    // TRIM w MySQL i SQLite zdejmuje same spacje — znaki nowej linii i tabulatory usuwamy osobno
                    ->where(static fn ($w) => $w->whereNull('description')->orWhereRaw(
                        "TRIM(REPLACE(REPLACE(REPLACE(description, CHAR(10), ''), CHAR(13), ''), CHAR(9), '')) = ''"
                    )),
                static fn ($q) => $q->whereNotIn('enrichment_status', [Product::ENRICHMENT_DONE, Product::ENRICHMENT_MANUAL]),
            )
            ->when($datasheetOnlyAccounts !== [], static fn ($q) => $q->whereNotIn(
                'id',
                B2bProductLink::query()->select('product_id')->whereNotNull('product_id')->whereIn('b2b_account_id', $datasheetOnlyAccounts)
            ))
            ->when($manufacturer !== '', static fn ($q) => $q->where('manufacturer', $manufacturer))
            ->when($category !== '', static fn ($q) => $q->where('category', $category))
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
        $batches = (int) ceil(count($ids) / $batchSize);
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
            $doneWithoutDescription ? ' „done” bez opisu, ponowne pobranie' : '',
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
        foreach (array_chunk($ids, $batchSize) as $chunk) {
            try {
                // „done” pomija enqueueProductIds bez force — przy kartach bez opisu to właśnie cel
                $result = $enrichment->enqueueProductIds($chunk, $user, force: $doneWithoutDescription);
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
