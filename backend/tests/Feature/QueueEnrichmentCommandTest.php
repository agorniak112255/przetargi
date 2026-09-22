<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PrefetchProductSourcesJob;
use App\Models\AiSetting;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Po naprawie nazw cennika 3M 2026 opisy trzeba pobrać ponownie, ale do przetargów potrzebne są środki ochrony, nie
 * ścierniwa. Polecenie wybiera karty po producencie i kategorii, pomija opisane, dzieli na partie wg limitu z Ustawień AI.
 */
final class QueueEnrichmentCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->withRole('admin')->create();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
            'enrichment_batch_limit' => 2,
        ]);
        $ppe = 'Środki ochrony indywidualnej';
        $this->card('1406213', '3M™ 2890 Gogle ochronne, szczelne, zaciemnienie spawalnicze 5.0, 2895S', $ppe, Product::ENRICHMENT_NONE);
        $this->card('GH1N', '3M™ Pasek podbródkowy do hełmu ochronnego G22 i G3000', $ppe, Product::ENRICHMENT_NONE);
        $this->card('9914', '3M™ Półmaska filtrująca 9914', $ppe, Product::ENRICHMENT_NONE);
        $this->card('9310+', '3M™ Półmaska filtrująca 9310+', $ppe, Product::ENRICHMENT_DONE);
        $this->card('34340', 'Elastyczne arkusze do szlifowania ręcznego 3M™ Hookit™ 270J', 'Materiały ścierne', Product::ENRICHMENT_NONE);
    }

    public function test_preview_counts_matching_cards_and_queues_nothing(): void
    {
        $this->artisan('products:queue-enrichment', ['--manufacturer' => '3M', '--category' => 'Środki ochrony indywidualnej'])
            ->expectsOutputToContain('Do pobrania opisu: 3 kart — 2 partii po 2')
            ->expectsOutputToContain('Podgląd')
            ->assertSuccessful();

        $this->assertSame(0, ProductEnrichmentBatch::query()->count());
    }

    public function test_apply_queues_only_undescribed_cards_of_category_in_batches(): void
    {
        $this->artisan('products:queue-enrichment', ['--manufacturer' => '3M', '--category' => 'Środki ochrony indywidualnej', '--apply' => true])
            ->expectsOutputToContain('Zlecono pobranie opisu: 3 kart w 2 partiach')
            ->assertSuccessful();

        $this->assertSame([2, 1], ProductEnrichmentBatch::query()->orderBy('id')->pluck('total')->map(static fn ($n): int => (int) $n)->all());
    }

    /**
     * Stary kod zostawiał „done” bez opisu (samo zdjęcie) — takie karty nie wracały do kolejki (audyt 22.09.2026:
     * 52 karty). Opcja wybiera tylko je; karta „done” z opisem i karta „manual” zostają.
     */
    public function test_done_without_description_preview_selects_only_empty_done_cards(): void
    {
        $this->card('9320+', '3M™ Półmaska filtrująca 9320+', 'Środki ochrony indywidualnej', Product::ENRICHMENT_DONE);
        Product::query()->where('sku', '9320+')->update(['description' => 'Półmaska filtrująca FFP2 z zaworem wydechowym.']);
        $this->card('9322+', '3M™ Półmaska filtrująca 9322+', 'Środki ochrony indywidualnej', Product::ENRICHMENT_DONE);
        Product::query()->where('sku', '9322+')->update(['description' => "  \n "]);
        $this->card('9332+', '3M™ Półmaska filtrująca 9332+', 'Środki ochrony indywidualnej', Product::ENRICHMENT_MANUAL);

        $this->artisan('products:queue-enrichment', ['--done-without-description' => true])
            ->expectsOutputToContain('Do pobrania opisu: 2 kart „done” bez opisu')
            ->expectsOutputToContain('Podgląd')
            ->assertSuccessful();

        $this->assertSame(0, ProductEnrichmentBatch::query()->count());
    }

    /**
     * Karta konta ARTRA (łącznik B2bDatasheetOnlyDescription) ma opis wyłącznie z karty katalogowej PDF — zwykłe
     * wzbogacanie z force wzięłoby go z internetu. Karta konta innego łącznika wraca normalnie. Podgląd pokazuje
     * producenta, bo opcja działa na cały katalog.
     */
    public function test_done_without_description_skips_datasheet_only_account_cards_and_shows_manufacturer(): void
    {
        $artra = B2bAccount::query()->create(['username' => 'ARTRA', 'sites' => ['artra.pl'], 'connector' => 'artra']);
        $tegro = B2bAccount::query()->create(['username' => 'TEGRO', 'sites' => ['tegro.pl'], 'connector' => 'tegro']);
        $artraCard = Product::query()->create([
            'sku' => '616560', 'name' => 'Półbut ARCASIO 732 S1 P ESD', 'manufacturer' => 'ARTRA',
            'catalog_price_net' => 10, 'purchase_price' => 8, 'stock' => 1, 'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
        $tegroCard = Product::query()->create([
            'sku' => 'T-100', 'name' => 'Rękawice robocze T-100', 'manufacturer' => 'TEGRO',
            'catalog_price_net' => 10, 'purchase_price' => 8, 'stock' => 1, 'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
        foreach ([[$artra, $artraCard], [$tegro, $tegroCard]] as [$account, $card]) {
            B2bProductLink::query()->create([
                'b2b_account_id' => $account->id,
                'remote_id' => 'r-'.$card->sku,
                'product_id' => $card->id,
                'remote_sku' => $card->sku,
                'remote_name' => $card->name,
            ]);
        }

        $this->artisan('products:queue-enrichment', ['--done-without-description' => true])
            // 3M 9310+ i TEGRO T-100; ARTRA pominięta
            ->expectsOutputToContain('Do pobrania opisu: 2 kart „done” bez opisu')
            ->expectsOutputToContain('Producent')
            ->expectsOutputToContain('TEGRO')
            ->doesntExpectOutputToContain('ARCASIO')
            ->assertSuccessful();
    }

    public function test_done_without_description_apply_requeues_with_force(): void
    {
        $this->artisan('products:queue-enrichment', ['--done-without-description' => true, '--manufacturer' => '3M', '--apply' => true])
            ->expectsOutputToContain('Zlecono pobranie opisu: 1 kart w 1 partiach')
            ->assertSuccessful();

        $batch = ProductEnrichmentBatch::query()->sole();
        $this->assertSame(1, (int) $batch->total);
        $this->assertTrue((bool) $batch->force);
        $doneId = (int) Product::query()->where('sku', '9310+')->value('id');
        Queue::assertPushed(PrefetchProductSourcesJob::class, 1);
        Queue::assertPushed(
            PrefetchProductSourcesJob::class,
            static fn (PrefetchProductSourcesJob $job): bool => $job->productId === $doneId && $job->force
        );
    }

    public function test_requires_a_filter(): void
    {
        $this->artisan('products:queue-enrichment')
            ->expectsOutputToContain('Podaj --manufacturer= albo --category=')
            ->assertFailed();
    }

    private function card(string $sku, string $name, string $category, string $status): void
    {
        Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => '3M',
            'category' => $category,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
            'enrichment_status' => $status,
        ]);
    }
}
