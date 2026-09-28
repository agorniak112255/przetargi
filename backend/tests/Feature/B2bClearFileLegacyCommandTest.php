<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductAccessory;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use App\Models\ProductSourcePrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * b2b:clear-file-legacy — pozostałości cennika z pliku producenta na kartach prowadzonych przez jego konto B2B
 * (Bolle, 28.09.2026): slot pliku, opakowanie i dane z wzbogacania AI znikają, opis z B2B i cena konta zostają.
 */
final class B2bClearFileLegacyCommandTest extends TestCase
{
    use RefreshDatabase;

    private const B2B_DESCRIPTION = "Miedziane okulary ochronne\n\nBAXTER, alternatywa dla modelu TRACKER, oferuje pełen komfort.";

    private B2bAccount $bolle;

    private PriceList $list;

    private Product $legacy;

    private Product $aiDescription;

    private Product $fileWins;

    private Product $foreignBrand;

    private Product $untouched;

    protected function setUp(): void
    {
        parent::setUp();
        $this->list = PriceList::query()->create(['manufacturer' => 'Bolle', 'version' => 'B2B', 'original_filename' => 'bolle-safety.com (API)']);
        $this->bolle = B2bAccount::query()->create([
            'username' => 'login-bolle',
            'password' => 'haslo',
            'sites' => ['b2b.bolle-safety.com'],
            'connector' => 'bolle',
        ]);
        $this->bolle->forceFill(['last_price_list_id' => $this->list->id])->save();

        // karta z pliku EMEA: slot pliku, opakowanie, dane AI z obcych sklepów, opis już z B2B
        $this->legacy = $this->card('BAXCSP', 'Bolle', self::B2B_DESCRIPTION);
        $this->link($this->legacy, self::B2B_DESCRIPTION);
        $this->fileSlot($this->legacy, 18.55);
        $this->accountSlot($this->legacy, 8.35);
        ProductEnrichmentCache::query()->create(['manufacturer' => 'bolle', 'sku' => 'baxcsp', 'description' => 'opis AI', 'enrichment_payload' => ['features' => ['Filtr spawalniczy Shade 5']]]);
        ProductAccessory::query()->create(['product_id' => $this->legacy->id, 'source' => ProductAccessory::SOURCE_ENRICHMENT, 'link_key' => 'sku:PSPUNISS01', 'related_sku' => 'PSPUNISS01']);
        ProductAccessory::query()->create(['product_id' => $this->legacy->id, 'source' => ProductAccessory::SOURCE_MANUAL, 'link_key' => 'sku:BCLEAN', 'related_sku' => 'BCLEAN']);
        ProductImage::query()->create(['product_id' => $this->legacy->id, 'path' => 'products/a.jpg', 'source_url' => 'https://www.specshop.pl/a.jpg', 'checksum' => 'a']);

        // opis nie jest z B2B (hash powiązania inny) — dane AI zostają, slot pliku znika
        $this->aiDescription = $this->card('TACTICALRXKIT', 'Bolle', 'Wkład korekcyjny Bolle TACTICALRXKIT to praktyczne rozwiązanie.');
        $this->link($this->aiDescription, 'inny tekst ze sklepu dostawcy');
        $this->fileSlot($this->aiDescription, 10.0);
        $this->accountSlot($this->aiDescription, 5.0);

        // konto bez ceny tej karty — cenę ustala plik, więc slot zostaje; dane AI i tak znikają
        $this->fileWins = $this->card('COBFSPSI', 'Bolle', self::B2B_DESCRIPTION.' COBRA');
        $this->link($this->fileWins, self::B2B_DESCRIPTION.' COBRA');
        $this->fileSlot($this->fileWins, 12.0);
        $this->accountSlot($this->fileWins, null);

        // konto Bolle nie jest producentem tej marki — karta nietknięta
        $this->foreignBrand = $this->card('UVX-1', 'Uvex', self::B2B_DESCRIPTION.' UVEX');
        $this->link($this->foreignBrand, self::B2B_DESCRIPTION.' UVEX');
        $this->fileSlot($this->foreignBrand, 7.0);
        $this->accountSlot($this->foreignBrand, 6.0);

        // slot pliku z innego cennika — poza zakresem
        $other = PriceList::query()->create(['manufacturer' => 'Bolle', 'version' => 'stary plik']);
        $this->untouched = $this->card('BAXPSI', 'Bolle', self::B2B_DESCRIPTION.' BAXPSI');
        $this->link($this->untouched, self::B2B_DESCRIPTION.' BAXPSI');
        $this->fileSlot($this->untouched, 18.0, $other->id);
        $this->accountSlot($this->untouched, 8.0);
    }

