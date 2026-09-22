<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DescribeB2bProductFromDatasheetJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDatasheetOnlyDescription;
use App\Services\B2b\B2bDescribesFromDatasheet;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Ponowny opis kart, których obecny opis napisał DescribeB2bProductFromDatasheetJob z karty katalogowej PDF.
 * Powód (22.09.2026): model dopisywał do opisów z ubogich PDF-ów ARTRY ogólną wiedzę („wodoodporna cholewka” przy
 * S1 P, „praca w wysokich temperaturach”, „200 J / 15 kN”); od tej zmiany polecenie jest ostrzejsze, a zdania bez
 * pokrycia w źródłach wycina SourceClaimGuard — opisy zapisane wcześniej trzeba napisać jeszcze raz.
 *
 * Kartę bierze job z $redo (DescribeB2bProductFromDatasheetJob::sources): opis z odciskiem powiązania równym sha1
 * obecnego opisu i śladem b2b_sources.described_at. Opis wpisany ręcznie albo przywrócony ma inny odcisk i zostaje.
 * Karta z limitem odrzuceń dla tych samych źródeł też zostaje (ponowne pytanie modelu nic by nie zmieniło).
 *
 * Tylko konta łącznika B2bDescribesFromDatasheet. Bez --apply podgląd; --apply zleca joby na kolejkę enrich — zapis
 * robi job (compare-and-set), więc karta zmieniona w międzyczasie zostaje nietknięta.
 */
final class B2bRedescribeFromDatasheetsCommand extends Command
{
    protected $signature = 'b2b:redescribe-from-datasheets
        {--account= : Konto B2B (id) z łącznikiem opisu z karty katalogowej PDF}
        {--id=* : Tylko te karty (id produktu)}
        {--apply : Zleć ponowny opis (bez tej flagi tylko podgląd)}';

    protected $description = 'Ponownie opisuje z karty katalogowej PDF karty, których opis napisał wcześniej job opisu z PDF (podgląd bez --apply)';

    public function handle(B2bConnectorRegistry $registry): int
    {
        $account = $this->resolveAccount();
        if ($account === null) {
            return self::FAILURE;
        }
        try {
            $connector = $registry->make($account, 0);
        } catch (RuntimeException $e) {
            $this->error('Łącznik konta #'.$account->id.' niedostępny: '.$e->getMessage());

            return self::FAILURE;
        }
        if (! $connector instanceof B2bDescribesFromDatasheet) {
            $this->error('Konto #'.$account->id.' nie ma łącznika z opisem z karty katalogowej PDF.');

            return self::FAILURE;
        }
        $datasheetOnly = $connector instanceof B2bDatasheetOnlyDescription;
        $accountId = (int) $account->id;
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id')), static fn (int $id): bool => $id > 0));

        $productIds = B2bProductLink::query()
            ->where('b2b_account_id', $accountId)
            ->whereNotNull('product_id')
            ->when($ids !== [], static fn ($q) => $q->whereIn('product_id', $ids))
            ->distinct()
            ->orderBy('product_id')
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $redo = [];
        $blocked = [];
        $changed = 0;
        foreach (array_chunk($productIds, 500) as $chunk) {
            foreach (Product::query()->whereIn('id', $chunk)->orderBy('id')->get() as $product) {
                $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
                $trace = is_array($payload['b2b_sources'] ?? null) ? $payload['b2b_sources'] : [];
                if (trim((string) ($trace['described_at'] ?? '')) === '' || (int) ($trace['b2b_account_id'] ?? 0) !== $accountId) {
                    continue;
                }
                $link = DescribeB2bProductFromDatasheetJob::link((int) $product->id, $accountId);
                if ($link === null || ! DescribeB2bProductFromDatasheetJob::describedByThisJob($product, $link)) {
                    $changed++;

                    continue;
                }
                $start = DescribeB2bProductFromDatasheetJob::sources(
                    $product,
                    $link,
                    DescribeB2bProductFromDatasheetJob::datasheet((int) $product->id, $accountId),
                    $datasheetOnly,
                    true,
                );
                if ($start === null) {
                    $blocked[] = $product;
                } else {
                    $redo[] = $product;
                }
            }
        }

        $this->table(['', 'Kart'], [
            ['Opisane z karty katalogowej PDF (do ponownego opisu)', count($redo)],
            ['Opisane z PDF, ale bez tekstu PDF albo z limitem odrzuceń dla tych samych źródeł', count($blocked)],
            ['Opis z PDF zmieniony później (ręcznie / przywrócony) — zostaje', $changed],
        ]);
        if ($redo !== [] && $this->output->isVerbose()) {
            $this->table(['ID', 'SKU'], array_map(static fn (Product $p): array => [$p->id, $p->sku], $redo));
        }

        if (! $this->option('apply')) {
            $this->line('Podgląd — uruchom z --apply, żeby zlecić ponowny opis.');

            return self::SUCCESS;
        }
        foreach ($redo as $product) {
            DescribeB2bProductFromDatasheetJob::dispatch((int) $product->id, $accountId, $datasheetOnly, true);
        }
        $this->info(sprintf('Zlecono ponowny opis z karty katalogowej PDF: %d kart (kolejka %s).', count($redo), DescribeB2bProductFromDatasheetJob::QUEUE));

        return self::SUCCESS;
    }

    private function resolveAccount(): ?B2bAccount
    {
        $option = trim((string) $this->option('account'));
        if ($option === '' || ! ctype_digit($option)) {
            $this->error('Podaj --account=<id konta B2B>.');

            return null;
        }
        $account = B2bAccount::query()->find((int) $option);
        if ($account === null) {
            $this->error("Nie ma konta B2B #{$option}.");
        }

        return $account;
    }
}
