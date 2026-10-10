<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\PriceListFile;
use App\Models\User;
use App\Services\PriceLists\Importers\PriceListFormatChanged;
use App\Services\PriceLists\IntakeBusy;
use App\Services\PriceLists\IntakeNotReady;
use App\Services\PriceLists\PriceListFileStore;
use App\Services\PriceLists\PriceListIntakeRunner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pliki cennika nowym sposobem (10.10.2026): zapis na dysku po sha256, ten sam plik → 200, poprzedni `new` →
 * superseded, pobranie, podgląd i import przez PriceListIntakeRunner (atrapa) i sprzątanie plików przy usunięciu cennika.
 */
final class PriceListIntakeFilesTest extends TestCase
{
    use RefreshDatabase;

    private const RUNNER = 'App\Services\PriceLists\PriceListIntakeRunner';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($this->admin);
    }

    public function test_upload_stores_file_by_sha_dedups_and_supersedes_previous_new_file(): void
    {
        $list = $this->intakeList('MAPA');

        $first = $this->upload($list, 'MAPA cennik 2025.XLSX', 'zawartość pierwsza')->assertCreated();
        $sha = hash('sha256', 'zawartość pierwsza');
        $file = PriceListFile::query()->sole();
        $this->assertSame($sha, $file->sha256);
        $this->assertSame('local', $file->disk);
        $this->assertSame('price-list-files/'.$sha.'.xlsx', $file->path);
        $this->assertSame('MAPA cennik 2025.XLSX', $file->original_name);
        $this->assertSame(strlen('zawartość pierwsza'), $file->size);
        $this->assertSame(PriceListFile::STATUS_NEW, $file->status);
        $this->assertSame($this->admin->id, (int) $file->uploaded_by);
        Storage::disk('local')->assertExists($file->path);
        $this->assertSame('zawartość pierwsza', Storage::disk('local')->get($file->path));

        $this->assertSame([
            'id', 'original_name', 'sha256', 'size', 'status', 'error', 'importer_key', 'importer_version', 'imported_at',
            'created_at', 'uploaded_by_name',
        ], array_keys($first->json('file')));
        $first->assertJsonPath('file.uploaded_by_name', $this->admin->name)
            ->assertJsonPath('intake.status', PriceList::INTAKE_AWAITING_IMPORTER)
            ->assertJsonPath('intake.latest_file.id', $file->id);

        // ten sam plik (inna nazwa) → istniejący wiersz, 200
        $first->assertJsonPath('duplicate', false);
        $this->upload($list, 'kopia.xlsx', 'zawartość pierwsza')->assertOk()
            ->assertJsonPath('file.id', $file->id)
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('intake.latest_file.id', $file->id);
        $this->assertSame(1, PriceListFile::query()->count());

        // nowy plik zastępuje poprzedni `new`
        $second = $this->upload($list, 'MAPA 2026.csv', 'zawartość druga')->assertCreated();
        $this->assertSame(PriceListFile::STATUS_SUPERSEDED, $file->fresh()->status);
        $this->assertSame(PriceListFile::STATUS_NEW, PriceListFile::query()->findOrFail($second->json('file.id'))->status);
        $second->assertJsonPath('intake.latest_file.original_name', 'MAPA 2026.csv');

        $this->getJson("/api/price-lists/{$list->id}/files")
            ->assertOk()
            ->assertJsonCount(2, 'files')
            ->assertJsonPath('files.0.original_name', 'MAPA 2026.csv')
            ->assertJsonPath('files.1.status', PriceListFile::STATUS_SUPERSEDED);
    }

    public function test_reupload_of_older_file_during_import_is_busy_and_changes_nothing(): void
    {
        $list = $this->intakeList('MAPA');
        $a = (int) $this->upload($list, 'A.xlsx', 'plik A')->assertCreated()->json('file.id');
        $b = (int) $this->upload($list, 'B.xlsx', 'plik B')->assertCreated()->json('file.id');
        // import tego cennika trzyma blokadę — wiersz pliku nie może zniknąć pod runnerem
        $lock = Cache::lock(PriceListIntakeRunner::LOCK_PREFIX.$list->id, 60);
        $this->assertTrue($lock->get());

        try {
            $this->upload($list, 'A znowu.xlsx', 'plik A')->assertStatus(409);
        } finally {
            $lock->release();
        }

        $this->assertSame([$b, $a], PriceListFile::query()->orderByDesc('id')->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(PriceListFile::STATUS_NEW, PriceListFile::query()->findOrFail($b)->status);
    }

    public function test_reupload_of_older_not_imported_file_makes_it_the_latest_again(): void
    {
        $list = $this->intakeList('MAPA');
        $this->upload($list, 'A.xlsx', 'plik A')->assertCreated();
        $b = (int) $this->upload($list, 'B.xlsx', 'plik B')->assertCreated()->json('file.id');
        $kierownik = User::factory()->withRole('kierownik')->create();
        Sanctum::actingAs($kierownik);

        $again = $this->upload($list, 'A znowu.xlsx', 'plik A')
            ->assertCreated()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('file.status', PriceListFile::STATUS_NEW)
            ->assertJsonPath('intake.latest_file.original_name', 'A znowu.xlsx');
        $aId = (int) $again->json('file.id');
        $this->assertGreaterThan($b, $aId);

        $files = $this->getJson("/api/price-lists/{$list->id}/files")->assertOk()->json('files');
        $this->assertCount(2, $files);
        $this->assertSame([$aId, PriceListFile::STATUS_NEW], [$files[0]['id'], $files[0]['status']]);
        $this->assertSame([$b, PriceListFile::STATUS_SUPERSEDED], [$files[1]['id'], $files[1]['status']]);
        $a = PriceListFile::query()->findOrFail($aId);
        $this->assertSame($kierownik->id, (int) $a->uploaded_by);
        $this->assertSame('price-list-files/'.hash('sha256', 'plik A').'.xlsx', $a->path);
        Storage::disk('local')->assertExists($a->path);

        // import A działa (nie jest już zastąpiony)
        $runner = $this->fakeRunner(static fn (): array => [], static fn (): array => ['created' => 1]);
        $this->postJson("/api/price-lists/{$list->id}/files/{$aId}/import")->assertCreated();
        $this->assertSame($aId, $runner->calls[0][2]->id);
    }

    public function test_reupload_of_older_imported_file_is_a_conflict(): void
    {
        $list = $this->intakeList('MAPA');
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(14, 5));
        $old = (int) $this->upload($list, 'stary.xlsx', 'stary')->json('file.id');
        $this->travelBack();
        PriceListFile::query()->whereKey($old)->update(['status' => PriceListFile::STATUS_IMPORTED]);
        $this->upload($list, 'nowy.xlsx', 'nowy')->assertCreated();

        $response = $this->upload($list, 'stary-kopia.xlsx', 'stary')
            ->assertStatus(409)
            ->assertJsonPath('file.id', $old)
            ->assertJsonPath('file.status', PriceListFile::STATUS_IMPORTED);
        $this->assertStringStartsWith('Ten plik był już dodany 09.10.2026 14:05 i jest starszy niż ostatni', (string) $response->json('message'));
        // nic się nie zmieniło: najnowszy plik dalej `new`
        $this->assertSame(2, PriceListFile::query()->count());
        $this->assertSame(PriceListFile::STATUS_NEW, PriceListFile::query()->orderByDesc('id')->value('status'));
    }

    public function test_imported_file_is_not_superseded_by_a_new_upload(): void
    {
        $list = $this->intakeList('MAPA');
        $this->upload($list, 'a.xlsx', 'A')->assertCreated();
        $imported = PriceListFile::query()->sole();
        $imported->forceFill(['status' => PriceListFile::STATUS_IMPORTED])->save();

        $this->upload($list, 'b.xlsx', 'B')->assertCreated();

        $this->assertSame(PriceListFile::STATUS_IMPORTED, $imported->fresh()->status);
    }

    public function test_upload_requires_intake_list_allowed_extension_and_permission(): void
    {
        $legacy = PriceList::query()->create([
            'manufacturer' => 'ARTRA', 'manufacturer_key' => 'artra', 'version' => '1', 'rows_total' => 0,
            'products_created' => 0, 'products_updated' => 0, 'rows_skipped' => 0,
        ]);
        $this->upload($legacy, 'artra.xlsx', 'x')->assertStatus(422);

        $list = $this->intakeList('MAPA');
        $this->upload($list, 'mapa.docx', 'x')->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson("/api/price-lists/{$list->id}/files", [])->assertStatus(422)->assertJsonValidationErrors('file');

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->upload($list, 'mapa.xlsx', 'x')->assertForbidden();

        $this->assertSame(0, PriceListFile::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_download_returns_original_name_and_checks_owner_list(): void
    {
        $list = $this->intakeList('MAPA');
        $other = $this->intakeList('Inny');
        $fileId = $this->upload($list, 'MAPA cennik.xlsx', 'treść pliku')->json('file.id');

        $response = $this->get("/api/price-lists/{$list->id}/files/{$fileId}/download")->assertOk();
        $this->assertStringContainsString('MAPA cennik.xlsx', (string) $response->headers->get('content-disposition'));
        $this->assertSame('treść pliku', $response->streamedContent());

        $this->getJson("/api/price-lists/{$other->id}/files/{$fileId}/download")->assertNotFound();
    }

    public function test_preview_and_import_go_through_runner_and_map_errors(): void
    {
        $list = $this->intakeList('MAPA');
        $fileId = (int) $this->upload($list, 'mapa.xlsx', 'treść')->json('file.id');
        $preview = ['importer' => ['key' => 'mapa-2025', 'version' => 1], 'rows_total' => 3];
        $result = ['created' => 2, 'updated' => 1, 'skipped' => 0, 'errors' => [], 'price_changes' => [], 'map_job' => 'queued'];

        $runner = $this->fakeRunner(static fn (): array => $preview, static fn (): array => $result);

        $this->postJson("/api/price-lists/{$list->id}/files/{$fileId}/preview", ['limit' => 50])->assertOk()->assertExactJson($preview);
        $this->postJson("/api/price-lists/{$list->id}/files/{$fileId}/import", ['describe' => true])->assertCreated()->assertExactJson($result);

        $this->assertCount(2, $runner->calls);
        [$method, $l, $f, $limit] = $runner->calls[0];
        $this->assertSame(['preview', $list->id, $fileId, 50], [$method, $l->id, $f->id, $limit]);
        [$method, $l, $f, $user, $describe] = $runner->calls[1];
        $this->assertSame(['import', $list->id, $fileId, $this->admin->id, true], [$method, $l->id, $f->id, $user->id, $describe]);

        // domyślny limit podglądu i describe=false
        $this->postJson("/api/price-lists/{$list->id}/files/{$fileId}/preview")->assertOk();
        $this->postJson("/api/price-lists/{$list->id}/files/{$fileId}/import")->assertCreated();
        $this->assertSame(200, $runner->calls[2][3]);
        $this->assertFalse($runner->calls[3][4]);
    }

    public function test_runner_errors_map_to_422_and_409(): void
    {
        $list = $this->intakeList('MAPA');
        $fileId = (int) $this->upload($list, 'mapa.xlsx', 'treść')->json('file.id');

        $this->fakeRunner(
            static fn (): array => throw PriceListFormatChanged::because('brak kolumny „Cena”'),
            static fn (): array => throw new IntakeNotReady('Cennik nie ma importera — przygotuje go programista.'),
        );

        $this->postJson("/api/price-lists/{$list->id}/files/{$fileId}/preview")
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Format pliku się zmienił: brak kolumny „Cena”']);
        $this->postJson("/api/price-lists/{$list->id}/files/{$fileId}/import", ['describe' => false])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Cennik nie ma importera — przygotuje go programista.']);
    }

    public function test_preview_of_file_missing_on_disk_returns_message_not_server_error(): void
    {
        $list = $this->intakeList('MAPA');
        $list->forceFill(['importer_key' => 'mapa-2025'])->save();
        $fileId = (int) $this->upload($list, 'mapa.xlsx', 'treść')->json('file.id');
        Storage::disk('local')->delete(PriceListFile::query()->findOrFail($fileId)->path);

        // prawdziwy PriceListIntakeRunner z importerem MAPA
        $response = $this->postJson("/api/price-lists/{$list->id}/files/{$fileId}/preview")->assertStatus(422);
        // dawniej 500 — wyjątek spoza PriceListFormatChanged/IntakeNotReady
        $this->assertStringStartsWith('Podgląd nieudany: ', (string) $response->json('message'));
    }

    public function test_preview_failure_is_reported_as_422(): void
    {
        $list = $this->intakeList('MAPA');
        $fileId = (int) $this->upload($list, 'mapa.xlsx', 'treść')->json('file.id');
        $this->fakeRunner(static fn (): array => throw new \RuntimeException('Zapis w podglądzie: insert into products'), static fn (): array => []);

        $this->postJson("/api/price-lists/{$list->id}/files/{$fileId}/preview")
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Podgląd nieudany: Zapis w podglądzie: insert into products']);
    }

    public function test_busy_import_lock_maps_to_409(): void
    {
        $list = $this->intakeList('MAPA');
        $fileId = (int) $this->upload($list, 'mapa.xlsx', 'treść')->json('file.id');
        $this->fakeRunner(static fn (): array => [], static fn (): array => throw new IntakeBusy('Import cennika MAPA już trwa.'));

        $this->postJson("/api/price-lists/{$list->id}/files/{$fileId}/import")
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Import cennika MAPA już trwa.']);
    }

    public function test_other_import_failure_returns_message_instead_of_server_error(): void
    {
        $list = $this->intakeList('MAPA');
        $fileId = (int) $this->upload($list, 'mapa.xlsx', 'treść')->json('file.id');
        $this->fakeRunner(static fn (): array => [], static fn (): array => throw new \RuntimeException('Zakleszczenie bazy'));

        $this->postJson("/api/price-lists/{$list->id}/files/{$fileId}/import")
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Import nieudany: Zakleszczenie bazy']);
    }

    public function test_runner_is_not_called_for_foreign_superseded_or_legacy(): void
    {
        $runner = $this->fakeRunner(static fn (): array => [], static fn (): array => []);

        $list = $this->intakeList('MAPA');
        $other = $this->intakeList('Inny');
        $oldId = (int) $this->upload($list, 'stary.xlsx', 'stary')->json('file.id');
        $this->upload($list, 'nowy.xlsx', 'nowy')->assertCreated();

        $this->postJson("/api/price-lists/{$other->id}/files/{$oldId}/preview")->assertNotFound();
        $this->postJson("/api/price-lists/{$list->id}/files/{$oldId}/import", ['describe' => false])->assertStatus(422);

        // cennik wrócił do dawnego sposobu (np. ręcznie w bazie) — podgląd i import nowym sposobem zablokowane
        $list->forceFill(['source_policy' => null])->save();
        $newId = (int) PriceListFile::query()->where('status', PriceListFile::STATUS_NEW)->value('id');
        $this->postJson("/api/price-lists/{$list->id}/files/{$newId}/preview")->assertStatus(422);
        $this->postJson("/api/price-lists/{$list->id}/files/{$newId}/import")->assertStatus(422);

        $this->assertSame([], $runner->calls);
    }

    public function test_destroy_removes_list_files_from_disk_but_keeps_file_shared_with_other_list(): void
    {
        $list = $this->intakeList('MAPA');
        $other = $this->intakeList('Inny');
        $this->upload($list, 'tylko-mapa.xlsx', 'tylko mapa')->assertCreated();
        $this->upload($list, 'wspolny.xlsx', 'wspólny')->assertCreated();
        $this->upload($other, 'wspolny.xlsx', 'wspólny')->assertCreated();
        $own = 'price-list-files/'.hash('sha256', 'tylko mapa').'.xlsx';
        $shared = 'price-list-files/'.hash('sha256', 'wspólny').'.xlsx';
        Storage::disk('local')->assertExists([$own, $shared]);

        $this->deleteJson("/api/price-lists/{$list->id}")->assertOk();

        $this->assertNull(PriceList::query()->find($list->id));
        $this->assertSame(1, PriceListFile::query()->count());
        Storage::disk('local')->assertMissing($own);
        Storage::disk('local')->assertExists($shared);
    }

    public function test_file_store_absolute_path(): void
    {
        $list = $this->intakeList('MAPA');
        $this->upload($list, 'mapa.xlsx', 'treść')->assertCreated();
        $file = PriceListFile::query()->sole();

        $path = app(PriceListFileStore::class)->absolutePath($file);
        $this->assertFileExists($path);
        $this->assertSame('treść', file_get_contents($path));

        Storage::disk('local')->delete($file->path);
        $this->expectException(\RuntimeException::class);
        app(PriceListFileStore::class)->absolutePath($file);
    }

    private function intakeList(string $manufacturer): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => $manufacturer,
            'manufacturer_key' => PriceList::manufacturerKey($manufacturer),
            'version' => '2025',
            'rows_total' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'rows_skipped' => 0,
            'product_ids' => [],
            'source_policy' => PriceList::POLICY_MAP_ONLY,
        ])->fresh();
    }

    /**
     * Atrapa PriceListIntakeRunner (klasa agenta B jest final — Mockery jej nie podrobi); kontroler bierze ją
     * z kontenera po nazwie klasy.
     *
     * @param  callable(): array<string, mixed>  $preview
     * @param  callable(): array<string, mixed>  $import
     */
    private function fakeRunner(callable $preview, callable $import): object
    {
        $runner = new class($preview, $import)
        {
            /** @var list<list<mixed>> */
            public array $calls = [];

            public function __construct(private $onPreview, private $onImport) {}

            /** @return array<string, mixed> */
            public function preview(PriceList $list, PriceListFile $file, int $limit = 200): array
            {
                $this->calls[] = ['preview', $list, $file, $limit];

                return ($this->onPreview)();
            }

            /** @return array<string, mixed> */
            public function import(PriceList $list, PriceListFile $file, User $user, bool $describe): array
            {
                $this->calls[] = ['import', $list, $file, $user, $describe];

                return ($this->onImport)();
            }
        };
        $this->app->instance(self::RUNNER, $runner);

        return $runner;
    }

    private function upload(PriceList $list, string $name, string $content): TestResponse
    {
        return $this->postJson("/api/price-lists/{$list->id}/files", [
            'file' => UploadedFile::fake()->createWithContent($name, $content),
        ]);
    }
}