    public function test_podglad_niczego_nie_zmienia(): void
    {
        $this->artisan('b2b:clear-file-legacy', ['--account' => $this->bolle->id])
            ->expectsOutputToContain('kart ze slotem pliku z tego wpisu: 4')
            ->expectsOutputToContain('BAXCSP')
            ->expectsOutputToContain('konto nie jest producentem marki karty')
            ->expectsOutputToContain('opis nie jest z B2B — dane AI zostają')
            ->expectsOutputToContain('slot pliku zostaje')
            ->expectsOutputToContain('Podgląd')
            ->assertSuccessful();

        $this->assertSame(4, ProductSourcePrice::query()->where('source_key', ProductSourcePrice::SOURCE_FILE)->where('price_list_id', $this->list->id)->count());
        $legacy = $this->legacy->refresh();
        $this->assertSame('EN 166, EN 169', $legacy->norms);
        $this->assertSame(Product::ENRICHMENT_DONE, $legacy->enrichment_status);
        $this->assertSame(1, ProductEnrichmentCache::query()->count());
    }

    public function test_apply_usuwa_pozostalosci_pliku_i_dane_ai(): void
    {
        $backup = storage_path('framework/testing/clear-file-legacy-'.uniqid().'.json');

        $this->artisan('b2b:clear-file-legacy', ['--account' => $this->bolle->id, '--apply' => true, '--backup' => $backup])
            ->expectsOutputToContain('Zmieniono kart: 3')
            ->assertSuccessful();

        $legacy = $this->legacy->refresh();
        $this->assertFalse($this->hasFileSlot($legacy));
        $this->assertTrue(ProductSourcePrice::query()->where('product_id', $legacy->id)->where('source_key', ProductSourcePrice::b2bKey($this->bolle->id))->exists());
        $this->assertEquals(8.35, (float) $legacy->purchase_price);
        $this->assertNull($legacy->pack_qty);
        $this->assertNull($legacy->packaging);
        $this->assertNull($legacy->norms);
        $this->assertSame(Product::ENRICHMENT_NONE, $legacy->enrichment_status);
        $this->assertNull($legacy->enriched_at);
        $this->assertSame(self::B2B_DESCRIPTION, $legacy->description);
        $payload = $legacy->enrichment_payload;
        $this->assertSame(['replaced_description', 'attributes'], array_keys($payload));
        $this->assertSame('stary opis', $payload['replaced_description']);
        $this->assertNotContains('EN 169', (array) ($payload['attributes']['normy_en'] ?? []));
        $this->assertStringNotContainsString('169', (string) $legacy->search_blob);
        $this->assertSame(0, ProductEnrichmentCache::query()->count());
        $this->assertSame(['manual'], ProductAccessory::query()->where('product_id', $legacy->id)->pluck('source')->all());
        $this->assertSame(1, ProductImage::query()->where('product_id', $legacy->id)->count());

        $ai = $this->aiDescription->refresh();
        $this->assertFalse($this->hasFileSlot($ai));
        $this->assertSame('EN 166, EN 169', $ai->norms);
        $this->assertSame(Product::ENRICHMENT_DONE, $ai->enrichment_status);
        $this->assertArrayHasKey('features', $ai->enrichment_payload);

        $fileWins = $this->fileWins->refresh();
        $this->assertTrue($this->hasFileSlot($fileWins));
        $this->assertSame(200, $fileWins->pack_qty);
        $this->assertNull($fileWins->norms);
        $this->assertSame(Product::ENRICHMENT_NONE, $fileWins->enrichment_status);

        $this->assertTrue($this->hasFileSlot($this->foreignBrand->refresh()));
        $this->assertSame('EN 166, EN 169', $this->foreignBrand->norms);
        $this->assertTrue($this->hasFileSlot($this->untouched->refresh()));
        $this->assertSame('EN 166, EN 169', $this->untouched->norms);

        // drugi przebieg: została tylko karta, której cenę ustala plik — bez danych AI nie ma czego zmieniać
        $this->artisan('b2b:clear-file-legacy', ['--account' => $this->bolle->id, '--apply' => true, '--backup' => $backup.'.2'])
            ->expectsOutputToContain('Nic do zmiany.')
            ->assertSuccessful();
    }

