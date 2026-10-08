<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductEnrichmentBatch;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\RunEffectsReverter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wycofanie opisów zapisanych przez partię pobierania (etap 2 opisów z cenników, plan §4 krok 7): każda karta
 * z wersją published z tej partii (origin enrichment albo model_shared), której opis wciąż jest na karcie, wraca do
 * poprzedniego opublikowanego opisu — nowa wersja published (origin restore) przez DescriptionVersionStore::publish,
 * wersja z partii zostaje w historii jako superseded (da się ją przywrócić w karcie: „Przywróć wersję”). Razem
 * z opisem cofane są skutki przebiegu partii na karcie (RunEffectsReverter, jak przy „Odrzuć” w przeglądzie): zdjęcia
 * i dokumenty z internetu dodane przez ten przebieg (tylko z listy wersji, nic cudzego — z dysku też) oraz normy
 * producenta przepisane przez przebieg, o ile nikt ich potem nie zmienił. Plików, które przebieg z force usunął
 * z karty przed zapisem (dropPreviousWebFiles), wycofanie nie przywraca. Powód przeglądu po wycofaniu odpowiada
 * przywróconej wersji (werdykt strony, chyba że człowiek ją zatwierdził) — nikt tej wersji teraz nie zatwierdza.
 * Karta bez poprzedniego opisu i karta, której opis zmienił się od partii, są pomijane (zerowanie karty to decyzja
 * handlowca w przeglądzie: „Odrzuć”). Domyślnie podgląd; zapis z --apply, z dziennikiem zmian (JSON, jedna karta
 * w wierszu, z numerami usuwanych plików). Partia w toku (queued/running) — podgląd działa, zapis odmawia.
 *
 * --reject-proposals: propozycje z tej partii czekające na decyzję → odrzucone bez blokady adresu (to nie decyzja
 * handlowca o cudzej stronie, tylko wycofanie przebiegu); powód przeglądu karty wynikający z propozycji znika albo
 * wraca do powodu obecnego opisu — jak przy odrzuceniu propozycji w przeglądzie. Propozycja nie ma danych
 * technicznych przebiegu (pliki i normy cofnęła już sama propozycja), więc poza decyzją nie ma czego cofać.
 */
final class RollbackBatchCommand extends Command
{
    private const LOG_LABEL = 'rollback-batch';

    private const PREVIEW_ROWS = 30;

    /** Powody przeglądu związane z propozycją — znikają, gdy propozycja dostanie decyzję (ProductReviewService). */
    private const PROPOSAL_REASONS = [Product::REVIEW_WORSE_VERSION, Product::REVIEW_REJECTED_SOURCE];

    protected $signature = 'products:rollback-batch
                            {batch : Numer partii pobierania opisów}
                            {--reject-proposals : Propozycje z tej partii czekające na decyzję → odrzucone (bez blokady adresu)}
                            {--log= : Plik dziennika zmian (domyślnie storage/app/repair-backups/rollback-batch-<partia>-<data>.json)}
                            {--apply : Wycofaj (bez tej flagi tylko podgląd)}';

    protected $description = 'Wycofanie opisów zapisanych przez partię: karty wracają do poprzedniego opublikowanego opisu (wersja origin restore), z karty znikają pliki dodane przez partię; podgląd bez --apply';

