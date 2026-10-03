<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProcurementNotice;
use App\Models\Tender;
use App\Models\TenderActivity;
use App\Models\TenderCondition;
use App\Models\TenderDocument;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Bzp\BzpNoticeParser;
use App\Services\Bzp\BzpNoticeStore;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * „Załóż przetarg z pozycjami”: przetarg z ogłoszenia + dokumenty postępowania z e-Zamówień w ścieżce dokumentów
 * kreatora (POST /tenders/{tender}/documents/from-notice → TenderDocumentImportService::analyzeUpload jak przy
 * wgraniu pliku). Pozycje i warunki czekają w podglądzie — nic nie trafia do przetargu bez „commit”.
 * Inne platformy: bez pobierania, pliki wgrywa człowiek (analyze) — historia mówi, skąd jest plik.
 */
final class NoticeTenderWithDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE_OCDS = 'ocds-148610-80ea708c-f1af-464b-a908-c2da5441f5de';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00', 'Europe/Warsaw'));
        Storage::fake('local');
    }

    public function test_tender_with_ezamowienia_documents_goes_to_preview_without_saving_items(): void
    {
        $user = User::factory()->withRole('przetargi')->create();
        Sanctum::actingAs($user);
        $notice = $this->ezamowieniaNotice();
        $ocds = (string) $notice->ocds_id;
        $bytes = (string) file_get_contents(base_path('tests/Fixtures/ezamowienia/formularz-ofertowy.docx'));
        Http::fake([
            'ezamowienia.gov.pl/mp-readmodels/api/Search/GetTenderDocuments*' => Http::response($this->sampleList($notice)),
            'ezamowienia.gov.pl/mp-readmodels/api/Tender/DownloadDocument/*' => Http::response($bytes, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ]),
        ]);

        $created = $this->postJson("/api/notices/{$notice->id}/tender", ['document_ids' => [$ocds.'_8', $ocds.'_6', $ocds.'_8']])
            ->assertCreated()
            ->assertJsonPath('document_ids', [$ocds.'_8', $ocds.'_6']);
        $tender = Tender::query()->findOrFail($created->json('tender_id'));
        // założenie przetargu niczego nie pobiera — robi to kreator, po jednym pliku
        Http::assertNothingSent();

        $response = $this->postJson("/api/tenders/{$tender->id}/documents/from-notice", [
            'notice_document_id' => $ocds.'_8',
            'mode' => 'simple',
            'targets' => ['items', 'conditions'],
        ])->assertOk();

        $document = TenderDocument::query()->findOrFail($response->json('document_id'));
        $response->assertJsonPath('mode', 'simple')
            ->assertJsonPath('source', [
                'platform' => 'ezamowienia',
                'notice_document_id' => $ocds.'_8',
                'name' => 'Załącznik nr 1 - Formularz ofertowy',
                'file_name' => 'Załącznik nr 1 - Formularz ofertowy.docx',
                'size_bytes' => strlen($bytes),
            ]);
        $this->assertStringContainsString('F O R M U L A R Z O F E R T O W Y', (string) $response->json('extracted_text'));
        $this->assertIsArray($response->json('items'));
        $this->assertIsArray($response->json('conditions'));

        // plik zapisany z pochodzeniem, jak wgrany w kreatorze
        $this->assertSame($tender->id, $document->tender_id);
        $this->assertSame($user->id, $document->uploaded_by);
        $this->assertSame('Załącznik nr 1 - Formularz ofertowy.docx', $document->original_name);
        $this->assertSame('docx', $document->extension);
        $this->assertSame('ezamowienia', $document->source);
        $this->assertSame($ocds.'_8', $document->source_ref);
        $this->assertSame('https://ezamowienia.gov.pl/mp-readmodels/api/Tender/DownloadDocument/'.$ocds.'/'.$ocds.'_8', $document->source_url);
        Storage::disk('local')->assertExists((string) $document->disk_path);
        $this->assertSame($bytes, Storage::disk('local')->get((string) $document->disk_path));
        $this->assertIsArray($document->analysis_json['items'] ?? null);

        // nic nie jest zatwierdzone automatycznie
        $this->assertSame(0, TenderItem::query()->where('tender_id', $tender->id)->count());
        $this->assertSame(0, TenderCondition::query()->where('tender_id', $tender->id)->count());
        $this->assertSame('draft', $tender->fresh()->status);

        $activity = TenderActivity::query()->where('tender_id', $tender->id)->where('action', 'document_added')->sole();
        $this->assertSame($user->id, $activity->user_id);
        $this->assertSame('ezamowienia', $activity->meta['source']);
        $this->assertSame($document->id, $activity->meta['document_id']);
        $this->assertSame(
            'Pobrano z platformy e-Zamówienia plik „Załącznik nr 1 - Formularz ofertowy.docx” („Załącznik nr 1 - Formularz ofertowy”) do odczytu pozycji i warunków.',
            $activity->meta['note'],
        );

        // ten sam dokument drugi raz — bez ponownego pobierania, wskazanie istniejącego pliku
        $this->postJson("/api/tenders/{$tender->id}/documents/from-notice", ['notice_document_id' => $ocds.'_8'])
            ->assertStatus(409)
            ->assertJsonPath('document_id', $document->id);
        Http::assertSentCount(2);

        // lista dokumentów przetargu pokazuje pochodzenie
        $this->getJson("/api/tenders/{$tender->id}/documents")
            ->assertOk()
            ->assertJsonPath('data.0.source', 'ezamowienia')
            ->assertJsonPath('data.0.source_url', $document->source_url);

        // zatwierdza człowiek, jak dotąd
        $this->postJson("/api/tenders/{$tender->id}/documents/commit", [
            'document_id' => $document->id,
            'items' => [['requirement' => 'Rękawice ochronne', 'quantity' => 10]],
        ])->assertOk()->assertJsonPath('items_created', 1);
    }

    public function test_file_from_ezamowienia_is_kept_in_every_format_and_mode(): void
    {
        Sanctum::actingAs(User::factory()->withRole('przetargi')->create());
        $notice = $this->ezamowieniaNotice();
        $ocds = (string) $notice->ocds_id;
        $list = $this->sampleList($notice);
        $list[] = ['objectId' => $ocds.'_12', 'name' => 'Formularz cenowy', 'fileName' => 'Formularz cenowy.csv', 'tenderDocumentState' => 'Published', 'publishedDate' => '2026-09-22T10:56:16.626Z', 'deleteDate' => null];
        Http::fake([
            'ezamowienia.gov.pl/mp-readmodels/api/Search/GetTenderDocuments*' => Http::response($list),
            'ezamowienia.gov.pl/mp-readmodels/api/Tender/DownloadDocument/*' => Http::response("Lp.;Opis;Ilość\n1;Rękawice nitrylowe;100\n", 200, ['Content-Type' => 'text/csv']),
        ]);
        $tenderId = $this->postJson("/api/notices/{$notice->id}/tender")->assertCreated()->json('tender_id');

        // wgrany ręcznie CSV w trybie „simple” nie jest archiwizowany; z e-Zamówień — zawsze (pochodzenie)
        $documentId = $this->postJson("/api/tenders/{$tenderId}/documents/from-notice", ['notice_document_id' => $ocds.'_12', 'mode' => 'simple'])
            ->assertOk()
            ->json('document_id');

        $document = TenderDocument::query()->findOrFail($documentId);
        $this->assertSame('csv', $document->extension);
        $this->assertSame($ocds.'_12', $document->source_ref);
        Storage::disk('local')->assertExists((string) $document->disk_path);
        $this->assertSame(0, TenderItem::query()->where('tender_id', $tenderId)->count());
    }

    public function test_download_errors_are_validation_errors_and_tender_stays_empty(): void
    {
        Sanctum::actingAs(User::factory()->withRole('przetargi')->create());
        $notice = $this->ezamowieniaNotice();
        $ocds = (string) $notice->ocds_id;
        Http::fake([
            'ezamowienia.gov.pl/mp-readmodels/api/Search/GetTenderDocuments*' => Http::response($this->sampleList($notice)),
            'ezamowienia.gov.pl/mp-readmodels/api/Tender/DownloadDocument/*' => Http::response('', 500),
        ]);
        $tenderId = $this->postJson("/api/notices/{$notice->id}/tender")->assertCreated()->assertJsonPath('document_ids', [])->json('tender_id');

        $this->postJson("/api/tenders/{$tenderId}/documents/from-notice", ['notice_document_id' => $ocds.'_9'])
            ->assertStatus(422)
            ->assertJsonPath('errors.notice_document_id.0', 'Platforma e-Zamówienia odpowiedziała błędem HTTP 500 przy pobieraniu „SWZ.pdf”.');
        $this->postJson("/api/tenders/{$tenderId}/documents/from-notice", ['notice_document_id' => '../../etc/passwd'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notice_document_id');

        $this->assertSame(0, TenderDocument::query()->where('tender_id', $tenderId)->count());
        $this->assertSame(0, TenderActivity::query()->where('tender_id', $tenderId)->where('action', 'document_added')->count());
    }

    public function test_document_ids_only_for_ezamowienia_procedure_and_with_import_permission(): void
    {
        Http::fake();
        $other = $this->fixtureNotice('contract-lots');
        $ezamowienia = $this->ezamowieniaNotice();

        Sanctum::actingAs(User::factory()->withRole('przetargi')->create());
        // inna platforma: nie pobieramy — 422 i nic nie powstaje
        $this->postJson("/api/notices/{$other->id}/tender", ['document_ids' => [$other->ocds_id.'_1']])
            ->assertStatus(422)
            ->assertJsonPath('errors.document_ids.0', 'Dokumenty można pobrać automatycznie tylko z listy dokumentów tego postępowania na platformie e-Zamówienia.');
        // dokument innego postępowania
        $this->postJson("/api/notices/{$ezamowienia->id}/tender", ['document_ids' => ['ocds-148610-00000000-0000-0000-0000-000000000000_1']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document_ids');

        // handlowiec zakłada przetarg, ale nie odczytuje dokumentów (tenders.import)
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->postJson("/api/notices/{$ezamowienia->id}/tender", ['document_ids' => [$ezamowienia->ocds_id.'_8']])->assertForbidden();
        $this->assertSame(0, Tender::query()->count());

        $tenderId = $this->postJson("/api/notices/{$ezamowienia->id}/tender")->assertCreated()->json('tender_id');
        $this->postJson("/api/tenders/{$tenderId}/documents/from-notice", ['notice_document_id' => $ezamowienia->ocds_id.'_8'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_other_platform_tender_takes_files_uploaded_by_hand_and_logs_their_origin(): void
    {
        Http::fake();
        $user = User::factory()->withRole('przetargi')->create();
        Sanctum::actingAs($user);
        $notice = $this->fixtureNotice('contract-lots');
        $tenderId = $this->postJson("/api/notices/{$notice->id}/tender")->assertCreated()->json('tender_id');

        // pobieranie z platformazakupowa.pl jest wyłączone — nawet z poprawnym identyfikatorem
        $this->postJson("/api/tenders/{$tenderId}/documents/from-notice", ['notice_document_id' => $notice->ocds_id.'_1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.notice_document_id.0', 'To postępowanie nie jest prowadzone na platformie e-Zamówienia — pobierz dokumenty ze strony postępowania i dodaj je ręcznie.');

        // plik pobrany ręcznie ze strony postępowania → ta sama ścieżka kreatora (analyze), podgląd bez zapisu pozycji
        $csv = UploadedFile::fake()->createWithContent('Formularz cenowy cz. 2.csv', "Lp.;Opis;Ilość\n1;Koszulka termoaktywna trudnopalna;20\n");
        $this->post("/api/tenders/{$tenderId}/documents/analyze", ['file' => $csv, 'mode' => 'simple', 'targets' => ['items']], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame(0, TenderItem::query()->where('tender_id', $tenderId)->count());
        $activity = TenderActivity::query()->where('tender_id', $tenderId)->where('action', 'document_added')->sole();
        $this->assertSame('upload', $activity->meta['source']);
        $this->assertSame('Dodano ręcznie plik „Formularz cenowy cz. 2.csv” do odczytu pozycji i warunków.', $activity->meta['note']);
        Http::assertNothingSent();
    }

    private function fixtureNotice(string $name): ProcurementNotice
    {
        $item = json_decode((string) file_get_contents(base_path("tests/Fixtures/bzp/{$name}.json")), true);

        return app(BzpNoticeStore::class)->upsert(app(BzpNoticeParser::class)->parse($item))->refresh();
    }

    /** Ogłoszenie z próbki prowadzone na platformie e-Zamówienia (Gmina Moszczenica). */
    private function ezamowieniaNotice(): ProcurementNotice
    {
        $notice = $this->fixtureNotice('contract-single');
        $this->assertStringStartsWith('https://ezamowienia.gov.pl/', (string) $notice->parsed['procedure_url']);

        return $notice;
    }

    /**
     * Próbka odpowiedzi GetTenderDocuments (inne postępowanie) z identyfikatorami przepisanymi na postępowanie ogłoszenia.
     *
     * @return list<array<string, mixed>>
     */
    private function sampleList(ProcurementNotice $notice): array
    {
        $json = (string) file_get_contents(base_path('tests/Fixtures/ezamowienia/tender-documents.json'));

        return json_decode(str_replace(self::SAMPLE_OCDS, (string) $notice->ocds_id, $json), true);
    }
}
