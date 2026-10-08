<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductDocument;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductImage;
use App\Services\Enrichment\DescriptionVersionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * products:rollback-batch (etap 2, 08.10.2026): karty z opisem z partii wracają do poprzedniego opublikowanego opisu
 * (nowa wersja origin restore) razem ze skutkami przebiegu (pliki z internetu dodane przez partię znikają, normy
 * producenta wracają) i z powodem przeglądu przywróconej wersji; karty bez poprzedniego opisu i ze zmienionym opisem
 * są pomijane, propozycje z partii dostają odrzucenie bez blokady adresu; podgląd bez --apply; partia w toku — odmowa.
 */
final class RollbackBatchCommandTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'Mata antyzmęczeniowa Orthomat Standard, pianka PVC, krawędzie skośne — opis sprzed partii.';

    private const NEW = 'Mata antyzmęczeniowa Orthomat Standard, pianka PVC, opis wspólny modelu z partii.';

    private DescriptionVersionStore $store;

    private ProductEnrichmentBatch $batch;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->store = app(DescriptionVersionStore::class);
        $this->batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRICE_LIST, 'scope_id' => 14, 'total' => 4, 'done' => 4, 'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_DONE, 'force' => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_previews_then_restores_previous_descriptions_and_rejects_proposals(): void
    {
        // A: opis sprzed partii (baza), potem opis z partii — wraca do bazy
        $restorable = $this->card('AF060001');
        $base = $this->published($restorable, self::OLD, ProductDescriptionVersion::ORIGIN_ENRICHMENT, null, 'https://www.coba.com/product/orthomat-standard');
        $fromBatch = $this->published($restorable, self::NEW, ProductDescriptionVersion::ORIGIN_MODEL_SHARED, $this->batch->id, 'https://www.coba.com/product/orthomat-standard');
        // B: tylko opis z partii — bez poprzedniego, pominięta
        $only = $this->card('AF060002');
        $onlyVersion = $this->published($only, self::NEW, ProductDescriptionVersion::ORIGIN_ENRICHMENT, $this->batch->id);
        // C: po partii opis zmienił się innym torem (ręcznie, synchronizacja) — wersja z partii bez bieżącej bazy, pominięta
        $changed = $this->card('AF060003');
        $this->published($changed, self::OLD, ProductDescriptionVersion::ORIGIN_ENRICHMENT, null);
        $this->published($changed, self::NEW, ProductDescriptionVersion::ORIGIN_MODEL_SHARED, $this->batch->id);
        $changed->update(['description' => 'Opis zmieniony ręcznie w karcie, mata antyzmęczeniowa czarna.']);
        // D: propozycja z partii czekająca na decyzję przy opisie z twardą bazą
        $proposed = $this->card('AF060004', ['review_reason' => Product::REVIEW_WORSE_VERSION, 'review_since' => now()]);
        $this->published($proposed, self::OLD, ProductDescriptionVersion::ORIGIN_ENRICHMENT, null, 'https://www.coba.com/product/orthomat-standard', 'hard');
        $proposal = $this->store->record($proposed, ProductDescriptionVersion::STATUS_PROPOSED, ProductDescriptionVersion::ORIGIN_MODEL_SHARED, [
            'description' => self::NEW, 'primary_source_url' => 'https://obcy.pl/x', 'identity_verdict' => 'none', 'batch_id' => $this->batch->id,
            'review_reason' => Product::REVIEW_WORSE_VERSION,
        ]);
        $versionsBefore = ProductDescriptionVersion::query()->count();

        // jedno oczekiwanie na linię: atrapa wyjścia dopasowuje do zapisu tylko pierwsze pasujące oczekiwanie
        $this->artisan('products:rollback-batch', ['batch' => $this->batch->id, '--reject-proposals' => true])
            ->expectsOutputToContain('opisów z partii na kartach 3 — do wycofania 1, pominiętych 2; propozycji do odrzucenia 1')
            ->expectsOutputToContain("| AF060001 | model_shared #{$fromBatch->id} | enrichment #{$base->id} (")
            ->expectsOutputToContain('brak poprzedniego opisu')
            ->expectsOutputToContain('opis karty zmienił się od partii')
            ->expectsOutputToContain('Podgląd — nic nie zapisano')
            ->assertSuccessful();
        $this->assertSame($versionsBefore, ProductDescriptionVersion::query()->count());
        $this->assertSame(self::NEW, $restorable->fresh()->description);
        $this->assertSame(ProductDescriptionVersion::STATUS_PROPOSED, $proposal->fresh()->status);

        $log = $this->tempPath();
        $this->artisan('products:rollback-batch', ['batch' => $this->batch->id, '--reject-proposals' => true, '--apply' => true, '--log' => $log])
            ->expectsOutputToContain('Wycofano: 1 kart (pominięto 0 — opis zmienił się w trakcie), odrzucono propozycji: 1')
            ->assertSuccessful();

        $restorable->refresh();
        $this->assertSame(self::OLD, $restorable->description);
        $current = $this->store->current($restorable);
        $this->assertSame(ProductDescriptionVersion::ORIGIN_RESTORE, $current->origin);
        $this->assertSame("wycofanie partii #{$this->batch->id} — z wersji #{$base->id}", $current->reason);
        $this->assertSame($base->description_sha1, $current->description_sha1);
        // wersja z partii zostaje w historii — da się ją przywrócić w karcie
        $this->assertSame(ProductDescriptionVersion::STATUS_SUPERSEDED, $fromBatch->fresh()->status);
        $this->assertSame(self::NEW, $only->fresh()->description);
        $this->assertSame(ProductDescriptionVersion::STATUS_PUBLISHED, $onlyVersion->fresh()->status);
        $this->assertSame('Opis zmieniony ręcznie w karcie, mata antyzmęczeniowa czarna.', $changed->fresh()->description);
        // propozycja odrzucona bez blokady adresu, powód przeglądu z propozycji znika (obecny opis z twardym werdyktem)
        $proposal->refresh();
        $this->assertSame(ProductDescriptionVersion::STATUS_REJECTED, $proposal->status);
        $this->assertSame(ProductDescriptionVersion::DECISION_REJECTED, $proposal->decision);
        $this->assertNull($proposal->decided_by);
        $this->assertStringContainsString("wycofanie partii #{$this->batch->id}", (string) $proposal->reason);
        $this->assertSame([], $this->store->rejectedUrls($proposed));
        $this->assertNull($proposed->fresh()->review_reason);
        $this->assertSame(self::OLD, $proposed->fresh()->description);
        $lines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('"label":"rollback-batch"', $lines[0]);
        $this->assertSame([
            'product_id' => $restorable->id, 'sku' => 'AF060001', 'batch_version_id' => $fromBatch->id, 'restored_version_id' => $base->id,
            'dropped_image_ids' => [], 'dropped_document_ids' => [],
        ], json_decode($lines[1], true));
        $this->assertSame(['product_id' => $proposed->id, 'rejected_proposal_id' => $proposal->id], json_decode($lines[2], true));

        // drugie uruchomienie: wersja A już wycofana (superseded), B i C dalej na kartach, ale nic do wycofania
        $this->artisan('products:rollback-batch', ['batch' => $this->batch->id, '--apply' => true, '--log' => $this->tempPath()])
            ->expectsOutputToContain('opisów z partii na kartach 2 — do wycofania 0, pominiętych 2')
            ->assertSuccessful();
        $this->assertSame(self::OLD, $restorable->fresh()->description);
    }

    public function test_rejected_proposal_leaves_review_reason_of_soft_current_description(): void
    {
        $card = $this->card('AF060005', ['review_reason' => Product::REVIEW_WORSE_VERSION, 'review_since' => now()->subDay()]);
        $this->published($card, self::OLD, ProductDescriptionVersion::ORIGIN_ENRICHMENT, null, 'https://sklep.pl/a', 'soft');
        $this->store->record($card, ProductDescriptionVersion::STATUS_PROPOSED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::NEW, 'identity_verdict' => 'none', 'batch_id' => $this->batch->id, 'review_reason' => Product::REVIEW_WORSE_VERSION,
        ]);

        $this->artisan('products:rollback-batch', ['batch' => $this->batch->id, '--reject-proposals' => true, '--apply' => true, '--log' => $this->tempPath()])
            ->assertSuccessful();

        $this->assertSame(Product::REVIEW_IDENTITY_SOFT, $card->fresh()->review_reason);
    }

    public function test_apply_drops_files_added_by_batch_restores_manufacturer_norms_and_review_reason_of_restored_version(): void
    {
        Storage::fake('public');
        $normsBefore = ['rows' => [['norm' => 'EN 14041', 'source' => 'strona-producenta']]];
        $card = $this->card('AF060010', ['manufacturer_norms' => $normsBefore]);
        // opis sprzed partii ze strony bez kodu (soft), bez decyzji człowieka — po wycofaniu karta wraca z tym powodem
        $base = $this->published($card, self::OLD, ProductDescriptionVersion::ORIGIN_ENRICHMENT, null, 'https://www.coba.com/product/orthomat-standard', 'soft');
        $kept = ProductImage::query()->create(['product_id' => $card->id, 'path' => "products/{$card->id}/stare.jpg", 'source_url' => 'https://www.coba.com/stare.jpg', 'checksum' => 'stare', 'sort_order' => 0]);
        Storage::disk('public')->put($kept->path, 'jpg');
        $before = $this->store->snapshot($card);

        // przebieg partii (opis wspólny od lidera, twardy werdykt): kopia zdjęcia lidera, karta techniczna, normy przepisane
        $batchImage = ProductImage::query()->create(['product_id' => $card->id, 'path' => "products/{$card->id}/lider-szary.jpg", 'source_url' => 'https://www.coba.com/lider-szary.jpg', 'checksum' => 'szary', 'sort_order' => 1]);
        $batchDoc = ProductDocument::query()->create(['product_id' => $card->id, 'path' => "products/{$card->id}/docs/karta.pdf",
            'source_url' => 'https://www.coba.com/orthomat-karta.pdf', 'title' => 'Karta techniczna', 'kind' => ProductDocument::KIND_DATASHEET]);
        Storage::disk('public')->put($batchImage->path, 'jpg');
        Storage::disk('public')->put($batchDoc->path, 'pdf');
        $written = ['rows' => [['norm' => 'EN 13893', 'source' => 'strona-producenta']]];
        $card->update(['manufacturer_norms' => $written]);
        $meta = $this->store->versionMeta($card, $before, ['manufacturer_norms' => $written]);
        $this->assertSame(['images' => [$batchImage->id], 'documents' => [$batchDoc->id]], $meta['web_file_ids']);
        $card->update(['description' => self::NEW]);
        $fromBatch = $this->store->record($card, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_MODEL_SHARED, [
            'description' => self::NEW, 'primary_source_url' => 'https://www.coba.com/product/orthomat-standard', 'identity_verdict' => 'hard',
            'batch_id' => $this->batch->id, 'enrichment_payload' => ['document_urls' => ['https://www.coba.com/orthomat-karta.pdf']], '_version' => $meta,
        ]);
        $this->assertNull($card->fresh()->review_reason);

        // podgląd: liczba plików przy karcie i uwaga o plikach usuniętych przez przebieg z force; nic nie znika
        // (pełne wyjście, bo atrapa wyjścia dopasowuje do jednej linii tylko pierwsze pasujące oczekiwanie)
        $this->assertSame(0, Artisan::call('products:rollback-batch', ['batch' => $this->batch->id]));
        $output = Artisan::output();
        $this->assertStringContainsString("| AF060010 | model_shared #{$fromBatch->id} | enrichment #{$base->id} (", $output);
        $this->assertStringContainsString('| 2 (1 zdj., 1 dok.) |', $output);
        $this->assertStringContainsString('(razem 2 na 1 kartach), normy producenta wracają do stanu sprzed przebiegu, gdy nikt ich potem nie zmienił. '
            .'Zdjęć i plików z internetu, które przebieg z force usunął z karty przed zapisem nowego opisu, wycofanie nie przywraca.', $output);
        $this->assertStringContainsString('Podgląd — nic nie zapisano', $output);
        $this->assertNotNull($batchImage->fresh());
        Storage::disk('public')->assertExists($batchImage->path);

        $log = $this->tempPath();
        $this->artisan('products:rollback-batch', ['batch' => $this->batch->id, '--apply' => true, '--log' => $log])
            ->expectsOutputToContain('Wycofano: 1 kart (pominięto 0 — opis zmienił się w trakcie), usunięto plików: 2.')
            ->assertSuccessful();

        $card->refresh();
        $this->assertSame(self::OLD, $card->description);
        $this->assertSame(ProductDescriptionVersion::ORIGIN_RESTORE, $this->store->current($card)?->origin);
        // pliki z partii znikają z karty i z dysku, zdjęcie sprzed partii zostaje
        $this->assertNull($batchImage->fresh());
        $this->assertNull($batchDoc->fresh());
        Storage::disk('public')->assertMissing($batchImage->path);
        Storage::disk('public')->assertMissing($batchDoc->path);
        $this->assertNotNull($kept->fresh());
        Storage::disk('public')->assertExists($kept->path);
        $this->assertSame([], $card->enrichment_payload['document_urls'] ?? []);
        $this->assertSame($normsBefore, $card->manufacturer_norms);
        // powód przeglądu przywróconej wersji (soft, nikt nie zatwierdzał), liczony od wycofania
        $this->assertSame(Product::REVIEW_IDENTITY_SOFT, $card->review_reason);
        $this->assertNotNull($card->review_since);
        // dziennik z numerami usuniętych plików
        $lines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $this->assertCount(2, $lines);
        $this->assertSame([
            'product_id' => $card->id, 'sku' => 'AF060010', 'batch_version_id' => $fromBatch->id, 'restored_version_id' => $base->id,
            'dropped_image_ids' => [$batchImage->id], 'dropped_document_ids' => [$batchDoc->id],
        ], json_decode($lines[1], true));
    }

    public function test_mixed_batch_restores_members_rejects_proposals_and_keeps_human_approved_description_out_of_review(): void
    {
        // lider: przebieg skończył się propozycją przy twardej bazie
        $leader = $this->card('AF060020', ['review_reason' => Product::REVIEW_WORSE_VERSION, 'review_since' => now()]);
        $this->published($leader, self::OLD, ProductDescriptionVersion::ORIGIN_ENRICHMENT, null, 'https://www.coba.com/product/orthomat-standard', 'hard');
        $leaderProposal = $this->store->record($leader, ProductDescriptionVersion::STATUS_PROPOSED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::NEW, 'primary_source_url' => 'https://obcy.pl/x', 'identity_verdict' => 'none', 'batch_id' => $this->batch->id,
            'review_reason' => Product::REVIEW_WORSE_VERSION,
        ]);
        // członek z opisem z partii; poprzedni opis (soft) zatwierdził handlowiec — po wycofaniu nie wraca do przeglądu
        $published = $this->card('AF060021');
        $approved = $this->published($published, self::OLD, ProductDescriptionVersion::ORIGIN_ENRICHMENT, null, 'https://www.coba.com/product/orthomat-standard', 'soft');
        $approved->forceFill(['decision' => ProductDescriptionVersion::DECISION_APPROVED, 'decided_at' => now()])->save();
        $memberVersion = $this->published($published, self::NEW, ProductDescriptionVersion::ORIGIN_MODEL_SHARED, $this->batch->id, 'https://www.coba.com/product/orthomat-standard', 'hard');
        // członek z propozycją z partii
        $proposed = $this->card('AF060022', ['review_reason' => Product::REVIEW_WORSE_VERSION, 'review_since' => now()]);
        $this->published($proposed, self::OLD, ProductDescriptionVersion::ORIGIN_ENRICHMENT, null, 'https://www.coba.com/product/orthomat-standard', 'hard');
        $memberProposal = $this->store->record($proposed, ProductDescriptionVersion::STATUS_PROPOSED, ProductDescriptionVersion::ORIGIN_MODEL_SHARED, [
            'description' => self::NEW, 'identity_verdict' => 'none', 'batch_id' => $this->batch->id, 'review_reason' => Product::REVIEW_WORSE_VERSION,
        ]);
        // członek, którego opis sprzed partii miał tę samą treść co opis z partii — wraca do wcześniejszego, innego opisu
        $sameText = $this->card('AF060023');
        $older = $this->published($sameText, self::OLD, ProductDescriptionVersion::ORIGIN_ENRICHMENT, null);
        $this->published($sameText, self::NEW, ProductDescriptionVersion::ORIGIN_ENRICHMENT, null);
        $this->published($sameText, self::NEW, ProductDescriptionVersion::ORIGIN_MODEL_SHARED, $this->batch->id);
        // członek tylko z tą samą treścią w historii — nie ma do czego wrócić, pominięty z uwagą
        $onlySame = $this->card('AF060024');
        $this->published($onlySame, self::NEW, ProductDescriptionVersion::ORIGIN_ENRICHMENT, null);
        $onlySameVersion = $this->published($onlySame, self::NEW, ProductDescriptionVersion::ORIGIN_MODEL_SHARED, $this->batch->id);

        $log = $this->tempPath();
        $this->assertSame(0, Artisan::call('products:rollback-batch', ['batch' => $this->batch->id, '--reject-proposals' => true, '--apply' => true, '--log' => $log]));
        $output = Artisan::output();
        $this->assertStringContainsString('opisów z partii na kartach 3 — do wycofania 2, pominiętych 1; propozycji do odrzucenia 2', $output);
        $this->assertMatchesRegularExpression('/\| AF060021 \| model_shared #'.$memberVersion->id.' +\| enrichment #'.$approved->id.' \(/u', $output);
        $this->assertStringContainsString('| AF060023 | model_shared #', $output);
        $this->assertMatchesRegularExpression('/\| AF060024 \| model_shared #'.$onlySameVersion->id.' +\| — +\| — +\| brak poprzedniego opisu — pominięta \(zerowanie: „Odrzuć” w przeglądzie\)/u', $output);
        $this->assertStringContainsString('Wycofano: 2 kart (pominięto 0 — opis zmienił się w trakcie), odrzucono propozycji: 2, usunięto plików: 0.', $output);

        $published->refresh();
        $this->assertSame(self::OLD, $published->description);
        $this->assertSame(ProductDescriptionVersion::STATUS_SUPERSEDED, $memberVersion->fresh()->status);
        $this->assertNull($published->review_reason);
        $sameText->refresh();
        $this->assertSame(self::OLD, $sameText->description);
        $this->assertSame($older->description_sha1, $this->store->current($sameText)?->description_sha1);
        $this->assertSame(self::NEW, $onlySame->fresh()->description);
        $this->assertSame(ProductDescriptionVersion::STATUS_PUBLISHED, $onlySameVersion->fresh()->status);
        foreach ([[$leader, $leaderProposal], [$proposed, $memberProposal]] as [$card, $proposal]) {
            $proposal->refresh();
            $this->assertSame(ProductDescriptionVersion::STATUS_REJECTED, $proposal->status);
            $this->assertSame(ProductDescriptionVersion::DECISION_REJECTED, $proposal->decision);
            $card->refresh();
            $this->assertSame(self::OLD, $card->description);
            $this->assertNull($card->review_reason);
        }
        $this->assertCount(5, file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    }

    public function test_apply_refuses_batch_in_progress_but_preview_works(): void
    {
        $card = $this->card('AF060030');
        $this->published($card, self::OLD, ProductDescriptionVersion::ORIGIN_ENRICHMENT, null);
        $this->published($card, self::NEW, ProductDescriptionVersion::ORIGIN_MODEL_SHARED, $this->batch->id);
        $versionsBefore = ProductDescriptionVersion::query()->count();

        foreach ([ProductEnrichmentBatch::STATUS_QUEUED, ProductEnrichmentBatch::STATUS_RUNNING] as $status) {
            $this->batch->update(['status' => $status]);
            $this->artisan('products:rollback-batch', ['batch' => $this->batch->id])
                ->expectsOutputToContain('do wycofania 1')
                ->expectsOutputToContain('Podgląd — nic nie zapisano')
                ->assertSuccessful();
            $this->artisan('products:rollback-batch', ['batch' => $this->batch->id, '--apply' => true, '--log' => $this->tempPath()])
                ->expectsOutputToContain("Partia #{$this->batch->id} jest w toku ({$status})")
                ->assertFailed();
            $this->assertSame(self::NEW, $card->fresh()->description);
            $this->assertSame($versionsBefore, ProductDescriptionVersion::query()->count());
        }
    }

    public function test_unknown_batch_fails(): void
    {
        $this->artisan('products:rollback-batch', ['batch' => 999])->expectsOutputToContain('Nie ma partii #999')->assertFailed();
    }

    /** @param  array<string, mixed>  $attributes */
    private function card(string $sku, array $attributes = []): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Orthomat Standard '.$sku,
            'manufacturer' => 'Coba',
            'description' => self::OLD,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 0,
            ...$attributes,
        ]);
    }

    /** Wersja published z tekstem na karcie — jak zapis przebiegu (poprzednia published → superseded). */
    private function published(Product $card, string $description, string $origin, ?int $batchId, ?string $url = null, ?string $verdict = null): ProductDescriptionVersion
    {
        $card->update(['description' => $description]);

        return $this->store->record($card, ProductDescriptionVersion::STATUS_PUBLISHED, $origin, [
            'description' => $description, 'primary_source_url' => $url, 'identity_verdict' => $verdict, 'batch_id' => $batchId,
        ]);
    }

    private function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rollback');
        @unlink($path);
        $this->files[] = $path;

        return $path;
    }
}
