<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EnrichmentCancelledException;
use App\Jobs\Concerns\RefreshesBatchProgress;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentBatchItem;
use App\Services\Enrichment\EnrichmentAttemptLog;
use App\Services\Enrichment\ModelGroupPlanner;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\Sources\SourceUnmappedException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Opis wspólny dla modelu (etap 2 opisów z cenników, 08.10.2026): po przebiegu lidera grupy modelu jego członkowie
 * dostają opis z wersji lidera bez modelu językowego — werdykt tożsamości per karta na zapisanych stronach lidera,
 * listy wspólne, atrybuty i dowody per karta, zdjęcie w kolorze karty, decyzja zapisu per karta
 * (ProductEnrichmentService::applyModelDescription). Kolejka enrich bez slotu LLM — zadanie nie liczy się do limitu
 * równoległych zapytań do modelu.
 *
 * Członek po członku: wyjątek przy jednym nie przerywa reszty — jego pozycja partii dostaje „failed” z komunikatem,
 * karta „błąd”. Pozycje: opis zapisany albo propozycja → done (z notą „Nowy opis czeka w »Do przeglądu«…”), karta
 * z tym opisem lidera już na sobie → skipped. Przerwanie partii w trakcie: bieżący i pozostali członkowie wracają do
 * stanu sprzed kolejki (restoreAfterCancel).
 *
 * Każdy członek obsłużony raz (kontrakt §1.3: idempotencja członka = przejęcie ze statusu „queued”): pozycja jest
 * przejmowana jednym UPDATE z warunkiem na status (claim) i znaczona uuid zadania (model_claim_uuid, stały między
 * próbami) — 0 wierszy znaczy, że ma ją inna instancja tego zadania albo przebieg już ją zamknął, i członek jest
 * pomijany. Ponowienie zadania (limit czasu, padnięty worker, wyjątek poza pętlą) bierze członków wciąż „queued”
 * i tych, których przerwana próba zostawiła w „running”: własne (ten sam uuid) bez względu na wiek, cudze tylko
 * porzucone, czyli starsze niż limit czasu zadania (świeższa pozycja może należeć do wciąż żywej instancji);
 * applyModelDescription pomija kartę, która ten opis już ma. Wersja lidera odrzucona w przeglądzie to brak podstawy
 * — członkowie dostają „błąd” z prośbą o ponowne pobranie modelu. Członkowie, którzy po wyczerpanych próbach zostali
 * „queued”, mają w pozycji numer wersji lidera — harmonogram (releaseStaleRunningProducts) zleca im to zadanie od nowa.
 */
class ApplyModelDescriptionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RefreshesBatchProgress;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 45, 90];

    // Nie dłużej niż EnrichProductJob i krócej niż retry_after kolejki (480 s, config/queue.php): dłuższe zadanie
    // kolejka bazodanowa oddałaby drugiemu workerowi, gdy pierwsze wciąż opisuje członków.
    public int $timeout = 420;

    public const QUEUE = EnrichProductJob::QUEUE;

    /** Wynik applyModelDescription dla jednego członka. */
    public const RESULT_PUBLISHED = 'published';

    public const RESULT_PROPOSED = 'proposed';

    public const RESULT_SKIPPED = 'skipped';

    public function __construct(
        public readonly int $batchId,
        public readonly int $leaderId,
        public readonly int $leaderVersionId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(ProductEnrichmentService $enrichment, ModelGroupPlanner $planner): void
    {
        $batch = ProductEnrichmentBatch::query()->find($this->batchId);
        if ($batch === null) {
            return;
        }
        $members = $this->members($planner);
        // bez czekających członków zadanie ma sens tylko dla upadłych liderów sztafety (cały model poza nimi już opisany)
        if ($members === [] && $planner->relayedLeaders($this->batchId, $this->leaderId) === []) {
            return;
        }
        if ($batch->isCancelled()) {
            $this->restoreAfterCancel($enrichment, $members);
            $this->delete();

            return;
        }

        // brak podstawy: wersja lidera znikła (retencja wersji, usunięta karta) albo handlowiec odrzucił ją w przeglądzie,
        // zanim zadanie doszło do głosu (kolejka enrich za zadaniami liderów, ponowne zlecenie z harmonogramu z zapisanym
        // numerem wersji; blokada adresu dotyczy tylko karty lidera) — członkowie dostają „błąd” dopiero po przejęciu
        // pozycji, żeby druga instancja zadania nie policzyła go drugi raz
        $leaderVersion = ProductDescriptionVersion::query()->find($this->leaderVersionId);
        $noBasis = match (true) {
            $leaderVersion === null => "Wersja opisu lidera #{$this->leaderVersionId} nie istnieje",
            self::isRejected($leaderVersion) => "Opis lidera (wersja #{$leaderVersion->id}) został odrzucony w przeglądzie — pobierz opisy modelu ponownie",
            default => null,
        };

        foreach ($members as $i => $memberId) {
            $batch->refresh();
            if ($batch->isCancelled()) {
                $this->restoreAfterCancel($enrichment, array_slice($members, $i));
                $this->delete();

                return;
            }
            $member = Product::query()->find($memberId);
            if ($member === null) {
                continue;
            }
            if (! $this->claim($member)) {
                // pozycję ma inna instancja tego zadania (drugi worker po retry_after) albo przebieg już ją zamknął
                continue;
            }
            if ($leaderVersion === null || $noBasis !== null) {
                // bez wersji $noBasis jest zawsze ustawione — warunek na $leaderVersion tylko zawęża typ dla pętli
                $this->failMember($enrichment, $batch, $member, (string) $noBasis);

                continue;
            }

            $batch->update([
                'status' => ProductEnrichmentBatch::STATUS_RUNNING,
                'current_sku' => $member->sku,
                'current_name' => mb_substr((string) $member->name, 0, 255),
                'message' => 'Opis wspólny modelu (z karty lidera, bez modelu językowego)…',
            ]);

            try {
                $result = $this->applyToMember($enrichment, $member, $leaderVersion);
            } catch (EnrichmentCancelledException) {
                $this->restoreAfterCancel($enrichment, array_slice($members, $i));
                $this->delete();

                return;
            } catch (SourceUnmappedException $e) {
                // cennik map_only, członek bez strony z importera (10.10.2026): kartę ustawił już przebieg („Do przeglądu”,
                // opis zostaje) — pozycja czeka na człowieka, nie jest błędem
                $enrichment->markBatchItem($batch, true, $member->fresh() ?? $member, ProductEnrichmentBatchItem::STATUS_MANUAL, mb_substr($e->getMessage(), 0, 500));
                $this->refreshBatchProgress($batch);

                continue;
            } catch (Throwable $e) {
                Log::warning('Model description apply failed for member', [
                    'batch_id' => $this->batchId,
                    'leader_id' => $this->leaderId,
                    'leader_version_id' => $this->leaderVersionId,
                    'product_id' => $member->id,
                    'sku' => $member->sku,
                    'error' => $e->getMessage(),
                ]);
                $this->failMember($enrichment, $batch, $member, $e->getMessage());

                continue;
            }

            if ($result === self::RESULT_SKIPPED) {
                $enrichment->markBatchItem($batch, true, $member, ProductEnrichmentBatchItem::STATUS_SKIPPED, 'Opis modelu już na karcie');
            } else {
                // opis zapisany albo propozycja — pozycja gotowa, przy propozycji z notą „Nowy opis czeka w »Do przeglądu«…”
                $enrichment->markBatchItem($batch, true, $member, ProductEnrichmentBatchItem::STATUS_DONE, $enrichment->lastProposalNote());
            }
            $this->refreshBatchProgress($batch);
        }
        $this->failOwnRunning($enrichment, $batch);
        if ($noBasis === null && $leaderVersion !== null) {
            $this->describeRelayedLeaders($enrichment, $planner, $batch, $leaderVersion);
        }
    }

    /**
     * Upadli liderzy sztafety (karta „ręcznie”/„błąd” bez opisu, pozycja przeszła pod tego lidera — nextLeader) dostają
     * opis modelu jak członkowie. Ich pozycja jest już policzona w partii, więc liczniki zostają; zmienia się tylko stan
     * pozycji i karty. Błąd albo przerwanie partii zostawia kartę tak, jak była — bez nowego błędu w liczniku.
     */
    private function describeRelayedLeaders(ProductEnrichmentService $enrichment, ModelGroupPlanner $planner, ProductEnrichmentBatch $batch, ProductDescriptionVersion $leaderVersion): void
    {
        foreach ($planner->relayedLeaders($this->batchId, $this->leaderId) as $productId) {
            $batch->refresh();
            if ($batch->isCancelled()) {
                return;
            }
            $card = Product::query()->find($productId);
            if ($card === null) {
                continue;
            }
            $before = ['enrichment_status' => $card->enrichment_status, 'enrichment_error' => $card->enrichment_error];
            try {
                $result = $this->applyToMember($enrichment, $card, $leaderVersion);
            } catch (Throwable $e) {
                Log::warning('Model description apply failed for relayed leader', [
                    'batch_id' => $this->batchId,
                    'leader_id' => $this->leaderId,
                    'product_id' => $card->id,
                    'error' => $e->getMessage(),
                ]);
                $fresh = $card->fresh();
                if ($fresh !== null && $fresh->enrichment_status === Product::ENRICHMENT_RUNNING) {
                    $fresh->update($before);
                }

                continue;
            }
            if ($result === self::RESULT_SKIPPED) {
                continue;
            }
            // przejście pozycji ze stanu końcowego na „gotowe” warunkowo (druga instancja zadania nie liczy drugi raz);
            // pozycja „błąd” była policzona w failed — przechodzi do done, „ręcznie” już była w done
            $item = ProductEnrichmentBatchItem::query()->where('batch_id', $this->batchId)->where('product_id', $card->id)->first();
            if ($item === null) {
                continue;
            }
            $wasFailed = $item->status === ProductEnrichmentBatchItem::STATUS_FAILED;
            $moved = ProductEnrichmentBatchItem::query()
                ->whereKey($item->id)
                ->where('status', $item->status)
                ->update([
                    'status' => ProductEnrichmentBatchItem::STATUS_DONE,
                    'message' => mb_substr(trim('Opis wspólny modelu (po sztafecie liderów). '.(string) $enrichment->lastProposalNote()), 0, 500),
                    'updated_at' => now(),
                ]);
            if ($moved === 1 && $wasFailed && $batch->failed > 0) {
                $batch->decrement('failed');
                $batch->increment('done');
                $this->refreshBatchProgress($batch);
            }
        }
    }

    /** Wersja odrzucona w przeglądzie (status „rejected” albo decyzja „rejected” przy propozycji) — nie jest podstawą opisu. */
    private static function isRejected(ProductDescriptionVersion $version): bool
    {
        return $version->status === ProductDescriptionVersion::STATUS_REJECTED
            || $version->decision === ProductDescriptionVersion::DECISION_REJECTED;
    }

    /**
     * Opis lidera na jednej karcie członka — wynik RESULT_PUBLISHED / RESULT_PROPOSED / RESULT_SKIPPED
     * (ProductEnrichmentService::applyModelDescription). Osobna metoda, żeby test zadania mógł podstawić wynik bez
     * źródeł i bez sieci: serwis jest klasą final, atrapa częściowa nie wchodzi w grę.
     */
    protected function applyToMember(ProductEnrichmentService $enrichment, Product $member, ProductDescriptionVersion $leaderVersion): ?string
    {
        return $enrichment->applyModelDescription($member, $leaderVersion, $this->batchId);
    }

    /**
     * Wyczerpane próby (limit czasu, padnięty worker): członek, którego ostatnia próba zostawiła w „running”, dostaje
     * „błąd” — inaczej jego pozycja wisiałaby w „running” na zawsze i partia nigdy by się nie domknęła. Członkowie
     * wciąż „queued” zostają z numerem wersji lidera w pozycji — zadanie zleci im od nowa harmonogram.
     */
    public function failed(?Throwable $e): void
    {
        $batch = ProductEnrichmentBatch::query()->find($this->batchId);
        if ($batch === null) {
            return;
        }
        $enrichment = app(ProductEnrichmentService::class);
        $interrupted = $this->interruptedMembers();
        if ($batch->isCancelled()) {
            $this->restoreAfterCancel($enrichment, $interrupted);

            return;
        }
        $message = 'Opis wspólny modelu przerwany: '.($e?->getMessage() ?? 'nieznany błąd');
        foreach ($interrupted as $memberId) {
            $member = Product::query()->find($memberId);
            if ($member !== null) {
                $this->failMember($enrichment, $batch, $member, $message);
            }
        }
    }

    /**
     * Atomowe przejęcie pozycji członka: „queued” → „running” jednym UPDATE z warunkiem na status, ze znacznikiem uuid
     * zadania. 0 wierszy = pozycję ma inna instancja tego zadania (kolejka bazodanowa oddaje zadanie drugiemu workerowi
     * po retry_after, gdy pierwsze wciąż pracuje — bez przejęcia obie brały tych samych członków: podwójne zapisy,
     * „done” liczone dwa razy, partia zamknięta przed czasem) albo przebieg już ją zamknął.
     *
     * Ponowienie (attempts() > 1) przejmuje też pozycję „running” zostawioną przez przerwaną próbę: własną (ten sam
     * uuid — zadanie zabite limitem czasu po 420 s wraca do kolejki po retry_after 480 s od pobrania, więc członek
     * zajęty tuż przed zabiciem ma przy ponowieniu wiek ~60 s i sam próg wieku by go nie przejął — wisiałby w „running”,
     * a po 15 min karta szłaby do „błąd” bez opisu) bez względu na wiek; cudzą (inny uuid, brak znacznika) tylko
     * porzuconą, starszą niż limit czasu zadania — żywa instancja nie może trzymać pozycji dłużej. Bez zadania kolejki
     * (dispatchSync, test) nie ma uuid — zostaje sam próg wieku. Przejęcie odświeża updated_at.
     */
    private function claim(Product $member): bool
    {
        $uuid = $this->claimUuid();
        $abandonedBefore = now()->subSeconds($this->timeout);
        $claimed = ProductEnrichmentBatchItem::query()
            ->where('batch_id', $this->batchId)
            ->where('product_id', (int) $member->id)
            ->where(function (Builder $q) use ($uuid, $abandonedBefore): void {
                $q->where('status', ProductEnrichmentBatchItem::STATUS_QUEUED);
                if ($this->attempts() <= 1) {
                    return;
                }
                if ($uuid !== null) {
                    $q->orWhere(static fn (Builder $q) => $q
                        ->where('status', ProductEnrichmentBatchItem::STATUS_RUNNING)
                        ->where('model_claim_uuid', $uuid));
                }
                $q->orWhere(static fn (Builder $q) => $q
                    ->where('status', ProductEnrichmentBatchItem::STATUS_RUNNING)
                    ->where('updated_at', '<', $abandonedBefore));
            })
            ->update([
                'sku' => mb_substr((string) $member->sku, 0, 100),
                'name' => mb_substr((string) $member->name, 0, 255),
                'status' => ProductEnrichmentBatchItem::STATUS_RUNNING,
                'message' => 'Opis wspólny modelu z karty lidera…',
                'model_claim_uuid' => $uuid,
                'updated_at' => now(),
            ]);

        return $claimed === 1;
    }

    /** uuid zadania kolejki — stały między próbami tego samego zadania; null bez zadania (dispatchSync, test). */
    private function claimUuid(): ?string
    {
        $uuid = $this->job?->uuid();

        return is_string($uuid) && $uuid !== '' ? mb_substr($uuid, 0, 36) : null;
    }

    /**
     * Po pętli: własne pozycje, które zostały w „running” (przejęte przez tę albo poprzednią próbę i niedomknięte przez
     * pętlę), dostają „błąd” z komunikatem — inaczej wisiałyby w „running” na zawsze, a partia nigdy by się nie
     * domknęła. Zabezpieczenie: pętla domyka każdą przejętą pozycję, a usunięta karta zabiera pozycję ze sobą
     * (klucz obcy z kaskadą). Bez uuid nie ma jak odróżnić własnych od cudzych — nic nie jest ruszane.
     */
    private function failOwnRunning(ProductEnrichmentService $enrichment, ProductEnrichmentBatch $batch): void
    {
        $uuid = $this->claimUuid();
        if ($uuid === null) {
            return;
        }
        $left = ProductEnrichmentBatchItem::query()
            ->where('batch_id', $this->batchId)
            ->where('model_leader_id', $this->leaderId)
            ->where('product_id', '!=', $this->leaderId)
            ->where('status', ProductEnrichmentBatchItem::STATUS_RUNNING)
            ->where('model_claim_uuid', $uuid)
            ->orderBy('id')
            ->pluck('product_id');
        foreach ($left as $memberId) {
            $member = Product::query()->find((int) $memberId);
            if ($member !== null) {
                $this->failMember($enrichment, $batch, $member, 'Opis wspólny modelu przerwany bez wyniku — pobierz opisy modelu ponownie');
            }
        }
    }

    /**
     * Kandydaci do opisania: „queued” z planera, a przy ponowieniu zadania także ci, których przerwana próba zostawiła
     * w „running” (najpierw oni — to karty w połowie roboty). O tym, kto naprawdę trafia do tej instancji, rozstrzyga
     * przejęcie pozycji (claim).
     *
     * @return list<int>
     */
    private function members(ModelGroupPlanner $planner): array
    {
        $members = array_map('intval', $planner->membersOf($this->batchId, $this->leaderId));
        if ($this->attempts() > 1) {
            $members = [...$this->interruptedMembers(), ...$members];
        }

        return array_values(array_unique(array_filter($members, fn (int $id): bool => $id > 0 && $id !== $this->leaderId)));
    }

    /** @return list<int> członkowie tego lidera z pozycją „running” (bez lidera — on ma własne zadanie) */
    private function interruptedMembers(): array
    {
        return ProductEnrichmentBatchItem::query()
            ->where('batch_id', $this->batchId)
            ->where('model_leader_id', $this->leaderId)
            ->where('product_id', '!=', $this->leaderId)
            ->where('status', ProductEnrichmentBatchItem::STATUS_RUNNING)
            ->orderBy('id')
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Błąd przy jednym członku: karta „błąd” z komunikatem i śladem (jak EnrichProductJob::recordItemFailure — bez tego
     * karta zostawała w „running”), pozycja partii „failed”, komunikat partii odświeżony. Karta z gotowym albo ręcznym
     * opisem zachowuje status — błąd dotyczy tylko tego przebiegu.
     */
    private function failMember(ProductEnrichmentService $enrichment, ProductEnrichmentBatch $batch, Product $member, string $error): void
    {
        $fresh = $member->fresh() ?? $member;
        if (! in_array($fresh->enrichment_status, [Product::ENRICHMENT_DONE, Product::ENRICHMENT_MANUAL], true)) {
            $log = app(EnrichmentAttemptLog::class);
            $log->add('fail', $error);
            $fresh->update([
                'enrichment_status' => Product::ENRICHMENT_FAILED,
                'enrichment_error' => mb_substr($error, 0, 2000),
                'enrichment_trace' => $log->snapshot($fresh),
            ]);
        }
        $enrichment->markBatchItem($batch, false, $fresh, ProductEnrichmentBatchItem::STATUS_FAILED, mb_substr($error, 0, 500));
        $this->refreshBatchProgress($batch);
    }

    /**
     * Przerwana partia: członkowie bez wyniku wracają do stanu sprzed kolejki (pozycja partii), nie do „błąd: Anulowano”.
     *
     * @param  list<int>  $memberIds
     */
    private function restoreAfterCancel(ProductEnrichmentService $enrichment, array $memberIds): void
    {
        foreach ($memberIds as $memberId) {
            $enrichment->restoreAfterCancel($this->batchId, (int) $memberId, 'Anulowano przez użytkownika');
        }
    }
}
