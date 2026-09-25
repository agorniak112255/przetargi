<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Vector\QdrantClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Wektory w Qdrant, których karty nie ma już w bazie. Do 25.09.2026 (26d91fa) scalanie kart kasowało kartę bez jej
 * wektora; wektor zostaje też wtedy, gdy Qdrant nie odpowie przy usuwaniu karty (błąd tylko w logu). Taki punkt nie
 * trafia do wyników (wyszukiwanie pomija numery bez karty), ale zajmuje miejsce w puli wektorowej (150) i w fuzji
 * rang — zwykle tuż przy karcie tego samego wyrobu, która została — i wypycha z puli inną kartę.
 * Produkcja 25.09.2026: 90 punktów bez karty na 48 442 (UVEX 39, P&M 36, ANRO 11, 3M 3, HexArmor 1 — w próbce karty
 * rozmiarów, najpewniej scalone przed 26d91fa).
 *
 * Bez --apply tylko podgląd. Z --apply kasuje porcjami (--chunk), a każdą porcję tuż przed kasowaniem sprawdza w bazie
 * jeszcze raz — punkt karty, która jest w bazie, zostaje. Gdy bez karty jest więcej niż MAX_ORPHAN_SHARE punktów,
 * --apply odmawia bez --force: to raczej kolekcja innej bazy (adres Qdrant, plik .env) niż resztki po kasowaniu kart,
 * a skasowany wektor istniejącej karty wraca dopiero po reindeksie z --force (karta ma embedding_hash).
 */
final class PruneOrphanVectorsCommand extends Command
{
    /** Punkty jednej strony przeglądu kolekcji. */
    private const PAGE = 1000;

    /** Ile punktów bez karty pokazuje tabela. */
    private const SAMPLE = 20;

    /** Taki odsetek punktów bez karty to już nie resztki (produkcja 25.09.2026: 0,19%). */
    private const MAX_ORPHAN_SHARE = 0.1;

    protected $signature = 'products:prune-orphan-vectors
        {--apply : Skasuj punkty bez karty (bez tej flagi tylko podgląd)}
        {--chunk=500 : Ile punktów kasuje jedno żądanie do Qdrant}
        {--force : Z --apply kasuj także wtedy, gdy bez karty jest ponad 10% punktów}';

    protected $description = 'Kasuje z Qdrant wektory kart, których nie ma już w bazie (podgląd bez --apply)';

