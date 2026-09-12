<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tender;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\TenderDocumentImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery\MockInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Arkusz z 15 długimi opisami SIWZ: pozycje wyciąga mapowanie kolumn, więc model
 * nie ma ich przepisywać — dostaje tylko warunki, a bez warunków nie jest wołany.
 */
final class TenderDocumentImportTargetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sheet_items_are_not_sent_to_the_model_again(): void
    {
        // model dostaje prośbę tylko o warunki — bez „- pozycje (items)” w prompcie
        $this->mock(OpenAiCompatibleClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(static function (array $messages): bool {
                    $prompt = (string) ($messages[1]['content'] ?? '');

                    return str_contains($prompt, '- warunki (conditions)') && ! str_contains($prompt, '- pozycje (items)');
                })
                ->andReturn(['content' => '{"items": [], "conditions": [{"category": "termin", "content": "Dostawa 14 dni"}]}', 'model' => 'test']);
        });

        $result = $this->analyze(['items', 'conditions']);

        $this->assertCount(2, $result['items']);
        $this->assertStringContainsString('Rękawice ochronne odporne na przecięcie', $result['items'][0]['requirement']);
        $this->assertSame('Dostawa 14 dni', $result['conditions'][0]['content']);
    }

    public function test_items_only_from_sheet_skips_the_model(): void
    {
        $this->mock(OpenAiCompatibleClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('chat')->never();
            $mock->shouldReceive('chatJson')->never();
        });

        $result = $this->analyze(['items']);

        $this->assertCount(2, $result['items']);
        $this->assertSame([], $result['conditions']);
    }

    /**
     * @param  list<string>  $targets
     * @return array<string, mixed>
     */
    private function analyze(array $targets): array
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Formularz cenowy');
        $sheet->fromArray([
            ['Lp.', 'Opis przedmiotu zamówienia', 'Ilość', 'J.m.', 'Cena jedn. netto'],
            [1, 'Rękawice ochronne odporne na przecięcie, poziom B wg EN 388, powlekane nitrylem, do prac z ostrymi elementami.', 50, 'par', null],
            [2, 'Półmaska filtrująca klasy FFP1 z zaworem wydechowym, do ochrony przed pyłami.', 100, 'szt.', null],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'siwz').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $user = User::factory()->create();
        $client = Client::query()->create(['name' => 'Zamawiający testowy']);
        $tender = Tender::query()->create([
            'number' => 'TEST/OPISOWY/1',
            'title' => 'Test opisowy',
            'client_id' => $client->id,
            'owner_id' => $user->id,
            'status' => 'draft',
        ]);

        try {
            return app(TenderDocumentImportService::class)->analyzeUpload(
                $tender,
                new UploadedFile($path, 'siwz.xlsx', null, null, true),
                'ai',
                $targets,
                $user,
            );
        } finally {
            @unlink($path);
        }
    }
}
