<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ApplyModelDescriptionJob;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentBatchItem;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\ModelGroupPlanner;
use App\Services\Enrichment\ProductEnrichmentService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Opis wspólny dla modelu (etap 2, 08.10.2026): zadanie członków po przebiegu lidera — pozycje partii po wyniku
 * (published/proposed → done, skipped → skipped, wyjątek → failed bez przerywania reszty), przerwanie partii,
 * brak wersji lidera, ponowienie po przerwanej próbie. Sam opis członka (applyModelDescription) to część A —
 * tu podstawiany przez szew zadania (ApplyModelDescriptionJob::applyToMember), bo serwis jest klasą final.
 */
final class ApplyModelDescriptionJobTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTION = 'Mata antyzmęczeniowa Orthomat Standard do suchych pomieszczeń, pianka PVC, krawędzie skośne.';

    private const MODEL_KEY = 'coba|AF|orthomat standard';

    /** jedna instancja na test — serwis nie jest singletonem, a nota propozycji żyje w jego polu */
    private ProductEnrichmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->service = app(ProductEnrichmentService::class);
    }

    public function test_batch_items_carry_model_columns_and_index(): void
    {
        $this->assertTrue(Schema::hasColumns('product_enrichment_batch_items', ['model_key', 'model_leader_id', 'model_leader_version_id', 'model_claim_uuid']));
        $this->assertContains('pebi_batch_model', array_column(Schema::getIndexes('product_enrichment_batch_items'), 'name'));

        $item = new ProductEnrichmentBatchItem(['model_key' => self::MODEL_KEY, 'model_leader_id' => 5, 'model_leader_version_id' => 9]);
        $this->assertSame([self::MODEL_KEY, 5, 9], [$item->model_key, $item->model_leader_id, $item->model_leader_version_id]);
    }

    public function test_marks_members_by_result_and_closes_batch(): void
    {
        [$batch, $leader, $version, $members] = $this->group(3);
        [$published, $proposed, $skipped] = $members;
        $results = [
            $published->id => ApplyModelDescriptionJob::RESULT_PUBLISHED,
            $proposed->id => ApplyModelDescriptionJob::RESULT_PROPOSED,
            $skipped->id => ApplyModelDescriptionJob::RESULT_SKIPPED,
        ];
        $seen = [];
        $job = $this->job($batch, $leader, $version, function (Product $member, ProductDescriptionVersion $leaderVersion) use ($results, $version, &$seen): string {
            $this->assertSame($version->id, $leaderVersion->id);
            $seen[] = $member->id;
            // członek w trakcie opisu ma pozycję „running”; propozycja zostawia notę jak przebieg karty
            $this->assertSame(ProductEnrichmentBatchItem::STATUS_RUNNING, $this->item($member)->status);
            if ($results[$member->id] === ApplyModelDescriptionJob::RESULT_PROPOSED) {
                $this->setProposalNote('Nowy opis czeka w „Do przeglądu” (tożsamość strony słabsza)');
            }

            return $results[$member->id];
        });

        $job->handle($this->service(), app(ModelGroupPlanner::class));

        $this->assertSame([$published->id, $proposed->id, $skipped->id], $seen);
        $this->assertSame(ProductEnrichmentBatchItem::STATUS_DONE, $this->item($published)->status);
        $this->assertNull($this->item($published)->message);
        $this->assertSame(ProductEnrichmentBatchItem::STATUS_DONE, $this->item($proposed)->status);
        $this->assertStringContainsString('Do przeglądu', (string) $this->item($proposed)->message);
        $this->assertSame(ProductEnrichmentBatchItem::STATUS_SKIPPED, $this->item($skipped)->status);
        $this->assertSame('Opis modelu już na karcie', $this->item($skipped)->message);
        $batch->refresh();
        $this->assertSame(4, $batch->done);
        $this->assertSame(0, $batch->failed);
        $this->assertSame(ProductEnrichmentBatch::STATUS_DONE, $batch->status);
        $this->assertSame('OK 4 · błędy 0 · pozostało 0', $batch->message);
        $this->assertNull($batch->current_sku);
    }

    public function test_relayed_leader_without_description_gets_the_model_description_without_touching_counters(): void
    {
        // pełne pobranie Coby #501: pierwszy lider (DP0106) nie znalazł strony → „ręcznie”, kolejny lider dał wersję —
        // upadły lider ma dostać opis modelu jak członek, a partia nie liczy go drugi raz
        [$batch, $leader, $version, $members] = $this->group(1);
        $failed = $this->card('DP0106', 'Deckplate Czarny 0.6m x 0.9m (15mm)', ['description' => null, 'enrichment_status' => Product::ENRICHMENT_MANUAL, 'enrichment_error' => 'Nie znaleziono karty potwierdzającej produkt DP0106']);
        ProductEnrichmentBatchItem::query()->create([
            'batch_id' => $batch->id, 'product_id' => $failed->id, 'sku' => $failed->sku, 'name' => $failed->name,
            'status' => ProductEnrichmentBatchItem::STATUS_MANUAL, 'model_key' => self::MODEL_KEY, 'model_leader_id' => $failed->id,
        ]);
        $batch->update(['total' => 3, 'done' => 2]);
        // sztafeta: upadły lider oddaje model temu liderowi — jego pozycja też przechodzi pod nowego lidera
        ProductEnrichmentBatchItem::query()->where('batch_id', $batch->id)->update(['model_leader_id' => $leader->id]);
        $seen = [];
        $job = $this->job($batch, $leader, $version, function (Product $member) use (&$seen): string {
            $seen[] = $member->sku;

            return ApplyModelDescriptionJob::RESULT_PUBLISHED;
        });

        $job->handle($this->service(), app(ModelGroupPlanner::class));

        $this->assertSame([$members[0]->sku, 'DP0106'], $seen, 'najpierw czekający członkowie, potem upadły lider');
        $this->assertSame(ProductEnrichmentBatchItem::STATUS_DONE, $this->item($failed)->status);
        $this->assertStringContainsString('sztafecie', (string) $this->item($failed)->message);
        $batch->refresh();
        $this->assertSame(3, $batch->done, 'upadły lider był już policzony — bez drugiego liczenia');
        $this->assertSame(0, $batch->failed);
    }

    public function test_relayed_leader_counted_as_failed_moves_to_done_once(): void
    {
        [$batch, $leader, $version] = $this->group(0);
        $failed = $this->card('DS0106', 'DeckStep Matting Czarny 0.6m x 10m (11.5mm)', ['description' => null, 'enrichment_status' => Product::ENRICHMENT_FAILED]);
        ProductEnrichmentBatchItem::query()->create([
            'batch_id' => $batch->id, 'product_id' => $failed->id, 'sku' => $failed->sku, 'name' => $failed->name,
            'status' => ProductEnrichmentBatchItem::STATUS_FAILED, 'model_key' => self::MODEL_KEY, 'model_leader_id' => $leader->id,
        ]);
        $batch->update(['total' => 2, 'done' => 1, 'failed' => 1]);
        $apply = static fn (): string => ApplyModelDescriptionJob::RESULT_PUBLISHED;

        $this->job($batch, $leader, $version, $apply)->handle($this->service(), app(ModelGroupPlanner::class));
        // druga instancja (ponowienie po retry_after) — pozycja już „gotowe”, liczniki bez zmian
        $this->job($batch, $leader, $version, $apply)->handle($this->service(), app(ModelGroupPlanner::class));

        $this->assertSame(ProductEnrichmentBatchItem::STATUS_DONE, $this->item($failed)->status);
        $batch->refresh();
        $this->assertSame([2, 0], [$batch->done, $batch->failed], 'błąd upadłego lidera przechodzi do gotowych raz');
    }

    public function test_relayed_leader_keeps_its_state_when_applying_fails(): void
    {
        [$batch, $leader, $version] = $this->group(0);
        $failed = $this->card('DP0106', 'Deckplate Czarny 0.6m x 0.9m (15mm)', ['description' => null, 'enrichment_status' => Product::ENRICHMENT_MANUAL, 'enrichment_error' => 'brak strony']);
        ProductEnrichmentBatchItem::query()->create([
            'batch_id' => $batch->id, 'product_id' => $failed->id, 'sku' => $failed->sku, 'name' => $failed->name,
            'status' => ProductEnrichmentBatchItem::STATUS_MANUAL, 'model_key' => self::MODEL_KEY, 'model_leader_id' => $leader->id,
        ]);
        $job = $this->job($batch, $leader, $version, static function (Product $member): string {
            $member->update(['enrichment_status' => Product::ENRICHMENT_RUNNING]);
            throw new RuntimeException('dysk pełny');
        });

        $job->handle($this->service(), app(ModelGroupPlanner::class));

        $this->assertSame(ProductEnrichmentBatchItem::STATUS_MANUAL, $this->item($failed)->status);
        $this->assertSame(Product::ENRICHMENT_MANUAL, $failed->fresh()->enrichment_status);
        $this->assertSame('brak strony', $failed->fresh()->enrichment_error);
        $this->assertSame(0, $batch->fresh()->failed);
    }

    public function test_exception_for_one_member_marks_it_failed_and_continues_with_the_rest(): void
    {
        [$batch, $leader, $version, $members] = $this->group(2);
        [$broken, $fine] = $members;
        $job = $this->job($batch, $leader, $version, static function (Product $member) use ($broken): string {
            if ($member->id === $broken->id) {
                throw new RuntimeException('Zapis zdjęcia nie powiódł się');
            }

            return ApplyModelDescriptionJob::RESULT_PUBLISHED;
        });

        $job->handle($this->service(), app(ModelGroupPlanner::class));

        $this->assertSame(ProductEnrichmentBatchItem::STATUS_FAILED, $this->item($broken)->status);
        $this->assertSame('Zapis zdjęcia nie powiódł się', $this->item($broken)->message);
        $this->assertSame(Product::ENRICHMENT_FAILED, $broken->fresh()->enrichment_status);
        $this->assertSame('Zapis zdjęcia nie powiódł się', $broken->fresh()->enrichment_error);
        $this->assertSame(ProductEnrichmentBatchItem::STATUS_DONE, $this->item($fine)->status);
        $batch->refresh();
        $this->assertSame([2, 1, ProductEnrichmentBatch::STATUS_DONE], [$batch->done, $batch->failed, $batch->status]);
    }

    public function test_cancelled_batch_restores_members_without_applying(): void
    {
        [$batch, $leader, $version, $members] = $this->group(2);
        $batch->markCancelledFlag();
        $job = $this->job($batch, $leader, $version, static function (): string {
            throw new RuntimeException('opis nie może ruszyć w przerwanej partii');
        });

        $job->handle($this->service(), app(ModelGroupPlanner::class));

        foreach ($members as $member) {
            // stan sprzed kolejki z pozycji partii (previous_status), nie „błąd: Anulowano”
            $this->assertSame(Product::ENRICHMENT_NONE, $member->fresh()->enrichment_status);
            $this->assertNull($member->fresh()->enrichment_error);
        }
        $this->assertSame(1, $batch->fresh()->done);
    }

    public function test_cancel_during_run_restores_the_remaining_members(): void
    {
        [$batch, $leader, $version, $members] = $this->group(3);
        [$first, $second, $third] = $members;
        $job = $this->job($batch, $leader, $version, static function (Product $member) use ($first, $batch): string {
            if ($member->id === $first->id) {
                // przerwanie partii po pierwszym członku
                $batch->markCancelledFlag();
            }

            return ApplyModelDescriptionJob::RESULT_PUBLISHED;
        });

        $job->handle($this->service(), app(ModelGroupPlanner::class));

        // wynik pierwszego członka został zapisany, ale partia była już przerwana — pozycja „anulowana”, jak przy
        // karcie, której przebieg skończył się po anulowaniu (markBatchItem)
        $this->assertSame(ProductEnrichmentBatchItem::STATUS_CANCELLED, $this->item($first)->status);
        foreach ([$second, $third] as $member) {
            $this->assertSame(ProductEnrichmentBatchItem::STATUS_QUEUED, $this->item($member)->status);
            $this->assertSame(Product::ENRICHMENT_NONE, $member->fresh()->enrichment_status);
        }
    }

    public function test_missing_leader_version_fails_members(): void
    {
        [$batch, $leader, $version, $members] = $this->group(1);
        $version->delete();
        $job = $this->job($batch, $leader, $version, static function (): string {
            throw new RuntimeException('bez wersji lidera nie ma czego stosować');
        });

        $job->handle($this->service(), app(ModelGroupPlanner::class));

        $this->assertSame(ProductEnrichmentBatchItem::STATUS_FAILED, $this->item($members[0])->status);
        $this->assertStringContainsString("Wersja opisu lidera #{$version->id} nie istnieje", (string) $this->item($members[0])->message);
        $this->assertSame(Product::ENRICHMENT_FAILED, $members[0]->fresh()->enrichment_status);
    }

    /**
     * Kolejka bazodanowa oddaje zadanie drugiemu workerowi po retry_after, gdy pierwsze wciąż pracuje — bez przejęcia
     * pozycji obie instancje brały tych samych „queued” członków (podwójne zapisy, „done” liczone dwa razy, partia
     * zamknięta przed czasem). Druga instancja rusza, gdy pierwsza opisuje pierwszego członka: każdy członek
     * obsłużony dokładnie raz, pierwsza instancja pomija członków zamkniętych przez drugą.
     */
    public function test_two_instances_on_the_same_members_handle_each_member_once(): void
    {
        [$batch, $leader, $version, $members] = $this->group(3);
        [$first, $second, $third] = $members;
        $seen = [];
        $other = $this->job($batch, $leader, $version, static function (Product $member) use (&$seen): string {
            $seen[] = 'B:'.$member->id;

            return ApplyModelDescriptionJob::RESULT_PUBLISHED;
        });
        $started = false;
        $job = $this->job($batch, $leader, $version, function (Product $member) use (&$seen, &$started, $other): string {
            $seen[] = 'A:'.$member->id;
            if (! $started) {
                $started = true;
                // drugi worker dostaje to samo zadanie, gdy pierwszy ma pierwszego członka w „running”
                $other->handle($this->service(), app(ModelGroupPlanner::class));
            }

            return ApplyModelDescriptionJob::RESULT_PUBLISHED;
        });

        $job->handle($this->service(), app(ModelGroupPlanner::class));

        $this->assertSame(['A:'.$first->id, 'B:'.$second->id, 'B:'.$third->id], $seen);
        foreach ($members as $member) {
            $this->assertSame(ProductEnrichmentBatchItem::STATUS_DONE, $this->item($member)->status);
        }
        $batch->refresh();
        $this->assertSame([4, 0, ProductEnrichmentBatch::STATUS_DONE], [$batch->done, $batch->failed, $batch->status]);
        $this->assertSame('OK 4 · błędy 0 · pozostało 0', $batch->message);
    }

    /**
     * Pierwsza próba nie rusza pozycji „running” (może należeć do innego zadania). Ponowienie po przerwanej próbie
     * przejmuje własną pozycję (ten sam uuid zadania) bez względu na wiek — zadanie zabite limitem czasu po 420 s
     * wraca po retry_after 480 s od pobrania, więc członek zajęty tuż przed zabiciem ma wtedy ~60 s; sam próg wieku
     * zostawiałby go w „running” na zawsze. Cudzą (inny uuid) tylko porzuconą — starszą niż limit czasu zadania;
     * świeższa może należeć do wciąż żywej instancji (drugi worker po retry_after) i zostaje jej.
     */
    public function test_retry_takes_over_own_and_abandoned_running_members_only(): void
    {
        [$batch, $leader, $version, $members] = $this->group(4);
        [$own, $foreignFresh, $foreignOld, $waiting] = $members;
        $this->running($own, 'aaaaaaaa-0000-4000-8000-000000000001', now()->subSeconds(80));
        $this->running($foreignFresh, 'bbbbbbbb-0000-4000-8000-000000000002', now()->subSeconds(80));
        $this->running($foreignOld, 'bbbbbbbb-0000-4000-8000-000000000002', now()->subMinutes(10));
        $seen = [];
        $apply = static function (Product $member) use (&$seen): string {
            $seen[] = $member->id;

            return ApplyModelDescriptionJob::RESULT_PUBLISHED;
        };

        $retry = $this->job($batch, $leader, $version, $apply);
        $retry->setJob($this->queueJob(2, 'aaaaaaaa-0000-4000-8000-000000000001'));
        $retry->handle($this->service(), app(ModelGroupPlanner::class));

        // najpierw przerwani (po id), potem czekający; świeża cudza pominięta
        $this->assertSame([$own->id, $foreignOld->id, $waiting->id], $seen, 'ponowienie: własna i porzucona cudza, nie świeża cudza');
        foreach ([$own, $foreignOld, $waiting] as $member) {
            $this->assertSame(ProductEnrichmentBatchItem::STATUS_DONE, $this->item($member)->status);
            $this->assertSame('aaaaaaaa-0000-4000-8000-000000000001', $this->item($member)->model_claim_uuid);
        }
        $this->assertSame(ProductEnrichmentBatchItem::STATUS_RUNNING, $this->item($foreignFresh)->status);
        $this->assertSame('bbbbbbbb-0000-4000-8000-000000000002', $this->item($foreignFresh)->model_claim_uuid);
        $batch->refresh();
        $this->assertSame([4, 0, ProductEnrichmentBatch::STATUS_RUNNING], [$batch->done, $batch->failed, $batch->status]);
    }

    /** Bez zadania kolejki (dispatchSync, test) nie ma uuid — ponowienie przejmuje „running” tylko po progu wieku, jak dotąd. */
    public function test_retry_without_queue_job_takes_over_running_members_by_age_only(): void
    {
        [$batch, $leader, $version, $members] = $this->group(2);
        [$fresh, $old] = $members;
        $this->running($fresh, null, now()->subSeconds(80));
        $this->running($old, null, now()->subMinutes(10));
        $seen = [];
        $retry = $this->job($batch, $leader, $version, static function (Product $member) use (&$seen): string {
            $seen[] = $member->id;

            return ApplyModelDescriptionJob::RESULT_PUBLISHED;
        });
        $retry->setJob($this->queueJob(2, null));

        $retry->handle($this->service(), app(ModelGroupPlanner::class));

        $this->assertSame([$old->id], $seen);
        $this->assertSame(ProductEnrichmentBatchItem::STATUS_RUNNING, $this->item($fresh)->status);
        $this->assertNull($this->item($old)->model_claim_uuid);
    }

    /**
     * Własna pozycja, która po pętli została w „running”, dostaje „błąd” — inaczej wisiałaby na zawsze i partia nigdy
     * by się nie domknęła (tu sztucznie: pozycja z własnym znacznikiem, której pierwsza próba nie bierze, bo „running”
     * nie jest jej kandydatem).
     */
    public function test_own_running_member_left_after_the_loop_is_marked_failed(): void
    {
        [$batch, $leader, $version, $members] = $this->group(2);
        [$left, $waiting] = $members;
        $this->running($left, 'aaaaaaaa-0000-4000-8000-000000000001', now()->subSeconds(80));
        $job = $this->job($batch, $leader, $version, static fn (): string => ApplyModelDescriptionJob::RESULT_PUBLISHED);
        $job->setJob($this->queueJob(1, 'aaaaaaaa-0000-4000-8000-000000000001'));

        $job->handle($this->service(), app(ModelGroupPlanner::class));

        $this->assertSame(ProductEnrichmentBatchItem::STATUS_DONE, $this->item($waiting)->status);
        $this->assertSame(ProductEnrichmentBatchItem::STATUS_FAILED, $this->item($left)->status);
        $this->assertStringContainsString('przerwany bez wyniku', (string) $this->item($left)->message);
        $this->assertSame(Product::ENRICHMENT_FAILED, $left->fresh()->enrichment_status);
        $batch->refresh();
        $this->assertSame([2, 1, ProductEnrichmentBatch::STATUS_DONE], [$batch->done, $batch->failed, $batch->status]);
    }

    /**
     * Zadanie czeka w kolejce enrich za zadaniami liderów (albo wraca z harmonogramu z zapisanym numerem wersji) —
     * w tym czasie handlowiec mógł odrzucić opis lidera jako cudzą stronę; blokada adresu dotyczy tylko karty lidera.
     * Odrzucona wersja (status albo decyzja) to brak podstawy: członkowie dostają „błąd” z prośbą o ponowne pobranie,
     * opis nie jest stosowany.
     *
     * @param  array<string, string>  $rejection
     */
    #[DataProvider('rejectedVersions')]
    public function test_rejected_leader_version_fails_members_without_applying(array $rejection): void
    {
        [$batch, $leader, $version, $members] = $this->group(2);
        $version->forceFill($rejection)->save();
        $job = $this->job($batch, $leader, $version, static function (): string {
            throw new RuntimeException('odrzucona wersja lidera nie może trafić na kartę członka');
        });

        $job->handle($this->service(), app(ModelGroupPlanner::class));

        foreach ($members as $member) {
            $this->assertSame(ProductEnrichmentBatchItem::STATUS_FAILED, $this->item($member)->status);
            $this->assertSame("Opis lidera (wersja #{$version->id}) został odrzucony w przeglądzie — pobierz opisy modelu ponownie", $this->item($member)->message);
            $this->assertSame(Product::ENRICHMENT_FAILED, $member->fresh()->enrichment_status);
            $this->assertNull($member->fresh()->description);
        }
        $batch->refresh();
        $this->assertSame([1, 2, ProductEnrichmentBatch::STATUS_DONE], [$batch->done, $batch->failed, $batch->status]);
    }

    /** @return array<string, array{array<string, string>}> */
    public static function rejectedVersions(): array
    {
        return [
            'status rejected' => [['status' => ProductDescriptionVersion::STATUS_REJECTED]],
            'propozycja z decyzją rejected' => [['status' => ProductDescriptionVersion::STATUS_PROPOSED, 'decision' => ProductDescriptionVersion::DECISION_REJECTED]],
        ];
    }

    public function test_failed_marks_member_left_running_by_the_interrupted_attempt(): void
    {
        [$batch, $leader, $version, $members] = $this->group(2);
        [$interrupted, $waiting] = $members;
        ProductEnrichmentBatchItem::query()->where('product_id', $interrupted->id)->update(['status' => ProductEnrichmentBatchItem::STATUS_RUNNING]);
        $interrupted->update(['enrichment_status' => Product::ENRICHMENT_RUNNING]);

        (new ApplyModelDescriptionJob($batch->id, $leader->id, $version->id))->failed(new RuntimeException('Limit czasu zadania'));

        $this->assertSame(ProductEnrichmentBatchItem::STATUS_FAILED, $this->item($interrupted)->status);
        $this->assertSame('Opis wspólny modelu przerwany: Limit czasu zadania', $this->item($interrupted)->message);
        $this->assertSame(Product::ENRICHMENT_FAILED, $interrupted->fresh()->enrichment_status);
        // czekający członek zostaje z numerem wersji lidera — zleci go od nowa harmonogram
        $this->assertSame(ProductEnrichmentBatchItem::STATUS_QUEUED, $this->item($waiting)->status);
        $this->assertSame($version->id, (int) $this->item($waiting)->model_leader_version_id);
        $this->assertSame(Product::ENRICHMENT_QUEUED, $waiting->fresh()->enrichment_status);
    }

    /**
     * Lider z opisem i wersją, członkowie „queued” w pozycjach partii z kluczem modelu, liderem i jego wersją
     * (jak po EnrichProductJob::handOverModel); partia liczy lidera jako zrobionego.
     *
     * @return array{0: ProductEnrichmentBatch, 1: Product, 2: ProductDescriptionVersion, 3: list<Product>}
     */
    private function group(int $memberCount): array
    {
        $leader = $this->card('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m', ['description' => self::DESCRIPTION, 'enrichment_status' => Product::ENRICHMENT_DONE]);
        $version = app(DescriptionVersionStore::class)->record($leader, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::DESCRIPTION,
            'primary_source_url' => 'https://www.coba.com/product/orthomat-standard',
            'identity_verdict' => 'hard',
        ]);
        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCTS, 'scope_id' => 0, 'total' => 1 + $memberCount, 'done' => 1, 'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_RUNNING, 'force' => false,
        ]);
        ProductEnrichmentBatchItem::query()->create([
            'batch_id' => $batch->id, 'product_id' => $leader->id, 'sku' => $leader->sku, 'name' => $leader->name,
            'status' => ProductEnrichmentBatchItem::STATUS_DONE, 'model_key' => self::MODEL_KEY, 'model_leader_id' => $leader->id,
        ]);
        $members = [];
        for ($i = 2; $i <= $memberCount + 1; $i++) {
            $member = $this->card('AF06000'.$i, 'Orthomat Standard Czarny 0.9m x 1.'.$i.'m', ['description' => null, 'enrichment_status' => Product::ENRICHMENT_QUEUED]);
            ProductEnrichmentBatchItem::query()->create([
                'batch_id' => $batch->id, 'product_id' => $member->id, 'sku' => $member->sku, 'name' => $member->name,
                'status' => ProductEnrichmentBatchItem::STATUS_QUEUED, 'previous_status' => Product::ENRICHMENT_NONE,
                'model_key' => self::MODEL_KEY, 'model_leader_id' => $leader->id, 'model_leader_version_id' => $version->id,
            ]);
            $members[] = $member;
        }

        return [$batch, $leader, $version, $members];
    }

    /**
     * Zadanie z podstawionym wynikiem opisu członka (szew applyToMember) — serwis jest klasą final, więc nie da się
     * go częściowo zamockować; wywołanie dostaje prawdziwy serwis z kontenera. Numer próby i uuid daje atrapa zadania
     * kolejki (queueJob + setJob) — bez niej InteractsWithQueue oddaje próbę 1 i brak uuid.
     *
     * @param  callable(Product, ProductDescriptionVersion): string  $apply
     */
    private function job(ProductEnrichmentBatch $batch, Product $leader, ProductDescriptionVersion $version, callable $apply): ApplyModelDescriptionJob
    {
        return new class($batch->id, $leader->id, $version->id, $apply) extends ApplyModelDescriptionJob
        {
            /** @param  callable(Product, ProductDescriptionVersion): string  $apply */
            public function __construct(int $batchId, int $leaderId, int $leaderVersionId, private $apply)
            {
                parent::__construct($batchId, $leaderId, $leaderVersionId);
            }

            protected function applyToMember(ProductEnrichmentService $enrichment, Product $member, ProductDescriptionVersion $leaderVersion): ?string
            {
                return ($this->apply)($member, $leaderVersion);
            }
        };
    }

    /** Atrapa zadania kolejki na danej próbie z uuid stałym między próbami (jak kolejka bazodanowa, która oddaje ten sam ładunek). */
    private function queueJob(int $attempt, ?string $uuid): Job
    {
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempt);
        $queueJob->shouldReceive('uuid')->andReturn($uuid);
        $queueJob->shouldIgnoreMissing();

        return $queueJob;
    }

    /** Pozycja członka zostawiona w „running” przez (własną albo cudzą) przerwaną próbę o danym wieku. */
    private function running(Product $member, ?string $claimUuid, \DateTimeInterface $updatedAt): void
    {
        ProductEnrichmentBatchItem::query()->where('product_id', $member->id)->update([
            'status' => ProductEnrichmentBatchItem::STATUS_RUNNING,
            'model_claim_uuid' => $claimUuid,
            'updated_at' => $updatedAt,
        ]);
        $member->update(['enrichment_status' => Product::ENRICHMENT_RUNNING]);
    }

    private function service(): ProductEnrichmentService
    {
        return $this->service;
    }

    /** Nota propozycji jak z keepAsProposal — w polu tej samej instancji serwisu, której lastProposalNote() czyta zadanie. */
    private function setProposalNote(string $note): void
    {
        $property = new \ReflectionProperty(ProductEnrichmentService::class, 'lastProposalNote');
        $property->setValue($this->service, $note);
    }

    private function item(Product $product): ProductEnrichmentBatchItem
    {
        return ProductEnrichmentBatchItem::query()->where('product_id', $product->id)->firstOrFail();
    }

    /** @param  array<string, mixed>  $attributes */
    private function card(string $sku, string $name, array $attributes = []): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'Coba',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 0,
            ...$attributes,
        ]);
    }
}
