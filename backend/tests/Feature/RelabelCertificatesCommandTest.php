<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Support\CertificateLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * products:relabel-certificates — porządek listy „Certyfikaty” zapisanej przed CertificateLabels: „Certyfikat
 * producenta” przy deklaracji opakowania PPWR (Ansell) i przy deklaracji zgodności, „CE” i kategorie ŚOI.
 * Podgląd nic nie zapisuje; --apply robi kopię całych enrichment_payload i zapisuje przez model.
 */
final class RelabelCertificatesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Product $ansell;

    private Product $ansellPpwrOnly;

    private Product $canis;

    private Product $clean;

    private string $backup;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['ai.vector_enabled' => true, 'ai.qdrant_url' => 'http://qdrant.test:6333']);
        $this->backup = storage_path('app/repair-backups/test-relabel-certificates.json');
        @unlink($this->backup);

        $this->ansell = $this->card('11-281', 'Ansell', 'Rękawice HyFlex 11-281', [
            'CE', 'Certyfikat producenta', 'Kategoria 3: 0334', 'Kategoria II PPE',
        ]);
        $this->document($this->ansell, 'ppwr_declaration_of_conformity.ashx', 'https://www.ansell.com/-/media/projects/ansell/website/pim/ppwr---declaration-of-conformity/ppwr_declaration_of_conformity.ashx');
        $this->document($this->ansell, 'Deklaracja zgodności UE.pdf', 'https://www.ansell.com/pl/pl/products/hyflex-11-281/doc/E83ifXErbcCV72Fuuq4hnQ');
        // plik z konta B2B nie pochodzi z wzbogacania — wzbogacanie nigdy nie dawało mu etykiety
        $account = B2bAccount::query()->create(['username' => 'login', 'password' => 'haslo', 'sites' => ['b2b.example.com'], 'connector' => 'ardon']);
        $this->document($this->ansell, 'G3427-certificate-EN.pdf', 'https://www.ardon.pl/eshop/download/product-attachment?id=45983', $account->id);

        $this->ansellPpwrOnly = $this->card('11-818', 'Ansell', 'Rękawice HyFlex 11-818', ['Certyfikat producenta']);
        $this->document($this->ansellPpwrOnly, 'ppwr_declaration_of_conformity.ashx', 'https://www.ansell.com/-/media/projects/ansell/website/pim/ppwr---declaration-of-conformity/ppwr_declaration_of_conformity.ashx');

        $this->canis = $this->card('PVC-45G', 'Canis', 'Rękawice CXS PVC-45G', ['Certyfikat producenta', 'CE']);
        $this->document($this->canis, '48699_DEKLARACE PVC-45G A PVC-45W_PL.PDF', 'https://www.canis.cz/imgserver/eshop/CANIS/19/2000000352/48699_DEKLARACE%20PVC-45G%20A%20PVC-45W_PL.PDF');

        $this->clean = $this->card('X-1', 'Ansell', 'Rękawice z certyfikatem', ['OEKO-TEX® STANDARD 100']);
        // zlecenia reindeksu z zakładania kart nie liczą się do przebiegu polecenia
        Queue::fake();
    }

    protected function tearDown(): void
    {
        @unlink($this->backup);
        parent::tearDown();
    }

    public function test_preview_reports_changes_and_writes_nothing(): void
    {
        $before = Product::query()->orderBy('id')->pluck('enrichment_payload', 'id')->all();

        $this->artisan('products:relabel-certificates')
            ->expectsOutputToContain('Karty z listą certyfikatów: 4, do zmiany: 3')
            ->expectsOutputToContain('Certyfikat producenta')
            ->expectsOutputToContain('Podgląd')
            ->assertSuccessful();

        $this->assertSame($before, Product::query()->orderBy('id')->pluck('enrichment_payload', 'id')->all());
        $this->assertFileDoesNotExist($this->backup);
        Queue::assertNotPushed(ReindexProductEmbeddingJob::class);
    }

    public function test_apply_relabels_from_documents_with_backup_and_restore(): void
    {
        $this->artisan('products:relabel-certificates', ['--manufacturer' => 'ansell', '--apply' => true, '--backup' => $this->backup])
            ->expectsOutputToContain('Zapisano certyfikaty 2 kart')
            ->assertSuccessful();

        $ansell = $this->ansell->fresh();
        // numer jednostki notyfikowanej zostaje, CE i kategoria znikają, PPWR i plik B2B bez etykiety
        $this->assertSame(['Kategoria 3: 0334', CertificateLabels::DECLARATION], $ansell->enrichment_payload['certificates']);
        $this->assertSame([], $this->ansellPpwrOnly->fresh()->enrichment_payload['certificates']);
        // opis i atrybuty bez zmian
        $this->assertSame('Opis rękawic HyFlex 11-281.', $ansell->description);
        $this->assertSame(['kategoria_bhp' => 'rekawice', 'klasa_ochrony' => 'kat. III'], $ansell->enrichment_payload['attributes']);
        // indeks tekstowy przeliczony przy zapisie (blob jest małymi literami, bez polskich znaków)
        $this->assertStringNotContainsString('certyfikat producenta', (string) $ansell->search_blob);
        $this->assertStringContainsString('deklaracja zgodnosci ue', (string) $ansell->search_blob);
        Queue::assertPushed(ReindexProductEmbeddingJob::class);
        // poza zawężeniem producenta nic się nie zmieniło
        $this->assertSame(['Certyfikat producenta', 'CE'], $this->canis->fresh()->enrichment_payload['certificates']);
        $this->assertSame(['OEKO-TEX® STANDARD 100'], $this->clean->fresh()->enrichment_payload['certificates']);

        // kopia: całe enrichment_payload zmienionych kart, poprawny JSON
        $this->assertFileExists($this->backup);
        $backup = json_decode((string) file_get_contents($this->backup), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('relabel-certificates', $backup['label']);
        $this->assertEqualsCanonicalizing([$this->ansell->id, $this->ansellPpwrOnly->id], array_column($backup['products'], 'id'));
        $saved = json_decode($backup['products'][0]['enrichment_payload'], true);
        $this->assertSame(['CE', 'Certyfikat producenta', 'Kategoria 3: 0334', 'Kategoria II PPE'], $saved['certificates']);
        $this->assertSame('rekawice', $saved['attributes']['kategoria_bhp']);

        // drugi przebieg nie ma nic do zrobienia
        $this->artisan('products:relabel-certificates', ['--manufacturer' => 'ansell'])
            ->expectsOutputToContain('Nic do zmiany')
            ->assertSuccessful();

        // --restore: lista wraca tylko tam, gdzie od przebiegu nikt jej nie zmienił
        $other = $this->ansellPpwrOnly->fresh();
        $other->enrichment_payload = [...$other->enrichment_payload, 'certificates' => ['Certyfikat badania typu UE']];
        $other->save();
        $this->artisan('products:relabel-certificates', ['--restore' => $this->backup])
            ->expectsOutputToContain('Przywrócono 1 kart')
            ->assertSuccessful();
        $this->assertSame(['CE', 'Certyfikat producenta', 'Kategoria 3: 0334', 'Kategoria II PPE'], $this->ansell->fresh()->enrichment_payload['certificates']);
        $this->assertSame(['Certyfikat badania typu UE'], $this->ansellPpwrOnly->fresh()->enrichment_payload['certificates']);
    }

    public function test_product_and_limit_options_narrow_the_run(): void
    {
        $this->artisan('products:relabel-certificates', ['--product' => [$this->canis->id], '--apply' => true, '--backup' => $this->backup])
            ->expectsOutputToContain('Zapisano certyfikaty 1 kart')
            ->assertSuccessful();
        $this->assertSame([CertificateLabels::DECLARATION], $this->canis->fresh()->enrichment_payload['certificates']);
        $this->assertSame(['CE', 'Certyfikat producenta', 'Kategoria 3: 0334', 'Kategoria II PPE'], $this->ansell->fresh()->enrichment_payload['certificates']);

        @unlink($this->backup);
        $this->artisan('products:relabel-certificates', ['--limit' => 1, '--apply' => true, '--backup' => $this->backup])
            ->expectsOutputToContain('Zapisano certyfikaty 1 kart')
            ->assertSuccessful();
        $this->assertSame(['Certyfikat producenta'], $this->ansellPpwrOnly->fresh()->enrichment_payload['certificates']);
    }

    /** @param  list<string>  $certificates */
    private function card(string $sku, string $manufacturer, string $name, array $certificates): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'description' => 'Opis rękawic HyFlex 11-281.',
            'enrichment_payload' => [
                'certificates' => $certificates,
                'norms' => ['EN 388'],
                'attributes' => ['kategoria_bhp' => 'rekawice', 'klasa_ochrony' => 'kat. III'],
            ],
        ]);
    }

    private function document(Product $product, string $title, string $url, ?int $accountId = null): void
    {
        ProductDocument::query()->create([
            'product_id' => $product->id,
            'b2b_account_id' => $accountId,
            'path' => 'product-documents/'.$product->id.'/'.md5($url).'.pdf',
            'source_url' => $url,
            'title' => $title,
            'kind' => ProductDocument::KIND_CERTIFICATE,
            'sort_order' => 0,
        ]);
    }
}