    public function handle(DescriptionVersionStore $versions, RunEffectsReverter $effects): int
    {
        $batchId = (int) $this->argument('batch');
        $batch = $batchId > 0 ? ProductEnrichmentBatch::query()->find($batchId) : null;
        if ($batch === null) {
            $this->error("Nie ma partii #{$batchId}.");

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $rejectProposals = (bool) $this->option('reject-proposals');
        if ($apply && in_array($batch->status, [ProductEnrichmentBatch::STATUS_QUEUED, ProductEnrichmentBatch::STATUS_RUNNING], true)) {
            // przebieg dopisywałby kolejne wersje i pliki w trakcie wycofywania — wycofanie dopiero po jego końcu
            $this->error("Partia #{$batch->id} jest w toku ({$batch->status}) — wycofanie dopiero po jej zakończeniu albo anulowaniu. Podgląd (bez --apply) działa.");

            return self::FAILURE;
        }

        /** @var list<array{product: Product, version: ProductDescriptionVersion, previous: ?ProductDescriptionVersion, files: array{images: list<int>, documents: list<int>}, note: ?string}> $plan */
        $plan = [];
        $published = ProductDescriptionVersion::query()
            ->where('batch_id', $batch->id)
            ->where('status', ProductDescriptionVersion::STATUS_PUBLISHED)
            ->whereIn('origin', [ProductDescriptionVersion::ORIGIN_ENRICHMENT, ProductDescriptionVersion::ORIGIN_MODEL_SHARED])
            ->orderBy('id')
            ->get();
        foreach ($published as $version) {
            $product = Product::query()->find($version->product_id, ['id', 'sku', 'name', 'description']);
            if ($product === null) {
                continue;
            }
            $current = $versions->current($product);
            if ($current === null || (int) $current->id !== (int) $version->id) {
                $plan[] = ['product' => $product, 'version' => $version, 'previous' => null, 'files' => ['images' => [], 'documents' => []], 'note' => 'opis karty zmienił się od partii — pominięta'];

                continue;
            }
            $previous = $this->previousPublished($version);
            $plan[] = [
                'product' => $product,
                'version' => $version,
                'previous' => $previous,
                // pliki z internetu dodane przez przebieg partii, które są dalej na karcie — do usunięcia przy wycofaniu
                'files' => $previous !== null ? $effects->filesToDrop($product, $version) : ['images' => [], 'documents' => []],
                'note' => $previous === null ? 'brak poprzedniego opisu — pominięta (zerowanie: „Odrzuć” w przeglądzie)' : null,
            ];
        }
        $proposals = $rejectProposals
            ? ProductDescriptionVersion::query()
                ->where('batch_id', $batch->id)
                ->where('status', ProductDescriptionVersion::STATUS_PROPOSED)
                ->whereNull('decision')
                ->orderBy('id')
                ->get()
            : collect();

        $restorable = array_values(array_filter($plan, static fn (array $row): bool => $row['previous'] !== null));
        $this->info(sprintf(
            'Partia #%d (%s, %s): opisów z partii na kartach %d — do wycofania %d, pominiętych %d%s.',
            $batch->id, (string) $batch->status, $batch->created_at?->format('Y-m-d H:i') ?? '—',
            count($plan), count($restorable), count($plan) - count($restorable),
            $rejectProposals ? '; propozycji do odrzucenia '.$proposals->count() : '',
        ));
        if ($plan !== []) {
            $this->table(
                ['Karta', 'SKU', 'Wersja z partii', 'Wraca do', 'Pliki do usunięcia', 'Uwagi'],
                array_map(static fn (array $row): array => [
                    (int) $row['product']->id,
                    (string) $row['product']->sku,
                    $row['version']->origin.' #'.$row['version']->id,
                    $row['previous'] !== null ? $row['previous']->origin.' #'.$row['previous']->id.' ('.$row['previous']->created_at?->format('Y-m-d').')' : '—',
                    $row['previous'] !== null ? self::filesLabel($row['files']) : '—',
                    (string) ($row['note'] ?? ''),
                ], array_slice($plan, 0, self::PREVIEW_ROWS)),
            );
        }
        if ($restorable !== []) {
            $this->line(sprintf(
                'Pliki do usunięcia: zdjęcia i dokumenty z internetu dodane przez przebieg partii (razem %d na %d kartach), normy producenta wracają do stanu sprzed przebiegu, gdy nikt ich potem nie zmienił. '
                .'Zdjęć i plików z internetu, które przebieg z force usunął z karty przed zapisem nowego opisu, wycofanie nie przywraca.',
                array_sum(array_map(static fn (array $row): int => count($row['files']['images']) + count($row['files']['documents']), $restorable)),
                count(array_filter($restorable, static fn (array $row): bool => $row['files']['images'] !== [] || $row['files']['documents'] !== [])),
            ));
        }
        if (! $apply) {
            $this->line('Podgląd — nic nie zapisano. Wycofanie: dodaj --apply'.($rejectProposals ? '' : ' (propozycje z partii: --reject-proposals)').'.');

            return self::SUCCESS;
        }
        if ($restorable === [] && $proposals->isEmpty()) {
            return self::SUCCESS;
        }

        $log = trim((string) $this->option('log'));
        if ($log === '') {
            $log = storage_path('app/repair-backups/'.self::LOG_LABEL.'-'.$batch->id.'-'.now()->format('Ymd-His').'.json');
        }
        if (! $this->writeLog($log, $batch, $restorable, $proposals->all())) {
            $this->error("Nie wycofano: nie udało się zapisać dziennika {$log}");

            return self::FAILURE;
        }

        $restored = 0;
        $skipped = 0;
        $droppedFiles = 0;
        foreach ($restorable as $row) {
            $dropped = DB::transaction(function () use ($versions, $effects, $row, $batch): ?int {
                // ten sam warunek co przy wyborze — karta, którą w międzyczasie zapisał przebieg albo handlowiec, zostaje
                $locked = Product::query()->lockForUpdate()->find($row['product']->id);
                if ($locked === null || (int) ($versions->current($locked)?->id ?? 0) !== (int) $row['version']->id) {
                    return null;
                }
                // najpierw skutki przebiegu (pliki, normy producenta), potem opis — publish liczy pliki karty już bez nich
                $files = $effects->filesToDrop($locked, $row['version']);
                $effects->revert($locked, $row['version']);
                $product = $versions->publish($row['previous'], null, ProductDescriptionVersion::ORIGIN_RESTORE);
                $versions->current($product)?->forceFill([
                    'reason' => mb_substr('wycofanie partii #'.$batch->id.' — z wersji #'.$row['previous']->id, 0, 255),
                ])->save();
                // publish zdejmuje powód przeglądu jak przy ręcznym „Przywróć” — tu nikt wersji nie zatwierdza, więc powód
                // wynika z przywróconej wersji (jej werdykt i ewentualna wcześniejsza decyzja człowieka)
                $this->setReasonFromCurrent($product, $row['previous']);

                return count($files['images']) + count($files['documents']);
            });
            if ($dropped === null) {
                $skipped++;
            } else {
                $restored++;
                $droppedFiles += $dropped;
            }
        }

        $rejected = 0;
        foreach ($proposals as $proposal) {
            $rejected += (int) DB::transaction(function () use ($versions, $proposal, $batch): bool {
                $fresh = ProductDescriptionVersion::query()->lockForUpdate()->find($proposal->id);
                if ($fresh === null || $fresh->status !== ProductDescriptionVersion::STATUS_PROPOSED || $fresh->decision !== null) {
                    return false;
                }
                $reason = trim((string) $fresh->reason);
                $fresh->forceFill([
                    'status' => ProductDescriptionVersion::STATUS_REJECTED,
                    'decision' => ProductDescriptionVersion::DECISION_REJECTED,
                    'decided_at' => now(),
                    'reason' => mb_substr(($reason !== '' ? $reason.' | ' : '').'wycofanie partii #'.$batch->id, 0, 255),
                ])->save();
                $product = Product::query()->lockForUpdate()->find($fresh->product_id);
                if ($product !== null && in_array($product->review_reason, self::PROPOSAL_REASONS, true)) {
                    $this->setReasonFromCurrent($product, $versions->current($product));
                }

                return true;
            });
        }

        $this->info(sprintf(
            'Wycofano: %d kart (pominięto %d — opis zmienił się w trakcie)%s, usunięto plików: %d. Dziennik: %s',
            $restored, $skipped, $rejectProposals ? ", odrzucono propozycji: {$rejected}" : '', $droppedFiles, $log,
        ));

        return self::SUCCESS;
    }

    /**
     * Komórka tabeli podglądu: ile zdjęć i dokumentów zniknie z karty.
     *
     * @param  array{images: list<int>, documents: list<int>}  $files
     */
    private static function filesLabel(array $files): string
    {
        $images = count($files['images']);
        $documents = count($files['documents']);

        return $images + $documents === 0 ? '0' : sprintf('%d (%d zdj., %d dok.)', $images + $documents, $images, $documents);
    }

    /**
     * Opis, który był na karcie przed wersją z partii: najnowsza superseded sprzed niej z tekstem opisu i inną treścią
     * (ta sama treść = nic do przywracania).
     */
    private function previousPublished(ProductDescriptionVersion $version): ?ProductDescriptionVersion
    {
        $candidates = ProductDescriptionVersion::query()
            ->where('product_id', $version->product_id)
            ->where('status', ProductDescriptionVersion::STATUS_SUPERSEDED)
            ->where('id', '<', $version->id)
            ->orderByDesc('id')
            ->get();
        foreach ($candidates as $candidate) {
            if ($candidate->description_sha1 !== $version->description_sha1
                && Product::isDescriptionText((string) ($candidate->description ?? ''))) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Powód przeglądu z wersji opisu, która jest (albo wraca) na kartę — jak ProductReviewService::reasonForCurrent:
     * strona bez kodu → identity_soft, niepotwierdzona → identity_none, potwierdzona, nieznana albo zatwierdzona przez
     * człowieka → brak. Po odrzuceniu propozycji: obecna baza karty; po wycofaniu: wersja źródłowa przywrócenia (kopia
     * origin restore nie niesie decyzji człowieka o tym tekście — niesie ją wersja, z której powstała).
     */
    private function setReasonFromCurrent(Product $product, ?ProductDescriptionVersion $current): void
    {
        $reason = null;
        if ($current !== null
            && $current->decision !== ProductDescriptionVersion::DECISION_APPROVED
            && $current->origin !== ProductDescriptionVersion::ORIGIN_REVIEW_APPROVE) {
            $reason = match ($current->identity_verdict) {
                ProductDescriptionVersion::VERDICT_SOFT => Product::REVIEW_IDENTITY_SOFT,
                ProductDescriptionVersion::VERDICT_NONE => Product::REVIEW_IDENTITY_NONE,
                default => null,
            };
        }
        if ($reason === null) {
            $product->update(['review_reason' => null, 'review_since' => null]);
        } elseif ($product->review_reason !== $reason) {
            $product->update(['review_reason' => $reason, 'review_since' => now()]);
        }
    }

    /**
     * Dziennik przed zapisem: karta, wersja z partii, wersja przywracana i numery zdjęć i dokumentów do usunięcia
     * (stan z podglądu — przy zapisie lista liczona jeszcze raz pod blokadą karty).
     *
     * @param  list<array{product: Product, version: ProductDescriptionVersion, previous: ?ProductDescriptionVersion, files: array{images: list<int>, documents: list<int>}, note: ?string}>  $restorable
     * @param  list<ProductDescriptionVersion>  $proposals
     */
    private function writeLog(string $path, ProductEnrichmentBatch $batch, array $restorable, array $proposals): bool
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return false;
        }
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            return false;
        }
        try {
            fwrite($handle, json_encode(['label' => self::LOG_LABEL, 'batch_id' => (int) $batch->id, 'at' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE)."\n");
            foreach ($restorable as $row) {
                fwrite($handle, json_encode([
                    'product_id' => (int) $row['product']->id,
                    'sku' => (string) $row['product']->sku,
                    'batch_version_id' => (int) $row['version']->id,
                    'restored_version_id' => (int) $row['previous']->id,
                    'dropped_image_ids' => $row['files']['images'],
                    'dropped_document_ids' => $row['files']['documents'],
                ], JSON_UNESCAPED_UNICODE)."\n");
            }
            foreach ($proposals as $proposal) {
                fwrite($handle, json_encode([
                    'product_id' => (int) $proposal->product_id,
                    'rejected_proposal_id' => (int) $proposal->id,
                ], JSON_UNESCAPED_UNICODE)."\n");
            }
        } finally {
            fclose($handle);
        }

        return true;
    }
}
