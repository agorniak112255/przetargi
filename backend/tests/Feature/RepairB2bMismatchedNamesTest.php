<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * products:repair-b2b-names --mismatched: import Bolle z 12.09.2026 (sprzed poprawki 88713db) przepisał nazwę z wiersza
 * wyżej na karty innych modeli — przyłbica FLASH nazwana „Napotnik do przyłbic ELECTRO…”, okulary NESS+ nazwane jak
 * zestaw uszczelki NESS+. Nazwa wraca z powiązania wskazanego konta (producenta, nie dystrybutora).
 */
final class RepairB2bMismatchedNamesTest extends TestCase
{
    use RefreshDatabase;

    private const SWEATBAND = 'Napotnik do przyłbic ELECTRO i ELECTRO+ (Pakiet 5 szt.)';

    private const FOAM_KIT = 'Zestaw uszczelki z pianki oraz elastycznej taśmy do modeli NESS+';

    private PriceList $priceList;

    private B2bAccount $bolle;

    private B2bAccount $procera;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->bolle = $this->account('bolle');
        $this->procera = $this->account('procera');
        $ids = [
            $this->card('FLASHV', self::SWEATBAND, 'FLASH – Welding helmet', 'OKULARY OCHRONNE BOLLE FLASH')->id,
            $this->card('ELECTN04W', self::SWEATBAND, 'ELECTRO – Sweat band')->id,
            $this->card('NESKIT', self::FOAM_KIT, 'NESS+ – Foam and strap kit')->id,
            $this->card('NESPSN10E', self::FOAM_KIT, 'NESS+ SMALL – Clear safety glasses')->id,
            $this->card('TRYBSSI', 'Okulary ochronne TRYON BSSI, soczewka miedziana', 'TRYON BSSI – Copper safety glasses')->id,
            $this->card('X1000T10M', 'X1000 – Clear vented ballistic goggles', 'X1000 – Clear vented ballistic goggles')->id,
        ];
        $this->priceList = PriceList::query()->create([
            'original_filename' => 'bolle-safety.com (API)',
            'manufacturer' => 'Bolle',
            'version' => '2026',
            'rows_total' => count($ids),
            'products_created' => count($ids),
            'product_ids' => $ids,
        ]);
    }

    public function test_preview_lists_other_product_names_and_repeated_names_and_changes_nothing(): void
    {
        $this->assertSame(0, Artisan::call('products:repair-b2b-names', [
            '--price-list' => $this->priceList->id,
            '--mismatched' => true,
            '--account' => $this->bolle->id,
        ]));
        $output = Artisan::output();

        $this->assertStringContainsString('Do poprawy: 4 nazw, 0 kategorii. Podgląd', $output);
        $this->assertMatchesRegularExpression('/FLASHV.*FLASH – Welding helmet.*nazwa innego wyrobu/u', $output);
        $this->assertMatchesRegularExpression('/NESPSN10E.*NESS\+ SMALL – Clear safety.*ta sama nazwa na 2 kartach/u', $output);
        // karta, z której nazwę przepisano, też jest w grupie — podgląd to pokazuje, --except ją zostawia
        $this->assertMatchesRegularExpression('/ELECTN04W.*ELECTRO – Sweat band.*ta sama nazwa na 2 kartach/u', $output);
        $this->assertStringNotContainsString('TRYBSSI', $output);
        $this->assertStringNotContainsString('X1000T10M', $output);
        $this->assertSame(self::SWEATBAND, Product::query()->where('sku', 'FLASHV')->value('name'));
    }

    public function test_apply_takes_the_name_of_the_chosen_account_skips_excepted_cards_and_restore_reverts(): void
    {
        $backup = storage_path('app/repair-backups/test-b2b-mismatched.json');
        @unlink($backup);
        $electro = Product::query()->where('sku', 'ELECTN04W')->firstOrFail();
        $kit = Product::query()->where('sku', 'NESKIT')->firstOrFail();

        $this->artisan('products:repair-b2b-names', [
            '--price-list' => $this->priceList->id,
            '--mismatched' => true,
            '--account' => $this->bolle->id,
            '--except' => [(string) $electro->id, (string) $kit->id],
            '--backup' => $backup,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Poprawiono 2 nazw i 0 kategorii')
            ->expectsOutputToContain('b2b:translate '.$this->bolle->id)
            ->assertSuccessful();

        // nazwa producenta, nie dystrybutora (Procera ma własne powiązanie z nazwą wersalikami)
        $this->assertSame('FLASH – Welding helmet', Product::query()->where('sku', 'FLASHV')->value('name'));
        $this->assertSame('NESS+ SMALL – Clear safety glasses', Product::query()->where('sku', 'NESPSN10E')->value('name'));
        $this->assertSame(self::SWEATBAND, $electro->fresh()->name);
        $this->assertSame(self::FOAM_KIT, $kit->fresh()->name);

        $this->artisan('products:repair-b2b-names', ['--restore' => $backup])
            ->expectsOutputToContain('Przywrócono 2 kart')
            ->assertSuccessful();
        $this->assertSame(self::SWEATBAND, Product::query()->where('sku', 'FLASHV')->value('name'));
        $this->assertSame(self::FOAM_KIT, Product::query()->where('sku', 'NESPSN10E')->value('name'));
        @unlink($backup);
    }

    public function test_without_mismatched_flag_other_product_names_are_left_alone(): void
    {
        $this->artisan('products:repair-b2b-names', ['--price-list' => $this->priceList->id])
            ->expectsOutputToContain('nic do naprawy')
            ->assertSuccessful();
    }

    private function account(string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $connector,
            'password' => 'haslo',
            'sites' => [$connector.'.example.test'],
            'connector' => $connector,
        ]);
    }

    private function card(string $sku, string $name, string $bolleName, ?string $proceraName = null): Product
    {
        $card = Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'Bolle',
            'category' => 'Ochrona oczu',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'currency' => 'EUR',
        ]);
        // powiązanie dystrybutora najpierw — bez --account wybór szedłby po kodzie i świeżości, nie po koncie
        if ($proceraName !== null) {
            B2bProductLink::query()->create([
                'b2b_account_id' => $this->procera->id, 'remote_id' => 'P-'.$sku, 'product_id' => $card->id,
                'remote_sku' => $sku, 'remote_name' => $proceraName, 'last_seen_at' => now(),
            ]);
        }
        B2bProductLink::query()->create([
            'b2b_account_id' => $this->bolle->id, 'remote_id' => $sku, 'product_id' => $card->id,
            'remote_sku' => $sku, 'remote_name' => $bolleName, 'last_seen_at' => now()->subDay(),
        ]);

        return $card;
    }
}