    public function test_restore_przywraca_karty_nieruszone_od_sprzatania(): void
    {
        $backup = storage_path('framework/testing/clear-file-legacy-'.uniqid().'.json');
        $this->artisan('b2b:clear-file-legacy', ['--account' => $this->bolle->id, '--apply' => true, '--backup' => $backup])
            ->assertSuccessful();

        // karta zmieniona po sprzątaniu — przywrócenie jej nie rusza
        $changed = $this->fileWins->refresh();
        $changed->norms = 'EN166, EN170';
        $changed->save();

        $this->artisan('b2b:clear-file-legacy', ['--restore' => $backup])
            ->expectsOutputToContain('COBFSPSI: karta zmieniła się po sprzątaniu')
            ->expectsOutputToContain('Przywrócono kart: 2, pominięte: 1')
            ->assertSuccessful();

        $legacy = $this->legacy->refresh();
        $this->assertTrue($this->hasFileSlot($legacy));
        $this->assertEquals(8.35, (float) $legacy->purchase_price);
        $this->assertSame(200, $legacy->pack_qty);
        $this->assertSame('4-5', $legacy->packaging);
        $this->assertSame('EN 166, EN 169', $legacy->norms);
        $this->assertSame(Product::ENRICHMENT_DONE, $legacy->enrichment_status);
        $this->assertSame(['Filtr spawalniczy Shade 5'], $legacy->enrichment_payload['features']);
        $this->assertSame(1, ProductEnrichmentCache::query()->count());
        $this->assertSame(2, ProductAccessory::query()->where('product_id', $legacy->id)->count());
        $this->assertTrue($this->hasFileSlot($this->aiDescription->refresh()));
        $this->assertSame('EN166, EN170', $this->fileWins->refresh()->norms);
    }

    public function test_karta_w_kolejce_wzbogacania_zachowuje_dane_ai(): void
    {
        $this->legacy->forceFill(['enrichment_status' => Product::ENRICHMENT_QUEUED])->save();

        $this->artisan('b2b:clear-file-legacy', ['--account' => $this->bolle->id, '--apply' => true, '--backup' => storage_path('framework/testing/clear-file-legacy-'.uniqid().'.json')])
            ->expectsOutputToContain('wzbogacanie w toku — dane AI zostają')
            ->assertSuccessful();

        $legacy = $this->legacy->refresh();
        $this->assertFalse($this->hasFileSlot($legacy));
        $this->assertSame('EN 166, EN 169', $legacy->norms);
        $this->assertSame(Product::ENRICHMENT_QUEUED, $legacy->enrichment_status);
        $this->assertSame(1, ProductEnrichmentCache::query()->count());
    }

