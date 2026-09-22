<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\PriceList;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Przeliczenie atrybutów BHP bez modelu: dotąd szło po całym katalogu, zapisywało od razu i przez saveQuietly
 * (indeks tekstowy, ppe_family i wektor zostawały stare). Teraz podgląd bez --apply, zawężenie do producenta
 * i cennika, kopia zapasowa, zapis przez model i --restore.
 */
final class BackfillBhpAttributesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Product $mask;

    private Product $mat;

    private Product $other;

    private PriceList $priceList;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        // karta CEDERROTH z audytu: model wpisał masce do resuscytacji kategorię i typ półmaski
        $this->mask = Product::query()->create([
            'sku' => '1921',
            'name' => 'Maska oddechowa Cederroth',
            'manufacturer' => 'CEDERROTH',
            'description' => 'Maska oddechowa Cederroth to jednorazowe narzędzie do bezpiecznego prowadzenia resuscytacji metodą usta-usta.',
            'enrichment_payload' => ['attributes' => ['kategoria_bhp' => 'drogi_oddechowe', 'typ_wyrobu' => 'ffp']],
        ]);
        $this->mat = Product::query()->create([
            'sku' => 'AF060001',
            'name' => 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)',
            'manufacturer' => 'Coba',
            'description' => 'Mata przeciwzmęczeniowa. Przetestowana ogniowo zgodnie z normą BS EN 13501-1 (klasa Dfl-s1).',
            'enrichment_payload' => ['attributes' => ['kategoria_bhp' => 'obuwie', 'klasa_ochrony' => 'S1']],
        ]);
        $this->other = Product::query()->create([
            'sku' => 'X-1',
            'name' => 'Maska oddechowa Cederroth duża',
            'manufacturer' => 'Inny',
            'description' => 'Maska do resuscytacji usta-usta.',
            'enrichment_payload' => ['attributes' => ['kategoria_bhp' => 'drogi_oddechowe', 'typ_wyrobu' => 'ffp']],
        ]);
        $this->priceList = PriceList::query()->create([
            'original_filename' => 'cederroth.xlsx',
            'manufacturer' => 'CEDERROTH',
            'version' => '2026',
            'product_ids' => [$this->mask->id],
        ]);
    }

    public function test_preview_shows_differences_and_changes_nothing(): void
    {
        $this->artisan('products:backfill-bhp-attributes', ['--force' => true, '--manufacturer' => 'cederroth'])
            ->expectsOutputToContain('do zmiany: 1')
            ->expectsOutputToContain('kategoria_bhp')
            ->expectsOutputToContain('Podgląd')
            ->assertSuccessful();

        $this->assertSame('drogi_oddechowe', $this->mask->refresh()->enrichment_payload['attributes']['kategoria_bhp']);
    }

    public function test_apply_writes_through_model_and_restore_reverts(): void
    {
        $backup = storage_path('app/repair-backups/test-bhp-attributes.json');
        @unlink($backup);
        Queue::fake();

        $this->artisan('products:backfill-bhp-attributes', [
            '--force' => true,
            '--price-list' => $this->priceList->id,
            '--backup' => $backup,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Zapisano atrybuty 1 kart')
            ->assertSuccessful();

        $mask = $this->mask->refresh();
        $this->assertSame('inne', $mask->enrichment_payload['attributes']['kategoria_bhp']);
        $this->assertNull($mask->enrichment_payload['attributes']['typ_wyrobu']);
        // zapis przez model: ppe_family przeliczone, wektor zlecony do reindeksu
        $this->assertNull($mask->ppe_family);
        Queue::assertPushed(ReindexProductEmbeddingJob::class, fn (ReindexProductEmbeddingJob $job): bool => true);
        // karta innego producenta i spoza cennika nietknięta
        $this->assertSame('drogi_oddechowe', $this->other->refresh()->enrichment_payload['attributes']['kategoria_bhp']);

        $this->artisan('products:backfill-bhp-attributes', ['--restore' => $backup])
            ->expectsOutputToContain('Przywrócono 1 kart')
            ->assertSuccessful();
        $this->assertSame(
            ['kategoria_bhp' => 'drogi_oddechowe', 'typ_wyrobu' => 'ffp'],
            $this->mask->refresh()->enrichment_payload['attributes'],
        );
        @unlink($backup);
    }

    public function test_restore_leaves_a_card_changed_since_the_run(): void
    {
        $backup = storage_path('app/repair-backups/test-bhp-attributes-cas.json');
        @unlink($backup);
        $this->artisan('products:backfill-bhp-attributes', [
            '--force' => true,
            '--id' => [(string) $this->mat->id],
            '--backup' => $backup,
            '--apply' => true,
        ])->assertSuccessful();
        $this->assertSame('inne', $this->mat->refresh()->enrichment_payload['attributes']['kategoria_bhp']);

        // wzbogacanie zapisało nowe atrybuty po przebiegu
        $this->mat->update(['enrichment_payload' => ['attributes' => ['kategoria_bhp' => 'inne', 'material' => 'PVC']]]);

        $this->artisan('products:backfill-bhp-attributes', ['--restore' => $backup])
            ->expectsOutputToContain('Przywrócono 0 kart')
            ->expectsOutputToContain('Pominięte')
            ->assertSuccessful();
        $this->assertSame('PVC', $this->mat->refresh()->enrichment_payload['attributes']['material']);
        @unlink($backup);
    }

    public function test_unknown_price_list_fails(): void
    {
        $this->artisan('products:backfill-bhp-attributes', ['--price-list' => 99999])
            ->expectsOutputToContain('Nie ma cennika numer 99999')
            ->assertFailed();
    }
}
