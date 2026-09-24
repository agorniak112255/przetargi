<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class RepairSizeNamesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_changes_nothing_apply_repairs_and_restore_brings_names_back(): void
    {
        Queue::fake();
        $protekt = B2bAccount::query()->create(['username' => 'PROTEKT', 'sites' => ['protekt.pl'], 'connector' => 'protekt']);
        $procera = B2bAccount::query()->create(['username' => 'PROCERA', 'sites' => ['procera.pl'], 'connector' => 'procera']);

        $harness = $this->card('AB 150 21', 'P-50mX - Szelki bezpieczeństwa - rozmiar S', $protekt, 'P-50mX - Szelki bezpieczeństwa - rozmiar XXL');
        $kit = $this->card('LIFTER', 'LIFTER - Zestaw do prac głębokościowych - rozmiar szelek M-XL', $protekt);
        $plain = $this->card('BW140', 'BW140 - Amortyzator bezpieczeństwa', $protekt);
        // rozmiar karty innego źródła to prawdziwy rozmiar tej karty — zostaje
        $boots = $this->card('ATLANTIS S1PL 39', 'P-50mX - Szelki bezpieczeństwa - rozmiar S', $procera);
        // urwane „roz.” po łączeniu rozmiarów — tylko na karcie scalonej; „…, size” z cennika Canis to tekst źródła
        $merged = $this->card('60499', 'Rękawice C500 Dry - nakrapiane roz.', null, null, ['60499.7', '60499.8']);
        $canis = $this->card('1830-083-806-00', 'Socks black-blue-gray with CoolMax, size', null);

        $this->artisan('products:repair-size-names')
            ->expectsOutputToContain('Podgląd: 3 nazw (PROTEKT: 2, urwane słowo rozmiaru po łączeniu: 1)')
            ->assertSuccessful();
        $this->assertSame('P-50mX - Szelki bezpieczeństwa - rozmiar S', $harness->fresh()->name);

        $backup = storage_path('framework/testing/size-names.json');
        $this->artisan('products:repair-size-names', ['--apply' => true, '--backup' => $backup])
            ->expectsOutputToContain('Zapisano 3 nazw.')
            ->assertSuccessful();

        $this->assertSame('P-50mX - Szelki bezpieczeństwa', $harness->fresh()->name);
        $this->assertSame('LIFTER - Zestaw do prac głębokościowych', $kit->fresh()->name);
        $this->assertSame('Rękawice C500 Dry - nakrapiane', $merged->fresh()->name);
        $this->assertSame('BW140 - Amortyzator bezpieczeństwa', $plain->fresh()->name);
        $this->assertSame('P-50mX - Szelki bezpieczeństwa - rozmiar S', $boots->fresh()->name);
        $this->assertSame('Socks black-blue-gray with CoolMax, size', $canis->fresh()->name);
        // nazwa ze źródła w powiązaniu zostaje dosłownie
        $this->assertSame('P-50mX - Szelki bezpieczeństwa - rozmiar XXL', B2bProductLink::query()->where('product_id', $harness->id)->value('remote_name'));
        // zapis przez model — indeks wyszukiwania zna nową nazwę
        $this->assertStringNotContainsString('rozmiar s', mb_strtolower((string) $harness->fresh()->search_blob));

        $this->artisan('products:repair-size-names')
            ->expectsOutputToContain('nic do naprawy')
            ->assertSuccessful();

        // przywracanie omija kartę, której nazwę ktoś zmienił po naprawie
        $kit->fresh()->update(['name' => 'LIFTER - zestaw (poprawiony ręcznie)']);
        $this->artisan('products:repair-size-names', ['--restore' => $backup])
            ->expectsOutputToContain('Przywrócono 2 nazw.')
            ->expectsOutputToContain('#'.$kit->id)
            ->assertSuccessful();
        $this->assertSame('P-50mX - Szelki bezpieczeństwa - rozmiar S', $harness->fresh()->name);
        $this->assertSame('Rękawice C500 Dry - nakrapiane roz.', $merged->fresh()->name);
        $this->assertSame('LIFTER - zestaw (poprawiony ręcznie)', $kit->fresh()->name);
        @unlink($backup);
    }

    /**
     * @param  list<string>  $mergedSkus
     */
    private function card(string $sku, string $name, ?B2bAccount $account, ?string $remoteName = null, array $mergedSkus = []): Product
    {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $account?->username ?? 'UVEX',
            'enrichment_payload' => $mergedSkus !== [] ? ['merged_size_skus' => $mergedSkus] : null,
        ]);
        if ($account !== null) {
            B2bProductLink::query()->create([
                'b2b_account_id' => $account->id,
                'remote_id' => $sku,
                'product_id' => $product->id,
                'remote_sku' => $sku,
                'remote_name' => $remoteName ?? $name,
            ]);
        }

        return $product;
    }
}
