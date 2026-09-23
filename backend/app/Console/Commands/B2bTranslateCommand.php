<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\TranslateB2bProductTextJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bForeignLanguageSource;
use App\Services\B2b\B2bKeepsExistingNames;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Zaległe tłumaczenia kart z importu B2B łącznika z obcojęzycznym źródłem (B2bForeignLanguageSource):
 * opisy zapisane przez import przed wprowadzeniem tłumaczenia (decyzja użytkownika 15.09.2026) i nazwy nowych kart.
 * Warunki jak w TranslateB2bProductTextJob — job i tak sprawdza je ponownie przed wywołaniem modelu i przy zapisie.
 *
 * Karty zaimportowane przed dodaniem remote_name dostaną tłumaczenie nazwy dopiero po jednym przebiegu importu,
 * który zapisze nazwę u dostawcy — bez niej nie da się odróżnić nazwy ze źródła od nazwy nadanej ręcznie.
 */
final class B2bTranslateCommand extends Command
{
    private const PREVIEW = 20;

    protected $signature = 'b2b:translate
        {account : ID konta B2B}
        {--limit= : Najwyżej tyle kart}
        {--dry-run : Tylko policz i pokaż}
        {--rejected : Tylko wypisz karty z odrzuconym tłumaczeniem (do ręcznego tłumaczenia)}
        {--redo-identical : Zleć ponownie karty, których „tłumaczenie” jest identyczne z tekstem źródła}
        {--retry-rejected : Zdejmij zapamiętane odrzucenia (po poprawce tłumacza) — karty wracają do tłumaczenia}
        {--redo-names : Kartom z --id przywróć nazwę ze źródła i przetłumacz ją od nowa}
        {--id=* : Tylko te karty (numery kart)}';

    protected $description = 'Zleca tłumaczenie na polski opisów (i nazw nowych kart) z importu B2B; nazwy kart sprzed remote_name — dopiero po jednym przebiegu importu';

    public function handle(B2bConnectorRegistry $registry): int
    {
        $account = B2bAccount::query()->find((int) $this->argument('account'));
        if ($account === null) {
            $this->error('Nie ma konta B2B o ID '.$this->argument('account').'.');

            return self::FAILURE;
        }

        try {
            $connector = $registry->make($account, 0);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (! $connector instanceof B2bForeignLanguageSource) {
            $this->error(sprintf(
                'Łącznik konta #%d (%s) podaje teksty po polsku — nie ma czego tłumaczyć.',
                $account->id,
                (string) $registry->label($connector::key()),
            ));

            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $dryRun = (bool) $this->option('dry-run');
        if ($this->option('rejected')) {
            return $this->listRejected($account);
        }
        if ($this->option('redo-identical')) {
            return $this->redoIdentical($account, $dryRun);
        }
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));
        if ($this->option('redo-names')) {
            return $this->redoNames($account, $ids, $dryRun);
        }
        if ($this->option('retry-rejected')) {
            $rejected = B2bProductLink::query()
                ->where('b2b_account_id', $account->id)
                ->whereNotNull('translation_rejected_hash')
                ->when($ids !== [], static fn ($q) => $q->whereIn('product_id', $ids));
            if ($dryRun) {
                $this->info(sprintf('Odrzucenia do zdjęcia: %d kart · bez zmian (--dry-run)', (clone $rejected)->count()));

                return self::SUCCESS;
            }
            $cleared = $rejected->update([
                'translation_rejected_hash' => null,
                'translation_rejected_reason' => null,
                'translation_rejected_at' => null,
            ]);
            $this->info("Zdjęto odrzucenie z {$cleared} kart — wracają do tłumaczenia.");
        }
        $candidates = $this->candidates((int) $account->id, $connector instanceof B2bKeepsExistingNames);

        $this->info(sprintf(
            'Konto #%d %s · do tłumaczenia: %d kart (opis: %d, nazwa: %d)%s',
            $account->id,
            $account->username,
            $candidates->count(),
            $candidates->where('description', true)->count(),
            $candidates->where('name', true)->count(),
            $dryRun ? ' · bez zlecania (--dry-run)' : '',
        ));

        $selected = $limit !== null ? $candidates->take($limit) : $candidates;

        if ($dryRun) {
            foreach ($selected->take(self::PREVIEW) as $candidate) {
                $this->line(sprintf(
                    '  %s%s%s',
                    $candidate['sku'],
                    $candidate['description'] ? ' · opis' : '',
                    $candidate['name'] ? ' · nazwa' : '',
                ));
            }
            if ($selected->count() > self::PREVIEW) {
                $this->line('  … i '.($selected->count() - self::PREVIEW).' więcej');
            }

            return self::SUCCESS;
        }

        foreach ($selected as $candidate) {
            TranslateB2bProductTextJob::dispatch($candidate['product_id'], (int) $account->id, $candidate['name']);
        }

        $this->info(sprintf(
            'Zlecono tłumaczenie %d kart (kolejka %s; karta, która już czeka w kolejce, nie jest dublowana).',
            $selected->count(),
            TranslateB2bProductTextJob::QUEUE,
        ));

        return self::SUCCESS;
    }

