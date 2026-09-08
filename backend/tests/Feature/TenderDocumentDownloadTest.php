<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tender;
use App\Models\TenderDocument;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenderDocumentDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_downloads_archived_document_file(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        Storage::fake('local');

        [$tender, $document] = $this->makeDocument('OPZ 308_2027.docx', 'tresc-opz');

        $this->get("/api/tenders/{$tender->id}/documents/{$document->id}/download")
            ->assertOk()
            ->assertDownload('OPZ 308_2027.docx');
    }

    public function test_download_fails_when_file_missing(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        Storage::fake('local');

        [$tender, $document] = $this->makeDocument('OPZ 308_2027.docx', null);

        $this->getJson("/api/tenders/{$tender->id}/documents/{$document->id}/download")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document']);
    }

    /**
     * @return array{0: Tender, 1: TenderDocument}
     */
    private function makeDocument(string $name, ?string $contents): array
    {
        $owner = User::factory()->create();
        $client = Client::query()->create(['name' => 'Klient OPZ']);
        $tender = Tender::query()->create([
            'number' => 'PRZ/OPZ/1',
            'title' => 'OPZ',
            'client_id' => $client->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
            'ai_percent' => 70,
            'last_activity_at' => now(),
        ]);
        $path = "tender-documents/{$tender->id}/opz.docx";
        if ($contents !== null) {
            Storage::disk('local')->put($path, $contents);
        }

        $document = TenderDocument::query()->create([
            'tender_id' => $tender->id,
            'uploaded_by' => $owner->id,
            'original_name' => $name,
            'disk_path' => $path,
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
            'size_bytes' => $contents !== null ? strlen($contents) : 0,
            'mode' => 'ai',
            'targets' => ['items'],
        ]);

        return [$tender, $document];
    }
}
