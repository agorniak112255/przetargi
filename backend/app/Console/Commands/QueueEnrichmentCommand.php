<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Enrichment\ProductEnrichmentService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Pobieranie opisów dla wybranej części katalogu: producent i kategoria z cennika. Naprawa nazw cennika 3M 2026 (14.09)
 * wyczyściła 2849 kart opisanych pod cudzą nazwą; ponad połowa to ścierniwa i taśmy, a do przetargów potrzebne są środki
 * ochrony indywidualnej. Pomija karty z pobranym i ręcznie wpisanym opisem. Bez --apply tylko podgląd.
 */
final class QueueEnrichmentCommand extends Command
{
    protected $signature = 'products:queue-enrichment
        {--manufacturer= : Tylko ten producent}
        {--category= : Tylko ta kategoria z cennika, np. „Środki ochrony indywidualnej”}
        {--limit=0 : Najwyżej tyle kart (0 = wszystkie)}
        {--user= : E-mail użytkownika, na którego idą partie (domyślnie pierwszy administrator)}
        {--apply : Zleć pobieranie (bez tej flagi tylko podgląd)}';

    protected $description = 'Zleca pobranie opisów kartom wybranego producenta i kategorii, które jeszcze nie mają opisu (podgląd bez --apply)';

    public function handle(ProductEnrichmentService $enrichment, AiSettingsService $settings): int
    {
        $manufacturer = trim((string) $this->option('manufacturer'));
        $category = trim((string) $this->option('category'));
        if ($manufacturer === '' && $category === '') {
            $this->error('Podaj --manufacturer= albo --category= — bez filtra polecenie objęłoby cały katalog.');

            return self::FAILURE;
        }
        $limit = max(0, (int) $this->option('limit'));
        $ids = Product::query()
            ->whereNotIn('enrichment_status', [Product::ENRICHMENT_DONE, Product::ENRICHMENT_MANUAL])
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
            ['SKU', 'Nazwa', 'Kategoria', 'Status'],
            Product::query()->whereIn('id', array_slice($ids, 0, 15))->orderBy('id')->get(['sku', 'name', 'category', 'enrichment_status'])
                ->map(static fn (Product $p): array => [(string) $p->sku, mb_substr((string) $p->name, 0, 60), (string) $p->category, (string) $p->enrichment_status])
                ->all(),
        );
        $this->info(sprintf('Do pobrania opisu: %d kart — %d partii po %d (limit z Ustawień AI).', count($ids), $batches, $batchSize));
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
                $result = $enrichment->enqueueProductIds($chunk, $user);
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
