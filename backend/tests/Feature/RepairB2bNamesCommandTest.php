<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pierwszy import cennika ARTRA wziął typ wyrobu za nazwę („sandały”) i numer wiersza za kategorię
 * („144”). Polecenie przywraca nazwę z powiązania B2B, a kategorię zmienia tylko na podaną wprost.
 */
final class RepairB2bNamesCommandTest extends TestCase
{
    use RefreshDatabase;

    private PriceList $priceList;

    private B2bAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->account = B2bAccount::query()->create([
            'username' => 'artra',
            'contractor_code' => 'Kartra',
            'password' => 'haslo',
            'sites' => ['artra.pl'],
            'connector' => 'artra',
        ]);
        $ids = [
            $this->card('ARYA 300 671460 S1 PL', 'sandały', '144', 'ARYA 300 671460 S1 PL')->id,
            $this->card('ARYN 321 Air 671460 S1 PL', 'półbuty', '145', null)->id,
            $this->card('ARMEN 9003 2360 S1', 'ARMEN 9003 2360 S1', 'Obuwie / Sandały ochronne', 'ARMEN 9003 2360 S1')->id,
            $this->card('ARMEN 900 6060 S1 P', 'ARMEN 900 6060 S1 P', 'Obuwie / Sandały ochronne', 'ARMEN 900 6060 S1 P')->id,
            $this->card('ARYEL 320 618080 S1 PL', 'ARYEL 320 618080 S1 PL', 'Obuwie / Półbuty ochronne', 'ARYEL 320 618080 S1 PL')->id,
        ];
        $this->priceList = PriceList::query()->create([
            'original_filename' => 'artra.xlsx',
            'manufacturer' => 'ARTRA',
            'version' => '2026',
            'rows_total' => count($ids),
            'products_created' => count($ids),
            'product_ids' => $ids,
        ]);
    }

    public function test_preview_lists_fixes_and_changes_nothing(): void
    {
        $this->artisan('products:repair-b2b-names', ['--price-list' => $this->priceList->id])
            ->expectsOutputToContain('sandały (3)')
            ->expectsOutputToContain('Obuwie / Sandały ochronne')
            ->expectsOutputToContain('brak powiązania B2B z nazwą')
            ->expectsOutputToContain('Do poprawy: 1 nazw, 0 kategorii. Podgląd')
            ->assertSuccessful();

        $card = Product::query()->where('sku', 'ARYA 300 671460 S1 PL')->firstOrFail();
        $this->assertSame('sandały', $card->name);
        $this->assertSame('144', $card->category);
    }

    public function test_apply_sets_remote_name_and_given_category_and_restore_reverts(): void
    {
        $backup = storage_path('app/repair-backups/test-b2b-names.json');
        @unlink($backup);
        $card = Product::query()->where('sku', 'ARYA 300 671460 S1 PL')->firstOrFail();
        $blobBefore = (string) $card->search_blob;

        $this->artisan('products:repair-b2b-names', [
            '--price-list' => $this->priceList->id,
            '--category' => 'Obuwie / Sandały ochronne',
            '--id' => [(string) $card->id],
            '--backup' => $backup,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Poprawiono 1 nazw i 1 kategorii')
            ->expectsOutputToContain('Karty bez danych wzbogacania: 1')
            ->assertSuccessful();

        $card->refresh();
        $this->assertSame('ARYA 300 671460 S1 PL', $card->name);
        $this->assertSame('Obuwie / Sandały ochronne', $card->category);
        // zapis przez model: indeks tekstowy przeliczony z nowej nazwy
        $this->assertNotSame($blobBefore, (string) $card->search_blob);
        // karta spoza --id nietknięta
        $this->assertSame('145', Product::query()->where('sku', 'ARYN 321 Air 671460 S1 PL')->value('category'));

        $this->artisan('products:repair-b2b-names', ['--restore' => $backup])
            ->expectsOutputToContain('Przywrócono 1 kart')
            ->assertSuccessful();
        $card->refresh();
        $this->assertSame('sandały', $card->name);
        $this->assertSame('144', $card->category);
        @unlink($backup);
    }

    /**
     * Kategoria z --category to wybór człowieka: znacznik manual (dowód rodzaju wyrobu, automat go nie nadpisze).
     * --restore oddaje pochodzenie sprzed naprawy razem z kategorią.
     */
    public function test_given_category_is_marked_manual_and_restore_brings_back_previous_source(): void
    {
        $backup = storage_path('app/repair-backups/test-b2b-names-source.json');
        @unlink($backup);
        $card = Product::query()->where('sku', 'ARYA 300 671460 S1 PL')->firstOrFail();
        $card->update(['category_source' => Product::CATEGORY_SOURCE_IMPORT]);

        $this->artisan('products:repair-b2b-names', [
            '--price-list' => $this->priceList->id,
            '--category' => 'Obuwie / Sandały ochronne',
            '--id' => [(string) $card->id],
            '--backup' => $backup,
            '--apply' => true,
        ])->assertSuccessful();

        $card->refresh();
        $this->assertSame('Obuwie / Sandały ochronne', $card->category);
        $this->assertSame(Product::CATEGORY_SOURCE_MANUAL, $card->category_source);
        $this->assertSame('Obuwie / Sandały ochronne', $card->categoryAsEvidence());

        $this->artisan('products:repair-b2b-names', ['--restore' => $backup])
            ->expectsOutputToContain('Przywrócono 1 kart')
            ->assertSuccessful();
        $card->refresh();
        $this->assertSame('144', $card->category);
        $this->assertSame(Product::CATEGORY_SOURCE_IMPORT, $card->category_source);
        @unlink($backup);
    }

    /** Kopia sprzed znacznika pochodzenia (bez klucza category_source) nie zeruje pochodzenia przy --restore. */
    public function test_restore_from_backup_without_source_leaves_source_alone(): void
    {
        $backup = storage_path('app/repair-backups/test-b2b-names-legacy.json');
        $card = Product::query()->where('sku', 'ARYA 300 671460 S1 PL')->firstOrFail();
        $card->update(['name' => 'ARYA 300 671460 S1 PL', 'category' => 'Obuwie / Sandały ochronne', 'category_source' => Product::CATEGORY_SOURCE_B2B]);
        file_put_contents($backup, json_encode(['label' => 'repair-b2b-names', 'products' => [[
            'id' => $card->id, 'sku' => $card->sku, 'name' => 'sandały', 'category' => '144',
            'written_name' => 'ARYA 300 671460 S1 PL', 'written_category' => 'Obuwie / Sandały ochronne',
        ]]], JSON_UNESCAPED_UNICODE));

        $this->artisan('products:repair-b2b-names', ['--restore' => $backup])
            ->expectsOutputToContain('Przywrócono 1 kart')
            ->assertSuccessful();
        $card->refresh();
        $this->assertSame('144', $card->category);
        $this->assertSame(Product::CATEGORY_SOURCE_B2B, $card->category_source);
        @unlink($backup);
    }

    public function test_restore_leaves_a_card_changed_since_the_repair(): void
    {
        $backup = storage_path('app/repair-backups/test-b2b-names-cas.json');
        @unlink($backup);
        $card = Product::query()->where('sku', 'ARYA 300 671460 S1 PL')->firstOrFail();
        $this->artisan('products:repair-b2b-names', [
            '--price-list' => $this->priceList->id,
            '--category' => 'Obuwie / Sandały ochronne',
            '--id' => [(string) $card->id],
            '--backup' => $backup,
            '--apply' => true,
        ])->assertSuccessful();
        // człowiek poprawił nazwę po naprawie
        $card->refresh()->update(['name' => 'Sandały ARYA 300 S1 PL']);

        $this->artisan('products:repair-b2b-names', ['--restore' => $backup])
            ->expectsOutputToContain('Przywrócono 0 kart')
            ->expectsOutputToContain('Pominięte (karta zmieniła się od naprawy')
            ->assertSuccessful();
        $card->refresh();
        $this->assertSame('Sandały ARYA 300 S1 PL', $card->name);
        $this->assertSame('Obuwie / Sandały ochronne', $card->category);
        @unlink($backup);
    }

    public function test_with_several_links_the_one_with_matching_code_wins(): void
    {
        $card = Product::query()->where('sku', 'ARYA 300 671460 S1 PL')->firstOrFail();
        B2bProductLink::query()->create([
            'b2b_account_id' => $this->account->id,
            'product_id' => $card->id,
            'remote_id' => 'inny',
            'remote_sku' => 'INNY KOD',
            'remote_name' => 'Nazwa z innego powiązania',
            'last_seen_at' => now()->addDay(),
        ]);
        $backup = storage_path('app/repair-backups/test-b2b-names-links.json');
        @unlink($backup);

        $this->artisan('products:repair-b2b-names', [
            '--price-list' => $this->priceList->id,
            '--backup' => $backup,
            '--apply' => true,
        ])
            ->expectsOutputToContain('2 powiązania')
            ->assertSuccessful();

        $this->assertSame('ARYA 300 671460 S1 PL', $card->fresh()?->name);
        // bez --category kategoria-liczba zostaje
        $this->assertSame('144', $card->fresh()?->category);
        @unlink($backup);
    }

    public function test_without_price_list_it_refuses(): void
    {
        $this->artisan('products:repair-b2b-names')
            ->expectsOutputToContain('Podaj numer cennika')
            ->assertFailed();
    }

    private function card(string $sku, string $name, string $category, ?string $remoteName): Product
    {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'ARTRA',
            'category' => $category,
            'catalog_price_net' => 100,
            'purchase_price' => 80,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);
        if ($remoteName !== null) {
            B2bProductLink::query()->create([
                'b2b_account_id' => $this->account->id,
                'product_id' => $product->id,
                'remote_id' => $sku,
                'remote_sku' => $sku,
                'remote_name' => $remoteName,
                'last_seen_at' => now(),
            ]);
        }

        return $product;
    }
}
