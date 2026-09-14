<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\User;
use RuntimeException;
use Throwable;

/**
 * Jeden przebieg synchronizacji konta: status na koncie (running/ok/failed) + wynik.
 */
final class B2bAccountSyncRunner
{
    public function __construct(
        private readonly B2bConnectorRegistry $connectors,
        private readonly B2bCatalogSync $sync,
    ) {}

    /**
     * @param  (callable(string): void)|null  $onProduct
     * @return array<string, mixed> wynik B2bCatalogSync::run
     */
    public function run(
        B2bAccount $account,
        ?int $limit = null,
        bool $dryRun = false,
        ?bool $withImages = null,
        int $delayMs = 150,
        ?callable $onProduct = null,
    ): array {
        if (! $dryRun) {
            $account->forceFill([
                'last_sync_status' => 'running',
                'last_sync_started_at' => now(),
                'last_sync_finished_at' => null,
                'last_sync_message' => null,
                'sync_requested_at' => null,
            ])->save();
        }

        try {
            $userId = $account->updated_by ?? $account->created_by;
            $user = $userId !== null ? User::query()->find($userId) : null;
            if ($user === null) {
                throw new RuntimeException('Konto B2B nie ma użytkownika, który mógłby być zapisany jako importujący — zapisz konto ponownie.');
            }

            $result = $this->sync->run(
                $account,
                $this->connectors->make($account, $delayMs),
                $user,
                limit: $limit,
                dryRun: $dryRun,
                withImages: $withImages ?? (bool) ($account->sync_images ?? true),
                onProduct: $onProduct,
            );
        } catch (Throwable $e) {
            if (! $dryRun) {
                $account->forceFill([
                    'last_sync_status' => 'failed',
                    'last_sync_finished_at' => now(),
                    'last_sync_message' => mb_substr($e->getMessage(), 0, 2000),
                ])->save();
            }

            throw $e;
        }

        if (! $dryRun) {
            $account->forceFill([
                'last_sync_status' => 'ok',
                'last_sync_finished_at' => now(),
                'last_sync_message' => $this->summary($result),
                'last_price_list_id' => $result['price_list']?->id ?? $account->last_price_list_id,
            ])->save();
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function summary(array $result): string
    {
        $text = sprintf(
            'W B2B: %d · sprawdzone: %d · nowe: %d · zaktualizowane: %d · bez zmian: %d · pominięte: %d · zmiany cen: %d · nowe opisy: %d · zdjęcia: %d',
            $result['total_remote'],
            $result['seen'],
            $result['created'],
            $result['updated'],
            $result['unchanged'],
            $result['skipped'],
            $result['prices_changed'],
            $result['descriptions'],
            $result['images'],
        );
        if ($result['errors'] !== []) {
            $text .= "\nPrzykładowe problemy: ".implode('; ', array_slice($result['errors'], 0, 3));
        }

        return mb_substr($text, 0, 2000);
    }
}