    /**
     * Karty, których tekstu model nie przetłumaczył poprawnie (TranslateB2bProductTextJob::reject) — przebiegi ich
     * nie ponawiają, dopóki dostawca nie zmieni tekstu. Lista do ręcznego tłumaczenia.
     */
    private function listRejected(B2bAccount $account): int
    {
        $links = B2bProductLink::query()
            ->where('b2b_account_id', $account->id)
            // także karta z przetłumaczonym opisem — odrzucona mogła być sama nazwa; udane tłumaczenie czyści odcisk
            ->whereNotNull('translation_rejected_hash')
            ->with('product:id,sku')
            ->orderBy('translation_rejected_at')
            ->get();

        $this->info(sprintf('Konto #%d %s · odrzucone tłumaczenia: %d kart', $account->id, $account->username, $links->count()));
        foreach ($links as $link) {
            $this->line(sprintf(
                '  %s (#%d) · %s · %s',
                (string) $link->product?->sku,
                (int) $link->product_id,
                $link->translation_rejected_at?->format('Y-m-d H:i') ?? '—',
                (string) $link->translation_rejected_reason,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Nazwa przetłumaczona źle (23.09.2026: „FLASH – Kask spawalniczy” zamiast przyłbicy) — karta dostaje z powrotem nazwę
     * ze źródła (b2b_product_links.remote_name tego konta) i zlecenie tłumaczenia nazwy od nowa. Tylko karty wskazane
     * przez --id: tłumaczenia nie da się odróżnić od nazwy nadanej ręcznie, więc wybór należy do człowieka.
     *
     * @param  list<int>  $ids
     */
    private function redoNames(B2bAccount $account, array $ids, bool $dryRun): int
    {
        if ($ids === []) {
            $this->error('Podaj karty: --id=… (numery kart, którym przetłumaczyć nazwę od nowa).');

            return self::FAILURE;
        }
        $links = B2bProductLink::query()
            ->where('b2b_account_id', $account->id)
            ->whereIn('product_id', $ids)
            ->with('product')
            ->orderBy('id')
            ->get()
            ->unique('product_id');

        $this->info(sprintf('Konto #%d %s · nazwy od nowa: %d kart%s', $account->id, $account->username, $links->count(), $dryRun ? ' · bez zmian (--dry-run)' : ''));
        $missing = array_diff($ids, $links->pluck('product_id')->map(static fn (mixed $id): int => (int) $id)->all());
        if ($missing !== []) {
            $this->warn('Bez powiązania z tym kontem (pominięte): '.implode(', ', $missing));
        }
        $done = 0;
        foreach ($links as $link) {
            $product = $link->product;
            $source = trim((string) $link->remote_name);
            if ($product === null || $source === '') {
                $this->warn(sprintf('  #%d: brak nazwy u dostawcy — pominięta', (int) $link->product_id));

                continue;
            }
            $this->line(sprintf('  %s (#%d): %s → %s', (string) $product->sku, (int) $product->id, (string) $product->name, $source));
            if ($dryRun) {
                continue;
            }
            // przez model: indeks tekstowy i wektor przeliczą się z nazwy; tłumaczenie nadpisze ją po polsku
            $product->update(['name' => $source]);
            $link->update(['translation_rejected_hash' => null, 'translation_rejected_reason' => null, 'translation_rejected_at' => null]);
            TranslateB2bProductTextJob::dispatch((int) $product->id, (int) $account->id, true);
            $done++;
        }
        if (! $dryRun) {
            $this->info(sprintf('Zlecono tłumaczenie nazw %d kart (kolejka %s).', $done, TranslateB2bProductTextJob::QUEUE));
        }

        return self::SUCCESS;
    }

    /**
     * Karty zapisane jako przetłumaczone, choć opis jest dokładnie tekstem źródła (source_description_hash =
     * description_hash = sha1(opisu karty)) — model oddał tekst bez tłumaczenia, a walidacja sprzed 23.09.2026 to
     * przepuszczała (HUSTLN50E). Zdjęcie znacznika tłumaczenia przywraca kartę do kolejki; tekst po polsku model
     * odda bez zmian i zostanie przyjęty ponownie, angielski zostanie przetłumaczony albo odrzucony (--rejected).
     */
    private function redoIdentical(B2bAccount $account, bool $dryRun): int
    {
        $links = B2bProductLink::query()
            ->where('b2b_account_id', $account->id)
            ->whereNotNull('source_description_hash')
            ->whereColumn('source_description_hash', 'description_hash')
            ->with('product:id,sku,description')
            ->get()
            ->filter(static fn (B2bProductLink $link): bool => $link->product !== null
                && hash_equals((string) $link->description_hash, sha1((string) $link->product->description)))
            ->unique('product_id')
            ->values();

        $this->info(sprintf(
            'Konto #%d %s · „tłumaczenie” identyczne ze źródłem: %d kart%s',
            $account->id,
            $account->username,
            $links->count(),
            $dryRun ? ' · bez zmian (--dry-run)' : '',
        ));
        foreach ($links as $link) {
            $this->line(sprintf('  %s (#%d)', (string) $link->product?->sku, (int) $link->product_id));
        }
        if ($dryRun || $links->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($links as $link) {
            $link->source_description_hash = null;
            $link->save();
            TranslateB2bProductTextJob::dispatch((int) $link->product_id, (int) $account->id, false);
        }
        $this->info(sprintf('Zlecono ponowne tłumaczenie %d kart (kolejka %s).', $links->count(), TranslateB2bProductTextJob::QUEUE));

        return self::SUCCESS;
    }

    /**
     * Sha1 liczone w PHP — tak samo jak przy imporcie (sha1 dokładnego tekstu opisu).
     *
     * @return Collection<int, array{product_id: int, sku: string, description: bool, name: bool}>
     */
    private function candidates(int $accountId, bool $keepsNames): Collection
    {
        $candidates = [];
        B2bProductLink::query()
            // bez filtra po source_description_hash — przy przetłumaczonym opisie nazwa ze źródła wciąż czeka (pending)
            ->where('b2b_account_id', $accountId)
            ->with('product:id,sku,name,description')
            ->chunkById(500, function (Collection $links) use (&$candidates, $keepsNames): void {
                foreach ($links as $link) {
                    $product = $link->product;
                    if ($product === null || isset($candidates[$product->id])) {
                        continue;
                    }

                    $pending = TranslateB2bProductTextJob::pending($product, $link, $keepsNames);
                    if ($pending['description'] || $pending['name']) {
                        $candidates[$product->id] = [
                            'product_id' => (int) $product->id,
                            'sku' => (string) $product->sku,
                            'description' => $pending['description'],
                            'name' => $pending['name'],
                        ];
                    }
                }
            });

        return collect(array_values($candidates));
    }
}