    public function test_opis_uzupelniony_ze_stron_konta_zachowuje_dane(): void
    {
        // opis napisało uzupełnianie krótkiego opisu B2B (SupplementB2bDescriptionJob): odcisk powiązania = sha1 opisu,
        // więc karta liczy się jako „opis z B2B”, ale dane w payloadzie pochodzą z tego opisu (decyzja 28.09.2026)
        $trace = ['b2b_account_id' => $this->bolle->id, 'result_sha1' => sha1(self::B2B_DESCRIPTION)];
        $this->legacy->forceFill(['enrichment_payload' => [...$this->legacy->enrichment_payload, 'b2b_supplement' => $trace]])->save();
        // ślad nieaktualny (opis zmieniony po uzupełnieniu) — zwykła reguła: dane AI znikają, ślad zostaje
        $stale = [...$trace, 'result_sha1' => sha1('inny opis')];
        $this->fileWins->forceFill(['enrichment_payload' => [...$this->fileWins->enrichment_payload, 'b2b_supplement' => $stale]])->save();

        $this->artisan('b2b:clear-file-legacy', ['--account' => $this->bolle->id, '--apply' => true, '--backup' => storage_path('framework/testing/clear-file-legacy-'.uniqid().'.json')])
            ->expectsOutputToContain('opis uzupełniony ze stron konta — dane zostają')
            ->assertSuccessful();

        $legacy = $this->legacy->refresh();
        // slot pliku i tak znika (cenę ustala konto), dane z opisu zostają
        $this->assertFalse($this->hasFileSlot($legacy));
        $this->assertSame('EN 166, EN 169', $legacy->norms);
        $this->assertSame(Product::ENRICHMENT_DONE, $legacy->enrichment_status);
        $this->assertSame(['Filtr spawalniczy Shade 5'], $legacy->enrichment_payload['features']);
        $this->assertSame($trace, $legacy->enrichment_payload['b2b_supplement']);
        $this->assertSame(2, ProductAccessory::query()->where('product_id', $legacy->id)->count());

        $fileWins = $this->fileWins->refresh();
        $this->assertNull($fileWins->norms);
        $this->assertSame(Product::ENRICHMENT_NONE, $fileWins->enrichment_status);
        $this->assertSame(['replaced_description', 'b2b_supplement', 'attributes'], array_keys($fileWins->enrichment_payload));
        $this->assertSame($stale, $fileWins->enrichment_payload['b2b_supplement']);
    }

    public function test_bez_konta_blad(): void
    {
        $this->artisan('b2b:clear-file-legacy')
            ->expectsOutputToContain('Podaj --account=')
            ->assertFailed();
    }

    private function card(string $sku, string $manufacturer, string $description): Product
    {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => 'Okulary '.$sku,
            'manufacturer' => $manufacturer,
            'description' => $description,
            'norms' => 'EN 166, EN 169',
            'pack_qty' => 200,
            'packaging' => '4-5',
            'catalog_price_net' => 18.55,
            'purchase_price' => 8.35,
            'currency' => 'EUR',
            'stock' => 0,
            'enrichment_payload' => [
                'features' => ['Filtr spawalniczy Shade 5'],
                'norms' => ['EN 166', 'EN 169'],
                'source_urls' => ['https://www.specshop.pl/baxter'],
                'attributes' => ['kategoria_bhp' => 'ochrona_oczu', 'normy_en' => ['EN 166', 'EN 169']],
                'replaced_description' => 'stary opis',
            ],
        ]);
        $product->forceFill(['enrichment_status' => Product::ENRICHMENT_DONE, 'enriched_at' => now()->subDays(16)])->save();

        return $product->refresh();
    }

    private function link(Product $product, string $syncedDescription): void
    {
        B2bProductLink::query()->create([
            'b2b_account_id' => $this->bolle->id,
            'remote_id' => 'r-'.$product->sku,
            'product_id' => $product->id,
            'remote_sku' => $product->sku,
            'manufacturer' => 'Bolle',
            'description_hash' => sha1($syncedDescription),
            'source_description_hash' => sha1('English source text'),
        ]);
    }

    private function fileSlot(Product $product, float $price, ?int $listId = null): void
    {
        ProductSourcePrice::query()->create([
            'product_id' => $product->id,
            'source_key' => ProductSourcePrice::SOURCE_FILE,
            'price_list_id' => $listId ?? $this->list->id,
            'catalog_price_net' => $price,
            'purchase_price' => $price,
            'currency' => 'EUR',
            'pack_qty' => 200,
            'checked_at' => Carbon::parse('2026-09-12 19:45:02'),
            'migrated' => true,
        ]);
    }

    private function accountSlot(Product $product, ?float $price): void
    {
        ProductSourcePrice::query()->create([
            'product_id' => $product->id,
            'source_key' => ProductSourcePrice::b2bKey($this->bolle->id),
            'b2b_account_id' => $this->bolle->id,
            'catalog_price_net' => $price === null ? null : $price * 2,
            'purchase_price' => $price,
            'currency' => 'EUR',
            'checked_at' => Carbon::parse('2026-09-28 12:43:30'),
        ]);
    }

    private function hasFileSlot(Product $product): bool
    {
        return ProductSourcePrice::query()->where('product_id', $product->id)->where('source_key', ProductSourcePrice::SOURCE_FILE)->exists();
    }
}
