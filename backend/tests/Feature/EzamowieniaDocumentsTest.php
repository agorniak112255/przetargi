<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProcurementNotice;
use App\Services\Bzp\BzpNoticeParser;
use App\Services\Bzp\BzpNoticeStore;
use App\Services\Bzp\EzamowieniaDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Klient dokumentów postępowania na platformie e-Zamówienia: lista z próbki API (tests/Fixtures/ezamowienia/
 * tender-documents.json), plik z próbki (formularz-ofertowy.docx — „Załącznik nr 1 - Formularz ofertowy” tego
 * postępowania, pobrany 03.10.2026 adresem /mp-readmodels/api/Tender/DownloadDocument/{tenderId}/{objectId}).
 */
final class EzamowieniaDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE_OCDS = 'ocds-148610-80ea708c-f1af-464b-a908-c2da5441f5de';

    private const DOWNLOAD = 'https://ezamowienia.gov.pl/mp-readmodels/api/Tender/DownloadDocument/';

    public function test_downloads_a_listed_file_with_application_user_agent(): void
    {
        $notice = $this->notice();
        $bytes = (string) file_get_contents(base_path('tests/Fixtures/ezamowienia/formularz-ofertowy.docx'));
        Http::fake([
            'ezamowienia.gov.pl/mp-readmodels/api/Search/GetTenderDocuments*' => Http::response($this->sampleList()),
            self::DOWNLOAD.'*' => Http::response($bytes, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Content-Disposition' => "attachment; filename=\"Za__cznik nr 1 - Formularz ofertowy.docx\"; filename*=UTF-8''Za%C5%82%C4%85cznik%20nr%201%20-%20Formularz%20ofertowy.docx",
            ]),
        ]);

        $file = app(EzamowieniaDocuments::class)->download($notice, self::SAMPLE_OCDS.'_8');

        try {
            $this->assertSame(self::SAMPLE_OCDS.'_8', $file['id']);
            $this->assertSame('Załącznik nr 1 - Formularz ofertowy.docx', $file['file_name']);
            $this->assertSame('Załącznik nr 1 - Formularz ofertowy', $file['name']);
            $this->assertSame('docx', $file['extension']);
            $this->assertSame(strlen($bytes), $file['size']);
            $this->assertSame($bytes, file_get_contents($file['path']));
            $this->assertSame(self::DOWNLOAD.self::SAMPLE_OCDS.'/'.self::SAMPLE_OCDS.'_8', $file['url']);
        } finally {
            @unlink($file['path']);
        }
        Http::assertSent(fn (Request $request): bool => $request->url() === self::DOWNLOAD.self::SAMPLE_OCDS.'/'.self::SAMPLE_OCDS.'_8'
            && $request->hasHeader('User-Agent', (string) config('bzp.user_agent')));
    }

    public function test_size_limit_while_downloading_and_from_declared_length(): void
    {
        config(['bzp.ezamowienia.max_file_mb' => 1]);
        $notice = $this->notice();
        Http::fake([
            'ezamowienia.gov.pl/mp-readmodels/api/Search/GetTenderDocuments*' => Http::response($this->sampleList()),
            // bez Content-Length (jak odpowiedź chunked e-Zamówień) — limit liczony w trakcie
            self::DOWNLOAD.'*_9' => Http::response(str_repeat('x', 1024 * 1024 + 1)),
            self::DOWNLOAD.'*_8' => Http::response('PK', 200, ['Content-Length' => (string) (30 * 1024 * 1024)]),
        ]);
        $temp = count(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'ezam*') ?: []);

        $this->assertDownloadFails($notice, self::SAMPLE_OCDS.'_9', 'Plik „SWZ.pdf” ma ponad 1 MB — pobierz go ze strony postępowania.');
        $this->assertDownloadFails($notice, self::SAMPLE_OCDS.'_8', 'Plik „Załącznik nr 1 - Formularz ofertowy.docx” ma ponad 1 MB — pobierz go ze strony postępowania.');
        // przerwane pobranie nie zostawia pliku tymczasowego
        $this->assertSame($temp, count(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'ezam*') ?: []));
    }

    public function test_only_listed_importable_files_of_this_procedure(): void
    {
        $notice = $this->notice();
        $list = $this->sampleList();
        $list[] = ['objectId' => self::SAMPLE_OCDS.'_12', 'name' => 'Dokumentacja', 'fileName' => 'Dokumentacja.zip', 'tenderDocumentState' => 'Published', 'publishedDate' => '2026-09-22T10:56:16.626Z', 'deleteDate' => null];
        $list[] = ['objectId' => self::SAMPLE_OCDS.'_13', 'name' => 'Stara wersja OPZ', 'fileName' => 'OPZ.pdf', 'tenderDocumentState' => 'Deleted', 'publishedDate' => '2026-09-22T10:56:16.626Z', 'deleteDate' => '2026-09-23T10:00:00Z'];
        $list[] = ['objectId' => self::SAMPLE_OCDS.'_14', 'name' => 'Strona z dokumentami', 'fileName' => null, 'url' => 'https://example.com/dokumenty', 'tenderDocumentState' => 'Published', 'publishedDate' => '2026-09-22T10:56:16.626Z', 'deleteDate' => null];
        $list[] = ['objectId' => 'ocds-148610-00000000-0000-0000-0000-000000000000_1', 'name' => 'Cudzy plik', 'fileName' => 'Cudzy.pdf', 'tenderDocumentState' => 'Published', 'publishedDate' => '2026-09-22T10:56:16.626Z', 'deleteDate' => null];
        Http::fake(['ezamowienia.gov.pl/mp-readmodels/api/Search/GetTenderDocuments*' => Http::response($list)]);

        $documents = app(EzamowieniaDocuments::class)->forNotice($notice);

        $ids = array_column($documents['items'], 'id');
        $this->assertContains(self::SAMPLE_OCDS.'_12', $ids);
        $this->assertNotContains(self::SAMPLE_OCDS.'_13', $ids);
        $this->assertNotContains(self::SAMPLE_OCDS.'_14', $ids);
        $this->assertNotContains('ocds-148610-00000000-0000-0000-0000-000000000000_1', $ids);
        $zip = collect($documents['items'])->firstWhere('id', self::SAMPLE_OCDS.'_12');
        $this->assertFalse($zip['importable']);
        $this->assertFalse($zip['suggested']);
        $this->assertStringContainsString('Plików, których aplikacja nie odczyta (inny format niż PDF, Word, Excel albo CSV): 1', $documents['note']);
        $this->assertStringContainsString('Odnośników do innych stron zamiast plików: 1', $documents['note']);

        $this->assertDownloadFails($notice, self::SAMPLE_OCDS.'_12', 'Plik „Dokumentacja.zip” ma format, którego aplikacja nie odczytuje (PDF, Word, Excel albo CSV) — otwórz go na stronie postępowania.');
        $this->assertDownloadFails($notice, self::SAMPLE_OCDS.'_13', 'Tego dokumentu nie ma (już) na liście plików postępowania na platformie e-Zamówienia.');
        $this->assertDownloadFails($notice, 'ocds-148610-00000000-0000-0000-0000-000000000000_1', 'Tego dokumentu nie ma (już) na liście plików postępowania na platformie e-Zamówienia.');
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), self::DOWNLOAD));
    }

    public function test_http_error_and_other_platform(): void
    {
        $notice = $this->notice();
        Http::fake([
            'ezamowienia.gov.pl/mp-readmodels/api/Search/GetTenderDocuments*' => Http::response($this->sampleList()),
            self::DOWNLOAD.'*' => Http::response('{"message":"not found"}', 404),
        ]);
        $this->assertDownloadFails($notice, self::SAMPLE_OCDS.'_9', 'Platforma e-Zamówienia odpowiedziała błędem HTTP 404 przy pobieraniu „SWZ.pdf”.');

        $other = $this->notice('https://platformazakupowa.pl/pn/szi');
        $this->assertFalse(EzamowieniaDocuments::isEzamowienia($other));
        $this->assertDownloadFails($other, self::SAMPLE_OCDS.'_9', 'To postępowanie nie jest prowadzone na platformie e-Zamówienia — pobierz dokumenty ze strony postępowania i dodaj je ręcznie.');
        Http::assertSentCount(2);
    }

    public function test_list_error_blocks_download_with_readable_message(): void
    {
        $notice = $this->notice();
        Http::fake(['ezamowienia.gov.pl/*' => Http::response('', 502)]);

        $this->assertDownloadFails($notice, self::SAMPLE_OCDS.'_9', 'Nie udało się pobrać listy dokumentów z platformy e-Zamówienia (błąd HTTP 502) — spróbuj ponownie za kilka minut.');
        // błąd zapamiętany na chwilę — drugie kliknięcie nie odpytuje platformy od razu
        $this->assertDownloadFails($notice, self::SAMPLE_OCDS.'_9', 'Nie udało się pobrać listy dokumentów z platformy e-Zamówienia (błąd HTTP 502) — spróbuj ponownie za kilka minut.');
        Http::assertSentCount(1);
    }

    #[DataProvider('kinds')]
    public function test_kind_from_document_name(string $name, string $fileName, string $kind): void
    {
        $this->assertSame($kind, EzamowieniaDocuments::kindOf($name, $fileName));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function kinds(): array
    {
        return [
            'opis przedmiotu' => ['Załącznik nr 3 - Opis przedmiotu zamówienia - część I', 'Zał 3.docx', 'description'],
            'OPZ' => ['OPZ cz. 1', 'opz_cz1.pdf', 'description'],
            'specyfikacja techniczna' => ['Specyfikacja techniczna rękawic', 'spec.pdf', 'description'],
            'formularz ofertowy' => ['Załącznik nr 1 - Formularz ofertowy', 'f.docx', 'form'],
            'formularz cenowy przed SWZ' => ['Załącznik nr 2 do SWZ - Formularz cenowy', 'f.xlsx', 'form'],
            'formularz asortymentowo-cenowy' => ['Formularz asortymentowo-cenowy', 'f.xlsx', 'form'],
            'kosztorys' => ['Kosztorys ofertowy', 'k.xls', 'form'],
            'SWZ' => ['SWZ', 'SWZ.pdf', 'swz'],
            'SIWZ w nazwie pliku' => ['Dokument', 'SIWZ_2026.pdf', 'swz'],
            'specyfikacja warunków' => ['Specyfikacja Warunków Zamówienia', 's.pdf', 'swz'],
            'wyjaśnienie SWZ' => ['Wyjaśnienie treści SWZ - 25-09-2026', 'w.pdf', 'other'],
            'zmiana SWZ' => ['Zmiana SWZ nr 1', 'z.pdf', 'other'],
            'wzór umowy (nie „wz”)' => ['Załącznik nr 4 - Wzór umowy', 'Wzór umowy.docx', 'other'],
            'oświadczenie' => ['Załącznik nr 2 - Oświadczenia', 'o.docx', 'other'],
        ];
    }

    private function assertDownloadFails(ProcurementNotice $notice, string $id, string $message): void
    {
        try {
            $file = app(EzamowieniaDocuments::class)->download($notice, $id);
            @unlink($file['path']);
            $this->fail('Pobranie '.$id.' powinno się nie udać.');
        } catch (RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    /** Ogłoszenie z próbki Biuletynu z identyfikatorem postępowania z próbki listy dokumentów. */
    private function notice(?string $procedureUrl = null): ProcurementNotice
    {
        $item = json_decode((string) file_get_contents(base_path('tests/Fixtures/bzp/contract-single.json')), true);
        $notice = app(BzpNoticeStore::class)->upsert(app(BzpNoticeParser::class)->parse($item))->refresh();
        $parsed = $notice->parsed;
        $parsed['procedure_url'] = $procedureUrl ?? 'https://ezamowienia.gov.pl/mp-client/search/list/'.self::SAMPLE_OCDS;
        $notice->update(['ocds_id' => self::SAMPLE_OCDS, 'parsed' => $parsed]);

        return $notice;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sampleList(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/ezamowienia/tender-documents.json')), true);
    }
}