    public function handle(QdrantClient $qdrant): int
    {
        if (! $qdrant->isConfigured()) {
            $this->warn('Wyszukiwanie wektorowe wyłączone lub brak qdrant_url — nic nie robimy.');

            return self::SUCCESS;
        }

        $collection = $qdrant->collection();
        $scanned = 0;
        /** @var list<int> $orphans */
        $orphans = [];
        /** @var array<string, int> $manufacturers */
        $manufacturers = [];
        /** @var list<list<int|string>> $sample */
        $sample = [];
        $offset = null;

        try {
            do {
                $page = $qdrant->scroll($offset, self::PAGE, true);
                $payloads = [];
                foreach ($page['points'] as $point) {
                    $payloads[$point['id']] = $point['payload'];
                }
                $scanned += count($payloads);

                foreach ($this->withoutCard(array_keys($payloads)) as $id) {
                    $orphans[] = $id;
                    $payload = $payloads[$id];
                    $manufacturer = trim((string) ($payload['manufacturer'] ?? ''));
                    $key = $manufacturer !== '' ? $manufacturer : '(brak)';
                    $manufacturers[$key] = ($manufacturers[$key] ?? 0) + 1;
                    if (count($sample) < self::SAMPLE) {
                        $sample[] = [
                            $id,
                            (string) ($payload['sku'] ?? ''),
                            $manufacturer,
                            mb_substr((string) ($payload['name'] ?? ''), 0, 60),
                        ];
                    }
                }

                // Qdrant zawsze przesuwa przegląd — ten sam punkt startowy zapętliłby polecenie
                if ($page['next_offset'] !== null && $page['next_offset'] === $offset) {
                    throw new RuntimeException('Qdrant podał ten sam punkt startowy następnej strony ('.$offset.').');
                }
                $offset = $page['next_offset'];
            } while ($offset !== null);
        } catch (Throwable $e) {
            $this->error('Przegląd kolekcji '.$collection.' przerwany: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Kolekcja '.$collection.': punktów '.$scanned.', bez karty w bazie: '.count($orphans).'.');
        if ($orphans === []) {
            return self::SUCCESS;
        }

        arsort($manufacturers);
        $parts = [];
        foreach ($manufacturers as $name => $count) {
            $parts[] = $name.' '.$count;
        }
        $this->line('Producenci wg danych punktu: '.implode(', ', $parts).'.');
        $this->table(['Punkt = nr karty', 'SKU', 'Producent', 'Nazwa'], $sample);
        if (count($orphans) > count($sample)) {
            $this->line('W tabeli pierwsze '.count($sample).' z '.count($orphans).'.');
        }

        $tooMany = count($orphans) > self::MAX_ORPHAN_SHARE * $scanned;
        if ($tooMany) {
            $this->warn('Bez karty jest '.self::percent(count($orphans) / $scanned).' punktów, więcej niż '
                .self::percent(self::MAX_ORPHAN_SHARE).' — to wygląda na kolekcję innej bazy. Sprawdź adres Qdrant '
                .'w Ustawieniach AI i plik .env.');
        }

        if (! $this->option('apply')) {
            $this->line('Podgląd — nic nie skasowano. Kasowanie: products:prune-orphan-vectors --apply');

            return self::SUCCESS;
        }

        if ($tooMany && ! $this->option('force')) {
            $this->error('Nic nie skasowano. Jeśli to na pewno kolekcja tej bazy, dodaj --force.');

            return self::FAILURE;
        }

        return $this->prune($qdrant, $collection, $orphans);
    }

    private static function percent(float $share): string
    {
        return number_format($share * 100, 1, ',', '').'%';
    }

    /**
     * @param  list<int>  $orphans
     */
    private function prune(QdrantClient $qdrant, string $collection, array $orphans): int
    {
        /** @var list<int> $deleted */
        $deleted = [];
        $kept = 0;

        try {
            foreach (array_chunk($orphans, max(1, (int) $this->option('chunk'))) as $chunk) {
                // od przeglądu baza mogła się zmienić — kasujemy tylko punkty, których karty dalej nie ma
                $ids = $this->withoutCard($chunk);
                $kept += count($chunk) - count($ids);
                if ($ids === []) {
                    continue;
                }

                $qdrant->deleteMany($ids);
                array_push($deleted, ...$ids);
            }
        } catch (Throwable $e) {
            $this->logDeleted($collection, $deleted);
            $this->error('Kasowanie przerwane (skasowano dotąd: '.count($deleted).'): '.$e->getMessage());

            return self::FAILURE;
        }

        $this->logDeleted($collection, $deleted);
        $this->info('Skasowano punktów bez karty: '.count($deleted).' (kolekcja '.$collection.').'
            .($kept > 0 ? ' Pominięte, bo karta jest w bazie: '.$kept.'.' : ''));

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int> numery, których nie ma w tabeli products, w kolejności wejścia
     */
    private function withoutCard(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $existing = array_flip(Product::query()->whereIn('id', $ids)->pluck('id')->map(static fn ($id): int => (int) $id)->all());

        return array_values(array_filter($ids, static fn (int $id): bool => ! isset($existing[$id])));
    }

    /**
     * @param  list<int>  $pointIds
     */
    private function logDeleted(string $collection, array $pointIds): void
    {
        if ($pointIds === []) {
            return;
        }

        Log::info('Orphan product vectors deleted', [
            'collection' => $collection,
            'deleted' => count($pointIds),
            'point_ids' => $pointIds,
        ]);
    }
}
