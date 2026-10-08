<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\Product;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDescriptionSupplement;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Uzupełnianie krótkich opisów z B2B ze stron z opisami konta (decyzja użytkownika 28.09.2026): karta konta z opisem
 * z B2B krótszym niż próg konta jest szukana najpierw na stronach konta, a AI pisze opis z tekstu B2B i znalezionych
 * stron (B2bDescriptionSupplement, SupplementB2bDescriptionJob). To samo co przycisk „Uzupełnij krótkie opisy” przy
 * koncie; bez --apply tylko podgląd.
 */
final class B2bSupplementDescriptionsCommand extends Command
{
    private const SAMPLE_ROWS = 10;

    protected $signature = 'b2b:supplement-descriptions
        {--account= : Jedno konto B2B — id albo klucz łącznika (domyślnie wszystkie konta ze stronami z opisami)}
        {--all : Także karty już próbowane (bez tej flagi tylko karty bez ostatecznej próby dla obecnego tekstu i stron)}
        {--undo-ungated : Cofnij opisy uzupełnione przed bramką wariantu (28.09.2026) i zleć je ponownie}
        {--undo=* : Cofnij uzupełnione opisy tych kart (numery kart, z jednym --account) i zleć je ponownie}
        {--reason= : Z --undo: powód cofnięcia zapisany w śladzie karty}
        {--keep-b2b : Z --undo: nie zlecaj ponownie — karta zostaje z opisem z B2B}
        {--apply : Zleć uzupełnianie (bez tej flagi tylko podgląd)}';

    protected $description = 'Zleca uzupełnianie krótkich opisów z B2B ze stron z opisami konta (podgląd bez --apply)';

    public function handle(B2bDescriptionSupplement $supplement): int
    {
        $accounts = $this->resolveAccounts();
        if ($accounts === null) {
            return self::FAILURE;
        }
        if ($accounts->isEmpty()) {
            $this->info('Żadne konto B2B nie ma stron z opisami.');

            return self::SUCCESS;
        }

        $onlyUntried = ! (bool) $this->option('all');
        $apply = (bool) $this->option('apply');

        $undo = $this->undoIds();
        if ($undo !== []) {
            if ($accounts->count() !== 1 || trim((string) $this->option('account')) === '') {
                $this->error('--undo wymaga jednego konta: --account=<id albo klucz łącznika>.');

                return self::FAILURE;
            }
            $reason = trim((string) $this->option('reason'));
            if ($reason === '') {
                $this->error('--undo wymaga powodu: --reason="…" (zapisywany w śladzie karty).');

                return self::FAILURE;
            }
            $this->undoCards($supplement, $accounts->first(), $undo, $reason, (bool) $this->option('keep-b2b'), $apply);
            if (! $apply) {
                $this->line('Podgląd — nic nie cofnięto. Zapis: dodaj --apply.');
            }

            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            $label = "#{$account->id} {$account->username} (".($account->connector ?: '?').')';
            if ($account->enrichmentHosts() === []) {
                $this->warn("{$label}: konto nie ma stron z opisami — pomijam.");

                continue;
            }

            $this->line("{$label}: strony ".implode(', ', $account->enrichmentHosts())
                .", próg {$account->enrichmentMinChars()} znaków");

            if ($this->option('undo-ungated')) {
                $this->undoUngated($supplement, $account, $apply);

                continue;
            }

            if ($apply) {
                $result = $supplement->queue($account, null, $onlyUntried);
                $this->info("  kart do uzupełnienia: {$result['candidates']}, zlecono: {$result['queued']}");

                continue;
            }

            $ids = $supplement->candidateIds($account, $onlyUntried);
            $this->info('  kart do uzupełnienia: '.count($ids));
            if ($ids === []) {
                continue;
            }

            $sample = Product::query()
                ->whereIn('id', array_slice($ids, 0, self::SAMPLE_ROWS))
                ->orderBy('id')
                ->get(['id', 'sku', 'description']);
            $this->table(
                ['id', 'SKU', 'długość opisu'],
                $sample->map(static fn (Product $product): array => [
                    $product->id,
                    (string) $product->sku,
                    B2bDescriptionSupplement::plainLength($product->description),
                ])->all(),
            );
        }

        if (! $apply) {
            $this->line('Podgląd — nic nie zlecono. Zlecenie: dodaj --apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Opisy uzupełnione przed bramką wariantu (strony innych wariantów w źródłach): podgląd listy albo cofnięcie
     * (tekst z B2B wraca na kartę) i ponowne zlecenie tych kart.
     */
    private function undoUngated(B2bDescriptionSupplement $supplement, B2bAccount $account, bool $apply): void
    {
        $result = $supplement->undoUngated($account, $apply);
        $this->info('  opisów uzupełnionych przed bramką wariantu: '.count($result['candidates']));
        if ($result['candidates'] !== []) {
            $this->table(
                ['id', 'SKU'],
                Product::query()->whereIn('id', array_slice($result['candidates'], 0, 50))->orderBy('id')->get(['id', 'sku'])
                    ->map(static fn (Product $product): array => [$product->id, (string) $product->sku])->all(),
            );
        }
        foreach ($result['skipped'] as $productId => $reason) {
            $this->warn("  karta #{$productId} pominięta: {$reason}");
        }
        if (! $apply) {
            return;
        }
        $this->info('  cofnięto: '.count($result['undone']));
        if ($result['undone'] !== []) {
            $queued = $supplement->queue($account, $result['undone'], true);
            $this->info("  zlecono ponownie: {$queued['queued']}");
        }
    }

    /**
     * Uzupełnione opisy wskazanych kart: podgląd listy albo cofnięcie (tekst z B2B wraca na kartę) i — bez --keep-b2b —
     * ponowne zlecenie, już z obecnymi bramkami stron i norm.
     *
     * @param  list<int>  $ids
     */
    private function undoCards(B2bDescriptionSupplement $supplement, B2bAccount $account, array $ids, string $reason, bool $keepB2b, bool $apply): void
    {
        $result = $supplement->undoCards($account, $ids, $reason, $keepB2b, $apply);
        $this->info('  opisów uzupełnionych do cofnięcia: '.count($result['candidates']).' z '.count($ids));
        if ($result['candidates'] !== []) {
            $this->table(
                ['id', 'SKU', 'pole norm'],
                Product::query()->whereIn('id', $result['candidates'])->orderBy('id')->get(['id', 'sku', 'norms'])
                    ->map(static fn (Product $product): array => [$product->id, (string) $product->sku, (string) ($product->norms ?? '')])->all(),
            );
        }
        foreach ($result['skipped'] as $productId => $skip) {
            $this->warn("  karta #{$productId} pominięta: {$skip}");
        }
        if (! $apply) {
            return;
        }
        $this->info('  cofnięto: '.count($result['undone']));
        if ($result['undone'] !== [] && ! $keepB2b) {
            // wskazane karty — także te z wyczerpanym limitem nieudanych prób
            $queued = $supplement->queue($account, $result['undone'], false);
            $this->info("  zlecono ponownie: {$queued['queued']}");
        }
    }

    /**
     * Numery kart z --undo (powtarzane albo po przecinku).
     *
     * @return list<int>
     */
    private function undoIds(): array
    {
        $ids = [];
        foreach ((array) $this->option('undo') as $value) {
            foreach (preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                if (ctype_digit($part) && (int) $part > 0) {
                    $ids[] = (int) $part;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return Collection<int, B2bAccount>|null null = błąd (komunikat już wypisany)
     */
    private function resolveAccounts(): ?Collection
    {
        $account = trim((string) $this->option('account'));
        if ($account === '') {
            return B2bAccount::query()
                ->orderBy('id')
                ->get()
                ->filter(static fn (B2bAccount $a): bool => $a->enrichmentHosts() !== [])
                ->values();
        }

        $query = B2bAccount::query()->orderBy('id');
        if (ctype_digit($account)) {
            $query->where('id', (int) $account);
        } else {
            $query->where('connector', $account);
        }
        $accounts = $query->get();
        if ($accounts->isEmpty()) {
            $this->error("Nie ma konta B2B „{$account}” — podaj id konta albo klucz łącznika (".implode(', ', app(B2bConnectorRegistry::class)->keys()).').');

            return null;
        }

        return $accounts;
    }
}
